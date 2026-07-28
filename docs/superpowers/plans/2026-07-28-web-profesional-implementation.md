# Professional Web Dashboard — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refactor existing monolithic index.php into Vite-powered frontend with Bybit-inspired design system, extracted CSS/JS components.

**Architecture:** Vite builds CSS/JS to dist/, index.php becomes HTML shell loading compiled assets. Backend (grid_ajax.php, websocket_server.php) unchanged. JS components receive data via CustomEvents from websocket.js (WS) or api.js (HTTP fallback).

**Tech Stack:** Vite 5+, vanilla JS (no framework), Chart.js 4.4 (CDN), Lightweight Charts 4.1 (CDN), CSS custom properties, Inter + JetBrains Mono fonts.

## Global Constraints

- PHP backend files must NOT be modified (grid_ajax.php, websocket_server.php, bot.php, Strategy/*)
- index.php must remain at src/php/index.php (no routing changes)
- Vite output goes to src/php/dist/
- All tokens from design system spec are exact
- No new external JS dependencies beyond what already exists (Chart.js CDN, Lightweight Charts CDN)
- Existing WebSocket JSON payload format must be preserved
- Existing AJAX endpoint names must be preserved (_status, _ticker, _market, etc.)

---

### Task 1: Scaffold Vite + Directory Structure

**Files:**
- Create: `src/php/package.json`
- Create: `src/php/vite.config.js`
- Create: `src/php/assets/js/main.js` (placeholder)
- Create: `src/php/assets/css/`  (directory)
- Create: `src/php/assets/js/utils/` (directory)
- Create: `src/php/assets/js/components/` (directory)

- [ ] **Step 1: Create package.json**

```json
{
  "name": "grid-bot-dashboard",
  "private": true,
  "type": "module",
  "scripts": {
    "dev": "vite",
    "build": "vite build",
    "preview": "vite preview"
  },
  "devDependencies": {
    "vite": "^6.0.0"
  }
}
```

- [ ] **Step 2: Create vite.config.js**

```js
import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  root: '.',
  base: '/src/php/dist/',
  build: {
    outDir: 'dist',
    manifest: true,
    rollupOptions: {
      input: resolve(__dirname, 'assets/js/main.js'),
    },
  },
});
```

- [ ] **Step 3: Create placeholder main.js**

```js
console.log('[Dashboard] v2 initialized');
```

- [ ] **Step 4: Create asset directories**

Run:
```bash
mkdir -p assets/css assets/js/utils assets/js/components
```

- [ ] **Step 5: Create .gitignore entry for dist**

Append to `.gitignore`:
```
src/php/dist/
```

- [ ] **Step 6: Install deps and verify build**

Run:
```bash
cd src/php && npm install && npx vite build 2>&1 | tail -5
```
Expected: "✓ built in Xms" with output written to `dist/`.

- [ ] **Step 7: Commit**

```bash
git add src/php/package.json src/php/vite.config.js src/php/assets/js/main.js src/php/.gitignore
git commit -m "feat(web): scaffold Vite build system"
```

---

### Task 2: Design System CSS

**Files:**
- Create: `src/php/assets/css/design-system.css`

- [ ] **Step 1: Write design-system.css**

```css
*,
*::before,
*::after {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

:root {
  --bg-primary:   #0a0e17;
  --bg-secondary: #111827;
  --bg-tertiary:  #1a2333;
  --bg-elevated:  #1e293b;
  --border:       #1e3a5f;
  --border-hover: #2d5a8e;
  --text-primary:   #f1f5f9;
  --text-secondary: #94a3b8;
  --text-muted:     #64748b;
  --accent:        #0ea5e9;
  --accent-hover:  #38bdf8;
  --accent-dim:    #0c4a6e;
  --green:  #22c55e;
  --red:    #ef4444;
  --yellow: #eab308;
  --font-ui:   'Inter', sans-serif;
  --font-mono: 'JetBrains Mono', monospace;
  --space-xs: 4px;  --space-sm: 8px;
  --space-md: 16px; --space-lg: 24px;
  --space-xl: 32px; --space-2xl: 48px;
  --radius-sm: 4px; --radius-md: 8px; --radius-lg: 12px;
}

html {
  font-size: 14px;
  scroll-behavior: smooth;
}

body {
  font-family: var(--font-ui);
  background: var(--bg-primary);
  color: var(--text-primary);
  line-height: 1.5;
  min-height: 100vh;
  -webkit-font-smoothing: antialiased;
}

a { color: var(--accent); text-decoration: none; }
a:hover { color: var(--accent-hover); }

::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: var(--bg-tertiary); }
::-webkit-scrollbar-thumb { background: var(--accent-dim); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--accent); }

::selection { background: var(--accent-dim); color: var(--text-primary); }
```

- [ ] **Step 2: Create layout.css**

```css
.app-container {
  max-width: 1440px;
  margin: 0 auto;
  padding: 0 var(--space-md);
}

/* Top Navbar */
.navbar {
  display: flex;
  align-items: center;
  height: 56px;
  padding: 0 var(--space-lg);
  background: var(--bg-secondary);
  border-bottom: 1px solid var(--border);
  position: sticky;
  top: 0;
  z-index: 100;
}

.navbar-brand {
  font-size: 1.15rem;
  font-weight: 700;
  color: var(--accent);
  margin-right: var(--space-xl);
  white-space: nowrap;
}

.navbar-tabs {
  display: flex;
  gap: var(--space-xs);
  list-style: none;
}

.navbar-tab {
  padding: var(--space-sm) var(--space-md);
  color: var(--text-muted);
  font-size: 0.9rem;
  font-weight: 500;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  transition: color 0.15s, border-color 0.15s;
  user-select: none;
}

.navbar-tab:hover { color: var(--text-secondary); }
.navbar-tab.active { color: var(--accent); border-bottom-color: var(--accent); }

.navbar-hamburger {
  display: none;
  background: none;
  border: none;
  color: var(--text-primary);
  font-size: 1.5rem;
  cursor: pointer;
  margin-left: auto;
}

/* KPI Row */
.kpi-row {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--space-md);
  margin: var(--space-lg) 0;
}

/* Main grid */
.main-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-md);
  margin-bottom: var(--space-lg);
}

/* Full width sections */
.full-width {
  margin-bottom: var(--space-lg);
}

/* Bottom two-column */
.bottom-grid {
  display: grid;
  grid-template-columns: 1.2fr 1fr;
  gap: var(--space-md);
  margin-bottom: var(--space-xl);
}

/* Card base */
.card {
  background: var(--bg-secondary);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  padding: var(--space-lg);
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: var(--space-md);
}

.card-title {
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

/* Mobile */
@media (max-width: 768px) {
  .kpi-row { grid-template-columns: repeat(2, 1fr); }
  .main-grid { grid-template-columns: 1fr; }
  .bottom-grid { grid-template-columns: 1fr; }
  .navbar-tabs { display: none; }
  .navbar-tabs.open { display: flex; flex-direction: column; position: absolute; top: 56px; left: 0; right: 0; background: var(--bg-secondary); border-bottom: 1px solid var(--border); padding: var(--space-sm); }
  .navbar-hamburger { display: block; }
}

@media (max-width: 480px) {
  .kpi-row { grid-template-columns: 1fr; }
  .app-container { padding: 0 var(--space-sm); }
}
```

- [ ] **Step 3: Create components.css**

```css
/* ─── KPI Cards ─── */
.kpi-card-value {
  font-family: var(--font-mono);
  font-size: 1.4rem;
  font-weight: 600;
  line-height: 1.2;
}

.kpi-card-value.green { color: var(--green); }
.kpi-card-value.red { color: var(--red); }
.kpi-card-value.accent { color: var(--accent); }

.kpi-card-label {
  font-size: 0.75rem;
  color: var(--text-muted);
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

/* ─── Tables ─── */
.data-table {
  width: 100%;
  border-collapse: collapse;
}

.data-table th {
  text-align: left;
  padding: var(--space-sm) var(--space-md);
  font-size: 0.7rem;
  font-weight: 600;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  border-bottom: 1px solid var(--border);
}

.data-table td {
  padding: var(--space-sm) var(--space-md);
  font-family: var(--font-mono);
  font-size: 0.85rem;
  border-bottom: 1px solid var(--border);
}

.data-table tr:hover td { background: var(--bg-tertiary); }
.data-table td.num { text-align: right; }
.data-table td.green { color: var(--green); }
.data-table td.red { color: var(--red); }

/* ─── Nav Tabs (panel switching) ─── */
.panel-tabs {
  display: flex;
  gap: 0;
  border-bottom: 1px solid var(--border);
  margin-bottom: var(--space-md);
}

.panel-tab {
  padding: var(--space-sm) var(--space-md);
  color: var(--text-muted);
  font-size: 0.85rem;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  transition: color 0.15s, border-color 0.15s;
  user-select: none;
}

.panel-tab:hover { color: var(--text-secondary); }
.panel-tab.active { color: var(--accent); border-bottom-color: var(--accent); }

.panel-content { display: none; }
.panel-content.active { display: block; }

/* ─── Buttons ─── */
.btn {
  display: inline-flex;
  align-items: center;
  gap: var(--space-xs);
  padding: var(--space-sm) var(--space-md);
  border: none;
  border-radius: var(--radius-md);
  font-family: var(--font-ui);
  font-size: 0.85rem;
  font-weight: 500;
  cursor: pointer;
  transition: background 0.15s, opacity 0.15s;
}

.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: var(--accent-hover); }
.btn-danger { background: var(--red); color: #fff; }
.btn-danger:hover { opacity: 0.85; }

/* ─── Badges ─── */
.badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
}

.badge-green { background: rgba(34,197,94,0.15); color: var(--green); }
.badge-red { background: rgba(239,68,68,0.15); color: var(--red); }
.badge-accent { background: rgba(14,165,233,0.15); color: var(--accent); }

/* ─── Gauge ─── */
.gauge-container {
  display: flex;
  flex-direction: column;
  align-items: center;
}

.gauge-svg { width: 120px; height: 80px; }

.gauge-label {
  font-size: 0.75rem;
  color: var(--text-muted);
  margin-top: var(--space-sm);
}

/* ─── Order Ladder ─── */
.ladder-row {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  padding: 3px 0;
  font-family: var(--font-mono);
  font-size: 0.8rem;
}

.ladder-row .side { width: 40px; font-weight: 600; }
.ladder-row .price { width: 80px; text-align: right; }
.ladder-row .qty { width: 80px; text-align: right; color: var(--text-secondary); }
.ladder-row .bar { flex: 1; height: 16px; border-radius: 3px; transition: width 0.3s; }
.ladder-row .bar.buy { background: rgba(34,197,94,0.25); }
.ladder-row .bar.sell { background: rgba(239,68,68,0.25); }
.ladder-row .bar.entry { background: rgba(14,165,233,0.3); }

/* ─── Log Viewer ─── */
.log-viewer {
  max-height: 400px;
  overflow-y: auto;
  font-family: var(--font-mono);
  font-size: 0.75rem;
  line-height: 1.6;
}

.log-line { padding: 2px 0; }
.log-line .ts { color: var(--text-muted); }
.log-line .level-info { color: var(--accent); }
.log-line .level-warn { color: var(--yellow); }
.log-line .level-error { color: var(--red); }

/* ─── Loading / Empty States ─── */
.skeleton {
  background: linear-gradient(90deg, var(--bg-tertiary) 25%, var(--bg-secondary) 50%, var(--bg-tertiary) 75%);
  background-size: 200% 100%;
  animation: shimmer 1.5s infinite;
  border-radius: var(--radius-sm);
}

@keyframes shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

.empty-state {
  text-align: center;
  padding: var(--space-xl);
  color: var(--text-muted);
}

/* ─── Flash animation for price changes ─── */
.price-flash-up { animation: flashGreen 0.6s ease-out; }
.price-flash-down { animation: flashRed 0.6s ease-out; }

@keyframes flashGreen {
  0% { color: var(--green); }
  100% { color: var(--text-primary); }
}

@keyframes flashRed {
  0% { color: var(--red); }
  100% { color: var(--text-primary); }
}
```

- [ ] **Step 4: Create CSS entry point (assets/css/style.css)**

```css
@import './design-system.css';
@import './layout.css';
@import './components.css';
```

- [ ] **Step 5: Build and verify no errors**

Run: `cd src/php && npx vite build 2>&1 | grep -i error || echo "No errors"`

- [ ] **Step 6: Commit**

```bash
git add src/php/assets/css/
git commit -m "feat(web): design system CSS with Bybit-inspired tokens"
```

---

### Task 3: JS Utilities (dom.js, format.js, api.js)

**Files:**
- Create: `src/php/assets/js/utils/dom.js`
- Create: `src/php/assets/js/utils/format.js`
- Create: `src/php/assets/js/api.js`

- [ ] **Step 1: Write dom.js**

```js
/**
 * Lightweight DOM helpers
 */
export const $ = (sel, ctx = document) => ctx.querySelector(sel);
export const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

export function el(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'className') node.className = v;
    else if (k === 'textContent') node.textContent = v;
    else if (k === 'innerHTML') node.innerHTML = v;
    else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
    else node.setAttribute(k, v);
  }
  for (const c of children) {
    if (typeof c === 'string') node.appendChild(document.createTextNode(c));
    else node.appendChild(c);
  }
  return node;
}

export function clear(el) { el.innerHTML = ''; }

export function show(el) { el.style.display = ''; }
export function hide(el) { el.style.display = 'none'; }

export function flashPrice(el, isUp) {
  el.classList.remove('price-flash-up', 'price-flash-down');
  void el.offsetWidth; // reflow
  el.classList.add(isUp ? 'price-flash-up' : 'price-flash-down');
}
```

- [ ] **Step 2: Write format.js**

```js
export function fmtPrice(v, decimals = 2) {
  const n = parseFloat(v);
  return isNaN(n) ? '—' : n.toFixed(decimals);
}

export function fmtPct(v) {
  const n = parseFloat(v);
  if (isNaN(n)) return '—';
  const s = n >= 0 ? '+' : '';
  return `${s}${n.toFixed(2)}%`;
}

export function fmtCurrency(v) {
  const n = parseFloat(v);
  return isNaN(n) ? '—' : n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function fmtTime(ts) {
  if (!ts) return '—';
  const d = typeof ts === 'number' ? new Date(ts * 1000) : new Date(ts);
  return d.toLocaleTimeString('en-US', { hour12: false });
}

export function fmtDuration(seconds) {
  if (!seconds) return '—';
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  return `${h}h ${m}m ${s}s`;
}

export function classForPct(v) {
  const n = parseFloat(v);
  if (isNaN(n)) return '';
  if (n > 0) return 'green';
  if (n < 0) return 'red';
  return '';
}
```

- [ ] **Step 3: Write api.js**

```js
const AJAX_URL = 'grid_ajax.php';

export async function api(endpoint, params = {}) {
  params._action = endpoint;
  const qs = new URLSearchParams(params).toString();
  const resp = await fetch(`${AJAX_URL}?${qs}`, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  if (!resp.ok) throw new Error(`API ${endpoint}: ${resp.status}`);
  return resp.json();
}

export async function apiPost(endpoint, data = {}) {
  data._action = endpoint;
  const resp = await fetch(AJAX_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
    body: new URLSearchParams(data).toString(),
  });
  if (!resp.ok) throw new Error(`API POST ${endpoint}: ${resp.status}`);
  return resp.json();
}
```

- [ ] **Step 4: Commit**

```bash
git add src/php/assets/js/utils/ src/php/assets/js/api.js
git commit -m "feat(web): JS utilities and API client"
```

---

### Task 4: WebSocket Event Dispatcher

**Files:**
- Create: `src/php/assets/js/websocket.js`

- [ ] **Step 1: Write websocket.js**

```js
/*
  WebSocket connection manager.
  Connects to ws://HOST:8082, dispatches CustomEvents on window.
  Falls back to HTTP polling if connection fails.
*/

const WS_PORT = 8082;
const POLL_INTERVAL = 2000;
const RECONNECT_DELAY = 3000;

let ws = null;
let pollTimer = null;
let isPolling = false;

function getWsUrl() {
  const proto = location.protocol === 'https:' ? 'wss' : 'ws';
  return `${proto}://${location.hostname}:${WS_PORT}`;
}

function dispatch(type, data) {
  window.dispatchEvent(new CustomEvent(type, { detail: data }));
}

function onWsMessage(event) {
  try {
    const d = JSON.parse(event.data);
    if (d.type === 'full') {
      // Map flat WS payload to typed events
      if (d.ticker) dispatch('data:ticker', d.ticker);
      dispatch('data:grid', { orders: d.orders || [], mode: d.mode, open_orders: d.open_orders });
      dispatch('data:kpi', {
        pnl_today:    d.pair?.pnl_today || 0,
        pnl_total:    d.pair?.pnl_total || 0,
        win_rate:     d.win_rate || 0,
        uptime_sec:   d.uptime || 0,
        total_upnl:   d.total_upnl || 0,
        real_balance: d.real_balance || 0,
        maker_fee:    d.makerFee || 0.0001,
        taker_fee:    d.takerFee || 0.0006,
      });
      dispatch('data:ai', {
        direction:   d.pair?.direction || 'SIDEWAYS',
        confidence:  d.pair?.confidence || 0,
        reason:      d.pair?.ai_reason || '',
        next_eval:   d.pair?.last_ai_check || null,
      });
      if (d.ticker) dispatch('data:market', {
        rsi:       d.pair?.rsi || null,
        macd:      d.pair?.macd || null,
        adx:       d.pair?.adx || null,
        atr:       d.ticker?.atr_pct || null,
        bollinger: d.pair?.bollinger_pct || null,
        ema9:      d.ticker?.ema9 || null,
        ema21:     d.ticker?.ema21 || null,
        ema50:     d.ticker?.ema50 || null,
        funding:   d.ticker?.fundRate || 0,
        oi:        d.ticker?.oi || 0,
      });
      if (d.positions) dispatch('data:positions', d.positions);
      if (d.recent_fills) dispatch('data:fills', d.recent_fills);
      if (d.logs) dispatch('data:logs', d.logs);
      if (d.pnl_hourly || d.pnl_cumulative) {
        dispatch('data:pnl', {
          hourly:     d.pnl_hourly || [],
          daily:      d.pnl_daily || [],
          cumulative: d.pnl_cumulative || [],
        });
      }
    }
  } catch (_) { /* ignore malformed */ }
}

function connectWs() {
  try {
    ws = new WebSocket(getWsUrl());
    ws.onopen = () => { dispatch('ws:status', { connected: true }); stopPolling(); };
    ws.onclose = () => { dispatch('ws:status', { connected: false }); ws = null; startPolling(); setTimeout(connectWs, RECONNECT_DELAY); };
    ws.onerror = () => { ws = null; };
    ws.onmessage = onWsMessage;
  } catch (_) {
    ws = null;
    startPolling();
  }
}

async function pollAll() {
  try {
    const { default: { api } } = await import('./api.js');
    const d = await api('_status');
    // Same mapping as WS onWsMessage
    if (d.ticker) dispatch('data:ticker', d.ticker);
    dispatch('data:grid', { orders: d.orders || [], mode: d.mode, open_orders: d.open_orders });
    dispatch('data:kpi', {
      pnl_today: d.pair?.pnl_today || 0, pnl_total: d.pair?.pnl_total || 0,
      win_rate: d.win_rate || 0, uptime_sec: d.uptime || 0,
    });
    dispatch('data:ai', {
      direction: d.pair?.direction || 'SIDEWAYS', confidence: d.pair?.confidence || 0,
      reason: d.pair?.ai_reason || '', next_eval: d.pair?.last_ai_check || null,
    });
    if (d.positions) dispatch('data:positions', d.positions);
    if (d.recent_fills) dispatch('data:fills', d.recent_fills);
    if (d.logs) dispatch('data:logs', d.logs);
  } catch (_) { /* silent */ }
}

function startPolling() {
  if (isPolling) return;
  isPolling = true;
  pollAll();
  pollTimer = setInterval(pollAll, POLL_INTERVAL);
}

function stopPolling() {
  isPolling = false;
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}

export function initWs() {
  connectWs();
}

export function closeWs() {
  if (ws) { ws.close(); ws = null; }
  stopPolling();
}
```

- [ ] **Step 2: Commit**

```bash
git add src/php/assets/js/websocket.js
git commit -m "feat(web): WebSocket event dispatcher with polling fallback"
```

---

### Task 5: Core Components (Ticker, KPI Cards, AI Gauge, Market Analysis)

**Files:**
- Create: `src/php/assets/js/components/ticker.js`
- Create: `src/php/assets/js/components/kpi-cards.js`
- Create: `src/php/assets/js/components/ai-gauge.js`
- Create: `src/php/assets/js/components/market.js`
- Modify: `src/php/assets/js/main.js`

- [ ] **Step 1: Write ticker.js**

```js
import { $, flashPrice } from '../utils/dom.js';
import { fmtPrice, fmtPct } from '../utils/format.js';

export function initTicker() {
  const el = $('#ticker-price');
  const elChange = $('#ticker-change');
  if (!el) return;

  window.addEventListener('data:ticker', (e) => {
    const { price, change24h } = e.detail;
    const old = el.textContent;
    const newPrice = `$${fmtPrice(price, 2)}`;
    if (old && old !== newPrice) {
      flashPrice(el, parseFloat(change24h) >= 0);
    }
    el.textContent = newPrice;
    if (elChange && change24h !== undefined) {
      const cls = parseFloat(change24h) >= 0 ? 'green' : 'red';
      elChange.textContent = fmtPct(change24h);
      elChange.className = `kpi-card-value ${cls}`;
    }
  });
}
```

- [ ] **Step 2: Write kpi-cards.js**

```js
import { $ } from '../utils/dom.js';
import { fmtCurrency, fmtPct, fmtDuration } from '../utils/format.js';

const FIELDS = {
  '#kpi-pnl-today': { key: 'pnl_today', fmt: fmtCurrency, cls: true },
  '#kpi-pnl-total': { key: 'pnl_total', fmt: fmtCurrency, cls: true },
  '#kpi-win-rate': { key: 'win_rate', fmt: (v) => `${parseFloat(v).toFixed(1)}%`, cls: false },
  '#kpi-uptime': { key: 'uptime_sec', fmt: fmtDuration, cls: false },
};

export function initKpiCards() {
  window.addEventListener('data:kpi', (e) => {
    const data = e.detail;
    for (const [sel, cfg] of Object.entries(FIELDS)) {
      const el = $(sel);
      if (!el) continue;
      const val = data[cfg.key];
      if (val === undefined) continue;
      el.textContent = cfg.fmt(val);
      if (cfg.cls) {
        const n = parseFloat(val);
        el.className = 'kpi-card-value' + (n > 0 ? ' green' : n < 0 ? ' red' : '');
      }
    }
  });
}
```

- [ ] **Step 3: Write ai-gauge.js**

```js
import { $ } from '../utils/dom.js';

export function initAiGauge() {
  const el = $('#ai-gauge');
  const elDirection = $('#ai-direction');
  const elConfidence = $('#ai-confidence');
  const elReason = $('#ai-reason');
  const elNextEval = $('#ai-next-eval');
  if (!el) return;

  window.addEventListener('data:ai', (e) => {
    const { direction, confidence, reason, next_eval } = e.detail;
    if (elDirection) {
      elDirection.textContent = direction || '—';
      const cls = direction === 'UP' ? 'green' : direction === 'DOWN' ? 'red' : 'accent';
      elDirection.className = `badge badge-${cls}`;
    }
    if (elConfidence) elConfidence.textContent = confidence != null ? `${confidence}%` : '—';
    if (elReason) elReason.textContent = reason || '';
    if (elNextEval) elNextEval.textContent = next_eval != null ? `${next_eval}s` : '—';
  });
}
```

- [ ] **Step 4: Write market.js**

```js
import { $, $$ } from '../utils/dom.js';
import { fmtPrice, fmtPct } from '../utils/format.js';

const FIELDS = [
  '#market-rsi', '#market-macd', '#market-adx',
  '#market-atr', '#market-bollinger', '#market-ema9',
  '#market-ema21', '#market-ema50', '#market-funding',
  '#market-oi',
];

export function initMarket() {
  window.addEventListener('data:market', (e) => {
    const data = e.detail;
    for (const sel of FIELDS) {
      const el = $(sel);
      if (!el) continue;
      const key = sel.replace('#market-', '').replace(/-/g, '_');
      const val = data[key];
      if (val === undefined) continue;
      el.textContent = typeof val === 'number' ? fmtPrice(val, 2) : val;
    }
  });
}
```

- [ ] **Step 5: Update main.js to initialize core components**

```js
import '../css/style.css';
import { initWs } from './websocket.js';
import { initTicker } from './components/ticker.js';
import { initKpiCards } from './components/kpi-cards.js';
import { initAiGauge } from './components/ai-gauge.js';
import { initMarket } from './components/market.js';

document.addEventListener('DOMContentLoaded', () => {
  initTicker();
  initKpiCards();
  initAiGauge();
  initMarket();
  initWs();
});
```

- [ ] **Step 6: Build and verify**

Run: `cd src/php && npx vite build 2>&1 | grep -i error || echo "Build OK"`

- [ ] **Step 7: Commit**

```bash
git add src/php/assets/js/components/ticker.js src/php/assets/js/components/kpi-cards.js src/php/assets/js/components/ai-gauge.js src/php/assets/js/components/market.js src/php/assets/js/main.js
git commit -m "feat(web): core dashboard components"
```

---

### Task 6: Order Ladder Component

**Files:**
- Create: `src/php/assets/js/components/grid-ladder.js`
- Modify: `src/php/assets/js/main.js`

- [ ] **Step 1: Write grid-ladder.js**

```js
import { $, clear } from '../utils/dom.js';
import { fmtPrice } from '../utils/format.js';

export function initGridLadder() {
  const container = $('#order-ladder');
  if (!container) return;

  window.addEventListener('data:grid', (e) => {
    const { orders, levels, spacing_pct } = e.detail;
    if (!orders || !orders.length) {
      container.innerHTML = '<div class="empty-state">No hay órdenes activas</div>';
      return;
    }
    clear(container);

    const header = document.createElement('div');
    header.className = 'ladder-row';
    header.style.fontWeight = '600';
    header.style.color = 'var(--text-muted)';
    header.style.fontSize = '0.7rem';
    header.style.textTransform = 'uppercase';
    header.innerHTML = '<span class="side">Side</span><span class="price">Price</span><span class="qty">Qty</span><span class="bar" style="flex:1;">Volume</span>';
    container.appendChild(header);

    const maxQty = Math.max(...orders.map(o => parseFloat(o.qty || 0)), 1);

    for (const o of orders) {
      const row = document.createElement('div');
      row.className = 'ladder-row';

      const side = document.createElement('span');
      side.className = 'side';
      side.textContent = o.side === 'Sell' ? 'SELL' : 'BUY';
      side.style.color = o.side === 'Sell' ? 'var(--red)' : 'var(--green)';

      const price = document.createElement('span');
      price.className = 'price';
      price.textContent = fmtPrice(o.price, 2);

      const qty = document.createElement('span');
      qty.className = 'qty';
      qty.textContent = o.qty || '—';

      const bar = document.createElement('div');
      bar.className = `bar ${o.side === 'Sell' ? 'sell' : 'buy'}`;
      const pct = (parseFloat(o.qty || 0) / maxQty) * 100;
      bar.style.width = `${Math.max(pct, 2)}%`;

      row.appendChild(side);
      row.appendChild(price);
      row.appendChild(qty);
      row.appendChild(bar);
      container.appendChild(row);
    }
  });
}
```

- [ ] **Step 2: Add initGridLadder to main.js**

```js
import { initGridLadder } from './components/grid-ladder.js';

document.addEventListener('DOMContentLoaded', () => {
  // ... existing inits ...
  initGridLadder();
});
```

- [ ] **Step 3: Build and commit**

```bash
git add src/php/assets/js/components/grid-ladder.js src/php/assets/js/main.js
git commit -m "feat(web): order ladder component"
```

---

### Task 7: Charts Component (PnL + Candlestick)

**Files:**
- Create: `src/php/assets/js/components/charts.js`
- Modify: `src/php/assets/js/main.js`

- [ ] **Step 1: Write charts.js**

```js
import { $, clear } from '../utils/dom.js';
import { fmtCurrency, fmtTime } from '../utils/format.js';

let pnlHourlyChart = null;
let pnlDailyChart = null;
let pnlCumulativeChart = null;
let candleChart = null;

export function initCharts() {
  initPnLCharts();
  initCandleChart();
  attachDataListeners();
}

function initPnLCharts() {
  const hourlyCtx = $('#chart-pnl-hourly');
  const dailyCtx = $('#chart-pnl-daily');
  const cumulativeCtx = $('#chart-pnl-cumulative');

  if (hourlyCtx && typeof Chart !== 'undefined') {
    pnlHourlyChart = new Chart(hourlyCtx, {
      type: 'bar',
      data: { labels: [], datasets: [{ label: 'PnL Hourly', data: [], backgroundColor: [], borderRadius: 4 }] },
      options: { responsive: true, plugins: { legend: { display: false } }, scales: { x: { grid: { color: '#1e3a5f' } }, y: { grid: { color: '#1e3a5f' } } } },
    });
  }
  if (dailyCtx && typeof Chart !== 'undefined') {
    pnlDailyChart = new Chart(dailyCtx, {
      type: 'bar',
      data: { labels: [], datasets: [{ label: 'PnL Daily', data: [], backgroundColor: [], borderRadius: 4 }] },
      options: { responsive: true, plugins: { legend: { display: false } }, scales: { x: { grid: { color: '#1e3a5f' } }, y: { grid: { color: '#1e3a5f' } } } },
    });
  }
  if (cumulativeCtx && typeof Chart !== 'undefined') {
    pnlCumulativeChart = new Chart(cumulativeCtx, {
      type: 'line',
      data: { labels: [], datasets: [{ label: 'Cumulative PnL', data: [], borderColor: '#0ea5e9', backgroundColor: 'rgba(14,165,233,0.1)', fill: true, tension: 0.3, pointRadius: 2 }] },
      options: { responsive: true, plugins: { legend: { display: false } }, scales: { x: { grid: { color: '#1e3a5f' } }, y: { grid: { color: '#1e3a5f' } } } },
    });
  }
}

function initCandleChart() {
  const container = $('#candle-chart');
  if (!container || typeof window.LightweightCharts === 'undefined') return;
  candleChart = window.LightweightCharts.createChart(container, {
    layout: { background: { color: 'transparent' }, textColor: '#94a3b8' },
    grid: { vertLines: { color: '#1e3a5f' }, horzLines: { color: '#1e3a5f' } },
    width: container.clientWidth,
    height: 300,
  });
  const candleSeries = candleChart.addCandlestickSeries({ upColor: '#22c55e', downColor: '#ef4444', borderVisible: false, wickUpColor: '#22c55e', wickDownColor: '#ef4444' });
  candleChart._series = candleSeries; // store ref

  window.addEventListener('data:candles', (e) => {
    const candles = e.detail;
    if (candles && candles.length) {
      candleSeries.setData(candles.map(c => ({ time: c.time || c.t, open: c.open || c.o, high: c.high || c.h, low: c.low || c.l, close: c.close || c.c })));
    }
  });
}

function attachDataListeners() {
  window.addEventListener('data:pnl', (e) => {
    const { hourly, daily, cumulative } = e.detail;
    if (pnlHourlyChart && hourly) updateBarChart(pnlHourlyChart, hourly);
    if (pnlDailyChart && daily) updateBarChart(pnlDailyChart, daily);
    if (pnlCumulativeChart && cumulative) updateLineChart(pnlCumulativeChart, cumulative);
  });
}

function updateBarChart(chart, data) {
  chart.data.labels = data.map(d => d.label || '');
  chart.data.datasets[0].data = data.map(d => d.value);
  chart.data.datasets[0].backgroundColor = data.map(d => d.value >= 0 ? 'rgba(34,197,94,0.5)' : 'rgba(239,68,68,0.5)');
  chart.update();
}

function updateLineChart(chart, data) {
  chart.data.labels = data.map(d => d.label || '');
  chart.data.datasets[0].data = data.map(d => d.value);
  chart.update();
}

// Resize handler for candle chart
window.addEventListener('resize', () => {
  if (candleChart) {
    const container = $('#candle-chart');
    if (container) candleChart.resize(container.clientWidth, 300);
  }
});
```

- [ ] **Step 2: Add initCharts to main.js**

```js
import { initCharts } from './components/charts.js';

document.addEventListener('DOMContentLoaded', () => {
  // ... existing inits ...
  initCharts();
});
```

- [ ] **Step 3: Build and commit**

```bash
git add src/php/assets/js/components/charts.js src/php/assets/js/main.js
git commit -m "feat(web): PnL and candlestick chart components"
```

---

### Task 8: Tabbed Panel (Positions, Fills, ML, Logs) + Log Viewer

**Files:**
- Create: `src/php/assets/js/components/tabbed-panel.js`
- Create: `src/php/assets/js/components/log-viewer.js`
- Modify: `src/php/assets/js/main.js`

- [ ] **Step 1: Write tabbed-panel.js**

```js
import { $, $$, clear } from '../utils/dom.js';
import { fmtCurrency, fmtPct, fmtPrice, fmtTime } from '../utils/format.js';

export function initTabbedPanel() {
  const tabs = $$('.panel-tab');
  const panels = $$('.panel-content');

  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      tabs.forEach(t => t.classList.remove('active'));
      panels.forEach(p => p.classList.remove('active'));
      tab.classList.add('active');
      const target = document.getElementById(tab.dataset.panel);
      if (target) target.classList.add('active');
    });
  });

  // Activate first tab
  if (tabs.length) tabs[0].click();

  // Data listeners
  window.addEventListener('data:positions', (e) => {
    const tbody = $('#positions-body');
    if (!tbody) return;
    clear(tbody);
    const positions = e.detail;
    if (!positions || !positions.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="empty-state">Sin posiciones abiertas</td></tr>';
      return;
    }
    for (const p of positions) {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${p.side || '—'}</td>
        <td class="num">${fmtPrice(p.qty, 4)}</td>
        <td class="num">${fmtPrice(p.entry_price, 2)}</td>
        <td class="num ${parseFloat(p.uPnL) >= 0 ? 'green' : 'red'}">${fmtCurrency(p.uPnL)}</td>
        <td class="num">${fmtPrice(p.liq_price, 2)}</td>`;
      tbody.appendChild(tr);
    }
  });

  window.addEventListener('data:fills', (e) => {
    const tbody = $('#fills-body');
    if (!tbody) return;
    clear(tbody);
    const fills = e.detail;
    if (!fills || !fills.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="empty-state">Sin fills registrados</td></tr>';
      return;
    }
    for (const f of fills) {
      const tr = document.createElement('tr');
      const pnl = parseFloat(f.pnl || 0);
      tr.innerHTML = `
        <td>${fmtTime(f.time)}</td>
        <td>${f.side || '—'}</td>
        <td>${f.role || '—'}</td>
        <td class="num ${pnl >= 0 ? 'green' : 'red'}">${fmtCurrency(pnl)}</td>
        <td class="num">${fmtPrice(f.price, 2)}</td>
        <td>${f.recovery ? '🔄' : ''}</td>`;
      tbody.appendChild(tr);
    }
  });

  window.addEventListener('data:ml', (e) => {
    const container = $('#ml-info');
    if (!container) return;
    const data = e.detail;
    if (!data || !data.features) {
      container.innerHTML = '<div class="empty-state">No hay datos ML</div>';
      return;
    }
    clear(container);
    for (const feat of data.features) {
      const row = document.createElement('div');
      row.style.cssText = 'display:flex;align-items:center;gap:var(--space-sm);margin:4px 0;font-size:0.8rem;';
      const barOuter = document.createElement('div');
      barOuter.style.cssText = 'flex:1;height:12px;background:var(--bg-tertiary);border-radius:4px;overflow:hidden;';
      const barInner = document.createElement('div');
      barInner.style.cssText = `height:100%;width:${Math.abs(parseFloat(feat.importance || 0) * 100)}%;background:var(--accent);border-radius:4px;transition:width 0.3s;`;
      barOuter.appendChild(barInner);
      row.innerHTML = `<span style="width:120px;">${feat.name || '—'}</span>`;
      row.appendChild(barOuter);
      row.innerHTML += `<span style="width:40px;text-align:right;font-family:var(--font-mono);">${(parseFloat(feat.importance || 0) * 100).toFixed(1)}%</span>`;
      container.appendChild(row);
    }
  });
}
```

- [ ] **Step 2: Write log-viewer.js**

```js
import { $, clear } from '../utils/dom.js';

const MAX_LINES = 200;

export function initLogViewer() {
  const container = $('#log-viewer');
  if (!container) return;

  window.addEventListener('data:logs', (e) => {
    const lines = e.detail;
    if (!lines || !lines.length) return;

    for (const line of lines) {
      const div = document.createElement('div');
      div.className = 'log-line';

      const ts = document.createElement('span');
      ts.className = 'ts';
      ts.textContent = `[${line.time || ''}] `;

      const level = (line.level || 'info').toLowerCase();
      const msg = document.createElement('span');
      msg.className = `level-${level}`;
      msg.textContent = line.message || '';

      div.appendChild(ts);
      div.appendChild(msg);
      container.appendChild(div);
    }

    // Trim
    while (container.children.length > MAX_LINES) {
      container.removeChild(container.firstChild);
    }

    // Auto-scroll
    container.scrollTop = container.scrollHeight;
  });
}
```

- [ ] **Step 3: Add to main.js**

```js
import { initTabbedPanel } from './components/tabbed-panel.js';
import { initLogViewer } from './components/log-viewer.js';

document.addEventListener('DOMContentLoaded', () => {
  // ... existing inits ...
  initTabbedPanel();
  initLogViewer();
});
```

- [ ] **Step 4: Build and commit**

```bash
git add src/php/assets/js/components/tabbed-panel.js src/php/assets/js/components/log-viewer.js src/php/assets/js/main.js
git commit -m "feat(web): tabbed panel and log viewer components"
```

---

### Task 9: Rewrite index.php as HTML Shell

**Files:**
- Modify: `src/php/index.php`

- [ ] **Step 1: Write the new index.php**

```php
<?php
declare(strict_types=1);

/**
 * Grid Bot Dashboard v2
 * HTML shell — Vite compiles CSS/JS to dist/
 */

// Load existing PHP bootstrap (config, data providers)
require_once __DIR__ . '/vendor/autoload.php';

use BinanceBot\Core\Config;
use BinanceBot\Core\Database;
use BinanceBot\Core\Logger;

$cfg = Config::getInstance()->getAll();
$init = [];

try {
    $db = Database::getInstance();
    // Load initial data (same as before — PnL, fills, open orders, etc.)
    $init['pnl_today']     = $db->fetchOne("SELECT SUM(pnl) FROM grid_orders WHERE DATE(closed_at) = CURDATE() AND pnl IS NOT NULL") ?: 0;
    $init['fills_total']   = $db->fetchOne("SELECT COUNT(*) FROM grid_orders WHERE pnl IS NOT NULL") ?: 0;
    $init['open_orders']   = $db->fetchOne("SELECT COUNT(*) FROM grid_orders WHERE status = 'open'") ?: 0;
    $init['direction']     = $cfg['bot']['direction'] ?? 'SIDEWAYS';
    $init['confidence']    = $cfg['ml']['min_confidence'] ?? 50;
    $init['ai_reason']     = 'Grid initialized';
    $init['levels']        = $cfg['grid']['levels'] ?? 16;
    $init['long_levels']   = $cfg['grid']['long_levels'] ?? 8;
    $init['short_levels']  = $cfg['grid']['short_levels'] ?? 8;
    $init['spacing_pct']   = $cfg['grid']['base_spacing'] * 100 ?? 0.03;
    $init['recovery_active'] = false;
    $init['capital']       = $cfg['bot']['capital_usd'] ?? 100;
    $init['ml_accuracy']   = $cfg['ml']['min_accuracy'] ?? 0.85;
    $init['maker_fee']     = $cfg['fees']['maker'] ?? 0.0001;
    $init['taker_fee']     = $cfg['fees']['taker'] ?? 0.0006;
} catch (\Throwable $e) {
    Logger::warn("[Dashboard] DB init: " . $e->getMessage());
}

// Read Vite manifest for hashed assets
$manifestPath = __DIR__ . '/dist/.vite/manifest.json';
$jsFile  = 'assets/js/main.js';
$cssFile = 'assets/js/main.css'; // Vite default CSS entry name
if (file_exists($manifestPath)) {
    $manifest = json_decode(file_get_contents($manifestPath), true);
    if (isset($manifest['assets/js/main.js'])) {
        $jsFile  = $manifest['assets/js/main.js']['file'];
        $cssFile = $manifest['assets/js/main.js']['css'][0] ?? $cssFile;
    }
}

?><!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Grid Bot Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script src="https://unpkg.com/lightweight-charts@4.1.1/dist/lightweight-charts.standalone.production.js"></script>
  <link rel="stylesheet" href="dist/<?= htmlspecialchars($cssFile) ?>">
</head>
<body>
  <div id="app">
    <!-- Navbar -->
    <nav class="navbar">
      <span class="navbar-brand">⬡ Grid Bot</span>
      <ul class="navbar-tabs" id="nav-tabs">
        <li class="navbar-tab active" data-section="dashboard">Dashboard</li>
        <li class="navbar-tab" data-section="positions">Positions</li>
        <li class="navbar-tab" data-section="ml">ML</li>
        <li class="navbar-tab" data-section="logs">Logs</li>
      </ul>
      <button class="navbar-hamburger" id="hamburger">☰</button>
    </nav>

    <div class="app-container">
      <!-- Ticker + KPI Row -->
      <div class="kpi-row">
        <div class="card">
          <div class="card-title">ETHUSDT</div>
          <div class="kpi-card-value accent" id="ticker-price">—</div>
          <div class="kpi-card-value" id="ticker-change">—</div>
        </div>
        <div class="card">
          <div class="card-title">PnL Hoy</div>
          <div class="kpi-card-value" id="kpi-pnl-today">—</div>
        </div>
        <div class="card">
          <div class="card-title">Win Rate</div>
          <div class="kpi-card-value" id="kpi-win-rate">—</div>
        </div>
        <div class="card">
          <div class="card-title">Uptime</div>
          <div class="kpi-card-value" id="kpi-uptime">—</div>
        </div>
      </div>

      <!-- AI Gauge + Market Analysis -->
      <div class="main-grid">
        <div class="card" id="ai-gauge">
          <div class="card-header">
            <span class="card-title">AI Signal</span>
            <span class="badge badge-accent" id="ai-direction">—</span>
          </div>
          <div class="gauge-container">
            <svg class="gauge-svg" viewBox="0 0 120 80">
              <path d="M10 70 A50 50 0 0 1 110 70" fill="none" stroke="#1e3a5f" stroke-width="8" stroke-linecap="round"/>
              <path id="gauge-arc" d="M10 70 A50 50 0 0 1 110 70" fill="none" stroke="#0ea5e9" stroke-width="8" stroke-linecap="round" stroke-dasharray="157 157" stroke-dashoffset="78" style="transition: stroke-dashoffset 0.5s;"/>
            </svg>
            <div style="font-size:1.5rem;font-weight:700;font-family:var(--font-mono);margin-top:-8px;" id="ai-confidence">—</div>
            <div class="gauge-label" id="ai-reason"></div>
            <div class="gauge-label">Próxima eval: <span id="ai-next-eval">—</span></div>
          </div>
        </div>
        <div class="card">
          <div class="card-title" style="margin-bottom:var(--space-md);">Market Analysis</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-sm);font-size:0.85rem;">
            <div>RSI: <span id="market-rsi" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
            <div>MACD: <span id="market-macd" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
            <div>ADX: <span id="market-adx" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
            <div>ATR%: <span id="market-atr" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
            <div>Boll %B: <span id="market-bollinger" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
            <div>EMA 9: <span id="market-ema9" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
            <div>EMA 21: <span id="market-ema21" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
            <div>EMA 50: <span id="market-ema50" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
            <div>Funding: <span id="market-funding" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
            <div>OI: <span id="market-oi" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
          </div>
        </div>
      </div>

      <!-- Order Ladder -->
      <div class="full-width">
        <div class="card">
          <div class="card-header">
            <span class="card-title">Order Ladder</span>
          </div>
          <div id="order-ladder">
            <div class="empty-state">Cargando...</div>
          </div>
        </div>
      </div>

      <!-- Bottom: PnL Charts + Tabbed Panel -->
      <div class="bottom-grid">
        <div class="card">
          <div class="card-title" style="margin-bottom:var(--space-md);">PnL Charts</div>
          <div style="display:grid;gap:var(--space-md);">
            <canvas id="chart-pnl-hourly" height="120"></canvas>
            <canvas id="chart-pnl-daily" height="120"></canvas>
            <canvas id="chart-pnl-cumulative" height="120"></canvas>
            <div id="candle-chart" style="height:300px;"></div>
          </div>
        </div>

        <div class="card">
          <div class="panel-tabs">
            <div class="panel-tab active" data-panel="panel-positions">Positions</div>
            <div class="panel-tab" data-panel="panel-fills">Fills</div>
            <div class="panel-tab" data-panel="panel-ml">ML</div>
            <div class="panel-tab" data-panel="panel-logs">Log</div>
          </div>

          <div class="panel-content active" id="panel-positions">
            <table class="data-table">
              <thead><tr><th>Side</th><th>Qty</th><th>Entry</th><th>uPnL</th><th>Liq</th></tr></thead>
              <tbody id="positions-body"><tr><td colspan="5" class="empty-state">Sin posiciones</td></tr></tbody>
            </table>
          </div>

          <div class="panel-content" id="panel-fills">
            <table class="data-table">
              <thead><tr><th>Time</th><th>Side</th><th>Role</th><th>PnL</th><th>Price</th><th>Rec</th></tr></thead>
              <tbody id="fills-body"><tr><td colspan="6" class="empty-state">Sin fills</td></tr></tbody>
            </table>
          </div>

          <div class="panel-content" id="panel-ml">
            <div id="ml-info"><div class="empty-state">Esperando datos ML...</div></div>
          </div>

          <div class="panel-content" id="panel-logs">
            <div class="log-viewer" id="log-viewer"><div class="empty-state">Esperando logs...</div></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Pass PHP init data to JS
    window.__INIT__ = <?= json_encode($init) ?>;
  </script>
  <script type="module" src="dist/<?= htmlspecialchars($jsFile) ?>"></script>
</body>
</html>
```

- [ ] **Step 2: Commit**

```bash
git add src/php/index.php
git commit -m "feat(web): rewrite index.php as Vite HTML shell"
```

---

### Task 10: Vite Build + Production Verification

**Files:** (build output only)

- [ ] **Step 1: Production build**

Run: `cd src/php && npx vite build`

Expected output:
```
✓ built in Xms
  dist/assets/main-XXXXXX.js   XX KB
  dist/assets/main-XXXXXX.css  XX KB
```

- [ ] **Step 2: Verify manifest**

Run: `cat src/php/dist/.vite/manifest.json | head -30`
Expected: Contains entry for `assets/js/main.js` with hashed file and css array.

- [ ] **Step 3: Verify PHP loads assets correctly**

Run: `php -r "require 'src/php/index.php';" 2>&1 | head -5`
Expected: HTML output with `<link>` and `<script>` tags pointing to hashed dist files.

- [ ] **Step 4: Test WebSocket connection**

Ensure websocket_server.php is running. Open browser dev tools to verify:
- WS connects (no errors)
- CustomEvents fire (check Event Listeners tab)
- Components update DOM with data

- [ ] **Step 5: Test responsive breakpoints**

Resize browser to <768px and <480px widths. Verify:
- KPI grid collapses correctly
- Navbar shows hamburger
- Layout becomes single column

- [ ] **Step 6: Final commit**

```bash
git add src/php/dist/
git commit -m "build(web): production build"
```

---

## Summary of Files

| File | Action |
|---|---|
| `src/php/package.json` | Create |
| `src/php/vite.config.js` | Create |
| `src/php/assets/js/main.js` | Create |
| `src/php/assets/css/style.css` | Create |
| `src/php/assets/css/design-system.css` | Create |
| `src/php/assets/css/layout.css` | Create |
| `src/php/assets/css/components.css` | Create |
| `src/php/assets/js/utils/dom.js` | Create |
| `src/php/assets/js/utils/format.js` | Create |
| `src/php/assets/js/api.js` | Create |
| `src/php/assets/js/websocket.js` | Create |
| `src/php/assets/js/components/ticker.js` | Create |
| `src/php/assets/js/components/kpi-cards.js` | Create |
| `src/php/assets/js/components/ai-gauge.js` | Create |
| `src/php/assets/js/components/market.js` | Create |
| `src/php/assets/js/components/grid-ladder.js` | Create |
| `src/php/assets/js/components/charts.js` | Create |
| `src/php/assets/js/components/tabbed-panel.js` | Create |
| `src/php/assets/js/components/log-viewer.js` | Create |
| `src/php/index.php` | Modify |
| `.gitignore` | Modify (append `src/php/dist/`) |
