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