import { $ } from '../utils/dom.js';

export function initAiGauge() {
  const el = $('#ai-gauge');
  const elDirection = $('#ai-direction');
  const elConfidence = $('#ai-confidence');
  const elReason = $('#ai-reason');
  const elNextEval = $('#ai-next-eval');
  if (!el) return;

  window.addEventListener('data:ai', (e) => {
    const { direction, confidence, reason, next_eval } = e.detail;
    if (elDirection) {
      elDirection.textContent = direction || '—';
      const cls = direction === 'UP' ? 'green' : direction === 'DOWN' ? 'red' : 'accent';
      elDirection.className = `badge badge-${cls}`;
    }
    if (elConfidence) elConfidence.textContent = confidence != null ? `${confidence}%` : '—';
    if (elReason) elReason.textContent = reason || '';
    if (elNextEval) elNextEval.textContent = next_eval != null ? `${next_eval}s` : '—';
  });
}
