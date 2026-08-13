// Utility functions

// Format price with fixed decimals
export function formatPrice(value: number | null | undefined, decimals = 2): string {
  if (value === null || value === undefined || isNaN(value)) return '--';
  return '$' + value.toFixed(decimals);
}

// Format money with sign
export function formatMoney(value: number | null | undefined, decimals = 4): string {
  if (value === null || value === undefined || isNaN(value)) return '<span style="color:var(--muted)">--</span>';
  const cls = value > 0 ? 'c-pos' : value < 0 ? 'c-neg' : 'c-dim';
  const sign = value > 0 ? '+' : '';
  return `<span class="${cls}">${sign}${value.toFixed(decimals)}</span>`;
}

// Format percentage
export function formatPct(value: number | null | undefined, decimals = 2): string {
  if (value === null || value === undefined || isNaN(value)) return '<span style="color:var(--muted)">--%</span>';
  const cls = value > 0 ? 'c-pos' : value < 0 ? 'c-neg' : 'c-dim';
  const sign = value > 0 ? '+' : '';
  return `<span class="${cls}">${sign}${value.toFixed(decimals)}%</span>`;
}

// Format number with commas
export function formatNumber(value: number | null | undefined, decimals = 0): string {
  if (value === null || value === undefined || isNaN(value)) return '--';
  return value.toLocaleString('en-US', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
}

// Format quantity (crypto)
export function formatQty(value: number | null | undefined, decimals = 4): string {
  if (value === null || value === undefined || isNaN(value)) return '--';
  return value.toFixed(decimals);
}

// Format uptime
export function formatUptime(seconds: number): string {
  if (seconds < 0) return '--';
  if (seconds >= 3600) {
    return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
  }
  if (seconds >= 60) {
    return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
  }
  return `${seconds}s`;
}

// Debounce function
export function debounce<T extends (...args: any[]) => any>(
  fn: T,
  ms: number
): (...args: Parameters<T>) => void {
  let timeoutId: ReturnType<typeof setTimeout>;
  return (...args: Parameters<T>) => {
    clearTimeout(timeoutId);
    timeoutId = setTimeout(() => fn(...args), ms);
  };
}

// Throttle function
export function throttle<T extends (...args: any[]) => any>(
  fn: T,
  ms: number
): (...args: Parameters<T>) => void {
  let lastCall = 0;
  return (...args: Parameters<T>) => {
    const now = Date.now();
    if (now - lastCall >= ms) {
      lastCall = now;
      fn(...args);
    }
  };
}

// Check if element is in viewport
export function isInViewport(element: HTMLElement, margin = 0): boolean {
  if (!element) return false;
  const rect = element.getBoundingClientRect();
  return (
    rect.top <= (window.innerHeight || document.documentElement.clientHeight) + margin &&
    rect.bottom >= -margin &&
    rect.right >= -margin &&
    rect.left <= (window.innerWidth || document.documentElement.clientWidth) + margin
  );
}

// Deep clone
export function deepClone<T>(obj: T): T {
  return JSON.parse(JSON.stringify(obj));
}

// Generate unique ID
export function generateId(): string {
  return Math.random().toString(36).substring(2, 15);
}

// Safe DOM query
export function $(id: string): HTMLElement | null {
  return document.getElementById(id);
}

export function $$(selector: string): NodeListOf<Element> {
  return document.querySelectorAll(selector);
}

// Create element with attributes
export function createElement<K extends keyof HTMLElementTagNameMap>(
  tag: K,
  attributes: Record<string, string> = {},
  children: (HTMLElement | string)[] = []
): HTMLElementTagNameMap[K] {
  const el = document.createElement(tag);
  Object.entries(attributes).forEach(([key, value]) => {
    if (key.startsWith('on') && typeof value === 'function') {
      el.addEventListener(key.substring(2).toLowerCase(), value as EventListener);
    } else {
      el.setAttribute(key, value);
    }
  });
  children.forEach(child => {
    if (typeof child === 'string') {
      el.appendChild(document.createTextNode(child));
    } else {
      el.appendChild(child);
    }
  });
  return el;
}

// Format timestamp
export function formatTimestamp(ts: string | number | Date, options: Intl.DateTimeFormatOptions = {}): string {
  const date = new Date(ts);
  return date.toLocaleTimeString('es-ES', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
    ...options,
  });
}

// Parse query string
export function parseQueryString(query: string): Record<string, string> {
  const params = new URLSearchParams(query);
  const result: Record<string, string> = {};
  params.forEach((value, key) => {
    result[key] = value;
  });
  return result;
}

// Sleep utility
export function sleep(ms: number): Promise<void> {
  return new Promise(resolve => setTimeout(resolve, ms));
}

// Retry with exponential backoff
export async function retryWithBackoff<T>(
  fn: () => Promise<T>,
  maxRetries = 3,
  baseDelay = 1000
): Promise<T> {
  let lastError: Error;
  for (let i = 0; i <= maxRetries; i++) {
    try {
      return await fn();
    } catch (error) {
      lastError = error as Error;
      if (i < maxRetries) {
        await sleep(baseDelay * Math.pow(2, i));
      }
    }
  }
  throw lastError!;
}

// Color for PnL
export function getPnLColor(value: number): string {
  if (value > 0) return 'var(--green)';
  if (value < 0) return 'var(--red)';
  return 'var(--accent)';
}

// Status badge class
export function getStatusBadgeClass(status: string): string {
  const map: Record<string, string> = {
    NEW: 'b-neu',
    PARTIALLY_FILLED: 'b-neu',
    FILLED: 'b-buy',
    CANCELED: 'b-sell',
    REJECTED: 'b-sell',
    EXPIRED: 'b-sell',
    pending: 'b-yl',
    credited: 'b-buy',
    failed: 'b-sell',
    OPEN: 'b-buy',
    CLOSED: 'b-sell',
  };
  return map[status.toUpperCase()] || 'b-neu';
}