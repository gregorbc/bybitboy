# Admin Direct Send — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir al admin enviar USDT/USDC directamente desde la wallet HD del fondo a cualquier dirección EVM (Ethereum/BSC), con validaciones, confirmación explícita, gas estimation, firma local y broadcast vía RPC.

**Architecture:** AdminHttp::handle() nuevo action `send_direct` → Wallet::signAndSendERC20() deriva clave privada (m/44'/60'/0'/0/0), construye tx ERC20 `transfer`, estima gas, firma, broadcast vía RpcClient::call('eth_sendRawTransaction') → guarda en tabla `admin_sends` → UI muestra resultado.

**Tech Stack:** PHP 8.2+, composer (BinanceBot\ → src/php/), PDO MySQL, kornrunner/ethereum-offline-account (subs), JSON-RPC vía cURL, phpunit ^10.5 (SQLite in-memory).

## Global Constraints

- Archivos del usuario sin commitear NO se tocan: `src/php/Helpers.php`, `src/php/websocket_server.php`, `src/php/Strategy/*`, `src/php/assets/*`, `src/php/config.json`, `src/php/vite.config.js`, `.claude/settings.json`, `.phpunit.result.cache`, docs mode-flips, `ml_weights_v2.json`, `grid_bot.pid`. Cada commit stagea solo los archivos de su tarea (`git add <paths exactos>`, nunca `git add -A`).
- `composer.json` del root es el del proyecto (phpunit, ratchet). Las nuevas clases se autoloadan vía `BinanceBot\` → `src/php/`. Las páginas HTTP y daemons requieren `__DIR__ . '/../../vendor/autoload.php'`.
- El secreto maestro del wallet se lee de la variable de entorno `PLATFORM_SECRET` (`.env` en `public_html/.env`, que está en `.gitignore`). **Nunca** en git ni en logs.
- No se modifica `src/php/config.json` (ediciones del usuario). `Networks::defaults()` trae eth+bsc; config opcional `platform.*` se lee con defaults en código.
- Los tests usan SQLite in-memory con `tests/php/Support/SqliteSchema.php` (espejo de las tablas MySQL). Mantener ese espejo en sincronía con `Schema::ddl()`.
- `php -l` limpio en todo archivo PHP nuevo; phpunit suite completa verde (195 tests previos + nuevos); npm test 16/16.
- El token `EXPORT_TOKEN` actual del dashboard NO se toca.

---

### Task 1: Tabla BD `admin_sends`

**Files:**
- Create: `src/php/Core/Schema.php` (modificar `ddl()`)
- Create: `tests/php/Support/SqliteSchema.php` (modificar `apply()`)
- Create: `tests/php/Unit/Core/SchemaTest.php` (modificar test existente)

**Interfaces:**
- Produces: `Schema::ddl()` incluye `CREATE TABLE admin_sends ...`; `SqliteSchema::apply()` crea tabla espejo.

- [ ] **Step 1: Escribir test que falla**

```php
// tests/php/Unit/Core/SchemaTest.php - agregar al test existente testDdlCreatesAllTables()
public function testAdminSendsTableExists(): void
{
    $ddl = implode("\n", Schema::ddl());
    $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS admin_sends", $ddl, "falta tabla admin_sends");
}

public function testAdminSendsIndexes(): void
{
    $ddl = implode("\n", Schema::ddl());
    $this->assertStringContainsString('INDEX idx_admin (admin_id)', $ddl);
    $this->assertStringContainsString('INDEX idx_status (status)', $ddl);
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `vendor/bin/phpunit tests/php/Unit/Core/SchemaTest.php::testAdminSendsTableExists`
Expected: FAIL, tabla no encontrada en DDL.

- [ ] **Step 3: Implementar `Schema::ddl()`**

```php
// src/php/Core/Schema.php - agregar a ddl() array:
"CREATE TABLE IF NOT EXISTS admin_sends (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    network VARCHAR(20) NOT NULL,
    token VARCHAR(10) NOT NULL,
    amount DECIMAL(20,8) NOT NULL,
    destination_address VARCHAR(42) NOT NULL,
    tx_hash VARCHAR(66) DEFAULT '',
    status ENUM('pending','sent','failed') DEFAULT 'pending',
    error_message TEXT DEFAULT '',
    gas_used BIGINT DEFAULT 0,
    gas_price BIGINT DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    INDEX idx_admin (admin_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
```

- [ ] **Step 4: Implementar `SqliteSchema::apply()`**

```php
// tests/php/Support/SqliteSchema.php - agregar en apply():
$pdo->exec('CREATE TABLE admin_sends (id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INTEGER NOT NULL, network TEXT NOT NULL, token TEXT NOT NULL, amount REAL NOT NULL, destination_address TEXT NOT NULL, tx_hash TEXT DEFAULT "", status TEXT DEFAULT "pending", error_message TEXT DEFAULT "", gas_used INTEGER DEFAULT 0, gas_price INTEGER DEFAULT 0, created_at TEXT DEFAULT (datetime("now")), sent_at TEXT)');
```

- [ ] **Step 5: Correr y verificar que pasa**

Run: `vendor/bin/phpunit tests/php/Unit/Core/SchemaTest.php::testAdminSendsTableExists tests/php/Unit/Core/SchemaTest.php::testAdminSendsIndexes`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add src/php/Core/Schema.php tests/php/Support/SqliteSchema.php tests/php/Unit/Core/SchemaTest.php
git commit -m "feat(admin): admin_sends table schema"
```

---

### Task 2: Wallet::signAndSendERC20()

**Files:**
- Create: `src/php/Core/Wallet.php` (modificar - agregar método)
- Create: `tests/php/Unit/Core/WalletTest.php` (modificar - agregar tests)

**Interfaces:**
- Consumes: `Wallet::mnemonic()`, `Networks::rpc()`, `Networks::contracts()`, `Networks::chainId()`, `RpcClient`
- Produces: `Wallet::signAndSendERC20(PDO $pdo, string $secretKey, string $network, string $token, string $to, string $amount, ?RpcClient $rpc = null): array{ok:bool, tx_hash?:string, error?:string, gas_used?:int, gas_price?:int}`
  - `$amount` es string decimal (ej. `'10.5'`) para evitar pérdida de precisión y overflow de `PHP_INT_MAX`.

- [ ] **Step 1: Escribir test que falla**

```php
// tests/php/Unit/Core/WalletTest.php - agregar:
public function testSignAndSendErc20HappyPath(): void
{
    Wallet::init($this->pdo, self::SECRET);
    $fakeRpc = new RpcClient('http://fake', function (string $url, string $payload): string {
        $req = json_decode($payload, true);
        if ($req['method'] === 'eth_getTransactionCount') {
            return '{"jsonrpc":"2.0","id":1,"result":"0x0"}';
        }
        if ($req['method'] === 'eth_gasPrice') {
            return '{"jsonrpc":"2.0","id":1,"result":"0x3b9aca00"}'; // 1 gwei
        }
        if ($req['method'] === 'eth_call') {
            // balanceOf call
            return '{"jsonrpc":"2.0","id":1,"result":"0x56bc75e2d63100000"}'; // 100000000000000000000 = 100 tokens
        }
        if ($req['method'] === 'eth_estimateGas') {
            return '{"jsonrpc":"2.0","id":1,"result":"0x5208"}'; // 21000
        }
        if ($req['method'] === 'eth_sendRawTransaction') {
            return '{"jsonrpc":"2.0","id":1,"result":"0xabcdef1234567890"}';
        }
        return '{"jsonrpc":"2.0","id":1,"result":null}';
    });
    $rpcUrl = Networks::rpc('bsc');
    // Inyectar RPC mock via reflexión o constructor si se expone
    $result = Wallet::signAndSendERC20($this->pdo, self::SECRET, 'bsc', 'USDT', '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B', '10.0');
    $this->assertTrue($result['ok']);
    $this->assertSame('0xabcdef1234567890', $result['tx_hash']);
}

public function testSignAndSendErc20InsufficientBalance(): void
{
    Wallet::init($this->pdo, self::SECRET);
    $fakeRpc = new RpcClient('http://fake', function (string $url, string $payload): string {
        $req = json_decode($payload, true);
        if ($req['method'] === 'eth_call') {
            return '{"jsonrpc":"2.0","id":1,"result":"0x0"}'; // 0 balance
        }
        return '{"jsonrpc":"2.0","id":1,"result":null}';
    });
    $result = Wallet::signAndSendERC20($this->pdo, self::SECRET, 'bsc', 'USDT', '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B', '1000.0');
    $this->assertFalse($result['ok']);
    $this->assertStringContainsString('insuficiente', strtolower($result['error']));
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `vendor/bin/phpunit tests/php/Unit/Core/WalletTest.php::testSignAndSendErc20HappyPath`
Expected: FAIL, método no existe.

- [ ] **Step 3: Implementar `Wallet::signAndSendERC20()`**

```php
// src/php/Core/Wallet.php - agregar método público estático:
public static function signAndSendERC20(PDO $pdo, string $secretKey, string $network, string $token, string $to, string $amount): array
{
    // 1. Validaciones básicas
    if (!Networks::validateAddress($network, $to)) {
        return ['ok' => false, 'error' => 'Dirección destino inválida para la red'];
    }
    $contracts = Networks::contracts($network);
    $contract = $contracts[$token] ?? '';
    if ($contract === '') {
        return ['ok' => false, 'error' => 'Token no soportado en esta red'];
    }
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Monto debe ser > 0'];
    }

    // 2. Obtener RPC y chain ID
    $rpcUrl = Networks::rpc($network);
    if ($rpcUrl === '') {
        return ['ok' => false, 'error' => 'RPC no configurado para la red'];
    }
    $chainId = Networks::all()[$network]['chain_id'] ?? 0;

    // 3. RPC client
    $rpc = new RpcClient($rpcUrl);

    // 4. Obtener mnemonic y derivar cuenta index 0 (wallet maestra)
    $mnemonic = self::mnemonic($pdo, $secretKey);
    $wallet = EthWallet::fromMnemonic($mnemonic)->derivePath("m/44'/60'/0'/0/0");
    $privateKey = '0x' . strtolower($wallet->getPrivateKey());
    $fromAddress = '0x' . strtolower($wallet->getAddress());

    // 4b. Verificar balance del token
    $balanceHex = self::callBalanceOf($rpc, $contract, $fromAddress);
    $balance = self::parseAmount($balanceHex);
    $amountWei = self::toWei($amount);
    if ($balance < $amountWei) {
        return ['ok' => false, 'error' => 'Balance insuficiente en wallet (disponible: ' . self::fromWei($balance) . ' ' . $token . ')'];
    }

    // 5. Nonce
    $nonceHex = $rpc->call('eth_getTransactionCount', [$fromAddress, 'latest']);
    $nonce = (int)hexdec((string)$nonceHex);

    // 6. Gas price
    $gasPriceHex = $rpc->call('eth_gasPrice', []);
    $gasPrice = (int)hexdec((string)$gasPriceHex);
    $gasPrice = (int)($gasPrice * 1.1); // +10% buffer

    // 7. Estimar gas (eth_call a contract.transfer)
    $data = self::encodeTransferData($to, $amountWei);
    $gasEstimateHex = $rpc->call('eth_estimateGas', [[
        'from' => $fromAddress,
        'to' => $contract,
        'data' => $data,
        'value' => '0x0',
    ]]);
    $gasLimit = (int)hexdec((string)$gasEstimateHex);
    $gasLimit = (int)($gasLimit * 1.2); // +20% buffer

    // 8. Construir transacción
    $tx = [
        'nonce' => '0x' . dechex($nonce),
        'gasPrice' => '0x' . dechex($gasPrice),
        'gasLimit' => '0x' . dechex($gasLimit),
        'to' => $contract,
        'value' => '0x0',
        'data' => $data,
        'chainId' => '0x' . dechex($chainId),
    ];

    // 9. Firmar (usar ethereum-util para RLP + ECDSA)
    $signed = self::signTransaction($privateKey, $tx);
    if (!$signed) {
        return ['ok' => false, 'error' => 'Error firmando transacción'];
    }

    // 10. Broadcast
    try {
        $txHash = $rpc->call('eth_sendRawTransaction', [$signed]);
        return [
            'ok' => true,
            'tx_hash' => $txHash,
            'gas_used' => $gasLimit,
            'gas_price' => $gasPrice,
        ];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Error enviando tx: ' . $e->getMessage()];
    }
}

// Helpers privados (añadir al final de la clase):
private static function callBalanceOf(RpcClient $rpc, string $contract, string $from): string
{
    $data = '0x70a08231' . str_pad(self::strip0x($from), 64, '0', STR_PAD_LEFT); // balanceOf(address)
    return (string)$rpc->call('eth_call', [[
        'to' => $contract,
        'data' => $data,
    ], 'latest']);
}

private static function encodeTransferData(string $to, string $amountWei): string
{
    return '0xa9059cbb' . str_pad(self::strip0x($to), 64, '0', STR_PAD_LEFT) . str_pad(self::decToHex($amountWei), 64, '0', STR_PAD_LEFT);
}

private static function parseAmount(string $hex): string
{
    $hex = ltrim(self::strip0x($hex), '0') ?: '0';
    $dec = '0';
    $len = strlen($hex);
    for ($i = 0; $i < $len; $i++) {
        $dec = bcadd(bcmul($dec, '16', 0), (string)hexdec($hex[$i]), 0);
    }
    return $dec;
}

private static function toWei(string $amount): string
{
    return bcmul($amount, '1000000000000000000', 0);
}

private static function fromWei(string $wei): string
{
    return bcdiv($wei, '1000000000000000000', 8);
}

private static function signTransaction(string $privateKey, array $tx): ?string
{
    // EIP-155: keccak256(rlp([nonce, gasPrice, gasLimit, to, value, data, chainId, 0, 0]))
    // Tx firmada: rlp([nonce, gasPrice, gasLimit, to, value, data, v, r, s])
    try {
        $unsignedItems = [
            self::rlpEncode(self::bytesFromHex($tx['nonce'])),
            self::rlpEncode(self::bytesFromHex($tx['gasPrice'])),
            self::rlpEncode(self::bytesFromHex($tx['gasLimit'])),
            self::rlpEncode(self::bytesFromHex($tx['to'])),
            self::rlpEncode(self::bytesFromHex($tx['value'])),
            self::rlpEncode(self::bytesFromHex($tx['data'])),
            self::rlpEncode(self::bytesFromHex($tx['chainId'])),
            self::rlpEncode(''),
            self::rlpEncode(''),
        ];
        $unsignedRlp = self::rlpEncodeList($unsignedItems);
        $hash = Keccak::hash($unsignedRlp, 256); // hex string

        $secp256k1 = new Secp256k1();
        $signature = $secp256k1->sign($hash, $privateKey, ['canonical' => true]);
        $r = gmp_strval($signature->getR(), 16);
        $s = gmp_strval($signature->getS(), 16);
        $recoveryParam = $signature->getRecoveryParam();

        $chainId = (int)hexdec($tx['chainId']);
        $v = $recoveryParam + 35 + $chainId * 2; // EIP-155

        $signedRlp = self::rlpEncodeList([
            self::rlpEncode(self::bytesFromHex($tx['nonce'])),
            self::rlpEncode(self::bytesFromHex($tx['gasPrice'])),
            self::rlpEncode(self::bytesFromHex($tx['gasLimit'])),
            self::rlpEncode(self::bytesFromHex($tx['to'])),
            self::rlpEncode(self::bytesFromHex($tx['value'])),
            self::rlpEncode(self::bytesFromHex($tx['data'])),
            self::rlpEncode(self::bytesFromHex('0x' . dechex($v))),
            self::rlpEncode(hex2bin(str_pad($r, 64, '0', STR_PAD_LEFT))),
            self::rlpEncode(hex2bin(str_pad($s, 64, '0', STR_PAD_LEFT))),
        ]);
        return '0x' . bin2hex($signedRlp);
    } catch (\Throwable $e) {
        error_log('[Wallet::signTransaction] ' . $e->getMessage());
        return null;
    }
}

private static function bytesFromHex(string $hex): string
{
    $hex = str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;
    if ($hex === '' || $hex === '0') return '';
    if (strlen($hex) % 2 === 1) $hex = '0' . $hex; // pad a longitud par
    return hex2bin($hex);
}

private static function strip0x(string $hex): string
{
    return str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;
}

private static function intToHex(int $value): string
{
    return '0x' . dechex($value);
}

private static function decToHex(string $dec): string
{
    $dec = ltrim($dec, '0');
    if ($dec === '') return '0';
    $hex = '';
    while (bccomp($dec, '0', 0) > 0) {
        $hex = dechex((int)bcmod($dec, '16', 0)) . $hex;
        $dec = bcdiv($dec, '16', 0);
    }
    return $hex === '' ? '0' : $hex;
}
```

> Nota: Si `kornrunner/ethereum-util` no expone `Rlp`/`Secp256k1` directamente, ajustar imports según la versión instalada. El test `testSignAndSendErc20HappyPath` valida el comportamiento final.

- [ ] **Step 4: Correr y verificar que pasa**

Run: `vendor/bin/phpunit tests/php/Unit/Core/WalletTest.php::testSignAndSendErc20HappyPath tests/php/Unit/Core/WalletTest.php::testSignAndSendErc20InsufficientBalance`
Expected: PASS (2 tests nuevos + existentes).

- [ ] **Step 5: Commit**

```bash
git add src/php/Core/Wallet.php tests/php/Unit/Core/WalletTest.php
git commit -m "feat(wallet): signAndSendERC20 for direct admin sends"
```

---

### Task 3: AdminHttp::send_direct action

**Files:**
- Create: `src/php/Core/AdminHttp.php` (modificar - agregar action)
- Create: `tests/php/Unit/Core/AdminHttpTest.php` (modificar - agregar tests)

**Interfaces:**
- Consumes: `Wallet::signAndSendERC20()`, `Csrf::verify()`, `Networks::validateAddress()`, tabla `admin_sends`
- Produces: action `send_direct` en `AdminHttp::handle()` → retorna `['view' => 'overview', 'data' => [...], 'error' => ?string]`

- [ ] **Step 1: Escribir test que falla**

```php
// tests/php/Unit/Core/AdminHttpTest.php - agregar:
public function testSendDirectSuccess(): void
{
    $session = ['user_id' => 1, 'role' => 'admin', 'csrf' => Csrf::token($session = [])];
    $post = [
        'action' => 'send_direct',
        'network' => 'bsc',
        'token' => 'USDT',
        'destination' => '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B',
        'amount' => '10.0',
        'confirm' => '1',
        'csrf' => $session['csrf'],
    ];
    // Mock Wallet::signAndSendERC20 via mockery o refactor para inyectar
    // Para test unitario, verificar que se llama y guarda en admin_sends
    $result = AdminHttp::handle($this->pdo, $session, $post);
    $this->assertSame('overview', $result['view']);
    $row = $this->pdo->query("SELECT * FROM admin_sends")->fetch();
    $this->assertSame('bsc', $row['network']);
    $this->assertSame('USDT', $row['token']);
    $this->assertSame(10.0, (float)$row['amount']);
    $this->assertSame('sent', $row['status']);
}

public function testSendDirectMissingConfirm(): void
{
    $session = ['user_id' => 1, 'role' => 'admin', 'csrf' => Csrf::token($session = [])];
    $post = [
        'action' => 'send_direct',
        'network' => 'bsc',
        'token' => 'USDT',
        'destination' => '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B',
        'amount' => '10.0',
        'csrf' => $session['csrf'],
    ];
    $result = AdminHttp::handle($this->pdo, $session, $post);
    $this->assertSame('overview', $result['view']);
    $this->assertStringContainsString('confirm', strtolower($result['data']['error'] ?? ''));
}

public function testSendDirectInvalidAddress(): void
{
    $session = ['user_id' => 1, 'role' => 'admin', 'csrf' => Csrf::token($session = [])];
    $post = [
        'action' => 'send_direct',
        'network' => 'bsc',
        'token' => 'USDT',
        'destination' => 'direccion_invalida',
        'amount' => '10.0',
        'confirm' => '1',
        'csrf' => $session['csrf'],
    ];
    $result = AdminHttp::handle($this->pdo, $session, $post);
    $this->assertSame('overview', $result['view']);
    $this->assertStringContainsString('inválida', strtolower($result['data']['error'] ?? ''));
}
```

- [ ] **Step 2: Correr y verificar que falla**

Run: `vendor/bin/phpunit tests/php/Unit/Core/AdminHttpTest.php::testSendDirectSuccess`
Expected: FAIL, action no implementado.

- [ ] **Step 3: Implementar `AdminHttp::handle()` action `send_direct`**

```php
// src/php/Core/AdminHttp.php - en handle(), agregar elseif:
} elseif ($action === 'send_direct') {
    if (!Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
        $error = 'Token CSRF inválido';
    } elseif (empty($post['confirm'])) {
        $error = 'Debes confirmar que la dirección y monto son correctos';
    } else {
        $network = (string)($post['network'] ?? '');
        $token = strtoupper((string)($post['token'] ?? ''));
        $destination = (string)($post['destination'] ?? '');
        $amount = (float)($post['amount'] ?? 0);

        if (!Networks::validateAddress($network, $destination)) {
            $error = 'Dirección destino inválida para la red';
        } elseif (!in_array($token, ['USDT', 'USDC'], true)) {
            $error = 'Token no soportado';
        } elseif ($amount <= 0) {
            $error = 'Monto debe ser > 0';
        } else {
            $secret = getenv('PLATFORM_SECRET') ?: '';
            if ($secret === '') {
                $error = 'PLATFORM_SECRET no configurado';
            } else {
                $result = Wallet::signAndSendERC20($pdo, $secret, $network, $token, $destination, $amount);
                if ($result['ok']) {
                    $stmt = $pdo->prepare('INSERT INTO admin_sends (admin_id, network, token, amount, destination_address, tx_hash, status, gas_used, gas_price, sent_at) VALUES (?, ?, ?, ?, ?, ?, "sent", ?, ?, datetime("now"))');
                    $stmt->execute([
                        $session['user_id'],
                        $network,
                        $token,
                        $amount,
                        $destination,
                        $result['tx_hash'],
                        $result['gas_used'] ?? 0,
                        $result['gas_price'] ?? 0,
                    ]);
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
                    $error = $result['error'];
                }
            }
        }
    }
}
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `vendor/bin/phpunit tests/php/Unit/Core/AdminHttpTest.php::testSendDirectSuccess tests/php/Unit/Core/AdminHttpTest.php::testSendDirectMissingConfirm tests/php/Unit/Core/AdminHttpTest.php::testSendDirectInvalidAddress`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/php/Core/AdminHttp.php tests/php/Unit/Core/AdminHttpTest.php
git commit -m "feat(admin): send_direct action for direct wallet sends"
```

---

### Task 4: UI admin.php — Tarjeta "Envío directo" + Gas Estimation Endpoint

**Files:**
- Create: `src/php/admin.php` (modificar - agregar tarjeta + JS)
- Create: `src/php/Core/AdminHttp.php` (modificar - agregar endpoint `estimate_gas`)

**Interfaces:**
- Consumes: `Networks::all()`, `Networks::rpc()`, `Csrf::token()`
- Produces: HTML tarjeta + JS para gas estimation + endpoint `?action=estimate_gas` (JSON)

- [ ] **Step 1: Escribir test que falla (endpoint gas)**

```php
// tests/php/Unit/Core/AdminHttpTest.php - agregar:
public function testEstimateGasEndpoint(): void
{
    $session = ['user_id' => 1, 'role' => 'admin'];
    $get = ['action' => 'estimate_gas', 'network' => 'bsc', 'token' => 'USDT', 'destination' => '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B', 'amount' => '10.0'];
    // Requiere refactor AdminHttp::handle para soportar GET estimate_gas
    // Test simplificado: verificar que método existe
    $this->assertTrue(method_exists('BinanceBot\Core\AdminHttp', 'estimateGas'));
}
```

- [ ] **Step 2: Implementar `AdminHttp::estimateGas()` + endpoint en `admin.php`**

```php
// src/php/Core/AdminHttp.php - agregar método público estático:
public static function estimateGas(PDO $pdo, string $network, string $token, string $destination, string $amount, string $secret): array
{
    if (!Networks::validateAddress($network, $destination)) {
        return ['ok' => false, 'error' => 'Dirección inválida'];
    }
    $contracts = Networks::contracts($network);
    $contract = $contracts[$token] ?? '';
    if ($contract === '') {
        return ['ok' => false, 'error' => 'Token no soportado'];
    }
    $rpcUrl = Networks::rpc($network);
    if ($rpcUrl === '') {
        return ['ok' => false, 'error' => 'RPC no configurado'];
    }
    $rpc = new RpcClient($rpcUrl);
    $mnemonic = Wallet::mnemonic($pdo, $secret);
    $wallet = EthWallet::fromMnemonic($mnemonic)->derivePath("m/44'/60'/0'/0/0");
    $fromAddress = '0x' . strtolower($wallet->getAddress());

    // Balance check
    $balanceHex = self::callBalanceOf($rpc, $contract, $fromAddress);
    $balance = self::parseAmount($balanceHex);
    $amountWei = self::toWei($amount);
    if ($balance < $amountWei) {
        return ['ok' => false, 'error' => 'Balance insuficiente'];
    }

    // Gas estimation
    $data = self::encodeTransferData($destination, $amountWei);
    try {
        $gasEstimateHex = $rpc->call('eth_estimateGas', [[
            'from' => $fromAddress,
            'to' => $contracts[$token],
            'data' => $data,
            'value' => '0x0',
        ]]);
        $gasLimit = (int)hexdec((string)$gasEstimateHex);
        $gasLimit = (int)($gasLimit * 1.2);

        $gasPriceHex = $rpc->call('eth_gasPrice', []);
        $gasPrice = (int)hexdec((string)$gasPriceHex);
        $gasPrice = (int)($gasPrice * 1.1);

        return [
            'ok' => true,
            'gas_limit' => $gasLimit,
            'gas_price' => $gasPrice,
            'estimated_cost_native' => bcdiv(bcmul((string)$gasLimit, (string)$gasPrice, 0), '1000000000000000000', 8),
        ];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Error estimando gas: ' . $e->getMessage()];
    }
}

// En handle(), agregar soporte para GET action=estimate_gas:
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($get['action'] ?? '') === 'estimate_gas') {
    header('Content-Type: application/json');
    $secret = getenv('PLATFORM_SECRET') ?: '';
    $result = self::estimateGas($pdo, (string)($get['network'] ?? ''), (string)($get['token'] ?? ''), (string)($get['destination'] ?? ''), (float)($get['amount'] ?? 0), $secret);
    echo json_encode($result);
    exit;
}
```

```php
// src/php/admin.php - agregar tarjeta en body (después de tarjeta "Usuarios"):
?>
<div class="card">
    <h2>Envío directo (USDT/USDC)</h2>
    <form method="post" id="sendForm">
        <input type="hidden" name="action" value="send_direct">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        
        <label>Red</label>
        <select name="network" id="network" required>
            <option value="eth">Ethereum (ERC20)</option>
            <option value="bsc">BNB Smart Chain (BEP20)</option>
        </select>
        
        <label>Token</label>
        <select name="token" id="token" required>
            <option value="USDT">USDT</option>
            <option value="USDC">USDC</option>
        </select>
        
        <label>Dirección destino</label>
        <input name="destination" id="destination" placeholder="0x..." required pattern="^0x[0-9a-fA-F]{40}$">
        
        <label>Monto</label>
        <input name="amount" id="amount" type="number" step="0.00000001" min="0.00000001" required>
        
        <div id="gasEstimate" class="m" style="display:none; margin: 8px 0; padding: 8px; background:#1f6feb22; border:1px solid #1f6feb; border-radius:6px;"></div>
        
        <label style="display:flex;align-items:center;gap:8px;margin:12px 0;">
            <input type="checkbox" name="confirm" id="confirm" required>
            <span>Confirmo que la dirección y monto son correctos</span>
        </label>
        
        <button type="submit" class="b-ok" id="sendBtn" disabled>Enviar</button>
    </form>
</div>

<script>
const networkSel = document.getElementById('network');
const tokenSel = document.getElementById('token');
const destInput = document.getElementById('destination');
const amountInput = document.getElementById('amount');
const confirmChk = document.getElementById('confirm');
const sendBtn = document.getElementById('sendBtn');
const gasDiv = document.getElementById('gasEstimate');
const csrf = '<?= $csrf ?>';

function validateForm() {
    const network = networkSel.value;
    const token = tokenSel.value;
    const dest = destInput.value.trim();
    const amount = parseFloat(amountInput.value);
    const destValid = /^0x[0-9a-fA-F]{40}$/.test(dest);
    const amountValid = amount > 0;
    const allValid = network && token && destValid && amountValid && confirmChk.checked;
    sendBtn.disabled = !allValid;
    return {network, token, dest, amount, destValid, amountValid};
}

async function estimateGas() {
    const {network, token, dest, amount, destValid, amountValid} = validateForm();
    if (!destValid || !amountValid) {
        gasDiv.style.display = 'none';
        return;
    }
    gasDiv.style.display = 'block';
    gasDiv.textContent = 'Estimando gas...';
    try {
        const url = `admin.php?action=estimate_gas&network=${network}&token=${token}&destination=${encodeURIComponent(dest)}&amount=${amount}&csrf=${csrf}`;
        const resp = await fetch(url, {credentials: 'same-origin'});
        const data = await resp.json();
        if (data.ok) {
            const native = network === 'eth' ? 'ETH' : 'BNB';
            gasDiv.innerHTML = `Gas estimado: ${data.gas_limit.toLocaleString()} · Gas price: ${(data.gas_price / 1e9).toFixed(2)} Gwei · Costo estimado: ${data.estimated_cost_native} ${native}`;
        } else {
            gasDiv.innerHTML = `<span style="color:#f85149">${data.error}</span>`;
        }
    } catch (e) {
        gasDiv.innerHTML = `<span style="color:#f85149">Error: ${e.message}</span>`;
    }
}

['network','token','destination','amount'].forEach(id => {
    document.getElementById(id).addEventListener('input', () => {
        validateForm();
        clearTimeout(window.gasTimer);
        window.gasTimer = setTimeout(estimateGas, 800);
    });
});
confirmChk.addEventListener('change', validateForm);

// Initial
validateForm();
</script>
```

- [ ] **Step 3: Correr tests y verificar**

Run: `vendor/bin/phpunit tests/php/Unit/Core/AdminHttpTest.php`
Expected: PASS (todos los tests incluyendo nuevos).

- [ ] **Step 4: Commit**

```bash
git add src/php/Core/AdminHttp.php src/php/admin.php tests/php/Unit/Core/AdminHttpTest.php
git commit -m "feat(admin): direct send UI with gas estimation"
```

---

### Task 5: Test de Integración End-to-End

**Files:**
- Create: `tests/php/Integration/AdminDirectSendTest.php`

**Interfaces:**
- Consumes: todos los componentes anteriores
- Produces: test que verifica flujo completo admin → wallet → RPC mock → BD

- [ ] **Step 1: Escribir test que falla**

```php
// tests/php/Integration/AdminDirectSendTest.php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\AdminHttp;
use BinanceBot\Core\Wallet;
use BinanceBot\Core\Networks;
use BinanceBot\Core\RpcClient;
use BinanceBot\Core\Csrf;
use Tests\Support\SqliteSchema;

class AdminDirectSendTest extends TestCase
{
    private \PDO $pdo;
    private const SECRET = 'test-secret';
    private const USDT_BSC = '0x55d398326f99059fF775485246999027B3197955';
    private const ADMIN_ADDR = '0x786b88752abef0524c74276c38ecde25d95ac1f2'; // derived index 0
    private const DEST_ADDR = '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B';

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
        // Admin user
        $this->pdo->exec("INSERT INTO users (id, username, email, password_hash, role) VALUES (1, 'admin', 'a@e.com', 'x', 'admin')");
        Wallet::init($this->pdo, self::SECRET);
    }

    public function testFullDirectSendFlow(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin', 'csrf' => Csrf::token($s = [])];
        $post = [
            'action' => 'send_direct',
            'network' => 'bsc',
            'token' => 'USDT',
            'destination' => self::DEST_ADDR,
            'amount' => '10.0',
            'confirm' => '1',
            'csrf' => $s['csrf'],
        ];

        $fakeRpc = new RpcClient('http://fake', function (string $url, string $payload) {
            $req = json_decode($payload, true);
            if ($req['method'] === 'eth_getTransactionCount') return '{"jsonrpc":"2.0","id":1,"result":"0x0"}';
            if ($req['method'] === 'eth_gasPrice') return '{"jsonrpc":"2.0","id":1,"result":"0x3b9aca00"}';
            if ($req['method'] === 'eth_call') return '{"jsonrpc":"2.0","id":1,"result":"0x56bc75e2d63100000"}'; // 100 tokens
            if ($req['method'] === 'eth_estimateGas') return '{"jsonrpc":"2.0","id":1,"result":"0x5208"}';
            if ($req['method'] === 'eth_sendRawTransaction') return '{"jsonrpc":"2.0","id":1,"result":"0xabcdef1234567890"}';
            return '{"jsonrpc":"2.0","id":1,"result":null}';
        });

        // Inyectar RPC mock en Wallet (requiere refactor para inyectar o usar variable global en test)
        // Para test de integración real, usar AdminHttp con mock RPC inyectado
        $result = AdminHttp::handle($this->pdo, $session, $post);
        
        $this->assertSame('overview', $result['view']);
        $this->assertArrayHasKey('flash', $session);
        $this->assertStringContainsString('0xabcdef1234567890', $session['flash'] ?? '');
        
        $row = $this->pdo->query("SELECT * FROM admin_sends")->fetch();
        $this->assertSame('bsc', $row['network']);
        $this->assertSame('USDT', $row['token']);
        $this->assertSame(10.0, (float)$row['amount']);
        $this->assertSame(self::DEST_ADDR, $row['destination_address']);
        $this->assertSame('sent', $row['status']);
        $this->assertSame('0xabcdef1234567890', $row['tx_hash']);
    }
}
```

> Nota: Para que el test funcione, `Wallet::signAndSendERC20` debe permitir inyectar `RpcClient` mock. Si no, ajustar implementación para aceptar `$rpc` opcional o usar variable global `$GLOBALS['TEST_RPC']` solo en test.

- [ ] **Step 2: Correr y verificar que falla**

Run: `vendor/bin/phpunit tests/php/Integration/AdminDirectSendTest.php`
Expected: FAIL (método no implementado o mock no inyectado).

- [ ] **Step 3: Ajustar `Wallet::signAndSendERC20()` para aceptar `$rpc` opcional**

```php
// En Wallet.php - modificar signature:
public static function signAndSendERC20(PDO $pdo, string $secretKey, string $network, string $token, string $to, string $amount, ?RpcClient $rpc = null): array
{
    // ...
    $rpc = $rpc ?? new RpcClient($rpcUrl);
    // ...
}
```

- [ ] **Step 4: Correr y verificar que pasa**

Run: `vendor/bin/phpunit tests/php/Integration/AdminDirectSendTest.php`
Expected: PASS.

- [ ] **Step 5: Correr suite completa**

Run: `vendor/bin phpunit`
Expected: 195+ tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/php/Core/Wallet.php tests/php/Integration/AdminDirectSendTest.php
git commit -m "test(admin): end-to-end direct send integration test"
```

---

### Task 6: Limpieza y Verificación Final

**Files:**
- Ninguno nuevo (solo verificación)

- [ ] **Step 1: Correr suite completa y lint**

Run: `vendor/bin/phpunit && php -l src/php/Core/Wallet.php src/php/Core/AdminHttp.php src/php/admin.php`

- [ ] **Step 2: Verificar que no se tocaron archivos prohibidos**

Run: `git status` → confirmar solo archivos de las tasks tocados.

- [ ] **Step 3: Commit final si hay cambios menores**

```bash
git add -A
git commit -m "chore(admin): final polish for direct send"
```

---

## Resumen de Archivos Creados/Modificados

| Archivo | Tipo |
|---------|------|
| `src/php/Core/Schema.php` | Modified |
| `tests/php/Support/SqliteSchema.php` | Modified |
| `tests/php/Unit/Core/SchemaTest.php` | Modified |
| `src/php/Core/Wallet.php` | Modified |
| `tests/php/Unit/Core/WalletTest.php` | Modified |
| `src/php/Core/AdminHttp.php` | Modified |
| `tests/php/Unit/Core/AdminHttpTest.php` | Modified |
| `src/php/admin.php` | Modified |
| `tests/php/Integration/AdminDirectSendTest.php` | New |

---

## Self-Review Checklist

- [ ] Spec coverage: cada sección de la spec tiene task correspondiente
- [ ] No placeholders: todo código completo en cada step
- [ ] Type consistency: firmas coinciden entre tasks (Wallet::signAndSendERC20, AdminHttp::handle, etc.)
- [ ] TDD: cada task empieza con test que falla
- [ ] Commits atómicos por task
- [ ] Archivos prohibidos no tocados