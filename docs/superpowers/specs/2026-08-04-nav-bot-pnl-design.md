# Diseño: NAV con PnL real del bot

Fecha: 2026-08-04

## Problema

El NAV de los inversores no refleja el rendimiento real del bot de trading. El scanner
escribe snapshots de NAV en cada ciclo, pero lee `pnl_total` desde `grid_status.json`,
clave que el bot nunca escribe → el PnL del bot siempre suma 0 al NAV. El cálculo del
PnL real ya existe (`accounting:sync-bot`, commit `78d9251`) pero solo corre manualmente
vía CLI, nadie lo automatiza.

## Objetivo

Que el NAV capture el PnL real del bot (realizado + no realizado) automáticamente,
ejecutado desde el propio loop del bot. Sin dobles snapshots.

## Decisiones acordadas

1. **El bot actualiza NAV en su loop** — `GridManager::run()` llama a
   `BotAccountingSync::sync()` cada 5 ciclos (≈40s con `bot.cycle_sec=8`), mismo ritmo
   que `writeStatus()`.
2. **Solo el bot escribe NAV** — el scanner deja de llamar `Accounting::updateNav()`.
   El scanner queda como procesador puro de depósitos.
3. **Frecuencia** — cada 5 ciclos, junto al bloque `cycleN % 5 === 0` existente.

## Contexto técnico verificado

- `bot.php` autoladea `BinanceBot\*` vía PSR-4 (`vendor/autoload.php`), por lo que
  `BotAccountingSync` y `Accounting` son importables desde `GridManager`.
- `GridManager::run()` (GridManager.php:228-272) ejecuta `while ($this->running)`, con
  `$this->api` (`BybitFutures`) disponible y el helper global `db()`/`dbx()` (PDO).
- `BotAccountingSync::sync(PDO $pdo, BybitFutures $api, string $symbol = 'ETHUSDT')`
  (Core/BotAccountingSync.php:10-46) ya calcula:
  - PnL realizado: `SUM(pnl_usd)` de `grid_orders` con `grid_role='EXIT'`,
    `status='FILLED'`, all-time, por symbol.
  - PnL no realizado: `$api->positions($symbol)`, suma de `unRealizedProfit`.
  - Balance real: `$api->balance()`.
  - `walletHeld`: `Accounting::walletHeld($pdo)`.
  - `Accounting::updateNav($pdo, $realBalance, $walletHeld, $botPnlTotal)`.
- `writeStatus()` (GridManager.php:1257-1299) ya consulta `$this->api->positions()` y
  `$this->api->balance()` cada 5 ciclos; el coste extra de la sync son ~2 llamadas Bybit
  más por ciclo de 40s (dentro de límites de rate).
- `updateNav()` (Accounting.php:142-156): `nav = (realBalance + walletHeld) / totalUnits`,
  inserta en `nav_snapshots`.
- `db()`/`dbx()` están definidos en bot.php (global) y usados en GridManager.

## Cambios

### 1. GridManager.php — bot escribe NAV en el loop

En `run()`, dentro del `try` del loop principal, junto al bloque existente:

```php
if ($this->cycleN % 5 === 0) $this->writeStatus($price);
if ($this->cycleN % 5 === 0) $this->syncNav();
```

Nuevo método privado:

```php
private function syncNav() {
    try {
        \BinanceBot\Core\BotAccountingSync::sync(db(), $this->api, G_SYM);
    } catch (\Throwable $e) {
        lW('[NAV] sync falló: ' . $e->getMessage());
    }
}
```

- Un fallo de contabilidad solo loguea warning; nunca rompe el trading.
- `use BinanceBot\Core\BotAccountingSync;` en el encabezado del archivo.

### 2. scanner.php — eliminar updateNav

Eliminar el bloque completo que lee `grid_status.json` y llama `Accounting::updateNav()`
(actualmente scanner.php:69-84). El scanner conserva: conexión DB con reconexión, tick de
cada red, `processPending()`. Se retira también el `use Accounting;` si queda huérfano.

## Errores y límites

- `BotAccountingSync::getRealBalance()` y `getUnrealizedPnl()` ya capturan `\Throwable`
  internamente y devuelven `0.0` → fallos puntuales de Bybit no rompen la sync ni el loop.
- `syncNav()` envuelve la llamada completa en `try/catch` como red de seguridad final.
- La sync corre en el hilo del bot; un timeout largo de Bybit no puede pausar el trading
  porque `balance()`/`positions()` usan timeouts de red de `BybitFutures` y la excepción
  se captura en `getRealBalance`/`getUnrealizedPnl`.

## Testing

1. **`tests/php/Unit/Core/BotAccountingSyncTest.php`** (nuevo):
   - Stub anónimo de `BybitFutures` (duck typing: solo `balance()` y `positions()`),
     sin tocar red ni `lI()` del constructor.
   - Setup: schema de `grid_orders`, `nav_snapshots`, `shares`, `bot_meta`.
   - Caso A: sin órdenes, balance 1000, sin posiciones → `nav_snapshots` con NAV
     `(1000+walletHeld)/totalUnits`, `bot_pnl_total=0`.
   - Caso B: una fila `grid_orders` EXIT FILLED con `pnl_usd=10.1987` + posición
     `unRealizedProfit=0.2802` + balance 1673409.20 → assert `realized_pnl`,
     `unrealized_pnl`, `bot_pnl_total` y que se insertó snapshot.
   - Caso C: `balance()` lanza excepción → sync no lanza, usa 0.0.

2. **`tests/php/Integration/PlatformFlowTest.php`** (extender o nuevo caso):
   - Tras un `BotAccountingSync::sync()` con datos, `Accounting::currentNav()` refleja el
     PnL (el patrón ya está en PlatformFlowTest.php:51 con `updateNav`).

3. No se escriben tests de `GridManager` (monolito con globals; el cambio es una llamada
   trivial). El comportamiento de sync se cubre con los tests unitarios de
   `BotAccountingSync`.

## Fuera de alcance (YAGNI)

- Auto-deploy de depósitos creditados al exchange (sigue manual vía admin).
- Sizing dinámico de capital del bot según depósitos.
- Timer systemd para `accounting:sync-bot` (se reemplaza por la sync en el loop).
