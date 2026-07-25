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

    public function testLogsReturnsLines(): void
    {
        $api = new Api();
        $result = $api->logs();
        $this->assertArrayHasKey('lines', $result);
        $this->assertIsArray($result['lines']);
    }
}
