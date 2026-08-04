<?php
// Global logging functions used by BybitFutures (mirrors bot.php)
// Only define if not already defined (tests may define their own)
if (!function_exists('lI')) { function lI(string $m): void { error_log('[INFO] ' . $m); } }
if (!function_exists('lW')) { function lW(string $m): void { error_log('[WARN] ' . $m); } }
if (!function_exists('lE')) { function lE(string $m): void { error_log('[ERROR] ' . $m); } }
if (!function_exists('lg')) { function lg(string $l, string $m): void { error_log('[' . $l . '] ' . $m); } }