<?php
// Test-only stubs for the global indicator functions defined in bot.php.
// These provide deterministic outputs so GridAI and GridML fallback paths
// can be tested without loading the full bot.php bootstrap.

if (!function_exists('rsiLast')) {
    function rsiLast(array $closes, int $period = 14): float
    {
        if (count($closes) < $period + 1) return 50.0;
        $gains = $losses = 0.0;
        for ($i = count($closes) - $period; $i < count($closes); $i++) {
            $diff = (float)$closes[$i] - (float)($closes[$i-1] ?? $closes[$i]);
            if ($diff > 0) $gains += $diff; else $losses -= $diff;
        }
        if ($losses == 0) return 100.0;
        return 100 - (100 / (1 + $gains / $losses));
    }
}

if (!function_exists('ema')) {
    function ema(array $values, int $period): array
    {
        if (empty($values)) return [0.0];
        $k = 2 / ($period + 1);
        $ema = (float)$values[0];
        $out = [$ema];
        for ($i = 1; $i < count($values); $i++) {
            $ema = (float)$values[$i] * $k + $ema * (1 - $k);
            $out[] = $ema;
        }
        return $out;
    }
}

if (!function_exists('macdHistLast')) {
    function macdHistLast(array $closes): float { return 0.0; }
}
if (!function_exists('atrPctLast')) {
    function atrPctLast(array $candles, int $period = 14): float { return 0.5; }
}
if (!function_exists('volRatioLast')) {
    function volRatioLast(array $candles): float { return 1.0; }
}
if (!function_exists('bbWidth')) {
    function bbWidth(array $candles, int $period = 20): float { return 0.0; }
}
if (!function_exists('stochLast')) {
    function stochLast(array $candles, int $period = 14): float { return 50.0; }
}
if (!function_exists('emaTrend')) {
    function emaTrend(array $closes): string { return 'SIDEWAYS'; }
}
if (!function_exists('multiTFMomentum')) {
    function multiTFMomentum(array $candles): float { return 0.0; }
}

// No-op stubs for the global logging helpers defined in bot.php.
// Required so GridML's constructor (load()) and reloadIfUpdated() can run in
// isolation without depending on another test having defined these globally.

if (!function_exists('lI')) {
    function lI($m): void {}
}
if (!function_exists('lW')) {
    function lW($m): void {}
}
if (!function_exists('lE')) {
    function lE($m): void {}
}
