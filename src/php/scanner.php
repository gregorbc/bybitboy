#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use BinanceBot\Core\Config;
use BinanceBot\Core\Database;
use BinanceBot\Core\DepositScanner;
use BinanceBot\Core\Networks;
use BinanceBot\Core\RpcClient;
use BinanceBot\Core\Schema;

// Función con reconexión automática (patrón de bot.php)
function getPdo(): ?\PDO {
    static $pdo = null;
    static $ts = 0;
    
    if ($pdo === null || time() - $ts > 30) {
        Database::reset();
        $db = Database::getInstance();
        $pdo = $db->isConnected() ? $db->getPdo() : null;
        if ($pdo) {
            try { $pdo->query('SELECT 1'); } catch (\Throwable $e) { $pdo = null; }
        }
        $ts = time();
    }
    return $pdo;
}

$pdo = getPdo();
if (!$pdo) {
    fwrite(STDERR, "ERROR: sin conexión MySQL\n");
    exit(1);
}
Schema::createTables($pdo);

$cfg = Config::getInstance();
$interval = (int)($cfg->get('platform.scan_interval_sec', 30));
$minAmount = (float)($cfg->get('platform.min_deposit', 1.0));

error_log('[scanner] iniciado (intervalo ' . $interval . 's)');

while (true) {
    $pdo = getPdo();
    if (!$pdo) {
        error_log('[scanner] sin DB, reintentando...');
        sleep($interval);
        continue;
    }

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
    sleep($interval);
}