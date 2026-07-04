# Bot Improvements v14.1 — Hybrid Trending Strategy Design

**Date**: 2026-07-05
**Status**: Design Proposed

---

## 1. Hybrid Strategy: Adding UP/DOWN Modes

### Goal
Introduce distinct trading strategies for UP and DOWN market regimes, complementing the existing SIDEWAYS grid strategy. This aims to capture profits during trends while maintaining stability in sideways markets.

### Architecture

- **Regime Detection**: The bot will continue to use ML predictions (`mlResult['direction']`) and heuristic analysis (`hScore`) for market direction detection.
- **Mode Activation**: A new regime `mode` will be stored in `grid_configs` table, alongside `direction`. Modes: `SIDEWAYS`, `UP_TREND`, `DOWN_TREND`.
  - **Transition**: Mode changes require dual confirmation: ML and Heuristic must agree on the direction (e.g., ML predicts UP and `hScore` >= 1.0) and meet confidence thresholds (ML confidence >= 70%).
  - **Confirmation Delay**: To prevent whipsaws, a mode change requires confirmation for 2 consecutive cycles.
- **Strategy Switching**: The bot's core logic will adapt based on the active `mode`:

  - **`SIDEWAYS` Mode (Current)**:
    - Grid: Symmetrical, 16 levels, spacing 0.15% (adjusted by ATR).
    - Sizing: 0.09 ETH.
    - Exit: Market price oscillates around average entry.

  - **`UP_TREND` Mode**: 
    - **Activation**: ML predicts UP (≥70% conf) AND `hScore` >= 1.0, confirmed for 2 cycles.
    - **Strategy**: Trailing Long Position. Instead of a grid, the bot manages a single long position.
    - **Entry**: Open a long position at the current market price if none exists or if the existing position is closed.
    - **Take Profit**: Trailing stop loss mechanism. If price drops by 2% from its peak within the trend, close the position.
    - **Sizing**: Maintain current size (0.09 ETH).
    - **Exit Condition**: If ML/Heuristic revert to `SIDEWAYS` (dual confirmation), close the `UP_TREND` position and revert to `SIDEWAYS` grid.

  - **`DOWN_TREND` Mode**: 
    - **Activation**: ML predicts DOWN (≥70% conf) AND `hScore` <= -1.0, confirmed for 2 cycles.
    - **Strategy**: Trailing Short Position. Similar to `UP_TREND` but for shorts.
    - **Entry**: Open a short position.
    - **Take Profit**: Trailing stop loss (2% from peak short price).
    - **Sizing**: Maintain current size (0.09 ETH).
    - **Exit Condition**: If ML/Heuristic revert to `SIDEWAYS`, close the `DOWN_TREND` position and revert to `SIDEWAYS` grid.

### Data Flow

- **Configuration**: `config.json` will be updated with new parameters for trend detection thresholds and trailing stop logic (if needed, e.g., ATR multiplier for trailing).
- **Database**: `grid_configs` table will store the active `mode` and `direction`.
- **Bot Logic**: `bot.php` will contain conditional logic based on the active `mode` to switch between grid management and trailing stop management.
- **ML Prediction**: The ML model will continue to provide direction probabilities, but the bot will use dual confirmation for mode switches.

### Success Criteria

- Bot correctly identifies and enters `UP_TREND` or `DOWN_TREND` modes based on dual confirmation.
- Bot successfully manages trailing stop losses and exits trend positions gracefully upon regime reversion.
- Bot maintains the current SIDEWAYS grid strategy when no clear trend is confirmed.
- No significant increase in API errors or memory usage.
- Profits are realized during trends without excessive drawdown due to stop losses.

---

## 2. ML Model Improvements

### Goal
Improve ML model's performance and robustness by addressing stale data, class imbalance, and feature relevance.

### Changes

- **Drop `rsi_14`**: Remove from features due to negligible importance (0.05).
- **Auto-Retrain Activation**: Enable `auto_retrain_enabled: true` in `config.json`, set daily retraining schedule (03:00 UTC), and configure `min_fills_required: 100`.
- **Manual Retrain**: Perform an immediate retraining using `retrain.php` to establish a baseline after these changes.

### Success Criteria

- `retrain.php` executes successfully and produces a new `ml_weights_v2.json`.
- `config.json` is updated with `auto_retrain_enabled: true` and scheduled parameters.
- Post-retraining, the model shows improved accuracy and ideally a more balanced prediction distribution (less SIDEWAYS dominance) if sufficient trend data was available.
