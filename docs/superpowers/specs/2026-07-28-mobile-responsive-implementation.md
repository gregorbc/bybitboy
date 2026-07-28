# Dashboard Responsive Mobile — Grid Bot

## 1. Objetivo

Hacer el dashboard profesional (Bybit-style) completamente funcional en dispositivos móviles (teléfonos ~375-430px y tablets ~768-1024px) manteniendo toda la funcionalidad existente y la experiencia desktop sin cambios.

## 2. Breakpoints

| Rango | Dispositivo | Cambios clave |
|-------|-------------|---------------|
| ≥1024px | Desktop | Layout actual sin cambios |
| 768–1023px | Tablet landscape | Navbar compacto, KPI 2-col, grids 1-col, right panel overlay |
| 480–767px | Tablet portrait/phablet | Right panel fullscreen overlay, tablas scroll horizontal, charts 100% width |
| <480px | Phone | Navbar mínimo, KPI vertical, drawer izquierdo full-width, touch targets 44px |

## 3. Navbar

- Altura reducida a 48px en <768px (vs 56px desktop)
- Brand: texto completo en tablet, solo "⚡" o abreviatura en phone
- Botones de acción: padding 4px, solo iconos, sin texto, wrap en row si es necesario
- Chip informativo (bid/ask/spread/mark/uPNL): en <768px mostrar solo bid+ask+mark+uPNL; en <480px solo bid+ask+mark
- Altura mínima touch target 44px en todos los botones

## 4. KPIs

- Desktop: 4-column grid
- Tablet (≥600px): 2x2 grid
- Phone (<600px): vertical list, label arriba / valor abajo, monospace grande

## 5. Grids principales

- Desktop: main-grid 2-col, bottom-grid 1.2fr/1fr
- <1024px: main-grid 1-col, bottom-grid 1-col
- Cards mantienen padding `var(--space-lg)` en tablet, `var(--space-md)` en phone

## 6. Right sidebar (panel derecho)

- Desktop: fijo 300px, border-left, siempre visible
- Tablet/phone: overlay fullscreen al togglear
  - Botón flotante circular (📊) en esquina inferior derecha, z-index 50
  - Panel: position fixed, inset 0, z-index 200, slide-in desde derecha
  - Botón ✕ close arriba derecha
  - Tabs: mismo ancho, labels cortos
  - Overlay backdrop semi-transparente

## 7. Left drawer

- Desktop/tablet: 280px width, slide desde izquierda
- Phone: 100vw width, padding reducido
- Overlay backdrop siempre presente

## 8. Tablas (Positions, Fills)

- Desktop: todas las columnas visibles
- Tablet: overflow-x auto en contenedor
- Phone: ocultar columnas secundarias
  - Fills: ocultar "Rol" y "Price", mostrar solo Hora/Lado/PnL
  - Positions: mostrar Lado/Qty/uPnL, ocultar Entry/Liq (o mostrar en tooltip)
- Font-size reducido a 0.7rem en phone

## 9. Charts & indicadores

- Canvas charts: width 100%, height fijo menor en móvil (60-80px vs 90-120px)
- Order ladder: precios más compactos, fuente 8px en phone
- Market analysis (RSI/MACD/ADX/ATR/Bollinger/EMA/Funding/OI): 2 columnas en mobile en vez de row horizontal
- PnL charts (hourly/daily/cumulative): stacked vertical en mobile, side-by-side en desktop

## 10. Config modal

- Desktop: max-width 420px, inputs 2-column
- Mobile: width 92vw, inputs apilados verticalmente, botones full-width
- Scroll interno si contenido excede viewport height

## 11. Touch & UX

- Botones: mínimo 44x44px touch target
- Toast notifications: width 100% en <480px
- Log viewer: max-height 250px en móvil (vs 400px desktop)
- Fills pagination: row compacta sin labels extra

## 12. Estrategia de implementación

1. Refactor inline styles → CSS clases en archivos existentes (`layout.css`, `components.css`)
2. Añadir media queries en los 3 breakpoints definidos
3. Añadir lógica JS para right panel overlay toggle en mobile
4. Mover estilos inline de index.php a clases CSS
5. No cambiar la lógica PHP/JS de negocio — solo presentación

## 13. Archivos a modificar

- `src/php/assets/css/layout.css` — breakpoints, grid, navbar, sidebar
- `src/php/assets/css/components.css` — tablas, cards, botones, tabs responsive
- `src/php/assets/css/design-system.css` — touch targets, font sizes
- `src/php/index.php` — reemplazar estilos inline por clases CSS, añadir right panel overlay logic
