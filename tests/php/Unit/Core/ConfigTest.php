<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Config;

class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        Config::reset();
    }

    public function testGetInstanceReturnsSingleton(): void
   {
        $a = Config::getInstance();
        $b = Config::getInstance();
        $this->assertSame($a, $b);
    }

    public function testAllReturnsArray(): void
    {
        $config = Config::getInstance();
        $this->assertIsArray($config->all());
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $config = Config::getInstance();
        $this->assertSame('fallback', $config->get('nonexistent.key', 'fallback'));
    }

    public function testGetWithDotNotation(): void
    {
        $config = Config::getInstance();
        $config->set(['bot', 'symbol'], 'BNBUSDT');
        $this->assertSame('BNBUSDT', $config->get('bot.symbol'));
    }

    public function testEnvFileCandidatesIncludeProjectRoot(): void
    {
        $root = dirname(__DIR__, 4);
        $this->assertContains($root . '/.env', Config::envFileCandidates());
    }
}
