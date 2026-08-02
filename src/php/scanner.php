#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use BinanceBot\Core\Accounting;
use BinanceBot\Core\Config;
use BinanceBot\Core\Database;
use BinanceBot\Core\DepositScanner;
use BinanceBot\Core\Networks;
use BinanceBot\Core\RpcClient;
use BinanceBot\Core\Schema;

$db = Database::getInstance();
if (!$db->isConnected()) {
    fwrite(STDERR, "ERROR: sin conexión MySQL\n");
    exit(1);
}
$pdo = $db->getPdo();
Schema::createTables($pdo);

$cfg = Config::getInstance();
$interval = (int)($cfg->get('platform.scan_interval_sec', 30));
$minAmount = (float)($cfg->get('platform.min_deposit', 1.0));
$statusFile = (string)($cfg->get('paths.status', dirname(__DIR__, 2) . '/config/grid_status.json'));

error_log('[scanner] iniciado (intervalo ' . $interval . 's)');

while (true) {
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
    try {
        $status = [];
        if (file_exists($statusFile)) {
            $status = json_decode((string)file_get_contents($statusFile), true);
        }
        $realBalance = (float)($status['real_balance'] ?? 0);
        $pnlTotal = (float)($status['pnl_total'] ?? 0);
        if ($realBalance > 0) {
            Accounting::updateNav($pdo, $realBalance, Accounting::walletHeld($pdo), $pnlTotal);
        }
    } catch (\Throwable $e) {
        error_log('[scanner] nav error: ' . $e->getMessage());
    }
    sleep($interval);
}