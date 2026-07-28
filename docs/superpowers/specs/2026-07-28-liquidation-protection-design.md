# Liquidation Protection System - Design Specification

**Date:** 2026-07-28  
**Version:** 1.0  
**Status:** Approved for Implementation  
**Author:** Grid Bot MT5 Team

---

## 1. Executive Summary

### Problem
Current Grid Bot has two reactive liquidation protections:
- **Hard Stop** (3% uPnL loss vs notional) - closes position
- **Liquidation Risk** (15% distance to liq price) - closes position

Both are binary, all-or-nothing, and trigger late. No progressive defense, no leverage reduction, no hedging, no margin top-up.

### Solution
Implement a **multi-layer escalating protection system** (`LiquidationProtector`) with 4 tiers of configurable triggers and actions, running every cycle (8s) alongside existing risk checks.

### Success Criteria
- Zero liquidations in backtest on 2023-2024 ETHUSDT 5m data
- Max 2% portfolio drawdown during 50% crash scenarios
- All 102 existing unit tests pass + new test coverage ≥90%
- Config-driven: zero code changes to adjust thresholds/actions

---

## 2. Architecture

### 2.1 Component Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                    GridManager (existente)                  │
│  ┌─────────────────────────────────────────────────────────┐│
│  │  riskCheck(price)  ──►  LiquidationProtector (NUEVO)   ││
│  │       │                         │                       ││
│  │       ▼                         ▼                       ││
│  │  checkLiquidationRisk()    evaluateTiers()              ││
│  │       │                         │                       ││
│  │       └────────────┬────────────┘                       ││
│  │                    ▼                                    ││
│  │            executeActions()                             ││
│  │                    │                                    ││
│  │       ┌────────────┼────────────┐                       ││
│  │       ▼            ▼            ▼                       ││
│  │  closePosition  reduceLeverage  addMargin  hedgeOrder  ││
│  └─────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────┘
                              │
                    ┌─────────┴─────────┐
                    ▼                   ▼
            BybitFutures API      GridManager State
                    │                   │
                    ▼                   ▼
            execute action      update config/DB
```

### 2.2 New Class: `LiquidationProtector`

```php
namespace BinanceBot\Strategy;

class LiquidationProtector {
    private BybitFutures $api;
    private array $tiers;           // 4 tiers L1-L4
    private array $lastTriggered;   // tier → timestamp (cooldown)
    private int $evalIntervalCycles; // eval cada N ciclos
    private array $config;
    
    public function __construct(BybitFutures $api, array $config = []);
    public function evaluate(float $price, array $positions, float $balance): void;
    // ... private methods
}
```

---

## 3. Configuration Schema (config.json)

```json
{
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
  }
}
```

### Trigger Types
| Type | Metric | Fires When |
|------|--------|------------|
| `dist_liq_pct_lt` | Distance to liq price (%) | `dist_liq_pct < threshold` |
| `free_margin_pct_lt` | Free margin / used margin (%) | `free_margin_pct < threshold` |
| `uPnL_pct_lt` | Unrealized PnL / notional (%) | `uPnL_pct < threshold` (negative) |

### Action Types
| Type | Params | Description |
|------|--------|-------------|
| `tighten_grid_spacing` | `factor` (0-1) | `spacing *= factor` |
| `reduce_leverage` | `target` (int) | `setLeverage(min(target, current))` |
| `hedge_partial` | `pct` (0-1) | Market order opposite side = `qty * pct` |
| `add_margin` | `max_pct_free_balance` | Transfer margin from free balance |
| `close_worst_positions` | `uPnL_pct_lt`, `max_count` | Close worst by uPnL |
| `close_all_positions` | - | Market close all |
| `cancel_all_orders` | - | Cancel open orders |
| `pause_bot_sec` | `duration` | Write pause to control file |
| `log_alert` | `message` | Structured JSON log |

---

## 4. Core Logic & Data Flow

### 4.1 Evaluation Loop (every 8s)

```
evaluate(price, positions, balance):
  if tiers empty → return
  if disabled by circuit breaker → return
  evalCount++
  if evalCount % evalIntervalCycles ≠ 0 → return

  for position in positions:
    metrics = computeMetrics(position, price, balance)
    
    for tier in tiers (L1→L4):
      if tier.disabled → continue
      if onCooldown(tier) → continue
      if anyTriggerMatches(tier.triggers, metrics):
        executeTier(tier, position, metrics, price)
        lastTriggered[tier.level] = now()
        break  // solo primer tier que matcha por posición
```

### 4.2 Metrics Computation

```php
computeMetrics(pos, price, balance) → array:
  qty = abs(pos['positionAmt'] ?? pos['size'] ?? 0)
  entryPx = (float)(pos['entryPrice'] ?? 0)
  liqPx = (float)(pos['liqPrice'] ?? 0)
  uPnL = (float)(pos['unRealizedProfit'] ?? 0)
  notional = qty * entryPx
  usedMargin = notional / G_LEVERAGE
  freeMargin = max(0, balance - usedMargin)
  
  return [
    'side'           => pos['side'],           // 'Buy'|'Sell'
    'qty'            => qty,
    'entry_price'    => entryPx,
    'liq_price'      => liqPx,
    'uPnL'           => uPnL,
    'notional'       => notional,
    'used_margin'    => usedMargin,
    'free_margin'    => freeMargin,
    'dist_liq_pct'   => liqPx>0 ? abs(liqPx-price)/price*100 : 999,
    'uPnL_pct'       => notional>0 ? uPnL/notional*100 : 0,
    'free_margin_pct'=> usedMargin>0 ? freeMargin/usedMargin*100 : 999,
  ]
```

### 4.3 Trigger Matching (ANY = OR logic)

```php
checkTrigger(type, metrics, threshold) → bool:
  match type:
    'dist_liq_pct_lt'      → metrics['dist_liq_pct'] < threshold
    'free_margin_pct_lt'   → metrics['free_margin_pct'] < threshold
    'uPnL_pct_lt'          → metrics['uPnL_pct'] < threshold
```

### 4.4 Key Action Implementations

```php
reduceLeverage(target):
  current = getCurrentLeverage()
  if target < current:
    api.setLeverage(G_SYM, target)
    log("[LIQ_PROT] Leverage $current → $target")

hedgePartial(pct):
  hedgeSide = (side === 'Buy') ? 'Sell' : 'Buy'
  hedgeQty = qty * pct
  api.marketClose(G_SYM, hedgeSide, hedgeQty)
  log("[LIQ_PROT] Hedge $hedgeSide $hedgeQty for $side")

addMargin(maxPctFree):
  available = free_margin * maxPctFree
  if available > 0:
    // Bybit: transfer from available balance to position margin
    api.addMargin(G_SYM, available)
    log("[LIQ_PROT] Added $available margin")

closeWorstPositions(uPnLThreshold, maxCount):
  positions = api.positions(G_SYM)
  sort by uPnL asc
  closed = 0
  foreach pos:
    if closed >= maxCount: break
    if pos.uPnL_pct < uPnLThreshold:
      api.marketClose(G_SYM, pos.side, pos.qty)
      closed++
  log("[LIQ_PROT] Closed $closed worst positions")

closeAllPositions():
  foreach api.positions(G_SYM):
    api.marketClose(G_SYM, pos.side, pos.qty)

pauseBot(duration):
  file_put_contents(CTRL, json_encode(['action'=>'pause','duration'=>$duration]))
```

---

## 5. Integration with GridManager

### 5.1 bot.php - Bootstrap

```php
// línea ~60
$LP_CFG = cv($cfg, ['liquidation_protection'], []);

// línea ~405
$lp = new \BinanceBot\Strategy\LiquidationProtector($api, $LP_CFG);
$bot = new \BinanceBot\Strategy\GridManager($api, $ai, $ml, $lp);
```

### 5.2 GridManager.php - Constructor

```php
private ?LiquidationProtector $liquidationProtector = null;

public function __construct($api, $ai, $ml, $liquidationProtector = null) {
    $this->api = $api;
    $this->ai = $ai;
    $this->ml = $ml;
    $this->liquidationProtector = $liquidationProtector;
    // ...
}
```

### 5.3 GridManager.php - riskCheck()

```php
private function riskCheck($price) {
    // ... existing hard stop logic ...
    
    // NEW: Liquidation Protector evaluation
    if ($this->liquidationProtector !== null) {
        $positions = $this->api->positions(G_SYM);
        $balance   = $this->api->balance();
        $this->liquidationProtector->evaluate($price, $positions, $balance);
    }

    $this->checkLiquidationRisk($price); // keeps existing 15% panic button
}
```

---

## 6. Error Handling & Safety

### 6.1 Config Validation (fail-fast)
- Missing `triggers` or `actions` → `InvalidArgumentException` at boot
- Unknown trigger/action type → exception with list of valid types
- Cooldown < 0 → exception
- Tier levels must be 1-4 sequential

### 6.2 Runtime Error Handling
```php
executeTier(tier, pos, metrics, price):
  foreach tier['actions'] as action:
    try:
      executeAction(action, pos, metrics, price)
    catch Throwable e:
      log("[LIQ_PROT] Action {$action['type']} failed: " . e.getMessage())
      continue  // NO bloquear tier, seguir con siguiente action
```

### 6.3 Circuit Breaker
```php
private int $consecutiveErrors = 0;
const MAX_CONSECUTIVE_ERRORS = 5;

catch Throwable:
  $this->consecutiveErrors++
  if $this->consecutiveErrors >= MAX_CONSECUTIVE_ERRORS:
    $this->disabled = true
    lE("[LIQ_PROT] Circuit breaker OPEN - protector DISABLED")
```

### 6.4 Cooldown Enforcement
```php
isOnCooldown(level):
  last = lastTriggered[level] ?? 0
  cooldown = tiers[level]['cooldown_sec'] ?? 60
  return (time() - last) < cooldown
```

### 6.5 Persistence (survives restart)
```php
// Write to file on trigger
$stateFile = dirname($GLOBALS['LOG']) . '/liq_prot_state.json';
file_put_contents($stateFile, json_encode([
  'last_triggered' => $this->lastTriggered,
  'consecutive_errors' => $this->consecutiveErrors,
  'disabled' => $this->disabled,
]));

// Read on construct
if file_exists($stateFile):
  $state = json_decode(file_get_contents($stateFile), true)
  restore state
```

### 6.6 Safety Invariants
1. **Never increases leverage** - only reduces or keeps same
2. **Never adds margin > free balance** - capped by `max_pct_free_balance`
3. **Hedge qty ≤ position qty** - `min(pct, 1.0)`
4. **Tier evaluation order fixed** - L1→L4, first match wins per position
5. **Cooldown per-tier** - prevents oscillation
6. **Circuit breaker disables protector** - fails safe (existing `checkLiquidationRisk` remains)

---

## 7. Testing Strategy

### 7.1 Unit Tests: `LiquidationProtectorTest.php`
| Test | Coverage |
|------|----------|
| `testL1TriggeredByDistLiqPct` | Trigger matching |
| `testL2TriggeredByFreeMarginPct` | Multiple trigger types |
| `testCooldownPreventsReentry` | Cooldown logic |
| `testL3AddMarginAction` | Margin top-up |
| `testL4EmergencyClosesAll` | Panic close |
| `testOnlyFirstMatchingTierFires` | Priority order |
| `testDisabledTierSkipped` | Config disabled |
| `testCircuitBreakerAfter5Errors` | Fail-safe |
| `testStatePersistsRestart` | File persistence |
| `testDefaultsWhenConfigEmpty` | Fallback tiers |

### 7.2 Integration Tests: `GridManagerIntegrationTest.php`
| Test | Coverage |
|------|----------|
| `testRiskCheckCallsProtector` | Wiring |
| `testL1TightensGridSpacing` | Action effect |
| `testL2ReducesLeverage` | Action effect |
| `testL3HedgePartial` | Action effect |
| `testL4PausesBot` | Action effect |
| `testHardStopStillWorks` | Backwards compat |

### 7.3 Property-Based Tests (optional)
- 1000 random positions → no infinite loops, ≤5 actions/eval

### 7.4 Coverage Targets
| Component | Target |
|-----------|--------|
| `evaluate()` | 100% |
| Trigger matching | 100% |
| Action execution | 90%+ (API mocked) |
| Cooldown | 100% |
| Circuit breaker | 100% |
| Config validation | 100% |

---

## 8. Observability

### 8.1 Structured Logs (JSON Lines)
```json
{"ts":"2026-07-28T10:30:45","tier":2,"trigger":"free_margin_pct_lt","dist_liq_pct":18.5,"free_margin_pct":22.1,"uPnL_pct":-2.3,"side":"Buy","qty":0.45,"actions":["reduce_leverage:50","hedge_partial:0.25"]}
```

### 8.2 Alert File (for external monitoring)
```
data/logs/liquidation_alerts.jsonl  (one JSON per tier activation)
```

### 8.3 Dashboard Metrics (via existing status JSON)
- `liquidation_protector.tier` (0-4)
- `liquidation_protector.last_trigger_ts`
- `liquidation_protector.disabled` (bool)

---

## 9. Rollout Plan

| Phase | Action | Validation |
|-------|--------|------------|
| 1 | Deploy with `enabled: false` | No behavior change, logs show "disabled" |
| 2 | Enable L1 only (alerts) | Verify triggers, no actions |
| 3 | Enable L1+L2 | Verify leverage reduction, hedge |
| 4 | Enable all tiers | Full protection active |
| 5 | Tune thresholds | Based on 2-week production data |

---

## 10. Files to Create/Modify

| File | Type | Description |
|------|------|-------------|
| `src/php/Strategy/LiquidationProtector.php` | New | Core class |
| `config/config.json` | Modify | Add `liquidation_protection` section |
| `src/php/bot.php` | Modify | Instantiate + inject protector |
| `src/php/Strategy/GridManager.php` | Modify | Constructor + riskCheck call |
| `tests/php/Unit/Strategy/LiquidationProtectorTest.php` | New | Unit tests |
| `tests/php/Unit/Strategy/LiquidationProtectorFixtures.php` | New | Test fixtures |
| `docs/superpowers/specs/2026-07-28-liquidation-protection-design.md` | New | This document |

---

## 11. Backwards Compatibility

- ✅ Existing `checkLiquidationRisk()` unchanged (15% panic button)
- ✅ Optional 4th param in GridManager constructor
- ✅ All existing tests pass without modification
- ✅ Config is additive - old configs work (defaults applied)

---

## 12. Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| False positive triggers → unwanted actions | Medium | Medium | Conservative defaults, L1=alert only, cooldowns |
| API rate limit from frequent hedges | Low | High | Cooldowns, max 1 action/tier/eval, circuit breaker |
| Config typo disables protection | Medium | High | Fail-fast validation at boot, clear error msgs |
| State file corruption on restart | Low | Medium | JSON validation, fallback to empty state |
| Leverage reduction breaks open orders | Medium | Medium | `cancelAllOrders()` before `setLeverage()` in L2/L3 |

---

**Approval:** ✅ All sections approved. Ready for implementation plan.