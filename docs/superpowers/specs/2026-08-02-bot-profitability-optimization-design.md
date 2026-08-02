# Grid Bot v16.2 — Optimización de Rentabilidad y Seguridad

**Fecha:** 2026-08-02
**Estado:** Aprobado
**Objetivo:** Restaurar la configuración segura aprobada (20x / 14 niveles / spacing 0.16%), corregir bugs de lógica que bloquean las ganancias y el compounding, y optimizar el PnL por trade dentro de un perfil de riesgo equilibrado.

---

## Contexto / Problema

El bot ETH/USDT (Bybit demo) está corriendo en un estado inconsistente y con riesgo elevado:

1. **Leverage en 100x** aunque la config aprobada era 20x. `config.json` dice `leverage: 100` (línea 11) pero la DB `grid_configs.leverage` dice `66`. El log confirma `Leverage 100x OK`. Con 100 USDT y 100x, la liquidación está a ~1% de distancia.
2. **Spacing por debajo del fee floor**: el bot usa spacing 0.0699% mientras el fee floor conservador es 0.14% → cada trade pierde dinero neto en fees.
3. **DB `fee_floor_mode = 'optimistic'`** anula el `conservative` del archivo `config.json`. El bot lee la config de DB, no del archivo.
4. **Compounding roto** en `profitOptimize()`: usa `$balance` (1.65M demo) en vez de `G_CAPITAL` (100) → `$pct` nunca alcanza el threshold → nunca compone.
5. **`getPnlToday()`** solo suma EXITs filled, no refleja uPnL de posiciones abiertas.
6. **`breakoutCheck()`** cancela TODA la red al salir de rango, perdiendo posiciones acumuladas.
7. **`buildGrid()`** valida margen contra el balance real (1.65M) en vez del capital efectivo.
8. **ML siempre predice SIDEWAYS 99%** → sin valor direccional real; la confianza 92-93% es auto-confirmación de una sola clase.

---

## Solución

### Sección 1 — Restaurar y fijar config segura (20x / 14 niveles / 0.16%)

**`src/php/config.json`:**
- `bot.leverage`: `100` → `20`
- `bot.levels`: `16` → `14`
- `bot.long_levels`: `8` → `7`
- `bot.short_levels`: `8` → `7`
- `grid.base_spacing`: `0.0005` → `0.0016` (0.16%)
- `grid.min_spacing`: `0.0001` → `0.0014` (>= fee floor conservador 0.14%)
- `fees.fee_floor_mode`: `conservative` (ya está, pero se refuerza)
- `fees.safety`: `2.0` (ya está)

**DB `grid_configs` (tabla `erika_bot`):**
```sql
UPDATE grid_configs SET
  leverage = 20,
  levels = 14,
  long_levels = 7,
  short_levels = 7,
  spacing_pct = 0.001600,
  qty_per_level = 0.0300,
  fee_floor_mode = 'conservative',
  confidence = 50,
  direction = 'SIDEWAYS'
WHERE symbol = 'ETHUSDT';
```

### Sección 2 — Corregir bugs de lógica

**`GridManager::profitOptimize()`** (`GridManager.php:1106`):
- Usar `G_CAPITAL` en vez de `$balance`:
  - `$pct = $pnlTdy / G_CAPITAL * 100`
  - `$hardCap = (G_CAPITAL * 0.12 * G_LEVERAGE) / $price`
- Así el compounding activa en ~$1.50 de PnL (1.5% de 100).

**`GridManager::getPnlToday()`** (`GridManager.php:1162`):
- Sumar uPnL de posiciones abiertas para reflejar el PnL real:
  - Consultar `api->positions()` y sumar `unRealizedProfit`.

**`GridManager::breakoutCheck()`** (`GridManager.php:1140`):
- No cancelar TODA la red en un breakout.
- Reconstruir conservando posiciones abiertas: cancelar solo ENTRYs lejanas, mantener EXITs asociados a posiciones.
- Si hay posiciones abiertas con EXIT vigentes, no cancelar esas EXIT.

**`GridManager::buildGrid()`** (`GridManager.php:674`):
- Validar margen contra `effectiveCap = min(balance, G_CAPITAL) * G_MARGIN_SAFETY`, no contra el balance real.
- Mantener la lógica anti-churn (`G_MIN_BUILD_INTERVAL`).

### Sección 3 — Optimizar ganancias (dentro de 20x)

- **Spacing conservador fijo**: `base_spacing = 0.0016` (0.16%), floor efectivo 0.14%. Cada trade: gross (0.16% × notional) > fees (0.14% conservative). PnL neto/trade ≈ $0.05.
- **`calcQty()`**: con leverage 20 y cap 40 USDT → qty ≈ 0.0300 ETH, margen ~39 USDT (igual al aprobado).
- **ML**: sesgar hacia heurística en baja confianza:
  - Si `ML confidence < 85%` → subir peso heurístico de 0.10 a 0.30.
  - Implementar en `GridManager::aiEvaluate()` ajustando `$w_ml` / `$w_heur`.

### Sección 4 — Verificación

1. `php -l` en los archivos modificados.
2. PHPUnit suite completa (140 tests) — no romper regresiones.
3. Reiniciar bot: `systemctl restart grid-bot`.
4. Verificar en `bot.log`:
   - `[Bybit] Leverage 20x OK`
   - `[CFG] feeFloor=0.1400% (mode=conservative, ...)`
   - `[CALC] Qty: 0.0300 ETH (cap=40.00 mrg/niv=...)`
   - `[GRID] ✓ 14/14 órdenes`
5. Confirmar PnL/trade > 0.05 USDT en las próximas horas.

---

## Archivos afectados

| Archivo | Cambio |
|---------|--------|
| `src/php/config.json` | Parámetros seguros (leverage 20, 14 niveles, spacing 0.0016, min_spacing 0.0014) |
| `src/php/Strategy/GridManager.php` | Fixes: profitOptimize, getPnlToday, breakoutCheck, buildGrid, sesgo ML |
| DB `grid_configs` | Sincronizar a config aprobada (20x/14L/0.16%/conservative) |

## No incluido (fuera de alcance)

- Reentrenamiento del modelo ML (posible mejora futura).
- Cambios al dashboard `index2.php`.
- Migración a mainnet real.
