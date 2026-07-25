<?php
declare(strict_types=1);

namespace Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use BinanceBot\Strategy\GridAI;

require_once __DIR__ . '/../../Integration/indicator_stubs.php';
if (!defined('G_FIXED_LEVELS')) define('G_FIXED_LEVELS', 16);
if (!defined('G_BASE_SPACING')) define('G_BASE_SPACING', 0.0003);

class GridAITest extends TestCase
{
    private function makeCandles(array $closes): array
    {
        $out = [];
        foreach ($closes as $i => $c) {
            $out[] = ['t' => $i, 'o' => $c, 'h' => $c * 1.001, 'l' => $c * 0.999, 'c' => $c, 'v' => 100];
        }
        return $out;
    }

    public function testReturnsValidDirectionAndConfidence(): void
    {
        $candles = $this->makeCandles(array_fill(0, 30, 100));
        $ai = new GridAI();
        $result = $ai->getStrategy($candles);

        $this->assertContains($result['direction'], ['UP', 'DOWN', 'SIDEWAYS']);
        $this->assertEquals(50, $result['confidence']);
        $this->assertEquals(G_FIXED_LEVELS, $result['levels']);
        $this->assertEquals(G_BASE_SPACING, $result['spacing_pct']);
        $this->assertArrayHasKey('reason', $result);
    }

    public function testReasonContainsRsiValue(): void
    {
        $closes = array_merge(
            array_fill(0, 25, 100),
            [100, 101, 102, 103, 104]
        );
        $candles = $this->makeCandles($closes);
        $ai = new GridAI();
        $result = $ai->getStrategy($candles);

        $this->assertStringContainsString('RSI', $result['reason']);
        $this->assertStringContainsString('Heurístico', $result['reason']);
    }
}
