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
