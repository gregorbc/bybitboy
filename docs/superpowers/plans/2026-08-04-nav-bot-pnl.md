# NAV con PnL Real del Bot — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** El NAV de los inversores capture el PnL real (realizado + no realizado) del bot de trading, calculado automáticamente en el loop del bot cada 5 ciclos.

**Architecture:** `GridManager::run()` llama `BotAccountingSync::sync()` (que ya existe y está correcto) cada 5 ciclos junto a `writeStatus()`. El scanner deja de escribir NAV; el bot pasa a ser el único productor de snapshots. Un fallo de contabilidad solo loguea warning, nunca rompe el trading.

**Tech Stack:** PHP 8.3, PDO (MySQL/SQLite), PHPUnit 10.5, PSR-4 `BinanceBot\` → `src/php/`.

## Global Constraints

- `BotAccountingSync::sync(PDO $pdo, \BinanceBot\Exchange\BybitFutures $api, string $symbol = 'ETHUSDT'): array` — NO se modifica su firma ni su cuerpo; ya está probado.
- `Accounting::updateNav(PDO, float $realBalance, float $walletHeld, float $botPnlTotal)` — `nav = (realBalance + walletHeld) / totalUnits`, inserta en `nav_snapshots`. NO se modifica.
- El bot usa los helpers globales `db()`/`dbx()` (definidos en `src/php/bot.php`); `GridManager` ya los usa. `db()` retorna `PDO|null`.
- `lI/lW/lE/lg` son funciones globales no-op definidas en `tests/php/Integration/indicator_stubs.php` (guard `function_exists`), cargadas por `GridMLTest`/`GridAITest` durante el discovery de PHPUnit. NUNCA redefinirlas sin guard en tests.
- Tests usan SQLite en memoria vía `Tests\Support\SqliteSchema::apply(\PDO $pdo)`.
- `BybitFutures::balance()` y `positions($symbol)` son métodos públicos. El type hint de `sync()` es la clase concreta `BybitFutures`, así que los fakes deben EXTENDERLA (no duck typing).
- Los bots/scanner corren como servicios; este plan no toca systemd.
- Prohibido `git add -A`; agregar solo rutas explícitas. No tocar archivos del usuario modificados sin stage (`src/php/Helpers.php`, `src/php/bot.php`, etc.).

---

### Task 1: Test unitario de BotAccountingSync (red→verde)

Valida que `BotAccountingSync::sync()` calcula realized/unrealized/balance correctamente e inserta el snapshot NAV. El código de `sync()` ya existe; este test lo fija.

**Files:**
- Create: `tests/php/Unit/Core/BotAccountingSyncTest.php`
- Test run: `vendor/bin/phpunit tests/php/Unit/Core/BotAccountingSyncTest.php`

**Interfaces:**
- Consumes: `BinanceBot\Core\BotAccountingSync::sync(PDO, BybitFutures, string): array`; `BinanceBot\Core\Accounting::{init,currentNav,walletHeld,totalUnits}`; `Tests\Support\SqliteSchema::apply(PDO)`.
- Produces: clase de test `BotAccountingSyncTest` con patrón de fake `FakeBybit extends BybitFutures`.

- [ ] **Step 1: Escribir el test fallante**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Accounting;
use BinanceBot\Core\BotAccountingSync;
use BinanceBot\Exchange\BybitFutures;
use Tests\Support\SqliteSchema;

class FakeBybit extends BybitFutures
{
    public float $balanceVal = 0.0;
    public array $positionsArr = [];

    public function __construct()
    {
        // Evita parent::__construct: no toca red ni usa lI()
    }

    public function balance()
    {
        return $this->balanceVal;
    }

    public function positions($symbol)
    {
        return $this->positionsArr;
    }
}

class BotAccountingSyncTest extends TestCase
{
    private \PDO $pdo;
    private FakeBybit $api;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
        $this->api = new FakeBybit();
    }

    private function seedExit(string $symbol, float $pnl): void
    {
        $this->pdo->prepare("INSERT INTO grid_orders (symbol, grid_role, status, pnl_usd, filled_at)
            VALUES (?, 'EXIT', 'FILLED', ?, DATETIME('now'))")
            ->execute([$symbol, $pnl]);
    }

    private function seedOpenGridOrder(): void
    {
        $this->pdo->exec("INSERT INTO grid_orders (symbol, grid_role, status) VALUES ('ETHUSDT', 'ENTRY', 'OPEN')");
    }

    public function testSyncWithNoPnlRecordsZeroAndInsertsSnapshot(): void
    {
        Accounting::init($this->pdo, 100000.0);
        $this->api->balanceVal = 1000.0;

        $result = BotAccountingSync::sync($this->pdo, $this->api, 'ETHUSDT');

        $this->assertTrue($result['ok']);
        $this->assertSame(0.0, $result['realized_pnl']);
        $this->assertSame(0.0, $result['unrealized_pnl']);
        $this->assertSame(0.0, $result['bot_pnl_total']);
        $this->assertSame(1000.0, $result['real_balance']);
        $this->assertSame(0.0, $result['wallet_held']);
        $this->assertSame(1.0, $result['nav']);
        $row = $this->pdo->query('SELECT * FROM nav_snapshots ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame(0.0, (float)$row['bot_pnl_total']);
    }

    public function testSyncComputesRealizedUnrealizedAndBalance(): void
    {
        Accounting::init($this->pdo, 100000.0);
        $this->seedExit('ETHUSDT', 10.1987);
        $this->seedExit('ETHUSDT', -3.5);
        $this->seedOpenGridOrder(); // no debe contar
        $this->seedExit('BTCUSDT', 99.0); // otro symbol no debe contar
        $this->api->balanceVal = 1673409.20;
        $this->api->positionsArr = [
            ['unRealizedProfit' => 0.2802],
            ['unRealizedProfit' => -1.0],
        ];

        $result = BotAccountingSync::sync($this->pdo, $this->api, 'ETHUSDT');

        $this->assertSame(6.6987, $result['realized_pnl']);
        $this->assertSame(-0.7198, $result['unrealized_pnl']);
        $this->assertSame(5.9789, $result['bot_pnl_total']);
        $this->assertSame(1673409.20, $result['real_balance']);
        $this->assertEqualsWithDelta(16.73409199, $result['nav'], 0.00000001);
    }

    public function testSyncIgnoresApiFailureForBalanceAndPositions(): void
    {
        Accounting::init($this->pdo, 100000.0);
        $this->api->balanceVal = -1.0; // -1 no es float real; usamos excepción abajo
        $this->api->positionsArr = [];

        // Simula fallo de red: balance() lanza, positions() lanza
        $throwing = new class extends FakeBybit {
            public function balance()
            {
                throw new \RuntimeException('timeout');
            }
            public function positions($symbol)
            {
                throw new \RuntimeException('timeout');
            }
        };

        $result = BotAccountingSync::sync($this->pdo, $throwing, 'ETHUSDT');

        $this->assertTrue($result['ok']);
        $this->assertSame(0.0, $result['real_balance']);
        $this->assertSame(0.0, $result['unrealized_pnl']);
        $this->assertSame(0.0, $result['bot_pnl_total']);
    }

    public function testSyncRespectsWalletHeld(): void
    {
        Accounting::init($this->pdo, 100000.0);
        $this->pdo->prepare("INSERT INTO deposits (user_id, network, token, tx_hash, block_number, amount, status, deployed)
            VALUES (1, 'eth', 'USDT', '0xaa', 1, 500.0, 'credited', 0)")
            ->execute();
        $this->api->balanceVal = 900.0;

        $result = BotAccountingSync::sync($this->pdo, $this->api, 'ETHUSDT');

        $this->assertSame(500.0, $result['wallet_held']);
        $this->assertEqualsWithDelta(1.004, $result['nav'], 0.00000001); // (900+500)/100000
    }
}
```

Nota: los tests `testSyncComputesRealizedUnrealizedAndBalance` y `testSyncRespectsWalletHeld` requieren que `grid_orders` en `SqliteSchema` tenga las columnas `symbol, grid_role, status, pnl_usd, filled_at`. `SqliteSchema::apply` crea `grid_orders` SOLO con `(id, order_id)`. Ajusta `SqliteSchema::apply` (archivo compartido) para que el DDL de `grid_orders` incluya las columnas reales:

En `tests/Support/SqliteSchema.php`, reemplazar:
```php
$pdo->exec('CREATE TABLE IF NOT EXISTS grid_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id TEXT)');
```
por:
```php
$pdo->exec('CREATE TABLE IF NOT EXISTS grid_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, symbol TEXT, grid_role TEXT, status TEXT, pnl_usd REAL, filled_at TEXT, order_id TEXT)');
```

- [ ] **Step 2: Ejecutar el test para verificar que falla**

Run: `vendor/bin/phpunit tests/php/Unit/Core/BotAccountingSyncTest.php`
Expected: FAIL en `testSyncComputesRealizedUnrealizedAndBalance` (SQLite no encuentra columna `symbol` en `grid_orders` hasta que ajustes SqliteSchema, o assertion de nav falla si ya ajustaste schema). El objetivo es ver el test ejecutarse y fallar por falta de columna o por `sync()` no definido aún — si ya ajustaste el schema, fallará solo si el cálculo no coincide.

- [ ] **Step 3: Ajustar SqliteSchema para grid_orders completo**

Aplica el reemplazo de `SqliteSchema::apply` descrito arriba en `tests/Support/SqliteSchema.php`.

- [ ] **Step 4: Ejecutar el test para verificar que pasa**

Run: `vendor/bin/phpunit tests/php/Unit/Core/BotAccountingSyncTest.php`
Expected: 4 tests PASS. Si algo falla, revisar que `sync()` consulta `WHERE symbol = '<sym>'` y `grid_role='EXIT'` y `status='FILLED'`, y que `updateNav` inserta el snapshot.

- [ ] **Step 5: Ejecutar la suite completa para no romper nada**

Run: `vendor/bin/phpunit`
Expected: todos los tests previos verdes (los que ya usan `grid_orders` mínimo — `PlatformFlowTest`/`DepositScannerTest` — deben seguir pasando con el DDL ampliado).

- [ ] **Step 6: Commit**

```bash
git add tests/php/Unit/Core/BotAccountingSyncTest.php tests/Support/SqliteSchema.php
git commit -m "test: BotAccountingSync computes real bot PnL into NAV"
```

---

### Task 2: Bot escribe NAV en su loop

**Files:**
- Modify: `src/php/Strategy/GridManager.php` (import en cabecera + método privado `syncNav()` + llamada en el loop, junto a la línea ~268 `if ($this->cycleN % 5 === 0) $this->writeStatus($price);`)
- Test: `tests/php/Unit/Strategy/GridManagerTest.php` (si existe; verificar)

**Interfaces:**
- Consumes: `BinanceBot\Core\BotAccountingSync::sync(PDO, BybitFutures, string): array`; helper global `db(): ?PDO`; constante global `G_SYM` (string, ej. 'ETHUSDT'); `$this->api` (`BybitFutures`).
- Produces: método privado `GridManager::syncNav(): void`.

- [ ] **Step 1: Revisar el test existente de GridManager**

Run: `ls tests/php/Unit/Strategy/GridManagerTest.php`
Leer el archivo para conocer su setup (si instancia `GridManager` y qué globals define: `G_SYM`, `G_CYCLE_SEC`, etc.). No romper su setup.

- [ ] **Step 2: Escribir el test que falla (para syncNav)**

Si `GridManagerTest` ya existe y tiene un patrón de instanciación, añadir este test. Si no se puede instanciar `GridManager` en tests (monolito con globals), documentar en el archivo del test por qué se cubre vía `BotAccountingSyncTest` y SKIP este paso con justificación escrita. La decisión se toma en el paso 1; el comportamiento de negocio ya queda cubierto por Task 1.

```php
public function testRunCallsSyncNavEveryFiveCycles(): void
{
    // Requiere que el test base defina G_SYM, G_CYCLE_SEC y un api fake.
    // Añadir a GridManager una bandera pública $syncNavCalled para observarla,
    // y en run() registrar cada llamada.
}
```

Nota de diseño: para hacer `syncNav` observable sin red, `GridManager` tendrá una propiedad protegida `protected array $syncNavLog = [];` y `syncNav()` hace `$this->syncNavLog[] = time();` antes de llamar a `BotAccountingSync::sync`. El test avanza ciclos llamando al cuerpo del loop (o usa reflection). Si la instanciación de `GridManager` es inviable en tests, documentar y cerrar el gap con Task 1 + revisión manual.

- [ ] **Step 3: Implementar syncNav en GridManager**

En `src/php/Strategy/GridManager.php`:

1) Añadir import junto a los `use` existentes (líneas ~21-25):
```php
use BinanceBot\Core\BotAccountingSync;
```

2) Añadir propiedad (junto a otras propiedades de estado del loop, cerca de `$this->cycleN`):
```php
private int $lastNavSyncCycle = 0;
```

3) Añadir el método privado (cerca de `writeStatus`, ~línea 1257):
```php
private function syncNav() {
    try {
        BotAccountingSync::sync(db(), $this->api, G_SYM);
        $this->lastNavSyncCycle = $this->cycleN;
    } catch (\Throwable $e) {
        lW('[NAV] sync falló: ' . $e->getMessage());
    }
}
```

4) En el loop `run()`, junto al bloque `if ($this->cycleN % 5 === 0) $this->writeStatus($price);` (línea ~268), añadir:
```php
if ($this->cycleN % 5 === 0) $this->syncNav();
```

- [ ] **Step 4: Ejecutar tests de GridManager**

Run: `vendor/bin/phpunit tests/php/Unit/Strategy/GridManagerTest.php`
Expected: PASS (los existentes no se rompen; el nuevo test, si se pudo añadir, pasa).

- [ ] **Step 5: Lint**

Run: `php -l src/php/Strategy/GridManager.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add src/php/Strategy/GridManager.php
git commit -m "feat(bot): sync investor NAV with real bot PnL every 5 cycles"
```

---

### Task 3: Scanner deja de escribir NAV

**Files:**
- Modify: `src/php/scanner.php` (eliminar bloque updateNav + `use BinanceBot\Core\Accounting;` huérfano)
- Test: `tests/php/Integration/PlatformFlowTest.php` (verificar que no dependía del NAV del scanner)

**Interfaces:**
- Consumes: estructura actual de `scanner.php` (loop con `getPdo()`, tick por red, `processPending()`).
- Produces: `scanner.php` sin ninguna referencia a `Accounting`/`updateNav`.

- [ ] **Step 1: Verificar uso de Accounting en scanner.php**

Run: `grep -n "Accounting" src/php/scanner.php`
Expected: solo en la línea ~7 (`use`) y en el bloque NAV (~línea 79).

- [ ] **Step 2: Eliminar el bloque NAV y el import**

En `src/php/scanner.php`:

1) Eliminar línea `use BinanceBot\Core\Accounting;` (línea ~7).

2) Eliminar TODO el bloque `try { ... } catch (\Throwable $e) { error_log('[scanner] nav error: ...'); }` que lee `$statusFile` y llama `Accounting::updateNav` (líneas ~66-84). Queda:

```php
    foreach (Networks::all() as $network => $net) {
        $rpcUrl = Networks::rpc($network);
        if ($rpcUrl === '') {
            continue;
        }
        try {
            $scanner = new DepositScanner($pdo, new RpcClient($rpcUrl), $network, Networks::confirmations($network), $net['contracts'] ?? [], $minAmount);
            $tick = $scanner->tick();
            $proc = $scanner->processPending();
            error_log(sprintf('[scanner:%s] head=%d from=%d to=%d logs=%d new=%d credited=%d failed=%d', $network, $tick['head'], $tick['from'], $tick['to'], $tick['logs'], $tick['inserted'], $proc['credited'], $proc['failed']));
        } catch (\Throwable $e) {
            error_log("[scanner:$network] error: " . $e->getMessage());
        }
    }
    sleep($interval);
```

Nota: `$statusFile` ya no se usa; eliminarla de la lectura de config si queda huérfana (línea ~30 `$statusFile = (string)...`). Verificar también que `$pdo = getPdo();` del bucle de NAV se conserva para el siguiente `foreach` — la estructura del bucle actual tiene `$pdo = getPdo()` al inicio del `while`, el bloque NAV re-lee `getPdo()`; al eliminarlo, el `while` debe mantener su `getPdo()` inicial tal como está.

- [ ] **Step 3: Lint**

Run: `php -l src/php/scanner.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Verificar tests de integración**

Run: `vendor/bin/phpunit tests/php/Integration/PlatformFlowTest.php`
Expected: PASS (ningún test dependía del NAV del scanner).

- [ ] **Step 5: Commit**

```bash
git add src/php/scanner.php
git commit -m "refactor(scanner): stop writing NAV; bot owns NAV snapshots"
```

---

### Task 4: Verificación final en vivo

**Files:**
- Ninguno (verificación operativa).

- [ ] **Step 1: Suite completa**

Run: `vendor/bin/phpunit`
Expected: todos los tests verdes (213+ tests). Registrar el número final de tests/assertions.

- [ ] **Step 2: Lint global de archivos tocados**

Run: `php -l src/php/Strategy/GridManager.php && php -l src/php/scanner.php && php -l tests/php/Unit/Core/BotAccountingSyncTest.php`
Expected: `No syntax errors detected` ×3.

- [ ] **Step 3: Reiniciar bot y verificar NAV con PnL**

```bash
# Identificar el servicio del bot
systemctl list-units 'grid*' --no-pager
```

Reiniciar el bot (el servicio real o el comando que lo lance). Esperar 2 ciclos (≈80s). Verificar que `nav_snapshots` tiene entradas nuevas con `bot_pnl_total` ≠ 0 (cuando exista PnL) o coherente:

```bash
mysql -u <user> -p<pass> erika_bot -e "SELECT id, nav, bot_pnl_total, snapshot_at FROM nav_snapshots ORDER BY id DESC LIMIT 5;"
```

Expected: snapshots nuevos cada ~40s con `bot_pnl_total` reflejando el PnL real del bot (realizado de `grid_orders` + no realizado de posiciones).

- [ ] **Step 4: Verificar scanner sigue procesando depósitos sin NAV**

Run: `journalctl -u binance-scanner --no-pager -n 10`
Expected: líneas `[scanner:eth]` / `[scanner:bsc]` con head avanzando, SIN líneas `nav error` ni `[scanner] nav`.

- [ ] **Step 5: Commit final si hubo ajustes**

Si los pasos 1-4 revelaron ajustes pendientes, commitearlos explícitamente. Si todo quedó en los commits de Tasks 1-3, no hay commit adicional.

---

## Self-Review

**Spec coverage:**
- "Bot actualiza NAV en su loop cada 5 ciclos" → Task 2 (GridManager syncNav + llamada en `cycleN % 5 === 0`).
- "Solo el bot escribe NAV; scanner deja updateNav" → Task 3 (eliminar bloque + import).
- "Frecuencia cada 5 ciclos (≈40s)" → Task 2, junto a writeStatus.
- "Testing: BotAccountingSyncTest con fake BybitFutures; casos A/B/C" → Task 1 (caso sin PnL, caso con realized+unrealized, caso API failure, caso walletHeld).
- "Sin cambios en BotAccountingSync / Accounting / Schema" → plan no toca esos archivos de producción (solo amplía DDL de test en SqliteSchema, que es de test).

**Placeholder scan:** los pasos tienen código completo. Task 2 Step 2 reconoce explícitamente que si `GridManager` no es instanciable en tests, se documenta y se cubre vía Task 1 — decisión de diseño declarada, no placeholder.

**Type consistency:** `BotAccountingSync::sync(PDO, BybitFutures, string): array` idéntico en Tasks 1 y 2. `db(): ?PDO`, `G_SYM` (string), `$this->api` (BybitFutures) consistentes. `Accounting::init`/`currentNav`/`walletHeld` usados como están definidos en `Accounting.php`. El fake `FakeBybit extends BybitFutures` sobreescribe `balance()`/`positions()` con firmas compatibles (sin type hints en la clase base → permitido).
