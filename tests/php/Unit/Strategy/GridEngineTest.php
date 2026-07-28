<?php
declare(strict_types=1);

namespace Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use BinanceBot\Strategy\GridEngine;

class GridEngineTest extends TestCase
{
    public function testCalcQtyReturnsExactValue(): void
    {
        $engine = new GridEngine();
        $qty = $engine->calcQty(1850.0, 16, [
            'capital' => 30.0,
            'leverage' => 100,
            'marginSafety' => 0.65,
        ]);
        // (30 * 0.65) / 16 * 100 / 1850 = 0.065878... → rounded to 4 decimals
        $this->assertSame(0.0659, $qty);
    }

    public function testCalcQtyUsesDefaults(): void
    {
        $engine = new GridEngine();
        $qty = $engine->calcQty(200.0, 10, []);
        // (30 * 0.65) / 10 * 100 / 200 = 0.975 → rounded to 4 decimals
        $this->assertSame(0.975, $qty);
    }

    public function testCalcPnlBuyProfit(): void
    {
        $engine = new GridEngine();
        $pnl = $engine->calcPnl('SELL', 1850.0, 1860.0, 0.07);
        // (1860 - 1850) * 0.07 = 0.7
        $this->assertSame(0.7, $pnl);
    }

    public function testCalcPnlBuyLoss(): void
    {
        $engine = new GridEngine();
        $pnl = $engine->calcPnl('SELL', 1850.0, 1840.0, 0.07);
        // (1840 - 1850) * 0.07 = -0.7
        $this->assertSame(-0.7, $pnl);
    }

    public function testCalcPnlShortProfit(): void
    {
        $engine = new GridEngine();
        $pnl = $engine->calcPnl('BUY', 1860.0, 1850.0, 0.05);
        // (1860 - 1850) * 0.05 = 0.5
        $this->assertSame(0.5, $pnl);
    }

    public function testBuildGridLongDirection(): void
    {
        $engine = new GridEngine();
        $orders = $engine->buildGrid(100.0, 'LONG', 3, 3, 1.0);

        $this->assertCount(3, $orders);
        foreach ($orders as $order) {
            $this->assertSame('BUY', $order['side']);
            $this->assertSame('ENTRY', $order['role']);
            $this->assertGreaterThan(0, $order['level']);
        }
        $this->assertSame(99.0, $orders[0]['entry']);
        $this->assertSame(98.0, $orders[1]['entry']);
        $this->assertSame(97.0, $orders[2]['entry']);
    }

    public function testBuildGridShortDirection(): void
    {
        $engine = new GridEngine();
        $orders = $engine->buildGrid(100.0, 'SHORT', 3, 3, 1.0);

        $this->assertCount(3, $orders);
        foreach ($orders as $order) {
            $this->assertSame('SELL', $order['side']);
            $this->assertSame('ENTRY', $order['role']);
            $this->assertLessThan(0, $order['level']);
        }
        $this->assertSame(101.0, $orders[0]['entry']);
        $this->assertSame(102.0, $orders[1]['entry']);
        $this->assertSame(103.0, $orders[2]['entry']);
    }

    public function testBuildGridBothDirections(): void
    {
        $engine = new GridEngine();
        $orders = $engine->buildGrid(100.0, 'BOTH', 2, 2, 0.5);

        $this->assertCount(4, $orders);
        $this->assertSame('BUY', $orders[0]['side']);
        $this->assertSame('BUY', $orders[1]['side']);
        $this->assertSame('SELL', $orders[2]['side']);
        $this->assertSame('SELL', $orders[3]['side']);
    }

    public function testBuildGridZeroLevels(): void
    {
        $engine = new GridEngine();
        $orders = $engine->buildGrid(100.0, 'LONG', 0, 0, 1.0);

        $this->assertCount(0, $orders);
    }

    public function testResolveFillsDetectsFilledOrders(): void
    {
        $engine = new GridEngine();
        $localOrders = [
            ['id' => 1, 'order_id' => '1001', 'side' => 'BUY', 'grid_level' => 1, 'grid_role' => 'ENTRY', 'price' => 99.0, 'qty' => 0.5],
            ['id' => 2, 'order_id' => '1002', 'side' => 'SELL', 'grid_level' => -1, 'grid_role' => 'ENTRY', 'price' => 101.0, 'qty' => 0.5],
        ];
        $apiOrders = [
            '1001' => ['status' => 'Filled', 'avgPrice' => 98.95],
            '1002' => ['status' => 'New'],
        ];

        $result = $engine->resolveFills($localOrders, $apiOrders);

        $this->assertCount(1, $result['fills']);
        $this->assertCount(0, $result['canceled']);
        $this->assertSame(1, $result['fills'][0]['order']['id']);
        $this->assertSame(98.95, $result['fills'][0]['fill_price']);
    }

    public function testResolveFillsDetectsCanceledOrders(): void
    {
        $engine = new GridEngine();
        $localOrders = [
            ['id' => 1, 'order_id' => '2001', 'side' => 'BUY', 'grid_level' => 1, 'grid_role' => 'ENTRY', 'price' => 99.0, 'qty' => 0.5],
            ['id' => 2, 'order_id' => '2002', 'side' => 'BUY', 'grid_level' => 2, 'grid_role' => 'ENTRY', 'price' => 98.0, 'qty' => 0.5],
        ];
        $apiOrders = [
            '2001' => ['status' => 'Cancelled'],
            '2002' => ['status' => 'Expired'],
        ];

        $result = $engine->resolveFills($localOrders, $apiOrders);

        $this->assertCount(0, $result['fills']);
        $this->assertCount(2, $result['canceled']);
    }

    public function testResolveFillsUsesFallbackPriceWhenAvgMissing(): void
    {
        $engine = new GridEngine();
        $localOrders = [
            ['id' => 3, 'order_id' => '3001', 'side' => 'BUY', 'grid_level' => 1, 'grid_role' => 'ENTRY', 'price' => 50.0, 'qty' => 1.0],
        ];
        $apiOrders = [
            '3001' => ['status' => 'Filled', 'avgPrice' => 0],
        ];

        $result = $engine->resolveFills($localOrders, $apiOrders);

        $this->assertCount(1, $result['fills']);
        $this->assertSame(50.0, $result['fills'][0]['fill_price']);
    }

    public function testResolveFillsSkipsUnknownOrders(): void
    {
        $engine = new GridEngine();
        $localOrders = [
            ['id' => 4, 'order_id' => '4001', 'side' => 'BUY', 'grid_level' => 1, 'grid_role' => 'ENTRY', 'price' => 99.0, 'qty' => 0.5],
        ];
        $apiOrders = [
            '9999' => ['status' => 'Filled'],
        ];

        $result = $engine->resolveFills($localOrders, $apiOrders);

        $this->assertCount(0, $result['fills']);
        $this->assertCount(0, $result['canceled']);
    }

    public function testRecycleEntryCreatesOppositeSideOrder(): void
    {
        $engine = new GridEngine();
        $exitOrder = ['grid_level' => 1, 'side' => 'SELL', 'price' => 101.0, 'qty' => 0.5];

        $result = $engine->recycleEntry($exitOrder, 100.0, 0.003, 0.5, 2, []);

        $this->assertNotNull($result);
        $this->assertSame('BUY', $result['side']);
        $this->assertSame(1, $result['level']);
        $this->assertSame('ENTRY', $result['role']);
        $this->assertTrue($result['is_recycle']);
        // 100.0 * (1 - 0.003) = 99.7
        $this->assertSame(99.7, $result['entry']);
    }

    public function testRecycleEntrySkipsIfLevelAlreadyActive(): void
    {
        $engine = new GridEngine();
        $exitOrder = ['grid_level' => 2, 'side' => 'BUY', 'price' => 98.0, 'qty' => 0.5];

        $result = $engine->recycleEntry($exitOrder, 100.0, 0.003, 0.5, 2, [2]);

        $this->assertNull($result);
    }

    public function testRecycleEntryForSellExit(): void
    {
        $engine = new GridEngine();
        $exitOrder = ['grid_level' => -1, 'side' => 'BUY', 'price' => 99.0, 'qty' => 0.3];

        $result = $engine->recycleEntry($exitOrder, 100.0, 0.01, 0.3, 1, []);

        $this->assertNotNull($result);
        $this->assertSame('SELL', $result['side']);
        // 100.0 * (1 + 0.01) = 101.0
        $this->assertSame(101.0, $result['entry']);
    }
}
