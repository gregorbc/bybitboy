import { $ } from '../utils/dom.js';
import { fmtCurrency, fmtPct, fmtDuration } from '../utils/format.js';

const FIELDS = {
  '#kpi-pnl-today': { key: 'pnl_today', fmt: fmtCurrency, cls: true },
  '#kpi-pnl-total': { key: 'pnl_total', fmt: fmtCurrency, cls: true },
  '#kpi-win-rate': { key: 'win_rate', fmt: (v) => `${parseFloat(v).toFixed(1)}%`, cls: false },
  '#kpi-uptime': { key: 'uptime_sec', fmt: fmtDuration, cls: false },
};

export function initKpiCards() {
  window.addEventListener('data:kpi', (e) => {
    const data = e.detail;
    for (const [sel, cfg] of Object.entries(FIELDS)) {
      const el = $(sel);
      if (!el) continue;
      const val = data[cfg.key];
      if (val === undefined) continue;
      el.textContent = cfg.fmt(val);
      if (cfg.cls) {
        const n = parseFloat(val);
        el.className = 'kpi-card-value' + (n > 0 ? ' green' : n < 0 ? ' red' : '');
      }
    }
  });
}
