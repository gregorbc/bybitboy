<?php
declare(strict_types=1);

namespace Tests\Unit;

use function sanitize;
use function checkToken;
use function getUptime;
use function botRunning;
use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    public function testSanitizeRemovesNonAlphanumeric(): void
    {
        $this->assertSame('ETHUSDT', sanitize('ETH/USDT'));
        $this->assertSame('BTCUSDT', sanitize('btc-usdt'));
        $this->assertSame('', sanitize('!!!'));
        $this->assertSame('A1B2', sanitize('a1-b2'));
    }

    public function testSanitizeLimitsLength(): void
    {
        $this->assertEquals(20, strlen(sanitize(str_repeat('A', 30))));
    }

    public function testCheckTokenEmptyAllows(): void
    {
        $_GET['token'] = 'wrong';
        $this->assertTrue(checkToken(''));
    }

    public function testCheckTokenValidMatches(): void
    {
        $_GET['token'] = 'secret123';
        $this->assertTrue(checkToken('secret123'));
    }

    public function testCheckTokenInvalidRejects(): void
    {
        $_GET['token'] = 'wrong';
        $this->assertFalse(checkToken('secret123'));
    }

    public function testGetUptimeFileNotExists(): void
    {
        $this->assertSame('--', getUptime('/nonexistent'));
    }

    public function testGetUptimeInvalidPid(): void
    {
        $tmp = sys_get_temp_dir() . '/pid_test_' . uniqid();
        file_put_contents($tmp, 'not-a-number');
        $this->assertSame('--', getUptime($tmp));
        unlink($tmp);
    }

    public function testBotRunningNoPidFile(): void
    {
        $this->assertFalse(botRunning('/nonexistent/pid', '/nonexistent/log'));
    }
}