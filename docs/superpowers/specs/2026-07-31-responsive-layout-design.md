# Diseño: Layout responsive "todo encaja" en móvil y PC

Fecha: 2026-07-31
Ámbito: `src/php/index.php` (todo el frontend es inline: `<style>` + `<script>`)

## Problema

El dashboard no aprovecha el espacio en PC (cajón izquierdo oculto, columna central estirada) y en móvil las cards y el gráfico quedan apretados o desbordan horizontalmente.

## Objetivo

- **PC (≥992px):** 3 columnas visibles estilo terminal de trading — izquierda (KPIs/Estrategia/Wallet/configuración), centro (gráfico + análisis + PnL + ladder), derecha (Stats/Posiciones/Fills/ML/Log).
- **Móvil (<768px):** una sola columna con scroll, orden: gráfico → KPIs/Estrategia/Wallet → análisis → PnL → ladder. Stats/Posiciones siguen como cajón derecho; configuración sigue como cajón ☰.
- **Tablet (768–991px):** 2 columnas (hero | centro); derecha como cajón.
- Sin scroll horizontal en ningún tamaño.

## Enfoque

CSS Grid con áreas con nombre + reestructura del DOM. Sin duplicar IDs (evita sync por JS).

## 1. Estructura HTML

- Nuevo contenedor `#heroCol` con las cards importantes (movidas del DOM actual, sin tocar su JS interno):
  - `kpi-grid`
  - Card "Señal IA"
  - Card "Estrategia & Estatus" (añadida en el plan anterior)
  - Card "Wallet"
  - Card "Próxima eval. IA" (barra de progreso `aiSec`/`aiBar`)
- `#sidebarLeft` (cajón ☰) queda solo con:
  - Card "Configuración Grid"
  - Card "Confianza IA (histórico)"
- Las cards de la columna central pasan a ser hijos directos de `.main-grid` (se elimina `#centerCol` — no tiene referencias en JS):
  - `.chart-sect` (chart-tabs + `#tvChartWrap` + `#candleChart` + `#chartLegend`)
  - Card "Análisis de Mercado" (`.mkt-analysis`)
  - `.pnl-charts`
  - Card PnL Acumulado
  - Card "Order Ladder"
- `#sidebarRight` sin cambios de contenido.

## 2. CSS (bloque de layout)

- `.main-grid` → `display:grid; gap:1px;`.
- **PC (≥992px):**
  - `grid-template-columns: 260px minmax(0,1fr) 300px;`
  - `grid-template-areas: "hero center right" "cfg center right";`
  - `#heroCol` ocupa la zona `hero`, `#sidebarLeft` la zona `cfg` (ambas con `position:static`), `#sidebarRight` la zona `right`.
  - El centro ocupa ambas filas.
  - El botón ☰ (`#menuToggle`) se oculta en PC.
- **Tablet (768–991px):**
  - `grid-template-columns: 260px minmax(0,1fr);`
  - `grid-template-areas: "hero center" "cfg center";`
  - `#sidebarRight` pasa a cajón derecho (`position:fixed`, como hoy).
- **Móvil (<768px):**
  - `grid-template-columns:minmax(0,1fr);`
  - `grid-template-areas: "chart" "hero" "mkt" "pnl" "ladder";`
  - `#heroCol` se muestra inline en la zona `hero` (entre el chart y el análisis).
  - `#sidebarLeft` vuelve a cajón ☰ (`position:fixed`, comportamiento actual); `#sidebarRight` cajón derecho (actual).
  - Se mantienen los ajustes existentes de ≤768px y ≤480px (topbar, botones, `mkt-analysis` 2 cols, `pnl-charts` 1 col, etc.).

## 3. Prevención de desbordes

- Todos los contenedores de columna: `min-width:0`; la columna central usa `minmax(0,1fr)`.
- Móvil: `.tv-wrap` (TradingView) altura ~300px.
- `.ladder-wrap` y tablas del panel derecho: `overflow-x:auto` como respaldo.
- El `.chart-hd`, `.cfg-v`, `.kpi-val` largos no desbordan (word-break / overflow-wrap defensivo si hace falta).

## 4. Fuera de alcance

- No se cambia el comportamiento JS de las cards ni de los cajones (solo CSS del layout y el movimiento de DOM).
- No se tocan los backends (`grid_ajax.php`, `websocket_server.php`, `bot.php`).
- No se reescribe el tema visual (colores, fuentes, espaciados internos de cada card).

## 5. Verificación

- `php -l src/php/index.php` → sin errores de sintaxis.
- `curl -sL 'https://binance.gregorbritez.cat/index.php'` → la estructura nueva presente (`#heroCol`, cards movidas, sin `#centerCol`).
- `git add src/php/index.php` (solo este archivo; nunca `git add -A`).
- Revisión visual final con el usuario en móvil y PC (sin browser-use).

## Nota de cambio

El árbol de trabajo contiene cambios no confirmados preexistentes (`Helpers.php`, `websocket_server.php`, `config.json`, etc.) del owner; no se tocan ni se incluyen en el commit.
