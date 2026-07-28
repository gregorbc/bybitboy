# Professional Web Dashboard — Design Spec

## Overview

Redesign the existing bot dashboard (`index.php`) with a Bybit.com-inspired professional look, using Approach A (in-place refactor with Vite build system). Backend PHP (grid_ajax.php, websocket_server.php) remains untouched.

## Architecture

```
src/php/
├── index.php              # HTML shell (~50 lines), loads Vite-compiled assets
├── assets/
│   ├── css/
│   │   ├── design-system.css   # CSS custom properties / design tokens
│   │   ├── layout.css          # Grid, header, navbar, responsive
│   │   └── components.css      # Cards, tables, gauges, nav tabs, modals, buttons
│   └── js/
│       ├── main.js             # Vite entry point — init all components
│       ├── websocket.js        # WS connection manager — dispatches custom events
│       ├── api.js              # HTTP polling fallback + REST calls
│       ├── components/
│       │   ├── ticker.js       # Price bar + 24h change
│       │   ├── kpi-cards.js    # PnL Today/Total, Win Rate, Uptime
│       │   ├── grid-ladder.js  # Order book / grid position visualizer
│       │   ├── ai-gauge.js     # AI confidence gauge + signal direction
│       │   ├── charts.js       # PnL charts (Chart.js) + Candlestick (Lightweight Charts)
│       │   ├── market.js       # RSI, MACD, ADX, ATR, Bollinger, EMA, funding, OI
│       │   └── log-viewer.js   # Real-time log viewer with filter/search
│       └── utils/
│           ├── dom.js          # DOM helpers (selectors, create elements)
│           └── format.js       # Number/currency formatting
├── vite.config.js
├── package.json               # Vite dev dependency only
├── grid_ajax.php              # Unchanged
└── websocket_server.php       # Unchanged
```

## Design System

### Design Tokens (design-system.css)

```css
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
```

### Component Styles

| Component | Styling |
|---|---|
| Cards | bg-secondary, border 1px solid --border, radius-lg, padding --space-lg |
| KPIs | font-mono value, dynamic color (green/red), text-secondary label |
| Tables | text-muted uppercase headers, hover rows, font-mono numbers right-aligned |
| Nav tabs | Active: border-bottom 2px solid --accent; Inactive: --text-muted |
| Gauges (AI) | SVG arc, color animated by direction (green UP, red DOWN, blue SIDEWAYS) |
| Buttons | bg-accent / bg-accent-hover, radius-md, no outline |
| Scrollbar | Thin, bg-tertiary track, accent-dim thumb |
| Chart.js | Dark theme: grid --border, text --text-secondary |

## Layout

### Desktop
- Fixed top navbar: logo + nav tabs (Dashboard, Stats, ML, Logs)
- KPI row: 4 cards (Price, PnL Today, Win Rate, Uptime)
- 2-column mid section: AI gauge (left) + Market Analysis cards (right)
- Full-width Order Ladder
- 2-column bottom: PnL Charts (left) + Tabbed panel Positions/Fills/ML/Log (right)

### Mobile (<768px)
- Collapsible hamburger nav
- Single column stacking
- Bottom nav bar for quick section access

## Data Flow

```
WebSocket (port 8082) ──→ websocket.js ──→ CustomEvents ──→ Components
                              ↓ (fallback)
AJAX (grid_ajax.php)  ──→ api.js ──→ fetch() ──→ Components
```

- websocket.js: single WS connection, dispatches `event:data` with typed payloads
- Each component subscribes to relevant events via `document.addEventListener('event:...', handler)`
- api.js provides `api.get(endpoint)` and `api.post(endpoint, data)` for HTTP fallback + control actions
- Components are agnostic to data source — they just update DOM on data arrival

## Vite Build

```js
// vite.config.js
export default defineConfig({
  root: 'src/php',
  build: {
    outDir: 'dist',
    manifest: true,
    rollupOptions: { input: 'src/php/assets/js/main.js' }
  }
})
```

- Dev: `vite` (HMR on localhost:5173, proxied by PHP)
- Prod: `vite build` → outputs `src/php/dist/assets/{hash}.js` + `{hash}.css`
- `index.php` reads manifest.json to load hashed assets

## Migration Plan

1. Create package.json + vite.config.js + install dependencies
2. Write design-system.css (tokens + reset)
3. Write layout.css (grid, navbar, responsive)
4. Write components.css (cards, tables, gauges, buttons, modals)
5. Create utils/dom.js, utils/format.js, api.js
6. Create websocket.js (event dispatcher)
7. Create each component file (migrate logic from inline JS)
8. Create main.js entry point (init all components)
9. Rewrite index.php as HTML shell loading Vite assets
10. Vite build → verify production output
11. Test WebSocket + AJAX fallback, responsive breakpoints

## Non-Goals
- No authentication system (token-based remains)
- No backend refactoring
- No new features (same data, same endpoints)
- No database changes

## Future (out of scope)
- Login/auth system
- Multi-page structure
- Dark/light theme toggle
- i18n
