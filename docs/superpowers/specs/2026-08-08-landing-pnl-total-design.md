# Spec: PnL total en la landing (Rendimiento en vivo)

**Fecha:** 2026-08-08
**Estado:** aprobado por el usuario

## Contexto

La sección "Rendimiento en vivo" de la landing (`/`) muestra "PnL de hoy", "Win rate", "Fills totales" y "Órdenes abiertas" en un grid 2x2. Se quiere añadir "PnL total" (acumulado histórico de fills EXIT) como 5ª celda, pasando el grid a 3 columnas.

El endpoint público `_landing_stats` (`src/php/grid_ajax.php`) ya calcula el PnL total en `$r2` (línea 619: `SUM(pnl_usd)` de todos los EXIT FILLED de ETHUSDT) pero no lo expone en la respuesta.

## Decisiones confirmadas con el usuario

1. **PnL total** = suma histórica de `pnl_usd` de `grid_orders` donde `symbol='ETHUSDT'`, `grid_role='EXIT'` y `status='FILLED'` (mismo criterio que PnL de hoy, sin filtro de fecha).
2. **Disposición:** PnL total como 5ª celda, grid de 3 columnas.

## Cambios

### 1. `src/php/grid_ajax.php` — endpoint `_landing_stats`

- Añadir `'pnl_total' => 0.0` al array inicial `$data` (línea 609-610).
- En el bloque `if ($db)`, exponer el total ya calculado: `$data['pnl_total'] = round((float)($r2['p'] ?? 0), 6);`

### 2. `index.php` — landing

- CSS: `.stats-grid` pasa de `grid-template-columns:1fr 1fr` a `repeat(3,1fr)`. Añadir media queries responsivas (3 → 2 → 1 columna).
- HTML: nueva celda tras "PnL de hoy":
  ```html
  <div class="stat"><div class="stat-lbl">PnL total</div><div class="stat-val" id="ldPnlTotal">--</div></div>
  ```
- JS `loadStats()`: leer `d.pnl_total`, formatear con signo (`+`/`-`), clase `up`/`down`, igual que `ldPnl`.

### 3. Tests — `tests/php/Integration/ApiEndpointsTest.php`

- `testLandingStatsEndpointReturnsStructure`: añadir `assertArrayHasKey('pnl_total', $data)`.
- Nuevo test `testLandingStatsPnlTotalIsNumeric`: `assertIsFloat($data['pnl_total'])`.

## Resultado visual

```
PnL de hoy     |  PnL total    |  Win rate
+1,03 $        |  +1.921,46 $  |  100%
Fills totales  |  Órdenes abiertas
50             |  13
```

## Fuera de alcance (YAGNI)

- No se toca el dashboard (`src/php/index.php`), que ya muestra PnL total.
- No se añade PnL total al uPnL ni a otras métricas de la landing.
