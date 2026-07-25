# Web Dashboard Improvements — Grid Bot

## 1. Live Config Panel
- Modal with inputs for capital, leverage, levels (long/short), spacing
- Sends to `grid_ajax.php?action=update_config` → writes `grid_control.json`
- Bot reads `grid_control.json` in `checkControl()` and rebuilds grid

## 2. Wallet & Performance Section
- Real balance from Bybit, used margin, available margin
- ROI (daily + total), fees estimate, net PnL
- 30-day projection based on daily average
- Displayed in sidebar left below KPI grid

## 3. Mobile Responsive
- Right sidebar → bottom sheet on mobile
- Topbar collapses extra buttons into hamburger menu
- Tables use native horizontal scroll
- Charts auto-resize

## 4. Performance
- Debounce UI updates (30fps max)
- Chart.js lazy render (only when tab visible)
- Virtual scroll for logs (last 100 lines)
- Reduce reflows with CSS `will-change`

## 5. Visual / UX
- Enhanced toasts with price context
- Optional sound on desktop notifications
- Gradient accents on KPIs
- Better candle chart tooltips
- Gradient depth colors in order ladder
- Faster initial load (stream data incrementally)
