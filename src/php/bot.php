#!/usr/bin/env php
<?php
/**
 * ETH/USDT GRID BOT v15.4 – FINAL
 * - Fix confirmación de posiciones acumuladas (permite tamaño >= esperado)
 * - Fix ML: ahora se espera usar LogisticRegression (pesos direccionales)
 * - Mejora reciclaje de ENTRY cuando ya existe posición neta acumulada
 * - Mayor tiempo de confirmación de posición (12s) para reducir falsos negativos
 * - Filtro de modelos ML: ignora pesos con accuracy < 85%
 *
 * Refactor: BybitFutures, GridML y GridAI extraídos a src/php/Exchange y src/php/Strategy
 *           cargados vía PSR-4 autoloading (BinanceBot\\ -> src/php/)
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Forzar carga de ChartVL para que se defina la función global
// analyzeChartWithVL() (usada por GridManager internamente).
class_exists(\BinanceBot\Strategy\ChartVL::class);

use BinanceBot\Exchange\BybitFutures;
use BinanceBot\Strategy\GridML;
use BinanceBot\Strategy\GridAI;
use BinanceBot\Strategy\ChartVL;
use BinanceBot\Strategy\GridManager;

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
$ENV    = (string)cv($cfg, ['bybit', 'environment'], ''); // 'mainnet' | 'testnet' | 'demo'
$DB_H   = trim((string)cv($cfg, ['mysql', 'host'], 'localhost'));
$DB_N   = trim((string)cv($cfg, ['mysql', 'dbname'], ''));
$DB_U   = trim((string)cv($cfg, ['mysql', 'user'], ''));
$DB_P   = trim((string)cv($cfg, ['mysql', 'password'], ''));
$ML_W   = trim((string)cv($cfg, ['ml', 'weights_file'], 'ml_weights_v2.json'));
$LP_CFG = cv($cfg, ['liquidation_protection'], []);

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
define('G_FEE_SAFETY',    max(1.0, (float)cv($cfg, ['fees', 'safety'], 1.5)));
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
        INDEX idx_filled (filled_at),
        INDEX idx_pnl_query (symbol, grid_role, status, filled_at)
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



// 12. GRID MANAGER → see src/php/Strategy/GridManager.php

// ════════════════════════════════════════════════════════
// 13. BOOTSTRAP
// ════════════════════════════════════════════════════════
dbInit();
$api = new BybitFutures($BK, $BS, $TN, $ENV ?: null);
$ai  = new GridAI();
$ml  = new GridML($ML_W);
$lp  = new \BinanceBot\Strategy\LiquidationProtector($api, $LP_CFG);
$bot = new \BinanceBot\Strategy\GridManager($api, $ai, $ml, $lp);
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function() use ($bot) { $bot->stop(); });
    pcntl_signal(SIGINT,  function() use ($bot) { $bot->stop(); });
    pcntl_signal(SIGHUP,  function() {});
}
$bot->run();
?>
