# Fee Optimization Design — Grid Bot

## 1. Architecture

El cambio se divide en dos partes independientes:

1. **Spacing dinámico** — En `GridManager.php`, al cargar config (`loadConfig()`), calcular `min_spacing = max(config.min_spacing, (G_MAKER_FEE + G_MAKER_FEE) * G_FEE_SAFETY)`. Si el `config.spacing_pct` actual está por debajo de ese piso, se actualiza automáticamente. Esto garantiza que cada trade cubra al menos el costo de fees con margen.

2. **Corrección de cálculos** — En `GridManager.php::calcPnl()`, añadir parámetro `$isTaker = false` para usar `G_TAKER_FEE` cuando corresponda. En `index.php`, reemplazar el fee hardcodeado (0.0004) por valores servidos desde el backend vía WebSocket o variable PHP embebida, y usar el notional promedio real de los fills.

**Cambios clave:**
- `GridManager.php`: `loadConfig()` + `calcPnl()` + `onFill()` (pasar tipo de fee)
- `bot.php`: definir constantes `G_FEE_SAFETY` (1.5)
- `index.php`: usar `G_MAKER_FEE` y `G_TAKER_FEE` en JS en lugar de hardcode 0.0004; usar promedio real de fills en lugar de 115
- `websocket_server.php`: incluir `makerFee`/`takerFee` en el payload de datos

## 2. Componentes

**1. `bot.php`** — Definir constante:
```php
define('G_FEE_SAFETY', 1.5);
```

**2. `GridManager.php`** — Tres cambios:
- `loadConfig()`: Calcular `dynamic_min_spacing = max(existing_min_spacing, (G_MAKER_FEE + G_MAKER_FEE) * G_FEE_SAFETY)`. Si `spacing_pct < dynamic_min_spacing`, forzar a `dynamic_min_spacing`.
- `calcPnl($exitSide, $entryPx, $exitPx, $qty, $isTaker = false)`: Usar `G_TAKER_FEE` si `$isTaker`, sino `G_MAKER_FEE`.
- `onFill()` y `marketClose()`: Pasar `true` a `calcPnl` si la orden fue taker.

**3. `index.php`** — Cambios en JS y PHP inline:
- En `calcPnl()` JS: usar `G_MAKER_FEE` y `G_TAKER_FEE` servidos desde PHP en lugar de hardcode 0.0004
- En `updateUIFromWebSocket()` y polling: reemplazar `avgNotional = 115` por promedio real de fills

**4. `websocket_server.php`** — Añadir al payload de datos:
```php
'makerFee' => G_MAKER_FEE,
'takerFee' => G_TAKER_FEE,
'fillsTotal' => $fillsCount,
'fillsNotional' => $fillsNotionalSum,
```

## 3. Flujo de datos

1. **Inicio del bot** (`bot.php`): Se cargan `G_MAKER_FEE`, `G_TAKER_FEE`, `G_FEE_SAFETY` desde config/env
2. **`GridManager::loadConfig()`**: Calcula `dynamic_min_spacing = max(min_spacing, (G_MAKER_FEE + G_MAKER_FEE) * 1.5)`. Si `spacing_pct < dynamic_min_spacing`, actualiza en DB y en memoria
3. **`buildGrid()`**: Usa `spacing_pct` (ya ajustado) para calcular niveles de precio
4. **Cuando un fill ocurre**:
   - Si es via WebSocket/límite (`PostOnly`): `calcPnl(isTaker=false)`
   - Si es via `marketClose()`: `calcPnl(isTaker=true)`
5. **Frontend**: WebSocket envía `makerFee`/`takerFee` en cada payload. Dashboard calcula `fee_estimate = fills_total * notional_promedio * (makerFee + takerFee) / 2` usando datos reales

## 4. Manejo de errores

- Si `(G_MAKER_FEE + G_MAKER_FEE) * G_FEE_SAFETY` supera `max_spacing`, se usa `max_spacing` como tope
- Si fees son 0 o negativas, se ignora el cálculo dinámico y se usa `min_spacing` normal
- Los cambios en `calcPnl()` son retrocompatibles: `$isTaker` default `false` mantiene el comportamiento actual

## 5. Estrategia de pruebas

- Verificar que `dynamic_min_spacing` se calcule correctamente con distintas combinaciones de fees/safety
- Verificar que `calcPnl(isTaker=true)` use taker fee
- Verificar que el frontend muestre fees estimados actualizados vs hardcodeados
- 102 PHP tests + 16 JS tests deben seguir pasando