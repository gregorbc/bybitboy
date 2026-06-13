<?php
/**
 * websocket_server.php v3.1 – Grid Bot Dashboard WebSocket Server
 * Ejecutar: php websocket_server.php
 * 
 * Mejoras:
 *  - Envío de datos completos cada 1s
 *  - Manejo de token de seguridad
 *  - Logging básico en consola
 *  - Datos: ticker, status, órdenes, fills, posiciones, PnL, logs, confianza
 */
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
require __DIR__ . '/vendor/autoload.php';

$cfgFile = '/home/erika/config/config.json';
if (!file_exists($cfgFile)) $cfgFile = __DIR__ . '/config.json';
if (!file_exists($cfgFile)) { fwrite(STDERR, "ERROR: config.json no encontrado.\n"); exit(1); }
$cfg = json_decode(file_get_contents($cfgFile), true);
if (!is_array($cfg)) $cfg = [];

$symbol    = 'ETHUSDT';
$logFile   = $cfg['paths']['log'] ?? '/home/erika/config/bot.log';
$statusFile = $cfg['paths']['status'] ?? (dirname($cfgFile) . '/grid_status.json');
$pidFile    = $cfg['paths']['pid'] ?? (dirname($cfgFile) . '/grid_bot.pid');
$confHist   = $cfg['paths']['conf_hist'] ?? (dirname($cfgFile) . '/grid_confidence.json');
$dbConfig   = $cfg['mysql'] ?? [];
$wsToken    = $cfg['ws_token'] ?? '';
$bybitKey    = $cfg['bybit']['api_key']    ?? '';
$bybitSecret = $cfg['bybit']['api_secret'] ?? '';
$bybitTest   = (bool)($cfg['bybit']['testnet'] ?? false);
$bybitBase   = $bybitTest ? 'https://api-demo.bybit.com' : 'https://api.bybit.com';

function bybitPub($path, $params = []) {
    global $bybitBase;
    $url = $bybitBase . $path . '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $r = curl_exec($ch); curl_close($ch);
    $d = json_decode((string)$r, true);
    return ($d['retCode'] ?? -1) === 0 ? ($d['result'] ?? null) : null;
}

function getBybitBalance(string $key, string $secret, string $base): float {
    if (empty($key) || empty($secret)) return 0.0;
    $ts = (string)(intval(microtime(true) * 1000));
    $recv = '8000';
    $params = ['accountType' => 'UNIFIED'];
    ksort($params);
    $query = http_build_query($params);
    $signStr = $ts . $key . $recv . $query;
    $sign = hash_hmac('sha256', $signStr, $secret);
    $headers = ["X-BAPI-API-KEY: $key","X-BAPI-TIMESTAMP: $ts","X-BAPI-RECV-WINDOW: $recv","X-BAPI-SIGN: $sign"];
    $url = $base . '/v5/account/wallet-balance?' . $query;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_HTTPHEADER => $headers]);
    $resp = curl_exec($ch); curl_close($ch);
    if (!$resp) return 0.0;
    $data = json_decode($resp, true);
    if (($data['retCode'] ?? -1) !== 0) return 0.0;
    foreach ($data['result']['list'] ?? [] as $acc) {
        $avail = (float)($acc['totalAvailableBalance'] ?? 0);
        if ($avail > 0) return $avail;
        foreach ($acc['coin'] ?? [] as $c) {
            if (($c['coin'] ?? '') !== 'USDT') continue;
            foreach (['availableToWithdraw','availableBalance','walletBalance','equity'] as $fld) {
                $v = (float)($c[$fld] ?? 0);
                if ($v > 0) return $v;
            }
        }
        $eq = (float)($acc['totalEquity'] ?? 0);
        if ($eq > 0) return $eq;
    }
    return 0.0;
}

function getBybitPositions(string $key, string $secret, string $base, string $symbol): array {
    if (empty($key) || empty($secret)) return [];
    $ts = (string)(intval(microtime(true) * 1000));
    $recv = '8000';
    $params = ['category' => 'linear', 'symbol' => $symbol];
    ksort($params);
    $query = http_build_query($params);
    $signStr = $ts . $key . $recv . $query;
    $sign = hash_hmac('sha256', $signStr, $secret);
    $headers = ["X-BAPI-API-KEY: $key","X-BAPI-TIMESTAMP: $ts","X-BAPI-RECV-WINDOW: $recv","X-BAPI-SIGN: $sign"];
    $url = $base . '/v5/position/list?' . $query;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_HTTPHEADER => $headers]);
    $resp = curl_exec($ch); curl_close($ch);
    if (!$resp) return [];
    $data = json_decode($resp, true);
    if (($data['retCode'] ?? -1) !== 0) return [];
    $positions = [];
    foreach ($data['result']['list'] ?? [] as $p) {
        $sz = (float)($p['size'] ?? 0);
        if ($sz < 0.001) continue;
        $positions[] = [
            'positionAmt'      => $p['side'] === 'Buy' ? $sz : -$sz,
            'entryPrice'       => (float)($p['avgPrice'] ?? 0),
            'unRealizedProfit' => (float)($p['unrealisedPnl'] ?? 0),
            'liquidationPrice' => (float)($p['liqPrice'] ?? 0),
            'side'             => $p['side'],
            'size'             => $sz,
        ];
    }
    return $positions;
}

function getDB($dbConfig) {
    if (empty($dbConfig['host'])) return null;
    try {
        $pdo = new PDO("mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4", $dbConfig['user'], $dbConfig['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    } catch (Exception $e) { return null; }
}

function botRunning($pidFile, $logFile): bool {
    if (file_exists($pidFile)) {
        $p = trim(file_get_contents($pidFile));
        if ($p && ctype_digit($p) && file_exists("/proc/$p")) return true;
    }
    return file_exists($logFile) && (time() - filemtime($logFile)) < 90;
}

function getUptime($pf): string {
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

class GridWebSocket implements MessageComponentInterface {
    protected $clients;
    private $loop;
    private $dbConfig;
    private $wsToken;
    private $bybitKey, $bybitSecret, $bybitBase;
    private $logFile, $statusFile, $pidFile, $confHist;

    public function __construct($dbConfig, $wsToken, $bybitKey, $bybitSecret, $bybitBase, $logFile, $statusFile, $pidFile, $confHist) {
        $this->clients     = new \SplObjectStorage;
        $this->dbConfig    = $dbConfig;
        $this->wsToken     = $wsToken;
        $this->bybitKey    = $bybitKey;
        $this->bybitSecret = $bybitSecret;
        $this->bybitBase   = $bybitBase;
        $this->logFile     = $logFile;
        $this->statusFile  = $statusFile;
        $this->pidFile     = $pidFile;
        $this->confHist    = $confHist;
    }

    public function onOpen(ConnectionInterface $conn) {
        $queryString = $conn->httpRequest->getUri()->getQuery();
        parse_str($queryString, $params);
        $providedToken = $params['token'] ?? '';
        if (!empty($this->wsToken) && $providedToken !== $this->wsToken) {
            echo "Conexión rechazada (token inválido): {$conn->resourceId}\n";
            $conn->close();
            return;
        }
        $this->clients->attach($conn);
        echo "Cliente conectado: {$conn->resourceId}\n";
        $conn->send(json_encode($this->collectData()));
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "Cliente desconectado: {$conn->resourceId}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }

    public function onMessage(ConnectionInterface $from, $msg) {}

    public function setLoop($loop) {
        $this->loop = $loop;
        $loop->addPeriodicTimer(1, function () {
            $data = $this->collectData();
            $json = json_encode($data);
            foreach ($this->clients as $client) {
                try {
                    $client->send($json);
                } catch (Exception $e) {
                    echo "Error sending to client: " . $e->getMessage() . "\n";
                    $this->clients->detach($client);
                    $client->close();
                }
            }
        });
    }

    private function getTicker() {
        $t = bybitPub('/v5/market/tickers', ['category' => 'linear', 'symbol' => 'ETHUSDT']);
        if ($t && !empty($t['list'])) {
            $tick = $t['list'][0];
            return [
                'price'       => (float)($tick['lastPrice'] ?? 0),
                'change_pct'  => round((float)($tick['price24hPcnt'] ?? 0) * 100, 2),
                'high24h'     => (float)($tick['highPrice24h'] ?? 0),
                'low24h'      => (float)($tick['lowPrice24h'] ?? 0),
                'volume24h'   => (float)($tick['volume24h'] ?? 0),
                'bid'         => (float)($tick['bid1Price'] ?? 0),
                'ask'         => (float)($tick['ask1Price'] ?? 0),
                'fundRate'    => (float)($tick['fundingRate'] ?? 0),
                'markPrice'   => (float)($tick['markPrice'] ?? 0),
                'oi'          => (float)($tick['openInterest'] ?? 0),
            ];
        }
        return null;
    }

    private function getStatus() {
        $running = botRunning($this->pidFile, $this->logFile);
        $uptime  = getUptime($this->pidFile);
        $result = ['bot_running' => $running, 'uptime' => $uptime, 'mode' => 'NORMAL'];
        if (file_exists($this->statusFile)) {
            $st = json_decode(file_get_contents($this->statusFile), true);
            if ($st) {
                $pj = $st['pairs']['ETHUSDT'] ?? [];
                $result['mode'] = $st['mode'] ?? 'NORMAL';
                $result['pair'] = [
                    'confidence'      => (int)($pj['confidence'] ?? 50),
                    'direction'       => $pj['direction'] ?? 'SIDEWAYS',
                    'ai_reason'       => $pj['ai_reason'] ?? '',
                    'last_ai_check'   => $pj['last_ai_check'] ?? null,
                    'levels'          => (int)($pj['levels'] ?? 16),
                    'long_levels'     => (int)($pj['long_levels'] ?? 8),
                    'short_levels'    => (int)($pj['short_levels'] ?? 8),
                    'spacing_pct'     => (float)($pj['spacing_pct'] ?? 0.0008),
                    'pnl_today'       => $pj['pnl_today'] ?? 0,
                    'peak_pnl'        => $pj['peak_pnl'] ?? 0,
                    'recovery_active' => (bool)($pj['recovery_active'] ?? false),
                    'open_entries'    => $pj['open_entries'] ?? 0,
                    'open_exits'      => $pj['open_exits'] ?? 0,
                    'grid_built'      => $pj['grid_built'] ?? true,
                    'cycle_n'         => $pj['cycle_n'] ?? 0,
                    'ml_accuracy'     => $st['ml_accuracy'] ?? 0,
                ];
            }
        }
        $db = getDB($this->dbConfig);
        if ($db) {
            try {
                $today = $db->query("SELECT COALESCE(SUM(pnl_usd),0) p, COUNT(*) c FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE()")->fetch();
                $total = $db->query("SELECT COALESCE(SUM(pnl_usd),0) p, COUNT(*) c FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED'")->fetch();
                $wr    = $db->query("SELECT COUNT(*) t, SUM(CASE WHEN pnl_usd>0 THEN 1 ELSE 0 END) w FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED'")->fetch();
                $openCnt = (int)$db->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='ETHUSDT' AND status='OPEN'")->fetchColumn();
                if (!isset($result['pair'])) $result['pair'] = [];
                $result['pair']['fills_today'] = (int)($today['c'] ?? 0);
                $result['pair']['pnl_today']   = round((float)($today['p'] ?? 0), 6);
                $result['pair']['fills_total'] = (int)($total['c'] ?? 0);
                $result['pair']['pnl_total']   = round((float)($total['p'] ?? 0), 6);
                $result['win_rate']            = ($wr && (int)$wr['t'] > 0) ? round($wr['w'] / $wr['t'] * 100, 1) : 0;
                $result['open_orders']         = $openCnt;
            } catch (Exception $e) {}
        }
        return $result;
    }

    private function getOpenOrders() {
        $db = getDB($this->dbConfig);
        if (!$db) return [];
        try {
            $stmt = $db->prepare("SELECT id, grid_level AS level, side, grid_role AS role, price, qty, status, pnl_usd AS pnl, filled_at, is_recovery FROM grid_orders WHERE symbol='ETHUSDT' AND status='OPEN' ORDER BY ABS(grid_level) ASC, price DESC LIMIT 80");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) { return []; }
    }

    private function getRecentFills($limit = 50) {
        $db = getDB($this->dbConfig);
        if (!$db) return [];
        try {
            $stmt = $db->prepare("SELECT symbol, side, grid_role, price, qty, pnl_usd, filled_at, is_recovery FROM grid_orders WHERE status='FILLED' ORDER BY filled_at DESC LIMIT $limit");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Exception $e) { return []; }
    }

    private function getPnlHourly() {
        $db = getDB($this->dbConfig);
        if (!$db) return [];
        try {
            return $db->query("SELECT DATE(filled_at) d, HOUR(filled_at) h, ROUND(SUM(pnl_usd),6) p FROM grid_orders WHERE grid_role='EXIT' AND status='FILLED' AND filled_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR) GROUP BY DATE(filled_at), HOUR(filled_at) ORDER BY d, h")->fetchAll();
        } catch (Exception $e) { return []; }
    }

    private function getPnlCumulative() {
        $db = getDB($this->dbConfig);
        if (!$db) return [];
        try {
            return $db->query("SELECT DATE(filled_at) d, ROUND(SUM(pnl_usd),6) p FROM grid_orders WHERE grid_role='EXIT' AND status='FILLED' GROUP BY DATE(filled_at) ORDER BY d DESC LIMIT 14")->fetchAll();
        } catch (Exception $e) { return []; }
    }

    private function getLogs($n = 20) {
        if (!file_exists($this->logFile)) return [];
        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return array_slice($lines, -$n);
    }

    private function getConfHistory() {
        if (file_exists($this->confHist)) {
            $data = json_decode(file_get_contents($this->confHist), true);
            return is_array($data) ? array_slice($data, -80) : [];
        }
        return [];
    }

    private function collectData(): array {
        $ticker = $this->getTicker();
        $status = $this->getStatus();
        $realBalance = getBybitBalance($this->bybitKey, $this->bybitSecret, $this->bybitBase);
        $positions = getBybitPositions($this->bybitKey, $this->bybitSecret, $this->bybitBase, 'ETHUSDT');
        $totalUpnl = array_sum(array_map(fn($p) => (float)($p['unRealizedProfit'] ?? 0), $positions));

        return [
            'type'               => 'full',
            'ticker'             => $ticker,
            'bot_running'        => $status['bot_running'] ?? false,
            'uptime'             => $status['uptime'] ?? '--',
            'mode'               => $status['mode'] ?? 'NORMAL',
            'pair'               => $status['pair'] ?? null,
            'win_rate'           => $status['win_rate'] ?? null,
            'open_orders'        => $status['open_orders'] ?? null,
            'orders'             => $this->getOpenOrders(),
            'recent_fills'       => $this->getRecentFills(50),
            'pnl_hourly'         => $this->getPnlHourly(),
            'pnl_cumulative'     => $this->getPnlCumulative(),
            'positions'          => $positions,
            'total_upnl'         => $totalUpnl,
            'real_balance'       => $realBalance,
            'logs'               => $this->getLogs(15),
            'confidence_history' => $this->getConfHistory(),
        ];
    }
}

$ws = new GridWebSocket($dbConfig, $wsToken, $bybitKey, $bybitSecret, $bybitBase, $logFile, $statusFile, $pidFile, $confHist);
$server = IoServer::factory(new HttpServer(new WsServer($ws)), 8082);
echo "=== Grid Bot WebSocket Server v3.1 ===\nEscuchando en puerto 8082\n";
if (!empty($wsToken)) echo "Autenticación por token activada\n";
else echo "ADVERTENCIA: Sin token de seguridad (configurar 'ws_token' en config.json)\n";
$ws->setLoop($server->loop);
$server->run();
?>