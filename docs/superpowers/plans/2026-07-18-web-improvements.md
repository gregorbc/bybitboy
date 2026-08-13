# Web Dashboard Improvements Implementation Plan

**Goal:** Enhance grid bot dashboard with live config panel, wallet/performance metrics, mobile responsiveness, performance optimization, and UX polish.

**Architecture:** All changes target `src/php/index.php` (PHP+CSS+JS) and `src/php/grid_ajax.php` (new action handlers). Config control via `private/grid_control.json`.

**Tech Stack:** PHP 8.3, Chart.js 4.4, Lightweight Charts, vanilla JS, CSS custom properties.

## Global Constraints
- No external dependencies beyond existing (Chart.js, Lightweight Charts)
- All config changes go through `grid_control.json` (bot reads it via `checkControl()`)
- Backward compatible — don't break existing WebSocket/polling fallback

---

### Task 1: Live Config Panel (modal + PHP endpoint)

**Files:**
- Modify: `src/php/index.php`
- Modify: `src/php/grid_ajax.php`

**Interfaces:**
- Consumes: `ctrl` file path from config
- Produces: config update action in grid_ajax, modal HTML in index.php

- [ ] **Step 1: Add PHP action handler in grid_ajax.php**

Add to the action switch in grid_ajax.php:
```php
case 'update_config':
    $ctrlFile = $cfg['paths']['ctrl'] ?? (dirname($cfgFile) . '/grid_control.json');
    $allowed = ['capital_usd', 'leverage', 'levels', 'long_levels', 'short_levels', 'spacing_pct'];
    $updates = [];
    foreach ($allowed as $k) {
        if (isset($_POST[$k])) $updates[$k] = $_POST[$k];
    }
    if (!empty($updates)) {
        $current = file_exists($ctrlFile) ? json_decode(file_get_contents($ctrlFile), true) : [];
        $current['config_update'] = $updates;
        $current['ts'] = time();
        file_put_contents($ctrlFile, json_encode($current));
        echo json_encode(['ok' => true, 'msg' => 'Configuración enviada al bot (próximo ciclo)']);
    } else echo json_encode(['ok' => false, 'msg' => 'Sin cambios']);
    exit;
```

- [ ] **Step 2: Add modal HTML to index.php (before `</body>`)**

```html
<div id="configModal" class="modal-overlay" style="display:none">
  <div class="modal">
    <div class="modal-hd">⚙️ Configuración en Vivo</div>
    <div class="modal-bd">
      <div class="cfg-field"><label>Capital (USDT)</label><input type="number" id="cfgCapital" class="cfg-input" min="10" step="10"></div>
      <div class="cfg-field"><label>Apalancamiento (×)</label><input type="number" id="cfgLeverage" class="cfg-input" min="1" max="100"></div>
      <div class="cfg-field"><label>Niveles totales</label><input type="number" id="cfgLevels" class="cfg-input" min="4" max="50"></div>
      <div class="cfg-row"><div class="cfg-field"><label>Long</label><input type="number" id="cfgLong" class="cfg-input" min="1"></div><div class="cfg-field"><label>Short</label><input type="number" id="cfgShort" class="cfg-input" min="1"></div></div>
      <div class="cfg-field"><label>Spacing (%)</label><input type="number" id="cfgSpacing" class="cfg-input" min="0.01" step="0.005"></div>
    </div>
    <div class="modal-ft">
      <button class="btn" onclick="closeConfig()">Cancelar</button>
      <button class="btn btn-g" onclick="applyConfig()">Aplicar y Reconstruir</button>
    </div>
  </div>
</div>
```

- [ ] **Step 3: Add modal CSS to index.php `:root{}` block**

```css
.modal-overlay{position:fixed;inset:0;z-index:9001;background:rgba(0,0,0,.7);display:grid;place-items:center;padding:16px}
.modal{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);width:100%;max-width:380px;box-shadow:0 8px 40px rgba(0,0,0,.5);animation:modal-in .2s ease}
@keyframes modal-in{from{transform:scale(.95) translateY(10px);opacity:0}to{transform:none;opacity:1}}
.modal-hd{padding:12px 16px;background:var(--bg3);border-bottom:1px solid var(--border);font-size:12px;font-weight:700;color:#fff}
.modal-bd{padding:14px 16px;display:flex;flex-direction:column;gap:10px}
.modal-ft{padding:10px 16px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end}
.cfg-field{display:flex;flex-direction:column;gap:3px}
.cfg-field label{font-size:9px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px}
.cfg-input{background:var(--bg);border:1px solid var(--border2);border-radius:var(--r2);padding:7px 10px;font-family:var(--mono);font-size:12px;color:var(--text);outline:none;width:100%}
.cfg-input:focus{border-color:var(--accent)}
.cfg-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
```

- [ ] **Step 4: Add JS functions to open/close/apply**

```javascript
function openConfig() {
  // populate from current values
  const el = id => document.getElementById(id);
  el('cfgCapital').value = CAPITAL;
  el('cfgLeverage').value = parseInt(document.querySelector('.brand-sub').textContent.match(/(\d+)×/)?.[1] || 100);
  el('cfgLevels').value = parseInt(el('cNiv').textContent || 16);
  el('cfgLong').value = parseInt((el('cLS').textContent || '8/8').split('/')[0]);
  el('cfgShort').value = parseInt((el('cLS').textContent || '8/8').split('/')[1]);
  el('cfgSpacing').value = (parseFloat((el('cSpc').textContent || '0.06').replace('%','')) || 0.06).toFixed(4);
  el('configModal').style.display = 'grid';
}
function closeConfig() { document.getElementById('configModal').style.display = 'none'; }
async function applyConfig() {
  const b = new URLSearchParams({action:'update_config',
    capital_usd: document.getElementById('cfgCapital').value,
    leverage: document.getElementById('cfgLeverage').value,
    levels: document.getElementById('cfgLevels').value,
    long_levels: document.getElementById('cfgLong').value,
    short_levels: document.getElementById('cfgShort').value,
    spacing_pct: (parseFloat(document.getElementById('cfgSpacing').value)/100).toFixed(6)
  });
  const r = await fetch(API, {method:'POST', body: b}).then(x=>x.json());
  toast('Configuración', r.msg || 'Aplicada', r.ok ? 'info' : 'error');
  closeConfig();
  if (r.ok) setTimeout(() => cmd('reset_grid'), 1000);
}
```

- [ ] **Step 5: Add config button to topbar (next to ⚡ Rápido)**

Replace line with config button:
```html
<button class="btn btn-b" onclick="openConfig()">⚙️</button>
```

---

### Task 2: Wallet & Performance Metrics

**Files:**
- Modify: `src/php/index.php`

- [ ] **Step 1: Add wallet section HTML in sidebar-left**

After the gauge card, add:
```html
<div class="card">
  <div class="card-hd"><b>💰 Wallet</b></div>
  <div class="cfg-grid">
    <span class="cfg-k">Balance</span><span class="cfg-v" id="wBalance">--</span>
    <span class="cfg-k">Margen usado</span><span class="cfg-v" id="wMarginUsed">--</span>
    <span class="cfg-k">Margen disp.</span><span class="cfg-v c-neu" id="wMarginFree">--</span>
    <span class="cfg-k">uPnL</span><span class="cfg-v" id="wUpnl">--</span>
    <span class="cfg-k">ROI diario</span><span class="cfg-v" id="wRoiD">--</span>
    <span class="cfg-k">ROI total</span><span class="cfg-v" id="wRoiT">--</span>
    <span class="cfg-k">Proy. 30d</span><span class="cfg-v c-pos" id="wProj">--</span>
    <span class="cfg-k">Fees estim.</span><span class="cfg-v c-dim" id="wFees">--</span>
  </div>
</div>
```

- [ ] **Step 2: Add WS update logic in `updateUIFromWebSocket`**

```javascript
if (data.real_balance !== undefined) {
  const bal = parseFloat(data.real_balance) || 0;
  $('wBalance').textContent = '$' + bal.toFixed(2);
  const used = CAPITAL; // approximate
  $('wMarginUsed').textContent = '$' + used.toFixed(2);
  $('wMarginFree').textContent = '$' + (bal - used).toFixed(2);
}
if (data.total_upnl !== undefined) {
  $('wUpnl').innerHTML = fM(data.total_upnl);
}
// ROI calculation from pair data
if (data.pair && data.pair.pnl_today !== undefined) {
  const pnlD = parseFloat(data.pair.pnl_today) || 0;
  const pnlT = parseFloat(data.pair.pnl_total) || 0;
  const roiD = CAPITAL > 0 ? (pnlD / CAPITAL * 100) : 0;
  const roiT = CAPITAL > 0 ? (pnlT / CAPITAL * 100) : 0;
  $('wRoiD').textContent = roiD.toFixed(2) + '%';
  $('wRoiT').textContent = roiT.toFixed(2) + '%';
  const avgDaily = pnlT > 0 ? pnlT / Math.max(1, ((Date.now() - startTs) / 86400000)) : pnlD;
  $('wProj').innerHTML = fM(avgDaily * 30);
  // fees estimate ~0.02% of notional per trade
  const fillsCount = parseInt($('stFills')?.textContent || '0');
  const avgNotional = 115; // rough average per fill
  const fees = fillsCount * avgNotional * 0.0004; // entry + exit maker
  $('wFees').textContent = '$' + fees.toFixed(2);
}
```

- [ ] **Step 3: Add `startTs` variable at top of JS block**

```javascript
const startTs = Date.now();
```

---

### Task 3: Mobile Responsive + Performance

**Files:**
- Modify: `src/php/index.php`

- [ ] **Step 1: Improve mobile breakpoint CSS**

Replace the `@media(max-width:900px)` block:
```css
@media(max-width:768px){
  .sidebar-right{position:fixed;right:0;top:50px;height:calc(100% - 50px);width:90%;max-width:340px;z-index:160;transform:translateX(100%);box-shadow:-2px 0 12px rgba(0,0,0,.4);transition:transform .25s ease}
  .sidebar-right.open{transform:translateX(0)}
  .sidebar-left{width:85%;max-width:300px}
  .price-live{font-size:16px}
  .ticker-block{gap:4px}
  .bid-ask{display:none}
  .status-block{gap:4px}
  .btns .btn{font-size:9px;padding:3px 6px}
  .btns .btn:nth-child(n+4){display:none}
  .mkt-analysis{grid-template-columns:repeat(2,1fr)}
  .pnl-charts{grid-template-columns:1fr}
  .kpi-grid{gap:4px}
  .kpi-val{font-size:14px}
  .brand-sub{font-size:7px}
  .btn-b{display:inline-flex}
}
@media(max-width:480px){
  .price-live{font-size:14px}
  .kpi-grid{grid-template-columns:1fr 1fr}
  .topbar{padding:0 6px;gap:4px}
  .tb-sep{display:none}
  .btns .btn{font-size:8px;padding:2px 5px}
  .btns .btn:nth-child(n+3){display:none}
  .live-pill{font-size:9px;padding:2px 7px}
}
```

- [ ] **Step 2: Add debounce utility**

```javascript
function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }
```

- [ ] **Step 3: Lazy chart rendering**

Wrap chart updates in visibility check:
```javascript
function renderIfVisible(chartId, renderFn) {
  const el = document.getElementById(chartId);
  if (!el) return;
  const rect = el.getBoundingClientRect();
  if (rect.top < window.innerHeight && rect.bottom > 0) renderFn();
}
```

- [ ] **Step 4: Virtual scroll for logs**

Modify `appendLogsFromWS` to keep max 100 lines:
```javascript
function appendLogsFromWS(logLines) {
  if (!logLines || !logLines.length) return;
  logLines.forEach(l => { allLogLines.push(l); });
  if (allLogLines.length > 500) allLogLines = allLogLines.slice(-500);
  // render only last 100 visible
  const lb = $('logBox');
  if (!lb || logPaused) return;
  const toRender = allLogLines.slice(-100);
  lb.innerHTML = toRender.map(l => `<div class="ll"><span class="lt">${l.t||''}</span><span class="${l.l||'li'}">${l.l||'[I]'}</span><span class="lm">${l.m||''}</span></div>`).join('');
  lb.scrollTop = lb.scrollHeight;
}
```

---

### Task 4: Visual / UX Polish

**Files:**
- Modify: `src/php/index.php`

- [ ] **Step 1: Gradient KPI accents**

Add to existing KPI styles:
```css
.kpi::after{content:'';position:absolute;bottom:0;left:0;right:0;height:40%;background:linear-gradient(transparent,var(--bg3));pointer-events:none}
```

- [ ] **Step 2: Better candle chart tooltips**

Modify lightweight charts config (in the chart setup code):
```javascript
// In the section where lwChart/lwSeries is created, after line ~1250 area
// The existing chart already uses lightweight-charts with decent tooltips
```

- [ ] **Step 3: Enhanced toasts with price**

Modify toast function to include optional price:
```javascript
function toast(title, msg, type, price) {
  const t = document.createElement('div');
  t.className = `toast ${type||'info'}${type==='fill_pos'?' fill-pos':''}${type==='fill_neg'?' fill-neg':''}`;
  t.innerHTML = `<div class="toast-icon">${type==='fill_pos'?'✅':type==='fill_neg'?'⚠️':'ℹ️'}</div><div class="toast-body"><div class="toast-title">${title}</div><div class="toast-msg">${msg}${price ? ' · $'+parseFloat(price).toFixed(2) : ''}</div></div><button class="toast-close" onclick="this.closest('.toast').remove()">✕</button>`;
  document.getElementById('toasts').appendChild(t);
  setTimeout(() => { t.classList.add('out'); setTimeout(() => t.remove(), 300); }, 5000);
}
```

- [ ] **Step 4: Order ladder gradient depth**

Modify ladder rendering to use opacity based on qty:
```javascript
// In updateLadder function, modify the bar width/opacity:
const maxQty = Math.max(...(data.map(o => Math.abs(parseFloat(o.qty) || 0)).filter(x => x > 0)), 0.01);
// Then for each bar, opacity = qty/maxQty
```

---

### Task 5: Execution

- [ ] **Step 1: Apply all changes to index.php**

Apply each edit described above to `/home/erika/web/binance.gregorbritez.cat/public_html/src/php/index.php`

- [ ] **Step 2: Add update_config action to grid_ajax.php**

Add the PHP handler from Task 1 to `/home/erika/web/binance.gregorbritez.cat/public_html/src/php/grid_ajax.php`

- [ ] **Step 3: Verify PHP syntax**

```bash
php -l /home/erika/web/binance.gregorbritez.cat/public_html/src/php/index.php
php -l /home/erika/web/binance.gregorbritez.cat/public_html/src/php/grid_ajax.php
```

- [ ] **Step 4: Verify in browser**

Access `https://binance.gregorbritez.cat/src/php/index.php` and test:
- Config modal opens/closes
- Wallet section shows data
- Layout responsive on mobile width
- No console errors
