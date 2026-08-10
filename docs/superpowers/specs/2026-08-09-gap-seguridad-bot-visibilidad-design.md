# Gap real de paneles: Seguridad + Bot + Visibilidad — Especificación de Diseño

## Objetivo

Cerrar el **gap real** detectado al contrastar una especificación externa contra el repo `gregorcbc/bybitboy` en producción: la mayoría de la funcionalidad pedida ya existe (auth con roles, paneles admin/inversor, edición en vivo parcial de grid, auditoría). Lo que **falta de verdad** se implementa sobre la estructura real, sin duplicar tablas ni contabilidad:

1. **2FA TOTP** para usuarios (obligatorio admin, opcional inversor).
2. **Notificaciones + alertas** por umbrales con envío Telegram configurable.
3. **Reconciliación** ledger local vs API de Bybit.
4. **Vista de modelos ML** (solo lectura).
5. **Edición en vivo ampliada** del riesgo del bot (recovery, daily loss).
6. **Logs paginados**: decisiones de IA y accesos (éxitos + fallos).

## Alcance

- **Incluye**:
  - Migración `scripts/migracion_gap.sql` + `scripts/rollback_gap.sql` (fuente de verdad) aplicada también de forma idempotente en runtime por `Core/Schema.php`.
  - Hook mínimos en `GridManager::run()` / `aiEvaluate()` (riesgo por ciclo, `logs_ia`, alertas) y ampliación de `applyConfigUpdate()`.
  - Páginas/forms nuevos en `admin.php` y `panel.php` siguiendo el design-system existente.
  - `composer require spomky-labs/otphp` (raíz), envoltorio `Core/TwoFactor.php`.
  - Pruebas phpunit + smoke + E2E navegador; README de despliegue escalonado.
- **No incluye** (YAGNI): autenticación tipo SPA/JWT, Redis, systemd (el despliegue es HestiaCP con daemons PHP), reescritura de paneles existentes, sub-roles, retraining de modelos, edición de `config.json` completo en vivo.

## Decisiones de diseño

- **Enfoque A** (elegido): hooks mínimos en `GridManager` siguiendo el patrón existente `checkControl()` — el bot es la única fuente fiable de alertas y estado en memoria. No se toca `buildGrid`/`checkFills`/`syncPositions` ni `websocket_server.php`.
- **2FA**: `spomky-labs/otphp` (no requiere gd); QR renderizado vía `api.qrserver.com` (el servidor tiene salida a internet), más clave manual como respaldo. Segundo paso de login con token de sesión intermedio.
- **Migraciones**: `scripts/*.sql` como fuente documentada + `Core/Schema.php` idempotente en runtime (patrón ya usado en `bot.php`: `CREATE TABLE IF NOT EXISTS` + `ALTER` en try/catch).
- **Config de alertas**: umbrales en tabla `alertas_config`; token de Telegram global en `bot_meta` (sin hardcode). Sin token configurado → la alerta se registra en BD pero no se envía.
- **Negocio**: NULL en `grid_configs.max_daily_loss`/`recovery_loss_pct` = usar valores de `config.json` (constantes `G_MAX_DAILY_LOSS`, `G_RECOVERY_LOSS_PCT`). `logs_acceso` registra logins exitosos y fallidos (independiente de `login_attempts`, que sigue como anti-brute-force).

---

## Arquitectura

### Reuso (sin cambios)

| Recurso | Uso |
|---------|-----|
| `Core/Schema.php` | Migraciones runtime idempotentes |
| `Core/AuthHttp.php` + `auth.php` | Sesión y roles existentes; se añade paso 2FA |
| `grid_ajax.php` `_control`/`update_config` + `GridManager::checkControl()`/`applyConfigUpdate()` | Mecanismo de edición en vivo existente; se amplía allowed-list |
| `admin.php` / `panel.php` | Hubs existentes; se añaden tabs y forms |
| `login_attempts` | Anti-brute-force (intacto) |

### Componentes nuevos / modificados

| Componente | Cambio |
|------------|--------|
| `scripts/migracion_gap.sql` | Fuente de verdad del esquema nuevo |
| `scripts/rollback_gap.sql` | DROP inverso por FKs |
| `Core/Schema.php` | Aplicación idempotente del esquema nuevo |
| `Core/TwoFactor.php` | Envoltorio otphp: `generateSecret`, `otpauthUri`, `verify`, `generateBackupCodes` |
| `Core/Notification.php` | `sendTelegram(token, chatId, text)` vía cURL; fallback a log |
| `Core/Reconciliation.php` | Cálculo ledger vs API Bybit |
| `Core/LogAccess.php` | Escritura de `logs_acceso` en login |
| `Core/AuthHttp.php` | Paso 2FA en login; registro `logs_acceso` |
| `src/php/login.php` | Pantalla intermedia 2FA |
| `Core/AdminHttp.php` | Acciones 2FA admin, alertas CRUD, test Telegram, datos de reconciliación/modelos/logs |
| `Core/InvestorHttp.php` | Acción 2FA inversor |
| `admin.php` | Tabs Reconciliación / Modelos ML / Logs IA / Logs acceso / Alertas; form de riesgo ampliado |
| `panel.php` | Opción 2FA en configuración |
| `Strategy/GridManager.php` | 3 ganchos mínimos + allowed-list ampliada |
| `bot.php` | Solo si fuese estrictamente necesario para datos de alerta (en principio no; `$CTRL` ya se lee vía `GridManager::checkControl`) |

---

## Base de Datos

### Tablas nuevas

```sql
-- logs_ia: decisiones de IA por evaluación
CREATE TABLE `logs_ia` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `fecha` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `senal` VARCHAR(20) NOT NULL,                -- LONG / SHORT / SIDEWAYS
  `confianza` DECIMAL(6,4) NOT NULL,
  `razon` VARCHAR(400) NOT NULL DEFAULT '',
  `accion_tomada` VARCHAR(50) NOT NULL DEFAULT '',
  `precio` DECIMAL(20,8) NOT NULL DEFAULT 0,
  KEY `idx_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- logs_acceso: logins exitosos y fallidos
CREATE TABLE `logs_acceso` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id` INT NULL,
  `username` VARCHAR(60) NOT NULL,
  `ip` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  `resultado` ENUM('exitoso','fallido') NOT NULL,
  `fecha` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_fecha` (`fecha`),
  CONSTRAINT `fk_logs_acceso_user` FOREIGN KEY (`usuario_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- alertas_config: umbrales de alerta y destino
CREATE TABLE `alertas_config` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tipo` VARCHAR(40) NOT NULL,                 -- drawdown_pct | daily_loss_pct | distancia_liquidacion_pct | saldo_min_usd
  `umbral` DECIMAL(12,4) NOT NULL,
  `habilitado` TINYINT(1) NOT NULL DEFAULT 1,
  `telegram_chat_id` VARCHAR(50) NOT NULL DEFAULT '',
  `ultima_notificacion` DATETIME NULL,
  `intervalo_min` INT NOT NULL DEFAULT 30,     -- anti-spam: no re-notificar antes de X min
  `actualizado_por` INT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_tipo` (`tipo`),
  CONSTRAINT `fk_alertas_admin` FOREIGN KEY (`actualizado_por`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Columnas nuevas

```sql
ALTER TABLE `users`
  ADD COLUMN `totp_secret` VARCHAR(64) NULL AFTER `last_login_at`,
  ADD COLUMN `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `totp_secret`;

ALTER TABLE `grid_configs`
  ADD COLUMN `max_daily_loss` DECIMAL(5,2) NULL AFTER `fee_floor_mode`,
  ADD COLUMN `recovery_loss_pct` DECIMAL(5,2) NULL AFTER `max_daily_loss`;
```

### `bot_meta`

- `meta_key = 'telegram_bot_token'` → token del bot de Telegram (global, gestionado desde el panel; NULL = sin envío).

---

## Flujo de datos

### 1. Login con 2FA
`login.php` → `AuthHttp::login()` valida credenciales (intacto, + `logs_acceso` fallido si error) → si `totp_enabled`, guarda `pendiente_2fa_user` en sesión y redirige a pantalla 2FA → `login.php?step=2fa` pide 6 dígitos → `TwoFactor::verify(code, totp_secret)` → éxito: sesión completa + `logs_acceso` exitoso.

### 2. Edición en vivo del riesgo
`admin.php` form → `grid_ajax.php` `update_config` con campos ampliados → `grid_control.json` → `GridManager::checkControl()` → `applyConfigUpdate()` (allowed-list ampliada: `recovery_active`, `max_daily_loss`, `recovery_loss_pct`) → persiste en `grid_configs` y actualiza `$this->cfg`. En el ciclo, `applyRiskConfig()` relee esos valores y ajusta los límites efectivos (NULL → constantes de `config.json`).

### 3. Alertas Telegram
`notifyIfAlert()` (cada ciclo) evalúa reglas de `alertas_config` habilitadas con datos en memoria (drawdown real, pérdida diaria %, distancia a liquidación, saldo) → si umbral superado y `ultima_notificacion` más antigua que `intervalo_min` → `Notification::sendTelegram()` (token de `bot_meta`) → actualiza `ultima_notificacion` y registra en `admin_audit` si hay token o falla.

### 4. Reconciliación
`Core/Reconciliation::compare()`: balance + posiciones abiertas de Bybit (client existente) vs suma de `movements` + `shares` + `nav_snapshots` → array de diferencias con semáforo (rojo si |diff| > tolerancia configurable). Solo lectura.

---

## Detalle por fase

### Fase 1 — Seguridad
1. `scripts/migracion_gap.sql` + `scripts/rollback_gap.sql`; aplicación en `Core/Schema.php`.
2. `composer require spomky-labs/otphp` (raíz; producción: `composer install --no-dev`).
3. `Core/TwoFactor.php` + `Core/LogAccess.php`; `AuthHttp.php` paso 2FA; `login.php` pantalla intermedia.
4. `AdminHttp` acciones `enable_2fa` / `verify_2fa` / `disable_2fa`; `InvestorHttp` acción `enable_2fa` (opcional inversor). Form en `admin.php` (Usuarios) y `panel.php` (Configuración).
5. Registro `logs_acceso` en login (éxito y fallo).

### Fase 2 — Bot
1. `applyRiskConfig()` en `GridManager::run()` (al inicio del ciclo): relee `max_daily_loss`/`recovery_loss_pct`/`recovery_active` y ajusta límites efectivos.
2. Hook en `aiEvaluate()`: INSERT en `logs_ia`.
3. `notifyIfAlert()` + `Core/Notification.php`.
4. Ampliar allowed-list de `applyConfigUpdate()`.
5. Migración runtime idempotente también en el arranque del bot (patrón de `bot.php`).

### Fase 3 — Visibilidad (admin.php)
1. Tab **Reconciliación**: tabla de diferencias ledger vs API Bybit.
2. Tab **Modelos ML**: metadata de `data/models/*`, `trainer_history.json`, `ml_accuracy` (solo lectura).
3. Tab **Logs IA**: paginado + filtros fecha/señal/confianza.
4. Tab **Logs acceso**: paginado + filtros usuario/resultado/fecha.
5. Tab **Alertas**: CRUD de `alertas_config` + botón "Probar Telegram".
6. Form de riesgo ampliado en tab **Bot**.

---

## Pruebas

- **phpunit** (baseline 255/1024 sin regresión):
  - `TwoFactorTest`: verify código correcto/incorrecto, secret rotativo.
  - `AlertasConfigTest`: evaluación de umbrales, anti-spam `intervalo_min`, fallback sin token.
  - `LogsIaTest` / `LogAccessTest`: escritura y filtros.
  - `ReconciliationTest`: con mock del client Bybit; diferencias y tolerancia.
  - `SchemaTest`: idempotencia de `migracion_gap.sql`.
- **Smoke**: `admin.php`/`panel.php` → 302 sin sesión, 200 con sesión; `grid_ajax.php` `_control` sin token → 403.
- **E2E navegador** (Chrome headless CDP, como en la verificación anterior): habilitar 2FA en admin, login con 2FA, alta de alerta, paginación, reconciliación con datos reales.

## Despliegue

1. `git checkout -b feature/paneles-gap-real` (no commit directo a `master`).
2. `mysqldump backup_pre_gap_$(date +%Y%m%d).sql` (fuera del repo) — comando exacto en README.
3. Aplicar `scripts/migracion_gap.sql` (o dejar que `Schema.php` la aplique al arrancar).
4. `composer install --no-dev`.
5. **Reinicio planificado del daemon `bot.php`** (PID actual 3745) para cargar el código nuevo; `websocket_server.php` y `scanner.php` no se tocan.
6. Verificar suite + smoke + E2E; actualizar `.superpowers/sdd/progress.md` y CHANGELOG.
