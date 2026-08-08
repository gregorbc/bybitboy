# PnL total en la landing (Rendimiento en vivo) — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mostrar "PnL total" (acumulado histórico) en la sección "Rendimiento en vivo" de la landing, como 5ª celda en un grid de 3 columnas.

**Architecture:** El endpoint público `_landing_stats` ya calcula el PnL total en `$r2` pero no lo expone; se añade `pnl_total` a la respuesta JSON. La landing (`index.php`) añade una celda HTML y la rellena en `loadStats()`.

**Tech Stack:** PHP (endpoint `grid_ajax.php`), HTML/CSS/JS inline en `index.php`, PHPUnit.

## Global Constraints

- `symbol='ETHUSDT'`, `grid_role='EXIT'`, `status='FILLED'` — mismo criterio que PnL de hoy, sin filtro de fecha (suma histórica).
- `pnl_total` se redondea a 6 decimales como `pnl_today` (`round(..., 6)`).
- Formato en landing: signo `+`/`-`, formato `fmt2` (2 decimales es-PY), clase `up`/`down`.
- Suite: `vendor/bin/phpunit -c phpunit.xml.dist` (NO existe `phpunit.xml`).
- Rutas canónicas: `index.php` en raíz del web root; `src/php/grid_ajax.php`; tests en `tests/php/Integration/ApiEndpointsTest.php`.
- Nada de emojis en código, sin comentarios nuevos salvo los existentes.

---

### Task 1: Exponer `pnl_total` en el endpoint `_landing_stats`

**Files:**
- Modify: `src/php/grid_ajax.php:609-625`
- Test: `tests/php/Integration/ApiEndpointsTest.php:137-158`

**Interfaces:**
- Consumes: nada nuevo (usa `$r2` ya calculado en el bloque `if ($db)`).
- Produces: respuesta JSON de `_landing_stats` con clave `pnl_total` (float, redondeado a 6 decimales) además de las claves existentes.

- [ ] **Step 1: Escribir el test que falla**

Añadir a `tests/php/Integration/ApiEndpointsTest.php` en la clase `ApiEndpointsTest`, dentro de `testLandingStatsEndpointReturnsStructure` (líneas 142-146, tras el assert de `pnl_today`):

```php
        $this->assertArrayHasKey('pnl_total', $data);
```

Y añadir un nuevo test después de `testLandingStatsReturnsNumericFields` (tras la línea 158):

```php
    public function testLandingStatsPnlTotalIsNumeric(): void
    {
        $data = $this->executeEndpoint(['_landing_stats' => '1']);
        $this->assertIsFloat($data['pnl_total']);
    }
```

- [ ] **Step 2: Ejecutar el test para verificar que falla**

Run: `vendor/bin/phpunit -c phpunit.xml.dist --filter "testLandingStatsEndpointReturnsStructure|testLandingStatsPnlTotalIsNumeric"`
Expected: FAIL — `Undefined array key "pnl_total"`.

- [ ] **Step 3: Implementar en el endpoint**

En `src/php/grid_ajax.php`, en el array inicial de `$data` (línea 609-610), añadir `'pnl_total'`:

```php
    $data = ['ok' => true, 'price' => 0.0, 'pnl_today' => 0.0, 'pnl_total' => 0.0, 'win_rate' => 0.0,
             'fills_total' => 0, 'open_orders' => 0, 'updated_at' => date('Y-m-d H:i:s')];
```

En el bloque `if ($db)`, justo después de la línea que asigna `pnl_today` (línea 622), añadir:

```php
            $data['pnl_total']   = round((float)($r2['p'] ?? 0), 6);
```

- [ ] **Step 4: Ejecutar los tests para verificar que pasan**

Run: `vendor/bin/phpunit -c phpunit.xml.dist --filter "testLandingStatsEndpointReturnsStructure|testLandingStatsPnlTotalIsNumeric"`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/php/grid_ajax.php tests/php/Integration/ApiEndpointsTest.php
git commit -m "feat(landing): exponer pnl_total en _landing_stats"
```

---

### Task 2: Añadir celda "PnL total" a la landing

**Files:**
- Modify: `index.php:47` (CSS), `index.php:109-114` (HTML), `index.php:154-182` (JS)

**Interfaces:**
- Consumes: clave `pnl_total` del endpoint `_landing_stats` (Task 1).
- Produces: celda `#ldPnlTotal` en la sección "Rendimiento en vivo", rellenada por `loadStats()`.

- [ ] **Step 1: CSS — grid a 3 columnas**

En `index.php` línea 47, reemplazar:

```css
.stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
```

por:

```css
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
@media(max-width:767px){.stats-grid{grid-template-columns:1fr 1fr}}
@media(max-width:480px){.stats-grid{grid-template-columns:1fr}}
```

- [ ] **Step 2: HTML — nueva celda PnL total**

En `index.php`, tras la línea 110 (celda `PnL de hoy`), añadir:

```html
      <div class="stat"><div class="stat-lbl">PnL total</div><div class="stat-val" id="ldPnlTotal">--</div></div>
```

- [ ] **Step 3: JS — rellenar PnL total**

En `index.php`, en `loadStats()` (línea 164), añadir tras `const pnl = parseFloat(d.pnl_today||0);`:

```js
    const pnlTotal = parseFloat(d.pnl_total||0);
```

Y tras el bloque que rellena `ldPnl` (líneas 169-171), añadir:

```js
    const pnlTotalEl = document.getElementById('ldPnlTotal');
    pnlTotalEl.textContent = (pnlTotal>=0?'+':'')+fmt2(pnlTotal)+' $';
    pnlTotalEl.className = 'stat-val '+(pnlTotal>=0?'up':'down');
```

- [ ] **Step 4: Verificar sintaxis y suite**

Run: `php -l index.php && vendor/bin/phpunit -c phpunit.xml.dist`
Expected: lint OK; suite PASS (236 tests).

- [ ] **Step 5: Verificar en producción (HTTP)**

Run:
```bash
curl -sk "https://binance.gregorbritez.cat/src/php/grid_ajax.php?_landing_stats=1"
```
Expected: JSON contiene `"pnl_total":<número>`.
```bash
curl -sk "https://binance.gregorbritez.cat/" | grep -o "ldPnlTotal"
```
Expected: al menos una coincidencia `ldPnlTotal`.

- [ ] **Step 6: Commit**

```bash
git add index.php
git commit -m "feat(landing): celda PnL total en Rendimiento en vivo"
```

---

## Self-Review

**Cobertura del spec:**
1. Endpoint expone `pnl_total` → Task 1.
2. Grid 3 columnas + media queries → Task 2 Step 1.
3. Celda HTML `#ldPnlTotal` → Task 2 Step 2.
4. JS rellena con signo/clase → Task 2 Step 3.
5. Tests de estructura y numérico → Task 1 Steps 1-4.
6. Sin placeholders; código completo en cada paso.
7. Tipos consistentes: `pnl_total` float redondeado a 6 en endpoint; `parseFloat` + `fmt2` en JS.
