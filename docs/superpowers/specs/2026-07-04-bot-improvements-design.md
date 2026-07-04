# Bot Improvements v14.0 — Design Spec

**Date**: 2026-07-04
**Status**: Approved
**Scope**: 6 areas — Risk, Alerts, ML Auto-Retrain, Trade Journal, Backtest, UI Polish

---

## 1. RiskEngine — Advanced Risk Management

### Goal
Replace inline risk checks with a dedicated `RiskEngine` class that provides VaR, drawdown protection, and Kelly-based position sizing.

### Architecture
```
RiskEngine (new class in bot.php)
├── calcVaR95($recentPnls, $capital) → float
├── maxDailyDrawdown($capital) → float
├── kellyFraction($winRate, $avgWin, $avgLoss) → float
├── checkDailyDrawdown($capital) → bool (true = should stop)
├── shouldReduceSize($kelly, $currentSize) → bool
└── getRecommendedSize($capital, $kelly) → float
```

### Data Flow
1. Every cycle, `RiskEngine` reads today's Pnls from `grid_orders` (EXIT FILLED).
2. `calcVaR95()`: sorted Pnls, percentile 5th → negative value = 95% VaR.
3. `maxDailyDrawdown()`: peak-to-trough of cumulative daily PnL.
4. `kellyFraction()`: `(winRate * avgWin - (1-winRate) * avgLoss) / avgWin`.
5. If `maxDailyDrawdown > config.max_daily_drawdown_pct` → trigger hard stop.
6. If `kelly < config.kelly_max_fraction` → reduce qty proportionally.

### Config
```json
"risk": {
  "var_confidence": 0.95,
  "max_daily_drawdown_pct": 10,
  "kelly_enabled": true,
  "kelly_max_fraction": 0.25,
  "var_max_pct": 5.0
}
```

### Integration Points
- `evaluateAI()` → calls `RiskEngine.kellyFraction()` for sizing
- `checkStopConditions()` → calls `RiskEngine.checkDailyDrawdown()`
- Dashboard `_status` endpoint → includes `risk_metrics` object

---

## 2. NotificationManager — Telegram/Discord Alerts

### Goal
Real-time alerts via Telegram Bot API or Discord Webhook. Rate-limited, retry-capable, configurable from dashboard.

### Architecture
```
NotificationManager (new class in bot.php)
├── __construct($config)
├── send($level, $title, $message, $data=[])
├── sendTelegram($text) → bool
├── sendDiscord($embed) → bool
├── buildEmbed($level, $title, $msg, $data) → array
└── rateLimit($key, $minSec) → bool
```

### Alert Events
| Event | Level | Trigger |
|-------|-------|---------|
| `loss_streak` | WARNING | ≥3 consecutive losses |
| `margin_low` | CRITICAL | Position margin <20% of capital |
| `daily_loss_limit` | CRITICAL | Daily loss >$12 (config) |
| `liq_risk` | CRITICAL | Position <3% from liquidation |
| `grid_rebuilt` | INFO | Grid reconstructed |
| `daily_summary` | INFO | End-of-day PnL summary |
| `hard_stop` | CRITICAL | Hard stop triggered |
| `recovery_entered` | WARNING | Recovery mode activated |

### Rate Limiting
- `loss_streak`: max 1 per 60s
- `margin_low`: max 1 per 300s
- `daily_loss_limit`: max 1 per 600s
- `daily_summary`: max 1 per 86400s

### Config
```json
"alerts": {
  "enabled": false,
  "telegram_enabled": false,
  "telegram_bot_token": "",
  "telegram_chat_id": "",
  "discord_enabled": false,
  "discord_webhook": "",
  "loss_streak_threshold": 3,
  "margin_low_pct": 20,
  "daily_loss_limit_usd": 12,
  "rate_limit_seconds": 60
}
```

### Dashboard Integration
- `_alerts_config` GET → returns current config (tokens masked)
- `_alerts_config` POST → updates config.json
- `_test_alert` POST → sends test message
- New section in Settings tab for alert configuration

---

## 3. ML Auto-Retrain — Weekly Pipeline

### Goal
Automated weekly retraining of the ML model using recent fills data. Validates improvement before deploying.

### Architecture
```
retrain.php (new CLI script)
├── loadRecentFills($days=30) → array
├── generateFeatures($candles, $fills) → DataFrame
├── trainModel($features) → (model, accuracy)
├── validateWalkForward($model, $testData) → (mae, r2)
├── compareAccuracy($new, $old) → bool
└── deployModel($newWeights, $backup=true) → bool
```

### Pipeline Steps
1. Fetch last 30 days of candles (150/day × 30 = 4500).
2. Generate features: RSI-14, Stoch-14, MACD-hist, EMA-9/21/50, ATR%, Bollinger %B, volume ratio.
3. Label fills: +1 if profitable exit, -1 if loss exit.
4. Train RandomForest (n_estimators=100, max_depth=8).
5. Walk-forward: 70/30 split, compute accuracy, MAE, R².
6. Compare: if `new_accuracy > old_accuracy + 0.01` → deploy.
7. Backup old weights: `ml_weights_v2.json → ml_weights_v2.bak.YYYY-MM-DD`.
8. Write new weights to `ml_weights_v2.json`.

### Config
```json
"ml": {
  "auto_retrain_enabled": false,
  "retrain_day": 0,
  "retrain_hour": 3,
  "min_accuracy_improvement": 0.01,
  "min_fills_required": 100,
  "max_model_age_days": 90
}
```

### Cron Integration
```bash
# Add to /etc/cron.d/grid-bot
0 3 * * 0 erika php /home/erika/web/binance.gregorbritez.cat/public_html/retrain.php >> /home/erika/web/binance.gregorbritez.cat/public_html/retrain.log 2>&1
```

### Monitoring
- `retrain.log` tracks each run
- Dashboard `_ml_info` endpoint → includes `last_retrain`, `model_age_days`
- Alert on failed retrain

---

## 4. Trade Journal — Tags + Notes + Export

### Goal
Persistent journal per trade with tags, notes, and metrics. Queryable from dashboard.

### Database Schema
```sql
CREATE TABLE IF NOT EXISTS `trade_journal` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `trade_id` INT NOT NULL,
  `symbol` VARCHAR(20) NOT NULL,
  `side` VARCHAR(5) NOT NULL,
  `grid_level` INT,
  `entry_price` DECIMAL(20,8),
  `exit_price` DECIMAL(20,8),
  `qty` DECIMAL(20,8),
  `pnl_usd` DECIMAL(14,8),
  `pnl_pct` DECIMAL(10,4),
  `fee_usd` DECIMAL(14,8) DEFAULT 0,
  `net_pnl` DECIMAL(14,8) DEFAULT 0,
  `hold_time_sec` INT DEFAULT 0,
  `mfe` DECIMAL(14,8) DEFAULT 0,
  `mae` DECIMAL(14,8) DEFAULT 0,
  `rr_ratio` DECIMAL(10,4) DEFAULT 0,
  `tags` JSON,
  `notes` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_trade` (`trade_id`),
  INDEX `idx_tags` ((CAST(tags->'$[*]' AS CHAR(50)))),
  INDEX `idx_pnl` (`pnl_usd`),
  INDEX `idx_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Auto-Population
On each EXIT fill:
1. Insert row with computed metrics from `grid_orders` + `position_snapshots`.
2. Default tags: `["auto"]` for grid fills, `["recovery"]` for recovery fills.
3. Calculate MFE/MAE from snapshots during hold period.

### API Endpoints
- `_journal` GET → list trades with filters (tags, date range, min/max PnL)
- `_journal` POST → update tags/notes for a trade
- `_journal_export` GET → CSV download with all columns
- `_journal_stats` GET → aggregated stats (by tag, time period)

### Dashboard Tab
New "Journal" tab in sidebar-right:
- Table: time, side, entry, exit, PnL, tags, notes
- Tag editor (add/remove tags per trade)
- Notes textarea (click to edit)
- Filter by tag, date, PnL range
- Export button → CSV

### Tags
Predefined: `scalp`, `swing`, `recovery`, `hedge`, `manual`, `override`, `cascade`
Custom: unlimited, stored as JSON array.

---

## 5. Backtest Runner — Walk-Forward Validation

### Goal
CLI backtester reusing bot's AI/ML/grid engine. Compare strategy vs buy-and-hold.

### Architecture
```
backtest.php (new CLI script)
├── loadHistoricalCandles($symbol, $tf, $start, $end) → array
├── initEngine($config) → GridManager (sim mode)
├── runBacktest($candles, $config) → BacktestResult
├── calcMetrics($trades) → Metrics
├── generateEquityCurve($trades) → array
├── compareVsBuyHold($equity, $startPrice, $endPrice) → Comparison
└── exportReport($result, $format='json') → string
```

### Simulation Mode
`GridManager` runs in "simulation" — no API calls:
- `BybitFutures` replaced by `SimulatedAPI` class.
- `SimulatedAPI`: simulates fills at limit prices, tracks virtual positions.
- Simulated fees: taker 0.055%, maker 0.02% (configurable).

### Metrics
- Total PnL, PnL %, Annualized Return
- Max Drawdown (absolute $ and %)
- Sharpe Ratio (risk-free = 0)
- Sortino Ratio
- Win Rate, Profit Factor
- Total Trades, Avg Trade PnL
- Avg Hold Time
- Best/Worst Trade
- Monthly Returns Table

### CLI Usage
```bash
php backtest.php --start=2026-06-01 --end=2026-07-01 --config=default
php backtest.php --start=2026-01-01 --end=2026-07-01 --compare=buyhold
```

### Dashboard Integration
- `_backtest_run` POST → triggers backtest via `exec()`
- `_backtest_result` GET → returns latest result
- New "Backtest" tab with equity curve, metrics, comparison

---

## 6. UI Polish — Theme, Persist, Shortcuts

### Goal
Professional UX with dark/light theme, persistent layout, keyboard shortcuts.

### Theme System
```css
:root {
  --bg: #06080e; /* dark */
  --text: #c8daf0;
  /* ... */
}
:root[data-theme="light"] {
  --bg: #f5f7fa;
  --text: #1a2535;
  /* ... */
}
```

### Implementation
1. `ThemeManager` JS class:
   - `toggle()`: switches dark↔light
   - `apply(theme)`: sets `data-theme` on `<html>`
   - `save()`: persists to `localStorage.setItem('theme', ...)`
   - `load()`: reads from localStorage on init

2. Toggle button in header: 🌙/☀️ icon

3. Default: dark (current). Light mode: full inversion of all CSS vars.

### Layout Persistence
```js
// Save
localStorage.setItem('layout', JSON.stringify({
  leftSidebarWidth: 280,
  rightSidebarWidth: 300,
  activeTab: 'stats',
  speed: 'fast',
  logPaused: false
}));

// Restore on load
const layout = JSON.parse(localStorage.getItem('layout') || '{}');
```

### Keyboard Shortcuts
| Key | Action |
|-----|--------|
| `h` | Toggle help modal |
| `t` | Toggle dark/light theme |
| `1-5` | Switch tabs (Stats/Posic/Fills/ML/Log) |
| `r` | Force refresh all |
| `Space` | Pause/resume log scroll |
| `f` | Toggle fast/normal speed |
| `Escape` | Close modals/drawers |

### Help Modal
New modal `<div id="helpModal">`:
- List of all shortcuts
- Current theme
- Bot version
- Connection status
- Toggle with `h` key

---

## Implementation Order

| Phase | Area | Estimated Time | Dependencies |
|-------|------|----------------|--------------|
| 1 | RiskEngine | 2-3h | None |
| 2 | NotificationManager | 2-3h | Config.json changes |
| 3 | Trade Journal | 2-3h | DB schema |
| 4 | ML Auto-Retrain | 2-3h | retrain.php |
| 5 | Backtest Runner | 3-4h | SimulatedAPI |
| 6 | UI Polish | 1-2h | CSS vars |

Total: ~12-18 hours

---

## Files Modified

| File | Changes |
|------|---------|
| `bot.php` | Add RiskEngine, NotificationManager classes. Integrate in GridManager. |
| `grid_ajax.php` | Add `_alerts_config`, `_journal`, `_backtest_run` endpoints. |
| `index.php` | Add Journal tab, Backtest tab, Theme toggle, Help modal, Keyboard shortcuts. |
| `config.json` | Add `risk`, `alerts`, `ml`, `ui` sections. |
| `setup_mysql.sql` | Add `trade_journal` table. |

## Files Created

| File | Purpose |
|------|---------|
| `retrain.php` | ML auto-retrain CLI |
| `backtest.php` | Backtest runner CLI |
| `SimulatedAPI.php` | Simulated Bybit API for backtest |

---

## Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| Bot downtime during changes | Restart after each phase, test on testnet |
| DB schema migration | `CREATE TABLE IF NOT EXISTS`, no ALTER needed |
| Config changes breaking bot | Default values in code, backward compatible |
| ML retrain degrades accuracy | Walk-forward validation, deploy only if better |
| Notification spam | Rate limiting, configurable thresholds |

---

## Success Criteria

- [ ] RiskEngine correctly computes VaR95, drawdown, Kelly
- [ ] Alerts fire within 5s of trigger event
- [ ] ML retrain improves accuracy by ≥1% (or skips)
- [ ] Trade Journal queryable with filters
- [ ] Backtest produces comparable metrics to Python vectorbt
- [ ] Theme toggle instant, layout persists across sessions
- [ ] All 6 areas working on testnet before mainnet consideration
