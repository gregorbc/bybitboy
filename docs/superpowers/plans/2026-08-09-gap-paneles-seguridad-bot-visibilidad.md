# Gap Paneles (Seguridad + Bot + Visibilidad) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cerrar el gap real de los paneles: 2FA TOTP, alertas/Telegram, reconciliación ledger vs Bybit, vista de modelos ML, edición en vivo ampliada del riesgo del bot y logs paginados (IA + accesos) — sin duplicar nada de lo ya existente.

**Architecture:** Enfoque A del spec: hooks mínimos en `Strategy/GridManager` (el único que conoce el estado en memoria del bot), migraciones `scripts/*.sql` + `Core/Schema.php` idempotente, páginas nuevas como tabs en `admin.php` y `panel.php` siguiendo el design-system existente, `spomky-labs/otphp` para TOTP.

**Tech Stack:** PHP 8.2/8.3, MySQL 8 (InnoDB utf8mb4), PDO prepared, cURL, Chart.js 4.4.1, PHPUnit 10, Mockery, otphp.

## Global Constraints

- **Idioma:** Todo código, comentarios y UI en **español**.
- **Rama:** Todo el trabajo se hace en `feature/paneles-gap-real`; nunca commit directo a `master`.
- **`vendor/` NO se toca** (working tree ajeno). Solo `composer require` (que actualiza `composer.json`/`composer.lock`).
- **`websocket_server.php` intacto**; `bot.php` intacto salvo que el plan lo indique explícitamente.
- **`GridManager`:** solo se tocan `applyConfigUpdate()`, `riskCheck()` (añadir `applyRiskConfig()` + `notifyIfAlert()`), `aiEvaluate()` (añadir `persistAiDecision()`). **Nunca** `buildGrid`, `checkFills`, `syncPositions`, `cleanupSession`, `closeAllPositions`, `enterRecovery`.
- **Seguridad:** PDO prepared statements en todo SQL con variables; CSRF en todo POST; `hash_equals` para comparar códigos; tokens/secretos nunca en logs.
- **Baseline de pruebas:** suite actual 255 tests / 1024 assertions (1 warning + 1 deprecación) sin regresión.
- **BD:** MySQL `erika_bot`; pruebas en SQLite (`tests/Support/SqliteSchema.php`). SQL compatible con ambos (evitar sintaxis solo-MySQL en código de runtime).
- **Config:** valores NULL en `grid_configs.max_daily_loss`/`recovery_loss_pct` = usar `config.json` (constantes `G_MAX_DAILY_LOSS`/`G_RECOVERY_LOSS_PCT`). Token de Telegram en `bot_meta('telegram_bot_token')`; sin token → la alerta se loguea pero no se envía.
- **Despliegue:** backup `mysqldump` antes de migrar; reinicio planificado del daemon `bot.php` (PID actual 3745) al final.

---

### Task 1: Migración del esquema (tablas + columnas + test schema)

**Files:**
- Create: `scripts/migracion_gap.sql`
- Create: `scripts/rollback_gap.sql`
- Modify: `src/php/Core/Schema.php`
- Modify: `tests/Support/SqliteSchema.php`
- Test: `tests/php/Unit/Core/SchemaGapTest.php`

**Interfaces:**
- Produces: tablas `logs_ia`, `logs_acceso`, `alertas_config`; columnas `users.totp_secret`, `users.totp_enabled`, `grid_configs.max_daily_loss`, `grid_configs.recovery_loss_pct`. `Schema::createTables(PDO)` debe crearlas idempotentemente; `SqliteSchema::apply(PDO)` debe crear las mismas estructuras (SQLite) para los tests.

- [ ] **Step 1: Crear `scripts/migracion_gap.sql`**

```sql
-- Gap real de paneles (2026-08-09) — fuente de verdad del esquema nuevo
-- Se aplica manualmente en producción y de forma idempotente via Core/Schema.php
-- Nota: los IF NOT EXISTS / ADD COLUMN ya-comprobados hacen el script re-ejecutable.

CREATE TABLE IF NOT EXISTS `logs_ia` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `fecha` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `senal` VARCHAR(20) NOT NULL,
  `confianza` DECIMAL(6,4) NOT NULL DEFAULT 0,
  `razon` VARCHAR(400) NOT NULL DEFAULT '',
  `accion_tomada` VARCHAR(50) NOT NULL DEFAULT '',
  `precio` DECIMAL(20,8) NOT NULL DEFAULT 0,
  KEY `idx_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS `logs_acceso` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS `alertas_config` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tipo` VARCHAR(40) NOT NULL,
  `umbral` DECIMAL(12,4) NOT NULL,
  `habilitado` TINYINT(1) NOT NULL DEFAULT 1,
  `telegram_chat_id` VARCHAR(50) NOT NULL DEFAULT '',
  `ultima_notificacion` DATETIME NULL,
  `intervalo_min` INT NOT NULL DEFAULT 30,
  `actualizado_por` INT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_tipo` (`tipo`),
  CONSTRAINT `fk_alertas_admin` FOREIGN KEY (`actualizado_por`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

ALTER TABLE `users`
  ADD COLUMN `totp_secret` VARCHAR(64) NULL AFTER `last_login_at`,
  ADD COLUMN `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `totp_secret`;

ALTER TABLE `grid_configs`
  ADD COLUMN `max_daily_loss` DECIMAL(5,2) NULL AFTER `fee_floor_mode`,
  ADD COLUMN `recovery_loss_pct` DECIMAL(5,2) NULL AFTER `max_daily_loss`;
```

- [ ] **Step 2: Crear `scripts/rollback_gap.sql`**

```sql
-- Rollback del gap de paneles (2026-08-09) — DROP en orden inverso por FKs
ALTER TABLE `alertas_config` DROP FOREIGN KEY `fk_alertas_admin`;
ALTER TABLE `logs_acceso` DROP FOREIGN KEY `fk_logs_acceso_user`;
DROP TABLE IF EXISTS `alertas_config`;
DROP TABLE IF EXISTS `logs_acceso`;
DROP TABLE IF EXISTS `logs_ia`;
ALTER TABLE `grid_configs` DROP COLUMN IF EXISTS `recovery_loss_pct`, DROP COLUMN IF EXISTS `max_daily_loss`;
ALTER TABLE `users` DROP COLUMN IF EXISTS `totp_enabled`, DROP COLUMN IF EXISTS `totp_secret`;
```

- [ ] **Step 3: Escribir el test que falla** — `tests/php/Unit/Core/SchemaGapTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Schema;

class SchemaGapTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT "investor", status TEXT DEFAULT "active", created_at TEXT, last_login_at TEXT)');
        $this->pdo->exec('CREATE TABLE grid_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, symbol TEXT, fee_floor_mode TEXT, enforce_fee_floor INTEGER DEFAULT 1)');
    }

    public function testCreateTablesAddsGapTablesAndColumns(): void
    {
        Schema::createTables($this->pdo);
        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['logs_ia', 'logs_acceso', 'alertas_config'] as $t) {
            $this->assertContains($t, $tables, "tabla $t debe existir");
        }
        $cols = function (string $table): array {
            return array_column($this->pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        };
        $this->assertContains('totp_secret', $cols('users'));
        $this->assertContains('totp_enabled', $cols('users'));
        $this->assertContains('max_daily_loss', $cols('grid_configs'));
        $this->assertContains('recovery_loss_pct', $cols('grid_configs'));
    }

    public function testCreateTablesIsIdempotent(): void
    {
        Schema::createTables($this->pdo);
        Schema::createTables($this->pdo);
        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='logs_ia'")->fetchColumn();
        $this->assertSame(1, $count);
    }
}
```

- [ ] **Step 4: Ejecutar el test para verificar que falla**

Run: `vendor/bin/phpunit tests/php/Unit/Core/SchemaGapTest.php`
Expected: FAIL — `logs_ia` no existe.

- [ ] **Step 5: Implementar `Schema.php` (tablas nuevas + ALTERs idempotentes)**

En `src/php/Core/Schema.php`, añadir al array de `ddl()` (después de la entrada de `admin_audit`):

```php
"CREATE TABLE IF NOT EXISTS logs_ia (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    senal VARCHAR(20) NOT NULL,
    confianza DECIMAL(6,4) NOT NULL DEFAULT 0,
    razon VARCHAR(400) NOT NULL DEFAULT '',
    accion_tomada VARCHAR(50) NOT NULL DEFAULT '',
    precio DECIMAL(20,8) NOT NULL DEFAULT 0,
    INDEX idx_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS logs_acceso (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL,
    username VARCHAR(60) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    resultado ENUM('exitoso','fallido') NOT NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_usuario (usuario_id),
    INDEX idx_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
"CREATE TABLE IF NOT EXISTS alertas_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(40) NOT NULL,
    umbral DECIMAL(12,4) NOT NULL,
    habilitado TINYINT(1) NOT NULL DEFAULT 1,
    telegram_chat_id VARCHAR(50) NOT NULL DEFAULT '',
    ultima_notificacion DATETIME NULL,
    intervalo_min INT NOT NULL DEFAULT 30,
    actualizado_por INT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
```

Y en `createTables()`, tras el try/catch existente de `movements.note`, añadir:

```php
foreach ([
    "ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) NULL AFTER last_login_at",
    "ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret",
    "ALTER TABLE grid_configs ADD COLUMN max_daily_loss DECIMAL(5,2) NULL AFTER fee_floor_mode",
    "ALTER TABLE grid_configs ADD COLUMN recovery_loss_pct DECIMAL(5,2) NULL AFTER max_daily_loss",
] as $sql) {
    try { $pdo->exec($sql); } catch (\Throwable $e) { /* columna ya existe */ }
}
```

- [ ] **Step 6: Actualizar `tests/Support/SqliteSchema.php`** (añadir al final de `apply()`):

```php
$pdo->exec('CREATE TABLE IF NOT EXISTS logs_ia (id INTEGER PRIMARY KEY AUTOINCREMENT, fecha TEXT DEFAULT CURRENT_TIMESTAMP, senal TEXT, confianza REAL DEFAULT 0, razon TEXT DEFAULT "", accion_tomada TEXT DEFAULT "", precio REAL DEFAULT 0)');
$pdo->exec('CREATE TABLE IF NOT EXISTS logs_acceso (id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER, username TEXT, ip TEXT, user_agent TEXT DEFAULT "", resultado TEXT, fecha TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE IF NOT EXISTS alertas_config (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT UNIQUE, umbral REAL NOT NULL, habilitado INTEGER DEFAULT 1, telegram_chat_id TEXT DEFAULT "", ultima_notificacion TEXT, intervalo_min INTEGER DEFAULT 30, actualizado_por INTEGER, updated_at TEXT)');
$pdo->exec('ALTER TABLE users ADD COLUMN totp_secret TEXT');
$pdo->exec('ALTER TABLE users ADD COLUMN totp_enabled INTEGER DEFAULT 0');
```

(Nota: `users` se crea antes dentro de `apply()`, por lo que el `ALTER TABLE` es válido.)

- [ ] **Step 7: Ejecutar el test para verificar que pasa**

Run: `vendor/bin/phpunit tests/php/Unit/Core/SchemaGapTest.php`
Expected: PASS (2 tests).

- [ ] **Step 8: Commit**

```bash
git add scripts/migracion_gap.sql scripts/rollback_gap.sql src/php/Core/Schema.php tests/Support/SqliteSchema.php tests/php/Unit/Core/SchemaGapTest.php
git commit -m "feat(esquema): tablas logs_ia, logs_acceso, alertas_config y columnas 2FA/riesgo con migración + rollback"
```

---

### Task 2: Dependencia otphp + `Core/TwoFactor`

**Files:**
- Modify: `composer.json`, `composer.lock` (vía `composer require`)
- Create: `src/php/Core/TwoFactor.php`
- Test: `tests/php/Unit/Core/TwoFactorTest.php`

**Interfaces:**
- Produces: `BinanceBot\Core\TwoFactor::generateSecret(): string`, `::otpauthUri(string $secret, string $account, string $issuer = 'Grid Bot'): string`, `::verify(string $code, string $secret): bool`. Consumen en AuthHttp (Task 3) y AdminHttp/InvestorHttp (Task 4).

- [ ] **Step 1: Escribir el test que falla** — `tests/php/Unit/Core/TwoFactorTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\TwoFactor;

class TwoFactorTest extends TestCase
{
    public function testGenerateSecretReturnsBase32OfExpectedLength(): void
    {
        $secret = TwoFactor::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertGreaterThanOrEqual(16, strlen($secret));
    }

    public function testVerifyAcceptsCurrentTOTPCode(): void
    {
        $secret = TwoFactor::generateSecret();
        $code = \OTPHP\TOTP::create($secret)->now();
        $this->assertTrue(TwoFactor::verify($code, $secret));
    }

    public function testVerifyRejectsWrongCode(): void
    {
        $secret = TwoFactor::generateSecret();
        $this->assertFalse(TwoFactor::verify('000000', $secret));
    }

    public function testVerifyRejectsEmptyInput(): void
    {
        $this->assertFalse(TwoFactor::verify('', 'ABCDEFGHIJKLMNOP'));
    }

    public function testOtpauthUriContainsAccountAndIssuer(): void
    {
        $uri = TwoFactor::otpauthUri('ABCDEFGHIJKLMNOP', 'admin@grid.com', 'Grid Bot');
        $this->assertStringContainsString('otpauth://totp/', $uri);
        $this->assertStringContainsString('issuer=Grid%20Bot', $uri);
        $this->assertStringContainsString('secret=ABCDEFGHIJKLMNOP', $uri);
    }
}
```

- [ ] **Step 2: Ejecutar el test para verificar que falla**

Run: `vendor/bin/phpunit tests/php/Unit/Core/TwoFactorTest.php`
Expected: FAIL — clase `TwoFactor` no encontrada.

- [ ] **Step 3: Instalar la dependencia**

```bash
composer require spomky-labs/otphp:^11.2
```

- [ ] **Step 4: Implementar `src/php/Core/TwoFactor.php`**

```php
<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use OTPHP\TOTP;

/**
 * Envoltorio de OTPHP para 2FA TOTP (RFC 6238).
 * Compatible con Google Authenticator, Authy, 1Password, etc.
 */
class TwoFactor
{
    public static function generateSecret(): string
    {
        return TOTP::generateSecret();
    }

    public static function otpauthUri(string $secret, string $account, string $issuer = 'Grid Bot'): string
    {
        $otp = TOTP::create($secret);
        $otp->setIssuer($issuer);
        return $otp->getProvisioningUri($account);
    }

    public static function verify(string $code, string $secret): bool
    {
        if ($code === '' || $secret === '') {
            return false;
        }
        $current = TOTP::create($secret)->now();
        return hash_equals($current, trim($code));
    }
}
```

- [ ] **Step 5: Ejecutar el test para verificar que pasa**

Run: `vendor/bin/phpunit tests/php/Unit/Core/TwoFactorTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock src/php/Core/TwoFactor.php tests/php/Unit/Core/TwoFactorTest.php
git commit -m "feat(2fa): dependencia otphp y envoltorio Core/TwoFactor (TOTP RFC 6238)"
```

---

### Task 3: `logs_acceso` + paso 2FA en el login (`AuthHttp` + `auth.php`)

**Files:**
- Create: `src/php/Core/LogAccess.php`
- Modify: `src/php/Core/Auth.php` (añadir `getUserById`)
- Modify: `src/php/Core/AuthHttp.php` (paso 2FA + registro `logs_acceso`)
- Modify: `src/php/auth.php` (render del paso 2FA)
- Test: `tests/php/Unit/Core/LogAccessTest.php`, `tests/php/Unit/Core/AuthHttpTest.php`

**Interfaces:**
- Consumes: `TwoFactor::verify()`, `LogAccess::record()`.
- Produces: `LogAccess::record(PDO $pdo, ?int $userId, string $username, string $ip, string $userAgent, string $resultado): void`; `Auth::getUserById(PDO $pdo, int $id): ?array`; flujo 2FA en `AuthHttp::handle` con view `'2fa'` y `$session['pending_2fa'] = ['user_id'=>int,'username'=>string,'role'=>string,'redirect'=>string]`.

> **Ajuste del spec:** la pantalla de login real es `src/php/auth.php` (a la que redirige `admin.php`); `login.php` es una página legacy separada. El paso 2FA se implementa en `auth.php`/`AuthHttp` (producción). `login.php` queda intacto.

- [ ] **Step 1: Escribir los tests que fallan**

`tests/php/Unit/Core/LogAccessTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\LogAccess;
use Tests\Support\SqliteSchema;

class LogAccessTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
    }

    public function testRecordInsertsRow(): void
    {
        LogAccess::record($this->pdo, 7, 'juan', '1.2.3.4', 'curl/8', 'exitoso');
        $row = $this->pdo->query("SELECT * FROM logs_acceso")->fetch();
        $this->assertSame(7, (int)$row['usuario_id']);
        $this->assertSame('juan', $row['username']);
        $this->assertSame('exitoso', $row['resultado']);
    }

    public function testRecordAcceptsNullUser(): void
    {
        LogAccess::record($this->pdo, null, 'desconocido', '1.2.3.4', '', 'fallido');
        $row = $this->pdo->query("SELECT * FROM logs_acceso")->fetch();
        $this->assertNull($row['usuario_id']);
        $this->assertSame('fallido', $row['resultado']);
    }
}
```

En `tests/php/Unit/Core/AuthHttpTest.php`, añadir (el `setUp` existente ya crea las tablas con `SqliteSchema`):

```php
public function testLoginWithTwoFactorRequiresVerificationStep(): void
{
    $secret = \BinanceBot\Core\TwoFactor::generateSecret();
    $this->pdo->prepare("INSERT INTO users (username, email, password_hash, role, status, totp_secret, totp_enabled) VALUES (?, ?, ?, 'admin', 'active', ?, 1)")
        ->execute(['boss', 'boss@e.com', password_hash('secreto123', PASSWORD_BCRYPT), $secret]);
    $session = [];
    $post = ['action' => 'login', 'username' => 'boss', 'password' => 'secreto123', 'csrf' => Csrf::token($session)];
    $out = AuthHttp::handle($this->pdo, $session, [], $post, '1.2.3.4');
    $this->assertSame('2fa', $out['view']);
    $this->assertArrayNotHasKey('user_id', $session);
    $this->assertSame('boss', $session['pending_2fa']['username']);
}

public function testVerifyTwoFactorCompletesLogin(): void
{
    $secret = \BinanceBot\Core\TwoFactor::generateSecret();
    $this->pdo->prepare("INSERT INTO users (username, email, password_hash, role, status, totp_secret, totp_enabled) VALUES (?, ?, ?, 'admin', 'active', ?, 1)")
        ->execute(['boss', 'boss@e.com', password_hash('secreto123', PASSWORD_BCRYPT), $secret]);
    $userId = (int)$this->pdo->lastInsertId();
    $session = ['pending_2fa' => ['user_id' => $userId, 'username' => 'boss', 'role' => 'admin', 'redirect' => 'src/php/index.php']];
    $code = \OTPHP\TOTP::create($secret)->now();
    $post = ['action' => 'verify_2fa', 'code' => $code, 'csrf' => Csrf::token($session)];
    $out = AuthHttp::handle($this->pdo, $session, [], $post, '1.2.3.4');
    $this->assertSame('src/php/index.php', $out['redirect']);
    $this->assertSame($userId, $session['user_id']);
    $this->assertArrayNotHasKey('pending_2fa', $session);
    $row = $this->pdo->query("SELECT resultado FROM logs_acceso ORDER BY id DESC LIMIT 1")->fetch();
    $this->assertSame('exitoso', $row['resultado']);
}

public function testVerifyTwoFactorWrongCodeShowsError(): void
{
    $secret = \BinanceBot\Core\TwoFactor::generateSecret();
    $this->pdo->prepare("INSERT INTO users (username, email, password_hash, role, status, totp_secret, totp_enabled) VALUES (?, ?, ?, 'admin', 'active', ?, 1)")
        ->execute(['boss', 'boss@e.com', password_hash('secreto123', PASSWORD_BCRYPT), $secret]);
    $userId = (int)$this->pdo->lastInsertId();
    $session = ['pending_2fa' => ['user_id' => $userId, 'username' => 'boss', 'role' => 'admin', 'redirect' => 'src/php/index.php']];
    $post = ['action' => 'verify_2fa', 'code' => '000000', 'csrf' => Csrf::token($session)];
    $out = AuthHttp::handle($this->pdo, $session, [], $post, '1.2.3.4');
    $this->assertSame('Código 2FA incorrecto', $out['error']);
    $this->assertArrayNotHasKey('user_id', $session);
    $row = $this->pdo->query("SELECT resultado FROM logs_acceso ORDER BY id DESC LIMIT 1")->fetch();
    $this->assertSame('fallido', $row['resultado']);
}
```

- [ ] **Step 2: Ejecutar los tests para verificar que fallan**

Run: `vendor/bin/phpunit tests/php/Unit/Core/LogAccessTest.php tests/php/Unit/Core/AuthHttpTest.php`
Expected: FAIL — `LogAccess` no existe y el flujo 2FA no está.

- [ ] **Step 3: Implementar `src/php/Core/LogAccess.php`**

```php
<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

/**
 * Registro de accesos al sistema (logins exitosos y fallidos).
 * Independiente de login_attempts, que sigue como protección anti-brute-force.
 */
class LogAccess
{
    public static function record(PDO $pdo, ?int $userId, string $username, string $ip, string $userAgent, string $resultado): void
    {
        $stmt = $pdo->prepare('INSERT INTO logs_acceso (usuario_id, username, ip, user_agent, resultado) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, mb_substr($username, 0, 60), mb_substr($ip, 0, 45), mb_substr($userAgent, 0, 255), $resultado]);
    }
}
```

- [ ] **Step 4: Añadir `Auth::getUserById`** (en `src/php/Core/Auth.php`, tras `login()`):

```php
public static function getUserById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    return $user ?: null;
}
```

- [ ] **Step 5: Implementar el flujo 2FA en `src/php/Core/AuthHttp.php`**

Reemplazar el bloque `if ($action === 'login')` y añadir el manejo de `verify_2fa`. Versión final de `handle()`:

```php
public static function handle(PDO $pdo, array &$session, array $get, array $post, string $ip): array
{
    $action = (string)($get['action'] ?? ($post['action'] ?? ''));
    $isPost = $post !== [];
    if ($isPost && !Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
        return ['redirect' => null, 'error' => 'Token CSRF inválido', 'view' => 'login'];
    }
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    if ($action === 'register') {
        $res = Auth::register($pdo, (string)($post['username'] ?? ''), (string)($post['email'] ?? ''), (string)($post['password'] ?? ''));
        if ($res['ok']) {
            $session['user_id'] = $res['user_id'];
            $session['username'] = (string)($post['username'] ?? '');
            $session['role'] = 'investor';
            LogAccess::record($pdo, (int)$res['user_id'], (string)($post['username'] ?? ''), $ip, $ua, 'exitoso');
            return ['redirect' => 'panel.php', 'view' => 'login', 'error' => null];
        }
        return ['redirect' => null, 'error' => $res['error'], 'view' => 'register'];
    }

    if ($action === 'login') {
        if (!Auth::checkRateLimit($pdo, $ip, 'login', 10, 900)) {
            return ['redirect' => null, 'error' => 'Demasiados intentos. Espera unos minutos.', 'view' => 'login'];
        }
        $user = Auth::login($pdo, (string)($post['username'] ?? ''), (string)($post['password'] ?? ''));
        Auth::recordAttempt($pdo, $ip, 'login', (string)($post['username'] ?? ''), $user !== null);
        if ($user) {
            if (!empty($user['totp_enabled'])) {
                $session['pending_2fa'] = [
                    'user_id' => (int)$user['id'],
                    'username' => (string)$user['username'],
                    'role' => (string)$user['role'],
                    'redirect' => ($user['role'] === 'admin') ? 'src/php/index.php' : 'panel.php',
                ];
                return ['redirect' => null, 'view' => '2fa', 'error' => null];
            }
            session_regenerate_id(true);
            $session['user_id'] = (int)$user['id'];
            $session['username'] = (string)$user['username'];
            $session['role'] = (string)$user['role'];
            LogAccess::record($pdo, (int)$user['id'], (string)$user['username'], $ip, $ua, 'exitoso');
            $redirect = ($user['role'] === 'admin') ? 'src/php/index.php' : 'panel.php';
            return ['redirect' => $redirect, 'view' => 'login', 'error' => null];
        }
        LogAccess::record($pdo, null, (string)($post['username'] ?? ''), $ip, $ua, 'fallido');
        return ['redirect' => null, 'error' => 'Usuario o contraseña incorrectos', 'view' => 'login'];
    }

    if ($action === 'verify_2fa') {
        if (empty($session['pending_2fa'])) {
            return ['redirect' => null, 'error' => 'Sesión expirada. Vuelve a ingresar.', 'view' => 'login'];
        }
        $pending = $session['pending_2fa'];
        $user = Auth::getUserById($pdo, (int)$pending['user_id']);
        if (!$user || !TwoFactor::verify((string)($post['code'] ?? ''), (string)$user['totp_secret'])) {
            LogAccess::record($pdo, (int)$pending['user_id'], (string)$pending['username'], $ip, $ua, 'fallido');
            return ['redirect' => null, 'error' => 'Código 2FA incorrecto', 'view' => '2fa'];
        }
        unset($session['pending_2fa']);
        session_regenerate_id(true);
        $session['user_id'] = (int)$user['id'];
        $session['username'] = (string)$user['username'];
        $session['role'] = (string)$user['role'];
        LogAccess::record($pdo, (int)$user['id'], (string)$user['username'], $ip, $ua, 'exitoso');
        return ['redirect' => (string)$pending['redirect'], 'view' => 'login', 'error' => null];
    }

    return ['redirect' => null, 'error' => null, 'view' => 'login'];
}
```

- [ ] **Step 6: Render del paso 2FA en `src/php/auth.php`**

Reemplazar la línea `$view = $result['view'] === 'register' ? 'register' : 'login';` y el bloque `else` del formulario por:

```php
$view = match ($result['view']) {
    'register' => 'register',
    '2fa' => '2fa',
    default => 'login',
};
```

Y sustituir la sección `<div class="alt">¿Sin cuenta? ...</div>` del else por un formulario condicional. El bloque `else` completo pasa a ser:

```php
<?php else: ?>
    <?php if ($view === '2fa'): ?>
    <form method="post">
        <input type="hidden" name="action" value="verify_2fa">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <label>Código de verificación (6 dígitos)</label>
        <input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" autofocus>
        <button type="submit">Verificar</button>
    </form>
    <?php else: ?>
    <form method="post">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <label>Usuario</label><input name="username" required autocomplete="username">
        <label>Contraseña</label><input name="password" type="password" required autocomplete="current-password">
        <button type="submit">Ingresar</button>
    </form>
    <?php endif; ?>
<?php endif; ?>
```

- [ ] **Step 7: Ejecutar los tests para verificar que pasan**

Run: `vendor/bin/phpunit tests/php/Unit/Core/LogAccessTest.php tests/php/Unit/Core/AuthHttpTest.php`
Expected: PASS (2 + 7 tests).

- [ ] **Step 8: Smoke manual del login**

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://binance.gregorbritez.cat/src/php/auth.php
```
Expected: `200`. Sin sesión, `admin.php` debe seguir redirigiendo a `auth.php` (302).

- [ ] **Step 9: Commit**

```bash
git add src/php/Core/LogAccess.php src/php/Core/Auth.php src/php/Core/AuthHttp.php src/php/auth.php tests/php/Unit/Core/LogAccessTest.php tests/php/Unit/Core/AuthHttpTest.php
git commit -m "feat(2fa): paso TOTP en login de auth.php + registro de accesos (logs_acceso)"
```

---

### Task 4: Activación/desactivación de 2FA en paneles (admin + inversor)

**Files:**
- Modify: `src/php/Core/AdminHttp.php`, `src/php/Core/InvestorHttp.php`
- Modify: `src/php/admin.php` (tab Ajustes), `src/php/panel.php` (Perfil)
- Test: `tests/php/Unit/Core/AdminHttpTest.php`, `tests/php/Unit/Core/InvestorHttpTest.php`

**Interfaces:**
- Produces (ambos handlers): acciones `enable_2fa`, `confirm_2fa`, `disable_2fa`. `enable_2fa` devuelve en `data` la clave `two_factor` con `secret` + `qr` (URL `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=...` — no hay `gd` en el servidor). `confirm_2fa` valida el código contra el secreto pendiente y activa (`totp_enabled=1`, guarda `totp_secret`). `disable_2fa` valida código y desactiva.
- El secreto pendiente se guarda en la sesión como `$session['pending_2fa_secret']` hasta que `confirm_2fa` lo persista en la BD.

- [ ] **Step 1: Escribir los tests que fallan**

En `tests/php/Unit/Core/InvestorHttpTest.php`, añadir (el `setUp` existente crea sesión + usuario con `SqliteSchema`):

```php
public function testEnableTwoFactorReturnsSecretAndQr(): void
{
    $out = InvestorHttp::handle($this->pdo, $this->session, [], ['action' => 'enable_2fa']);
    $this->assertArrayHasKey('two_factor', $out['data']);
    $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $out['data']['two_factor']['secret']);
    $this->assertStringContainsString('api.qrserver.com', $out['data']['two_factor']['qr']);
}

public function testConfirmTwoFactorActivatesIt(): void
{
    $out = InvestorHttp::handle($this->pdo, $this->session, [], ['action' => 'enable_2fa']);
    $secret = $out['data']['two_factor']['secret'];
    $code = \OTPHP\TOTP::create($secret)->now();
    $out2 = InvestorHttp::handle($this->pdo, $this->session, [], ['action' => 'confirm_2fa', 'code' => $code]);
    $this->assertSame('2FA activada correctamente', $out2['data']['flash'] ?? $out2['flash'] ?? '');
    $row = $this->pdo->query('SELECT totp_enabled, totp_secret FROM users WHERE id = ' . (int)$this->session['user_id'])->fetch();
    $this->assertSame(1, (int)$row['totp_enabled']);
    $this->assertSame($secret, $row['totp_secret']);
}

public function testConfirmTwoFactorWrongCodeDoesNotActivate(): void
{
    $out = InvestorHttp::handle($this->pdo, $this->session, [], ['action' => 'enable_2fa']);
    $out2 = InvestorHttp::handle($this->pdo, $this->session, [], ['action' => 'confirm_2fa', 'code' => '000000']);
    $this->assertSame('Código incorrecto', $out2['error']);
    $row = $this->pdo->query('SELECT totp_enabled FROM users WHERE id = ' . (int)$this->session['user_id'])->fetch();
    $this->assertSame(0, (int)$row['totp_enabled']);
}

public function testDisableTwoFactorRequiresCode(): void
{
    $this->pdo->exec('UPDATE users SET totp_enabled = 1, totp_secret = "ABCDEFGHIJKLMNOP" WHERE id = ' . (int)$this->session['user_id']);
    $code = \OTPHP\TOTP::create('ABCDEFGHIJKLMNOP')->now();
    $out = InvestorHttp::handle($this->pdo, $this->session, [], ['action' => 'disable_2fa', 'code' => $code]);
    $this->assertSame('2FA desactivada', $out['data']['flash'] ?? $out['flash'] ?? '');
    $row = $this->pdo->query('SELECT totp_enabled FROM users WHERE id = ' . (int)$this->session['user_id'])->fetch();
    $this->assertSame(0, (int)$row['totp_enabled']);
}
```

Análogamente en `tests/php/Unit/Core/AdminHttpTest.php`: mismo juego de 4 tests pero con `AdminHttp::handle($this->pdo, $this->session, $post)` (firma real de AdminHttp). **Verificar la firma exacta de `AdminHttp::handle` antes de escribir** (usa `?callable $sendDirect`/`?RpcClient` — los tests existentes ya la cubren).

- [ ] **Step 2: Ejecutar los tests para verificar que fallan**

Run: `vendor/bin/phpunit tests/php/Unit/Core/InvestorHttpTest.php tests/php/Unit/Core/AdminHttpTest.php`
Expected: FAIL — las acciones 2FA no existen.

- [ ] **Step 3: Implementar acciones 2FA en `InvestorHttp::handle`**

Añadir al inicio del `switch ($action)`:

```php
case 'enable_2fa':
    $secret = TwoFactor::generateSecret();
    $session['pending_2fa_secret'] = $secret;
    $uri = TwoFactor::otpauthUri($secret, (string)$session['username'], 'Grid Bot');
    return ['view' => 'perfil', 'data' => [
        'two_factor' => [
            'secret' => $secret,
            'qr' => 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($uri),
        ],
    ], 'error' => null];

case 'confirm_2fa':
    $secret = (string)($session['pending_2fa_secret'] ?? '');
    if ($secret === '' || !TwoFactor::verify((string)($post['code'] ?? ''), $secret)) {
        return ['view' => 'perfil', 'data' => [], 'error' => 'Código incorrecto'];
    }
    $stmt = $pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?');
    $stmt->execute([$secret, (int)$session['user_id']]);
    unset($session['pending_2fa_secret']);
    return ['view' => 'perfil', 'data' => ['flash' => '2FA activada correctamente'], 'error' => null];

case 'disable_2fa':
    $stmt = $pdo->prepare('SELECT totp_secret FROM users WHERE id = ?');
    $stmt->execute([(int)$session['user_id']]);
    $dbSecret = (string)($stmt->fetchColumn() ?: '');
    if (!TwoFactor::verify((string)($post['code'] ?? ''), $dbSecret)) {
        return ['view' => 'perfil', 'data' => [], 'error' => 'Código incorrecto'];
    }
    $stmt = $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?');
    $stmt->execute([(int)$session['user_id']]);
    return ['view' => 'perfil', 'data' => ['flash' => '2FA desactivada'], 'error' => null];
```

Y en el case de `perfil`, añadir a `$data`:
```php
'2fa_enabled' => (int)($user['totp_enabled'] ?? 0) === 1,
```

- [ ] **Step 4: Implementar acciones 2FA en `AdminHttp::handle`** (mismas acciones; para admin **obligatorio**: `enable_2fa` solo se ofrece si aún no está activada).

- [ ] **Step 5: UI en `panel.php` (Perfil)** — dentro de la sección del usuario logueado:

```php
<?php if (empty($data['2fa_enabled'])): ?>
<div class="card">
  <h3>Autenticación de dos factores</h3>
  <p>Escanéa el código QR con Google Authenticator (o añade el secreto manualmente) y verifica un código para activarlo.</p>
  <?php if (isset($data['two_factor'])): ?>
    <img src="<?= htmlspecialchars($data['two_factor']['qr']) ?>" width="220" height="220" alt="QR 2FA">
    <p><code><?= htmlspecialchars($data['two_factor']['secret']) ?></code></p>
    <form method="post" class="inline"><input type="hidden" name="action" value="confirm_2fa">
      <input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required placeholder="Código de 6 dígitos">
      <button type="submit">Activar</button></form>
  <?php else: ?>
    <form method="post"><input type="hidden" name="action" value="enable_2fa"><button type="submit">Activar 2FA</button></form>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="card">
  <h3>Autenticación de dos factores</h3>
  <p>Activa. Para desactivarla verifica un código.</p>
  <form method="post" class="inline"><input type="hidden" name="action" value="disable_2fa">
    <input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required placeholder="Código de 6 dígitos">
    <button type="submit">Desactivar</button></form>
</div>
<?php endif; ?>
```

- [ ] **Step 6: UI en `admin.php` (tab Ajustes)** — sección equivalente (obligatorio; el form nunca se muestra si `2fa_enabled`).

- [ ] **Step 7: Ejecutar los tests para verificar que pasan**

Run: `vendor/bin/phpunit tests/php/Unit/Core/InvestorHttpTest.php tests/php/Unit/Core/AdminHttpTest.php`
Expected: PASS (tests previos + 8 nuevos).

- [ ] **Step 8: Smoke manual**

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://binance.gregorbritez.cat/src/php/admin.php   # 302 sin sesión
curl -s -o /dev/null -w "%{http_code}\n" https://binance.gregorbritez.cat/src/php/panel.php    # 302 sin sesión
```
Con sesión admin (E2E opcional): la pestaña Ajustes debe mostrar el botón "Activar 2FA".

- [ ] **Step 9: Commit**

```bash
git add src/php/Core/AdminHttp.php src/php/Core/InvestorHttp.php src/php/admin.php src/php/panel.php tests/php/Unit/Core/AdminHttpTest.php tests/php/Unit/Core/InvestorHttpTest.php
git commit -m "feat(2fa): activación/desactivación TOTP desde paneles admin e inversor"
```

---

### Task 5: Hooks de GridManager (riesgo en vivo, logs IA, alertas)

**Files:**
- Modify: `src/php/Strategy/GridManager.php`
- Modify: `src/php/grid_ajax.php` (ampliar allowed-list de `update_config`)
- Modify: `src/php/bot.php` (inicializar `alertas_config` seed + verificar token Telegram) — **solo si es imprescindible; revisar antes**
- Test: `tests/php/Unit/Strategy/GridManagerTest.php` (añadir métodos), `tests/php/Unit/Strategy/RiskConfigTest.php` (nuevo)

**Interfaces:**
- Consumes: `Core\Notification::sendTelegram()` (Task 6 — definir primero la firma; o crear `Notification` en esta task para no bloquear).
- Produces:
  - `GridManager::applyRiskConfig(PDO $pdo): void` — lee `grid_configs` del símbolo activo; para `max_daily_loss`/`recovery_loss_pct`, si el valor DB es NULL usa las constantes `G_MAX_DAILY_LOSS`/`G_RECOVERY_LOSS_PCT`; actualiza el estado en memoria del manager. Se llama al inicio de cada ciclo en `run()` (junto a `checkControl()`).
  - `GridManager::persistAiDecision(PDO $pdo, array $decision): void` — inserta en `logs_ia`. Se llama dentro de `aiEvaluate()` cuando hay decisión.
  - `GridManager::notifyIfAlert(PDO $pdo): void` — evalúa `alertas_config` habilitadas contra estado en memoria; respeta `intervalo_min` actualizando `ultima_notificacion`; si hay `telegram_bot_token` en `bot_meta` envía vía `Notification::sendTelegram`, si no, deja el aviso en `logs_ia` con `senal='ALERTA'`.
  - `applyConfigUpdate()`: añadir a la allowed-list `recovery_active`, `max_daily_loss`, `recovery_loss_pct` (validados: `recovery_active` booleano; `max_daily_loss`/`recovery_loss_pct` en `[0.5, 50]` o NULL=constante) además de los 6 campos actuales.

- [ ] **Step 1: Escribir los tests que fallan**

En `tests/php/Unit/Strategy/GridManagerTest.php`, añadir (siguiendo el patrón de stubs existente de las líneas 585-720: `lI/lW/lE/lg`, `dbx`, `define('G_*')`):

```php
public function testApplyRiskConfigUsesDbValueWhenNotNull(): void
{
    $pdo = $this->createMock(\PDO::class); // stubs para SELECT grid_configs
    // configurar stubs: max_daily_loss = 12.5, recovery_loss_pct = 4.0
    $gm = new \BinanceBot\Strategy\GridManager();
    $gm->applyRiskConfig($pdo);
    // verificar que $gm->getState() refleja maxDailyLoss=12.5, recoveryLossPct=4.0
    $this->assertSame(12.5, $gm->getRiskMaxDailyLoss());
    $this->assertSame(4.0, $gm->getRiskRecoveryLossPct());
}

public function testApplyRiskConfigUsesConstantsWhenNull(): void
{
    // stubs devuelven NULL para ambos campos
    $gm = new \BinanceBot\Strategy\GridManager();
    $gm->applyRiskConfig($pdo);
    $this->assertSame(G_MAX_DAILY_LOSS, $gm->getRiskMaxDailyLoss());
    $this->assertSame(G_RECOVERY_LOSS_PCT, $gm->getRiskRecoveryLossPct());
}

public function testPersistAiDecisionInsertsLog(): void
{
    // stub dbx con método prepare + insert
    $gm = new \BinanceBot\Strategy\GridManager();
    $gm->persistAiDecision($pdo, ['senal' => 'LONG', 'confianza' => 0.81, 'razon' => 'Tendencia alcista', 'accion_tomada' => 'enter', 'precio' => 3200.5]);
    // verificar insert en logs_ia con esos valores
}

public function testNotifyIfAlertSkipsWhenBelowThreshold(): void
{
    // alertas_config con tipo 'drawdown_pct' umbral 30, estado en memoria drawdown 5% -> sin notificacion
    $gm->notifyIfAlert($pdo);
    // verificar que no se llamó a sendTelegram (stub de bot_meta sin token) y que ultima_notificacion quedó NULL
}

public function testNotifyIfAlertSendsWhenThresholdExceeded(): void
{
    // estado drawdown > umbral; bot_meta con telegram_bot_token; esperar Notification::sendTelegram llamado 1 vez
}
```

Si los getters no existen, definirlos (solos para estado interno de riesgo, no alteran el resto del manager).

- [ ] **Step 2: Ejecutar los tests para verificar que fallan**

Run: `vendor/bin/phpunit tests/php/Unit/Strategy/GridManagerTest.php`
Expected: FAIL — métodos no existen.

- [ ] **Step 3: Implementar los métodos en `src/php/Strategy/GridManager.php`**

```php
public function applyRiskConfig(\PDO $pdo): void
{
    $config = $this->getActiveConfig(); // array del grid activo ya cargado
    $max = $config['max_daily_loss'] ?? null;
    $rec = $config['recovery_loss_pct'] ?? null;
    $this->riskMaxDailyLoss = ($max !== null && $max !== '') ? (float)$max : G_MAX_DAILY_LOSS;
    $this->riskRecoveryLossPct = ($rec !== null && $rec !== '') ? (float)$rec : G_RECOVERY_LOSS_PCT;
    // O: si grid_configs no está en memoria, leer de BD con SELECT preparado.
}
```

Adaptar a la realidad del código: **revisar cómo `GridManager` accede a su config activa y a la BD** (usa el patrón `dbx` global de `GridManagerTest`). Si `GridManager` no tiene una propiedad de config en memoria, `applyRiskConfig` debe leer `grid_configs` con un `SELECT max_daily_loss, recovery_loss_pct FROM grid_configs WHERE symbol = ?` (con el símbolo del manager) y almacenar los valores en propiedades privadas nuevas; `riskCheck()` debe usar esas propiedades en lugar de las constantes. **No romper el comportamiento cuando los campos son NULL** (debe caer a las constantes).

```php
public function persistAiDecision(\PDO $pdo, array $decision): void
{
    $stmt = $pdo->prepare('INSERT INTO logs_ia (senal, confianza, razon, accion_tomada, precio) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        mb_substr((string)($decision['senal'] ?? ''), 0, 20),
        (float)($decision['confianza'] ?? 0),
        mb_substr((string)($decision['razon'] ?? ''), 0, 400),
        mb_substr((string)($decision['accion_tomada'] ?? ''), 0, 50),
        (float)($decision['precio'] ?? 0),
    ]);
}

public function notifyIfAlert(\PDO $pdo): void
{
    $rows = $pdo->query('SELECT * FROM alertas_config WHERE habilitado = 1')->fetchAll();
    $state = $this->getStateForAlerts(); // drawdown %, pnl hoy %, distancia a liquidación, saldo
    foreach ($rows as $row) {
        $value = $state[$row['tipo']] ?? null;
        if ($value === null) continue;
        $intervalOk = true;
        if (!empty($row['ultima_notificacion'])) {
            $min = max(1, (int)$row['intervalo_min']);
            $intervalOk = (time() - strtotime((string)$row['ultima_notificacion'])) >= ($min * 60);
        }
        if (!$intervalOk) continue;
        if ($value <= (float)$row['umbral']) continue; // alerta cuando supera el umbral
        $token = $this->getTelegramToken($pdo); // bot_meta('telegram_bot_token')
        $text = sprintf('[%s] %s superó el umbral: %.2f (umbral %.4f)',
            date('Y-m-d H:i:s'), $row['tipo'], $value, (float)$row['umbral']);
        if ($token !== '' && !empty($row['telegram_chat_id'])) {
            Notification::sendTelegram($token, (string)$row['telegram_chat_id'], $text);
        }
        $stmt = $pdo->prepare('UPDATE alertas_config SET ultima_notificacion = NOW() WHERE id = ?');
        $stmt->execute([(int)$row['id']]);
    }
}
```

Los métodos `getStateForAlerts()` y `getTelegramToken()` son privados y mapean el estado en memoria a los tipos `drawdown_pct|daily_loss_pct|distancia_liquidacion_pct|saldo_min_usd`.

- [ ] **Step 4: Conectar en `run()`** — junto al `checkControl()` existente, al inicio de cada ciclo:

```php
$this->applyRiskConfig($pdo);
$this->notifyIfAlert($pdo);
```

Y en `aiEvaluate()`, justo antes de devolver la decisión:

```php
if ($decision !== null) {
    $this->persistAiDecision($pdo, $decision);
}
```

- [ ] **Step 5: Hacer que `riskCheck()` use el estado dinámico**

Reemplazar las referencias a `G_MAX_DAILY_LOSS`/`G_RECOVERY_LOSS_PCT` por `$this->riskMaxDailyLoss`/`$this->riskRecoveryLossPct` (inicializadas por defecto con las constantes en el constructor para no cambiar el comportamiento actual cuando nunca se llama `applyRiskConfig`).

- [ ] **Step 6: Ampliar allowed-list de `applyConfigUpdate()`**

En `src/php/Strategy/GridManager.php` (dentro de `applyConfigUpdate`, ~línea 1130), añadir a la lista de campos permitidos:

```php
'recovery_active' => ['cancel' => true],
'max_daily_loss'  => ['cancel' => true], // NULL = usar config.json
'recovery_loss_pct' => ['cancel' => true],
```

Y en `src/php/grid_ajax.php` (`update_config`, líneas 400-428), ampliar la validación de entrada:

```php
$allowed = ['capital_usd','leverage','levels','long_levels','short_levels','spacing_pct',
            'recovery_active','max_daily_loss','recovery_loss_pct'];
if (isset($changes['recovery_active'])) $changes['recovery_active'] = $changes['recovery_active'] ? 1 : 0;
foreach (['max_daily_loss','recovery_loss_pct'] as $k) {
    if (isset($changes[$k]) && $changes[$k] !== '' && $changes[$k] !== null) {
        $v = (float)$changes[$k];
        if ($v < 0.5 || $v > 50) return ['error' => "$k fuera de rango [0.5, 50]"];
        $changes[$k] = $v;
    }
}
```

- [ ] **Step 7: Ejecutar los tests para verificar que pasan**

Run: `vendor/bin/phpunit tests/php/Unit/Strategy/GridManagerTest.php`
Expected: PASS (tests previos + 5 nuevos, sin regresión).

- [ ] **Step 8: Smoke de la edición de riesgo**

Con sesión admin (curl con cookie), `grid_ajax.php` `_control` + `update_config` con `max_daily_loss=15`:
```bash
curl -s -b cookies.txt -X POST https://binance.gregorbritez.cat/src/php/grid_ajax.php \
  -d "action=_control&t=update_config&max_daily_loss=15&csrf=$CSRF"
```
Expected: respuesta de éxito. Verificar que `private/grid_control.json` contiene `max_daily_loss: 15`.

- [ ] **Step 9: Commit**

```bash
git add src/php/Strategy/GridManager.php src/php/grid_ajax.php tests/php/Unit/Strategy/GridManagerTest.php tests/php/Unit/Strategy/RiskConfigTest.php
git commit -m "feat(bot): riesgo editable en vivo + logs_ia por decisión de IA + alertas por umbral (applyRiskConfig/persistAiDecision/notifyIfAlert)"
```

---

### Task 6: `Core/Notification` (Telegram) + CRUD de alertas

**Files:**
- Create: `src/php/Core/Notification.php`
- Modify: `src/php/Core/AdminHttp.php` (acciones `alertas_list`, `alerta_save`, `alerta_delete`, `test_telegram`, `set_telegram_token`)
- Modify: `src/php/admin.php` (tab Alertas)
- Test: `tests/php/Unit/Core/NotificationTest.php`, `tests/php/Unit/Core/AlertasConfigTest.php`

**Interfaces:**
- Produces: `Notification::sendTelegram(string $token, string $chatId, string $text): bool` (usa cURL `https://api.telegram.org/bot{token}/sendMessage`, JSON, `chat_id` + `text` + `parse_mode='HTML'`, timeout 8s; `false` ante error HTTP). Acciones CRUD en `AdminHttp` + persistencia de token en `bot_meta('telegram_bot_token')` y chat_id por regla en `alertas_config.telegram_chat_id`.
- Consumes: `AdminHttp` (existe), tab Alertas nuevo en `admin.php`.

- [ ] **Step 1: Escribir los tests que fallan**

`tests/php/Unit/Core/NotificationTest.php` (con servidor HTTP de prueba vía `curl_setopt(CURLOPT_URL)` apuntando a un handler PHP local, o `file_get_contents` mokeado; verificar el comportamiento de parseo de respuesta):

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Notification;

class NotificationTest extends TestCase
{
    public function testSendTelegramReturnsFalseOnEmptyChatId(): void
    {
        $this->assertFalse(Notification::sendTelegram('token', '', 'hola'));
    }

    public function testSendTelegramReturnsFalseOnEmptyToken(): void
    {
        $this->assertFalse(Notification::sendTelegram('', '123', 'hola'));
    }
}
```

`tests/php/Unit/Core/AlertasConfigTest.php` (CRUD vía `AdminHttp` con sesión admin + `SqliteSchema`):

```php
public function testAlertasListReturnsConfiguredRows(): void
{
    $this->pdo->exec("INSERT INTO alertas_config (tipo, umbral) VALUES ('drawdown_pct', 30)");
    $out = AdminHttp::handle($this->pdo, $this->adminSession, ['action' => 'alertas_list']);
    $this->assertSame('drawdown_pct', $out['data']['alertas'][0]['tipo']);
    $this->assertSame('30', (string)$out['data']['alertas'][0]['umbral']);
}

public function testAlertaSaveUpserts(): void
{
    $out = AdminHttp::handle($this->pdo, $this->adminSession, [
        'action' => 'alerta_save', 'tipo' => 'daily_loss_pct', 'umbral' => '5',
        'habilitado' => '1', 'telegram_chat_id' => '777', 'intervalo_min' => '60',
    ]);
    $this->assertSame('Alerta guardada', $out['data']['flash'] ?? $out['flash'] ?? '');
    $row = $this->pdo->query("SELECT * FROM alertas_config WHERE tipo = 'daily_loss_pct'")->fetch();
    $this->assertSame('5', (string)$row['umbral']);
    $this->assertSame('777', $row['telegram_chat_id']);
    $this->assertSame(60, (int)$row['intervalo_min']);
}

public function testAlertaSaveRejectsUnknownTipo(): void
{
    $out = AdminHttp::handle($this->pdo, $this->adminSession, ['action' => 'alerta_save', 'tipo' => 'foo', 'umbral' => '1']);
    $this->assertSame('Tipo de alerta no válido', $out['error']);
}

public function testAlertaDeleteRemovesRow(): void
{
    $this->pdo->exec("INSERT INTO alertas_config (tipo, umbral) VALUES ('drawdown_pct', 30)");
    $id = (int)$this->pdo->lastInsertId();
    AdminHttp::handle($this->pdo, $this->adminSession, ['action' => 'alerta_delete', 'id' => (string)$id]);
    $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM alertas_config')->fetchColumn());
}

public function testSetTelegramTokenPersistsInBotMeta(): void
{
    AdminHttp::handle($this->pdo, $this->adminSession, ['action' => 'set_telegram_token', 'token' => '123:abc']);
    $row = $this->pdo->query("SELECT valor FROM bot_meta WHERE clave = 'telegram_bot_token'")->fetch();
    $this->assertSame('123:abc', $row['valor']);
}
```

- [ ] **Step 2: Ejecutar los tests para verificar que fallan**

Run: `vendor/bin/phpunit tests/php/Unit/Core/NotificationTest.php tests/php/Unit/Core/AlertasConfigTest.php`
Expected: FAIL — `Notification` no existe y las acciones CRUD no están.

- [ ] **Step 3: Implementar `src/php/Core/Notification.php`**

```php
<?php
declare(strict_types=1);

namespace BinanceBot\Core;

/**
 * Envío de notificaciones vía Telegram (Bot API).
 */
class Notification
{
    public static function sendTelegram(string $token, string $chatId, string $text): bool
    {
        if ($token === '' || $chatId === '') {
            return false;
        }
        $payload = json_encode([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
        $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false || $code !== 200) {
            return false;
        }
        $data = json_decode((string)$res, true);
        return isset($data['ok']) && $data['ok'] === true;
    }
}
```

- [ ] **Step 4: Implementar acciones CRUD en `AdminHttp::handle`**

Añadir al switch (todas exigen sesión admin; el token `telegram_bot_token` se guarda en `bot_meta`):

```php
case 'alertas_list':
    return ['view' => 'alertas', 'data' => [
        'alertas' => $pdo->query('SELECT * FROM alertas_config ORDER BY tipo')->fetchAll(),
        'telegram_token' => self::botMeta($pdo, 'telegram_bot_token'),
    ], 'error' => null];

case 'alerta_save':
    $tipos = ['drawdown_pct', 'daily_loss_pct', 'distancia_liquidacion_pct', 'saldo_min_usd'];
    $tipo = (string)($post['tipo'] ?? '');
    if (!in_array($tipo, $tipos, true)) {
        return ['view' => 'alertas', 'data' => [], 'error' => 'Tipo de alerta no válido'];
    }
    $umbral = (float)($post['umbral'] ?? 0);
    $habilitado = isset($post['habilitado']) && (int)$post['habilitado'] === 1 ? 1 : 0;
    $chatId = mb_substr((string)($post['telegram_chat_id'] ?? ''), 0, 50);
    $intervalo = max(1, (int)($post['intervalo_min'] ?? 30));
    $stmt = $pdo->prepare('INSERT INTO alertas_config (tipo, umbral, habilitado, telegram_chat_id, intervalo_min, actualizado_por)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE umbral = VALUES(umbral), habilitado = VALUES(habilitado),
            telegram_chat_id = VALUES(telegram_chat_id), intervalo_min = VALUES(intervalo_min),
            actualizado_por = VALUES(actualizado_por)');
    $stmt->execute([$tipo, $umbral, $habilitado, $chatId, $intervalo, (int)($this->adminId ?? null)]);
    return ['view' => 'alertas', 'data' => ['flash' => 'Alerta guardada'], 'error' => null];

case 'alerta_delete':
    $stmt = $pdo->prepare('DELETE FROM alertas_config WHERE id = ?');
    $stmt->execute([(int)($post['id'] ?? 0)]);
    return ['view' => 'alertas', 'data' => ['flash' => 'Alerta eliminada'], 'error' => null];

case 'set_telegram_token':
    $token = mb_substr((string)($post['token'] ?? ''), 0, 200);
    if ($token === '') {
        return ['view' => 'alertas', 'data' => [], 'error' => 'Token vacío'];
    }
    $stmt = $pdo->prepare('INSERT INTO bot_meta (clave, valor) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE valor = VALUES(valor)');
    $stmt->execute(['telegram_bot_token', $token]);
    return ['view' => 'alertas', 'data' => ['flash' => 'Token Telegram guardado'], 'error' => null];

case 'test_telegram':
    $token = self::botMeta($pdo, 'telegram_bot_token');
    $chatId = (string)($post['chat_id'] ?? '');
    $ok = Notification::sendTelegram($token, $chatId, 'Prueba de alerta desde el panel de Grid Bot');
    return ['view' => 'alertas', 'data' => ['test_ok' => $ok], 'error' => $ok ? null : 'No se pudo enviar el mensaje de prueba'];
```

Helper privado `botMeta(PDO $pdo, string $clave): string` (SELECT valor FROM bot_meta WHERE clave = ?).

> **Ajuste al espec:** el tab original "Notificaciones" queda cubierto por `Alertas` (también permite probar el envío). No duplicar: el token no se muestra en pantalla (solo se indica si está configurado: `'telegram_token' ? 'configurado' : 'no configurado'`).

- [ ] **Step 5: Tab Alertas en `admin.php`**

Añadir un nuevo `div.panel-tab` con `data-tab="alertas"` y su botón en la cabecera:

```php
<button class="tab" data-tab="alertas">Alertas</button>
```

Dentro del div: lista de alertas con form inline por fila (guardar/eliminar), form para token de Telegram y botón "Probar envío".

- [ ] **Step 6: Ejecutar los tests para verificar que pasan**

Run: `vendor/bin/phpunit tests/php/Unit/Core/NotificationTest.php tests/php/Unit/Core/AlertasConfigTest.php`
Expected: PASS (2 + 5 tests).

- [ ] **Step 7: Commit**

```bash
git add src/php/Core/Notification.php src/php/Core/AdminHttp.php src/php/admin.php tests/php/Unit/Core/NotificationTest.php tests/php/Unit/Core/AlertasConfigTest.php
git commit -m "feat(alertas): Core/Notification (Telegram) + CRUD de alertas_config y token en bot_meta"
```

---

### Task 7: Visibilidad (tabs Reconciliación, Modelos ML, Logs IA, Logs acceso)

**Files:**
- Create: `src/php/Core/Reconciliation.php`
- Modify: `src/php/Core/AdminHttp.php` (acciones `reconciliar`, `modelos_list`, `logs_ia`, `logs_acceso`)
- Modify: `src/php/admin.php` (4 tabs nuevos)
- Test: `tests/php/Unit/Core/ReconciliationTest.php`, `tests/php/Unit/Core/AdminHttpTest.php` (logs paginados)

**Interfaces:**
- Produces:
  - `Reconciliation::reconcile(PDO $pdo, \BinanceBot\Exchange\BybitFutures $client): array` — compara el saldo contable del ledger (`Accounting::currentNav($pdo)`) con el saldo real del exchange (wallet + PnL no realizado de posiciones vía `$client->balance()`/`positions()`). Devuelve `['ledger_total'=>float,'exchange_total'=>float,'diferencia'=>float,'ok'=>bool]` (`ok` = |diferencia| < 0.50 USD).
  - `AdminHttp` acciones: `reconciliar` (ejecuta `Reconciliation` con el cliente Bybit construido desde `bot.php`/`ExchangeFactory` — revisar cómo se instancia el cliente en el código actual; verificar `BybitFutures` y `Networks`), `modelos_list` (lista metadata de `data/models/*` + `config/trainer_history.json` + `ml_accuracy` — solo lectura), `logs_ia`/`logs_acceso` (paginados: `pagina` + `por_pagina` default 25, devuelven `['filas'=>[], 'total'=>int, 'paginas'=>int, 'pagina'=>int]`).

- [ ] **Step 1: Escribir los tests que fallan**

`tests/php/Unit/Core/ReconciliationTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Reconciliation;
use BinanceBot\Exchange\BybitFutures;
use Tests\Support\SqliteSchema;

class ReconciliationTest extends TestCase
{
    private \PDO $pdo;
    private BybitFutures $client;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
        $this->pdo->exec("INSERT INTO bot_meta (clave, valor) VALUES ('capital_inicial', '1000')");
        $this->client = $this->createMock(BybitFutures::class);
    }

    public function testReconcileReturnsOkWhenMatch(): void
    {
        // ledger = 1050, exchange = 1050 -> ok
        $this->pdo->exec("INSERT INTO movements (tipo, cantidad, saldo, fecha) VALUES ('deposit', 1050, 1050, '2026-08-01 00:00:00')");
        $this->client->method('balance')->willReturn(['totalEquity' => '1050.00']);
        $this->client->method('positions')->willReturn([]);
        $res = Reconciliation::reconcile($this->pdo, $this->client);
        $this->assertTrue($res['ok']);
        $this->assertLessThan(0.50, abs($res['diferencia']));
    }

    public function testReconcileReportsDifference(): void
    {
        $this->pdo->exec("INSERT INTO movements (tipo, cantidad, saldo, fecha) VALUES ('deposit', 1050, 1050, '2026-08-01 00:00:00')");
        $this->client->method('balance')->willReturn(['totalEquity' => '1000.00']);
        $this->client->method('positions')->willReturn([]);
        $res = Reconciliation::reconcile($this->pdo, $this->client);
        $this->assertFalse($res['ok']);
        $this->assertSame(50.0, round($res['diferencia'], 2));
    }

    public function testReconcileIncludesUnrealizedPnl(): void
    {
        $this->pdo->exec("INSERT INTO movements (tipo, cantidad, saldo, fecha) VALUES ('deposit', 1000, 1000, '2026-08-01 00:00:00')");
        $this->client->method('balance')->willReturn(['totalEquity' => '900.00']);
        $this->client->method('positions')->willReturn([
            ['symbol' => 'ETHUSDT', 'positionAmt' => '0.1', 'entryPrice' => '3000', 'unRealizedProfit' => '100.00', 'liquidationPrice' => '2500'],
        ]);
        $res = Reconciliation::reconcile($this->pdo, $this->client);
        // exchange = 900 wallet + 100 pnl no realizado = 1000 == ledger
        $this->assertTrue($res['ok']);
        $this->assertSame(1000.0, $res['exchange_total']);
    }
}
```

En `tests/php/Unit/Core/AdminHttpTest.php`, añadir:

```php
public function testLogsIaPaginated(): void
{
    for ($i = 0; $i < 30; $i++) {
        $this->pdo->exec("INSERT INTO logs_ia (senal, confianza) VALUES ('LONG', 0.5)");
    }
    $out = AdminHttp::handle($this->pdo, $this->adminSession, ['action' => 'logs_ia', 'pagina' => '2', 'por_pagina' => '25']);
    $this->assertSame(30, $out['data']['total']);
    $this->assertSame(2, $out['data']['paginas']);
    $this->assertCount(5, $out['data']['filas']);
}

public function testLogsAccesoPaginated(): void
{
    for ($i = 0; $i < 10; $i++) {
        $this->pdo->exec("INSERT INTO logs_acceso (username, ip, resultado) VALUES ('u$i', '1.1.1.1', 'fallido')");
    }
    $out = AdminHttp::handle($this->pdo, $this->adminSession, ['action' => 'logs_acceso']);
    $this->assertSame(10, $out['data']['total']);
    $this->assertCount(10, $out['data']['filas']);
}
```

> **Ajuste del spec:** el tab "Accesos" del spec (logins por usuario con gráfico) se cubre con `logs_acceso` paginado. No se añade columna nueva a `users` (la info de logins está en `logs_acceso` y `login_attempts`).

- [ ] **Step 2: Ejecutar los tests para verificar que fallan**

Run: `vendor/bin/phpunit tests/php/Unit/Core/ReconciliationTest.php tests/php/Unit/Core/AdminHttpTest.php`
Expected: FAIL — `Reconciliation` no existe y las acciones no están.

- [ ] **Step 3: Implementar `src/php/Core/Reconciliation.php`**

```php
<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;
use BinanceBot\Exchange\BybitFutures;

/**
 * Reconciliación del ledger interno contra el saldo real del exchange.
 * Ledger: Accounting::currentNav(); Exchange: wallet + PnL no realizado.
 */
class Reconciliation
{
    public static function reconcile(PDO $pdo, BybitFutures $client): array
    {
        $ledger = Accounting::currentNav($pdo);
        $balance = $client->balance();
        $wallet = (float)($balance['totalEquity'] ?? 0);
        $pnl = 0.0;
        foreach ($client->positions() as $pos) {
            $pnl += (float)($pos['unRealizedProfit'] ?? 0);
        }
        $exchange = $wallet + $pnl;
        $diferencia = $exchange - $ledger;
        return [
            'ledger_total' => round($ledger, 2),
            'exchange_total' => round($exchange, 2),
            'diferencia' => round($diferencia, 2),
            'ok' => abs($diferencia) < 0.50,
        ];
    }
}
```

> **Revisar antes de codificar:** cómo se instancia `BybitFutures` en el código actual (`src/php/bot.php` y `Exchange/`) y cómo `Accounting::currentNav` calcula el NAV (en el spec se validó). Si `balance()` no devuelve `totalEquity`, mapear el campo real devuelto por `BybitFutures::balance()` (línea 208).

- [ ] **Step 4: Implementar acciones de logs en `AdminHttp::handle`**

```php
case 'logs_ia':
    $pagina = max(1, (int)($get['pagina'] ?? 1));
    $por = min(100, max(10, (int)($get['por_pagina'] ?? 25)));
    $total = (int)$pdo->query('SELECT COUNT(*) FROM logs_ia')->fetchColumn();
    $paginas = max(1, (int)ceil($total / $por));
    $off = ($pagina - 1) * $por;
    $stmt = $pdo->prepare('SELECT * FROM logs_ia ORDER BY fecha DESC LIMIT ? OFFSET ?');
    $stmt->execute([$por, $off]);
    return ['view' => 'logs-ia', 'data' => [
        'filas' => $stmt->fetchAll(), 'total' => $total, 'paginas' => $paginas, 'pagina' => $pagina,
    ], 'error' => null];

case 'logs_acceso':
    $pagina = max(1, (int)($get['pagina'] ?? 1));
    $por = min(100, max(10, (int)($get['por_pagina'] ?? 25)));
    $total = (int)$pdo->query('SELECT COUNT(*) FROM logs_acceso')->fetchColumn();
    $paginas = max(1, (int)ceil($total / $por));
    $off = ($pagina - 1) * $por;
    $stmt = $pdo->prepare('SELECT * FROM logs_acceso ORDER BY fecha DESC LIMIT ? OFFSET ?');
    $stmt->execute([$por, $off]);
    return ['view' => 'logs-acceso', 'data' => [
        'filas' => $stmt->fetchAll(), 'total' => $total, 'paginas' => $paginas, 'pagina' => $pagina,
    ], 'error' => null];
```

(Para MySQL `LIMIT ? OFFSET ?` con PDO se usan enteros; con `EMULATE_PREPARES` funciona — verificar cómo conecta `Database` en el proyecto y si ya se usan `LIMIT` preparado en `Accounting`/`InvestorHttp` para seguir el mismo patrón.)

- [ ] **Step 5: Implementar `modelos_list`** (solo lectura) en `AdminHttp::handle`:

```php
case 'modelos_list':
    $modelos = [];
    $dir = dirname(__DIR__, 3) . '/data/models';
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') as $f) {
            $modelos[] = ['archivo' => basename($f), 'tamano' => filesize($f), 'modificado' => date('Y-m-d H:i:s', filemtime($f))];
        }
    }
    $historial = '';
    $hfile = dirname(__DIR__, 3) . '/config/trainer_history.json';
    if (is_file($hfile)) {
        $historial = (string)file_get_contents($hfile);
    }
    $precisión = null;
    $pfile = dirname(__DIR__, 3) . '/config/volatility_weights.json';
    if (is_file($pfile)) {
        $precisión = json_decode((string)file_get_contents($pfile), true);
    }
    return ['view' => 'modelos', 'data' => ['modelos' => $modelos, 'historial' => $historial, 'precision' => $precisión], 'error' => null];
```

> **Revisar rutas:** en el spec se validó `data/models/{volatility_model.pkl,volatility_scaler.pkl}` y `config/{trainer_history.json,volatility_weights.json}`. Confirmar la ruta base real desde `src/php/` (el código actual usa `privateConfigPath()`). Esta vista es de solo lectura; no ejecuta ni entrena nada.

- [ ] **Step 6: Tabs nuevos en `admin.php`**

Añadir 4 botones de tab (`data-tab="reconciliacion"`, `"modelos"`, `"logs-ia"`, `"logs-acceso"`) y sus `div.panel-tab`:
- **Reconciliación:** botón "Ejecutar reconciliación" (POST `reconciliar` con CSRF) + tabla de resultados (`ledger_total`, `exchange_total`, `diferencia`, badge ok/fallo).
- **Modelos ML:** tabla de archivos + pre JSON de historial/`ml_accuracy`.
- **Logs IA:** tabla paginada (`senal`, `confianza`, `razon`, `accion_tomada`, `precio`, `fecha`) + paginador.
- **Logs acceso:** tabla paginada (`username`, `ip`, `resultado` exitoso/fallido con color, `fecha`) + paginador.

- [ ] **Step 7: Ejecutar los tests para verificar que pasan**

Run: `vendor/bin/phpunit tests/php/Unit/Core/ReconciliationTest.php tests/php/Unit/Core/AdminHttpTest.php`
Expected: PASS (3 + 4 tests nuevos, sin regresión).

- [ ] **Step 8: Commit**

```bash
git add src/php/Core/Reconciliation.php src/php/Core/AdminHttp.php src/php/admin.php tests/php/Unit/Core/ReconciliationTest.php tests/php/Unit/Core/AdminHttpTest.php
git commit -m "feat(visibilidad): reconciliación ledger vs Bybit, vista de modelos ML y logs IA/acceso paginados"
```

---

### Task 8: Suite completa, smoke, E2E y despliegue

**Files:**
- Modify: `README.md` (sección nueva: "Gap de paneles" con migración + despliegue), `CHANGELOG.md`
- Modify: `.superpowers/sdd/progress.md`

- [ ] **Step 1: Suite completa**

Run: `vendor/bin/phpunit`
Expected: 255 + nuevos tests, 0 fallos, sin regresión. Confirmar el conteo nuevo exacto y anotarlo en el plan.

- [ ] **Step 2: Smoke HTTP**

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://binance.gregorbritez.cat/src/php/auth.php      # 200
curl -s -o /dev/null -w "%{http_code}\n" https://binance.gregorbritez.cat/src/php/admin.php      # 302 sin sesión
curl -s -o /dev/null -w "%{http_code}\n" https://binance.gregorbritez.cat/src/php/panel.php       # 302 sin sesión
curl -s -o /dev/null -w "%{http_code}\n" https://binance.gregorbritez.cat/src/php/login.php       # 200 (legacy intacta)
```
Y con sesión admin: `grid_ajax.php _control` sin token → `403`; con token → OK.

- [ ] **Step 3: E2E Chrome headless (CDP :9222, `--no-sandbox`)** — flujo: login → 2FA obligatorio → tabs nuevos visibles; login inversor → 2FA opcional en Perfil; alta/edición de alerta; botón "Probar envío" (se espera fallo controlado si no hay token).

- [ ] **Step 4: Despliegue**

```bash
git checkout -b feature/paneles-gap-real        # (si no se creó antes)
mysqldump -u erika_bot -p... binance_erika_bot > /home/erika/backup_pre_gap_$(date +%Y%m%d).sql
mysql -u erika_bot -p... binance_erika_bot < scripts/migracion_gap.sql
# O dejar que Schema::createTables lo aplique en el próximo arranque del bot
composer install --no-dev
kill 3745 && (nohup php bot.php > bot.log 2>&1 &)     # reiniciar el daemon para cargar el código nuevo
```
Verificar: `ps -p 3745` → proceso nuevo; `tail bot.log` sin errores; `curl` del panel OK.

- [ ] **Step 5: README y changelog**

En `README.md`, sección "Gap de paneles": pasos de migración (`scripts/migracion_gap.sql`, rollback disponible), activación de 2FA obligatoria para admin, token de Telegram en el tab Alertas, y nota de reinicio del bot. Actualizar `CHANGELOG.md` y `.superpowers/sdd/progress.md` (marcar fases Seguridad → Bot → Visibilidad).

- [ ] **Step 6: Commit final**

```bash
git add README.md CHANGELOG.md .superpowers/sdd/progress.md
git commit -m "docs: despliegue del gap de paneles (migración, 2FA, alertas) y changelog"
```

---

## Notas finales / riesgos

1. **`ON DUPLICATE KEY UPDATE` no es compatible con SQLite** (Task 6 `alerta_save`, `set_telegram_token`). Los tests corren sobre SQLite (`SqliteSchema`). **Resolver:** usar patrón portable (`SELECT` + `UPDATE` o `INSERT`) en las clases, o un helper `Upsert` que en MySQL use `ON DUPLICATE KEY UPDATE` y en SQLite haga `INSERT OR REPLACE`. Verificar cómo maneja esto el código existente (p. ej. `bot_meta`) antes de implementar.
2. **`LIMIT ? OFFSET ?` en MySQL con PDO:** depende de `PDO::ATTR_EMULATE_PREPARES`. Verificar la conexión de `Database` y el patrón usado por `Accounting`/`InvestorHttp` si ya paginan.
3. **Firma de `AdminHttp::handle`:** verificar en el test existente si recibe `?callable $sendDirect`/`?RpcClient`; los nuevos tests deben llamarla con la firma real.
4. **Cómo instanciar `BybitFutures` en `Reconciliation`:** verificar la factoría/`Networks` actual para no duplicar la lógica de credenciales.
5. **`riskCheck()` umbral de liquidación 15% hardcodeado (línea 1057):** queda fuera de alcance (no se cambia).
6. **`session_regenerate_id`** ya se usa en `Auth::login`; el paso 2FA lo vuelve a invocar tras verificar el código.
7. **`admin.php`/`panel.php` dependen de `$session`** — los tabs nuevos solo se renderizan dentro del bloque autenticado existente; no alterar el gate.
