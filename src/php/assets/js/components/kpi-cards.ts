// KPI Cards Component

import type { PairStatus, BotStatus } from '../types';
import { formatPrice, formatMoney, formatPct, formatNumber, formatUptime } from '../utils/format';

export function initKpiCards(): void {
  // KPI cards are updated via state subscription
  // This just sets up any initial animations or interactions
}

export function updateKpiCards(data: { pair: PairStatus | null; bot: BotStatus | null; capital: number }): void {
  const { pair, bot, capital } = data;
  if (!pair) return;

  // PnL Today
  updateElement('kPnlH', formatMoney(pair.pnl_today, 4));
  updateElement('kPnlHP', `${capital > 0 ? ((pair.pnl_today / capital) * 100).toFixed(2) : '0.00'}% capital`);

  // PnL Total
  updateElement('kPnlT', formatMoney(pair.pnl_total || 0, 4));

  // Win Rate - placeholder
  updateElement('kWin', `${pair.avg_pnl_fill > 0 ? '+' : ''}${pair.avg_pnl_fill.toFixed(2)} avg`);

  // Uptime
  updateElement('kUpt', bot?.uptime || '--');
  updateElement('kOpenO', `${pair.open_entries + pair.open_exits} órd. abiertas`);

  // Projection
  // Would calculate based on historical data
}

function updateElement(id: string, text: string): void {
  const el = document.getElementById(id);
  if (el) el.textContent = text;
}