// Ticker Component - Top bar price display

import type { Ticker } from '../types';
import { formatPrice, formatPct, formatNumber } from '../utils/format';

export function initTicker(): void {
  // Ticker updates come from state/WebSocket
}

export function updateTicker(ticker: Ticker): void {
  if (!ticker) return;

  updateElement('priceLive', formatPrice(ticker.lastPrice));
  updateElement('priceChg', formatPct(ticker.price24hPcnt * 100), {
    className: `price-chg ${ticker.price24hPcnt >= 0 ? 'up' : 'dn'}`
  });
  updateElement('priceHL', `H: ${formatPrice(ticker.highPrice24h)} · L: ${formatPrice(ticker.lowPrice24h)} · Vol: ${formatNumber(ticker.volume24h)}`);
  updateElement('bidPx', formatPrice(ticker.bidPrice));
  updateElement('askPx', formatPrice(ticker.askPrice));
  updateElement('spreadVal', formatPrice(ticker.spread, 4));
  updateElement('tbFunding', formatPct(ticker.fundingRate * 100, 4));
  updateElement('tbMark', formatPrice(ticker.markPrice));
}

function updateElement(id: string, text: string, options?: { className?: string }): void {
  const el = document.getElementById(id);
  if (el) {
    el.textContent = text;
    if (options?.className) {
      el.className = options.className;
    }
  }
}