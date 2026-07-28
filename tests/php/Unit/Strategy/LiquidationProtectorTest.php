<?php
declare(strict_types=1);

namespace Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use BinanceBot\Strategy\LiquidationProtector;
use BinanceBot\Exchange\BybitFutures;
use Mockery;

class LiquidationProtectorTest extends TestCase
{
    protected BybitFutures|Mockery\MockInterface $api;
    protected array $config;
    protected LiquidationProtector $protector;

    protected function setUp(): void
    {
        $stateFile = sys_get_temp_dir() . '/' . LiquidationProtector::DEFAULT_STATE_FILE;
        @unlink($stateFile);

        $this->api = Mockery::mock(BybitFutures::class);
        $this->config = [
            'enabled' => true,
            'tiers' => [
                1 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 25]],
                    'actions' => [['type' => 'log_alert']],
                    'cooldown_sec' => 60,
                ],
            ],
            'global' => ['eval_interval_sec' => 8],
            'circuit_breaker' => ['max_consecutive_errors' => 5],
        ];
        $this->protector = new LiquidationProtector($this->api, $this->config);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testConstructsWithValidConfig(): void
    {
        $this->assertInstanceOf(LiquidationProtector::class, $this->protector);
    }

    public function testThrowsOnMissingTriggers(): void
    {
        $config = [
            'enabled' => true,
            'tiers' => [
                1 => [
                    'enabled' => true,
                    'actions' => [['type' => 'log_alert']],
                ],
            ],
        ];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('triggers requerido');
        new LiquidationProtector($this->api, $config);
    }

    public function testThrowsOnMissingActions(): void
    {
        $config = [
            'enabled' => true,
            'tiers' => [
                1 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 25]],
                ],
            ],
        ];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('actions requerido');
        new LiquidationProtector($this->api, $config);
    }

    public function testThrowsOnUnknownTriggerType(): void
    {
        $config = [
            'enabled' => true,
            'tiers' => [
                1 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'unknown_type', 'threshold' => 25]],
                    'actions' => [['type' => 'log_alert']],
                ],
            ],
        ];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trigger type inválido');
        new LiquidationProtector($this->api, $config);
    }

    public function testThrowsOnUnknownActionType(): void
    {
        $config = [
            'enabled' => true,
            'tiers' => [
                1 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 25]],
                    'actions' => [['type' => 'unknown_action']],
                ],
            ],
        ];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('action type inválido');
        new LiquidationProtector($this->api, $config);
    }

    public function testLoadsDefaultTiersWhenConfigEmpty(): void
    {
        $config = ['enabled' => true, 'tiers' => []];
        $prot = new LiquidationProtector($this->api, $config);
        $this->assertCount(4, $prot->getTiers()); // L1-L4 defaults
    }

    /**
     * Helper to create a position for testing
     * @param int $liqPrice
     * @param int $entryPx
     * @param string $side
     * @param float $uPnL
     * @return array
     */
    private function makePosition(int $liqPrice, int $entryPx, string $side = 'Buy', float $uPnL = -100): array
    {
        return [
            'side' => $side,
            'positionAmt' => '0.5',
            'size' => '0.5',
            'entryPrice' => (string)$entryPx,
            'liqPrice' => (string)$liqPrice,
            'unRealizedProfit' => (string)$uPnL,
            'leverage' => '100',
        ];
    }

    /**
     * Get last triggered tier level using reflection
     */
    private function getLastTriggeredLevel(LiquidationProtector $prot): int
    {
        $ref = new \ReflectionClass($prot);
        $prop = $ref->getProperty('lastTriggered');
        $prop->setAccessible(true);
        $triggered = $prop->getValue($prot);
        return $triggered ? max(array_keys($triggered)) : 0;
    }

    /**
     * Test L1 triggered by dist_liq_pct_lt trigger
     */
    public function testL1TriggeredByDistLiqPct(): void
    {
        $config = [
            'enabled' => true,
            'tiers' => [
                1 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 25]],
                    'actions' => [['type' => 'log_alert']],
                    'cooldown_sec' => 1,
                ],
                2 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 20]],
                    'actions' => [['type' => 'log_alert']],
                    'cooldown_sec' => 1,
                ],
            ],
            'global' => ['eval_interval_sec' => 1],
            'circuit_breaker' => ['max_consecutive_errors' => 5],
        ];
        $prot = new LiquidationProtector($this->api, $config);

        // Position with liqPrice=8000, entry=10000, current price=9000
        // dist_liq_pct = |9000-8000|/9000*100 = 11.11% < 25% -> triggers L1
        $positions = [$this->makePosition(8000, 10000, 'Buy', -100)];
        $balance = 1000.0;
        $price = 9000.0;

        // Mock API calls that actions might make
        $this->api->allows()->setLeverage()->andReturn(['retCode' => 0]);
        $this->api->allows()->placeOrder()->andReturn(['retCode' => 0, 'result' => ['orderId' => '123']]);
        $this->api->allows()->setMargin()->andReturn(['retCode' => 0]);
        $this->api->allows()->cancelAllOrders()->andReturn(['retCode' => 0]);
        $this->api->allows()->getPositions()->andReturn([]);
        $this->api->allows()->getWalletBalance()->andReturn(['result' => ['list' => [['coin' => 'USDT', 'walletBalance' => '1000']]]]);

        // First eval cycle (evalIntervalSec=1, so evaluate every time)
        $ref = new \ReflectionClass($prot);
        $method = $ref->getMethod('evaluate');
        $method->setAccessible(true);
        $method->invoke($prot, $price, $positions, $balance);

        $this->assertEquals(1, $this->getLastTriggeredLevel($prot), 'L1 should have been triggered');
    }

    /**
     * Test L2 triggered by free_margin_pct_lt trigger
     */
    public function testL2TriggeredByFreeMargin(): void
    {
        $config = [
            'enabled' => true,
            'tiers' => [
                1 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 5]], // Very tight, won't trigger
                    'actions' => [['type' => 'log_alert']],
                    'cooldown_sec' => 1,
                ],
                2 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'free_margin_pct_lt', 'threshold' => 25]],
                    'actions' => [['type' => 'reduce_leverage', 'target' => 50], ['type' => 'log_alert']],
                    'cooldown_sec' => 1,
                ],
            ],
            'global' => ['eval_interval_sec' => 1],
            'circuit_breaker' => ['max_consecutive_errors' => 5],
        ];
        $prot = new LiquidationProtector($this->api, $config);

        // Position: entry=10000, liq=8000, qty=0.5, price=9000
        // position value = 0.5 * 9000 = 4500
        // uPnL = (9000-10000)*0.5 = -500
        // free_margin = balance + uPnL = 1000 - 500 = 500
        // free_margin_pct = 500/4500*100 = 11.11% < 25% -> triggers L2
        $positions = [$this->makePosition(8000, 10000, 'Buy', -500)];
        $balance = 1000.0;
        $price = 9000.0;

        $this->api->allows()->setLeverage()->andReturn(['retCode' => 0]);
        $this->api->allows()->placeOrder()->andReturn(['retCode' => 0, 'result' => ['orderId' => '123']]);
        $this->api->allows()->setMargin()->andReturn(['retCode' => 0]);
        $this->api->allows()->cancelAllOrders()->andReturn(['retCode' => 0]);
        $this->api->allows()->getPositions()->andReturn([]);
        $this->api->allows()->getWalletBalance()->andReturn(['result' => ['list' => [['coin' => 'USDT', 'walletBalance' => '1000']]]]);

        $ref = new \ReflectionClass($prot);
        $method = $ref->getMethod('evaluate');
        $method->setAccessible(true);
        $method->invoke($prot, $price, $positions, $balance);

        $this->assertEquals(2, $this->getLastTriggeredLevel($prot), 'L2 should have been triggered by free_margin_pct');
    }

    /**
     * Test L3 triggered by uPnL_pct_lt trigger
     */
    public function testL3TriggeredByUpnlPct(): void
    {
        $config = [
            'enabled' => true,
            'tiers' => [
                1 => ['enabled' => true, 'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 5]], 'actions' => [['type' => 'log_alert']], 'cooldown_sec' => 1],
                2 => ['enabled' => true, 'triggers' => [['type' => 'free_margin_pct_lt', 'threshold' => 5]], 'actions' => [['type' => 'log_alert']], 'cooldown_sec' => 1],
                3 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'uPnL_pct_lt', 'threshold' => -3]],
                    'actions' => [['type' => 'add_margin', 'max_pct_free_balance' => 0.5], ['type' => 'log_alert']],
                    'cooldown_sec' => 1,
                ],
            ],
            'global' => ['eval_interval_sec' => 1],
            'circuit_breaker' => ['max_consecutive_errors' => 5],
        ];
        $prot = new LiquidationProtector($this->api, $config);

        // Position: entry=10000, liq=8000, qty=0.5, price=9500
        // uPnL = (9500-10000)*0.5 = -250
        // position value = 0.5*9500 = 4750
        // uPnL_pct = -250/4750*100 = -5.26% < -3% -> triggers L3
        // dist_liq = |9500-8000|/9500*100 = 15.78% > 5% (L1 won't trigger)
        // free_margin = 1000-250=750, free_margin_pct = 750/4750*100 = 15.78% > 5% (L2 won't trigger)
        $positions = [$this->makePosition(8000, 10000, 'Buy', -250)];
        $balance = 1000.0;
        $price = 9500.0;

        $this->api->allows()->setLeverage()->andReturn(['retCode' => 0]);
        $this->api->allows()->placeOrder()->andReturn(['retCode' => 0, 'result' => ['orderId' => '123']]);
        $this->api->allows()->setMargin()->andReturn(['retCode' => 0]);
        $this->api->allows()->cancelAllOrders()->andReturn(['retCode' => 0]);
        $this->api->allows()->getPositions()->andReturn([]);
        $this->api->allows()->getWalletBalance()->andReturn(['result' => ['list' => [['coin' => 'USDT', 'walletBalance' => '1000']]]]);

        $ref = new \ReflectionClass($prot);
        $method = $ref->getMethod('evaluate');
        $method->setAccessible(true);
        $method->invoke($prot, $price, $positions, $balance);

        $this->assertEquals(3, $this->getLastTriggeredLevel($prot), 'L3 should have been triggered by uPnL_pct');
    }

    /**
     * Test L4 triggered by dist_liq_pct_lt critical threshold
     */
    public function testL4TriggeredByDistLiqCritical(): void
    {
        $config = [
            'enabled' => true,
            'tiers' => [
                1 => ['enabled' => true, 'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 0.5]], 'actions' => [['type' => 'log_alert']], 'cooldown_sec' => 1],
                2 => ['enabled' => true, 'triggers' => [['type' => 'free_margin_pct_lt', 'threshold' => 5]], 'actions' => [['type' => 'log_alert']], 'cooldown_sec' => 1],
                3 => ['enabled' => true, 'triggers' => [['type' => 'uPnL_pct_lt', 'threshold' => -10]], 'actions' => [['type' => 'log_alert']], 'cooldown_sec' => 1],
                4 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 8]],
                    'actions' => [
                        ['type' => 'close_all_positions'],
                        ['type' => 'cancel_all_orders'],
                        ['type' => 'pause_bot_sec', 'duration' => 1],
                        ['type' => 'log_alert'],
                    ],
                    'cooldown_sec' => 1,
                ],
            ],
            'global' => ['eval_interval_sec' => 1],
            'circuit_breaker' => ['max_consecutive_errors' => 5],
        ];
        $prot = new LiquidationProtector($this->api, $config);

        // Position: entry=10000, liq=9500, qty=0.5, price=9600
        // dist_liq_pct = |9600-9500|/9600*100 = 1.04% < 8% -> triggers L4
        $positions = [$this->makePosition(9500, 10000, 'Buy', -200)];
        $balance = 1000.0;
        $price = 9600.0;

        $this->api->allows()->setLeverage()->andReturn(['retCode' => 0]);
        $this->api->allows()->placeOrder()->andReturn(['retCode' => 0, 'result' => ['orderId' => '123']]);
        $this->api->allows()->setMargin()->andReturn(['retCode' => 0]);
        $this->api->allows()->cancelAllOrders()->andReturn(['retCode' => 0]);
        $this->api->allows()->getPositions()->andReturn([]);
        $this->api->allows()->getWalletBalance()->andReturn(['result' => ['list' => [['coin' => 'USDT', 'walletBalance' => '1000']]]]);

        $ref = new \ReflectionClass($prot);
        $method = $ref->getMethod('evaluate');
        $method->setAccessible(true);
        $method->invoke($prot, $price, $positions, $balance);

        $this->assertEquals(4, $this->getLastTriggeredLevel($prot), 'L4 should have been triggered by critical dist_liq_pct');
    }

    /**
     * Edge case: Cooldown prevents immediate retrigger
     */
    public function testCooldownPreventsRetrigger(): void
    {
        $config = [
            'enabled' => true,
            'tiers' => [
                1 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 25]],
                    'actions' => [['type' => 'log_alert']],
                    'cooldown_sec' => 60,
                ],
            ],
            'global' => ['eval_interval_sec' => 1],
            'circuit_breaker' => ['max_consecutive_errors' => 5],
        ];
        $prot = new LiquidationProtector($this->api, $config);

        // Position with liqPrice=8000, entry=10000, current price=9000 -> dist 11.11% < 25% triggers L1
        $positions = [$this->makePosition(8000, 10000, 'Buy', -100)];
        $balance = 1000.0;
        $price = 9000.0;

        $ref = new \ReflectionClass($prot);
        $method = $ref->getMethod('evaluate');
        $method->setAccessible(true);

        // First evaluation triggers L1
        $method->invoke($prot, $price, $positions, $balance);
        $this->assertEquals(1, $this->getLastTriggeredLevel($prot), 'L1 should trigger on first eval');

        // Second evaluation within cooldown - should NOT trigger again
        $method->invoke($prot, $price, $positions, $balance);
        $this->assertEquals(1, $this->getLastTriggeredLevel($prot), 'Cooldown should prevent retrigger');

        // The trigger count remains at 1
        $ref2 = new \ReflectionClass($prot);
        $stateProp = $ref2->getProperty('state');
        $stateProp->setAccessible(true);
        $state = $stateProp->getValue($prot);
        $this->assertCount(1, $state['last_triggered'], 'Only one trigger recorded');
    }

    /**
     * Edge case: Only the first matching tier fires (break after first match)
     */
    public function testOnlyFirstMatchingTierFires(): void
    {
        $config = [
            'enabled' => true,
            'tiers' => [
                1 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 25]],
                    'actions' => [['type' => 'log_alert']],
                    'cooldown_sec' => 1,
                ],
                2 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 20]],
                    'actions' => [['type' => 'log_alert']],
                    'cooldown_sec' => 1,
                ],
                3 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 15]],
                    'actions' => [['type' => 'log_alert']],
                    'cooldown_sec' => 1,
                ],
            ],
            'global' => ['eval_interval_sec' => 1],
            'circuit_breaker' => ['max_consecutive_errors' => 5],
        ];
        $prot = new LiquidationProtector($this->api, $config);

        // Position with liqPrice=8000, entry=10000, current price=9000 -> dist 11.11%
        // This matches all 3 tiers (L1<25, L2<20, L3<15). Only L1 should fire.
        $positions = [$this->makePosition(8000, 10000, 'Buy', -100)];
        $balance = 1000.0;
        $price = 9000.0;

        $this->api->allows()->setLeverage()->andReturn(['retCode' => 0]);
        $this->api->allows()->placeOrder()->andReturn(['retCode' => 0, 'result' => ['orderId' => '123']]);
        $this->api->allows()->setMargin()->andReturn(['retCode' => 0]);
        $this->api->allows()->cancelAllOrders()->andReturn(['retCode' => 0]);

        $ref = new \ReflectionClass($prot);
        $method = $ref->getMethod('evaluate');
        $method->setAccessible(true);
        $method->invoke($prot, $price, $positions, $balance);

        $this->assertEquals(1, $this->getLastTriggeredLevel($prot), 'Only L1 should fire (first matching tier)');

        // Verify L2 and L3 were NOT triggered
        $this->assertNull($prot->getLastTriggered(2), 'L2 should not have been triggered');
        $this->assertNull($prot->getLastTriggered(3), 'L3 should not have been triggered');
    }

    /**
     * Edge case: Disabled tier is skipped
     */
    public function testDisabledTierSkipped(): void
    {
        $config = [
            'enabled' => true,
            'tiers' => [
                1 => [
                    'enabled' => false, // Disabled tier
                    'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 25]],
                    'actions' => [['type' => 'log_alert']],
                    'cooldown_sec' => 1,
                ],
                2 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 20]],
                    'actions' => [['type' => 'log_alert']],
                    'cooldown_sec' => 1,
                ],
            ],
            'global' => ['eval_interval_sec' => 1],
            'circuit_breaker' => ['max_consecutive_errors' => 5],
        ];
        $prot = new LiquidationProtector($this->api, $config);

        // Position with liqPrice=8000, entry=10000, current price=9000 -> dist 11.11%
        // L1 is disabled, so L2 should be the first matching tier that fires
        $positions = [$this->makePosition(8000, 10000, 'Buy', -100)];
        $balance = 1000.0;
        $price = 9000.0;

        $ref = new \ReflectionClass($prot);
        $method = $ref->getMethod('evaluate');
        $method->setAccessible(true);
        $method->invoke($prot, $price, $positions, $balance);

        $this->assertEquals(2, $this->getLastTriggeredLevel($prot), 'L1 disabled, L2 should trigger');
        $this->assertNull($prot->getLastTriggered(1), 'Disabled L1 should not be in last_triggered');
    }

    /**
     * Edge case: Circuit breaker disables protector after 5 consecutive errors
     */
    public function testCircuitBreakerAfter5Errors(): void
    {
        $config = [
            'enabled' => true,
            'tiers' => [
                1 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 25]],
                    'actions' => [['type' => 'reduce_leverage', 'target' => 50]],
                    'cooldown_sec' => 0, // No cooldown to allow re-evaluation
                ],
            ],
            'global' => ['eval_interval_sec' => 1],
            'circuit_breaker' => ['max_consecutive_errors' => 5],
        ];
        $prot = new LiquidationProtector($this->api, $config);

        // Mock the position with symbol so setLeverage is called
        $positions = [[
            'symbol' => 'BTCUSDT',
            'side' => 'Buy',
            'positionAmt' => '0.5',
            'size' => '0.5',
            'entryPrice' => '10000',
            'liqPrice' => '8000',
            'unRealizedProfit' => '-100',
            'leverage' => '100',
        ]];
        $balance = 1000.0;
        $price = 9000.0;

        // Configure API mock to always throw exception
        $this->api->allows()->setLeverage()->andThrow(new \RuntimeException('API down'));

        $ref = new \ReflectionClass($prot);
        $method = $ref->getMethod('evaluate');
        $method->setAccessible(true);

        // Trigger errors 6 times - first 5 should increment, 5th should disable
        for ($i = 0; $i < 6; $i++) {
            $method->invoke($prot, $price, $positions, $balance);
        }

        // Verify protector is disabled after 5 consecutive errors
        $this->assertTrue($prot->isDisabled(), 'Protector should be disabled after 5 consecutive errors');
        $this->assertGreaterThanOrEqual(5, $prot->getConsecutiveErrors(), 'Consecutive errors should be at threshold');
    }

    /**
     * Edge case: State persists across restart
     */
    public function testStatePersistsAcrossRestart(): void
    {
        $config = [
            'enabled' => true,
            'tiers' => [
                1 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 25]],
                    'actions' => [['type' => 'log_alert']],
                    'cooldown_sec' => 1,
                ],
            ],
            'global' => ['eval_interval_sec' => 1],
            'circuit_breaker' => ['max_consecutive_errors' => 5],
        ];

        // First instance: trigger L1
        $prot1 = new LiquidationProtector($this->api, $config);

        $positions = [$this->makePosition(8000, 10000, 'Buy', -100)];
        $balance = 1000.0;
        $price = 9000.0;

        $ref = new \ReflectionClass($prot1);
        $method = $ref->getMethod('evaluate');
        $method->setAccessible(true);
        $method->invoke($prot1, $price, $positions, $balance);

        $this->assertEquals(1, $this->getLastTriggeredLevel($prot1), 'L1 should trigger on first protector');
        $this->assertNotNull($prot1->getLastTriggered(1), 'L1 trigger timestamp should be set');

        // Simulate restart: create a new instance with same config
        $prot2 = new LiquidationProtector($this->api, $config);

        // State should be loaded from disk
        $this->assertNotNull($prot2->getLastTriggered(1), 'L1 trigger timestamp should persist across restart');
        $this->assertEquals(
            $prot1->getLastTriggered(1),
            $prot2->getLastTriggered(1),
            'Trigger timestamp should be identical across restart'
        );
    }
}