# Design: Tetris Game in `/tetris/`

Date: 2026-08-02

## Goal

Create a folder `tetris/` in the web root (`public_html/`), served at
`https://binance.gregorbritez.cat/tetris/`, containing a fully self-contained
Tetris game **developed by Gregor Britez**. No build tools, no external
dependencies.

## Scope

- Standalone page (not linked from site navigation).
- Classic neon-dark visual style matching the existing dashboard aesthetic.
- Credit text "Desarrollado por Gregor Britez" visible in the game.

## Architecture / Components

Three static files, all self-contained:

- `tetris/index.html` — page structure, title, credit footer, and links to
  local `style.css` and `tetris.js`.
- `tetris/style.css` — classic/neon dark styling.
- `tetris/tetris.js` — full game logic.

## Game Mechanics (Classic Tetris guideline)

- Board 10×20.
- 7 standard tetrominoes (I, O, T, S, Z, J, L), each with a distinct neon color.
- Keyboard controls: `←`/`→` move, `↓` soft drop, `↑` rotate,
  `Space` hard drop, `C` hold, `P` pause.
- Hold piece, Next-piece preview, ghost piece.
- Scoring: 100/300/500/800 points for 1/2/3/4 lines.
- 10 levels with increasing gravity; level up every 10 lines.
- Game over screen with restart button.
- HUD: Points, Lines, Level.

## Interface

- Central neon board, side panel with Next, Hold, and score.
- Title "TETRIS" + footer with "Desarrollado por Gregor Britez".
- Dark gradient background.

## Verification

- `http://<host>/tetris/` (or local file) loads with no console errors.
- Manual test: rotation, hold, line clear, level up, game over, restart.

## Out of Scope

- No backend, no high-score persistence to server, no mobile swipe controls
  (keyboard only), no site-navigation integration.