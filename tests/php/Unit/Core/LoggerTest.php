<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Logger;

class LoggerTest extends TestCase
{
    private string $tmpLog;

    protected function setUp(): void
    {
        $this->tmpLog = sys_get_temp_dir() . '/test_log_' . uniqid() . '.log';
        Logger::setFile($this->tmpLog);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpLog)) unlink($this->tmpLog);
    }

    public function testInfoWritesToLog(): void
    {
        Logger::info('test message');
        $content = file_get_contents($this->tmpLog);
        $this->assertStringContainsString('[INFO]', $content);
        $this->assertStringContainsString('test message', $content);
    }

    public function testWarnWritesToLog(): void
    {
        Logger::warn('warning message');
        $content = file_get_contents($this->tmpLog);
        $this->assertStringContainsString('[WARN]', $content);
    }

    public function testErrorWritesToLog(): void
    {
        Logger::error('error message');
        $content = file_get_contents($this->tmpLog);
        $this->assertStringContainsString('[ERROR]', $content);
    }
}