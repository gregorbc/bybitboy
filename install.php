<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = __DIR__;
$privateDir = $root . DIRECTORY_SEPARATOR . 'private';
$configFile = $root . DIRECTORY_SEPARATOR . 'config.json';
$privateConfigFile = $privateDir . DIRECTORY_SEPARATOR . 'config.json';
$sqlFile = $root . DIRECTORY_SEPARATOR . 'setup_mysql.sql';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function post($key, $default = '') { return $_POST[$key] ?? $default; }
function randomToken(): string { return bin2hex(random_bytes(24)); }
function slashPath(string $path): string { return str_replace('\\', '/', $path); }
function mysqlIdent(string $name): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new InvalidArgumentException('El nombre de la base MySQL solo puede usar letras, numeros y guion bajo.');
    }
    return '`' . $name . '`';
}

function defaultConfig(string $root, string $privateDir): array {
    return [
        'exchange' => 'bybit',
        'bybit' => [
            'api_key' => '',
            'api_secret' => '',
            'testnet' => true,
        ],
        'mt5' => [
            'login' => 0,
            'password' => '',
            'server' => 'XMGlobal-MT5 3',
        ],
        'bot' => [
            'symbol' => 'ETHUSDT',
            'capital_usd' => 30,
            'leverage' => 100,
            'timeframe' => '5',
            'candles_feed' => 150,
            'cycle_sec' => 8,
            'ai_interval_sec' => 120,
            'levels' => 16,
            'long_levels' => 8,
            'short_levels' => 8,
        ],
        'ml' => [
            'weights_file' => slashPath($root . DIRECTORY_SEPARATOR . 'ml_weights_v2.json'),
            'min_confidence' => 45,
        ],
        'nvidia' => [
            'api_key' => '',
            'enabled' => false,
            'interval_sec' => 480,
        ],
        'mysql' => [
            'host' => 'localhost',
            'dbname' => 'erika_bot',
            'user' => 'root',
            'password' => '',
        ],
        'paths' => [
            'log' => slashPath($root . DIRECTORY_SEPARATOR . 'bot.log'),
            'web_dir' => slashPath($root),
            'config_dir' => slashPath($privateDir),
            'status' => slashPath($privateDir . DIRECTORY_SEPARATOR . 'grid_status.json'),
            'pid' => slashPath($root . DIRECTORY_SEPARATOR . 'grid_bot.pid'),
            'conf_hist' => slashPath($privateDir . DIRECTORY_SEPARATOR . 'grid_confidence.json'),
            'ctrl' => slashPath($privateDir . DIRECTORY_SEPARATOR . 'grid_control.json'),
        ],
        'security_token' => randomToken(),
        'ws_token' => randomToken(),
    ];
}

function loadConfigOrDefault(string $configFile, string $root, string $privateDir): array {
    if (is_file($configFile)) {
        $data = json_decode((string)file_get_contents($configFile), true);
        if (is_array($data)) return array_replace_recursive(defaultConfig($root, $privateDir), $data);
    }
    return defaultConfig($root, $privateDir);
}

function createTables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS grid_configs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        symbol VARCHAR(20) NOT NULL,
        direction VARCHAR(20) DEFAULT 'NEUTRAL',
        confidence INT DEFAULT 50,
        ai_reason VARCHAR(400) DEFAULT '',
        last_ai_check DATETIME DEFAULT NULL,
        capital_usd DECIMAL(12,4) DEFAULT NULL,
        leverage INT DEFAULT 100,
        levels INT DEFAULT 10,
        spacing_pct DECIMAL(10,6) DEFAULT 0.000800,
        long_levels INT DEFAULT 5,
        short_levels INT DEFAULT 5,
        qty_per_level DECIMAL(20,8) DEFAULT 0,
        pp INT DEFAULT 2,
        qp INT DEFAULT 3,
        mode VARCHAR(20) DEFAULT 'NORMAL',
        recovery_active TINYINT(1) DEFAULT 0,
        peak_pnl_today DECIMAL(14,6) DEFAULT 0,
        status VARCHAR(10) DEFAULT 'ACTIVE',
        paused_reason VARCHAR(100) DEFAULT NULL,
        ml_accuracy DECIMAL(6,4) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_sym (symbol)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS grid_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        config_id INT DEFAULT NULL,
        symbol VARCHAR(20) DEFAULT NULL,
        direction VARCHAR(20) DEFAULT NULL,
        grid_level INT DEFAULT NULL,
        side VARCHAR(5) DEFAULT NULL,
        grid_role VARCHAR(5) DEFAULT NULL,
        order_id VARCHAR(80) DEFAULT NULL,
        price DECIMAL(20,8) DEFAULT NULL,
        exit_price DECIMAL(20,8) DEFAULT NULL,
        qty DECIMAL(20,8) DEFAULT NULL,
        status VARCHAR(12) DEFAULT 'OPEN',
        linked_order INT DEFAULT NULL,
        pnl_usd DECIMAL(14,8) DEFAULT NULL,
        is_recovery TINYINT(1) DEFAULT 0,
        filled_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sym (symbol),
        INDEX idx_status (status),
        INDEX idx_oid (order_id),
        INDEX idx_cfg (config_id),
        INDEX idx_linked (linked_order),
        INDEX idx_filled (filled_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$cfg = loadConfigOrDefault($configFile, $root, $privateDir);
$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cfg['exchange'] = 'bybit';
    $cfg['bybit']['api_key'] = trim((string)post('bybit_api_key'));
    $cfg['bybit']['api_secret'] = trim((string)post('bybit_api_secret'));
    $cfg['bybit']['testnet'] = post('bybit_testnet') === '1';
    $cfg['mt5']['login'] = (int)post('mt5_login', 0);
    $cfg['mt5']['password'] = (string)post('mt5_password');
    $cfg['mt5']['server'] = trim((string)post('mt5_server', 'XMGlobal-MT5 3'));
    $cfg['bot']['symbol'] = strtoupper(trim((string)post('symbol', 'ETHUSDT')));
    $cfg['bot']['capital_usd'] = (float)post('capital_usd', 30);
    $cfg['bot']['leverage'] = (int)post('leverage', 100);
    $cfg['bot']['levels'] = (int)post('levels', 16);
    $cfg['bot']['long_levels'] = (int)post('long_levels', 8);
    $cfg['bot']['short_levels'] = (int)post('short_levels', 8);
    $cfg['mysql']['host'] = trim((string)post('mysql_host', 'localhost'));
    $cfg['mysql']['dbname'] = trim((string)post('mysql_dbname', 'erika_bot'));
    $cfg['mysql']['user'] = trim((string)post('mysql_user', 'root'));
    $cfg['mysql']['password'] = (string)post('mysql_password');
    $cfg['nvidia']['api_key'] = trim((string)post('nvidia_api_key'));
    $cfg['nvidia']['enabled'] = post('nvidia_enabled') === '1';
    $cfg['security_token'] = trim((string)post('security_token')) ?: randomToken();
    $cfg['ws_token'] = trim((string)post('ws_token')) ?: randomToken();

    if ($cfg['bot']['symbol'] === '') $errors[] = 'Falta el simbolo.';
    if ($cfg['mysql']['host'] === '' || $cfg['mysql']['dbname'] === '' || $cfg['mysql']['user'] === '') {
        $errors[] = 'Completa host, base y usuario MySQL.';
    }
    if ($cfg['mysql']['dbname'] !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $cfg['mysql']['dbname'])) {
        $errors[] = 'El nombre de la base MySQL solo puede usar letras, numeros y guion bajo.';
    }

    if (!$errors) {
        try {
            if (!is_dir($privateDir) && !mkdir($privateDir, 0755, true)) {
                throw new RuntimeException('No se pudo crear la carpeta private.');
            }
            $server = new PDO(
                "mysql:host={$cfg['mysql']['host']};charset=utf8mb4",
                $cfg['mysql']['user'],
                $cfg['mysql']['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $server->exec("CREATE DATABASE IF NOT EXISTS " . mysqlIdent($cfg['mysql']['dbname']) . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo = new PDO(
                "mysql:host={$cfg['mysql']['host']};dbname={$cfg['mysql']['dbname']};charset=utf8mb4",
                $cfg['mysql']['user'],
                $cfg['mysql']['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            createTables($pdo);
            $stmt = $pdo->prepare("INSERT INTO grid_configs (symbol,direction,confidence,ai_reason,capital_usd,leverage,levels,long_levels,short_levels,status)
                VALUES (?, 'SIDEWAYS', 50, 'Configuracion inicial', ?, ?, ?, ?, ?, 'ACTIVE')
                ON DUPLICATE KEY UPDATE capital_usd=VALUES(capital_usd), leverage=VALUES(leverage), levels=VALUES(levels), long_levels=VALUES(long_levels), short_levels=VALUES(short_levels)");
            $stmt->execute([
                $cfg['bot']['symbol'],
                $cfg['bot']['capital_usd'],
                $cfg['bot']['leverage'],
                $cfg['bot']['levels'],
                $cfg['bot']['long_levels'],
                $cfg['bot']['short_levels'],
            ]);

            $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) throw new RuntimeException('No se pudo generar config.json.');
            file_put_contents($configFile, $json . PHP_EOL);
            file_put_contents($privateConfigFile, $json . PHP_EOL);
            @file_put_contents($cfg['paths']['ctrl'], json_encode(['action' => 'none', 'ts' => date('Y-m-d H:i:s')], JSON_PRETTY_PRINT));
            @file_put_contents($cfg['paths']['status'], json_encode(['ok' => true, 'installed_at' => date('c')], JSON_PRETTY_PRINT));
            @file_put_contents($cfg['paths']['conf_hist'], json_encode([], JSON_PRETTY_PRINT));
            $messages[] = 'Instalacion completada. Ya puedes abrir index.php o iniciar el bot.';
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$installed = is_file($configFile);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalador Grid Bot</title>
<style>
body{margin:0;background:#0b1020;color:#e8edf7;font-family:Arial,Helvetica,sans-serif}
main{max-width:980px;margin:0 auto;padding:30px 18px 60px}
h1{font-size:28px;margin:0 0 6px}
p{color:#aeb9cb}
.panel{background:#121a2d;border:1px solid #22304d;border-radius:8px;padding:18px;margin-top:16px}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
label{display:block;font-size:13px;color:#c6d1e3;margin-bottom:6px}
input,select{width:100%;box-sizing:border-box;background:#0a0f1d;color:#fff;border:1px solid #314260;border-radius:6px;padding:10px}
.full{grid-column:1/-1}
button{background:#2d8cff;color:#fff;border:0;border-radius:6px;padding:12px 18px;font-weight:700;cursor:pointer}
.ok{background:#0f3f2c;border-color:#1b7a52;color:#bff5d6}
.err{background:#4a1821;border-color:#973246;color:#ffd3da}
.hint{font-size:12px;color:#8fa0ba}
@media(max-width:760px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<main>
  <h1>Instalador Grid Bot</h1>
  <p>Configura MySQL, credenciales, tokens y archivos base del proyecto.</p>

  <?php foreach ($messages as $m): ?><div class="panel ok"><?=h($m)?></div><?php endforeach; ?>
  <?php foreach ($errors as $e): ?><div class="panel err"><?=h($e)?></div><?php endforeach; ?>

  <form method="post" class="panel">
    <div class="grid">
      <div>
        <label>Bybit API Key</label>
        <input name="bybit_api_key" value="<?=h($cfg['bybit']['api_key'])?>">
      </div>
      <div>
        <label>Bybit API Secret</label>
        <input name="bybit_api_secret" type="password" value="<?=h($cfg['bybit']['api_secret'])?>">
      </div>
      <div>
        <label>Modo Bybit</label>
        <select name="bybit_testnet">
          <option value="1" <?=$cfg['bybit']['testnet'] ? 'selected' : ''?>>Testnet</option>
          <option value="0" <?=!$cfg['bybit']['testnet'] ? 'selected' : ''?>>Real</option>
        </select>
      </div>
      <div>
        <label>Simbolo</label>
        <input name="symbol" value="<?=h($cfg['bot']['symbol'])?>">
      </div>
      <div>
        <label>Capital USD</label>
        <input name="capital_usd" type="number" step="0.01" value="<?=h($cfg['bot']['capital_usd'])?>">
      </div>
      <div>
        <label>Leverage</label>
        <input name="leverage" type="number" value="<?=h($cfg['bot']['leverage'])?>">
      </div>
      <div>
        <label>Niveles totales</label>
        <input name="levels" type="number" value="<?=h($cfg['bot']['levels'])?>">
      </div>
      <div>
        <label>Niveles long / short</label>
        <input name="long_levels" type="number" style="width:49%" value="<?=h($cfg['bot']['long_levels'])?>">
        <input name="short_levels" type="number" style="width:49%" value="<?=h($cfg['bot']['short_levels'])?>">
      </div>
      <div>
        <label>MySQL host</label>
        <input name="mysql_host" value="<?=h($cfg['mysql']['host'])?>">
      </div>
      <div>
        <label>MySQL base</label>
        <input name="mysql_dbname" value="<?=h($cfg['mysql']['dbname'])?>">
      </div>
      <div>
        <label>MySQL usuario</label>
        <input name="mysql_user" value="<?=h($cfg['mysql']['user'])?>">
      </div>
      <div>
        <label>MySQL password</label>
        <input name="mysql_password" type="password" value="<?=h($cfg['mysql']['password'])?>">
      </div>
      <div>
        <label>MT5 login</label>
        <input name="mt5_login" type="number" value="<?=h($cfg['mt5']['login'] ?? 0)?>">
      </div>
      <div>
        <label>MT5 password</label>
        <input name="mt5_password" type="password" value="<?=h($cfg['mt5']['password'] ?? '')?>">
      </div>
      <div>
        <label>MT5 server</label>
        <input name="mt5_server" value="<?=h($cfg['mt5']['server'] ?? 'XMGlobal-MT5 3')?>">
      </div>
      <div>
        <label>NVIDIA API Key</label>
        <input name="nvidia_api_key" type="password" value="<?=h($cfg['nvidia']['api_key'])?>">
      </div>
      <div>
        <label>NVIDIA</label>
        <select name="nvidia_enabled">
          <option value="0" <?=empty($cfg['nvidia']['enabled']) ? 'selected' : ''?>>Desactivado</option>
          <option value="1" <?=!empty($cfg['nvidia']['enabled']) ? 'selected' : ''?>>Activado</option>
        </select>
      </div>
      <div>
        <label>Token dashboard</label>
        <input name="security_token" value="<?=h($cfg['security_token'])?>">
      </div>
      <div>
        <label>Token WebSocket</label>
        <input name="ws_token" value="<?=h($cfg['ws_token'])?>">
      </div>
      <div class="full">
        <button type="submit"><?= $installed ? 'Actualizar instalacion' : 'Instalar proyecto' ?></button>
        <span class="hint">Crea/actualiza config.json, private/config.json y tablas MySQL.</span>
      </div>
    </div>
  </form>
</main>
</body>
</html>
