<?php
declare(strict_types=1);

namespace Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use BinanceBot\Strategy\GridEngine;

class GridEngineTest extends TestCase
{
    public function testCalcQtyReturnsPositive(): void
    {
        $engine = new GridEngine();
        $qty = $engine->calcQty(1850.0, 16, [
            'capital' => 30.0,
            'leverage' => 100,
            'marginSafety' => 0.65,
        ]);
        $this->assertGreaterThan(0, $qty);
    }

    public function testCalcPnlCalculatesCorrectly(): void
    {
        $engine = new GridEngine();
        $pnl = $engine->calcPnl('SELL', 1850.0, 1860.0, 0.07);
        $this->assertGreaterThan(0, $pnl); // Profit on SELL from low to high
    }
}
