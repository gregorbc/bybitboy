# Dashboard UX Improvements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve dashboard UX with integrated CSS design system, Chart.js tooltips, responsive touch targets, skeleton loading, stale data indicator, and WebSocket heartbeat with reconnection backoff.

**Architecture:** Link 3 existing external CSS files (540 lines) into index.php while preserving inline variable compatibility; add Chart.js tooltip plugin config to all charts; add lightweight-charts custom tooltip overlay; improve WS server with heartbeat and symbol filter fixes; add stale detection and exponential backoff in client.

**Tech Stack:** PHP 8.x, Chart.js 3.x, lightweight-charts, Ratchet WebSocket, CSS custom properties

## Global Constraints

- All CSS external files already exist at `src/php/assets/css/` — no new CSS files
- Inline CSS variables (`--bg`, `--green`, `--red`, etc.) must remain defined for backward compatibility with inline style attributes
- No HTML structure changes — only CSS/JSPHP additions
- All PHP files must pass `php -l` syntax check

---

### Task 1: WebSocket Server — Symbol Filters + Heartbeat

**Files:**
- Modify: `src/php/websocket_server.php:224-237` (setLoop broadcast)
- Modify: `src/php/websocket_server.php:336-352` (getRecentFills)
- Modify: `src/php/websocket_server.php:372-378` (getPnlHourly)

**Interfaces:**
- Consumes: Existing `collectData()`, `setLoop()` methods
- Produces: Heartbeat messages `{"type":"heartbeat","ts":<int>}` every 5s; corrected SQL queries with `AND symbol='ETHUSDT'`

- [ ] **Step 1: Fix symbol filters in getRecentFills**

Replace line 346:
```php
$stmt = $db->prepare("SELECT symbol, side, grid_role, price, qty, pnl_usd, filled_at, is_recovery FROM grid_orders WHERE status='FILLED' ORDER BY filled_at DESC LIMIT $limit");
```
With:
```php
$stmt = $db->prepare("SELECT symbol, side, grid_role, price, qty, pnl_usd, filled_at, is_recovery FROM grid_orders WHERE symbol='ETHUSDT' AND status='FILLED' ORDER BY filled_at DESC LIMIT $limit");
```

- [ ] **Step 2: Fix symbol filter in getPnlHourly**

Replace line 376:
```php
return $db->query("SELECT DATE(filled_at) d, HOUR(filled_at) h, ROUND(SUM(pnl_usd),6) p FROM grid_orders WHERE grid_role='EXIT' AND status='FILLED' AND filled_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR) GROUP BY DATE(filled_at), HOUR(filled_at) ORDER BY d, h")->fetchAll();
```
With:
```php
return $db->query("SELECT DATE(filled_at) d, HOUR(filled_at) h, ROUND(SUM(pnl_usd),6) p FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' AND filled_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR) GROUP BY DATE(filled_at), HOUR(filled_at) ORDER BY d, h")->fetchAll();
```

- [ ] **Step 3: Add heartbeat to setLoop**

Modify `setLoop()` timer callback to send heartbeat every 5 ticks. Add a counter to the class:

Add property after line 173:
```php
private $heartbeatTick = 0;
```

Modify the timer at lines 224-237:
```php
public function setLoop($loop) {
    $this->loop = $loop;
    $loop->addPeriodicTimer(1, function () {
        $this->heartbeatTick++;
        $data = $this->collectData();
        $json = json_encode($data);
        foreach ($this->clients as $client) {
            try {
                $client->send($json);
            } catch (Exception $e) {
                echo "Error sending to client: " . $e->getMessage() . "\n";
                $this->clients->detach($client);
                $client->close();
            }
        }
        // Heartbeat every 5 seconds
        if ($this->heartbeatTick % 5 === 0) {
            $hb = json_encode(['type' => 'heartbeat', 'ts' => (int)(microtime(true) * 1000)]);
            foreach ($this->clients as $client) {
                try {
                    $client->send($hb);
                } catch (Exception $e) {}
            }
        }
    });
}
```

- [ ] **Step 4: Verify PHP syntax**

Run:
```bash
php -l src/php/websocket_server.php
```
Expected: No syntax errors detected

- [ ] **Step 5: Commit**

```bash
git add src/php/websocket_server.php
git commit -m "fix(ws): add symbol filters and heartbeat"
```

---

### Task 2: Link External CSS + Trim Inline Styles

**Files:**
- Modify: `src/php/index.php` (lines 99-108 head section; lines 110-350 style block)

**Interfaces:**
- Consumes: Existing `assets/css/design-system.css`, `layout.css`, `components.css`
- Produces: Cleaner HTML head with external CSS links and reduced inline style block

- [ ] **Step 1: Add CSS links in head**

After line ~99 (`<meta name="viewport"...>`) in index.php, before `<style>`:
```html
<link rel="stylesheet" href="assets/css/design-system.css">
<link rel="stylesheet" href="assets/css/layout.css">
<link rel="stylesheet" href="assets/css/components.css">
```

- [ ] **Step 2: Remove duplicate CSS from inline style block**

The inline `<style>` block (lines 110-350) contains many rules now duplicated in the external files. Remove these duplicate sections:
- The `*` reset block (external: design-system.css lines 1-7)
- `::-webkit-scrollbar` styles (external: design-system.css lines 58-61)
- Basic body/html styles (external: design-system.css lines 41-53)
- `.btn` styles (external: components.css lines 75-93)
- `.badge*` styles (external: components.css lines 95-106)
- `.data-table` styles (external: components.css lines 22-48)
- `.panel-tab*` styles (external: components.css lines 51-72)
- `.gauge*` styles (external: components.css lines 109-121)
- `.ladder*` styles (external: components.css lines 124-139)
- `.log*` styles (external: components.css lines 142-154)
- `.skeleton` / shimmer animation (external: components.css lines 157-167)
- `.empty-state` (external: components.css lines 169-173)
- `.cfg-input` (external: components.css lines 230-235)
- `.toast` styles (external: components.css lines 252-257)
- `.sidebar-right*` styles (external: layout.css lines 144-169, components.css lines 192-217)

Keep in inline block:
- CSS variables (`:root { --bg:... }`) — needed for inline `style=""` compatibility
- Any custom unique styles not in external files
- The `@keyframes toastIn` animation (unique)
- Overrides specific to this dashboard that differ from external CSS

- [ ] **Step 3: Verify PHP syntax**

Run:
```bash
php -l src/php/index.php
```
Expected: No syntax errors detected

- [ ] **Step 4: Visual verification after loading in browser**

Navigate to the dashboard URL and confirm:
- Layout renders correctly (KPI grid, charts, sidebars)
- No broken styles or white text on white background

- [ ] **Step 5: Commit**

```bash
git add src/php/index.php
git commit -m "feat(css): link external design system, trim inline styles"
```

---

### Task 3: Chart.js Tooltips + Candle Chart Tooltip Overlay

**Files:**
- Modify: `src/php/index.php` (chartDef function ~lines 1121-1128; candle chart creation ~lines ~1050-1110)

**Interfaces:**
- Consumes: Existing `chartDef(id, type, labels, data, opts)` helper
- Produces: Charts with tooltip showing formatted PnL values with +/− sign and 4 decimal precision

- [ ] **Step 1: Add tooltip plugin config to chartDef**

Modify `chartDef()` at ~lines 1121-1128:

Replace the options object to add tooltip plugin:

```javascript
function chartDef(id,type,labels,data,opts){
  const ctx=$(id)?.getContext('2d');
  if(!ctx) return null;
  return new Chart(ctx,{
    type,
    data:{labels,datasets:[{...opts,data}]},
    options:{
      responsive:true,
      maintainAspectRatio:false,
      animation:{duration:400},
      plugins:{
        legend:{display:false},
        tooltip:{
          enabled:true,
          backgroundColor:'rgba(6,8,14,.92)',
          titleColor:'#c8daf0',
          bodyColor:'#7a99bb',
          borderColor:'#1a2535',
          borderWidth:1,
          padding:8,
          cornerRadius:6,
          displayColors:false,
          callbacks:{
            label:ctx=>ctx.parsed.y>=0?'+'+ctx.parsed.y.toFixed(4)+' USDT':ctx.parsed.y.toFixed(4)+' USDT'
          }
        }
      },
      scales:{
        x:{ticks:{color:'#3a5270',font:{size:7}},grid:{color:'rgba(26,37,53,.4)'}},
        y:{ticks:{color:'#3a5270',font:{size:7}},grid:{color:'rgba(26,37,53,.4)'}}
      }
    }
  });
}
```

- [ ] **Step 2: Add custom HTML tooltip overlay for lightweight charts**

Find where lwChart is created (~lines 1050-1110). After the chart creation, add:

```javascript
// Candle tooltip overlay
const candleTooltip = document.createElement('div');
candleTooltip.id = 'candleTooltip';
candleTooltip.style.cssText = 'position:absolute;display:none;background:rgba(6,8,14,.92);border:1px solid #1a2535;border-radius:6px;padding:8px 10px;font-family:var(--mono);font-size:10px;z-index:10;pointer-events:none;white-space:nowrap';
chartContainer.appendChild(candleTooltip);

lwChart.subscribeCrosshairMove(param => {
  if (!param.time || !param.point) { candleTooltip.style.display = 'none'; return; }
  const data = param.seriesData.get(lwSeries);
  if (!data) { candleTooltip.style.display = 'none'; return; }
  const o = data.open.toFixed(2);
  const h = data.high.toFixed(2);
  const l = data.low.toFixed(2);
  const c = data.close.toFixed(2);
  const color = data.close >= data.open ? '#00c97a' : '#f03c52';
  candleTooltip.style.display = 'block';
  candleTooltip.innerHTML = `<span style="color:${color}">O ${o}</span> · <span style="color:${color}">H ${h}</span> · <span style="color:${color}">L ${l}</span> · <span style="color:${color}">C ${c}</span>`;
  const rect = chartContainer.getBoundingClientRect();
  let left = param.point.x + 12;
  let top = param.point.y - 20;
  if (left + candleTooltip.offsetWidth > rect.width - 5) left = param.point.x - candleTooltip.offsetWidth - 12;
  if (top < 5) top = 5;
  candleTooltip.style.left = left + 'px';
  candleTooltip.style.top = top + 'px';
});
```

- [ ] **Step 3: Verify PHP syntax**

Run:
```bash
php -l src/php/index.php
```
Expected: No syntax errors detected

- [ ] **Step 4: Commit**

```bash
git add src/php/index.php
git commit -m "feat(charts): add Chart.js tooltips and candle tooltip overlay"
```

---

### Task 4: UX Improvements — Touch Targets, Skeleton, Stale, Sidebars, WS Client

**Files:**
- Modify: `src/php/index.php` (CSS inline additions; JS skeleton/stale/WS heartbeat handling)

**Interfaces:**
- Consumes: WS heartbeat from Task 1; external CSS `.skeleton` class from Task 2
- Produces: Touch-friendly buttons, skeleton loading state, stale indicator, sidebar overlay unification, WS reconnection backoff

- [ ] **Step 1: Add touch target CSS and skeleton/stale styles**

Add to the inline `<style>` block (after the retained variables):

```css
/* Touch targets */
.fills-pg button, .sidebar-right-tab-btn, .navbar-action-btn {
  min-height:44px;
}
/* Stale state */
body.stale #app {opacity:.6;transition:opacity .8s;}
/* Skeleton for KPI loading */
.skel{background:linear-gradient(90deg,var(--bg3) 25%,var(--bg2) 50%,var(--bg3) 75%);background-size:200% 100%;animation:skShimmer 1.5s infinite;border-radius:6px;}
@keyframes skShimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
```

- [ ] **Step 2: Add skeleton loading replacement in HTML**

Find the KPI values in the HTML (e.g., `id="kPnlH"`). After the page loads but before WS connects, replace `--` with skeleton spans. Add this JS after `hideLdr()`:

```javascript
// Skeleton loading for KPIs
['kPnlH','kPnlT','kWin','kUpt','wBalance','stFills'].forEach(id => {
  const el = $(id);
  if (el && el.textContent === '--') el.innerHTML = '<span class="skel" style="display:inline-block;width:60px;height:14px">&nbsp;</span>';
});
```

- [ ] **Step 3: Add stale data detection in WS message handler**

In `updateUIFromWebSocket()`, add at the beginning:

```javascript
// Reset stale on any data
document.body.classList.remove('stale');
```

Add a stale timer:
```javascript
let staleTimer = null;
function markStale() { document.body.classList.add('stale'); }
```

In `connectWebSocket()`, add after ws.onopen:
```javascript
if (staleTimer) clearTimeout(staleTimer);
staleTimer = setTimeout(markStale, 10000);
```

In `updateUIFromWebSocket()`, add at the beginning:
```javascript
if (staleTimer) clearTimeout(staleTimer);
staleTimer = setTimeout(markStale, 10000);
```

- [ ] **Step 4: Add WS reconnection backoff**

Modify `connectWebSocket()` to use exponential backoff. Add a variable:

After `let wsReconnectTimer = null;` add:
```javascript
let wsReconnectDelay = 1000;
```

Modify the ws.onclose handler:
```javascript
ws.onclose = () => {
    console.log('[WS] Desconectado, reconectando en ' + (wsReconnectDelay/1000) + 's...');
    const ind = $('wsIndicator');
    if (ind) ind.style.background = 'var(--muted)';
    wsReconnectTimer = setTimeout(() => {
        connectWebSocket();
        wsReconnectDelay = Math.min(wsReconnectDelay * 2, 15000);
    }, wsReconnectDelay);
};
```

In ws.onopen, reset the delay:
```javascript
ws.onopen = () => {
    wsReconnectDelay = 1000; // Reset backoff
    // ... rest of handler
};
```

- [ ] **Step 5: Handle heartbeat in WS message handler**

In the `ws.onmessage` callback, add heartbeat handling. Find the existing `JSON.parse` block and add:

```javascript
// Heartbeat
if (data.type === 'heartbeat') {
    if (staleTimer) clearTimeout(staleTimer);
    staleTimer = setTimeout(markStale, 12000);
    return;
}
```

This goes before `updateUIFromWebSocket(data);` so heartbeats don't trigger full UI updates.

- [ ] **Step 6: Verify PHP syntax**

Run:
```bash
php -l src/php/index.php
```
Expected: No syntax errors detected

- [ ] **Step 7: Commit**

```bash
git add src/php/index.php
git commit -m "feat(ux): touch targets, skeleton loading, stale indicator, WS backoff"
```

---

### Task 5: Final Verification

- [ ] **Step 1: Verify PHP syntax on all modified files**

Run:
```bash
php -l src/php/index.php && php -l src/php/websocket_server.php
```
Expected: No syntax errors detected in both files

- [ ] **Step 2: Verify WebSocket server starts**

Run:
```bash
cd src/php && timeout 3 php websocket_server.php 2>&1 || true
```
Expected: Output includes "Grid Bot WebSocket Server" and "Escuchando en puerto 8094"

- [ ] **Step 3: Final commit of any remaining files**

```bash
git status
```

If all clean, add the plan file:
```bash
git add docs/superpowers/plans/2026-07-30-dashboard-ux-improvements.md
git add docs/superpowers/specs/2026-07-30-dashboard-ux-improvements-design.md
git commit -m "docs: add UX improvements spec and implementation plan"
```

- [ ] **Step 4: Summary**

```bash
git log --oneline -5
```
