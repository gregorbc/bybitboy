<?php

declare(strict_types=1);

namespace BinanceBot\Strategy;

use BinanceBot\Exchange\BybitFutures;
use BinanceBot\Core\Logger;

class LiquidationProtector
{
    public const VALID_TRIGGERS = [
        'dist_liq_pct_lt',
        'free_margin_pct_lt',
        'uPnL_pct_lt',
    ];

    public const VALID_ACTIONS = [
        'log_alert',
        'tighten_grid_spacing',
        'reduce_leverage',
        'hedge_partial',
        'add_margin',
        'close_worst_positions',
        'close_all_positions',
        'cancel_all_orders',
        'pause_bot_sec',
    ];

    public const DEFAULT_CIRCUIT_BREAKER_THRESHOLD = 5;
    public const DEFAULT_EVAL_INTERVAL_SEC = 8;
    public const DEFAULT_STATE_FILE = 'liq_prot_state.json';

    private BybitFutures $api;
    private array $config;
    private string $stateFile;
    private array $state = [
        'last_triggered' => [],
        'consecutive_errors' => 0,
        'disabled' => false,
    ];
    private int $evalIntervalSec;
    private int $circuitBreakerThreshold;
    private array $tiers = [];

    public function __construct(BybitFutures $api, array $config)
    {
        $this->api = $api;
        $this->config = $config;

        $logFile = $GLOBALS['LOG'] ?? '';
        $logDir = $logFile ? dirname($logFile) : sys_get_temp_dir();
        $this->stateFile = $logDir . '/' . self::DEFAULT_STATE_FILE;

        $this->loadState();
        $this->loadConfig();
    }

    private function loadConfig(): void
    {
        $this->evalIntervalSec = (int)(
            $this->config['global']['eval_interval_sec'] ?? self::DEFAULT_EVAL_INTERVAL_SEC
        );
        $this->circuitBreakerThreshold = (int)(
            $this->config['circuit_breaker']['max_consecutive_errors'] ?? self::DEFAULT_CIRCUIT_BREAKER_THRESHOLD
        );
        $this->tiers = $this->loadAndValidateTiers($this->config['tiers'] ?? []);
    }

    private function loadState(): void
    {
        if (file_exists($this->stateFile)) {
            $content = @file_get_contents($this->stateFile);
            if ($content !== false) {
                $data = @json_decode($content, true);
                if (is_array($data)) {
                    $this->state = array_merge($this->state, $data);
                }
            }
        }
    }

    private function saveState(): void
    {
        @file_put_contents($this->stateFile, json_encode($this->state, JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function loadAndValidateTiers(array $rawTiers): array
    {
        if (empty($rawTiers)) {
            return $this->getDefaultTiers();
        }

        $valid = [];
        foreach ($rawTiers as $level => $tier) {
            $level = (int)$level;

            if (!isset($tier['triggers']) || !is_array($tier['triggers']) || empty($tier['triggers'])) {
                throw new \InvalidArgumentException("Tier $level: triggers requerido (array no vacío)");
            }
            if (!isset($tier['actions']) || !is_array($tier['actions']) || empty($tier['actions'])) {
                throw new \InvalidArgumentException("Tier $level: actions requerido (array no vacío)");
            }

foreach ($tier['triggers'] as $j => $tr) {
            if (!isset($tr['type']) || !in_array($tr['type'], self::VALID_TRIGGERS, true)) {
                throw new \InvalidArgumentException("Tier $level trigger $j: trigger type inválido (válidos: " . implode(', ', self::VALID_TRIGGERS) . ")");
            }
                if (!isset($tr['threshold']) || !is_numeric($tr['threshold'])) {
                    throw new \InvalidArgumentException("Tier $level trigger $j: threshold numérico requerido");
                }
            }

foreach ($tier['actions'] as $j => $ac) {
            if (!isset($ac['type']) || !in_array($ac['type'], self::VALID_ACTIONS, true)) {
                throw new \InvalidArgumentException("Tier $level action $j: action type inválido (válidos: " . implode(', ', self::VALID_ACTIONS) . ")");
            }
            }

            $tier['cooldown_sec'] = (int)($tier['cooldown_sec'] ?? 120);
            $tier['enabled'] = (bool)($tier['enabled'] ?? true);

            $valid[$level] = $tier;
        }

        ksort($valid);
        return $valid;
    }

    private function getDefaultTiers(): array
    {
        return [
            1 => [
                'enabled' => true,
                'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 25]],
                'actions' => [
                    ['type' => 'tighten_grid_spacing', 'factor' => 0.9],
                    ['type' => 'log_alert', 'message' => 'L1: Dist liq {{dist_liq_pct}}%'],
                ],
                'cooldown_sec' => 60,
            ],
            2 => [
                'enabled' => true,
                'triggers' => [
                    ['type' => 'dist_liq_pct_lt', 'threshold' => 20],
                    ['type' => 'free_margin_pct_lt', 'threshold' => 25],
                    ['type' => 'uPnL_pct_lt', 'threshold' => -3],
                ],
                'actions' => [
                    ['type' => 'reduce_leverage', 'target' => 50],
                    ['type' => 'hedge_partial', 'pct' => 0.25],
                    ['type' => 'log_alert', 'message' => 'L2: Leverage 100→50 + hedge 25%'],
                ],
                'cooldown_sec' => 120,
            ],
            3 => [
                'enabled' => true,
                'triggers' => [
                    ['type' => 'dist_liq_pct_lt', 'threshold' => 15],
                    ['type' => 'free_margin_pct_lt', 'threshold' => 15],
                    ['type' => 'uPnL_pct_lt', 'threshold' => -5],
                ],
                'actions' => [
                    ['type' => 'add_margin', 'max_pct_free_balance' => 0.5],
                    ['type' => 'close_worst_positions', 'uPnL_pct_lt' => -3, 'max_count' => 2],
                    ['type' => 'log_alert', 'message' => 'L3: Margin top-up + close worst'],
                ],
                'cooldown_sec' => 180,
            ],
            4 => [
                'enabled' => true,
                'triggers' => [
                    ['type' => 'dist_liq_pct_lt', 'threshold' => 8],
                    ['type' => 'free_margin_pct_lt', 'threshold' => 5],
                ],
                'actions' => [
                    ['type' => 'close_all_positions'],
                    ['type' => 'cancel_all_orders'],
                    ['type' => 'pause_bot_sec', 'duration' => 1800],
                    ['type' => 'log_alert', 'message' => 'L4: EMERGENCY CLOSE ALL + PAUSE 30min'],
                ],
                'cooldown_sec' => 3600,
            ],
        ];
    }

    public function getEvalIntervalSec(): int
    {
        return $this->evalIntervalSec;
    }

    public function getCircuitBreakerThreshold(): int
    {
        return $this->circuitBreakerThreshold;
    }

    public function isDisabled(): bool
    {
        return $this->state['disabled'] ?? false;
    }

    public function getConsecutiveErrors(): int
    {
        return $this->state['consecutive_errors'] ?? 0;
    }

    public function getLastTriggered(int $tier): ?int
    {
        return $this->state['last_triggered'][$tier] ?? null;
    }

    public function getTiers(): array
    {
        return $this->tiers;
    }

    protected function recordError(): void
    {
        $this->state['consecutive_errors'] = ($this->state['consecutive_errors'] ?? 0) + 1;
        if ($this->state['consecutive_errors'] >= $this->circuitBreakerThreshold) {
            $this->state['disabled'] = true;
            Logger::error(
                '[LiquidationProtector] Circuit breaker triggered: ' .
                $this->circuitBreakerThreshold . ' consecutive errors, protection disabled'
            );
        }
        $this->saveState();
    }

    protected function recordSuccess(): void
    {
        if (($this->state['consecutive_errors'] ?? 0) > 0) {
            $this->state['consecutive_errors'] = 0;
            $this->saveState();
        }
    }

    protected function recordTrigger(int $tier): void
    {
        $this->state['last_triggered'][$tier] = time();
        $this->saveState();
    }

    protected function getApi(): BybitFutures
    {
        return $this->api;
    }
}
