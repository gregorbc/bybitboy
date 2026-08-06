# Diseño: Landing pública + registro/login estilizado + controles protegidos

Fecha: 2026-08-06
Ámbito: `index.php` (raíz), `src/php` (app web PHP monolítica)

## Problema

El sitio `binance.gregorbritez.cat` redirige directamente a `src/php/index.php` (dashboard del bot ETH/USDT), que es totalmente público y expone controles destructivos (Stop, Reset Grid, IA, Config, export PnL) sin autenticación. No existe una página de inicio que presente el producto, ni una página de registro coherente con la marca: `auth.php` tiene el flujo funcional pero un estilo visual ajeno al design-system dark del bot.

## Objetivo

1. Landing pública (`index.php` raíz) con marca "Grid Bot · ETH/USDT", stats en vivo, características, sección demo y CTAs.
2. Páginas `register.php` y `login.php` nuevas, estilizadas con el design-system, reutilizando la lógica de `Core\Auth`/`Core\Csrf`/`Core\Schema` (sin tocar `AuthHttp`).
3. Proteger los controles destructivos del dashboard (`_control` y `update_config` en `grid_ajax.php`) exigiendo sesión de rol `admin` además del token existente; las lecturas siguen públicas para que la demo funcione.

Decisiones de producto (validadas con el operador):
- **Landing pública completa** (hero, stats, características, cómo funciona, demo, footer).
- **Demo = abrir el dashboard real** (`src/php/index.php`) en pestaña nueva; no iframe embebido.
- **Registro/login nuevos estilizados** con el design-system; `auth.php` queda como fallback.
- **Stats públicas en vivo** en la landing (precio, PnL hoy, win rate, fills).
- **Controles del dashboard protegidos con login de rol `admin`**; las vistas de stats siguen públicas.
- Marca: "Grid Bot · ETH/USDT" con el dominio actual y el design-system dark existente (`--bg-primary`, `--accent`, etc.).
- Sin nombre comercial nuevo, sin captcha.

## 1. Arquitectura

| Componente | Qué hace | Archivo |
|---|---|---|
| Landing | Página de inicio pública con stats en vivo y CTAs | `index.php` (raíz) |
| Registro | Alta de inversores, estilizado | `src/php/register.php` (nuevo) |
| Login | Ingreso de usuarios, estilizado | `src/php/login.php` (nuevo) |
| Auth existente | Fallback / flujo previo intacto | `src/php/auth.php`, `Core/AuthHttp.php` |
| Dashboard | Demo del bot (sin cambios de contenido) | `src/php/index.php` |
| API ajax | `_control`/`update_config` ahora exigen rol admin | `src/php/grid_ajax.php` |
| CSS | Design-system compartido | `src/php/assets/css/design-system.css` |

Flujo de landing: visitante → hero con stats en vivo → CTA "Ver demo" (abre `src/php/index.php`) → CTA "Crear cuenta" (abre `register.php`) → registro → `panel.php`.

## 2. Landing (`index.php` raíz)

- Deja de ser un redirect; se convierte en página HTML completa con el design-system (enlaza a `src/php/assets/css/design-system.css`).
- Secciones:
  - **Header/nav**: marca "Grid Bot · ETH/USDT", enlaces (Demo, Cómo funciona, Características), botones "Ingresar" y "Crear cuenta".
  - **Hero**: título, tagline, precio ETH/USDT en vivo, CTAs "Ver demo en vivo" y "Crear cuenta".
  - **Stats públicas**: PnL de hoy, win rate, fills totales, órdenes abiertas — leídas de MySQL (misma consulta agregada que el dashboard) y refrescadas por polling JS (intervalo ~10s) hacia un endpoint público.
  - **Características**: grid de 4-6 tarjetas (Grid Trading, IA/ML, tiempo real WS, gestión de riesgo, 24/7, Bybit).
  - **Cómo funciona**: 3 pasos (Regístrate → Recibe tu dashboard → Sigue tu PnL en tiempo real).
  - **Demo**: card con botón que abre `src/php/index.php` en `target="_blank"`.
  - **Footer**: marca, aviso de riesgo (el trading con apalancamiento conlleva riesgo), año.
- Responsive con el mismo patrón de media queries del proyecto (767px / 480px).
- Sin credenciales en el HTML; los stats vienen de un endpoint dedicado de solo lectura.

## 3. Endpoint de stats para la landing

- Nuevo endpoint público de solo lectura que devuelve JSON: `{price, pnl_today, win_rate, fills_total, open_orders, updated_at}`.
- Se implementa como endpoint `_landing_stats` dentro de `grid_ajax.php` (mismas consultas agregadas que el dashboard), de solo lectura, sin exigir sesión. No se crea un archivo suelto; la ruta por defecto (sin `action`) sigue respondiendo `no action` como hoy.

## 4. Registro y Login estilizados

- `src/php/register.php` y `src/php/login.php`, mismo patrón que `auth.php` pero con layout del design-system.
- Usan `Core\Auth::register()`, `Core\Auth::login()`, `Core\Auth::checkRateLimit()`, `Core\Auth::recordAttempt()`, `Core\Csrf::token()`, `Core\Schema::createTables()` y sesiones con las mismas opciones (`HttpOnly`, `Secure`, `SameSite=Lax`).
- Registro: usuario (3-50), email, contraseña (≥8) → al éxito `$_SESSION['user_id']`, rol `investor`, `session_regenerate_id(true)` → redirect a `panel.php`.
- Login: usuario/contraseña con rate-limit por IP (10 intentos / 900s) → redirect a `panel.php`.
- Muestran error en línea, sin exponer detalles de DB.
- No modifican `AuthHttp`; la duplicación de ~10 líneas de lógica de sesión es aceptable y de bajo riesgo.

## 5. Protección de controles del dashboard

- En `src/php/grid_ajax.php`:
  - El bloque `_control` (stop, force_ai, reset_grid, reset_pair) exige `session_start()` + `$_SESSION['role'] === 'admin'` **y** `checkToken($requiredToken)`. Si no hay sesión admin → `{ok:false, msg:'No autorizado'}` con HTTP 403.
  - El bloque `update_config` exige lo mismo.
  - Los endpoints de lectura (`_status`, `_ticker`, `_market`, `_logs`, `_pnl_float`, `_ai_decisions`, `_scalp`, `_ml_info`, `_fills_history`, `_health`, nuevo `_landing_stats`) permanecen públicos.
  - `session_start()` ya se usa en otras páginas; en `grid_ajax.php` hay que iniciar sesión con las mismas opciones antes de evaluar `_control`.
- En `src/php/index.php` (dashboard):
  - El token JS (`const token = ...`) solo se inyecta si `$_SESSION['role'] === 'admin'`; en caso contrario queda vacío.
  - Los botones de control (⚡ Rápido, ⚙️, 🧠 IA, ↻ Grid, ■ Stop, 📥) se ocultan o deshabilitan si no hay sesión admin.
  - El bot que escribe en `grid_control.json` no pasa por `grid_ajax` (usa el archivo directamente), por lo que la protección no afecta al daemon.

## 6. Seguridad

- CSRF en todos los formularios nuevos (registro/login) con `Core\Csrf`.
- Rate-limit de login por IP (existente en `Core\Auth`).
- Headers: `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff` en páginas y JSON.
- El token público de WS/lectura se mantiene (solo lectura). El token de control ya no basta sin sesión admin.
- Sin exponer rutas de config, secretos ni credenciales en el HTML de la landing.

## 7. Pruebas

- **Unitarias (PHPUnit)**: guard de sesión admin en `_control` y `update_config` (sesión vacía → 403; rol `investor` → 403; rol `admin` + token válido → ok). Se probará extrayendo la lógica de autorización a una función testeable o mediante test de integración sobre `grid_ajax.php` con inyección de `$_SESSION`/`$_POST`.
- **Unitarias**: `_landing_stats` devuelve JSON con los campos esperados (mock PDO o DB de test).
- **Verificación manual**: landing 200 con stats; registro crea usuario y redirige a `panel.php`; login ok; demo abre el dashboard; Stop sin sesión → 403; Stop con admin → ok.
- Suite completa `phpunit` al final sin regresiones.

## 8. Fuera de alcance (YAGNI)

- No se rediseñan `panel.php` ni `admin.php`.
- No se tocan `bot.php`, `scanner.php`, el servidor WS, ni la lógica de `AuthHttp`.
- No se añade captcha, verificación de email ni nombre comercial nuevo.
- No se cambia el modelo de datos.
