# Paneles Admin + Inversor — Especificación de Diseño

## Objetivo

Convertir el panel de administración en un **hub unificado profesional** que combine la gestión del fondo de inversión existente con el **control y monitorización del grid bot** (sin tocar el dashboard actual `index.php`), y **mejorar el panel del inversor** (`panel.php`) con más KPIs, gráficas y gestión de perfil.

---

## Alcance

- **Incluye**:
  - Panel admin unificado con secciones: Resumen, Bot (monitor + control), Órdenes + PnL (gráficas), Fondo (con gráfico NAV histórico), Usuarios (con ajuste de saldo), Auditoría.
  - Reuso de los endpoints ya admin-protegidos de `grid_ajax.php` para el monitor/control del bot.
  - Panel inversor mejorado: KPI de crecimiento, gráfica de equity histórico, paginación/filtros en historiales, pestaña perfil (email + contraseña).
  - Tabla nueva `admin_audit` con registro de todas las acciones admin.
  - Ajustes de saldo a usuarios (depósito manual / corrección / reintegro) usando `shares` + `movements`.
- **No incluye** (YAGNI): 2FA, notificaciones por email/Telegram, edición de configuración del bot desde admin, reemplazo o eliminación de `index.php`, envíos programados, sub-roles.

---

## Decisiones de diseño

- **Enfoque A**: extender el patrón existente (server-rendered PHP + handlers `AdminHttp`/`InvestorHttp` + design-system + Chart.js). No SPA, no iframes.
- **`index.php` queda intacto**: sigue siendo el dashboard con token; el admin unificado es un hub adicional.
- **Reuso de `grid_ajax.php`**: sus endpoints (`_status`, `_logs`, `_ticker`, `_market`, `_control`, `_pnl_float`) ya requieren sesión admin (`requireAdminSession()`), salvo `_landing_stats`. El panel admin los consume directamente. Único endpoint nuevo: `pnl_cumulative` (lectura, admin-gated).

---

## Arquitectura

### Reuso (sin cambios)

| Recurso | Uso |
|---------|-----|
| `grid_ajax.php` `_status` | Estado en vivo del bot (running, uptime, PnL, win-rate, órdenes abiertas, balance, uPnL) |
| `grid_ajax.php` `_logs` | Logs en vivo del bot |
| `grid_ajax.php` `_ticker` | Franja ticker ETHUSDT |
| `grid_ajax.php` `_control` | Detener / Forzar IA / Reconstruir grilla / Reset pair (POST + token compartido) |
| `grid_ajax.php` `_pnl_float` | PnL flotante de posiciones |
| `index.php` | Intacto |

### Componentes nuevos / modificados

| Componente | Cambio |
|------------|--------|
| `src/php/Core/Schema.php` | Tabla `admin_audit` |
| `src/php/Core/AdminHttp.php` | Acción `adjust_user`; logging de auditoría en todas las acciones; datos `nav_history`, `audit_logs`, `fills_summary` |
| `src/php/admin.php` | Hub con secciones Resumen / Bot / Órdenes+PnL / Fondo / Usuarios / Auditoría; JS de polling y gráficas |
| `src/php/grid_ajax.php` | Endpoint `_pnl_cumulative` (solo lectura) |
| `src/php/Core/InvestorHttp.php` | Acciones `change_password`, `update_profile`; dato `equity_history` |
| `src/php/panel.php` | KPI crecimiento, pestaña Crecimiento (gráfica), paginación/filtros, pestaña Perfil |
| `src/php/assets/js/components/` | Componentes Chart.js reutilizables (PnL, NAV, equity) y polling del bot |

---

## Base de Datos

### Tabla `admin_audit`

```sql
CREATE TABLE IF NOT EXISTS admin_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    username VARCHAR(50) NOT NULL DEFAULT '',
    action VARCHAR(50) NOT NULL,
    detail VARCHAR(500) NOT NULL DEFAULT '',
    ip VARCHAR(45) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin (admin_id, created_at),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

- Sin columnas nuevas en tablas existentes. Ajustes de saldo usan `shares` + `movements` (tipo `adjust`); NAV histórico usa `nav_snapshots`.

---

## Panel Admin Unificado (`admin.php`)

Tabs del hub:

1. **Resumen** — KPIs del fondo (NAV, unidades, usuarios activos, retiros pendientes) + KPIs del bot (precio ETHUSDT, estado corriendo/detenido, PnL hoy, win-rate, balance Bybit, uPnL).
2. **Bot** — Monitor en vivo (polling `_status` cada 5s): modo, engine AI, dirección, confianza, niveles, órdenes abiertas, ciclo. **Control**: Detener / Forzar IA / Reconstruir grilla / Reset pair (vía `_control` + token, mismo patrón que `index.php`). **Logs** en vivo (`_logs`). Franja ticker (`_ticker`).
3. **Órdenes + PnL** — Tabla de órdenes abiertas (de `_status.orders`); historial de fills paginado (query `grid_orders`); gráficas Chart.js: PnL horario 48h, diario 14d (de `_status`) y acumulado (endpoint nuevo `_pnl_cumulative`).
4. **Fondo** — Contenido existente (retiros pendientes/historial + marcar enviado, depósitos + marcar desplegado, envío directo con estimación de gas) **+ gráfico NAV histórico** desde `nav_snapshots`.
5. **Usuarios** — Tabla existente (suspender/activar) **+ modal "Ajustar saldo"**: tipo (`deposit` | `correction` | `refund`), monto, motivo → escribe `shares` + `movements` + `admin_audit`.
6. **Auditoría** — Tabla con las últimas 500 acciones admin (fecha, admin, acción, detalle, IP).

### Endpoint `_pnl_cumulative` (en `grid_ajax.php`)

Sigue el patrón del archivo (admin session, JSON, sin token adicional):
```
SELECT DATE(filled_at) d, ROUND(SUM(pnl_usd),6) p
FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED'
GROUP BY DATE(filled_at) ORDER BY d ASC
```

---

## Panel Inversor Mejorado (`panel.php`)

1. **KPIs** — existentes (equidad, unidades, NAV) + **crecimiento %** desde el ingreso del usuario.
2. **Pestaña "Crecimiento"** — gráfica de línea (Chart.js) del equity histórico del usuario, calculado de `movements` (unidades acumuladas × NAV del fondo por fecha). Sin tablas nuevas.
3. **Historiales** — paginación client-side y filtros por tipo/estado en Movimientos, Depósitos y Retiros.
4. **Pestaña "Perfil"** — cambiar email y cambiar contraseña (exige contraseña actual con `password_verify`; al cambiarla se regenera el ID de sesión con `session_regenerate_id(true)`). Se mantiene el acceso a "Admin" si el rol lo permite.

---

## Seguridad

- **CSRF**: todas las acciones nuevas (ajuste de saldo, perfil/contraseña) usan `Csrf::verify` contra el token de sesión, igual que las acciones existentes.
- **Autorización**: ajuste de saldo y auditoría solo rol `admin`; perfil/contraseña solo sesión del propio usuario (`$_SESSION['user_id']`).
- **Ajuste de saldo**:
  - Solo rol admin; monto validado como decimal > 0 con máx. 8 decimales; tipo en whitelist (`deposit|correction|refund`); motivo obligatorio ≤ 500 chars.
  - Se escribe en `shares` + `movements` + `admin_audit`. No toca saldos del bot (Bybit).
- **Auditoría**: registra `admin_id`, `username`, `action`, `detail` (JSON corto), `ip` (`REMOTE_ADDR`) en cada acción admin: aprobar/rechazar/marcar-enviado retiro, desplegar depósito, suspender/activar usuario, envío directo, ajuste de saldo.
- **Control del bot**: reutiliza el mecanismo existente (`_control` con token compartido en query string + sesión admin). Sin endpoints nuevos de control.
- **Perfil**: cambio de contraseña verifica la actual (`password_verify`) antes de actualizar; nueva contraseña ≥ 8 chars; `session_regenerate_id(true)` tras el cambio.
- `index.php` y sus mecanismos de token quedan intactos.

---

## Testing

- **phpunit**:
  - `AdminHttpTest`: `adjust_user` escribe `shares` + `movements` + `admin_audit`; rechaza sin admin y sin CSRF válido; valida monto/tipo.
  - `InvestorHttpTest`: `change_password` con contraseña incorrecta falla y con correcta actualiza; `update_profile` actualiza email validado.
  - `SchemaTest`: tabla `admin_audit` creada.
  - Suite existente se mantiene verde (baseline 241/993).
- **Manual / smoke**:
  - Login admin → secciones renderizan; control escribe `grid_control.json`; gráficas cargan; auditoría registra.
  - Panel inversor → cambio de contraseña y gráfica de crecimiento.
  - `php -l` en archivos tocados; HTTP: endpoints nuevos responden 200 con sesión admin y 403 sin ella.

---

## Fuera de alcance (YAGNI)

- 2FA, notificaciones email/Telegram, sub-roles admin.
- Edición de configuración del bot desde admin.
- Reemplazo o eliminación de `index.php`.
- Envíos programados o batch.
