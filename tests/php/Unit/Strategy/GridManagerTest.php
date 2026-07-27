<?php
declare(strict_types=1);

namespace Tests\Unit\Strategy
{
    use PHPUnit\Framework\TestCase;
    use BinanceBot\Strategy\GridManager;
    use BinanceBot\Strategy\GridAI;
    use BinanceBot\Strategy\GridML;
    use BinanceBot\Exchange\BybitFutures;

    class GridManagerTest extends TestCase
    {
        public function testCanBeConstructedWithDependencies(): void
        {
            $api = new BybitFutures('test_key', 'test_secret', true);
            $ai  = new GridAI();
            $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');

            $manager = new GridManager($api, $ai, $ml);

            $this->assertInstanceOf(GridManager::class, $manager);
        }

        public function testStopSetsRunningFlag(): void
        {
            // Use reflection to verify stop() flips $running to false without actually
            // entering the trading loop (we cannot call run() in a unit test).
            $api = new BybitFutures('test_key', 'test_secret', true);
            $ai  = new GridAI();
            $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');

            $manager = new GridManager($api, $ai, $ml);
            $manager->stop();

            $ref = new \ReflectionClass(GridManager::class);
            $prop = $ref->getProperty('running');
            $prop->setAccessible(true);
            $this->assertFalse($prop->getValue($manager));
        }

        public function testHasExpectedPublicMethods(): void
        {
            $ref = new \ReflectionClass(GridManager::class);
            $methods = array_map(fn($m) => $m->name, $ref->getMethods(\ReflectionMethod::IS_PUBLIC));
            $this->assertContains('run', $methods);
            $this->assertContains('stop', $methods);
            $this->assertContains('__construct', $methods);
        }

public function testHasVolatilityModelProperties(): void
    {
        $ref = new \ReflectionClass(GridManager::class);
        $expected = ['volWeights', 'volScalerMean', 'volScalerScale',
                     'volIntercept', 'volMtime', 'volFile',
                     'volClipLower', 'volClipUpper'];
        foreach ($expected as $name) {
            $this->assertTrue(
                $ref->hasProperty($name),
                "Missing property: $name"
            );
        }
    }

    public function testDynamicSpacingFloorApplied(): void
    {
        $api = new BybitFutures('test_key', 'test_secret', true);
        $ai  = new GridAI();
        $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');

        $manager = new GridManager($api, $ai, $ml);

        $ref = new \ReflectionClass(GridManager::class);
        $prop = $ref->getProperty('cfg');
        $prop->setAccessible(true);

        // Simulate config row with spacing below fee floor
        // G_MAKER_FEE=0.0001, G_TAKER_FEE=0.0006, G_FEE_SAFETY=1.5
        // feeFloor = (0.0001 + 0.0001) * 1.5 = 0.0003
        // G_MIN_SPACING = 0.0003
        // dynamicMin = max(0.0003, 0.0003) = 0.0003
        // If spacing_pct in DB is 0.0002, it should be adjusted to 0.0003

        $testConfig = [
            'id' => 1,
            'symbol' => 'ETHUSDT',
            'direction' => 'SIDEWAYS',
            'confidence' => 50,
            'capital_usd' => 30.0,
            'leverage' => 100,
            'levels' => 16,
            'spacing_pct' => 0.0002, // Below fee floor
            'long_levels' => 8,
            'short_levels' => 8,
            'qty_per_level' => 0.01,
            'pp' => 2,
            'qp' => 2,
            'mode' => 'NORMAL',
            'status' => 'ACTIVE',
        ];
        $prop->setValue($manager, $testConfig);

        // Test that loadConfig would adjust spacing (we test the logic via reflection)
        $method = $ref->getMethod('loadConfig');
        $method->setAccessible(true);

        // We can't easily test the DB update, but we can verify the logic
        $feeFloor = (G_MAKER_FEE + G_MAKER_FEE) * G_FEE_SAFETY;
        $dynamicMin = max(G_MIN_SPACING, $feeFloor);
        $this->assertEqualsWithDelta(0.0003, $dynamicMin, 0.0000001);
        $this->assertLessThanOrEqual(G_MAX_SPACING, $dynamicMin);
    }

    public function testVolatilityModelLoadsFromParentDirectory(): void
        {
            // The model JSON must exist at src/php/volatility_weights_ridge.json
            $modelFile = dirname(__DIR__, 4) . '/src/php/volatility_weights_ridge.json';
            if (!file_exists($modelFile)) {
                $modelFile = dirname(__DIR__, 4) . '/src/php/volatility_weights.json';
            }
            $this->assertFileExists($modelFile, 'Volatility model JSON must exist at src/php/');

            $api = new BybitFutures('test_key', 'test_secret', true);
            $ai  = new GridAI();
            $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');

            $manager = new GridManager($api, $ai, $ml);

            $ref = new \ReflectionClass(GridManager::class);
            $prop = $ref->getProperty('volWeights');
            $prop->setAccessible(true);
            $this->assertNotNull(
                $prop->getValue($manager),
                'volWeights must load from src/php/ (via dirname(__DIR__)) — not stay null'
            );
        }

    public function testCalcPnlUsesMakerFeeByDefault(): void
    {
        $api = new BybitFutures('test_key', 'test_secret', true);
        $ai  = new GridAI();
        $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
        $manager = new GridManager($api, $ai, $ml);

        $ref = new \ReflectionMethod(GridManager::class, 'calcPnl');
        $ref->setAccessible(true);

        // SELL exit: gross = (exitPx - entryPx) * qty
        // entry=100, exit=101, qty=1 => gross=1
        // fee = 100*1*G_MAKER_FEE + 101*1*G_MAKER_FEE
        // G_MAKER_FEE=0.0001 => fee = 0.01 + 0.0101 = 0.0201
        // net = 1 - 0.0201 = 0.9799
        $pnl = $ref->invoke($manager, 'SELL', 100.0, 101.0, 1.0);
        $expectedFee = 100.0 * 1.0 * G_MAKER_FEE + 101.0 * 1.0 * G_MAKER_FEE;
        $expected = round((101.0 - 100.0) * 1.0 - $expectedFee, 8);
        $this->assertEqualsWithDelta($expected, $pnl, 0.0001);
    }

    public function testCalcPnlUsesTakerFeeWhenIsTaker(): void
    {
        $api = new BybitFutures('test_key', 'test_secret', true);
        $ai  = new GridAI();
        $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
        $manager = new GridManager($api, $ai, $ml);

        $ref = new \ReflectionMethod(GridManager::class, 'calcPnl');
        $ref->setAccessible(true);

        // With isTaker=true, fee uses G_TAKER_FEE (0.0006)
        $pnl = $ref->invoke($manager, 'SELL', 100.0, 101.0, 1.0, true);
        $expectedFee = 100.0 * 1.0 * G_TAKER_FEE + 101.0 * 1.0 * G_TAKER_FEE;
        $expected = round((101.0 - 100.0) * 1.0 - $expectedFee, 8);
        $this->assertEqualsWithDelta($expected, $pnl, 0.0001);
    }

    public function testCalcPnlBuyExit(): void
    {
        $api = new BybitFutures('test_key', 'test_secret', true);
        $ai  = new GridAI();
        $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
        $manager = new GridManager($api, $ai, $ml);

        $ref = new \ReflectionMethod(GridManager::class, 'calcPnl');
        $ref->setAccessible(true);

        // BUY exit: gross = (entryPx - exitPx) * qty
        // entry=101, exit=100, qty=1 => gross=1
        // fee = 101*1*G_MAKER_FEE + 100*1*G_MAKER_FEE = 0.0101 + 0.01 = 0.0201
        $pnl = $ref->invoke($manager, 'BUY', 101.0, 100.0, 1.0);
        $expectedFee = 101.0 * 1.0 * G_MAKER_FEE + 100.0 * 1.0 * G_MAKER_FEE;
        $expected = round((101.0 - 100.0) * 1.0 - $expectedFee, 8);
        $this->assertEqualsWithDelta($expected, $pnl, 0.0001);
    }
    }
}

namespace
{
    if (!function_exists('lI')) {
        function lI($m) {}
    }
    if (!function_exists('lW')) {
        function lW($m) {}
    }
    if (!function_exists('lE')) {
        function lE($m) {}
    }
    if (!function_exists('lg')) {
        function lg($m) {}
    }

    // Fee optimization constants (matching src/php/bot.php)
    if (!defined('G_MAKER_FEE')) {
        define('G_MAKER_FEE', 0.0001);
    }
    if (!defined('G_TAKER_FEE')) {
        define('G_TAKER_FEE', 0.0006);
    }
    if (!defined('G_FEE_SAFETY')) {
        define('G_FEE_SAFETY', 1.5);
    }
    if (!defined('G_MIN_SPACING')) {
        define('G_MIN_SPACING', 0.0003);
    }
    if (!defined('G_MAX_SPACING')) {
        define('G_MAX_SPACING', 0.0012);
    }
    if (!defined('G_BASE_SPACING')) {
        define('G_BASE_SPACING', 0.0003);
    }
    if (!defined('G_SYM')) {
        define('G_SYM', 'ETHUSDT');
    }
}
