import { $, flashPrice } from '../utils/dom.js';
import { fmtPrice, fmtPct } from '../utils/format.js';

export function initTicker() {
  const el = $('#ticker-price');
  const elChange = $('#ticker-change');
  if (!el) return;

  window.addEventListener('data:ticker', (e) => {
    const { price, change24h } = e.detail;
    const old = el.textContent;
    const newPrice = `$${fmtPrice(price, 2)}`;
    if (old && old !== newPrice) {
      flashPrice(el, parseFloat(change24h) >= 0);
    }
    el.textContent = newPrice;
    if (elChange && change24h !== undefined) {
      const cls = parseFloat(change24h) >= 0 ? 'green' : 'red';
      elChange.textContent = fmtPct(change24h);
      elChange.className = `kpi-card-value ${cls}`;
    }
  });
}
