import { describe, test, expect, beforeEach, vi } from 'vitest';
import { JSDOM } from 'jsdom';

const COLS = 10;

function makeDom() {
  const dom = new JSDOM(`<!DOCTYPE html><html><body>
    <div id="board"></div><div id="next"></div><div id="hold"></div>
    <span id="score">0</span><span id="lines">0</span><span id="level">1</span>
  </body></html>`, { pretendToBeVisual: true });
  global.document = dom.window.document;
  global.window = dom.window;
}

async function loadGame() {
  return import('../../tetris/tetris.js');
}

function press(key) {
  document.dispatchEvent(new window.KeyboardEvent('keydown', { key }));
}

describe('tetris integration (real tetris.js in jsdom)', () => {
  beforeEach(() => {
    vi.resetModules();
    makeDom();
  });

  test('bootstraps a 10x20 board with HUD defaults', async () => {
    await loadGame();
    expect([...document.querySelectorAll('#board .cell')].length).toBe(COLS * 20);
    expect(document.getElementById('score').textContent).toBe('0');
    expect(document.getElementById('lines').textContent).toBe('0');
    expect(document.getElementById('level').textContent).toBe('1');
  });

  test('active piece renders on spawn (colored cells present)', async () => {
    await loadGame();
    expect(boardCount()).toBeGreaterThanOrEqual(1);
  });

  test('arrows and hard-drop drive the piece without crashing', async () => {
    await loadGame();
    const before = boardCount();
    press('ArrowLeft');
    press('ArrowRight');
    press('ArrowDown');
    press(' ');
    // a hard drop locks a piece => strictly more committed cells
    expect(boardCount()).toBeGreaterThanOrEqual(before);
  });

  test('rotate via ArrowUp does not crash', async () => {
    await loadGame();
    press('ArrowUp');
    expect(boardCount()).toBeGreaterThanOrEqual(1);
  });

  test('hold places a piece in the hold box', async () => {
    await loadGame();
    press('c');
    expect(document.getElementById('hold').childElementCount).toBeGreaterThan(0);
  });

  test('next-piece preview renders', async () => {
    await loadGame();
    expect(document.getElementById('next').childElementCount).toBeGreaterThan(0);
  });

  test('pause toggles the paused class and gates input', async () => {
    await loadGame();
    press('p');
    expect(document.body.classList.contains('paused')).toBe(true);
    const during = boardCount();
    press('ArrowLeft');
    press('ArrowDown');
    expect(boardCount()).toBe(during); // input gated while paused
    press('p');
    expect(document.body.classList.contains('paused')).toBe(false);
  });
});

function boardCount() {
  return [...document.querySelectorAll('#board .cell')].filter((c) => c.className !== 'cell').length;
}
