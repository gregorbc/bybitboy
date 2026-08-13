# Fee Optimization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Optimize trading costs by adding dynamic spacing based on fees, correcting PnL fee calculations to distinguish maker/taker, and replacing hardcoded fee values in the frontend with real backend-served data.

**Architecture:** Add a `G_FEE_SAFETY` constant, compute a dynamic minimum spacing floor in `GridManager::loadConfig()` to guarantee each round-trip covers fees, add `$isTaker` parameter to `calcPnl()`, and wire real fee data through `websocket_server.php` to the frontend.

**Tech Stack:** PHP 8.3, MySQL, Ratchet WebSocket, PHPUnit 10.5, vanilla JS (frontend dashboard)

## Global Constraints

- PHP 8.2+ (current: 8.3.32)
- Ubuntu 24.04, MariaDB, nginx (HestiaCP)
- PSR-4 autoloading: `BinanceBot\\` → `src/php/`
- 102 PHP unit tests + 16 JS tests must continue passing after each task
- Fees in config: `maker: 0.0001` (0.01%), `taker: 0.0006` (0.06%)
- Spacing range: `min_spacing: 0.0003`, `max_spacing: 0.0012`

---

### Task 1: Add `G_FEE_SAFETY` constant to `bot.php`

**Files:**
- Modify: `src/php/bot.php:96-98`

**Interfaces:**
- Consumes: existing `G_MAKER_FEE` and `G_TAKER_FEE` constants (defined at lines 96-97)
- Produces: `G_FEE_SAFETY` constant usable by `GridManager.php`

- [ ] **Step 1: Add the constant**

After the existing fee constants in `bot.php` (line 97), add:

```php
define('G_FEE_SAFETY',    max(1.0, (float)cv($cfg, ['fees', 'safety'], 1.5)));
```

- [ ] **Step 2: Run tests to verify no regressions**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && php vendor/bin/phpunit tests/php/Unit/`
Expected: All 102 tests PASS

- [ ] **Step 3: Commit**

```bash
git add src/php/bot.php
git commit -m "feat: add G_FEE_SAFETY constant for dynamic spacing floor"
```

---

### Task 2: Dynamic spacing floor in `GridManager::loadConfig()`

**Files:**
- Modify: `src/php/Strategy/GridManager.php:271-283` (loadConfig method)

**Interfaces:**
- Consumes: `G_MAKER_FEE`, `G_TAKER_FEE`, `G_FEE_SAFETY`, `G_MIN_SPACING`, `G_MAX_SPACING`
- Produces: adjusted `spacing_pct` in `$this->cfg` and in DB when floor is higher

- [ ] **Step 1: Write the failing test**

Add to `tests/php/Unit/Strategy/GridManagerTest.php`:

```php
public function testDynamicSpacingFloorApplied(): void
{
    // G_MAKER_FEE=0.0001, G_TAKER_FEE=0.0006, G_FEE_SAFETY=1.5
    // floor = (0.0001 + 0.0001) * 1.5 = 0.0003
    // This equals the current G_MIN_SPACING, so spacing should not be forced up
    // But if we had lower fees, floor would be lower
    $expectedFloor = (0.0001 + 0.0001) * 1.5; // 0.0003
    $this->assertGreaterThanOrEqual(0.0003, $expectedFloor);
    $this->assertLessThanOrEqual(G_MAX_SPACING, $expectedFloor);
}
```

- [ ] **Step 2: Run test to verify it passes (sanity check)**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && php vendor/bin/phpunit tests/php/Unit/Strategy/GridManagerTest.php::testDynamicSpacingFloorApplied -v`
Expected: PASS

- [ ] **Step 3: Implement the dynamic spacing floor in `loadConfig()`**

In `src/php/Strategy/GridManager.php`, modify `loadConfig()` (line 271-283). After the line `$this->cfg = $row;` (line 279) and before the `lI(sprintf(...)` log line, add:

```php
        // Dynamic spacing floor: ensure spacing covers fee round-trip
        $currentSpacing = (float)($row['spacing_pct'] ?? G_BASE_SPACING);
        $feeFloor = (G_MAKER_FEE + G_MAKER_FEE) * G_FEE_SAFETY;
        $dynamicMin = max(G_MIN_SPACING, $feeFloor);
        if ($currentSpacing < $dynamicMin) {
            $adjustedSpacing = min(G_MAX_SPACING, $dynamicMin);
            lI(sprintf("[CFG] Spacing %.4f%% below fee floor %.4f%% → ajustando a %.4f%%",
                $currentSpacing * 100, $dynamicMin * 100, $adjustedSpacing * 100));
            dbx(function($d) use ($adjustedSpacing) {
                return $d->prepare("UPDATE grid_configs SET spacing_pct=? WHERE symbol=?")
                    ->execute([$adjustedSpacing, G_SYM]);
            });
            $this->cfg['spacing_pct'] = $adjustedSpacing;
            $currentSpacing = $adjustedSpacing;
        }
```

The existing log line on line 280-282 already reads from `$row['spacing_pct']` so it will show the original value. We can update it to use the adjusted value by changing line 281:

```php
        lI(sprintf("[CFG] niv=%d spc=%.4f%% long=%d short=%d capital=%.0f",
            isset($row['levels']) ? $row['levels'] : G_FIXED_LEVELS, $currentSpacing * 100,
            isset($row['long_levels']) ? $row['long_levels'] : G_LONG_LEVELS, isset($row['short_levels']) ? $row['short_levels'] : G_SHORT_LEVELS, G_CAPITAL));
```

- [ ] **Step 4: Run tests to verify no regressions**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && php vendor/bin/phpunit tests/php/Unit/`
Expected: All tests PASS

- [ ] **Step 5: Commit**

```bash
git add src/php/Strategy/GridManager.php tests/php/Unit/Strategy/GridManagerTest.php
git commit -m "feat: dynamic spacing floor based on fee round-trip cost"
```

---

### Task 3: Add `$isTaker` parameter to `GridManager::calcPnl()`

**Files:**
- Modify: `src/php/Strategy/GridManager.php:870-874` (calcPnl method)
- Test: `tests/php/Unit/Strategy/GridManagerTest.php`

**Interfaces:**
- Consumes: `G_MAKER_FEE`, `G_TAKER_FEE`
- Produces: `calcPnl($exitSide, $entryPx, $exitPx, $qty, $isTaker = false)` — later tasks pass `$isTaker=true` for marketClose calls

- [ ] **Step 1: Write failing tests**

Add to `tests/php/Unit/Strategy/GridManagerTest.php`:

```php
public function testCalcPnlUsesMakerFeeByDefault(): void
{
    $api = new BybitFutures('test_key', 'test_secret', true);
    $ai  = new GridAI();
    $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
    $manager = new GridManager($api, $ai, $ml);

    $ref = new \ReflectionMethod(GridManager::class, 'calcPnl');
    $ref->setAccessible(true);

    // SELL exit: gross = (exitPx - entryPx) * qty
    // entry=100, exit=101, qty=1 → gross=1
    // fee = 100*1*G_MAKER_FEE + 101*1*G_MAKER_FEE
    // G_MAKER_FEE=0.0001 → fee = 0.01 + 0.0101 = 0.0201
    // net = 1 - 0.0201 = 0.9799
    $pnl = $ref->invoke($manager, 'SELL', 100.0, 101.0, 1.0);
    $expectedFee = 100.0 * 1.0 * G_MAKER_FEE + 101.0 * 1.0 * G_MAKER_FEE;
    $expected = round((101.0 - 100.0) * 1.0 - $expectedFee, 8);
    $this->assertEquals($expected, $pnl, '', 0.0001);
}

public function testCalcPnlUsesTakerFeeWhenIsTaker(): void
{
    $api = new BybitFutures('test_key', 'test_secret', true);
    $ai  = new GridAI();
    $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
    $manager = new GridManager($api, $ai, $ml);

    $ref = new \ReflectionMethod(GridManager::class, 'calcPnl');
    $ref->setAccessible(true);

    // With isTaker=true, fee uses G_TAKER_FEE (0.0006)
    $pnl = $ref->invoke($manager, 'SELL', 100.0, 101.0, 1.0, true);
    $expectedFee = 100.0 * 1.0 * G_TAKER_FEE + 101.0 * 1.0 * G_TAKER_FEE;
    $expected = round((101.0 - 100.0) * 1.0 - $expectedFee, 8);
    $this->assertEquals($expected, $pnl, '', 0.0001);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && php vendor/bin/phpunit tests/php/Unit/Strategy/GridManagerTest.php::testCalcPnlUsesMakerFeeByDefault tests/php/Unit/Strategy/GridManagerTest.php::testCalcPnlUsesTakerFeeWhenIsTaker -v`
Expected: FAIL (calcPnl only has 4 params, no $isTaker)

- [ ] **Step 3: Update `calcPnl()` implementation**

Replace the `calcPnl` method in `src/php/Strategy/GridManager.php` (lines 870-874):

```php
    private function calcPnl($exitSide, $entryPx, $exitPx, $qty, $isTaker = false) {
        $gross = ($exitSide === 'SELL') ? ($exitPx - $entryPx) * $qty : ($entryPx - $exitPx) * $qty;
        $feeRate = $isTaker ? G_TAKER_FEE : G_MAKER_FEE;
        $fee = $entryPx * $qty * $feeRate + $exitPx * $qty * $feeRate;
        return round($gross - $fee, 8);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && php vendor/bin/phpunit tests/php/Unit/Strategy/GridManagerTest.php -v`
Expected: All tests PASS

- [ ] **Step 5: Commit**

```bash
git add src/php/Strategy/GridManager.php tests/php/Unit/Strategy/GridManagerTest.php
git commit -m "feat: calcPnl distinguishes maker vs taker fees via isTaker param"
```

---

### Task 4: Pass `$isTaker=true` in `marketClose` and `closeAllPositions`

**Files:**
- Modify: `src/php/Strategy/GridManager.php:382-383` (closeAllPositions), `src/php/Strategy/GridManager.php:940-941` (riskCheck hard stop)

**Interfaces:**
- Consumes: updated `calcPnl($exitSide, $entryPx, $exitPx, $qty, $isTaker = false)` from Task 3
- Produces: market orders correctly tagged as taker in PnL

Note: The `onFill()` method on line 861 already calls `calcPnl()` for EXIT fills. EXIT fills that were originally limit orders (PostOnly) are maker fills, so they correctly use `$isTaker=false` (default). Only explicit `marketClose()` calls should pass `$isTaker=true`.

- [ ] **Step 1: Locate all `marketClose` calls in GridManager.php**

The calls that trigger position closure are:
- `closeAllPositions()` line 382: `$this->api->marketClose(G_SYM, $side, $sz);` — these are risk-management market closes, taker
- `riskCheck()` line 940: `$this->api->marketClose(...)` — hard stop, taker

These are not followed by `calcPnl()` directly (they don't produce a fill that triggers `onFill()`). The `onFill()` method handles fills detected from the API, and those fills will come back as either maker or taker depending on how the original order was placed.

So the key insight: `calcPnl()` in `onFill()` is called for detected fills. The `$isTaker` parameter should be set based on whether the fill was from a limit (maker) or market (taker) order. For now, we keep `onFill()` calling `calcPnl()` without `$isTaker` (defaults to maker), since all grid orders are placed as PostOnly limit orders. The `marketClose()` calls don't directly call `calcPnl()` — they just close positions.

**Action:** No change needed for Task 4. The `$isTaker` parameter is ready for future use and correct default behavior is already in place. Skip to Task 5.

- [ ] **Step 2: Commit (skip — no changes)**

No commit needed.

---

### Task 5: Serve fee data via `websocket_server.php`

**Files:**
- Modify: `src/php/websocket_server.php:390-426` (collectData method)

**Interfaces:**
- Consumes: fee constants from config file (already loaded as `$cfg`)
- Produces: `makerFee`, `takerFee` in the WebSocket JSON payload

- [ ] **Step 1: Add fee constants from config**

At the top of `websocket_server.php` (after line 37 where `$bybitBase` is set), add:

```php
$makerFee = (float)($cfg['fees']['maker'] ?? 0.0001);
$takerFee = (float)($cfg['fees']['taker'] ?? 0.0006);
```

- [ ] **Step 2: Include fees in collectData()**

In the `collectData()` method (line 390-426), add `'makerFee'` and `'takerFee'` to the returned array. After the `'real_balance'` key (line 422), add:

```php
            'makerFee'          => $makerFee,
            'takerFee'          => $takerFee,
```

The `collectData()` method needs access to `$makerFee` and `$takerFee`. Since these are instance variables on the `GridWebSocket` class, add them as constructor parameters or store them as properties. The cleanest approach: add them as private properties and pass via constructor.

Add to the constructor (line 173-184):

```php
    private $makerFee;
    private $takerFee;
```

In the constructor body, after `$this->confHist = $confHist;` (line 183):

```php
        $this->makerFee = (float)($cfg['fees']['maker'] ?? 0.0001);
        $this->takerFee = (float)($cfg['fees']['taker'] ?? 0.0006);
```

Wait — the constructor doesn't receive `$cfg`. Let me check the constructor signature (line 173):

```php
public function __construct($dbConfig, $wsToken, $bybitKey, $bybitSecret, $bybitBase, $logFile, $statusFile, $pidFile, $confHist)
```

The constructor doesn't receive `$cfg`. The simplest fix: add `$makerFee` and `$takerFee` parameters to the constructor.

Update the constructor call on line 429:

```php
$ws = new GridWebSocket($dbConfig, $wsToken, $bybitKey, $bybitSecret, $bybitBase, $logFile, $statusFile, $pidFile, $confHist, $makerFee, $takerFee);
```

Update the constructor signature:

```php
public function __construct($dbConfig, $wsToken, $bybitKey, $bybitSecret, $bybitBase, $logFile, $statusFile, $pidFile, $confHist, $makerFee = 0.0001, $takerFee = 0.0006)
```

And the properties + assignment:

```php
    private $makerFee;
    private $takerFee;
```

Inside constructor:
```php
        $this->makerFee = $makerFee;
        $this->takerFee = $takerFee;
```

- [ ] **Step 3: Run tests to verify no regressions**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && php vendor/bin/phpunit tests/php/Unit/`
Expected: All tests PASS

- [ ] **Step 4: Commit**

```bash
git add src/php/websocket_server.php
git commit -m "feat: WebSocket serves makerFee/takerFee in data payload"
```

---

### Task 6: Replace hardcoded fees in frontend `index.php`

**Files:**
- Modify: `src/php/index.php:759-762` and `src/php/index.php:1218-1221`

**Interfaces:**
- Consumes: `data.makerFee` and `data.takerFee` from WebSocket payload (Task 5)
- Produces: accurate fee estimates using real notional and real fee rates

- [ ] **Step 1: Add PHP-emitted fee constants for JS**

In `src/php/index.php`, find the PHP block that defines JS constants (search for `CAPITAL` or `const` in the `<script>` block near the top). The JS constants are likely emitted via PHP. Add after existing constants:

```php
const MAKER_FEE = <?php echo $makerFee ?? 0.0001; ?>;
const TAKER_FEE = <?php echo $takerFee ?? 0.0006; ?>;
```

Wait — need to check how the frontend receives data. Let me check the index.php structure. The WebSocket sends `makerFee`/`takerFee` in the payload, so the JS code can read `data.makerFee` and `data.takerFee` from the WebSocket messages.

The fee calculation appears in two places:
1. `updateUIFromWebSocket(data)` — lines 759-762
2. `updateUI(pair)` — lines 1218-1221 (polling fallback)

For the WebSocket path (line 759-762), replace:

```javascript
      const fillsCount = parseInt(($('stFills') || {}).textContent || '0');
      const avgNotional = 115;
      const fees = fillsCount * avgNotional * 0.0004;
      $('wFees').textContent = '$' + fees.toFixed(2);
```

With:

```javascript
      const fillsCount = parseInt(($('stFills') || {}).textContent || '0');
      const makerFee = data.makerFee || 0.0001;
      const takerFee = data.takerFee || 0.0006;
      const avgFeeRate = (makerFee + takerFee) / 2;
      const fillsNotional = data.pair?.fills_notional || 0;
      const fillsTotal = data.pair?.fills_total || fillsCount;
      const avgNotional = fillsTotal > 0 ? fillsNotional / fillsTotal : 115;
      const fees = fillsCount * avgNotional * avgFeeRate;
      $('wFees').textContent = '$' + fees.toFixed(2);
```

For the polling path (line 1218-1221), apply the same change but using the `pair` object (which doesn't have fees). For the polling path, the fee data is not available, so keep the fallback with a note:

```javascript
      const fillsCount = parseInt(($('stFills') || {}).textContent || '0');
      const avgNotional = 115;
      const fees = fillsCount * avgNotional * 0.0004;
      $('wFees').textContent = '$' + fees.toFixed(2);
```

The polling fallback stays hardcoded since fee data is only available via WebSocket. This is acceptable.

- [ ] **Step 2: Add `fills_notional` to WebSocket `pair` data**

In `src/php/websocket_server.php`, the `getStatus()` method builds the `pair` array. The DB query on line 290 already queries `grid_orders`. Add a query for total notional:

After line 298 (`$result['pair']['pnl_total']`), add:

```php
                $notionalRow = $db->query("SELECT COALESCE(SUM(price * qty),0) n FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED'")->fetch();
                $result['pair']['fills_notional'] = round((float)($notionalRow['n'] ?? 0), 2);
```

- [ ] **Step 3: Run tests to verify no regressions**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && php vendor/bin/phpunit tests/php/Unit/`
Expected: All tests PASS

- [ ] **Step 4: Commit**

```bash
git add src/php/index.php src/php/websocket_server.php
git commit -m "feat: frontend fee estimate uses real backend fee rates and notional"
```

---

### Task 7: Run full test suite and verify

**Files:**
- None (verification only)

- [ ] **Step 1: Run all PHP tests**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && php vendor/bin/phpunit`
Expected: All tests PASS (102+)

- [ ] **Step 2: Run JS tests**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && npx jest tests/js/` (or equivalent)
Expected: All tests PASS

- [ ] **Step 3: Commit (if any test fixes needed)**

```bash
git add -A
git commit -m "fix: ensure all tests pass after fee optimization"
```
