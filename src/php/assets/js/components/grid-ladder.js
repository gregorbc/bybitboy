import { $, clear } from '../utils/dom.js';
import { fmtPrice } from '../utils/format.js';

export function initGridLadder() {
  const container = $('#order-ladder');
  if (!container) return;

  window.addEventListener('data:grid', (e) => {
    const { orders, levels, spacing_pct } = e.detail;
    if (!orders || !orders.length) {
      container.innerHTML = '<div class="empty-state">No hay órdenes activas</div>';
      return;
    }
    clear(container);

    const header = document.createElement('div');
    header.className = 'ladder-row';
    header.style.fontWeight = '600';
    header.style.color = 'var(--text-muted)';
    header.style.fontSize = '0.7rem';
    header.style.textTransform = 'uppercase';
    header.innerHTML = '<span class="side">Side</span><span class="price">Price</span><span class="qty">Qty</span><span class="bar" style="flex:1;">Volume</span>';
    container.appendChild(header);

    const maxQty = Math.max(...orders.map(o => parseFloat(o.qty || 0)), 1);

    for (const o of orders) {
      const row = document.createElement('div');
      row.className = 'ladder-row';

      const side = document.createElement('span');
      side.className = 'side';
      side.textContent = o.side === 'Sell' ? 'SELL' : 'BUY';
      side.style.color = o.side === 'Sell' ? 'var(--red)' : 'var(--green)';

      const price = document.createElement('span');
      price.className = 'price';
      price.textContent = fmtPrice(o.price, 2);

      const qty = document.createElement('span');
      qty.className = 'qty';
      qty.textContent = o.qty || '—';

      const bar = document.createElement('div');
      bar.className = `bar ${o.side === 'Sell' ? 'sell' : 'buy'}`;
      const pct = (parseFloat(o.qty || 0) / maxQty) * 100;
      bar.style.width = `${Math.max(pct, 2)}%`;

      row.appendChild(side);
      row.appendChild(price);
      row.appendChild(qty);
      row.appendChild(bar);
      container.appendChild(row);
    }
  });
}
