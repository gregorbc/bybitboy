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
}
