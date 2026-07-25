<?php
declare(strict_types=1);

namespace BinanceBot\Strategy;

class Indicators
{
    public static function ema(array $values, int $period): float
    {
        if (empty($values)) return 0.0;

        $k = 2 / ($period + 1);
        $ema = (float)$values[0];

        for ($i = 1; $i < count($values); $i++) {
            $ema = (float)$values[$i] * $k + $ema * (1 - $k);
        }

        return $ema;
    }

    public static function rsi(array $candles, int $period = 14): float
    {
        if (count($candles) < $period + 1) return 50.0;

        $gains = $losses = 0.0;
        for ($i = count($candles) - $period; $i < count($candles); $i++) {
            $diff = (float)$candles[$i]['close'] - (float)($candles[$i - 1]['close'] ?? $candles[$i]['close']);
            if ($diff > 0) $gains += $diff; else $losses -= $diff;
        }

        if ($losses == 0) return 100.0;
        $rs = $gains / $losses;
        return 100 - (100 / (1 + $rs));
    }

    public static function macd(array $candles): float
    {
        if (count($candles) < 35) return 0.0;

        $closes = array_column($candles, 'close');
        $ema12 = self::ema($closes, 12);
        $ema26 = self::ema($closes, 26);
        $macdLine = $ema12 - $ema26;

        $macdValues = [];
        for ($i = 26; $i <= count($closes); $i++) {
            $slice = array_slice($closes, 0, $i);
            $e12 = self::ema($slice, 12);
            $e26 = self::ema($slice, 26);
            $macdValues[] = $e12 - $e26;
        }
        $signal = self::ema($macdValues, 9);

        return $macdLine - $signal;
    }

    public static function atrPct(array $candles, int $period = 14): float
    {
        if (count($candles) < $period + 1) return 0.0;

        $trs = [];
        for ($i = 1; $i < count($candles); $i++) {
            $h = (float)$candles[$i]['high'];
            $l = (float)$candles[$i]['low'];
            $pc = (float)($candles[$i - 1]['close'] ?? $candles[$i]['close']);
            $trs[] = max($h - $l, abs($h - $pc), abs($l - $pc));
        }

        $atr = array_slice($trs, -$period);
        $avg = array_sum($atr) / count($atr);
        $lastClose = (float)end($candles)['close'];

        return $lastClose > 0 ? ($avg / $lastClose) * 100 : 0.0;
    }

    public static function volRatio(array $candles, int $period = 20): float
    {
        if (count($candles) < $period + 1) return 1.0;

        $vols = array_column($candles, 'volume');
        $recent = (float)end($vols);
        $avg = array_sum(array_slice($vols, -$period)) / $period;

        return $avg > 0 ? $recent / $avg : 1.0;
    }

    public static function bbWidth(array $candles, int $period = 20): float
    {
        if (count($candles) < $period) return 0.0;

        $closes = array_slice(array_column($candles, 'close'), -$period);
        $mean = array_sum($closes) / $period;
        $variance = 0.0;
        foreach ($closes as $c) {
            $variance += ($c - $mean) ** 2;
        }
        $stddev = sqrt($variance / $period);

        return $mean > 0 ? ($stddev * 2 / $mean) * 100 : 0.0;
    }

    public static function stoch(array $candles, int $period = 14): float
    {
        if (count($candles) < $period) return 50.0;

        $slice = array_slice($candles, -$period);
        $high = max(array_column($slice, 'high'));
        $low = min(array_column($slice, 'low'));
        $close = (float)end($candles)['close'];

        $range = $high - $low;
        return $range > 0 ? (($close - $low) / $range) * 100 : 50.0;
    }
}
