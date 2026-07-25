<?php
declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use BinanceBot\Dashboard\Router;
use BinanceBot\Dashboard\Api;

class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = [];
    }

    public function testDispatchHealthEndpoint(): void
    {
        $mockApi = $this->createMock(Api::class);
        $mockApi->method('health')->willReturn(['ok' => true, 'ts' => date('Y-m-d H:i:s')]);

        $router = new Router($mockApi);
        ob_start();
        $router->dispatch(['_health' => '1']);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['ok']);
        $this->assertArrayHasKey('ts', $data);
    }

    public function testDispatchLogsEndpoint(): void
    {
        $mockApi = $this->createMock(Api::class);
        $mockApi->method('logs')->willReturn(['lines' => ['log line'], 'size' => 100]);

        $router = new Router($mockApi);
        ob_start();
        $router->dispatch(['_logs' => '1']);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('lines', $data);
        $this->assertArrayHasKey('size', $data);
        $this->assertEquals(['log line'], $data['lines']);
        $this->assertEquals(100, $data['size']);
    }

    public function testDispatchUnknownEndpointReturnsError(): void
    {
        $mockApi = $this->createMock(Api::class);

        $router = new Router($mockApi);
        ob_start();
        $router->dispatch(['unknown' => '1']);
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Unknown endpoint', $data['error']);
    }

    public function testConstructorCreatesDefaultApi(): void
    {
        $router = new Router();
        $reflection = new \ReflectionClass($router);
        $prop = $reflection->getProperty('api');
        $prop->setAccessible(true);
        $api = $prop->getValue($router);
        $this->assertInstanceOf(Api::class, $api);
    }

    public function testConstructorAcceptsInjectedApi(): void
    {
        $mockApi = $this->createMock(Api::class);
        $router = new Router($mockApi);
        $reflection = new \ReflectionClass($router);
        $prop = $reflection->getProperty('api');
        $prop->setAccessible(true);
        $api = $prop->getValue($router);
        $this->assertSame($mockApi, $api);
    }

}
