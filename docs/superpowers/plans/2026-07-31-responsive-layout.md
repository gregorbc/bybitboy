# Layout Responsive "Todo Encaja" Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reestructurar `src/php/index.php` para que el dashboard se vea y encaje correctamente en PC (3 columnas), tablet (2 columnas) y móvil (1 columna con orden gráfico → hero → análisis → PnL → ladder), sin scroll horizontal.

**Architecture:** CSS Grid con áreas con nombre. Las cards importantes se mueven a un nuevo `#heroCol`; las de configuración quedan en el cajón `#sidebarLeft`; las cards centrales pasan a hijos directos de `.main-grid` (se elimina `#centerCol`). Los breakpoints son 992px (3→2 cols), 991px (2 cols), 767px (1 col).

**Tech Stack:** PHP inline (HTML + CSS + JS en `src/php/index.php`), sin build steps.

## Global Constraints

- Un único archivo modificado: `src/php/index.php`. No se tocan `grid_ajax.php`, `websocket_server.php`, `bot.php`, ni la base de datos.
- NO se duplican IDs. Ningún `id` del HTML puede aparecer más de una vez.
- No se cambia el JS de las cards ni el comportamiento de los cajones (solo layout CSS y movimiento de DOM).
- El árbol tiene cambios no confirmados preexistentes (`Helpers.php`, `websocket_server.php`, `config.json`, etc.). Nunca `git add -A` ni `git commit -am`; siempre `git add src/php/index.php` explícito.
- No reiniciar bot ni servidor WS.
- Respeta el spec: `docs/superpowers/specs/2026-07-31-responsive-layout-design.md`.

---

### Task 1: Reestructura HTML + CSS Grid responsive

**Files:**
- Modify: `src/php/index.php` (bloque de layout CSS ~líneas 146-178; HTML ~líneas 429-577)

**Interfaces:**
- Consumes: el DOM existente (bloques contiguos del cajón izquierdo y de `#centerCol`), los CSS de las cards (`kpi-grid`, `card`, `cfg-grid`, `chart-sect`, `tv-wrap`, `pnl-charts`, `ladder-wrap`, `sidebar-right`).
- Produces: nuevos elementos `#heroCol` (clase `hero-col`), clases `mkt-card` y `ladder-card` en las cards centrales, y las áreas de grid `hero/cfg/chart/mkt/pnl/cum/ladder/right`. `#centerCol` deja de existir.

- [ ] **Step 1: Reestructurar el HTML del cajón izquierdo → `#heroCol` + `#sidebarLeft`**

En `src/php/index.php`:

1. Línea 430: cambiar la apertura
   ```html
   <div class="sidebar-left" id="sidebarLeft">
   ```
   por
   ```html
   <div class="hero-col" id="heroCol">
   ```
   (El bloque 431-512 — `kpi-grid`, `upnl-float`, `grid-status-bar`, card "Señal IA", card "Estrategia & Estatus", card "Wallet", card "Próxima eval. IA" — queda como contenido de `heroCol`.)

2. Después del cierre de la card "Próxima eval. IA" (la línea que contiene `</div>` tras `id="aiBar"`, actualmente ~512) insertar:
   ```html
     </div>
     <div class="sidebar-left" id="sidebarLeft">
   ```
   Resultado: `heroCol` se cierra ahí y `sidebarLeft` abre justo antes de la card "Configuración Grid".

3. `sidebarLeft` queda cerrado por el `</div>` existente que estaba antes de `.drawer-overlay` (~línea 532). No tocar el `drawer-overlay` ni el `</div>` de cierre.

- [ ] **Step 2: Mover las cards centrales fuera de `#centerCol`**

En `src/php/index.php`:

1. Eliminar la línea `<div class="center-col" id="centerCol">` (~línea 535).
2. Eliminar el `</div>` que cerraba `#centerCol` (el que está justo después del cierre de la card "Order Ladder", ~línea 577). Las 5 cards centrales quedan como hijos directos de `.main-grid`.
3. Card "Análisis de Mercado" (~línea 551): cambiar `<div class="card">` por `<div class="card mkt-card">`. (Contexto: es el `<div class="card">` inmediatamente posterior al cierre de `.chart-sect`.)
4. Card "Order Ladder" (~línea 572): cambiar
   ```html
   <div class="card" style="flex:1;display:flex;flex-direction:column;min-height:240px">
   ```
   por
   ```html
   <div class="card ladder-card">
   ```
5. No cambiar `.chart-sect`, `.pnl-charts`, `.pnl-cum-block` (conservan sus clases).

- [ ] **Step 3: Reemplazar el bloque de layout CSS**

Reemplazar las reglas de las líneas 146-152 (`.main-grid`, `.sidebar-left`, `.sidebar-left.open`, `.drawer-overlay`, `.drawer-overlay.active`, `.center-col`, `.sidebar-right`) por:

```css
.main-grid{display:grid;flex:1;min-height:0;position:relative;gap:1px;overflow:hidden;background:var(--bg);grid-template-columns:260px minmax(0,1fr) 300px;grid-template-rows:auto auto auto auto minmax(0,1fr);grid-template-areas:"hero chart right" "hero mkt right" "cfg pnl right" "cfg cum right" "cfg ladder right"}
.hero-col{grid-area:hero;min-width:0;min-height:0;background:var(--bg2);border-right:1px solid var(--border);display:flex;flex-direction:column;gap:1px;overflow-y:auto}
.sidebar-left{grid-area:cfg;min-width:0;min-height:0;position:static;width:auto;height:auto;left:auto;top:auto;z-index:auto;border-right:1px solid var(--border);background:var(--bg2);display:flex;flex-direction:column;gap:1px;overflow-y:auto}
.sidebar-left.open{left:0}
.drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:140;display:none}
.drawer-overlay.active{display:block}
.sidebar-right{grid-area:right;min-width:0;min-height:0;position:static;width:auto;height:auto;background:var(--bg2);border-left:1px solid var(--border);display:flex;flex-direction:column;overflow-y:auto;overflow-x:hidden}
.chart-sect{grid-area:chart;min-width:0;display:flex;flex-direction:column}
.mkt-card{grid-area:mkt;min-width:0}
.pnl-charts{grid-area:pnl;min-width:0}
.pnl-cum-block{grid-area:cum;min-width:0}
.ladder-card{grid-area:ladder;min-width:0;min-height:240px;display:flex;flex-direction:column}
```

- [ ] **Step 4: Añadir el ocultado del botón ☰ en PC**

Inmediatamente después del bloque CSS del Step 3 (puede ir justo antes de `@media(max-width:768px)`), añadir:

```css
@media(min-width:992px){.menu-btn{display:none}}
```

- [ ] **Step 5: Convertir el `@media(max-width:768px)` en dos bloques: tablet (991px) y móvil (767px)**

Reemplazar TODO el bloque `@media(max-width:768px){ ... }` (líneas 153-169) por:

```css
@media(max-width:991px){
  .main-grid{grid-template-columns:260px minmax(0,1fr);grid-template-areas:"hero chart" "hero mkt" "cfg pnl" "cfg cum" "cfg ladder"}
  .sidebar-right{position:fixed;right:0;top:50px;height:calc(100% - 50px);width:90%;max-width:340px;z-index:160;transform:translateX(100%);box-shadow:-2px 0 12px rgba(0,0,0,.4);transition:transform .25s ease}
  .sidebar-right.open{transform:translateX(0);overflow-y:auto}
}
@media(max-width:767px){
  .main-grid{grid-template-columns:minmax(0,1fr);grid-template-rows:none;grid-template-areas:"chart" "hero" "mkt" "pnl" "cum" "ladder";overflow-y:auto}
  .hero-col{grid-area:hero;border-right:none}
  .sidebar-left{position:fixed;top:50px;left:-100%;width:85%;max-width:300px;height:calc(100% - 50px);z-index:150;transition:left .3s ease}
  .sidebar-left.open{left:0}
  .tv-wrap{height:300px}
  .price-live{font-size:16px}
  .ticker-block{gap:4px}
  .bid-ask{display:none}
  .status-block{gap:4px}
  .btns .btn{font-size:9px;padding:3px 6px}
  .btns .btn:nth-child(n+4){display:none}
  .mkt-analysis{grid-template-columns:repeat(2,1fr)}
  .pnl-charts{grid-template-columns:1fr}
  .kpi-grid{gap:4px}
  .kpi-val{font-size:14px}
  .brand-sub{font-size:7px}
  .btn-b{display:inline-flex}
}
```

Mantener el bloque `@media(max-width:480px){ ... }` siguiente intacto.

- [ ] **Step 6: Verificación estructural**

Run:
```bash
php -l src/php/index.php
```
Expected: `No syntax errors detected in src/php/index.php`

Run:
```bash
curl -sL 'https://binance.gregorbritez.cat/index.php' > /tmp/opencode/responsive.html
for id in heroCol sidebarLeft sidebarRight drawerOverlay chartTabPro candleChart chartLegend mktRange ladderWrap; do
  printf '%s: %s\n' "$id" "$(grep -c "id=\"$id\"" /tmp/opencode/responsive.html)";
done
printf 'centerCol: %s\n' "$(grep -c 'id="centerCol"' /tmp/opencode/responsive.html)"
printf 'mkt-card: %s\n' "$(grep -c 'class="card mkt-card"' /tmp/opencode/responsive.html)"
printf 'ladder-card: %s\n' "$(grep -c 'class="card ladder-card"' /tmp/opencode/responsive.html)"
printf 'grid-template-areas: %s\n' "$(grep -c 'grid-template-areas' /tmp/opencode/responsive.html)"
printf '991px block: %s\n' "$(grep -c '@media(max-width:991px)' /tmp/opencode/responsive.html)"
```
Expected: cada `id` → 1; `centerCol` → 0; `mkt-card` → 1; `ladder-card` → 1; `grid-template-areas` → 3; `991px block` → 1.

Run (IDs movidos no duplicados — todos deben dar 1):
```bash
for id in kpiPnlH upnlVal gridStatusTxt gLbl strategyName wBalance aiBar cNiv confChart; do
  printf '%s: %s\n' "$id" "$(grep -c "id=\"$id\"" /tmp/opencode/responsive.html)";
done
```
Expected: cada uno → 1.

- [ ] **Step 7: Commit**

```bash
git add src/php/index.php
git commit -m "feat(web): responsive grid layout for desktop, tablet, and mobile"
```

- [ ] **Step 8: Comprobar el código vivo**

Tras el commit, recargar `https://binance.gregorbritez.cat/` y confirmar visualmente con el usuario en móvil y PC (sin browser-use): 3 columnas en PC, cajón ☰ solo con Configuración Grid/Confianza IA, en móvil el orden gráfico → KPIs/Estrategia/Wallet → análisis → PnL → ladder, y sin scroll horizontal. Reportar el resultado.
