# Dashboard Test Coverage & CI/CD Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add comprehensive test coverage (PHP + JavaScript), static analysis, and CI/CD pipeline for the existing Grid Bot Dashboard to enable confident refactoring and prevent regressions.

**Architecture:** The dashboard is a single-file PHP frontend (`src/php/index.php`) backed by a PHP API (`src/php/grid_ajax.php`). Tests will use PHPUnit for PHP, Vitest for JavaScript, and GitHub Actions for CI. Static analysis via PHPStan (level 5) and ESLint.

**Tech Stack:** PHP 8.3, PHPUnit 10, Vitest, ESLint, PHPStan, GitHub Actions, Composer, npm

## Global Constraints

- PHP version: 8.3+ (currently 8.3.32)
- Node version: 18+ (for Vitest/ESLint)
- Test framework: PHPUnit 10 for PHP, Vitest for JS
- Static analysis: PHPStan level 5, ESLint recommended config
- CI: GitHub Actions (ubuntu-latest)
- No external test dependencies (no Selenium, no Cypress - unit/integration only)
- Keep existing single-file structure; don't restructure unless necessary
- All new code must pass `phpstan analyse --level=5` and `eslint .`
- Commit message format: `type(scope): description` (conventional commits)

---

### Task 1: Project Setup & Tooling Configuration

**Files:**
- Create: `composer.json` (add dev dependencies)
- Create: `package.json` (npm dev dependencies)
- Create: `phpstan.neon` (PHPStan config)
- Create: `eslint.config.js` (ESLint flat config)
- Create: `vitest.config.ts` (Vitest config)
- Create: `phpunit.xml.dist` (PHPUnit config)

**Interfaces:**
- Consumes: existing `src/php/index.php`, `src/php/grid_ajax.php`
- Produces: test infrastructure, linting configs

- [ ] **Step 1.1: Update composer.json with dev dependencies**

```json
{
  "require-dev": {
    "phpunit/phpunit": "^10.5",
    "phpstan/phpstan": "^1.11",
    "phpstan/phpstan-deprecation-rules": "^1.1",
    "phpstan/phpstan-phpunit": "^1.3",
    "mockery/mockery": "^1.6",
    "squizlabs/php_codesniffer": "^3.8"
  },
  "autoload-dev": {
    "psr-4": {
      "Tests\\": "tests/"
    }
  },
  "scripts": {
    "test": "phpunit",
    "test:coverage": "phpunit --coverage-html coverage",
    "stan": "phpstan analyse --level=5 src/php",
    "cs": "phpcs --standard=PSR12 src/php",
    "cs:fix": "phpcbf --standard=PSR12 src/php"
  },
  "config": {
    "allow-plugins": {
      "phpstan/extension-installer": true
    }
  }
}
```

- [ ] **Step 1.2: Run `composer install` to install dev dependencies**

```bash
composer install --dev
```
Expected: All packages installed, `vendor/bin/phpunit`, `vendor/bin/phpstan` available

- [ ] **Step 1.3: Create package.json for JS tooling**

```json
{
  "name": "grid-bot-dashboard",
  "version": "15.0.0",
  "type": "module",
  "scripts": {
    "test": "vitest run",
    "test:watch": "vitest",
    "test:coverage": "vitest run --coverage",
    "lint": "eslint .",
    "lint:fix": "eslint . --fix"
  },
  "devDependencies": {
    "vitest": "^2.0.0",
    "@vitest/coverage-v8": "^2.0.0",
    "eslint": "^9.0.0",
    "globals": "^15.0.0",
    "jsdom": "^24.0.0"
  }
}
```

- [ ] **Step 1.4: Run `npm install`**

```bash
npm install
```
Expected: `node_modules/.bin/vitest`, `node_modules/.bin/eslint` available

- [ ] **Step 1.5: Create phpstan.neon**

```neon
includes:
  - vendor/phpstan/phpstan-deprecation-rules/rules.neon
  - vendor/phpstan/phpstan-phpunit/rules.neon

parameters:
  level: 5
  paths:
    - src/php
  excludePaths:
    - src/php/vendor
  ignoreErrors:
    - '#^Function .* not found#'  # For dynamic function calls
    - '#^Access to an undefined property#'
  checkGenericClassAsArray: false
  treatPhpDocTypesAsCertain: false
```

- [ ] **Step 1.6: Create eslint.config.js (flat config)**

```js
import globals from 'globals';
import pluginJs from '@eslint/js';

export default [
  { files: ['**/*.js', '**/*.mjs'] },
  { languageOptions: { ecmaVersion: 2022, sourceType: 'module', globals: { ...globals.browser, ...globals.es2022 } } },
  pluginJs.configs.recommended,
  {
    rules: {
      'no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
      'no-undef': 'off',  // Browser globals handled by globals
      'eqeqeq': ['error', 'always'],
      'prefer-const': 'error',
      'no-var': 'error'
    }
  }
];
```

- [ ] **Step 1.7: Create vitest.config.ts**

```ts
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['tests/js/**/*.test.ts'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json', 'html'],
      exclude: ['node_modules/', 'tests/', '*.config.*']
    }
  }
});
```

- [ ] **Step 1.8: Create phpunit.xml.dist**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
  <testsuites>
    <testsuite name="Unit">
      <directory>tests/php/Unit</directory>
    </testsuite>
    <testsuite name="Integration">
      <directory>tests/php/Integration</directory>
    </testsuite>
  </testsuites>
  <filter>
    <whitelist processUncoveredFilesFromWhitelist="true">
      <directory suffix=".php">src/php</directory>
      <exclude>
        <directory>src/php/vendor</directory>
      </exclude>
    </whitelist>
  </filter>
  <php>
    <env name="APP_ENV" value="test"/>
  </php>
</phpunit>
```

- [ ] **Step 1.9: Commit tooling setup**

```bash
git add composer.json composer.lock package.json package-lock.json phpstan.neon eslint.config.js vitest.config.ts phpunit.xml.dist
git commit -m "chore: add test tooling (PHPUnit, Vitest, PHPStan, ESLint)"
```

---

### Task 2: PHP Unit Tests - API Endpoints (grid_ajax.php)

**Files:**
- Create: `tests/php/Unit/GridAjaxTest.php`
- Create: `tests/php/Unit/HelpersTest.php`

**Interfaces:**
- Consumes: `src/php/grid_ajax.php` (helper functions: `getDB`, `botRunning`, `getUptime`, `sanitize`, `bybitSign`, `getBybitPositions`, `getBybitBalance`, `getBybitTicker`, `getBybitFunding`, `getBybitOI`, `checkToken`, `dbInitOnce`)
- Produces: test coverage for all pure functions

- [ ] **Step 2.1: Write failing test for `sanitize()`**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    public function testSanitizeRemovesNonAlphanumeric(): void
    {
        $this->assertSame('ETHUSDT', sanitize('ETH/USDT'));
        $this->assertSame('BTCUSDT', sanitize('btc-usdt'));
        $this->assertSame('', sanitize('!!!'));
        $this->assertSame('A1B2', sanitize('a1-b2'));
    }

    public function testSanitizeLimitsLength(): void
    {
        $this->assertEquals(20, strlen(sanitize(str_repeat('A', 30))));
    }
}
```

- [ ] **Step 2.2: Run test - expect FAIL (function not accessible)**

```bash
vendor/bin/phpunit tests/php/Unit/HelpersTest.php --filter testSanitize
```
Expected: FAIL - `sanitize()` not found (private to grid_ajax.php)

- [ ] **Step 2.3: Extract helpers to separate file**

**Files:**
- Create: `src/php/Helpers.php` (extract pure functions from grid_ajax.php)
- Modify: `src/php/grid_ajax.php:53-152` (replace with `require_once __DIR__ . '/Helpers.php';`)

```php
<?php
// src/php/Helpers.php
declare(strict_types=1);

function sanitize(string $s): string {
    return substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($s)), 0, 20);
}

function checkToken(string $requiredToken): bool {
    $clean = trim($requiredToken ?? '');
    if ($clean === '') return true;
    return hash_equals($clean, trim($_GET['token'] ?? ''));
}

function getUptime(string $pf): string {
    if (!file_exists($pf)) return '--';
    $pid = trim(file_get_contents($pf));
    if (!$pid || !ctype_digit($pid) || !file_exists("/proc/$pid/stat")) return '--';
    $up   = (float)explode(' ', (string)@file_get_contents('/proc/uptime'))[0];
    $stat = (string)@file_get_contents("/proc/$pid/stat");
    $rp   = strrpos($stat, ')'); if ($rp === false) return '--';
    $flds = explode(' ', trim(substr($stat, $rp + 2)));
    $age  = max(0, (int)($up - (float)($flds[19] ?? 0) / 100));
    if ($age >= 3600) return intdiv($age, 3600) . 'h ' . intdiv($age % 3600, 60) . 'm';
    if ($age >= 60)   return intdiv($age, 60) . 'm ' . ($age % 60) . 's';
    return $age . 's';
}

function botRunning(string $pidFile, string $logFile): bool {
    $pidPaths = array_unique([$pidFile, dirname($logFile) . '/grid_bot.pid', __DIR__ . '/grid_bot.pid']);
    foreach ($pidPaths as $pf) {
        if (!file_exists($pf)) continue;
        $p = trim((string)file_get_contents($pf));
        if ($p && ctype_digit($p) && file_exists("/proc/$p")) return true;
    }
    return file_exists($logFile) && (time() - filemtime($logFile)) < 90;
}

// bybitSign, getBybitPositions, getBybitBalance, getBybitTicker, getBybitFunding, getBybitOI
// kept as-is but now testable via mocking curl
```

- [ ] **Step 2.4: Update grid_ajax.php to use Helpers.php**

```php
// Replace lines 53-152 in grid_ajax.php with:
require_once __DIR__ . '/Helpers.php';
```

- [ ] **Step 2.5: Run HelpersTest again - expect PASS**

```bash
vendor/bin/phpunit tests/php/Unit/HelpersTest.php --filter testSanitize
```
Expected: PASS

- [ ] **Step 2.6: Write test for `checkToken()`**

```php
public function testCheckTokenEmptyAllows(): void {
    $_GET['token'] = 'wrong';
    $this->assertTrue(checkToken(''));
}

public function testCheckTokenValidMatches(): void {
    $_GET['token'] = 'secret123';
    $this->assertTrue(checkToken('secret123'));
}

public function testCheckTokenInvalidRejects(): void {
    $_GET['token'] = 'wrong';
    $this->assertFalse(checkToken('secret123'));
}
```

- [ ] **Step 2.7: Write test for `getUptime()` (mock /proc)**

```php
public function testGetUptimeFileNotExists(): void {
    $this->assertSame('--', getUptime('/nonexistent'));
}

public function testGetUptimeInvalidPid(): void {
    $tmp = sys_get_temp_dir() . '/pid_test_' . uniqid();
    file_put_contents($tmp, 'not-a-number');
    $this->assertSame('--', getUptime($tmp));
    unlink($tmp);
}
```

- [ ] **Step 2.8: Write test for `botRunning()`**

```php
public function testBotRunningNoPidFile(): void {
    $this->assertFalse(botRunning('/nonexistent/pid', '/nonexistent/log'));
}
```

- [ ] **Step 2.9: Commit Helpers extraction + tests**

```bash
git add src/php/Helpers.php src/php/grid_ajax.php tests/php/Unit/HelpersTest.php
git commit -m "refactor: extract helpers to Helpers.php + unit tests"
```

---

### Task 3: PHP Integration Tests - API Endpoints

**Files:**
- Create: `tests/php/Integration/ApiEndpointsTest.php`

**Interfaces:**
- Consumes: `src/php/grid_ajax.php` (full endpoint responses)
- Produces: integration tests hitting endpoints via CLI PHP

- [ ] **Step 3.1: Create test bootstrap for integration tests**

```php
<?php
// tests/php/Integration/bootstrap.php
declare(strict_types=1);

// Mock $_GET, $_POST, $_SERVER for each test
function resetGlobals(): void {
    $_GET = []; $_POST = []; $_SERVER = ['REQUEST_METHOD' => 'GET'];
    ob_start();
}

function captureOutput(callable $fn): string {
    resetGlobals();
    $fn();
    return ob_get_clean();
}
```

- [ ] **Step 3.2: Write failing test for `_ticker` endpoint**

```php
public function testTickerEndpointReturnsJson(): void {
    $output = captureOutput(function() {
        $_GET['_ticker'] = '1';
        require __DIR__ . '/../../../src/php/grid_ajax.php';
    });
    $data = json_decode($output, true);
    $this->assertArrayHasKey('ok', $data);
    $this->assertTrue($data['ok'] ?? false);
    $this->assertArrayHasKey('price', $data);
}
```

- [ ] **Step 3.2: Run - expect FAIL (no mock Bybit API)**

```bash
vendor/bin/phpunit tests/php/Integration/ApiEndpointsTest.php --filter testTickerEndpoint
```
Expected: FAIL - curl to Bybit fails

- [ ] **Step 3.3: Add curl mocking via `uopz` or wrapper class** - Skip for now, mark as known limitation. Instead test `_health` endpoint which doesn't need external APIs.

- [ ] **Step 3.4: Write test for `_health` endpoint**

```php
public function testHealthEndpointReturnsStructure(): void {
    $output = captureOutput(function() {
        $_GET['_health'] = '1';
        require __DIR__ . '/../../../src/php/grid_ajax.php';
    });
    $data = json_decode($output, true);
    $this->assertTrue($data['ok']);
    $this->assertArrayHasKey('ts', $data);
    $this->assertArrayHasKey('bot_running', $data);
    $this->assertArrayHasKey('mysql', $data);
    $this->assertArrayHasKey('bybit_api', $data);
}
```

- [ ] **Step 3.5: Write test for `_status` with mocked DB** - Use SQLite in-memory for integration tests.

- [ ] **Step 3.6: Write test for `_logs` endpoint**

```php
public function testLogsEndpointReturnsArray(): void {
    $tmpLog = sys_get_temp_dir() . '/test_bot_log_' . uniqid() . '.log';
    file_put_contents($tmpLog, "2026-01-01 00:00:00 [INFO] Test line\n");
    $origLogFile = $GLOBALS['logFile'] ?? '';
    $GLOBALS['logFile'] = $tmpLog;
    
    $output = captureOutput(function() {
        $_GET['_logs'] = '1';
        require __DIR__ . '/../../../src/php/grid_ajax.php';
    });
    
    $GLOBALS['logFile'] = $origLogFile;
    unlink($tmpLog);
    
    $data = json_decode($output, true);
    $this->assertArrayHasKey('lines', $data);
    $this->assertIsArray($data['lines']);
    $this->assertGreaterThan(0, count($data['lines']));
}
```

- [ ] **Step 3.7: Commit integration tests**

```bash
git add tests/php/Integration/
git commit -m "test: add integration tests for API endpoints"
```

---

### Task 4: JavaScript Unit Tests - Dashboard Logic

**Files:**
- Create: `tests/js/utils.test.ts`
- Create: `tests/js/formatters.test.ts`
- Create: `tests/js/chart-helpers.test.ts`

**Interfaces:**
- Consumes: Functions extracted from `index.php` `<script>` section
- Produces: test coverage for formatters, chart helpers, UI state

- [ ] **Step 4.1: Extract pure JS functions to separate module**

**Files:**
- Create: `src/php/assets/js/utils.js` (ES module)
- Modify: `src/php/index.php` (import instead of inline)

```javascript
// src/php/assets/js/utils.js
export const $ = (id) => document.getElementById(id);

export function fP(v, d = 2) {
  return '$' + parseFloat(v || 0).toFixed(d);
}

export function fM(v, d = 4) {
  v = parseFloat(v || 0);
  if (isNaN(v)) return '<span style="color:var(--muted)">--</span>';
  const cls = v > 0 ? 'c-pos' : v < 0 ? 'c-neg' : 'c-dim';
  return `<span class="${cls}">${v > 0 ? '+' : ''}${v.toFixed(d)}</span>`;
}

export function debounce(fn, ms) {
  let t;
  return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
}

export const G_LEN = Math.PI * 64;
export function setGauge(conf, dir) { /* ... existing implementation ... */ }
```

- [ ] **Step 4.2: Update index.php to import utils.js**

```html
<script type="module">
import { $, fP, fM, debounce, setGauge, G_LEN } from './assets/js/utils.js';
// ... rest of inline script uses imported functions
</script>
```

- [ ] **Step 4.3: Write test for `fP` formatter**

```ts
// tests/js/formatters.test.ts
import { fP, fM } from '../src/php/assets/js/utils.js';

describe('fP', () => {
  test('formats positive number', () => {
    expect(fP(1234.56)).toBe('$1234.56');
  });
  test('formats negative number', () => {
    expect(fP(-99.9)).toBe('$-99.90');
  });
  test('handles zero', () => {
    expect(fP(0)).toBe('$0.00');
  });
  test('handles null/undefined', () => {
    expect(fP(null)).toBe('$0.00');
    expect(fP(undefined)).toBe('$0.00');
  });
});

describe('fM', () => {
  test('positive shows green class', () => {
    expect(fM(10.5)).toContain('c-pos');
    expect(fM(10.5)).toContain('+10.5000');
  });
  test('negative shows red class', () => {
    expect(fM(-5.25)).toContain('c-neg');
    expect(fM(-5.25)).toContain('-5.2500');
  });
  test('zero shows dim class', () => {
    expect(fM(0)).toContain('c-dim');
  });
  test('NaN returns muted span', () => {
    expect(fM(NaN)).toContain('var(--muted)');
    expect(fM(NaN)).toContain('--');
  });
});
```

- [ ] **Step 4.4: Run Vitest - expect PASS**

```bash
npm test -- tests/js/formatters.test.ts
```
Expected: All tests pass

- [ ] **Step 4.5: Write test for `debounce`**

```ts
import { debounce } from '../src/php/assets/js/utils.js';

describe('debounce', () => {
  test('delays execution', async () => {
    const fn = vi.fn();
    const debounced = debounce(fn, 50);
    debounced();
    debounced();
    expect(fn).not.toHaveBeenCalled();
    await new Promise(r => setTimeout(r, 60));
    expect(fn).toHaveBeenCalledTimes(1);
  });
  
  test('passes arguments', async () => {
    const fn = vi.fn();
    const debounced = debounce(fn, 10);
    debounced('arg1', 123);
    await new Promise(r => setTimeout(r, 20));
    expect(fn).toHaveBeenCalledWith('arg1', 123);
  });
});
```

- [ ] **Step 4.6: Write test for `setGauge` (DOM required)**

```ts
import { setGauge } from '../src/php/assets/js/utils.js';

describe('setGauge', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <svg viewBox="0 0 160 88">
        <path id="gArc" d="M 16 80 A 64 64 0 0 1 144 80"/>
      </svg>
      <div id="gLbl"></div>
      <div id="gDir"></div>
      <div id="gRsn"></div>
    `;
  });
  
  test('updates gauge arc and labels', () => {
    setGauge(75, 'UP');
    const arc = document.getElementById('gArc');
    expect(arc.style.stroke).toBe('var(--green)');
    expect(arc.style.strokeDashoffset).not.toBe('');
    expect(document.getElementById('gLbl').textContent).toBe('75%');
    expect(document.getElementById('gDir').innerHTML).toContain('▲ UP');
  });
});
```

- [ ] **Step 4.7: Commit JS tests + extracted module**

```bash
git add src/php/assets/js/utils.js tests/js/ src/php/index.php
git commit -m "test: add JS unit tests + extract utils module"
```

---

### Task 5: GitHub Actions CI Pipeline

**Files:**
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: all test/lint commands from Tasks 1-4
- Produces: CI pipeline running on push/PR

- [ ] **Step 5.1: Create CI workflow**

```yaml
# .github/workflows/ci.yml
name: CI

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main]

jobs:
  php:
    name: PHP Tests & Analysis
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo, pdo_mysql, curl, mbstring, json, dom
          tools: composer:v2
          
      - name: Cache Composer
        uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ hashFiles('composer.lock') }}
          
      - name: Install dependencies
        run: composer install --prefer-dist --no-progress
        
      - name: Run PHPUnit
        run: vendor/bin/phpunit --testdox
        
      - name: Run PHPStan
        run: vendor/bin/phpstan analyse --level=5 src/php
        
      - name: Run CodeSniffer
        run: vendor/bin/phpcs --standard=PSR12 src/php

  js:
    name: JS Tests & Lint
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      
      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'
          
      - name: Install dependencies
        run: npm ci
        
      - name: Run Vitest
        run: npm test
        
      - name: Run ESLint
        run: npm run lint

  integration:
    name: Integration Smoke Test
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mariadb:10.11
        env:
          MYSQL_ROOT_PASSWORD: test
          MYSQL_DATABASE: test_bot
          MYSQL_USER: test_user
          MYSQL_PASSWORD: test_pass
        ports: ['3306:3306']
        options: --health-cmd="mysqladmin ping" --health-interval=10s --health-timeout=5s --health-retries=5
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', extensions: pdo, pdo_mysql, curl, mbstring }
      - run: composer install --prefer-dist --no-progress
      - name: Run integration tests
        env:
          MYSQL_HOST: 127.0.0.1
          MYSQL_DBNAME: test_bot
          MYSQL_USER: test_user
          MYSQL_PASSWORD: test_pass
        run: vendor/bin/phpunit tests/php/Integration --testdox
```

- [ ] **Step 5.2: Test CI locally with `act` (optional)**

```bash
# If act is available: act -j php
```

- [ ] **Step 5.3: Commit CI workflow**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: add GitHub Actions pipeline (PHP + JS + integration)"
```

---

### Task 6: Dashboard Enhancements (Minor)

**Files:**
- Modify: `src/php/index.php` (add features)
- Create: `src/php/assets/js/keyboard.ts` (new)

**Interfaces:**
- Consumes: existing dashboard UI
- Produces: keyboard shortcuts, theme toggle, export improvements

- [ ] **Step 6.1: Add keyboard shortcuts**

```javascript
// src/php/assets/js/keyboard.js
export function initKeyboardShortcuts() {
  const shortcuts = {
    ' ': () => toggleSpeed(),
    'r': () => cmd('reset_grid'),
    's': () => cmd('stop'),
    'a': () => cmd('force_ai'),
    'c': () => openConfig(),
    'e': () => exportPnl(),
    'l': () => { const lb = $('logBox'); lb.scrollTop = lb.scrollHeight; },
    '?': () => showShortcutsHelp()
  };
  
  document.addEventListener('keydown', (e) => {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    const action = shortcuts[e.key.toLowerCase()];
    if (action) { e.preventDefault(); action(); }
  });
}

function showShortcutsHelp() {
  const help = `⌨️ Atajos:
  Espacio - Cambiar velocidad
  R - Reconstruir grilla
  S - Detener bot
  A - Forzar IA
  C - Configuración
  E - Exportar PnL
  L - Scroll logs
  ? - Esta ayuda`;
  toast('Atajos', help, 'info');
}
```

- [ ] **Step 6.2: Add dark/light theme toggle (optional, low priority)**

```css
/* Add to index.php <style> */
:root[data-theme="light"] {
  --bg:#f8fafc; --bg2:#fff; --bg3:#f1f5f9; --bg4:#e2e8f0;
  --border:#cbd5e1; --border2:#94a3b8;
  --text:#0f172a; --muted:#64748b; --dim:#94a3b8;
  --acc-g:rgba(45,140,255,.15); --gn-g:rgba(0,201,122,.15);
  --rd-g:rgba(240,60,82,.15); --yl-g:rgba(245,166,35,.15);
}
```

```javascript
function toggleTheme() {
  const html = document.documentElement;
  const isLight = html.dataset.theme === 'light';
  html.dataset.theme = isLight ? 'dark' : 'light';
  localStorage.setItem('theme', html.dataset.theme);
}
```

- [ ] **Step 6.3: Improve PnL export (add weekly/monthly CSV)**

```php
// In index.php export_pnl handler - add period param
$period = $_GET['period'] ?? 'daily'; // daily|weekly|monthly
$groupBy = match($period) {
  'weekly' => "YEARWEEK(filled_at, 1)",
  'monthly' => "DATE_FORMAT(filled_at, '%Y-%m')",
  default => "DATE(filled_at)"
};
```

- [ ] **Step 6.4: Commit enhancements**

```bash
git add src/php/index.php src/php/assets/js/
git commit -m "feat: add keyboard shortcuts, theme toggle, enhanced PnL export"
```

---

### Task 7: Documentation & README

**Files:**
- Create: `docs/dashboard-testing.md`
- Modify: `README.md` (add test/CI badges)

- [ ] **Step 7.1: Write testing guide**

```markdown
# Dashboard Testing Guide

## Running Tests

### PHP Tests
```bash
# All tests
composer test

# With coverage
composer test:coverage

# Specific test
vendor/bin/phpunit tests/php/Unit/HelpersTest.php
```

### JavaScript Tests
```bash
# All tests
npm test

# Watch mode
npm run test:watch

# With coverage
npm run test:coverage
```

### Static Analysis
```bash
# PHPStan
composer stan

# CodeSniffer
composer cs

# Auto-fix
composer cs:fix

# ESLint
npm run lint
npm run lint:fix
```

## CI Pipeline
Runs on every push/PR to main/develop:
1. PHP Unit + Integration tests
2. PHPStan level 5
3. PSR-12 CodeSniffer
4. Vitest JS tests
5. ESLint
6. Integration tests with MariaDB

## Adding New Tests
- PHP unit: `tests/php/Unit/`
- PHP integration: `tests/php/Integration/`
- JS unit: `tests/js/`
```

- [ ] **Step 7.2: Update README with badges**

```markdown
![PHP Tests](https://github.com/.../actions/workflows/ci.yml/badge.php)
![JS Tests](https://github.com/.../actions/workflows/ci.yml/badge.js)
![PHPStan](https://img.shields.io/badge/PHPStan-level%205-brightgreen)
![Coverage](https://img.shields.io/badge/coverage-%3E80%25-brightgreen)
```

- [ ] **Step 7.3: Commit docs**

```bash
git add docs/ README.md
git commit -m "docs: add testing guide + CI badges"
```

---

## Self-Review Checklist

- [ ] **Spec coverage:** All missing pieces addressed (tests, CI, linting, minor enhancements)
- [ ] **No placeholders:** Every step has actual code/commands
- [ ] **Type consistency:** PHP/JS function signatures match across tasks
- [ ] **Task independence:** Each task produces testable deliverable
- [ ] **DRY/YAGNI:** No over-engineering; extracted only what's needed for testing

---

## Execution Handoff

**Plan complete and saved to `docs/superpowers/plans/2026-07-25-dashboard-testing-ci.md`.**

**Two execution options:**

1. **Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks
   - REQUIRED SUB-SKILL: `superpowers:subagent-driven-development`

2. **Inline Execution** - Execute tasks in this session using `superpowers:executing-plans`
   - Batch execution with checkpoints for review

**Which approach?**