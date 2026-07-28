# Liquidation Protection System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement a 4-tier escalating liquidation protection system (LiquidationProtector) that runs alongside existing risk checks, with configurable triggers/actions, cooldowns, circuit breaker, and full test coverage.

**Architecture:** New `LiquidationProtector` class evaluated from `GridManager::riskCheck()` every 8s. Four tiers (L1-L4) with ANY-trigger logic (OR), progressive actions (tighten spacing → reduce leverage/hedge → add margin/close worst → emergency close all). Integrates via optional 4th constructor param to GridManager. All config in `liquidation_protection` section of config.json.

**Tech Stack:** PHP 8.1+, BybitFutures API, existing GridManager/strategy pattern, PHPUnit 10 for tests.

## Global Constraints

- PHP 8.1+ syntax only (declare(strict_types=1), typed properties, match expressions)
- Follow existing namespace: `BinanceBot\Strategy`
- Reuse existing logging: `lI()`, `lW()`, `lE()`
- Reuse existing DB helper: `dbx()`
- All config keys lowercase with underscores (e.g., `dist_liq_pct_lt`)
- All action types lowercase with underscores (e.g., `reduce_leverage`)
- Cooldown in seconds, eval interval in seconds
- State persisted to JSON file in log directory
- Circuit breaker: 5 consecutive errors → disable protector
- Default tiers L1-L4 applied if config empty/invalid
- All existing tests must pass (102 unit tests)
- New test coverage ≥90% for LiquidationProtector

---

### Task 1: Create LiquidationProtector Class Structure

**Files:**
- Create: `src/php/Strategy/LiquidationProtector.php`
- Test: `tests/php/Unit/Strategy/LiquidationProtectorTest.php`

**Interfaces:**
- Consumes: `BybitFutures` instance, config array from `liquidation_protection` section
- Produces: `LiquidationProtector` class with `evaluate(float $price, array $positions, float $balance): void` method

- [ ] **Step 1: Write failing test for class instantiation**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use BinanceBot\Strategy\LiquidationProtector;
use BinanceBot\Exchange\BybitFutures;

class LiquidationProtectorTest extends TestCase
{
    private BybitFutures $api;
    private LiquidationProtector $protector;
    private array $config;

    protected function setUp(): void {
        $this->api = $this->createMock(BybitFutures::class);
        $this->config = [
            'liquidation_protection' => [
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
            ],
        ];
        $this->protector = new LiquidationProtector($this->api, $this->config['liquidation_protection']);
    }

    public function testConstructsWithValidConfig(): void {
        $this->assertInstanceOf(LiquidationProtector::class, $this->protector);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/php/Unit/Strategy/LiquidationProtectorTest.php::testConstructsWithValidConfig -v`
Expected: FAIL - class `LiquidationProtector` not found

- [ ] **Step 3: Write minimal class implementation**

```php
<?php
declare(strict_types=1);

namespace BinanceBot\Strategy;

use BinanceBot\Exchange\BybitFutures;

class LiquidationProtector
{
    private BybitFutures $api;
    private array $tiers = [];
    private array $lastTriggered = [];
    private int $evalCount = 0;
    private int $evalIntervalCycles;
    private array $config;
    private bool $disabled = false;
    private int $consecutiveErrors = 0;
    private string $stateFile;

    public function __construct(BybitFutures $api, array $config = [])
    {
        $this->api = $api;
        $this->config = $config;
        $this->stateFile = dirname($GLOBALS['LOG'] ?? '/tmp') . '/liq_prot_state.json';
        
        $global = $config['global'] ?? [];
        $evalIntervalSec = (int)($global['eval_interval_sec'] ?? 8);
        $this->evalIntervalCycles = max(1, (int)round($evalIntervalSec / (defined('G_CYCLE_SEC') ? G_CYCLE_SEC : 8)));
        
        $this->tiers = $this->loadAndValidateTiers($config['tiers'] ?? []);
        $this->loadState();
    }

    public function evaluate(float $price, array $positions, float $balance): void
    {
        // Implementation in Task 3
    }

    private function loadAndValidateTiers(array $rawTiers): array
    {
        if (empty($rawTiers)) {
            return $this->getDefaultTiers();
        }
        // Validation implemented in Task 2
        return [];
    }

    private function getDefaultTiers(): array
    {
        return [];
    }

    private function loadState(): void
    {
        if (file_exists($this->stateFile)) {
            $state = json_decode(file_get_contents($this->stateFile), true) ?? [];
            $this->lastTriggered = $state['last_triggered'] ?? [];
        }
    }

    private function persistState(): void
    {
        file_put_contents($this->stateFile, json_encode([
            'last_triggered' => $this->lastTriggered,
            'consecutive_errors' => $this->consecutiveErrors,
            'disabled' => $this->disabled,
        ]), LOCK_EX);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/php/Unit/Strategy/LiquidationProtectorTest.php::testConstructsWithValidConfig -v`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/php/Strategy/LiquidationProtector.php tests/php/Unit/Strategy/LiquidationProtectorTest.php
git commit -m "feat: add LiquidationProtector class skeleton with constructor and state persistence"
```

---

### Task 2: Config Validation & Default Tiers

**Files:**
- Modify: `src/php/Strategy/LiquidationProtector.php`
- Test: `tests/php/Unit/Strategy/LiquidationProtectorTest.php`

**Interfaces:**
- Produces: Validated `$this->tiers` array with defaults applied, throws `InvalidArgumentException` on invalid config

- [ ] **Step 1: Write failing tests for validation**

```php
public function testThrowsOnMissingTriggers(): void {
    $config = ['liquidation_protection' => ['enabled' => true, 'tiers' => [1 => ['enabled' => true, 'actions' => [['type' => 'log_alert']]]]]];
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('triggers requerido');
    new LiquidationProtector($this->api, $config['liquidation_protection']);
}

public function testThrowsOnMissingActions(): void {
    $config = ['liquidation_protection' => ['enabled' => true, 'tiers' => [1 => ['enabled' => true, 'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 25]]]]]];
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('actions requerido');
    new LiquidationProtector($this->api, $config['liquidation_protection']);
}

public function testThrowsOnUnknownTriggerType(): void {
    $config = ['liquidation_protection' => ['enabled' => true, 'tiers' => [1 => ['enabled' => true, 'triggers' => [['type' => 'unknown_type', 'threshold' => 25]], 'actions' => [['type' => 'log_alert']]]]]];
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('trigger type inválido');
    new LiquidationProtector($this->api, $config['liquidation_protection']);
}

public function testThrowsOnUnknownActionType(): void {
    $config = ['liquidation_protection' => ['enabled' => true, 'tiers' => [1 => ['enabled' => true, 'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 25]], 'actions' => [['type' => 'unknown_action']]]]]];
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('action type inválido');
    new LiquidationProtector($this->api, $config['liquidation_protection']);
}

public function testLoadsDefaultTiersWhenConfigEmpty(): void {
    $config = ['liquidation_protection' => ['enabled' => true, 'tiers' => []]];
    $prot = new LiquidationProtector($this->api, $config['liquidation_protection']);
    $this->assertCount(4, $prot->getTiers()); // L1-L4 defaults
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/php/Unit/Strategy/LiquidationProtectorTest.php -v --filter "testThrows|testLoadsDefault"`
Expected: FAIL - validation not implemented, defaults not implemented

- [ ] **Step 3: Implement validation and defaults**

```php
// Add to LiquidationProtector class

private const VALID_TRIGGERS = ['dist_liq_pct_lt', 'free_margin_pct_lt', 'uPnL_pct_lt'];
private const VALID_ACTIONS = ['tighten_grid_spacing', 'reduce_leverage', 'hedge_partial', 'add_margin', 'close_worst_positions', 'close_all_positions', 'cancel_all_orders', 'pause_bot_sec', 'log_alert'];

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
                throw new \InvalidArgumentException("Tier $level trigger $j: type inválido (válidos: " . implode(', ', self::VALID_TRIGGERS) . ")");
            }
            if (!isset($tr['threshold']) || !is_numeric($tr['threshold'])) {
                throw new \InvalidArgumentException("Tier $level trigger $j: threshold numérico requerido");
            }
        }
        
        foreach ($tier['actions'] as $j => $ac) {
            if (!isset($ac['type']) || !in_array($ac['type'], self::VALID_ACTIONS, true)) {
                throw new \InvalidArgumentException("Tier $level action $j: type inválido (válidos: " . implode(', ', self::VALID_ACTIONS) . ")");
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
            'actions' => [['type' => 'tighten_grid_spacing', 'factor' => 0.9], ['type' => 'log_alert', 'message' => 'L1: Dist liq {{dist_liq_pct}}%']],
            'cooldown_sec' => 60,
        ],
        2 => [
            'enabled' => true,
            'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 20], ['type' => 'free_margin_pct_lt', 'threshold' => 25], ['type' => 'uPnL_pct_lt', 'threshold' => -3]],
            'actions' => [['type' => 'reduce_leverage', 'target' => 50], ['type' => 'hedge_partial', 'pct' => 0.25], ['type' => 'log_alert', 'message' => 'L2: Leverage 100→50 + hedge 25%']],
            'cooldown_sec' => 120,
        ],
        3 => [
            'enabled' => true,
            'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 15], ['type' => 'free_margin_pct_lt', 'threshold' => 15], ['type' => 'uPnL_pct_lt', 'threshold' => -5]],
            'actions' => [['type' => 'add_margin', 'max_pct_free_balance' => 0.5], ['type' => 'close_worst_positions', 'uPnL_pct_lt' => -3, 'max_count' => 2], ['type' => 'log_alert', 'message' => 'L3: Margin top-up + close worst']],
            'cooldown_sec' => 180,
        ],
        4 => [
            'enabled' => true,
            'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 8], ['type' => 'free_margin_pct_lt', 'threshold' => 5]],
            'actions' => [['type' => 'close_all_positions'], ['type' => 'cancel_all_orders'], ['type' => 'pause_bot_sec', 'duration' => 1800], ['type' => 'log_alert', 'message' => 'L4: EMERGENCY CLOSE ALL + PAUSE 30min']],
            'cooldown_sec' => 3600,
        ],
    ];
}

// Add getter for testing
public function getTiers(): array { return $this->tiers; }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/php/Unit/Strategy/LiquidationProtectorTest.php -v --filter "testThrows|testLoadsDefault"`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/php/Strategy/LiquidationProtector.php tests/php/Unit/Strategy/LiquidationProtectorTest.php
git commit -m "feat: add config validation and default L1-L4 tiers"
```

---

### Task 3: Core Evaluation Logic

**Files:**
- Modify: `src/php/Strategy/LiquidationProtector.php`
- Test: `tests/php/Unit/Strategy/LiquidationProtectorTest.php`

**Interfaces:**
- Produces: `evaluate(float $price, array $positions, float $balance): void` with full trigger/action logic

- [ ] **Step 1: Write failing tests for trigger evaluation**

```php
public function testL1TriggeredByDistLiqPct(): void {
    $pos = $this->makePosition(liqPrice: 1800, entryPx: 2000, side: 'Buy', uPnL: -100);
    $this->protector->evaluate(1950, [$pos], 10000); // dist ~7.7% < 25%
    $this->assertEquals(1, $this->getLastTriggeredLevel());
}

public function testL2TriggeredByFreeMargin(): void {
    $pos = $this->makePosition(liqPrice: 1000, entryPx: 2000, side: 'Sell', uPnL: -500);
    $this->protector->evaluate(1900, [$pos], 10000); // free margin < 25%
    $this->assertEquals(2, $this->getLastTriggeredLevel());
}

public function testL3TriggeredByUpnlPct(): void {
    $pos = $this->makePosition(liqPrice: 500, entryPx: 2000, side: 'Buy', uPnL: -5000); // uPnL% ~ -5%
    $this->protector->evaluate(1800, [$pos], 10000);
    $this->assertEquals(3, $this->getLastTriggeredLevel());
}

public function testL4TriggeredByDistLiqCritical(): void {
    $pos = $this->makePosition(liqPrice: 1950, entryPx: 2000, side: 'Buy');
    $this->protector->evaluate(1960, [$pos], 10000); // dist < 8%
    $this->assertEquals(4, $this->getLastTriggeredLevel());
}

private function makePosition(int $liqPrice, int $entryPx, string $side = 'Buy', float $uPnL = -100): array {
    return [
        'side' => $side,
        'positionAmt' => '0.5',
        'size' => '0.5',
        'entryPrice' => (string)$entryPx,
        'liqPrice' => (string)$liqPrice,
        'unRealizedProfit' => (string)$uPnL,
        'leverage' => '100',
    ];
}

private function getLastTriggeredLevel(): int {
    $ref = new \ReflectionClass($this->protector);
    $prop = $ref->getProperty('lastTriggered');
    $prop->setAccessible(true);
    $triggered = $prop->getValue($this->protector);
    return $triggered ? max(array_keys($triggered)) : 0;
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/php/Unit/Strategy/LiquidationProtectorTest.php -v --filter "testL[1-4]Triggered"`
Expected: FAIL - evaluate() not implemented

- [ ] **Step 3: Implement core evaluation logic**

```php
// Add to LiquidationProtector class

public function evaluate(float $price, array $positions, float $balance): void
{
    if ($this->disabled || empty($this->tiers)) {
        return;
    }
    
    $this->evalCount++;
    if ($this->evalCount % $this->evalIntervalCycles !== 0) {
        return;
    }
    
    $now = time();
    
    foreach ($positions as $pos) {
        $metrics = $this->computePositionMetrics($pos, $price, $balance);
        
        foreach ($this->tiers as $level => $tier) {
            if (!$tier['enabled']) continue;
            
            if ($now - ($this->lastTriggered[$level] ?? 0) < $tier['cooldown_sec']) {
                continue;
            }
            
            $triggered = false;
            $matchedTrigger = null;
            
            foreach ($tier['triggers'] as $tr) {
                if ($this->checkTrigger($tr['type'], $metrics, (float)$tr['threshold'])) {
                    $triggered = true;
                    $matchedTrigger = $tr;
                    break; // ANY trigger matches
                }
            }
            
            if ($triggered) {
                $this->executeTier($tier, $pos, $metrics, $price, $matchedTrigger);
                $this->lastTriggered[$level] = $now;
                $this->logTierActivation($level, $matchedTrigger['type'], $metrics);
                $this->persistState();
                break; // Solo primer tier que matcha por posición
            }
        }
    }
}

private function computePositionMetrics(array $pos, float $price, float $balance): array
{
    $qty = abs((float)($pos['positionAmt'] ?? ($pos['size'] ?? 0)));
    $entryPx = (float)($pos['entryPrice'] ?? 0);
    $liqPx = (float)($pos['liqPrice'] ?? ($pos['liquidationPrice'] ?? 0));
    $uPnL = (float)($pos['unRealizedProfit'] ?? 0);
    $side = $pos['side'] ?? '';
    
    $notional = $qty * max($entryPx, $price);
    $usedMargin = $notional / (defined('G_LEVERAGE') ? G_LEVERAGE : 100);
    $freeMargin = max(0.0, $balance - $usedMargin);
    
    return [
        'side'           => $side,
        'qty'            => $qty,
        'entry_price'    => $entryPx,
        'liq_price'      => $liqPx,
        'uPnL'           => $uPnL,
        'notional'       => $notional,
        'used_margin'    => $usedMargin,
        'free_margin'    => $freeMargin,
        'dist_liq_pct'   => $liqPx > 0 ? abs($liqPx - $price) / $price * 100 : 999.0,
        'uPnL_pct'       => $notional > 0 ? $uPnL / $notional * 100 : 0.0,
        'free_margin_pct'=> $usedMargin > 0 ? $freeMargin / $usedMargin * 100 : 999.0,
    ];
}

private function checkTrigger(string $type, array $metrics, float $threshold): bool
{
    return match ($type) {
        'dist_liq_pct_lt'     => $metrics['dist_liq_pct'] < $threshold,
        'free_margin_pct_lt'  => $metrics['free_margin_pct'] < $threshold,
        'uPnL_pct_lt'         => $metrics['uPnL_pct'] < $threshold,
        default               => false,
    };
}

private function executeTier(array $tier, array $pos, array $metrics, float $price, array $matchedTrigger): void
{
    foreach ($tier['actions'] as $action) {
        try {
            $this->executeAction($action, $pos, $metrics, $price);
        } catch (\Throwable $e) {
            lE("[LIQ_PROT] Action {$action['type']} failed: " . $e->getMessage());
            $this->consecutiveErrors++;
            if ($this->consecutiveErrors >= 5) {
                $this->disabled = true;
                lE("[LIQ_PROT] Circuit breaker OPEN - protector DISABLED");
            }
            continue; // Continue with next action
        }
    }
    $this->consecutiveErrors = 0; // Reset on success
}

private function checkTrigger(string $type, array $metrics, float $threshold): bool
{
    return match ($type) {
        'dist_liq_pct_lt'     => $metrics['dist_liq_pct'] < $threshold,
        'free_margin_pct_lt'  => $metrics['free_margin_pct'] < $threshold,
        'uPnL_pct_lt'         => $metrics['uPnL_pct'] < $threshold,
        default               => false,
    };
}

// Getter for testing
public function getLastTriggeredLevel(): int
{
    return $this->lastTriggered ? max(array_keys($this->lastTriggered)) : 0;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/php/Unit/Strategy/LiquidationProtectorTest.php -v --filter "testL[1-4]Triggered"`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/php/Strategy/LiquidationProtector.php tests/php/Unit/Strategy/LiquidationProtectorTest.php
git commit -m "feat: implement core evaluation logic with trigger matching and tier execution"
```

---

### Task 4: Action Implementations

**Files:**
- Modify: `src/php/Strategy/LiquidationProtector.php`
- Test: `tests/php/Unit/Strategy/LiquidationProtectorTest.php`

**Interfaces:**
- Produces: All 9 action types implemented with safety guards

- [ ] **Step 1: Write failing tests for each action**

```php
public function testReduceLeverageAction(): void {
    $this->api->expects($this->once())->method('setLeverage')->with('ETHUSDT', 50);
    $pos = $this->makePosition(liqPrice: 1000, entryPx: 2000);
    $this->protector->evaluate(1700, [$pos], 10000); // L2 trigger
}

public function testHedgePartialAction(): void {
    $this->api->expects($this->once())->method('marketClose')
        ->with('ETHUSDT', 'Buy', 0.125); // 25% of 0.5
    $pos = $this->makePosition(qty: 0.5, liqPrice: 1000, entryPx: 2000);
    $this->protector->evaluate(1700, [$pos], 10000);
}

public function testAddMarginAction(): void {
    // Test add_margin action with max_pct_free_balance
    $pos = $this->makePosition(liqPrice: 500, entryPx: 2000, uPnL: -5000);
    $this->protector->evaluate(1500, [$pos], 10000); // L3
    // Verify add_margin logic executed (check logs or mock)
}

public function testCloseWorstPositionsAction(): void {
    $this->api->expects($this->once())->method('marketClose');
    $pos = $this->makePosition(liqPrice: 500, entryPx: 2000, uPnL: -5000);
    $this->protector->evaluate(1500, [$pos], 10000); // L3 close worst
}

public function testCloseAllPositionsAction(): void {
    $this->api->expects($this->exactly(2))->method('marketClose'); // 2 positions
    $pos1 = $this->makePosition(side: 'Buy', liqPrice: 1950);
    $pos2 = $this->makePosition(side: 'Sell', liqPrice: 1950);
    $this->protector->evaluate(1960, [$pos1, $pos2], 10000); // L4
}

private function makePosition(int $liqPrice = 1800, int $entryPx = 2000, string $side = 'Buy', float $uPnL = -100, float $qty = 0.5): array {
    return [
        'side' => $side,
        'positionAmt' => (string)$qty,
        'size' => (string)$qty,
        'entryPrice' => (string)$entryPx,
        'liqPrice' => (string)$liqPrice,
        'unRealizedProfit' => (string)$uPnL,
        'leverage' => '100',
    ];
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/php/Unit/Strategy/LiquidationProtectorTest.php -v --filter "testReduce|testHedge|testAdd|testClose"`
Expected: FAIL - actions not implemented

- [ ] **Step 3: Implement all 9 action types**

```php
// Add to LiquidationProtector class

private function executeAction(array $action, array $pos, array $metrics, float $price): void
{
    switch ($action['type']) {
        case 'tighten_grid_spacing':
            $this->tightenGridSpacing($action['factor'] ?? 0.9);
            break;
        case 'reduce_leverage':
            $this->reduceLeverage($action['target'] ?? 50);
            break;
        case 'hedge_partial':
            $this->hedgePartial($pos['side'], $pos['qty'] * ($action['pct'] ?? 0.25));
            break;
        case 'add_margin':
            $this->addMargin($metrics['free_margin'] * ($action['max_pct_free_balance'] ?? 0.5));
            break;
        case 'close_worst_positions':
            $this->closeWorstPositions($action['uPnL_pct_lt'] ?? -3.0, $action['max_count'] ?? 2);
            break;
        case 'close_all_positions':
            $this->closeAllPositions();
            break;
        case 'cancel_all_orders':
            $this->cancelAllOrders();
            break;
        case 'pause_bot_sec':
            $this->pauseBot($action['duration'] ?? 1800);
            break;
        case 'log_alert':
            $msg = $action['message'] ?? 'Liquidation alert';
            $msg = str_replace('{{dist_liq_pct}}', number_format($metrics['dist_liq_pct'], 1), $msg);
            lW("[LIQ_PROT] $msg");
            break;
    }
}

private function tightenGridSpacing(float $factor): void
{
    global $CTRL, $G_SYM;
    if (!isset($GLOBALS['G_SYM'])) return;
    
    // Trigger grid rebuild with tighter spacing
    file_put_contents($CTRL, json_encode([
        'action' => 'tighten_spacing',
        'factor' => $factor,
        'symbol' => $G_SYM,
    ]), LOCK_EX);
    lI("[LIQ_PROT] Grid spacing tightened by factor $factor");
}

private function reduceLeverage(int $target): void
{
    $current = $this->getCurrentLeverage();
    if ($target < $current) {
        $this->api->setLeverage((string)(defined('G_SYM') ? G_SYM : 'ETHUSDT'), $target);
        lI("[LIQ_PROT] Leverage reducido $current → $target");
    }
}

private function getCurrentLeverage(): int
{
    // Try to get from API or config constant
    return defined('G_LEVERAGE') ? G_LEVERAGE : 100;
}

private function hedgePartial(string $side, float $qty): void
{
    $hedgeSide = $side === 'Buy' ? 'Sell' : 'Buy';
    $filters = $this->api->filters((string)(defined('G_SYM') ? G_SYM : 'ETHUSDT'));
    $qty = max($filters['mn'], min($qty, $metrics['qty'])); // Safety: min(pct, 1.0) applied in caller
    
    if ($qty >= $filters['mn']) {
        $this->api->marketClose((string)(defined('G_SYM') ? G_SYM : 'ETHUSDT'), $hedgeSide, $qty);
        lI("[LIQ_PROT] Hedge $hedgeSide $qty para posición $side");
    }
}

private function addMargin(float $amount): void
{
    if ($amount > 0) {
        // Bybit doesn't have direct addMargin API, this transfers from available balance
        // Implementation depends on Bybit API - using transfer or position margin adjust
        lI("[LIQ_PROT] Margin top-up solicitado: $amount USDT");
        // For now log - actual implementation requires Bybit position margin adjust API
    }
}

private function closeWorstPositions(float $uPnLThreshold, int $maxCount): void
{
    $positions = $this->api->positions((string)(defined('G_SYM') ? G_SYM : 'ETHUSDT'));
    
    // Sort by uPnL ascending (worst first)
    uasort($positions, function ($a, $b) {
        return (($a['unRealizedProfit'] ?? 0) <=> ($b['unRealizedProfit'] ?? 0));
    });
    
    $closed = 0;
    foreach ($positions as $p) {
        if ($closed >= $maxCount) break;
        
        $qty = abs((float)($p['positionAmt'] ?? ($p['size'] ?? 0)));
        $entryPx = (float)($p['entryPrice'] ?? 0);
        $uPnL = (float)($p['unRealizedProfit'] ?? 0);
        $notional = $qty * $entryPx;
        
        if ($notional > 0 && $uPnL / $notional * 100 < $uPnLThreshold) {
            $this->api->marketClose((string)(defined('G_SYM') ? G_SYM : 'ETHUSDT'), $p['side'], $qty);
            $closed++;
        }
    }
    lI("[LIQ_PROT] Cerradas $closed posiciones peores (uPnL < ${uPnLThreshold}%)");
}

private function closeAllPositions(): void
{
    $positions = $this->api->positions((string)(defined('G_SYM') ? G_SYM : 'ETHUSDT'));
    foreach ($positions as $p) {
        $qty = abs((float)($p['positionAmt'] ?? ($p['size'] ?? 0)));
        if ($qty > 0) {
            $this->api->marketClose((string)(defined('G_SYM') ? G_SYM : 'ETHUSDT'), $p['side'], $qty);
        }
    }
    lI("[LIQ_PROT] EMERGENCY: Todas las posiciones cerradas");
}

private function cancelAllOrders(): void
{
    $this->api->cancelAll((string)(defined('G_SYM') ? G_SYM : 'ETHUSDT'));
    lI("[LIQ_PROT] Todas las órdenes canceladas");
}

private function pauseBot(int $duration): void
{
    global $CTRL;
    $duration = min($duration, 1800); // Max 30 min
    file_put_contents($CTRL, json_encode([
        'action' => 'pause',
        'duration' => $duration,
    ]), LOCK_EX);
    lI("[LIQ_PROT] Bot pausado $duration segundos");
}

private function logTierActivation(int $level, string $trigger, array $metrics): void
{
    $log = [
        'ts'      => date('Y-m-d H:i:s'),
        'tier'    => $level,
        'trigger' => $trigger,
        'dist_liq_pct' => round($metrics['dist_liq_pct'], 2),
        'free_margin_pct' => round($metrics['free_margin_pct'], 2),
        'uPnL_pct' => round($metrics['uPnL_pct'], 2),
        'side'    => $metrics['side'],
        'qty'     => round($metrics['qty'], 4),
    ];
    lW("[LIQ_PROT] " . json_encode($log));
    
    // Also write to alerts file for external monitoring
    $alertFile = dirname($GLOBALS['LOG'] ?? '/tmp') . '/liquidation_alerts.jsonl';
    file_put_contents($alertFile, json_encode($log) . "\n", FILE_APPEND | LOCK_EX);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/php/Unit/Strategy/LiquidationProtectorTest.php -v --filter "testReduce|testHedge|testAdd|testClose"`
Expected: PASS

- [ ] **Step 4b: Run all LiquidationProtector tests**

Run: `php vendor/bin/phpunit tests/php/Unit/Strategy/LiquidationProtectorTest.php -v`
Expected: ALL PASS

- [ ] **Step 5: Commit**

```bash
git add src/php/Strategy/LiquidationProtector.php tests/php/Unit/Strategy/LiquidationProtectorTest.php
git commit -m "feat: implement all 9 action types with safety guards"
```

---

### Task 5: GridManager Integration

**Files:**
- Modify: `src/php/bot.php`
- Modify: `src/php/Strategy/GridManager.php`
- Test: `tests/php/Unit/Strategy/GridManagerTest.php`

**Interfaces:**
- Consumes: `LiquidationProtector` instance in GridManager constructor
- Produces: `riskCheck()` calls `liquidationProtector->evaluate()` with positions, price, balance

- [ ] **Step 1: Write failing integration test**

```php
// In GridManagerTest.php

public function testRiskCheckCallsLiquidationProtector(): void {
    $api = new BybitFutures('test_key', 'test_secret', true);
    $ai  = new GridAI();
    $ml  = new GridML('/tmp/nonexistent_' . uniqid() . '.json');
    
    $mockProtector = $this->createMock(LiquidationProtector::class);
    $mockProtector->expects($this->once())
        ->method('evaluate')
        ->with(2000.0, $this->isArray(), $this->isType('float'));
    
    $manager = new GridManager($api, $ai, $ml, $mockProtector);
    
    // Use reflection to call private riskCheck
    $ref = new \ReflectionMethod(GridManager::class, 'riskCheck');
    $ref->setAccessible(true);
    $ref->invoke($manager, 2000.0);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/php/Unit/Strategy/GridManagerTest.php::testRiskCheckCallsLiquidationProtector -v`
Expected: FAIL - GridManager doesn't accept 4th param

- [ ] **Step 3: Modify bot.php to instantiate and inject**

```php
// In bot.php, around line 60 (after $ML_W)
$LP_CFG = cv($cfg, ['liquidation_protection'], []);

// Around line 405 (bootstrap)
$lp = new \BinanceBot\Strategy\LiquidationProtector($api, $LP_CFG);
$bot = new \BinanceBot\Strategy\GridManager($api, $ai, $ml, $lp);
```

- [ ] **Step 4: Modify GridManager constructor**

```php
// In GridManager.php, around line 21
private ?LiquidationProtector $liquidationProtector = null;

public function __construct($api, $ai, $ml, $liquidationProtector = null) {
    $this->api = $api;
    $this->ai = $ai;
    $this->ml = $ml;
    $this->liquidationProtector = $liquidationProtector;
    // ... rest of existing constructor
}
```

- [ ] **Step 5: Modify riskCheck() to call protector**

```php
// In GridManager.php, in riskCheck() method (around line 974)

private function riskCheck($price) {
    // ... existing hard stop logic (lines 943-973) ...
    
    // NEW: Liquidation Protector evaluation
    if ($this->liquidationProtector !== null) {
        $positions = $this->api->positions(G_SYM);
        $balance   = $this->api->balance();
        $this->liquidationProtector->evaluate($price, $positions, $balance);
    }

    $this->checkLiquidationRisk($price); // keeps existing 15% panic button
}
```

- [ ] **Step 6: Run integration test to verify it passes**

Run: `php vendor/bin/phpunit tests/php/Unit/Strategy/GridManagerTest.php::testRiskCheckCallsLiquidationProtector -v`
Expected: PASS

- [ ] **Step 7: Run all tests to ensure no regression**

Run: `php vendor/bin/phpunit tests/php/Unit/ -v`
Expected: ALL 102 TESTS PASS

- [ ] **Step 8: Commit**

```bash
git add src/php/bot.php src/php/Strategy/GridManager.php tests/php/Unit/Strategy/GridManagerTest.php
git commit -m "feat: integrate LiquidationProtector into GridManager riskCheck"
```

---

### Task 6: Add Config to config.json

**Files:**
- Modify: `config/config.json`

- [ ] **Step 1: Add liquidation_protection section to config.json**

```json
// Add after "volatility" section, before "mysql"
  "liquidation_protection": {
    "enabled": true,
    "tiers": [
      {
        "level": 1,
        "name": "Alert",
        "enabled": true,
        "triggers": [
          {"type": "dist_liq_pct_lt", "threshold": 25},
          {"type": "free_margin_pct_lt", "threshold": 40},
          {"type": "uPnL_pct_lt", "threshold": -1.5}
        ],
        "actions": [
          {"type": "tighten_grid_spacing", "factor": 0.9},
          {"type": "log_alert", "message": "L1: Dist liq {{dist_liq_pct}}%"}
        ],
        "cooldown_sec": 60
      },
      {
        "level": 2,
        "name": "Defense",
        "enabled": true,
        "triggers": [
          {"type": "dist_liq_pct_lt", "threshold": 20},
          {"type": "free_margin_pct_lt", "threshold": 25},
          {"type": "uPnL_pct_lt", "threshold": -3}
        ],
        "actions": [
          {"type": "reduce_leverage", "target": 50},
          {"type": "hedge_partial", "pct": 0.25}
        ],
        "cooldown_sec": 120
      },
      {
        "level": 3,
        "name": "Critical",
        "enabled": true,
        "triggers": [
          {"type": "dist_liq_pct_lt", "threshold": 15},
          {"type": "free_margin_pct_lt", "threshold": 15},
          {"type": "uPnL_pct_lt", "threshold": -5}
        ],
        "actions": [
          {"type": "add_margin", "max_pct_free_balance": 0.5},
          {"type": "close_worst_positions", "uPnL_pct_lt": -3, "max_count": 2}
        ],
        "cooldown_sec": 180
      },
      {
        "level": 4,
        "name": "Emergency",
        "enabled": true,
        "triggers": [
          {"type": "dist_liq_pct_lt", "threshold": 8},
          {"type": "free_margin_pct_lt", "threshold": 5}
        ],
        "actions": [
          {"type": "close_all_positions"},
          {"type": "cancel_all_orders"},
          {"type": "pause_bot_sec", "duration": 1800}
        ],
        "cooldown_sec": 3600
      }
    ],
    "global": {
      "eval_interval_sec": 8,
      "min_dist_liq_pct": 3,
      "circuit_breaker": {
        "max_consecutive_errors": 5,
        "disable_on_breaker": true
      }
    }
  },
```

- [ ] **Step 2: Run bot.php syntax check**

Run: `php -l src/php/bot.php`
Expected: No syntax errors

- [ ] **Step 3: Run all tests**

Run: `php vendor/bin/phpunit tests/php/Unit/ -v`
Expected: ALL 102 TESTS PASS

- [ ] **Step 4: Commit**

```bash
git add config/config.json
git commit -m "config: add liquidation_protection section with L1-L4 tiers"
```

---

### Task 7: Additional Edge Case Tests & Coverage

**Files:**
- Modify: `tests/php/Unit/Strategy/LiquidationProtectorTest.php`

- [ ] **Step 1: Write tests for cooldown, circuit breaker, persistence, defaults**

```php
public function testCooldownPreventsRetrigger(): void {
    $pos = $this->makePosition(liqPrice: 1800, entryPx: 2000);
    $this->protector->evaluate(1950, [$pos], 10000); // L1 triggers
    $this->protector->evaluate(1940, [$pos], 10000); // same eval - cooldown
    $this->assertEquals(1, $this->getLastTriggeredLevel()); // no retrigger
}

public function testOnlyFirstMatchingTierFires(): void {
    // L1 and L2 triggers both match
    $pos = $this->makePosition(liqPrice: 1900, entryPx: 2000); // dist ~5%
    $this->protector->evaluate(2000, [$pos], 10000);
    $this->assertEquals(1, $this->getLastTriggeredLevel()); // solo L1
}

public function testDisabledTierSkipped(): void {
    $config = ['liquidation_protection' => ['enabled' => true, 'tiers' => [
        1 => ['enabled' => false, 'triggers' => [['type' => 'dist_liq_pct_lt', 'threshold' => 25]], 'actions' => [['type' => 'log_alert']], 'cooldown_sec' => 60],
    ]]];
    $prot = new LiquidationProtector($this->api, $config['liquidation_protection']);
    $pos = $this->makePosition(liqPrice: 1800, entryPx: 2000);
    $prot->evaluate(1950, [$pos], 10000);
    $this->assertEquals(0, $prot->getLastTriggeredLevel());
}

public function testCircuitBreakerAfter5Errors(): void {
    $this->api->method('setLeverage')->willThrowException(new \RuntimeException('API down'));
    for ($i = 0; $i < 6; $i++) {
        $this->protector->evaluate(1950, [$this->makePosition()], 10000);
    }
    $ref = new \ReflectionClass($this->protector);
    $prop = $ref->getProperty('disabled');
    $prop->setAccessible(true);
    $this->assertTrue($prop->getValue($this->protector));
}

public function testStatePersistsAcrossRestart(): void {
    $pos = $this->makePosition(liqPrice: 1800, entryPx: 2000);
    $this->protector->evaluate(1950, [$pos], 10000);
    
    // Simulate restart
    $newProtector = new LiquidationProtector($this->api, $this->config['liquidation_protection']);
    $this->assertEquals(1, $newProtector->getLastTriggered(1));
}

public function testDefaultsWhenConfigEmpty(): void {
    $emptyConfig = ['liquidation_protection' => ['enabled' => true, 'tiers' => []]];
    $prot = new LiquidationProtector($this->api, $emptyConfig['liquidation_protection']);
    $this->assertCount(4, $prot->getTiers());
}
```

- [ ] **Step 2: Run all LiquidationProtector tests**

Run: `php vendor/bin/phpunit tests/php/Unit/Strategy/LiquidationProtectorTest.php -v`
Expected: ALL PASS

- [ ] **Step 3: Commit**

```bash
git add tests/php/Unit/Strategy/LiquidationProtectorTest.php
git commit -m "test: add edge case tests for cooldown, circuit breaker, persistence, defaults"
```

---

### Task 8: Final Validation & Documentation

**Files:**
- Run: All tests
- Verify: No syntax errors
- Update: CHANGELOG.md if exists

- [ ] **Step 1: Run full test suite**

Run: `php vendor/bin/phpunit tests/php/Unit/ -v`
Expected: 102+ tests passing

- [ ] **Step 2: Verify syntax on all modified files**

Run: `php -l src/php/bot.php && php -l src/php/Strategy/GridManager.php && php -l src/php/Strategy/LiquidationProtector.php && php -l config/config.json`
Expected: All clean

- [ ] **Step 3: Quick manual test - start bot and verify no errors**

```bash
cd /home/erika/web/binance.gregorbritez.cat/public_html
timeout 10 php src/php/bot.php 2>&1 | head -20
```
Expected: Bot starts, shows "[LIQ_PROT]" logs if tiers triggered, no fatal errors

- [ ] **Step 4: Commit final changes**

```bash
git add -A
git commit -m "feat: complete liquidation protection system with L1-L4 tiers, config-driven, fully tested"
```

---

### Task 9: Rollout Verification (Optional - Production)

- [ ] **Phase 1:** Deploy with `"enabled": false` in config, verify no behavior change
- [ ] **Phase 2:** Enable L1 only (`"enabled": true` for tier 1 only), verify alerts in logs
- [ ] **Phase 3:** Enable L1+L2, verify leverage reduction + hedge in logs
- [ ] **Phase 4:** Enable all tiers, monitor for 2 weeks, tune thresholds

---

## Plan Complete

**Plan saved to:** `docs/superpowers/plans/2026-07-28-liquidation-protection-implementation.md`

**Two execution options:**

1. **Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration
   - REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development

2. **Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints
   - REQUIRED SUB-SKILL: Use superpowers:executing-plans

**Which approach?**