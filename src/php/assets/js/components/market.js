import { $ } from '../utils/dom.js';
import { fmtPrice } from '../utils/format.js';
import { api } from '../api.js';

const FIELDS = [
  '#market-rsi', '#market-macd', '#market-adx',
  '#market-atr', '#market-bollinger', '#market-ema9',
  '#market-ema21', '#market-ema50', '#market-funding',
  '#market-oi',
];

function updateMarket(data) {
  if (!data) return;
  for (const sel of FIELDS) {
    const el = $(sel);
    if (!el) continue;
    const key = sel.replace('#market-', '').replace(/-/g, '_');
    const val = data[key];
    if (val === undefined) continue;
    el.textContent = typeof val === 'number' ? fmtPrice(val, 2) : val;
  }
}

export function initMarket() {
  // Fetch market data via API (not available in WS)
  api('_market').then(updateMarket).catch(() => {});

  // Also listen for WS-delivered market data (if available)
  window.addEventListener('data:market', (e) => updateMarket(e.detail));
}
