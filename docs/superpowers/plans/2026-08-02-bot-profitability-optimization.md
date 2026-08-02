# Grid Bot v16.2 Profitability & Safety Optimization — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the approved safe grid config (20x / 14 levels / 0.16% spacing / conservative fee floor), fix the compounding and margin bugs that block profit, and make the bot trade profitably per fill.

**Architecture:** Update `src/php/config.json` + DB `grid_configs` to the approved parameters, then patch four logic bugs in `GridManager.php` (compounding uses `G_CAPITAL` not demo balance, PnL today includes open-position uPnL, breakout re-centers without abandoning positions, grid margin budgets against effective capital). Add a small heuristic-bias helper for low-confidence ML. Verify with PHPUnit + live log.

**Tech Stack:** PHP 8.2, MySQL/MariaDB (DB `erika_bot`), PHPUnit 10.5, Mockery, Bybit demo futures API, systemd.

## Global Constraints

- Must not break the existing 140-test suite (currently 703 assertions).
- DB user: `erika_bot` / pass `Enladisco123@` / db `erika_bot` / host `localhost`.
- Approved target config: `leverage=20`, `levels=14`, `long_levels=7`, `short_levels=7`, `base_spacing=0.0016` (0.16%), `min_spacing=0.0014` (>= conservative floor 0.14%), `max_spacing=0.0020`, `fee_floor_mode=conservative`.
- `G_MAKER_FEE=0.0001`, `G_TAKER_FEE=0.0006`, `G_FEE_SAFETY=2.0` → conservative floor = `(0.0001+0.0006)*2.0 = 0.0014` (0.14%).
- Tests must define their own `G_*` constants in the test namespace block (see existing pattern in `tests/php/Unit/Strategy/GridManagerTest.php:424-448`).
- Every code change must run `php -l` and the full PHPUnit suite before commit.
- Bot lives in `/home/erika/web/binance.gregorbritez.cat/public_html`.

---

### Task 1: Restore safe config in `config.json` and sync DB `grid_configs`

**Files:**
- Modify: `src/php/config.json`
- (DB only — no code test for this task)

**Interfaces:**
- Produces: `config.json` with `bot.leverage=20`, `bot.levels=14`, `bot.long_levels=7`, `bot.short_levels=7`, `grid.min_spacing=0.0014`, `grid.max_spacing=0.0020`, `grid.base_spacing=0.0016`.
- Produces: DB row `grid_configs` for `ETHUSDT` synced to the same values + `fee_floor_mode='conservative'`, `qty_per_level=0.0300`.

- [ ] **Step 1: Edit `config.json` values**

Open `src/php/config.json`. In the `"bot"` object change `"leverage"` from `100` to `20`, `"levels"` from `16` to `14`, `"long_levels"` from `8` to `7`, `"short_levels"` from `8` to `7`.

In the `"grid"` object change `"min_spacing"` from `0.0001` to `0.0014`, `"max_spacing"` from `0.0013` to `0.0020`, `"base_spacing"` from `0.0005` to `0.0016`. Leave `spacing_atr_mult` and `min_build_interval_sec` unchanged.

- [ ] **Step 2: Validate JSON**

Run: `php -r 'json_decode(file_get_contents("src/php/config.json")); var_dump(json_last_error()===JSON_ERROR_NONE);'` from `/home/erika/web/binance.gregorbritez.cat/public_html`
Expected: `bool(true)`

- [ ] **Step 3: Sync DB**

Run from `/home/erika/web/binance.gregorbritez.cat/public_html`:

```bash
mysql -u erika_bot -p'Enladisco123@' erika_bot -e "
UPDATE grid_configs SET
  leverage = 20,
  levels = 14,
  long_levels = 7,
  short_levels = 7,
  spacing_pct = 0.001600,
  qty_per_level = 0.0300,
  fee_floor_mode = 'conservative',
  confidence = 50,
  direction = 'SIDEWAYS',
  recovery_active = 0
WHERE symbol = 'ETHUSDT';
SELECT symbol,leverage,levels,long_levels,short_levels,spacing_pct,qty_per_level,fee_floor_mode FROM grid_configs;
"
```
Expected: row shows `20 14 7 7 0.001600 0.03000000 conservative`.

- [ ] **Step 4: Commit**

```bash
git add src/php/config.json
git commit -m "config: restore approved safe grid params (20x/14L/0.16%/conservative)"
```

---

### Task 2: `getPnlToday()` includes open-position unrealized PnL

**Files:**
- Modify: `src/php/Strategy/GridManager.php:1162-1169`
- Test: `tests/php/Unit/Strategy/GridManagerTest.php`

**Interfaces:**
- Consumes: `$this->api->positions(G_SYM)` returning array of normalized positions with `unRealizedProfit` keys.
- Produces: `private function getPnlToday(): float` returns EXIT-filled PnL today **plus** sum of `unRealizedProfit` of all open positions.

- [ ] **Step 1: Write the failing test**

Append inside class `GridManagerTest` (before the closing brace at line 406):

```php
public function testGetPnlTodayIncludesOpenPositionUPnL(): void
{
    $api = \Mockery::mock(BybitFutures::class);
    $api->shouldReceive('positions')->with('ETHUSDT')->once()->andReturn([
        ['unRealizedProfit' => 0.5, 'positionAmt' => 0.03, 'side' => 'Buy'],
        ['unRealizedProfit' => -0.2, 'positionAmt' => -0.03, 'side' => 'Sell'],
    ]);
    $ai  = new GridAI();
    $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
    $manager = new GridManager($api, $ai, $ml);

    self::$dbxFetchResult = ['p' => '1.00000000'];

    $ref = new \ReflectionMethod(GridManager::class, 'getPnlToday');
    $ref->setAccessible(true);
    $result = $ref->invoke($manager);

    // 1.0 (EXITs filled today) + 0.5 + (-0.2) = 1.3
    $this->assertEqualsWithDelta(1.3, $result, 0.000001);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && vendor/bin/phpunit --filter testGetPnlTodayIncludesOpenPositionUPnL tests/php/Unit/Strategy/GridManagerTest.php`
Expected: FAIL — Mockery reports `positions()` never called, or result is `1.0` instead of `1.3`.

- [ ] **Step 3: Implement**

Replace the body of `getPnlToday()` (`GridManager.php:1162-1169`) with:

```php
private function getPnlToday() {
    try {
        $r = dbx(function($d) {
            return $d->query("SELECT COALESCE(SUM(pnl_usd),0) AS p FROM grid_orders WHERE symbol='" . G_SYM . "' AND grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE()")->fetch();
        });
        $pnl = $r ? (float)$r['p'] : 0.0;
        foreach ($this->api->positions(G_SYM) as $pos) {
            $pnl += (float)($pos['unRealizedProfit'] ?? 0);
        }
        return $pnl;
    } catch (\Exception $e) { lE("[PNL] " . $e->getMessage()); return 0.0; }
}
```

- [ ] **Step 4: Run tests to verify pass**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && vendor/bin/phpunit --filter testGetPnlTodayIncludesOpenPositionUPnL tests/php/Unit/Strategy/GridManagerTest.php && vendor/bin/phpunit tests/php/Unit/Strategy/GridManagerTest.php`
Expected: new test PASS; existing GridManager tests still PASS.

- [ ] **Step 5: Lint and commit**

```bash
cd /home/erika/web/binance.gregorbritez.cat/public_html
php -l src/php/Strategy/GridManager.php
git add src/php/Strategy/GridManager.php tests/php/Unit/Strategy/GridManagerTest.php
git commit -m "fix(grid): getPnlToday includes open-position unrealized PnL"
```

---

### Task 3: `profitOptimize()` compounding uses `G_CAPITAL`, not demo balance

**Files:**
- Modify: `src/php/Strategy/GridManager.php:1106-1138` (lines 1109 and 1120)
- Test: `tests/php/Unit/Strategy/GridManagerTest.php`

**Interfaces:**
- Consumes: `getPnlToday()` (Task 2), `$this->api->balance()`, `$this->api->filters(G_SYM)`, constants `G_CAPITAL`, `G_LEVERAGE`, `G_COMPOUND_THR`, `G_COMPOUND_MULT`, `G_COMPOUND_CD`, `G_FIXED_LEVELS`.
- Produces: compounding decision based on `$pnlTdy / G_CAPITAL * 100` (not `$pnlTdy / $balance * 100`).

- [ ] **Step 1: Add missing test constants**

In the test namespace block at the bottom (`GridManagerTest.php:424-448`), after the existing `if (!defined('G_BASE_SPACING'))` block, add:

```php
    if (!defined('G_CAPITAL')) {
        define('G_CAPITAL', 100.0);
    }
    if (!defined('G_LEVERAGE')) {
        define('G_LEVERAGE', 20);
    }
    if (!defined('G_MARGIN_SAFETY')) {
        define('G_MARGIN_SAFETY', 0.40);
    }
    if (!defined('G_COMPOUND_THR')) {
        define('G_COMPOUND_THR', 1.5);
    }
    if (!defined('G_COMPOUND_MULT')) {
        define('G_COMPOUND_MULT', 1.05);
    }
    if (!defined('G_COMPOUND_CD')) {
        define('G_COMPOUND_CD', 0);
    }
    if (!defined('G_FIXED_LEVELS')) {
        define('G_FIXED_LEVELS', 14);
    }
    if (!defined('G_LONG_LEVELS')) {
        define('G_LONG_LEVELS', 7);
    }
    if (!defined('G_SHORT_LEVELS')) {
        define('G_SHORT_LEVELS', 7);
    }
    if (!defined('G_ML_BLEND_WEIGHT')) {
        define('G_ML_BLEND_WEIGHT', 0.90);
    }
    if (!defined('G_VL_BLEND_WEIGHT')) {
        define('G_VL_BLEND_WEIGHT', 0.10);
    }
    if (!defined('G_AI_INTERVAL')) {
        define('G_AI_INTERVAL', 120);
    }
```

- [ ] **Step 2: Write the failing test**

Append inside class `GridManagerTest`:

```php
public function testProfitOptimizeUsesCapitalNotBalanceForCompound(): void
{
    $api = \Mockery::mock(BybitFutures::class);
    $api->shouldReceive('balance')->andReturn(1650000.0);
    $api->shouldReceive('filters')->andReturn(['step'=>0.001,'tick'=>0.01,'mn'=>0.01,'qp'=>3,'pp'=>2]);
    $api->shouldReceive('positions')->andReturn([]);
    $ai  = new GridAI();
    $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
    $manager = new GridManager($api, $ai, $ml);

    $ref = new \ReflectionClass(GridManager::class);
    $cfgProp = $ref->getProperty('cfg');
    $cfgProp->setAccessible(true);
    $cfgProp->setValue($manager, [
        'id' => 1, 'symbol' => 'ETHUSDT', 'direction' => 'SIDEWAYS',
        'confidence' => 50, 'qty_per_level' => 0.03, 'recovery_active' => 0,
        'spacing_pct' => 0.0016,
    ]);
    $lastCompound = $ref->getProperty('lastCompound');
    $lastCompound->setAccessible(true);
    $lastCompound->setValue($manager, 0);

    self::$dbxFetchResult = ['p' => '2.00000000'];

    $method = $ref->getMethod('profitOptimize');
    $method->setAccessible(true);
    $method->invoke($manager, 1869.0);

    $hasQtyUpdate = false;
    foreach (self::$dbxCalls as $sql) {
        if (str_contains($sql, 'UPDATE grid_configs SET qty_per_level')) $hasQtyUpdate = true;
    }
    // With 1.65M demo balance, pct = 2/1650000*100 = 0.00012% (old code → no compound).
    // With G_CAPITAL=100, pct = 2/100*100 = 2% >= 1.5 (new code → compound fires).
    $this->assertTrue($hasQtyUpdate, 'compounding must use G_CAPITAL, not demo balance');
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && vendor/bin/phpunit --filter testProfitOptimizeUsesCapitalNotBalanceForCompound tests/php/Unit/Strategy/GridManagerTest.php`
Expected: FAIL — `$hasQtyUpdate` is false (old code divides by `$balance`).

- [ ] **Step 4: Implement**

In `profitOptimize()` (`GridManager.php:1106-1138`), change line 1109:
```php
$pct = $pnlTdy / G_CAPITAL * 100;
```
(was `$pct = $pnlTdy / $balance * 100;`)

Change line 1120:
```php
$hardCap    = (G_CAPITAL * 0.12 * G_LEVERAGE) / $price;
```
(was `$hardCap    = ($balance * 0.12 * G_LEVERAGE) / $price;`)

Keep everything else in the method unchanged.

- [ ] **Step 5: Run tests to verify pass**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && vendor/bin/phpunit --filter testProfitOptimizeUsesCapitalNotBalanceForCompound tests/php/Unit/Strategy/GridManagerTest.php && vendor/bin/phpunit tests/php/Unit/Strategy/GridManagerTest.php`
Expected: new test PASS; existing GridManager tests still PASS.

- [ ] **Step 6: Lint and commit**

```bash
cd /home/erika/web/binance.gregorbritez.cat/public_html
php -l src/php/Strategy/GridManager.php
git add src/php/Strategy/GridManager.php tests/php/Unit/Strategy/GridManagerTest.php
git commit -m "fix(grid): compound using G_CAPITAL instead of demo balance"
```

---

### Task 4: `breakoutCheck()` re-centers the grid without abandoning positions

**Files:**
- Modify: `src/php/Strategy/GridManager.php:1140-1160`
- Test: `tests/php/Unit/Strategy/GridManagerTest.php`

**Interfaces:**
- Consumes: `$this->api->cancelAll(G_SYM)`, `dbx(...)`, `$this->syncPositions()` (defined `GridManager.php:336`), `$this->api->positions(G_SYM)`.
- Produces: on breakout, cancels open orders, marks `gridBuilt=false`, then re-creates EXIT orders for any open positions via `syncPositions()`.

- [ ] **Step 1: Write the failing test**

Append inside class `GridManagerTest`:

```php
public function testBreakoutCheckReCentersAndPreservesPositions(): void
{
    $api = \Mockery::mock(BybitFutures::class);
    $api->shouldReceive('cancelAll')->with('ETHUSDT')->once();
    $api->shouldReceive('positions')->once()->andReturn([]);
    $ai  = new GridAI();
    $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
    $manager = new GridManager($api, $ai, $ml);

    $ref = new \ReflectionClass(GridManager::class);
    $gridBuilt = $ref->getProperty('gridBuilt');
    $gridBuilt->setAccessible(true);
    $gridBuilt->setValue($manager, true);
    $lastBuild = $ref->getProperty('lastGridBuild');
    $lastBuild->setAccessible(true);
    $lastBuild->setValue($manager, 999999);
    $cfgProp = $ref->getProperty('cfg');
    $cfgProp->setAccessible(true);
    $cfgProp->setValue($manager, [
        'id' => 1, 'symbol' => 'ETHUSDT', 'direction' => 'SIDEWAYS',
        'levels' => 14, 'long_levels' => 7, 'short_levels' => 7,
        'spacing_pct' => 0.0016,
    ]);

    // DB range: mn=1800, mx=1900 -> margin=30 -> price 2000 is a breakout
    self::$dbxFetchResult = ['mn' => '1800.00000000', 'mx' => '1900.00000000'];

    $method = $ref->getMethod('breakoutCheck');
    $method->setAccessible(true);
    $method->invoke($manager, 2000.0);

    $this->assertFalse($gridBuilt->getValue($manager), 'breakout must mark grid for rebuild');
    $this->assertSame(0, $lastBuild->getValue($manager), 'breakout must rebuild immediately');

    $hasCancel = false;
    foreach (self::$dbxCalls as $sql) {
        if (str_contains($sql, "SET status='CANCELED' WHERE symbol='ETHUSDT' AND status='OPEN'")) $hasCancel = true;
    }
    $this->assertTrue($hasCancel, 'breakout must cancel open orders');
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && vendor/bin/phpunit --filter testBreakoutCheckReCentersAndPreservesPositions tests/php/Unit/Strategy/GridManagerTest.php`
Expected: FAIL — Mockery reports `positions()` never called (old code never calls `syncPositions()` after a breakout).

- [ ] **Step 3: Implement**

Replace the body of `breakoutCheck()` (`GridManager.php:1140-1160`) with:

```php
private function breakoutCheck($price) {
    if (!$this->gridBuilt) return;
    $r = dbx(function($d) {
        return $d->query("SELECT MIN(price) mn,MAX(price) mx FROM grid_orders WHERE symbol='" . G_SYM . "' AND status='OPEN'")->fetch();
    });
    if (!$r || !$r['mn']) return;
    $range = (float)$r['mx'] - (float)$r['mn']; $margin = $range * 0.30;
    if ($price < (float)$r['mn'] - $margin || $price > (float)$r['mx'] + $margin) {
        $lastFill = dbx(function($d) {
            return $d->query("SELECT MAX(filled_at) FROM grid_orders WHERE symbol='" . G_SYM . "' AND status='FILLED' AND filled_at IS NOT NULL")->fetchColumn();
        });
        if ($lastFill && (time() - strtotime($lastFill)) < 90) {
            lI(sprintf("[BREAKOUT] $%.2f fuera rango pero fill reciente (%ds), esperando...", $price, time() - strtotime($lastFill)));
            return;
        }
        lI(sprintf("[BREAKOUT] $%.2f fuera [%.2f-%.2f] → re-centrando grid, conservando posiciones", $price, $r['mn'], $r['mx']));
        $this->api->cancelAll(G_SYM);
        dbx(function($d) { return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE symbol=? AND status='OPEN'")->execute([G_SYM]); });
        $this->gridBuilt = false; $this->lastGridBuild = 0;
        $this->syncPositions();
    }
}
```

- [ ] **Step 4: Run tests to verify pass**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && vendor/bin/phpunit --filter testBreakoutCheckReCentersAndPreservesPositions tests/php/Unit/Strategy/GridManagerTest.php && vendor/bin/phpunit tests/php/Unit/Strategy/GridManagerTest.php`
Expected: new test PASS; existing GridManager tests still PASS.

- [ ] **Step 5: Lint and commit**

```bash
cd /home/erika/web/binance.gregorbritez.cat/public_html
php -l src/php/Strategy/GridManager.php
git add src/php/Strategy/GridManager.php tests/php/Unit/Strategy/GridManagerTest.php
git commit -m "fix(grid): breakout re-centers grid and preserves open positions"
```

---

### Task 5: `buildGrid()` margin check budgets against effective capital

**Files:**
- Modify: `src/php/Strategy/GridManager.php:689-738`
- Test: `tests/php/Unit/Strategy/GridManagerTest.php`

**Interfaces:**
- Consumes: `$this->api->balance()`, `$this->api->filters(G_SYM)`, `$this->api->limitOrder(...)`, constants `G_CAPITAL`, `G_MARGIN_SAFETY`, `G_LEVERAGE`, `G_FIXED_LEVELS`.
- Produces: `$effectiveCap = min($balance, G_CAPITAL) * G_MARGIN_SAFETY` used as the margin budget for both BUY and SELL grid placement.

- [ ] **Step 1: Write the failing test**

Append inside class `GridManagerTest`:

```php
public function testBuildGridSkipsOrdersWhenEffectiveCapExceeded(): void
{
    $api = \Mockery::mock(BybitFutures::class);
    $api->shouldReceive('balance')->andReturn(1650000.0);
    $api->shouldReceive('filters')->andReturn(['step'=>0.001,'tick'=>0.01,'mn'=>0.01,'qp'=>3,'pp'=>2]);
    $api->shouldReceive('limitOrder')->never();
    $ai  = new GridAI();
    $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
    $manager = new GridManager($api, $ai, $ml);

    $ref = new \ReflectionClass(GridManager::class);
    $cfgProp = $ref->getProperty('cfg');
    $cfgProp->setAccessible(true);
    $cfgProp->setValue($manager, [
        'id' => 1, 'symbol' => 'ETHUSDT', 'direction' => 'SIDEWAYS',
        'confidence' => 50, 'levels' => 14, 'long_levels' => 7,
        'short_levels' => 7, 'spacing_pct' => 0.0016,
        'qty_per_level' => 0.5,
    ]);
    $lastBuild = $ref->getProperty('lastGridBuild');
    $lastBuild->setAccessible(true);
    $lastBuild->setValue($manager, 0);

    $method = $ref->getMethod('buildGrid');
    $method->setAccessible(true);
    $method->invoke($manager, 1869.0);

    $gridBuilt = $ref->getProperty('gridBuilt');
    $gridBuilt->setAccessible(true);
    // qty=0.5 -> reqMargin per level = 0.5*1869/20 = 46.7 > effectiveCap=40 -> all skipped
    $this->assertFalse($gridBuilt->getValue($manager), 'orders must be skipped when margin exceeds effectiveCap');
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && vendor/bin/phpunit --filter testBuildGridSkipsOrdersWhenEffectiveCapExceeded tests/php/Unit/Strategy/GridManagerTest.php`
Expected: FAIL — old code budgets against 1.65M balance, so `limitOrder` IS called, breaking the `->never()` expectation.

- [ ] **Step 3: Implement**

In `buildGrid()` (`GridManager.php:689-738`), after the existing balance block (after line 691 `else { lI(sprintf("[GRID] Balance disponible: %.4f USDT", $balance)); }`), insert:

```php
        $effectiveCap = min($balance, G_CAPITAL) * G_MARGIN_SAFETY;
```

Then in the BUY loop change line 713:
```php
if ($reqMargin > ($effectiveCap - $usedMargin) * 0.95) { lW("[GRID] Margen insuficiente BUY L$i"); continue; }
```
(was `$reqMargin > ($balance - $usedMargin) * 0.95`)

And in the SELL loop change line 728:
```php
if ($reqMargin > ($effectiveCap - $usedMargin) * 0.95) { lW("[GRID] Margen insuficiente SELL L$i"); continue; }
```
(was `$reqMargin > ($balance - $usedMargin) * 0.95`)

- [ ] **Step 4: Run tests to verify pass**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && vendor/bin/phpunit --filter testBuildGridSkipsOrdersWhenEffectiveCapExceeded tests/php/Unit/Strategy/GridManagerTest.php && vendor/bin/phpunit tests/php/Unit/Strategy/GridManagerTest.php`
Expected: new test PASS; existing GridManager tests still PASS.

- [ ] **Step 5: Lint and commit**

```bash
cd /home/erika/web/binance.gregorbritez.cat/public_html
php -l src/php/Strategy/GridManager.php
git add src/php/Strategy/GridManager.php tests/php/Unit/Strategy/GridManagerTest.php
git commit -m "fix(grid): buildGrid margins budget against effective capital"
```

---

### Task 6: Low-confidence ML boosts heuristic weight

**Files:**
- Modify: `src/php/Strategy/GridManager.php` — add private helper near `aiEvaluate()` (insert before line 474 `private function aiEvaluate($price) {`), and update the blend block at lines 524-541.
- Test: `tests/php/Unit/Strategy/GridManagerTest.php`

**Interfaces:**
- Consumes: constant `G_ML_BLEND_WEIGHT`.
- Produces: `private function computeBlendWeights(int $mlConf): array` returning `['ml' => float, 'heur' => float]`. When `$mlConf < 85`, `heur = max(1 - G_ML_BLEND_WEIGHT, 0.30)` and `ml = 1 - heur`; otherwise default `ml = G_ML_BLEND_WEIGHT`, `heur = 1 - G_ML_BLEND_WEIGHT`.

- [ ] **Step 1: Write the failing test**

Append inside class `GridManagerTest`:

```php
public function testComputeBlendWeightsBoostsHeuristicOnLowConfidence(): void
{
    $api = new BybitFutures('test_key', 'test_secret', true);
    $ai  = new GridAI();
    $ml  = new GridML('/tmp/nonexistent_weights_' . uniqid() . '.json');
    $manager = new GridManager($api, $ai, $ml);

    $ref = new \ReflectionMethod(GridManager::class, 'computeBlendWeights');
    $ref->setAccessible(true);

    $high = $ref->invoke($manager, 99);
    $this->assertEqualsWithDelta(0.90, $high['ml'], 0.0001);
    $this->assertEqualsWithDelta(0.10, $high['heur'], 0.0001);

    $low = $ref->invoke($manager, 70);
    $this->assertEqualsWithDelta(0.70, $low['ml'], 0.0001);
    $this->assertEqualsWithDelta(0.30, $low['heur'], 0.0001);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && vendor/bin/phpunit --filter testComputeBlendWeightsBoostsHeuristicOnLowConfidence tests/php/Unit/Strategy/GridManagerTest.php`
Expected: FAIL — `ReflectionMethod` throws "Method does not exist".

- [ ] **Step 3: Implement helper**

Insert before `private function aiEvaluate($price) {` (line 474):

```php
    private function computeBlendWeights(int $mlConf): array {
        $w_ml   = G_ML_BLEND_WEIGHT;
        $w_heur = 1 - $w_ml;
        if ($mlConf < 85) {
            $w_heur = max($w_heur, 0.30);
            $w_ml   = 1 - $w_heur;
        }
        return ['ml' => $w_ml, 'heur' => $w_heur];
    }
```

In `aiEvaluate()`, replace lines 524-525:
```php
        $w_ml = G_ML_BLEND_WEIGHT;
        $w_heur = 1 - $w_ml;
```
with:
```php
        $blend = $this->computeBlendWeights($mlResult['confidence']);
        $w_ml = $blend['ml'];
        $w_heur = $blend['heur'];
```

The rest of the VL-blend logic (lines 526-541) stays unchanged.

- [ ] **Step 4: Run tests to verify pass**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && vendor/bin/phpunit --filter testComputeBlendWeightsBoostsHeuristicOnLowConfidence tests/php/Unit/Strategy/GridManagerTest.php && vendor/bin/phpunit tests/php/Unit/Strategy/GridManagerTest.php`
Expected: new test PASS; existing GridManager tests still PASS.

- [ ] **Step 5: Lint and commit**

```bash
cd /home/erika/web/binance.gregorbritez.cat/public_html
php -l src/php/Strategy/GridManager.php
git add src/php/Strategy/GridManager.php tests/php/Unit/Strategy/GridManagerTest.php
git commit -m "feat(grid): boost heuristic weight when ML confidence < 85%"
```

---

### Task 7: Full regression + live deployment verification

**Files:**
- None new (verification only).

- [ ] **Step 1: Run full test suite**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && vendor/bin/phpunit`
Expected: 140+ tests, all PASS (no regressions; count grows to at least 145 with the 5 new tests).

- [ ] **Step 2: Lint all modified source files**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && php -l src/php/Strategy/GridManager.php && php -l src/php/config.json` (config.json lint = JSON validate from Task 1).
Expected: no syntax errors.

- [ ] **Step 3: Restart the bot**

Run:
```bash
sudo systemctl restart grid-bot
sleep 15
sudo systemctl restart grid-bot-ws
```
(If `sudo` is unavailable, run as the `erika` user: `systemctl --user restart grid-bot` — verify which manager owns the units first with `systemctl status grid-bot`.)

- [ ] **Step 4: Verify startup log**

Run: `tail -n 40 /home/erika/web/binance.gregorbritez.cat/public_html/bot.log`
Expected to see:
- `[Bybit] Leverage 20x OK`
- `[CFG] niv=14 spc=0.1600% long=7 short=7 capital=100 | feeFloor=0.1400% (mode=conservative, maker=0.0100% taker=0.0600% safety=2.00)`
- `[CALC] Qty: 0.0300 ETH (cap=40.00 mrg/niv=...)`
- `[GRID] ✓ 14/14 órdenes | 0 err | Margen: ...`

- [ ] **Step 5: Verify DB row matches**

Run:
```bash
mysql -u erika_bot -p'Enladisco123@' erika_bot -e "SELECT symbol,leverage,levels,spacing_pct,qty_per_level,fee_floor_mode FROM grid_configs;"
```
Expected: `ETHUSDT 20 14 0.001600 0.03000000 conservative`

- [ ] **Step 6: Monitor for profitable fills**

Run: `sleep 600 && tail -n 20 /home/erika/web/binance.gregorbritez.cat/public_html/bot.log`
Expected: any `[FILL] EXIT ... PnL=...` lines show net PnL > 0 (spacing 0.16% > conservative floor 0.14%).

- [ ] **Step 7: Final commit (if any test files changed in Task 7)**

```bash
cd /home/erika/web/binance.gregorbritez.cat/public_html
git status --short
git add -A
git commit -m "test(grid): add profitability optimization regression tests" || echo "nothing to commit"
```
