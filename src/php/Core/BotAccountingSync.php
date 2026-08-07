<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class BotAccountingSync
{
    public static function sync(PDO $pdo, \BinanceBot\Exchange\BybitFutures $api, string $symbol = 'ETHUSDT'): array
    {
        // 1. Get bot's total realized PnL (all time, not just today)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(pnl_usd), 0) AS realized_pnl
            FROM grid_orders
            WHERE symbol = ?
            AND grid_role = 'EXIT'
            AND status = 'FILLED'
        ");
        $stmt->execute([$symbol]);
        $realizedPnl = (float)($stmt->fetch()['realized_pnl'] ?? 0);

        // 2. Get unrealized PnL from open positions on Bybit
        $unrealizedPnl = self::getUnrealizedPnl($api, $symbol);

        // 3. Total bot PnL
        $botPnlTotal = $realizedPnl + $unrealizedPnl;

        // 5. Get wallet held (credited but not deployed)
        $walletHeld = Accounting::walletHeld($pdo);

        // 6. Equity = capital desplegado (unidades) + PnL acumulado.
        //    NO se usa el saldo total de la cuenta exchange: en demo/testnet puede
        //    estar inflado con saldo virtual y dispararía el NAV a valores absurdos.
        $deployedCapital = Accounting::totalUnits($pdo);
        $equity = $deployedCapital + $botPnlTotal;

        // 7. Update NAV. El monto en wallet ya está reconocido dentro de totalUnits
        //    (se emiten unidades al acreditar el depósito), así que no se vuelve a sumar.
        Accounting::updateNav($pdo, $equity, 0.0, $botPnlTotal);

        return [
            'ok' => true,
            'realized_pnl' => $realizedPnl,
            'unrealized_pnl' => $unrealizedPnl,
            'bot_pnl_total' => $botPnlTotal,
            'real_balance' => $equity,
            'wallet_held' => $walletHeld,
            'nav' => Accounting::currentNav($pdo),
        ];
    }

    private static function getUnrealizedPnl(\BinanceBot\Exchange\BybitFutures $api, string $symbol): float
    {
        try {
            $positions = $api->positions($symbol);
            $total = 0.0;
            foreach ($positions as $pos) {
                $total += (float)($pos['unRealizedProfit'] ?? 0);
            }
            return $total;
        } catch (\Throwable $e) {
            error_log('[BotAccountingSync] getUnrealizedPnl: ' . $e->getMessage());
            return 0.0;
        }
    }
}