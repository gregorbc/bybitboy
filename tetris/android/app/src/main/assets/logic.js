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
