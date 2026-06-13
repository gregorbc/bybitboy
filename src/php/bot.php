#!/usr/bin/env php
<?php
/**
 * ETH/USDT GRID BOT v15.4 – FINAL
 * - Fix confirmación de posiciones acumuladas (permite tamaño >= esperado)
 * - Fix ML: ahora se espera usar LogisticRegression (pesos direccionales)
 * - Mejora reciclaje de ENTRY cuando ya existe posición neta acumulada
 * - Mayor tiempo de confirmación de posición (12s) para reducir falsos negativos
 * - Filtro de modelos ML: ignora pesos con accuracy < 85%
 */

set_time_limit(0);
ini_set('memory_limit', '256M');
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Solo CLI\n"); }
date_default_timezone_set('UTC');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

// ════════════════════════════════════════════════════════
// 1. CONFIGURACIÓN
// ════════════════════════════════════════════════════════
$_cfgPaths = [
    dirname(__DIR__) . '/private/config.json',
    __DIR__ . '/config.json',
    '/home/erika/config/config.json',
];
$cfgFile = null;
foreach ($_cfgPaths as $_p) { if (@file_exists($_p)) { $cfgFile = $_p; break; } }
if (!$cfgFile) {
    fwrite(STDERR, "ERROR: config.json no encontrado.\nBuscado en:\n  " . implode("\n  ", $_cfgPaths) . "\n");
    exit(1);
}
$cfg = json_decode(file_get_contents($cfgFile), true);
if (!is_array($cfg)) { fwrite(STDERR, "ERROR: config.json inválido\n"); exit(1); }

function cv($c, $k, $d = null) {
    $v = $c;
    foreach ($k as $key) { if (!isset($v[$key])) return $d; $v = $v[$key]; }
    return $v;
}

$BK     = trim((string)cv($cfg, ['bybit', 'api_key'], ''));
$BS     = trim((string)cv($cfg, ['bybit', 'api_secret'], ''));
$TN     = (bool)cv($cfg, ['bybit', 'testnet'], false);
$DB_H   = trim((string)cv($cfg, ['mysql', 'host'], 'localhost'));
$DB_N   = trim((string)cv($cfg, ['mysql', 'dbname'], ''));
$DB_U   = trim((string)cv($cfg, ['mysql', 'user'], ''));
$DB_P   = trim((string)cv($cfg, ['mysql', 'password'], ''));
$ML_W   = trim((string)cv($cfg, ['ml', 'weights_file'], 'ml_weights_v2.json'));

$NV_ENABLED   = (bool)cv($cfg, ['nvidia', 'enabled'], false);
$NV_API_KEY   = trim((string)cv($cfg, ['nvidia', 'api_key'], ''));
$NV_INTERVAL  = (int)cv($cfg, ['nvidia', 'interval_sec'], 480);

if (empty($BK) || empty($BS) || empty($DB_N)) {
    fwrite(STDERR, "ERROR: Faltan credenciales\n"); exit(1);
}

$BOT_DIR   = __DIR__;
$LOG       = cv($cfg, ['paths', 'log'],       "$BOT_DIR/bot.log");
$STATUS    = cv($cfg, ['paths', 'status'],    "$BOT_DIR/../private/grid_status.json");
$CTRL      = cv($cfg, ['paths', 'ctrl'],      "$BOT_DIR/../private/grid_control.json");
$CONF_HIST = cv($cfg, ['paths', 'conf_hist'], "$BOT_DIR/../private/grid_confidence.json");
$PID_FILE  = cv($cfg, ['paths', 'pid'],       "$BOT_DIR/grid_bot.pid");

// ════════════════════════════════════════════════════════
// 2. CONSTANTES ESTRATÉGICAS
// ════════════════════════════════════════════════════════
define('G_SYM',           strtoupper(trim((string)cv($cfg, ['bot', 'symbol'], 'ETHUSDT'))));
define('G_CAPITAL',       max(0.0, (float)cv($cfg, ['bot', 'capital_usd'], 30.0)));
define('G_LEVERAGE',      max(1, (int)cv($cfg, ['bot', 'leverage'], 100)));
define('G_CYCLE_SEC',     max(1, (int)cv($cfg, ['bot', 'cycle_sec'], 8)));
define('G_AI_INTERVAL',   max(1, (int)cv($cfg, ['bot', 'ai_interval_sec'], 120)));
define('G_TF',            (string)cv($cfg, ['bot', 'timeframe'], '5'));
define('G_CANDLES',       max(50, (int)cv($cfg, ['bot', 'candles_feed'], 150)));
define('G_MIN_LEVELS',    max(1, (int)cv($cfg, ['grid', 'min_levels'], 8)));
define('G_MAX_LEVELS',    max(G_MIN_LEVELS, (int)cv($cfg, ['grid', 'max_levels'], 20)));
define('G_MIN_SPACING',   max(0.000001, (float)cv($cfg, ['grid', 'min_spacing'], 0.0003)));
define('G_MAX_SPACING',   max(G_MIN_SPACING, (float)cv($cfg, ['grid', 'max_spacing'], 0.0012)));
define('G_MARGIN_SAFETY', max(0.01, (float)cv($cfg, ['risk', 'margin_safety'], 0.65)));
define('G_MAKER_FEE',     max(0.0, (float)cv($cfg, ['fees', 'maker'], 0.0001)));
define('G_TAKER_FEE',     max(0.0, (float)cv($cfg, ['fees', 'taker'], 0.0006)));
define('G_MAX_DAILY_LOSS',max(0.0, (float)cv($cfg, ['risk', 'max_daily_loss'], 12.0)));
define('G_HARD_STOP_PCT', max(0.0, (float)cv($cfg, ['risk', 'hard_stop_pct'], 3.0)));
define('G_RECOVERY_THR',  max(0.0, (float)cv($cfg, ['risk', 'recovery_thr'], 1.0)));
define('G_COMPOUND_THR',  max(0.0, (float)cv($cfg, ['compound', 'threshold'], 1.5)));
define('G_COMPOUND_MULT', max(1.0, (float)cv($cfg, ['compound', 'multiplier'], 1.05)));
define('G_COMPOUND_CD',   max(0, (int)cv($cfg, ['compound', 'cooldown_sec'], 300)));
define('G_MIN_NOTIONAL',  max(0.0, (float)cv($cfg, ['exchange_rules', 'min_notional'], 3.0)));

define('G_FIXED_LEVELS',     max(1, (int)cv($cfg, ['bot', 'levels'], 16)));
define('G_LONG_LEVELS',      min(G_FIXED_LEVELS, max(1, (int)cv($cfg, ['bot', 'long_levels'], (int)(G_FIXED_LEVELS / 2)))));
define('G_SHORT_LEVELS',     min(G_FIXED_LEVELS, max(1, (int)cv($cfg, ['bot', 'short_levels'], G_FIXED_LEVELS - G_LONG_LEVELS))));
define('G_BASE_SPACING',     min(G_MAX_SPACING, max(G_MIN_SPACING, (float)cv($cfg, ['grid', 'base_spacing'], 0.0003))));
define('G_SPACING_ATR_MULT', max(0.0, (float)cv($cfg, ['grid', 'spacing_atr_mult'], 0.28)));
define('G_RECOVERY_LOSS_PCT',max(0.0, (float)cv($cfg, ['risk', 'recovery_loss_pct'], 3.0)));
define('G_MIN_BUILD_INTERVAL',max(0, (int)cv($cfg, ['grid', 'min_build_interval_sec'], 90)));
define('G_ML_BLEND_WEIGHT',  max(0.0, (float)cv($cfg, ['ml', 'blend_weight'], 0.90)));
define('G_ML_RELOAD_CYCLES', max(1, (int)cv($cfg, ['ml', 'reload_cycles'], 120)));
define('G_VL_BLEND_WEIGHT',  max(0.0, (float)cv($cfg, ['nvidia', 'blend_weight'], 0.10)));
define('G_VOL_RELOAD_CYCLES', max(1, (int)cv($cfg, ['volatility', 'reload_cycles'], 120)));
define('G_ML_MIN_ACCURACY',  max(0.0, (float)cv($cfg, ['ml', 'min_accuracy'], 0.85))); // Nueva constante: accuracy mínima para aceptar pesos

// ════════════════════════════════════════════════════════
// 3. PID LOCK
// ════════════════════════════════════════════════════════
$lockFile = $PID_FILE;
$fpLock   = null;
$fpLock = @fopen($lockFile, 'x');
if ($fpLock === false) {
    $existingPid = trim((string)@file_get_contents($lockFile));
    if ($existingPid && ctype_digit($existingPid) && file_exists("/proc/$existingPid")) {
        fwrite(STDERR, "Bot ya en ejecución (PID $existingPid). Saliendo.\n");
        exit(1);
    }
    @unlink($lockFile);
    $fpLock = @fopen($lockFile, 'x');
    if ($fpLock === false) {
        fwrite(STDERR, "No se pudo adquirir PID lock.\n"); exit(1);
    }
}
fwrite($fpLock, (string)getmypid());
fflush($fpLock);
register_shutdown_function(function () use ($fpLock, $lockFile) {
    if (is_resource($fpLock)) { fclose($fpLock); }
    @unlink($lockFile);
    $last = error_get_last();
    if ($last && in_array($last['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        @file_put_contents($GLOBALS['LOG'] ?? '/tmp/gridbot_fatal.log',
            date('Y-m-d H:i:s') . " [FATAL] {$last['message']} en {$last['file']}:{$last['line']}\n",
            FILE_APPEND);
    }
});

// ════════════════════════════════════════════════════════
// 4. LOGGER
// ════════════════════════════════════════════════════════
function lg($level, $msg) {
    global $LOG;
    static $lastMsg = '', $lastTs = 0.0;
    $now = microtime(true);
    if ($msg === $lastMsg && ($now - $lastTs) < 2.0) return;
    $lastMsg = $msg; $lastTs = $now;
    $line = date('Y-m-d H:i:s') . " [$level] $msg\n";
    if (function_exists('posix_isatty') && posix_isatty(STDOUT)) { echo $line; }
    if ($LOG) {
        if (file_exists($LOG) && filesize($LOG) > 12 * 1024 * 1024) {
            @rename($LOG, $LOG . '.' . date('Ymd_His') . '.bak');
        }
        file_put_contents($LOG, $line, FILE_APPEND | LOCK_EX);
    }
}
function lI($m) { lg('INFO',  $m); }
function lW($m) { lg('WARN',  $m); }
function lE($m) { lg('ERROR', $m); }

// ════════════════════════════════════════════════════════
// 5. HTTP
// ════════════════════════════════════════════════════════
function hGet($url, $timeout = 10) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'EthGridBot/15.4',
    ]);
    $b = curl_exec($ch); $e = curl_error($ch); curl_close($ch);
    if ($b === false) throw new RuntimeException("GET $url: $e");
    return (string)$b;
}
function hPost($url, $payload, $headers = [], $timeout = 25) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => $payload, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => $timeout, CURLOPT_USERAGENT => 'EthGridBot/15.4',
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $b = curl_exec($ch); $e = curl_error($ch); curl_close($ch);
    if ($b === false) throw new RuntimeException("POST: $e");
    return ['body' => json_decode((string)$b, true) ?: [], 'raw' => (string)$b];
}

// ════════════════════════════════════════════════════════
// 6. DATABASE
// ════════════════════════════════════════════════════════
function db($fresh = false) {
    global $DB_H, $DB_N, $DB_U, $DB_P;
    static $pdo = null, $ts = 0;
    if ($fresh || !$pdo) {
        $pdo = null;
        foreach (array_unique([$DB_H, 'localhost', '127.0.0.1']) as $h) {
            try {
                $pdo = new PDO(
                    "mysql:host=$h;dbname=$DB_N;charset=utf8mb4", $DB_U, $DB_P,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                     PDO::ATTR_TIMEOUT => 15,
                     PDO::ATTR_PERSISTENT => false,
                     PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone='+00:00'"]
                );
                $pdo->query('SELECT 1'); $ts = time(); break;
            } catch (Exception $e) { $pdo = null; }
        }
        if (!$pdo) { lE("[DB] MySQL no disponible"); return null; }
    }
    if (time() - $ts > 30) {
        try { $pdo->query('SELECT 1'); $ts = time(); }
        catch (Exception $e) { return db(true); }
    }
    return $pdo;
}
function dbx($f) {
    try { $d = db(); if (!$d) throw new Exception("Sin DB"); return $f($d); }
    catch (PDOException $e) {
        $m = $e->getMessage();
        if (stripos($m, 'gone away') !== false || stripos($m, 'Lost connection') !== false) {
            try { $d2 = db(true); if (!$d2) throw new Exception(); return $f($d2); }
            catch (Exception $e2) { lE("[DB] Reconexión: " . $e2->getMessage()); }
        } else { lE("[DB] " . $m); }
        return null;
    } catch (Exception $e) { lE("[DB] " . $e->getMessage()); return null; }
}
function dbInit() {
    $d = db(true); if (!$d) return;
    $d->exec("CREATE TABLE IF NOT EXISTS grid_configs (
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
    $d->exec("CREATE TABLE IF NOT EXISTS grid_orders (
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
    try { $d->exec("ALTER TABLE grid_configs ADD COLUMN ml_accuracy DECIMAL(6,4) DEFAULT 0"); } catch (Exception $e) {}
    lI("[DB] Tablas v15.4 OK");
}

// ════════════════════════════════════════════════════════
// 7. INDICADORES TÉCNICOS OPTIMIZADOS
// ════════════════════════════════════════════════════════
// Caché estática para indicadores calculados recientemente
$_IND_CACHE = [];

function ema($v, $p) {
    static $cache = [];
    $key = md5(serialize(array_slice($v, -50)) . '_' . $p);
    if (isset($cache[$key])) return $cache[$key];
    
    $n = count($v); if ($n === 0 || $p <= 0) return [];
    $r = array_fill(0, min($p - 1, $n), null);
    if ($n < $p) return $r;
    $k = 2 / ($p + 1);
    $e = array_sum(array_slice($v, 0, $p)) / $p;
    $r[] = $e;
    for ($i = $p; $i < $n; $i++) { $e = $v[$i] * $k + $e * (1 - $k); $r[] = $e; }
    
    if (count($cache) > 100) array_shift($cache);
    $cache[$key] = $r;
    return $r;
}

function rsiLast($c, $p = 14) {
    static $cache = [];
    $key = md5(serialize(array_slice($c, -30)) . '_' . $p);
    if (isset($cache[$key])) return $cache[$key];
    
    $n = count($c); if ($n <= $p) return 50.0;
    $ag = $al = 0.0;
    for ($i = 1; $i <= $p; $i++) {
        $d = $c[$i] - $c[$i - 1];
        if ($d > 0) $ag += $d; else $al += abs($d);
    }
    $ag /= $p; $al /= $p;
    for ($i = $p + 1; $i < $n; $i++) {
        $d = $c[$i] - $c[$i - 1];
        $ag = ($ag * ($p - 1) + max($d, 0)) / $p;
        $al = ($al * ($p - 1) + max(-$d, 0)) / $p;
    }
    $result = $al == 0 ? 100.0 : round(100 - 100 / (1 + $ag / $al), 2);
    
    if (count($cache) > 100) array_shift($cache);
    $cache[$key] = $result;
    return $result;
}

function macdHistLast($c) {
    static $cache = [];
    $key = md5(serialize(array_slice($c, -50)));
    if (isset($cache[$key])) return $cache[$key];
    
    $ef = ema($c, 12); $es = ema($c, 26); $ml = [];
    for ($i = 0; $i < count($ef); $i++)
        if ($ef[$i] !== null && $es[$i] !== null) $ml[] = $ef[$i] - $es[$i];
    if (count($ml) < 9) { $cache[$key] = 0.0; return 0.0; }
    $sig = ema($ml, 9); $sv = end($sig); $vl = end($ml);
    $result = round((float)($vl - ($sv !== false ? $sv : 0)), 8);
    
    if (count($cache) > 100) array_shift($cache);
    $cache[$key] = $result;
    return $result;
}
function emaTrend($c) {
    $e9 = ema($c, 9); $e21 = ema($c, 21); $e50 = ema($c, 50);
    $last = end($c); $e9l = end($e9); $e21l = end($e21); $e50l = end($e50);
    if (!$e9l || !$e21l) return ['trend' => 'NEUTRAL', 'strength' => 0];
    $bull = ($last > $e9l && $e9l > $e21l);
    $bear = ($last < $e9l && $e9l < $e21l);
    if ($bull && $e50l && $last > $e50l) return ['trend' => 'BULLISH', 'strength' => 2];
    if ($bear && $e50l && $last < $e50l) return ['trend' => 'BEARISH', 'strength' => 2];
    if ($bull) return ['trend' => 'BULLISH', 'strength' => 1];
    if ($bear) return ['trend' => 'BEARISH', 'strength' => 1];
    return ['trend' => 'NEUTRAL', 'strength' => 0];
}
function atrPctLast($cn, $p = 14) {
    $n = count($cn); if ($n < 2) return 0.0;
    $trs = [];
    for ($i = 1; $i < $n; $i++)
        $trs[] = max($cn[$i]['h'] - $cn[$i]['l'],
                     abs($cn[$i]['h'] - $cn[$i - 1]['c']),
                     abs($cn[$i]['l'] - $cn[$i - 1]['c']));
    $a = array_slice($trs, -$p);
    $atr = array_sum($a) / count($a);
    $price = end($cn)['c'];
    return $price > 0 ? round($atr / $price * 100, 4) : 0.0;
}
function volRatioLast($cn) {
    $vols = array_column($cn, 'v');
    $last = end($vols);
    $avg  = array_sum(array_slice($vols, -20)) / 20;
    return $avg > 0 ? round($last / $avg, 2) : 1.0;
}
function bbWidth($cn, $p = 20) {
    $cl = array_column($cn, 'c'); $n = count($cl);
    if ($n < $p) return 0.0;
    $slice = array_slice($cl, -$p);
    $avg = array_sum($slice) / $p;
    $std = 0.0;
    foreach ($slice as $v) { $std += ($v - $avg) ** 2; }
    $std = sqrt($std / $p);
    $last = end($cl);
    return $last > 0 ? round($std * 4 / $last * 100, 4) : 0.0;
}
function stochLast($cn, $p = 14) {
    $n = count($cn); if ($n < $p) return 50.0;
    $slice = array_slice($cn, -$p);
    $hh = max(array_column($slice, 'h'));
    $ll = min(array_column($slice, 'l'));
    $lastClose = end(array_column($slice, 'c'));
    return ($hh - $ll == 0) ? 50.0 : ($lastClose - $ll) / ($hh - $ll) * 100;
}
function multiTFMomentum($cl) {
    $n = count($cl);
    $m1 = $n >= 2 ? ($cl[$n-1] - $cl[$n-2]) / $cl[$n-2] * 100 : 0;
    $m3 = $n >= 4 ? ($cl[$n-1] - $cl[$n-4]) / $cl[$n-4] * 100 : 0;
    $m6 = $n >= 7 ? ($cl[$n-1] - $cl[$n-7]) / $cl[$n-7] * 100 : 0;
    $agree = ($m1 > 0 && $m3 > 0 && $m6 > 0) ? 'UP'
           : (($m1 < 0 && $m3 < 0 && $m6 < 0) ? 'DOWN' : 'MIX');
    return ['m1' => $m1, 'm3' => $m3, 'm6' => $m6, 'agree' => $agree];
}

// ════════════════════════════════════════════════════════
// 8. BYBIT FUTURES API
// ════════════════════════════════════════════════════════
class BybitFutures {
    private $key, $secret, $base, $pub;
    private $fc = [], $levMem = [];
    public function __construct($key, $secret, $testnet) {
        $this->key    = $key;
        $this->secret = $secret;
        $this->base = $testnet ? 'https://api-demo.bybit.com' : 'https://api.bybit.com';
        $this->pub  = 'https://api.bybit.com';
        lI("[Bybit] " . ($testnet ? 'DEMO/TESTNET' : 'MAINNET') . " priv=" . $this->base . " pub=" . $this->pub);
    }
    private function ts() { return (string)(intval(microtime(true) * 1000)); }
    private function signGet($params) {
        $ts = $this->ts(); $recv = '8000'; ksort($params);
        $str = $ts . $this->key . $recv . http_build_query($params);
        return ['X-BAPI-API-KEY' => $this->key, 'X-BAPI-TIMESTAMP' => $ts,
                'X-BAPI-RECV-WINDOW' => $recv,
                'X-BAPI-SIGN' => hash_hmac('sha256', $str, $this->secret)];
    }
    private function signPost($body) {
        $ts = $this->ts(); $recv = '8000';
        $str = $ts . $this->key . $recv . $body;
        return ['Content-Type' => 'application/json', 'X-BAPI-API-KEY' => $this->key,
                'X-BAPI-TIMESTAMP' => $ts, 'X-BAPI-RECV-WINDOW' => $recv,
                'X-BAPI-SIGN' => hash_hmac('sha256', $str, $this->secret)];
    }
    private function get($path, $params = [], $retry = 2) {
        ksort($params);
        for ($a = 0; $a <= $retry; $a++) {
            $hdrs = $this->signGet($params);
            $url  = $this->base . $path . '?' . http_build_query($params);
            $ch   = curl_init($url);
            $headersArr = [];
            foreach ($hdrs as $k => $v) { $headersArr[] = "$k: $v"; }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'EthGridBot/15.4',
                CURLOPT_HTTPHEADER => $headersArr,
            ]);
            $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
            if ($resp === false) { if ($a < $retry) { usleep(600000); continue; } throw new RuntimeException("GET $path: $err"); }
            $d = json_decode((string)$resp, true); $rc = isset($d['retCode']) ? $d['retCode'] : -1;
            if ($rc === 0) return isset($d['result']) ? $d['result'] : [];
            if (in_array($rc, [10002, 10006]) && $a < $retry) { sleep(1); continue; }
            throw new RuntimeException("Bybit GET [{$rc}]: " . (isset($d['retMsg']) ? $d['retMsg'] : $resp));
        }
        throw new RuntimeException("GET $path: agotados reintentos");
    }
    private function post($path, $params, $retry = 2) {
        $body = json_encode($params);
        for ($a = 0; $a <= $retry; $a++) {
            $hdrs = $this->signPost($body);
            $headersArr = [];
            foreach ($hdrs as $k => $v) { $headersArr[] = "$k: $v"; }
            $r = hPost($this->base . $path, $body, $headersArr);
            $d = $r['body']; $rc = isset($d['retCode']) ? $d['retCode'] : -1;
            if ($rc === 0) return isset($d['result']) ? $d['result'] : [];
            if (in_array($rc, [10002, 10006, 110007]) && $a < $retry) { sleep(1); continue; }
            throw new RuntimeException("Bybit POST [{$rc}]: " . (isset($d['retMsg']) ? $d['retMsg'] : json_encode($d)));
        }
        throw new RuntimeException("POST $path: agotados reintentos");
    }
    public function validate() {
        $r = $this->get('/v5/account/wallet-balance', ['accountType' => 'UNIFIED']);
        lI("[Bybit] API OK – cuenta UNIFIED"); return $r;
    }
    private function getPub($path, $params = [], $retry = 2) {
        ksort($params);
        $url = $this->pub . $path . '?' . http_build_query($params);
        for ($a = 0; $a <= $retry; $a++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'EthGridBot/15.4',
            ]);
            $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
            if ($resp === false) { if ($a < $retry) { usleep(500000); continue; } throw new RuntimeException("getPub $path: $err"); }
            $d = json_decode((string)$resp, true); $rc = isset($d['retCode']) ? $d['retCode'] : -1;
            if ($rc === 0) return isset($d['result']) ? $d['result'] : [];
            if ($a < $retry) { usleep(400000); continue; }
            throw new RuntimeException("Bybit PUB [{$rc}]: " . (isset($d['retMsg']) ? $d['retMsg'] : ''));
        }
        return [];
    }
    public function price($symbol) {
        try {
            $r = $this->getPub('/v5/market/tickers', ['category' => 'linear', 'symbol' => $symbol]);
            $px = (float)(isset($r['list'][0]['lastPrice']) ? $r['list'][0]['lastPrice'] : 0);
            if ($px > 0) return $px;
        } catch (Exception $e) { lW("[Bybit] price (pub): " . $e->getMessage()); }
        $r = $this->get('/v5/market/tickers', ['category' => 'linear', 'symbol' => $symbol]);
        return (float)(isset($r['list'][0]['lastPrice']) ? $r['list'][0]['lastPrice'] : 0);
    }
    public function klines($symbol, $interval, $limit) {
        $bybitSymbol = strtoupper($symbol);
        $bybitIv     = $interval . 'm';
        if (in_array($interval, ['60','120','240','360','720'])) {
            $hrs = (int)($interval) / 60;
            $bybitIv = $hrs . 'h';
        } elseif ($interval === '1D' || $interval === 'D') {
            $bybitIv = '1d';
        }
        $sources = [
            ['url' => "https://api.bybit.com/v5/market/kline?category=linear&symbol={$bybitSymbol}&interval={$interval}&limit={$limit}", 'parser' => 'bybit', 'tag' => 'bybit-mainnet'],
        ];
        foreach ($sources as $src) {
            try {
                $ch = curl_init($src['url']);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
                    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_USERAGENT      => 'EthGridBot/15.4',
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                ]);
                $resp = curl_exec($ch); $curlErr = curl_error($ch); curl_close($ch);
                if ($resp === false || strlen($resp) < 10) {
                    lW(sprintf("[klines] %s curl: %s", $src['tag'], $curlErr));
                    continue;
                }
                $data = json_decode($resp, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    lW(sprintf("[klines] %s JSON inválido", $src['tag']));
                    continue;
                }
                $out = [];
                if ($src['parser'] === 'bybit') {
                    $rc = isset($data['retCode']) ? $data['retCode'] : -1;
                    if ($rc !== 0) {
                        lW(sprintf("[klines] %s retCode=%d", $src['tag'], $rc));
                        continue;
                    }
                    $list = isset($data['result']['list']) ? $data['result']['list'] : [];
                    if (empty($list)) {
                        lW(sprintf("[klines] %s retCode=0 lista vacía (demo sin datos históricos)", $src['tag']));
                        continue;
                    }
                    foreach (array_reverse($list) as $k)
                        $out[] = [(int)$k[0], (float)$k[1], (float)$k[2], (float)$k[3], (float)$k[4], (float)$k[5]];
                }
                if (count($out) >= 30) {
                    lI(sprintf("[klines] ✓ %d velas [%s]", count($out), $src['tag']));
                    return $out;
                }
                lW(sprintf("[klines] %s: solo %d velas (< 30)", $src['tag'], count($out)));
            } catch (Exception $e) {
                lW(sprintf("[klines] %s ex: %s", $src['tag'], $e->getMessage()));
            }
        }
        lE("[klines] TODAS las fuentes fallaron. Sin datos de velas.");
        return [];
    }
    public function filters($symbol) {
        if (!isset($this->fc[$symbol])) {
            try {
                $r = $this->getPub('/v5/market/instruments-info', ['category' => 'linear', 'symbol' => $symbol]);
                $info = isset($r['list'][0]) ? $r['list'][0] : [];
                $lot = isset($info['lotSizeFilter']) ? $info['lotSizeFilter'] : [];
                $prx = isset($info['priceFilter']) ? $info['priceFilter'] : [];
                $step = (float)(isset($lot['qtyStep']) ? $lot['qtyStep'] : 0.01);
                $tick = (float)(isset($prx['tickSize']) ? $prx['tickSize'] : 0.01);
                $this->fc[$symbol] = ['step' => $step, 'tick' => $tick,
                    'mn' => (float)(isset($lot['minOrderQty']) ? $lot['minOrderQty'] : 0.01),
                    'qp' => max(0, (int)round(-log10(max($step, 1e-8)))),
                    'pp' => max(0, (int)round(-log10(max($tick, 1e-8))))];
            } catch (Exception $e) {
                $this->fc[$symbol] = ['step' => 0.01, 'tick' => 0.01, 'mn' => 0.01, 'qp' => 2, 'pp' => 2];
            }
        }
        return $this->fc[$symbol];
    }
    public function balance() {
        try {
            $r = $this->get('/v5/account/wallet-balance', ['accountType' => 'UNIFIED']);
            foreach (isset($r['list']) ? $r['list'] : [] as $acc) {
                $accAvail = (float)(isset($acc['totalAvailableBalance']) ? $acc['totalAvailableBalance'] : 0);
                if ($accAvail > 0) return $accAvail;
                foreach (isset($acc['coin']) ? $acc['coin'] : [] as $c) {
                    if (($c['coin'] ?? '') !== 'USDT') continue;
                    foreach (['availableToWithdraw','availableBalance','walletBalance','equity'] as $fld) {
                        $v = (float)(isset($c[$fld]) ? $c[$fld] : 0); if ($v > 0) return $v;
                    }
                }
                $eq = (float)(isset($acc['totalEquity']) ? $acc['totalEquity'] : 0);
                if ($eq > 0) return $eq;
            }
            lW("[Bybit] Balance 0 — sin saldo USDT libre"); return 0.0;
        } catch (Exception $e) { lW("[Bybit] Error balance: " . $e->getMessage()); return 0.0; }
    }
    public function positions($symbol) {
        $r = $this->get('/v5/position/list', ['category' => 'linear', 'symbol' => $symbol]);
        $out = [];
        foreach (isset($r['list']) ? $r['list'] : [] as $p) {
            $sz = (float)(isset($p['size']) ? $p['size'] : 0); if ($sz < 0.001) continue;
            $out[] = ['positionAmt' => (isset($p['side']) && $p['side'] === 'Buy') ? $sz : -$sz,
                      'entryPrice' => (float)(isset($p['avgPrice']) ? $p['avgPrice'] : 0),
                      'unRealizedProfit' => (float)(isset($p['unrealisedPnl']) ? $p['unrealisedPnl'] : 0),
                      'liquidationPrice' => (float)(isset($p['liqPrice']) ? $p['liqPrice'] : 0),
                      'side' => isset($p['side']) ? $p['side'] : '', 'size' => $sz];
        }
        return $out;
    }
    public function setLeverage($symbol, $lev) {
        if (isset($this->levMem[$symbol]) && $this->levMem[$symbol] === $lev) return;
        try {
            $this->post('/v5/position/set-leverage', ['category' => 'linear', 'symbol' => $symbol,
                'buyLeverage' => (string)$lev, 'sellLeverage' => (string)$lev]);
            $this->levMem[$symbol] = $lev;
            lI("[Bybit] Leverage {$lev}x OK");
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'leverage not modified') !== false) $this->levMem[$symbol] = $lev;
            else lW("[Bybit] setLeverage: " . $e->getMessage());
        }
    }
    public function limitOrder($symbol, $side, $qty, $price, $reduceOnly = false, $postOnly = true) {
        $f = $this->filters($symbol);
        $qty = max($f['step'], round($qty / $f['step']) * $f['step']);
        $pr  = round($price / $f['tick']) * $f['tick'];
        $r = $this->post('/v5/order/create', [
            'category' => 'linear', 'symbol' => $symbol, 'side' => ucfirst(strtolower($side)),
            'orderType' => 'Limit', 'qty' => number_format($qty, $f['qp'], '.', ''),
            'price' => number_format($pr, $f['pp'], '.', ''),
            'timeInForce' => $postOnly ? 'PostOnly' : 'GTC', 'reduceOnly' => $reduceOnly,
            'orderLinkId' => uniqid('g154_', true),
        ]);
        return ['orderId' => $r['orderId'], 'price' => $pr, 'qty' => $qty];
    }
    public function marketClose($symbol, $side, $qty) {
        $f = $this->filters($symbol);
        $qty = max($f['step'], round($qty / $f['step']) * $f['step']);
        $cside = $side === 'Buy' ? 'Sell' : 'Buy';
        $r = $this->post('/v5/order/create', [
            'category' => 'linear', 'symbol' => $symbol, 'side' => $cside,
            'orderType' => 'Market', 'qty' => number_format($qty, $f['qp'], '.', ''),
            'timeInForce' => 'IOC', 'reduceOnly' => true, 'orderLinkId' => uniqid('mc_', true),
        ]);
        return isset($r['orderId']) ? $r['orderId'] : null;
    }
    public function getOrder($symbol, $orderId) {
        $map = ['New' => 'NEW', 'PartiallyFilled' => 'PARTIALLY_FILLED', 'Filled' => 'FILLED',
                'Cancelled' => 'CANCELED', 'Rejected' => 'CANCELED', 'Expired' => 'CANCELED'];
        try {
            $r = $this->get('/v5/order/realtime', ['category' => 'linear', 'symbol' => $symbol, 'orderId' => $orderId]);
            if (!empty($r['list'])) {
                $o = $r['list'][0];
                return ['status' => isset($map[$o['orderStatus']]) ? $map[$o['orderStatus']] : 'UNKNOWN',
                        'avgPrice' => (float)(isset($o['avgPrice']) ? $o['avgPrice'] : (isset($o['price']) ? $o['price'] : 0)),
                        'qty' => (float)(isset($o['cumExecQty']) ? $o['cumExecQty'] : (isset($o['qty']) ? $o['qty'] : 0))];
            }
        } catch (Exception $e) {}
        try {
            $r = $this->get('/v5/order/history', ['category' => 'linear', 'symbol' => $symbol,
                                                   'orderId' => $orderId, 'limit' => 1]);
            if (!empty($r['list'])) {
                $o = $r['list'][0];
                return ['status' => isset($map[$o['orderStatus']]) ? $map[$o['orderStatus']] : 'UNKNOWN',
                        'avgPrice' => (float)(isset($o['avgPrice']) ? $o['avgPrice'] : (isset($o['price']) ? $o['price'] : 0)),
                        'qty' => (float)(isset($o['cumExecQty']) ? $o['cumExecQty'] : (isset($o['qty']) ? $o['qty'] : 0))];
            }
        } catch (Exception $e) {}
        return ['status' => 'UNKNOWN', 'avgPrice' => 0, 'qty' => 0];
    }
    public function getOpenOrders($symbol) {
        try {
            $r = $this->get('/v5/order/realtime', ['category' => 'linear', 'symbol' => $symbol,
                                                     'limit' => 50, 'orderFilter' => 'Order']);
            $orders = [];
            foreach (isset($r['list']) ? $r['list'] : [] as $o) {
                $orders[$o['orderId']] = ['orderId' => $o['orderId'], 'price' => (float)(isset($o['price']) ? $o['price'] : 0),
                    'qty' => (float)(isset($o['qty']) ? $o['qty'] : 0), 'side' => isset($o['side']) ? $o['side'] : '',
                    'status' => isset($o['orderStatus']) ? $o['orderStatus'] : '', 'avgPrice' => (float)(isset($o['avgPrice']) ? $o['avgPrice'] : (isset($o['price']) ? $o['price'] : 0)),
                    'cumExecQty' => (float)(isset($o['cumExecQty']) ? $o['cumExecQty'] : (isset($o['qty']) ? $o['qty'] : 0))];
            }
            return $orders;
        } catch (Exception $e) {
            lW("[Bybit] getOpenOrders: " . $e->getMessage()); return [];
        }
    }
    public function cancelAll($symbol) {
        try {
            $this->post('/v5/order/cancel-all', ['category' => 'linear', 'symbol' => $symbol]);
            sleep(1); lI("[Bybit] cancelAll $symbol OK");
        } catch (Exception $e) { lW("[Bybit] cancelAll: " . $e->getMessage()); }
    }
}

// ════════════════════════════════════════════════════════
// 9. ML LOCAL (CON FILTRO DE ACCURACY)
// ════════════════════════════════════════════════════════
class GridML {
    private $wf;
    private $weights = [], $intercepts = [];
    private $scalerMean = null, $scalerScale = null;
    private $featureNames = [];
    private $classes = ['DOWN', 'SIDEWAYS', 'UP'];
    private $lastMtime = 0;
    private $accuracy = 0.0;
    private $loadedOk = false; // indica si hay pesos cargados válidos

    public function __construct($wf) { $this->wf = $wf; $this->load(); }
    public function getAccuracy() { return $this->accuracy; }
    public function reloadIfUpdated() {
        $paths = [dirname(__FILE__) . '/' . basename($this->wf), $this->wf];
        foreach ($paths as $f) {
            if (!file_exists($f)) continue;
            $mtime = (int)filemtime($f);
            if ($mtime > $this->lastMtime) {
                $this->load();
                lI("[ML] Pesos actualizados desde disco (mtime=" . date('H:i:s', $mtime) . " acc={$this->accuracy})");
                return true;
            }
        }
        return false;
    }
    private function load() {
        $paths = [dirname(__FILE__) . '/' . basename($this->wf), $this->wf];
        foreach ($paths as $f) {
            if (!file_exists($f)) continue;
            $d = json_decode(file_get_contents($f), true);
            if (!is_array($d)) continue;
            $acc = (float)(isset($d['acc']) ? $d['acc'] : 0);
            // Filtro: solo aceptar pesos con accuracy >= G_ML_MIN_ACCURACY (0.85)
            if ($acc < G_ML_MIN_ACCURACY) {
                lW("[ML] Archivo $f tiene accuracy " . round($acc*100,1) . "% < " . (G_ML_MIN_ACCURACY*100) . "% → IGNORADO. Manteniendo modelo anterior.");
                continue;
            }
            $this->weights      = isset($d['weights']) ? $d['weights'] : [];
            $this->intercepts   = isset($d['intercepts']) ? $d['intercepts'] : [0,0,0];
            $this->scalerMean   = isset($d['scaler_mean']) ? $d['scaler_mean'] : null;
            $this->scalerScale  = isset($d['scaler_scale']) ? $d['scaler_scale'] : null;
            $this->classes      = isset($d['classes']) ? $d['classes'] : ['DOWN','SIDEWAYS','UP'];
            $this->featureNames = array_keys($this->weights);
            $this->accuracy     = $acc;
            $this->lastMtime    = (int)filemtime($f);
            $this->loadedOk     = true;
            lI(sprintf("[ML] Cargado: %s | acc=%.1f%% | features=%d | updated=%s",
                basename($f), $this->accuracy * 100, count($this->featureNames), isset($d['updated_at']) ? $d['updated_at'] : '?'));
            return;
        }
        if (!$this->loadedOk) {
            lW("[ML] Sin archivo de pesos válido (accuracy < 85% o inexistente) — usando fallback RSI");
            $this->weights = [];
            $this->accuracy = 0;
        }
    }
    private function buildFeatures($candles, $price) {
        $cl = array_column($candles, 'c');
        $features = [];
        foreach ($this->featureNames as $feat) {
            switch ($feat) {
                case 'rsi_14':         $features[$feat] = rsiLast($cl); break;
                case 'stoch_14':       $features[$feat] = stochLast($candles); break;
                case 'macd_hist':      $features[$feat] = macdHistLast($cl); break;
                case 'ema_diff_9_21':
                    $e9 = ema($cl, 9); $e21 = ema($cl, 21);
                    $e9l = end($e9); $e21l = end($e21);
                    $features[$feat] = ($e9l && $e21l && $price > 0) ? (($e9l - $e21l) / $price) : 0;
                    break;
                case 'vol_ratio':      $features[$feat] = volRatioLast($candles); break;
                case 'bb_width':       $features[$feat] = bbWidth($candles); break;
                case 'atr_pct':        $features[$feat] = atrPctLast($candles); break;
                case 'vwap_ratio':
                    $vols = array_column($candles, 'v');
                    $cumTV = 0; $cumV = 0;
                    for ($i = 0; $i < count($candles); $i++) {
                        $cc = $candles[$i];
                        $typ = ($cc['h'] + $cc['l'] + $cc['c']) / 3;
                        $cumTV += $typ * $vols[$i]; $cumV += $vols[$i];
                    }
                    $vwap = $cumV > 0 ? $cumTV / $cumV : $price;
                    $features[$feat] = $vwap > 0 ? $price / $vwap : 1;
                    break;
                case 'spread_pct':
                    $last = end($candles);
                    $features[$feat] = ($last['h'] - $last['l']) / $last['c'] * 100;
                    break;
                case 'momentum_5':
                    if (count($cl) >= 6) {
                        $prev = $cl[count($cl) - 6]; $curr = end($cl);
                        $features[$feat] = ($curr - $prev) / $prev * 100;
                    } else { $features[$feat] = 0; }
                    break;
                default: $features[$feat] = 0;
            }
        }
        if ($this->scalerMean !== null && $this->scalerScale !== null) {
            $i = 0;
            foreach ($this->featureNames as $fn) {
                $val = $features[$fn];
                $sc  = (float)(isset($this->scalerScale[$i]) ? $this->scalerScale[$i] : 1); if ($sc == 0) $sc = 1;
                $scaled = ($val - (float)(isset($this->scalerMean[$i]) ? $this->scalerMean[$i] : 0)) / $sc;
                $features[$fn] = max(-3.0, min(3.0, $scaled));
                $i++;
            }
        }
        return $features;
    }
    private function softmax($s) {
        $mx = max($s);
        $ex = array_map(function($v) use ($mx) { return exp($v - $mx); }, $s);
        $sum = array_sum($ex);
        return array_map(function($e) use ($sum) { return $e / $sum; }, $ex);
    }
    public function predict($candles) {
        if (empty($this->weights)) return $this->fallback($candles);
        try {
            $cl = array_column($candles, 'c'); $price = end($cl);
            $feats = $this->buildFeatures($candles, $price);
            $scores = [];
            foreach ($this->classes as $i => $cls) {
                $score = (float)(isset($this->intercepts[$i]) ? $this->intercepts[$i] : 0);
                foreach ($this->featureNames as $fn) {
                    if (isset($this->weights[$fn][$cls])) $score += $feats[$fn] * (float)$this->weights[$fn][$cls];
                }
                $scores[] = $score;
            }
            $probs  = $this->softmax($scores);
            $maxIdx = (int)array_search(max($probs), $probs);
            $dir    = $this->classes[$maxIdx];
            $conf   = (int)round($probs[$maxIdx] * 100);
            lI(sprintf("[ML] %s %d%% (D=%.0f%% S=%.0f%% U=%.0f%%) acc=%.1f%%",
                $dir, $conf, $probs[0]*100, $probs[1]*100, $probs[2]*100, $this->accuracy*100));
            return ['direction' => $dir, 'confidence' => $conf, 'probs' => $probs, 'reason' => "ML:{$dir}({$conf}%)"];
        } catch (Exception $e) {
            lW("[ML] " . $e->getMessage()); return $this->fallback($candles);
        }
    }
    private function fallback($c) {
        $rsi = rsiLast(array_column($c, 'c'));
        $dir = $rsi > 58 ? 'UP' : ($rsi < 42 ? 'DOWN' : 'SIDEWAYS');
        return ['direction' => $dir, 'confidence' => 35, 'probs' => [0.33, 0.34, 0.33],
                'reason' => "ML-fallback RSI=" . round($rsi, 1)];
    }
}

// ════════════════════════════════════════════════════════
// 10. IA ESTRATÉGICA (respaldo)
// ════════════════════════════════════════════════════════
class GridAI {
    public function getStrategy($candles) {
        $cl  = array_column($candles, 'c');
        $rsi = rsiLast($cl);
        $dir = $rsi > 58 ? 'UP' : ($rsi < 42 ? 'DOWN' : 'SIDEWAYS');
        return ['direction' => $dir, 'confidence' => 50, 'levels' => G_FIXED_LEVELS,
                'spacing_pct' => G_BASE_SPACING, 'long_pct' => 0.5,
                'reason' => "Heurístico RSI=" . round($rsi, 1)];
    }
}

// ════════════════════════════════════════════════════════
// 11. ANÁLISIS CON NVIDIA VL
// ════════════════════════════════════════════════════════
function analyzeChartWithVL($imagePath, $apiKey) {
    if (!file_exists($imagePath)) return null;
    $imageData = base64_encode(file_get_contents($imagePath));
    $prompt = "Analiza este gráfico de velas de ETH/USDT en timeframe 5 minutos. 
    Identifica: tendencia principal, niveles de soporte/resistencia visibles, 
    patrones de velas. Responde SOLO con un JSON válido:
    {\"direction\":\"UP/DOWN/SIDEWAYS\", \"confidence\":0-100, 
     \"reason\":\"breve razón\", \"volatility\":\"low/medium/high\"}";
    
    $url = "https://integrate.api.nvidia.com/v1/chat/completions";
    $payload = [
        "model" => "nvidia/llama-3.1-nemotron-nano-vl-8b-v1",
        "messages" => [[
            "role" => "user",
            "content" => [
                ["type" => "text", "text" => $prompt],
                ["type" => "image_url", "image_url" => ["url" => "data:image/png;base64,{$imageData}"]]
            ]
        ]],
        "temperature" => 0.2,
        "max_tokens" => 300,
        "stream" => false
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ],
        CURLOPT_TIMEOUT => 20
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        lE("[VL] Error HTTP $httpCode: $resp");
        return null;
    }
    $data = json_decode($resp, true);
    $content = isset($data['choices'][0]['message']['content']) ? $data['choices'][0]['message']['content'] : '';
    if (preg_match('/\{.*\}/s', $content, $matches)) {
        return json_decode($matches[0], true);
    }
    return null;
}

// ════════════════════════════════════════════════════════
// 12. GRID MANAGER (VERSIÓN CORREGIDA CON MAYOR TIEMPO DE CONFIRMACIÓN)
// ════════════════════════════════════════════════════════
class GridManager {
    private $api;
    private $ai;
    private $ml;
    private $running = true;
    private $cfg = null;
    private $lastAI = 0;
    private $lastVL = 0;
    private $gridBuilt = false;
    private $cycleN = 0;
    private $peakPnl = 0.0;
    private $lastCompound = 0;
    private $lastGridBuild = 0;
    private $mlReloadCycle = 0;
    private $volReloadCycle = 0;
    
    private $last_atr_predicho = null;
    private $last_vl_result = null;
    
    private $volWeights = null;
    private $volScalerMean = null;
    private $volScalerScale = null;
    private $volIntercept = 0.0;
    private $volMtime = 0;
    private $volFile = null;
    private $volClipLower = 0.05;
    private $volClipUpper = 1.5;

    private $lastDirection = null;
    private $directionChangeCount = 0;

    public function __construct($api, $ai, $ml) {
        $this->api = $api;
        $this->ai = $ai;
        $this->ml = $ml;
        $this->volFile = __DIR__ . '/volatility_weights_ridge.json';
        $this->loadVolatilityModel();
    }

    private function loadVolatilityModel() {
        $ridgeFile = __DIR__ . '/volatility_weights_ridge.json';
        $linearFile = __DIR__ . '/volatility_weights.json';
        $chosen = null;
        if (file_exists($ridgeFile)) $chosen = $ridgeFile;
        elseif (file_exists($linearFile)) $chosen = $linearFile;
        if (!$chosen) {
            lW("[Vol] Sin modelo de volatilidad. Usando ATR actual.");
            return;
        }
        $data = json_decode(file_get_contents($chosen), true);
        if (!is_array($data) || !isset($data['weights']) || !isset($data['scaler_mean'])) {
            lW("[Vol] Archivo de modelo inválido: $chosen");
            return;
        }
        $this->volWeights     = $data['weights'];
        $this->volIntercept   = (float)($data['intercept'] ?? 0.0);
        $this->volScalerMean  = $data['scaler_mean'];
        $this->volScalerScale = $data['scaler_scale'];
        $this->volMtime       = filemtime($chosen);
        if (isset($data['prediction_clip_lower'])) {
            $this->volClipLower = (float)$data['prediction_clip_lower'];
            $this->volClipUpper = (float)$data['prediction_clip_upper'];
            lI(sprintf("[Vol] Modelo cargado: %s | MAE=%.3f%% R²=%.2f | clip=[%.3f%%, %.3f%%]",
                basename($chosen), $data['mae'] ?? 0, $data['r2'] ?? 0,
                $this->volClipLower, $this->volClipUpper));
        } else {
            lI(sprintf("[Vol] Modelo cargado: %s | MAE=%.3f%% R²=%.2f",
                basename($chosen), $data['mae'] ?? 0, $data['r2'] ?? 0));
        }
    }

    private function reloadVolatilityIfUpdated() {
        if (!file_exists($this->volFile)) return;
        $mtime = filemtime($this->volFile);
        if ($mtime > $this->volMtime) {
            lI("[Vol] Detectada actualización de volatility_weights_ridge.json, recargando...");
            $this->loadVolatilityModel();
        }
    }

    private function predictFutureATR($candles) {
        if ($this->volWeights === null || $this->volScalerMean === null) return null;
        if (count($candles) < 30) return null;
        
        $last = end($candles);
        $price = $last['c'];
        $closes = array_column($candles, 'c');
        
        $features = [];
        $features['rsi_14'] = rsiLast($closes);
        $features['stoch_14'] = stochLast($candles);
        $features['macd_hist'] = macdHistLast($closes);
        
        $e9 = ema($closes, 9);
        $e21 = ema($closes, 21);
        $e9l = end($e9);
        $e21l = end($e21);
        $features['ema_diff_9_21'] = ($e9l && $e21l && $price > 0) ? (($e9l - $e21l) / $price) : 0;
        
        $features['vol_ratio'] = volRatioLast($candles);
        $features['bb_width'] = bbWidth($candles);
        $features['atr_pct'] = atrPctLast($candles);
        
        $vols = array_column($candles, 'v');
        $cumTV = 0; $cumV = 0;
        foreach ($candles as $i => $cc) {
            $typ = ($cc['h'] + $cc['l'] + $cc['c']) / 3;
            $cumTV += $typ * $vols[$i];
            $cumV += $vols[$i];
        }
        $vwap = $cumV > 0 ? $cumTV / $cumV : $price;
        $features['vwap_ratio'] = $vwap > 0 ? $price / $vwap : 1;
        
        $features['spread_pct'] = ($last['h'] - $last['l']) / $last['c'] * 100;
        
        if (count($closes) >= 6) {
            $prev = $closes[count($closes) - 6];
            $curr = end($closes);
            $features['momentum_5'] = ($curr - $prev) / $prev * 100;
        } else {
            $features['momentum_5'] = 0;
        }
        
        $featOrder = ['rsi_14', 'stoch_14', 'macd_hist', 'ema_diff_9_21', 
                      'vol_ratio', 'bb_width', 'atr_pct', 'vwap_ratio', 
                      'spread_pct', 'momentum_5'];
        
        $scaled = [];
        for ($i = 0; $i < count($featOrder); $i++) {
            $feat = $featOrder[$i];
            $val = $features[$feat];
            $mean = isset($this->volScalerMean[$i]) ? (float)$this->volScalerMean[$i] : 0;
            $scale = isset($this->volScalerScale[$i]) ? (float)$this->volScalerScale[$i] : 1;
            if ($scale == 0) $scale = 1;
            $scaled[] = ($val - $mean) / $scale;
        }
        
        $pred = $this->volIntercept;
        for ($i = 0; $i < count($featOrder); $i++) {
            $feat = $featOrder[$i];
            if (isset($this->volWeights[$feat])) {
                $pred += $scaled[$i] * (float)$this->volWeights[$feat];
            }
        }
        
        $atr_actual = $features['atr_pct'];
        $pred_original = $pred;
        
        if ($pred_original < 0) {
            lW(sprintf("[Vol] Pred negativa (%.4f) — modelo fuera de rango, usando ATR actual %.2f%%", $pred_original, $atr_actual));
            return null;
        }
        
        $pred = max($this->volClipLower, min($this->volClipUpper, $pred));
        
        if ($atr_actual > 0.01) {
            $ratio = $pred / $atr_actual;
            if ($ratio < 0.5) {
                $alpha = 0.4;
                $pred_adj = $alpha * $pred + (1 - $alpha) * $atr_actual;
                lW(sprintf("[Vol] Pred baja (ratio %.2f), ajuste: %.2f%% → %.2f%%", $ratio, $pred, $pred_adj));
                $pred = $pred_adj;
            } elseif ($ratio > 3.0) {
                $pred = 0.65 * $atr_actual + 0.35 * $pred;
                if ($ratio > 5.0) lW(sprintf("[Vol] Pred muy alta (ratio %.2f), blend suave: %.4f%%", $ratio, $pred));
                else lI(sprintf("[Vol] Pred alta (ratio %.2f), blend suave: %.4f%%", $ratio, $pred));
            }
        }
        
        lI(sprintf("[Vol] ATR actual=%.2f%% → predicho=%.2f%% (original=%.2f%%)", $atr_actual, $pred, $pred_original));
        return $pred;
    }

    public function run() {
        lI("╔══════════════════════════════════════════╗");
        lI("║  ETH/USDT Grid Bot v15.4 – FINAL        ║");
        lI(sprintf("║  Capital: %.0f USDT  AI: %ds  PID: %d",
            G_CAPITAL, G_AI_INTERVAL, getmypid()) . str_repeat(' ', 10) . "║");
        lI("╚══════════════════════════════════════════╝");

        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                $this->api->validate();
                $this->api->setLeverage(G_SYM, G_LEVERAGE);
                lI("[INIT] Conexión exitosa"); break;
            } catch (Exception $e) {
                lW("[INIT] Intento " . ($attempt + 1) . "/10: " . $e->getMessage());
                if ($attempt >= 9) { lE("[INIT] Sin conexión."); return; }
                sleep(30);
            }
        }

        $balance = $this->api->balance();
        if ($balance <= 0) { lW("[INIT] Saldo 0, usando capital teórico: " . G_CAPITAL); $balance = G_CAPITAL; }
        else { lI("[INIT] Saldo disponible: {$balance} USDT"); }

        $this->loadConfig();
        $this->cleanupSession();
        $this->syncPositions();
        $this->peakPnl = 0.0;
        dbx(function($d) { return $d->prepare("UPDATE grid_configs SET peak_pnl_today=0, paused_reason=NULL WHERE symbol=?")->execute([G_SYM]); });
        lI("[INIT] Estado inicial reseteado. Entrando al loop principal...");

        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
            $this->checkControl();
            $this->cycleN++;

            $this->mlReloadCycle++;
            if ($this->mlReloadCycle >= G_ML_RELOAD_CYCLES) {
                $this->mlReloadCycle = 0;
                $this->ml->reloadIfUpdated();
            }

            $this->volReloadCycle++;
            if ($this->volReloadCycle >= G_VOL_RELOAD_CYCLES) {
                $this->volReloadCycle = 0;
                $this->reloadVolatilityIfUpdated();
            }

            try {
                $price = $this->api->price(G_SYM);
                if ($price <= 0) { lW("[MAIN] Precio 0"); sleep(G_CYCLE_SEC); continue; }
                $adaptiveInterval = G_AI_INTERVAL;
                $conf = (int)(isset($this->cfg['confidence']) ? $this->cfg['confidence'] : 50);
                if ($conf >= 85) $adaptiveInterval = G_AI_INTERVAL * 2;
                elseif ($conf < 50) $adaptiveInterval = max(60, G_AI_INTERVAL / 2);
                if (time() - $this->lastAI >= $adaptiveInterval) $this->aiEvaluate($price);
                if (!$this->gridBuilt) $this->buildGrid($price);
                elseif ($this->gridBuilt) {
                    $openCnt = dbx(function($d) {
                        return (int)$d->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='" . G_SYM . "' AND status='OPEN'")->fetchColumn();
                    }) ?? 0;
                    if ($openCnt < (G_FIXED_LEVELS - 3)) {
                        lW("[MAIN] Solo $openCnt órdenes abiertas (mín " . (G_FIXED_LEVELS - 3) . ") → rebuild");
                        $this->gridBuilt = false; $this->lastGridBuild = 0;
                    }
                }
                $this->checkFills($price);
                $this->riskCheck($price);
                $this->profitOptimize($price);
                $this->breakoutCheck($price);
                if ($this->cycleN % 5 === 0) $this->writeStatus($price);
                if ($this->cycleN % 10 === 0) $this->logCycleSummary($price);
            } catch (Exception $e) { lE("[MAIN] " . $e->getMessage()); }
            sleep(G_CYCLE_SEC);
        }
        lI("[MAIN] Bot detenido limpiamente.");
    }

    private function loadConfig() {
        $row = dbx(function($d) { return $d->query("SELECT * FROM grid_configs WHERE symbol='" . G_SYM . "' AND status='ACTIVE' LIMIT 1")->fetch(); });
        if (!$row) {
            dbx(function($d) { return $d->prepare("INSERT INTO grid_configs (symbol,direction,confidence,capital_usd,leverage,levels,spacing_pct,long_levels,short_levels,qty_per_level,pp,qp,mode) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status='ACTIVE'")
                ->execute([G_SYM, 'SIDEWAYS', 50, G_CAPITAL, G_LEVERAGE, G_FIXED_LEVELS,
                           G_BASE_SPACING, G_LONG_LEVELS, G_SHORT_LEVELS, 0, 2, 2, 'NORMAL']); });
            $row = dbx(function($d) { return $d->query("SELECT * FROM grid_configs WHERE symbol='" . G_SYM . "' LIMIT 1")->fetch(); });
        }
        $this->cfg = $row;
        lI(sprintf("[CFG] niv=%d spc=%.4f%% long=%d short=%d capital=%.0f",
            isset($row['levels']) ? $row['levels'] : G_FIXED_LEVELS, (isset($row['spacing_pct']) ? $row['spacing_pct'] : G_BASE_SPACING) * 100,
            isset($row['long_levels']) ? $row['long_levels'] : G_LONG_LEVELS, isset($row['short_levels']) ? $row['short_levels'] : G_SHORT_LEVELS, G_CAPITAL));
    }

    private function syncPositions() {
        $positions = $this->api->positions(G_SYM);
        if (empty($positions)) {
            lI("[SYNC] No hay posiciones abiertas. Todo limpio.");
            return;
        }
        lI("[SYNC] Detectadas " . count($positions) . " posiciones abiertas. Sincronizando...");
        $cfg = $this->cfg;
        if (!$cfg) { lW("[SYNC] Configuración no cargada, no se pueden sincronizar."); return; }
        $cfgId = (int)$cfg['id'];
        $f = $this->api->filters(G_SYM);
        $spacing = (float)($cfg['spacing_pct'] ?? G_BASE_SPACING);
        
        foreach ($positions as $pos) {
            $side = $pos['side']; // 'Buy' o 'Sell'
            $qty = abs($pos['positionAmt'] ?? ($pos['size'] ?? 0));
            $entryPrice = (float)$pos['entryPrice'];
            if ($qty < 0.001) continue;
            
            $price = $this->api->price(G_SYM);
            $level = null;
            if ($side === 'Buy') {
                $diff = ($price - $entryPrice) / $price;
                $nivel = round($diff / $spacing);
                if ($nivel >= 1 && $nivel <= G_FIXED_LEVELS) $level = $nivel;
            } else {
                $diff = ($entryPrice - $price) / $price;
                $nivel = round($diff / $spacing);
                if ($nivel >= 1 && $nivel <= G_FIXED_LEVELS) $level = -$nivel;
            }
            if ($level === null) $level = ($side === 'Buy') ? 1 : -1;
            
            $existing = dbx(function($d) use ($entryPrice, $side, $qty) {
                $s = $d->prepare("SELECT id FROM grid_orders WHERE symbol=? AND side=? AND price=? AND qty=? AND grid_role='ENTRY' AND status='FILLED' LIMIT 1");
                $s->execute([G_SYM, strtoupper($side), $entryPrice, $qty]);
                return $s->fetch();
            });
            if ($existing) {
                lI("[SYNC] Ya existe registro ENTRY para posición {$side} {$qty} @ {$entryPrice}");
                continue;
            }
            
            $orderId = 'SYNC_' . uniqid();
            dbx(function($d) use ($cfgId, $side, $level, $orderId, $entryPrice, $qty) {
                return $d->prepare("INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,filled_at) 
                    VALUES(?,?,?,?,?,?,?,?,?,'FILLED',NOW())")
                    ->execute([$cfgId, G_SYM, $cfg['direction'], $level, strtoupper($side), 'ENTRY', $orderId, $entryPrice, $qty]);
            });
            lI("[SYNC] Registrada ENTRY {$side} nivel {$level} qty={$qty} price={$entryPrice}");
            
            $exitSide = ($side === 'Buy') ? 'SELL' : 'BUY';
            $exitPrice = ($side === 'Buy') ? round($entryPrice * (1 + $spacing), $f['pp']) : round($entryPrice * (1 - $spacing), $f['pp']);
            if ($exitPrice <= 0) continue;
            
            $exitExisting = dbx(function($d) use ($exitPrice, $exitSide, $qty) {
                $s = $d->prepare("SELECT id FROM grid_orders WHERE symbol=? AND side=? AND price=? AND qty=? AND grid_role='EXIT' AND status='OPEN' LIMIT 1");
                $s->execute([G_SYM, $exitSide, $exitPrice, $qty]);
                return $s->fetch();
            });
            if ($exitExisting) {
                lI("[SYNC] Ya existe EXIT para esta posición");
                continue;
            }
            
            try {
                $res = $this->api->limitOrder(G_SYM, $exitSide, $qty, $exitPrice, true, true);
                dbx(function($d) use ($cfgId, $level, $exitSide, $res, $exitPrice, $qty) {
                    return $d->prepare("INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,linked_order) 
                        VALUES(?,?,?,?,?,?,?,?,?,'OPEN',?)")
                        ->execute([$cfgId, G_SYM, $cfg['direction'], $level, $exitSide, 'EXIT', $res['orderId'], $exitPrice, $qty, 0]);
                });
                lI("[SYNC] Creada EXIT {$exitSide} @ {$exitPrice} para posición existente");
            } catch (Exception $e) {
                lW("[SYNC] Error creando EXIT: " . $e->getMessage());
            }
        }
    }

    private function cleanupSession() {
        $this->api->cancelAll(G_SYM);
        sleep(1);
        dbx(function($d) {
            return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE symbol=? AND status='OPEN'")
                    ->execute([G_SYM]);
        });
        $this->gridBuilt = false;
        lI("[INIT] Órdenes canceladas. Posiciones existentes conservadas.");
    }

    private function closeAllPositions() {
        $positions = $this->api->positions(G_SYM);
        foreach ($positions as $p) {
            $sz = abs($p['positionAmt'] ?? ($p['size'] ?? 0));
            if ($sz < 0.001) continue;
            $side = $p['side'];
            for ($retry = 0; $retry < 3; $retry++) {
                try {
                    $this->api->marketClose(G_SYM, $side, $sz);
                    lI(sprintf("[CLOSE] %s %.4f (intento %d)", $side, $sz, $retry+1));
                    break;
                } catch (Exception $e) {
                    lW("[CLOSE] Error cerrando {$side} {$sz}: " . $e->getMessage());
                    if ($retry < 2) sleep(1);
                }
            }
        }
    }

    private function applyAIResultFallback($price) {
        $prevDir  = isset($this->cfg['direction']) ? $this->cfg['direction'] : 'SIDEWAYS';
        $prevConf = isset($this->cfg['confidence']) ? (int)$this->cfg['confidence'] : 50;
        $f        = $this->api->filters(G_SYM);
        $levels   = G_FIXED_LEVELS;
        $spacing  = G_BASE_SPACING;
        $qty      = isset($this->cfg['qty_per_level']) ? (float)$this->cfg['qty_per_level'] : 0;
        if ($qty <= 0) $qty = $this->calcQty($price, $levels, $f);
        $reason   = "Sin-velas: heurístico-puro dir={$prevDir} conf={$prevConf}";
        $direction  = $prevDir;
        $confidence = max(30, $prevConf - 10);
        $longLev = G_LONG_LEVELS;
        $shortLev = G_SHORT_LEVELS;
        dbx(function($d) use ($direction, $confidence, $reason, $levels, $spacing, $longLev, $shortLev, $qty) {
            return $d->prepare("UPDATE grid_configs SET direction=?,confidence=?,ai_reason=?,last_ai_check=NOW(),levels=?,spacing_pct=?,long_levels=?,short_levels=?,qty_per_level=? WHERE symbol=?")
                ->execute([$direction, $confidence, $reason, $levels, $spacing, $longLev, $shortLev, $qty, G_SYM]);
        });
        $this->cfg = dbx(function($d) { return $d->query("SELECT * FROM grid_configs WHERE symbol='" . G_SYM . "' LIMIT 1")->fetch(); });
        $this->lastAI = time();
        lI(sprintf("[AI-FALLBACK] %s conf=%d%% (sin velas, manteniendo última dir conocida)", $direction, $confidence));
        $this->appendConf($confidence, $direction);
        if ($direction !== $prevDir) {
            $this->api->cancelAll(G_SYM);
            dbx(function($d) { return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE symbol=? AND status='OPEN'")->execute([G_SYM]); });
            $this->gridBuilt = false; $this->lastGridBuild = 0;
        }
    }

    private function aiEvaluate($price) {
        global $NV_ENABLED, $NV_API_KEY, $NV_INTERVAL;
        lI("[AI] Evaluando ML + heurístico" . (G_VL_BLEND_WEIGHT > 0 && $NV_ENABLED ? " + VL" : "") . "...");
        $raw = $this->api->klines(G_SYM, G_TF, G_CANDLES);
        if (count($raw) < 30) {
            $this->applyAIResultFallback($price);
            return;
        }
        $candles = array_map(function($k) {
            return ['t'=>$k[0],'o'=>$k[1],'h'=>$k[2],'l'=>$k[3],'c'=>$k[4],'v'=>$k[5]];
        }, $raw);
        
        $mlResult = $this->ml->predict($candles);
        $mlProbs = $mlResult['probs'];
        
        $closes = array_column($candles, 'c');
        $rsi = rsiLast($closes);
        $macd = macdHistLast($closes);
        $ema9l = end(ema($closes, 9)); $ema21l = end(ema($closes, 21));
        $emaBull = ($ema9l && $ema21l && $ema9l > $ema21l && $price > $ema21l);
        $emaBear = ($ema9l && $ema21l && $ema9l < $ema21l && $price < $ema21l);
        $hScore = 0;
        if ($rsi > 55) $hScore += 1; elseif ($rsi < 45) $hScore -= 1;
        if ($macd > 0) $hScore += 0.5; elseif ($macd < 0) $hScore -= 0.5;
        if ($emaBull) $hScore += 0.5; elseif ($emaBear) $hScore -= 0.5;
        $norm = ($hScore + 2.0) / 4.0;
        $hProbs = [max(0, 0.5 - $norm), max(0, abs(0.5 - $norm) * 0.4 + 0.2), max(0, $norm - 0.1)];
        $hSum = array_sum($hProbs);
        if ($hSum > 0) $hProbs = array_map(function($p) use ($hSum) { return $p / $hSum; }, $hProbs);
        else $hProbs = [0.33, 0.34, 0.33];
        
        $vlProbs = null;
        $vlResult = null;
        if (G_VL_BLEND_WEIGHT > 0 && $NV_ENABLED && (time() - $this->lastVL) >= $NV_INTERVAL) {
            $chartPath = '/tmp/latest_chart.png';
            if (file_exists($chartPath)) {
                $vlResult = analyzeChartWithVL($chartPath, $NV_API_KEY);
                if ($vlResult) {
                    $vlDir = $vlResult['direction'];
                    $vlConf = $vlResult['confidence'] / 100;
                    $vlProbs = ['DOWN' => 0.33, 'SIDEWAYS' => 0.34, 'UP' => 0.33];
                    $vlProbs[$vlDir] = $vlConf;
                    $sum = array_sum($vlProbs);
                    foreach ($vlProbs as $k => $v) $vlProbs[$k] = $v / $sum;
                    lI("[VL] $vlDir {$vlResult['confidence']}% - {$vlResult['reason']}");
                }
            }
            $this->lastVL = time();
        }
        
        $w_ml = G_ML_BLEND_WEIGHT;
        $w_heur = 1 - $w_ml;
        if ($vlProbs) {
            $w_vl = G_VL_BLEND_WEIGHT;
            $w_ml = $w_ml * (1 - $w_vl);
            $w_heur = $w_heur * (1 - $w_vl);
            $blended = [
                $w_ml * $mlProbs[0] + $w_heur * $hProbs[0] + $w_vl * $vlProbs['DOWN'],
                $w_ml * $mlProbs[1] + $w_heur * $hProbs[1] + $w_vl * $vlProbs['SIDEWAYS'],
                $w_ml * $mlProbs[2] + $w_heur * $hProbs[2] + $w_vl * $vlProbs['UP']
            ];
        } else {
            $blended = [
                $w_ml * $mlProbs[0] + $w_heur * $hProbs[0],
                $w_ml * $mlProbs[1] + $w_heur * $hProbs[1],
                $w_ml * $mlProbs[2] + $w_heur * $hProbs[2]
            ];
        }
        
        $maxIdx = (int)array_search(max($blended), $blended);
        $classes = ['DOWN', 'SIDEWAYS', 'UP'];
        $direction = $classes[$maxIdx];
        $confidence = (int)round($blended[$maxIdx] * 100);
        
        $prevDir = isset($this->cfg['direction']) ? $this->cfg['direction'] : 'SIDEWAYS';
        if ($direction !== $prevDir) {
            if ($direction === $this->lastDirection) {
                $this->directionChangeCount++;
                if ($this->directionChangeCount < 2) {
                    lI("[AI] Dirección $direction propuesta, pero se requiere confirmación (2 ciclos). Manteniendo $prevDir.");
                    $direction = $prevDir;
                    $confidence = (int)round(($confidence + (isset($this->cfg['confidence']) ? $this->cfg['confidence'] : 50)) / 2);
                } else {
                    $this->directionChangeCount = 0;
                }
            } else {
                $this->lastDirection = $direction;
                $this->directionChangeCount = 1;
                lI("[AI] Posible cambio de dirección a $direction, pendiente de confirmación.");
                $direction = $prevDir;
                $confidence = (int)round(($confidence + (isset($this->cfg['confidence']) ? $this->cfg['confidence'] : 50)) / 2);
            }
        } else {
            $this->directionChangeCount = 0;
            $this->lastDirection = $direction;
        }
        
        $atr_actual = atrPctLast($candles);
        $atr_predicho = $this->predictFutureATR($candles);
        $this->last_atr_predicho = $atr_predicho;
        
        if ($atr_predicho !== null && $atr_predicho > 0.01) {
            $atr_efectivo = 0.70 * $atr_actual + 0.30 * $atr_predicho;
            lI("[Spacing] ATR blend (siempre): real={$atr_actual}% pred={$atr_predicho}% → efectivo={$atr_efectivo}%");
        } else {
            $atr_efectivo = $atr_actual;
            lI("[Spacing] Usando solo ATR real: {$atr_actual}%");
        }
        
        $spacing_raw = G_BASE_SPACING + ($atr_efectivo * G_SPACING_ATR_MULT / 100);
        $spacing = min(G_MAX_SPACING, max(G_MIN_SPACING, $spacing_raw));
        if ($direction === 'SIDEWAYS') $spacing = max(G_MIN_SPACING, $spacing * 0.90);
        
        $levels = G_FIXED_LEVELS;
        if ($direction === 'UP') {
            $longLev  = (int)round($levels * 0.625);
            $shortLev = $levels - $longLev;
        } elseif ($direction === 'DOWN') {
            $shortLev = (int)round($levels * 0.625);
            $longLev  = $levels - $shortLev;
        } else {
            $longLev  = (int)($levels * 0.5);
            $shortLev = $levels - $longLev;
        }
        $f = $this->api->filters(G_SYM);
        $qty = $this->calcQty($price, $levels, $f);
        $mlAcc = $this->ml->getAccuracy();
        $reason = sprintf("ML:%s(%d%%) H:%.1f Blend:%s(%d%%) acc=%.0f%% VolPred=%.2f%%",
            $mlResult['direction'], $mlResult['confidence'], $hScore, $direction, $confidence, $mlAcc * 100, $atr_predicho);
        
        dbx(function($d) use ($direction, $confidence, $reason, $levels, $spacing, $longLev, $shortLev, $qty, $f, $mlAcc) {
            return $d->prepare("UPDATE grid_configs SET direction=?,confidence=?,ai_reason=?,last_ai_check=NOW(),levels=?,spacing_pct=?,long_levels=?,short_levels=?,qty_per_level=?,pp=?,qp=?,ml_accuracy=? WHERE symbol=?")
                ->execute([$direction, $confidence, $reason, $levels, $spacing, $longLev, $shortLev, $qty, $f['pp'], $f['qp'], $mlAcc, G_SYM]);
        });
        $this->cfg = dbx(function($d) { return $d->query("SELECT * FROM grid_configs WHERE symbol='" . G_SYM . "' LIMIT 1")->fetch(); });
        $this->lastAI = time();
        $this->appendConf($confidence, $direction);
        $this->last_vl_result = $vlResult;
        
        $atr_pred_str = ($atr_predicho === null) ? 'null' : number_format($atr_predicho, 2).'%';
        lI(sprintf("[AI] %s conf=%d%% | spacing=%.4f%% | atr_real=%.2f%% atr_pred=%s | niveles=%d | qty=%.4f ETH", 
            $direction, $confidence, $spacing*100, $atr_actual, $atr_pred_str, $levels, $qty));
        
        if ($direction !== $prevDir && $this->directionChangeCount == 0) {
            lI("[AI] Dirección $prevDir → $direction → Reconstruyendo grid");
            $this->api->cancelAll(G_SYM);
            usleep(800000);
            
            $positions = $this->api->positions(G_SYM);
            $exitsByLevel = [];
            dbx(function($d) use (&$exitsByLevel) {
                $rows = $d->query("SELECT grid_level, side FROM grid_orders WHERE symbol='" . G_SYM . "' AND status='OPEN' AND grid_role='EXIT'")->fetchAll();
                foreach ($rows as $r) $exitsByLevel[$r['grid_level']] = $r['side'];
            });
            foreach ($positions as $pos) {
                $side = $pos['side'];
                $qtyPos = abs($pos['positionAmt'] ?? ($pos['size'] ?? 0));
                $hasExitForSide = false;
                foreach ($exitsByLevel as $level => $exitSide) {
                    if (($exitSide === 'SELL' && $side === 'Buy') || ($exitSide === 'BUY' && $side === 'Sell')) {
                        $hasExitForSide = true;
                        break;
                    }
                }
                if (!$hasExitForSide && $qtyPos > 0.001) {
                    lI("[AI] Cerrando posición huérfana {$side} {$qtyPos} (sin EXIT asociada)");
                    $this->api->marketClose(G_SYM, $side, $qtyPos);
                }
            }
            dbx(function($d) { return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE symbol=? AND status='OPEN'")->execute([G_SYM]); });
            $this->gridBuilt = false;
            $this->lastGridBuild = 0;
        }
    }

    private $lastLoggedQty = 0.0;
    private function calcQty($price, $levels, $f, $knownBalance = null) {
        $balance = $knownBalance ?? $this->api->balance();
        if ($balance <= 0) $balance = G_CAPITAL;
        $effectiveCap = min($balance, G_CAPITAL) * G_MARGIN_SAFETY;
        $marginPerLevel = $effectiveCap / max(1, $levels);
        $qty = ($marginPerLevel * G_LEVERAGE) / $price;
        $maxQty = ($effectiveCap * 0.12 * G_LEVERAGE) / $price;
        if ($qty > $maxQty) $qty = $maxQty;
        
        $qty = max($f['step'], round($qty / $f['step']) * $f['step']);
        $notional = $qty * $price;
        if ($notional < G_MIN_NOTIONAL) {
            $qty = G_MIN_NOTIONAL / $price;
            $qty = max($f['step'], round($qty / $f['step']) * $f['step']);
            lI(sprintf("[CALC] Ajuste por notional mínimo: qty %.4f ETH (notional %.2f USDT)", $qty, $qty * $price));
        }
        if (abs($qty - $this->lastLoggedQty) > 0.0001) {
            lI(sprintf("[CALC] Qty: %.4f ETH (cap=%.2f mrg/niv=%.4f notional=%.2f)", $qty, $effectiveCap, $marginPerLevel, $qty * $price));
            $this->lastLoggedQty = $qty;
        }
        return $qty;
    }

    private function buildGrid($price) {
        $cfg = $this->cfg; if (!$cfg) return;
        $elapsed = time() - $this->lastGridBuild;
        if ($this->lastGridBuild > 0 && $elapsed < G_MIN_BUILD_INTERVAL) {
            lW(sprintf("[GRID] Anti-churn: última build hace %ds (mín %ds)", $elapsed, G_MIN_BUILD_INTERVAL));
            return;
        }
        $levels   = G_FIXED_LEVELS;
        $spacing  = (float)(isset($cfg['spacing_pct']) ? $cfg['spacing_pct'] : G_BASE_SPACING);
        $longLev  = (int)(isset($cfg['long_levels']) ? $cfg['long_levels'] : G_LONG_LEVELS);
        $shortLev = (int)(isset($cfg['short_levels']) ? $cfg['short_levels'] : G_SHORT_LEVELS);
        $dir      = isset($cfg['direction']) ? $cfg['direction'] : 'SIDEWAYS';
        $qty      = (float)(isset($cfg['qty_per_level']) ? $cfg['qty_per_level'] : 0);
        $f        = $this->api->filters(G_SYM);
        $cfgId    = (int)$cfg['id'];
        $balance = $this->api->balance();
        if ($balance <= 0) { lW("[GRID] Balance 0, usando capital teórico"); $balance = G_CAPITAL; }
        else { lI(sprintf("[GRID] Balance disponible: %.4f USDT", $balance)); }
        if ($balance < G_CAPITAL * 0.1) {
            lE("[GRID] Balance real ({$balance}) < 10% capital (" . G_CAPITAL . "). Pausando.");
            dbx(function($d) { return $d->prepare("UPDATE grid_configs SET paused_reason='Saldo insuficiente' WHERE symbol=?")->execute([G_SYM]); });
            $this->gridBuilt = false; return;
        } else {
            dbx(function($d) { return $d->prepare("UPDATE grid_configs SET paused_reason=NULL WHERE symbol=?")->execute([G_SYM]); });
        }
        if ($qty <= 0) {
            $qty = $this->calcQty($price, $levels, $f, $balance);
            dbx(function($d) use ($qty, $cfgId) { return $d->prepare("UPDATE grid_configs SET qty_per_level=? WHERE id=?")->execute([$qty, $cfgId]); });
        }
        lI(sprintf("[GRID] Construyendo $%.2f | %s: L=%d S=%d spc=%.4f%% qty=%.4f", $price, $dir, $longLev, $shortLev, $spacing * 100, $qty));
        $placed = 0; $errors = 0; $usedMargin = 0.0;
        for ($i = 1; $i <= $longLev; $i++) {
            $p = round($price * (1 - $spacing * $i), $f['pp']); if ($p <= 0) continue;
            $reqMargin = ($qty * $p) / G_LEVERAGE;
            if ($reqMargin > ($balance - $usedMargin) * 0.95) { lW("[GRID] Margen insuficiente BUY L$i"); continue; }
            try {
                $res = $this->api->limitOrder(G_SYM, 'Buy', $qty, $p, false, true);
                dbx(function($d) use ($cfgId, $dir, $i, $res, $p, $qty) {
                    return $d->prepare("INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status) VALUES(?,?,?,?,?,?,?,?,?,'OPEN')")
                        ->execute([$cfgId, G_SYM, $dir, $i, 'BUY', 'ENTRY', $res['orderId'], $p, $qty]);
                });
                $placed++; $usedMargin += $reqMargin;
            } catch (Exception $e) { lW("[GRID] BUY L{$i}: " . $e->getMessage()); $errors++; }
            usleep(120000);
        }
        for ($i = 1; $i <= $shortLev; $i++) {
            $p = round($price * (1 + $spacing * $i), $f['pp']);
            $reqMargin = ($qty * $p) / G_LEVERAGE;
            if ($reqMargin > ($balance - $usedMargin) * 0.95) { lW("[GRID] Margen insuficiente SELL L$i"); continue; }
            try {
                $res = $this->api->limitOrder(G_SYM, 'Sell', $qty, $p, false, true);
                dbx(function($d) use ($cfgId, $dir, $i, $res, $p, $qty) {
                    return $d->prepare("INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status) VALUES(?,?,?,?,?,?,?,?,?,'OPEN')")
                        ->execute([$cfgId, G_SYM, $dir, -$i, 'SELL', 'ENTRY', $res['orderId'], $p, $qty]);
                });
                $placed++; $usedMargin += $reqMargin;
            } catch (Exception $e) { lW("[GRID] SELL L{$i}: " . $e->getMessage()); $errors++; }
            usleep(120000);
        }
        $this->gridBuilt = ($placed > 0);
        $this->lastGridBuild = $placed > 0 ? time() : 0;
        lI(sprintf("[GRID] ✓ %d/%d órdenes | %d err | Margen: %.2f USDT", $placed, $levels, $errors, $usedMargin));
        if ($placed == 0 && $errors > 0) {
            lW("[GRID] Sin órdenes colocadas. Reduciendo niveles...");
            $newLong  = max(G_MIN_LEVELS, $longLev - 1);
            $newShort = max(G_MIN_LEVELS, $shortLev - 1);
            $newLevels = $newLong + $newShort;
            dbx(function($d) use ($newLevels, $newLong, $newShort) {
                return $d->prepare("UPDATE grid_configs SET levels=?, long_levels=?, short_levels=? WHERE symbol=?")
                    ->execute([$newLevels, $newLong, $newShort, G_SYM]);
            });
            $this->cfg['levels'] = $newLevels;
            $this->cfg['long_levels']  = $newLong;
            $this->cfg['short_levels'] = $newShort;
            $this->gridBuilt = false;
        }
    }

    private function checkFills($price) {
        $openOrders = $this->api->getOpenOrders(G_SYM);
        $localOpens = dbx(function($d) {
            return $d->query("SELECT * FROM grid_orders WHERE symbol='" . G_SYM . "' AND status='OPEN' AND order_id IS NOT NULL LIMIT 60")->fetchAll();
        }) ?? [];
        if (empty($localOpens)) return;
        $apiEmpty = empty($openOrders);
        if ($apiEmpty) {
            lW("[FILLS] API devolvió 0 órdenes abiertas. Verificando individualmente (" . count($localOpens) . " locales)...");
        }
        foreach ($localOpens as $order) {
            $oid  = $order['order_id'];
            $real = isset($openOrders[$oid]) ? $openOrders[$oid] : null;
            if ($real && !$apiEmpty) {
                $statusMap = ['New' => 'NEW', 'PartiallyFilled' => 'PARTIALLY_FILLED',
                              'Filled' => 'FILLED', 'Cancelled' => 'CANCELED',
                              'Rejected' => 'CANCELED', 'Expired' => 'CANCELED'];
                $status = isset($statusMap[$real['status']]) ? $statusMap[$real['status']] : 'UNKNOWN';
                if ($status === 'FILLED') {
                    $this->onFill($order, $real, $price);
                } elseif (in_array($status, ['CANCELED', 'EXPIRED'])) {
                    dbx(function($d) use ($order) { return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE id=?")->execute([$order['id']]); });
                }
            } else {
                $age = time() - strtotime(isset($order['created_at']) ? $order['created_at'] : date('Y-m-d H:i:s'));
                if ($age < 30) continue;
                try {
                    $info = $this->api->getOrder(G_SYM, $oid);
                    if ($info['status'] === 'FILLED') {
                        $this->onFill($order, $info, $price);
                    } elseif (in_array($info['status'], ['CANCELED', 'EXPIRED'])) {
                        dbx(function($d) use ($order) { return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE id=?")->execute([$order['id']]); });
                    }
                } catch (Exception $e) {}
            }
        }
    }

    // ========== FUNCIONES CORREGIDAS ==========
    
    /**
     * Confirma que la posición exista en el exchange.
     * Ahora permite que el tamaño real sea MAYOR o igual al esperado
     * (posiciones acumuladas) y extiende el tiempo de espera a ~12 segundos.
     */
    private function confirmPositionExists($entrySide, $expectedQty) {
        // Aumentado a 12s (6 intentos de 2s cada uno, total 12s)
        $sleepMs = [2000000, 2000000, 2000000, 2000000, 2000000, 2000000];
        $targetSide = ($entrySide === 'BUY') ? 'Buy' : 'Sell';
        for ($i = 0; $i < count($sleepMs); $i++) {
            $positions = $this->api->positions(G_SYM);
            foreach ($positions as $pos) {
                if (($pos['side'] ?? '') !== $targetSide) continue;
                $sz = abs($pos['positionAmt'] ?? ($pos['size'] ?? 0));
                if ($sz >= $expectedQty * 0.98) {
                    lI("[POSCONF] Posición {$targetSide} detectada (qty={$sz}, esperada={$expectedQty})");
                    return true;
                }
            }
            if ($i < count($sleepMs)-1) {
                lW("[POSCONF] Esperando posición {$targetSide} (intento " . ($i+1) . "/" . count($sleepMs) . ")");
                usleep($sleepMs[$i]);
            }
        }
        lW("[POSCONF] No se detectó posición {$targetSide} después de " . (array_sum($sleepMs)/1e6) . "s");
        return false;
    }

    /**
     * Verifica si ya existe una posición abierta en el exchange para un lado dado,
     * con tamaño al menos el 95% del esperado.
     */
    private function hasOpenPositionForSide($side, $expectedQty) {
        $positions = $this->api->positions(G_SYM);
        $targetSide = ($side === 'BUY') ? 'Buy' : 'Sell';
        foreach ($positions as $pos) {
            if (($pos['side'] ?? '') !== $targetSide) continue;
            $sz = abs($pos['positionAmt'] ?? ($pos['size'] ?? 0));
            if ($sz >= $expectedQty * 0.95) return true;
        }
        return false;
    }

    private function hasOpenEntryForLevel($level) {
        $count = dbx(function($d) use ($level) {
            return $d->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='" . G_SYM . "' AND grid_level={$level} AND grid_role='ENTRY' AND status='OPEN'")->fetchColumn();
        }) ?? 0;
        return $count > 0;
    }

    private function onFill($order, $info, $price) {
        $cfg = $this->cfg;
        $spacing = (float)(isset($cfg['spacing_pct']) ? $cfg['spacing_pct'] : G_BASE_SPACING);
        $qty     = (float)$order['qty'];
        $fillPx  = (float)(isset($info['avgPrice']) && $info['avgPrice'] > 0 ? $info['avgPrice'] : $order['price']);
        $side    = $order['side']; $role = $order['grid_role'];
        $f       = $this->api->filters(G_SYM);
        $isRec   = (int)(isset($order['is_recovery']) ? $order['is_recovery'] : 0);
        dbx(function($d) use ($fillPx, $order) {
            return $d->prepare("UPDATE grid_orders SET status='FILLED',filled_at=NOW(),exit_price=? WHERE id=?")->execute([$fillPx, $order['id']]);
        });
        if ($role === 'ENTRY') {
            $positionFound = $this->confirmPositionExists($side, $qty);
            if ($positionFound) {
                $exitSide = ($side === 'BUY') ? 'SELL' : 'BUY';
                $bySide   = ($exitSide === 'BUY') ? 'Buy' : 'Sell';
                $exitPx   = ($side === 'BUY') ? round($fillPx * (1 + $spacing), $f['pp']) : round($fillPx * (1 - $spacing), $f['pp']);
                if ($exitPx <= 0) { lW("[FILL] exitPx inválido"); return; }
                try {
                    $res = $this->api->limitOrder(G_SYM, $bySide, $qty, $exitPx, true, true);
                    dbx(function($d) use ($cfg, $order, $exitSide, $res, $exitPx, $qty, $isRec) {
                        return $d->prepare("INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,linked_order,is_recovery) VALUES(?,?,?,?,?,?,?,?,?,'OPEN',?,?)")
                            ->execute([(int)$cfg['id'], G_SYM, $cfg['direction'], $order['grid_level'], $exitSide, 'EXIT', $res['orderId'], $exitPx, $qty, $order['id'], $isRec]);
                    });
                    lI(sprintf("[FILL] ENTRY %s $%.2f → EXIT %s $%.2f qty=%.4f", $side, $fillPx, $exitSide, $exitPx, $qty));
                } catch (Exception $e) { lW("[FILL] EXIT fail: " . $e->getMessage()); }
            } else {
                usleep(2000000);
                $positionFound = $this->confirmPositionExists($side, $qty);
                if ($positionFound) {
                    lI("[FILL] Posición detectada en segundo intento, procediendo con EXIT normal");
                    $exitSide = ($side === 'BUY') ? 'SELL' : 'BUY';
                    $bySide   = ($exitSide === 'BUY') ? 'Buy' : 'Sell';
                    $exitPx   = ($side === 'BUY') ? round($fillPx * (1 + $spacing), $f['pp']) : round($fillPx * (1 - $spacing), $f['pp']);
                    if ($exitPx > 0) {
                        try {
                            $res = $this->api->limitOrder(G_SYM, $bySide, $qty, $exitPx, true, true);
                            dbx(function($d) use ($cfg, $order, $exitSide, $res, $exitPx, $qty, $isRec) {
                                return $d->prepare("INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,linked_order,is_recovery) VALUES(?,?,?,?,?,?,?,?,?,'OPEN',?,?)")
                                    ->execute([(int)$cfg['id'], G_SYM, $cfg['direction'], $order['grid_level'], $exitSide, 'EXIT', $res['orderId'], $exitPx, $qty, $order['id'], $isRec]);
                            });
                            lI(sprintf("[FILL] (reintento) ENTRY %s $%.2f → EXIT %s $%.2f qty=%.4f", $side, $fillPx, $exitSide, $exitPx, $qty));
                            return;
                        } catch (Exception $e) { lW("[FILL] EXIT fail en reintento: " . $e->getMessage()); }
                    }
                }
                if ($this->hasOpenPositionForSide($side, $qty)) {
                    lI("[FILL] Posición {$side} existe en la API (acumulada). Creando EXIT normalmente.");
                    $exitSide = ($side === 'BUY') ? 'SELL' : 'BUY';
                    $bySide   = ($exitSide === 'BUY') ? 'Buy' : 'Sell';
                    $exitPx   = ($side === 'BUY') ? round($fillPx * (1 + $spacing), $f['pp']) : round($fillPx * (1 - $spacing), $f['pp']);
                    if ($exitPx > 0) {
                        try {
                            $res = $this->api->limitOrder(G_SYM, $bySide, $qty, $exitPx, true, true);
                            dbx(function($d) use ($cfg, $order, $exitSide, $res, $exitPx, $qty, $isRec) {
                                return $d->prepare("INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,linked_order,is_recovery) VALUES(?,?,?,?,?,?,?,?,?,'OPEN',?,?)")
                                    ->execute([(int)$cfg['id'], G_SYM, $cfg['direction'], $order['grid_level'], $exitSide, 'EXIT', $res['orderId'], $exitPx, $qty, $order['id'], $isRec]);
                            });
                            lI(sprintf("[FILL] (posición acumulada) ENTRY %s $%.2f → EXIT %s $%.2f qty=%.4f", $side, $fillPx, $exitSide, $exitPx, $qty));
                            return;
                        } catch (Exception $e) { lW("[FILL] EXIT fail en posición acumulada: " . $e->getMessage()); }
                    }
                }
                lW(sprintf("[FILL] ENTRY %s $%.2f sin posición → reciclando (último recurso)", $side, $fillPx));
                $this->recycleEntryDirect($order, $fillPx, $price, $spacing, $qty, $f, $isRec);
            }
        } elseif ($role === 'EXIT') {
            $entryRow = dbx(function($d) use ($order) {
                $s = $d->prepare("SELECT * FROM grid_orders WHERE id=?");
                $s->execute([$order['linked_order']]);
                return $s->fetch();
            });
            $entryPx  = $entryRow ? (float)$entryRow['price'] : (float)$order['price'];
            $pnl      = $this->calcPnl($side, $entryPx, $fillPx, $qty);
            dbx(function($d) use ($pnl, $order) { return $d->prepare("UPDATE grid_orders SET pnl_usd=? WHERE id=?")->execute([$pnl, $order['id']]); });
            lI(sprintf("[FILL] EXIT %s $%.2f PnL=%.4f USDT %s", $side, $fillPx, $pnl, $pnl >= 0 ? '✅' : '⚠️'));
            $today = $this->getPnlToday();
            if ($today > $this->peakPnl) $this->peakPnl = $today;
            $this->recycleEntry($order, $fillPx, $price, $spacing, $qty, $f, $isRec);
        }
    }

    private function calcPnl($exitSide, $entryPx, $exitPx, $qty) {
        $gross = ($exitSide === 'SELL') ? ($exitPx - $entryPx) * $qty : ($entryPx - $exitPx) * $qty;
        $fee = $entryPx * $qty * G_MAKER_FEE + $exitPx * $qty * G_MAKER_FEE;
        return round($gross - $fee, 8);
    }

    private function recycleEntry($exitOrder, $fillPx, $currentPrice, $spacing, $qty, $f, $isRec) {
        $cfg = $this->cfg; $cfgId = (int)$cfg['id'];
        $newSide = ($exitOrder['side'] === 'SELL') ? 'BUY' : 'SELL';
        $level = $exitOrder['grid_level'];
        if ($this->hasOpenEntryForLevel($level)) {
            lI("[RECYCLE] Ya existe ENTRY abierta para nivel {$level}, omitiendo reciclaje.");
            return;
        }
        $bySide  = ($newSide === 'BUY') ? 'Buy' : 'Sell';
        $newPx   = ($newSide === 'BUY') ? round($currentPrice * (1 - $spacing), $f['pp']) : round($currentPrice * (1 + $spacing), $f['pp']);
        try {
            $res = $this->api->limitOrder(G_SYM, $bySide, $qty, $newPx, false, true);
            dbx(function($d) use ($cfgId, $cfg, $exitOrder, $newSide, $res, $newPx, $qty, $isRec) {
                return $d->prepare("INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,is_recovery) VALUES(?,?,?,?,?,?,?,?,?,'OPEN',?)")
                    ->execute([$cfgId, G_SYM, $cfg['direction'], $exitOrder['grid_level'], $newSide, 'ENTRY', $res['orderId'], $newPx, $qty, $isRec]);
            });
            lI(sprintf("[RECYCLE] ENTRY %s $%.2f", $newSide, $newPx));
        } catch (Exception $e) { lW("[RECYCLE] " . $e->getMessage()); }
    }

    private function recycleEntryDirect($order, $fillPx, $price, $spacing, $qty, $f, $isRec) {
        $cfg = $this->cfg; $cfgId = (int)$cfg['id'];
        $newSide = $order['side'];
        $level = $order['grid_level'];
        if ($this->hasOpenEntryForLevel($level)) {
            lI("[RECYCLE_D] Ya existe ENTRY abierta para nivel {$level}, omitiendo reciclaje.");
            return;
        }
        if ($this->hasOpenPositionForSide($newSide, $qty)) {
            lI("[RECYCLE_D] Ya existe posición {$newSide} abierta, omitiendo reciclaje.");
            return;
        }
        $bySide  = ($newSide === 'BUY') ? 'Buy' : 'Sell';
        $newPx   = ($newSide === 'BUY') ? round($price * (1 - $spacing), $f['pp']) : round($price * (1 + $spacing), $f['pp']);
        try {
            $res = $this->api->limitOrder(G_SYM, $bySide, $qty, $newPx, false, true);
            dbx(function($d) use ($cfgId, $cfg, $order, $newSide, $res, $newPx, $qty, $isRec) {
                return $d->prepare("INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,is_recovery) VALUES(?,?,?,?,?,?,?,?,?,'OPEN',?)")
                    ->execute([$cfgId, G_SYM, $cfg['direction'], $order['grid_level'], $newSide, 'ENTRY', $res['orderId'], $newPx, $qty, $isRec]);
            });
            lI(sprintf("[RECYCLE_D] ENTRY %s $%.2f", $newSide, $newPx));
        } catch (Exception $e) { lW("[RECYCLE_D] " . $e->getMessage()); }
    }

    private function riskCheck($price) {
        $pnlTdy  = $this->getPnlToday();
        $lossPct = $pnlTdy < 0 ? abs($pnlTdy) / G_CAPITAL * 100 : 0;
        if ($lossPct >= G_RECOVERY_LOSS_PCT && !(isset($this->cfg['recovery_active']) && $this->cfg['recovery_active'])) {
            lW(sprintf("[RECOVERY] Pérdida diaria %.2f%% → activando", $lossPct));
            $this->enterRecovery($price, $pnlTdy);
        }
        if ($lossPct >= G_MAX_DAILY_LOSS) {
            lE(sprintf("[RISK] Límite diario %.1f%% → pausa 20min", G_MAX_DAILY_LOSS));
            $this->api->cancelAll(G_SYM);
            $this->closeAllPositions();
            $this->gridBuilt = false;
            sleep(1200);
        }
        $positions = $this->api->positions(G_SYM);
        foreach ($positions as $pos) {
            $upnl = (float)(isset($pos['unRealizedProfit']) ? $pos['unRealizedProfit'] : 0);
            $notional = abs(isset($pos['positionAmt']) ? $pos['positionAmt'] : (isset($pos['size']) ? $pos['size'] : 0)) * abs(isset($pos['entryPrice']) ? $pos['entryPrice'] : 0);
            if ($notional > 0 && abs($upnl) / $notional * 100 >= G_HARD_STOP_PCT && $upnl < 0) {
                lE(sprintf("[HARD_STOP] uPnL $%.4f → cierre forzoso", $upnl));
                try { $this->api->marketClose(G_SYM, $pos['side'], abs(isset($pos['positionAmt']) ? $pos['positionAmt'] : (isset($pos['size']) ? $pos['size'] : 0))); }
                catch (Exception $e) { lW("[HARD_STOP] " . $e->getMessage()); }
            }
        }
        $this->checkLiquidationRisk($price);
    }

    private function checkLiquidationRisk($price) {
        $positions = $this->api->positions(G_SYM);
        foreach ($positions as $pos) {
            $liq = (float)(isset($pos['liquidationPrice']) ? $pos['liquidationPrice'] : 0);
            if ($liq <= 0) continue;
            $distancePct = abs($liq - $price) / $price * 100;
            if ($distancePct < 15) {
                lE(sprintf("[LIQ_RISK] Posición %s a solo %.1f%% de liquidación (liq=%.2f). Cerrando.", $pos['side'], $distancePct, $liq));
                $this->api->marketClose(G_SYM, $pos['side'], abs(isset($pos['positionAmt']) ? $pos['positionAmt'] : (isset($pos['size']) ? $pos['size'] : 0)));
            }
        }
    }

    private function enterRecovery($price, $pnlTdy) {
        $cfg = $this->cfg; $f = $this->api->filters(G_SYM);
        $spacing = min(G_MAX_SPACING, (float)(isset($cfg['spacing_pct']) ? $cfg['spacing_pct'] : G_BASE_SPACING) * 1.8);
        $qty     = $this->calcQty($price, G_MIN_LEVELS * 2, $f);
        $dir     = isset($cfg['direction']) ? $cfg['direction'] : 'SIDEWAYS';
        $this->api->cancelAll(G_SYM);
        dbx(function($d) { return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE symbol=? AND status='OPEN'")->execute([G_SYM]); });
        dbx(function($d) use ($spacing) { return $d->prepare("UPDATE grid_configs SET recovery_active=1,spacing_pct=? WHERE symbol=?")->execute([$spacing, G_SYM]); });
        $this->cfg['recovery_active'] = 1; $this->cfg['spacing_pct'] = $spacing;
        $this->gridBuilt = false; $this->lastGridBuild = 0;
        $balance = $this->api->balance(); if ($balance <= 0) $balance = G_CAPITAL;
        $halfLev = (int)(G_MIN_LEVELS); $placed = 0;
        for ($i = 1; $i <= $halfLev; $i++) {
            $p = round($price * (1 - $spacing * $i), $f['pp']); if ($p <= 0) continue;
            $reqM = ($qty * $p) / G_LEVERAGE; if ($reqM > $balance * 0.9) { lW("[REC] Margen insuficiente BUY L$i"); continue; }
            try {
                $res = $this->api->limitOrder(G_SYM, 'Buy', $qty, $p, false, true);
                dbx(function($d) use ($cfg, $dir, $i, $res, $p, $qty) {
                    return $d->prepare("INSERT INTO grid_orders(config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,is_recovery)VALUES(?,?,?,?,?,?,?,?,?,'OPEN',1)")
                        ->execute([(int)$cfg['id'], G_SYM, $dir, $i, 'BUY', 'ENTRY', $res['orderId'], $p, $qty]);
                });
                $placed++;
            } catch (Exception $e) { lW("[REC] BUY $i: " . $e->getMessage()); }
            usleep(120000);
        }
        for ($i = 1; $i <= $halfLev; $i++) {
            $p = round($price * (1 + $spacing * $i), $f['pp']);
            $reqM = ($qty * $p) / G_LEVERAGE; if ($reqM > $balance * 0.9) { lW("[REC] Margen insuficiente SELL L$i"); continue; }
            try {
                $res = $this->api->limitOrder(G_SYM, 'Sell', $qty, $p, false, true);
                dbx(function($d) use ($cfg, $dir, $i, $res, $p, $qty) {
                    return $d->prepare("INSERT INTO grid_orders(config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,is_recovery)VALUES(?,?,?,?,?,?,?,?,?,'OPEN',1)")
                        ->execute([(int)$cfg['id'], G_SYM, $dir, -$i, 'SELL', 'ENTRY', $res['orderId'], $p, $qty]);
                });
                $placed++;
            } catch (Exception $e) { lW("[REC] SELL $i: " . $e->getMessage()); }
            usleep(120000);
        }
        $this->gridBuilt = ($placed > 0);
        if ($placed > 0) $this->lastGridBuild = time();
        lI(sprintf("[RECOVERY] %d órdenes recovery spacing=%.3f%%", $placed, $spacing * 100));
    }

    private function profitOptimize($price) {
        $pnlTdy = $this->getPnlToday(); $pct = $pnlTdy / G_CAPITAL * 100;
        if ($pnlTdy > $this->peakPnl) {
            $this->peakPnl = $pnlTdy;
            dbx(function($d) { return $d->prepare("UPDATE grid_configs SET peak_pnl_today=? WHERE symbol=?")->execute([$this->peakPnl, G_SYM]); });
        }
        $cooldownOk = (time() - $this->lastCompound) >= G_COMPOUND_CD;
        if ($pct >= G_COMPOUND_THR && !(isset($this->cfg['recovery_active']) && $this->cfg['recovery_active']) && $cooldownOk) {
            $f = $this->api->filters(G_SYM); $levels = G_FIXED_LEVELS;
            $oldQty  = (float)(isset($this->cfg['qty_per_level']) ? $this->cfg['qty_per_level'] : 0);
            if ($oldQty <= 0) $oldQty = $this->calcQty($price, $levels, $f);
            $maxAllowed = ($oldQty * 3.0);
            $hardCap    = (G_CAPITAL * 0.12 * G_LEVERAGE) / $price;
            $newQty  = min($oldQty * G_COMPOUND_MULT, $maxAllowed, $hardCap);
            $newQty  = max($f['step'], round($newQty / $f['step']) * $f['step']);
            if (abs($newQty - $oldQty) > $f['step'] * 0.3) {
                dbx(function($d) use ($newQty) { return $d->prepare("UPDATE grid_configs SET qty_per_level=? WHERE symbol=?")->execute([$newQty, G_SYM]); });
                $this->cfg['qty_per_level'] = $newQty;
                $this->lastCompound = time();
                lI(sprintf("[COMPOUND] PnL +%.2f%% → qty %.4f→%.4f ETH", $pct, $oldQty, $newQty));
            }
        }
        if ((isset($this->cfg['recovery_active']) && $this->cfg['recovery_active']) && $pnlTdy >= 0) {
            lI("[RECOVERY] PnL recuperado → modo normal");
            dbx(function($d) { return $d->prepare("UPDATE grid_configs SET recovery_active=0,spacing_pct=? WHERE symbol=?")->execute([G_BASE_SPACING, G_SYM]); });
            $this->cfg['recovery_active'] = 0; $this->cfg['spacing_pct'] = G_BASE_SPACING;
            $this->api->cancelAll(G_SYM);
            dbx(function($d) { return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE symbol=? AND status='OPEN'")->execute([G_SYM]); });
            $this->gridBuilt = false; $this->lastGridBuild = 0;
        }
    }

    private function breakoutCheck($price) {
        if (!$this->gridBuilt) return;
        $r = dbx(function($d) {
            return $d->query("SELECT MIN(price) mn,MAX(price) mx FROM grid_orders WHERE symbol='" . G_SYM . "' AND status='OPEN'")->fetch();
        });
        if (!$r || !$r['mn']) return;
        $range = (float)$r['mx'] - (float)$r['mn']; $margin = $range * 0.30;
        if ($price < (float)$r['mn'] - $margin || $price > (float)$r['mx'] + $margin) {
            $lastFill = dbx(function($d) {
                return $d->query("SELECT MAX(filled_at) FROM grid_orders WHERE symbol='" . G_SYM . "' AND status='FILLED' AND filled_at IS NOT NULL")->fetchColumn();
            });
            if ($lastFill && (time() - strtotime($lastFill)) < 90) {
                lI(sprintf("[BREAKOUT] $%.2f fuera rango pero fill reciente (%ds), esperando...", $price, time() - strtotime($lastFill)));
                return;
            }
            lI(sprintf("[BREAKOUT] $%.2f fuera [%.2f-%.2f] → rebuild", $price, $r['mn'], $r['mx']));
            $this->api->cancelAll(G_SYM);
            dbx(function($d) { return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE symbol=? AND status='OPEN'")->execute([G_SYM]); });
            $this->gridBuilt = false; $this->lastGridBuild = 0;
        }
    }

    private function getPnlToday() {
        try {
            $r = dbx(function($d) {
                return $d->query("SELECT COALESCE(SUM(pnl_usd),0) AS p FROM grid_orders WHERE symbol='" . G_SYM . "' AND grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE()")->fetch();
            });
            return $r ? (float)$r['p'] : 0.0;
        } catch (Exception $e) { lE("[PNL] " . $e->getMessage()); return 0.0; }
    }

    private function logCycleSummary($price) {
        $pnl = $this->getPnlToday();
        $openCnt = dbx(function($d) { return $d->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='" . G_SYM . "' AND status='OPEN'")->fetchColumn(); }) ?? 0;
        $fillsCnt = dbx(function($d) { return $d->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='" . G_SYM . "' AND grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE()")->fetchColumn(); }) ?? 0;
        lI(sprintf("[CICLO #%d] $%.2f | PnL hoy=%.4f USDT | Abiertos=%d | Fills hoy=%d | Grid=%s | Dir=%s",
            $this->cycleN, $price, $pnl, $openCnt, $fillsCnt,
            $this->gridBuilt ? 'ON' : 'OFF', isset($this->cfg['direction']) ? $this->cfg['direction'] : '?'));
    }

    private function checkControl() {
        global $CTRL; if (!file_exists($CTRL)) return;
        $cmd = json_decode(file_get_contents($CTRL), true); @unlink($CTRL);
        if (!is_array($cmd)) return;
        switch (isset($cmd['action']) ? $cmd['action'] : '') {
            case 'stop': $this->running = false; lI("[CTL] Stop"); break;
            case 'force_ai': $this->lastAI = 0; lI("[CTL] Forzando IA"); break;
            case 'reset_grid':
                $this->api->cancelAll(G_SYM);
                dbx(function($d) { return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE symbol=? AND status='OPEN'")->execute([G_SYM]); });
                $this->gridBuilt = false; $this->lastGridBuild = 0; lI("[CTL] Grid reset"); break;
        }
    }

    private function writeStatus($price) {
        global $STATUS; $cfg = $this->cfg ?? []; $pnlTdy = $this->getPnlToday(); $positions = [];
        try { $positions = $this->api->positions(G_SYM); } catch (Exception $e) {}
        $pnl1h = dbx(function($d) {
            return $d->query("SELECT COALESCE(SUM(pnl_usd),0) p, COUNT(*) c FROM grid_orders WHERE symbol='" . G_SYM . "' AND grid_role='EXIT' AND status='FILLED' AND filled_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)")->fetch();
        });
        $avgPnlPerFill = (float)(dbx(function($d) {
            return $d->query("SELECT COALESCE(AVG(pnl_usd),0) FROM grid_orders WHERE symbol='" . G_SYM . "' AND grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE()")->fetchColumn();
        }) ?? 0);
        $mode   = (isset($cfg['recovery_active']) && $cfg['recovery_active']) ? 'RECOVERY' : 'NORMAL';
        $balance = $this->api->balance();
        
        file_put_contents($STATUS, json_encode([
            'ts' => date('Y-m-d H:i:s'), 'mode' => $mode, 'ai_engine' => 'Grid v15.4',
            'leverage' => G_LEVERAGE, 'real_balance' => $balance,
            'ml_accuracy' => $this->ml->getAccuracy(),
            'pairs' => [G_SYM => [
                'price'          => $price,
                'direction'      => isset($cfg['direction']) ? $cfg['direction'] : 'SIDEWAYS',
                'confidence'     => (int)(isset($cfg['confidence']) ? $cfg['confidence'] : 50),
                'ai_reason'      => isset($cfg['ai_reason']) ? $cfg['ai_reason'] : '',
                'last_ai_check'  => isset($cfg['last_ai_check']) ? $cfg['last_ai_check'] : null,
                'levels'         => (int)(isset($cfg['levels']) ? $cfg['levels'] : G_FIXED_LEVELS),
                'long_levels'    => (int)(isset($cfg['long_levels']) ? $cfg['long_levels'] : G_LONG_LEVELS),
                'short_levels'   => (int)(isset($cfg['short_levels']) ? $cfg['short_levels'] : G_SHORT_LEVELS),
                'spacing_pct'    => (float)(isset($cfg['spacing_pct']) ? $cfg['spacing_pct'] : G_BASE_SPACING),
                'leverage'       => G_LEVERAGE,
                'pnl_today'      => round($pnlTdy, 6),
                'peak_pnl'       => round($this->peakPnl, 6),
                'recovery_active'=> (bool)(isset($cfg['recovery_active']) && $cfg['recovery_active']),
                'grid_built'     => $this->gridBuilt,
                'fills_per_hour' => (int)(isset($pnl1h['c']) ? $pnl1h['c'] : 0),
                'pnl_1h'         => round((float)(isset($pnl1h['p']) ? $pnl1h['p'] : 0), 4),
                'avg_pnl_fill'   => round((float)$avgPnlPerFill, 4),
                'cycle_n'        => $this->cycleN,
                'real_positions' => $positions,
                'atr_predicted'  => $this->last_atr_predicho,
                'vl_used'        => ($this->last_vl_result !== null),
                'vl_direction'   => $this->last_vl_result['direction'] ?? null,
                'vl_confidence'  => $this->last_vl_result['confidence'] ?? null,
            ]],
        ], JSON_PRETTY_PRINT));
    }

    private function appendConf($conf, $dir) {
        global $CONF_HIST; $arr = [];
        if (file_exists($CONF_HIST)) $arr = json_decode(file_get_contents($CONF_HIST), true) ?? [];
        $arr[] = ['time' => date('Y-m-d H:i:s'), 'confidence' => $conf, 'direction' => $dir];
        if (count($arr) > 500) $arr = array_slice($arr, -500);
        file_put_contents($CONF_HIST, json_encode($arr));
    }

    public function stop() { $this->running = false; lI("[BOT] Stop señal recibida"); }
}

// ════════════════════════════════════════════════════════
// 13. BOOTSTRAP
// ════════════════════════════════════════════════════════
dbInit();
$api = new BybitFutures($BK, $BS, $TN);
$ai  = new GridAI();
$ml  = new GridML($ML_W);
$bot = new GridManager($api, $ai, $ml);
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function() use ($bot) { $bot->stop(); });
    pcntl_signal(SIGINT,  function() use ($bot) { $bot->stop(); });
    pcntl_signal(SIGHUP,  function() {});
}
$bot->run();
?>
