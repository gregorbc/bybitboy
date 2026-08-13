// AI Confidence Gauge Component

import { formatMoney } from '../utils/format';

interface GaugeData {
  direction: 'UP' | 'DOWN' | 'SIDEWAYS' | 'NEUTRAL';
  confidence: number;
  reason: string;
  mlAccuracy: number;
}

let lastDirection: string | null = null;

export function renderAiGauge(container: HTMLElement, data: GaugeData): void {
  if (!data) return;

  const { direction, confidence, reason, mlAccuracy } = data;

  // Direction change notification
  if (lastDirection !== null && direction !== lastDirection) {
    showToast('Cambio de dirección IA', `Nueva dirección: ${direction} (confianza ${confidence}%)`, 'info');
    if ('Notification' in window && Notification.permission === 'granted') {
      new Notification('Grid Bot - Cambio de dirección', {
        body: `Nueva dirección: ${direction} (confianza ${confidence}%)`,
        icon: '/favicon.ico',
      });
    }
  }
  lastDirection = direction;

  // Update gauge arc
  updateGaugeArc(confidence, direction);

  // Update labels
  updateElement('gLbl', `${confidence}%`);
  updateElement('gDir', direction);
  updateElement('gRsn', reason);

  // Update ML badge
  updateElement('mlBadge', `ML ${mlAccuracy > 0 ? (mlAccuracy * 100).toFixed(0) + '%' : '--'}`);

  // Update strategy panel
  updateElement('strategyDir', direction);
  updateElement('strategyConf', `${confidence}%`);
  updateElement('strategyMl', mlAccuracy > 0 ? `${(mlAccuracy * 100).toFixed(1)}%` : '--');
}

function updateGaugeArc(confidence: number, direction: string): void {
  const arc = document.getElementById('gArc') as SVGPathElement;
  if (!arc) return;

  const radius = 64;
  const centerX = 80;
  const centerY = 80;

  // Map direction to angle range
  // DOWN: -90 to -30 deg (left side)
  // SIDEWAYS: -30 to 30 deg (center)
  // UP: 30 to 90 deg (right side)
  let startAngle: number, endAngle: number;

  switch (direction) {
    case 'UP':
      startAngle = -30;
      endAngle = -30 + (confidence / 100) * 60;
      break;
    case 'DOWN':
      startAngle = -90;
      endAngle = -90 + (confidence / 100) * 60;
      break;
    case 'SIDEWAYS':
    default:
      startAngle = -30;
      endAngle = -30 + (confidence / 100) * 60;
      break;
  }

  const startRad = (startAngle * Math.PI) / 180;
  const endRad = (endAngle * Math.PI) / 180;

  const x1 = centerX + radius * Math.cos(startRad);
  const y1 = centerY + radius * Math.sin(startRad);
  const x2 = centerX + radius * Math.cos(endRad);
  const y2 = centerY + radius * Math.sin(endRad);

  const largeArc = confidence > 50 ? 1 : 0;

  arc.setAttribute('d', `M ${x1} ${y1} A ${radius} ${radius} 0 ${largeArc} 1 ${x2} ${y2}`);

  // Color based on direction
  const colors = {
    UP: 'var(--green)',
    DOWN: 'var(--red)',
    SIDEWAYS: 'var(--accent)',
    NEUTRAL: 'var(--muted)',
  };
  arc.setAttribute('stroke', colors[direction as keyof typeof colors] || 'var(--accent)');
}

function updateElement(id: string, text: string): void {
  const el = document.getElementById(id);
  if (el) el.textContent = text;
}

// AI Next Evaluation Countdown
let aiCountdownInterval: ReturnType<typeof setInterval> | null = null;
let aiNextEvalTime = 0;

export function startAiCountdown(intervalSec: number): void {
  if (aiCountdownInterval) clearInterval(aiCountdownInterval);

  aiNextEvalTime = Date.now() + intervalSec * 1000;

  aiCountdownInterval = setInterval(() => {
    const remaining = Math.max(0, Math.ceil((aiNextEvalTime - Date.now()) / 1000));
    const bar = document.getElementById('aiBar');
    const sec = document.getElementById('aiSec');

    if (bar) {
      const pct = Math.min(100, (1 - remaining / intervalSec) * 100);
      bar.style.width = `${pct}%`;
    }
    if (sec) sec.textContent = `${remaining}s`;

    if (remaining <= 0) {
      aiNextEvalTime = Date.now() + intervalSec * 1000;
    }
  }, 1000);
}

export function resetAiCountdown(intervalSec: number): void {
  aiNextEvalTime = Date.now() + intervalSec * 1000;
}

export function stopAiCountdown(): void {
  if (aiCountdownInterval) {
    clearInterval(aiCountdownInterval);
    aiCountdownInterval = null;
  }
}

// Toast notification system
export function showToast(title: string, message: string, type: 'info' | 'fill_pos' | 'fill_neg' = 'info'): void {
  const container = document.getElementById('toasts');
  if (!container) return;

  const icons = {
    info: 'ℹ️',
    fill_pos: '✅',
    fill_neg: '⚠️',
  };

  const colors = {
    info: 'var(--accent)',
    fill_pos: 'var(--green)',
    fill_neg: 'var(--red)',
  };

  const toast = document.createElement('div');
  toast.className = `toast ${type !== 'info' ? `fill-${type === 'fill_pos' ? 'pos' : 'neg'}` : ''}`;
  toast.innerHTML = `
    <span class="toast-icon">${icons[type]}</span>
    <div class="toast-body">
      <div class="toast-title" style="color:${colors[type]}">${title}</div>
      <div class="toast-msg">${message}</div>
    </div>
    <button class="toast-close" onclick="this.parentElement.remove()">×</button>
  `;

  container.appendChild(toast);

  // Auto remove after 5 seconds
  setTimeout(() => {
    toast.classList.add('out');
    setTimeout(() => toast.remove(), 300);
  }, 5000);
}