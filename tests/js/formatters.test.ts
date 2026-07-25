import { describe, test, expect, vi, beforeEach } from 'vitest';
import { fP, fM, debounce, setGauge, $, G_LEN } from '../../src/php/assets/js/utils.js';

describe('fP', () => {
  test('formats positive number', () => {
    expect(fP(1234.56)).toBe('$1234.56');
  });
  test('formats negative number', () => {
    expect(fP(-99.9)).toBe('$-99.90');
  });
  test('handles zero', () => {
    expect(fP(0)).toBe('$0.00');
  });
  test('handles null/undefined', () => {
    expect(fP(null)).toBe('$0.00');
    expect(fP(undefined)).toBe('$0.00');
  });
  test('custom decimals', () => {
    expect(fP(1.2345, 4)).toBe('$1.2345');
  });
});

describe('fM', () => {
  test('positive shows green class', () => {
    expect(fM(10.5)).toContain('c-pos');
    expect(fM(10.5)).toContain('+10.5000');
  });
  test('negative shows red class', () => {
    expect(fM(-5.25)).toContain('c-neg');
    expect(fM(-5.25)).toContain('-5.2500');
  });
  test('zero shows dim class', () => {
    expect(fM(0)).toContain('c-dim');
  });
  test('NaN is coerced to 0 via || operator', () => {
    expect(fM(NaN)).toContain('c-dim');
    expect(fM(NaN)).toContain('0.0000');
  });
});

describe('debounce', () => {
  test('delays execution', async () => {
    const fn = vi.fn();
    const debounced = debounce(fn, 50);
    debounced();
    debounced();
    expect(fn).not.toHaveBeenCalled();
    await new Promise(r => setTimeout(r, 60));
    expect(fn).toHaveBeenCalledTimes(1);
  });
  test('passes arguments', async () => {
    const fn = vi.fn();
    const debounced = debounce(fn, 10);
    debounced('arg1', 123);
    await new Promise(r => setTimeout(r, 20));
    expect(fn).toHaveBeenCalledWith('arg1', 123);
  });
});

describe('setGauge', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <svg viewBox="0 0 160 88">
        <path id="gArc" d="M 16 80 A 64 64 0 0 1 144 80"/>
      </svg>
      <div id="gLbl"></div>
      <div id="gDir"></div>
    `;
  });
  test('updates gauge arc and labels', () => {
    setGauge(75, 'UP');
    const arc = document.getElementById('gArc');
    expect(arc.style.stroke).toBe('var(--green)');
    expect(arc.style.strokeDashoffset).not.toBe('');
    expect(document.getElementById('gLbl').textContent).toBe('75%');
    expect(document.getElementById('gDir').innerHTML).toContain('UP');
  });
  test('SIDEWAYS uses accent color', () => {
    setGauge(50, 'SIDEWAYS');
    expect(document.getElementById('gArc').style.stroke).toBe('var(--accent)');
    expect(document.getElementById('gDir').innerHTML).toContain('\u2194');
  });
});

describe('G_LEN', () => {
  test('is Math.PI * 64', () => {
    expect(G_LEN).toBe(Math.PI * 64);
  });
});

describe('$', () => {
  beforeEach(() => {
    document.body.innerHTML = '<div id="test-el"></div>';
  });
  test('returns element by id', () => {
    expect($('test-el')).toBeTruthy();
    expect($('test-el').id).toBe('test-el');
  });
  test('returns null for missing element', () => {
    expect($('nonexistent')).toBeNull();
  });
});
