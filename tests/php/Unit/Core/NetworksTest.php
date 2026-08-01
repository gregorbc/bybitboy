<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Networks;
use BinanceBot\Core\Config;

class NetworksTest extends TestCase
{
    protected function setUp(): void
    {
        Config::reset();
    }

    public function testDefaultsIncludeEthAndBsc(): void
    {
        $all = Networks::all();
        $this->assertArrayHasKey('eth', $all);
        $this->assertArrayHasKey('bsc', $all);
    }

    public function testEthUsdtContract(): void
    {
        $c = Networks::contracts('eth');
        $this->assertSame('0xdac17f958d2ee523a2206206994597c13d831ec7', strtolower($c['USDT']));
    }

    public function testBscUsdcContract(): void
    {
        $c = Networks::contracts('bsc');
        $this->assertSame('0x8ac76a51cc950d9822d68b83fe1ad97b32cd580d', strtolower($c['USDC']));
    }

    public function testValidateAddress(): void
    {
        $this->assertTrue(Networks::validateAddress('eth', '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B'));
        $this->assertFalse(Networks::validateAddress('eth', 'Ab5801a7D398351b8bE11C439e05C5B3259aeC9B'));
        $this->assertFalse(Networks::validateAddress('eth', '0xXYZ801a7D398351b8bE11C439e05C5B3259aeC9B'));
        $this->assertFalse(Networks::validateAddress('xx', '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B'));
    }
}