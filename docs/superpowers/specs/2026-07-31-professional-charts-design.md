# Professional Charts + Pending Positions + Strategy Status — Design Spec

## Overview

Improve the bot dashboard (`src/php/index.php`) in three areas requested by the user:

1. **Professional charts** — add a TradingView embed widget as the main professional chart, keeping the Lightweight Charts view as a quick-view tab.
2. **Pending positions on the chart** — overlay open (pending) grid orders on the Lightweight Charts candlestick chart as horizontal price lines.
3. **Strategy used + status** — new "Estrategia & Estatus" panel in the left sidebar showing strategy name, mode, direction, confidence, AI reason, ML accuracy, grid state and bot running state.

The dashboard is a single-page inline-JS app. All frontend changes go into `src/php/index.php`; the only backend change is adding two fields to the WebSocket `pair` payload.

## Architecture

```
src/php/
├── index.php               # Chart tabs (Pro/Rápido), TradingView iframe,
│                           #   pending-position price lines, Strategy panel,
│                           #   initial render from grid_status.json
├── websocket_server.php    # [+] getStatus(): add ai_engine, last_ai_check,
│                           #   atr_predicted to $result['pair']
└── assets/css/
    ├── components.css      # [+] tabs, chart legend, strategy panel styles
    └── layout.css          # [+] (if needed) iframe container sizing
```

## Data Flow

- **Initial render:** a small PHP block in `index.php` reads `grid_status.json` (path from `config.json` → `paths.status`) and seeds the Strategy panel before WS connects. Values fall back to `--` if unreadable.
- **Live updates:** WebSocket `full` message → `updateUIFromWebSocket(data)`:
  - `data.pair`, `data.mode`, `data.bot_running` → `updateStrategyPanel(...)`
  - `data.orders` → `updateOrderLines(orders)` on the LW chart
  - `data.ticker.lastPrice` → current-price dashed line
- The `orders` array (already broadcast) has: `level`, `side`, `role` (ENTRY/EXIT), `price`, `qty`, `status`, `is_recovery`.

## Feature 1: Chart Tabs (Pro / Rápido)

Replace the static `#candleChart` card header with a tab toggle:

- **Pro tab** — `<iframe>` embedding the free TradingView widget for `BYBIT:ETHUSDT`, interval `5`, dark theme, `hideideas=1`. Lazy-loaded. Fixed height (~420px). TradingView's own error renders inside the iframe if it cannot load; the Rápido tab always works.

Embed URL template:
```
https://s.tradingview.com/widgetembed/?frameElementId=tv_{rand}&symbol=BYBIT:ETHUSDT&interval=5&hidesidetoolbar=0&hideideas=1&theme=dark&style=1&timezone=Etc%2FUTC&studies=[]&show_popup_button=1&popup_width=1000&popup_height=650
```
- **Rápido tab** — the existing Lightweight Charts chart (`initLwChart`), height raised from 200px to ~360px, plus pending-position lines (Feature 2) and a legend row below it.

Tabs are plain buttons toggling visibility of the iframe container vs. the LW container. The LW chart re-applies size when its tab becomes visible.

## Feature 2: Pending Positions on Rápido Chart

Using `createPriceLine()` on the candlestick series (`lwSeries`), for each open order:

| Order role | Style | Color | Title |
|---|---|---|---|
| ENTRY | solid | `#00c97a` (green) | `E{level}` (e.g. `E-3`) |
| EXIT | dotted | `#f5a623` (amber) | `X{level}` |
| Recovery order (is_recovery) | solid | `#9b72f5` (purple) | `R{level}` |
| Current price | dashed | `#2d8cff` (accent blue) | `MARK` |

Behavior:
- On every `data.orders` update, remove all previously created order price lines and re-create them (idempotent, matches `updateLadder`).
- Current-price line is created once and its `price` option updated on each tick.
- A legend row under the chart lists pending levels: `E-3 1860.00 · X-5 1875.00` (colored), and shows a count. Empty state: "Sin órdenes pendientes".
- If `orders` is missing/invalid, keep existing lines and do not throw (ladder logic untouched).
- Price lines only apply to the Rápido tab; the TradingView widget cannot display them (closed iframe).

## Feature 3: Strategy & Status Panel

New card in `#sidebarLeft` between "Señal IA" and "Wallet", titled **"Estrategia & Estatus"**:

| Field | Key | Source |
|---|---|---|
| Estrategia | `ai_engine` | status file → WS `pair.ai_engine` (new) |
| Modo | `mode` | `data.mode` (NORMAL/RECOVERY), colored badge |
| Dirección + Confianza | `direction` + `confidence` | `pair` |
| Razón IA | `ai_reason` | `pair` (truncated, `title` tooltip) |
| Precisión ML | `ml_accuracy` | `pair` |
| Grid | `grid_built` + `open_entries`/`open_exits` | `pair` |
| Última eval IA | `last_ai_check` | `pair` (new in WS) |
| Estado bot | `bot_running` | `data.bot_running` |
| Ciclo | `cycle_n` | `pair` |

Presentation: compact `cfg-grid` layout consistent with the existing "Configuración Grid" card. Bot status shown as green "Corriendo" / red "Detenido" pill.

## Backend Change (websocket_server.php)

In `getStatus()`, extend `$result['pair']` with:

```php
'ai_engine'      => $st['ai_engine'] ?? 'Grid v15.4',
'last_ai_check'  => $pj['last_ai_check'] ?? null,
```

No DB schema changes, no grid_ajax changes.

## Error Handling

- TradingView iframe unavailable → its own error UI; Rápido tab unaffected.
- `orders` payload invalid → skip line rebuild, keep existing lines.
- Status file unreadable at initial render → panel shows `--`, filled when WS connects.
- WS disconnect → existing stale indicator + panel keeps last known values.

## Testing

- Run `vendor/bin/phpunit` — existing suite must pass (no regressions from the WS change; websocket_server has no unit tests, verified manually).
- Run `npm test` (vitest) — existing JS test must pass.
- Manual browser verification:
  1. Tabs Pro/Rápido switch correctly; LW chart resizes when shown.
  2. Pending-position lines appear for each open order and stay in sync with the Order Ladder.
  3. Current-price line tracks ticks.
  4. Strategy panel shows all fields live; bot status reflects running/stopped.
  5. Restart `websocket_server.php` and confirm `pair.ai_engine`/`last_ai_check` arrive.

## Non-Goals

- No TradingView charting_library (requires license/approval).
- No authentication changes.
- No DB schema changes.
- No changes to grid_ajax.php or bot.php.
