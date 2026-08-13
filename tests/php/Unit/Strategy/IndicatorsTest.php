<?php
declare(strict_types=1);

namespace Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use BinanceBot\Strategy\Indicators;

class IndicatorsTest extends TestCase
{
    // --- EMA ---

    public function testEmaCalculatesCorrectly(): void
    {
        // k = 2/(3+1) = 0.5
        // 10 -> 10.5 -> 11.25 -> 12.125 -> 13.0625
        $data = [10, 11, 12, 13, 14];
        $result = Indicators::ema($data, 3);
        $this->assertEqualsWithDelta(13.0625, $result, 0.0001);
    }

    public function testEmaEmptyReturnsZero(): void
    {
        $this->assertEquals(0.0, Indicators::ema([], 3));
    }

    public function testEmaSingleValueReturnsThatValue(): void
    {
        $this->assertEqualsWithDelta(5.0, Indicators::ema([5], 3), 0.0001);
    }

    // --- RSI ---

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

    public function testRsiAllGainsReturns100(): void
    {
        // All closes increasing -> all gains, no losses -> RSI = 100
        $candles = [];
        for ($i = 0; $i < 20; $i++) {
            $candles[] = ['close' => 100 + $i];
        }
        $this->assertEqualsWithDelta(100.0, Indicators::rsi($candles, 14), 0.0001);
    }

    public function testRsiAllLossesReturns0(): void
    {
        // All closes decreasing -> no gains, all losses -> RSI = 0
        $candles = [];
        for ($i = 0; $i < 20; $i++) {
            $candles[] = ['close' => 200 - $i];
        }
        $this->assertEqualsWithDelta(0.0, Indicators::rsi($candles, 14), 0.0001);
    }

    public function testRsiInsufficientDataReturns50(): void
    {
        $candles = [['close' => 100], ['close' => 101]];
        $this->assertEquals(50.0, Indicators::rsi($candles, 14));
    }

    // --- ATR ---

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

    public function testAtrPctCalculatesCorrectly(): void
    {
        // 2 candles: high=101,low=99,close=100 for both
        // TR = max(101-99, |101-100|, |99-100|) = max(2,1,1) = 2
        // ATR = 2, close=100 -> ATR% = 2/100*100 = 2%
        $candles = [
            ['high' => 101, 'low' => 99, 'close' => 100],
            ['high' => 101, 'low' => 99, 'close' => 100],
        ];
        $this->assertEqualsWithDelta(2.0, Indicators::atrPct($candles, 1), 0.0001);
    }

    public function testAtrPctInsufficientDataReturnsZero(): void
    {
        $candles = [['high' => 101, 'low' => 99, 'close' => 100]];
        $this->assertEquals(0.0, Indicators::atrPct($candles, 14));
    }

    // --- MACD ---

    public function testMacdReturnsFloat(): void
    {
        $candles = [];
        for ($i = 0; $i < 40; $i++) {
            $candles[] = ['close' => 100 + $i * 0.5];
        }
        $result = Indicators::macd($candles);
        $this->assertIsFloat($result);
    }

    public function testMacdInsufficientDataReturnsZero(): void
    {
        $candles = [];
        for ($i = 0; $i < 30; $i++) {
            $candles[] = ['close' => 100 + $i];
        }
        $this->assertEquals(0.0, Indicators::macd($candles));
    }

    public function testMacdTrendingUpReturnsPositive(): void
    {
        // Strong uptrend: EMA12 > EMA26, and MACD line above signal
        $candles = [];
        for ($i = 0; $i < 50; $i++) {
            $candles[] = ['close' => 100 + $i * 2];
        }
        $result = Indicators::macd($candles);
        $this->assertGreaterThan(0, $result);
    }

    // --- VolRatio ---

    public function testVolRatioReturnsFloat(): void
    {
        $candles = [];
        for ($i = 0; $i < 25; $i++) {
            $candles[] = ['volume' => 1000 + $i * 10];
        }
        $result = Indicators::volRatio($candles);
        $this->assertIsFloat($result);
    }

    public function testVolRatioHighVolumeReturnsAbove1(): void
    {
        // Last candle volume is 2x average -> ratio > 1
        $candles = [];
        for ($i = 0; $i < 20; $i++) {
            $candles[] = ['volume' => 100];
        }
        $candles[] = ['volume' => 200]; // 21st candle
        $result = Indicators::volRatio($candles);
        $this->assertGreaterThan(1.0, $result);
    }

    public function testVolRatioLowVolumeReturnsBelow1(): void
    {
        $candles = [];
        for ($i = 0; $i < 20; $i++) {
            $candles[] = ['volume' => 100];
        }
        $candles[] = ['volume' => 50]; // half of average
        $result = Indicators::volRatio($candles);
        $this->assertLessThan(1.0, $result);
    }

    public function testVolRatioInsufficientDataReturns1(): void
    {
        $candles = [['volume' => 100], ['volume' => 200]];
        $this->assertEquals(1.0, Indicators::volRatio($candles));
    }

    public function testVolRatioZeroVolumeReturns1(): void
    {
        $candles = [];
        for ($i = 0; $i < 21; $i++) {
            $candles[] = ['volume' => 0];
        }
        $this->assertEquals(1.0, Indicators::volRatio($candles));
    }

    // --- BB Width ---

    public function testBbWidthReturnsFloat(): void
    {
        $candles = [];
        for ($i = 0; $i < 20; $i++) {
            $candles[] = ['close' => 100 + ($i % 5)];
        }
        $result = Indicators::bbWidth($candles);
        $this->assertIsFloat($result);
    }

    public function testBbWidthConstantCloseReturnsZero(): void
    {
        $candles = [];
        for ($i = 0; $i < 20; $i++) {
            $candles[] = ['close' => 100];
        }
        $this->assertEquals(0.0, Indicators::bbWidth($candles));
    }

    public function testBbWidthVolatileReturnsPositive(): void
    {
        $candles = [];
        for ($i = 0; $i < 20; $i++) {
            $candles[] = ['close' => 100 + ($i % 2 === 0 ? 10 : -10)];
        }
        $result = Indicators::bbWidth($candles);
        $this->assertGreaterThan(0, $result);
    }

    public function testBbWidthInsufficientDataReturnsZero(): void
    {
        $candles = [['close' => 100], ['close' => 101]];
        $this->assertEquals(0.0, Indicators::bbWidth($candles));
    }

    public function testBbWidthCalculatesCorrectly(): void
    {
        // 20 candles, closes = 90,91,92,...,109
        // mean = (90+91+...+109)/20 = 99.5
        // stddev of uniform-ish sequence
        $candles = [];
        for ($i = 0; $i < 20; $i++) {
            $candles[] = ['close' => 90 + $i];
        }
        $result = Indicators::bbWidth($candles);
        // stddev ≈ 5.766, width = (5.766*2/99.5)*100 ≈ 11.59%
        $this->assertGreaterThan(10.0, $result);
        $this->assertLessThan(13.0, $result);
    }

    // --- Stochastic ---

    public function testStochReturnsFloat(): void
    {
        $candles = [];
        for ($i = 0; $i < 14; $i++) {
            $candles[] = ['high' => 110, 'low' => 90, 'close' => 100];
        }
        $result = Indicators::stoch($candles);
        $this->assertIsFloat($result);
    }

    public function testStochAtHighReturns100(): void
    {
        $candles = [];
        for ($i = 0; $i < 14; $i++) {
            $candles[] = ['high' => 110, 'low' => 90, 'close' => 110];
        }
        $this->assertEqualsWithDelta(100.0, Indicators::stoch($candles), 0.0001);
    }

    public function testStochAtLowReturns0(): void
    {
        $candles = [];
        for ($i = 0; $i < 14; $i++) {
            $candles[] = ['high' => 110, 'low' => 90, 'close' => 90];
        }
        $this->assertEqualsWithDelta(0.0, Indicators::stoch($candles), 0.0001);
    }

    public function testStochMidReturns50(): void
    {
        $candles = [];
        for ($i = 0; $i < 14; $i++) {
            $candles[] = ['high' => 110, 'low' => 90, 'close' => 100];
        }
        $this->assertEqualsWithDelta(50.0, Indicators::stoch($candles), 0.0001);
    }

    public function testStochInsufficientDataReturns50(): void
    {
        $candles = [['high' => 110, 'low' => 90, 'close' => 100]];
        $this->assertEquals(50.0, Indicators::stoch($candles));
    }

    public function testStochEqualHighLowReturns50(): void
    {
        $candles = [];
        for ($i = 0; $i < 14; $i++) {
            $candles[] = ['high' => 100, 'low' => 100, 'close' => 100];
        }
        $this->assertEquals(50.0, Indicators::stoch($candles));
    }
}
