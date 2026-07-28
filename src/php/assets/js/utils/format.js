export function fmtPrice(v, decimals = 2) {
  const n = parseFloat(v);
  return isNaN(n) ? '\u2014' : n.toFixed(decimals);
}

export function fmtPct(v) {
  const n = parseFloat(v);
  if (isNaN(n)) return '\u2014';
  const s = n >= 0 ? '+' : '';
  return `${s}${n.toFixed(2)}%`;
}

export function fmtCurrency(v) {
  const n = parseFloat(v);
  return isNaN(n) ? '\u2014' : n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function fmtTime(ts) {
  if (!ts) return '\u2014';
  const d = typeof ts === 'number' ? new Date(ts * 1000) : new Date(ts);
  return d.toLocaleTimeString('en-US', { hour12: false });
}

export function fmtDuration(seconds) {
  if (!seconds) return '\u2014';
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  return `${h}h ${m}m ${s}s`;
}

export function classForPct(v) {
  const n = parseFloat(v);
  if (isNaN(n)) return '';
  if (n > 0) return 'green';
  if (n < 0) return 'red';
  return '';
}
