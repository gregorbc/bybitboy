<?php
declare(strict_types=1);

namespace Tests\Unit\Strategy
{
    use PHPUnit\Framework\TestCase;
    use BinanceBot\Strategy\GridManager;
    use BinanceBot\Strategy\GridAI;
    use BinanceBot\Strategy\GridML;
    use BinanceBot\Exchange\BybitFutures;

    class RiskConfigTest extends TestCase
    {
        protected function tearDown(): void
        {
            \Mockery::close();
        }

        private function makeManager(): GridManager
        {
            return new GridManager(
                \Mockery::mock(BybitFutures::class)->shouldIgnoreMissing(),
                new GridAI(),
                new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json')
            );
        }

        private function setCfg(GridManager $manager, array $cfg): void
        {
            $ref = new \ReflectionClass(GridManager::class);
            $prop = $ref->getProperty('cfg');
            $prop->setAccessible(true);
            $prop->setValue($manager, $cfg);
        }

        public function testApplyRiskConfigUsesDbValueWhenNotNull(): void
        {
            $manager = $this->makeManager();
            $this->setCfg($manager, [
                'id' => 1, 'symbol' => 'ETHUSDT',
                'max_daily_loss' => '12.5', 'recovery_loss_pct' => '4.0',
            ]);
            $manager->applyRiskConfig();
            $this->assertSame(12.5, $manager->getRiskMaxDailyLoss());
            $this->assertSame(4.0, $manager->getRiskRecoveryLossPct());
        }

        public function testApplyRiskConfigUsesConstantsWhenNull(): void
        {
            $manager = $this->makeManager();
            $this->setCfg($manager, [
                'id' => 1, 'symbol' => 'ETHUSDT',
                'max_daily_loss' => null, 'recovery_loss_pct' => null,
            ]);
            $manager->applyRiskConfig();
            $this->assertSame(G_MAX_DAILY_LOSS, $manager->getRiskMaxDailyLoss());
            $this->assertSame(G_RECOVERY_LOSS_PCT, $manager->getRiskRecoveryLossPct());
        }

        public function testApplyRiskConfigUsesConstantsWhenEmptyString(): void
        {
            $manager = $this->makeManager();
            $this->setCfg($manager, [
                'id' => 1, 'symbol' => 'ETHUSDT',
                'max_daily_loss' => '', 'recovery_loss_pct' => '',
            ]);
            $manager->applyRiskConfig();
            $this->assertSame(G_MAX_DAILY_LOSS, $manager->getRiskMaxDailyLoss());
            $this->assertSame(G_RECOVERY_LOSS_PCT, $manager->getRiskRecoveryLossPct());
        }
    }
}

namespace
{
    if (!function_exists('lI')) { function lI($m) {} }
    if (!function_exists('lW')) { function lW($m) {} }
    if (!function_exists('lE')) { function lE($m) {} }
    if (!function_exists('lg')) { function lg($m) {} }

    if (!defined('G_SYM')) { define('G_SYM', 'ETHUSDT'); }
    if (!defined('G_CAPITAL')) { define('G_CAPITAL', 100.0); }
    if (!defined('G_MAX_DAILY_LOSS')) { define('G_MAX_DAILY_LOSS', 12.0); }
    if (!defined('G_RECOVERY_LOSS_PCT')) { define('G_RECOVERY_LOSS_PCT', 3.0); }
    if (!defined('G_LEVERAGE')) { define('G_LEVERAGE', 20); }
    if (!defined('G_MARGIN_SAFETY')) { define('G_MARGIN_SAFETY', 0.40); }
    if (!defined('G_MIN_NOTIONAL')) { define('G_MIN_NOTIONAL', 5.0); }
    if (!defined('G_FIXED_LEVELS')) { define('G_FIXED_LEVELS', 14); }
    if (!defined('G_LONG_LEVELS')) { define('G_LONG_LEVELS', 7); }
    if (!defined('G_SHORT_LEVELS')) { define('G_SHORT_LEVELS', 7); }
    if (!defined('G_ML_BLEND_WEIGHT')) { define('G_ML_BLEND_WEIGHT', 0.90); }
    if (!defined('G_VL_BLEND_WEIGHT')) { define('G_VL_BLEND_WEIGHT', 0.10); }
    if (!defined('G_AI_INTERVAL')) { define('G_AI_INTERVAL', 120); }
    if (!defined('G_ML_RELOAD_CYCLES')) { define('G_ML_RELOAD_CYCLES', 600); }
    if (!defined('G_VOL_RELOAD_CYCLES')) { define('G_VOL_RELOAD_CYCLES', 300); }
    if (!defined('G_CYCLE_SEC')) { define('G_CYCLE_SEC', 1); }
    if (!defined('G_TF')) { define('G_TF', '15m'); }
    if (!defined('G_CANDLES')) { define('G_CANDLES', 60); }
    if (!defined('G_BASE_SPACING')) { define('G_BASE_SPACING', 0.0003); }
}
