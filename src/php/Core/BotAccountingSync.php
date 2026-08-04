<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class BotAccountingSync
{
    public static function sync(PDO $pdo, \BinanceBot\Exchange\BybitFutures $api, string $symbol = 'ETHUSDT'): array
    {
        // 1. Get bot's total realized PnL (all time, not just today)
        $stmt = $pdo->query("
            SELECT COALESCE(SUM(pnl_usd), 0) AS realized_pnl
            FROM grid_orders
            WHERE symbol = '" . $symbol . "' 
            AND grid_role = 'EXIT' 
            AND status = 'FILLED'
        ");
        $realizedPnl = (float)($stmt->fetch()['realized_pnl'] ?? 0);

        // 2. Get unrealized PnL from open positions on Bybit
        $unrealizedPnl = self::getUnrealizedPnl($api, $symbol);

        // 3. Total bot PnL
        $botPnlTotal = $realizedPnl + $unrealizedPnl;

        // 4. Get real balance from Bybit (wallet balance for the trading symbol's quote currency)
        $realBalance = self::getRealBalance($api);

        // 5. Get wallet held (credited but not deployed)
        $walletHeld = Accounting::walletHeld($pdo);

        // 6. Update NAV
        Accounting::updateNav($pdo, $realBalance, $walletHeld, $botPnlTotal);

        return [
            'ok' => true,
            'realized_pnl' => $realizedPnl,
            'unrealized_pnl' => $unrealizedPnl,
            'bot_pnl_total' => $botPnlTotal,
            'real_balance' => $realBalance,
            'wallet_held' => $walletHeld,
            'nav' => Accounting::currentNav($pdo),
        ];
    }

    private static function getRealBalance(\BinanceBot\Exchange\BybitFutures $api): float
    {
        try {
            return (float)$api->balance();
        } catch (\Throwable $e) {
            error_log('[BotAccountingSync] getRealBalance: ' . $e->getMessage());
            return 0.0;
        }
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