<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use BinanceBot\Core\Config;
use BinanceBot\Core\Csrf;
use BinanceBot\Core\Database;
use BinanceBot\Core\InvestorHttp;
use BinanceBot\Core\Schema;

session_set_cookie_params([
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: auth.php');
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPdo();
if (!$pdo) {
    http_response_code(500);
    exit('Base de datos no disponible');
}
Schema::createTables($pdo);

$secret = getenv('PLATFORM_SECRET') ?: '';
if ($secret === '') {
    http_response_code(500);
    exit('PLATFORM_SECRET no configurado');
}

$result = InvestorHttp::handle($pdo, $_SESSION, $_GET, $_POST, $secret);
if ($result['view'] === 'login') {
    header('Location: auth.php');
    exit;
}
$d = $result['data'];
$csrf = Csrf::token($_SESSION);
$networks = [
    'eth' => 'Ethereum (ERC20)',
    'bsc' => 'BNB Smart Chain (BEP20)',
];
?>
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
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
    .row-hidden { display: none; }
</style>
</head>
<body>
<nav class="navbar">
    <span class="navbar-brand">Grid Bot</span>
    <div class="navbar-actions">
        <span class="nav-chip"><span class="chip-label">Usuario</span><span class="chip-val"><?= htmlspecialchars($_SESSION['username'] ?? '') ?></span></span>
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
            <div class="kpi-card-value <?= ($d['growth_pct'] ?? 0) >= 0 ? 'green' : 'red' ?>"><?= ($d['growth_pct'] ?? 0) >= 0 ? '+' : '' ?><?= number_format($d['growth_pct'] ?? 0, 2) ?>%</div>
            <div class="kpi-card-label">Crecimiento</div>
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
        <div class="panel-tab" data-tab="crecimiento">Crecimiento</div>
        <div class="panel-tab" data-tab="perfil">Perfil</div>
    </div>

    <div id="tab-resumen" class="panel-content active">
        <div class="card">
            <div class="card-header"><span class="card-title">Direcciones de depósito (USDT / USDC)</span></div>
            <?php foreach ($d['networks'] as $network): ?>
                <p style="margin: 0 0 4px;"><strong><?= htmlspecialchars($networks[$network] ?? $network) ?></strong></p>
                <p style="margin: 0 0 12px; font-family: var(--font-mono); word-break: break-all; color: var(--text-secondary);"><?= htmlspecialchars($d['addresses'][$network] ?? 'no disponible') ?></p>
            <?php endforeach; ?>
            <p style="margin:0; color: var(--text-muted); font-size: 0.85rem;">Envía USDT o USDC a tu dirección. Solo se acreditan depósitos confirmados.</p>
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
            <table class="data-table" id="depTb">
                <tr><th>Estado</th><th>Red</th><th>Token</th><th>Monto</th><th class="hide-mobile">Tx</th></tr>
                <?php foreach ($d['deposits'] as $dep): ?>
                <tr>
                    <td>
                        <?php $depBadge = ($dep['status'] ?? '') === 'pending' ? 'badge-accent' : (($dep['status'] ?? '') === 'credited' ? 'badge-green' : 'badge-red'); ?>
                        <?php $depLabel = ($dep['status'] ?? '') === 'pending' ? 'Pendiente' : (($dep['status'] ?? '') === 'credited' ? 'Acreditado' : 'Fallido'); ?>
                        <span class="badge <?= $depBadge ?>"><?= $depLabel ?></span>
                    </td>
                    <td><?= htmlspecialchars($dep['network']) ?></td>
                    <td><?= htmlspecialchars($dep['token']) ?></td>
                    <td class="num"><?= number_format((float)$dep['amount'], 2) ?></td>
                    <td class="hide-mobile" style="font-family: var(--font-mono); word-break: break-all;"><?= htmlspecialchars($dep['tx_hash'] ?: '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (empty($d['deposits'])): ?>
            <div class="empty-state">Sin registros.</div>
            <?php endif; ?>
            <div class="empty-state" id="depMoreBtn" style="cursor:pointer; margin-top: var(--space-md);">▼ Ver más depósitos</div>
        </div>
    </div>

    <div id="tab-retiros" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Mis retiros</span></div>
            <table class="data-table" id="wdTb">
                <tr><th>Estado</th><th>Red</th><th>Monto</th><th class="hide-mobile">Tx</th></tr>
                <?php foreach ($d['withdrawals'] as $w): ?>
                <tr>
                    <td>
                        <?php $wBadge = ($w['status'] ?? '') === 'sent' ? 'badge-green' : (($w['status'] ?? '') === 'rejected' ? 'badge-red' : 'badge-accent'); ?>
                        <?php $wLabel = ($w['status'] ?? '') === 'sent' ? 'Enviado' : (($w['status'] ?? '') === 'rejected' ? 'Rechazado' : (($w['status'] ?? '') === 'approved' ? 'Aprobado' : 'Pendiente')); ?>
                        <span class="badge <?= $wBadge ?>"><?= $wLabel ?></span>
                    </td>
                    <td><?= htmlspecialchars($w['network']) ?></td>
                    <td class="num"><?= number_format((float)$w['amount'], 2) ?></td>
                    <td class="hide-mobile" style="font-family: var(--font-mono); word-break: break-all;"><?= htmlspecialchars($w['tx_hash'] ?: '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (empty($d['withdrawals'])): ?>
            <div class="empty-state">Sin registros.</div>
            <?php endif; ?>
            <div class="empty-state" id="wdMoreBtn" style="cursor:pointer; margin-top: var(--space-md);">▼ Ver más retiros</div>
        </div>
    </div>

    <div id="tab-movimientos" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Movimientos</span></div>
            <table class="data-table" id="movTb">
                <tr><th>Fecha</th><th>Tipo</th><th>Monto</th><th class="hide-mobile">Unidades</th><th class="hide-mobile">NAV</th><th class="hide-mobile">Saldo posterior</th></tr>
                <?php foreach ($d['movements'] as $m): ?>
                <tr>
                    <td style="font-family: var(--font-mono); white-space: nowrap;"><?= htmlspecialchars($m['created_at']) ?></td>
                    <td>
                        <?php $mBadge = ($m['type'] ?? '') === 'deposit' ? 'badge-green' : (($m['type'] ?? '') === 'withdrawal' ? 'badge-red' : 'badge-accent'); ?>
                        <?php $mLabel = ($m['type'] ?? '') === 'deposit' ? 'Depósito' : (($m['type'] ?? '') === 'withdrawal' ? 'Retiro' : 'Ajuste'); ?>
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
            <div class="empty-state" id="movMoreBtn" style="cursor:pointer; margin-top: var(--space-md);">▼ Ver más movimientos</div>
        </div>
    </div>

    <div id="tab-crecimiento" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Crecimiento de tu inversión</span></div>
            <div style="height: 260px;"><canvas id="growthChart"></canvas></div>
        </div>
    </div>

    <div id="tab-perfil" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Datos de perfil</span></div>
            <form method="post">
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <div class="cfg-field">
                    <label for="pfEmail">Email</label>
                    <input class="cfg-input" id="pfEmail" name="email" type="email" value="<?= htmlspecialchars($d['email'] ?? '') ?>" required>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top: var(--space-lg);">Guardar perfil</button>
            </form>
        </div>
        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Cambiar contraseña</span></div>
            <form method="post">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <div class="cfg-field">
                    <label for="pwCurrent">Contraseña actual</label>
                    <input class="cfg-input" id="pwCurrent" name="current_password" type="password" required>
                </div>
                <div class="cfg-field" style="margin-top: var(--space-md);">
                    <label for="pwNew">Nueva contraseña (mín. 8)</label>
                    <input class="cfg-input" id="pwNew" name="new_password" type="password" minlength="8" required>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top: var(--space-lg);">Cambiar contraseña</button>
            </form>
        </div>
        <?php if (empty($d['2fa_enabled'])): ?>
        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Autenticación de dos factores</span></div>
            <p>Escanea el código QR con Google Authenticator (o añade el secreto manualmente) y verifica un código para activarlo.</p>
            <?php if (isset($d['two_factor'])): ?>
                <img src="<?= htmlspecialchars($d['two_factor']['qr']) ?>" width="220" height="220" alt="QR 2FA" style="display:block;margin:var(--space-md) 0;">
                <p><code><?= htmlspecialchars($d['two_factor']['secret']) ?></code></p>
                <form method="post" style="margin-top: var(--space-md);">
                    <input type="hidden" name="action" value="confirm_2fa">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <div class="cfg-field">
                        <label for="pf2faCode">Código de 6 dígitos</label>
                        <input class="cfg-input" id="pf2faCode" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-top: var(--space-md);">Activar</button>
                </form>
            <?php else: ?>
                <form method="post" style="margin-top: var(--space-md);">
                    <input type="hidden" name="action" value="enable_2fa">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <button type="submit" class="btn btn-primary">Activar 2FA</button>
                </form>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Autenticación de dos factores</span></div>
            <p>Activa. Para desactivarla verifica un código.</p>
            <form method="post" style="margin-top: var(--space-md);">
                <input type="hidden" name="action" value="disable_2fa">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <div class="cfg-field">
                    <label for="pf2faCodeDisable">Código de 6 dígitos</label>
                    <input class="cfg-input" id="pf2faCodeDisable" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top: var(--space-md);">Desactivar</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
<script>
function activatePanelTab(tab) {
    document.querySelectorAll('.panel-tab').forEach(function (t) { t.classList.remove('active'); });
    document.querySelectorAll('.panel-content').forEach(function (p) { p.classList.remove('active'); });
    tab.classList.add('active');
    document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
    history.replaceState(null, '', '#' + tab.dataset.tab);
}
document.querySelectorAll('.panel-tab').forEach(function (tab) {
    tab.addEventListener('click', function () { activatePanelTab(tab); });
});
var savedTab = location.hash.replace('#', '');
if (savedTab) {
    var savedEl = document.querySelector('.panel-tab[data-tab="' + savedTab + '"]');
    if (savedEl) activatePanelTab(savedEl);
}

const EQUITY_DATA = <?= json_encode($d['equity_history']) ?>;

function renderGrowthChart() {
    if (typeof Chart === 'undefined' || !document.getElementById('growthChart')) return;
    new Chart(document.getElementById('growthChart'), {
        type: 'line',
        data: {
            labels: EQUITY_DATA.map(r => (r.created_at || '').slice(0, 10)),
            datasets: [{ label: 'Equidad', data: EQUITY_DATA.map(r => Number(r.balance_after)), borderColor: '#22c55e', backgroundColor: 'rgba(34,197,94,.12)', fill: true, tension: .2, pointRadius: 0 }]
        },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { x: { grid: { color: '#1e3a5f' } }, y: { grid: { color: '#1e3a5f' } } } }
    });
}

function setupPagination(tableId, btnId, perPage) {
    const tb = document.getElementById(tableId);
    const btn = document.getElementById(btnId);
    if (!tb) return;
    const rows = Array.prototype.slice.call(tb.rows);
    if (rows.length <= perPage) { if (btn) btn.style.display = 'none'; return; }
    rows.forEach(function (r, i) { if (i >= perPage) r.classList.add('row-hidden'); });
    if (btn) btn.addEventListener('click', function () {
        rows.forEach(function (r) { r.classList.remove('row-hidden'); });
        btn.style.display = 'none';
    });
}

renderGrowthChart();
setupPagination('movTb', 'movMoreBtn', 20);
setupPagination('depTb', 'depMoreBtn', 20);
setupPagination('wdTb', 'wdMoreBtn', 20);
</script>
</body>
</html>
