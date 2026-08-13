// Fills Table Component with pagination

import type { Fill } from '../types';
import { formatPrice, formatMoney, formatTimestamp } from '../utils/format';

const FILLS_PER_PAGE = 40;
let currentPage = 1;
let allFills: Fill[] = [];

export function setFillsData(fills: Fill[]): void {
  allFills = fills;
  currentPage = 1;
}

export function prependFills(newFills: Fill[]): void {
  // Avoid duplicates
  const existing = new Set(allFills.slice(0, 50).map(f => `${f.execId}`));
  const unique = newFills.filter(f => !existing.has(f.execId));
  allFills = [...unique, ...allFills].slice(0, 1000);
}

export function renderFillsTable(container: HTMLElement, page = 1): void {
  currentPage = page;
  const start = (page - 1) * FILLS_PER_PAGE;
  const end = start + FILLS_PER_PAGE;
  const pageFills = allFills.slice(start, end);

  const tbody = container.querySelector('#fillBody');
  if (!tbody) return;

  if (!pageFills.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="no-data">Sin historial</td></tr>';
    updatePagination();
    return;
  }

  tbody.innerHTML = pageFills.map(fill => {
    const sideClass = fill.side === 'BUY' ? 'b-buy' : 'b-sell';
    const role = fill.execType || '';
    const pnl = parseFloat(fill.closedPnl?.toString() || '0');
    const price = fill.price || 0;

    return `
      <tr>
        <td style="color:var(--muted)">${formatTimestamp(fill.execTime).slice(0, 8)}</td>
        <td><span class="badge ${sideClass}">${fill.side}</span></td>
        <td style="color:var(--muted)">${role}</td>
        <td class="tr">${formatMoney(pnl, 4)}</td>
        <td style="color:var(--dim)">${formatPrice(price, 2)}</td>
        <td>${fill.execType === 'Trade' ? '' : '<span class="badge b-rec">R</span>'}</td>
      </tr>
    `;
  }).join('');

  updatePagination();
}

function updatePagination(): void {
  const totalPages = Math.ceil(allFills.length / FILLS_PER_PAGE);
  const pageEl = document.getElementById('fillsPage');
  const prevBtn = document.getElementById('fillPrev');
  const nextBtn = document.getElementById('fillNext');

  if (pageEl) pageEl.textContent = `${currentPage}/${Math.max(1, totalPages)}`;
  if (prevBtn) (prevBtn as HTMLButtonElement).disabled = currentPage <= 1;
  if (nextBtn) (nextBtn as HTMLButtonElement).disabled = currentPage >= totalPages;
}

export function fillsPrev(): void {
  if (currentPage > 1) {
    renderFillsTable(document.getElementById('tab-fills') as HTMLElement, currentPage - 1);
  }
}

export function fillsNext(): void {
  const totalPages = Math.ceil(allFills.length / FILLS_PER_PAGE);
  if (currentPage < totalPages) {
    renderFillsTable(document.getElementById('tab-fills') as HTMLElement, currentPage + 1);
  }
}

export function loadFillsHistory(): void {
  // This will be called from the main app to fetch more history
  window.dispatchEvent(new CustomEvent('fills:load-history'));
}

export function getFillsCount(): number {
  return allFills.length;
}