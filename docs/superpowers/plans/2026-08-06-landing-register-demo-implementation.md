# Landing Pública + Registro/Login Estilizado + Controles Protegidos — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Crear una landing pública (`index.php` raíz) con stats en vivo, páginas `register.php`/`login.php` estilizadas con el design-system dark, y proteger los controles destructivos del dashboard (`_control`/`update_config`) exigiendo sesión de rol `admin`.

**Architecture:** Reutiliza el design-system existente (`src/php/assets/css/design-system.css`), la lógica de auth existente (`Core\Auth`, `Core\Csrf`, `Core\Schema`) y el backend `grid_ajax.php`. La landing consulta un nuevo endpoint público `_landing_stats`. Los controles del bot se protegen server-side con una comprobación de sesión admin, y el front del dashboard oculta los botones sin sesión.

**Tech Stack:** PHP 8.3 (sin framework), MySQL/MariaDB, HTML/CSS/JS vanilla, PHPUnit 9, nginx+Apache+php-fpm.

## Global Constraints

- PHP 8.3, strict_types en clases Core nuevas, sin framework.
- No modificar `Core\AuthHttp.php` (queda como fallback), ni `panel.php`, `admin.php`, `bot.php`, `scanner.php`, servidor WS.
- Reutilizar el design-system: variables `--bg-primary`, `--accent`, `--green`, `--red`, clases `.btn`, `.btn-primary`, `.btn-danger`, `.badge`, `.data-table`, `.panel-tabs`.
- Idiomas de UI: español, formato fecha `Y-m-d H:i:s`, moneda `$` con 2 decimales.
- El endpoint `_landing_stats` es público; `_control` y `update_config` exigen `$_SESSION['role'] === 'admin'` además del token existente.
- CSRF en todos los POST de registro/login (`Core\Csrf::token` + `Core\Csrf::verify`).
- Tests: PHPUnit (suite Unit e Integration). Test de `grid_ajax` se hace vía subproceso (`proc_open`) con `$_GET`/`$_POST` inyectados.
- Los commits se hacen al final de cada tarea; mensajes en inglés tipo conventional commits.

---

### Task 1: Endpoint público `_landing_stats` en grid_ajax.php

**Files:**
- Modify: `src/php/grid_ajax.php` (insertar después del bloque `_health`, antes del `echo json_encode(['error' => 'no action'...)` final)

**Interfaces:**
- Produces: endpoint `GET /src/php/grid_ajax.php?_landing_stats=1` → JSON `{ok, price, pnl_today, win_rate, fills_total, open_orders, updated_at}` (público, sin sesión).

- [ ] **Step 1: Write the failing integration test**

Add to `tests/php/Integration/ApiEndpointsTest.php`:

```php
public function testLandingStatsEndpointReturnsStructure(): void
{
    $data = $this->executeEndpoint(['_landing_stats' => '1']);
    $this->assertIsArray($data);
    $this->assertTrue($data['ok']);
    $this->assertArrayHasKey('price', $data);
    $this->assertArrayHasKey('pnl_today', $data);
    $this->assertArrayHasKey('win_rate', $data);
    $this->assertArrayHasKey('fills_total', $data);
    $this->assertArrayHasKey('open_orders', $data);
    $this->assertArrayHasKey('updated_at', $data);
}

public function testLandingStatsReturnsNumericFields(): void
{
    $data = $this->executeEndpoint(['_landing_stats' => '1']);
    $this->assertIsFloat($data['price']);
    $this->assertIsFloat($data['pnl_today']);
    $this->assertIsFloat($data['win_rate']);
    $this->assertIsInt($data['fills_total']);
    $this->assertIsInt($data['open_orders']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter LandingStats tests/php/Integration/ApiEndpointsTest.php`
Expected: FAIL (keys `ok`/`price` missing; response is `{"error":"no action",...}`)

- [ ] **Step 3: Add the endpoint**

Insert in `src/php/grid_ajax.php` just before the final `echo json_encode(['error' => 'no action'...`:

```php
// ═══════════════════════════════════════════════════════
// 6b. LANDING STATS (público, solo lectura)
// ═══════════════════════════════════════════════════════
if (isset($_GET['_landing_stats'])) {
    $db = getDB($mc);
    $data = ['ok' => true, 'price' => 0.0, 'pnl_today' => 0.0, 'win_rate' => 0.0,
             'fills_total' => 0, 'open_orders' => 0, 'updated_at' => date('Y-m-d H:i:s')];
    $st = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : null;
    if ($st && isset($st['pairs']['ETHUSDT']['price'])) {
        $data['price'] = (float)$st['pairs']['ETHUSDT']['price'];
    }
    if ($db) {
        dbInitOnce($db);
        try {
            $r1 = $db->query("SELECT COUNT(*) c, COALESCE(SUM(pnl_usd),0) p FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE()")->fetch();
            $r2 = $db->query("SELECT COUNT(*) c, COALESCE(SUM(pnl_usd),0) p FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED'")->fetch();
            $totalFills = (int)($r2['c'] ?? 0);
            $wins = (int)$db->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' AND pnl_usd>0")->fetchColumn();
            $data['pnl_today']   = round((float)($r1['p'] ?? 0), 6);
            $data['fills_total'] = $totalFills;
            $data['win_rate']    = $totalFills > 0 ? round(($wins / $totalFills) * 100, 1) : 0.0;
            $data['open_orders'] = (int)$db->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='ETHUSDT' AND status='OPEN'")->fetchColumn();
        } catch (Exception $e) {}
    }
    echo json_encode($data); exit;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter LandingStats tests/php/Integration/ApiEndpointsTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Run full suite for regressions**

Run: `vendor/bin/phpunit`
Expected: PASS (no failures)

- [ ] **Step 6: Commit**

```bash
git add src/php/grid_ajax.php tests/php/Integration/ApiEndpointsTest.php
git commit -m "feat(api): add public _landing_stats endpoint"
```

---

### Task 2: Helper `isAdminSession()` + guard en `_control` y `update_config`

**Files:**
- Modify: `src/php/Helpers.php` (añadir función al final)
- Modify: `src/php/grid_ajax.php` (bloques `_control` y `update_config`)
- Test: `tests/php/Unit/HelpersTest.php`

**Interfaces:**
- Produces: `function isAdminSession(array $session): bool` — true si `($session['role'] ?? '') === 'admin'`.

- [ ] **Step 1: Write the failing unit tests**

Add to `tests/php/Unit/HelpersTest.php`:

```php
public function testIsAdminSessionFalseWhenEmpty(): void
{
    $this->assertFalse(isAdminSession([]));
}

public function testIsAdminSessionFalseForInvestor(): void
{
    $this->assertFalse(isAdminSession(['role' => 'investor']));
}

public function testIsAdminSessionTrueForAdmin(): void
{
    $this->assertTrue(isAdminSession(['role' => 'admin']));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter IsAdminSession tests/php/Unit/HelpersTest.php`
Expected: FAIL (undefined function)

- [ ] **Step 3: Implement `isAdminSession()`**

Append to `src/php/Helpers.php`:

```php
function isAdminSession(array $session): bool {
    return ($session['role'] ?? '') === 'admin';
}
```

- [ ] **Step 4: Run unit test to verify it passes**

Run: `vendor/bin/phpunit --filter IsAdminSession tests/php/Unit/HelpersTest.php`
Expected: PASS

- [ ] **Step 5: Add guard to `_control`**

In `src/php/grid_ajax.php`, at the top of the file after `if (!function_exists('sanitize')) { require_once __DIR__ . '/Helpers.php'; }`, add session bootstrap:

```php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => true,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}
```

Then replace the `_control` block header:

```php
if (isset($_POST['_control'])) {
    if (!isAdminSession($_SESSION)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
        exit;
    }
    if (!checkToken($requiredToken)) {
```

- [ ] **Step 6: Add guard to `update_config`**

In `src/php/grid_ajax.php`, replace:

```php
if (isset($_POST['action']) && $_POST['action'] === 'update_config') {
```

with:

```php
if (isset($_POST['action']) && $_POST['action'] === 'update_config') {
    if (!isAdminSession($_SESSION)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
        exit;
    }
```

- [ ] **Step 7: Write integration tests for the guard**

Add to `tests/php/Integration/ApiEndpointsTest.php`:

```php
public function testControlWithoutAdminSessionRejected(): void
{
    $script = <<<PHP
<?php
error_reporting(0);
ini_set("display_errors", "0");
\$_SESSION = [];
\$_POST = ['_control' => '1', 'action' => 'stop'];
\$_SERVER = ["REQUEST_METHOD" => "POST"];
chdir('/home/erika/web/binance.gregorbritez.cat/public_html');
ob_start();
require '/home/erika/web/binance.gregorbritez.cat/public_html/src/php/grid_ajax.php';
\$output = ob_get_clean();
echo \$output;
PHP;
    $tmpFile = sys_get_temp_dir() . '/test_control_' . uniqid() . '.php';
    file_put_contents($tmpFile, $script);
    $process = proc_open('php ' . escapeshellarg($tmpFile), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($process);
    unlink($tmpFile);
    $result = json_decode($output ?: '{}', true);
    $this->assertIsArray($result);
    $this->assertFalse($result['ok']);
    $this->assertSame('No autorizado', $result['msg']);
}
```

Note: because `session_start()` is invoked when the subprocess runs, the freshly created session is empty → `$_SESSION['role']` unset → guard rejects. This test verifies the 403 path.

- [ ] **Step 8: Run the new integration test**

Run: `vendor/bin/phpunit --filter ControlWithoutAdminSession tests/php/Integration/ApiEndpointsTest.php`
Expected: PASS

- [ ] **Step 9: Run full suite**

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add src/php/Helpers.php src/php/grid_ajax.php tests/php/Unit/HelpersTest.php tests/php/Integration/ApiEndpointsTest.php
git commit -m "feat(api): require admin session for bot control and config update"
```

---

### Task 3: Landing pública en `index.php` raíz

**Files:**
- Modify: `index.php` (raíz — reemplaza el redirect)
- Reference: `src/php/assets/css/design-system.css`

**Interfaces:**
- Consumes: endpoint `_landing_stats` (Task 1).
- Produces: página pública en `/` con stats en vivo; enlaces a `src/php/register.php`, `src/php/login.php`, y botón demo → `src/php/index.php`.

- [ ] **Step 1: Replace the root `index.php`**

Replace the entire content of `index.php` (currently a redirect) with the landing page:

```php
<?php
/**
 * index.php — Landing pública Grid Bot · ETH/USDT
 */
error_reporting(0); ini_set('display_errors', '0');
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Grid Bot · ETH/USDT · Trading Automatizado</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="src/php/assets/css/design-system.css">
<style>
:root{--bg:#0a0e17;--bg2:#111827;--bg3:#1a2333;--border:#1e3a5f;--accent:#0ea5e9;--green:#22c55e;--red:#ef4444;--text:#f1f5f9;--muted:#94a3b8;--dim:#64748b;--radius:12px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);line-height:1.6}
a{color:var(--accent);text-decoration:none}
.land-nav{position:sticky;top:0;z-index:100;background:rgba(10,14,23,.92);backdrop-filter:blur(16px);border-bottom:1px solid var(--border);padding:0 24px;height:60px;display:flex;align-items:center;justify-content:space-between}
.brand{display:flex;align-items:center;gap:10px;font-weight:700}
.brand-icon{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--accent),#7c3aed);display:grid;place-items:center;font-size:18px}
.brand-sub{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);display:block;margin-top:1px}
.nav-links{display:flex;align-items:center;gap:20px}
.nav-links a{font-size:13px;color:var(--muted)}
.nav-links a:hover{color:var(--text)}
.hero{display:grid;grid-template-columns:1.2fr .8fr;gap:40px;max-width:1100px;margin:0 auto;padding:80px 24px 60px;align-items:center}
@media(max-width:767px){.hero{grid-template-columns:1fr;padding:48px 24px 40px}}
.hero h1{font-size:clamp(28px,4.5vw,46px);line-height:1.15;margin-bottom:16px}
.hero h1 .grad{background:linear-gradient(90deg,var(--accent),#7c3aed);-webkit-background-clip:text;background-clip:text;color:transparent}
.hero p.lead{color:var(--muted);font-size:17px;max-width:520px;margin-bottom:28px}
.price-big{font-family:'JetBrains Mono',monospace;font-size:30px;font-weight:600}
.price-chg-up{color:var(--green);font-size:13px;font-weight:700}
.price-chg-dn{color:var(--red);font-size:13px;font-weight:700}
.cta-row{display:flex;gap:12px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 20px;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer;border:1px solid var(--border);background:var(--bg3);color:var(--text);transition:.15s}
.btn:hover{transform:translateY(-1px)}
.btn-primary{background:var(--accent);color:#fff;border-color:var(--accent)}
.btn-primary:hover{background:#38bdf8}
.btn-outline{background:transparent;border-color:var(--border)}
.btn-outline:hover{border-color:var(--accent);color:var(--accent)}
.stats-dash{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:24px}
.stats-dash h3{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:16px}
.stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.stat{border-top:2px solid var(--border)}
.stat-lbl{font-size:11px;color:var(--muted);margin:8px 0 4px}
.stat-val{font-family:'JetBrains Mono',monospace;font-size:18px;font-weight:600}
.stat-val.up{color:var(--green)}.stat-val.down{color:var(--red)}.stat-val.accent{color:var(--accent)}
.section{max-width:1100px;margin:0 auto;padding:70px 24px}
.section-hd{text-align:center;margin-bottom:44px}
.section-hd h2{font-size:30px;margin-bottom:8px}
.section-hd p{color:var(--muted)}
.features{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
@media(max-width:767px){.features{grid-template-columns:1fr}}
.feat{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:22px}
.feat-icon{font-size:24px;margin-bottom:10px}
.feat h3{font-size:16px;margin-bottom:6px}
.feat p{color:var(--muted);font-size:13px}
.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
@media(max-width:767px){.steps{grid-template-columns:1fr}}
.step{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:22px;position:relative}
.step-num{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--accent);font-weight:700;margin-bottom:8px}
.step h3{font-size:16px;margin-bottom:6px}
.step p{color:var(--muted);font-size:13px}
.demo-section{max-width:1100px;margin:0 auto;padding:40px 24px 80px}
.demo-card{background:linear-gradient(135deg,var(--bg2),var(--bg3));border:1px solid var(--border);border-radius:var(--radius);padding:44px;text-align:center}
.demo-card h2{font-size:26px;margin-bottom:8px}
.demo-card p{color:var(--muted);margin-bottom:24px}
.land-footer{border-top:1px solid var(--border);padding:32px 24px;text-align:center;color:var(--dim);font-size:12px}
.land-footer p{margin:6px 0}
.spin{display:inline-block;width:14px;height:14px;border:2px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:sp 1s linear infinite;vertical-align:-2px}
@keyframes sp{to{transform:rotate(360deg)}}
</style>
</head>
<body>
<nav class="land-nav">
  <div class="brand">
    <div class="brand-icon">⚡</div>
    <div>
      <div>Grid Bot</div>
      <div class="brand-sub">ETH/USDT · BYBIT · 24/7</div>
    </div>
  </div>
  <div class="nav-links">
    <a href="#demo">Demo</a>
    <a href="#como-funciona">Cómo funciona</a>
    <a href="#caracteristicas">Características</a>
    <a href="src/php/login.php">Ingresar</a>
    <a href="src/php/register.php" class="btn btn-primary" style="padding:8px 16px">Crear cuenta</a>
  </div>
</nav>

<section class="hero">
  <div>
    <h1>Trading automatizado con <span class="grad">Grid Bot + IA</span> en ETH/USDT</h1>
    <p class="lead">Estrategia grid en Bybit con señales de machine learning, rebalanceo en tiempo real y gestión de riesgo. Sin errores humanos, operando 24/7.</p>
    <div class="cta-row">
      <a href="#demo" class="btn btn-primary">▶ Ver demo en vivo</a>
      <a href="src/php/register.php" class="btn btn-outline">Crear cuenta gratis</a>
    </div>
  </div>
  <div class="stats-dash">
    <h3>Rendimiento en vivo</h3>
    <div class="price-big" id="ldPrice">$<span id="ldPriceVal">--</span></div>
    <div id="ldChg" class="price-chg-up">--</div>
    <div class="stats-grid" style="margin-top:18px">
      <div class="stat"><div class="stat-lbl">PnL de hoy</div><div class="stat-val" id="ldPnl">--</div></div>
      <div class="stat"><div class="stat-lbl">Win rate</div><div class="stat-val accent" id="ldWin">--</div></div>
      <div class="stat"><div class="stat-lbl">Fills totales</div><div class="stat-val" id="ldFills">--</div></div>
      <div class="stat"><div class="stat-lbl">Órdenes abiertas</div><div class="stat-val" id="ldOpen">--</div></div>
    </div>
    <div style="margin-top:14px;font-size:11px;color:var(--dim)" id="ldUpd">Actualizado: --</div>
  </div>
</section>

<section id="caracteristicas" class="section">
  <div class="section-hd"><h2>Características</h2><p>Todo lo que el bot hace por ti</p></div>
  <div class="features">
    <div class="feat"><div class="feat-icon">📊</div><h3>Grid Trading</h3><p>Compra y vende en niveles predefinidos automáticamente, capturando la volatilidad del mercado.</p></div>
    <div class="feat"><div class="feat-icon">🧠</div><h3>Señales con IA</h3><p>Modelos de machine learning evalúan dirección, confianza y razón de cada operación.</p></div>
    <div class="feat"><div class="feat-icon">⚡</div><h3>Tiempo real</h3><p>WebSocket bidireccional con actualización instantánea de precio, PnL y órdenes.</p></div>
    <div class="feat"><div class="feat-icon">🛡️</div><h3>Gestión de riesgo</h3><p>Modo recovery, límites de apalancamiento y paradas para proteger el capital.</p></div>
    <div class="feat"><div class="feat-icon">🕐</div><h3>24/7</h3><p>Servicio systemd con reinicio automático. Sin dependencia de estar pendiente de la pantalla.</p></div>
    <div class="feat"><div class="feat-icon">📈</div><h3>PnL trazable</h3><p>Historial completo de fills, win rate y acumulado por día/hora en el dashboard.</p></div>
  </div>
</section>

<section id="como-funciona" class="section">
  <div class="section-hd"><h2>Cómo funciona</h2><p>Empieza en tres pasos</p></div>
  <div class="steps">
    <div class="step"><div class="step-num">PASO 1</div><h3>Regístrate</h3><p>Crea tu cuenta de inversor con usuario, email y contraseña.</p></div>
    <div class="step"><div class="step-num">PASO 2</div><h3>Recibe tu dashboard</h3><p>Accede al panel de inversor con tu capital, movimientos y rendimiento.</p></div>
    <div class="step"><div class="step-num">PASO 3</div><h3>Sigue tu PnL</h3><p>Visualiza en tiempo real cómo el bot opera y crece tu equidad.</p></div>
  </div>
</section>

<section id="demo" class="demo-section">
  <div class="demo-card">
    <h2>¿Quieres ver el bot en acción?</h2>
    <p>Abre el dashboard completo con el gráfico, el ladder de órdenes y el PnL en tiempo real.</p>
    <a href="src/php/index.php" target="_blank" rel="noopener" class="btn btn-primary">▶ Abrir dashboard en vivo</a>
  </div>
</section>

<footer class="land-footer">
  <p><strong>Grid Bot · ETH/USDT</strong> · Trading automatizado sobre Bybit</p>
  <p>⚠️ El trading de criptomonedas con apalancamiento conlleva un alto riesgo. Este producto no es una recomendación de inversión.</p>
  <p>© <?= date('Y') ?> binance.gregorbritez.cat</p>
</footer>

<script>
const API = 'src/php/grid_ajax.php';
const fmt = v => (parseFloat(v)||0).toLocaleString('es-PY',{maximumFractionDigits:2});
const fmt2 = v => (parseFloat(v)||0).toLocaleString('es-PY',{minimumFractionDigits:2,maximumFractionDigits:2});
async function loadStats(){
  try{
    const r = await fetch(API+'?_landing_stats=1');
    const d = await r.json();
    if(!d || !d.ok) throw new Error('bad response');
    const price = parseFloat(d.price||0);
    const pnl = parseFloat(d.pnl_today||0);
    document.getElementById('ldPriceVal').textContent = fmt2(price);
    const chgEl = document.getElementById('ldChg');
    chgEl.textContent = 'ETH/USDT · Bybit';
    chgEl.className = 'price-chg-up';
    const pnlEl = document.getElementById('ldPnl');
    pnlEl.textContent = (pnl>=0?'+':'')+fmt2(pnl)+' $';
    pnlEl.className = 'stat-val '+(pnl>=0?'up':'down');
    document.getElementById('ldWin').textContent = fmt(d.win_rate)+'%';
    document.getElementById('ldFills').textContent = fmt(d.fills_total);
    document.getElementById('ldOpen').textContent = fmt(d.open_orders);
    document.getElementById('ldUpd').textContent = 'Actualizado: '+(d.updated_at||'').replace('T',' ').slice(0,19);
  }catch(e){
    document.getElementById('ldUpd').textContent = 'No se pudieron cargar las estadísticas';
  }
}
loadStats();
setInterval(loadStats, 10000);
</script>
</body>
</html>
```

- [ ] **Step 2: Verify the landing loads**

Run: `curl -s http://192.168.100.170:8080/ | head -5`
Expected: HTML starts with `<!DOCTYPE html>` and contains `Grid Bot`

- [ ] **Step 3: Verify the stats endpoint responds**

Run: `curl -s 'http://192.168.100.170:8080/src/php/grid_ajax.php?_landing_stats=1'`
Expected: JSON with `ok:true` and numeric fields

- [ ] **Step 4: Commit**

```bash
git add index.php
git commit -m "feat(landing): public landing page with live stats"
```

---

### Task 4: Páginas `register.php` y `login.php` estilizadas

**Files:**
- Create: `src/php/register.php`
- Create: `src/php/login.php`
- Test: `tests/php/Unit/Core/AuthHttpTest.php` (opcional, si se quiere cubrir flujo)

**Interfaces:**
- Consumes: `Core\Auth::register`, `Core\Auth::login`, `Core\Auth::checkRateLimit`, `Core\Auth::recordAttempt`, `Core\Csrf::token`, `Core\Csrf::verify`, `Core\Database`, `Core\Schema`.
- Produces: `src/php/register.php` (formulario POST con `action=register`, csrf, username/email/password) y `src/php/login.php` (formulario POST con `action=login`, csrf, username/password). Ambos redirigen a `panel.php` al éxito.

- [ ] **Step 1: Create `src/php/register.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use BinanceBot\Core\Csrf;
use BinanceBot\Core\Database;
use BinanceBot\Core\Schema;
use BinanceBot\Core\Auth;

session_set_cookie_params([
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();

$db = Database::getInstance();
$pdo = $db->getPdo();
if (!$pdo) {
    http_response_code(500);
    exit('Base de datos no disponible');
}
Schema::createTables($pdo);

$error = null;
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_SESSION, $_POST['csrf'] ?? null)) {
        $error = 'Token CSRF inválido';
    } else {
        $res = Auth::register($pdo, (string)($_POST['username'] ?? ''), (string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($res['ok']) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $res['user_id'];
            $_SESSION['username'] = trim((string)($_POST['username'] ?? ''));
            $_SESSION['role'] = 'investor';
            header('Location: panel.php');
            exit;
        }
        $error = $res['error'];
    }
}
$csrf = Csrf::token($_SESSION);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registro · Grid Bot</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/design-system.css">
<style>
:root{--bg:#0a0e17;--bg2:#111827;--bg3:#1a2333;--border:#1e3a5f;--accent:#0ea5e9;--green:#22c55e;--text:#f1f5f9;--muted:#94a3b8;--dim:#64748b}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
.auth-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:36px;width:100%;max-width:380px}
.auth-logo{width:44px;height:44px;border-radius:11px;background:linear-gradient(135deg,var(--accent),#7c3aed);display:grid;place-items:center;font-size:22px;margin-bottom:16px}
.auth-card h1{font-size:20px;margin-bottom:4px}
.auth-card p.sub{color:var(--muted);font-size:13px;margin-bottom:24px}
label{display:block;font-size:12px;color:var(--muted);margin:14px 0 5px;font-weight:600}
input{width:100%;box-sizing:border-box;padding:11px 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-size:14px;outline:none}
input:focus{border-color:var(--accent)}
.btn{width:100%;margin-top:20px;padding:12px;border:0;border-radius:8px;background:var(--accent);color:#fff;font-weight:700;font-size:14px;cursor:pointer}
.btn:hover{background:#38bdf8}
.error{color:#ef4444;font-size:13px;margin-top:12px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:8px;padding:10px 12px}
.alt{margin-top:18px;font-size:13px;text-align:center;color:var(--muted)}
.alt a{color:var(--accent);font-weight:600}
.back{margin-top:14px;text-align:center;font-size:12px}
.back a{color:var(--dim)}
</style>
</head>
<body>
<div class="auth-card">
  <div class="auth-logo">⚡</div>
  <h1>Crear cuenta</h1>
  <p class="sub">Únete al Grid Bot · ETH/USDT</p>
  <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post" novalidate>
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <label>Usuario</label>
    <input name="username" required minlength="3" maxlength="50" autocomplete="username" value="<?= htmlspecialchars((string)($_POST['username'] ?? '')) ?>">
    <label>Email</label>
    <input name="email" type="email" required autocomplete="email" value="<?= htmlspecialchars((string)($_POST['email'] ?? '')) ?>">
    <label>Contraseña (mín. 8)</label>
    <input name="password" type="password" required minlength="8" autocomplete="new-password">
    <button type="submit" class="btn">Crear cuenta</button>
  </form>
  <div class="alt">¿Ya tienes cuenta? <a href="login.php">Ingresar</a></div>
  <div class="back"><a href="../index.php">← Volver al inicio</a></div>
</div>
</body>
</html>
```

- [ ] **Step 2: Create `src/php/login.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use BinanceBot\Core\Csrf;
use BinanceBot\Core\Database;
use BinanceBot\Core\Schema;
use BinanceBot\Core\Auth;

session_set_cookie_params([
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();

$db = Database::getInstance();
$pdo = $db->getPdo();
if (!$pdo) {
    http_response_code(500);
    exit('Base de datos no disponible');
}
Schema::createTables($pdo);

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_SESSION, $_POST['csrf'] ?? null)) {
        $error = 'Token CSRF inválido';
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!Auth::checkRateLimit($pdo, $ip, 'login', 10, 900)) {
            $error = 'Demasiados intentos. Espera unos minutos.';
        } else {
            $user = Auth::login($pdo, (string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
            Auth::recordAttempt($pdo, $ip, 'login', (string)($_POST['username'] ?? ''), $user !== null);
            if ($user) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = (string)$user['username'];
                $_SESSION['role'] = (string)$user['role'];
                header('Location: panel.php');
                exit;
            }
            $error = 'Usuario o contraseña incorrectos';
        }
    }
}
$csrf = Csrf::token($_SESSION);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ingreso · Grid Bot</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/design-system.css">
<style>
:root{--bg:#0a0e17;--bg2:#111827;--bg3:#1a2333;--border:#1e3a5f;--accent:#0ea5e9;--green:#22c55e;--text:#f1f5f9;--muted:#94a3b8;--dim:#64748b}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
.auth-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:36px;width:100%;max-width:380px}
.auth-logo{width:44px;height:44px;border-radius:11px;background:linear-gradient(135deg,var(--accent),#7c3aed);display:grid;place-items:center;font-size:22px;margin-bottom:16px}
.auth-card h1{font-size:20px;margin-bottom:4px}
.auth-card p.sub{color:var(--muted);font-size:13px;margin-bottom:24px}
label{display:block;font-size:12px;color:var(--muted);margin:14px 0 5px;font-weight:600}
input{width:100%;box-sizing:border-box;padding:11px 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-size:14px;outline:none}
input:focus{border-color:var(--accent)}
.btn{width:100%;margin-top:20px;padding:12px;border:0;border-radius:8px;background:var(--accent);color:#fff;font-weight:700;font-size:14px;cursor:pointer}
.btn:hover{background:#38bdf8}
.error{color:#ef4444;font-size:13px;margin-top:12px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:8px;padding:10px 12px}
.alt{margin-top:18px;font-size:13px;text-align:center;color:var(--muted)}
.alt a{color:var(--accent);font-weight:600}
.back{margin-top:14px;text-align:center;font-size:12px}
.back a{color:var(--dim)}
</style>
</head>
<body>
<div class="auth-card">
  <div class="auth-logo">⚡</div>
  <h1>Ingresar</h1>
  <p class="sub">Accede a tu panel de inversor</p>
  <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post" novalidate>
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <label>Usuario</label>
    <input name="username" required autocomplete="username" value="<?= htmlspecialchars((string)($_POST['username'] ?? '')) ?>">
    <label>Contraseña</label>
    <input name="password" type="password" required autocomplete="current-password">
    <button type="submit" class="btn">Ingresar</button>
  </form>
  <div class="alt">¿Sin cuenta? <a href="register.php">Registrarse</a></div>
  <div class="back"><a href="../index.php">← Volver al inicio</a></div>
</div>
</body>
</html>
```

- [ ] **Step 3: Verify registration flow manually**

Run: `curl -s -c /tmp/landing_cookies.txt -o /dev/null -w "%{http_code}" http://192.168.100.170:8080/src/php/register.php`
Expected: `200`

- [ ] **Step 4: Verify login page loads**

Run: `curl -s -o /dev/null -w "%{http_code}" http://192.168.100.170:8080/src/php/login.php`
Expected: `200`

- [ ] **Step 5: Run full suite**

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/php/register.php src/php/login.php
git commit -m "feat(auth): styled register and login pages"
```

---

### Task 5: Dashboard — ocultar controles sin sesión admin

**Files:**
- Modify: `src/php/index.php` (PHP arriba + JS de token + botones)

**Interfaces:**
- Consumes: sesión PHP; `$IS_ADMIN` bool y `$CTRL_TOKEN` string inyectados en el HTML.
- Produces: dashboard con botones de control ocultos sin admin; `cmd()` envía `token`; `connectWebSocket` usa el token público solo lectura.

- [ ] **Step 1: Add session bootstrap + admin flag in the PHP preamble**

In `src/php/index.php`, after `define('EXPORT_TOKEN', getenv('SECURITY_TOKEN') ?: 'g273f123');` (line 27), add:

```php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => true,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}
$IS_ADMIN = ($_SESSION['role'] ?? '') === 'admin';
$CTRL_TOKEN = $IS_ADMIN ? EXPORT_TOKEN : '';
```

- [ ] **Step 2: Gate the control buttons in the topbar**

In `src/php/index.php`, replace the `.btns` block (currently lines ~437-444):

```html
    <div class="btns">
      <button class="btn btn-b" onclick="toggleSpeed()" id="speedBtn">⚡ Rápido</button>
      <?php if ($IS_ADMIN): ?>
      <button class="btn btn-b" onclick="openConfig()">⚙️</button>
      <button class="btn btn-b" onclick="cmd('force_ai')">🧠 IA</button>
      <button class="btn btn-g" onclick="cmd('reset_grid')">↻ Grid</button>
      <button class="btn btn-b" onclick="exportPnl()">📥</button>
      <button class="btn btn-r" onclick="cmd('stop')">■ Stop</button>
      <?php endif; ?>
    </div>
```

Note: `toggleSpeed` is local polling speed (no server call) so it stays public. `exportPnl` requires the token; it is inside the admin gate because the CSV shows PnL.

- [ ] **Step 3: Make `cmd()` send the token**

In `src/php/index.php`, replace the `cmd()` function:

```js
function cmd(action){
  const labels={stop:'¿Detener el bot?',force_ai:'¿Forzar evaluación IA?',reset_grid:'¿Reconstruir grilla?'};
  if(!confirm(labels[action]||'¿Confirmar?')) return;
  const fd=new FormData();fd.append('_control','1');fd.append('action',action);
  fd.append('token','<?= $CTRL_TOKEN ?>');
  fetch(API,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.ok)toast('Comando enviado',action,'info');else alert(d.msg);}).catch(()=>alert('Error'));
}
```

- [ ] **Step 4: Verify dashboard loads and controls hidden without session**

Run: `curl -s http://192.168.100.170:8080/src/php/index.php | grep -c "cmd('stop')\|exportPnl()"`
Expected: `0` (no session → controls not rendered)

- [ ] **Step 5: Verify `_control` still protected when called directly**

Run: `curl -s -X POST -d '_control=1&action=stop' http://192.168.100.170:8080/src/php/grid_ajax.php`
Expected: `{"ok":false,"msg":"No autorizado"}`

- [ ] **Step 6: Run full suite**

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/php/index.php
git commit -m "feat(dashboard): hide bot controls without admin session"
```

---

### Task 6: Revisión final y verificación E2E

**Files:**
- (ninguno nuevo)

**Interfaces:**
- Consumes: todas las tareas anteriores.

- [ ] **Step 1: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS, sin regresiones

- [ ] **Step 2: Lint PHP**

Run: `vendor/bin/phpcs src/php/grid_ajax.php src/php/register.php src/php/login.php src/php/Helpers.php index.php src/php/index.php`
Expected: sin errores de estilo (ajustar si hay warnings menores de longitud de línea en el HTML).

- [ ] **Step 3: E2E — landing**

Run: `curl -s http://192.168.100.170:8080/ | grep -c "Grid Bot"`
Expected: `>= 1`

- [ ] **Step 4: E2E — stats**

Run: `curl -s 'http://192.168.100.170:8080/src/php/grid_ajax.php?_landing_stats=1'`
Expected: JSON `ok:true` con `price` numérico

- [ ] **Step 5: E2E — register form present**

Run: `curl -s http://192.168.100.170:8080/src/php/register.php | grep -c "name=\"csrf\"\|name=\"username\"\|name=\"email\""`
Expected: `3`

- [ ] **Step 6: E2E — demo button**

Run: `curl -s http://192.168.100.170:8080/ | grep -c 'target="_blank" rel="noopener"'`
Expected: `1`

- [ ] **Step 7: E2E — control protected**

Run: `curl -s -X POST -d '_control=1&action=stop' http://192.168.100.170:8080/src/php/grid_ajax.php`
Expected: `{"ok":false,"msg":"No autorizado"}`

- [ ] **Step 8: Update `.superpowers/sdd/progress.md` ledger**

Append a section recording this plan's completion (tasks 1-6, commit SHAs).

- [ ] **Step 9: Commit ledger update**

```bash
git add .superpowers/sdd/progress.md
git commit -m "docs: record landing/register/demo plan completion"
```
