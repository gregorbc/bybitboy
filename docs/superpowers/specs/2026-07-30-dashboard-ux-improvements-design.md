# Dashboard UX Improvements Design

## 1. CSS Architecture

### 1.1 Integration
Link 3 external CSS files in `index.php` `<head>`:
- `assets/css/design-system.css` (design tokens)
- `assets/css/layout.css` (grid, navbar, sidebar, responsive)
- `assets/css/components.css` (KPI cards, tables, badges, buttons, gauge, ladder, log viewer, loading states, toasts)

### 1.2 Inline Style Block
- Keep existing `<style>` block with its CSS variables (`--bg`, `--bg2`, `--green`, `--red`, `--accent`, etc.) for backward compatibility with inline `style="..."` attributes
- Remove ~150 lines of duplicate CSS now covered by external files (basic resets, button styles that duplicate `.btn`, table styles that duplicate `.data-table`, etc.)
- No HTML changes required

## 2. Chart Tooltips

### 2.1 Chart.js Tooltip Config
Add to `chartDef()` helper in `index.php`:

```
plugins: {
  legend: { display: false },
  tooltip: {
    enabled: true,
    backgroundColor: 'rgba(6,8,14,.92)',
    titleColor: '#c8daf0',
    bodyColor: '#7a99bb',
    borderColor: '#1a2535',
    borderWidth: 1,
    padding: 8,
    cornerRadius: 6,
    displayColors: false,
    callbacks: {
      label: ctx => ctx.parsed.y >= 0 ? '+' + ctx.parsed.y.toFixed(4) + ' USDT' : ctx.parsed.y.toFixed(4) + ' USDT'
    }
  }
}
```

### 2.2 Custom Candle Tooltip
HTML overlay for lightweight-charts candlestick chart showing OHLC + bot PnL status. Positioned absolutely over the chart canvas, follows crosshair.

## 3. Responsive & UX Improvements

### 3.1 Touch Targets
- Fill pagination buttons: `min-height: 44px`
- Sidebar right tab buttons: `min-height: 44px`
- Navbar action buttons: `min-height: 44px`

### 3.2 Sidebar Behavior
- Unify overlay click-to-close for both sidebars
- Right sidebar uses transform slide-in (already in external CSS)

### 3.3 Chart Interactivity
- Candlestick: enable crosshair mode in lightweight-charts
- Legend label follows crosshair position

### 3.4 Skeleton Loading
- Replace `--` placeholders with CSS shimmer animation while initial WS data hasn't arrived
- Use the `.skeleton` class from external CSS

### 3.5 Stale Data Indicator
- If WS data gap >10s, UI dims slightly and `#liveIndicator` turns muted
- Resets when fresh data arrives

## 4. WebSocket Server Improvements

### 4.1 Security: Symbol Filters
- `getRecentFills()`: add `AND symbol='ETHUSDT'` to SQL query
- `getPnlHourly()`: add `AND symbol='ETHUSDT'` to SQL query

### 4.2 Heartbeat
- Server sends `{"type":"heartbeat","ts":<timestamp>}` every 5s (separate from data broadcast)
- Client detects stale connection if no message received in >12s, shows stale indicator and forces reconnect

### 4.3 Connection Status Events
- Client triggers custom events: `ws:connecting`, `ws:connected`, `ws:disconnected`
- UI badge shows connection state (green dot / gray dot / "reconnecting...")

### 4.4 Reconnection Backoff
- Exponential: 1s → 3s → 7s → 15s (capped at 15s)
- Reset to 1s on successful connection

## 5. Files Modified

| File | Changes |
|------|---------|
| `src/php/index.php` | Add CSS links, trim inline styles, add tooltip plugin, touch targets, skeleton/stale logic, WS heartbeat handling |
| `src/php/websocket_server.php` | Add symbol filters to getRecentFills/getPnlHourly, add heartbeat broadcast |
