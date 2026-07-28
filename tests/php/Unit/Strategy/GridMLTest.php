<?php
declare(strict_types=1);

namespace Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use BinanceBot\Strategy\GridML;

require_once __DIR__ . '/../../Integration/indicator_stubs.php';
if (!defined('G_ML_MIN_ACCURACY')) define('G_ML_MIN_ACCURACY', 0.85);

class GridMLTest extends TestCase
{
    private function makeCandles(array $closes): array
    {
        $out = [];
        foreach ($closes as $i => $c) {
            $out[] = ['t' => $i, 'o' => $c, 'h' => $c * 1.001, 'l' => $c * 0.999, 'c' => $c, 'v' => 100];
        }
        return $out;
    }

    public function testConstructorWithNonExistentFileStillConstructsObject(): void
    {
        if (!defined('G_ML_MIN_ACCURACY')) define('G_ML_MIN_ACCURACY', 0.85);

        $ml = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');

        $this->assertEquals(0.0, $ml->getAccuracy());
    }

    public function testReloadIfUpdatedReturnsFalseWhenFileDoesNotExist(): void
    {
        if (!defined('G_ML_MIN_ACCURACY')) define('G_ML_MIN_ACCURACY', 0.85);

        $ml = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
        $this->assertFalse($ml->reloadIfUpdated());
    }

    public function testPredictReturnsFallbackWhenNoWeightsLoaded(): void
    {
        $ml = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
        $candles = $this->makeCandles(array_fill(0, 50, 100));
        $result = $ml->predict($candles);

        $this->assertContains($result['direction'], ['UP', 'DOWN', 'SIDEWAYS']);
        $this->assertEquals(35, $result['confidence']);
        $this->assertStringContainsString('ML-fallback', $result['reason']);
    }

    public function testPredictFallbackDirectionUpWhenPricesRising(): void
    {
        $ml = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
        $upCloses = array_merge(
            array_fill(0, 14, 100),
            [100, 101, 102, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112, 113, 114]
        );
        $candles = $this->makeCandles($upCloses);
        $result = $ml->predict($candles);

        // With consistent upward price movement, RSI goes to 100, direction should be UP
        $this->assertEquals('UP', $result['direction']);
    }
}
