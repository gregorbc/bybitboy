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
    public array $lastTriggered = [];
    private int $evalIntervalSec;
    private int $circuitBreakerThreshold;
    private array $tiers = [];
    private int $evalCycleCount = 0;
    private int $evalIntervalCycles;

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
        $this->evalIntervalCycles = (int)(
            $this->config['global']['eval_interval_cycles'] ?? 1
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
                    $this->lastTriggered = $this->state['last_triggered'] ?? [];
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
            // config.json uses list-style tiers with an explicit 'level' field;
            // honor it so cooldowns / last_triggered map to tier 1..4.
            if (isset($tier['level'])) {
                $level = (int)$tier['level'];
            } else {
                $level = (int)$level;
            }
            if ($level < 1) {
                $level = 1;
            }

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
        $this->lastTriggered[$tier] = time();
        $this->saveState();
    }

    protected function getApi(): BybitFutures
    {
        return $this->api;
    }

    public function evaluate(float $price, array $positions, float $balance): void
    {
        if (!($this->config['enabled'] ?? false)) {
            return;
        }

        if ($this->state['disabled'] ?? false) {
            return;
        }

        $this->evalCycleCount++;

        if ($this->evalCycleCount % max(1, $this->evalIntervalCycles) !== 0) {
            return;
        }

        try {
            $this->evaluateInternal($price, $positions, $balance);
            $this->recordSuccess();
        } catch (\Throwable $e) {
            Logger::warn("[LiquidationProtector] evaluate error: " . $e->getMessage());
            $this->recordError();
        }
    }

    private function evaluateInternal(float $price, array $positions, float $balance): void
    {
        if (empty($positions)) {
            return;
        }

        $metrics = $this->computeAggregateMetrics($price, $positions, $balance);

        foreach ($this->tiers as $level => $tier) {
            if (!$tier['enabled']) {
                continue;
            }

            if ($this->isInCooldown($level, $tier['cooldown_sec'])) {
                continue;
            }

            if ($this->evaluateTriggers($tier['triggers'], $metrics)) {
                $this->executeTierActions($level, $tier['actions'], $metrics, $price, $positions, $balance);
                $this->recordTrigger($level);
                $this->logTierActivation($level, $tier, $metrics);
                break;
            }
        }
    }

    private function computeAggregateMetrics(float $price, array $positions, float $balance): array
    {
        $totalPosValue = 0.0;
        $totalUPnL = 0.0;
        $minDistLiqPct = PHP_FLOAT_MAX;
        $worstUPnLPct = 0.0;
        $side = 'Buy';
        $totalQty = 0.0;

        foreach ($positions as $pos) {
            $side = $pos['side'] ?? 'Buy';
            $qty = abs((float)($pos['positionAmt'] ?? $pos['size'] ?? 0));
            $entryPx = (float)($pos['entryPrice'] ?? 0);
            $liqPx = (float)($pos['liquidationPrice'] ?? $pos['liqPrice'] ?? 0);
            $uPnL = (float)($pos['unRealizedProfit'] ?? 0);
            $leverage = (int)($pos['leverage'] ?? 1);

            // Invalid positions are skipped, but liquidationPrice==0 (Bybit demo
            // reports 0 for low-risk positions) must NOT hide free_margin/uPnL metrics.
            if ($qty <= 0 || $entryPx <= 0) {
                continue;
            }

            $posValue = $qty * $price;
            $totalPosValue += $posValue;
            $totalUPnL += $uPnL;
            $totalQty += $qty;

            // Dist to liq is only meaningful when the exchange provides a liq price;
            // otherwise use 999 so dist-based triggers never false-fire.
            $distLiqPct = $liqPx > 0 ? abs($price - $liqPx) / $price * 100 : 999;
            if ($distLiqPct < $minDistLiqPct) {
                $minDistLiqPct = $distLiqPct;
            }

            $uPnLPct = $posValue > 0 ? ($uPnL / $posValue) * 100 : 0;
            if ($uPnLPct < $worstUPnLPct) {
                $worstUPnLPct = $uPnLPct;
            }
        }

        $freeMargin = $balance + $totalUPnL;
        $freeMarginPct = $totalPosValue > 0 ? ($freeMargin / $totalPosValue) * 100 : 100;
        $distLiqPct = $minDistLiqPct === PHP_FLOAT_MAX ? 999 : $minDistLiqPct;

        return [
            'dist_liq_pct' => $distLiqPct,
            'free_margin_pct' => $freeMarginPct,
            'uPnL_pct' => $worstUPnLPct,
            'side' => $side,
            'qty' => $totalQty,
            'balance' => $balance,
            'total_pos_value' => $totalPosValue,
            'total_u_pnl' => $totalUPnL,
            'free_margin' => $freeMargin,
        ];
    }

    private function evaluateTriggers(array $triggers, array $metrics): bool
    {
        foreach ($triggers as $trigger) {
            $type = $trigger['type'];
            $threshold = (float)$trigger['threshold'];

            switch ($type) {
                case 'dist_liq_pct_lt':
                    if ($metrics['dist_liq_pct'] < $threshold) {
                        return true;
                    }
                    break;
                case 'free_margin_pct_lt':
                    if ($metrics['free_margin_pct'] < $threshold) {
                        return true;
                    }
                    break;
                case 'uPnL_pct_lt':
                    if ($metrics['uPnL_pct'] < $threshold) {
                        return true;
                    }
                    break;
            }
        }
        return false;
    }

    private function isInCooldown(int $level, int $cooldownSec): bool
    {
        $lastTriggered = $this->state['last_triggered'][$level] ?? 0;
        if ($lastTriggered === 0) {
            return false;
        }
        return (time() - $lastTriggered) < $cooldownSec;
    }

    private function executeTierActions(
        int $level,
        array $actions,
        array $metrics,
        float $price,
        array $positions,
        float $balance
    ): void {
        foreach ($actions as $action) {
            $type = $action['type'];
            try {
                $this->executeAction($type, $action, $metrics, $price, $positions, $balance);
            } catch (\Throwable $e) {
                Logger::error("[LiquidationProtector] L{$level} action '{$type}' failed: " . $e->getMessage());
                throw $e;
            }
        }
    }

    private function executeAction(
        string $type,
        array $action,
        array $metrics,
        float $price,
        array $positions,
        float $balance
    ): void {
        $api = $this->getApi();

        switch ($type) {
            case 'log_alert':
                $msg = $action['message'] ?? "Liquidation protection triggered";
                $msg = $this->interpolateMessage($msg, $metrics);
                Logger::warn("[LiquidationProtector] {$msg}");
                break;

            case 'tighten_grid_spacing':
                // Not an exchange operation: the grid spacing lives in GridManager.
                // Log it as a warning so operators know the alert fired without
                // crashing the protector (previously called an undefined method).
                $factor = (float)($action['factor'] ?? 0.9);
                Logger::warn("[LiquidationProtector] tighten_grid_spacing requiere soporte de GridManager — omitido (factor {$factor})");
                break;

            case 'reduce_leverage':
                $target = (int)($action['target'] ?? 50);
                foreach ($positions as $pos) {
                    $symbol = $pos['symbol'] ?? G_SYM;
                    if ($symbol) {
                        $api->setLeverage($symbol, $target);
                    }
                }
                Logger::info("[LiquidationProtector] Reduced leverage to {$target}x");
                break;

            case 'hedge_partial':
                $pct = (float)($action['pct'] ?? 0.25);
                foreach ($positions as $pos) {
                    $symbol = $pos['symbol'] ?? G_SYM;
                    $side = $pos['side'] ?? 'Buy';
                    $qty = abs((float)($pos['positionAmt'] ?? $pos['size'] ?? 0));
                    $hedgeQty = $qty * $pct;
                    if ($symbol && $hedgeQty > 0) {
                        $api->marketClose($symbol, $side, $hedgeQty);
                    }
                }
                $hedgePct = $pct * 100;
                Logger::info("[LiquidationProtector] Hedged partial position ({$hedgePct}%)");
                break;

            case 'add_margin':
                $maxPctFree = (float)($action['max_pct_free_balance'] ?? 0.5);
                $available = $balance * $maxPctFree;
                foreach ($positions as $pos) {
                    $symbol = $pos['symbol'] ?? G_SYM;
                    if ($symbol && $available > 0) {
                        if (method_exists($api, 'addMargin')) {
                            $api->addMargin($symbol, $available);
                        } else {
                            Logger::warn("[LiquidationProtector] add_margin no soportado por la API — omitido");
                        }
                        $available = 0;
                        break;
                    }
                }
                Logger::info("[LiquidationProtector] Added margin from free balance");
                break;

            case 'close_worst_positions':
                $uPnLPctThreshold = (float)($action['uPnL_pct_lt'] ?? -3);
                $maxCount = (int)($action['max_count'] ?? 2);
                $sorted = $this->sortPositionsByUPnL($positions);
                $closed = 0;
                foreach ($sorted as $pos) {
                    if ($closed >= $maxCount) {
                        break;
                    }
                    $uPnL = (float)($pos['unRealizedProfit'] ?? 0);
                    $qty = abs((float)($pos['positionAmt'] ?? $pos['size'] ?? 0));
                    $entryPx = (float)($pos['entryPrice'] ?? 0);
                    if ($entryPx > 0 && $qty > 0) {
                        $posValue = $qty * $price;
                        $pct = $posValue > 0 ? ($uPnL / $posValue) * 100 : 0;
                        if ($pct < $uPnLPctThreshold) {
                            $symbol = $pos['symbol'] ?? G_SYM;
                            $side = $pos['side'] ?? 'Buy';
                            if ($symbol) {
                                $api->marketClose($symbol, $side, $qty);
                                $closed++;
                            }
                        }
                    }
                }
                Logger::info("[LiquidationProtector] Closed {$closed} worst positions");
                break;

            case 'close_all_positions':
                foreach ($positions as $pos) {
                    $symbol = $pos['symbol'] ?? G_SYM;
                    $side = $pos['side'] ?? 'Buy';
                    $qty = abs((float)($pos['positionAmt'] ?? $pos['size'] ?? 0));
                    if ($symbol && $qty > 0) {
                        $api->marketClose($symbol, $side, $qty);
                    }
                }
                Logger::warn("[LiquidationProtector] EMERGENCY: Closed all positions");
                break;

            case 'cancel_all_orders':
                foreach ($positions as $pos) {
                    $symbol = $pos['symbol'] ?? G_SYM;
                    if ($symbol) {
                        $api->cancelAll($symbol);
                    }
                }
                Logger::info("[LiquidationProtector] Cancelled all open orders");
                break;

            case 'pause_bot_sec':
                $duration = (int)($action['duration'] ?? 1800);
                $this->pauseBot($duration);
                Logger::warn("[LiquidationProtector] Bot paused for {$duration} seconds");
                break;

            default:
                Logger::warn("[LiquidationProtector] Unknown action type: {$type}");
        }
    }

    private function sortPositionsByUPnL(array $positions): array
    {
        uasort($positions, function ($a, $b) {
            $uPnLA = (float)($a['unRealizedProfit'] ?? 0);
            $uPnLB = (float)($b['unRealizedProfit'] ?? 0);
            return $uPnLA <=> $uPnLB;
        });
        return $positions;
    }

    private function interpolateMessage(string $message, array $metrics): string
    {
        $placeholders = [
            '{{dist_liq_pct}}' => number_format($metrics['dist_liq_pct'], 2),
            '{{free_margin_pct}}' => number_format($metrics['free_margin_pct'], 2),
            '{{uPnL_pct}}' => number_format($metrics['uPnL_pct'], 2),
            '{{balance}}' => number_format($metrics['balance'], 2),
            '{{free_margin}}' => number_format($metrics['free_margin'], 2),
        ];
        return strtr($message, $placeholders);
    }

    private function logTierActivation(int $level, array $tier, array $metrics): void
    {
        $logData = [
            'event' => 'liquidation_protection_triggered',
            'level' => $level,
            'triggers' => $tier['triggers'],
            'actions' => array_column($tier['actions'], 'type'),
            'metrics' => $metrics,
            'timestamp' => time(),
        ];
        Logger::warn('[LiquidationProtector] ' . json_encode($logData, JSON_UNESCAPED_UNICODE));
    }

    private function pauseBot(int $durationSec): void
    {
        $pauseFile = sys_get_temp_dir() . '/bot_pause_' . getmypid() . '.tmp';
        @file_put_contents($pauseFile, (string)(time() + $durationSec), LOCK_EX);
    }
}
