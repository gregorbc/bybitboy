# Proyección 30 días en landing y dashboard — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mostrar una proyección de PnL a 30 días (media de PnL diario real de días completados × 30) en la landing y en el dashboard admin.

**Architecture:** Función helper `projection30d()` en `src/php/Helpers.php` (única fuente de verdad del cálculo, SQL portable a SQLite/MySQL mediante cutoff ligado como parámetro). Los endpoints `_landing_stats` (público) y `_status` (admin) la llaman y exponen `pnl_proj_30d` (float) y `pnl_proj_days` (int). La landing (`index.php` raíz) añade una celda "Est. 30 días"; el dashboard (`src/php/index.php`) añade una KPI card y reemplaza el cálculo cliente de `wProj`.

**Tech Stack:** PHP 8 (sin framework), MySQL (producción) / SQLite in-memory (tests), PHPUnit (`vendor/bin/phpunit -c phpunit.xml.dist`), JS vanilla inline.

## Global Constraints

- Helper `projection30d(PDO $db, string $symbol): array` devuelve `['proj_30d' => float, 'days' => int]`.
- Fórmula: `proj_30d = round((SUM(pnl_usd) / COUNT(DISTINCT DATE(filled_at))) * 30, 2)` de días completados (`filled_at < date('Y-m-d')`). `days=0` → `proj_30d = 0.0`.
- Misma query semántica que pnl_today/pnl_total: `symbol=?`, `grid_role='EXIT'`, `status='FILLED'`.
- NO usar `CURDATE()` (no existe en SQLite): `filled_at < ?` con `$cutoff = date('Y-m-d')`.
- Nombres de campo JSON exactos: `pnl_proj_30d` (float) y `pnl_proj_days` (int).
- Endpoint `_landing_stats` es público; `_status` exige sesión admin (gate existente en grid_ajax.php).
- Textos exactos UI: landing "Est. 30 días" / sub "basado en N día(s)" / "sin historial aún"; dashboard "Proyección 30d" / "est. N día(s)" / "sin historial".
- No añadir comentarios al código salvo los existentes. No emojis en código.
- Suite: `vendor/bin/phpunit -c phpunit.xml.dist` (NO existe `phpunit.xml`).
- No tocar `vendor/`, `docs/`, `index2.php`, `websocket_server.php`.
- No se guarda la proyección en DB; siempre se calcula sobre `grid_orders`.

---

### Task 1: Helper `projection30d()` con tests unitarios

**Files:**
- Modify: `src/php/Helpers.php` (añadir función al final del archivo)
- Test: `tests/php/Unit/HelpersTest.php`

**Interfaces:**
- Consumes: `\PDO` (objeto), `string $symbol`.
- Produces: `projection30d(PDO $db, string $symbol): array` → `['proj_30d' => float, 'days' => int]`. Tasks 2-4 dependen de este nombre exacto.

- [ ] **Step 1: Escribir los tests fallidos**

Añadir al bloque de imports de `tests/php/Unit/HelpersTest.php` (tras el `namespace Tests\Unit;` en línea 4, junto a los `use function` existentes en líneas 6-11):

```php
use function projection30d;
use Tests\Support\SqliteSchema;
```

Añadir al final de la clase (antes de la llave de cierre) el fixture sqlite y los tests:

```php
// --- fixture y tests de projection30d() ---
public function testProjection30dEmptyDbReturnsZeroDays(): void
{
    $pdo = new \PDO('sqlite::memory:');
    SqliteSchema::apply($pdo);
    $r = projection30d($pdo, 'ETHUSDT');
    $this->assertSame(0.0, $r['proj_30d']);
    $this->assertSame(0, $r['days']);
}

public function testProjection30dIgnoresTodayAndSumsCompletedDays(): void
{
    $pdo = new \PDO('sqlite::memory:');
    SqliteSchema::apply($pdo);
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $twoDaysAgo = date('Y-m-d', strtotime('-2 days'));
    $today = date('Y-m-d');
    $ins = $pdo->prepare("INSERT INTO grid_orders (symbol, grid_role, status, pnl_usd, filled_at) VALUES (?, 'EXIT', 'FILLED', ?, ?)");
    $ins->execute(['ETHUSDT', 1.0, $yesterday . ' 10:00:00']);
    $ins->execute(['ETHUSDT', 2.0, $yesterday . ' 11:00:00']);   // segundo fill mismo día: suma al día, no crea día nuevo
    $ins->execute(['ETHUSDT', 3.0, $twoDaysAgo . ' 10:00:00']);
    $ins->execute(['ETHUSDT', 99.0, $today . ' 10:00:00']);      // hoy: excluido
    $ins->execute(['BTCUSDT', 99.0, $yesterday . ' 10:00:00']);  // otro símbolo: excluido
    $r = projection30d($pdo, 'ETHUSDT');
    $this->assertSame(2, $r['days']);
    $this->assertSame(round((1.0 + 2.0 + 3.0) / 2 * 30, 2), $r['proj_30d']); // = 90.0
}

public function testProjection30dSingleCompletedDay(): void
{
    $pdo = new \PDO('sqlite::memory:');
    SqliteSchema::apply($pdo);
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $pdo->prepare("INSERT INTO grid_orders (symbol, grid_role, status, pnl_usd, filled_at) VALUES ('ETHUSDT', 'EXIT', 'FILLED', 2.5, ?)")
        ->execute([$yesterday . ' 10:00:00']);
    $r = projection30d($pdo, 'ETHUSDT');
    $this->assertSame(1, $r['days']);
    $this->assertSame(round(2.5 * 30, 2), $r['proj_30d']);
}
```

> Nota: `tests/php/Unit/HelpersTest.php` usa `namespace Tests\Unit;` — los dos `use` nuevos van en el bloque de imports tras el namespace, junto a los `use function` existentes.

- [ ] **Step 2: Ejecutar los tests para ver que fallan**

Run: `vendor/bin/phpunit -c phpunit.xml.dist --filter Projection30d tests/php/Unit/HelpersTest.php`
Expected: FAIL — `Error: Call to undefined function Tests\Unit\projection30d()`

- [ ] **Step 3: Implementar el helper**

Añadir al final de `src/php/Helpers.php` (tras `isAdminSession`, última función del archivo):

```php
function projection30d(PDO $db, string $symbol): array {
    $cutoff = date('Y-m-d');
    $row = $db->prepare(
        "SELECT COALESCE(SUM(pnl_usd),0) p, COUNT(DISTINCT DATE(filled_at)) d
         FROM grid_orders
         WHERE symbol=? AND grid_role='EXIT' AND status='FILLED'
           AND filled_at < ?"
    );
    $row->execute([$symbol, $cutoff]);
    $r = $row->fetch();
    $days = (int)($r['d'] ?? 0);
    $proj = $days > 0 ? round(((float)($r['p'] ?? 0) / $days) * 30, 2) : 0.0;
    return ['proj_30d' => $proj, 'days' => $days];
}
```

- [ ] **Step 4: Ejecutar los tests para que pasen**

Run: `vendor/bin/phpunit -c phpunit.xml.dist --filter Projection30d tests/php/Unit/HelpersTest.php`
Expected: PASS (3 tests, 6 assertions)

- [ ] **Step 5: Lint y suite completa**

Run: `php -l src/php/Helpers.php && vendor/bin/phpunit -c phpunit.xml.dist`
Expected: lint OK; suite completa PASS (los Warnings/Deprecations pre-existentes no son errores; la suite sube de 237 a 240 tests).

- [ ] **Step 6: Commit**

```bash
git add src/php/Helpers.php tests/php/Unit/HelpersTest.php
git commit -m "feat(helpers): projection30d calcula proyección 30d de PnL"
```

---

### Task 2: Exponer `pnl_proj_30d` / `pnl_proj_days` en `_landing_stats` y `_status`

**Files:**
- Modify: `src/php/grid_ajax.php:607-631` (endpoint `_landing_stats`) y `src/php/grid_ajax.php:136-154` (array `pairs['ETHUSDT']` en `_status`)
- Test: `tests/php/Integration/ApiEndpointsTest.php`

**Interfaces:**
- Consumes: `projection30d(PDO $db, string $symbol)` de Task 1.
- Produces: JSON `_landing_stats` con `pnl_proj_30d` (float) y `pnl_proj_days` (int); `_status` → `pairs.ETHUSDT.pnl_proj_30d` y `pairs.ETHUSDT.pnl_proj_days`. Task 3 consume la landing, Task 4 consume `_status`.

- [ ] **Step 1: Escribir los tests de integración fallidos**

En `tests/php/Integration/ApiEndpointsTest.php`:

En `testLandingStatsEndpointReturnsStructure` (líneas 137-149), añadir tras el assert de `pnl_total` (línea 144):

```php
        $this->assertArrayHasKey('pnl_proj_30d', $data);
        $this->assertArrayHasKey('pnl_proj_days', $data);
```

En `testLandingStatsReturnsNumericFields` (líneas 151-159), añadir tras el assert de `pnl_total`:

```php
        $this->assertIsFloat($data['pnl_proj_30d']);
        $this->assertIsInt($data['pnl_proj_days']);
```

Añadir un test nuevo tras `testLandingStatsPnlTotalIsNumeric` (línea 165):

```php
    public function testStatusPairIncludesProjectionFields(): void
    {
        $data = $this->executeEndpointAsAdmin(['_status' => '1']);
        $this->assertIsArray($data);
        $pair = $data['pairs']['ETHUSDT'] ?? null;
        $this->assertIsArray($pair);
        $this->assertArrayHasKey('pnl_proj_30d', $pair);
        $this->assertArrayHasKey('pnl_proj_days', $pair);
        $this->assertIsFloat($pair['pnl_proj_30d']);
        $this->assertIsInt($pair['pnl_proj_days']);
    }
```

- [ ] **Step 2: Ejecutar los tests para ver que fallan**

Run: `vendor/bin/phpunit -c phpunit.xml.dist --filter "LandingStats|StatusPairIncludesProjection" tests/php/Integration/ApiEndpointsTest.php`
Expected: FAIL — `assertArrayHasKey` / `assertIsFloat` / `assertIsInt` en los 3 tests.

- [ ] **Step 3: Implementar en `_landing_stats`**

En `grid_ajax.php` línea 609-610, añadir `pnl_proj_30d` y `pnl_proj_days` al array inicial `$data`:

```php
    $data = ['ok' => true, 'price' => 0.0, 'pnl_today' => 0.0, 'pnl_total' => 0.0, 'win_rate' => 0.0,
             'fills_total' => 0, 'open_orders' => 0, 'pnl_proj_30d' => 0.0, 'pnl_proj_days' => 0,
             'updated_at' => date('Y-m-d H:i:s')];
```

Tras la línea 626 (tras `open_orders`), dentro del bloque `if ($db)`:

```php
            $proj = projection30d($db, 'ETHUSDT');
            $data['pnl_proj_30d'] = $proj['proj_30d'];
            $data['pnl_proj_days'] = $proj['days'];
```

- [ ] **Step 4: Implementar en `_status`**

En `grid_ajax.php` en el array `pairs['ETHUSDT']` (líneas 136-154), tras `'pnl_total'` (línea 153):

```php
                'pnl_proj_30d'   => (float)($proj['proj_30d'] ?? 0),
                'pnl_proj_days'  => (int)($proj['days'] ?? 0),
```

Y ANTES de usar `$proj`, dentro del bloque `if ($db)` (tras la línea 129, tras el cálculo de `pnl_hourly`):

```php
            $proj = projection30d($db, 'ETHUSDT');
```

> Ojo: `$proj` debe definirse antes de usarse. En `_status`, `pairs['ETHUSDT']` se construye dentro del `try` que ya tiene `$db` — colocar `$proj = projection30d($db, 'ETHUSDT');` junto a las demás consultas (tras `$data['pnl_hourly'] = ...`).

- [ ] **Step 5: Ejecutar los tests para que pasen**

Run: `vendor/bin/phpunit -c phpunit.xml.dist --filter "LandingStats|StatusPairIncludesProjection" tests/php/Integration/ApiEndpointsTest.php`
Expected: PASS

- [ ] **Step 6: Lint y suite completa**

Run: `php -l src/php/grid_ajax.php && vendor/bin/phpunit -c phpunit.xml.dist`
Expected: lint OK; suite completa PASS.

- [ ] **Step 7: Commit**

```bash
git add src/php/grid_ajax.php tests/php/Integration/ApiEndpointsTest.php
git commit -m "feat(api): exponer pnl_proj_30d y pnl_proj_days en _landing_stats y _status"
```

---

### Task 3: Celda "Est. 30 días" en la landing

**Files:**
- Modify: `index.php:112-117` (grid de stats), `index.php:157-188` (JS `loadStats`)

**Interfaces:**
- Consumes: `pnl_proj_30d` (float) y `pnl_proj_days` (int) del JSON `_landing_stats` (Task 2), y `fmt2` (definido en `index.php:160`).
- Produces: celda HTML `#ldProj` + sub `#ldProjDays` rellenadas por `loadStats()`.

- [ ] **Step 1: Añadir la celda HTML**

En `index.php`, tras la celda "PnL total" (línea 113), añadir:

```html
      <div class="stat"><div class="stat-lbl">Est. 30 días</div>
        <div class="stat-val accent" id="ldProj">--</div>
        <div style="font-size:10px;color:var(--dim)" id="ldProjDays">--</div>
      </div>
```

El grid queda con 6 celdas en `repeat(3,1fr)` (ya aplicado): 2 filas de 3.

- [ ] **Step 2: Añadir el JS en `loadStats()`**

En `index.php`, tras el bloque que rellena `ldPnlTotal` (líneas 176-178), añadir:

```js
    const proj = parseFloat(d.pnl_proj_30d||0);
    const projDays = parseInt(d.pnl_proj_days||0, 10);
    const projEl = document.getElementById('ldProj');
    if(projDays>0){
      projEl.textContent = (proj>=0?'+':'')+fmt2(proj)+' $';
      projEl.className = 'stat-val '+(proj>=0?'up':'down');
      document.getElementById('ldProjDays').textContent = 'basado en '+projDays+' día'+(projDays!==1?'s':'');
    }else{
      projEl.textContent = '--';
      projEl.className = 'stat-val accent';
      document.getElementById('ldProjDays').textContent = 'sin historial aún';
    }
```

- [ ] **Step 3: Verificar sintaxis y suite**

Run: `php -l index.php && vendor/bin/phpunit -c phpunit.xml.dist`
Expected: lint OK; suite completa PASS.

- [ ] **Step 4: Verificar en producción (HTTP)**

Run:
```bash
curl -sk "https://binance.gregorbritez.cat/src/php/grid_ajax.php?_landing_stats=1"
```
Expected: JSON contiene `"pnl_proj_30d":<número>` y `"pnl_proj_days":<número>`.

```bash
curl -sk "https://binance.gregorbritez.cat/" | grep -o "ldProj"
```
Expected: al menos una coincidencia `ldProj` (y `ldProjDays`).

- [ ] **Step 5: Commit**

```bash
git add index.php
git commit -m "feat(landing): celda Est. 30 días en Rendimiento en vivo"
```

---

### Task 4: KPI card "Proyección 30d" y `wProj` consistente en el dashboard admin

**Files:**
- Modify: `src/php/index.php:470-474` (kpi-grid), `src/php/index.php:848-857` (JS `updateUIFromWebSocket` wProj), `src/php/index.php:1394-1423` (JS `updatePairNumbers`)

**Interfaces:**
- Consumes: `pairs.ETHUSDT.pnl_proj_30d` / `pairs.ETHUSDT.pnl_proj_days` del endpoint `_status` (Task 2), y `fM` (formateador existente del dashboard).
- Produces: KPI card HTML `#kProj` + sub `#kProjD` actualizadas en `updatePairNumbers(pair)`; `wProj` usa el valor del servidor cuando está disponible.

- [ ] **Step 1: Añadir la KPI card HTML**

En `src/php/index.php`, tras la KPI "PnL Total" (línea 474), añadir:

```html
        <div class="kpi neu">
          <div class="kpi-lbl">Proyección 30d</div>
          <div class="kpi-val" id="kProj">--</div>
          <div class="kpi-sub" id="kProjD">--</div>
        </div>
```

- [ ] **Step 2: Añadir el JS en `updatePairNumbers(pair)`**

En `src/php/index.php`, en `updatePairNumbers(pair)` tras el bloque `if(pair.pnl_total!==undefined)` (línea 1401), añadir:

```js
  const projEl = document.getElementById('kProj');
  const projDEl = document.getElementById('kProjD');
  if(pair.pnl_proj_30d!==undefined){
    const proj = parseFloat(pair.pnl_proj_30d)||0;
    const pd = parseInt(pair.pnl_proj_days||0,10);
    if(pd>0){
      projEl.textContent = fM(proj);
      projEl.className = 'kpi-val '+(proj>=0?'c-pos':'c-neg');
      projDEl.textContent = 'est. '+pd+' día'+(pd!==1?'s':'');
    }else{
      projEl.textContent = '--';
      projDEl.textContent = 'sin historial';
    }
  }
```

- [ ] **Step 3: Reemplazar el cálculo cliente de `wProj`**

Hay DOS sitios que calculan `wProj` como `avgDaily * 30`: línea 857 (en `updateUIFromWebSocket`, bloque `if (data.pair && data.pair.pnl_today !== undefined)`) y línea 1423 (en `updatePairNumbers`, bloque `if (pair.pnl_today !== undefined)`).

Para cada uno, sustituir la línea `$('wProj').innerHTML = fM(avgDaily * 30);` por:

```js
      const projSrv = parseFloat(pair.pnl_proj_30d);
      $('wProj').innerHTML = fM(!isNaN(projSrv) ? projSrv : (avgDaily * 30));
```

> En el bloque de la línea 857 la variable se llama `data.pair`, y en el de la 1423 se llama `pair` — usar el nombre de la variable del bloque correspondiente. En ambos, `avgDaily` ya está definido antes (líneas 855-856 y 1421-1422).

- [ ] **Step 4: Verificar sintaxis y suite**

Run: `php -l src/php/index.php && vendor/bin/phpunit -c phpunit.xml.dist`
Expected: lint OK; suite completa PASS.

- [ ] **Step 5: Verificar en producción (HTTP)**

Run:
```bash
curl -sk "https://binance.gregorbritez.cat/src/php/grid_ajax.php?_status=1" -b <cookies_admin>
```
Expected: `pairs.ETHUSDT` contiene `pnl_proj_30d` y `pnl_proj_days`.

```bash
curl -sk "https://binance.gregorbritez.cat/src/php/index.php" | grep -o "kProj"
```
Expected: al menos una coincidencia `kProj` y `kProjD`.

> Nota: `_status` exige sesión admin. Para el curl del endpoint basta confirmar los campos en la respuesta JSON; si no hay sesión admin disponible en la sesión curl, verificar con el test de integración `testStatusPairIncludesProjectionFields` ya cubierto en Task 2 y validar solo el grep de HTML.

- [ ] **Step 6: Commit**

```bash
git add src/php/index.php
git commit -m "feat(dashboard): KPI Proyección 30d y wProj con valor del servidor"
```

---

## Self-Review

**Cobertura del spec:**
1. Helper `projection30d()` → Task 1.
2. `_landing_stats` expone `pnl_proj_30d`/`pnl_proj_days` → Task 2.
3. `_status` expone ambos campos en `pairs.ETHUSDT` → Task 2.
4. Celda "Est. 30 días" + subtexto en landing → Task 3.
5. KPI card "Proyección 30d" en dashboard → Task 4.
6. `wProj` usa valor del servidor con fallback → Task 4.
7. Tests: unidad (Task 1) + integración (Task 2); manual (Tasks 3-4).
8. Sin placeholders; código completo en cada paso.
9. Tipos consistentes: `proj_30d` float / `days` int en helper, JSON y parse en JS (`parseFloat`/`parseInt`).
10. Sin `CURDATE()` en ningún SQL nuevo (portable SQLite).
