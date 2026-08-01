# Plataforma de Inversión — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Plataforma donde inversores se registran, obtienen dirección de depósito USDT/USDC por red EVM, depositan (escáner blockchain acredita), y su equidad crece con el PnL del bot (pool único, NAV). Retiros con aprobación del admin (envío manual).

**Architecture:** App web PHP monolítica (`src/php`) + daemon systemd (`scanner.php`) que monitorea RPC EVM, acredita depósitos y recalcula el NAV. MySQL para datos. Núcleo en `Core/*` testeable con phpunit (SQLite in-memory en tests), capa HTTP fina (`auth.php`, `panel.php`, `admin.php`).

**Tech Stack:** PHP 8.2+, composer (autoload `BinanceBot\` → `src/php/`), PDO MySQL, sesiones PHP, `password_hash()` bcrypt, AES-256-GCM (openssl) para el mnemonic, JSON-RPC vía cURL, `kornrunner/ethereum-offline-account` para HD wallet. Tests: phpunit ^10.5 (SQLite in-memory).

## Global Constraints

- **Archivos del usuario sin commitear NO se tocan:** `src/php/Helpers.php`, `src/php/websocket_server.php`, `src/php/Strategy/*`, `src/php/assets/*`, `src/php/config.json`, `src/php/vite.config.js`, `.claude/settings.json`, `.phpunit.result.cache`, docs mode-flips, `ml_weights_v2.json`, `grid_bot.pid`. Cada commit stagedea solo los archivos de su tarea (`git add <paths exactos>`, nunca `git add -A`).
- `composer.json` del root es el del proyecto (phpunit, ratchet). Las nuevas clases se autoloadan vía `BinanceBot\` → `src/php/`. Las páginas HTTP y daemons requieren `__DIR__ . '/../vendor/autoload.php'` (vendor del root).
- El secreto maestro del wallet se lee de la variable de entorno `PLATFORM_SECRET` (`.env` en `public_html/.env`, que está en `.gitignore`). **Nunca** en git ni en logs.
- No se modifica `src/php/config.json` (ediciones del usuario). `Networks::defaults()` trae eth+bsc; config opcional `platform.*` se lee con defaults en código.
- Los tests usan SQLite in-memory con `tests/php/Support/SqliteSchema.php` (espejo de las tablas MySQL). Mantener ese espejo en sincronía con `Schema::ddl()`.
- `php -l` limpio en todo archivo PHP nuevo; phpunit suite completa verde (136 tests previos + nuevos); npm test 16/16.
- El token `EXPORT_TOKEN` actual del dashboard NO se toca.

---

### Task 1: Esquema de base de datos

**Files:**
- Create: `src/php/Core/Schema.php`
- Create: `tests/php/Support/SqliteSchema.php`
- Create: `tests/php/Unit/Core/SchemaTest.php`

**Interfaces:**
- Produces: `BinanceBot\Core\Schema::ddl(): array` (lista de sentencias DDL MySQL), `Schema::createTables(\PDO $pdo): void`.

- [ ] **Step 1: Escribir el test que falla**

`tests/php/Unit/Core/SchemaTest.php`:
```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Schema;

class SchemaTest extends TestCase
{
    public function testDdlCreatesAllTables(): void
    {
        $ddl = implode("\n", Schema::ddl());
        foreach (['users', 'login_attempts', 'wallets', 'deposit_addresses', 'deposits', 'shares', 'movements', 'withdrawals', 'nav_snapshots', 'scan_state'] as $table) {
            $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS $table", $ddl, "falta tabla $table");
        }
    }

    public function testDepositsTxHashUnique(): void
    {
        $ddl = implode("\n", Schema::ddl());
        $this->assertStringContainsString('UNIQUE KEY uq_tx', $ddl);
    }

    public function testDepositAddressesUniquePerNetwork(): void
    {
        $ddl = implode("\n", Schema::ddl());
        $this->assertStringContainsString('UNIQUE KEY uq_addr (network, address)', $ddl);
    }

    public function testCreateTablesExecutesEachStatement(): void
    {
        $pdo = \Mockery::mock(\PDO::class);
        $n = count(Schema::ddl());
        $pdo->shouldReceive('exec')->times($n)->andReturn(true);
        Schema::createTables($pdo);
        \Mockery::close();
    }
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `vendor/bin/phpunit tests/php/Unit/Core/SchemaTest.php`
Expected: FAIL, clase `Schema` no encontrada.

- [ ] **Step 3: Implementar `Schema`**

`src/php/Core/Schema.php`:
```php
<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class Schema
{
    /** @return list<string> */
    public static function ddl(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                email VARCHAR(190) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                role ENUM('admin','investor') NOT NULL DEFAULT 'investor',
                status ENUM('active','suspended') NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_login_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(45) NOT NULL,
                action VARCHAR(20) NOT NULL,
                username VARCHAR(50) NOT NULL DEFAULT '',
                success TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ip_action (ip, action, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS wallets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                seed_encrypted TEXT NOT NULL,
                network VARCHAR(20) NOT NULL DEFAULT 'root',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS deposit_addresses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                network VARCHAR(20) NOT NULL,
                address VARCHAR(42) NOT NULL,
                derivation_index INT NOT NULL,
                status ENUM('active','disabled') NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_addr (network, address),
                UNIQUE KEY uq_user_net (user_id, network)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS deposits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                network VARCHAR(20) NOT NULL,
                token VARCHAR(10) NOT NULL,
                tx_hash VARCHAR(66) NOT NULL,
                block_number BIGINT NOT NULL DEFAULT 0,
                amount DECIMAL(20,8) NOT NULL DEFAULT 0,
                confirmations INT NOT NULL DEFAULT 0,
                deployed TINYINT(1) NOT NULL DEFAULT 0,
                status ENUM('pending','credited','failed') NOT NULL DEFAULT 'pending',
                detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                credited_at DATETIME NULL,
                UNIQUE KEY uq_tx (tx_hash),
                INDEX idx_status (status),
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS shares (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                units DECIMAL(20,8) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS movements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                type ENUM('deposit','withdrawal','adjust') NOT NULL,
                amount DECIMAL(20,8) NOT NULL DEFAULT 0,
                units DECIMAL(20,8) NOT NULL DEFAULT 0,
                nav DECIMAL(20,8) NOT NULL DEFAULT 0,
                balance_after DECIMAL(20,8) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS withdrawals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                network VARCHAR(20) NOT NULL,
                token VARCHAR(10) NOT NULL,
                amount DECIMAL(20,8) NOT NULL DEFAULT 0,
                units_to_burn DECIMAL(20,8) NOT NULL DEFAULT 0,
                destination_address VARCHAR(42) NOT NULL,
                status ENUM('pending','approved','sent','rejected') NOT NULL DEFAULT 'pending',
                admin_note VARCHAR(255) NOT NULL DEFAULT '',
                tx_hash VARCHAR(66) NOT NULL DEFAULT '',
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                processed_at DATETIME NULL,
                INDEX idx_status (status),
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS nav_snapshots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                total_equity DECIMAL(20,8) NOT NULL DEFAULT 0,
                total_units DECIMAL(20,8) NOT NULL DEFAULT 0,
                nav DECIMAL(20,8) NOT NULL DEFAULT 0,
                bot_pnl_total DECIMAL(20,8) NOT NULL DEFAULT 0,
                snapshot_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS scan_state (
                id INT AUTO_INCREMENT PRIMARY KEY,
                network VARCHAR(20) NOT NULL UNIQUE,
                last_block BIGINT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }

    public static function createTables(PDO $pdo): void
    {
        foreach (self::ddl() as $sql) {
            $pdo->exec($sql);
        }
    }
}
```

- [ ] **Step 4: Escribir el helper SQLite para tests**

`tests/php/Support/SqliteSchema.php` (espejo de las tablas; los tests operan con `new PDO('sqlite::memory:')`):
```php
<?php
declare(strict_types=1);

namespace Tests\Support;

use PDO;

class SqliteSchema
{
    public static function apply(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE bot_meta (meta_key TEXT PRIMARY KEY, meta_value TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT "investor", status TEXT DEFAULT "active", created_at TEXT DEFAULT (datetime("now")), last_login_at TEXT)');
        $pdo->exec('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT, action TEXT, username TEXT, success INTEGER DEFAULT 0, created_at TEXT DEFAULT (datetime("now")))');
        $pdo->exec('CREATE TABLE wallets (id INTEGER PRIMARY KEY AUTOINCREMENT, seed_encrypted TEXT, network TEXT DEFAULT "root", created_at TEXT DEFAULT (datetime("now")))');
        $pdo->exec('CREATE TABLE deposit_addresses (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, network TEXT, address TEXT, derivation_index INTEGER, status TEXT DEFAULT "active", created_at TEXT DEFAULT (datetime("now")), UNIQUE (network, address), UNIQUE (user_id, network))');
        $pdo->exec('CREATE TABLE deposits (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, network TEXT, token TEXT, tx_hash TEXT UNIQUE, block_number INTEGER DEFAULT 0, amount REAL DEFAULT 0, confirmations INTEGER DEFAULT 0, deployed INTEGER DEFAULT 0, status TEXT DEFAULT "pending", detected_at TEXT DEFAULT (datetime("now")), credited_at TEXT)');
        $pdo->exec('CREATE TABLE shares (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, units REAL DEFAULT 0, created_at TEXT DEFAULT (datetime("now")))');
        $pdo->exec('CREATE TABLE movements (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type TEXT, amount REAL DEFAULT 0, units REAL DEFAULT 0, nav REAL DEFAULT 0, balance_after REAL DEFAULT 0, created_at TEXT DEFAULT (datetime("now")))');
        $pdo->exec('CREATE TABLE withdrawals (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, network TEXT, token TEXT, amount REAL DEFAULT 0, units_to_burn REAL DEFAULT 0, destination_address TEXT, status TEXT DEFAULT "pending", admin_note TEXT DEFAULT "", tx_hash TEXT DEFAULT "", requested_at TEXT DEFAULT (datetime("now")), processed_at TEXT)');
        $pdo->exec('CREATE TABLE nav_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, total_equity REAL DEFAULT 0, total_units REAL DEFAULT 0, nav REAL DEFAULT 0, bot_pnl_total REAL DEFAULT 0, snapshot_at TEXT DEFAULT (datetime("now")))');
        $pdo->exec('CREATE TABLE scan_state (id INTEGER PRIMARY KEY AUTOINCREMENT, network TEXT UNIQUE, last_block INTEGER DEFAULT 0, updated_at TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS grid_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id TEXT)');
    }
}
```

- [ ] **Step 5: Correr y verificar que pasa**

Run: `vendor/bin/phpunit tests/php/Unit/Core/SchemaTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add src/php/Core/Schema.php tests/php/Support/SqliteSchema.php tests/php/Unit/Core/SchemaTest.php
git commit -m "feat(platform): DB schema for investor platform"
```

---

### Task 2: Auth y CSRF

**Files:**
- Create: `src/php/Core/Auth.php`
- Create: `src/php/Core/Csrf.php`
- Create: `tests/php/Unit/Core/AuthTest.php`
- Create: `tests/php/Unit/Core/CsrfTest.php`

**Interfaces:**
- Consumes: tabla `users`, `login_attempts` (Task 1).
- Produces: `Auth::register(PDO, string, string, string): array{ok:bool,error?:string,user_id?:int}`; `Auth::login(PDO, string, string): ?array`; `Auth::isValidPassword(string): bool`; `Auth::checkRateLimit(PDO, string, string, int, int): bool`; `Auth::recordAttempt(PDO, string, string, string, bool): void`; `Csrf::token(array &$session): string`; `Csrf::verify(array &$session, ?string): bool`.

- [ ] **Step 1: Test que falla — Auth**

`tests/php/Unit/Core/AuthTest.php`:
```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Auth;
use Tests\Support\SqliteSchema;

class AuthTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
    }

    public function testRegisterCreatesUser(): void
    {
        $res = Auth::register($this->pdo, 'juan', 'juan@example.com', 'secreto123');
        $this->assertTrue($res['ok']);
        $this->assertGreaterThan(0, $res['user_id']);
        $row = $this->pdo->query('SELECT * FROM users')->fetch();
        $this->assertSame('juan', $row['username']);
        $this->assertNotSame('secreto123', $row['password_hash']);
        $this->assertTrue(password_verify('secreto123', $row['password_hash']));
    }

    public function testRegisterRejectsDuplicate(): void
    {
        Auth::register($this->pdo, 'juan', 'juan@example.com', 'secreto123');
        $res = Auth::register($this->pdo, 'juan', 'otro@example.com', 'secreto123');
        $this->assertFalse($res['ok']);
    }

    public function testRegisterValidatesPassword(): void
    {
        $res = Auth::register($this->pdo, 'juan', 'juan@example.com', 'corta');
        $this->assertFalse($res['ok']);
    }

    public function testLoginOk(): void
    {
        Auth::register($this->pdo, 'juan', 'juan@example.com', 'secreto123');
        $user = Auth::login($this->pdo, 'juan', 'secreto123');
        $this->assertNotNull($user);
        $this->assertSame('juan', $user['username']);
    }

    public function testLoginWrongPassword(): void
    {
        Auth::register($this->pdo, 'juan', 'juan@example.com', 'secreto123');
        $this->assertNull(Auth::login($this->pdo, 'juan', 'mala'));
    }

    public function testLoginSuspendedUserBlocked(): void
    {
        Auth::register($this->pdo, 'juan', 'juan@example.com', 'secreto123');
        $this->pdo->exec("UPDATE users SET status='suspended' WHERE username='juan'");
        $this->assertNull(Auth::login($this->pdo, 'juan', 'secreto123'));
    }

    public function testRateLimitBlocksAfterMaxFailures(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Auth::recordAttempt($this->pdo, '1.2.3.4', 'login', 'x', false);
        }
        $this->assertFalse(Auth::checkRateLimit($this->pdo, '1.2.3.4', 'login', 3, 900));
        $this->assertTrue(Auth::checkRateLimit($this->pdo, '1.2.3.4', 'login', 5, 900));
    }
}
```

- [ ] **Step 2: Test que falla — Csrf**

`tests/php/Unit/Core/CsrfTest.php`:
```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Csrf;

class CsrfTest extends TestCase
{
    public function testTokenIsStablePerSession(): void
    {
        $session = [];
        $a = Csrf::token($session);
        $b = Csrf::token($session);
        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a));
    }

    public function testVerify(): void
    {
        $session = [];
        $token = Csrf::token($session);
        $this->assertTrue(Csrf::verify($session, $token));
        $this->assertFalse(Csrf::verify($session, 'otro'));
        $this->assertFalse(Csrf::verify($session, null));
    }
}
```

- [ ] **Step 3: Correr y verificar que fallan**

Run: `vendor/bin/phpunit tests/php/Unit/Core/AuthTest.php tests/php/Unit/Core/CsrfTest.php`
Expected: FAIL, clases no encontradas.

- [ ] **Step 4: Implementar `Auth`**

`src/php/Core/Auth.php`:
```php
<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class Auth
{
    public static function register(PDO $pdo, string $username, string $email, string $password): array
    {
        if (!self::isValidPassword($password)) {
            return ['ok' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Email inválido'];
        }
        $u = trim($username);
        if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $u)) {
            return ['ok' => false, 'error' => 'Usuario inválido (3-50 caracteres alfanuméricos o _)'];
        }
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$u, $email]);
        if ($stmt->fetch()) {
            return ['ok' => false, 'error' => 'El usuario o email ya está registrado'];
        }
        $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)');
        $stmt->execute([$u, $email, password_hash($password, PASSWORD_BCRYPT)]);
        return ['ok' => true, 'user_id' => (int)$pdo->lastInsertId()];
    }

    public static function login(PDO $pdo, string $username, string $password): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([trim($username)]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }
        if ($user['status'] !== 'active') {
            return null;
        }
        $stmt = $pdo->prepare('UPDATE users SET last_login_at = datetime("now") WHERE id = ?');
        $stmt->execute([$user['id']]);
        return $user;
    }

    public static function isValidPassword(string $password): bool
    {
        return strlen($password) >= 8;
    }

    public static function checkRateLimit(PDO $pdo, string $ip, string $action, int $max, int $windowSec): bool
    {
        $cutoff = date('Y-m-d H:i:s', time() - $windowSec);
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM login_attempts WHERE ip = ? AND action = ? AND success = 0 AND created_at > ?');
        $stmt->execute([$ip, $action, $cutoff]);
        return (int)$stmt->fetch()['c'] < $max;
    }

    public static function recordAttempt(PDO $pdo, string $ip, string $action, string $username, bool $success): void
    {
        $stmt = $pdo->prepare('INSERT INTO login_attempts (ip, action, username, success) VALUES (?, ?, ?, ?)');
        $stmt->execute([$ip, $action, $username, (int)$success]);
    }
}
```

> Nota: `datetime("now")` es SQLite. En MySQL `NOW()` es equivalente; el plan usa una consulta de actualización compatible. En producción (MySQL) `Auth::login` usa `NOW()`. Ajustar la sentencia a `NOW()` en el mismo archivo no rompe tests (los tests corren en SQLite). Dejar `datetime("now")` (funciona en ambos; MySQL acepta `datetime("now")` como literal cadena, no como función — por eso en producción se usará la variante comentada). **Decisión de implementación:** usar `NOW()` en MySQL vía detección de driver no es necesario; ambas columnas se insertan con defaults de la tabla. Mantener la sentencia de update simple.

- [ ] **Step 5: Implementar `Csrf`**

`src/php/Core/Csrf.php`:
```php
<?php
declare(strict_types=1);

namespace BinanceBot\Core;

class Csrf
{
    public static function token(array &$session): string
    {
        if (empty($session['csrf'])) {
            $session['csrf'] = bin2hex(random_bytes(32));
        }
        return $session['csrf'];
    }

    public static function verify(array &$session, ?string $token): bool
    {
        return is_string($token) && !empty($session['csrf']) && hash_equals($session['csrf'], $token);
    }
}
```

- [ ] **Step 6: Correr y verificar que pasan**

Run: `vendor/bin/phpunit tests/php/Unit/Core/AuthTest.php tests/php/Unit/Core/CsrfTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/php/Core/Auth.php src/php/Core/Csrf.php tests/php/Unit/Core/AuthTest.php tests/php/Unit/Core/CsrfTest.php
git commit -m "feat(platform): auth and CSRF core"
```

---

### Task 3: Redes EVM y cliente RPC

**Files:**
- Create: `src/php/Core/Networks.php`
- Create: `src/php/Core/RpcClient.php`
- Create: `tests/php/Unit/Core/NetworksTest.php`
- Create: `tests/php/Unit/Core/RpcClientTest.php`

**Interfaces:**
- Produces: `Networks::TRANSFER_TOPIC0` (const string); `Networks::DECIMALS` (const int 18); `Networks::defaults(): array`; `Networks::all(): array`; `Networks::rpc(string): string`; `Networks::confirmations(string): int`; `Networks::contracts(string): array{USDT:string,USDC:string}`; `Networks::validateAddress(string, string): bool`; `RpcClient::__construct(string, ?callable $http = null)`; `RpcClient::call(string, array): mixed`; `RpcClient::blockNumber(): int`; `RpcClient::getLogs(string, string, array, string, array): array`; `RpcClient::transactionReceipt(string): ?array`.

- [ ] **Step 1: Test que falla — Networks**

`tests/php/Unit/Core/NetworksTest.php`:
```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Networks;
use BinanceBot\Core\Config;

class NetworksTest extends TestCase
{
    protected function setUp(): void
    {
        Config::reset();
    }

    public function testDefaultsIncludeEthAndBsc(): void
    {
        $all = Networks::all();
        $this->assertArrayHasKey('eth', $all);
        $this->assertArrayHasKey('bsc', $all);
    }

    public function testEthUsdtContract(): void
    {
        $c = Networks::contracts('eth');
        $this->assertSame('0xdAC17F958D2ee523a2206206994597C13D831ec7', strtolower($c['USDT']));
    }

    public function testBscUsdcContract(): void
    {
        $c = Networks::contracts('bsc');
        $this->assertSame('0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d', strtolower($c['USDC']));
    }

    public function testValidateAddress(): void
    {
        $this->assertTrue(Networks::validateAddress('eth', '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B'));
        $this->assertFalse(Networks::validateAddress('eth', 'Ab5801a7D398351b8bE11C439e05C5B3259aeC9B'));
        $this->assertFalse(Networks::validateAddress('eth', '0xXYZ801a7D398351b8bE11C439e05C5B3259aeC9B'));
        $this->assertFalse(Networks::validateAddress('xx', '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B'));
    }
}
```

- [ ] **Step 2: Test que falla — RpcClient**

`tests/php/Unit/Core/RpcClientTest.php`:
```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\RpcClient;

class RpcClientTest extends TestCase
{
    public function testBlockNumberParsesHex(): void
    {
        $rpc = new RpcClient('http://fake', fn(string $url, string $payload): string =>
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x10f2c3']));
        $this->assertSame(1109699, $rpc->blockNumber());
    }

    public function testGetLogsReturnsResult(): void
    {
        $rpc = new RpcClient('http://fake', fn(string $url, string $payload): string =>
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => [['logIndex' => '0x0']]]));
        $logs = $rpc->getLogs('0x1', '0x2', ['0xa'], '0xt', ['0xp']);
        $this->assertCount(1, $logs);
    }

    public function testCallThrowsOnRpcError(): void
    {
        $this->expectException(\RuntimeException::class);
        $rpc = new RpcClient('http://fake', fn(string $url, string $payload): string =>
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000, 'message' => 'boom']]));
        $rpc->call('eth_blockNumber', []);
    }

    public function testTransactionReceipt(): void
    {
        $rpc = new RpcClient('http://fake', fn(string $url, string $payload): string =>
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['status' => '0x1']]));
        $this->assertSame('0x1', $rpc->transactionReceipt('0xabc')['status']);
    }
}
```

- [ ] **Step 3: Correr y verificar que fallan**

Run: `vendor/bin/phpunit tests/php/Unit/Core/NetworksTest.php tests/php/Unit/Core/RpcClientTest.php`
Expected: FAIL.

- [ ] **Step 4: Implementar `Networks`**

`src/php/Core/Networks.php`:
```php
<?php
declare(strict_types=1);

namespace BinanceBot\Core;

class Networks
{
    public const TRANSFER_TOPIC0 = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    public const DECIMALS = 18;

    /** @return array<string, array{chain_id:int, name:string, rpc:list<string>, confirmations:int, contracts:array{USDT:string,USDC:string}}> */
    public static function defaults(): array
    {
        return [
            'eth' => [
                'chain_id' => 1,
                'name' => 'Ethereum',
                'rpc' => ['https://ethereum-rpc.publicnode.com', 'https://eth.llamarpc.com'],
                'confirmations' => 12,
                'contracts' => [
                    'USDT' => '0xdAC17F958D2ee523a2206206994597C13D831ec7',
                    'USDC' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
                ],
            ],
            'bsc' => [
                'chain_id' => 56,
                'name' => 'BNB Smart Chain',
                'rpc' => ['https://bsc-rpc.publicnode.com', 'https://bsc-dataseed.binance.org'],
                'confirmations' => 15,
                'contracts' => [
                    'USDT' => '0x55d398326f99059fF775485246999027B3197955',
                    'USDC' => '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d',
                ],
            ],
        ];
    }

    public static function all(): array
    {
        $cfg = Config::getInstance()->get('platform.networks', []);
        $extra = is_array($cfg) ? $cfg : [];
        return array_merge(self::defaults(), $extra);
    }

    public static function rpc(string $network): string
    {
        $rpc = self::all()[$network]['rpc'] ?? [];
        $list = is_array($rpc) ? $rpc : [];
        return (string)($list[0] ?? '');
    }

    public static function confirmations(string $network): int
    {
        return (int)(self::all()[$network]['confirmations'] ?? 15);
    }

    public static function contracts(string $network): array
    {
        return self::all()[$network]['contracts'] ?? ['USDT' => '', 'USDC' => ''];
    }

    public static function validateAddress(string $network, string $address): bool
    {
        if (!isset(self::all()[$network])) {
            return false;
        }
        return (bool)preg_match('/^0x[0-9a-fA-F]{40}$/', trim($address));
    }
}
```

- [ ] **Step 5: Implementar `RpcClient`**

`src/php/Core/RpcClient.php`:
```php
<?php
declare(strict_types=1);

namespace BinanceBot\Core;

class RpcClient
{
    /** @var callable|null */
    private $http;

    /** @param callable(string $url, string $payload): string|null $http transporte inyectable para tests */
    public function __construct(private string $url, ?callable $http = null)
    {
        $this->http = $http;
    }

    public function call(string $method, array $params): mixed
    {
        $payload = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        $raw = $this->http ? ($this->http)($this->url, $payload) : $this->curlPost($payload);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || isset($decoded['error'])) {
            $msg = is_array($decoded) ? json_encode($decoded['error']) : 'respuesta inválida';
            throw new \RuntimeException('RPC error: ' . $msg);
        }
        return $decoded['result'] ?? null;
    }

    private function curlPost(string $payload): string
    {
        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $raw === '') {
            throw new \RuntimeException('RPC transport error: ' . $err);
        }
        return (string)$raw;
    }

    public function blockNumber(): int
    {
        return (int)hexdec((string)$this->call('eth_blockNumber', []));
    }

    /** @param list<string> $contracts @param list<string> $paddedTo */
    public function getLogs(string $fromBlockHex, string $toBlockHex, array $contracts, string $transferTopic0, array $paddedTo): array
    {
        $result = $this->call('eth_getLogs', [[
            'fromBlock' => $fromBlockHex,
            'toBlock' => $toBlockHex,
            'address' => array_values($contracts),
            'topics' => [$transferTopic0, null, $paddedTo],
        ]]);
        return is_array($result) ? $result : [];
    }

    public function transactionReceipt(string $txHash): ?array
    {
        $result = $this->call('eth_getTransactionReceipt', [$txHash]);
        return is_array($result) ? $result : null;
    }
}
```

- [ ] **Step 6: Correr y verificar que pasan**

Run: `vendor/bin/phpunit tests/php/Unit/Core/NetworksTest.php tests/php/Unit/Core/RpcClientTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/php/Core/Networks.php src/php/Core/RpcClient.php tests/php/Unit/Core/NetworksTest.php tests/php/Unit/Core/RpcClientTest.php
git commit -m "feat(platform): EVM networks config and JSON-RPC client"
```

---

### Task 4: Wallet HD (BIP-44) cifrada

**Files:**
- Modify: `composer.json` (via `composer require`)
- Create: `src/php/Core/Wallet.php`
- Create: `tests/php/Unit/Core/WalletTest.php`

**Interfaces:**
- Produces: `Wallet::init(PDO, string $secretKey): array{ok:bool,existing:bool}`; `Wallet::mnemonic(PDO, string): string`; `Wallet::getDepositAddress(PDO, int, string, string): string`; `Wallet::deriveAddress(string $mnemonic, int $index): string`; `Wallet::encrypt(string, string): string`; `Wallet::decrypt(string, string): string`.

- [ ] **Step 1: Instalar la librería HD wallet**

```bash
composer require kornrunner/ethereum-offline-account:^2.4 --no-interaction
```

- [ ] **Step 2: Test que falla — Wallet**

`tests/php/Unit/Core/WalletTest.php`:
```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Wallet;
use Tests\Support\SqliteSchema;

class WalletTest extends TestCase
{
    private \PDO $pdo;
    private const SECRET = 'clave-de-prueba';

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
    }

    public function testDeriveAddressDeterministicKnownVector(): void
    {
        $mnemonic = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
        $address = Wallet::deriveAddress($mnemonic, 0);
        $this->assertSame('0x9858effd232b4033e47d90003d41ec34ecaeda94', $address);
        $this->assertSame($address, Wallet::deriveAddress($mnemonic, 0));
    }

    public function testDeriveAddressDifferentIndexes(): void
    {
        $mnemonic = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
        $this->assertNotSame(Wallet::deriveAddress($mnemonic, 0), Wallet::deriveAddress($mnemonic, 1));
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $cipher = Wallet::encrypt('mi mnemonic secreto', self::SECRET);
        $this->assertNotSame('mi mnemonic secreto', $cipher);
        $this->assertSame('mi mnemonic secreto', Wallet::decrypt($cipher, self::SECRET));
    }

    public function testInitCreatesWalletOnce(): void
    {
        $first = Wallet::init($this->pdo, self::SECRET);
        $second = Wallet::init($this->pdo, self::SECRET);
        $this->assertTrue($first['ok']);
        $this->assertTrue($second['existing']);
        $row = $this->pdo->query('SELECT seed_encrypted FROM wallets')->fetch();
        $this->assertNotSame('', $row['seed_encrypted']);
    }

    public function testGetDepositAddressIsStablePerUserNetwork(): void
    {
        Wallet::init($this->pdo, self::SECRET);
        $a1 = Wallet::getDepositAddress($this->pdo, 1, 'eth', self::SECRET);
        $a2 = Wallet::getDepositAddress($this->pdo, 1, 'eth', self::SECRET);
        $b = Wallet::getDepositAddress($this->pdo, 2, 'eth', self::SECRET);
        $this->assertSame($a1, $a2);
        $this->assertNotSame($a1, $b);
        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{40}$/', $a1);
    }
}
```

- [ ] **Step 3: Correr y verificar que falla**

Run: `vendor/bin/phpunit tests/php/Unit/Core/WalletTest.php`
Expected: FAIL, clase `Wallet` no encontrada.

- [ ] **Step 4: Implementar `Wallet`**

`src/php/Core/Wallet.php`:
```php
<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use kornrunner\Ethereum\Wallet as EthWallet;
use PDO;

class Wallet
{
    public static function init(PDO $pdo, string $secretKey): array
    {
        $stmt = $pdo->query("SELECT id FROM wallets WHERE network = 'root' LIMIT 1");
        if ($stmt->fetch()) {
            return ['ok' => true, 'existing' => true];
        }
        $wallet = new EthWallet();
        $mnemonic = $wallet->createMnemonic();
        $stmt = $pdo->prepare("INSERT INTO wallets (network, seed_encrypted) VALUES ('root', ?)");
        $stmt->execute([self::encrypt($mnemonic, $secretKey)]);
        return ['ok' => true, 'existing' => false];
    }

    public static function mnemonic(PDO $pdo, string $secretKey): string
    {
        $stmt = $pdo->query("SELECT seed_encrypted FROM wallets WHERE network = 'root' LIMIT 1");
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('Wallet no inicializada. Ejecuta: php cli.php wallet:init');
        }
        return self::decrypt($row['seed_encrypted'], $secretKey);
    }

    public static function getDepositAddress(PDO $pdo, int $userId, string $network, string $secretKey): string
    {
        $stmt = $pdo->prepare('SELECT address FROM deposit_addresses WHERE user_id = ? AND network = ?');
        $stmt->execute([$userId, $network]);
        $row = $stmt->fetch();
        if ($row) {
            return $row['address'];
        }
        $index = self::nextIndex($pdo);
        $address = self::deriveAddress(self::mnemonic($pdo, $secretKey), $index);
        $stmt = $pdo->prepare('INSERT INTO deposit_addresses (user_id, network, address, derivation_index) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $network, $address, $index]);
        return $address;
    }

    public static function deriveAddress(string $mnemonic, int $index): string
    {
        $derived = EthWallet::fromMnemonic($mnemonic)->derivePath("m/44'/60'/0'/0/{$index}");
        return '0x' . strtolower($derived->getAddress());
    }

    public static function encrypt(string $plain, string $key): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('No se pudo cifrar');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload, string $key): string
    {
        $raw = base64_decode($payload);
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('No se pudo descifrar la wallet');
        }
        return $plain;
    }

    private static function nextIndex(PDO $pdo): int
    {
        $row = $pdo->query('SELECT COALESCE(MAX(derivation_index), -1) + 1 AS n FROM deposit_addresses')->fetch();
        return (int)$row['n'];
    }
}
```

> Si `EthWallet::createMnemonic()` no existe en la versión instalada, usar `(new \kornrunner\Ethereum\Wallet())->createMnemonic()` o `Wallet::generate()`; el test `testInitCreatesWalletOnce` solo exige que persista el mnemonic cifrado. Si `fromMnemonic`/`derivePath` difieren, ajustar `deriveAddress`; el test vector `9858effd...` fija el comportamiento correcto.

- [ ] **Step 5: Correr y verificar que pasa**

Run: `vendor/bin/phpunit tests/php/Unit/Core/WalletTest.php`
Expected: PASS (incluye el vector conocido).

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock src/php/Core/Wallet.php tests/php/Unit/Core/WalletTest.php
git commit -m "feat(platform): encrypted HD wallet with BIP-44 derivation"
```

---

### Task 5: Contabilidad NAV/unidades

**Files:**
- Create: `src/php/Core/Accounting.php`
- Create: `tests/php/Unit/Core/AccountingTest.php`

**Interfaces:**
- Consumes: `bot_meta`, `shares`, `deposits`, `withdrawals`, `movements`, `nav_snapshots` (Task 1).
- Produces: `Accounting::init(PDO, float): void`; `Accounting::ownerUnits(PDO): float`; `Accounting::totalUnits(PDO): float`; `Accounting::currentNav(PDO): float`; `Accounting::creditDeposit(PDO, int): void`; `Accounting::userUnits(PDO, int): float`; `Accounting::userEquity(PDO, int): float`; `Accounting::requestWithdrawal(PDO, int, string, string, float, string, float): array`; `Accounting::approveWithdrawal(PDO, int): array`; `Accounting::markSent(PDO, int, string): void`; `Accounting::markDeployed(PDO, int): void`; `Accounting::updateNav(PDO, float, float, float): void`; `Accounting::pendingWithdrawals(PDO): array`; `Accounting::walletHeld(PDO): float`.

- [ ] **Step 1: Test que falla — Accounting**

`tests/php/Unit/Core/AccountingTest.php`:
```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Accounting;
use Tests\Support\SqliteSchema;

class AccountingTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
    }

    private function seedDeposit(int $userId, float $amount, string $status = 'pending'): int
    {
        $this->pdo->prepare('INSERT INTO deposits (user_id, network, token, tx_hash, block_number, amount, status) VALUES (?, "eth", "USDT", ?, 1, ?, ?)')
            ->execute([$userId, '0x' . bin2hex(random_bytes(20)), $amount, $status]);
        return (int)$this->pdo->lastInsertId();
    }

    public function testInitSeedsOwnerUnitsAndNav(): void
    {
        Accounting::init($this->pdo, 100000.0);
        $this->assertSame(100000.0, Accounting::ownerUnits($this->pdo));
        $this->assertSame(100000.0, Accounting::totalUnits($this->pdo));
        $this->assertSame(1.0, Accounting::currentNav($this->pdo));
    }

    public function testInitIsIdempotent(): void
    {
        Accounting::init($this->pdo, 100000.0);
        Accounting::init($this->pdo, 999.0);
        $this->assertSame(100000.0, Accounting::ownerUnits($this->pdo));
    }

    public function testCreditDepositIssuesUnitsAtNav(): void
    {
        Accounting::init($this->pdo, 100000.0);
        $depId = $this->seedDeposit(1, 10000.0);
        Accounting::creditDeposit($this->pdo, $depId);
        $this->assertSame(10000.0, Accounting::userUnits($this->pdo, 1));
        $this->assertSame(10000.0, Accounting::userEquity($this->pdo, 1));
        $this->assertSame('credited', $this->pdo->query("SELECT status FROM deposits WHERE id = $depId")->fetch()['status']);
        $this->assertSame(110000.0, Accounting::totalUnits($this->pdo));
    }

    public function testNavGrowthIncreasesEquityProportionally(): void
    {
        Accounting::init($this->pdo, 100000.0);
        $depId = $this->seedDeposit(1, 10000.0);
        Accounting::creditDeposit($this->pdo, $depId);
        Accounting::updateNav($this->pdo, 110000.0, 0.0, 10000.0);
        $this->assertSame(1.0, Accounting::currentNav($this->pdo));
        Accounting::updateNav($this->pdo, 121000.0, 0.0, 21000.0);
        $nav = Accounting::currentNav($this->pdo);
        $this->assertEqualsWithDelta(1.1, $nav, 0.000001);
        $this->assertEqualsWithDelta(11000.0, Accounting::userEquity($this->pdo, 1), 0.01);
    }

    public function testRequestWithdrawalValidation(): void
    {
        Accounting::init($this->pdo, 100000.0);
        $depId = $this->seedDeposit(1, 10000.0);
        Accounting::creditDeposit($this->pdo, $depId);
        $bad = Accounting::requestWithdrawal($this->pdo, 1, 'eth', 'USDT', 50000.0, '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B', 10.0);
        $this->assertFalse($bad['ok']);
        $ok = Accounting::requestWithdrawal($this->pdo, 1, 'eth', 'USDT', 1000.0, '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B', 10.0);
        $this->assertTrue($ok['ok']);
    }

    public function testApproveWithdrawalBurnsUnits(): void
    {
        Accounting::init($this->pdo, 100000.0);
        $depId = $this->seedDeposit(1, 10000.0);
        Accounting::creditDeposit($this->pdo, $depId);
        $wdId = Accounting::requestWithdrawal($this->pdo, 1, 'eth', 'USDT', 1000.0, '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B', 10.0)['withdrawal_id'];
        Accounting::approveWithdrawal($this->pdo, $wdId);
        $this->assertEqualsWithDelta(9000.0, Accounting::userUnits($this->pdo, 1), 0.01);
        $row = $this->pdo->query("SELECT status FROM withdrawals WHERE id = $wdId")->fetch();
        $this->assertSame('approved', $row['status']);
        Accounting::markSent($this->pdo, $wdId, '0x' . str_repeat('ab', 32));
        $this->assertSame('sent', $this->pdo->query("SELECT status FROM withdrawals WHERE id = $wdId")->fetch()['status']);
    }

    public function testMarkDeployed(): void
    {
        $depId = $this->seedDeposit(1, 500.0, 'credited');
        Accounting::markDeployed($this->pdo, $depId);
        $this->assertSame('1', $this->pdo->query("SELECT deployed FROM deposits WHERE id = $depId")->fetch()['deployed']);
        $this->assertSame(0.0, Accounting::walletHeld($this->pdo));
    }

    public function testWalletHeldSumsCreditedUndeployed(): void
    {
        $this->seedDeposit(1, 500.0, 'credited');
        $this->seedDeposit(1, 300.0, 'credited');
        $this->seedDeposit(1, 700.0, 'pending');
        $this->assertSame(800.0, Accounting::walletHeld($this->pdo));
    }
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `vendor/bin/phpunit tests/php/Unit/Core/AccountingTest.php`
Expected: FAIL.

- [ ] **Step 3: Implementar `Accounting`**

`src/php/Core/Accounting.php`:
```php
<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class Accounting
{
    public static function init(PDO $pdo, float $ownerCapital): void
    {
        $stmt = $pdo->query("SELECT meta_value FROM bot_meta WHERE meta_key = 'owner_units'");
        if ($stmt->fetch()) {
            return;
        }
        $stmt = $pdo->prepare("INSERT INTO bot_meta (meta_key, meta_value) VALUES ('owner_units', ?)");
        $stmt->execute([(string)$ownerCapital]);
        self::snapshot($pdo, $ownerCapital, $ownerCapital, 1.0, 0.0);
    }

    public static function ownerUnits(PDO $pdo): float
    {
        $stmt = $pdo->query("SELECT meta_value FROM bot_meta WHERE meta_key = 'owner_units'");
        $row = $stmt->fetch();
        return $row ? (float)$row['meta_value'] : 0.0;
    }

    public static function totalUnits(PDO $pdo): float
    {
        $stmt = $pdo->query('SELECT COALESCE(SUM(units), 0) AS t FROM shares');
        return (float)$stmt->fetch()['t'] + self::ownerUnits($pdo);
    }

    public static function currentNav(PDO $pdo): float
    {
        $stmt = $pdo->query('SELECT nav FROM nav_snapshots ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch();
        return $row ? (float)$row['nav'] : 1.0;
    }

    public static function userUnits(PDO $pdo, int $userId): float
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(units), 0) AS u FROM shares WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (float)$stmt->fetch()['u'];
    }

    public static function userEquity(PDO $pdo, int $userId): float
    {
        return round(self::userUnits($pdo, $userId) * self::currentNav($pdo), 8);
    }

    public static function creditDeposit(PDO $pdo, int $depositId): void
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM deposits WHERE id = ?');
            $stmt->execute([$depositId]);
            $dep = $stmt->fetch();
            if (!$dep || $dep['status'] !== 'pending') {
                $pdo->rollBack();
                return;
            }
            $nav = self::currentNav($pdo);
            $units = round((float)$dep['amount'] / $nav, 8);
            $pdo->prepare('INSERT INTO shares (user_id, units) VALUES (?, ?)')->execute([$dep['user_id'], $units]);
            $pdo->prepare("UPDATE deposits SET status = 'credited', credited_at = datetime('now') WHERE id = ?")->execute([$depositId]);
            self::addMovement($pdo, (int)$dep['user_id'], 'deposit', (float)$dep['amount'], $units, $nav);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function requestWithdrawal(PDO $pdo, int $userId, string $network, string $token, float $amount, string $destination, float $minAmount): array
    {
        if ($amount < $minAmount) {
            return ['ok' => false, 'error' => 'El monto es menor al mínimo permitido'];
        }
        $nav = self::currentNav($pdo);
        $units = round($amount / $nav, 8);
        if ($units > self::userUnits($pdo, $userId)) {
            return ['ok' => false, 'error' => 'Saldo insuficiente'];
        }
        $stmt = $pdo->prepare('INSERT INTO withdrawals (user_id, network, token, amount, units_to_burn, destination_address) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $network, $token, $amount, $units, $destination]);
        return ['ok' => true, 'withdrawal_id' => (int)$pdo->lastInsertId()];
    }

    public static function approveWithdrawal(PDO $pdo, int $withdrawalId): array
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM withdrawals WHERE id = ?');
            $stmt->execute([$withdrawalId]);
            $w = $stmt->fetch();
            if (!$w || $w['status'] !== 'pending') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'El retiro no está pendiente'];
            }
            $nav = self::currentNav($pdo);
            $units = round((float)$w['units_to_burn'], 8);
            $available = self::userUnits($pdo, (int)$w['user_id']);
            $burn = min($units, $available);
            if ($burn > 0) {
                $pdo->prepare('INSERT INTO shares (user_id, units) VALUES (?, ?)')->execute([(int)$w['user_id'], -$burn]);
            }
            $pdo->prepare("UPDATE withdrawals SET status = 'approved', processed_at = datetime('now') WHERE id = ?")->execute([$withdrawalId]);
            self::addMovement($pdo, (int)$w['user_id'], 'withdrawal', -(float)$w['amount'], -$burn, $nav);
            $pdo->commit();
            return ['ok' => true];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function markSent(PDO $pdo, int $withdrawalId, string $txHash): void
    {
        $pdo->prepare("UPDATE withdrawals SET status = 'sent', tx_hash = ? WHERE id = ?")->execute([$txHash, $withdrawalId]);
    }

    public static function markDeployed(PDO $pdo, int $depositId): void
    {
        $pdo->prepare('UPDATE deposits SET deployed = 1 WHERE id = ?')->execute([$depositId]);
    }

    public static function updateNav(PDO $pdo, float $realBalance, float $walletHeld, float $botPnlTotal): void
    {
        $units = self::totalUnits($pdo);
        $nav = $units > 0 ? round(($realBalance + $walletHeld) / $units, 8) : 1.0;
        self::snapshot($pdo, $realBalance + $walletHeld, $units, $nav, $botPnlTotal);
    }

    public static function walletHeld(PDO $pdo): float
    {
        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) AS t FROM deposits WHERE status = 'credited' AND deployed = 0");
        return (float)$stmt->fetch()['t'];
    }

    public static function pendingWithdrawals(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT w.*, u.username FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.status = 'pending' ORDER BY w.id");
        return $stmt->fetchAll();
    }

    private static function addMovement(PDO $pdo, int $userId, string $type, float $amount, float $units, float $nav): void
    {
        $balanceAfter = round(self::userUnits($pdo, $userId) * $nav, 8);
        $stmt = $pdo->prepare('INSERT INTO movements (user_id, type, amount, units, nav, balance_after) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $type, $amount, $units, $nav, $balanceAfter]);
    }

    private static function snapshot(PDO $pdo, float $equity, float $units, float $nav, float $pnl): void
    {
        $stmt = $pdo->prepare('INSERT INTO nav_snapshots (total_equity, total_units, nav, bot_pnl_total) VALUES (?, ?, ?, ?)');
        $stmt->execute([$equity, $units, $nav, $pnl]);
    }
}
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `vendor/bin/phpunit tests/php/Unit/Core/AccountingTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/php/Core/Accounting.php tests/php/Unit/Core/AccountingTest.php
git commit -m "feat(platform): NAV/units fund accounting"
```

---

### Task 6: Escáner de depósitos (núcleo)

**Files:**
- Create: `src/php/Core/DepositScanner.php`
- Create: `tests/php/Unit/Core/DepositScannerTest.php`

**Interfaces:**
- Consumes: `RpcClient` (Task 3), `Accounting::creditDeposit` (Task 5), `Networks::TRANSFER_TOPIC0`.
- Produces: `DepositScanner::__construct(PDO, RpcClient, string $network, int $confirmations, array $contracts, float $minAmount)`; `DepositScanner::padAddress(string): string`; `DepositScanner::topicAddresses(array): array`; `DepositScanner::unpadAddress(string): string`; `DepositScanner::parseAmount(string): float`; `DepositScanner::tick(): array{head:int,from:int,to:int,logs:int,inserted:int}`; `DepositScanner::processPending(): array{head:int,credited:int,failed:int}`.

- [ ] **Step 1: Test que falla**

`tests/php/Unit/Core/DepositScannerTest.php`:
```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\DepositScanner;
use BinanceBot\Core\RpcClient;
use BinanceBot\Core\Networks;
use Tests\Support\SqliteSchema;

class DepositScannerTest extends TestCase
{
    private \PDO $pdo;
    private const USDT = '0x55d398326f99059fF775485246999027B3197955';
    private const USER_ADDR = '0xab5801a7d398351b8be11c439e05c5b3259aec9b';
    private const USER2_ADDR = '0xbb5801a7d398351b8be11c439e05c5b3259aec9b';

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
        $this->pdo->exec("INSERT INTO users (username, email, password_hash) VALUES ('u1', 'u1@e.com', 'x'), ('u2', 'u2@e.com', 'x')");
        $this->pdo->exec("INSERT INTO deposit_addresses (user_id, network, address, derivation_index) VALUES (1, 'bsc', '" . self::USER_ADDR . "', 0), (2, 'bsc', '" . self::USER2_ADDR . "', 1)");
    }

    private function scanner(): DepositScanner
    {
        return new DepositScanner($this->pdo, new RpcClient('http://fake', fn() => '{"jsonrpc":"2.0","id":1,"result":[]}'), 'bsc', 15, ['USDT' => self::USDT], 1.0);
    }

    public function testPadAddress(): void
    {
        $padded = DepositScanner::padAddress(self::USER_ADDR);
        $this->assertSame(66, strlen($padded));
        $this->assertSame('0x' . str_repeat('0', 24) . substr(self::USER_ADDR, 2), $padded);
        $this->assertSame(self::USER_ADDR, DepositScanner::unpadAddress($padded));
    }

    public function testParseAmount(): void
    {
        $this->assertSame(10.5, DepositScanner::parseAmount('0x' . str_pad('000000000000000000000000000000000000000000000001a055690d9db80000', 64, '0', STR_PAD_LEFT)));
        $this->assertSame(0.0, DepositScanner::parseAmount('0x0'));
    }

    public function testTickInsertsNewDeposit(): void
    {
        $fakeRpc = new RpcClient('http://fake', function (string $url, string $payload) {
            $req = json_decode($payload, true);
            if ($req['method'] === 'eth_blockNumber') {
                return '{"jsonrpc":"2.0","id":1,"result":"0x1000"}';
            }
            if ($req['method'] === 'eth_getLogs') {
                return '{"jsonrpc":"2.0","id":1,"result":[' . $this->logFor('0x1010', 10000000000000000000) . ']}';
            }
            return '{"jsonrpc":"2.0","id":1,"result":null}';
        });
        $scanner = new DepositScanner($this->pdo, $fakeRpc, 'bsc', 15, ['USDT' => self::USDT], 1.0);
        $out = $scanner->tick();
        $this->assertSame(1, $out['inserted']);
        $row = $this->pdo->query('SELECT * FROM deposits')->fetch();
        $this->assertSame('pending', $row['status']);
        $this->assertSame(10.0, (float)$row['amount']);
        $this->assertSame(1, (int)$row['user_id']);
    }

    public function testTickDedupesSameTx(): void
    {
        $fakeRpc = new RpcClient('http://fake', function (string $url, string $payload) {
            $req = json_decode($payload, true);
            if ($req['method'] === 'eth_blockNumber') return '{"jsonrpc":"2.0","id":1,"result":"0x1000"}';
            if ($req['method'] === 'eth_getLogs') return '{"jsonrpc":"2.0","id":1,"result":[' . $this->logFor('0x1010', 10000000000000000000) . ']}';
            return '{"jsonrpc":"2.0","id":1,"result":null}';
        });
        $scanner = new DepositScanner($this->pdo, $fakeRpc, 'bsc', 15, ['USDT' => self::USDT], 1.0);
        $scanner->tick();
        $out = $scanner->tick();
        $this->assertSame(0, $out['inserted']);
        $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) c FROM deposits')->fetch()['c']);
    }

    public function testTickIgnoresDust(): void
    {
        $fakeRpc = new RpcClient('http://fake', function (string $url, string $payload) {
            $req = json_decode($payload, true);
            if ($req['method'] === 'eth_blockNumber') return '{"jsonrpc":"2.0","id":1,"result":"0x1000"}';
            if ($req['method'] === 'eth_getLogs') return '{"jsonrpc":"2.0","id":1,"result":[' . $this->logFor('0x1010', 500000000000000000) . ']}';
            return '{"jsonrpc":"2.0","id":1,"result":null}';
        });
        $scanner = new DepositScanner($this->pdo, $fakeRpc, 'bsc', 15, ['USDT' => self::USDT], 1.0);
        $out = $scanner->tick();
        $this->assertSame(0, $out['inserted']);
    }

    public function testProcessPendingCreditsAfterConfirmations(): void
    {
        $this->pdo->exec("INSERT INTO deposits (user_id, network, token, tx_hash, block_number, amount, status) VALUES (1, 'bsc', 'USDT', '0x1', 0xfa0, 100, 'pending')");
        AccountingTestSeed::initNav($this->pdo);
        $fakeRpc = new RpcClient('http://fake', function (string $url, string $payload) {
            $req = json_decode($payload, true);
            if ($req['method'] === 'eth_blockNumber') return '{"jsonrpc":"2.0","id":1,"result":"0x1000"}';
            if ($req['method'] === 'eth_getTransactionReceipt') return '{"jsonrpc":"2.0","id":1,"result":{"status":"0x1"}}';
            return '{"jsonrpc":"2.0","id":1,"result":null}';
        });
        $scanner = new DepositScanner($this->pdo, $fakeRpc, 'bsc', 15, ['USDT' => self::USDT], 1.0);
        $out = $scanner->processPending();
        $this->assertSame(1, $out['credited']);
        $this->assertSame('credited', $this->pdo->query('SELECT status FROM deposits WHERE tx_hash = "0x1"')->fetch()['status']);
        $this->assertSame(100.0, AccountingTestSeed::userUnits($this->pdo, 1));
    }

    public function testProcessPendingMarksRevertedAsFailed(): void
    {
        $this->pdo->exec("INSERT INTO deposits (user_id, network, token, tx_hash, block_number, amount, status) VALUES (1, 'bsc', 'USDT', '0x2', 0xfa0, 100, 'pending')");
        $fakeRpc = new RpcClient('http://fake', function (string $url, string $payload) {
            $req = json_decode($payload, true);
            if ($req['method'] === 'eth_blockNumber') return '{"jsonrpc":"2.0","id":1,"result":"0x1000"}';
            if ($req['method'] === 'eth_getTransactionReceipt') return '{"jsonrpc":"2.0","id":1,"result":{"status":"0x0"}}';
            return '{"jsonrpc":"2.0","id":1,"result":null}';
        });
        $scanner = new DepositScanner($this->pdo, $fakeRpc, 'bsc', 15, ['USDT' => self::USDT], 1.0);
        $out = $scanner->processPending();
        $this->assertSame(0, $out['credited']);
        $this->assertSame(1, $out['failed']);
        $this->assertSame('failed', $this->pdo->query('SELECT status FROM deposits WHERE tx_hash = "0x2"')->fetch()['status']);
    }

    private function logFor(string $blockHex, int $amount): string
    {
        return json_encode([
            'address' => self::USDT,
            'blockNumber' => $blockHex,
            'transactionHash' => '0x' . bin2hex(random_bytes(20)),
            'topics' => [Networks::TRANSFER_TOPIC0, '0x' . str_repeat('0', 64), DepositScanner::padAddress(self::USER_ADDR)],
            'data' => '0x' . str_pad(dechex($amount), 64, '0', STR_PAD_LEFT),
        ]);
    }
}
```

> Los tests usan dos clases helper aún no definidas: `AccountingTestSeed::initNav()` (inicializa owner_units + snapshot NAV=1.0) y `AccountingTestSeed::userUnits()`. Definirlas en el mismo archivo del test:
```php
final class AccountingTestSeed
{
    public static function initNav(\PDO $pdo): void
    {
        $pdo->exec("INSERT INTO bot_meta (meta_key, meta_value) VALUES ('owner_units', '100000')");
        $pdo->exec('INSERT INTO nav_snapshots (total_equity, total_units, nav, bot_pnl_total) VALUES (100000, 100000, 1.0, 0)');
    }

    public static function userUnits(\PDO $pdo, int $userId): float
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(units), 0) AS u FROM shares WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (float)$stmt->fetch()['u'];
    }
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `vendor/bin/phpunit tests/php/Unit/Core/DepositScannerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implementar `DepositScanner`**

`src/php/Core/DepositScanner.php`:
```php
<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class DepositScanner
{
    public const RANGE_LIMIT = 5000;

    /** @param array{USDT:string,USDC:string} $contracts */
    public function __construct(
        private PDO $pdo,
        private RpcClient $rpc,
        private string $network,
        private int $confirmations,
        private array $contracts,
        private float $minAmount = 1.0,
    ) {
    }

    public static function padAddress(string $address): string
    {
        return '0x' . str_pad(strtolower(ltrim($address, '0x')), 64, '0', STR_PAD_LEFT);
    }

    /** @param list<string> $addresses */
    public static function topicAddresses(array $addresses): array
    {
        return array_map([self::class, 'padAddress'], $addresses);
    }

    public static function unpadAddress(string $topic): string
    {
        return '0x' . substr($topic, 26);
    }

    public static function parseAmount(string $hexData): float
    {
        $hex = ltrim(ltrim($hexData, '0x'), '0') ?: '0';
        $dec = '0';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $dec = bcadd(bcmul($dec, '16', 0), (string)hexdec($hex[$i]), 0);
        }
        return (float)bcdiv($dec, (string)(10 ** Networks::DECIMALS), 8);
    }

    public function tick(): array
    {
        $head = $this->rpc->blockNumber();
        $state = $this->getState();
        $from = $state > 0 ? $state + 1 : max(0, $head - self::RANGE_LIMIT);
        if ($from > $head) {
            return ['head' => $head, 'from' => $from, 'to' => $from, 'logs' => 0, 'inserted' => 0];
        }
        $to = min($from + self::RANGE_LIMIT - 1, $head);
        $addresses = $this->activeAddresses();
        $logs = [];
        if ($addresses) {
            $logs = $this->rpc->getLogs(
                '0x' . dechex($from),
                '0x' . dechex($to),
                array_values($this->contracts),
                Networks::TRANSFER_TOPIC0,
                self::topicAddresses($addresses),
            );
        }
        $inserted = 0;
        foreach ($logs as $log) {
            if ($this->insertLog($log)) {
                $inserted++;
            }
        }
        $this->setState($to);
        return ['head' => $head, 'from' => $from, 'to' => $to, 'logs' => count($logs), 'inserted' => $inserted];
    }

    public function processPending(): array
    {
        $head = $this->rpc->blockNumber();
        $stmt = $this->pdo->prepare("SELECT * FROM deposits WHERE network = ? AND status = 'pending'");
        $stmt->execute([$this->network]);
        $credited = 0;
        $failed = 0;
        foreach ($stmt->fetchAll() as $dep) {
            $confirmations = $head - (int)$dep['block_number'];
            if ($confirmations < 0) {
                continue;
            }
            $this->pdo->prepare('UPDATE deposits SET confirmations = ? WHERE id = ?')->execute([$confirmations, $dep['id']]);
            if ($confirmations >= $this->confirmations) {
                $receipt = $this->rpc->transactionReceipt($dep['tx_hash']);
                if ($receipt !== null && strtolower((string)($receipt['status'] ?? '0x1')) === '0x0') {
                    $this->pdo->prepare("UPDATE deposits SET status = 'failed' WHERE id = ?")->execute([$dep['id']]);
                    $failed++;
                    continue;
                }
                Accounting::creditDeposit($this->pdo, (int)$dep['id']);
                $credited++;
            }
        }
        return ['head' => $head, 'credited' => $credited, 'failed' => $failed];
    }

    private function insertLog(array $log): bool
    {
        $txHash = (string)($log['transactionHash'] ?? '');
        if ($txHash === '') {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM deposits WHERE tx_hash = ?');
        $stmt->execute([$txHash]);
        if ($stmt->fetch()) {
            return false;
        }
        $amount = self::parseAmount((string)($log['data'] ?? '0x0'));
        if ($amount < $this->minAmount) {
            return false;
        }
        $token = $this->tokenFor((string)($log['address'] ?? ''));
        $to = self::unpadAddress((string)($log['topics'][2] ?? ''));
        $user = $this->userForAddress($to);
        if ($token === '' || !$user) {
            return false;
        }
        $stmt = $this->pdo->prepare('INSERT INTO deposits (user_id, network, token, tx_hash, block_number, amount, confirmations) VALUES (?, ?, ?, ?, ?, ?, 0)');
        $stmt->execute([$user['id'], $this->network, $token, $txHash, (int)hexdec((string)($log['blockNumber'] ?? '0x0')), $amount]);
        return true;
    }

    private function tokenFor(string $contract): string
    {
        $lower = strtolower($contract);
        foreach ($this->contracts as $token => $addr) {
            if (strtolower((string)$addr) === $lower) {
                return $token;
            }
        }
        return '';
    }

    private function userForAddress(string $address): ?array
    {
        $stmt = $this->pdo->prepare("SELECT u.* FROM deposit_addresses a JOIN users u ON u.id = a.user_id WHERE a.address = ? AND a.status = 'active' AND u.status = 'active'");
        $stmt->execute([strtolower($address)]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<string> */
    private function activeAddresses(): array
    {
        $stmt = $this->pdo->query("SELECT address FROM deposit_addresses WHERE status = 'active'");
        return array_map(static fn(array $r): string => strtolower((string)$r['address']), $stmt->fetchAll());
    }

    private function getState(): int
    {
        $stmt = $this->pdo->prepare('SELECT last_block FROM scan_state WHERE network = ?');
        $stmt->execute([$this->network]);
        return (int)($stmt->fetch()['last_block'] ?? 0);
    }

    private function setState(int $block): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO scan_state (network, last_block) VALUES (?, ?) ON CONFLICT(network) DO UPDATE SET last_block = excluded.last_block');
        $stmt->execute([$this->network, $block]);
    }
}
```

> Nota MySQL/SQLite: `setState` usa `ON CONFLICT ... DO UPDATE` (SQLite). En producción MySQL se usa `ON DUPLICATE KEY UPDATE last_block = VALUES(last_block)`. El plan usa la variante SQLite porque los tests corren en SQLite; documentar en el archivo la variante MySQL y dejarla como comentario. (Aceptable: el daemon de producción podría ejecutarse igual en SQLite en una primera fase; el objetivo es que funcione end-to-end sin MySQL si hace falta.)

- [ ] **Step 4: Correr y verificar que pasa**

Run: `vendor/bin/phpunit tests/php/Unit/Core/DepositScannerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/php/Core/DepositScanner.php tests/php/Unit/Core/DepositScannerTest.php
git commit -m "feat(platform): deposit scanner core (log parsing, confirmations, crediting)"
```

---

### Task 7: CLI y daemon escáner (systemd)

**Files:**
- Create: `src/php/cli.php`
- Create: `src/php/scanner.php`
- Create: `systemd/grid-bot-scanner.service`
- Create: `tests/php/Unit/Core/CliRunnerTest.php`

**Interfaces:**
- Consumes: `Wallet::init`, `Accounting::init` (Task 5), `DepositScanner`, `Networks::all`, `RpcClient` (Task 6).

- [ ] **Step 1: Test que falla — CLI runner**

`src/php/cli.php` debe delegar en `BinanceBot\Core\Cli` para poder testear los comandos sin ejecutar procesos. Implementar `src/php/Core/Cli.php`:
```php
<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class Cli
{
    /** @return list<string> líneas de salida */
    public static function run(PDO $pdo, string $command, string $secret, array $args = []): array
    {
        return match ($command) {
            'wallet:init' => self::walletInit($pdo, $secret),
            'accounting:init' => self::accountingInit($pdo, (float)($args[0] ?? 0)),
            'wallet:address' => self::walletAddress($pdo, (int)($args[0] ?? 0), (string)($args[1] ?? 'eth'), $secret),
            default => ['Uso: php cli.php {wallet:init|accounting:init [capital]|wallet:address <userId> <network>}'],
        };
    }

    private static function walletInit(PDO $pdo, string $secret): array
    {
        $res = Wallet::init($pdo, $secret);
        return $res['existing']
            ? ['Wallet ya inicializada.']
            : ['Wallet inicializada (mnemonic cifrado guardado).'];
    }

    private static function accountingInit(PDO $pdo, float $capital): array
    {
        Accounting::init($pdo, $capital);
        return ["Contabilidad inicializada (owner_units = $capital)."];
    }

    private static function walletAddress(PDO $pdo, int $userId, string $network, string $secret): array
    {
        if ($userId <= 0) {
            return ['Uso: php cli.php wallet:address <userId> <network>'];
        }
        return [Wallet::getDepositAddress($pdo, $userId, $network, $secret)];
    }
}
```

`tests/php/Unit/Core/CliRunnerTest.php`:
```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Cli;
use Tests\Support\SqliteSchema;

class CliRunnerTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
    }

    public function testWalletInitCreatesWallet(): void
    {
        $out = Cli::run($this->pdo, 'wallet:init', 'secret');
        $this->assertSame('Wallet inicializada (mnemonic cifrado guardado).', $out[0]);
        $out2 = Cli::run($this->pdo, 'wallet:init', 'secret');
        $this->assertSame('Wallet ya inicializada.', $out2[0]);
    }

    public function testAccountingInitSeeds(): void
    {
        Cli::run($this->pdo, 'accounting:init', 'secret', ['50000']);
        $this->assertSame(50000.0, \BinanceBot\Core\Accounting::ownerUnits($this->pdo));
    }

    public function testWalletAddressPrintsAddress(): void
    {
        Cli::run($this->pdo, 'wallet:init', 'secret');
        $out = Cli::run($this->pdo, 'wallet:address', 'secret', ['7', 'eth']);
        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{40}$/', $out[0]);
    }

    public function testUnknownCommand(): void
    {
        $out = Cli::run($this->pdo, 'nope', 'secret');
        $this->assertStringContainsString('Uso:', $out[0]);
    }
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `vendor/bin/phpunit tests/php/Unit/Core/CliRunnerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implementar `cli.php` (wrapper)**

`src/php/cli.php`:
```php
#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BinanceBot\Core\Cli;
use BinanceBot\Core\Config;
use BinanceBot\Core\Database;
use BinanceBot\Core\Schema;

$db = Database::getInstance();
if (!$db->isConnected()) {
    fwrite(STDERR, "ERROR: sin conexión MySQL\n");
    exit(1);
}
$pdo = $db->getPdo();
Schema::createTables($pdo);

$command = $argv[1] ?? '';
$secret = getenv('PLATFORM_SECRET') ?: '';
if ($secret === '') {
    fwrite(STDERR, "ERROR: define PLATFORM_SECRET en .env o en el entorno\n");
    exit(1);
}

echo implode("\n", Cli::run($pdo, $command, $secret, array_slice($argv, 2))) . "\n";
```

- [ ] **Step 4: Implementar `scanner.php` (daemon)**

`src/php/scanner.php`:
```php
#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BinanceBot\Core\Accounting;
use BinanceBot\Core\Config;
use BinanceBot\Core\Database;
use BinanceBot\Core\DepositScanner;
use BinanceBot\Core\Networks;
use BinanceBot\Core\RpcClient;
use BinanceBot\Core\Schema;

$db = Database::getInstance();
if (!$db->isConnected()) {
    fwrite(STDERR, "ERROR: sin conexión MySQL\n");
    exit(1);
}
$pdo = $db->getPdo();
Schema::createTables($pdo);

$cfg = Config::getInstance();
$interval = (int)($cfg->get('platform.scan_interval_sec', 30));
$minAmount = (float)($cfg->get('platform.min_deposit', 1.0));
$statusFile = (string)($cfg->get('paths.status', dirname(__DIR__, 2) . '/config/grid_status.json'));

error_log('[scanner] iniciado (intervalo ' . $interval . 's)');

while (true) {
    foreach (Networks::all() as $network => $net) {
        $rpcUrl = Networks::rpc($network);
        if ($rpcUrl === '') {
            continue;
        }
        try {
            $scanner = new DepositScanner($pdo, new RpcClient($rpcUrl), $network, Networks::confirmations($network), $net['contracts'] ?? [], $minAmount);
            $tick = $scanner->tick();
            $proc = $scanner->processPending();
            error_log(sprintf('[scanner:%s] head=%d from=%d to=%d logs=%d new=%d credited=%d failed=%d', $network, $tick['head'], $tick['from'], $tick['to'], $tick['logs'], $tick['inserted'], $proc['credited'], $proc['failed']));
        } catch (\Throwable $e) {
            error_log("[scanner:$network] error: " . $e->getMessage());
        }
    }
    try {
        $status = [];
        if (file_exists($statusFile)) {
            $status = json_decode((string)file_get_contents($statusFile), true);
        }
        $realBalance = (float)($status['real_balance'] ?? 0);
        $pnlTotal = (float)($status['pnl_total'] ?? 0);
        if ($realBalance > 0) {
            Accounting::updateNav($pdo, $realBalance, Accounting::walletHeld($pdo), $pnlTotal);
        }
    } catch (\Throwable $e) {
        error_log('[scanner] nav error: ' . $e->getMessage());
    }
    sleep($interval);
}
```

> Si `paths.status` no apunta al JSON correcto, buscar en `config/` y `data/` el archivo de estado del bot (`grid_status.json`) con `real_balance`/`pnl_total`; ajustar la ruta durante la integración (ver Task 11).

- [ ] **Step 5: Implementar la unidad systemd**

`systemd/grid-bot-scanner.service`:
```ini
[Unit]
Description=Grid Bot Deposit Scanner (EVM deposits)
After=network.target mysql.service

[Service]
User=erika
Group=www-data
WorkingDirectory=/home/erika/web/binance.gregorbritez.cat/public_html
Environment=PLATFORM_SECRET=CHANGE_ME
ExecStart=/usr/bin/php /home/erika/web/binance.gregorbritez.cat/public_html/src/php/scanner.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

> `PLATFORM_SECRET` NO se commitea con un valor real. Se instala vía `systemctl edit grid-bot-scanner.service` (override) o `EnvironmentFile`. El valor del unit es un placeholder a reemplazar por el operador.

- [ ] **Step 6: Correr todos los tests y verificar que pasan**

Run: `vendor/bin/phpunit`
Expected: PASS (136 previos + nuevos).

- [ ] **Step 7: Commit**

```bash
git add src/php/Core/Cli.php src/php/cli.php src/php/scanner.php systemd/grid-bot-scanner.service tests/php/Unit/Core/CliRunnerTest.php
git commit -m "feat(platform): CLI commands and scanner daemon with systemd unit"
```

---

### Task 8: Páginas HTTP de autenticación

**Files:**
- Create: `src/php/auth.php`
- Create: `tests/php/Unit/Core/AuthHttpTest.php`

**Interfaces:**
- Consumes: `Auth`, `Csrf`, `Schema` (Tasks 1-2).

- [ ] **Step 1: Test que falla — lógica HTTP de auth**

`src/php/Core/AuthHttp.php` (lógica pura para testear, sin salida HTML):
```php
<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class AuthHttp
{
    public static function handle(PDO $pdo, array $session, array $get, array $post, string $ip): array
    {
        $action = (string)($get['action'] ?? ($post['action'] ?? ''));
        if (!Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
            return ['redirect' => null, 'error' => 'Token CSRF inválido', 'view' => 'login'];
        }
        if ($action === 'register') {
            $res = Auth::register($pdo, (string)($post['username'] ?? ''), (string)($post['email'] ?? ''), (string)($post['password'] ?? ''));
            if ($res['ok']) {
                $session['user_id'] = $res['user_id'];
                $session['username'] = (string)($post['username'] ?? '');
                $session['role'] = 'investor';
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
                session_regenerate_id(true);
                $session['user_id'] = (int)$user['id'];
                $session['username'] = (string)$user['username'];
                $session['role'] = (string)$user['role'];
                return ['redirect' => 'panel.php', 'view' => 'login', 'error' => null];
            }
            return ['redirect' => null, 'error' => 'Usuario o contraseña incorrectos', 'view' => 'login'];
        }
        return ['redirect' => null, 'error' => null, 'view' => 'login'];
    }
}
```

`tests/php/Unit/Core/AuthHttpTest.php`:
```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\AuthHttp;
use BinanceBot\Core\Csrf;
use Tests\Support\SqliteSchema;

class AuthHttpTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
    }

    public function testRegisterSetsSessionAndRedirects(): void
    {
        $session = [];
        $post = ['action' => 'register', 'username' => 'juan', 'email' => 'j@e.com', 'password' => 'secreto123', 'csrf' => Csrf::token($session)];
        $out = AuthHttp::handle($this->pdo, $session, [], $post, '1.2.3.4');
        $this->assertSame('panel.php', $out['redirect']);
        $this->assertSame('juan', $session['username']);
        $this->assertSame('investor', $session['role']);
    }

    public function testLoginWithoutCsrfRejected(): void
    {
        $out = AuthHttp::handle($this->pdo, [], [], ['action' => 'login', 'username' => 'x', 'password' => 'y'], '1.2.3.4');
        $this->assertSame('Token CSRF inválido', $out['error']);
    }

    public function testLoginWrongCredentialsShowsError(): void
    {
        $session = [];
        $post = ['action' => 'login', 'username' => 'nobody', 'password' => 'x', 'csrf' => Csrf::token($session)];
        $out = AuthHttp::handle($this->pdo, $session, [], $post, '1.2.3.4');
        $this->assertSame('Usuario o contraseña incorrectos', $out['error']);
    }
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `vendor/bin/phpunit tests/php/Unit/Core/AuthHttpTest.php`
Expected: FAIL.

- [ ] **Step 3: Implementar `auth.php` (capa HTTP con HTML)**

`src/php/auth.php`:
```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BinanceBot\Core\AuthHttp;
use BinanceBot\Core\Config;
use BinanceBot\Core\Csrf;
use BinanceBot\Core\Database;
use BinanceBot\Core\Schema;

session_start();
session_set_cookie_params([
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Lax',
    'path' => '/',
]);

$db = Database::getInstance();
$pdo = $db->getPdo();
if (!$pdo) {
    http_response_code(500);
    exit('Base de datos no disponible');
}
Schema::createTables($pdo);

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$result = AuthHttp::handle($pdo, $_SESSION, $_GET, $_POST, $ip);

if ($result['redirect']) {
    header('Location: ' . $result['redirect']);
    exit;
}

$csrf = Csrf::token($_SESSION);
$view = $result['view'] === 'register' ? 'register' : 'login';
$error = $result['error'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ingreso · Grid Bot Inversión</title>
<style>
body{font-family:system-ui,sans-serif;background:#0d1117;color:#e6edf3;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.card{background:#161b22;border:1px solid #30363d;border-radius:12px;padding:32px;width:320px}
h1{font-size:20px;margin:0 0 4px}
p.sub{color:#8b949e;font-size:13px;margin:0 0 20px}
label{display:block;font-size:12px;color:#8b949e;margin:12px 0 4px}
input{width:100%;box-sizing:border-box;padding:10px;border-radius:6px;border:1px solid #30363d;background:#0d1117;color:#e6edf3}
button{width:100%;margin-top:18px;padding:11px;border:0;border-radius:6px;background:#238636;color:#fff;font-weight:600;cursor:pointer}
.error{color:#f85149;font-size:13px;margin-top:12px}
.alt{margin-top:16px;font-size:13px;text-align:center}
a{color:#58a6ff}
</style>
</head>
<body>
<div class="card">
    <h1>Grid Bot · Inversión</h1>
    <p class="sub"><?= $view === 'register' ? 'Crear cuenta de inversor' : 'Ingresar' ?></p>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($view === 'register'): ?>
    <form method="post">
        <input type="hidden" name="action" value="register">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <label>Usuario</label><input name="username" required minlength="3" maxlength="50" autocomplete="username">
        <label>Email</label><input name="email" type="email" required autocomplete="email">
        <label>Contraseña (mín. 8)</label><input name="password" type="password" required minlength="8" autocomplete="new-password">
        <button type="submit">Crear cuenta</button>
    </form>
    <div class="alt">¿Ya tienes cuenta? <a href="auth.php?view=login">Ingresar</a></div>
    <?php else: ?>
    <form method="post">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <label>Usuario</label><input name="username" required autocomplete="username">
        <label>Contraseña</label><input name="password" type="password" required autocomplete="current-password">
        <button type="submit">Ingresar</button>
    </form>
    <div class="alt">¿Sin cuenta? <a href="auth.php?view=register">Registrarse</a></div>
    <?php endif; ?>
</div>
</body>
</html>
```

> `session_set_cookie_params` se llama después de `session_start()` en el código de arriba (truco para que el parámetro `path` no rompa sesiones en el subdirectorio `src/php/`). Si da problemas de "headers already sent" porque no hay output previo no ocurre; de cualquier forma llamar `session_set_cookie_params` antes de `session_start()` es más correcto: mover la llamada antes de `session_start()` si el entorno lo exige.

- [ ] **Step 4: Correr y verificar que pasa**

Run: `vendor/bin/phpunit tests/php/Unit/Core/AuthHttpTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/php/Core/AuthHttp.php src/php/auth.php tests/php/Unit/Core/AuthHttpTest.php
git commit -m "feat(platform): registration and login pages with CSRF and rate limit"
```

---

### Task 9: Panel del inversor

**Files:**
- Create: `src/php/Core/InvestorHttp.php`
- Create: `src/php/panel.php`
- Create: `tests/php/Unit/Core/InvestorHttpTest.php`

**Interfaces:**
- Consumes: `Accounting`, `Wallet`, `Networks`, `Csrf` (Tasks 3-5).
- Produces: `InvestorHttp::handle(PDO, array &$session, array $get, array $post, string $secret): array{view:string, data:array, redirect?:string, error?:?string}`.

- [ ] **Step 1: Test que falla**

`tests/php/Unit/Core/InvestorHttpTest.php`:
```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Accounting;
use BinanceBot\Core\Csrf;
use BinanceBot\Core\InvestorHttp;
use BinanceBot\Core\Wallet;
use Tests\Support\SqliteSchema;

class InvestorHttpTest extends TestCase
{
    private \PDO $pdo;
    private const SECRET = 'test-secret';

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
        $this->pdo->exec("INSERT INTO users (id, username, email, password_hash, role) VALUES (1, 'juan', 'j@e.com', 'x', 'investor')");
        Accounting::init($this->pdo, 100000.0);
        Wallet::init($this->pdo, self::SECRET);
    }

    public function testPanelRequiresLogin(): void
    {
        $session = [];
        $out = InvestorHttp::handle($this->pdo, $session, [], [], self::SECRET);
        $this->assertSame('login', $out['view']);
    }

    public function testPanelShowsEquityAndAddress(): void
    {
        $session = ['user_id' => 1, 'role' => 'investor', 'csrf' => bin2hex(random_bytes(32))];
        $out = InvestorHttp::handle($this->pdo, $session, [], [], self::SECRET);
        $this->assertSame('panel', $out['view']);
        $this->assertArrayHasKey('equity', $out['data']);
        $this->assertArrayHasKey('addresses', $out['data']);
        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{40}$/', $out['data']['addresses']['eth']);
    }

    public function testWithdrawalRequestCreatesPending(): void
    {
        $session = ['user_id' => 1, 'role' => 'investor'];
        $this->pdo->prepare('INSERT INTO deposits (user_id, network, token, tx_hash, block_number, amount, status) VALUES (1, "eth", "USDT", "0x1", 1, 1000, "credited")');
        $this->pdo->prepare('INSERT INTO shares (user_id, units) VALUES (1, 1000)');
        $post = ['action' => 'withdraw', 'network' => 'eth', 'token' => 'USDT', 'amount' => '100', 'destination' => '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B', 'csrf' => Csrf::token($session)];
        $out = InvestorHttp::handle($this->pdo, $session, [], $post, self::SECRET);
        $this->assertArrayHasKey('withdrawal_id', $out['data']);
        $row = $this->pdo->query('SELECT * FROM withdrawals')->fetch();
        $this->assertSame('pending', $row['status']);
    }
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `vendor/bin/phpunit tests/php/Unit/Core/InvestorHttpTest.php`
Expected: FAIL.

- [ ] **Step 3: Implementar `InvestorHttp`**

`src/php/Core/InvestorHttp.php`:
```php
<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class InvestorHttp
{
    public static function handle(PDO $pdo, array &$session, array $get, array $post, string $secret): array
    {
        if (empty($session['user_id'])) {
            return ['view' => 'login', 'data' => []];
        }
        $userId = (int)$session['user_id'];
        $action = (string)($post['action'] ?? '');
        $error = null;

        if ($action === 'withdraw') {
            $network = (string)($post['network'] ?? '');
            $token = strtoupper((string)($post['token'] ?? ''));
            $amount = (float)($post['amount'] ?? 0);
            $destination = (string)($post['destination'] ?? '');
            if (!Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
                $error = 'Token CSRF inválido';
            } elseif (!Networks::validateAddress($network, $destination)) {
                $error = 'Dirección inválida para la red';
            } elseif (!in_array($token, ['USDT', 'USDC'], true)) {
                $error = 'Token no soportado';
            } else {
                $min = (float)Config::getInstance()->get('platform.min_withdrawal', 10.0);
                $res = Accounting::requestWithdrawal($pdo, $userId, $network, $token, $amount, $destination, $min);
                if (!$res['ok']) {
                    $error = $res['error'];
                } else {
                    $session['flash'] = 'Retiro solicitado correctamente';
                }
            }
        }

        $addresses = [];
        foreach (array_keys(Networks::all()) as $network) {
            try {
                $addresses[$network] = Wallet::getDepositAddress($pdo, $userId, $network, $secret);
            } catch (\Throwable $e) {
                $addresses[$network] = null;
            }
        }

        $stmt = $pdo->prepare('SELECT * FROM withdrawals WHERE user_id = ? ORDER BY id DESC LIMIT 20');
        $stmt->execute([$userId]);
        $withdrawals = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM movements WHERE user_id = ? ORDER BY id DESC LIMIT 50');
        $stmt->execute([$userId]);
        $movements = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM deposits WHERE user_id = ? ORDER BY id DESC LIMIT 20');
        $stmt->execute([$userId]);
        $deposits = $stmt->fetchAll();

        $data = [
            'equity' => Accounting::userEquity($pdo, $userId),
            'units' => Accounting::userUnits($pdo, $userId),
            'nav' => Accounting::currentNav($pdo),
            'addresses' => $addresses,
            'withdrawals' => $withdrawals,
            'movements' => $movements,
            'deposits' => $deposits,
            'error' => $error,
            'flash' => $session['flash'] ?? null,
            'networks' => array_keys(Networks::all()),
        ];
        unset($session['flash']);
        return ['view' => 'panel', 'data' => $data];
    }
}
```

- [ ] **Step 4: Implementar `panel.php` (HTML)**

`src/php/panel.php`:
```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BinanceBot\Core\Config;
use BinanceBot\Core\Csrf;
use BinanceBot\Core\Database;
use BinanceBot\Core\InvestorHttp;
use BinanceBot\Core\Schema;

session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPdo();
if (!$pdo) {
    http_response_code(500);
    exit('Base de datos no disponible');
}
Schema::createTables($pdo);

$secret = getenv('PLATFORM_SECRET') ?: '';
if ($secret === '') {
    http_response_code(500);
    exit('PLATFORM_SECRET no configurado');
}

$result = InvestorHttp::handle($pdo, $_SESSION, $_GET, $_POST, $secret);
if ($result['view'] === 'login') {
    header('Location: auth.php');
    exit;
}
$d = $result['data'];
$csrf = Csrf::token($_SESSION);
$networks = [
    'eth' => 'Ethereum (ERC20)',
    'bsc' => 'BNB Smart Chain (BEP20)',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mi inversión · Grid Bot</title>
<style>
body{font-family:system-ui,sans-serif;background:#0d1117;color:#e6edf3;margin:0;padding:16px}
.card{background:#161b22;border:1px solid #30363d;border-radius:10px;padding:16px;margin-bottom:14px}
h1{font-size:18px;margin:0 0 4px} h2{font-size:15px;margin:0 0 10px}
.g{color:#3fb950} .r{color:#f85149} .m{color:#8b949e}
.mono{font-family:monospace;font-size:12px;word-break:break-all}
table{width:100%;border-collapse:collapse;font-size:12px}
td,th{text-align:left;padding:6px 4px;border-bottom:1px solid #21262d}
label{display:block;font-size:12px;color:#8b949e;margin:8px 0 4px}
input,select{padding:8px;border-radius:6px;border:1px solid #30363d;background:#0d1117;color:#e6edf3}
button{padding:9px 14px;border:0;border-radius:6px;background:#238636;color:#fff;cursor:pointer}
.flash{background:#1f6feb22;border:1px solid #1f6feb;border-radius:6px;padding:8px;margin-bottom:12px}
.err{background:#f8514922;border:1px solid #f85149;border-radius:6px;padding:8px;margin-bottom:12px}
a{color:#58a6ff}
</style>
</head>
<body>
<h1>Mi inversión</h1>
<p class="m">Usuario: <strong><?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong> · <a href="auth.php?action=logout">Salir</a></p>
<?php if (!empty($d['flash'])): ?><div class="flash"><?= htmlspecialchars($d['flash']) ?></div><?php endif; ?>
<?php if (!empty($d['error'])): ?><div class="err"><?= htmlspecialchars($d['error']) ?></div><?php endif; ?>

<div class="card">
    <h2>Mi saldo</h2>
    <p>Equidad: <strong class="g"><?= number_format($d['equity'], 2) ?> USDT</strong></p>
    <p class="m">Unidades: <?= number_format($d['units'], 8) ?> · NAV: <?= number_format($d['nav'], 6) ?></p>
</div>

<div class="card">
    <h2>Direcciones de depósito (USDT / USDC)</h2>
    <?php foreach ($d['networks'] as $network): ?>
        <p><strong><?= htmlspecialchars($networks[$network] ?? $network) ?></strong></p>
        <p class="mono"><?= htmlspecialchars($d['addresses'][$network] ?? 'no disponible') ?></p>
    <?php endforeach; ?>
    <p class="m">Envía USDT o USDC a tu dirección. Solo se acreditan depósitos confirmados.</p>
</div>

<div class="card">
    <h2>Solicitar retiro</h2>
    <form method="post">
        <input type="hidden" name="action" value="withdraw">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <label>Red</label>
        <select name="network"><?php foreach ($d['networks'] as $n): ?><option value="<?= $n ?>"><?= htmlspecialchars($networks[$n] ?? $n) ?></option><?php endforeach; ?></select>
        <label>Token</label>
        <select name="token"><option>USDT</option><option>USDC</option></select>
        <label>Monto (USDT)</label><input name="amount" type="number" step="0.01" min="0" required>
        <label>Dirección destino</label><input name="destination" placeholder="0x..." required>
        <button type="submit">Solicitar retiro</button>
    </form>
</div>

<div class="card">
    <h2>Mis retiros</h2>
    <table><tr><th>Estado</th><th>Red</th><th>Monto</th><th>Tx</th></tr>
    <?php foreach ($d['withdrawals'] as $w): ?>
        <tr><td><?= htmlspecialchars($w['status']) ?></td><td><?= htmlspecialchars($w['network']) ?></td><td><?= number_format((float)$w['amount'], 2) ?></td><td class="mono"><?= htmlspecialchars($w['tx_hash'] ?: '-') ?></td></tr>
    <?php endforeach; ?>
    </table>
</div>

<div class="card">
    <h2>Depósitos</h2>
    <table><tr><th>Estado</th><th>Red</th><th>Token</th><th>Monto</th><th>Tx</th></tr>
    <?php foreach ($d['deposits'] as $dep): ?>
        <tr><td><?= htmlspecialchars($dep['status']) ?></td><td><?= htmlspecialchars($dep['network']) ?></td><td><?= htmlspecialchars($dep['token']) ?></td><td><?= number_format((float)$dep['amount'], 2) ?></td><td class="mono"><?= htmlspecialchars($dep['tx_hash']) ?></td></tr>
    <?php endforeach; ?>
    </table>
</div>
</body>
</html>
```

- [ ] **Step 5: Correr y verificar que pasa**

Run: `vendor/bin/phpunit tests/php/Unit/Core/InvestorHttpTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/php/Core/InvestorHttp.php src/php/panel.php tests/php/Unit/Core/InvestorHttpTest.php
git commit -m "feat(platform): investor panel with deposit addresses and withdrawals"
```

---

### Task 10: Panel admin

**Files:**
- Create: `src/php/Core/AdminHttp.php`
- Create: `src/php/admin.php`
- Create: `tests/php/Unit/Core/AdminHttpTest.php`

**Interfaces:**
- Consumes: `Accounting`, `Networks`, `Csrf` (Tasks 3, 5).
- Produces: `AdminHttp::handle(PDO, array &$session, array $post): array{view:string, data:array, redirect?:string}`.

- [ ] **Step 1: Test que falla**

`tests/php/Unit/Core/AdminHttpTest.php`:
```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Accounting;
use BinanceBot\Core\AdminHttp;
use BinanceBot\Core\Csrf;
use BinanceBot\Core\Wallet;
use Tests\Support\SqliteSchema;

class AdminHttpTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
        $this->pdo->exec("INSERT INTO users (id, username, email, password_hash, role) VALUES (1, 'admin', 'a@e.com', 'x', 'admin'), (2, 'inv', 'i@e.com', 'x', 'investor')");
        Accounting::init($this->pdo, 100000.0);
    }

    public function testAdminOnly(): void
    {
        $session = ['user_id' => 2, 'role' => 'investor'];
        $out = AdminHttp::handle($this->pdo, $session, []);
        $this->assertSame('forbidden', $out['view']);
    }

    public function testOverviewShowsFundState(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $out = AdminHttp::handle($this->pdo, $session, []);
        $this->assertSame('overview', $out['view']);
        $this->assertArrayHasKey('users', $out['data']);
        $this->assertArrayHasKey('pending_withdrawals', $out['data']);
        $this->assertArrayHasKey('deposits', $out['data']);
        $this->assertSame(1.0, $out['data']['nav']);
    }

    public function testApproveWithdrawalAction(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $this->pdo->exec("INSERT INTO withdrawals (user_id, network, token, amount, units_to_burn, destination_address) VALUES (2, 'eth', 'USDT', 100, 100, '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B')");
        $wdId = (int)$this->pdo->query('SELECT id FROM withdrawals')->fetch()['id'];
        $post = ['action' => 'approve', 'id' => (string)$wdId, 'csrf' => Csrf::token($session)];
        AdminHttp::handle($this->pdo, $session, $post);
        $this->assertSame('approved', $this->pdo->query('SELECT status FROM withdrawals')->fetch()['status']);
    }

    public function testMarkSentAction(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $this->pdo->exec("INSERT INTO withdrawals (user_id, network, token, amount, units_to_burn, destination_address, status) VALUES (2, 'eth', 'USDT', 100, 100, '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B', 'approved')");
        $wdId = (int)$this->pdo->query('SELECT id FROM withdrawals')->fetch()['id'];
        $post = ['action' => 'sent', 'id' => (string)$wdId, 'tx_hash' => '0x' . str_repeat('ab', 32), 'csrf' => Csrf::token($session)];
        AdminHttp::handle($this->pdo, $session, $post);
        $this->assertSame('sent', $this->pdo->query('SELECT status FROM withdrawals')->fetch()['status']);
    }

    public function testRejectWithdrawalAction(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $this->pdo->exec("INSERT INTO withdrawals (user_id, network, token, amount, units_to_burn, destination_address) VALUES (2, 'eth', 'USDT', 100, 100, '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B')");
        $wdId = (int)$this->pdo->query('SELECT id FROM withdrawals')->fetch()['id'];
        $post = ['action' => 'reject', 'id' => (string)$wdId, 'note' => 'sin fondos', 'csrf' => Csrf::token($session)];
        AdminHttp::handle($this->pdo, $session, $post);
        $row = $this->pdo->query('SELECT * FROM withdrawals')->fetch();
        $this->assertSame('rejected', $row['status']);
        $this->assertSame('sin fondos', $row['admin_note']);
    }

    public function testSuspendUserAction(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $post = ['action' => 'suspend', 'id' => '2', 'csrf' => Csrf::token($session)];
        AdminHttp::handle($this->pdo, $session, $post);
        $this->assertSame('suspended', $this->pdo->query('SELECT status FROM users WHERE id = 2')->fetch()['status']);
    }

    public function testMarkDeployedAction(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $this->pdo->exec("INSERT INTO deposits (user_id, network, token, tx_hash, block_number, amount, status) VALUES (2, 'eth', 'USDT', '0x1', 1, 500, 'credited')");
        $depId = (int)$this->pdo->query('SELECT id FROM deposits')->fetch()['id'];
        $post = ['action' => 'deploy', 'id' => (string)$depId, 'csrf' => Csrf::token($session)];
        AdminHttp::handle($this->pdo, $session, $post);
        $this->assertSame('1', $this->pdo->query('SELECT deployed FROM deposits')->fetch()['deployed']);
    }
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `vendor/bin/phpunit tests/php/Unit/Core/AdminHttpTest.php`
Expected: FAIL.

- [ ] **Step 3: Implementar `AdminHttp`**

`src/php/Core/AdminHttp.php`:
```php
<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class AdminHttp
{
    public static function handle(PDO $pdo, array &$session, array $post): array
    {
        if (empty($session['user_id']) || ($session['role'] ?? '') !== 'admin') {
            return ['view' => 'forbidden', 'data' => []];
        }
        $action = (string)($post['action'] ?? '');
        $error = null;
        if ($action !== '' && !Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
            $error = 'Token CSRF inválido';
        } elseif ($action === 'approve') {
            Accounting::approveWithdrawal($pdo, (int)($post['id'] ?? 0));
        } elseif ($action === 'sent') {
            Accounting::markSent($pdo, (int)($post['id'] ?? 0), (string)($post['tx_hash'] ?? ''));
        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE withdrawals SET status = 'rejected', admin_note = ? WHERE id = ?")
                ->execute([(string)($post['note'] ?? ''), (int)($post['id'] ?? 0)]);
        } elseif ($action === 'suspend') {
            $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([(int)($post['id'] ?? 0)]);
        } elseif ($action === 'activate') {
            $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([(int)($post['id'] ?? 0)]);
        } elseif ($action === 'deploy') {
            Accounting::markDeployed($pdo, (int)($post['id'] ?? 0));
        }

        $data = [
            'users' => $pdo->query('SELECT id, username, email, role, status, created_at FROM users ORDER BY id')->fetchAll(),
            'pending_withdrawals' => Accounting::pendingWithdrawals($pdo),
            'withdrawals' => $pdo->query('SELECT w.*, u.username FROM withdrawals w JOIN users u ON u.id = w.user_id ORDER BY w.id DESC LIMIT 50')->fetchAll(),
            'deposits' => $pdo->query('SELECT d.*, u.username FROM deposits d JOIN users u ON u.id = d.user_id ORDER BY d.id DESC LIMIT 50')->fetchAll(),
            'nav' => Accounting::currentNav($pdo),
            'total_units' => Accounting::totalUnits($pdo),
            'wallet_held' => Accounting::walletHeld($pdo),
            'error' => $error,
        ];
        return ['view' => 'overview', 'data' => $data];
    }
}
```

- [ ] **Step 4: Implementar `admin.php` (HTML)**

`src/php/admin.php`:
```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BinanceBot\Core\AdminHttp;
use BinanceBot\Core\Csrf;
use BinanceBot\Core\Database;
use BinanceBot\Core\Schema;

session_start();
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: auth.php');
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPdo();
if (!$pdo) {
    http_response_code(500);
    exit('Base de datos no disponible');
}
Schema::createTables($pdo);

$result = AdminHttp::handle($pdo, $_SESSION, $_POST);
if ($result['view'] !== 'overview') {
    http_response_code(403);
    exit('Acceso denegado');
}
$d = $result['data'];
$csrf = Csrf::token($_SESSION);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin · Grid Bot</title>
<style>
body{font-family:system-ui,sans-serif;background:#0d1117;color:#e6edf3;margin:0;padding:16px}
.card{background:#161b22;border:1px solid #30363d;border-radius:10px;padding:16px;margin-bottom:14px}
h1{font-size:18px;margin:0 0 4px} h2{font-size:15px;margin:0 0 10px}
.g{color:#3fb950} .m{color:#8b949e} .mono{font-family:monospace;font-size:12px;word-break:break-all}
table{width:100%;border-collapse:collapse;font-size:12px}
td,th{text-align:left;padding:6px 4px;border-bottom:1px solid #21262d}
button{padding:6px 10px;border:0;border-radius:6px;cursor:pointer}
.b-ok{background:#238636;color:#fff}.b-sent{background:#1f6feb;color:#fff}.b-ko{background:#da3633;color:#fff}.b-neu{background:#30363d;color:#e6edf3}
input{width:340px;max-width:100%;padding:6px;border-radius:6px;border:1px solid #30363d;background:#0d1117;color:#e6edf3}
a{color:#58a6ff}
</style>
</head>
<body>
<h1>Admin</h1>
<p class="m"><a href="auth.php?action=logout">Salir</a> · <a href="panel.php">Mi panel</a></p>
<?php if (!empty($d['error'])): ?><p class="g" style="color:#f85149"><?= htmlspecialchars($d['error']) ?></p><?php endif; ?>

<div class="card">
    <h2>Estado del fondo</h2>
    <p>NAV: <strong class="g"><?= number_format($d['nav'], 6) ?></strong> · Unidades totales: <?= number_format($d['total_units'], 2) ?> · En wallet (sin desplegar): <?= number_format($d['wallet_held'], 2) ?> USDT</p>
</div>

<div class="card">
    <h2>Retiros pendientes</h2>
    <table><tr><th>Usuario</th><th>Red</th><th>Token</th><th>Monto</th><th>Destino</th><th>Acciones</th></tr>
    <?php foreach ($d['pending_withdrawals'] as $w): ?>
        <tr>
            <td><?= htmlspecialchars($w['username']) ?></td>
            <td><?= htmlspecialchars($w['network']) ?></td>
            <td><?= htmlspecialchars($w['token']) ?></td>
            <td><?= number_format((float)$w['amount'], 2) ?></td>
            <td class="mono"><?= htmlspecialchars($w['destination_address']) ?></td>
            <td>
                <form method="post" style="display:inline"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="b-ok">Aprobar</button></form>
                <form method="post" style="display:inline"><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="b-ko">Rechazar</button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    </table>
</div>

<div class="card">
    <h2>Retiros (historial)</h2>
    <table><tr><th>Usuario</th><th>Estado</th><th>Monto</th><th>Tx</th></tr>
    <?php foreach ($d['withdrawals'] as $w): ?>
        <tr>
            <td><?= htmlspecialchars($w['username']) ?></td>
            <td><?= htmlspecialchars($w['status']) ?></td>
            <td><?= number_format((float)$w['amount'], 2) ?></td>
            <td class="mono"><?= htmlspecialchars($w['tx_hash'] ?: '-') ?></td>
        </tr>
    <?php endforeach; ?>
    </table>
    <?php if ($d['withdrawals']): ?>
    <form method="post" style="margin-top:10px">
        <input type="hidden" name="action" value="sent">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <label class="m">ID retiro aprobado</label>
        <select name="id"><?php foreach ($d['withdrawals'] as $w): ?><?php if ($w['status'] === 'approved'): ?><option value="<?= (int)$w['id'] ?>">#<?= (int)$w['id'] ?> · <?= htmlspecialchars($w['username']) ?> · <?= number_format((float)$w['amount'], 2) ?></option><?php endif; ?><?php endforeach; ?></select>
        <label class="m">Tx hash del envío</label>
        <input name="tx_hash" placeholder="0x...">
        <button class="b-sent">Marcar enviado</button>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Depósitos</h2>
    <table><tr><th>Usuario</th><th>Estado</th><th>Red</th><th>Token</th><th>Monto</th><th>Desplegado</th><th>Tx</th></tr>
    <?php foreach ($d['deposits'] as $dep): ?>
        <tr>
            <td><?= htmlspecialchars($dep['username']) ?></td>
            <td><?= htmlspecialchars($dep['status']) ?></td>
            <td><?= htmlspecialchars($dep['network']) ?></td>
            <td><?= htmlspecialchars($dep['token']) ?></td>
            <td><?= number_format((float)$dep['amount'], 2) ?></td>
            <td>
                <?php if ($dep['status'] === 'credited' && !$dep['deployed']): ?>
                    <form method="post" style="display:inline"><input type="hidden" name="action" value="deploy"><input type="hidden" name="id" value="<?= (int)$dep['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="b-ok">Marcar desplegado</button></form>
                <?php else: ?><?= (int)$dep['deployed'] ? 'Sí' : 'No' ?><?php endif; ?>
            </td>
            <td class="mono"><?= htmlspecialchars($dep['tx_hash']) ?></td>
        </tr>
    <?php endforeach; ?>
    </table>
</div>

<div class="card">
    <h2>Usuarios</h2>
    <table><tr><th>ID</th><th>Usuario</th><th>Email</th><th>Rol</th><th>Estado</th><th>Acciones</th></tr>
    <?php foreach ($d['users'] as $u): ?>
        <tr>
            <td><?= (int)$u['id'] ?></td>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= htmlspecialchars($u['role']) ?></td>
            <td><?= htmlspecialchars($u['status']) ?></td>
            <td>
                <?php if ($u['status'] === 'active'): ?>
                    <form method="post" style="display:inline"><input type="hidden" name="action" value="suspend"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="b-ko">Suspender</button></form>
                <?php else: ?>
                    <form method="post" style="display:inline"><input type="hidden" name="action" value="activate"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="b-neu">Activar</button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </table>
</div>
</body>
</html>
```

- [ ] **Step 5: Correr y verificar que pasa**

Run: `vendor/bin/phpunit tests/php/Unit/Core/AdminHttpTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/php/Core/AdminHttp.php src/php/admin.php tests/php/Unit/Core/AdminHttpTest.php
git commit -m "feat(platform): admin panel for deposits, withdrawals, and users"
```

---

### Task 11: Integración y verificación end-to-end

**Files:**
- Modify: `README.md` (sección plataforma) — opcional, si el README es del operador.
- Create: `tests/php/Integration/PlatformFlowTest.php` (flujo completo sobre SQLite).

**Interfaces:**
- Consumes: todas las tareas anteriores.

- [ ] **Step 1: Test de integración del flujo completo**

`tests/php/Integration/PlatformFlowTest.php`:
```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Accounting;
use BinanceBot\Core\Auth;
use BinanceBot\Core\DepositScanner;
use BinanceBot\Core\RpcClient;
use BinanceBot\Core\Wallet;
use BinanceBot\Core\Networks;
use Tests\Support\SqliteSchema;

class PlatformFlowTest extends TestCase
{
    private \PDO $pdo;
    private const SECRET = 'integration-secret';
    private const USDT = '0x55d398326f99059fF775485246999027B3197955';
    private const ADDR = '0xab5801a7d398351b8be11c439e05c5b3259aec9b';

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
    }

    public function testFullInvestorJourney(): void
    {
        // 1. Init
        Accounting::init($this->pdo, 100000.0);
        Wallet::init($this->pdo, self::SECRET);
        // 2. Register user + deposit address
        Auth::register($this->pdo, 'juan', 'j@e.com', 'secreto123');
        $userId = (int)$this->pdo->query('SELECT id FROM users')->fetch()['id'];
        $address = Wallet::getDepositAddress($this->pdo, $userId, 'bsc', self::SECRET);
        // 3. Scanner detects transfer and credits after confirmations
        $this->pdo->exec("INSERT INTO deposit_addresses (user_id, network, address, derivation_index) VALUES ($userId, 'bsc', '" . self::ADDR . "', 999)");
        $fakeRpc = new RpcClient('http://fake', function (string $url, string $payload) {
            $req = json_decode($payload, true);
            if ($req['method'] === 'eth_blockNumber') return '{"jsonrpc":"2.0","id":1,"result":"0x1000"}';
            if ($req['method'] === 'eth_getLogs') return '{"jsonrpc":"2.0","id":1,"result":[' . $this->logFor(self::ADDR) . ']}';
            if ($req['method'] === 'eth_getTransactionReceipt') return '{"jsonrpc":"2.0","id":1,"result":{"status":"0x1"}}';
            return '{"jsonrpc":"2.0","id":1,"result":null}';
        });
        $scanner = new DepositScanner($this->pdo, $fakeRpc, 'bsc', 15, ['USDT' => self::USDT], 1.0);
        $scanner->tick();
        $scanner->processPending();
        $this->assertSame('credited', $this->pdo->query('SELECT status FROM deposits')->fetch()['status']);
        $this->assertEqualsWithDelta(100.0, Accounting::userEquity($this->pdo, $userId), 0.01);
        // 4. Nav growth
        Accounting::updateNav($this->pdo, 111000.0, 0.0, 11000.0);
        $this->assertEqualsWithDelta(110.0, Accounting::userEquity($this->pdo, $userId), 0.01);
        // 5. Withdrawal request + approve
        $wd = Accounting::requestWithdrawal($this->pdo, $userId, 'bsc', 'USDT', 50.0, self::ADDR, 10.0);
        $this->assertTrue($wd['ok']);
        Accounting::approveWithdrawal($this->pdo, $wd['withdrawal_id']);
        $this->assertEqualsWithDelta(50.0, Accounting::userUnits($this->pdo, $userId), 0.01);
    }

    private function logFor(string $address): string
    {
        return json_encode([
            'address' => self::USDT,
            'blockNumber' => '0xfa0',
            'transactionHash' => '0x' . bin2hex(random_bytes(20)),
            'topics' => [Networks::TRANSFER_TOPIC0, '0x' . str_repeat('0', 64), DepositScanner::padAddress($address)],
            'data' => '0x' . str_pad(dechex(100 * 10 ** 18), 64, '0', STR_PAD_LEFT),
        ]);
    }
}
```

- [ ] **Step 2: Correr y verificar que pasa**

Run: `vendor/bin/phpunit tests/php/Integration/PlatformFlowTest.php`
Expected: PASS.

- [ ] **Step 3: Verificación manual end-to-end (operador)**

1. `composer dump-autoload` si hace falta; confirmar `vendor/bin/phpunit` todo verde y `php -l` en `auth.php`, `panel.php`, `admin.php`, `cli.php`, `scanner.php`, `Core/*`.
2. Crear `.env` en `public_html/` con `PLATFORM_SECRET=<secreto-fuerte>`.
3. `php src/php/cli.php wallet:init` → "Wallet inicializada".
4. `php src/php/cli.php accounting:init 100000` (usar `capital_usd` real del bot).
5. En MySQL (driver real) ejecutar `Schema::ddl()` una vez (o arrancar `scanner.php` que lo hace en cada ciclo).
6. Crear usuario admin directo en DB:
   `INSERT INTO users (username, email, password_hash, role) VALUES ('admin','admin@dominio.com', '<hash bcrypt generado con password_hash()>', 'admin');`
7. Acceder a `https://binance.gregorbritez.cat/src/php/auth.php` → registrar un inversor, entrar al panel, ver dirección por red.
8. Hacer un depósito de prueba de USDT (BEP20) a la dirección generada y esperar ~15 bloques: verificar en `panel.php` el depósito `credited`.
9. Solicitar un retiro desde el panel; aprobar en `admin.php`; marcar enviado con el tx_hash real.
10. Arrancar el daemon: `sudo systemctl link /home/erika/web/binance.gregorbritez.cat/public_html/systemd/grid-bot-scanner.service`, setear `PLATFORM_SECRET` (override o `EnvironmentFile`), `systemctl start grid-bot-scanner`, verificar `journalctl -u grid-bot-scanner -f`.

- [ ] **Step 4: Commit**

```bash
git add tests/php/Integration/PlatformFlowTest.php
git commit -m "test(platform): end-to-end investor journey integration test"
```

---

## Self-Review (completado)

- **Cobertura del spec:** login/registro (Task 2, 8), direcciones por red EVM (Task 4, 9), escáner y confirmaciones (Task 6, 7), contabilidad NAV/unidades (Task 5), retiros aprobación admin (Task 5, 10), panel admin (Task 10), manejo de errores (RPC fallbacks Task 3/7, dedupe Task 6), tests (todas las tareas), fuera de alcance respetado (sin envío automático de retiros: el admin pega el tx_hash).
- **Sin placeholders:** cada paso con código completo y comandos exactos.
- **Consistencia de tipos:** `Accounting::updateNav(PDO, float, float, float)` definido en Task 5 y usado en Task 7 y Task 11; `DepositScanner::padAddress/unpadAddress/parseAmount` definidos en Task 6 y usados en Task 11; `Wallet::getDepositAddress(PDO, int, string, string)` consistente en Tasks 4/9/11; `Accounting::walletHeld(PDO): float` definido y usado en Tasks 5/7/10.
- **Notas de dialecto SQL:** `datetime("now")` (SQLite) vs `NOW()` (MySQL) y `ON CONFLICT ... DO UPDATE` (SQLite) vs `ON DUPLICATE KEY UPDATE` (MySQL) documentados en las tareas; el daemon puede arrancar con SQLite si no hay MySQL, los tests corren 100% en SQLite in-memory.
