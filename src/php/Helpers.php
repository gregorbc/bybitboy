<?php
/**
 * Helpers.php - Extracted pure functions from grid_ajax.php for testability
 */
declare(strict_types=1);

function sanitize(string $s): string {
    return substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($s)), 0, 20);
}

function checkToken(string $requiredToken): bool {
    $clean = trim($requiredToken);
    if ($clean === '') return true;
    return hash_equals($clean, trim($_GET['token'] ?? ''));
}

function getUptime(string $pf): string {
    if (!file_exists($pf)) return '--';
    $pid = trim(file_get_contents($pf));
    $age = 0;
    if ($pid && ctype_digit($pid) && file_exists("/proc/$pid/stat")) {
        $up   = (float)explode(' ', (string)@file_get_contents('/proc/uptime'))[0];
        $stat = (string)@file_get_contents("/proc/$pid/stat");
        $rp   = strrpos($stat, ')');
        if ($rp !== false) {
            $flds = explode(' ', trim(substr($stat, $rp + 2)));
            $age  = max(0, (int)($up - (float)($flds[19] ?? 0) / 100));
        }
    }
    if ($age <= 0 && file_exists($pf)) {
        $age = max(0, time() - filemtime($pf));
    }
    if ($age <= 0) return '1m';
    if ($age >= 3600) return intdiv($age, 3600) . 'h ' . intdiv($age % 3600, 60) . 'm';
    if ($age >= 60)   return intdiv($age, 60) . 'm ' . ($age % 60) . 's';
    return $age . 's';
}

function botRunning(string $pidFile, string $logFile): bool {
    $pidPaths = array_unique([$pidFile, dirname($logFile) . '/grid_bot.pid', __DIR__ . '/grid_bot.pid']);
    foreach ($pidPaths as $pf) {
        if (!file_exists($pf)) continue;
        $p = trim((string)file_get_contents($pf));
        if ($p && ctype_digit($p) && file_exists("/proc/$p")) return true;
    }
    return file_exists($logFile) && (time() - filemtime($logFile)) < 90;
}

function getDB(array $mc): ?PDO {
    if (empty($mc['host'])) return null;
    foreach (array_unique([$mc['host'], '127.0.0.1', 'localhost']) as $h) {
        try {
            $pdo = new PDO("mysql:host=$h;dbname={$mc['dbname']};charset=utf8mb4",
                $mc['user'], $mc['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_TIMEOUT => 3,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $pdo->exec("SET time_zone = '+00:00'");
            $pdo->query('SELECT 1');
            return $pdo;
        } catch (Exception $e) {}
    }
    return null;
}

function dbInitOnce(PDO $db): void {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS bot_meta (
            meta_key VARCHAR(50) PRIMARY KEY,
            meta_value VARCHAR(100) DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmt = $db->prepare("SELECT meta_value FROM bot_meta WHERE meta_key = 'db_inited'");
        $stmt->execute();
        $inited = $stmt->fetchColumn();
        if ($inited === '1') return;

        $db->exec("CREATE TABLE IF NOT EXISTS grid_configs (
            id INT AUTO_INCREMENT PRIMARY KEY, symbol VARCHAR(20) NOT NULL,
            direction VARCHAR(20) DEFAULT 'NEUTRAL', confidence INT DEFAULT 50,
            ai_reason VARCHAR(400) DEFAULT '', last_ai_check DATETIME,
            capital_usd DECIMAL(12,4), leverage INT DEFAULT 100,
            levels INT DEFAULT 10, spacing_pct DECIMAL(10,6) DEFAULT 0.000800,
            long_levels INT DEFAULT 5, short_levels INT DEFAULT 5,
            qty_per_level DECIMAL(20,8) DEFAULT 0, pp INT DEFAULT 2, qp INT DEFAULT 3,
            mode VARCHAR(20) DEFAULT 'NORMAL', recovery_active TINYINT(1) DEFAULT 0,
            peak_pnl_today DECIMAL(14,6) DEFAULT 0, status VARCHAR(10) DEFAULT 'ACTIVE',
            paused_reason VARCHAR(100) DEFAULT NULL,
            ml_accuracy DECIMAL(6,4) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sym (symbol)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS grid_orders (
            id INT AUTO_INCREMENT PRIMARY KEY, config_id INT, symbol VARCHAR(20),
            direction VARCHAR(20), grid_level INT, side VARCHAR(5), grid_role VARCHAR(5),
            order_id VARCHAR(80), price DECIMAL(20,8), exit_price DECIMAL(20,8),
            qty DECIMAL(20,8), status VARCHAR(12) DEFAULT 'OPEN',
            linked_order INT DEFAULT NULL, pnl_usd DECIMAL(14,8),
            is_recovery TINYINT(1) DEFAULT 0, filled_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sym (symbol), INDEX idx_status (status), INDEX idx_oid (order_id),
            INDEX idx_cfg (config_id), INDEX idx_linked (linked_order),
            INDEX idx_filled (filled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try { $db->exec("ALTER TABLE grid_configs ADD COLUMN ml_accuracy DECIMAL(6,4) DEFAULT 0"); } catch (Exception $e) {}

        $db->prepare("INSERT INTO bot_meta (meta_key, meta_value) VALUES ('db_inited', '1') ON DUPLICATE KEY UPDATE meta_value='1'")->execute();
    } catch (Exception $e) {}
}

function bybitSign(string $key, string $secret, string $base, string $path, array $params): array {
    $ts    = (string)(intval(microtime(true) * 1000));
    $recv  = '8000';
    ksort($params);
    $query   = http_build_query($params);
    $signStr = $ts . $key . $recv . $query;
    $sign    = hash_hmac('sha256', $signStr, $secret);
    $headers = ["X-BAPI-API-KEY: $key", "X-BAPI-TIMESTAMP: $ts",
                "X-BAPI-RECV-WINDOW: $recv", "X-BAPI-SIGN: $sign"];
    $url = $base . $path . '?' . $query;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
                            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_HTTPHEADER => $headers]);
    $resp = curl_exec($ch); curl_close($ch);
    if (!$resp) return [];
    $data = json_decode($resp, true);
    return ($data['retCode'] ?? -1) === 0 ? ($data['result'] ?? []) : [];
}

function getBybitPositions(string $key, string $secret, string $base, string $symbol): array {
    if (empty($key) || empty($secret)) return [];
    $r = bybitSign($key, $secret, $base, '/v5/position/list',
                   ['category' => 'linear', 'symbol' => $symbol]);
    $positions = [];
    foreach ($r['list'] ?? [] as $p) {
        $sz = (float)($p['size'] ?? 0); if ($sz < 0.001) continue;
        $positions[] = ['positionAmt'      => $p['side'] === 'Buy' ? $sz : -$sz,
                        'entryPrice'       => (float)($p['avgPrice']      ?? 0),
                        'unRealizedProfit' => (float)($p['unrealisedPnl'] ?? 0),
                        'liquidationPrice' => (float)($p['liqPrice']      ?? 0),
                        'side'             => $p['side'], 'size' => $sz];
    }
    return $positions;
}

function getBybitBalance(string $key, string $secret, string $base): float {
    if (empty($key) || empty($secret)) return 0.0;
    $r = bybitSign($key, $secret, $base, '/v5/account/wallet-balance', ['accountType' => 'UNIFIED']);
    foreach ($r['list'] ?? [] as $acc) {
        $v = (float)($acc['totalAvailableBalance'] ?? 0); if ($v > 0) return $v;
        foreach ($acc['coin'] ?? [] as $c) {
            if (($c['coin'] ?? '') !== 'USDT') continue;
            foreach (['availableToWithdraw','availableBalance','walletBalance','equity'] as $fld) {
                $v = (float)($c[$fld] ?? 0); if ($v > 0) return $v;
            }
        }
        $v = (float)($acc['totalEquity'] ?? 0); if ($v > 0) return $v;
    }
    return 0.0;
}

function getBybitTicker(string $base, string $symbol): array {
    $url = $base . '/v5/market/tickers?category=linear&symbol=' . urlencode($symbol);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => true]);
    $resp = curl_exec($ch); curl_close($ch);
    if (!$resp) return [];
    $d = json_decode($resp, true);
    return ($d['retCode'] ?? -1) === 0 ? ($d['result']['list'][0] ?? []) : [];
}

function getBybitFunding(string $base, string $symbol): array {
    $url = $base . '/v5/market/funding/history?category=linear&symbol=' . urlencode($symbol) . '&limit=1';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => true]);
    $resp = curl_exec($ch); curl_close($ch);
    if (!$resp) return [];
    $d = json_decode($resp, true);
    return ($d['retCode'] ?? -1) === 0 ? ($d['result']['list'][0] ?? []) : [];
}
function getBybitOI(string $base, string $symbol): array
{
    $url = $base . '/v5/market/open-interest?category=linear&symbol=' . urlencode($symbol) . '&intervalTime=5min&limit=1';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => true]);
    $resp = curl_exec($ch); curl_close($ch);
    if (!$resp) return [];
    $d = json_decode($resp, true);
    return ($d['retCode'] ?? -1) === 0 ? ($d['result']['list'][0] ?? []) : [];
}

if (!function_exists('analyzeChartWithVL')) {
    function analyzeChartWithVL(string $imagePath, string $apiKey): ?array
    {
        return \BinanceBot\Strategy\ChartVL::analyze($imagePath, $apiKey);
    }
}