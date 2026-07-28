# Web Responsiveness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the grid bot dashboard fully responsive and mobile-friendly by enabling vertical scrolling when content exceeds viewport height while maintaining existing drawer patterns and functionality.

**Architecture:** Modify CSS overflow properties to allow vertical scrolling in the main container and sidebars when opened. Preserve existing HTML structure and JavaScript functionality. Use media queries to adjust sidebar widths and ensure proper scrolling behavior on mobile devices.

**Tech Stack:** PHP 8.3, HTML5, CSS3 with custom properties, JavaScript (vanilla), Chart.js 4.4, Lightweight Charts

## Global Constraints

- No external dependencies beyond existing (Chart.js, Lightweight Charts)
- All config changes go through grid_control.json (bot reads it via checkControl())
- Backward compatible — don't break existing WebSocket/polling fallback
- PHP 8.3.32, MariaDB, nginx (443 with HestiaCP)
- PSR-4 autoloading: BinanceBot\\ → src/php/
- Must maintain existing functionality and UI patterns

---

### File Structure

- **Modify:** `src/php/index.php` - Contains all HTML, CSS, and JavaScript for the dashboard. Changes will focus on CSS overflow properties and height calculations to enable vertical scrolling.
- **No new files** - All changes are modifications to existing CSS rules within index.php.
- **Test:** Manual verification in browser across viewport sizes (320px, 375px, 425px, 768px, 1024px+) and existing automated JS tests in `/tests/js/`

### Task 1: Enable vertical scrolling in main container

**Files:**
- Modify: `src/php/index.php:137`

**Interfaces:**
- Consumes: None (visual change only)
- Produces: Modified `.main-grid` CSS rule allowing vertical overflow

- [ ] **Step 1: Locate and modify .main-grid overflow property**

```css
.main-grid{display:flex;flex:1;overflow-y:auto;position:relative}
```

- [ ] **Step 2: Verify change saves correctly**

Run: `grep -n "overflow" /home/erika/web/binance.gregorbritez.cat/public_html/src/php/index.php | grep main-grid`
Expected: `.main-grid{display:flex;flex:1;overflow-y:auto;position:relative}`

- [ ] **Step 3: Commit**

```bash
git add src/php/index.php
git commit -m "feat: enable vertical scroll in main container for mobile responsiveness"
```

### Task 2: Enable vertical scrolling in right sidebar when open

**Files:**
- Modify: `src/php/index.php:143` (base rule)
- Modify: `src/php/index.php:149` (open state rule in media query)

**Interfaces:**
- Consumes: None (visual change only)
- Produces: Modified `.sidebar-right` and `.sidebar-right.open` CSS rules

- [ ] **Step 1: Update base .sidebar-right overflow property**

```css
.sidebar-right{width:300px;background:var(--bg2);border-left:1px solid var(--border);display:flex;flex-direction:column;overflow-y:auto;transition:transform .2s}
```

- [ ] **Step 2: Verify base rule change**

Run: `grep -A1 "\.sidebar-right{" /home/erika/web/binance.gregorbritez.cat/public_html/src/php/index.php`
Expected: `.sidebar-right{width:300px;background:var(--bg2);border-left:1px solid var(--border);display:flex;flex-direction:column;overflow-y:auto;transition:transform .2s}`

- [ ] **Step 3: Ensure open state inherits overflow (no change needed)**

The `.sidebar-right.open` rule in media query doesn't override overflow, so it will inherit `overflow-y:auto` from base rule.

- [ ] **Step 4: Commit**

```bash
git add src/php/index.php
git commit -m "feat: enable vertical scroll in right sidebar when open"
```

### Task 3: Adjust mobile media queries for proper sidebar height calculation

**Files:**
- Modify: `src/php/index.php:149` (max-width:768px media rule)
- Modify: `src/php/index.php:161` (max-width:480px media rule if needed)

**Interfaces:**
- Consumes: None (visual change only)
- Produces: Updated height calculations in mobile media queries

- [ ] **Step 1: Update height calculation in 768px media query**

```css
@media(max-width:768px){
  .sidebar-right{position:fixed;right:0;top:50px;height:calc(100% - 50px);width:90%;max-width:340px;z-index:160;transform:translateX(100%);box-shadow:-2px 0 12px rgba(0,0,0,.4);transition:transform .25s ease;overflow-y:auto}
  .sidebar-right.open{transform:translateX(0)}
  /* ... existing rules ... */
}
```

- [ ] **Step 2: Verify 768px media query update**

Run: `sed -n '144,160p' /home/erika/web/binance.gregorbritez.cat/public_html/src/php/index.php | grep -A2 "sidebar-right{"`
Expected: `.sidebar-right{position:fixed;right:0;top:50px;height:calc(100% - 50px);width:90%;max-width:340px;z-index:160;transform:translateX(100%);box-shadow:-2px 0 12px rgba(0,0,0,.4);transition:transform .25s ease;overflow-y:auto}`

- [ ] **Step 3: Check 480px media query (no height change needed)**

The 480px media query doesn't modify height, so the 768px rule cascades correctly. No changes needed.

- [ ] **Step 4: Commit**

```bash
git add src/php/index.php
git commit -m "feat: fix sidebar height calculation in mobile media queries"
```

### Task 4: Verify left sidebar overflow behavior in mobile views

**Files:**
- Modify: `src/php/index.php:138` (verify existing overflow-y:auto)
- Modify: `src/php/index.php:147` (768px media rule)
- Modify: `src/php/index.php:161` (480px media rule if needed)

**Interfaces:**
- Consumes: None (visual verification only)
- Produces: Confirmed `.sidebar-left` has `overflow-y:auto` in all states

- [ ] **Step 1: Verify base .sidebar-left already has overflow-y:auto**

Run: `grep -A2 "\.sidebar-left{" /home/erika/web/binance.gregorbritez.cat/public_html/src/php/index.php | grep overflow`
Expected: `overflow-y:auto;` (should already exist from line 138)

- [ ] **Step 2: Verify 768px media query doesn't override overflow**

Run: `sed -n '144,155p' /home/erika/web/binance.gregorbritez.cat/public_html/src/php/index.php | grep -A5 "\.sidebar-left{"`
Expected: Should show width changes but NO overflow property (meaning it inherits overflow-y:auto from base)

- [ ] **Step 3: Verify 480px media query doesn't override overflow**

Run: `sed -n '161,170p' /home/erika/web/binance.gregorbritez.cat/public_html/src/php/index.php | grep -A5 "\.sidebar-left{"`
Expected: Should show no overflow property (inherits overflow-y:auto from base)

- [ ] **Step 4: Commit if any fixes needed (otherwise skip commit)**

```bash
# Only if changes were made:
git add src/php/index.php
git commit -m "fix: ensure left sidebar maintains vertical scroll in mobile views"
```

### Task 5: Test implementation across viewport sizes and verify no regressions

**Files:**
- Test: Manual browser testing
- Test: Existing JS test suite (`npm test` or equivalent)

**Interfaces:**
- Consumes: All implemented changes
- Produces: Verified responsive behavior and no regressions

- [ ] **Step 1: Test desktop viewport (>1024px)**

  - Verify sidebar-left opens/closes with horizontal slide (no vertical scroll needed normally)
  - Verify sidebar-right opens/closes with horizontal slide (no vertical scroll needed normally)
  - Verify main content area does not scroll unless content exceeds viewport height
  - Verify topbar remains fixed during scroll

- [ ] **Step 2: Test tablet viewport (768px-1024px)**

  - Verify sidebar-left opens/closes with horizontal slide
  - Verify sidebar-right becomes full-height modal overlay with vertical scrolling when content exceeds height
  - Verify main content area scrolls vertically when content exceeds viewport height
  - Verify topbar remains fixed during scroll

- [ ] **Step 3: Test mobile viewport (<768px)**

  - Verify sidebar-left becomes wider overlay (85% width) with vertical scrolling when content exceeds height
  - Verify sidebar-right becomes full-height modal overlay (90% width) with vertical scrolling when content exceeds height
  - Verify main content area scrolls vertically when content exceeds viewport height
  - Verify topbar remains fixed during scroll
  - Verify horizontal scrolling is eliminated (no horizontal scrollbar)

- [ ] **Step 4: Test interactive elements**

  - Verify all topbar buttons (menu, config, AI, reset grid, export PnL, stop) work correctly
  - Verify drawer toggles (menu button, right toggle button) work correctly
  - Verify tab switching in right sidebar works correctly
  - Verify modal opens/closes correctly
  - Verify table pagination in fills tab works correctly
  - Verify charts render correctly when scrolled into view

- [ ] **Step 5: Run existing JavaScript tests**

Run: `cd /home/erika/web/binance.gregorbritez.cat/public_html && npm test`
Expected: All existing tests pass (no new test failures introduced)

- [ ] **Step 6: Final commit**

```bash
git add src/php/index.php
git commit -m "chore: verify responsive implementation and run regression tests"
```