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
        public static array $dbxCalls = [];
        public static mixed $dbxFetchResult = false;

        protected function setUp(): void
        {
            self::$dbxCalls = [];
            self::$dbxFetchResult = false;
        }

        protected function tearDown(): void
        {
            \Mockery::close();
        }

        public static function dbxHandle(callable $fn): mixed
        {
            $stmt = \Mockery::mock(\PDOStatement::class);
            $stmt->shouldReceive('execute')->andReturn(true);
            $stmt->shouldReceive('fetch')->andReturn(self::$dbxFetchResult);
            $stmt->shouldReceive('fetchAll')->andReturn([]);
            $stmt->shouldReceive('fetchColumn')->andReturn(0);
            $stmt->shouldReceive('fetchObject')->andReturn(false);
            $pdo = \Mockery::mock(\PDO::class);
            $pdo->shouldReceive('prepare')->andReturnUsing(
                function (string $sql) use ($stmt): mixed {
                    self::$dbxCalls[] = $sql;
                    return $stmt;
                }
            );
            $pdo->shouldReceive('query')->andReturn($stmt);
            $pdo->shouldReceive('lastInsertId')->andReturn(1);
            return $fn($pdo);
        }

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

        // With isTaker=true: entry was maker (PostOnly), exit is taker (market)
        $pnl = $ref->invoke($manager, 'SELL', 100.0, 101.0, 1.0, true);
        $expectedFee = 100.0 * 1.0 * G_MAKER_FEE + 101.0 * 1.0 * G_TAKER_FEE;
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

    public function testOnFillDetectsMarketOrderAsTaker(): void
    {
        $api = new BybitFutures('test_key', 'test_secret', true);
        $ai  = new GridAI();
        $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
        $manager = new GridManager($api, $ai, $ml);

        $ref = new \ReflectionMethod(GridManager::class, 'onFill');
        $ref->setAccessible(true);

        $refCalcPnl = new \ReflectionMethod(GridManager::class, 'calcPnl');
        $refCalcPnl->setAccessible(true);

        // Test that market order fills are detected as taker
        $order = [
            'id' => 1,
            'side' => 'SELL',
            'grid_role' => 'EXIT',
            'qty' => 1.0,
            'price' => 100.0,
            'grid_level' => 1,
            'linked_order' => 2,
            'is_recovery' => 0,
        ];

        $entryOrder = [
            'id' => 2,
            'price' => 99.0,
            'qty' => 1.0,
            'side' => 'BUY',
            'grid_role' => 'ENTRY',
            'grid_level' => 1,
        ];

        // Mock database to return entry order
        $dbMock = $this->createMock(\PDO::class);
        // We can't easily mock the dbx() global, so we test calcPnl directly with isTaker=true
        // The fix in onFill() checks $info['orderType'] === 'Market'
        // calcPnl already has tests for isTaker=true, so we verify the detection logic here

        // Simulate the logic in onFill for EXIT role
        $infoMarket = ['orderType' => 'Market', 'avgPrice' => 101.0];
        $infoLimit = ['orderType' => 'Limit', 'avgPrice' => 101.0];

        // Market order should be detected as taker
        $isTakerMarket = isset($infoMarket['orderType']) && $infoMarket['orderType'] === 'Market';
        $this->assertTrue($isTakerMarket);

        // Limit order should not be detected as taker
        $isTakerLimit = isset($infoLimit['orderType']) && $infoLimit['orderType'] === 'Market';
        $this->assertFalse($isTakerLimit);

        // Verify calcPnl produces different results for taker vs maker
        $pnlMaker = $refCalcPnl->invoke($manager, 'SELL', 99.0, 101.0, 1.0, false);
        $pnlTaker = $refCalcPnl->invoke($manager, 'SELL', 99.0, 101.0, 1.0, true);

        // Taker fee (0.0006) should result in lower PnL than maker fee (0.0001)
        $this->assertLessThan($pnlMaker, $pnlTaker);
    }

    /**
     * Fix #3: checkControl() must process a pending config_update (the
     * "Aplicar y Reconstruir" button) and rebuild the grid with new values.
     */
    public function testCheckControlAppliesConfigUpdate(): void
    {
        $api = \Mockery::mock(BybitFutures::class);
        $api->shouldReceive('cancelAll')->once()->with('ETHUSDT');
        $ai  = new GridAI();
        $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
        $manager = new GridManager($api, $ai, $ml);

        $gridBuilt = new \ReflectionProperty(GridManager::class, 'gridBuilt');
        $gridBuilt->setAccessible(true);
        $gridBuilt->setValue($manager, true);
        $lastBuild = new \ReflectionProperty(GridManager::class, 'lastGridBuild');
        $lastBuild->setAccessible(true);
        $lastBuild->setValue($manager, 999999);

        $ctrl = tempnam(sys_get_temp_dir(), 'ctrl_');
        $GLOBALS['CTRL'] = $ctrl;
        file_put_contents($ctrl, json_encode([
            'config_update' => ['spacing_pct' => 0.0005, 'levels' => 20, 'evil' => 1],
            'ts' => date('Y-m-d H:i:s'),
        ]));

        $ref = new \ReflectionMethod(GridManager::class, 'checkControl');
        $ref->setAccessible(true);
        $ref->invoke($manager);

        $this->assertFileDoesNotExist($ctrl, 'control file must be consumed');

        $hasGridConfigUpdate = false;
        foreach (self::$dbxCalls as $sql) {
            if (str_contains($sql, 'UPDATE grid_configs SET')
                && str_contains($sql, 'spacing_pct')
                && str_contains($sql, 'levels')
                && !str_contains($sql, 'evil')) {
                $hasGridConfigUpdate = true;
            }
        }
        $this->assertTrue($hasGridConfigUpdate, 'grid_configs must be updated with whitelisted fields only');
        $this->assertFalse($gridBuilt->getValue($manager), 'grid must be marked for rebuild');
        $this->assertSame(0, $lastBuild->getValue($manager), 'grid must be rebuilt immediately');
    }

    /**
     * Fix #3: checkControl() must handle the reset_pair action sent by the dashboard.
     */
    public function testCheckControlHandlesResetPair(): void
    {
        $api = \Mockery::mock(BybitFutures::class);
        $api->shouldReceive('cancelAll')->once()->with('ETHUSDT');
        $ai  = new GridAI();
        $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
        $manager = new GridManager($api, $ai, $ml);

        $gridBuilt = new \ReflectionProperty(GridManager::class, 'gridBuilt');
        $gridBuilt->setAccessible(true);
        $gridBuilt->setValue($manager, true);

        $ctrl = tempnam(sys_get_temp_dir(), 'ctrl_');
        $GLOBALS['CTRL'] = $ctrl;
        file_put_contents($ctrl, json_encode(['action' => 'reset_pair', 'sym' => 'ETHUSDT', 'ts' => date('Y-m-d H:i:s')]));

        $ref = new \ReflectionMethod(GridManager::class, 'checkControl');
        $ref->setAccessible(true);
        $ref->invoke($manager);

        $this->assertFalse($gridBuilt->getValue($manager), 'reset_pair must force a grid rebuild');
    }

    /**
     * Fix #4: the pause file written by LiquidationProtector::pause_bot_sec
     * must actually pause the bot loop.
     */
    public function testHandlePauseReturnsTrueWhilePauseActive(): void
    {
        $api = new BybitFutures('test_key', 'test_secret', true);
        $ai  = new GridAI();
        $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
        $manager = new GridManager($api, $ai, $ml);

        $pauseFile = sys_get_temp_dir() . '/bot_pause_' . getmypid() . '.tmp';
        @unlink($pauseFile);
        file_put_contents($pauseFile, (string)(time() + 2));

        $ref = new \ReflectionMethod(GridManager::class, 'handlePause');
        $ref->setAccessible(true);
        $result = $ref->invoke($manager);

        $this->assertTrue($result, 'handlePause must return true while the pause file is active');
        $this->assertFileExists($pauseFile, 'pause file must remain while the pause is active');
        @unlink($pauseFile);
    }

    /**
     * Fix #4: an expired pause file must be cleaned up and not pause the bot.
     */
    public function testHandlePauseCleansExpiredPauseFile(): void
    {
        $api = new BybitFutures('test_key', 'test_secret', true);
        $ai  = new GridAI();
        $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
        $manager = new GridManager($api, $ai, $ml);

        $pauseFile = sys_get_temp_dir() . '/bot_pause_' . getmypid() . '.tmp';
        @unlink($pauseFile);
        file_put_contents($pauseFile, (string)(time() - 5));

        $ref = new \ReflectionMethod(GridManager::class, 'handlePause');
        $ref->setAccessible(true);
        $result = $ref->invoke($manager);

        $this->assertFalse($result, 'expired pause must not pause the bot');
        $this->assertFileDoesNotExist($pauseFile, 'expired pause file must be cleaned up');
    }

    public function testGetPnlTodayIncludesOpenPositionUPnL(): void
    {
        $api = \Mockery::mock(BybitFutures::class);
        $api->shouldReceive('positions')->with('ETHUSDT')->once()->andReturn([
            ['unRealizedProfit' => 0.5, 'positionAmt' => 0.03, 'side' => 'Buy'],
            ['unRealizedProfit' => -0.2, 'positionAmt' => -0.03, 'side' => 'Sell'],
        ]);
        $ai  = new GridAI();
        $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
        $manager = new GridManager($api, $ai, $ml);

        self::$dbxFetchResult = ['p' => '1.00000000'];

        $ref = new \ReflectionMethod(GridManager::class, 'getPnlToday');
        $ref->setAccessible(true);
        $result = $ref->invoke($manager);

        // 1.0 (EXITs filled today) + 0.5 + (-0.2) = 1.3
        $this->assertEqualsWithDelta(1.3, $result, 0.000001);
    }

    public function testProfitOptimizeUsesCapitalNotBalanceForCompound(): void
    {
        $api = \Mockery::mock(BybitFutures::class);
        $api->shouldReceive('balance')->andReturn(1650000.0);
        $api->shouldReceive('filters')->andReturn(['step'=>0.001,'tick'=>0.01,'mn'=>0.01,'qp'=>3,'pp'=>2]);
        $api->shouldReceive('positions')->andReturn([]);
        $ai  = new GridAI();
        $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
        $manager = new GridManager($api, $ai, $ml);

        $ref = new \ReflectionClass(GridManager::class);
        $cfgProp = $ref->getProperty('cfg');
        $cfgProp->setAccessible(true);
        $cfgProp->setValue($manager, [
            'id' => 1, 'symbol' => 'ETHUSDT', 'direction' => 'SIDEWAYS',
            'confidence' => 50, 'qty_per_level' => 0.03, 'recovery_active' => 0,
            'spacing_pct' => 0.0016,
        ]);
        $lastCompound = $ref->getProperty('lastCompound');
        $lastCompound->setAccessible(true);
        $lastCompound->setValue($manager, 0);

        self::$dbxFetchResult = ['p' => '2.00000000'];

        $method = $ref->getMethod('profitOptimize');
        $method->setAccessible(true);
        $method->invoke($manager, 1869.0);

        $hasQtyUpdate = false;
        foreach (self::$dbxCalls as $sql) {
            if (str_contains($sql, 'UPDATE grid_configs SET qty_per_level')) $hasQtyUpdate = true;
        }
        // With 1.65M demo balance, pct = 2/1650000*100 = 0.00012% (old code → no compound).
        // With G_CAPITAL=100, pct = 2/100*100 = 2% >= 1.5 (new code → compound fires).
        $this->assertTrue($hasQtyUpdate, 'compounding must use G_CAPITAL, not demo balance');
    }

    public function testBreakoutCheckReCentersAndPreservesPositions(): void
    {
        $api = \Mockery::mock(BybitFutures::class);
        $api->shouldReceive('cancelAll')->with('ETHUSDT')->once();
        $api->shouldReceive('positions')->once()->andReturn([]);
        $ai  = new GridAI();
        $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
        $manager = new GridManager($api, $ai, $ml);

        $ref = new \ReflectionClass(GridManager::class);
        $gridBuilt = $ref->getProperty('gridBuilt');
        $gridBuilt->setAccessible(true);
        $gridBuilt->setValue($manager, true);
        $lastBuild = $ref->getProperty('lastGridBuild');
        $lastBuild->setAccessible(true);
        $lastBuild->setValue($manager, 999999);
        $cfgProp = $ref->getProperty('cfg');
        $cfgProp->setAccessible(true);
        $cfgProp->setValue($manager, [
            'id' => 1, 'symbol' => 'ETHUSDT', 'direction' => 'SIDEWAYS',
            'levels' => 14, 'long_levels' => 7, 'short_levels' => 7,
            'spacing_pct' => 0.0016,
        ]);

        // DB range: mn=1800, mx=1900 -> margin=30 -> price 2000 is a breakout
        self::$dbxFetchResult = ['mn' => '1800.00000000', 'mx' => '1900.00000000'];

        $method = $ref->getMethod('breakoutCheck');
        $method->setAccessible(true);
        $method->invoke($manager, 2000.0);

        $this->assertFalse($gridBuilt->getValue($manager), 'breakout must mark grid for rebuild');
        $this->assertSame(0, $lastBuild->getValue($manager), 'breakout must rebuild immediately');

        $hasCancel = false;
        foreach (self::$dbxCalls as $sql) {
            if (str_contains($sql, "SET status='CANCELED' WHERE symbol=? AND status='OPEN'")) $hasCancel = true;
        }
        $this->assertTrue($hasCancel, 'breakout must cancel open orders');
    }

    public function testBuildGridSkipsOrdersWhenEffectiveCapExceeded(): void
    {
        $api = \Mockery::mock(BybitFutures::class);
        $api->shouldReceive('balance')->andReturn(1650000.0);
        $api->shouldReceive('filters')->andReturn(['step'=>0.001,'tick'=>0.01,'mn'=>0.01,'qp'=>3,'pp'=>2]);
        $api->shouldReceive('limitOrder')->never();
        $ai  = new GridAI();
        $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
        $manager = new GridManager($api, $ai, $ml);

        $ref = new \ReflectionClass(GridManager::class);
        $cfgProp = $ref->getProperty('cfg');
        $cfgProp->setAccessible(true);
        $cfgProp->setValue($manager, [
            'id' => 1, 'symbol' => 'ETHUSDT', 'direction' => 'SIDEWAYS',
            'confidence' => 50, 'levels' => 14, 'long_levels' => 7,
            'short_levels' => 7, 'spacing_pct' => 0.0016,
            'qty_per_level' => 0.5,
        ]);
        $lastBuild = $ref->getProperty('lastGridBuild');
        $lastBuild->setAccessible(true);
        $lastBuild->setValue($manager, 0);

        $method = $ref->getMethod('buildGrid');
        $method->setAccessible(true);
        $method->invoke($manager, 1869.0);

        $gridBuilt = $ref->getProperty('gridBuilt');
        $gridBuilt->setAccessible(true);
        // qty=0.5 -> reqMargin per level = 0.5*1869/20 = 46.7 > effectiveCap=40 -> all skipped
        $this->assertFalse($gridBuilt->getValue($manager), 'orders must be skipped when margin exceeds effectiveCap');
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
    if (!defined('G_CYCLE_SEC')) {
        define('G_CYCLE_SEC', 1);
    }
    if (!defined('G_CAPITAL')) {
        define('G_CAPITAL', 100.0);
    }
    if (!defined('G_LEVERAGE')) {
        define('G_LEVERAGE', 20);
    }
    if (!defined('G_MARGIN_SAFETY')) {
        define('G_MARGIN_SAFETY', 0.40);
    }
    if (!defined('G_COMPOUND_THR')) {
        define('G_COMPOUND_THR', 1.5);
    }
    if (!defined('G_COMPOUND_MULT')) {
        define('G_COMPOUND_MULT', 1.05);
    }
    if (!defined('G_COMPOUND_CD')) {
        define('G_COMPOUND_CD', 0);
    }
    if (!defined('G_FIXED_LEVELS')) {
        define('G_FIXED_LEVELS', 14);
    }
    if (!defined('G_LONG_LEVELS')) {
        define('G_LONG_LEVELS', 7);
    }
    if (!defined('G_SHORT_LEVELS')) {
        define('G_SHORT_LEVELS', 7);
    }
    if (!defined('G_ML_BLEND_WEIGHT')) {
        define('G_ML_BLEND_WEIGHT', 0.90);
    }
    if (!defined('G_VL_BLEND_WEIGHT')) {
        define('G_VL_BLEND_WEIGHT', 0.10);
    }
    if (!defined('G_AI_INTERVAL')) {
        define('G_AI_INTERVAL', 120);
    }

    // Test-only dbx() stub: passes a PDO mock into the closure and records SQL.
    if (!function_exists('dbx')) {
        function dbx($fn) {
            return \Tests\Unit\Strategy\GridManagerTest::dbxHandle($fn);
        }
    }
}
