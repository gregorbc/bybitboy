export const $ = (id) => document.getElementById(id);

export const G_LEN = Math.PI * 64;

export const fP = (v, d = 2) => '$' + parseFloat(v || 0).toFixed(d);

export function fM(v, d = 4) {
  v = parseFloat(v || 0);
  if (isNaN(v)) return '<span style="color:var(--muted)">--</span>';
  const cls = v > 0 ? 'c-pos' : v < 0 ? 'c-neg' : 'c-dim';
  return `<span class="${cls}">${v > 0 ? '+' : ''}${v.toFixed(d)}</span>`;
}

export function debounce(fn, ms) {
  let t;
  return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
}

export function setGauge(conf, dir) {
  const col = { UP: 'var(--green)', DOWN: 'var(--red)', SIDEWAYS: 'var(--accent)' }[dir] || 'var(--accent)';
  const ico = { UP: '\u25B2', DOWN: '\u25BC', SIDEWAYS: '\u2194' }[dir] || '';
  const arc = $('gArc');
  arc.style.strokeDasharray = G_LEN;
  arc.style.strokeDashoffset = G_LEN - (conf / 100) * G_LEN;
  arc.style.stroke = col;
  $('gLbl').textContent = conf + '%';
  $('gLbl').style.color = col;
  $('gDir').innerHTML = `<span style="color:${col}">${ico} ${dir}</span>`;
}
