# Paneles Admin Unificado + Inversor Mejorado — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convertir el panel admin en un hub unificado (fondo + control/monitor del grid bot) y mejorar el panel del inversor con gráficas, paginación y perfil.

**Architecture:** Patrón server-rendered PHP existente. Reusa los endpoints ya admin-protegidos de `grid_ajax.php` (`_status`, `_logs`, `_ticker`, `_control`) para el monitor/control del bot; amplía `AdminHttp`/`InvestorHttp`; tabla nueva `admin_audit`; Chart.js desde CDN (ya en uso en `index.php`). `index.php` queda intacto.

**Tech Stack:** PHP 8.x, PDO MySQL, sqlite para tests, Chart.js 4 (CDN), design-system.css existente.

## Global Constraints

- `index.php`, `grid_ajax.php` (salvo el endpoint nuevo), `login.php`, `register.php`, `auth.php` NO se reescriben.
- Seguridad: toda acción nueva valida `Csrf::verify` contra la sesión y el rol correspondiente (`admin` / usuario propio).
- `vendor/` no se toca. Suite existente debe quedar verde (baseline 241 tests / 993 assertions, 1 warning + 1 deprecación).
- Nombres de tablas/columnas en español existentes se respetan (`movements`, `shares`, `nav_snapshots`, `admin_audit`).
- El monto de ajuste de saldo se valida con `preg_match('/^\d{1,14}(\.\d{1,8})?$/')` y tipo en whitelist `['deposit','correction','refund']`.
- Los ajustes de saldo solo acreditan unidades (positivos); la reducción de saldo se hace por retiros (flujo existente).

---

### Task 1: Esquema — tabla `admin_audit` + columna `movements.note`

**Files:**
- Modify: `src/php/Core/Schema.php`
- Modify: `tests/Support/SqliteSchema.php`
- Modify: `tests/php/Unit/Core/SchemaTest.php`

**Interfaces:**
- Consumes: nada nuevo.
- Produces: `Schema::ddl(): array` incluye `CREATE TABLE IF NOT EXISTS admin_audit` y `movements` con columna `note VARCHAR(255) NOT NULL DEFAULT ''`. `Schema::createTables(PDO $pdo): void` ejecuta además un `ALTER TABLE movements ADD COLUMN note ...` en try/catch. `SqliteSchema::apply(PDO)` crea `admin_audit` y `movements` con `note`.

- [ ] **Step 1: Actualizar el test de Schema para la tabla nueva y el ALTER**

En `tests/php/Unit/Core/SchemaTest.php`, añadir estos tests:

```php
    public function testAdminAuditTableExists(): void
    {
        $ddl = implode("\n", Schema::ddl());
        $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS admin_audit", $ddl, "falta tabla admin_audit");
    }

    public function testMovementsHasNoteColumn(): void
    {
        $ddl = implode("\n", Schema::ddl());
        $this->assertStringContainsString('note VARCHAR(255) NOT NULL DEFAULT', $ddl, "falta columna note en movements");
    }
```

- [ ] **Step 2: Correr el test nuevo para verlo fallar**

Run: `php vendor/bin/phpunit --filter 'AdminAuditTableExists|MovementsHasNoteColumn' tests/php/Unit/Core/SchemaTest.php`
Expected: FAIL (tabla/columna no existen aún).

- [ ] **Step 3: Implementar en `Schema.php`**

En `src/php/Core/Schema.php`:
1. En `ddl()`, dentro del `CREATE TABLE IF NOT EXISTS movements (`, añadir la columna `note` después de `balance_after`:

```php
            "CREATE TABLE IF NOT EXISTS movements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                type ENUM('deposit','withdrawal','adjust') NOT NULL,
                amount DECIMAL(20,8) NOT NULL DEFAULT 0,
                units DECIMAL(20,8) NOT NULL DEFAULT 0,
                nav DECIMAL(20,8) NOT NULL DEFAULT 0,
                balance_after DECIMAL(20,8) NOT NULL DEFAULT 0,
                note VARCHAR(255) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
```

2. Añadir la tabla `admin_audit` al array `ddl()` (después de `admin_sends`):

```php
            "CREATE TABLE IF NOT EXISTS admin_audit (
                id INT AUTO_INCREMENT PRIMARY KEY,
                admin_id INT NOT NULL,
                username VARCHAR(50) NOT NULL DEFAULT '',
                action VARCHAR(50) NOT NULL,
                detail VARCHAR(500) NOT NULL DEFAULT '',
                ip VARCHAR(45) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_admin (admin_id, created_at),
                INDEX idx_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
```

3. Añadir el ALTER (para BD existentes) en `createTables()`:

```php
    public static function createTables(PDO $pdo): void
    {
        foreach (self::ddl() as $sql) {
            $pdo->exec($sql);
        }
        try {
            $pdo->exec("ALTER TABLE movements ADD COLUMN note VARCHAR(255) NOT NULL DEFAULT ''");
        } catch (\Throwable $e) {
            // columna ya existe
        }
    }
```

- [ ] **Step 4: Actualizar `testCreateTablesExecutesEachStatement`** (ahora hay 1 exec extra por el ALTER)

En `SchemaTest.php`, cambiar `->times($n)` por `->times($n + 1)`.

- [ ] **Step 5: Implementar en `SqliteSchema.php`**

En `tests/Support/SqliteSchema.php`:
1. En el CREATE de `movements`, añadir `note TEXT DEFAULT ''`:

```php
        $pdo->exec('CREATE TABLE movements (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type TEXT, amount REAL DEFAULT 0, units REAL DEFAULT 0, nav REAL DEFAULT 0, balance_after REAL DEFAULT 0, note TEXT DEFAULT "", created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
```

2. Añadir la tabla `admin_audit` al final de `apply()`:

```php
        $pdo->exec('CREATE TABLE admin_audit (id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INTEGER NOT NULL, username TEXT DEFAULT "", action TEXT, detail TEXT DEFAULT "", ip TEXT DEFAULT "", created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
```

- [ ] **Step 6: Correr los tests para verlos pasar**

Run: `php vendor/bin/phpunit --filter SchemaTest tests/php/Unit/Core/SchemaTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/php/Core/Schema.php tests/Support/SqliteSchema.php tests/php/Unit/Core/SchemaTest.php
git commit -m "feat(db): tabla admin_audit y columna movements.note"
```

---

### Task 2: `Accounting::adjustUnits` + `addMovement` con nota

**Files:**
- Modify: `src/php/Core/Accounting.php`
- Modify: `tests/php/Unit/Core/AccountingTest.php`

**Interfaces:**
- Consumes: `Schema::ddl()` con `movements.note` y `admin_audit` (Task 1); `SqliteSchema` correspondiente.
- Produces:
  - `Accounting::adjustUnits(PDO $pdo, int $userId, float $amountUsd, string $type, string $reason = ''): bool` — acredita `amountUsd / nav` unidades en `shares`, registra un `movements` tipo `adjust` con `note` y devuelve `true`; `false` si `units <= 0` o error de BD.
  - `Accounting::addMovement(PDO $pdo, int $userId, string $type, float $amount, float $units, float $nav, string $note = ''): void` (privado) — ahora inserta también `note`.

- [ ] **Step 1: Escribir el test que falla** — añadir a `tests/php/Unit/Core/AccountingTest.php`:

```php
    public function testAdjustUnitsCreditsSharesAndMovement(): void
    {
        $this->pdo->exec("INSERT INTO users (id, username, email, password_hash, role) VALUES (2, 'inv2', 'i2@e.com', 'x', 'investor')");
        Accounting::init($this->pdo, 100000.0);
        $ok = Accounting::adjustUnits($this->pdo, 2, 500.0, 'deposit', 'depósito manual verificado');
        $this->assertTrue($ok);
        $units = $this->pdo->query('SELECT units FROM shares WHERE user_id = 2')->fetch()['units'];
        $this->assertSame(500.0, (float)$units);
        $mov = $this->pdo->query('SELECT * FROM movements WHERE user_id = 2')->fetch();
        $this->assertSame('adjust', $mov['type']);
        $this->assertSame('depósito manual verificado', $mov['note']);
    }

    public function testAdjustUnitsRejectsNonPositive(): void
    {
        Accounting::init($this->pdo, 100000.0);
        $this->assertFalse(Accounting::adjustUnits($this->pdo, 1, 0.0, 'deposit', 'x'));
    }
```

(Revisa en `AccountingTest.php` cómo se inicializa `$this->pdo` en `setUp()`; si usa `SqliteSchema::apply($this->pdo)` y crea el usuario 1, sigue ese mismo patrón para estos tests.)

- [ ] **Step 2: Correr el test para verlo fallar**

Run: `php vendor/bin/phpunit --filter 'AdjustUnits' tests/php/Unit/Core/AccountingTest.php`
Expected: FAIL (método no existe).

- [ ] **Step 3: Implementar en `Accounting.php`**

1. Cambiar `addMovement()` para insertar `note`:

```php
    private static function addMovement(PDO $pdo, int $userId, string $type, float $amount, float $units, float $nav, string $note = ''): void
    {
        $balanceAfter = round(self::userUnits($pdo, $userId) * $nav, 8);
        $stmt = $pdo->prepare('INSERT INTO movements (user_id, type, amount, units, nav, balance_after, note) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $type, $amount, $units, $nav, $balanceAfter, $note]);
    }
```

2. Añadir el método público tras `markDeployed()`:

```php
    public static function adjustUnits(PDO $pdo, int $userId, float $amountUsd, string $type, string $reason = ''): bool
    {
        $nav = self::currentNav($pdo);
        $units = round($amountUsd / $nav, 8);
        if ($units <= 0) {
            return false;
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO shares (user_id, units) VALUES (?, ?)')->execute([$userId, $units]);
            self::addMovement($pdo, $userId, 'adjust', $amountUsd, $units, $nav, $reason);
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }
    }
```

- [ ] **Step 4: Correr el test para verlo pasar**

Run: `php vendor/bin/phpunit --filter 'AdjustUnits' tests/php/Unit/Core/AccountingTest.php`
Expected: PASS.

- [ ] **Step 5: Correr los tests de Accounting completos** (validar que `addMovement` con nota no rompe créditos/retiros)

Run: `php vendor/bin/phpunit tests/php/Unit/Core/AccountingTest.php tests/php/Unit/Core/AdminHttpTest.php tests/php/Unit/Core/InvestorHttpTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/php/Core/Accounting.php tests/php/Unit/Core/AccountingTest.php
git commit -m "feat(accounting): adjustUnits y addMovement con nota"
```

---

### Task 3: `AdminHttp` — auditoría, acción `adjust_user` y datos nuevos

**Files:**
- Modify: `src/php/Core/AdminHttp.php`
- Modify: `tests/php/Unit/Core/AdminHttpTest.php`

**Interfaces:**
- Consumes: `Accounting::adjustUnits()` (Task 2), `admin_audit`/`movements.note` (Task 1).
- Produces:
  - `AdminHttp::handle(PDO $pdo, array &$session, array $post, ?callable $sendDirect = null, ?RpcClient $rpc = null): array` — misma firma. Acciones nuevas: `adjust_user`. Escribe filas en `admin_audit` en cada acción admin. Datos nuevos en `$data`: `nav_history`, `audit_logs`, `fills`, `flash`.
  - Helper privado `AdminHttp::audit(PDO $pdo, array $session, string $action, string $detail): void`.

- [ ] **Step 1: Escribir los tests que fallan** — añadir a `tests/php/Unit/Core/AdminHttpTest.php`:

```php
    public function testAdjustUserCreditsSharesAndAudits(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $session['csrf'] = Csrf::token($session);
        $post = [
            'action' => 'adjust_user',
            'user_id' => '2',
            'adjust_type' => 'deposit',
            'amount' => '250.00',
            'reason' => 'depósito manual verificado',
            'csrf' => $session['csrf'],
        ];
        $out = AdminHttp::handle($this->pdo, $session, $post);
        $this->assertSame('overview', $out['view']);
        $units = $this->pdo->query('SELECT units FROM shares WHERE user_id = 2')->fetch();
        $this->assertSame(250.0, (float)$units['units']);
        $mov = $this->pdo->query('SELECT * FROM movements WHERE user_id = 2 AND type = "adjust"')->fetch();
        $this->assertSame('depósito manual verificado', $mov['note']);
        $audit = $this->pdo->query('SELECT * FROM admin_audit WHERE action = "adjust_user"')->fetch();
        $this->assertSame(1, (int)$audit['admin_id']);
        $this->assertSame('admin', $audit['username']);
    }

    public function testAdjustUserRejectsInvalidAmount(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $session['csrf'] = Csrf::token($session);
        $post = ['action' => 'adjust_user', 'user_id' => '2', 'adjust_type' => 'refund', 'amount' => 'abc', 'reason' => 'x', 'csrf' => $session['csrf']];
        $out = AdminHttp::handle($this->pdo, $session, $post);
        $this->assertStringContainsString('Monto inválido', $out['data']['error'] ?? '');
        $this->assertNull($this->pdo->query('SELECT id FROM admin_audit')->fetch());
    }

    public function testAdjustUserRejectsBadCsrf(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $post = ['action' => 'adjust_user', 'user_id' => '2', 'adjust_type' => 'deposit', 'amount' => '10', 'reason' => 'x', 'csrf' => 'wrong'];
        AdminHttp::handle($this->pdo, $session, $post);
        $this->assertNull($this->pdo->query('SELECT id FROM admin_audit')->fetch());
    }

    public function testApproveWithdrawalWritesAudit(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $this->pdo->exec("INSERT INTO withdrawals (user_id, network, token, amount, units_to_burn, destination_address) VALUES (2, 'eth', 'USDT', 100, 100, '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B')");
        $wdId = (int)$this->pdo->query('SELECT id FROM withdrawals')->fetch()['id'];
        $post = ['action' => 'approve', 'id' => (string)$wdId, 'csrf' => Csrf::token($session)];
        AdminHttp::handle($this->pdo, $session, $post);
        $audit = $this->pdo->query('SELECT * FROM admin_audit WHERE action = "approve_withdrawal"')->fetch();
        $this->assertSame((string)$wdId, (string)(int)$audit['detail'] ?: '');
    }

    public function testOverviewIncludesNewDataKeys(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $out = AdminHttp::handle($this->pdo, $session, []);
        $this->assertArrayHasKey('nav_history', $out['data']);
        $this->assertArrayHasKey('audit_logs', $out['data']);
        $this->assertArrayHasKey('fills', $out['data']);
        $this->assertIsArray($out['data']['fills']);
    }
```

- [ ] **Step 2: Correr los tests para verlos fallar**

Run: `php vendor/bin/phpunit --filter 'AdjustUser|ApproveWithdrawalWritesAudit|OverviewIncludesNewDataKeys' tests/php/Unit/Core/AdminHttpTest.php`
Expected: FAIL (acciones/claves no existen).

- [ ] **Step 3: Implementar en `AdminHttp.php`** — sustituir el archivo por:

```php
<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class AdminHttp
{
    public static function estimateGas(PDO $pdo, string $network, string $token, string $destination, string $amount, string $secret, ?RpcClient $rpc = null): array
    {
        return Wallet::estimateGas($pdo, $secret, $network, $token, $destination, $amount, $rpc);
    }

    public static function handle(PDO $pdo, array &$session, array $post, ?callable $sendDirect = null, ?RpcClient $rpc = null): array
    {
        if (empty($session['user_id']) || ($session['role'] ?? '') !== 'admin') {
            return ['view' => 'forbidden', 'data' => []];
        }
        $action = (string)($post['action'] ?? '');
        $error = null;
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

        if ($action !== '' && !Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
            $error = 'Token CSRF inválido';
        } elseif ($action === 'approve') {
            Accounting::approveWithdrawal($pdo, (int)($post['id'] ?? 0));
            self::audit($pdo, $session, 'approve_withdrawal', 'retiro #' . (int)($post['id'] ?? 0), $ip);
        } elseif ($action === 'sent') {
            Accounting::markSent($pdo, (int)($post['id'] ?? 0), (string)($post['tx_hash'] ?? ''));
            self::audit($pdo, $session, 'mark_sent', 'retiro #' . (int)($post['id'] ?? 0), $ip);
        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE withdrawals SET status = 'rejected', admin_note = ? WHERE id = ?")
                ->execute([(string)($post['note'] ?? ''), (int)($post['id'] ?? 0)]);
            self::audit($pdo, $session, 'reject_withdrawal', 'retiro #' . (int)($post['id'] ?? 0), $ip);
        } elseif ($action === 'suspend') {
            $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([(int)($post['id'] ?? 0)]);
            self::audit($pdo, $session, 'suspend_user', 'usuario #' . (int)($post['id'] ?? 0), $ip);
        } elseif ($action === 'activate') {
            $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([(int)($post['id'] ?? 0)]);
            self::audit($pdo, $session, 'activate_user', 'usuario #' . (int)($post['id'] ?? 0), $ip);
        } elseif ($action === 'deploy') {
            Accounting::markDeployed($pdo, (int)($post['id'] ?? 0));
            self::audit($pdo, $session, 'deploy_deposit', 'depósito #' . (int)($post['id'] ?? 0), $ip);
        } elseif ($action === 'adjust_user') {
            $targetUserId = (int)($post['user_id'] ?? 0);
            $adjustType = (string)($post['adjust_type'] ?? '');
            $amount = trim((string)($post['amount'] ?? ''));
            $reason = trim((string)($post['reason'] ?? ''));
            if (!in_array($adjustType, ['deposit', 'correction', 'refund'], true)) {
                $error = 'Tipo de ajuste inválido';
            } elseif (!preg_match('/^\d{1,14}(\.\d{1,8})?$/', $amount) || (float)$amount <= 0) {
                $error = 'Monto inválido';
            } elseif ($reason === '' || mb_strlen($reason) > 500) {
                $error = 'Motivo obligatorio (máx 500)';
            } else {
                $ok = Accounting::adjustUnits($pdo, $targetUserId, (float)$amount, $adjustType, $reason);
                if ($ok) {
                    self::audit($pdo, $session, 'adjust_user', json_encode([
                        'user_id' => $targetUserId,
                        'type' => $adjustType,
                        'amount' => $amount,
                        'reason' => $reason,
                    ], JSON_UNESCAPED_UNICODE), $ip);
                    $session['flash'] = 'Ajuste aplicado correctamente';
                } else {
                    $error = 'No se pudo aplicar el ajuste';
                }
            }
        } elseif ($action === 'send_direct') {
            if (empty($post['confirm'])) {
                $error = 'Debes confirmar que la dirección y monto son correctos';
            } else {
                $network = (string)($post['network'] ?? '');
                $token = strtoupper((string)($post['token'] ?? ''));
                $destination = (string)($post['destination'] ?? '');
                $amount = trim((string)($post['amount'] ?? ''));

                if (!Networks::validateAddress($network, $destination)) {
                    $error = 'Dirección destino inválida para la red';
                } elseif (!in_array($token, ['USDT', 'USDC'], true)) {
                    $error = 'Token no soportado';
                } elseif (!preg_match('/^\d{1,18}(\.\d{1,18})?$/', $amount)) {
                    $error = 'Monto inválido';
                } else {
                    $secret = getenv('PLATFORM_SECRET') ?: '';
                    if ($secret === '') {
                        $error = 'PLATFORM_SECRET no configurado';
                    } else {
                        $result = $sendDirect
                            ? $sendDirect($pdo, $secret, $network, $token, $destination, $amount)
                            : Wallet::signAndSendERC20($pdo, $secret, $network, $token, $destination, $amount, $rpc);
                        $now = date('Y-m-d H:i:s');
                        if ($result['ok']) {
                            $stmt = $pdo->prepare('INSERT INTO admin_sends (admin_id, network, token, amount, destination_address, tx_hash, status, gas_used, gas_price, sent_at) VALUES (?, ?, ?, ?, ?, ?, "sent", ?, ?, ?)');
                            $stmt->execute([
                                $session['user_id'],
                                $network,
                                $token,
                                $amount,
                                $destination,
                                $result['tx_hash'],
                                $result['gas_used'] ?? 0,
                                $result['gas_price'] ?? 0,
                                $now,
                            ]);
                            self::audit($pdo, $session, 'send_direct', json_encode([
                                'network' => $network,
                                'token' => $token,
                                'amount' => $amount,
                                'destination' => $destination,
                                'tx_hash' => $result['tx_hash'],
                            ], JSON_UNESCAPED_UNICODE), $ip);
                            $session['flash'] = 'Envío exitoso. Tx: ' . $result['tx_hash'];
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO admin_sends (admin_id, network, token, amount, destination_address, status, error_message) VALUES (?, ?, ?, ?, ?, "failed", ?)');
                            $stmt->execute([
                                $session['user_id'],
                                $network,
                                $token,
                                $amount,
                                $destination,
                                $result['error'],
                            ]);
                            self::audit($pdo, $session, 'send_direct', json_encode([
                                'network' => $network,
                                'token' => $token,
                                'amount' => $amount,
                                'error' => $result['error'],
                            ], JSON_UNESCAPED_UNICODE), $ip);
                            $error = $result['error'];
                        }
                    }
                }
            }
        }

        $data = [
            'users' => $pdo->query('SELECT id, username, email, role, status, created_at FROM users ORDER BY id')->fetchAll(),
            'pending_withdrawals' => Accounting::pendingWithdrawals($pdo),
            'withdrawals' => $pdo->query('SELECT w.*, u.username FROM withdrawals w JOIN users u ON u.id = w.user_id ORDER BY w.id DESC LIMIT 50')->fetchAll(),
            'deposits' => $pdo->query('SELECT d.*, u.username FROM deposits d JOIN users u ON u.id = d.user_id ORDER BY d.id DESC LIMIT 50')->fetchAll(),
            'admin_sends' => $pdo->query('SELECT * FROM admin_sends ORDER BY id DESC LIMIT 50')->fetchAll(),
            'nav' => Accounting::currentNav($pdo),
            'total_units' => Accounting::totalUnits($pdo),
            'wallet_held' => Accounting::walletHeld($pdo),
            'nav_history' => $pdo->query('SELECT snapshot_at, nav, total_equity FROM nav_snapshots ORDER BY id DESC LIMIT 90')->fetchAll(),
            'audit_logs' => $pdo->query('SELECT a.* FROM admin_audit a ORDER BY a.id DESC LIMIT 500')->fetchAll(),
            'fills' => [],
            'error' => $error,
            'flash' => $session['flash'] ?? null,
        ];
        try {
            $data['fills'] = $pdo->query("SELECT id, side, grid_role, price, qty, pnl_usd, status, is_recovery, filled_at FROM grid_orders WHERE symbol='ETHUSDT' AND status='FILLED' ORDER BY filled_at DESC LIMIT 200")->fetchAll();
        } catch (\Throwable $e) {
            $data['fills'] = [];
        }
        unset($session['flash']);
        return ['view' => 'overview', 'data' => $data];
    }

    private static function audit(PDO $pdo, array $session, string $action, string $detail, string $ip): void
    {
        $stmt = $pdo->prepare('INSERT INTO admin_audit (admin_id, username, action, detail, ip) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
            (int)($session['user_id'] ?? 0),
            (string)($session['username'] ?? ''),
            $action,
            mb_substr($detail, 0, 500),
            $ip,
        ]);
    }
}
```

Nota: `datetime("now")` (sqlite) en el INSERT de `admin_sends` se reemplaza por un parámetro `$now` (compatible MySQL) — fix menor incluido porque el archivo se toca para la auditoría.

- [ ] **Step 4: Correr los tests de AdminHttp para verlos pasar**

Run: `php vendor/bin/phpunit tests/php/Unit/Core/AdminHttpTest.php`
Expected: PASS (incluye los existentes `testSendDirectSuccess` etc., ahora con `$now`).

- [ ] **Step 5: Correr la suite completa**

Run: `php vendor/bin/phpunit --no-coverage 2>&1 | tail -5`
Expected: `Tests: 247, Assertions: ...` (7 nuevos), Warnings: 1, Deprecations: 1.

- [ ] **Step 6: Commit**

```bash
git add src/php/Core/AdminHttp.php tests/php/Unit/Core/AdminHttpTest.php
git commit -m "feat(admin): auditoría de acciones, ajuste de saldo y datos de gráficas"
```

---

### Task 4: Endpoint `_pnl_cumulative` en `grid_ajax.php`

**Files:**
- Modify: `src/php/grid_ajax.php`
- Modify: `tests/php/Integration/ApiEndpointsTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `GET grid_ajax.php?_pnl_cumulative` (requiere sesión admin, como el resto del archivo) → `{"ok":true,"points":[{"d":"YYYY-MM-DD","p":"<sum>"}]}`.

- [ ] **Step 1: Escribir el test que falla** — añadir a `tests/php/Integration/ApiEndpointsTest.php`:

```php
    public function testPnlCumulativeEndpointReturnsStructure(): void
    {
        $data = $this->executeEndpointAsAdmin(['_pnl_cumulative' => '1']);
        $this->assertIsArray($data);
        $this->assertTrue($data['ok'] ?? false);
        $this->assertArrayHasKey('points', $data);
        $this->assertIsArray($data['points']);
    }
```

- [ ] **Step 2: Correr el test para verlo fallar**

Run: `php vendor/bin/phpunit --filter 'PnlCumulative' tests/php/Integration/ApiEndpointsTest.php`
Expected: FAIL (endpoint no existe → respuesta "Unknown" o error).

- [ ] **Step 3: Implementar en `grid_ajax.php`**

Insertar justo después del bloque `_status` (después de `echo json_encode($data); exit; }` que cierra el `if (isset($_GET['_status']))`, hacia la línea 176):

```php
// ═══════════════════════════════════════════════════════
// 2b. PNL ACUMULADO (requiere sesión admin)
// ═══════════════════════════════════════════════════════
if (isset($_GET['_pnl_cumulative'])) {
    $db = getDB($mc);
    if (!$db) {
        echo json_encode(['ok' => false, 'msg' => 'MySQL no disponible']);
        exit;
    }
    $points = $db->query("SELECT DATE(filled_at) d, ROUND(SUM(pnl_usd),6) p FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' GROUP BY DATE(filled_at) ORDER BY d ASC")->fetchAll();
    echo json_encode(['ok' => true, 'points' => $points]);
    exit;
}
```

- [ ] **Step 4: Correr el test para verlo pasar**

Run: `php vendor/bin/phpunit --filter 'PnlCumulative' tests/php/Integration/ApiEndpointsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/php/grid_ajax.php tests/php/Integration/ApiEndpointsTest.php
git commit -m "feat(bot): endpoint _pnl_cumulative para el panel admin"
```

---

### Task 5: `InvestorHttp` — perfil, contraseña y datos de crecimiento

**Files:**
- Modify: `src/php/Core/InvestorHttp.php`
- Modify: `tests/php/Unit/Core/InvestorHttpTest.php`

**Interfaces:**
- Consumes: `Csrf::verify`, `Accounting::userEquity/userUnits/currentNav`.
- Produces:
  - `InvestorHttp::handle(PDO $pdo, array &$session, array $get, array $post, string $secret): array` — misma firma. Acciones nuevas: `change_password`, `update_profile`. Datos nuevos en `$data`: `growth_pct`, `equity_history`, `email`. Límites de historiales: movements 200, withdrawals/deposits 100.

- [ ] **Step 1: Escribir los tests que fallan** — añadir a `tests/php/Unit/Core/InvestorHttpTest.php`:

```php
    public function testChangePasswordWrongCurrentFails(): void
    {
        $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE id = 1")
            ->execute([password_hash('vieja-pass', PASSWORD_BCRYPT)]);
        $session = ['user_id' => 1, 'role' => 'investor'];
        $post = ['action' => 'change_password', 'current_password' => 'mal', 'new_password' => 'nueva-pass', 'csrf' => Csrf::token($session)];
        $out = InvestorHttp::handle($this->pdo, $session, [], $post, self::SECRET);
        $this->assertStringContainsString('Contraseña actual incorrecta', $out['data']['error'] ?? '');
    }

    public function testChangePasswordOk(): void
    {
        $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE id = 1")
            ->execute([password_hash('vieja-pass', PASSWORD_BCRYPT)]);
        $session = ['user_id' => 1, 'role' => 'investor'];
        $post = ['action' => 'change_password', 'current_password' => 'vieja-pass', 'new_password' => 'nueva-pass', 'csrf' => Csrf::token($session)];
        $out = InvestorHttp::handle($this->pdo, $session, [], $post, self::SECRET);
        $this->assertNull($out['data']['error']);
        $hash = $this->pdo->query('SELECT password_hash FROM users WHERE id = 1')->fetch()['password_hash'];
        $this->assertTrue(password_verify('nueva-pass', $hash));
    }

    public function testUpdateProfileChangesEmail(): void
    {
        $session = ['user_id' => 1, 'role' => 'investor'];
        $post = ['action' => 'update_profile', 'email' => 'nuevo@e.com', 'csrf' => Csrf::token($session)];
        $out = InvestorHttp::handle($this->pdo, $session, [], $post, self::SECRET);
        $this->assertNull($out['data']['error']);
        $this->assertSame('nuevo@e.com', $this->pdo->query('SELECT email FROM users WHERE id = 1')->fetch()['email']);
    }

    public function testPanelIncludesGrowthData(): void
    {
        $session = ['user_id' => 1, 'role' => 'investor'];
        $out = InvestorHttp::handle($this->pdo, $session, [], [], self::SECRET);
        $this->assertArrayHasKey('growth_pct', $out['data']);
        $this->assertArrayHasKey('equity_history', $out['data']);
        $this->assertArrayHasKey('email', $out['data']);
    }
```

- [ ] **Step 2: Correr los tests para verlos fallar**

Run: `php vendor/bin/phpunit --filter 'ChangePassword|UpdateProfile|IncludesGrowthData' tests/php/Unit/Core/InvestorHttpTest.php`
Expected: FAIL.

- [ ] **Step 3: Implementar en `InvestorHttp.php`** — sustituir el archivo por:

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
        $withdrawalId = null;

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
                    $withdrawalId = $res['withdrawal_id'];
                }
            }
        } elseif ($action === 'change_password') {
            if (!Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
                $error = 'Token CSRF inválido';
            } else {
                $current = (string)($post['current_password'] ?? '');
                $new = (string)($post['new_password'] ?? '');
                $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
                $stmt->execute([$userId]);
                $row = $stmt->fetch();
                if (!$row || !password_verify($current, $row['password_hash'])) {
                    $error = 'Contraseña actual incorrecta';
                } elseif (strlen($new) < 8) {
                    $error = 'La nueva contraseña debe tener al menos 8 caracteres';
                } else {
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                        ->execute([password_hash($new, PASSWORD_BCRYPT), $userId]);
                    session_regenerate_id(true);
                    $session['flash'] = 'Contraseña actualizada correctamente';
                }
            }
        } elseif ($action === 'update_profile') {
            if (!Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
                $error = 'Token CSRF inválido';
            } else {
                $email = strtolower(trim((string)($post['email'] ?? '')));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Email inválido';
                } else {
                    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
                    $stmt->execute([$email, $userId]);
                    if ($stmt->fetch()) {
                        $error = 'Ese email ya está en uso';
                    } else {
                        $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $userId]);
                        $session['flash'] = 'Perfil actualizado';
                    }
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

        $stmt = $pdo->prepare('SELECT * FROM withdrawals WHERE user_id = ? ORDER BY id DESC LIMIT 100');
        $stmt->execute([$userId]);
        $withdrawals = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM movements WHERE user_id = ? ORDER BY id DESC LIMIT 200');
        $stmt->execute([$userId]);
        $movements = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM deposits WHERE user_id = ? ORDER BY id DESC LIMIT 100');
        $stmt->execute([$userId]);
        $deposits = $stmt->fetchAll();

        $equity = Accounting::userEquity($pdo, $userId);
        $stmt = $pdo->prepare('SELECT created_at, balance_after FROM movements WHERE user_id = ? ORDER BY id ASC');
        $stmt->execute([$userId]);
        $equityHistory = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS t FROM movements WHERE user_id = ? AND type = 'deposit'");
        $stmt->execute([$userId]);
        $totalDeposited = (float)$stmt->fetch()['t'];
        $growthPct = $totalDeposited > 0 ? round(($equity - $totalDeposited) / $totalDeposited * 100, 2) : 0.0;

        $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $email = $stmt->fetch()['email'] ?? '';

        $data = [
            'equity' => $equity,
            'units' => Accounting::userUnits($pdo, $userId),
            'nav' => Accounting::currentNav($pdo),
            'growth_pct' => $growthPct,
            'equity_history' => $equityHistory,
            'email' => $email,
            'addresses' => $addresses,
            'withdrawals' => $withdrawals,
            'movements' => $movements,
            'deposits' => $deposits,
            'error' => $error,
            'flash' => $session['flash'] ?? null,
            'networks' => array_keys(Networks::all()),
        ];
        if ($withdrawalId !== null) {
            $data['withdrawal_id'] = $withdrawalId;
        }
        unset($session['flash']);
        return ['view' => 'panel', 'data' => $data];
    }
}
```

- [ ] **Step 4: Correr los tests para verlos pasar**

Run: `php vendor/bin/phpunit tests/php/Unit/Core/InvestorHttpTest.php`
Expected: PASS.

- [ ] **Step 5: Correr la suite completa**

Run: `php vendor/bin/phpunit --no-coverage 2>&1 | tail -5`
Expected: verde (Warnings: 1, Deprecations: 1).

- [ ] **Step 6: Commit**

```bash
git add src/php/Core/InvestorHttp.php tests/php/Unit/Core/InvestorHttpTest.php
git commit -m "feat(investor): perfil, cambio de contraseña y datos de crecimiento"
```

---

### Task 6: Panel admin unificado (`admin.php`)

**Files:**
- Modify: `src/php/admin.php` (reescritura completa)

**Interfaces:**
- Consumes: `AdminHttp::handle` data keys (`nav_history`, `audit_logs`, `fills`, `flash`, además de las existentes); `grid_ajax.php` endpoints `_status`, `_logs`, `_ticker`, `_control`, `_pnl_cumulative`; env `SECURITY_TOKEN` para el token de control.

- [ ] **Step 1: `php -l` del archivo actual** (baseline)

Run: `php -l src/php/admin.php`
Expected: `No syntax errors detected`.

- [ ] **Step 2: Reescribir `src/php/admin.php`** con el contenido completo:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use BinanceBot\Core\AdminHttp;
use BinanceBot\Core\Csrf;
use BinanceBot\Core\Database;
use BinanceBot\Core\Schema;

session_set_cookie_params([
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Lax',
    'path' => '/',
]);
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

if (($_GET['action'] ?? '') === 'estimate_gas') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $secret = getenv('PLATFORM_SECRET') ?: '';
    try {
        $result = AdminHttp::estimateGas(
            $pdo,
            (string)($_GET['network'] ?? ''),
            (string)($_GET['token'] ?? ''),
            (string)($_GET['destination'] ?? ''),
            (string)($_GET['amount'] ?? ''),
            $secret
        );
    } catch (\Throwable $e) {
        error_log('[admin.php] estimate_gas: ' . $e->getMessage());
        $result = ['ok' => false, 'error' => 'Error estimando gas'];
    }
    echo json_encode($result);
    exit;
}

$result = AdminHttp::handle($pdo, $_SESSION, $_POST);
if ($result['view'] !== 'overview') {
    http_response_code(403);
    exit('Acceso denegado');
}
$d = $result['data'];
$csrf = Csrf::token($_SESSION);
$CTRL_TOKEN = getenv('SECURITY_TOKEN') ?: '';
$navHistory = array_reverse($d['nav_history']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin · Grid Bot</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/design-system.css">
<link rel="stylesheet" href="assets/css/layout.css">
<link rel="stylesheet" href="assets/css/components.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<nav class="navbar">
    <span class="navbar-brand">Grid Bot · Admin</span>
    <div class="navbar-actions">
        <span class="nav-chip"><span class="chip-label">Usuario</span><span class="chip-val"><?= htmlspecialchars($_SESSION['username'] ?? '') ?></span></span>
        <a class="btn btn-primary navbar-action-btn" href="panel.php">Mi panel</a>
        <a class="btn btn-danger navbar-action-btn" href="auth.php?action=logout">Salir</a>
    </div>
</nav>
<div class="app-container">
    <?php if (!empty($d['error'])): ?>
    <div class="card" style="border-color: var(--red); background: rgba(239,68,68,0.08); margin-top: var(--space-md);">
        <p style="margin:0; color: var(--red); font-size: 0.85rem;"><?= htmlspecialchars($d['error']) ?></p>
    </div>
    <?php endif; ?>
    <?php if (!empty($d['flash'])): ?>
    <div class="card" style="border-color: var(--green); background: rgba(34,197,94,0.08); margin-top: var(--space-md);">
        <p style="margin:0; color: var(--green); font-size: 0.85rem;"><?= htmlspecialchars($d['flash']) ?></p>
    </div>
    <?php endif; ?>

    <?php $activeUsers = 0; foreach ($d['users'] as $u) { if (($u['status'] ?? '') === 'active') { $activeUsers++; } } ?>
    <div class="kpi-row">
        <div class="card"><div class="kpi-card-value green"><?= number_format($d['nav'], 6) ?></div><div class="kpi-card-label">NAV</div></div>
        <div class="card"><div class="kpi-card-value accent"><?= number_format($d['total_units'], 2) ?></div><div class="kpi-card-label">Unidades totales</div></div>
        <div class="card"><div class="kpi-card-value red"><?= count($d['pending_withdrawals']) ?></div><div class="kpi-card-label">Retiros pendientes</div></div>
        <div class="card"><div class="kpi-card-value"><?= $activeUsers ?></div><div class="kpi-card-label">Usuarios activos</div></div>
        <div class="card"><div class="kpi-card-value" id="kpiPrice">-</div><div class="kpi-card-label">ETHUSDT</div></div>
        <div class="card"><div class="kpi-card-value" id="kpiRunning">-</div><div class="kpi-card-label">Bot</div></div>
        <div class="card"><div class="kpi-card-value" id="kpiPnlToday">-</div><div class="kpi-card-label">PnL hoy</div></div>
        <div class="card"><div class="kpi-card-value" id="kpiWinRate">-</div><div class="kpi-card-label">Win rate</div></div>
    </div>

    <div class="panel-tabs">
        <div class="panel-tab active" data-tab="resumen">Resumen</div>
        <div class="panel-tab" data-tab="bot">Bot</div>
        <div class="panel-tab" data-tab="ordenes">Órdenes + PnL</div>
        <div class="panel-tab" data-tab="fondo">Fondo</div>
        <div class="panel-tab" data-tab="usuarios">Usuarios</div>
        <div class="panel-tab" data-tab="auditoria">Auditoría</div>
    </div>

    <div id="tab-resumen" class="panel-content active">
        <div class="card">
            <div class="card-header"><span class="card-title">Estado del fondo</span></div>
            <p style="margin:0;">NAV: <strong style="color: var(--green);"><?= number_format($d['nav'], 6) ?></strong></p>
            <p style="margin:0;">Unidades totales: <strong><?= number_format($d['total_units'], 2) ?></strong></p>
            <p style="margin:0;">En wallet (sin desplegar): <strong><?= number_format($d['wallet_held'], 2) ?> USDT</strong></p>
        </div>
        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Estado del bot</span></div>
            <div class="kpi-row">
                <div class="card"><div class="kpi-card-value" id="rRunning">-</div><div class="kpi-card-label">Estado</div></div>
                <div class="card"><div class="kpi-card-value" id="rMode">-</div><div class="kpi-card-label">Modo</div></div>
                <div class="card"><div class="kpi-card-value" id="rDirection">-</div><div class="kpi-card-label">Dirección</div></div>
                <div class="card"><div class="kpi-card-value" id="rConfidence">-</div><div class="kpi-card-label">Confianza</div></div>
                <div class="card"><div class="kpi-card-value" id="rUptime">-</div><div class="kpi-card-label">Uptime</div></div>
                <div class="card"><div class="kpi-card-value" id="rBalance">-</div><div class="kpi-card-label">Balance Bybit</div></div>
            </div>
            <div style="margin-top: var(--space-md);">
                <a class="btn btn-primary" href="#bot">Ir al monitor del bot</a>
            </div>
        </div>
    </div>

    <div id="tab-bot" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Monitor en vivo <span id="botTs" style="font-family:var(--font-mono);font-size:.75rem;color:var(--text-muted)"></span></span></div>
            <div class="kpi-row">
                <div class="card"><div class="kpi-card-value"><span class="badge" id="botRunning">-</span></div><div class="kpi-card-label">Estado</div></div>
                <div class="card"><div class="kpi-card-value" id="botMode">-</div><div class="kpi-card-label">Modo</div></div>
                <div class="card"><div class="kpi-card-value" id="botPrice">-</div><div class="kpi-card-label">Precio</div></div>
                <div class="card"><div class="kpi-card-value" id="botPnLToday">-</div><div class="kpi-card-label">PnL hoy</div></div>
                <div class="card"><div class="kpi-card-value" id="botWinRate">-</div><div class="kpi-card-label">Win rate</div></div>
                <div class="card"><div class="kpi-card-value" id="botBalance">-</div><div class="kpi-card-label">Balance</div></div>
                <div class="card"><div class="kpi-card-value" id="botUPnL">-</div><div class="kpi-card-label">uPnL</div></div>
                <div class="card"><div class="kpi-card-value" id="botFillsToday">-</div><div class="kpi-card-label">Fills hoy</div></div>
            </div>
            <div style="margin-top: var(--space-md); display:flex; gap: var(--space-sm); flex-wrap: wrap;">
                <button class="btn btn-danger" onclick="cmd('stop')">Detener</button>
                <button class="btn btn-primary" onclick="cmd('force_ai')">Forzar IA</button>
                <button class="btn btn-primary" onclick="cmd('reset_grid')">Reconstruir grilla</button>
                <button class="btn btn-primary" onclick="cmd('reset_pair')">Reset pair</button>
            </div>
        </div>

        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Ticker ETHUSDT</span></div>
            <div id="tickerLine" style="font-family: var(--font-mono); font-size: .85rem;">-</div>
        </div>

        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Logs del bot</span></div>
            <pre id="botLogs" style="max-height: 320px; overflow:auto; background: rgba(0,0,0,.3); border-radius: var(--radius-md); padding: var(--space-md); font-family: var(--font-mono); font-size: .75rem; line-height: 1.5;">Cargando...</pre>
        </div>
    </div>

    <div id="tab-ordenes" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Órdenes abiertas</span></div>
            <table class="data-table">
                <tr><th>Side</th><th>Rol</th><th>Precio</th><th>Qty</th><th>Nivel</th><th class="hide-mobile">Creada</th></tr>
                <tbody id="openOrdersTb"><tr><td colspan="6" class="empty-state">Cargando...</td></tr></tbody>
            </table>
        </div>
        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Fills recientes</span></div>
            <table class="data-table">
                <tr><th>Fecha</th><th>Side</th><th>Rol</th><th>Precio</th><th>Qty</th><th>PnL</th><th class="hide-mobile">Recovery</th></tr>
                <?php foreach ($d['fills'] as $f): ?>
                <tr>
                    <td style="font-family:var(--font-mono);white-space:nowrap;"><?= htmlspecialchars($f['filled_at'] ?? '') ?></td>
                    <td><?= htmlspecialchars($f['side'] ?? '') ?></td>
                    <td><?= htmlspecialchars($f['grid_role'] ?? '') ?></td>
                    <td class="num"><?= number_format((float)($f['price'] ?? 0), 2) ?></td>
                    <td class="num"><?= number_format((float)($f['qty'] ?? 0), 4) ?></td>
                    <td class="num" style="color: <?= ((float)($f['pnl_usd'] ?? 0) >= 0) ? 'var(--green)' : 'var(--red)' ?>;"><?= number_format((float)($f['pnl_usd'] ?? 0), 4) ?></td>
                    <td class="hide-mobile"><?= (int)($f['is_recovery'] ?? 0) ? 'Sí' : 'No' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (empty($d['fills'])): ?><div class="empty-state">Sin fills registrados.</div><?php endif; ?>
        </div>
        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">PnL acumulado</span></div>
            <div style="height: 220px;"><canvas id="cumChart"></canvas></div>
        </div>
        <div style="margin-top: var(--space-md); display:grid; grid-template-columns:1fr 1fr; gap:1px; background:var(--border);">
            <div class="card"><div class="card-header"><span class="card-title">PnL horario 48h</span></div><div style="height:180px;"><canvas id="hChart"></canvas></div></div>
            <div class="card"><div class="card-header"><span class="card-title">PnL diario 14d</span></div><div style="height:180px;"><canvas id="dChart"></canvas></div></div>
        </div>
    </div>

    <div id="tab-fondo" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">NAV histórico</span></div>
            <div style="height: 220px;"><canvas id="navChart"></canvas></div>
        </div>

        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Retiros pendientes</span></div>
            <table class="data-table">
                <tr><th>Usuario</th><th>Red</th><th>Token</th><th>Monto</th><th class="hide-mobile">Destino</th><th>Acciones</th></tr>
                <?php foreach ($d['pending_withdrawals'] as $w): ?>
                <tr>
                    <td><?= htmlspecialchars($w['username']) ?></td>
                    <td><?= htmlspecialchars($w['network']) ?></td>
                    <td><?= htmlspecialchars($w['token']) ?></td>
                    <td class="num"><?= number_format((float)$w['amount'], 2) ?></td>
                    <td class="hide-mobile" style="font-family: var(--font-mono); word-break: break-all;"><?= htmlspecialchars($w['destination_address']) ?></td>
                    <td>
                        <form method="post" style="display:inline"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="btn btn-primary" style="padding: 4px 10px; font-size: 0.75rem;">Aprobar</button></form>
                        <form method="post" style="display:inline"><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="btn btn-danger" style="padding: 4px 10px; font-size: 0.75rem;">Rechazar</button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (empty($d['pending_withdrawals'])): ?><div class="empty-state">Sin retiros pendientes.</div><?php endif; ?>
        </div>

        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Retiros (historial)</span></div>
            <table class="data-table">
                <tr><th>Usuario</th><th>Estado</th><th>Monto</th><th class="hide-mobile">Tx</th></tr>
                <?php foreach ($d['withdrawals'] as $w): ?>
                <tr>
                    <td><?= htmlspecialchars($w['username']) ?></td>
                    <td>
                        <?php $whBadge = ($w['status'] ?? '') === 'sent' ? 'badge-green' : (($w['status'] ?? '') === 'rejected' ? 'badge-red' : 'badge-accent'); ?>
                        <?php $whLabel = ($w['status'] ?? '') === 'sent' ? 'Enviado' : (($w['status'] ?? '') === 'rejected' ? 'Rechazado' : (($w['status'] ?? '') === 'approved' ? 'Aprobado' : 'Pendiente')); ?>
                        <span class="badge <?= $whBadge ?>"><?= $whLabel ?></span>
                    </td>
                    <td class="num"><?= number_format((float)$w['amount'], 2) ?></td>
                    <td class="hide-mobile" style="font-family: var(--font-mono); word-break: break-all;"><?= htmlspecialchars($w['tx_hash'] ?: '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (empty($d['withdrawals'])): ?><div class="empty-state">Sin registros.</div><?php endif; ?>
            <?php if ($d['withdrawals']): ?>
            <form method="post" style="margin-top: var(--space-lg);">
                <input type="hidden" name="action" value="sent">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <div class="cfg-field">
                    <label for="sentId">ID retiro aprobado</label>
                    <select class="cfg-input" id="sentId" name="id"><?php foreach ($d['withdrawals'] as $w): ?><?php if ($w['status'] === 'approved'): ?><option value="<?= (int)$w['id'] ?>">#<?= (int)$w['id'] ?> · <?= htmlspecialchars($w['username']) ?> · <?= number_format((float)$w['amount'], 2) ?></option><?php endif; ?><?php endforeach; ?></select>
                </div>
                <div class="cfg-field" style="margin-top: var(--space-md);">
                    <label for="sentTx">Tx hash del envío</label>
                    <input class="cfg-input" id="sentTx" name="tx_hash" placeholder="0x...">
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top: var(--space-lg);">Marcar enviado</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Depósitos</span></div>
            <table class="data-table">
                <tr><th>Usuario</th><th>Estado</th><th>Red</th><th>Token</th><th>Monto</th><th>Desplegado</th><th class="hide-mobile">Tx</th></tr>
                <?php foreach ($d['deposits'] as $dep): ?>
                <tr>
                    <td><?= htmlspecialchars($dep['username']) ?></td>
                    <td>
                        <?php $adBadge = ($dep['status'] ?? '') === 'pending' ? 'badge-accent' : (($dep['status'] ?? '') === 'credited' ? 'badge-green' : 'badge-red'); ?>
                        <?php $adLabel = ($dep['status'] ?? '') === 'pending' ? 'Pendiente' : (($dep['status'] ?? '') === 'credited' ? 'Acreditado' : 'Fallido'); ?>
                        <span class="badge <?= $adBadge ?>"><?= $adLabel ?></span>
                    </td>
                    <td><?= htmlspecialchars($dep['network']) ?></td>
                    <td><?= htmlspecialchars($dep['token']) ?></td>
                    <td class="num"><?= number_format((float)$dep['amount'], 2) ?></td>
                    <td>
                        <?php if ($dep['status'] === 'credited' && !$dep['deployed']): ?>
                            <form method="post" style="display:inline"><input type="hidden" name="action" value="deploy"><input type="hidden" name="id" value="<?= (int)$dep['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="btn btn-primary" style="padding: 4px 10px; font-size: 0.75rem;">Marcar desplegado</button></form>
                        <?php else: ?><?= (int)$dep['deployed'] ? 'Sí' : 'No' ?><?php endif; ?>
                    </td>
                    <td class="hide-mobile" style="font-family: var(--font-mono); word-break: break-all;"><?= htmlspecialchars($dep['tx_hash']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (empty($d['deposits'])): ?><div class="empty-state">Sin registros.</div><?php endif; ?>
        </div>

        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Envío directo (USDT/USDC)</span></div>
            <form method="post" id="sendForm">
                <input type="hidden" name="action" value="send_direct">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <div class="cfg-field">
                    <label for="network">Red</label>
                    <select class="cfg-input" name="network" id="network" required>
                        <option value="eth">Ethereum (ERC20)</option>
                        <option value="bsc" selected>BNB Smart Chain (BEP20)</option>
                    </select>
                </div>
                <div class="cfg-field" style="margin-top: var(--space-md);">
                    <label for="token">Token</label>
                    <select class="cfg-input" name="token" id="token" required>
                        <option value="USDT" selected>USDT</option>
                        <option value="USDC">USDC</option>
                    </select>
                </div>
                <div class="cfg-field" style="margin-top: var(--space-md);">
                    <label for="destination">Dirección destino</label>
                    <input class="cfg-input" name="destination" id="destination" placeholder="0x..." required pattern="^0x[0-9a-fA-F]{40}$">
                </div>
                <div class="cfg-field" style="margin-top: var(--space-md);">
                    <label for="amount">Monto</label>
                    <input class="cfg-input" name="amount" id="amount" type="number" step="0.00000001" min="0.00000001" placeholder="0.00" required>
                </div>
                <div id="gasEstimate" style="display:none; margin-top: var(--space-md); padding: var(--space-md); background: rgba(14,165,233,0.08); border:1px solid var(--accent); border-radius: var(--radius-md); font-family: var(--font-mono); font-size: 0.8rem;"></div>
                <label style="display:flex;align-items:center;gap:8px;margin-top: var(--space-md);">
                    <input type="checkbox" name="confirm" id="confirm" required>
                    <span style="color: var(--text-muted); font-size: 0.8rem;">Confirmo que la dirección y monto son correctos</span>
                </label>
                <button type="submit" class="btn btn-primary" id="sendBtn" disabled style="margin-top: var(--space-md);">Enviar</button>
            </form>
        </div>

        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Envíos directos (historial)</span></div>
            <table class="data-table">
                <tr><th>ID</th><th>Red</th><th>Token</th><th>Monto</th><th class="hide-mobile">Destino</th><th>Estado</th><th class="hide-mobile">Tx</th></tr>
                <?php foreach ($d['admin_sends'] as $s): ?>
                <tr>
                    <td><?= (int)$s['id'] ?></td>
                    <td><?= htmlspecialchars($s['network']) ?></td>
                    <td><?= htmlspecialchars($s['token']) ?></td>
                    <td class="num"><?= number_format((float)$s['amount'], 8) ?></td>
                    <td class="hide-mobile" style="font-family: var(--font-mono); word-break: break-all;"><?= htmlspecialchars($s['destination_address']) ?></td>
                    <td><span class="badge badge-accent"><?= htmlspecialchars($s['status']) ?></span></td>
                    <td class="hide-mobile" style="font-family: var(--font-mono); word-break: break-all;"><?= htmlspecialchars($s['tx_hash'] ?: $s['error_message'] ?: '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (empty($d['admin_sends'])): ?><div class="empty-state">Sin registros.</div><?php endif; ?>
        </div>
    </div>

    <div id="tab-usuarios" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Usuarios</span></div>
            <table class="data-table">
                <tr><th>ID</th><th>Usuario</th><th class="hide-mobile">Email</th><th>Rol</th><th>Estado</th><th>Acciones</th></tr>
                <?php foreach ($d['users'] as $u): ?>
                <tr>
                    <td><?= (int)$u['id'] ?></td>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td class="hide-mobile"><?= htmlspecialchars($u['email']) ?></td>
                    <td><?= htmlspecialchars($u['role']) ?></td>
                    <td>
                        <?php $uBadge = ($u['status'] ?? '') === 'active' ? 'badge-green' : 'badge-red'; ?>
                        <?php $uLabel = ($u['status'] ?? '') === 'active' ? 'Activo' : 'Suspendido'; ?>
                        <span class="badge <?= $uBadge ?>"><?= $uLabel ?></span>
                    </td>
                    <td>
                        <?php if (($u['status'] ?? '') === 'active'): ?>
                            <form method="post" style="display:inline"><input type="hidden" name="action" value="suspend"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="btn btn-danger" style="padding: 4px 10px; font-size: 0.75rem;">Suspender</button></form>
                        <?php else: ?>
                            <form method="post" style="display:inline"><input type="hidden" name="action" value="activate"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="btn btn-primary" style="padding: 4px 10px; font-size: 0.75rem;">Activar</button></form>
                        <?php endif; ?>
                        <button class="btn btn-primary" style="padding: 4px 10px; font-size: 0.75rem;" onclick="openAdjust(<?= (int)$u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>')">Ajustar</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (empty($d['users'])): ?><div class="empty-state">Sin registros.</div><?php endif; ?>
        </div>
    </div>

    <div id="tab-auditoria" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Auditoría de acciones (últimas 500)</span></div>
            <table class="data-table">
                <tr><th>Fecha</th><th>Admin</th><th>Acción</th><th>Detalle</th><th class="hide-mobile">IP</th></tr>
                <?php foreach ($d['audit_logs'] as $a): ?>
                <tr>
                    <td style="font-family:var(--font-mono);white-space:nowrap;"><?= htmlspecialchars($a['created_at'] ?? '') ?></td>
                    <td><?= htmlspecialchars($a['username'] ?? '') ?></td>
                    <td style="font-family:var(--font-mono);font-size:.8rem;"><?= htmlspecialchars($a['action'] ?? '') ?></td>
                    <td style="max-width: 340px; word-break: break-word;"><?= htmlspecialchars($a['detail'] ?? '') ?></td>
                    <td class="hide-mobile" style="font-family:var(--font-mono);font-size:.8rem;"><?= htmlspecialchars($a['ip'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (empty($d['audit_logs'])): ?><div class="empty-state">Sin acciones registradas.</div><?php endif; ?>
        </div>
    </div>
</div>

<div class="modal-overlay" id="adjustModal" style="display:none;">
    <div class="modal-card">
        <form method="post">
            <input type="hidden" name="action" value="adjust_user">
            <input type="hidden" name="user_id" id="adjUserId">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <div class="card-header"><span class="card-title" id="adjTitle">Ajustar saldo</span></div>
            <div class="cfg-field">
                <label for="adjType">Tipo</label>
                <select class="cfg-input" id="adjType" name="adjust_type">
                    <option value="deposit">Depósito manual</option>
                    <option value="correction">Corrección</option>
                    <option value="refund">Reintegro</option>
                </select>
            </div>
            <div class="cfg-field" style="margin-top: var(--space-md);">
                <label for="adjAmount">Monto (USDT)</label>
                <input class="cfg-input" id="adjAmount" name="amount" type="number" step="0.00000001" min="0.00000001" required>
            </div>
            <div class="cfg-field" style="margin-top: var(--space-md);">
                <label for="adjReason">Motivo</label>
                <input class="cfg-input" id="adjReason" name="reason" maxlength="500" required placeholder="Ej: depósito manual verificado">
            </div>
            <div style="margin-top: var(--space-lg); display:flex; gap: var(--space-sm);">
                <button type="submit" class="btn btn-primary">Aplicar ajuste</button>
                <button type="button" class="btn" onclick="document.getElementById('adjustModal').style.display='none'">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<script>
const API = 'grid_ajax.php';
const CTRL_TOKEN = '<?= htmlspecialchars($CTRL_TOKEN, ENT_QUOTES) ?>';
const NAV_DATA = <?= json_encode($navHistory) ?>;

let pnlHourlyChart = null, pnlDailyChart = null, pnlCumChart = null, navChart = null;

function initCharts() {
    if (typeof Chart === 'undefined') return;
    const baseOpts = { responsive: true, plugins: { legend: { display: false } }, scales: { x: { grid: { color: '#1e3a5f' } }, y: { grid: { color: '#1e3a5f' } } } };
    pnlHourlyChart = new Chart(document.getElementById('hChart'), { type: 'bar', data: { labels: [], datasets: [{ label: 'PnL horario', data: [], backgroundColor: [] }] }, options: baseOpts });
    pnlDailyChart = new Chart(document.getElementById('dChart'), { type: 'bar', data: { labels: [], datasets: [{ label: 'PnL diario', data: [], backgroundColor: [] }] }, options: baseOpts });
    pnlCumChart = new Chart(document.getElementById('cumChart'), { type: 'line', data: { labels: [], datasets: [{ label: 'PnL acumulado', data: [], borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,.15)', fill: true, tension: .2, pointRadius: 0 }] }, options: baseOpts });
    navChart = new Chart(document.getElementById('navChart'), { type: 'line', data: { labels: [], datasets: [{ label: 'NAV', data: [], borderColor: '#22c55e', backgroundColor: 'rgba(34,197,94,.12)', fill: true, tension: .2, pointRadius: 0 }] }, options: baseOpts });
    renderNavChart();
}

function renderNavChart() {
    if (!navChart) return;
    navChart.data.labels = NAV_DATA.map(r => (r.snapshot_at || '').slice(5, 16));
    navChart.data.datasets[0].data = NAV_DATA.map(r => Number(r.nav));
    navChart.update();
}

function botStatus() {
    fetch(API + '?_status&token=' + encodeURIComponent(CTRL_TOKEN), { credentials: 'same-origin' })
        .then(r => r.json()).then(s => {
            if (!s || s.ok === false) return;
            const p = (s.pairs && s.pairs.ETHUSDT) ? s.pairs.ETHUSDT : {};
            const running = !!s.running;
            const runEl = document.getElementById('botRunning');
            if (runEl) { runEl.textContent = running ? 'CORRIENDO' : 'DETENIDO'; runEl.className = 'badge ' + (running ? 'badge-green' : 'badge-red'); }
            const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
            set('botTs', s.ts || '');
            set('botMode', s.mode || 'NORMAL');
            set('botPrice', p.price ? Number(p.price).toFixed(2) : '-');
            set('botPnLToday', (p.pnl_today ?? 0).toFixed(4));
            set('botWinRate', (p.win_rate ?? 0) + '%');
            set('botBalance', (p.real_balance ?? 0).toFixed(2));
            set('botUPnL', (p.total_upnl ?? 0).toFixed(4));
            set('botFillsToday', p.fills_today ?? 0);
            set('kpiPrice', p.price ? Number(p.price).toFixed(2) : '-');
            set('kpiRunning', running ? 'CORRIENDO' : 'DETENIDO');
            set('kpiPnlToday', (p.pnl_today ?? 0).toFixed(4));
            set('kpiWinRate', (p.win_rate ?? 0) + '%');
            set('rRunning', running ? 'CORRIENDO' : 'DETENIDO');
            set('rMode', s.mode || 'NORMAL');
            set('rDirection', p.direction || 'SIDEWAYS');
            set('rConfidence', p.confidence ?? '-');
            set('rUptime', s.uptime || '-');
            set('rBalance', (p.real_balance ?? 0).toFixed(2));
            renderOpenOrders(p.orders || []);
            renderPnLCharts(s.pnl_hourly || [], s.pnl_daily || []);
            loadCumulative();
        }).catch(() => {});
}

function renderOpenOrders(orders) {
    const tb = document.getElementById('openOrdersTb');
    if (!tb) return;
    if (!orders.length) { tb.innerHTML = '<tr><td colspan="6" class="empty-state">Sin órdenes abiertas</td></tr>'; return; }
    tb.innerHTML = orders.map(o =>
        '<tr><td>' + (o.side || '') + '</td><td>' + (o.grid_role || '') + '</td><td class="num">' + Number(o.price || 0).toFixed(2) +
        '</td><td class="num">' + Number(o.qty || 0).toFixed(4) + '</td><td>' + (o.level ?? '') + '</td>' +
        '<td class="hide-mobile" style="font-family:var(--font-mono);font-size:.8rem">' + (o.created_at || '') + '</td></tr>').join('');
}

function renderPnLCharts(hourly, daily) {
    if (!pnlHourlyChart || !pnlDailyChart) return;
    const hl = hourly.map(r => (r.d || '').slice(5) + ' ' + String(r.h).padStart(2, '0') + 'h');
    const hv = hourly.map(r => Number(r.p));
    pnlHourlyChart.data.labels = hl; pnlHourlyChart.data.datasets[0].data = hv;
    pnlHourlyChart.data.datasets[0].backgroundColor = hv.map(v => v >= 0 ? 'rgba(34,197,94,.6)' : 'rgba(239,68,68,.6)');
    pnlHourlyChart.update();
    const dl = daily.map(r => (r.d || '').slice(5));
    const dv = daily.map(r => Number(r.p));
    pnlDailyChart.data.labels = dl; pnlDailyChart.data.datasets[0].data = dv;
    pnlDailyChart.data.datasets[0].backgroundColor = dv.map(v => v >= 0 ? 'rgba(34,197,94,.6)' : 'rgba(239,68,68,.6)');
    pnlDailyChart.update();
}

let cumLoaded = false;
function loadCumulative() {
    if (cumLoaded || !pnlCumChart) return;
    fetch(API + '?_pnl_cumulative', { credentials: 'same-origin' })
        .then(r => r.json()).then(d => {
            if (!d || !d.ok) return;
            cumLoaded = true;
            pnlCumChart.data.labels = (d.points || []).map(r => (r.d || '').slice(5));
            pnlCumChart.data.datasets[0].data = (d.points || []).map(r => Number(r.p));
            pnlCumChart.update();
        }).catch(() => {});
}

function loadTicker() {
    fetch(API + '?_ticker', { credentials: 'same-origin' })
        .then(r => r.json()).then(t => {
            const el = document.getElementById('tickerLine');
            if (!el || !t || !t.ok) return;
            const chg = (t.change24h ?? 0);
            el.innerHTML = '<strong>' + Number(t.price || 0).toFixed(2) + '</strong> USDT' +
                ' <span style="color:' + (chg >= 0 ? 'var(--green)' : 'var(--red)') + '">' + (chg >= 0 ? '+' : '') + chg.toFixed(2) + '%</span>' +
                ' · H24 <strong>' + Number(t.high24h || 0).toFixed(2) + '</strong> / ' + Number(t.low24h || 0).toFixed(2) +
                ' · Vol ' + (t.vol24h ? Number(t.vol24h).toFixed(0) : '0');
        }).catch(() => {});
}

function loadLogs() {
    fetch(API + '?_logs&token=' + encodeURIComponent(CTRL_TOKEN), { credentials: 'same-origin' })
        .then(r => r.json()).then(d => {
            const el = document.getElementById('botLogs');
            if (el && d && d.lines) el.textContent = d.lines.slice(-200).join('\n');
        }).catch(() => {});
}

function cmd(action) {
    const labels = { stop: '¿Detener el bot?', force_ai: '¿Forzar evaluación IA?', reset_grid: '¿Reconstruir grilla?', reset_pair: '¿Resetear par?' };
    if (!confirm(labels[action] || '¿Confirmar?')) return;
    const fd = new FormData();
    fd.append('_control', '1');
    fd.append('action', action);
    fetch(API + '?_control&token=' + encodeURIComponent(CTRL_TOKEN), { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json()).then(d => { alert(d.ok ? (d.msg || 'OK') : (d.msg || 'Error')); if (d.ok) botStatus(); })
        .catch(() => alert('Error de red'));
}

function openAdjust(id, username) {
    document.getElementById('adjUserId').value = id;
    document.getElementById('adjTitle').textContent = 'Ajustar saldo · ' + username;
    document.getElementById('adjustModal').style.display = 'flex';
}

const networkSel = document.getElementById('network');
const tokenSel = document.getElementById('token');
const destInput = document.getElementById('destination');
const amountInput = document.getElementById('amount');
const confirmChk = document.getElementById('confirm');
const sendBtn = document.getElementById('sendBtn');
const gasDiv = document.getElementById('gasEstimate');

function validateForm() {
    const network = networkSel.value;
    const token = tokenSel.value;
    const dest = destInput.value.trim();
    const amount = parseFloat(amountInput.value);
    const destValid = /^0x[0-9a-fA-F]{40}$/.test(dest);
    const amountValid = !isNaN(amount) && amount > 0;
    const allValid = network && token && destValid && amountValid && confirmChk.checked;
    sendBtn.disabled = !allValid;
    return { network, token, dest, amount, destValid, amountValid };
}

async function estimateGas() {
    const { network, token, dest, amount, destValid, amountValid } = validateForm();
    if (!destValid || !amountValid) {
        gasDiv.style.display = 'none';
        return;
    }
    gasDiv.style.display = 'block';
    gasDiv.style.color = '';
    gasDiv.textContent = 'Estimando gas...';
    try {
        const url = 'admin.php?action=estimate_gas&network=' + encodeURIComponent(network) + '&token=' + encodeURIComponent(token) + '&destination=' + encodeURIComponent(dest) + '&amount=' + encodeURIComponent(amountInput.value);
        const resp = await fetch(url, { credentials: 'same-origin' });
        const data = await resp.json();
        if (data.ok) {
            const native = network === 'eth' ? 'ETH' : 'BNB';
            gasDiv.textContent = 'Gas estimado: ' + Number(data.gas_limit).toLocaleString() + ' · Gas price: ' + (data.gas_price / 1e9).toFixed(2) + ' Gwei · Costo estimado: ' + data.estimated_cost_native + ' ' + native;
        } else {
            gasDiv.textContent = (data.error || 'Error');
            gasDiv.style.color = '#f85149';
        }
    } catch (e) {
        gasDiv.textContent = 'Error: ' + (e.message || 'no disponible');
        gasDiv.style.color = '#f85149';
    }
}

['network', 'token', 'destination', 'amount'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', function () {
        validateForm();
        clearTimeout(window.gasTimer);
        window.gasTimer = setTimeout(estimateGas, 800);
    });
});
confirmChk.addEventListener('change', validateForm);
validateForm();

function activatePanelTab(tab) {
    document.querySelectorAll('.panel-tab').forEach(function (t) { t.classList.remove('active'); });
    document.querySelectorAll('.panel-content').forEach(function (p) { p.classList.remove('active'); });
    tab.classList.add('active');
    document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
    history.replaceState(null, '', '#' + tab.dataset.tab);
}
document.querySelectorAll('.panel-tab').forEach(function (tab) {
    tab.addEventListener('click', function () { activatePanelTab(tab); });
});

initCharts();
botStatus();
loadTicker();
loadLogs();
setInterval(botStatus, 5000);
setInterval(loadTicker, 10000);
setInterval(loadLogs, 15000);

var savedTab = location.hash.replace('#', '');
if (savedTab) {
    var savedEl = document.querySelector('.panel-tab[data-tab="' + savedTab + '"]');
    if (savedEl) activatePanelTab(savedEl);
}
</script>
</body>
</html>
```

- [ ] **Step 3: `php -l`**

Run: `php -l src/php/admin.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Smoke — página responde 302 sin sesión admin y 200 con sesión admin**

Run:
```bash
curl -sk -o /dev/null -w "%{http_code}\n" -H "Host: binance.gregorbritez.cat" "https://192.168.100.170/src/php/admin.php"
```
Expected: `302` (redirige a auth).

Smoke con sesión admin (crear una sesión temporal de prueba vía sqlite/MySQL directo si hace falta, o validar en el navegador en la verificación final — se acepta verificación manual en Task 8).

- [ ] **Step 5: Correr la suite** (asegura que nada del admin rompió tests)

Run: `php vendor/bin/phpunit --no-coverage 2>&1 | tail -5`
Expected: verde.

- [ ] **Step 6: Commit**

```bash
git add src/php/admin.php
git commit -m "feat(admin): panel unificado con monitor/control del bot, órdenes+PnL, NAV, auditoría y ajustes"
```

---

### Task 7: Panel inversor mejorado (`panel.php`)

**Files:**
- Modify: `src/php/panel.php`

**Interfaces:**
- Consumes: `InvestorHttp::handle` data keys `growth_pct`, `equity_history`, `email` (Task 5).

- [ ] **Step 1: `php -l` baseline**

Run: `php -l src/php/panel.php`
Expected: `No syntax errors detected`.

- [ ] **Step 2: KPI de crecimiento** — tras el card de Equidad (bloque de las líneas 85-103), añadir un card:

Reemplazar:

```php
    <div class="kpi-row">
        <div class="card">
            <div class="kpi-card-value green"><?= number_format($d['equity'], 2) ?> USDT</div>
            <div class="kpi-card-label">Equidad</div>
        </div>
```

por:

```php
    <div class="kpi-row">
        <div class="card">
            <div class="kpi-card-value green"><?= number_format($d['equity'], 2) ?> USDT</div>
            <div class="kpi-card-label">Equidad</div>
        </div>
        <div class="card">
            <div class="kpi-card-value <?= ($d['growth_pct'] ?? 0) >= 0 ? 'green' : 'red' ?>"><?= ($d['growth_pct'] ?? 0) >= 0 ? '+' : '' ?><?= number_format($d['growth_pct'] ?? 0, 2) ?>%</div>
            <div class="kpi-card-label">Crecimiento</div>
        </div>
```

- [ ] **Step 3: Tabs** — añadir "Crecimiento" y "Perfil" a la fila de tabs:

Reemplazar:

```php
        <div class="panel-tab" data-tab="movimientos">Movimientos</div>
    </div>
```

por:

```php
        <div class="panel-tab" data-tab="movimientos">Movimientos</div>
        <div class="panel-tab" data-tab="crecimiento">Crecimiento</div>
        <div class="panel-tab" data-tab="perfil">Perfil</div>
    </div>
```

- [ ] **Step 4: Sección Crecimiento** — insertar después del cierre del `#tab-movimientos` (antes de `</div>` del app-container, es decir después del bloque que termina en la línea ~223 `</div>` que cierra tab-movimientos):

```html
    <div id="tab-crecimiento" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Crecimiento de tu inversión</span></div>
            <div style="height: 260px;"><canvas id="growthChart"></canvas></div>
        </div>
    </div>

    <div id="tab-perfil" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Datos de perfil</span></div>
            <form method="post">
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <div class="cfg-field">
                    <label for="pfEmail">Email</label>
                    <input class="cfg-input" id="pfEmail" name="email" type="email" value="<?= htmlspecialchars($d['email'] ?? '') ?>" required>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top: var(--space-lg);">Guardar perfil</button>
            </form>
        </div>
        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Cambiar contraseña</span></div>
            <form method="post">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <div class="cfg-field">
                    <label for="pwCurrent">Contraseña actual</label>
                    <input class="cfg-input" id="pwCurrent" name="current_password" type="password" required>
                </div>
                <div class="cfg-field" style="margin-top: var(--space-md);">
                    <label for="pwNew">Nueva contraseña (mín. 8)</label>
                    <input class="cfg-input" id="pwNew" name="new_password" type="password" minlength="8" required>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top: var(--space-lg);">Cambiar contraseña</button>
            </form>
        </div>
    </div>
```

- [ ] **Step 5: Paginación de historiales** — añadir un botón "Ver más" al final de cada tabla de historial. Ejemplo en `#tab-movimientos` (tabla con `id="movTb"`), tras el `<?php endif; ?>` del empty-state:

Reemplazar la apertura de la tabla de movimientos:

```php
            <table class="data-table">
                <tr><th>Fecha</th><th>Tipo</th><th>Monto</th><th class="hide-mobile">Unidades</th><th class="hide-mobile">NAV</th><th class="hide-mobile">Saldo posterior</th></tr>
                <?php foreach ($d['movements'] as $m): ?>
```

por:

```php
            <table class="data-table" id="movTb">
                <tr><th>Fecha</th><th>Tipo</th><th>Monto</th><th class="hide-mobile">Unidades</th><th class="hide-mobile">NAV</th><th class="hide-mobile">Saldo posterior</th></tr>
                <?php foreach ($d['movements'] as $m): ?>
```

y tras el bloque `<?php endif; ?>` que cierra el empty-state de movimientos (el `</table>` + empty-state), insertar:

```html
            <div class="empty-state" id="movMoreBtn" style="cursor:pointer; margin-top: var(--space-md);">▼ Ver más movimientos</div>
```

Repetir el mismo patrón para `#tab-depositos` (tabla → `id="depTb"`, botón `depMoreBtn`) y `#tab-retiros` (tabla → `id="wdTb"`, botón `wdMoreBtn`), añadiendo `id` a cada `<table class="data-table">` y su botón "Ver más" tras el empty-state correspondiente.

- [ ] **Step 6: CSS y JS** — insertar antes de la etiqueta `</head>` un `<style>` y antes del `</body>` el JS de paginación y gráfica. Antes de `</head>`:

```html
<style>
    .row-hidden { display: none; }
</style>
```

Y añadir Chart.js al `<head>` (junto a los CSS):

```html
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
```

En el bloque `<script>` existente de panel.php (donde está `activatePanelTab`), añadir:

```js
const EQUITY_DATA = <?= json_encode($d['equity_history']) ?>;

function renderGrowthChart() {
    if (typeof Chart === 'undefined' || !document.getElementById('growthChart')) return;
    new Chart(document.getElementById('growthChart'), {
        type: 'line',
        data: {
            labels: EQUITY_DATA.map(r => (r.created_at || '').slice(0, 10)),
            datasets: [{ label: 'Equidad', data: EQUITY_DATA.map(r => Number(r.balance_after)), borderColor: '#22c55e', backgroundColor: 'rgba(34,197,94,.12)', fill: true, tension: .2, pointRadius: 0 }]
        },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { x: { grid: { color: '#1e3a5f' } }, y: { grid: { color: '#1e3a5f' } } } }
    });
}

function setupPagination(tableId, btnId, perPage) {
    const tb = document.getElementById(tableId);
    const btn = document.getElementById(btnId);
    if (!tb) return;
    const rows = Array.prototype.slice.call(tb.rows);
    if (rows.length <= perPage) { if (btn) btn.style.display = 'none'; return; }
    rows.forEach(function (r, i) { if (i >= perPage) r.classList.add('row-hidden'); });
    if (btn) btn.addEventListener('click', function () {
        rows.forEach(function (r) { r.classList.remove('row-hidden'); });
        btn.style.display = 'none';
    });
}

renderGrowthChart();
setupPagination('movTb', 'movMoreBtn', 20);
setupPagination('depTb', 'depMoreBtn', 20);
setupPagination('wdTb', 'wdMoreBtn', 20);
```

- [ ] **Step 7: `php -l`**

Run: `php -l src/php/panel.php`
Expected: `No syntax errors detected`.

- [ ] **Step 8: Correr la suite**

Run: `php vendor/bin/phpunit --no-coverage 2>&1 | tail -5`
Expected: verde.

- [ ] **Step 9: Commit**

```bash
git add src/php/panel.php
git commit -m "feat(panel): panel de inversor con crecimiento, perfil y paginación"
```

---

### Task 8: Verificación final y push

**Files:**
- Ninguno nuevo (solo verificación).

- [ ] **Step 1: `php -l` de todos los archivos tocados**

Run:
```bash
php -l src/php/admin.php && php -l src/php/panel.php && php -l src/php/Core/AdminHttp.php && php -l src/php/Core/InvestorHttp.php && php -l src/php/Core/Accounting.php && php -l src/php/Core/Schema.php && php -l src/php/grid_ajax.php
```
Expected: `No syntax errors detected` en todos.

- [ ] **Step 2: Suite completa**

Run: `php vendor/bin/phpunit --no-coverage 2>&1 | tail -5`
Expected: `Tests: 248`, `Assertions: > 993`, `Warnings: 1`, `Deprecations: 1` (baseline + nuevos tests).

- [ ] **Step 3: Smoke web (sin sesión)**

Run:
```bash
for p in "/src/php/admin.php" "/src/php/panel.php" "/src/php/grid_ajax.php?_pnl_cumulative"; do
  curl -sk -o /dev/null -w "%{http_code} $p\n" -H "Host: binance.gregorbritez.cat" "https://192.168.100.170$p"
done
```
Expected: `302` para admin.php y panel.php (redirigen a auth.php); `403` para `_pnl_cumulative` sin sesión admin.

- [ ] **Step 4: Verificación manual en navegador** (browser-use): login como admin → las 6 secciones renderizan; botón control escribe `grid_control.json`; gráficas cargan; auditoría registra el login/acciones; login como inversor → crecimiento, gráfica y perfil.

- [ ] **Step 5: Push**

```bash
git push origin master
```

- [ ] **Step 6: Actualizar `.superpowers/sdd/progress.md`** con el resumen de lo implementado.

---

## Self-Review

**1. Spec coverage:**
- Sección 3.1 Resumen (KPIs fondo + bot) → Task 6 (KPI row) ✓
- Sección 3.2 Bot (monitor + control + logs + ticker) → Task 6 (tab-bot) ✓
- Sección 3.3 Órdenes + PnL (abiertas, fills, charts horario/diario/acumulado) → Task 6 (tab-ordenes) + Task 4 (_pnl_cumulative) ✓
- Sección 3.4 Fondo + NAV histórico → Task 6 (tab-fondo + navChart) + Task 3 (nav_history) ✓
- Sección 3.5 Usuarios + ajuste de saldo → Task 3 (adjust_user) + Task 6 (modal) ✓
- Sección 3.6 Auditoría → Task 1 (admin_audit) + Task 3 (audit) + Task 6 (tab-auditoria) ✓
- Sección 4 Panel inversor (KPIs + crecimiento, paginación, perfil) → Task 5 (growth/email) + Task 7 ✓
- Sección 5 Seguridad (CSRF, roles, validación, session_regenerate_id) → Tasks 3 y 5 ✓
- Sección 6 Testing → Tasks 1-5 (phpunit) + Task 8 (manual) ✓

**2. Placeholder scan:** sin TBD/TODO; todo paso con código completo.

**3. Type consistency:** `Accounting::adjustUnits(PDO,int,float,string,string):bool` idéntico en Tasks 2 y 3; `AdminHttp::handle` y `InvestorHttp::handle` mantienen firmas; data keys `nav_history`/`audit_logs`/`fills` (Task 3) consumidos en Task 6; `growth_pct`/`equity_history`/`email` (Task 5) consumidos en Task 7; `_pnl_cumulative` (Task 4) consumido en Task 6.
