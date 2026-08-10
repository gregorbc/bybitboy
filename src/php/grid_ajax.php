<?php
/**
 * grid_ajax.php v15.4 – ETH/USDT Grid Bot Backend
 * Endpoints: _status, _logs, _ticker, _market, _control, _pnl_float,
 *            _ai_decisions, _scalp, _ml_info, _fills_history, _health
 *
 * MEJORAS:
 *  - Endpoint _health para monitoreo
 *  - Win rate calculado correctamente (sin división por cero)
 *  - Verificación de token en _control
 *  - Respuestas consistentes y manejo de errores
 *  - Soporte para WebSocket (token opcional)
 *  - Sanitización de inputs
 */
error_reporting(0);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Bot-Version: 15.4');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

// ─── Autoload (loads Helpers.php via composer files directive) ───
$autoloadPaths = [
    dirname(__DIR__, 2) . '/vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];
foreach ($autoloadPaths as $_al) {
    if (file_exists($_al)) { require_once $_al; break; }
}

// ─── Helpers (standalone fallback when autoloader unavailable) ───
if (!function_exists('sanitize')) {
    require_once __DIR__ . '/Helpers.php';
}

// Cargar configuración: private/ primero (fuera de HTTP), luego public_html/
if (!file_exists(privateConfigPath())) {
    http_response_code(500);
    echo json_encode(['error' => 'config.json no encontrado. Buscado en: ' . privateConfigPath()]);
    exit;
}
$cfg = botCfg();

// Rutas y credenciales
$mc          = $cfg['mysql']  ?? [];
$logFile     = $cfg['paths']['log'] ?? __DIR__ . '/bot.log';
$pidFile     = $cfg['paths']['pid'] ?? (dirname(privateConfigPath()) . '/grid_bot.pid');
$ctrlFile    = $cfg['paths']['ctrl'] ?? (dirname(privateConfigPath()) . '/grid_control.json');
$confHist    = $cfg['paths']['conf_hist'] ?? (dirname(privateConfigPath()) . '/grid_confidence.json');
$statusFile  = $cfg['paths']['status'] ?? (dirname(privateConfigPath()) . '/grid_status.json');
$mlWeightsFile = $cfg['ml']['weights_file'] ?? (__DIR__ . '/ml_weights_v2.json');
$bybitKey    = $cfg['bybit']['api_key']    ?? getenv('BYBIT_API_KEY') ?: '';
$bybitSecret = $cfg['bybit']['api_secret'] ?? getenv('BYBIT_API_SECRET') ?: '';
$bybitTest   = (bool)($cfg['bybit']['testnet'] ?? filter_var(getenv('BYBIT_TESTNET'), FILTER_VALIDATE_BOOLEAN));
$bybitEnv    = (string)($cfg['bybit']['environment'] ?? ($bybitTest ? 'demo' : 'mainnet'));
$bybitBase   = match ($bybitEnv) {
    'mainnet' => 'https://api.bybit.com',
    'testnet' => 'https://api-testnet.bybit.com',
    'demo'    => 'https://api-demo.bybit.com',
    default   => 'https://api.bybit.com',
};
$pubBase     = $bybitBase;
$requiredToken = $cfg['security_token'] ?? getenv('SECURITY_TOKEN') ?: '';

// ─── Control de acceso: solo _landing_stats es público ───
if (!isset($_GET['_landing_stats'])) {
    if (!requireAdminSession()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
        exit;
    }
    session_write_close();
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
// 2. BOT STATUS (con token opcional)
// ═══════════════════════════════════════════════════════
if (isset($_GET['_status'])) {
    $running = botRunning($pidFile, $logFile);
    $uptime  = getUptime($pidFile);
    $st      = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : null;
    $db      = getDB($mc);
    $data    = ['ok' => true, 'running' => $running, 'uptime' => $uptime,
                'mode' => (isset($st['mode'])) ? $st['mode'] : 'NORMAL',
                'ts' => date('Y-m-d H:i:s'), 'pairs' => (object)[]];

    if ($db) {
        dbInitOnce($db);
        try {
            $cfgRow = $db->query("SELECT * FROM grid_configs WHERE symbol='ETHUSDT' ORDER BY id DESC LIMIT 1")->fetch() ?: [];
            $pj     = ($st && isset($st['pairs']['ETHUSDT'])) ? $st['pairs']['ETHUSDT'] : [];

            $r1 = $db->query("SELECT COUNT(*) c, COALESCE(SUM(pnl_usd),0) p FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE()")->fetch();
            $r2 = $db->query("SELECT COUNT(*) c, COALESCE(SUM(pnl_usd),0) p FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED'")->fetch();
            $oe = (int)$db->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='ETHUSDT' AND status='OPEN' AND grid_role='ENTRY'")->fetchColumn();
            $ox = (int)$db->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='ETHUSDT' AND status='OPEN' AND grid_role='EXIT'")->fetchColumn();
            $cp = (float)($pj['price'] ?? 0);

            $realBalance = getBybitBalance($bybitKey, $bybitSecret, $bybitBase);
            $realPositions = getBybitPositions($bybitKey, $bybitSecret, $bybitBase, 'ETHUSDT');
            $totalUpnl = array_sum(array_map(fn($p) => (float)($p['unRealizedProfit'] ?? 0), $realPositions));

            $orders = $db->query("SELECT id,side,grid_role,price,qty,grid_level AS level,is_recovery,created_at FROM grid_orders WHERE symbol='ETHUSDT' AND status='OPEN' ORDER BY price DESC LIMIT 60")->fetchAll();

            $data['pnl_daily']  = $db->query("SELECT DATE(filled_at) d, ROUND(SUM(pnl_usd),6) p FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' AND filled_at>=DATE_SUB(NOW(),INTERVAL 14 DAY) GROUP BY DATE(filled_at) ORDER BY d ASC")->fetchAll();
            $data['pnl_hourly'] = $db->query("SELECT DATE(filled_at) d,HOUR(filled_at) h,ROUND(SUM(pnl_usd),6) p FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' AND filled_at>=DATE_SUB(NOW(),INTERVAL 48 HOUR) GROUP BY DATE(filled_at),HOUR(filled_at) ORDER BY d,h")->fetchAll();
            $proj = projection30d($db, 'ETHUSDT');

            // Win rate (evitar división por cero)
            $totalFills = (int)($r2['c'] ?? 0);
            $wins = (int)$db->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' AND pnl_usd>0")->fetchColumn();
            $winRate = ($totalFills > 0) ? round(($wins / $totalFills) * 100, 1) : 0;

            $data['pairs'] = ['ETHUSDT' => [
                'ai_engine'      => $st['ai_engine'] ?? 'Grid v15.4',
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
                'pnl_proj_30d'   => (float)($proj['proj_30d'] ?? 0),
                'pnl_proj_days'  => (int)($proj['days'] ?? 0),
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
                'win_rate'       => $winRate,
            ]];
        } catch (Exception $e) { $data['db_error'] = $e->getMessage(); $data['pairs'] = (object)[]; }
    } else { $data['pairs'] = (object)[]; $data['db_error'] = 'MySQL no disponible'; }

    echo json_encode($data); exit;
}

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

// ═══════════════════════════════════════════════════════
// 3. MARKET DATA (sin token)
// ═══════════════════════════════════════════════════════
if (isset($_GET['_market'])) {
    $t  = getBybitTicker($pubBase, 'ETHUSDT');
    $fn = getBybitFunding($pubBase, 'ETHUSDT');
    $oi = getBybitOI($pubBase, 'ETHUSDT');

    $klines = []; $closes = []; $volumes = [];
    $klSources = [
        ['url' => $pubBase . '/v5/market/kline?category=linear&symbol=ETHUSDT&interval=5&limit=100', 'type' => 'bybit'],
    ];
    foreach ($klSources as $src) {
        $ch  = curl_init($src['url']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
                                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_FOLLOWLOCATION => true]);
        $resp = curl_exec($ch); curl_close($ch);
        if (!$resp) continue;
        if ($src['type'] === 'bybit') {
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
// 4. LOGS (via Router/Api)
// ═══════════════════════════════════════════════════════
if (isset($_GET['_logs'])) {
    if (class_exists(\BinanceBot\Dashboard\Router::class)) {
        $router = new \BinanceBot\Dashboard\Router();
        $router->dispatch($_GET);
        exit;
    }
    // Fallback: inline implementation
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
        $lines[] = "$now [INFO] === Dashboard v15.4 ===";
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
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'secure' => true,
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();
    }
    if (!isAdminSession($_SESSION)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
        exit;
    }
    session_write_close();
    if (!checkToken($requiredToken)) {
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
// 5b. CONFIG UPDATE (POST) – actualizar configuración en vivo
// ═══════════════════════════════════════════════════════
if (isset($_POST['action']) && $_POST['action'] === 'update_config') {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'secure' => true,
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();
    }
    if (!isAdminSession($_SESSION)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
        exit;
    }
    session_write_close();
    if (!checkToken($requiredToken)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'msg' => 'Token inválido']);
        exit;
    }
    $allowed = ['capital_usd', 'leverage', 'levels', 'long_levels', 'short_levels', 'spacing_pct'];
    $updates = [];
    foreach ($allowed as $k) {
        if (isset($_POST[$k])) $updates[$k] = $_POST[$k];
    }
    if (!empty($updates)) {
        $current = file_exists($ctrlFile) ? json_decode(file_get_contents($ctrlFile), true) : [];
        $current['config_update'] = $updates;
        $current['ts'] = date('Y-m-d H:i:s');
        file_put_contents($ctrlFile, json_encode($current));
        echo json_encode(['ok' => true, 'msg' => 'Configuración enviada al bot (próximo ciclo)']);
    } else echo json_encode(['ok' => false, 'msg' => 'Sin cambios']);
    exit;
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
            $out['win_rate']       = (($avg['t']??0) > 0) ? round(($avg['w']/$avg['t'])*100,1) : 0.0;
            $out['fills_per_hour'] = (int)($h1['c']??0);
            $out['best_pnl']       = round((float)($avg['best']??0),6);
            $out['worst_pnl']      = round((float)($avg['worst']??0),6);
        } catch (Exception $e) { $out['db_err'] = $e->getMessage(); }
    }
    echo json_encode($out); exit;
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
// 10. HISTORIAL DE FILLS (corregido: LIMIT/OFFSET como enteros)
// ═══════════════════════════════════════════════════════
if (isset($_GET['_fills_history'])) {
    $limit  = min(200, max(10, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $db     = getDB($mc);
    if (!$db) { echo json_encode(['ok' => false, 'fills' => [], 'total' => 0]); exit; }
    dbInitOnce($db);
    try {
        $total = (int)$db->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED'")->fetchColumn();
        // Usar bindParam con tipo entero para evitar comillas en LIMIT/OFFSET
        $stmt = $db->prepare("SELECT id, side, grid_role, price, exit_price, qty, pnl_usd, filled_at, is_recovery, grid_level 
                              FROM grid_orders 
                              WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' 
                              ORDER BY filled_at DESC 
                              LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $fills = $stmt->fetchAll();
        echo json_encode(['ok' => true, 'fills' => $fills, 'total' => $total]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════
// 11. HEALTH ENDPOINT (via Router/Api)
// ═══════════════════════════════════════════════════════
if (isset($_GET['_health'])) {
    if (class_exists(\BinanceBot\Dashboard\Router::class)) {
        $router = new \BinanceBot\Dashboard\Router();
        $router->dispatch($_GET);
        exit;
    }
    // Fallback: inline implementation
    $health = ['ok' => true, 'ts' => date('Y-m-d H:i:s')];
    // Bot running?
    $health['bot_running'] = botRunning($pidFile, $logFile);
    // Uptime del bot
    $health['bot_uptime'] = getUptime($pidFile);
    // Última modificación del log
    $health['log_mtime'] = file_exists($logFile) ? date('Y-m-d H:i:s', filemtime($logFile)) : null;
    $health['log_size'] = file_exists($logFile) ? filesize($logFile) : 0;
    // Estado MySQL
    $db = getDB($mc);
    $health['mysql'] = ($db !== null);
    if ($db) {
        try {
            $db->query('SELECT 1');
            $health['mysql_ok'] = true;
        } catch (Exception $e) {
            $health['mysql_ok'] = false;
            $health['mysql_error'] = $e->getMessage();
        }
    } else {
        $health['mysql_ok'] = false;
    }
    // Bybit API reachable
    $ticker = getBybitTicker($pubBase, 'ETHUSDT');
    $health['bybit_api'] = !empty($ticker);
    // Último entrenamiento ML
    $mlFile = $mlWeightsFile;
    if (file_exists($mlFile)) {
        $health['ml_weights_mtime'] = date('Y-m-d H:i:s', filemtime($mlFile));
        $mlData = json_decode(file_get_contents($mlFile), true);
        $health['ml_accuracy'] = $mlData['acc'] ?? null;
    }
    // Carga del sistema (solo Linux)
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $health['load_1min'] = $load[0];
        $health['load_5min'] = $load[1];
        $health['load_15min'] = $load[2];
    }
    // Memoria usada por el bot (via /proc)
    if ($health['bot_running'] && file_exists($pidFile)) {
        $pid = trim(file_get_contents($pidFile));
        if ($pid && ctype_digit($pid)) {
            $stat = @file_get_contents("/proc/$pid/statm");
            if ($stat) {
                $pages = explode(' ', $stat);
                $rss_pages = (int)($pages[1] ?? 0);
                $page_size = 4096;
                $health['bot_memory_mb'] = round(($rss_pages * $page_size) / (1024 * 1024), 2);
            }
        }
    }
    header('Content-Type: application/json');
    echo json_encode($health);
    exit;
}

// ═══════════════════════════════════════════════════════
// 6b. LANDING STATS (público, solo lectura)
// ═══════════════════════════════════════════════════════
if (isset($_GET['_landing_stats'])) {
    $db = getDB($mc);
    $data = ['ok' => true, 'price' => 0.0, 'pnl_today' => 0.0, 'pnl_total' => 0.0, 'win_rate' => 0.0,
             'fills_total' => 0, 'open_orders' => 0, 'pnl_proj_30d' => 0.0, 'pnl_proj_days' => 0,
             'updated_at' => date('Y-m-d H:i:s')];
    $st = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : null;
    if ($st && isset($st['pairs']['ETHUSDT']['price'])) {
        $data['price'] = (float)$st['pairs']['ETHUSDT']['price'];
    }
    if ($db) {
        dbInitOnce($db);
        try {
            $r1 = $db->query("SELECT COUNT(*) c, COALESCE(SUM(pnl_usd),0) p FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE()")->fetch();
            $r2 = $db->query("SELECT COUNT(*) c, COALESCE(SUM(pnl_usd),0) p FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED'")->fetch();
            $totalFills = (int)($r2['c'] ?? 0);
            $wins = (int)$db->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' AND pnl_usd>0")->fetchColumn();
            $data['pnl_today']   = round((float)($r1['p'] ?? 0), 6);
            $data['pnl_total']   = round((float)($r2['p'] ?? 0), 6);
            $data['fills_total'] = $totalFills;
            $data['win_rate']    = $totalFills > 0 ? round(($wins / $totalFills) * 100, 1) : 0.0;
            $data['open_orders'] = (int)$db->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='ETHUSDT' AND status='OPEN'")->fetchColumn();

            $proj = projection30d($db, 'ETHUSDT');
            $data['pnl_proj_30d'] = (float)($proj['proj_30d'] ?? 0);
            $data['pnl_proj_days'] = (int)($proj['days'] ?? 0);
        } catch (Exception $e) {}
    }
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRESERVE_ZERO_FRACTION); exit;
}

echo json_encode(['error' => 'no action', 'version' => '15.4']);
?>