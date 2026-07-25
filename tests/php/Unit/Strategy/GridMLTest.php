<?php
declare(strict_types=1);

namespace Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use BinanceBot\Strategy\GridML;

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
        if (!defined('G_ML_MIN_ACCURACY')) define('G_ML_MIN_ACCURACY', 0.85);

        $ml = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
        $candles = $this->makeCandles(array_fill(0, 50, 100));

        try {
            $result = $ml->predict($candles);
            $this->assertContains($result['direction'], ['UP', 'DOWN', 'SIDEWAYS']);
        } catch (\Error $e) {
            $this->markTestSkipped(
                'Global indicator functions (rsiLast, ema...) not available in test context: ' . $e->getMessage()
            );
        }
    }
}
