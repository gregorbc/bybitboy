import { $, clear } from '../utils/dom.js';

const MAX_LINES = 200;

export function initLogViewer() {
  const container = $('#log-viewer');
  if (!container) return;

  window.addEventListener('data:logs', (e) => {
    const lines = e.detail;
    if (!lines || !lines.length) return;

    for (const line of lines) {
      const div = document.createElement('div');
      div.className = 'log-line';

      const ts = document.createElement('span');
      ts.className = 'ts';
      ts.textContent = `[${line.filled_at || line.time || ''}] `;

      const level = (line.level || 'info').toLowerCase();
      const msg = document.createElement('span');
      msg.className = `level-${level}`;
      msg.textContent = line.message || line.msg || '';

      div.appendChild(ts);
      div.appendChild(msg);
      container.appendChild(div);
    }

    while (container.children.length > MAX_LINES) {
      container.removeChild(container.firstChild);
    }

    container.scrollTop = container.scrollHeight;
  });
}
