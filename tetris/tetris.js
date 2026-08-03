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
  renderNext(bagKeys[0] ? pad(SHAPES[bagKeys[0]]) : null);
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
  if (paused && e.key !== 'P' && e.key !== 'p') return;
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

const touchActions = {
  left: () => move(-1, 0),
  right: () => move(1, 0),
  down: () => softDrop(),
  rotate: () => rotatePiece(),
  drop: () => hardDrop(),
  hold: () => holdPiece(),
  pause: () => togglePause(),
  restart: () => location.reload(),
};

document.querySelectorAll('.touch-controls .tc-btn').forEach((btn) => {
  const act = btn.dataset.act;
  const fn = touchActions[act];
  if (!fn) return;
  btn.addEventListener('pointerdown', (e) => {
    e.preventDefault();
    if (paused && act !== 'pause' && act !== 'restart') return;
    if (over && act !== 'restart') return;
    fn();
  });
  btn.addEventListener('click', (e) => e.preventDefault());
});

updateHud();
spawn();
schedule();
