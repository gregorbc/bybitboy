// Logs Viewer Component

import type { LogEntry } from '../types';

let logBuffer: LogEntry[] = [];
let logFilter = '';
let logPaused = false;
const MAX_LOG_LINES = 500;

export function setLogs(logs: LogEntry[]): void {
  logBuffer = logs.slice(-MAX_LOG_LINES);
  if (!logPaused) renderLogs();
}

export function prependLogs(newLogs: LogEntry[]): void {
  if (logPaused) return;

  const last10 = logBuffer.slice(-10).map(l => `${l.time}|${l.level}|${l.message}`);
  const existing = new Set(last10);
  const unique = newLogs.filter(l => !existing.has(`${l.time}|${l.level}|${l.message}`));

  logBuffer = [...logBuffer, ...unique].slice(-MAX_LOG_LINES);
  renderLogs();
}

export function setLogFilter(filter: string): void {
  logFilter = filter.toLowerCase();
  renderLogs();
}

export function setLogPaused(paused: boolean): void {
  logPaused = paused;
  const pauseBtn = document.querySelector('[title="Pausar scroll"]') as HTMLButtonElement;
  if (pauseBtn) {
    pauseBtn.style.color = paused ? 'var(--yellow)' : '';
  }
}

export function clearLogs(): void {
  logBuffer = [];
  renderLogs();
}

function renderLogs(): void {
  const container = document.getElementById('logBox');
  if (!container) return;

  const filtered = logFilter
    ? logBuffer.filter(l =>
        l.message.toLowerCase().includes(logFilter) ||
        l.level.toLowerCase().includes(logFilter)
      )
    : logBuffer;

  container.innerHTML = filtered.slice(-100).map(entry => {
    const levelClass = {
      INFO: 'li',
      WARN: 'lw',
      ERROR: 'le',
      DEBUG: 'lm',
    }[entry.level] || 'lm';

    return `
      <div class="ll">
        <span class="lt">${entry.time.slice(11, 19)}</span>
        <span class="${levelClass}">[${entry.level}]</span>
        <span class="lm">${escapeHtml(entry.message)}</span>
      </div>
    `;
  }).join('');

  // Auto-scroll if not paused
  if (!logPaused) {
    container.scrollTop = container.scrollHeight;
  }
}

function escapeHtml(text: string): string {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}