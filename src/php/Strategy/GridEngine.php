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

        if ($direction === 'LONG' || $direction === 'BOTH') {
            for ($i = 1; $i <= $longLevels; $i++) {
                $entryPx = $price * (1 - $spacingPct * $i);
                $exitPx = $entryPx * (1 + $spacingPct * 2);
                $orders[] = [
                    'level' => $i, 'side' => 'BUY', 'role' => 'ENTRY',
                    'entry' => round($entryPx, 2), 'exit' => round($exitPx, 2),
                ];
            }
        }

        if ($direction === 'SHORT' || $direction === 'BOTH') {
            for ($i = 1; $i <= $shortLevels; $i++) {
                $entryPx = $price * (1 + $spacingPct * $i);
                $exitPx = $entryPx * (1 - $spacingPct * 2);
                $orders[] = [
                    'level' => -$i, 'side' => 'SELL', 'role' => 'ENTRY',
                    'entry' => round($entryPx, 2), 'exit' => round($exitPx, 2),
                ];
            }
        }

        return $orders;
    }

    /**
     * Compares local open orders against API open orders to resolve fills and cancellations.
     *
     * @param array $localOrders  Rows from DB with keys: id, order_id, side, grid_level, grid_role, price, qty, status, created_at
     * @param array $apiOrders    Keyed by orderId => ['status' => 'New'|'Filled'|...]
     * @return array ['fills' => [...], 'canceled' => [...]]
     */
    public function resolveFills(array $localOrders, array $apiOrders): array
    {
        $statusMap = [
            'New'              => 'NEW',
            'PartiallyFilled'  => 'PARTIALLY_FILLED',
            'Filled'           => 'FILLED',
            'Cancelled'        => 'CANCELED',
            'Rejected'         => 'CANCELED',
            'Expired'          => 'CANCELED',
        ];

        $fills = [];
        $canceled = [];

        foreach ($localOrders as $order) {
            $oid = $order['order_id'] ?? '';
            $apiOrder = $apiOrders[$oid] ?? null;

            if ($apiOrder === null) {
                continue;
            }

            $mappedStatus = $statusMap[$apiOrder['status']] ?? 'UNKNOWN';

            if ($mappedStatus === 'FILLED') {
                $fillPrice = isset($apiOrder['avgPrice']) && (float)$apiOrder['avgPrice'] > 0
                    ? (float)$apiOrder['avgPrice']
                    : (float)$order['price'];
                $fills[] = [
                    'order'      => $order,
                    'api'        => $apiOrder,
                    'fill_price' => $fillPrice,
                ];
            } elseif ($mappedStatus === 'CANCELED') {
                $canceled[] = [
                    'order' => $order,
                    'api'   => $apiOrder,
                ];
            }
        }

        return ['fills' => $fills, 'canceled' => $canceled];
    }

    /**
     * After an EXIT order fills, calculates the new ENTRY order on the opposite side.
     *
     * @param array  $exitOrder      The filled exit order row (keys: grid_level, side, price, qty)
     * @param float  $currentPrice   Current market price for calculating new entry
     * @param float  $spacing        Spacing as a percentage (e.g. 0.003 for 0.3%)
     * @param float  $qty            Order quantity
     * @param int    $pricePrecision Price precision (decimal places)
     * @param array  $activeLevels   Grid levels that already have an open ENTRY (grid_level values)
     * @return array|null  New entry order params, or null if recycling is skipped
     */
    public function recycleEntry(
        array $exitOrder,
        float $currentPrice,
        float $spacing,
        float $qty,
        int $pricePrecision,
        array $activeLevels = []
    ): ?array {
        $level = $exitOrder['grid_level'];

        if (in_array($level, $activeLevels, true)) {
            return null;
        }

        $newSide = $exitOrder['side'] === 'SELL' ? 'BUY' : 'SELL';
        $newPx = $newSide === 'BUY'
            ? round($currentPrice * (1 - $spacing), $pricePrecision)
            : round($currentPrice * (1 + $spacing), $pricePrecision);

        return [
            'level'     => $level,
            'side'      => $newSide,
            'role'      => 'ENTRY',
            'entry'     => $newPx,
            'qty'       => $qty,
            'is_recycle' => true,
        ];
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
