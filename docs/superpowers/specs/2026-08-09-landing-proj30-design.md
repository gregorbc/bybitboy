# Spec: Proyección 30 días en landing y dashboard

**Fecha:** 2026-08-09
**Estado:** aprobado por el usuario

## Contexto

La landing (`/`) muestra en "Rendimiento en vivo" PnL de hoy, PnL total, Win rate, Fills totales y Órdenes abiertas. El dashboard admin (`src/php/index.php`) muestra una proyección 30d en la card Wallet (`wProj`) calculada en el cliente con `pnl_total / díasTranscurridosEnElNavegador × 30` — fórmula imprecisa (depende del `startTs` de la sesión, no de días reales de operación).

Se quiere una proyección a 30 días con base honesta (media de PnL diario real de días completados) y mostrarla tanto en la landing como en el dashboard, de forma consistente.

**Historial actual:** ~1.5 días de operación (50 fills, PnL total ≈ $3.03). Solo hay 1 día completo (2026-08-07). La media diaria será poco representativa al inicio, por eso la UI siempre indica el número de días base.

## Decisiones confirmadas con el usuario

1. **Fórmula:** media diaria = `SUM(pnl_usd)` de días completados / `COUNT(DISTINCT DATE(filled_at))` de días completados; proyección = media × 30, redondeada a 2 decimales.
2. **Días completados** = `filled_at < date('Y-m-d')` (hoy excluido, el día en curso está parcial).
3. **Enfoque:** función helper compartida `projection30d()` en `src/php/Helpers.php`, usada por ambos endpoints (no duplicar SQL).
4. **Presentación:** etiqueta "Est. 30 días" con subtexto "basado en N días". Si no hay días completados → `--` / "sin historial aún".
5. **Dashboard:** nueva KPI card "Proyección 30d" + reemplazar el `wProj` de Wallet para usar el mismo valor del backend.

## Cambios

### 1. `src/php/Helpers.php` — helper `projection30d`

Nueva función junto a los demás helpers:

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

- Devuelve `['proj_30d' => float, 'days' => int]`.
- `days=0` (sin días completados) → `proj_30d = 0.0`.
- Misma query semántica que pnl_today/pnl_total (`symbol='ETHUSDT'`, `grid_role='EXIT'`, `status='FILLED'`).
- `filled_at < ?` con `$cutoff = date('Y-m-d')` en vez de `CURDATE()`: equivalente en MySQL (hoy excluido) y portable a SQLite (los tests unitarios usan `sqlite::memory:` donde `CURDATE()` no existe).

### 2. `src/php/grid_ajax.php` — endpoints

**`_landing_stats`** (líneas 607-631):
- Array inicial `$data` (609-610): añadir `'pnl_proj_30d' => 0.0, 'pnl_proj_days' => 0`.
- Bloque `if ($db)`: `$proj = projection30d($db, 'ETHUSDT'); $data['pnl_proj_30d'] = $proj['proj_30d']; $data['pnl_proj_days'] = $proj['days'];`

**`_status`** (líneas 101+, array `pairs['ETHUSDT']` ~136-154):
- Añadir al array del par: `'pnl_proj_30d' => ...` y `'pnl_proj_days' => ...`, calculados con `projection30d($db, 'ETHUSDT')`.

### 3. `index.php` — landing

- HTML: nueva celda tras "PnL total" (línea 113):
  ```html
  <div class="stat"><div class="stat-lbl">Est. 30 días</div>
    <div class="stat-val accent" id="ldProj">--</div>
    <div style="font-size:10px;color:var(--dim)" id="ldProjDays">--</div>
  </div>
  ```
  El grid queda en `repeat(3,1fr)` (ya aplicado en tarea anterior): 6 celdas = 2 filas de 3.
- JS `loadStats()` (tras bloque `ldPnlTotal`, ~línea 178):
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
- Reutiliza `fmt2` existente y las clases `up`/`down`/`accent` ya definidas.

### 4. `src/php/index.php` — dashboard admin

- HTML: nueva KPI card tras "PnL Total" (línea 474):
  ```html
  <div class="kpi neu">
    <div class="kpi-lbl">Proyección 30d</div>
    <div class="kpi-val" id="kProj">--</div>
    <div class="kpi-sub" id="kProjD">--</div>
  </div>
  ```
- JS: en `updatePairNumbers(pair)` (tras `kPnlT`, línea 1401) y en el punto que procesa `data.pair` (tras `wProj`, línea 857):
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
- Reemplazar el cálculo cliente de `wProj` (líneas 855-857 y 1421-1423): usar `pair.pnl_proj_30d` cuando esté disponible, con fallback al cálculo actual si no:
  ```js
  const projSrv = parseFloat(pair.pnl_proj_30d);
  const projVal = !isNaN(projSrv) ? projSrv : (avgDaily * 30);
  $('wProj').innerHTML = fM(projVal);
  ```

## Resultado visual (landing)

```
PnL de hoy     |  PnL total      |  Est. 30 días
+1,03 $        |  +3,03 $        |  +59,70 $
                               basado en 1 día
Fills totales  |  Órdenes abiertas
50             |  13
```

## Testing

### TDD unidad — `projection30d()`

Nuevo test en `tests/php/Unit/HelpersTest.php` usando el patrón del repo: `new \PDO('sqlite::memory:')` + `Tests\Support\SqliteSchema::apply($pdo)` (como `BotAccountingSyncTest`), e insertando filas en `grid_orders` con `filled_at` controlado (días pasados vs hoy, p.ej. `date('Y-m-d')` para hoy y `date('Y-m-d', strtotime('-1 day'))` para ayer — el helper usa `filled_at < date('Y-m-d')` como cutoff):

1. DB vacía → `['proj_30d' => 0.0, 'days' => 0]`.
2. Fills en 2 días completos (hoy excluido) → `days=2`, `proj_30d = round((p1+p2)/2*30, 2)`.
3. Fill del día en curso NO cuenta ni suma.
4. `days=1` → `proj_30d = round(pnl_ayer*30, 2)`.

### Integración — `ApiEndpointsTest.php`

- `_landing_stats`: `assertArrayHasKey('pnl_proj_30d')`, `assertIsFloat`, `assertArrayHasKey('pnl_proj_days')`, `assertIsInt`.
- `_status` (admin): `pairs.ETHUSDT` incluye ambos campos con los tipos correctos.

### Manual

- `php -l` en `Helpers.php`, `grid_ajax.php`, `index.php` (raíz) y `src/php/index.php`.
- `vendor/bin/phpunit -c phpunit.xml.dist`.
- `curl -sk "https://binance.gregorbritez.cat/src/php/grid_ajax.php?_landing_stats=1"` → contiene `"pnl_proj_30d"` y `"pnl_proj_days"`.
- `curl -sk "https://binance.gregorbritez.cat/" | grep -o "ldProj"` → al menos una coincidencia.

## Fuera de alcance (YAGNI)

- No se toca `index2.php` ni `websocket_server.php` (no consumidos por landing/dashboard en este flujo).
- No se muestra la proyección en porcentaje ni como ROI.
- No se cambia la fórmula del uPnL.
- No se guarda la proyección en DB (siempre se calcula sobre `grid_orders`).
