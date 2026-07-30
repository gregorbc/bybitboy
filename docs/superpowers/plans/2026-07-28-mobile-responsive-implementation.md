# Mobile Responsive Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the bot dashboard fully functional on phones (375–430px) and tablets (768–1024px) while preserving desktop layout.

**Architecture:** CSS-only responsive layer via media queries at 3 breakpoints + JS toggle for right panel overlay on mobile. Inline styles in `index.php` extracted to CSS classes. No changes to PHP business logic or JS data flow.

**Tech Stack:** Vanilla CSS (custom properties, media queries), plain JS for sidebar toggle, Vite for build.

**Spec:** `docs/superpowers/specs/2026-07-28-mobile-responsive-implementation.md`

## Global Constraints

- All touch targets ≥44×44px on mobile
- Only icon-only navbar buttons on <768px (no text labels)
- Right panel becomes fullscreen overlay with slide-in animation on <1024px
- Left drawer becomes 100vw on <480px
- No changes to PHP logic, WebSocket handling, or data flow
- Vite build must succeed after changes

---

### Task 1: CSS responsive foundation — design-system + layout

**Files:**
- Modify: `src/php/assets/css/design-system.css`
- Modify: `src/php/assets/css/layout.css`

**Interfaces:**
- Consumes: existing CSS custom properties and class names
- Produces: responsive CSS rules consumed by index.php HTML

- [ ] **Step 1: Add mobile touch-target and font-size tokens to design-system.css**

Add after `--radius-sm/--radius-lg` block:

```css
:root {
  /* ... existing vars ... */
  --touch-min: 44px;
  --nav-h: 56px;
  --font-xs: 0.65rem;
  --font-sm: 0.75rem;
  --font-base: 0.85rem;
  --font-lg: 1rem;
  --font-xl: 1.15rem;
  --sidebar-w: 300px;
}

@media (max-width: 768px) {
  :root { --nav-h: 48px; }
}
```

- [ ] **Step 2: Add full responsive rules to layout.css**

Replace existing content (after `.card-title` block) with comprehensive responsive:

```css
/* ─── Mobile ─── */
@media (max-width: 1023px) {
  .sidebar-right { position: fixed; inset: 0; width: 100%; z-index: 200; transform: translateX(100%); transition: transform 0.3s ease; }
  .sidebar-right.open { transform: translateX(0); }
  .sidebar-right-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 199; display: none; }
  .sidebar-right-overlay.open { display: block; }
}

@media (max-width: 768px) {
  :root { --nav-h: 48px; }
  .kpi-row { grid-template-columns: repeat(2, 1fr); }
  .main-grid { grid-template-columns: 1fr; }
  .bottom-grid { grid-template-columns: 1fr; }
  .navbar-tabs { display: none; }
  .navbar-tabs.open { display: flex; flex-direction: column; position: absolute; top: var(--nav-h); left: 0; right: 0; background: var(--bg-secondary); border-bottom: 1px solid var(--border); padding: var(--space-sm); z-index: 99; }
  .navbar-hamburger { display: block; }
  .navbar { height: var(--nav-h); padding: 0 var(--space-md); }
  .navbar-actions { gap: 2px; }
  .navbar-actions .btn { padding: 4px 6px; font-size: 14px; min-width: var(--touch-min); min-height: var(--touch-min); display: flex; align-items: center; justify-content: center; }
  .nav-chip-group { gap: 4px; flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .nav-chip { font-size: var(--font-xs); padding: 2px 6px; white-space: nowrap; }
  .nav-chip .chip-label { display: none; }
  .card { padding: var(--space-md); }
  .bottom-grid .card { padding: var(--space-md); }
  .sidebar-left { width: 280px; }
  .market-grid { grid-template-columns: repeat(2, 1fr); gap: var(--space-sm); }
  .pnl-charts-wrap { flex-direction: column; }
  .pnl-charts-wrap > div { width: 100% !important; border-left: none !important; }
  .pnl-charts-wrap > div:first-child { border-bottom: 1px solid var(--border); padding-bottom: var(--space-sm); margin-bottom: var(--space-sm); }
}

@media (max-width: 480px) {
  :root { --nav-h: 44px; }
  .kpi-row { grid-template-columns: 1fr; }
  .kpi-card-value { font-size: 1.1rem; }
  .app-container { padding: 0 var(--space-sm); }
  .sidebar-left { width: 100vw; }
  .navbar-brand { font-size: 0.85rem; margin-right: var(--space-sm); }
  .navbar-actions .btn { padding: 3px 4px; font-size: 12px; min-width: 36px; min-height: var(--touch-min); }
  .nav-chip-group > *:nth-child(n+5) { display: none; }
  .card { padding: var(--space-sm); border-radius: var(--radius-md); }
  .card-title { font-size: 0.7rem; }
  .data-table th, .data-table td { padding: var(--space-xs) var(--space-sm); font-size: 0.7rem; }
  .data-table td.hide-mobile, .data-table th.hide-mobile { display: none; }
  .panel-tab { padding: var(--space-sm) var(--space-sm); font-size: 0.75rem; }
  .gauge-svg { width: 80px; height: 60px; }
  .log-viewer { max-height: 250px; font-size: 0.65rem; }
  .ladder-row { font-size: 0.7rem; }
  .ladder-row .price { width: 60px; }
  .ladder-row .qty { width: 55px; }
  .config-modal { width: 92vw; margin-top: 10vh; }
  .config-modal .cfg-row { flex-direction: column; }
  .toast { width: calc(100% - 32px); left: 16px; right: 16px; }
  .market-grid { grid-template-columns: 1fr; }
  .empty-state { padding: var(--space-md); }
  .sidebar-right-tab-btn { font-size: 0.7rem; padding: 6px 2px; }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/php/assets/css/design-system.css src/php/assets/css/layout.css
git commit -m "feat(responsive): add CSS foundation for mobile — touch targets, breakpoints, grid rules"
```

---

### Task 2: CSS component responsive rules

**Files:**
- Modify: `src/php/assets/css/components.css`

- [ ] **Step 1: Add responsive table/modal/ladder classes**

Append to `components.css`:

```css
/* ─── Responsive helpers ─── */
@media (max-width: 480px) {
  .hide-mobile { display: none !important; }
}

/* ─── Sidebar right toggle button (mobile) ─── */
.sidebar-right-toggle {
  position: fixed;
  bottom: 20px;
  right: 20px;
  width: 48px;
  height: 48px;
  border-radius: 50%;
  background: var(--accent);
  color: #fff;
  border: none;
  font-size: 20px;
  cursor: pointer;
  z-index: 50;
  display: none;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 12px rgba(0,0,0,0.4);
  transition: transform 0.2s;
}
.sidebar-right-toggle:active { transform: scale(0.92); }

@media (max-width: 1023px) {
  .sidebar-right-toggle { display: flex; }
}

/* ─── Sidebar right close button ─── */
.sidebar-right-close {
  position: absolute;
  top: 12px;
  right: 12px;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: var(--bg-tertiary);
  color: var(--text-muted);
  border: 1px solid var(--border);
  font-size: 16px;
  cursor: pointer;
  z-index: 210;
  display: none;
  align-items: center;
  justify-content: center;
}

@media (max-width: 1023px) {
  .sidebar-right-close { display: flex; }
}

/* ─── Navbar chip compact ─── */
.nav-chip {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 8px;
  border-radius: var(--radius-sm);
  background: var(--bg-tertiary);
  font-family: var(--font-mono);
  font-size: 0.7rem;
  color: var(--text-secondary);
  white-space: nowrap;
}
.nav-chip .chip-label {
  font-family: var(--font-ui);
  font-size: 0.6rem;
  color: var(--text-muted);
  text-transform: uppercase;
}
.nav-chip .chip-val { font-weight: 600; }
.nav-chip .chip-val.green { color: var(--green); }
.nav-chip .chip-val.red { color: var(--red); }
.nav-chip .chip-val.accent { color: var(--accent); }

/* ─── PnL charts responsive wrap ─── */
.pnl-charts-wrap { display: flex; }
@media (max-width: 768px) {
  .pnl-charts-wrap { flex-direction: column; }
}

/* ─── Market indicators responsive grid ─── */
.market-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--space-md);
}
@media (max-width: 768px) {
  .market-grid { grid-template-columns: repeat(2, 1fr); gap: var(--space-sm); }
}
@media (max-width: 480px) {
  .market-grid { grid-template-columns: 1fr; }
}

/* ─── Config modal responsive ─── */
.cfg-row { display: flex; gap: var(--space-md); }
@media (max-width: 480px) {
  .cfg-row { flex-direction: column; gap: var(--space-sm); }
}

/* ─── Toast responsive ─── */
.toast {
  position: fixed;
  bottom: 20px;
  left: 50%;
  transform: translateX(-50%);
  padding: 10px 20px;
  border-radius: var(--radius-md);
  font-size: 0.85rem;
  z-index: 999;
  transition: opacity 0.3s;
}
@media (max-width: 480px) {
  .toast { width: calc(100% - 32px); left: 16px; right: 16px; transform: none; font-size: 0.75rem; padding: 8px 16px; }
}

/* ─── Ladder price responsive ─── */
.lr-price { min-width: 40px; }
.lr-qty { min-width: 40px; font-size: 0.75rem; }
@media (max-width: 480px) {
  .lr-price { min-width: 30px; font-size: 0.65rem !important; }
  .lr-qty { min-width: 30px; font-size: 0.65rem !important; }
  .ladder-row .side { width: 24px; font-size: 0.65rem; }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/php/assets/css/components.css
git commit -m "feat(responsive): add component responsive rules — tables, modals, chips, toggle buttons"
```

---

### Task 3: Extract navbar inline styles to classes

**Files:**
- Modify: `src/php/index.php`

- [ ] **Step 1: Replace navbar brand inline style**

Find:
```html
<span style="font-weight:700;font-size:14px;color:var(--accent);letter-spacing:.5px">🤖 Grid Bot</span>
```
Replace with:
```html
<span class="navbar-brand">🤖 Grid Bot</span>
```

- [ ] **Step 2: Replace navbar chip group inline style**

Find:
```html
<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:10px;font-family:var(--font-mono);max-width:100%;overflow-x:auto">
```
Replace with:
```html
<div class="nav-chip-group" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch">
```
(Keep inline for font-size:10px can't easily be classed due to JS setting values dynamically; the chip-group container itself gets class but retains overflow behavior)

- [ ] **Step 3: Replace each nav chip span**

For each chip like:
```html
<span style="background:var(--bg-tertiary);padding:1px 6px;border-radius:3px;white-space:nowrap">
  <span style="color:var(--text-muted);font-size:7px;font-family:var(--font-ui)">BID</span>
  <span id="tickerBid" style="font-weight:600">--</span>
</span>
```
Replace pattern:
```html
<span class="nav-chip">
  <span class="chip-label">BID</span>
  <span class="chip-val" id="tickerBid">--</span>
</span>
```

Apply for all chips: BID, ASK, SPR, FUND, MARK, uPNL, HIGH, LOW, VOL.

- [ ] **Step 4: Replace navbar actions buttons**

Find each action button with inline style like:
```html
<button class="btn btn-primary" onclick="toggleSpeed()" id="speedBtn" style="font-size:9px;padding:4px 9px">⚡ Rápido</button>
```
Replace with (remove inline style, rely on `.navbar-actions .btn` CSS):
```html
<button class="btn btn-primary navbar-action-btn" onclick="toggleSpeed()" id="speedBtn">⚡</button>
```

Apply for all 6 action buttons (speed, config, IA, reset, export, stop). Remove text labels, keep only emoji/icon. Wrap them:
```html
<div class="navbar-actions" style="display:flex;align-items:center;gap:4px;margin-left:auto">
```

- [ ] **Step 5: Replace hamburger button**

Find:
```html
<button id="menuToggle" style="background:none;border:none;color:var(--text-muted);font-size:20px;cursor:pointer;padding:6px;display:flex;align-items:center">☰</button>
```
Replace:
```html
<button id="menuToggle" class="navbar-hamburger">☰</button>
```
(And remove the duplicate `.navbar-hamburger` inline style from the other hamburger element if present — there's only one hamburger in the current code.)

- [ ] **Step 6: Commit**

```bash
git add src/php/index.php
git commit -m "feat(responsive): extract navbar inline styles to CSS classes, compact action buttons"
```

---

### Task 4: Extract sidebar/drawer inline styles to classes

**Files:**
- Modify: `src/php/index.php`

- [ ] **Step 1: Replace left sidebar inline styles**

Find:
```html
<div id="sidebarLeft" style="position:fixed;top:50px;left:-280px;width:280px;height:calc(100% - 50px);background:var(--bg-elevated);border-right:1px solid var(--border);transition:left .3s ease;z-index:150;overflow-y:auto">
```
Replace with:
```html
<div id="sidebarLeft" class="sidebar-left">
```

- [ ] **Step 2: Replace drawer overlay**

Find:
```html
<div id="drawerOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:140;display:none"></div>
```
Replace:
```html
<div id="drawerOverlay" class="sidebar-left-overlay"></div>
```

Add CSS for `.sidebar-left-overlay` and `.sidebar-left` to `layout.css` (replacing fixed inline values):

```css
.sidebar-left {
  position: fixed;
  top: var(--nav-h, 56px);
  left: -280px;
  width: 280px;
  height: calc(100% - var(--nav-h, 56px));
  background: var(--bg-elevated);
  border-right: 1px solid var(--border);
  transition: left 0.3s ease;
  z-index: 150;
  overflow-y: auto;
}
.sidebar-left.open { left: 0; }
.sidebar-left-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.6);
  z-index: 140;
  display: none;
}
.sidebar-left-overlay.open { display: block; }

@media (max-width: 480px) {
  .sidebar-left { width: 100vw; }
}
```

- [ ] **Step 3: Replace right sidebar inline styles**

Find:
```html
<div id="sidebarRight" style="width:300px;background:var(--bg-elevated);border-left:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden">
```
Replace:
```html
<div id="sidebarRight" class="sidebar-right">
```

Add CSS for `.sidebar-right` to `layout.css`:
```css
.sidebar-right {
  width: 300px;
  background: var(--bg-elevated);
  border-left: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

@media (max-width: 1023px) {
  .sidebar-right {
    position: fixed;
    inset: 0;
    width: 100%;
    z-index: 200;
    transform: translateX(100%);
    transition: transform 0.3s ease;
    border-left: none;
  }
  .sidebar-right.open { transform: translateX(0); }
}
```

- [ ] **Step 4: Add right sidebar close button and toggle button to HTML**

After the right sidebar's panel content (before `</div>` of `#sidebarRight`), add:
```html
<button class="sidebar-right-close" onclick="closeRightPanel()" aria-label="Close panel">✕</button>
```

After the `.app-container` closing `</div>` (or at end of body), add:
```html
<button id="rightToggle" class="sidebar-right-toggle" onclick="toggleRightPanel()" aria-label="Toggle panel">📊</button>
<div id="rightOverlay" class="sidebar-right-overlay" onclick="closeRightPanel()"></div>
```

- [ ] **Step 5: Add JS functions for right panel overlay**

In the JS section of index.php, after existing `menuToggle` handler, add:
```javascript
function toggleRightPanel() {
  const panel = document.getElementById('sidebarRight');
  const overlay = document.getElementById('rightOverlay');
  if (window.innerWidth < 1024) {
    panel.classList.toggle('open');
    if (overlay) overlay.classList.toggle('open');
  }
}
function closeRightPanel() {
  const panel = document.getElementById('sidebarRight');
  const overlay = document.getElementById('rightOverlay');
  panel.classList.remove('open');
  if (overlay) overlay.classList.remove('open');
}
```

- [ ] **Step 6: Commit**

```bash
git add src/php/index.php src/php/assets/css/layout.css
git commit -m "feat(responsive): extract sidebar inline styles to classes, add mobile right panel overlay with JS"
```

---

### Task 5: Replace chart/table/modal inline styles with CSS classes

**Files:**
- Modify: `src/php/index.php`
- Modify: `src/php/assets/css/components.css`

- [ ] **Step 1: Replace PnL chart wrappers**

Find the hourly/daily chart container:
```html
<div style="display:flex" id="pnlChartsWrap">
```
Replace with:
```html
<div class="pnl-charts-wrap" id="pnlChartsWrap">
```

- [ ] **Step 2: Replace PnL chart inner divs**

For each PnL chart div with inline styles like:
```html
<div style="background:var(--bg-elevated)"><div class="card-header"><span class="card-title">PnL Horario 48h</span>...
```
These get their border/padding from the layout's `.bottom-grid .card` or can keep minimal inline. But the second chart has:
```html
<div style="background:var(--bg-elevated);border-left:1px solid var(--border)">
```
In responsive this should become `border-top` on mobile. The responsive CSS handles this:
```css
.pnl-charts-wrap > div { flex: 1; }
.pnl-charts-wrap > div:first-child { ... }
```

Add to layout.css:
```css
.pnl-charts-wrap { display: flex; }
.pnl-charts-wrap > div { flex: 1; min-width: 0; }
@media (max-width: 768px) {
  .pnl-charts-wrap { flex-direction: column; }
  .pnl-charts-wrap > div { width: 100% !important; border-left: none !important; }
  .pnl-charts-wrap > div:first-child { border-bottom: 1px solid var(--border); padding-bottom: var(--space-sm); margin-bottom: var(--space-sm); }
}
```

- [ ] **Step 3: Replace config modal wrapper**

Find:
```html
<div id="configModal" style="display:none;position:fixed;inset:0;z-index:300;background:rgba(0,0,0,0.7);align-items:center;justify-content:center">
```
Replace with:
```html
<div id="configModal" class="config-modal-overlay">
```

Add CSS:
```css
.config-modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 300;
  background: rgba(0,0,0,0.7);
  align-items: center;
  justify-content: center;
}
.config-modal-overlay.open { display: flex; }

@media (max-width: 480px) {
  .config-modal-overlay { align-items: flex-start; padding-top: 10vh; }
}
```

- [ ] **Step 4: Replace config modal inner box**

Find:
```html
<div style="background:var(--bg-elevated);border-radius:var(--radius-lg);padding:24px;max-width:420px;width:100%;margin:20px;max-height:90vh;overflow-y:auto">
```
Replace with:
```html
<div class="config-modal">
```

Add CSS:
```css
.config-modal {
  background: var(--bg-elevated);
  border-radius: var(--radius-lg);
  padding: 24px;
  max-width: 420px;
  width: 100%;
  margin: 20px;
  max-height: 90vh;
  overflow-y: auto;
}
@media (max-width: 480px) {
  .config-modal { width: 92vw; margin: 10px; padding: 16px; }
}
```

- [ ] **Step 5: Replace config input grid rows**

Find the container div with:
```html
<div style="display:flex;gap:12px" class="cfg-top-row">
```
And the grid config container:
```html
<div style="display:flex;gap:12px;margin:8px 0">
```
Replace both with:
```html
<div class="cfg-row">
```

- [ ] **Step 6: Replace cfg-input inline styles**

Find each `<input class="cfg-input"` — they all share inline style `background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 10px;font-family:var(--font-mono);font-size:12px;color:var(--text-primary);outline:none;width:100%`.

Add to `components.css`:
```css
.cfg-input {
  background: var(--bg-primary);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 7px 10px;
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--text-primary);
  outline: none;
  width: 100%;
}
.cfg-input:focus { border-color: var(--accent); }
```
Remove inline `style` from each `cfg-input` element.

- [ ] **Step 7: Replace order ladder header inline styles**

Find:
```html
<div class="ladder-hd"><span style="text-align:right">Precio</span><span style="text-align:center">Qty</span><span style="text-align:left">Rol</span></div>
```
Replace with:
```html
<div class="ladder-hd"><span class="lr-label-right">Precio</span><span class="lr-label-center">Qty</span><span class="lr-label-left">Rol</span></div>
```

Add CSS:
```css
.lr-label-right { text-align: right; flex: 1; }
.lr-label-center { text-align: center; flex: 1; }
.lr-label-left { text-align: left; flex: 1; }
@media (max-width: 480px) {
  .ladder-hd { font-size: 0.6rem; }
  .lr-label-right, .lr-label-center, .lr-label-left { font-size: 0.6rem; }
}
```

- [ ] **Step 8: Commit**

```bash
git add src/php/index.php src/php/assets/css/components.css src/php/assets/css/layout.css
git commit -m "feat(responsive): replace chart/modal/table inline styles with CSS classes"
```

---

### Task 6: Right panel tab buttons + table responsive classes

**Files:**
- Modify: `src/php/index.php`

- [ ] **Step 1: Replace right sidebar tab buttons**

Find each `<button class="tab-btn"` in the right sidebar style strip. They all have similar inline styles. Replace with class:
```html
<button class="sidebar-right-tab-btn" onclick="switchTab('stats',this)" style="border-bottom-color:var(--accent);color:var(--accent)">Stats</button>
```

Add CSS for the tab button strip container:
```css
.sidebar-right-tabs {
  display: flex;
  position: sticky;
  top: 0;
  z-index: 10;
  background: var(--bg-elevated);
}
.sidebar-right-tab-btn {
  flex: 1;
  padding: 9px 4px;
  font-size: 9px;
  font-weight: 600;
  border: none;
  border-bottom: 2px solid transparent;
  background: transparent;
  cursor: pointer;
  font-family: var(--font-ui);
  color: var(--text-muted);
  min-height: var(--touch-min);
}
.sidebar-right-tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); }
@media (max-width: 480px) {
  .sidebar-right-tab-btn { font-size: 0.7rem; padding: 6px 2px; }
}
```

Wrap the tab buttons in a container:
```html
<div class="sidebar-right-tabs" id="rightTabStrip">
```

- [ ] **Step 2: Add hide-mobile class to low-priority table columns**

In the positions table, add `hide-mobile` to headings/cells that should be hidden on phone:
```html
<th>Lado</th><th>Qty</th><th class="hide-mobile">Entry $</th><th>uPnL</th><th class="hide-mobile">Liq $</th>
```

In the fills table:
```html
<th>Hora</th><th>Lado</th><th class="hide-mobile">Rol</th><th class="tr">PnL</th><th class="hide-mobile">Price</th><th class="hide-mobile">R</th>
```

- [ ] **Step 3: Ensure fills pagination adapts**

Find the fills pagination bar and wrap in a classed div:
```html
<div class="fills-pagination">
  <span id="fillInfo" style="font-size:10px;color:var(--text-muted)"></span>
  <button class="btn btn-primary fills-page-btn" onclick="fillsPrev()">◀</button>
  <button class="btn btn-primary fills-page-btn" onclick="fillsNext()">▶</button>
  <button class="btn btn-primary fills-page-btn" onclick="loadFillsHistory()">🔄</button>
</div>
```

Add CSS:
```css
.fills-pagination { display: flex; align-items: center; gap: 4px; padding: 4px; flex-wrap: wrap; }
.fills-page-btn { font-size: 9px; padding: 2px 7px; min-width: var(--touch-min); min-height: var(--touch-min); }
```

- [ ] **Step 4: Commit**

```bash
git add src/php/index.php src/php/assets/css/components.css
git commit -m "feat(responsive): add tab button classes, hide-mobile cols, responsive pagination"
```

---

### Task 7: Vite build and verify

**Files:** None (build artifacts)

- [ ] **Step 1: Run Vite build**

```bash
cd src/php && npx vite build 2>&1
```
Expected: build succeeds, output files in `dist/assets/`.

- [ ] **Step 2: Run PHP tests**

```bash
vendor/bin/phpunit 2>&1 | tail -5
```
Expected: `Tests: 122, Assertions: 658` all passed.

- [ ] **Step 3: Quick review of changed files**

```bash
git diff --stat HEAD~7..HEAD
```
Verify the changed files are only: `src/php/index.php`, `src/php/assets/css/design-system.css`, `src/php/assets/css/layout.css`, `src/php/assets/css/components.css`.

- [ ] **Step 4: Commit any final adjustments**

```bash
git add -A && git commit -m "chore: vite build for responsive mobile"
```
