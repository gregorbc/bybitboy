# Professional Charts + Pending Positions + Strategy Status — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a TradingView professional chart tab, overlay pending grid orders as price lines on the quick-view candlestick chart, and add a "Estrategia & Estatus" panel to the dashboard.

**Architecture:** Backend exposes `ai_engine` (strategy name) and `mode` via the existing WebSocket `full` payload and the `grid_ajax.php?_status=1` fallback. Frontend (all inline in `src/php/index.php`) adds a Pro/Rápido chart-tab toggle, draws per-order price lines on the Lightweight Charts series, and renders a strategy/status card in the left sidebar fed by the initial PHP payload and live WS data.

**Tech Stack:** PHP 7.4+ (inline HTML/JS/CSS in `index.php`), Lightweight Charts v4.1.1 (global `LightweightCharts`), TradingView free embed widget, Ratchet WebSocket server, PHPUnit, Vitest.

## Global Constraints

- All frontend lives inline in `src/php/index.php` (inline `<style>` block + inline `<script>`). Do **not** create new JS/CSS files — follow the existing inline pattern.
- The dashboard is live: the bot (`bot.php`) and WS server (`websocket_server.php`) are running. Only the WS server is restarted in this plan; `bot.php` is **never** touched.
- Database schema is unchanged. `grid_ajax.php`, `websocket_server.php` get only additive array-key changes (no query/logic changes).
- Style conventions: `const $ = id => document.getElementById(id)`; guards like `if($('el'))` before every element access; existing CSS vars (`--green`, `--yellow`, `--red`, `--purple`, `--accent`, `--border`, `--bg3`, `--muted`, `--dim`); `font-family:var(--mono)` for numbers.
- Automated JS tests are not feasible for inline dashboard code (no module boundary). Verification = `php -l`, existing suites (`vendor/bin/phpunit`, `npm test`), and the concrete browser checks listed per task.
- Commit per task with conventional style (`feat:`).

---
---

## Task 1: Backend — expose `ai_engine` and `mode`

**Files:**
- Modify: `src/php/websocket_server.php:296-313` (the `$result['pair'] = [...]` block in `getStatus()`)
- Modify: `src/php/grid_ajax.php:102-103` (the `$data` array) and `src/php/grid_ajax.php:131-162` (the `$data['pairs']` array)

**Interfaces:**
- Produces: WS `full` payload now includes `pair.ai_engine` (string, default `'Grid v15.4'`). `grid_ajax.php?_status=1` now returns `mode` (string) at top level and `pairs.ETHUSDT.ai_engine` (string).
- Consumed by: Task 2's `updateStrategyPanel(pair, mode, botRunning)` and initial PHP render.

- [ ] **Step 1: Add `ai_engine` to the WS pair payload**

In `src/php/websocket_server.php`, change the first lines of the `$result['pair'] = [` block (currently lines 296-297):

```php
                $result['pair'] = [
                    'confidence'      => (int)($pj['confidence'] ?? 50),
```

to:

```php
                $result['pair'] = [
                    'ai_engine'       => $st['ai_engine'] ?? 'Grid v15.4',
                    'confidence'      => (int)($pj['confidence'] ?? 50),
```

- [ ] **Step 2: Add `mode` and `ai_engine` to grid_ajax `_status`**

In `src/php/grid_ajax.php`, change the `$data` initialization (currently lines 102-103):

```php
    $data    = ['ok' => true, 'running' => $running, 'uptime' => $uptime,
                'ts' => date('Y-m-d H:i:s'), 'pairs' => (object)[]];
```

to:

```php
    $data    = ['ok' => true, 'running' => $running, 'uptime' => $uptime,
                'mode' => (isset($st['mode'])) ? $st['mode'] : 'NORMAL',
                'ts' => date('Y-m-d H:i:s'), 'pairs' => (object)[]];
```

Then, in the `$data['pairs'] = ['ETHUSDT' => [` array (currently starts at line 131), add `ai_engine` as the first key:

```php
            $data['pairs'] = ['ETHUSDT' => [
                'ai_engine'      => $st['ai_engine'] ?? 'Grid v15.4',
                'direction'      => $pj['direction']     ?? $cfgRow['direction']   ?? 'SIDEWAYS',
```

- [ ] **Step 3: Lint both files**

Run: `php -l src/php/websocket_server.php && php -l src/php/grid_ajax.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Verify grid_ajax response**

Run: `curl -s 'http://localhost/grid_ajax.php?_status=1' | head -c 600`
Expected: JSON containing `"mode":"NORMAL"` and `"ai_engine":"Grid v15.4"`. (If the endpoint is token-gated on this host, append `&token=<EXPORT_TOKEN>` from `src/php/index.php` line 27.)

- [ ] **Step 5: Run existing test suites**

Run: `vendor/bin/phpunit`
Expected: All green (no regressions).

Run: `npm test`
Expected: All pass.

- [ ] **Step 6: Restart the WebSocket server**

The WS server runs as PID 2800 (user `erika`). Restart preserving ownership:

```bash
pkill -f websocket_server.php; sleep 1
su - erika -c "cd /home/erika/web/binance.gregorbritez.cat/public_html/src/php && nohup /usr/bin/php websocket_server.php >> /home/erika/web/binance.gregorbritez.cat/public_html/data/logs/websocket.log 2>&1 &"
sleep 2
ss -ltnp | grep 8094
```

Expected: port `8094` listening. (If `su - erika` is not permitted, restart the same way the process was originally launched — the key requirement is that the server restarts and binds 8094.)

- [ ] **Step 7: Commit**

```bash
git add src/php/websocket_server.php src/php/grid_ajax.php
git commit -m "feat(api): expose ai_engine and mode in WS and status endpoints"
```

---
---

## Task 2: Strategy & Estatus panel

**Files:**
- Modify: `src/php/index.php` — style block (~line 250), sidebar HTML (between lines 463-464), PHP init block (lines 88-93), initial-render JS (lines 1490-1509), new `updateStrategyPanel()` after `updatePairUI` (line 1266), hooks in `updateUIFromWebSocket` (line 736) and `fetchStatus` (line 940).

**Interfaces:**
- Consumes: WS `data.pair` (with new `ai_engine` from Task 1), `data.mode`, `data.bot_running`; `_status` fallback `d.pairs.ETHUSDT`; initial PHP `$init` with new keys.
- Produces: DOM ids `strategyName`, `strategyMode`, `strategyDir`, `strategyConf`, `strategyMl`, `strategyBot`, `strategyGrid`, `strategyCycle`, `strategyAiTs`, `strategyReason`. Function `updateStrategyPanel(pair, mode, botRunning)` (no return).

- [ ] **Step 1: Add panel CSS**

In `src/php/index.php`, after the `.cfg-v` rule (line 250), add:

```css
.strategy-reason{margin:0 12px 10px;font-family:var(--mono);font-size:8px;color:var(--dim);line-height:1.5;border-top:1px solid var(--border);padding-top:8px;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
```

- [ ] **Step 2: Add panel HTML to the left sidebar**

Insert this card between the "Señal IA" card closing `</div>` (line 463) and the "Wallet" card opening `<div class="card">` (line 464):

```html
      <div class="card">
        <div class="card-hd"><b>🎯 Estrategia &amp; Estatus</b><span id="strategyMode" class="mode-badge m-NORMAL">NORMAL</span></div>
        <div class="cfg-grid">
          <span class="cfg-k">Estrategia</span><span class="cfg-v" id="strategyName">--</span>
          <span class="cfg-k">Dirección</span><span class="cfg-v" id="strategyDir">--</span>
          <span class="cfg-k">Confianza</span><span class="cfg-v" id="strategyConf">--</span>
          <span class="cfg-k">ML precisión</span><span class="cfg-v c-neu" id="strategyMl">--</span>
          <span class="cfg-k">Estado bot</span><span class="cfg-v" id="strategyBot">--</span>
          <span class="cfg-k">Grid</span><span class="cfg-v" id="strategyGrid">--</span>
          <span class="cfg-k">Ciclo</span><span class="cfg-v" id="strategyCycle">--</span>
          <span class="cfg-k">Última IA</span><span class="cfg-v" id="strategyAiTs">--</span>
        </div>
        <div class="strategy-reason" id="strategyReason" title="">--</div>
      </div>
```

- [ ] **Step 3: Extend the PHP `$init` payload**

In `src/php/index.php`, inside the `try` block, after the `$init = [...]` assignment (line 93) and before the closing `} catch`, add:

```php
            $stPath  = $cfg['paths']['status'] ?? null;
            $stRaw   = ($stPath && file_exists($stPath)) ? json_decode(file_get_contents($stPath), true) : null;
            $stPair  = (isset($stRaw['pairs']['ETHUSDT'])) ? $stRaw['pairs']['ETHUSDT'] : [];
            $init['ai_engine']     = $stRaw['ai_engine']     ?? 'Grid v15.4';
            $init['mode']          = $stRaw['mode']          ?? 'NORMAL';
            $init['grid_built']    = (bool)($stPair['grid_built'] ?? true);
            $init['last_ai_check'] = $stPair['last_ai_check'] ?? null;
            $init['cycle_n']       = (int)($stPair['cycle_n'] ?? 0);
```

- [ ] **Step 4: Seed the panel from the initial PHP payload**

In the initial-render JS block (lines 1490-1509), inside the `(function(){ ... })()`, after the `$('gRsn').textContent=i.ai_reason;` line (line 1505), add:

```js
  if(i.ai_engine) $('strategyName').textContent=i.ai_engine;
  if(i.mode){const sm=$('strategyMode');sm.textContent=i.mode;sm.className='mode-badge m-'+i.mode;}
  if(i.direction) $('strategyDir').textContent=i.direction;
  if(i.confidence) $('strategyConf').textContent=i.confidence+'%';
  const iMl=i.ml_accuracy||0;
  if(iMl>0) $('strategyMl').textContent=(iMl*100).toFixed(1)+'%';
  $('strategyGrid').textContent=(i.grid_built===false)?'✗ No construido':'✓ '+(i.open_orders||0)+' órd.';
  if(i.cycle_n) $('strategyCycle').textContent=i.cycle_n;
  if(i.last_ai_check) $('strategyAiTs').textContent=String(i.last_ai_check).slice(0,16);
  if(i.ai_reason){$('strategyReason').textContent=i.ai_reason;$('strategyReason').title=i.ai_reason;}
```

- [ ] **Step 5: Add `updateStrategyPanel()`**

After the `updatePairUI` function (ends line 1266), add:

```js
function updateStrategyPanel(pair, mode, botRunning){
  if(!pair) return;
  if($('strategyName')) $('strategyName').textContent=pair.ai_engine||'Grid v15.4';
  const sm=$('strategyMode');
  if(sm&&mode){sm.textContent=mode;sm.className='mode-badge m-'+mode;}
  if($('strategyDir')) $('strategyDir').textContent=pair.direction||'--';
  if($('strategyConf')) $('strategyConf').textContent=(pair.confidence||0)+'%';
  const ml=pair.ml_accuracy||0;
  if($('strategyMl')) $('strategyMl').textContent=ml>0?(ml*100).toFixed(1)+'%':'--';
  if($('strategyBot')){
    const on=!!botRunning;
    $('strategyBot').textContent=on?'● Corriendo':'● Detenido';
    $('strategyBot').style.color=on?'var(--green)':'var(--red)';
  }
  if($('strategyGrid')){
    const built=pair.grid_built!==false;
    $('strategyGrid').textContent=built?('✓ '+(pair.open_entries||0)+'E '+(pair.open_exits||0)+'S'):'✗ No construido';
  }
  if($('strategyCycle')) $('strategyCycle').textContent=pair.cycle_n||'--';
  if($('strategyAiTs')) $('strategyAiTs').textContent=pair.last_ai_check?String(pair.last_ai_check).slice(0,16):'--';
  if($('strategyReason')){
    $('strategyReason').textContent=pair.ai_reason||'--';
    $('strategyReason').title=pair.ai_reason||'';
  }
}
```

- [ ] **Step 6: Hook into the WebSocket path**

In `updateUIFromWebSocket` (line 721), inside `if (data.pair) {` (line 736), add as the first statement:

```js
        updateStrategyPanel(data.pair, data.mode, data.bot_running);
```

- [ ] **Step 7: Hook into the polling fallback**

In `fetchStatus` (line 927), inside `if(pair){` (line 941), add as the first statement:

```js
    updateStrategyPanel(pair, mode, running);
```

(`mode` is defined at line 937 and `running` at line 932 in the same function.)

- [ ] **Step 8: Lint and verify**

Run: `php -l src/php/index.php`
Expected: `No syntax errors detected`.

Browser check (open the dashboard):
1. Reload — the "🎯 Estrategia & Estatus" card shows "Grid v15.4", mode badge, direction, confidence, ML %, bot status, grid state, cycle, last AI timestamp, and the AI reason text (2-line clamp).
2. Stop/start the bot (`■ Stop` / start) and confirm "Estado bot" flips between "● Corriendo" (green) and "● Detenido" (red).
3. Confirm the mode badge updates when mode is RECOVERY.

- [ ] **Step 9: Commit**

```bash
git add src/php/index.php
git commit -m "feat(web): add strategy and status panel to sidebar"
```

---
---

## Task 3: Chart tabs — TradingView Pro / Rápido

**Files:**
- Modify: `src/php/index.php` — style block (after `.chart-hd` rules, ~line 253), chart section HTML (lines 507-513), global `lastOhlc` (line 637), `initLwChart` (lines 965-1043), `fetchMarket` (lines 1049-1063), new `switchChartTab()` after `switchTab` (line 1159).

**Interfaces:**
- Consumes: nothing new from other tasks.
- Produces: DOM ids `tvChartWrap`, `tvFrame`, `chartTabPro`, `chartTabFast`, `chartLegend`. Global `lastOhlc` (array of `{time,open,high,low,close}`). Function `switchChartTab(tab)` (`'pro'` | `'fast'`). Task 4 consumes `#candleChart` (now 360px tall) and `#chartLegend`.

- [ ] **Step 1: Add chart-tab CSS**

In the inline `<style>` block, after the `.chart-hd b{...}` rule (line 253), add:

```css
.chart-tabs{display:flex;gap:2px;padding:6px 13px 0;border-bottom:1px solid var(--border);background:var(--bg3)}
.chart-tab{padding:5px 12px;font-size:9px;font-weight:600;color:var(--muted);background:transparent;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;cursor:pointer;font-family:var(--sans);letter-spacing:.3px}
.chart-tab.active{color:var(--accent);border-color:var(--border);background:var(--bg2)}
.tv-wrap{display:none;height:420px}
.tv-wrap iframe{width:100%;height:100%;border:0;display:block}
.chart-legend{display:flex;flex-wrap:wrap;gap:4px 12px;padding:6px 13px;background:var(--bg3);border-top:1px solid var(--border);font-family:var(--mono);font-size:8px;color:var(--dim);min-height:26px;align-items:center}
```

- [ ] **Step 2: Replace the chart section HTML**

Replace lines 507-513 (the whole `.chart-sect` block) with:

```html
      <div class="chart-sect card">
        <div class="chart-hd">
          <b>ETH/USDT · 5m · Bybit</b>
          <span id="mktRange" style="color:var(--dim);font-size:9px"></span>
        </div>
        <div class="chart-tabs">
          <button class="chart-tab active" id="chartTabPro" onclick="switchChartTab('pro')">TradingView</button>
          <button class="chart-tab" id="chartTabFast" onclick="switchChartTab('fast')">Rápido</button>
        </div>
        <div id="tvChartWrap" class="tv-wrap" style="display:block">
          <iframe id="tvFrame" title="TradingView ETHUSDT" loading="lazy" src="https://s.tradingview.com/widgetembed/?frameElementId=tv_ethusdt&amp;symbol=BYBIT:ETHUSDT&amp;interval=5&amp;hidesidetoolbar=0&amp;hideideas=1&amp;theme=dark&amp;style=1&amp;timezone=Etc%2FUTC&amp;studies=%5B%5D&amp;show_popup_button=1&amp;popup_width=1000&amp;popup_height=650"></iframe>
        </div>
        <div id="candleChart" style="display:none;height:360px"></div>
        <div id="chartLegend" class="chart-legend" style="display:none">Sin órdenes pendientes</div>
      </div>
```

- [ ] **Step 3: Add globals**

Change line 637:

```js
let lwChart=null, lwSeries=null, lastCandleTime=0;
```

to:

```js
let lwChart=null, lwSeries=null, lastCandleTime=0, lastOhlc=[];
let orderPriceLines=[], markPriceLine=null;
```

- [ ] **Step 4: Raise chart height and replay cached candles in `initLwChart`**

In `initLwChart`, change `height: 200,` (line 973) to `height: 360,`.

Then, inside the `create()` function, immediately after the candleTooltip creation/append block and before `lwChart.subscribeCrosshairMove(...)` (i.e. right after line 998), add candle replay from cache:

```js
        if(lastOhlc.length){
          try{ lwSeries.setData(lastOhlc); lastCandleTime=lastOhlc[lastOhlc.length-1].time; }catch(e){}
        }
```

- [ ] **Step 5: Cache candles in `fetchMarket`**

In `fetchMarket`, inside the `if(ohlc.length){` block (starts line 1055), add `lastOhlc=ohlc;` as the first statement so the Rápido chart can replay candles when first shown:

```js
    if(ohlc.length){
      lastOhlc=ohlc;
      initLwChart();
```

- [ ] **Step 6: Add `switchChartTab()`**

Immediately after the `switchTab` function (ends line 1159), add:

```js
function switchChartTab(tab){
  const tv=$('tvChartWrap'), fast=$('candleChart'), legend=$('chartLegend');
  const isPro=tab==='pro';
  const pt=$('chartTabPro'), ft=$('chartTabFast');
  if(pt) pt.classList.toggle('active',isPro);
  if(ft) ft.classList.toggle('active',!isPro);
  if(tv) tv.style.display=isPro?'block':'none';
  if(fast) fast.style.display=isPro?'none':'block';
  if(legend) legend.style.display=isPro?'none':'flex';
  if(!isPro){
    initLwChart();
    if(lwChart&&fast) lwChart.applyOptions({width:fast.clientWidth});
  }
}
```

- [ ] **Step 7: Lint and verify**

Run: `php -l src/php/index.php`
Expected: `No syntax errors detected`.

Browser check:
1. On load the "TradingView" tab is active and the BYBIT:ETHUSDT 5m widget renders (full indicators, dark theme).
2. Click "Rápido" — the Lightweight Charts candlestick chart appears ~360px tall, populated with candles (from the `lastOhlc` cache), crosshair + OHLC tooltip work.
3. Click back to "TradingView" and again to "Rápido" — chart persists and resizes correctly on narrow/mobile widths.

- [ ] **Step 8: Commit**

```bash
git add src/php/index.php
git commit -m "feat(charts): add TradingView pro tab and quick-view toggle"
```

---
---

## Task 4: Pending positions as price lines + MARK line + legend

**Files:**
- Modify: `src/php/index.php` — hook in `updateUIFromWebSocket` (line 753), hook in `fetchStatus` (line 944), MARK line in `updateTickerUI` (after line 1244), new `updateOrderLines()` after `updateLadder` (line 1353).

**Interfaces:**
- Consumes: `data.orders` (array of `{level, side, grid_role, price, qty, status, is_recovery}`) from both WS `full` and `_status` fallback; `#lwSeries` and `#chartLegend` from Task 3.
- Produces: global `orderPriceLines` (array), `markPriceLine` (object|null). Function `updateOrderLines(orders)` (no return).

- [ ] **Step 1: Hook into the WebSocket path**

In `updateUIFromWebSocket`, after `if (data.orders) updateLadder(data.orders);` (line 753), add:

```js
    if (data.orders) updateOrderLines(data.orders);
```

- [ ] **Step 2: Hook into the polling fallback**

In `fetchStatus`, after `if(pair.orders) updateLadder(pair.orders);` (line 944), add:

```js
    if(pair.orders) updateOrderLines(pair.orders);
```

- [ ] **Step 3: Add the MARK price line to `updateTickerUI`**

In `updateTickerUI` (line 1219), insert this block between the `if(d.oi){...}` statement (line 1245) and the existing `if(lwSeries && d.price && lastCandleTime > 0){...}` block (line 1246):

```js
  if(lwSeries&&d.price){
    if(!markPriceLine){
      const LS=window.LightweightCharts&&LightweightCharts.LineStyle;
      markPriceLine=lwSeries.createPriceLine({price:d.price,color:'#2d8cff',lineWidth:1,lineStyle:LS?LS.Dashed:2,axisLabelVisible:true,title:'MARK'});
    } else {
      try{markPriceLine.applyOptions({price:d.price});}catch(e){}
    }
  }
```

- [ ] **Step 4: Add `updateOrderLines()`**

Immediately after the `updateLadder` function (ends line 1353), add:

```js
function updateOrderLines(orders){
  if(!lwSeries) return;
  if(orderPriceLines.length){
    orderPriceLines.forEach(l=>{try{lwSeries.removePriceLine(l);}catch(e){}});
    orderPriceLines=[];
  }
  const legend=$('chartLegend');
  if(!orders||!orders.length){
    if(legend) legend.innerHTML='<span style="color:var(--muted)">Sin órdenes pendientes</span>';
    return;
  }
  const LS=window.LightweightCharts&&LightweightCharts.LineStyle;
  for(const o of orders){
    const px=parseFloat(o.price);
    if(!px||px<=0) continue;
    const rec=!!o.is_recovery, exit=o.grid_role==='EXIT';
    const color=rec?'#9b72f5':(exit?'#f5a623':'#00c97a');
    const label=(rec?'R':(exit?'X':'E'))+(o.level!==undefined?o.level:'');
    orderPriceLines.push(lwSeries.createPriceLine({
      price:px,color,lineWidth:1,
      lineStyle:LS?(exit?LS.Dotted:LS.Solid):(exit?1:0),
      axisLabelVisible:true,title:label
    }));
  }
  if(legend){
    const parts=[...orders]
      .sort((a,b)=>parseFloat(b.price)-parseFloat(a.price))
      .map(o=>{
        const rec=!!o.is_recovery, exit=o.grid_role==='EXIT';
        const col=rec?'#9b72f5':(exit?'#f5a623':'#00c97a');
        const lbl=(rec?'R':(exit?'X':'E'))+(o.level!==undefined?o.level:'');
        return '<span style="color:'+col+'">'+lbl+' '+parseFloat(o.price).toFixed(2)+'</span>';
      });
    legend.innerHTML=parts.join(' <span style="color:var(--muted)">·</span> ');
  }
}
```

- [ ] **Step 5: Lint and verify**

Run: `php -l src/php/index.php`
Expected: `No syntax errors detected`.

Browser check:
1. Open the dashboard, switch to "Rápido". For every row in the Order Ladder there is a matching horizontal price line on the chart with an axis label (`E…` green solid, `X…` amber dotted, `R…` purple solid for recovery orders).
2. The legend row below the chart lists the same levels, colored, sorted by price.
3. The blue dashed `MARK` line follows the live price tick.
4. When an order fills and disappears from the ladder, its line and legend entry disappear within one WS update (~3s). With zero open orders, the legend shows "Sin órdenes pendientes" and no stray lines remain.
5. The existing ladder, tooltips, and candle updates still work.

- [ ] **Step 6: Commit**

```bash
git add src/php/index.php
git commit -m "feat(charts): overlay pending order price lines and MARK line"
```

---
---

## Final Verification

After all tasks:

- [ ] Run: `vendor/bin/phpunit` → all green
- [ ] Run: `npm test` → all pass
- [ ] Run: `php -l src/php/index.php src/php/grid_ajax.php src/php/websocket_server.php` → no syntax errors
- [ ] Browser: Pro tab renders TradingView widget; Rápido tab shows candles + pending-order lines + MARK line + legend; Strategy & Estatus panel live-updates (strategy name, mode, bot status, grid, cycle, last AI check)
- [ ] `ss -ltnp | grep 8094` → WS server listening
