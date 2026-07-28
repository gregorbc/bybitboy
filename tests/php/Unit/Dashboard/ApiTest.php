<?php
declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use BinanceBot\Dashboard\Api;
use BinanceBot\Core\Config;
use BinanceBot\Core\Database;

class ApiTest extends TestCase
{
    protected function setUp(): void
    {
        Config::reset();
        Database::reset();
    }

    public function testHealthReturnsArray(): void
    {
        $api = new Api();
        $result = $api->health();
        $this->assertArrayHasKey('ok', $result);
        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('ts', $result);
    }

    public function testHealthReturnsBotRunningField(): void
    {
        $api = new Api();
        $result = $api->health();
        $this->assertArrayHasKey('bot_running', $result);
        $this->assertIsBool($result['bot_running']);
    }

    public function testHealthReturnsBotUptimeField(): void
    {
        $api = new Api();
        $result = $api->health();
        $this->assertArrayHasKey('bot_uptime', $result);
        $this->assertIsString($result['bot_uptime']);
    }

    public function testHealthReturnsLogMtimeField(): void
    {
        $api = new Api();
        $result = $api->health();
        $this->assertArrayHasKey('log_mtime', $result);
    }

    public function testHealthReturnsLogSizeField(): void
    {
        $api = new Api();
        $result = $api->health();
        $this->assertArrayHasKey('log_size', $result);
        $this->assertIsInt($result['log_size']);
    }

    public function testHealthReturnsMysqlField(): void
    {
        $api = new Api();
        $result = $api->health();
        $this->assertArrayHasKey('mysql', $result);
        $this->assertIsBool($result['mysql']);
    }

    public function testLogsReturnsLines(): void
    {
        $api = new Api();
        $result = $api->logs();
        $this->assertArrayHasKey('lines', $result);
        $this->assertIsArray($result['lines']);
    }

    public function testLogsReturnsSizeField(): void
    {
        $api = new Api();
        $result = $api->logs();
        $this->assertArrayHasKey('size', $result);
        $this->assertIsInt($result['size']);
    }

    public function testLogsReturnsLinesArrayStructure(): void
    {
        $api = new Api();
        $result = $api->logs();
        $this->assertArrayHasKey('lines', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertIsArray($result['lines']);
        foreach ($result['lines'] as $line) {
            $this->assertIsString($line);
        }
    }

    public function testLogsWithMissingLogFileReturnsDefaults(): void
    {
        $config = Config::getInstance();
        $config->set(['paths', 'log'], '/tmp/nonexistent_test_log_' . uniqid() . '.log');

        $api = new Api();
        $result = $api->logs();
        $this->assertArrayHasKey('lines', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertIsArray($result['lines']);
        $this->assertEquals(0, $result['size']);
    }

    public function testLogsWithEmptyLogFileReturnsZeroSize(): void
    {
        $tmpFile = sys_get_temp_dir() . '/empty_test_log_' . uniqid() . '.log';
        file_put_contents($tmpFile, '');

        $config = Config::getInstance();
        $config->set(['paths', 'log'], $tmpFile);

        $api = new Api();
        $result = $api->logs();
        $this->assertArrayHasKey('lines', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertIsArray($result['lines']);
        $this->assertEquals(0, $result['size']);

        @unlink($tmpFile);
    }

    public function testLogsWithContentReturnsLines(): void
    {
        $tmpFile = sys_get_temp_dir() . '/test_log_with_content_' . uniqid() . '.log';
        file_put_contents($tmpFile, "line1\nline2\n\nline3\n");

        $config = Config::getInstance();
        $config->set(['paths', 'log'], $tmpFile);

        $api = new Api();
        $result = $api->logs();
        $this->assertArrayHasKey('lines', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertNotEmpty($result['lines']);
        $this->assertGreaterThan(0, $result['size']);

        @unlink($tmpFile);
    }

    public function testLogsWithLargeFileReturnsTail(): void
    {
        $tmpFile = sys_get_temp_dir() . '/large_test_log_' . uniqid() . '.log';
        $lines = [];
        for ($i = 0; $i < 500; $i++) {
            $lines[] = "log line $i";
        }
        file_put_contents($tmpFile, implode("\n", $lines) . "\n");

        $config = Config::getInstance();
        $config->set(['paths', 'log'], $tmpFile);

        $api = new Api();
        $result = $api->logs();
        $this->assertArrayHasKey('lines', $result);
        $this->assertArrayHasKey('size', $result);
        $this->assertNotEmpty($result['lines']);
        $this->assertLessThanOrEqual(400, count($result['lines']));

        @unlink($tmpFile);
    }
}
