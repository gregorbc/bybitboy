<?php
declare(strict_types=1);

namespace BinanceBot\Core;

class Logger
{
    private static string $file = '';
    private static bool $buffered = false;
    private static array $buffer = [];

    public static function setFile(string $file): void
    {
        self::$file = $file;
    }

    public static function info(string $msg): void
    {
        self::log('INFO', $msg);
    }

    public static function warn(string $msg): void
    {
        self::log('WARN', $msg);
    }

    public static function error(string $msg): void
    {
        self::log('ERROR', $msg);
    }

    public static function log(string $level, string $msg): void
    {
        $ts = date('Y-m-d H:i:s');
        $entry = "[{$ts}] [{$level}] {$msg}";

        if (self::$buffered) {
            self::$buffer[] = $entry;
            return;
        }

        if (self::$file) {
            file_put_contents(self::$file, $entry . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    public static function flush(): void
    {
        if (!self::$file || empty(self::$buffer)) return;

        $content = implode("\n", self::$buffer) . "\n";
        file_put_contents(self::$file, $content, FILE_APPEND | LOCK_EX);
        self::$buffer = [];
    }

    public static function setBuffered(bool $buffered): void
    {
        self::$buffered = $buffered;
    }

    public static function getBuffer(): array
    {
        return self::$buffer;
    }
}
