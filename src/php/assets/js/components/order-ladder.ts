// Order Ladder Component

import type { Order } from '../types';
import { formatPrice, formatQty } from '../utils/format';

export interface LadderRow {
  level: number;
  side: 'BUY' | 'SELL';
  role: 'ENTRY' | 'EXIT';
  price: number;
  qty: number;
  isCurrentPrice: boolean;
}

function processOrdersForLadder(orders: Order[], currentPrice: number): LadderRow[] {
  const rows: LadderRow[] = [];

  orders.forEach(order => {
    const level = order.gridLevel ?? 0;
    const side = order.side;
    const role = order.gridRole ?? 'ENTRY';
    const price = order.price;
    const qty = order.qty;

    rows.push({
      level,
      side,
      role,
      price,
      qty,
      isCurrentPrice: false,
    });
  });

  // Sort: SELL (high to low) then current price then BUY (low to high)
  rows.sort((a, b) => {
    if (a.side === 'SELL' && b.side === 'BUY') return -1;
    if (a.side === 'BUY' && b.side === 'SELL') return 1;
    if (a.side === 'SELL') return b.price - a.price; // High to low
    return a.price - b.price; // Low to high
  });

  // Find insertion point for current price
  const currentPriceRow: LadderRow = {
    level: 0,
    side: 'BUY',
    role: 'ENTRY',
    price: currentPrice,
    qty: 0,
    isCurrentPrice: true,
  };

  // Insert current price marker
  let inserted = false;
  const finalRows: LadderRow[] = [];
  rows.forEach(row => {
    if (!inserted && row.side === 'BUY' && row.price > currentPrice) {
      finalRows.push(currentPriceRow);
      inserted = true;
    }
    finalRows.push(row);
  });
  if (!inserted) {
    finalRows.push(currentPriceRow);
  }

  return finalRows;
}

export function renderOrderLadder(container: HTMLElement, orders: Order[], currentPrice: number): void {
  if (!orders?.length) {
    container.innerHTML = '<div class="empty-ladder">Sin órdenes activas</div>';
    return;
  }

  const rows = processOrdersForLadder(orders, currentPrice);

  container.innerHTML = `
    <div class="ladder-hd">
      <span style="text-align:right">Precio</span>
      <span style="text-align:center">Qty</span>
      <span style="text-align:left">Rol</span>
    </div>
    <div class="ladder-wrap">${rows.map(row => renderLadderRow(row)).join('')}</div>
  `;
}

function renderLadderRow(row: LadderRow): string {
  if (row.isCurrentPrice) {
    return `
      <div class="ladder-row current-price-row">
        <div class="lr-price cur">${formatPrice(row.price)}</div>
        <div class="lr-bar-wrap"><div class="lr-bar" style="width:100%;background:var(--accent)"></div></div>
        <div class="lr-qty">MARKET</div>
      </div>
    `;
  }

  const isBuy = row.side === 'BUY';
  const priceClass = isBuy ? 'buy' : 'sell';
  const barClass = isBuy ? 'buy' : 'sell';
  const roleLabel = row.role === 'ENTRY' ? (isBuy ? 'ENTRY L' : 'ENTRY S') : (isBuy ? 'EXIT L' : 'EXIT S');
  const levelLabel = row.level !== 0 ? `${row.level}` : '';

  return `
    <div class="ladder-row">
      <div class="lr-price ${priceClass}">${formatPrice(row.price)}</div>
      <div class="lr-bar-wrap"><div class="lr-bar ${barClass}" style="width:${calculateBarWidth(row.price, row.side)}%"></div></div>
      <div class="lr-qty">${formatQty(row.qty)} ${roleLabel}${levelLabel}</div>
    </div>
  `;
}

function calculateBarWidth(price: number, side: 'BUY' | 'SELL'): number {
  // Simple visualization - could be enhanced with actual order book depth
  return side === 'BUY' ? 60 : 40;
}

// Update ladder efficiently
let lastLadderOrders: Order[] = [];
let lastLadderPrice = 0;

export function updateOrderLadder(container: HTMLElement, orders: Order[], currentPrice: number): void {
  // Simple diff check to avoid unnecessary re-renders
  const ordersKey = orders.map(o => `${o.orderId}:${o.price}:${o.qty}`).join(',');
  const lastKey = lastLadderOrders.map(o => `${o.orderId}:${o.price}:${o.qty}`).join(',');

  if (ordersKey === lastKey && Math.abs(currentPrice - lastLadderPrice) < 0.01) {
    return; // No significant change
  }

  lastLadderOrders = [...orders];
  lastLadderPrice = currentPrice;
  renderOrderLadder(container, orders, currentPrice);
}