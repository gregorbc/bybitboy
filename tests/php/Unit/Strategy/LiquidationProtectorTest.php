<?php
declare(strict_types=1);

namespace Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use BinanceBot\Strategy\LiquidationProtector;
use BinanceBot\Exchange\BybitFutures;
use Mockery;

class LiquidationProtectorTest extends TestCase
{
    protected BybitFutures|Mockery\MockInterface $api;
    protected array $config;
    protected LiquidationProtector $protector;

    protected function setUp(): void
    {
        $this->api = Mockery::mock(BybitFutures::class);
        $this->config = [
            'enabled' => true,
            'tiers' => [
                1 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 25]],
                    'actions' => [['type' => 'log_alert']],
                    'cooldown_sec' => 60,
                ],
            ],
            'global' => ['eval_interval_sec' => 8],
            'circuit_breaker' => ['max_consecutive_errors' => 5],
        ];
        $this->protector = new LiquidationProtector($this->api, $this->config);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testConstructsWithValidConfig(): void
    {
        $this->assertInstanceOf(LiquidationProtector::class, $this->protector);
    }

    public function testThrowsOnMissingTriggers(): void
    {
        $config = [
            'enabled' => true,
            'tiers' => [
                1 => [
                    'enabled' => true,
                    'actions' => [['type' => 'log_alert']],
                ],
            ],
        ];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('triggers requerido');
        new LiquidationProtector($this->api, $config);
    }

    public function testThrowsOnMissingActions(): void
    {
        $config = [
            'enabled' => true,
            'tiers' => [
                1 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 25]],
                ],
            ],
        ];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('actions requerido');
        new LiquidationProtector($this->api, $config);
    }

    public function testThrowsOnUnknownTriggerType(): void
    {
        $config = [
            'enabled' => true,
            'tiers' => [
                1 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'unknown_type', 'threshold' => 25]],
                    'actions' => [['type' => 'log_alert']],
                ],
            ],
        ];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trigger type inválido');
        new LiquidationProtector($this->api, $config);
    }

    public function testThrowsOnUnknownActionType(): void
    {
        $config = [
            'enabled' => true,
            'tiers' => [
                1 => [
                    'enabled' => true,
                    'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 25]],
                    'actions' => [['type' => 'unknown_action']],
                ],
            ],
        ];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('action type inválido');
        new LiquidationProtector($this->api, $config);
    }

    public function testLoadsDefaultTiersWhenConfigEmpty(): void
    {
        $config = ['enabled' => true, 'tiers' => []];
        $prot = new LiquidationProtector($this->api, $config);
        $this->assertCount(4, $prot->getTiers()); // L1-L4 defaults
    }
}