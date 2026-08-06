<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use BinanceBot\Core\AdminHttp;
use BinanceBot\Core\Csrf;
use BinanceBot\Core\Database;
use BinanceBot\Core\Schema;

session_set_cookie_params([
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
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

if (($_GET['action'] ?? '') === 'estimate_gas') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $secret = getenv('PLATFORM_SECRET') ?: '';
    try {
        $result = AdminHttp::estimateGas(
            $pdo,
            (string)($_GET['network'] ?? ''),
            (string)($_GET['token'] ?? ''),
            (string)($_GET['destination'] ?? ''),
            (string)($_GET['amount'] ?? ''),
            $secret
        );
    } catch (\Throwable $e) {
        error_log('[admin.php] estimate_gas: ' . $e->getMessage());
        $result = ['ok' => false, 'error' => 'Error estimando gas'];
    }
    echo json_encode($result);
    exit;
}

$result = AdminHttp::handle($pdo, $_SESSION, $_POST);
if ($result['view'] !== 'overview') {
    http_response_code(403);
    exit('Acceso denegado');
}
$d = $result['data'];
$csrf = Csrf::token($_SESSION);
?>
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
        <span class="nav-chip"><span class="chip-label">Usuario</span><span class="chip-val"><?= htmlspecialchars($_SESSION['username'] ?? '') ?></span></span>
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
                        <?php $whBadge = ($w['status'] ?? '') === 'sent' ? 'badge-green' : (($w['status'] ?? '') === 'rejected' ? 'badge-red' : 'badge-accent'); ?>
                        <?php $whLabel = ($w['status'] ?? '') === 'sent' ? 'Enviado' : (($w['status'] ?? '') === 'rejected' ? 'Rechazado' : (($w['status'] ?? '') === 'approved' ? 'Aprobado' : 'Pendiente')); ?>
                        <span class="badge <?= $whBadge ?>"><?= $whLabel ?></span>
                    </td>
                    <td class="num"><?= number_format((float)$w['amount'], 2) ?></td>
                    <td class="hide-mobile" style="font-family: var(--font-mono); word-break: break-all;"><?= htmlspecialchars($w['tx_hash'] ?: '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (empty($d['withdrawals'])): ?>
            <div class="empty-state">Sin registros.</div>
            <?php endif; ?>
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
                        <?php $adBadge = ($dep['status'] ?? '') === 'pending' ? 'badge-accent' : (($dep['status'] ?? '') === 'credited' ? 'badge-green' : 'badge-red'); ?>
                        <?php $adLabel = ($dep['status'] ?? '') === 'pending' ? 'Pendiente' : (($dep['status'] ?? '') === 'credited' ? 'Acreditado' : 'Fallido'); ?>
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
            <?php if (empty($d['deposits'])): ?>
            <div class="empty-state">Sin registros.</div>
            <?php endif; ?>
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
                        <?php $uBadge = ($u['status'] ?? '') === 'active' ? 'badge-green' : 'badge-red'; ?>
                        <?php $uLabel = ($u['status'] ?? '') === 'active' ? 'Activo' : 'Suspendido'; ?>
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
            <?php if (empty($d['users'])): ?>
            <div class="empty-state">Sin registros.</div>
            <?php endif; ?>
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
                    <span style="color: var(--text-muted); font-size: 0.8rem;">Confirmo que la dirección y monto son correctos</span>
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
            <?php if (empty($d['admin_sends'])): ?>
            <div class="empty-state">Sin registros.</div>
            <?php endif; ?>
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
    gasDiv.style.color = '';
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
</script>
</body>
</html>
