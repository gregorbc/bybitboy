// ML Features Importance Bars Component

import type { MlInfo } from '../types';

export function renderMlFeatures(container: HTMLElement, data: MlInfo | null): void {
  if (!container || !data?.importances) {
    container.innerHTML = '<div style="color:var(--muted);font-size:9px;text-align:center;padding:10px">Sin datos</div>';
    return;
  }

  const { importances, accuracy, features, updatedAt } = data;
  const entries = Object.entries(importances).sort((a, b) => Math.abs(b[1]) - Math.abs(a[1]));
  const maxVal = Math.max(...entries.map(([_, v]) => Math.abs(v)));

  document.getElementById('mlAccStat')!.textContent = `${(accuracy * 100).toFixed(1)}%`;
  document.getElementById('mlFeatCount')!.textContent = String(features);
  document.getElementById('mlUpdated')!.textContent = updatedAt?.slice(0, 16) || '--';

  container.innerHTML = entries.map(([name, value]) => {
    const pct = maxVal > 0 ? (Math.abs(value) / maxVal) * 100 : 0;
    const isPositive = value >= 0;
    return `
      <div class="ml-feat-row">
        <span class="ml-feat-name">${name}</span>
        <div class="ml-feat-bar-bg">
          <div class="ml-feat-bar" style="width:${pct.toFixed(1)}%; background:${isPositive ? 'var(--green)' : 'var(--red)'}"></div>
        </div>
        <span class="ml-feat-val">${value.toFixed(3)}</span>
      </div>
    `;
  }).join('');
}