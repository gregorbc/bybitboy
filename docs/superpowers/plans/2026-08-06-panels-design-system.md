# Panels (Investor + Admin) on Design-System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Re-skin `src/php/panel.php` (investor) and `src/php/admin.php` (admin) with the existing design-system CSS so both panels look as professional as the `index.php` dashboard, without touching any PHP/business logic.

**Architecture:** Both files keep their PHP logic byte-identical (session guard, `InvestorHttp::handle`/`AdminHttp::handle`, `estimate_gas` JSON endpoint, CSRF, form names). Only the HTML markup inside the `<body>` and the `<head>` CSS links change, reusing classes from `design-system.css`, `layout.css`, `components.css`. Tab switching is done with a small vanilla JS toggle (`.panel-tab` / `.panel-content`). Each file is rewritten in its own task and verified by lint + the full phpunit suite (still green) + curl smoke tests + manual browser check.

**Tech Stack:** PHP 8.x (no framework), vanilla HTML/CSS/JS, existing design-system (`assets/css/design-system.css`, `layout.css`, `components.css`), PHPUnit 10.5.

## Global Constraints

- **Visual only.** No change to: handler calls, `action` names, `csrf` fields, form field `name` attributes, `id` attributes referenced by the admin JS (`network`, `token`, `destination`, `amount`, `confirm`, `sendBtn`, `gasEstimate`), the `estimate_gas` JSON endpoint, or redirect/403 behavior.
- CSS head block must be exactly (path relative to `src/php/`):
  ```html
  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/design-system.css">
  <link rel="stylesheet" href="assets/css/layout.css">
  <link rel="stylesheet" href="assets/css/components.css">
  ```
- `lang="es"` on `<html>`; Spanish copy; do NOT use emojis in the markup.
- Status badge mappings (values verified against Schema ENUMs):
  - Deposits: `pending`→badge-accent "Pendiente", `credited`→badge-green "Acreditado", `failed`→badge-red "Fallido".
  - Withdrawals: `pending`→badge-accent "Pendiente", `approved`→badge-accent "Aprobado", `sent`→badge-green "Enviado", `rejected`→badge-red "Rechazado".
  - Users: `active`→badge-green "Activo", `suspended`→badge-red "Suspendido".
  - Admin sends status: raw string in a badge-accent.
- All rows/values computed in the template from already-loaded arrays — no new PHP queries, no Core changes.
- Verify at the end of each task: `php -l` passes; full `vendor/bin/phpunit` suite stays green; the repo is on branch `master` and only the two target files (plus `docs/` plan file) are committed per task.

---

### Task 1: Investor panel (`src/php/panel.php`) on design-system

**Files:**
- Rewrite: `src/php/panel.php`

**Interfaces:**
- Consumes: `$d = InvestorHttp::handle(...)['data']` with keys `equity` (float), `units` (float), `nav` (float), `addresses` (map network→string|null), `withdrawals` (list: `id`, `user_id`, `network`, `token`, `amount`, `destination_address`, `status`, `tx_hash`, `error_message`, `created_at`), `movements` (list: `id`, `type` in `deposit|withdrawal|adjust`, `amount`, `units`, `nav`, `balance_after`, `created_at`), `deposits` (list: `id`, `network`, `token`, `amount`, `status`, `tx_hash`, `deployed`), `error`, `flash`, `networks` (list of `eth|bsc`). `$_SESSION['username']`, `$_SESSION['role']`. `$csrf`.
- Produces: A complete HTML document that renders the same data with `Resumen`, `Depósitos`, `Retiros`, `Movimientos` tabs. No other task consumes it.

- [ ] **Step 1: Read the current file and keep the PHP header**

The first 50 lines of `src/php/panel.php` (PHP preamble through the `$networks` array at line 46-49) stay unchanged. Confirm `php -l src/php/panel.php` passes BEFORE editing:

Run: `php -l src/php/panel.php`
Expected: `No syntax errors detected in src/php/panel.php`

- [ ] **Step 2: Write the new HTML document (replace everything from `<!DOCTYPE html>` at line 51 to EOF)**

Replace the entire HTML view with the following (PHP preamble lines 1-49 untouched). Note: deposit KPI `pendingCount` counts `$d['deposits']` rows with `status === 'pending'`; the admin link only renders when `$_SESSION['role'] === 'admin'`.

```html
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mi inversión · Grid Bot</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/design-system.css">
<link rel="stylesheet" href="assets/css/layout.css">
<link rel="stylesheet" href="assets/css/components.css">
</head>
<body>
<nav class="navbar">
    <span class="navbar-brand">Grid Bot</span>
    <div class="navbar-actions">
        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
        <a class="btn btn-primary navbar-action-btn" href="admin.php">Admin</a>
        <?php endif; ?>
        <a class="btn btn-danger navbar-action-btn" href="auth.php?action=logout">Salir</a>
    </div>
</nav>
<div class="app-container">
    <?php if (!empty($d['flash'])): ?>
    <div class="card" style="border-color: var(--accent); background: rgba(14,165,233,0.08); margin-top: var(--space-md);">
        <p style="margin:0; color: var(--accent); font-size: 0.85rem;"><?= htmlspecialchars($d['flash']) ?></p>
    </div>
    <?php endif; ?>
    <?php if (!empty($d['error'])): ?>
    <div class="card" style="border-color: var(--red); background: rgba(239,68,68,0.08); margin-top: var(--space-md);">
        <p style="margin:0; color: var(--red); font-size: 0.85rem;"><?= htmlspecialchars($d['error']) ?></p>
    </div>
    <?php endif; ?>

    <div class="kpi-row">
        <div class="card">
            <div class="kpi-card-value green"><?= number_format($d['equity'], 2) ?> USDT</div>
            <div class="kpi-card-label">Equidad</div>
        </div>
        <div class="card">
            <div class="kpi-card-value accent"><?= number_format($d['units'], 8) ?></div>
            <div class="kpi-card-label">Unidades</div>
        </div>
        <div class="card">
            <div class="kpi-card-value"><?= number_format($d['nav'], 6) ?></div>
            <div class="kpi-card-label">NAV</div>
        </div>
        <div class="card">
            <?php $pendingCount = 0; foreach ($d['deposits'] as $dep) { if (($dep['status'] ?? '') === 'pending') { $pendingCount++; } } ?>
            <div class="kpi-card-value"><?= $pendingCount ?> <span class="badge badge-accent">dep</span></div>
            <div class="kpi-card-label">Depósitos pendientes</div>
        </div>
    </div>

    <div class="panel-tabs">
        <div class="panel-tab active" data-tab="resumen">Resumen</div>
        <div class="panel-tab" data-tab="depositos">Depósitos</div>
        <div class="panel-tab" data-tab="retiros">Retiros</div>
        <div class="panel-tab" data-tab="movimientos">Movimientos</div>
    </div>

    <div id="tab-resumen" class="panel-content active">
        <div class="card">
            <div class="card-header"><span class="card-title">Direcciones de depósito (USDT / USDC)</span></div>
            <?php foreach ($d['networks'] as $network): ?>
                <p style="margin: 0 0 4px;"><strong><?= htmlspecialchars($networks[$network] ?? $network) ?></strong></p>
                <p style="margin: 0 0 12px; font-family: var(--font-mono); word-break: break-all; color: var(--text-secondary);"><?= htmlspecialchars($d['addresses'][$network] ?? 'no disponible') ?></p>
            <?php endforeach; ?>
            <p class="kpi-card-label">Envía USDT o USDC a tu dirección. Solo se acreditan depósitos confirmados.</p>
        </div>

        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Solicitar retiro</span></div>
            <form method="post">
                <input type="hidden" name="action" value="withdraw">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <div class="cfg-row">
                    <div class="cfg-field" style="flex:1;">
                        <label for="wNetwork">Red</label>
                        <select class="cfg-input" id="wNetwork" name="network"><?php foreach ($d['networks'] as $n): ?><option value="<?= $n ?>"><?= htmlspecialchars($networks[$n] ?? $n) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="cfg-field" style="flex:1;">
                        <label for="wToken">Token</label>
                        <select class="cfg-input" id="wToken" name="token"><option>USDT</option><option>USDC</option></select>
                    </div>
                </div>
                <div class="cfg-field" style="margin-top: var(--space-md);">
                    <label for="wAmount">Monto (USDT)</label>
                    <input class="cfg-input" id="wAmount" name="amount" type="number" step="0.01" min="0" required>
                </div>
                <div class="cfg-field" style="margin-top: var(--space-md);">
                    <label for="wDest">Dirección destino</label>
                    <input class="cfg-input" id="wDest" name="destination" placeholder="0x..." required>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top: var(--space-lg);">Solicitar retiro</button>
            </form>
        </div>
    </div>

    <div id="tab-depositos" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Depósitos</span></div>
            <table class="data-table">
                <tr><th>Estado</th><th>Red</th><th>Token</th><th>Monto</th><th class="hide-mobile">Tx</th></tr>
                <?php foreach ($d['deposits'] as $dep): ?>
                <tr>
                    <td>
                        <?php $depBadge = $dep['status'] === 'pending' ? 'badge-accent' : ($dep['status'] === 'credited' ? 'badge-green' : 'badge-red'); ?>
                        <?php $depLabel = $dep['status'] === 'pending' ? 'Pendiente' : ($dep['status'] === 'credited' ? 'Acreditado' : 'Fallido'); ?>
                        <span class="badge <?= $depBadge ?>"><?= $depLabel ?></span>
                    </td>
                    <td><?= htmlspecialchars($dep['network']) ?></td>
                    <td><?= htmlspecialchars($dep['token']) ?></td>
                    <td class="num"><?= number_format((float)$dep['amount'], 2) ?></td>
                    <td class="hide-mobile" style="font-family: var(--font-mono); word-break: break-all;"><?= htmlspecialchars($dep['tx_hash'] ?: '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div id="tab-retiros" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Mis retiros</span></div>
            <table class="data-table">
                <tr><th>Estado</th><th>Red</th><th>Monto</th><th class="hide-mobile">Tx</th></tr>
                <?php foreach ($d['withdrawals'] as $w): ?>
                <tr>
                    <td>
                        <?php $wBadge = $w['status'] === 'sent' ? 'badge-green' : ($w['status'] === 'rejected' ? 'badge-red' : 'badge-accent'); ?>
                        <?php $wLabel = $w['status'] === 'sent' ? 'Enviado' : ($w['status'] === 'rejected' ? 'Rechazado' : ($w['status'] === 'approved' ? 'Aprobado' : 'Pendiente')); ?>
                        <span class="badge <?= $wBadge ?>"><?= $wLabel ?></span>
                    </td>
                    <td><?= htmlspecialchars($w['network']) ?></td>
                    <td class="num"><?= number_format((float)$w['amount'], 2) ?></td>
                    <td class="hide-mobile" style="font-family: var(--font-mono); word-break: break-all;"><?= htmlspecialchars($w['tx_hash'] ?: '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div id="tab-movimientos" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Movimientos</span></div>
            <table class="data-table">
                <tr><th>Fecha</th><th>Tipo</th><th>Monto</th><th class="hide-mobile">Unidades</th><th class="hide-mobile">NAV</th><th class="hide-mobile">Saldo posterior</th></tr>
                <?php foreach ($d['movements'] as $m): ?>
                <tr>
                    <td style="font-family: var(--font-mono); white-space: nowrap;"><?= htmlspecialchars($m['created_at']) ?></td>
                    <td>
                        <?php $mBadge = $m['type'] === 'deposit' ? 'badge-green' : ($m['type'] === 'withdrawal' ? 'badge-red' : 'badge-accent'); ?>
                        <?php $mLabel = $m['type'] === 'deposit' ? 'Depósito' : ($m['type'] === 'withdrawal' ? 'Retiro' : 'Ajuste'); ?>
                        <span class="badge <?= $mBadge ?>"><?= $mLabel ?></span>
                    </td>
                    <td class="num"><?= number_format((float)$m['amount'], 8) ?></td>
                    <td class="hide-mobile num"><?= number_format((float)$m['units'], 8) ?></td>
                    <td class="hide-mobile num"><?= number_format((float)$m['nav'], 6) ?></td>
                    <td class="hide-mobile num"><?= number_format((float)$m['balance_after'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (empty($d['movements'])): ?>
            <div class="empty-state">Sin movimientos todavía.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
document.querySelectorAll('.panel-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
        document.querySelectorAll('.panel-tab').forEach(function (t) { t.classList.remove('active'); });
        document.querySelectorAll('.panel-content').forEach(function (p) { p.classList.remove('active'); });
        tab.classList.add('active');
        document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
    });
});
</script>
</body>
</html>
```

- [ ] **Step 3: Lint the result**

Run: `php -l src/php/panel.php`
Expected: `No syntax errors detected in src/php/panel.php`

- [ ] **Step 4: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: `OK (N tests, M assertions)` — the same 224 tests / 908 assertions as before this change (the only test referencing `panel.php` is `AuthHttpTest::testHandleLoginRedirectsToPanel`, which asserts the redirect string, not HTML).

- [ ] **Step 5: Smoke-test the unauthenticated redirect**

Run:
```bash
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' 'https://binance.gregorbritez.cat/src/php/panel.php'
```
Expected: `302` and a redirect to `auth.php` (same behavior as before).

- [ ] **Step 6: Commit**

```bash
git add src/php/panel.php
git commit -m "feat(panel): investor panel on design-system with tabs"
```

---

### Task 2: Admin panel (`src/php/admin.php`) on design-system

**Files:**
- Rewrite: `src/php/admin.php`

**Interfaces:**
- Consumes: `$d = AdminHttp::handle(...)['data']` with keys `users` (list: `id`, `username`, `email`, `role`, `status`), `pending_withdrawals` (list: `id`, `username`, `network`, `token`, `amount`, `destination_address`), `withdrawals` (list: `id`, `username`, `status`, `amount`, `tx_hash`), `deposits` (list: `id`, `username`, `status`, `network`, `token`, `amount`, `deployed` (0/1), `tx_hash`), `admin_sends` (list: `id`, `network`, `token`, `amount`, `destination_address`, `status`, `tx_hash`, `error_message`), `nav` (float), `total_units` (float), `wallet_held` (float), `error`. `$csrf`. The JS reads element ids `network`, `token`, `destination`, `amount`, `confirm`, `sendBtn`, `gasEstimate`.
- Produces: A complete HTML document with `Resumen`, `Retiros`, `Depósitos`, `Usuarios`, `Envíos` tabs, the unchanged `estimate_gas` JSON endpoint (PHP lines 32-51) and the unchanged send-direct JS (kept verbatim, ids re-anchored). No other task consumes it.

- [ ] **Step 1: Read the current file and keep the PHP header**

The first 59 lines of `src/php/admin.php` (PHP preamble, `estimate_gas` JSON block, `AdminHttp::handle`, 403 gate, `$csrf`) stay unchanged. Confirm `php -l src/php/admin.php` passes BEFORE editing:

Run: `php -l src/php/admin.php`
Expected: `No syntax errors detected in src/php/admin.php`

- [ ] **Step 2: Write the new HTML document (replace everything from `<!DOCTYPE html>` at line 61 to EOF)**

Replace the entire HTML view with the following (PHP preamble lines 1-59 untouched). KPIs: NAV (`$d['nav']`), Unidades totales (`$d['total_units']`), Retiros pendientes (`count($d['pending_withdrawals'])` with red badge), Usuarios activos (count of `$d['users']` with `status === 'active'`). Keep every form `action`, `name`, and `id` identical to the current file. Keep the whole `<script>` block byte-identical.

```html
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin · Grid Bot</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/design-system.css">
<link rel="stylesheet" href="assets/css/layout.css">
<link rel="stylesheet" href="assets/css/components.css">
</head>
<body>
<nav class="navbar">
    <span class="navbar-brand">Grid Bot · Admin</span>
    <div class="navbar-actions">
        <a class="btn btn-primary navbar-action-btn" href="panel.php">Mi panel</a>
        <a class="btn btn-danger navbar-action-btn" href="auth.php?action=logout">Salir</a>
    </div>
</nav>
<div class="app-container">
    <?php if (!empty($d['error'])): ?>
    <div class="card" style="border-color: var(--red); background: rgba(239,68,68,0.08); margin-top: var(--space-md);">
        <p style="margin:0; color: var(--red); font-size: 0.85rem;"><?= htmlspecialchars($d['error']) ?></p>
    </div>
    <?php endif; ?>

    <?php $activeUsers = 0; foreach ($d['users'] as $u) { if (($u['status'] ?? '') === 'active') { $activeUsers++; } } ?>
    <div class="kpi-row">
        <div class="card">
            <div class="kpi-card-value green"><?= number_format($d['nav'], 6) ?></div>
            <div class="kpi-card-label">NAV</div>
        </div>
        <div class="card">
            <div class="kpi-card-value accent"><?= number_format($d['total_units'], 2) ?></div>
            <div class="kpi-card-label">Unidades totales</div>
        </div>
        <div class="card">
            <div class="kpi-card-value red"><?= count($d['pending_withdrawals']) ?> <span class="badge badge-red">pend</span></div>
            <div class="kpi-card-label">Retiros pendientes</div>
        </div>
        <div class="card">
            <div class="kpi-card-value"><?= $activeUsers ?></div>
            <div class="kpi-card-label">Usuarios activos</div>
        </div>
    </div>

    <div class="panel-tabs">
        <div class="panel-tab active" data-tab="resumen">Resumen</div>
        <div class="panel-tab" data-tab="retiros">Retiros</div>
        <div class="panel-tab" data-tab="depositos">Depósitos</div>
        <div class="panel-tab" data-tab="usuarios">Usuarios</div>
        <div class="panel-tab" data-tab="envios">Envíos</div>
    </div>

    <div id="tab-resumen" class="panel-content active">
        <div class="card">
            <div class="card-header"><span class="card-title">Estado del fondo</span></div>
            <p style="margin:0;">NAV: <strong style="color: var(--green);"><?= number_format($d['nav'], 6) ?></strong></p>
            <p style="margin:0;">Unidades totales: <strong><?= number_format($d['total_units'], 2) ?></strong></p>
            <p style="margin:0;">En wallet (sin desplegar): <strong><?= number_format($d['wallet_held'], 2) ?> USDT</strong></p>
        </div>
    </div>

    <div id="tab-retiros" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Retiros pendientes</span></div>
            <table class="data-table">
                <tr><th>Usuario</th><th>Red</th><th>Token</th><th>Monto</th><th class="hide-mobile">Destino</th><th>Acciones</th></tr>
                <?php foreach ($d['pending_withdrawals'] as $w): ?>
                <tr>
                    <td><?= htmlspecialchars($w['username']) ?></td>
                    <td><?= htmlspecialchars($w['network']) ?></td>
                    <td><?= htmlspecialchars($w['token']) ?></td>
                    <td class="num"><?= number_format((float)$w['amount'], 2) ?></td>
                    <td class="hide-mobile" style="font-family: var(--font-mono); word-break: break-all;"><?= htmlspecialchars($w['destination_address']) ?></td>
                    <td>
                        <form method="post" style="display:inline"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="btn btn-primary" style="padding: 4px 10px; font-size: 0.75rem;">Aprobar</button></form>
                        <form method="post" style="display:inline"><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="btn btn-danger" style="padding: 4px 10px; font-size: 0.75rem;">Rechazar</button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (empty($d['pending_withdrawals'])): ?>
            <div class="empty-state">Sin retiros pendientes.</div>
            <?php endif; ?>
        </div>

        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Retiros (historial)</span></div>
            <table class="data-table">
                <tr><th>Usuario</th><th>Estado</th><th>Monto</th><th class="hide-mobile">Tx</th></tr>
                <?php foreach ($d['withdrawals'] as $w): ?>
                <tr>
                    <td><?= htmlspecialchars($w['username']) ?></td>
                    <td>
                        <?php $whBadge = $w['status'] === 'sent' ? 'badge-green' : ($w['status'] === 'rejected' ? 'badge-red' : 'badge-accent'); ?>
                        <?php $whLabel = $w['status'] === 'sent' ? 'Enviado' : ($w['status'] === 'rejected' ? 'Rechazado' : ($w['status'] === 'approved' ? 'Aprobado' : 'Pendiente')); ?>
                        <span class="badge <?= $whBadge ?>"><?= $whLabel ?></span>
                    </td>
                    <td class="num"><?= number_format((float)$w['amount'], 2) ?></td>
                    <td class="hide-mobile" style="font-family: var(--font-mono); word-break: break-all;"><?= htmlspecialchars($w['tx_hash'] ?: '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if ($d['withdrawals']): ?>
            <form method="post" style="margin-top: var(--space-lg);">
                <input type="hidden" name="action" value="sent">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <div class="cfg-field">
                    <label for="sentId">ID retiro aprobado</label>
                    <select class="cfg-input" id="sentId" name="id"><?php foreach ($d['withdrawals'] as $w): ?><?php if ($w['status'] === 'approved'): ?><option value="<?= (int)$w['id'] ?>">#<?= (int)$w['id'] ?> · <?= htmlspecialchars($w['username']) ?> · <?= number_format((float)$w['amount'], 2) ?></option><?php endif; ?><?php endforeach; ?></select>
                </div>
                <div class="cfg-field" style="margin-top: var(--space-md);">
                    <label for="sentTx">Tx hash del envío</label>
                    <input class="cfg-input" id="sentTx" name="tx_hash" placeholder="0x...">
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top: var(--space-lg);">Marcar enviado</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-depositos" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Depósitos</span></div>
            <table class="data-table">
                <tr><th>Usuario</th><th>Estado</th><th>Red</th><th>Token</th><th>Monto</th><th>Desplegado</th><th class="hide-mobile">Tx</th></tr>
                <?php foreach ($d['deposits'] as $dep): ?>
                <tr>
                    <td><?= htmlspecialchars($dep['username']) ?></td>
                    <td>
                        <?php $adBadge = $dep['status'] === 'pending' ? 'badge-accent' : ($dep['status'] === 'credited' ? 'badge-green' : 'badge-red'); ?>
                        <?php $adLabel = $dep['status'] === 'pending' ? 'Pendiente' : ($dep['status'] === 'credited' ? 'Acreditado' : 'Fallido'); ?>
                        <span class="badge <?= $adBadge ?>"><?= $adLabel ?></span>
                    </td>
                    <td><?= htmlspecialchars($dep['network']) ?></td>
                    <td><?= htmlspecialchars($dep['token']) ?></td>
                    <td class="num"><?= number_format((float)$dep['amount'], 2) ?></td>
                    <td>
                        <?php if ($dep['status'] === 'credited' && !$dep['deployed']): ?>
                            <form method="post" style="display:inline"><input type="hidden" name="action" value="deploy"><input type="hidden" name="id" value="<?= (int)$dep['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="btn btn-primary" style="padding: 4px 10px; font-size: 0.75rem;">Marcar desplegado</button></form>
                        <?php else: ?><?= (int)$dep['deployed'] ? 'Sí' : 'No' ?><?php endif; ?>
                    </td>
                    <td class="hide-mobile" style="font-family: var(--font-mono); word-break: break-all;"><?= htmlspecialchars($dep['tx_hash']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div id="tab-usuarios" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Usuarios</span></div>
            <table class="data-table">
                <tr><th>ID</th><th>Usuario</th><th class="hide-mobile">Email</th><th>Rol</th><th>Estado</th><th>Acciones</th></tr>
                <?php foreach ($d['users'] as $u): ?>
                <tr>
                    <td><?= (int)$u['id'] ?></td>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td class="hide-mobile"><?= htmlspecialchars($u['email']) ?></td>
                    <td><?= htmlspecialchars($u['role']) ?></td>
                    <td>
                        <?php $uBadge = $u['status'] === 'active' ? 'badge-green' : 'badge-red'; ?>
                        <?php $uLabel = $u['status'] === 'active' ? 'Activo' : 'Suspendido'; ?>
                        <span class="badge <?= $uBadge ?>"><?= $uLabel ?></span>
                    </td>
                    <td>
                        <?php if ($u['status'] === 'active'): ?>
                            <form method="post" style="display:inline"><input type="hidden" name="action" value="suspend"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="btn btn-danger" style="padding: 4px 10px; font-size: 0.75rem;">Suspender</button></form>
                        <?php else: ?>
                            <form method="post" style="display:inline"><input type="hidden" name="action" value="activate"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="btn btn-primary" style="padding: 4px 10px; font-size: 0.75rem;">Activar</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <div id="tab-envios" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Envío directo (USDT/USDC)</span></div>
            <form method="post" id="sendForm">
                <input type="hidden" name="action" value="send_direct">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">

                <div class="cfg-field">
                    <label for="network">Red</label>
                    <select class="cfg-input" name="network" id="network" required>
                        <option value="eth">Ethereum (ERC20)</option>
                        <option value="bsc" selected>BNB Smart Chain (BEP20)</option>
                    </select>
                </div>

                <div class="cfg-field" style="margin-top: var(--space-md);">
                    <label for="token">Token</label>
                    <select class="cfg-input" name="token" id="token" required>
                        <option value="USDT" selected>USDT</option>
                        <option value="USDC">USDC</option>
                    </select>
                </div>

                <div class="cfg-field" style="margin-top: var(--space-md);">
                    <label for="destination">Dirección destino</label>
                    <input class="cfg-input" name="destination" id="destination" placeholder="0x..." required pattern="^0x[0-9a-fA-F]{40}$">
                </div>

                <div class="cfg-field" style="margin-top: var(--space-md);">
                    <label for="amount">Monto</label>
                    <input class="cfg-input" name="amount" id="amount" type="number" step="0.00000001" min="0.00000001" placeholder="0.00" required>
                </div>

                <div id="gasEstimate" style="display:none; margin-top: var(--space-md); padding: var(--space-md); background: rgba(14,165,233,0.08); border:1px solid var(--accent); border-radius: var(--radius-md); font-family: var(--font-mono); font-size: 0.8rem;"></div>

                <label style="display:flex;align-items:center;gap:8px;margin-top: var(--space-md);">
                    <input type="checkbox" name="confirm" id="confirm" required>
                    <span class="kpi-card-label">Confirmo que la dirección y monto son correctos</span>
                </label>

                <button type="submit" class="btn btn-primary" id="sendBtn" disabled style="margin-top: var(--space-md);">Enviar</button>
            </form>
        </div>

        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Envíos directos (historial)</span></div>
            <table class="data-table">
                <tr><th>ID</th><th>Red</th><th>Token</th><th>Monto</th><th class="hide-mobile">Destino</th><th>Estado</th><th class="hide-mobile">Tx</th></tr>
                <?php foreach ($d['admin_sends'] as $s): ?>
                <tr>
                    <td><?= (int)$s['id'] ?></td>
                    <td><?= htmlspecialchars($s['network']) ?></td>
                    <td><?= htmlspecialchars($s['token']) ?></td>
                    <td class="num"><?= number_format((float)$s['amount'], 8) ?></td>
                    <td class="hide-mobile" style="font-family: var(--font-mono); word-break: break-all;"><?= htmlspecialchars($s['destination_address']) ?></td>
                    <td><span class="badge badge-accent"><?= htmlspecialchars($s['status']) ?></span></td>
                    <td class="hide-mobile" style="font-family: var(--font-mono); word-break: break-all;"><?= htmlspecialchars($s['tx_hash'] ?: $s['error_message'] ?: '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>
<script>
const networkSel = document.getElementById('network');
const tokenSel = document.getElementById('token');
const destInput = document.getElementById('destination');
const amountInput = document.getElementById('amount');
const confirmChk = document.getElementById('confirm');
const sendBtn = document.getElementById('sendBtn');
const gasDiv = document.getElementById('gasEstimate');

function validateForm() {
    const network = networkSel.value;
    const token = tokenSel.value;
    const dest = destInput.value.trim();
    const amount = parseFloat(amountInput.value);
    const destValid = /^0x[0-9a-fA-F]{40}$/.test(dest);
    const amountValid = !isNaN(amount) && amount > 0;
    const allValid = network && token && destValid && amountValid && confirmChk.checked;
    sendBtn.disabled = !allValid;
    return {network, token, dest, amount, destValid, amountValid};
}

async function estimateGas() {
    const {network, token, dest, amount, destValid, amountValid} = validateForm();
    if (!destValid || !amountValid) {
        gasDiv.style.display = 'none';
        return;
    }
    gasDiv.style.display = 'block';
    gasDiv.textContent = 'Estimando gas...';
    try {
        const url = 'admin.php?action=estimate_gas&network=' + encodeURIComponent(network) + '&token=' + encodeURIComponent(token) + '&destination=' + encodeURIComponent(dest) + '&amount=' + encodeURIComponent(amountInput.value);
        const resp = await fetch(url, {credentials: 'same-origin'});
        const data = await resp.json();
        if (data.ok) {
            const native = network === 'eth' ? 'ETH' : 'BNB';
            gasDiv.textContent = 'Gas estimado: ' + Number(data.gas_limit).toLocaleString() + ' · Gas price: ' + (data.gas_price / 1e9).toFixed(2) + ' Gwei · Costo estimado: ' + data.estimated_cost_native + ' ' + native;
        } else {
            gasDiv.textContent = (data.error || 'Error');
            gasDiv.style.color = '#f85149';
        }
    } catch (e) {
        gasDiv.textContent = 'Error: ' + (e.message || 'no disponible');
        gasDiv.style.color = '#f85149';
    }
}

['network','token','destination','amount'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', function () {
        validateForm();
        clearTimeout(window.gasTimer);
        window.gasTimer = setTimeout(estimateGas, 800);
    });
});
confirmChk.addEventListener('change', validateForm);
validateForm();

document.querySelectorAll('.panel-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
        document.querySelectorAll('.panel-tab').forEach(function (t) { t.classList.remove('active'); });
        document.querySelectorAll('.panel-content').forEach(function (p) { p.classList.remove('active'); });
        tab.classList.add('active');
        document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
    });
});
</script>
</body>
</html>
```

- [ ] **Step 3: Lint the result**

Run: `php -l src/php/admin.php`
Expected: `No syntax errors detected in src/php/admin.php`

- [ ] **Step 4: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: `OK (N tests, M assertions)` — the same 224 tests / 908 assertions as before this change (`AdminHttpTest` covers `AdminHttp::handle`, not the template).

- [ ] **Step 5: Smoke-test endpoints**

Run:
```bash
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' 'https://binance.gregorbritez.cat/src/php/admin.php'
curl -s -o /dev/null -w '%{http_code}\n' 'https://binance.gregorbritez.cat/src/php/admin.php?action=estimate_gas&network=bsc&token=USDT&destination=0x0000000000000000000000000000000000000000&amount=1'
```
Expected: both lines `302` → redirect to `auth.php` — identical to pre-change behavior (`estimate_gas` sits behind the admin session guard, so an unauthenticated request redirects; the 200-JSON path requires an authenticated admin session, not covered by this curl check).

- [ ] **Step 6: Manual browser E2E (both panels)**

Log in as admin and as an investor in a browser and confirm: navbar renders with Salir (+ Mi panel for admin); the 4 KPI cards show; each tab switches content without a page reload; the withdrawal form submits (investor) and admin approve/reject/deploy/suspend buttons still work; in the Envíos tab the send button stays disabled until a valid `0x...` address, amount, and confirmation checkbox are set, and the gas estimate text appears after ~800ms of typing.

- [ ] **Step 7: Commit**

```bash
git add src/php/admin.php
git commit -m "feat(panel): admin panel on design-system with tabs"
```

---

## Final Verification (after both tasks)

- [ ] Run: `vendor/bin/phpunit` — full suite green (224 tests / 908 assertions).
- [ ] Run: `php -l src/php/panel.php && php -l src/php/admin.php` — no syntax errors.
- [ ] Confirm `git status` shows no unintended files staged (the working tree contains many pre-existing modified/untracked files — `docs/`, `ml_weights_v2.json`, `grid_bot.pid`, `.claude/settings.json`, `.phpunit.result.cache`, `src/php/index.php` hide-mobile tweaks, `vendor/`, `dist/`, `tetris/` — leave them untouched; only stage the two target files plus this plan doc).
- [ ] Cross-check against the spec `docs/superpowers/specs/2026-08-06-panels-design-system-design.md`: all 4 sections implemented (architecture, investor panel, admin panel, errors/flash/badges/testing); `estimate_gas` JSON block and admin JS ids preserved; no Core/handler changes.
