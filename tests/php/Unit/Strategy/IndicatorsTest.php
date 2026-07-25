<?php
declare(strict_types=1);

namespace Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use BinanceBot\Strategy\Indicators;

class IndicatorsTest extends TestCase
{
    public function testEmaCalculatesCorrectly(): void
    {
        $data = [10, 11, 12, 13, 14];
        $result = Indicators::ema($data, 3);
        $this->assertIsFloat($result);
        $this->assertGreaterThan(0, $result);
    }

    public function testRsiReturnsBetween0And100(): void
    {
        $candles = [];
        for ($i = 0; $i < 20; $i++) {
            $candles[] = ['close' => 100 + $i * 0.5];
        }
        $rsi = Indicators::rsi($candles, 14);
        $this->assertGreaterThanOrEqual(0, $rsi);
        $this->assertLessThanOrEqual(100, $rsi);
    }

    public function testAtrPctReturnsPositive(): void
    {
        $candles = [];
        for ($i = 0; $i < 20; $i++) {
            $candles[] = [
                'high' => 100 + $i + 1,
                'low' => 100 + $i - 1,
                'close' => 100 + $i,
            ];
        }
        $atr = Indicators::atrPct($candles, 14);
        $this->assertGreaterThan(0, $atr);
    }
}
