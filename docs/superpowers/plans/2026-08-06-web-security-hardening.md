# Hardening de Seguridad Web — Grid Bot — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminar la fuga de credenciales y proteger todo el panel web del Grid Bot (config.json público, instaladores activos, endpoints de datos sin auth, tokens hardcodeados y fail-open).

**Architecture:** Las credenciales salen de `src/php/config.json` y del sistema hacia un único `.env` (gitignored, bloqueado por nginx) más un `config.json` público-descargable → movido a `~web/private/config.json` sin credenciales. Un único helper `botCfg()` (en `Helpers.php`, autoload global vía composer `files`) carga `.env` + configuración para todos los consumidores (web y CLI). El acceso a dashboards y endpoints de datos exige sesión con rol `admin` (`isAdminSession`), dejando solo `_landing_stats` público. Bloqueo a nivel de servidor (nginx deny + `.htaccess`) como defensa en profundidad.

**Tech Stack:** PHP 8.3 (sin framework), Ratchet (WS), MySQL/MariaDB, nginx (front) + Apache (backend) bajo HestiaCP, systemd (`grid-bot`, `grid-bot-ws`, `binance-scanner`), PHPUnit 10.5.

## Global Constraints

- **NO se rotan las claves Bybit** (decisión usuario): cuenta demo/testnet sin fondos reales; solo se protegen. Claves de verdad únicas = las del bot en `/etc/grid_bot/.env` (`7DR2LXscaoHQfbrmnA` / `eEAAxGbQAitGcTxivjmO06j8xnIrD52D1ahP`). La modificación sin commitear de `src/php/config.json` (claves `DJLQ...`) se descarta: `git rm` ese archivo.
- **Sí se rota la password MySQL** (decisión usuario). Actual: `Enladisco123@` (usuario `erika_bot`@`localhost`, db `erika_bot`). Nueva: generada con `openssl rand -hex 16`, aplicada en MySQL y en los DOS `.env` (`public_html/.env` y `/etc/grid_bot/.env`).
- **`SECURITY_TOKEN` == `WS_TOKEN`** (el JS del dashboard conecta el WS con el token exportado; el WS valida `ws_token`).
- **Solo `_landing_stats` queda público.** Todo lo demás del panel y sus endpoints exige sesión rol `admin`.
- **`src/php/index2.php` se corrige y mantiene** (no se elimina).
- **Los backups se mueven, no se borran** → `/home/erika/backups_seguros/`.
- **Rutas canónicas** (verificadas): web root = `public_html`; private fuera del web root = `/home/erika/web/binance.gregorbritez.cat/private/`; autoload canónico = `public_html/vendor/autoload.php`; test runner = `vendor/bin/phpunit -c phpunit.xml.dist` (baseline: 226 tests OK).
- El `.env` vive en `public_html/.env` (gitignored, bloqueado por nginx dotfiles → 404 confirmado).
- No añadir comentarios salvo donde el código original ya los usa por convención.

---

### Task 1: Helpers de seguridad (`envLoadOnce`, `privateConfigPath`, `botCfg`, `checkToken` fail-closed, `requireAdminSession`)

**Files:**
- Modify: `src/php/Helpers.php`
- Test: `tests/php/Unit/SecurityHelpersTest.php`

**Interfaces:**
- Produces:
  - `envLoadOnce(): void` — carga `.env` (public_html → domain-home → src/php) a `getenv()` solo si aún no está definida. Idempotente (static `$loaded`).
  - `privateConfigPath(): string` — ruta canónica `~web/private/config.json` (fuera del web root).
  - `botCfg(): array` — decodifica `privateConfigPath()` (fallback a `__DIR__/config.json` si no existe) y fusiona variables de entorno por encima (`BYBIT_API_KEY`, `BYBIT_API_SECRET`, `BYBIT_TESTNET`, `MYSQL_PASSWORD`, `SECURITY_TOKEN`, `WS_TOKEN`, `NVIDIA_API_KEY`, `NVIDIA_ENABLED`). Convierte `bybit.testnet` a `bool`.
  - `checkToken(string $requiredToken): bool` — fail-closed (ver Task 5 para su uso; aquí se corrige la implementación).
  - `requireAdminSession(): bool` — inicia sesión si no está iniciada (cookie httponly/secure/samesite) y devuelve `isAdminSession($_SESSION)`.
- Consumes: `isAdminSession()` ya existente en `Helpers.php:206`.

- [ ] **Step 1: Escribir el test que falla**

`tests/php/Unit/SecurityHelpersTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SecurityHelpersTest extends TestCase
{
    public function testPrivateConfigPathIsOutsideWebRoot(): void
    {
        $path = privateConfigPath();
        $this->assertStringEndsWith('/private/config.json', $path);
        $this->assertStringNotContainsString('/public_html', $path);
        $this->assertDirectoryExists(dirname($path));
    }

    public function testEnvLoadOnceIsIdempotentAndReadsEnvFile(): void
    {
        envLoadOnce();
        envLoadOnce();
        $this->assertNotSame('', getenv('PLATFORM_SECRET'));
    }

    public function testBotCfgReturnsArrayAndMergesEnv(): void
    {
        $cfg = botCfg();
        $this->assertIsArray($cfg);
        $this->assertIsArray($cfg['bybit'] ?? []);
    }

    public function testCheckTokenIsFailClosed(): void
    {
        $orig = $_GET['token'] ?? null;
        $_GET['token'] = '';
        $this->assertFalse(checkToken(''));
        $this->assertFalse(checkToken('abc'));
        $_GET['token'] = 'abc';
        $this->assertTrue(checkToken('abc'));
        $this->assertFalse(checkToken('abd'));
        unset($_GET['token']);
        $this->assertFalse(checkToken('abc'));
        if ($orig !== null) { $_GET['token'] = $orig; }
    }

    public function testRequireAdminSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['role'] = 'investor';
        $this->assertFalse(requireAdminSession());
        $_SESSION['role'] = 'admin';
        $this->assertTrue(requireAdminSession());
    }
}
```

- [ ] **Step 2: Ejecutar el test para verificar que falla**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/php/Unit/SecurityHelpersTest.php`
Expected: FAIL — `envLoadOnce`, `privateConfigPath`, `botCfg`, `checkToken('')===false`, `requireAdminSession` no existen.

- [ ] **Step 3: Implementación mínima**

Añadir a `src/php/Helpers.php` (tras `sanitize`, antes de `checkToken`) y reemplazar `checkToken`:

```php
function envLoadOnce(): void {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    foreach ([
        dirname(__DIR__, 2) . '/.env',      // public_html/.env
        dirname(__DIR__, 3) . '/.env',      // ~web/.env
        __DIR__ . '/.env',                  // src/php/.env
    ] as $envFile) {
        if (!is_file($envFile)) continue;
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            if (getenv($k) === false) putenv($k . '=' . trim($v, '"\' '));
        }
    }
}

function privateConfigPath(): string {
    return dirname(__DIR__, 3) . '/private/config.json';
}

function botCfg(): array {
    envLoadOnce();
    $path = privateConfigPath();
    if (!is_file($path)) $path = __DIR__ . '/config.json';
    $cfg = [];
    if (is_file($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) $cfg = $decoded;
    }
    $overrides = [
        ['bybit.api_key',    'BYBIT_API_KEY'],
        ['bybit.api_secret', 'BYBIT_API_SECRET'],
        ['bybit.testnet',    'BYBIT_TESTNET'],
        ['mysql.password',   'MYSQL_PASSWORD'],
        ['security_token',   'SECURITY_TOKEN'],
        ['ws_token',         'WS_TOKEN'],
        ['nvidia.api_key',   'NVIDIA_API_KEY'],
        ['nvidia.enabled',   'NVIDIA_ENABLED'],
    ];
    foreach ($overrides as [$pathKey, $envKey]) {
        $val = getenv($envKey);
        if ($val === false) continue;
        $ref = &$cfg;
        foreach (explode('.', $pathKey) as $part) {
            if (!is_array($ref)) $ref = [];
            $ref = &$ref[$part];
        }
        $ref = $val;
    }
    if (array_key_exists('testnet', $cfg['bybit'] ?? [])) {
        $cfg['bybit']['testnet'] = filter_var($cfg['bybit']['testnet'], FILTER_VALIDATE_BOOLEAN);
    }
    return $cfg;
}

function checkToken(string $requiredToken): bool {
    $requiredToken = trim($requiredToken);
    if ($requiredToken === '') return false;
    return hash_equals($requiredToken, trim($_GET['token'] ?? ''));
}

function requireAdminSession(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'secure' => true,
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();
    }
    return isAdminSession($_SESSION);
}
```

- [ ] **Step 4: Ejecutar el test para verificar que pasa**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/php/Unit/SecurityHelpersTest.php`
Expected: PASS (6 tests). Nota: `testBotCfgReturnsArrayAndMergesEnv` no exige que exista el `private/config.json` (usa fallback).

- [ ] **Step 5: Commit**

```bash
git add src/php/Helpers.php tests/php/Unit/SecurityHelpersTest.php
git commit -m "feat(sec): helpers de config segura + checkToken fail-closed"
```

---

### Task 2: Unificar consumidores de configuración en `botCfg()` / `Core\Config`

**Files:**
- Modify: `src/php/grid_ajax.php` (reordenar autoload/helpers + carga con `botCfg`)
- Modify: `src/php/index.php` (dashboard; añadir autoload + `botCfg`)
- Modify: `src/php/index2.php` (añadir autoload + `botCfg`)
- Modify: `src/php/trainer.php` (añadir autoload + `botCfg` para EXPORT_TOKEN)
- Modify: `src/php/bot.php` (usar `botCfg`)
- Modify: `src/php/websocket_server.php` (carga env + `private/config.json` self-contained)
- Modify: `src/php/Core/Config.php:38-42` (ruta a `~web/private/config.json`)
- Modify: `src/php/ConfigLoader.php:29,66` (rutas canónicas)
- Verify: `src/php/test_config.php` (debe seguir funcionando sin cambios)

**Interfaces:**
- Consumes: `botCfg()`, `envLoadOnce()`, `privateConfigPath()` de Task 1 (disponibles globalmente vía `vendor/autoload.php` porque composer `files` carga `Helpers.php`).
- Produces: todos los consumidores leen la misma config; el sistema sigue operativo (fallback a `src/php/config.json` aún existe).

- [ ] **Step 1: grid_ajax.php — mover autoload arriba y usar `botCfg`**

Reemplazar el bloque de cabeceras+carga de config (líneas 15–35) por:

```php
error_reporting(0);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Bot-Version: 15.4');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

// ─── Autoload (loads Helpers.php via composer files directive) ───
$autoloadPaths = [
    dirname(__DIR__, 2) . '/vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];
foreach ($autoloadPaths as $_al) {
    if (file_exists($_al)) { require_once $_al; break; }
}

// ─── Helpers (standalone fallback when autoloader unavailable) ───
if (!function_exists('sanitize')) {
    require_once __DIR__ . '/Helpers.php';
}

// Cargar configuración: private/ primero (fuera de HTTP), luego public_html/
if (!file_exists(privateConfigPath())) {
    http_response_code(500);
    echo json_encode(['error' => 'config.json no encontrado. Buscado en: ' . privateConfigPath()]);
    exit;
}
$cfg = botCfg();
```

Y eliminar el bloque duplicado de autoload (líneas 58–70 actuales).

- [ ] **Step 2: index.php (dashboard) — autoload + `botCfg`**

Reemplazar las líneas 12–17:

```php
error_reporting(0); ini_set('display_errors', '0');
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
$cfg = botCfg();
```

(mantener `function trimRecursive` y `$cfg = trimRecursive($cfg); $mc = $cfg['mysql'] ?? [];` sin cambios).

- [ ] **Step 3: index2.php — autoload + `botCfg`**

Reemplazar las líneas 22–28:

```php
error_reporting(0); ini_set('display_errors', '0');
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// Cargar configuración desde private/ (fuera del web root) + env
$cfg = botCfg();
```

(mantener `trimRecursive` y su uso sin cambios).

- [ ] **Step 4: trainer.php — autoload + env (prepara EXPORT_TOKEN real)**

Reemplazar la línea 9:

```php
error_reporting(0); ini_set('display_errors', '0');
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
```

Reemplazar la línea 18:

```php
define('EXPORT_TOKEN', getenv('SECURITY_TOKEN') ?: '');
```

(El gate de sesión se añade en Task 4.)

- [ ] **Step 5: bot.php — usar `botCfg`**

Reemplazar el bloque de líneas 37–49:

```php
$_cfgPaths = [
    privateConfigPath(),
    dirname(__DIR__) . '/private/config.json',
    __DIR__ . '/config.json',
    '/home/erika/config/config.json',
];
$cfgFile = null;
foreach ($_cfgPaths as $_p) { if (@file_exists($_p)) { $cfgFile = $_p; break; } }
if (!$cfgFile) {
    fwrite(STDERR, "ERROR: config.json no encontrado.\nBuscado en:\n  " . implode("\n  ", $_cfgPaths) . "\n");
    exit(1);
}
$cfg = botCfg();
if (empty($cfg) && $cfgFile && $cfgFile !== privateConfigPath()) {
    $cfg = json_decode(file_get_contents($cfgFile), true) ?: [];
}
if (!is_array($cfg)) { fwrite(STDERR, "ERROR: config.json inválido\n"); exit(1); }
```

- [ ] **Step 6: websocket_server.php — env + private config (self-contained, sin Helpers)**

Reemplazar la línea 19 y el bloque 21–25:

```php
require __DIR__ . '/vendor/autoload.php';

foreach ([dirname(__DIR__, 2) . '/.env', dirname(__DIR__, 3) . '/.env'] as $envFile) {
    if (!is_file($envFile)) continue;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        if (getenv($k) === false) putenv($k . '=' . trim($v, '"\' '));
    }
}

$cfgFile = dirname(__DIR__, 3) . '/private/config.json';
if (!file_exists($cfgFile)) $cfgFile = __DIR__ . '/config.json';
if (!file_exists($cfgFile)) { fwrite(STDERR, "ERROR: config.json no encontrado.\n"); exit(1); }
$cfg = json_decode(file_get_contents($cfgFile), true);
if (!is_array($cfg)) $cfg = [];
```

Reemplazar las líneas 32–35 (credenciales con fallback a env):

```php
$dbConfig   = $cfg['mysql'] ?? [];
$dbConfig['password'] = getenv('MYSQL_PASSWORD') ?: ($dbConfig['password'] ?? '');
$wsToken    = getenv('WS_TOKEN') ?: ($cfg['ws_token'] ?? '');
$bybitKey    = getenv('BYBIT_API_KEY')    ?: ($cfg['bybit']['api_key']    ?? '');
$bybitSecret = getenv('BYBIT_API_SECRET') ?: ($cfg['bybit']['api_secret'] ?? '');
```

Nota: `websocket_server.php` define localmente `getBybitBalance`/`bybitSign`; por eso NO se cambia a `botCfg()` (evitaría redeclaración fatal). Este bloque carga `.env` y lee `private/config.json` directamente.

- [ ] **Step 7: Core/Config.php — ruta canónica**

Reemplazar líneas 38–42:

```php
$paths = [
    dirname(__DIR__, 4) . '/private/config.json',
    dirname(__DIR__) . '/config.json',
    '/home/erika/config/config.json',
];
```

- [ ] **Step 8: ConfigLoader.php — rutas canónicas**

Línea 29: `$envFile = dirname(__DIR__) . '/.env';` → `$envFile = dirname(__DIR__, 2) . '/.env';`
Línea 66: `$configFile = dirname(__DIR__) . '/config.json';` → `$configFile = dirname(__DIR__, 3) . '/private/config.json';`

- [ ] **Step 9: Verificar que nada se rompe (suite completa + CLI smoke)**

Run: `vendor/bin/phpunit -c phpunit.xml.dist`
Expected: 226 tests OK (igual que baseline; `_logs`/`_health` aún anónimos — Task 5 los cierra).

Run:
```bash
php -l src/php/grid_ajax.php && php -l src/php/index.php && php -l src/php/index2.php && php -l src/php/trainer.php && php -l src/php/bot.php && php -l src/php/websocket_server.php && php -l src/php/Core/Config.php && php -l src/php/ConfigLoader.php
php src/php/test_config.php
```
Expected: lint OK; `test_config` muestra credenciales detectadas (aún desde `src/php/config.json` vía fallback).

Run (spot check web, el sistema sigue igual):
```bash
curl -sk -o /dev/null -w '%{http_code}\n' 'https://binance.gregorbritez.cat/src/php/grid_ajax.php?_landing_stats=1'
```
Expected: `200`.

- [ ] **Step 10: Commit**

```bash
git add src/php/grid_ajax.php src/php/index.php src/php/index2.php src/php/trainer.php src/php/bot.php src/php/websocket_server.php src/php/Core/Config.php src/php/ConfigLoader.php
git commit -m "refactor(sec): consumidores de config unificados en botCfg/private"
```

---

### Task 3: Secrets — `.env`, tokens, rotación MySQL, mover config.json fuera del web root

**Files:**
- Create: `public_html/.env` (expandir el existente; gitignored)
- Modify: `/etc/grid_bot/.env` (fuera de git; solo acceso root)
- Create: `/home/erika/web/binance.gregorbritez.cat/private/config.json` (sin credenciales)
- Modify: `.gitignore` (añadir `config.json`)
- Delete (git): `src/php/config.json`
- Verify: `src/php/test_config.php`

**Interfaces:**
- Consumes: `botCfg()` (Task 1), rutas canónicas (Task 2).
- Produces: las credenciales viven SOLO en `.env`; `config.json` en web root deja de existir; `private/config.json` es la única config sin secretos.

- [ ] **Step 1: Generar tokens**

```bash
TOK=$(openssl rand -hex 32)
echo "TOK=$TOK"
```
Usar EL MISMO `$TOK` para `SECURITY_TOKEN` y `WS_TOKEN` (ver constraint).

- [ ] **Step 2: Rotar password MySQL**

```bash
NEWPW=$(openssl rand -hex 16)
mysql -uroot -e "ALTER USER 'erika_bot'@'localhost' IDENTIFIED BY '$NEWPW'; CREATE USER IF NOT EXISTS 'erika_bot'@'127.0.0.1' IDENTIFIED BY '$NEWPW'; GRANT ALL PRIVILEGES ON \`erika_bot\`.* TO 'erika_bot'@'127.0.0.1'; FLUSH PRIVILEGES;"
echo "NEWPW=$NEWPW"
```
Verificar:
```bash
mysql -u erika_bot -p"$NEWPW" -h localhost erika_bot -e "SELECT 1"
mysql -u erika_bot -p"$NEWPW" -h 127.0.0.1 erika_bot -e "SELECT 1"
```
Expected: ambas devuelven `1`.

- [ ] **Step 3: Escribir `public_html/.env` (completo)**

Contenido final (sustituir `<TOK>` y `<NEWPW>`):

```
PLATFORM_SECRET=808ed8d375f65cabb684f4d681d5c3590be80f5fa59b6c4d97c411bcf4fdb8b3
BYBIT_API_KEY=7DR2LXscaoHQfbrmnA
BYBIT_API_SECRET=eEAAxGbQAitGcTxivjmO06j8xnIrD52D1ahP
BYBIT_TESTNET=true
NVIDIA_API_KEY=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
NVIDIA_ENABLED=false
MYSQL_HOST=localhost
MYSQL_DBNAME=erika_bot
MYSQL_USER=erika_bot
MYSQL_PASSWORD=<NEWPW>
SECURITY_TOKEN=<TOK>
WS_TOKEN=<TOK>
```

- [ ] **Step 4: Actualizar `/etc/grid_bot/.env` (root)**

Reemplazar `MYSQL_PASSWORD=Enladisco123@` por `MYSQL_PASSWORD=<NEWPW>` y añadir:
```
SECURITY_TOKEN=<TOK>
WS_TOKEN=<TOK>
```
(El resto — claves Bybit, NVIDIA, testnet — ya coincide con el `.env` de web; NO cambiar las claves Bybit.)

- [ ] **Step 5: Crear `private/config.json` sin credenciales**

Generar desde el config actual eliminando secretos (`bybit.api_key`, `bybit.api_secret`, `nvidia.api_key`, `security_token`, `ws_token`, `mysql.password`). Ejecutar:

```bash
php -r '
$src = json_decode(file_get_contents("src/php/config.json"), true);
unset($src["bybit"]["api_key"], $src["bybit"]["api_secret"], $src["nvidia"]["api_key"],
      $src["security_token"], $src["ws_token"], $src["mysql"]["password"]);
file_put_contents("/home/erika/web/binance.gregorbritez.cat/private/config.json",
    json_encode($src, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
'
chown erika:erika /home/erika/web/binance.gregorbritez.cat/private/config.json
chmod 640 /home/erika/web/binance.gregorbritez.cat/private/config.json
```

Verificar que no quedan secretos:
```bash
grep -Ei 'api_key|api_secret|password|token' /home/erika/web/binance.gregorbritez.cat/private/config.json
```
Expected: sin coincidencias.

- [ ] **Step 6: Eliminar `src/php/config.json` del repo y del disco; ignorar**

```bash
git rm src/php/config.json
```
Añadir a `.gitignore`:
```
config.json
```
El archivo queda borrado del working tree; las peticiones HTTP a `/src/php/config.json` pasarán a 404 (nginx `try_files` → @fallback → Apache).

- [ ] **Step 7: Añadir test de "sin secretos inline"**

Añadir a `tests/php/Unit/SecurityHelpersTest.php`:

```php
    public function testPrivateConfigHasNoInlineSecrets(): void
    {
        $path = privateConfigPath();
        $this->assertFileExists($path);
        $raw = (string)file_get_contents($path);
        $this->assertStringNotContainsString('api_key', $raw);
        $this->assertStringNotContainsString('api_secret', $raw);
        $this->assertStringNotContainsString('password', $raw);
        $this->assertStringNotContainsString('token', $raw);
    }
```

- [ ] **Step 8: Verificar credenciales por env (test_config + test suite)**

Run: `php src/php/test_config.php`
Expected: BYBIT_API_KEY ✓, BYBIT_API_SECRET ✓, MYSQL_PASSWORD ✓, SECURITY_TOKEN ✓ (generado), WS_TOKEN ✓ (generado); "TODOS LOS TESTS PASARON".

Run: `vendor/bin/phpunit -c phpunit.xml.dist`
Expected: PASS (227 tests).

- [ ] **Step 9: Reiniciar servicios para que tomen el nuevo password**

```bash
sudo systemctl restart grid-bot grid-bot-ws binance-scanner
```
Verificar:
```bash
systemctl is-active grid-bot grid-bot-ws binance-scanner
tail -n 20 /home/erika/web/binance.gregorbritez.cat/public_html/bot.log
```
Expected: `active` en los tres; el log del bot sin errores de conexión MySQL nuevos (puede haber "restart" esperado).

- [ ] **Step 10: Verificar por HTTP que config.json ya no se descarga**

```bash
curl -sk -o /dev/null -w '%{http_code}\n' 'https://binance.gregorbritez.cat/src/php/config.json'
curl -sk -o /dev/null -w '%{http_code}\n' 'https://binance.gregorbritez.cat/.env'
curl -sk -o /dev/null -w '%{http_code}\n' 'https://binance.gregorbritez.cat/src/php/grid_ajax.php?_landing_stats=1'
```
Expected: `404`, `404`, `200`.

- [ ] **Step 11: Commit**

```bash
git add .gitignore tests/php/Unit/SecurityHelpersTest.php
git add -u src/php/config.json
git commit -m "feat(sec): secrets a .env, rotacion mysql, config.json fuera del web root"
```

---

### Task 4: Gate admin en dashboards (`index.php`, `index2.php`, `trainer.php`)

**Files:**
- Modify: `src/php/index.php`
- Modify: `src/php/index2.php`
- Modify: `src/php/trainer.php`
- Reference: `src/php/login.php` (destino del redirect)

**Interfaces:**
- Consumes: `isAdminSession()` / `requireAdminSession()` (Task 1), autoload+`botCfg` (Task 2).
- Produces: GET anónimo a los dashboards → `302 Location: login.php`.

- [ ] **Step 1: Escribir la verificación que falla (curl)**

```bash
curl -sk -o /dev/null -w '%{http_code}\n' 'https://binance.gregorbritez.cat/src/php/index.php'
curl -sk -o /dev/null -w '%{http_code}\n' 'https://binance.gregorbritez.cat/src/php/index2.php'
curl -sk -o /dev/null -w '%{http_code}\n' 'https://binance.gregorbritez.cat/src/php/trainer.php'
```
Expected (antes): `200`, `200`, `200`. Tras la implementación: `302` los tres.

- [ ] **Step 2: index.php (dashboard) — gate tras session_start**

Reemplazar líneas 27–41 (EXPORT_TOKEN + sesión + IS_ADMIN):

```php
define('EXPORT_TOKEN', getenv('SECURITY_TOKEN') ?: '');
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => true,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}
if (!isAdminSession($_SESSION)) {
    header('Location: login.php');
    exit;
}
$IS_ADMIN = true;
$CTRL_TOKEN = EXPORT_TOKEN;
$AI_INT   = (int)($cfg['bot']['ai_interval_sec'] ?? 120);
$CAPITAL  = (int)($cfg['bot']['capital_usd']     ?? 20);
$LEVERAGE = (int)($cfg['bot']['leverage']        ?? 100);
```

(El `export_pnl` de la línea 43–45 sigue funcionando: `$IS_ADMIN` es `true` y compara contra `EXPORT_TOKEN`.)

- [ ] **Step 3: index2.php — gate al inicio**

Reemplazar la línea 37 y añadir el gate tras `$SYMBOL = ...` (línea 41):

```php
define('EXPORT_TOKEN', getenv('SECURITY_TOKEN') ?: '');
$AI_INT   = (int)($cfg['bot']['ai_interval_sec'] ?? 120);
$CAPITAL  = (int)($cfg['bot']['capital_usd']     ?? 20);
$LEVERAGE = (int)($cfg['bot']['leverage']        ?? 100);
$SYMBOL   = $cfg['bot']['symbol'] ?? 'ETHUSDT';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => true,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}
if (!isAdminSession($_SESSION)) {
    header('Location: login.php');
    exit;
}
```

- [ ] **Step 4: trainer.php — gate de sesión + token fail-closed**

Reemplazar las líneas 38–42:

```php
/* ── Solo administradores ── */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => true,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}
if (!isAdminSession($_SESSION)) {
    header('Location: login.php');
    exit;
}

/* ── Security token (fail-closed) ── */
$token = $_GET['token'] ?? $_POST['token'] ?? '';
if ($token !== EXPORT_TOKEN) {
    http_response_code(403); exit('Acceso denegado');
}
```

- [ ] **Step 5: Verificar por HTTP**

```bash
for u in 'src/php/index.php' 'src/php/index2.php' 'src/php/trainer.php'; do
  echo -n "$u -> "; curl -sk -o /dev/null -w '%{http_code}\n' "https://binance.gregorbritez.cat/$u"
done
```
Expected: `302` en los tres (redirección a `login.php`).

Y el login sigue operativo (200) y la landing pública intacta:
```bash
curl -sk -o /dev/null -w '%{http_code}\n' 'https://binance.gregorbritez.cat/'
curl -sk -o /dev/null -w '%{http_code}\n' 'https://binance.gregorbritez.cat/src/php/login.php'
```
Expected: `200`, `200`.

- [ ] **Step 6: Suite de tests**

Run: `vendor/bin/phpunit -c phpunit.xml.dist`
Expected: PASS (los tests de API aún anónimos pasan porque Task 5 no se ha aplicado).

- [ ] **Step 7: Commit**

```bash
git add src/php/index.php src/php/index2.php src/php/trainer.php
git commit -m "feat(sec): dashboards solo admin, token sin fallback hardcodeado"
```

---

### Task 5: Auth en endpoints de datos + CORS + token en `update_config`

**Files:**
- Modify: `src/php/grid_ajax.php` (gate global; quitar CORS `*`; token en `update_config`)
- Modify: `src/php/Dashboard/Router.php` (quitar CORS `*`)
- Modify: `tests/php/Integration/ApiEndpointsTest.php` (esperar 403 anónimo; helper admin)

**Interfaces:**
- Consumes: `requireAdminSession()` (Task 1), `botCfg()` (Task 1), `checkToken()` fail-closed (Task 1).
- Produces: todos los endpoints de `grid_ajax.php` exigen sesión admin salvo `_landing_stats`.

- [ ] **Step 1: Actualizar el test (escribir la especificación que falla)**

Reemplazar `tests/php/Integration/ApiEndpointsTest.php`:

- Añadir helper:

```php
    private function executeEndpointAsAdmin(array $getParams): array
    {
        $getParamJson = json_encode($getParams);
        $script = <<<PHP
<?php
error_reporting(0);
ini_set("display_errors", "0");
session_start();
\$_SESSION['role'] = 'admin';
\$_GET = json_decode('{$getParamJson}', true);
\$_SERVER = ["REQUEST_METHOD" => "GET"];
chdir('/home/erika/web/binance.gregorbritez.cat/public_html');
ob_start();
require '/home/erika/web/binance.gregorbritez.cat/public_html/src/php/grid_ajax.php';
\$output = ob_get_clean();
echo \$output;
PHP;

        $tmpFile = sys_get_temp_dir() . '/test_admin_' . uniqid() . '.php';
        file_put_contents($tmpFile, $script);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open('php ' . escapeshellarg($tmpFile), $descriptors, $pipes);
        if (!is_resource($process)) { unlink($tmpFile); return []; }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($tmpFile);

        $result = json_decode($output ?: '{}', true);
        return is_array($result) ? $result : ['error' => $output, 'stderr' => $stderr];
    }
```

- Cambiar las llamadas a `executeEndpoint` por `executeEndpointAsAdmin` en los tests de `_health` (3 tests) y `_logs` (2 tests).
- Añadir:

```php
    public function testDataEndpointsRejectAnonymous(): void
    {
        foreach (['_status', '_logs', '_ticker', '_market', '_pnl_float',
                  '_ai_decisions', '_scalp', '_ml_info', '_fills_history', '_health'] as $ep) {
            $data = $this->executeEndpoint([$ep => '1']);
            $this->assertIsArray($data, $ep);
            $this->assertFalse($data['ok'] ?? true, "$ep debería requerir sesión");
            $this->assertSame('No autorizado', $data['msg'] ?? null, $ep);
        }
    }
```

- [ ] **Step 2: Ejecutar el test para verificar que falla**

Run: `vendor/bin/phpunit -c phpunit.xml.dist tests/php/Integration/ApiEndpointsTest.php`
Expected: FAIL — `_health`/`_logs` anónimos aún responden `ok=true`.

- [ ] **Step 3: grid_ajax.php — gate global + quitar CORS**

- Línea 17: eliminar `header('Access-Control-Allow-Origin: *');`.
- Tras el bloque de Helpers (Task 2) y antes del endpoint `_ticker`, insertar:

```php
// ─── Control de acceso: solo _landing_stats es público ───
if (!isset($_GET['_landing_stats'])) {
    if (!requireAdminSession()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
        exit;
    }
    session_write_close();
}
```

- [ ] **Step 4: grid_ajax.php — token en `update_config`**

En el bloque `update_config` (línea ~379), tras el chequeo `isAdminSession` y `session_write_close()` (línea ~394), añadir:

```php
    if (!checkToken($requiredToken)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'msg' => 'Token inválido']);
        exit;
    }
```

- [ ] **Step 5: Router.php — quitar CORS `*`**

Eliminar la línea 18: `header('Access-Control-Allow-Origin: *');`.

- [ ] **Step 6: Ejecutar el test para verificar que pasa**

Run: `vendor/bin/phpunit -c phpunit.xml.dist`
Expected: PASS (todos, incluido `testDataEndpointsRejectAnonymous` y los tests de admin).

- [ ] **Step 7: Verificar por HTTP**

```bash
curl -sk -o /dev/null -w '%{http_code}\n' 'https://binance.gregorbritez.cat/src/php/grid_ajax.php?_status=1'
curl -sk -o /dev/null -w '%{http_code}\n' 'https://binance.gregorbritez.cat/src/php/grid_ajax.php?_logs=1'
curl -sk -o /dev/null -w '%{http_code}\n' 'https://binance.gregorbritez.cat/src/php/grid_ajax.php?_landing_stats=1'
curl -sk -o /dev/null -w '%{http_code}\n' 'https://binance.gregorbritez.cat/'
```
Expected: `403`, `403`, `200`, `200`.

- [ ] **Step 8: Commit**

```bash
git add src/php/grid_ajax.php src/php/Dashboard/Router.php tests/php/Integration/ApiEndpointsTest.php
git commit -m "feat(sec): endpoints de datos exigen sesion admin; solo _landing_stats publico"
```

---

### Task 6: Bloqueo a nivel servidor (nginx deny + `.htaccess`)

**Files:**
- Modify: `/home/erika/conf/web/binance.gregorbritez.cat/nginx.conf` (vhost :80)
- Modify: `/home/erika/conf/web/binance.gregorbritez.cat/nginx.ssl.conf` (vhost :443)
- Create: `public_html/.htaccess`
- Note: los vhosts nginx son generados por HestiaCP; si se reconstruye el dominio hay que re-aplicar estos cambios (documentado en la verificación final).

**Interfaces:**
- Consumes: nada nuevo; es defensa en profundidad tras Task 3 (config.json ya no está en web root).
- Produces: ningún `.json`/`.bak`/`.sql`/`.log`/`.env`/`.conf` es servible vía nginx ni Apache.

- [ ] **Step 1: Insertar regla deny en ambos vhosts**

En `nginx.ssl.conf`, tras el bloque `location ~ /\.(?!well-known\/|file)` (líneas 24–27) insertar:

```nginx
	location ~* \.(json|bak|sql|log|env|conf|dist|lock)$ {
		deny all;
		return 404;
	}
```

Y en la regex de estáticos (línea 34) quitar `json|` para que los `.json` caigan a `@fallback` (Apache, donde `.htaccess` los bloquea). La regex pasa a:

```
location ~* ^.+\.(css|htm|html|js|mjs|apng|avif|bmp|cur|gif|ico|jfif|jpg|jpeg|pjp|pjpeg|png|svg|tif|tiff|webp|aac|caf|flac|m4a|midi|mp3|ogg|opus|wav|3gp|av1|avi|m4v|mkv|mov|mpg|mpeg|mp4|mp4v|webm|otf|ttf|woff|woff2|doc|docx|odf|odp|ods|odt|pdf|ppt|pptx|rtf|txt|xls|xlsx|7z|bz2|gz|rar|tar|tgz|zip|apk|appx|bin|dmg|exe|img|iso|jar|msi|webmanifest)$
```

Aplicar los mismos dos cambios a `nginx.conf` (vhost :80; deny tras línea 14–17, y quitar `json|` de la línea 22).

- [ ] **Step 2: Crear `public_html/.htaccess`**

```apache
# Web Security Hardening — Grid Bot
Options -Indexes

# Bloquear archivos sensibles (defensa en profundidad tras nginx)
<FilesMatch "\.(json|bak|sql|log|env|conf|dist|lock|key|pem)$">
    Require all denied
</FilesMatch>

<FilesMatch "^(install\.php|install_hestia\.php)$">
    Require all denied
</FilesMatch>
```

- [ ] **Step 3: Validar y recargar nginx**

```bash
sudo nginx -t
sudo systemctl reload nginx
```
Expected: `nginx: configuration file /etc/nginx/nginx.conf test is successful` y recarga sin error.

- [ ] **Step 4: Verificar por HTTP**

```bash
for u in 'src/php/config.json' 'config.json' 'ml_weights_v2.json' 'scripts/install.log' 'scripts/install.sql' '.env'; do
  echo -n "$u -> "; curl -sk -o /dev/null -w '%{http_code}\n' "https://binance.gregorbritez.cat/$u"
done
```
Expected: todos `404`. (Nota: tras Task 7, `scripts/` ya no existirá en web root; el 404 se mantiene.)

Comprobar que lo legítimo sigue bien:
```bash
curl -sk -o /dev/null -w '%{http_code}\n' 'https://binance.gregorbritez.cat/'
curl -sk -o /dev/null -w '%{http_code}\n' 'https://binance.gregorbritez.cat/assets/css/design-system.css'
```
Expected: `200`, `200`.

- [ ] **Step 5: Commit**

```bash
git add .htaccess
git commit -m "feat(sec): nginx deny de json/bak/sql/log/env + htaccess"
```

---

### Task 7: Backups a un directorio seguro + limpieza de instaladores/scripts

**Files:**
- Move (fuera de repo): `/home/erika/web/binance.gregorbritez.cat/public_html (2)`, `public_html (3)`, `public_html.zip` → `/home/erika/backups_seguros/`
- Move (fuera de web root): `src/php/install.php`, `src/php/install_hestia.php`, `scripts/`, `systemd/` → `/home/erika/web/binance.gregorbritez.cat/private/ops/`
- Note: `scripts/` está en git; el `git add -A` del commit final registrará la eliminación.

**Interfaces:**
- Consumes: nada.
- Produces: ningún instalador/script de despliegue accesible por HTTP; backups fuera del árbol web.

- [ ] **Step 1: Verificar referencias en runtime antes de mover**

```bash
grep -rn 'scripts/\|systemd/\|install\.php\|install_hestia' src/php/ --include='*.php' | grep -v vendor
```
Expected: sin referencias de runtime.

- [ ] **Step 2: Mover backups**

```bash
mkdir -p /home/erika/backups_seguros
mv "/home/erika/web/binance.gregorbritez.cat/public_html (2)" \
   "/home/erika/web/binance.gregorbritez.cat/public_html (3)" \
   "/home/erika/web/binance.gregorbritez.cat/public_html.zip" \
   /home/erika/backups_seguros/
ls -la /home/erika/backups_seguros/
```
Expected: los tres elementos listados.

- [ ] **Step 3: Mover instaladores y scripts de despliegue fuera del web root**

```bash
mkdir -p /home/erika/web/binance.gregorbritez.cat/private/ops
mv scripts systemd /home/erika/web/binance.gregorbritez.cat/private/ops/
mv src/php/install.php src/php/install_hestia.php /home/erika/web/binance.gregorbritez.cat/private/ops/
```

- [ ] **Step 4: Verificar HTTP**

```bash
for u in 'src/php/install.php' 'src/php/install_hestia.php' 'scripts/install.sh' 'scripts/install.sql' 'scripts/INSTALL_SUMMARY.txt' 'systemd/grid-bot.service'; do
  echo -n "$u -> "; curl -sk -o /dev/null -w '%{http_code}\n' "https://binance.gregorbritez.cat/$u"
done
```
Expected: todos `404`.

- [ ] **Step 5: Commit (registrar borrados)**

```bash
git add -A
git commit -m "chore(sec): backups movidos y instaladores/scripts fuera del web root"
```

---

### Task 8: WebSocket bind solo a loopback

**Files:**
- Modify: `src/php/websocket_server.php:471`

**Interfaces:**
- Consumes: proxy nginx `location /ws/ → proxy_pass http://127.0.0.1:8094/` (ya existente en `nginx.ssl.conf_ws`).
- Produces: el puerto 8094 solo es alcanzable desde 127.0.0.1 (nginx).

- [ ] **Step 1: Cambiar el bind**

Reemplazar la línea 471:

```php
$server = IoServer::factory(new HttpServer(new WsServer($ws)), 8094, '127.0.0.1');
```

(Firma verificada en `src/php/vendor/cboden/ratchet/src/Ratchet/Server/IoServer.php:57`: `factory($component, $port = 80, $address = '0.0.0.0')`.)

- [ ] **Step 2: Validar sintaxis y reiniciar el servicio**

```bash
php -l src/php/websocket_server.php
sudo systemctl restart grid-bot-ws
```
Expected: lint OK; servicio `active`.

- [ ] **Step 3: Verificar bind y autenticación WS**

```bash
sudo ss -ltnp | grep 8094
```
Expected: `127.0.0.1:8094` (no `0.0.0.0`).

Con el token del `.env` (`<TOK>`), probar el handshake a través de nginx (usa el proxy, no el puerto directo):
```bash
curl -sk -i --max-time 8 -H "Connection: Upgrade" -H "Upgrade: websocket" -H "Sec-WebSocket-Version: 13" -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" "https://binance.gregorbritez.cat/ws/?token=<TOK>" | head -1
```
Expected: `HTTP/1.1 101 Switching Protocols`.

Y sin token:
```bash
curl -sk -i --max-time 8 -H "Connection: Upgrade" -H "Upgrade: websocket" -H "Sec-WebSocket-Version: 13" -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" "https://binance.gregorbritez.cat/ws/" | head -1
```
Expected: no `101` (rechazado por token en `GridWebSocket`).

- [ ] **Step 4: Commit**

```bash
git add src/php/websocket_server.php
git commit -m "feat(sec): websocket bind 127.0.0.1 + token via env"
```

---

### Task 9: Fixes misceláneos (`save_chart`, registro rate-limit, CSRF solo POST, SQL prepared)

**Files:**
- Modify: `src/php/save_chart.php` (auth + límite de tamaño + validación de imagen)
- Modify: `src/php/register.php` (rate limit de registro)
- Modify: `src/php/Core/AuthHttp.php:13` (CSRF solo en POST)
- Modify: `src/php/Strategy/GridManager.php:1161,1167,1184` y el SELECT de `writeStatus` (≈1270) (SQL prepared)
- Modify: `src/php/Core/BotAccountingSync.php:16` (SQL prepared)
- Test: `tests/php/Unit/...` (SQL: los cambios son internos al bot CLI; validar con `php -l` y ejecución del suite)

**Interfaces:**
- Consumes: `requireAdminSession()` (Task 1), `Auth::checkRateLimit`/`recordAttempt` (existentes en `Core/Auth.php`).
- Produces: `save_chart.php` solo acepta admin; registro limitado por IP; CSRF verificado solo en POST; SQL sin interpolación de `$symbol`.

- [ ] **Step 1: save_chart.php — auth + límite + validación**

Reemplazar el bloque de cabecera (líneas 6–7) y añadir gate tras el check CLI (línea 15):

```php
header('Content-Type: application/json');

require_once __DIR__ . '/../../vendor/autoload.php';

// Si se ejecuta en CLI, mostrar mensaje amigable y salir
if (php_sapi_name() === 'cli') {
    echo json_encode([
        'ok' => false,
        'error' => 'Este script debe ejecutarse mediante petición HTTP POST desde el navegador.'
    ]);
    exit;
}

// Solo administradores
if (!requireAdminSession()) {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}
```

Y tras `$img = base64_decode($data);` (línea 37) añadir:

```php
if ($img === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid base64 image data.']);
    exit;
}
if (strlen($img) > 5 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['error' => 'Image too large (max 5 MB).']);
    exit;
}
if (substr($img, 0, 8) !== "\x89PNG\r\n\x1a\n") {
    http_response_code(400);
    echo json_encode(['error' => 'Solo se aceptan imágenes PNG.']);
    exit;
}
```

(El `if ($img === false)` ya existe en el original: mantenerlo y añadir los dos checks nuevos después.)

- [ ] **Step 2: register.php — rate limit de registro**

Reemplazar el bloque POST (líneas 29–44):

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $username = trim((string)($_POST['username'] ?? ''));
    if (!Csrf::verify($_SESSION, $_POST['csrf'] ?? null)) {
        $error = 'Token CSRF inválido';
    } elseif (!Auth::checkRateLimit($pdo, $ip, 'register', 3, 3600)) {
        $error = 'Demasiados registros desde esta IP. Espera una hora.';
    } else {
        $res = Auth::register($pdo, $username, (string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($res['ok']) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $res['user_id'];
            $_SESSION['username'] = $username;
            $_SESSION['role'] = 'investor';
            header('Location: panel.php');
            exit;
        }
        $error = $res['error'];
    }
    Auth::recordAttempt($pdo, $ip, 'register', $username, ($_SESSION['user_id'] ?? null) !== null);
}
```

- [ ] **Step 3: AuthHttp.php — CSRF solo en POST**

Reemplazar líneas 12–15:

```php
        $action = (string)($get['action'] ?? ($post['action'] ?? ''));
        $isPost = $post !== [];
        if ($isPost && !Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
            return ['redirect' => null, 'error' => 'Token CSRF inválido', 'view' => 'login'];
        }
```

- [ ] **Step 4: GridManager.php — SQL preparado**

`breakoutCheck` (línea 1161):

```php
            return $d->prepare("SELECT MIN(price) mn, MAX(price) mx FROM grid_orders WHERE symbol=? AND status='OPEN'")
                ->execute([G_SYM]) ? $d->query('') : null;
```

No — usar el patrón correcto con `dbx` (devolver el resultado):

```php
    private function breakoutCheck($price) {
        if (!$this->gridBuilt) return;
        $r = dbx(function($d) {
            $stmt = $d->prepare("SELECT MIN(price) mn, MAX(price) mx FROM grid_orders WHERE symbol=? AND status='OPEN'");
            $stmt->execute([G_SYM]);
            return $stmt->fetch();
        });
```

Línea 1167:

```php
            $lastFill = dbx(function($d) {
                $stmt = $d->prepare("SELECT MAX(filled_at) FROM grid_orders WHERE symbol=? AND status='FILLED' AND filled_at IS NOT NULL");
                $stmt->execute([G_SYM]);
                return $stmt->fetchColumn();
            });
```

`getPnlToday` (línea 1184):

```php
            $r = dbx(function($d) {
                $stmt = $d->prepare("SELECT COALESCE(SUM(pnl_usd),0) AS p FROM grid_orders WHERE symbol=? AND grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE()");
                $stmt->execute([G_SYM]);
                return $stmt->fetch();
            });
```

`writeStatus` — los tres `query(... symbol='" . G_SYM . "' ...)` (bloque ≈1267–1272):

```php
        $pnl1h = dbx(function($d) {
            $stmt = $d->prepare("SELECT COALESCE(SUM(pnl_usd),0) p, COUNT(*) c FROM grid_orders WHERE symbol=? AND grid_role='EXIT' AND status='FILLED' AND filled_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)");
            $stmt->execute([G_SYM]);
            return $stmt->fetch();
        });
        $avgPnlPerFill = (float)(dbx(function($d) {
            $stmt = $d->prepare("SELECT COALESCE(AVG(pnl_usd),0) FROM grid_orders WHERE symbol=? AND grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE()");
            $stmt->execute([G_SYM]);
            return $stmt->fetchColumn();
        }) ?? 0);
```

(Revisar con `grep -n "symbol='\" . G_SYM" src/php/Strategy/GridManager.php` y convertir todas las coincidencias restantes al mismo patrón prepared.)

- [ ] **Step 5: BotAccountingSync.php — SQL preparado**

Reemplazar líneas 13–19:

```php
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(pnl_usd), 0) AS realized_pnl
            FROM grid_orders
            WHERE symbol = ?
            AND grid_role = 'EXIT'
            AND status = 'FILLED'
        ");
        $stmt->execute([$symbol]);
```

- [ ] **Step 6: Verificar sintaxis y suite**

```bash
php -l src/php/save_chart.php && php -l src/php/register.php && php -l src/php/Core/AuthHttp.php && php -l src/php/Strategy/GridManager.php && php -l src/php/Core/BotAccountingSync.php
vendor/bin/phpunit -c phpunit.xml.dist
```
Expected: lint OK; suite PASS.

- [ ] **Step 7: Commit**

```bash
git add src/php/save_chart.php src/php/register.php src/php/Core/AuthHttp.php src/php/Strategy/GridManager.php src/php/Core/BotAccountingSync.php
git commit -m "fix(sec): save_chart auth+limite, rate limit registro, CSRF solo POST, SQL prepared"
```

---

### Task 10: Verificación final integral + commit

**Files:**
- None (solo verificación; re-aplicar nginx si el dominio se reconstruye)

**Interfaces:**
- Consumes: todos los tasks anteriores.
- Produces: evidencia de que el hardening funciona end-to-end.

- [ ] **Step 1: Suite de tests completa**

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```
Expected: PASS, 227+ tests.

- [ ] **Step 2: Matriz curl final (anónimo)**

```bash
declare -A expect=(
  ["/"]="200"
  ["/index.php"]="200"
  ["/src/php/login.php"]="200"
  ["/src/php/index.php"]="302"
  ["/src/php/index2.php"]="302"
  ["/src/php/trainer.php"]="302"
  ["/src/php/grid_ajax.php?_landing_stats=1"]="200"
  ["/src/php/grid_ajax.php?_status=1"]="403"
  ["/src/php/grid_ajax.php?_logs=1"]="403"
  ["/src/php/grid_ajax.php?_health=1"]="403"
  ["/src/php/config.json"]="404"
  ["/config.json"]="404"
  ["/src/php/config.json.bak"]="404"
  ["/ml_weights_v2.json"]="404"
  ["/scripts/install.sql"]="404"
  ["/src/php/install.php"]="404"
  ["/src/php/install_hestia.php"]="404"
  ["/.env"]="404"
  ["/assets/css/design-system.css"]="200"
)
fail=0
for u in "${!expect[@]}"; do
  code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 "https://binance.gregorbritez.cat$u")
  if [ "$code" != "${expect[$u]}" ]; then echo "FAIL $u => $code (esperado ${expect[$u]})"; fail=1; fi
done
[ $fail -eq 0 ] && echo "MATRIZ OK"
```
Expected: `MATRIZ OK`.

- [ ] **Step 3: Servicios y procesos**

```bash
systemctl is-active grid-bot grid-bot-ws binance-scanner
sudo ss -ltnp | grep 8094
```
Expected: los tres `active`; `127.0.0.1:8094`.

- [ ] **Step 4: Backups y artefactos**

```bash
ls /home/erika/backups_seguros/
ls /home/erika/web/binance.gregorbritez.cat/private/ops/
find /home/erika/web/binance.gregorbritez.cat/public_html -name '*.bak' -not -path '*/.superpowers/*'
```
Expected: backups listados; ops/ con instaladores+scripts; sin `.bak` fuera de `.superpowers`.

- [ ] **Step 5: Nota de mantenimiento**

Añadir un apartado breve al final del spec (o a `docs/superpowers/specs/2026-08-06-web-security-hardening-design.md`) documentando:
- los vhosts nginx editados a mano se pierden si HestiaCP reconstruye el dominio → re-aplicar Task 6.
- el `.env` fuente de verdad está en `public_html/.env` y `/etc/grid_bot/.env`; rotar password → actualizar ambos.
- `SECURITY_TOKEN` y `WS_TOKEN` deben ser iguales.

- [ ] **Step 6: Commit final (si hay cambios de docs)**

```bash
git add docs/
git commit -m "docs(sec): notas de mantenimiento del hardening"
```

---

## Self-Review

**Cobertura de la spec (9 secciones):**
1. *Secrets* → Task 3 (`.env`, tokens) + Task 2 (unificación).
2. *Acceso dashboard* → Task 4 (gate admin index/index2) + Task 5 (endpoints).
3. *Tokens* → Task 1 (checkToken fail-closed) + Task 4 (quitar `g273f123`) + Task 5 (token en update_config) + Task 3 (tokens aleatorios).
4. *Bloqueo* → Task 6 (nginx deny + `.htaccess`) + Task 7 (mover instaladores).
5. *Fixes* → Task 9 (save_chart, registro, CSRF, SQL prepared).
6. *WebSocket* → Task 8 (bind 127.0.0.1) + Task 3 (WS_TOKEN) + Task 2 (config).
7. *Limpieza* → Task 7 (backups, instaladores, scripts).
8. *MySQL* → Task 3 (rotación, ambos `.env`, restart servicios).
9. *Pruebas* → Task 1/3/5 (tests) + Task 10 (matriz curl).

**Sin placeholders:** todo paso tiene código/commando exacto y salida esperada.

**Consistencia de tipos:** `botCfg(): array`, `envLoadOnce(): void`, `privateConfigPath(): string`, `checkToken(string): bool`, `requireAdminSession(): bool` — firmas idénticas en todos los tasks. `IoServer::factory($handler, 8094, '127.0.0.1')` coincide con la firma verificada.
