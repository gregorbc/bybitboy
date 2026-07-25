<?php
declare(strict_types=1);

namespace BinanceBot\Strategy;

use Exchange\ExchangeInterface;

class GridEngine
{
    private ?ExchangeInterface $api;
    private array $orders = [];

    public function __construct(?ExchangeInterface $api = null)
    {
        $this->api = $api;
    }

    public function calcQty(float $price, int $levels, array $params): float
    {
        $capital = $params['capital'] ?? 30.0;
        $leverage = $params['leverage'] ?? 100;
        $marginSafety = $params['marginSafety'] ?? 0.65;

        $marginPerLevel = ($capital * $marginSafety) / $levels;
        $notional = $marginPerLevel * $leverage;
        $qty = $notional / $price;

        return round($qty, 4);
    }

    public function calcPnl(string $exitSide, float $entryPx, float $exitPx, float $qty): float
    {
        $diff = $exitSide === 'SELL'
            ? $exitPx - $entryPx
            : $entryPx - $exitPx;
        return round($diff * $qty, 6);
    }

    public function buildGrid(float $price, string $direction, int $longLevels, int $shortLevels, float $spacing): array
    {
        $orders = [];
        $spacingPct = $spacing / 100;

        for ($i = 1; $i <= $longLevels; $i++) {
            $entryPx = $price * (1 - $spacingPct * $i);
            $exitPx = $entryPx * (1 + $spacingPct * 2);
            $orders[] = [
                'level' => $i, 'side' => 'BUY', 'role' => 'ENTRY',
                'entry' => round($entryPx, 2), 'exit' => round($exitPx, 2),
            ];
        }

        for ($i = 1; $i <= $shortLevels; $i++) {
            $entryPx = $price * (1 + $spacingPct * $i);
            $exitPx = $entryPx * (1 - $spacingPct * 2);
            $orders[] = [
                'level' => -$i, 'side' => 'SELL', 'role' => 'ENTRY',
                'entry' => round($entryPx, 2), 'exit' => round($exitPx, 2),
            ];
        }

        return $orders;
    }

    public function getOrders(): array
    {
        return $this->orders;
    }

    public function setOrders(array $orders): void
    {
        $this->orders = $orders;
    }
}
