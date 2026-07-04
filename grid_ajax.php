<?php
/**
 * grid_ajax.php v14.10 – ETH/USDT Grid Bot Backend
 * Endpoints: _status, _logs, _ticker, _market, _control, _pnl_float,
 *            _ai_decisions, _scalp, _ml_info, _fills_history
 *
 * MODIFICADO v14.10:
 *  - Añadida verificación de token en endpoint _control
 *  - dbInit() se ejecuta solo una vez (control mediante tabla bot_meta)
 */
error_reporting(0);
ini_set('display_errors', '0');
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Bot-Version: 14.10');

// Cargar configuración: private/ primero (fuera de HTTP), luego public_html/
$_cfgOpts = [dirname(__DIR__) . '/private/config.json', __DIR__ . '/config.json'];
$configPath = null;
foreach ($_cfgOpts as $_opt) { if (@file_exists($_opt)) { $configPath = $_opt; break; } }
if (!$configPath) {
    http_response_code(500);
    echo json_encode(['error' => 'config.json no encontrado. Buscado en: ' . implode(', ', $_cfgOpts)]);
    exit;
}

$cfg = json_decode(file_get_contents($configPath), true);
if (!is_array($cfg)) $cfg = [];

// Rutas y credenciales
$mc          = $cfg['mysql']  ?? [];
$logFile     = $cfg['paths']['log'] ?? __DIR__ . '/bot.log';
$pidFile     = $cfg['paths']['pid'] ?? (dirname($configPath) . '/grid_bot.pid');
$ctrlFile    = $cfg['paths']['ctrl'] ?? (dirname($configPath) . '/grid_control.json');
$confHist    = $cfg['paths']['conf_hist'] ?? (dirname($configPath) . '/grid_confidence.json');
$statusFile  = $cfg['paths']['status'] ?? (dirname($configPath) . '/grid_status.json');
$mlWeightsFile = $cfg['ml']['weights_file'] ?? (__DIR__ . '/ml_weights_v2.json');
$bybitKey    = $cfg['bybit']['api_key']    ?? '';
$bybitSecret = $cfg['bybit']['api_secret'] ?? '';
$bybitTest   = (bool)($cfg['bybit']['testnet'] ?? false);
$bybitBase   = $bybitTest ? 'https://api-demo.bybit.com' : 'https://api.bybit.com';
$pubBase     = 'https://api.bybit.com';
$requiredToken = $cfg['security_token'] ?? '';

// ─── Helpers ───────────────────────────────────────────
function checkToken(): bool {
    global $requiredToken;
    $clean = trim($requiredToken ?? '');
    if ($clean === '') return true;
    return hash_equals($clean, trim($_GET['token'] ?? ''));
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

// dbInit con control de ejecución única mediante tabla bot_meta
function dbInitOnce(PDO $db): void {
    try {
        // Crear tabla de metadatos si no existe
        $db->exec("CREATE TABLE IF NOT EXISTS bot_meta (
            meta_key VARCHAR(50) PRIMARY KEY,
            meta_value VARCHAR(100) DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Verificar si ya se inicializó
        $stmt = $db->prepare("SELECT meta_value FROM bot_meta WHERE meta_key = 'db_inited'");
        $stmt->execute();
        $inited = $stmt->fetchColumn();
        if ($inited === '1') return;

        // Ejecutar creación de tablas
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

        // Asegurar columna ml_accuracy en grid_configs
        try { $db->exec("ALTER TABLE grid_configs ADD COLUMN ml_accuracy DECIMAL(6,4) DEFAULT 0"); } catch (Exception $e) {}

        // Marcar como inicializado
        $db->prepare("INSERT INTO bot_meta (meta_key, meta_value) VALUES ('db_inited', '1') ON DUPLICATE KEY UPDATE meta_value='1'")->execute();
    } catch (Exception $e) {
        // Fallo silencioso, la base de datos podría ya estar inicializada
    }
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

function getUptime(string $pf): string {
    if (!file_exists($pf)) return '--';
    $pid = trim(file_get_contents($pf));
    if (!$pid || !ctype_digit($pid) || !file_exists("/proc/$pid/stat")) return '--';
    $up   = (float)explode(' ', (string)@file_get_contents('/proc/uptime'))[0];
    $stat = (string)@file_get_contents("/proc/$pid/stat");
    $rp   = strrpos($stat, ')'); if ($rp === false) return '--';
    $flds = explode(' ', trim(substr($stat, $rp + 2)));
    $age  = max(0, (int)($up - (float)($flds[19] ?? 0) / 100));
    if ($age >= 3600) return intdiv($age, 3600) . 'h ' . intdiv($age % 3600, 60) . 'm';
    if ($age >= 60)   return intdiv($age, 60) . 'm ' . ($age % 60) . 's';
    return $age . 's';
}

function sanitize(string $s): string {
    return substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($s)), 0, 20);
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
        $side     = $p['side'] ?? 'Buy';
        $entry    = (float)($p['avgPrice']      ?? 0);
        $mark     = (float)($p['markPrice']     ?? 0);
        $liq      = (float)($p['liqPrice']      ?? 0);
        $upnl     = (float)($p['unrealisedPnl'] ?? 0);
        $lev      = (int)($p['leverage']        ?? 1);
        $im       = (float)($p['positionIM']    ?? 0);
        $mm       = (float)($p['positionMM']    ?? 0);
        $adl      = (int)($p['adlRankIndicator'] ?? 0);
        $created  = (int)($p['createdTime']     ?? 0);
        $updated  = (int)($p['updatedTime']     ?? 0);
        $positions[] = [
            'positionAmt'      => $side === 'Buy' ? $sz : -$sz,
            'entryPrice'       => $entry,
            'markPrice'        => $mark,
            'unRealizedProfit' => $upnl,
            'liquidationPrice' => $liq,
            'side'             => $side,
            'size'             => $sz,
            'leverage'         => $lev,
            'positionIM'       => $im,
            'positionMM'       => $mm,
            'adlRank'          => $adl,
            'createdTime'      => $created,
            'updatedTime'      => $updated,
            'roe'              => $im > 0 ? round(($upnl / $im) * 100, 2) : 0,
            'distToLiqPct'     => $entry > 0 && $liq > 0 ? round(abs($entry - $liq) / $entry * 100, 2) : 0,
            'markToEntryPct'   => $entry > 0 && $mark > 0 ? round(($mark - $entry) / $entry * 100, 4) : 0,
        ];
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

function getBybitOI(string $base, string $symbol): array {
    $url = $base . '/v5/market/open-interest?category=linear&symbol=' . urlencode($symbol) . '&intervalTime=5min&limit=1';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => true]);
    $resp = curl_exec($ch); curl_close($ch);
    if (!$resp) return [];
    $d = json_decode($resp, true);
    return ($d['retCode'] ?? -1) === 0 ? ($d['result']['list'][0] ?? []) : [];
}

// ═══════════════════════════════════════════════════════
// 1. TICKER (sin token)
// ═══════════════════════════════════════════════════════
if (isset($_GET['_ticker'])) {
    $t = getBybitTicker($pubBase, 'ETHUSDT');
    if (!$t) { echo json_encode(['error' => 'ticker fail']); exit; }
    echo json_encode([
        'ok'        => true,
        'price'     => (float)($t['lastPrice']  ?? 0),
        'bid'       => (float)($t['bid1Price']  ?? 0),
        'ask'       => (float)($t['ask1Price']  ?? 0),
        'change24h' => (float)($t['price24hPcnt'] ?? 0) * 100,
        'high24h'   => (float)($t['highPrice24h'] ?? 0),
        'low24h'    => (float)($t['lowPrice24h']  ?? 0),
        'vol24h'    => (float)($t['volume24h']    ?? 0),
        'markPrice' => (float)($t['markPrice']    ?? 0),
        'oi'        => (float)($t['openInterest'] ?? 0),
        'fundRate'  => (float)($t['fundingRate']  ?? 0),
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════
// 2. BOT STATUS (con token)
// ═══════════════════════════════════════════════════════
if (isset($_GET['_status'])) {
    $running = botRunning($pidFile, $logFile);
    $uptime  = getUptime($pidFile);
    $st      = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : null;
    $db      = getDB($mc);
    $data    = ['ok' => true, 'running' => $running, 'uptime' => $uptime,
                'ts' => date('Y-m-d H:i:s'), 'pairs' => (object)[]];

    if ($db) {
        dbInitOnce($db); // <-- solo ejecuta creación si es necesario
        try {
            $cfgRow = $db->query("SELECT * FROM grid_configs WHERE symbol='ETHUSDT' ORDER BY id DESC LIMIT 1")->fetch() ?: [];
            $pj     = ($st && isset($st['pairs']['ETHUSDT'])) ? $st['pairs']['ETHUSDT'] : [];

            $r1 = $db->query("SELECT COUNT(*) c, COALESCE(SUM(pnl_usd),0) p FROM grid_orders WHERE grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE()")->fetch();
            $r2 = $db->query("SELECT COUNT(*) c, COALESCE(SUM(pnl_usd),0) p FROM grid_orders WHERE grid_role='EXIT' AND status='FILLED'")->fetch();
            $oe = (int)$db->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='ETHUSDT' AND status='OPEN' AND grid_role='ENTRY'")->fetchColumn();
            $ox = (int)$db->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='ETHUSDT' AND status='OPEN' AND grid_role='EXIT'")->fetchColumn();
            $cp = (float)($pj['price'] ?? 0);

            $realBalance = getBybitBalance($bybitKey, $bybitSecret, $bybitBase);
            $realPositions = getBybitPositions($bybitKey, $bybitSecret, $bybitBase, 'ETHUSDT');
            $totalUpnl = array_sum(array_map(fn($p) => (float)($p['unRealizedProfit'] ?? 0), $realPositions));

            $orders = $db->query("SELECT id,side,grid_role,price,qty,grid_level,is_recovery,created_at FROM grid_orders WHERE symbol='ETHUSDT' AND status='OPEN' ORDER BY price DESC LIMIT 60")->fetchAll();

            $data['pnl_daily']  = $db->query("SELECT DATE(filled_at) d, ROUND(SUM(pnl_usd),6) p FROM grid_orders WHERE grid_role='EXIT' AND status='FILLED' AND filled_at>=DATE_SUB(NOW(),INTERVAL 14 DAY) GROUP BY DATE(filled_at) ORDER BY d ASC")->fetchAll();
            $data['pnl_hourly'] = $db->query("SELECT DATE(filled_at) d,HOUR(filled_at) h,ROUND(SUM(pnl_usd),6) p FROM grid_orders WHERE grid_role='EXIT' AND status='FILLED' AND filled_at>=DATE_SUB(NOW(),INTERVAL 48 HOUR) GROUP BY DATE(filled_at),HOUR(filled_at) ORDER BY d,h")->fetchAll();

            $data['pairs'] = ['ETHUSDT' => [
                'direction'      => $pj['direction']     ?? $cfgRow['direction']   ?? 'SIDEWAYS',
                'confidence'     => (int)($pj['confidence'] ?? $cfgRow['confidence'] ?? 50),
                'ai_reason'      => $pj['ai_reason']     ?? $cfgRow['ai_reason']   ?? '',
                'last_ai_check'  => $pj['last_ai_check'] ?? $cfgRow['last_ai_check'] ?? null,
                'price'          => $cp,
                'spacing_pct'    => (float)($pj['spacing_pct'] ?? $cfgRow['spacing_pct'] ?? 0.0008),
                'levels'         => (int)($pj['levels']    ?? $cfgRow['levels']    ?? 8),
                'long_levels'    => (int)($pj['long_levels']  ?? $cfgRow['long_levels']  ?? 4),
                'short_levels'   => (int)($pj['short_levels'] ?? $cfgRow['short_levels'] ?? 4),
                'leverage'       => 100,
                'open_entries'   => $oe,
                'open_exits'     => $ox,
                'fills_today'    => (int)($r1['c'] ?? 0),
                'pnl_today'      => round((float)($r1['p'] ?? 0), 6),
                'fills_total'    => (int)($r2['c'] ?? 0),
                'pnl_total'      => round((float)($r2['p'] ?? 0), 6),
                'peak_pnl'       => (float)($pj['peak_pnl'] ?? 0),
                'recovery_active'=> (bool)($pj['recovery_active'] ?? $cfgRow['recovery_active'] ?? false),
                'ml_accuracy'    => (float)($st['ml_accuracy'] ?? $cfgRow['ml_accuracy'] ?? 0),
                'grid_built'     => (bool)($pj['grid_built'] ?? true),
                'cycle_n'        => (int)($pj['cycle_n']  ?? 0),
                'real_positions' => $realPositions,
                'total_upnl'     => round($totalUpnl, 6),
                'orders'         => $orders,
                'real_balance'   => $realBalance,
                'atr_predicted'  => isset($pj['atr_predicted']) ? (float)$pj['atr_predicted'] : null,
                'vl_used'        => isset($pj['vl_used']) ? (bool)$pj['vl_used'] : false,
                'vl_direction'   => $pj['vl_direction'] ?? null,
                'vl_confidence'  => isset($pj['vl_confidence']) ? (int)$pj['vl_confidence'] : null,
            ]];
        } catch (Exception $e) { $data['db_error'] = $e->getMessage(); $data['pairs'] = (object)[]; }
    } else { $data['pairs'] = (object)[]; $data['db_error'] = 'MySQL no disponible'; }

    echo json_encode($data); exit;
}

// ═══════════════════════════════════════════════════════
// 3. MARKET DATA (sin token)
// ═══════════════════════════════════════════════════════
if (isset($_GET['_market'])) {
    $t  = getBybitTicker($pubBase, 'ETHUSDT');
    $fn = getBybitFunding($pubBase, 'ETHUSDT');
    $oi = getBybitOI($pubBase, 'ETHUSDT');

    $klines = []; $closes = []; $volumes = [];
    $klSources = [
        ['url' => 'https://fapi.binance.com/fapi/v1/klines?symbol=ETHUSDT&interval=5m&limit=100', 'type' => 'binance'],
        ['url' => $pubBase . '/v5/market/kline?category=linear&symbol=ETHUSDT&interval=5&limit=100', 'type' => 'bybit'],
    ];
    foreach ($klSources as $src) {
        $ch  = curl_init($src['url']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
                                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_FOLLOWLOCATION => true]);
        $resp = curl_exec($ch); curl_close($ch);
        if (!$resp) continue;
        if ($src['type'] === 'binance') {
            $kd = json_decode($resp, true);
            if (!is_array($kd) || empty($kd)) continue;
            foreach ($kd as $k) {
                if (!isset($k[4])) continue;
                $klines[] = ['t' => (int)$k[0], 'o' => (float)$k[1], 'h' => (float)$k[2],
                             'l' => (float)$k[3], 'c' => (float)$k[4], 'v' => (float)$k[5]];
                $closes[] = (float)$k[4]; $volumes[] = (float)$k[5];
            }
        } else {
            $kd = json_decode($resp, true);
            if (($kd['retCode'] ?? -1) !== 0) continue;
            foreach (array_reverse($kd['result']['list'] ?? []) as $k) {
                $klines[] = ['t' => (int)$k[0], 'o' => (float)$k[1], 'h' => (float)$k[2],
                             'l' => (float)$k[3], 'c' => (float)$k[4], 'v' => (float)$k[5]];
                $closes[] = (float)$k[4]; $volumes[] = (float)$k[5];
            }
        }
        if (count($klines) >= 30) break;
    }

    // RSI-14
    $rsi = 50.0;
    if (count($closes) > 14) {
        $ag = $al = 0.0;
        for ($i = 1; $i <= 14; $i++) { $d = $closes[$i] - $closes[$i-1]; if ($d>0) $ag+=$d; else $al+=abs($d); }
        $ag /= 14; $al /= 14;
        for ($i = 15; $i < count($closes); $i++) { $d = $closes[$i] - $closes[$i-1]; $ag=($ag*13+max($d,0))/14; $al=($al*13+max(-$d,0))/14; }
        $rsi = $al == 0 ? 100.0 : round(100 - 100 / (1 + $ag / $al), 2);
    }

    // MACD Hist
    $macdHist = 0.0;
    if (count($closes) >= 26) {
        $ema12 = []; $ema26 = []; $macdLine = [];
        $e12 = array_sum(array_slice($closes,0,12))/12;
        $e26 = array_sum(array_slice($closes,0,26))/26;
        $k12 = 2/13; $k26 = 2/27; $k9 = 2/10;
        for ($i=12; $i<count($closes); $i++) { $e12 = $closes[$i]*$k12 + $e12*(1-$k12); $ema12[] = $e12; }
        for ($i=26; $i<count($closes); $i++) { $e26 = $closes[$i]*$k26 + $e26*(1-$k26); $ema26[] = $e26; $macdLine[] = $e12 - $e26; }
        if (count($macdLine) >= 9) {
            $sig = array_sum(array_slice($macdLine,0,9))/9;
            for ($i=9; $i<count($macdLine); $i++) { $sig = $macdLine[$i]*$k9 + $sig*(1-$k9); }
            $macdHist = round(end($macdLine) - $sig, 8);
        }
    }

    // ADX-14
    $adx = 0.0;
    if (count($closes) >= 28) {
        $trArr=[]; $pdArr=[]; $ndArr=[];
        for ($i=1;$i<count($klines);$i++) {
            $h=$klines[$i]['h'];$l=$klines[$i]['l'];$pc=$klines[$i-1]['c'];
            $trArr[]=max($h-$l,abs($h-$pc),abs($l-$pc));
            $pdm=$h-$klines[$i-1]['h']; $ndm=$klines[$i-1]['l']-$l;
            $pdArr[]=($pdm>$ndm&&$pdm>0)?$pdm:0;
            $ndArr[]=($ndm>$pdm&&$ndm>0)?$ndm:0;
        }
        $atr14=array_sum(array_slice($trArr,-14))/14;
        $pdi14=($atr14>0)?array_sum(array_slice($pdArr,-14))/14/$atr14*100:0;
        $ndi14=($atr14>0)?array_sum(array_slice($ndArr,-14))/14/$atr14*100:0;
        $dx=($pdi14+$ndi14>0)?abs($pdi14-$ndi14)/($pdi14+$ndi14)*100:0;
        $adx=round($dx,1);
    }

    // BB %B
    $bbPct=0.5; $bbW=0.0;
    if (count($closes)>=20) {
        $sl=array_slice($closes,-20); $avg=array_sum($sl)/20;
        $std=sqrt(array_sum(array_map(fn($v)=>($v-$avg)**2,$sl))/20);
        $lp=end($closes); $upper=$avg+2*$std; $lower=$avg-2*$std;
        $bbPct=($std>0)?round(($lp-$lower)/($upper-$lower),3):0.5;
        $bbW=round(($upper-$lower)/$avg*100,3);
    }

    // EMA 9/21/50
    $ema9=null;$ema21=null;$ema50=null;
    if (count($closes)>=9){$e=array_sum(array_slice($closes,0,9))/9;for($i=9;$i<count($closes);$i++){$e=$closes[$i]*(2/10)+$e*(1-2/10);}$ema9=round($e,2);}
    if (count($closes)>=21){$e=array_sum(array_slice($closes,0,21))/21;for($i=21;$i<count($closes);$i++){$e=$closes[$i]*(2/22)+$e*(1-2/22);}$ema21=round($e,2);}
    if (count($closes)>=50){$e=array_sum(array_slice($closes,0,50))/50;for($i=50;$i<count($closes);$i++){$e=$closes[$i]*(2/51)+$e*(1-2/51);}$ema50=round($e,2);}

    // Vol ratio
    $vols = $volumes; $last_v = end($vols); $avg_v = count($vols)>=20?array_sum(array_slice($vols,-20))/20:($last_v?:1);
    $volRatio = $avg_v>0?round($last_v/$avg_v,2):1.0;

    // ATR%
    $atrPct=0.0;
    if (count($klines)>14){
        $trs=[];
        for($i=1;$i<count($klines);$i++)
            $trs[]=max($klines[$i]['h']-$klines[$i]['l'],abs($klines[$i]['h']-$klines[$i-1]['c']),abs($klines[$i]['l']-$klines[$i-1]['c']));
        $atrVal=array_sum(array_slice($trs,-14))/14;
        $lc=end($closes); $atrPct=$lc>0?round($atrVal/$lc*100,4):0;
    }

    echo json_encode([
        'ok'       => true,
        'ts'       => date('Y-m-d H:i:s'),
        'klines'   => $klines,
        'rsi'      => $rsi,
        'macd_hist'=> $macdHist,
        'adx'      => $adx,
        'bb_pct'   => $bbPct,
        'bb_width' => $bbW,
        'ema9'     => $ema9, 'ema21' => $ema21, 'ema50' => $ema50,
        'atr_pct'  => $atrPct,
        'vol_ratio'=> $volRatio,
        'funding'  => (float)($t['fundingRate'] ?? $fn['fundingRate'] ?? 0),
        'next_fund'=> $fn['fundingRateTimestamp'] ?? null,
        'oi_value' => (float)($oi['openInterest'] ?? $t['openInterest'] ?? 0),
        'mark_px'  => (float)($t['markPrice']  ?? 0),
        'index_px' => (float)($t['indexPrice'] ?? 0),
        'price'    => (float)($t['lastPrice']  ?? 0),
        'high24h'  => (float)($t['highPrice24h'] ?? 0),
        'low24h'   => (float)($t['lowPrice24h']  ?? 0),
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════
// 4. LOGS (con token)
// ═══════════════════════════════════════════════════════
if (isset($_GET['_logs'])) {
    $lines = [];
    $logExists = file_exists($logFile);
    if ($logExists) {
        $size = filesize($logFile);
        if ($size > 0) {
            $read = min($size, 80000);
            $fp = fopen($logFile, 'r');
            fseek($fp, max(0, $size - $read));
            $raw = fread($fp, $read);
            fclose($fp);
            $lines = array_values(array_filter(explode("\n", $raw), function($l) { return trim($l) !== ''; }));
        }
    }

    if (empty($lines)) {
        $now = date('Y-m-d H:i:s');
        $lines[] = "$now [INFO] === Dashboard v14.10 ===";
        $lines[] = "$now [INFO] Esperando logs del bot...";
        if (!$logExists) {
            $lines[] = "$now [WARN] Archivo de log no encontrado en: $logFile";
        } else {
            $lines[] = "$now [WARN] El archivo de log existe pero está vacío. El bot aún no ha escrito nada.";
        }
        $lines[] = "$now [INFO] Para generar logs, ejecuta: php bot.php";
    }

    echo json_encode(['lines' => array_slice($lines, -400), 'size' => $logExists ? filesize($logFile) : 0]);
    exit;
}

// ═══════════════════════════════════════════════════════
// 5. CONTROL (POST) – CON VERIFICACIÓN DE TOKEN
// ═══════════════════════════════════════════════════════
if (isset($_POST['_control'])) {
    // Verificar token (ahora sí)
    if (!checkToken()) {
        echo json_encode(['ok' => false, 'msg' => 'Token inválido']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $ok = false; $msg = 'Acción desconocida';
    if (in_array($action, ['stop', 'force_ai', 'reset_grid', 'reset_pair'])) {
        file_put_contents($ctrlFile, json_encode([
            'action' => $action,
            'sym'    => sanitize($_POST['sym'] ?? 'ETHUSDT'),
            'ts'     => date('Y-m-d H:i:s'),
        ]));
        $ok = true; $msg = "Comando '$action' enviado";
    }
    echo json_encode(['ok' => $ok, 'msg' => $msg]); exit;
}

// ═══════════════════════════════════════════════════════
// 6. PNL FLOTANTE
// ═══════════════════════════════════════════════════════
if (isset($_GET['_pnl_float'])) {
    $positions   = getBybitPositions($bybitKey, $bybitSecret, $bybitBase, 'ETHUSDT');
    $totalUpnl   = array_sum(array_map(fn($p) => (float)($p['unRealizedProfit'] ?? 0), $positions));
    $realBalance = getBybitBalance($bybitKey, $bybitSecret, $bybitBase);
    echo json_encode(['ok' => true, 'positions' => $positions,
                      'total_upnl' => round($totalUpnl, 6), 'real_balance' => $realBalance]);
    exit;
}

// ═══════════════════════════════════════════════════════
// 6b. HISTORIAL POSICIONES (ROE / uPnL series)
// ═══════════════════════════════════════════════════════
if (isset($_GET['_pos_history'])) {
    $hours = (int)($_GET['hours'] ?? 24);
    $since = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
    $db = getDB($mc);
    if (!$db) { echo json_encode(['ok' => false, 'history' => []]); exit; }
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%H:%i') AS hm,
            AVG(unrealised_pnl) AS avg_upnl,
            AVG(roe_pct) AS avg_roe,
            MAX(created_at) AS ts
        FROM position_snapshots
        WHERE symbol = 'ETHUSDT' AND created_at >= ?
        GROUP BY hm
        ORDER BY ts ASC
    ");
    $stmt->execute([$since]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'history' => $rows]); exit;
}

// ═══════════════════════════════════════════════════════
// 7. DECISIONES IA
// ═══════════════════════════════════════════════════════
if (isset($_GET['_ai_decisions'])) {
    $decisions = [];
    if (file_exists($confHist)) {
        $confArr = json_decode(file_get_contents($confHist), true) ?: [];
        $st      = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : null;
        $lastDir = $st['pairs']['ETHUSDT']['direction'] ?? 'SIDEWAYS';
        $lastSpc = $st['pairs']['ETHUSDT']['spacing_pct'] ?? 0.001;
        foreach (array_slice($confArr, -50) as $entry) {
            $decisions[] = [
                'time'       => $entry['time']       ?? '',
                'direction'  => $entry['direction']  ?? $lastDir,
                'confidence' => (int)($entry['confidence'] ?? 50),
                'spacing'    => $lastSpc,
            ];
        }
    }
    echo json_encode(['ok' => true, 'decisions' => $decisions]); exit;
}

// ═══════════════════════════════════════════════════════
// 8. SCALPING STATS
// ═══════════════════════════════════════════════════════
if (isset($_GET['_scalp'])) {
    $db2 = getDB($mc);
    $out = ['ok'=>true,'fills_1h'=>0,'fills_24h'=>0,'pnl_1h'=>0.0,'pnl_24h'=>0.0,
            'avg_pnl'=>0.0,'win_rate'=>0.0,'fills_per_hour'=>0.0,'best_pnl'=>0.0,'worst_pnl'=>0.0];
    if ($db2) {
        dbInitOnce($db2);
        try {
            $h1  = $db2->query("SELECT COUNT(*) c, COALESCE(SUM(pnl_usd),0) p FROM grid_orders WHERE grid_role='EXIT' AND status='FILLED' AND filled_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)")->fetch();
            $h24 = $db2->query("SELECT COUNT(*) c, COALESCE(SUM(pnl_usd),0) p FROM grid_orders WHERE grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE()")->fetch();
            $avg = $db2->query("SELECT AVG(pnl_usd) a, COUNT(*) t, SUM(pnl_usd>0) w, MAX(pnl_usd) best, MIN(pnl_usd) worst FROM grid_orders WHERE grid_role='EXIT' AND status='FILLED' AND filled_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetch();
            $out['fills_1h']       = (int)($h1['c']??0);
            $out['pnl_1h']         = round((float)($h1['p']??0),6);
            $out['fills_24h']      = (int)($h24['c']??0);
            $out['pnl_24h']        = round((float)($h24['p']??0),6);
            $out['avg_pnl']        = round((float)($avg['a']??0),6);
            $out['win_rate']       = ($avg['t']??0)>0 ? round(($avg['w']/$avg['t'])*100,1) : 0.0;
            $out['fills_per_hour'] = (int)($h1['c']??0);
            $out['best_pnl']       = round((float)($avg['best']??0),6);
            $out['worst_pnl']      = round((float)($avg['worst']??0),6);
        } catch (Exception $e) { $out['db_err'] = $e->getMessage(); }
    }
    echo json_encode($out); exit;
}

// ═══════════════════════════════════════════════════════
// 8b. PÉRDIDAS - Monitoreo de fills con pérdida
// ═══════════════════════════════════════════════════════
if (isset($_GET['_losses'])) {
    $db = getDB($mc);
    if (!$db) { echo json_encode(['ok' => false, 'losses' => [], 'stats' => []]); exit; }
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $losses = [];
    $stats  = ['total_fills' => 0, 'loss_fills' => 0, 'win_fills' => 0, 'total_loss_usd' => 0.0, 'total_win_usd' => 0.0, 'avg_loss' => 0.0, 'avg_win' => 0.0, 'worst_loss' => 0.0, 'best_win' => 0.0, 'loss_streak' => 0, 'current_streak' => 0, 'max_loss_streak' => 0];
    try {
        $r = $db->query("SELECT id, side, grid_level, price, exit_price, qty, pnl_usd, filled_at, is_recovery FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' ORDER BY filled_at DESC LIMIT $limit")->fetchAll();
        foreach ($r as $row) {
            $pnl = (float)$row['pnl_usd'];
            $losses[] = [
                'id' => (int)$row['id'],
                'side' => $row['side'],
                'grid_level' => (int)$row['grid_level'],
                'entry_price' => (float)$row['price'],
                'exit_price' => (float)$row['exit_price'],
                'qty' => (float)$row['qty'],
                'pnl_usd' => round($pnl, 6),
                'is_loss' => $pnl < 0,
                'pnl_pct' => (float)$row['price'] > 0 ? round(($pnl / ((float)$row['qty'] * (float)$row['price'])) * 100, 4) : 0,
                'filled_at' => $row['filled_at'],
                'is_recovery' => (bool)$row['is_recovery'],
            ];
        }
        // Stats
        $all = $db->query("SELECT pnl_usd FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' ORDER BY filled_at DESC")->fetchAll(PDO::FETCH_COLUMN);
        $stats['total_fills'] = count($all);
        $lossesArr = array_filter($all, fn($v) => (float)$v < 0);
        $winsArr   = array_filter($all, fn($v) => (float)$v >= 0);
        $stats['loss_fills']  = count($lossesArr);
        $stats['win_fills']   = count($winsArr);
        $stats['total_loss_usd'] = round(array_sum($lossesArr), 6);
        $stats['total_win_usd']  = round(array_sum($winsArr), 6);
        $stats['avg_loss'] = count($lossesArr) > 0 ? round(array_sum($lossesArr) / count($lossesArr), 6) : 0;
        $stats['avg_win']  = count($winsArr) > 0 ? round(array_sum($winsArr) / count($winsArr), 6) : 0;
        $stats['worst_loss'] = count($lossesArr) > 0 ? round(min($lossesArr), 6) : 0;
        $stats['best_win']   = count($winsArr) > 0 ? round(max($winsArr), 6) : 0;
        // Loss streak actual
        $streak = 0; $maxStreak = 0;
        foreach (array_reverse($all) as $v) {
            if ((float)$v < 0) { $streak++; $maxStreak = max($maxStreak, $streak); }
            else { $streak = 0; }
        }
        $stats['current_streak'] = $streak;
        $stats['max_loss_streak'] = $maxStreak;
    } catch (Exception $e) { $stats['error'] = $e->getMessage(); }
    echo json_encode(['ok' => true, 'losses' => $losses, 'stats' => $stats]); exit;
}

// ═══════════════════════════════════════════════════════
// 9. ML INFO
// ═══════════════════════════════════════════════════════
if (isset($_GET['_ml_info'])) {
    $paths = [$mlWeightsFile, __DIR__ . '/' . basename($mlWeightsFile)];
    $ml = null;
    foreach ($paths as $p) { if (file_exists($p)) { $ml = json_decode(file_get_contents($p), true); break; } }
    if (!$ml) { echo json_encode(['ok' => false, 'error' => 'Sin archivo de pesos']); exit; }

    $importances = [];
    foreach ($ml['weights'] ?? [] as $feat => $clsWeights) {
        $importances[$feat] = round(array_sum(array_map('abs', array_values($clsWeights))), 4);
    }
    arsort($importances);

    echo json_encode([
        'ok'          => true,
        'symbol'      => $ml['symbol']     ?? 'ETHUSDT',
        'accuracy'    => (float)($ml['acc'] ?? 0),
        'updated_at'  => $ml['updated_at'] ?? null,
        'features'    => count($ml['weights'] ?? []),
        'classes'     => $ml['classes']    ?? [],
        'importances' => $importances,
        'intercepts'  => $ml['intercepts'] ?? [],
        'file'        => basename($mlWeightsFile),
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════
// 10. HISTORIAL DE FILLS
// ═══════════════════════════════════════════════════════
if (isset($_GET['_fills_history'])) {
    $limit  = min(200, max(10, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $db     = getDB($mc);
    if (!$db) { echo json_encode(['ok' => false, 'fills' => [], 'total' => 0]); exit; }
    dbInitOnce($db);
    try {
        $total = (int)$db->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED'")->fetchColumn();
        $stmt  = $db->prepare("SELECT id, side, grid_role, price, exit_price, qty, pnl_usd, filled_at, is_recovery, grid_level FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' ORDER BY filled_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $fills = $stmt->fetchAll();
        echo json_encode(['ok' => true, 'fills' => $fills, 'total' => $total]);
    } catch (Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// ═══════════════════════════════════════════════════════
// 10. ALERTS CONFIG
// ═══════════════════════════════════════════════════════
if (isset($_GET['_alerts_config'])) {
    $cfgFile = dirname(__DIR__) . '/private/config.json';
    if (!file_exists($cfgFile)) $cfgFile = __DIR__ . '/config.json';
    $cfg = json_decode(file_get_contents($cfgFile), true) ?: [];
    $alerts = $cfg['alerts'] ?? [];
    if (!empty($alerts['telegram_bot_token'])) $alerts['telegram_bot_token'] = substr($alerts['telegram_bot_token'], 0, 10) . '...';
    if (!empty($alerts['discord_webhook'])) $alerts['discord_webhook'] = substr($alerts['discord_webhook'], 0, 40) . '...';
    echo json_encode(['ok' => true, 'alerts' => $alerts]); exit;
}

if (isset($_POST['_alerts_config'])) {
    $cfgFile = dirname(__DIR__) . '/private/config.json';
    if (!file_exists($cfgFile)) $cfgFile = __DIR__ . '/config.json';
    $cfg = json_decode(file_get_contents($cfgFile), true) ?: [];
    $updates = [
        'enabled' => isset($_POST['enabled']) ? (bool)$_POST['enabled'] : ($cfg['alerts']['enabled'] ?? false),
        'telegram_enabled' => isset($_POST['telegram_enabled']) ? (bool)$_POST['telegram_enabled'] : ($cfg['alerts']['telegram_enabled'] ?? false),
        'telegram_bot_token' => $_POST['telegram_bot_token'] ?? ($cfg['alerts']['telegram_bot_token'] ?? ''),
        'telegram_chat_id' => $_POST['telegram_chat_id'] ?? ($cfg['alerts']['telegram_chat_id'] ?? ''),
        'discord_enabled' => isset($_POST['discord_enabled']) ? (bool)$_POST['discord_enabled'] : ($cfg['alerts']['discord_enabled'] ?? false),
        'discord_webhook' => $_POST['discord_webhook'] ?? ($cfg['alerts']['discord_webhook'] ?? ''),
        'loss_streak_threshold' => (int)($_POST['loss_streak_threshold'] ?? 3),
        'margin_low_pct' => (float)($_POST['margin_low_pct'] ?? 20),
        'daily_loss_limit_usd' => (float)($_POST['daily_loss_limit_usd'] ?? 12),
    ];
    $cfg['alerts'] = $updates;
    file_put_contents($cfgFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode(['ok' => true, 'msg' => 'Alerts config updated']); exit;
}

if (isset($_POST['_test_alert'])) {
    $cfgFile = dirname(__DIR__) . '/private/config.json';
    if (!file_exists($cfgFile)) $cfgFile = __DIR__ . '/config.json';
    $cfg = json_decode(file_get_contents($cfgFile), true) ?: [];
    $notify = new NotificationManager($cfg['alerts'] ?? []);
    $ok = $notify->send('INFO', 'Test Alert', 'This is a test notification from ETH/USDT Grid Bot.');
    echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Test alert sent' : 'Failed to send']); exit;
}

// ═══════════════════════════════════════════════════════
// 11. TRADE JOURNAL
// ═══════════════════════════════════════════════════════
if (isset($_GET['_journal'])) {
    $db = getDB($mc);
    if (!$db) { echo json_encode(['ok' => false, 'trades' => []]); exit; }
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);
    $tagFilter = $_GET['tag'] ?? null;
    
    $where = "WHERE symbol='ETHUSDT'";
    $params = [];
    if ($tagFilter) {
        $where .= " AND JSON_CONTAINS(tags, ?)";
        $params[] = json_encode($tagFilter);
    }
    
    $total = $db->prepare("SELECT COUNT(*) FROM trade_journal $where");
    $total->execute($params);
    $total = (int)$total->fetchColumn();
    
    $stmt = $db->prepare("SELECT * FROM trade_journal $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['ok' => true, 'trades' => $trades, 'total' => $total]); exit;
}

if (isset($_POST['_journal'])) {
    $db = getDB($mc);
    if (!$db) { echo json_encode(['ok' => false]); exit; }
    $id = (int)($_POST['id'] ?? 0);
    $tags = $_POST['tags'] ?? null;
    $notes = $_POST['notes'] ?? null;
    
    if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => 'Invalid ID']); exit; }
    
    $updates = [];
    $params = [];
    if ($tags !== null) { $updates[] = "tags=?"; $params[] = $tags; }
    if ($notes !== null) { $updates[] = "notes=?"; $params[] = $notes; }
    if (empty($updates)) { echo json_encode(['ok' => false, 'msg' => 'No updates']); exit; }
    
    $params[] = $id;
    $db->prepare("UPDATE trade_journal SET " . implode(',', $updates) . " WHERE id=?")->execute($params);
    echo json_encode(['ok' => true]); exit;
}

if (isset($_GET['_journal_export'])) {
    $db = getDB($mc);
    if (!$db) { echo json_encode(['ok' => false]); exit; }
    
    $stmt = $db->query("SELECT * FROM trade_journal WHERE symbol='ETHUSDT' ORDER BY created_at DESC");
    $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="journal_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Trade ID','Side','Level','Entry','Exit','Qty','PnL','PnL%','Fee','Net','Hold(s)','MFE','MAE','R:R','Tags','Notes','Created']);
    foreach ($trades as $t) {
        fputcsv($out, [$t['id'],$t['trade_id'],$t['side'],$t['grid_level'],$t['entry_price'],$t['exit_price'],$t['qty'],$t['pnl_usd'],$t['pnl_pct'],$t['fee_usd'],$t['net_pnl'],$t['hold_time_sec'],$t['mfe'],$t['mae'],$t['rr_ratio'],$t['tags'],$t['notes'],$t['created_at']]);
    }
    fclose($out);
    exit;
}

echo json_encode(['error' => 'no action', 'version' => '14.10']);
?>