<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;
use BinanceBot\Exchange\BybitFutures;

/**
 * Reconciliación del ledger interno contra el saldo real del exchange.
 * Ledger: NAV × unidades totales; Exchange: saldo wallet + PnL no realizado.
 */
class Reconciliation
{
    public static function reconcile(PDO $pdo, BybitFutures $client, string $symbol = 'ETHUSDT'): array
    {
        $ledger = Accounting::currentNav($pdo) * Accounting::totalUnits($pdo);
        $wallet = (float)$client->balance();
        $pnl = 0.0;
        foreach ($client->positions($symbol) as $pos) {
            $pnl += (float)($pos['unRealizedProfit'] ?? 0);
        }
        $exchange = $wallet + $pnl;
        $diferencia = $exchange - $ledger;
        return [
            'ledger_total' => round($ledger, 2),
            'exchange_total' => round($exchange, 2),
            'diferencia' => round($diferencia, 2),
            'ok' => abs($diferencia) < 0.50,
        ];
    }
}
