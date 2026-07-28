<?php

declare(strict_types=1);

namespace BinanceBot\Strategy;

use BinanceBot\Exchange\BybitFutures;
use BinanceBot\Core\Logger;

class LiquidationProtector
{
    public const VALID_TRIGGERS = [
        'dist_liq_pct_lt',
        'margin_ratio_gt',
        'unrealized_pnl_pct_lt',
    ];

    public const VALID_ACTIONS = [
        'log_alert',
        'reduce_position_pct',
        'hedge_open',
        'close_position',
        'cancel_orders',
        'alert_discord',
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
