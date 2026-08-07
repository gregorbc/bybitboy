# Spec: Hardening de seguridad web

**Fecha:** 2026-08-06
**Estado:** aprobado por el usuario
**Contexto:** análisis web (2026-08-06) detectó hallazgos críticos de seguridad, varios confirmados por HTTP (config.json descargable con credenciales, instalador activo, endpoints de datos públicos).

## Decisiones confirmadas con el usuario

1. **Credenciales Bybit:** NO se rotan (cuenta demo/testnet sin fondos reales). Se protegen del acceso web.
2. **Dashboard y datos:** exigir login. El dashboard del bot (`src/php/index.php`, `src/php/index2.php`) es **solo admin**; los endpoints de datos requieren sesión activa; la landing pública queda pública.
3. **index2.php:** se corrige y mantiene (mismo gate de login y fixes que index.php).
4. **Backups con secrets:** `public_html (2)`, `public_html (3)`, `public_html.zip` se mueven fuera del árbol web.
5. **Secretos:** van a `.env` (gitignored) y `config.json` sin credenciales se mueve fuera del web root a `private/`.

## Arquitectura

### 1. Secretos en `.env`
- Ampliar `public_html/.env` (existente, gitignored, no descargable por la regla nginx de dotfiles) con:
  `BYBIT_API_KEY`, `BYBIT_API_SECRET`, `NVIDIA_API_KEY`, `MYSQL_PASSWORD`, `SECURITY_TOKEN`, `WS_TOKEN`, `PLATFORM_SECRET`.
- `ConfigLoader::load()` centraliza la carga: lee `config.json` y aplica overrides desde `getenv()`. Es el **único** loader usado por todos los consumidores.
- Consumidores a unificar (hoy 4 cargadores):
  - `src/php/grid_ajax.php:25` (ya intenta `private/config.json` primero)
  - `src/php/websocket_server.php:21-22`
  - `src/php/Core/Config.php:38-42`
  - `src/php/bot.php` y demás (`Helpers.php`, `trainer.php`)
- `config.json` se mueve de `src/php/config.json` a `private/config.json` y se eliminan sus campos secretos (quedan `""` o ausentes; el override de env los rellena).

### 2. Modelo de acceso
- **Landing** (`index.php` raíz, 184 l.): pública. Consume solo `_landing_stats`.
- **Dashboard** (`src/php/index.php` + `index2.php`): exige sesión con rol `admin`; si no → redirect a `login.php` (403 si es AJAX).
- **Endpoints de datos** (`grid_ajax.php`, `Dashboard/Router.php`, `Dashboard/Api.php`): exigen sesión activa (cualquier rol); respuesta `401 {ok:false,error}` si no. Excepción: `_landing_stats`.
- **CORS:** quitar `Access-Control-Allow-Origin: *` (same-origin). En `grid_ajax.php:17` y `Router.php:18`.

### 3. Tokens
- `SECURITY_TOKEN` y `WS_TOKEN`: valores aleatorios (32+ bytes hex) generados en `.env`.
- Eliminar fallback hardcodeado `g273f123` en `EXPORT_TOKEN` (`index.php:27`, `index2.php:37`, `trainer.php:33`): fallback solo a env, fail-closed si no hay env.
- `update_config` (`grid_ajax.php:379-408`): añadir `checkToken()`.
- `Helpers.php:11-15 checkToken()`: fail-closed (token vacío → false).

### 4. Bloqueo web (nginx + Apache)
- Crear `public_html/.htaccess`:
  - deny `*.json`, `*.bak`, `*.sql`, `*.log`, `.env`
  - deny `install.php`, `install_hestia.php`, `test_config.php`
- Regla en vhost nginx del dominio (`/home/erika/conf/web/binance.gregorbritez.cat/nginx.conf*`):
  `location ~* \.(json|bak|sql|log)$ { deny all; return 403; }`
  (nginx sirve estáticos sin pasar por Apache → defensa definitiva).
- Verificación: `curl -k https://binance.gregorbritez.cat/src/php/config.json` → 403; raíz → 200.

### 5. Fixes de código
- `trainer.php:39-42`: requerir siempre token + sesión admin.
- `save_chart.php`: exigir token, límite 2 MB, validar PNG base64.
- `register.php`: rate limit por IP (reusar tabla `login_attempts`).
- `AuthHttp.php:13`: verificar CSRF solo en POST (el primer GET a `auth.php` no debe fallar).
- SQL por interpolación → prepared/bind: `GridManager.php:1161,1167,1270`, `BotAccountingSync.php:16`.

### 6. WebSocket
- `websocket_server.php:471`: bind a `127.0.0.1:8094` (hoy 0.0.0.0). El proxy nginx `location /ws/ → 127.0.0.1:8094` ya existe; el acceso público sigue por `wss://binance.gregorbritez.cat/ws/`.
- `ws_token` rotado vía `.env`.

### 7. Limpieza de archivos
- Borrar: `install.php`, `install_hestia.php`, `test_config.php`, `index.php.bak*`, `config.json.bak*` y cualquier `.bak`/backup con secrets dentro del web root.
- Mover `public_html (2)`, `public_html (3)`, `public_html.zip` → `/home/erika/backups_seguros/`.

### 8. Rotación password MySQL
- Generar password aleatoria; `ALTER USER 'erika_bot'@'localhost' IDENTIFIED BY '<nueva>'` (acceso root disponible).
- Escribir en `.env` como `MYSQL_PASSWORD`; restart de `grid-bot`, `grid-bot-ws`, `binance-scanner`.
- Verificación inmediata: estado de los 3 servicios y consulta MySQL con la nueva credencial.

## Manejo de errores
- Cualquier endpoint protegido sin sesión → `401 {ok:false, error:'No autorizado'}` (JSON) o redirect (páginas).
- `checkToken` fail-closed: si `SECURITY_TOKEN` no está en env, las acciones de control quedan bloqueadas (no abiertas).
- Al rotar la password MySQL, si un servicio falla, restaurar la anterior en `.env` y reiniciar (rollback definido).

## Pruebas
1. Suíte PHP completa (`php vendor/bin/phpunit --no-coverage`): 226 tests sin regresiones.
2. Curl checks:
   - `config.json` → 403
   - `install.php` → 404/403
   - `_status` sin sesión → 401
   - `_landing_stats` sin sesión → 200
   - raíz landing → 200
   - dashboard sin sesión → redirect a login
3. Logs del bot tras restarts (`[GRID]`, `[INIT]`) y estado `systemctl` de los 3 servicios.
4. WS: conexión sin token → cerrada; con token correcto → snapshot `full`.

## Fuera de alcance (YAGNI)
- TLS directo en :8094 (ya resuelto por proxy nginx wss).
- Eliminar historial git con secrets antiguos (rewrite de historia) — se documenta como deuda futura.
- Mover claves al panel de Bybit (rotación real de la API key).

## Deuda técnica documentada (no bloqueante)
- Historias git de backups con credenciales antiguas (se mueven fuera, no se purgan).
- `index.php`/`index2.php` duplicados divergentes (se mantienen ambos con gate de login).
- Sin prueba de frontend modular (huérfano) — sin cambios aquí.
