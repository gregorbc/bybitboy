<?php
declare(strict_types=1);

namespace BinanceBot\Strategy;

class GridAI
{
    public function getStrategy(array $candles): array
    {
        $cl  = array_column($candles, 'c');
        $rsi = rsiLast($cl);
        $dir = $rsi > 58 ? 'UP' : ($rsi < 42 ? 'DOWN' : 'SIDEWAYS');
        return [
            'direction'   => $dir,
            'confidence'  => 50,
            'levels'      => G_FIXED_LEVELS,
            'spacing_pct' => G_BASE_SPACING,
            'long_pct'    => 0.5,
            'reason'      => "Heurístico RSI=" . round($rsi, 1),
        ];
    }
}
