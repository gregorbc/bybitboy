import { $, $$, clear } from '../utils/dom.js';
import { fmtCurrency, fmtPct, fmtPrice, fmtTime } from '../utils/format.js';

export function initTabbedPanel() {
  const tabs = $$('.panel-tab');
  const panels = $$('.panel-content');

  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      tabs.forEach(t => t.classList.remove('active'));
      panels.forEach(p => p.classList.remove('active'));
      tab.classList.add('active');
      const target = document.getElementById(tab.dataset.panel);
      if (target) target.classList.add('active');
    });
  });

  if (tabs.length) tabs[0].click();

  window.addEventListener('data:positions', (e) => {
    const tbody = $('#positions-body');
    if (!tbody) return;
    clear(tbody);
    const positions = e.detail;
    if (!positions || !positions.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="empty-state">Sin posiciones abiertas</td></tr>';
      return;
    }
    for (const p of positions) {
      const tr = document.createElement('tr');
      const upnl = parseFloat(p.unRealizedProfit || p.uPnL || 0);
      tr.innerHTML = `
        <td>${p.side || '—'}</td>
        <td class="num">${fmtPrice(p.qty || p.size, 4)}</td>
        <td class="num">${fmtPrice(p.entry_price || p.entryPrice, 2)}</td>
        <td class="num ${upnl >= 0 ? 'green' : 'red'}">${fmtCurrency(upnl)}</td>
        <td class="num">${fmtPrice(p.liq_price || p.liquidationPrice, 2)}</td>`;
      tbody.appendChild(tr);
    }
  });

  window.addEventListener('data:fills', (e) => {
    const tbody = $('#fills-body');
    if (!tbody) return;
    clear(tbody);
    const fills = e.detail;
    if (!fills || !fills.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="empty-state">Sin fills registrados</td></tr>';
      return;
    }
    for (const f of fills) {
      const tr = document.createElement('tr');
      const pnl = parseFloat(f.pnl_usd || f.pnl || 0);
      const time = f.filled_at || f.time;
      tr.innerHTML = `
        <td>${fmtTime(time)}</td>
        <td>${f.side || '—'}</td>
        <td>${f.grid_role || f.role || '—'}</td>
        <td class="num ${pnl >= 0 ? 'green' : 'red'}">${fmtCurrency(pnl)}</td>
        <td class="num">${fmtPrice(f.price, 2)}</td>
        <td>${f.is_recovery || f.recovery ? '🔄' : ''}</td>`;
      tbody.appendChild(tr);
    }
  });

  window.addEventListener('data:ml', (e) => {
    const container = $('#ml-info');
    if (!container) return;
    const data = e.detail;
    if (!data || !data.features) {
      container.innerHTML = '<div class="empty-state">No hay datos ML</div>';
      return;
    }
    clear(container);
    for (const feat of data.features) {
      const row = document.createElement('div');
      row.style.cssText = 'display:flex;align-items:center;gap:var(--space-sm);margin:4px 0;font-size:0.8rem;';
      const barOuter = document.createElement('div');
      barOuter.style.cssText = 'flex:1;height:12px;background:var(--bg-tertiary);border-radius:4px;overflow:hidden;';
      const barInner = document.createElement('div');
      barInner.style.cssText = `height:100%;width:${Math.abs(parseFloat(feat.importance || 0) * 100)}%;background:var(--accent);border-radius:4px;transition:width 0.3s;`;
      barOuter.appendChild(barInner);
      row.innerHTML = `<span style="width:120px;">${feat.name || '—'}</span>`;
      row.appendChild(barOuter);
      row.innerHTML += `<span style="width:40px;text-align:right;font-family:var(--font-mono);">${(parseFloat(feat.importance || 0) * 100).toFixed(1)}%</span>`;
      container.appendChild(row);
    }
  });
}
