# Tetris `/tetris/` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create a self-contained classic Tetris game in `public_html/tetris/` (index.html + style.css + tetris.js + logic.js), reachable at `/tetris/`, with visible credit "Desarrollado por Gregor Britez".

**Architecture:** Pure board math lives in an importable ES module `tetris/logic.js`, unit-tested with the repo's Vitest. `tetris/tetris.js` is the UI/game controller (DOM grid render, gravity, input, hold/next/ghost) importing `logic.js`. `index.html` + `style.css` provide the neon-dark frame and credit. Board cells store an integer id 1..7 rendered as CSS class `bg{id}`; id 9 = ghost.

**Tech Stack:** Vanilla JS ES modules, HTML5, CSS. Vitest 2 + jsdom (`npm test`, glob `tests/js/**/*.test.js`).

## Global Constraints

- All game files live under `public_html/tetris/` (web root = `public_html/`, served at `/tetris/`).
- Zero runtime deps: only `index.html`, `style.css`, `tetris.js`, `logic.js`.
- `logic.js` must be pure and DOM-free; all unit tests target it.
- Board 10 cols × 20 rows (`COLS = 10`, `ROWS = 20`).
- 7 tetrominoes: I, O, T, S, Z, J, L.
- Scoring 100/300/500/800 for 1/2/3/4 lines; level up every 10 lines; level 1 start; gravity floor 50ms.
- Keyboard: `←`/`→` move, `↓` soft drop, `↑` rotate, `Space` hard drop, `C` hold, `P` pause.
- HUD: Points, Lines, Level, Hold, Next.
- `COLOR_ID` maps piece key → id 1..7.
- Run `npm test` before completion; no regressions.

---

### Task 1: Pure game logic `logic.js` + tests

**Files:**
- Create: `tetris/logic.js`
- Test: `tests/js/tetris.logic.test.js`

**Produced interfaces (used by Tasks 2-4):**
- `COLS = 10`, `ROWS = 20`
- `SHAPES` — `{ I, O, T, S, Z, J, L }` 2D 0/1 matrices.
- `COLORS` — hex map (reference only).
- `COLOR_ID` — `{ I:1, O:2, T:3, S:4, Z:5, J:6, L:7 }`.
- `createBoard(rows = ROWS, cols = COLS)` → all-zero matrix.
- `rotate(matrix, dir = 1)` → 90° cw (`dir=1`) / ccw (`dir=-1`), non-mutating.
- `collides(board, shape, x, y)` → bool.
- `merge(board, shape, x, y, color)` → new board (non-mutating).
- `clearFullLines(board)` → `{ board, linesCleared }` (non-mutating).

- [ ] **Step 1: Write failing tests** (`tests/js/tetris.logic.test.js`)

```js
import { describe, test, expect } from 'vitest';
import {
  COLS, ROWS, SHAPES, COLOR_ID,
  createBoard, rotate, collides, merge, clearFullLines,
} from '../../tetris/logic.js';

describe('constants', () => {
  test('board is 10x20', () => {
    expect(COLS).toBe(10); expect(ROWS).toBe(20);
  });
  test('has 7 tetrominoes with unique color ids', () => {
    expect(Object.keys(SHAPES).length).toBe(7);
    expect(new Set(Object.values(COLOR_ID)).size).toBe(7);
  });
});

describe('createBoard', () => {
  test('all-zero with given dims', () => {
    const b = createBoard(20, 10);
    expect(b.length).toBe(20); expect(b[0].length).toBe(10);
    expect(b.every((r) => r.every((c) => c === 0))).toBe(true);
  });
});

describe('rotate', () => {
  test('clockwise without mutating input', () => {
    const m = [[1, 0], [1, 0]];
    expect(rotate(m)).toEqual([[1, 1], [0, 0]]);
    expect(m).toEqual([[1, 0], [1, 0]]);
  });
  test('counter-clockwise reverses', () => {
    const m = [[1, 0], [1, 0]];
    expect(rotate(m, -1)).toEqual([[0, 0], [1, 1]]);
  });
});

describe('collides', () => {
  test('off-left collides', () => {
    expect(collides(createBoard(), [[1]], -1, 5)).toBe(true);
  });
  test('off-bottom collides', () => {
    expect(collides(createBoard(), [[1]], 3, ROWS)).toBe(true);
  });
  test('overlap collides', () => {
    const b = createBoard(); b[10][3] = 1;
    expect(collides(b, [[1]], 3, 10)).toBe(true);
  });
  test('valid placement no collision', () => {
    expect(collides(createBoard(), [[1]], 3, 10)).toBe(false);
  });
});

describe('merge', () => {
  test('writes color at offset without mutating input', () => {
    const b = createBoard(); const s = [[1, 1]];
    const out = merge(b, s, 3, 10, 5);
    expect(out[10][3]).toBe(5); expect(out[10][4]).toBe(5);
    expect(out[10][0]).toBe(0); expect(b[10][3]).toBe(0);
  });
});

describe('clearFullLines', () => {
  test('clears one full row and drops rows above', () => {
    const b = createBoard();
    for (let c = 0; c < COLS; c++) b[ROWS - 1][c] = 1;
    b[ROWS - 2][0] = 2;
    const { board: out, linesCleared } = clearFullLines(b);
    expect(linesCleared).toBe(1); expect(out.length).toBe(ROWS);
    expect(out[ROWS - 1][0]).toBe(2);
    expect(out[0].every((c) => c === 0)).toBe(true);
  });
  test('counts two cleared lines', () => {
    const b = createBoard();
    for (let r = ROWS - 1; r >= ROWS - 2; r--) for (let c = 0; c < COLS; c++) b[r][c] = 1;
    expect(clearFullLines(b).linesCleared).toBe(2);
  });
});
```

- [ ] **Step 2: Run to confirm failure**

Run: `npx vitest run tests/js/tetris.logic.test.js`
Expected: FAIL (module `../../tetris/logic.js` does not exist).

- [ ] **Step 3: Create `tetris/logic.js`**

```js
// Pure Tetris game logic. DOM-free; unit-tested by tests/js/tetris.logic.test.js.

export const COLS = 10;
export const ROWS = 20;

export const SHAPES = {
  I: [[1, 1, 1, 1]],
  O: [[1, 1], [1, 1]],
  T: [[0, 1, 0], [1, 1, 1]],
  S: [[0, 1, 1], [1, 1, 0]],
  Z: [[1, 1, 0], [0, 1, 1]],
  J: [[1, 0, 0], [1, 1, 1]],
  L: [[0, 0, 1], [1, 1, 1]],
};

export const COLORS = {
  I: '#00f0f0', O: '#f0f000', T: '#a000f0',
  S: '#00f000', Z: '#f00000', J: '#1375d3', L: '#f0a000',
};

export const COLOR_ID = { I: 1, O: 2, T: 3, S: 4, Z: 5, J: 6, L: 7 };

export function createBoard(rows = ROWS, cols = COLS) {
  return Array.from({ length: rows }, () => Array(cols).fill(0));
}

export function rotate(matrix, dir = 1) {
  const h = matrix.length;
  const w = matrix[0].length;
  const out = Array.from({ length: w }, () => Array(h).fill(0));
  for (let r = 0; r < h; r++) {
    for (let c = 0; c < w; c++) {
      const v = matrix[r][c];
      out[dir === 1 ? c : w - 1 - c][dir === 1 ? h - 1 - r : r] = v;
    }
  }
  return out;
}

export function collides(board, shape, x, y) {
  for (let r = 0; r < shape.length; r++) {
    for (let c = 0; c < shape[r].length; c++) {
      if (!shape[r][c]) continue;
      const bx = x + c;
      const by = y + r;
      if (bx < 0 || bx >= board[0].length || by < 0 || by >= board.length) return true;
      if (board[by][bx]) return true;
    }
  }
  return false;
}

export function merge(board, shape, x, y, color) {
  const out = board.map((row) => row.slice());
  for (let r = 0; r < shape.length; r++) {
    for (let c = 0; c < shape[r].length; c++) {
      if (!shape[r][c]) continue;
      const by = y + r;
      const bx = x + c;
      if (by >= 0 && by < out.length && bx >= 0 && bx < out[by].length) out[by][bx] = color;
    }
  }
  return out;
}

export function clearFullLines(board) {
  const kept = board.filter((row) => row.some((c) => c === 0));
  const linesCleared = board.length - kept.length;
  const out = Array.from(kept);
  while (out.length < board.length) out.unshift(Array(board[0].length).fill(0));
  return { board: out, linesCleared };
}
```

- [ ] **Step 4: Run to confirm pass**

Run: `npx vitest run tests/js/tetris.logic.test.js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tetris/logic.js tests/js/tetris.logic.test.js
git commit -m "feat(tetris): pure board logic module with unit tests"
```

---

### Task 2: Game controller `tetris.js`

**Files:**
- Create: `tetris/tetris.js`

**Interfaces:** Consumes `COLS, ROWS, SHAPES, COLOR_ID, createBoard, rotate, collides, merge, clearFullLines` from `./logic.js`; DOM `#board`, `#next`, `#hold`, `#score`, `#lines`, `#level`. Produces a playable game (manual verification in Task 4).

- [ ] **Step 1: Create `tetris/tetris.js`**

```js
import { COLS, ROWS, SHAPES, COLOR_ID, createBoard, rotate, collides, merge, clearFullLines } from './logic.js';

const boardEl = document.getElementById('board');
const nextEl = document.getElementById('next');
const holdEl = document.getElementById('hold');
const scoreEl = document.getElementById('score');
const linesEl = document.getElementById('lines');
const levelEl = document.getElementById('level');

function makeGrid(el, cols, rows) {
  const cells = [];
  for (let r = 0; r < rows; r++) {
    const row = document.createElement('div');
    row.className = 'row';
    for (let c = 0; c < cols; c++) {
      const cell = document.createElement('div');
      cell.className = 'cell';
      row.appendChild(cell);
      cells.push(cell);
    }
    el.appendChild(row);
  }
  return (data) => {
    for (let i = 0; i < cells.length; i++) {
      const v = data ? data[Math.floor(i / cols)][i % cols] : 0;
      cells[i].className = v === 9 ? 'cell ghost' : (v > 0 ? `cell bg${v}` : 'cell');
    }
  };
}

const renderBoard = makeGrid(boardEl, COLS, ROWS);
const renderNext = makeGrid(nextEl, 4, 2);
const renderHold = makeGrid(holdEl, 4, 2);

function pad(shape) {
  const g = Array.from({ length: 2 }, () => Array(4).fill(0));
  for (let r = 0; r < shape.length && r < 2; r++) {
    for (let c = 0; c < shape[r].length && c < 4; c++) {
      if (shape[r][c]) g[r][c] = 1;
    }
  }
  return g;
}

let board = createBoard();
let bagKeys = [];
let current = null;
let hold = null;
let canHold = true;
let score = 0, lines = 0, level = 1;
let over = false, paused = false;
let timer = null;

function nextKey() {
  if (bagKeys.length === 0) {
    const keys = Object.keys(SHAPES);
    for (let i = keys.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [keys[i], keys[j]] = [keys[j], keys[i]];
    }
    bagKeys = keys;
  }
  return bagKeys.shift();
}

function centerX(shape) { return Math.floor((COLS - shape[0].length) / 2); }

function spawn() {
  if (over) return;
  const key = nextKey();
  const shape = SHAPES[key];
  current = { key, shape, x: centerX(shape), y: 0 };
  if (collides(board, current.shape, current.x, current.y)) { over = true; return; }
  renderPieces();
}

function ghostY() {
  let gy = current.y;
  while (!collides(board, current.shape, current.x, gy + 1)) gy++;
  return gy;
}

function lock() {
  board = merge(board, current.shape, current.x, current.y, COLOR_ID[current.key]);
  const res = clearFullLines(board);
  board = res.board;
  if (res.linesCleared > 0) {
    score += [0, 100, 300, 500, 800][Math.min(res.linesCleared, 4)] * level;
    lines += res.linesCleared;
    level = Math.floor(lines / 10) + 1;
    updateHud();
  }
  canHold = true;
  if (!over) spawn();
  schedule();
}

function move(dx, dy) {
  if (over) return;
  if (!collides(board, current.shape, current.x + dx, current.y + dy)) {
    current.x += dx; current.y += dy;
    renderPieces();
  } else if (dy > 0) {
    lock();
  }
}

function softDrop() { move(0, 1); }

function hardDrop() {
  if (over) return;
  current.y = ghostY();
  lock();
  renderPieces();
}

function rotatePiece() {
  if (over) return;
  const rotated = rotate(current.shape);
  for (const k of [0, -1, 1, -2, 2]) {
    if (!collides(board, rotated, current.x + k, current.y)) {
      current.shape = rotated;
      current.x += k;
      renderPieces();
      return;
    }
  }
}

function holdPiece() {
  if (over || !canHold) return;
  if (hold === null) hold = current.key;
  else { const t = current.key; current.key = hold; hold = t; }
  current.shape = SHAPES[current.key];
  current.x = centerX(current.shape);
  current.y = 0;
  canHold = false;
  if (collides(board, current.shape, current.x, current.y)) { over = true; }
  renderPieces();
}

function togglePause() {
  if (over) return;
  paused = !paused;
  document.body.classList.toggle('paused', paused);
  if (!paused) schedule();
}

function updateHud() {
  scoreEl.textContent = score;
  linesEl.textContent = lines;
  levelEl.textContent = level;
}

function renderPieces() {
  if (!current) return;
  // Board locked cells.
  const view = board.map((r) => r.slice());
  // Ghost (id 9).
  const gy = ghostY();
  for (let r = 0; r < current.shape.length; r++) {
    for (let c = 0; c < current.shape[r].length; c++) {
      if (current.shape[r][c]) {
        const by = gy + r, bx = current.x + c;
        if (by >= 0 && by < ROWS && bx >= 0 && bx < COLS && view[by][bx] === 0) view[by][bx] = 9;
      }
    }
  }
  // Active piece (real color), overwriting ghost where they overlap.
  for (let r = 0; r < current.shape.length; r++) {
    for (let c = 0; c < current.shape[r].length; c++) {
      if (current.shape[r][c]) {
        const by = current.y + r, bx = current.x + c;
        if (by >= 0 && by < ROWS && bx >= 0 && bx < COLS) view[by][bx] = COLOR_ID[current.key];
      }
    }
  }
  renderBoard(view);
  renderNext(pad(SHAPES[bagKeys[0]]));
  renderHold(hold ? pad(SHAPES[hold]) : null);
}

function schedule() {
  clearTimeout(timer);
  if (over) return;
  const speed = Math.max(50, 1000 - (level - 1) * 85);
  timer = setTimeout(() => { if (!paused && !over) { move(0, 1); schedule(); } }, speed);
}

document.addEventListener('keydown', (e) => {
  if (over) return;
  switch (e.key) {
    case 'ArrowLeft': e.preventDefault(); move(-1, 0); break;
    case 'ArrowRight': e.preventDefault(); move(1, 0); break;
    case 'ArrowDown': e.preventDefault(); softDrop(); break;
    case 'ArrowUp': rotatePiece(); break;
    case ' ': e.preventDefault(); hardDrop(); break;
    case 'C': case 'c': holdPiece(); break;
    case 'P': case 'p': togglePause(); break;
  }
});

updateHud();
spawn();
schedule();
```

- [ ] **Step 2: Sanity-lint**

Run: `node --check tetris/tetris.js && node --check tetris/logic.js`
Expected: no syntax errors.

- [ ] **Step 3: Commit**

```bash
git add tetris/tetris.js
git commit -m "feat(tetris): game controller, input and rendering"
```

---

### Task 3: HTML + CSS

**Files:**
- Create: `tetris/index.html`
- Create: `tetris/style.css`

**Interfaces:** Provides DOM ids consumed by `tetris.js` (`#board`, `#next`, `#hold`, `#score`, `#lines`, `#level`), cell classes `.cell`, `.bg1..bg7`, `.ghost`, and the footer credit.

- [ ] **Step 1: Create `tetris/index.html`**

```html
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>TETRIS — Gregor Britez</title>
  <link rel="stylesheet" href="style.css" />
</head>
<body>
  <h1 class="title">TETRIS</h1>

  <div class="game">
    <div class="panel">
      <div class="hud"><span class="lbl">PUNTOS</span><span id="score">0</span></div>
      <div class="hud"><span class="lbl">LÍNEAS</span><span id="lines">0</span></div>
      <div class="hud"><span class="lbl">NIVEL</span><span id="level">1</span></div>
      <div class="hud"><span class="lbl">HOLD</span><div id="hold"></div></div>
      <div class="hud"><span class="lbl">SIGUIENTE</span><div id="next"></div></div>
    </div>

    <div class="board-wrap"><div id="board"></div></div>

    <div class="side controls">
      <p>← → mover</p>
      <p>↑ rotar</p>
      <p>↓ caída</p>
      <p>Espacio caída rápida</p>
      <p>C hold</p>
      <p>P pausa</p>
      <p><button onclick="location.reload()">Reiniciar</button></p>
    </div>
  </div>

  <footer>Desarrollado por <strong>Gregor Britez</strong></footer>

  <script type="module" src="tetris.js"></script>
</body>
</html>
```

- [ ] **Step 2: Create `tetris/style.css`**

```css
:root { --bg: #0b0e1a; --panel: #131a2e; --neon: #00d9ff; --text: #e8ecff; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  min-height: 100vh;
  background: radial-gradient(ellipse at top, #1b2340 0%, var(--bg) 70%);
  color: var(--text);
  font-family: 'Segoe UI', system-ui, sans-serif;
  display: flex; flex-direction: column; align-items: center; gap: 18px; padding: 24px 12px;
}
.title { font-size: 3rem; letter-spacing: 0.3em; color: var(--neon); text-shadow: 0 0 18px var(--neon); }
.game { display: flex; gap: 20px; align-items: stretch; }
.panel {
  background: var(--panel); border: 1px solid #223055; border-radius: 10px; padding: 14px;
  min-width: 150px; display: flex; flex-direction: column; gap: 14px;
}
.side p { font-size: 0.85rem; color: #c6cde8; line-height: 1.6; }
.side button {
  margin-top: 8px; padding: 8px 14px; cursor: pointer; color: var(--text);
  background: #1c2548; border: 1px solid var(--neon); border-radius: 6px; letter-spacing: 0.05em;
}
.side button:hover { background: #26325e; }
.hud .lbl { display: block; font-size: 0.7rem; color: #9aa3c0; letter-spacing: 0.18em; margin-bottom: 2px; }
.hud { font-size: 1.4rem; }
.hud > div { min-height: 62px; }
.board-wrap { background: #0b0e1a; border: 3px solid var(--neon); box-shadow: 0 0 26px rgba(0,217,255,.25); }
.row { display: flex; }
#board .cell { width: 26px; height: 26px; border: 1px solid rgba(255,255,255,.06); background: #0f1424; }
#hold .cell, #next .cell { width: 22px; height: 22px; border: 1px solid rgba(255,255,255,.05); background: #0f1424; }
.bg1 { background: #00f0f0; } .bg2 { background: #f0f000; }
.bg3 { background: #a000f0; } .bg4 { background: #00f000; }
.bg5 { background: #f00000; } .bg6 { background: #1375d3; } .bg7 { background: #f0a000; }
.ghost { background: rgba(255,255,255,.14); }
body.paused .board-wrap { opacity: .55; }
footer { margin-top: auto; padding: 14px; color: #8f97bf; letter-spacing: .05em; }
footer strong { color: var(--neon); }
```

- [ ] **Step 3: Verify page loads**

Serve `public_html/tetris/` (`python3 -m http.server 8000 -d tetris/`, or browse the live URL). Confirm title, board grid, HUD, hold/next, credit render, no console errors.

- [ ] **Step 4: Commit**

```bash
git add tetris/index.html tetris/style.css
git commit -m "feat(tetris): neon-dark page, controls and credit"
```

---

### Task 4: Full verification

**Files:** none new.

- [ ] **Step 1: Run unit tests**

Run: `npm test`
Expected: `tetris.logic` suite passes; existing suites unaffected.

- [ ] **Step 2: Manual playtest**

At `/tetris/`: piece falls/moves/rotates; Space hard-drops; C holds; two-line clear awards 300; 10 lines level up; P pauses; credit visible.

- [ ] **Step 3: Report pre-existing failures**

If `npm test` shows failures unrelated to Tetris, report to the user; do not suppress them.

- [ ] **Step 4: Final commit**

```bash
cd /home/erika/web/binance.gregorbritez.cat/public_html
git status --short
git add tetris/ tests/js/tetris.logic.test.js
git commit -m "test(tetris): final regression run" || echo "nothing to commit"
```