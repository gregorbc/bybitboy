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
$CTRL_TOKEN = getenv('SECURITY_TOKEN') ?: '';
$navHistory = array_reverse($d['nav_history']);
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
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
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
    <?php if (!empty($d['flash'])): ?>
    <div class="card" style="border-color: var(--green); background: rgba(34,197,94,0.08); margin-top: var(--space-md);">
        <p style="margin:0; color: var(--green); font-size: 0.85rem;"><?= htmlspecialchars($d['flash']) ?></p>
    </div>
    <?php endif; ?>

    <?php $activeUsers = 0; foreach ($d['users'] as $u) { if (($u['status'] ?? '') === 'active') { $activeUsers++; } } ?>
    <div class="kpi-row">
        <div class="card"><div class="kpi-card-value green"><?= number_format($d['nav'], 6) ?></div><div class="kpi-card-label">NAV</div></div>
        <div class="card"><div class="kpi-card-value accent"><?= number_format($d['total_units'], 2) ?></div><div class="kpi-card-label">Unidades totales</div></div>
        <div class="card"><div class="kpi-card-value red"><?= count($d['pending_withdrawals']) ?></div><div class="kpi-card-label">Retiros pendientes</div></div>
        <div class="card"><div class="kpi-card-value"><?= $activeUsers ?></div><div class="kpi-card-label">Usuarios activos</div></div>
        <div class="card"><div class="kpi-card-value" id="kpiPrice">-</div><div class="kpi-card-label">ETHUSDT</div></div>
        <div class="card"><div class="kpi-card-value" id="kpiRunning">-</div><div class="kpi-card-label">Bot</div></div>
        <div class="card"><div class="kpi-card-value" id="kpiPnlToday">-</div><div class="kpi-card-label">PnL hoy</div></div>
        <div class="card"><div class="kpi-card-value" id="kpiWinRate">-</div><div class="kpi-card-label">Win rate</div></div>
    </div>

    <div class="panel-tabs">
        <div class="panel-tab active" data-tab="resumen">Resumen</div>
        <div class="panel-tab" data-tab="bot">Bot</div>
        <div class="panel-tab" data-tab="ordenes">Órdenes + PnL</div>
        <div class="panel-tab" data-tab="fondo">Fondo</div>
        <div class="panel-tab" data-tab="usuarios">Usuarios</div>
        <div class="panel-tab" data-tab="auditoria">Auditoría</div>
        <div class="panel-tab" data-tab="alertas">Alertas</div>
        <div class="panel-tab" data-tab="reconciliacion">Reconciliación</div>
        <div class="panel-tab" data-tab="modelos">Modelos ML</div>
        <div class="panel-tab" data-tab="logs-ia">Logs IA</div>
        <div class="panel-tab" data-tab="logs-acceso">Logs acceso</div>
        <div class="panel-tab" data-tab="ajustes">Ajustes</div>
    </div>

    <div id="tab-resumen" class="panel-content active">
        <div class="card">
            <div class="card-header"><span class="card-title">Estado del fondo</span></div>
            <p style="margin:0;">NAV: <strong style="color: var(--green);"><?= number_format($d['nav'], 6) ?></strong></p>
            <p style="margin:0;">Unidades totales: <strong><?= number_format($d['total_units'], 2) ?></strong></p>
            <p style="margin:0;">En wallet (sin desplegar): <strong><?= number_format($d['wallet_held'], 2) ?> USDT</strong></p>
        </div>
        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Estado del bot</span></div>
            <div class="kpi-row">
                <div class="card"><div class="kpi-card-value" id="rRunning">-</div><div class="kpi-card-label">Estado</div></div>
                <div class="card"><div class="kpi-card-value" id="rMode">-</div><div class="kpi-card-label">Modo</div></div>
                <div class="card"><div class="kpi-card-value" id="rDirection">-</div><div class="kpi-card-label">Dirección</div></div>
                <div class="card"><div class="kpi-card-value" id="rConfidence">-</div><div class="kpi-card-label">Confianza</div></div>
                <div class="card"><div class="kpi-card-value" id="rUptime">-</div><div class="kpi-card-label">Uptime</div></div>
                <div class="card"><div class="kpi-card-value" id="rBalance">-</div><div class="kpi-card-label">Balance Bybit</div></div>
            </div>
            <div style="margin-top: var(--space-md);">
                <a class="btn btn-primary" href="#bot">Ir al monitor del bot</a>
            </div>
        </div>
    </div>

    <div id="tab-bot" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Monitor en vivo <span id="botTs" style="font-family:var(--font-mono);font-size:.75rem;color:var(--text-muted)"></span></span></div>
            <div class="kpi-row">
                <div class="card"><div class="kpi-card-value"><span class="badge" id="botRunning">-</span></div><div class="kpi-card-label">Estado</div></div>
                <div class="card"><div class="kpi-card-value" id="botMode">-</div><div class="kpi-card-label">Modo</div></div>
                <div class="card"><div class="kpi-card-value" id="botPrice">-</div><div class="kpi-card-label">Precio</div></div>
                <div class="card"><div class="kpi-card-value" id="botPnLToday">-</div><div class="kpi-card-label">PnL hoy</div></div>
                <div class="card"><div class="kpi-card-value" id="botWinRate">-</div><div class="kpi-card-label">Win rate</div></div>
                <div class="card"><div class="kpi-card-value" id="botBalance">-</div><div class="kpi-card-label">Balance</div></div>
                <div class="card"><div class="kpi-card-value" id="botUPnL">-</div><div class="kpi-card-label">uPnL</div></div>
                <div class="card"><div class="kpi-card-value" id="botFillsToday">-</div><div class="kpi-card-label">Fills hoy</div></div>
            </div>
            <div style="margin-top: var(--space-md); display:flex; gap: var(--space-sm); flex-wrap: wrap;">
                <button class="btn btn-danger" onclick="cmd('stop')">Detener</button>
                <button class="btn btn-primary" onclick="cmd('force_ai')">Forzar IA</button>
                <button class="btn btn-primary" onclick="cmd('reset_grid')">Reconstruir grilla</button>
                <button class="btn btn-primary" onclick="cmd('reset_pair')">Reset pair</button>
            </div>
        </div>

        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Ticker ETHUSDT</span></div>
            <div id="tickerLine" style="font-family: var(--font-mono); font-size: .85rem;">-</div>
        </div>

        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Logs del bot</span></div>
            <pre id="botLogs" style="max-height: 320px; overflow:auto; background: rgba(0,0,0,.3); border-radius: var(--radius-md); padding: var(--space-md); font-family: var(--font-mono); font-size: .75rem; line-height: 1.5;">Cargando...</pre>
        </div>
    </div>

    <div id="tab-ordenes" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Órdenes abiertas</span></div>
            <table class="data-table">
                <tr><th>Side</th><th>Rol</th><th>Precio</th><th>Qty</th><th>Nivel</th><th class="hide-mobile">Creada</th></tr>
                <tbody id="openOrdersTb"><tr><td colspan="6" class="empty-state">Cargando...</td></tr></tbody>
            </table>
        </div>
        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">Fills recientes</span></div>
            <table class="data-table">
                <tr><th>Fecha</th><th>Side</th><th>Rol</th><th>Precio</th><th>Qty</th><th>PnL</th><th class="hide-mobile">Recovery</th></tr>
                <?php foreach ($d['fills'] as $f): ?>
                <tr>
                    <td style="font-family:var(--font-mono);white-space:nowrap;"><?= htmlspecialchars($f['filled_at'] ?? '') ?></td>
                    <td><?= htmlspecialchars($f['side'] ?? '') ?></td>
                    <td><?= htmlspecialchars($f['grid_role'] ?? '') ?></td>
                    <td class="num"><?= number_format((float)($f['price'] ?? 0), 2) ?></td>
                    <td class="num"><?= number_format((float)($f['qty'] ?? 0), 4) ?></td>
                    <td class="num" style="color: <?= ((float)($f['pnl_usd'] ?? 0) >= 0) ? 'var(--green)' : 'var(--red)' ?>;"><?= number_format((float)($f['pnl_usd'] ?? 0), 4) ?></td>
                    <td class="hide-mobile"><?= (int)($f['is_recovery'] ?? 0) ? 'Sí' : 'No' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (empty($d['fills'])): ?><div class="empty-state">Sin fills registrados.</div><?php endif; ?>
        </div>
        <div class="card" style="margin-top: var(--space-md);">
            <div class="card-header"><span class="card-title">PnL acumulado</span></div>
            <div style="height: 220px;"><canvas id="cumChart"></canvas></div>
        </div>
        <div style="margin-top: var(--space-md); display:grid; grid-template-columns:1fr 1fr; gap:1px; background:var(--border);">
            <div class="card"><div class="card-header"><span class="card-title">PnL horario 48h</span></div><div style="height:180px;"><canvas id="hChart"></canvas></div></div>
            <div class="card"><div class="card-header"><span class="card-title">PnL diario 14d</span></div><div style="height:180px;"><canvas id="dChart"></canvas></div></div>
        </div>
    </div>

    <div id="tab-fondo" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">NAV histórico</span></div>
            <div style="height: 220px;"><canvas id="navChart"></canvas></div>
        </div>

        <div class="card" style="margin-top: var(--space-md);">
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
            <?php if (empty($d['pending_withdrawals'])): ?><div class="empty-state">Sin retiros pendientes.</div><?php endif; ?>
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
            <?php if (empty($d['withdrawals'])): ?><div class="empty-state">Sin registros.</div><?php endif; ?>
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

        <div class="card" style="margin-top: var(--space-md);">
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
            <?php if (empty($d['deposits'])): ?><div class="empty-state">Sin registros.</div><?php endif; ?>
        </div>

        <div class="card" style="margin-top: var(--space-md);">
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
            <?php if (empty($d['admin_sends'])): ?><div class="empty-state">Sin registros.</div><?php endif; ?>
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
                        <?php if (($u['status'] ?? '') === 'active'): ?>
                            <form method="post" style="display:inline"><input type="hidden" name="action" value="suspend"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="btn btn-danger" style="padding: 4px 10px; font-size: 0.75rem;">Suspender</button></form>
                        <?php else: ?>
                            <form method="post" style="display:inline"><input type="hidden" name="action" value="activate"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="btn btn-primary" style="padding: 4px 10px; font-size: 0.75rem;">Activar</button></form>
                        <?php endif; ?>
                        <button class="btn btn-primary" style="padding: 4px 10px; font-size: 0.75rem;" onclick="openAdjust(<?= (int)$u['id'] ?>, '<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>')">Ajustar</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (empty($d['users'])): ?><div class="empty-state">Sin registros.</div><?php endif; ?>
        </div>
    </div>

    <div id="tab-auditoria" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Auditoría de acciones (últimas 500)</span></div>
            <table class="data-table">
                <tr><th>Fecha</th><th>Admin</th><th>Acción</th><th>Detalle</th><th class="hide-mobile">IP</th></tr>
                <?php foreach ($d['audit_logs'] as $a): ?>
                <tr>
                    <td style="font-family:var(--font-mono);white-space:nowrap;"><?= htmlspecialchars($a['created_at'] ?? '') ?></td>
                    <td><?= htmlspecialchars($a['username'] ?? '') ?></td>
                    <td style="font-family:var(--font-mono);font-size:.8rem;"><?= htmlspecialchars($a['action'] ?? '') ?></td>
                    <td style="max-width: 340px; word-break: break-word;"><?= htmlspecialchars($a['detail'] ?? '') ?></td>
                    <td class="hide-mobile" style="font-family:var(--font-mono);font-size:.8rem;"><?= htmlspecialchars($a['ip'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (empty($d['audit_logs'])): ?><div class="empty-state">Sin acciones registradas.</div><?php endif; ?>
        </div>
    </div>

    <div id="tab-ajustes" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Autenticación de dos factores</span></div>
            <?php if (empty($d['2fa_enabled'])): ?>
                <p>Obligatoria para el panel de administración. Escanea el código QR con Google Authenticator (o añade el secreto manualmente) y verifica un código para activarla.</p>
                <?php if (isset($d['two_factor'])): ?>
                    <img src="<?= htmlspecialchars($d['two_factor']['qr']) ?>" width="220" height="220" alt="QR 2FA" style="display:block;margin:var(--space-md) 0;">
                    <p><code><?= htmlspecialchars($d['two_factor']['secret']) ?></code></p>
                    <form method="post" style="margin-top: var(--space-md);">
                        <input type="hidden" name="action" value="confirm_2fa">
                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                        <div class="cfg-field">
                            <label for="ad2faCode">Código de 6 dígitos</label>
                            <input class="cfg-input" id="ad2faCode" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
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
            <?php else: ?>
                <p>Activa. Para desactivarla verifica un código.</p>
                <form method="post" style="margin-top: var(--space-md);">
                    <input type="hidden" name="action" value="disable_2fa">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <div class="cfg-field">
                        <label for="ad2faCodeDisable">Código de 6 dígitos</label>
                        <input class="cfg-input" id="ad2faCodeDisable" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="margin-top: var(--space-md);">Desactivar</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-alertas" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Alertas del bot</span></div>
            <p style="font-size:0.85rem;color:var(--muted, #94a3b8);">El bot evalúa cada ciclo las alertas habilitadas contra el estado en vivo y notifica cuando el valor supera el umbral.</p>
            <p style="font-size:0.85rem;color:var(--muted, #94a3b8);">
                Token Telegram:
                <?= !empty($d['telegram_token']) ? 'configurado' : 'no configurado' ?>
            </p>
            <?php if (empty($d['alertas'])): ?>
                <p style="font-size:0.85rem;color:var(--muted, #94a3b8);">Aún no hay alertas configuradas.</p>
            <?php else: ?>
                <?php foreach ($d['alertas'] as $alerta): ?>
                <form method="post" class="alert-row" style="display:flex;gap:var(--space-sm);align-items:center;flex-wrap:wrap;margin:var(--space-sm) 0;">
                    <input type="hidden" name="action" value="alerta_save">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="tipo" value="<?= htmlspecialchars($alerta['tipo']) ?>">
                    <code style="min-width:190px;"><?= htmlspecialchars($alerta['tipo']) ?></code>
                    <input class="cfg-input" type="number" step="0.01" min="0.01" name="umbral" value="<?= htmlspecialchars((string)$alerta['umbral']) ?>" style="width:90px;" title="Umbral">
                    <input class="cfg-input" name="telegram_chat_id" placeholder="chat_id Telegram" value="<?= htmlspecialchars((string)($alerta['telegram_chat_id'] ?? '')) ?>" style="width:150px;">
                    <input class="cfg-input" type="number" min="1" name="intervalo_min" value="<?= (int)($alerta['intervalo_min'] ?? 30) ?>" style="width:70px;" title="Intervalo mínimo (min)">
                    <label style="font-size:0.8rem;"><input type="checkbox" name="habilitado" value="1" <?= (int)($alerta['habilitado'] ?? 1) === 1 ? 'checked' : '' ?>> activa</label>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </form>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="alerta_delete">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="id" value="<?= (int)$alerta['id'] ?>">
                    <button type="submit" class="btn btn-danger" style="margin:0 0 var(--space-md) var(--space-sm);" onclick="return confirm('Eliminar esta alerta?');">Eliminar</button>
                </form>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="card" style="margin-top:var(--space-md);">
            <div class="card-header"><span class="card-title">Añadir alerta</span></div>
            <form method="post" style="display:flex;gap:var(--space-sm);flex-wrap:wrap;align-items:center;">
                <input type="hidden" name="action" value="alerta_save">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <select class="cfg-input" name="tipo">
                    <option value="drawdown_pct">Drawdown desde pico (%)</option>
                    <option value="daily_loss_pct">Pérdida diaria (%)</option>
                    <option value="distancia_liquidacion_pct">Distancia a liquidación (%)</option>
                    <option value="saldo_min_usd">Saldo mínimo (USDT)</option>
                </select>
                <input class="cfg-input" type="number" step="0.01" min="0.01" name="umbral" placeholder="Umbral" required style="width:100px;">
                <input class="cfg-input" name="telegram_chat_id" placeholder="chat_id Telegram" style="width:150px;">
                <input class="cfg-input" type="number" min="1" name="intervalo_min" value="30" style="width:70px;" title="Intervalo mínimo (min)">
                <button type="submit" class="btn btn-primary">Crear</button>
            </form>
        </div>
        <div class="card" style="margin-top:var(--space-md);">
            <div class="card-header"><span class="card-title">Token de Telegram</span></div>
            <form method="post" style="display:flex;gap:var(--space-sm);flex-wrap:wrap;align-items:center;">
                <input type="hidden" name="action" value="set_telegram_token">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input class="cfg-input" type="password" name="token" placeholder="Bot token (123456:ABC...)" style="min-width:260px;">
                <button type="submit" class="btn btn-primary">Guardar token</button>
            </form>
            <form method="post" style="margin-top:var(--space-md);">
                <input type="hidden" name="action" value="test_telegram">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input class="cfg-input" name="chat_id" placeholder="chat_id de prueba" style="width:150px;">
                <button type="submit" class="btn">Probar envío</button>
            </form>
        </div>
    </div>

    <div id="tab-reconciliacion" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Reconciliación ledger vs Bybit</span></div>
            <p style="font-size:0.85rem;color:var(--muted, #94a3b8);">Compara el patrimonio contable (NAV × unidades) contra el saldo real del exchange (wallet + PnL no realizado). Diferencia menor a 0.50 USDT = OK.</p>
            <form method="post" style="margin-top:var(--space-md);">
                <input type="hidden" name="action" value="reconciliar">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <button type="submit" class="btn btn-primary">Ejecutar reconciliación</button>
            </form>
            <?php if (!empty($d['reconciliacion'])): ?>
            <?php $rec = $d['reconciliacion']; ?>
            <table class="data-table" style="margin-top:var(--space-md);">
                <tr><th>Concepto</th><th>Valor</th></tr>
                <tr><td>Ledger (NAV × unidades)</td><td class="num"><?= number_format((float)$rec['ledger_total'], 2) ?> USDT</td></tr>
                <tr><td>Exchange (wallet + uPnL)</td><td class="num"><?= number_format((float)$rec['exchange_total'], 2) ?> USDT</td></tr>
                <tr><td>Diferencia</td><td class="num" style="color: <?= (float)$rec['diferencia'] >= 0 ? 'var(--green)' : 'var(--red)' ?>;"><?= number_format((float)$rec['diferencia'], 2) ?> USDT</td></tr>
                <tr><td>Resultado</td><td><span class="badge" style="background: <?= $rec['ok'] ? 'var(--green)' : 'var(--red)' ?>;"><?= $rec['ok'] ? 'OK' : 'DESCUADRE' ?></span></td></tr>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-modelos" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Modelos ML (solo lectura)</span></div>
            <p style="font-size:0.85rem;color:var(--muted, #94a3b8);">No se ejecuta ni entrena nada desde este panel.</p>
            <form method="post" style="margin-top:var(--space-md);">
                <input type="hidden" name="action" value="modelos_list">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <button type="submit" class="btn">Cargar modelos</button>
            </form>
            <?php if (!empty($d['modelos'])): ?>
            <table class="data-table" style="margin-top:var(--space-md);">
                <tr><th>Archivo</th><th>Tamaño</th><th>Modificado</th></tr>
                <?php foreach ($d['modelos']['modelos'] as $m): ?>
                <tr>
                    <td style="font-family:var(--font-mono);"><?= htmlspecialchars($m['archivo']) ?></td>
                    <td class="num"><?= number_format((float)$m['tamano']) ?> B</td>
                    <td><?= htmlspecialchars($m['modificado']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (empty($d['modelos']['modelos'])): ?><div class="empty-state">Sin archivos en data/models/.</div><?php endif; ?>
            <div class="card-header" style="margin-top:var(--space-md);"><span class="card-title">Historial del entrenador</span></div>
            <pre style="max-height:220px;overflow:auto;background:rgba(0,0,0,.3);border-radius:var(--radius-md);padding:var(--space-md);font-family:var(--font-mono);font-size:.75rem;"><?= htmlspecialchars((string)($d['modelos']['historial'] ?? '')) ?></pre>
            <div class="card-header" style="margin-top:var(--space-md);"><span class="card-title">Pesos de volatilidad</span></div>
            <pre style="max-height:220px;overflow:auto;background:rgba(0,0,0,.3);border-radius:var(--radius-md);padding:var(--space-md);font-family:var(--font-mono);font-size:.75rem;"><?= htmlspecialchars(json_encode($d['modelos']['precision'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            <p style="font-size:0.85rem;color:var(--muted, #94a3b8);">ml_accuracy configurado: <strong><?= htmlspecialchars((string)($d['modelos']['ml_accuracy'] ?? '')) ?></strong></p>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-logs-ia" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Logs de decisiones IA</span></div>
            <form method="post" style="display:flex;gap:var(--space-sm);align-items:center;flex-wrap:wrap;margin:var(--space-sm) 0;">
                <input type="hidden" name="action" value="logs_ia">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <label style="font-size:0.85rem;">Página
                    <input class="cfg-input" type="number" min="1" name="pagina" value="<?= max(1, (int)($d['pagina'] ?? 1)) ?>" style="width:70px;">
                </label>
                <label style="font-size:0.85rem;">Por página
                    <input class="cfg-input" type="number" min="10" max="100" step="5" name="por_pagina" value="<?= max(10, (int)($d['paginas'] ?? 25)) ?>" style="width:70px;">
                </label>
                <button type="submit" class="btn">Ver</button>
            </form>
            <?php if (($d['log_view'] ?? null) === 'logs_ia'): ?>
            <table class="data-table">
                <tr><th>Fecha</th><th>Señal</th><th>Confianza</th><th>Razón</th><th>Acción</th><th>Precio</th></tr>
                <?php foreach ($d['filas'] as $row): ?>
                <tr>
                    <td style="font-family:var(--font-mono);white-space:nowrap;"><?= htmlspecialchars($row['fecha'] ?? '') ?></td>
                    <td><span class="badge" style="background: <?= ($row['senal'] ?? '') === 'LONG' ? 'var(--green)' : (($row['senal'] ?? '') === 'SHORT' ? 'var(--red)' : 'var(--muted, #94a3b8)') ?>;"><?= htmlspecialchars($row['senal'] ?? '') ?></span></td>
                    <td class="num"><?= number_format((float)($row['confianza'] ?? 0), 2) ?></td>
                    <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars((string)($row['razon'] ?? '')) ?>"><?= htmlspecialchars((string)($row['razon'] ?? '')) ?></td>
                    <td><?= htmlspecialchars($row['accion_tomada'] ?? '') ?></td>
                    <td class="num"><?= number_format((float)($row['precio'] ?? 0), 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (empty($d['filas'])): ?><div class="empty-state">Sin registros de IA.</div><?php endif; ?>
            <p style="font-size:0.85rem;color:var(--muted, #94a3b8);">Página <?= (int)$d['pagina'] ?> de <?= (int)$d['paginas'] ?> · <?= (int)$d['total'] ?> registros</p>
            <?php else: ?>
            <div class="empty-state">Pulsa «Ver» para cargar los logs de IA.</div>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-logs-acceso" class="panel-content">
        <div class="card">
            <div class="card-header"><span class="card-title">Logs de acceso</span></div>
            <form method="post" style="display:flex;gap:var(--space-sm);align-items:center;flex-wrap:wrap;margin:var(--space-sm) 0;">
                <input type="hidden" name="action" value="logs_acceso">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <label style="font-size:0.85rem;">Página
                    <input class="cfg-input" type="number" min="1" name="pagina" value="<?= max(1, (int)($d['pagina'] ?? 1)) ?>" style="width:70px;">
                </label>
                <label style="font-size:0.85rem;">Por página
                    <input class="cfg-input" type="number" min="10" max="100" step="5" name="por_pagina" value="<?= max(10, (int)($d['paginas'] ?? 25)) ?>" style="width:70px;">
                </label>
                <button type="submit" class="btn">Ver</button>
            </form>
            <?php if (($d['log_view'] ?? null) === 'logs_acceso'): ?>
            <table class="data-table">
                <tr><th>Fecha</th><th>Usuario</th><th>IP</th><th>User agent</th><th>Resultado</th></tr>
                <?php foreach ($d['filas'] as $row): ?>
                <tr>
                    <td style="font-family:var(--font-mono);white-space:nowrap;"><?= htmlspecialchars($row['fecha'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['username'] ?? '') ?></td>
                    <td style="font-family:var(--font-mono);"><?= htmlspecialchars($row['ip'] ?? '') ?></td>
                    <td class="hide-mobile" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars((string)($row['user_agent'] ?? '')) ?>"><?= htmlspecialchars((string)($row['user_agent'] ?? '')) ?></td>
                    <td><span class="badge" style="background: <?= ($row['resultado'] ?? '') === 'exitoso' ? 'var(--green)' : 'var(--red)' ?>;"><?= htmlspecialchars($row['resultado'] ?? '') ?></span></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (empty($d['filas'])): ?><div class="empty-state">Sin registros de acceso.</div><?php endif; ?>
            <p style="font-size:0.85rem;color:var(--muted, #94a3b8);">Página <?= (int)$d['pagina'] ?> de <?= (int)$d['paginas'] ?> · <?= (int)$d['total'] ?> registros</p>
            <?php else: ?>
            <div class="empty-state">Pulsa «Ver» para cargar los logs de acceso.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal-overlay" id="adjustModal" style="display:none;">
    <div class="modal-card">
        <form method="post">
            <input type="hidden" name="action" value="adjust_user">
            <input type="hidden" name="user_id" id="adjUserId">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <div class="card-header"><span class="card-title" id="adjTitle">Ajustar saldo</span></div>
            <div class="cfg-field">
                <label for="adjType">Tipo</label>
                <select class="cfg-input" id="adjType" name="adjust_type">
                    <option value="deposit">Depósito manual</option>
                    <option value="correction">Corrección</option>
                    <option value="refund">Reintegro</option>
                </select>
            </div>
            <div class="cfg-field" style="margin-top: var(--space-md);">
                <label for="adjAmount">Monto (USDT)</label>
                <input class="cfg-input" id="adjAmount" name="amount" type="number" step="0.00000001" min="0.00000001" required>
            </div>
            <div class="cfg-field" style="margin-top: var(--space-md);">
                <label for="adjReason">Motivo</label>
                <input class="cfg-input" id="adjReason" name="reason" maxlength="500" required placeholder="Ej: depósito manual verificado">
            </div>
            <div style="margin-top: var(--space-lg); display:flex; gap: var(--space-sm);">
                <button type="submit" class="btn btn-primary">Aplicar ajuste</button>
                <button type="button" class="btn" onclick="document.getElementById('adjustModal').style.display='none'">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<script>
const API = 'grid_ajax.php';
const CTRL_TOKEN = '<?= htmlspecialchars($CTRL_TOKEN, ENT_QUOTES) ?>';
const NAV_DATA = <?= json_encode($navHistory) ?>;

let pnlHourlyChart = null, pnlDailyChart = null, pnlCumChart = null, navChart = null;

function initCharts() {
    if (typeof Chart === 'undefined') return;
    const baseOpts = { responsive: true, plugins: { legend: { display: false } }, scales: { x: { grid: { color: '#1e3a5f' } }, y: { grid: { color: '#1e3a5f' } } } };
    pnlHourlyChart = new Chart(document.getElementById('hChart'), { type: 'bar', data: { labels: [], datasets: [{ label: 'PnL horario', data: [], backgroundColor: [] }] }, options: baseOpts });
    pnlDailyChart = new Chart(document.getElementById('dChart'), { type: 'bar', data: { labels: [], datasets: [{ label: 'PnL diario', data: [], backgroundColor: [] }] }, options: baseOpts });
    pnlCumChart = new Chart(document.getElementById('cumChart'), { type: 'line', data: { labels: [], datasets: [{ label: 'PnL acumulado', data: [], borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,.15)', fill: true, tension: .2, pointRadius: 0 }] }, options: baseOpts });
    navChart = new Chart(document.getElementById('navChart'), { type: 'line', data: { labels: [], datasets: [{ label: 'NAV', data: [], borderColor: '#22c55e', backgroundColor: 'rgba(34,197,94,.12)', fill: true, tension: .2, pointRadius: 0 }] }, options: baseOpts });
    renderNavChart();
}

function renderNavChart() {
    if (!navChart) return;
    navChart.data.labels = NAV_DATA.map(r => (r.snapshot_at || '').slice(5, 16));
    navChart.data.datasets[0].data = NAV_DATA.map(r => Number(r.nav));
    navChart.update();
}

function botStatus() {
    fetch(API + '?_status&token=' + encodeURIComponent(CTRL_TOKEN), { credentials: 'same-origin' })
        .then(r => r.json()).then(s => {
            if (!s || s.ok === false) return;
            const p = (s.pairs && s.pairs.ETHUSDT) ? s.pairs.ETHUSDT : {};
            const running = !!s.running;
            const runEl = document.getElementById('botRunning');
            if (runEl) { runEl.textContent = running ? 'CORRIENDO' : 'DETENIDO'; runEl.className = 'badge ' + (running ? 'badge-green' : 'badge-red'); }
            const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
            set('botTs', s.ts || '');
            set('botMode', s.mode || 'NORMAL');
            set('botPrice', p.price ? Number(p.price).toFixed(2) : '-');
            set('botPnLToday', (p.pnl_today ?? 0).toFixed(4));
            set('botWinRate', (p.win_rate ?? 0) + '%');
            set('botBalance', (p.real_balance ?? 0).toFixed(2));
            set('botUPnL', (p.total_upnl ?? 0).toFixed(4));
            set('botFillsToday', p.fills_today ?? 0);
            set('kpiPrice', p.price ? Number(p.price).toFixed(2) : '-');
            set('kpiRunning', running ? 'CORRIENDO' : 'DETENIDO');
            set('kpiPnlToday', (p.pnl_today ?? 0).toFixed(4));
            set('kpiWinRate', (p.win_rate ?? 0) + '%');
            set('rRunning', running ? 'CORRIENDO' : 'DETENIDO');
            set('rMode', s.mode || 'NORMAL');
            set('rDirection', p.direction || 'SIDEWAYS');
            set('rConfidence', p.confidence ?? '-');
            set('rUptime', s.uptime || '-');
            set('rBalance', (p.real_balance ?? 0).toFixed(2));
            renderOpenOrders(p.orders || []);
            renderPnLCharts(s.pnl_hourly || [], s.pnl_daily || []);
            loadCumulative();
        }).catch(() => {});
}

function renderOpenOrders(orders) {
    const tb = document.getElementById('openOrdersTb');
    if (!tb) return;
    if (!orders.length) { tb.innerHTML = '<tr><td colspan="6" class="empty-state">Sin órdenes abiertas</td></tr>'; return; }
    tb.innerHTML = orders.map(o =>
        '<tr><td>' + (o.side || '') + '</td><td>' + (o.grid_role || '') + '</td><td class="num">' + Number(o.price || 0).toFixed(2) +
        '</td><td class="num">' + Number(o.qty || 0).toFixed(4) + '</td><td>' + (o.level ?? '') + '</td>' +
        '<td class="hide-mobile" style="font-family:var(--font-mono);font-size:.8rem">' + (o.created_at || '') + '</td></tr>').join('');
}

function renderPnLCharts(hourly, daily) {
    if (!pnlHourlyChart || !pnlDailyChart) return;
    const hl = hourly.map(r => (r.d || '').slice(5) + ' ' + String(r.h).padStart(2, '0') + 'h');
    const hv = hourly.map(r => Number(r.p));
    pnlHourlyChart.data.labels = hl; pnlHourlyChart.data.datasets[0].data = hv;
    pnlHourlyChart.data.datasets[0].backgroundColor = hv.map(v => v >= 0 ? 'rgba(34,197,94,.6)' : 'rgba(239,68,68,.6)');
    pnlHourlyChart.update();
    const dl = daily.map(r => (r.d || '').slice(5));
    const dv = daily.map(r => Number(r.p));
    pnlDailyChart.data.labels = dl; pnlDailyChart.data.datasets[0].data = dv;
    pnlDailyChart.data.datasets[0].backgroundColor = dv.map(v => v >= 0 ? 'rgba(34,197,94,.6)' : 'rgba(239,68,68,.6)');
    pnlDailyChart.update();
}

let cumLoaded = false;
function loadCumulative() {
    if (cumLoaded || !pnlCumChart) return;
    fetch(API + '?_pnl_cumulative', { credentials: 'same-origin' })
        .then(r => r.json()).then(d => {
            if (!d || !d.ok) return;
            cumLoaded = true;
            pnlCumChart.data.labels = (d.points || []).map(r => (r.d || '').slice(5));
            pnlCumChart.data.datasets[0].data = (d.points || []).map(r => Number(r.p));
            pnlCumChart.update();
        }).catch(() => {});
}

function loadTicker() {
    fetch(API + '?_ticker', { credentials: 'same-origin' })
        .then(r => r.json()).then(t => {
            const el = document.getElementById('tickerLine');
            if (!el || !t || !t.ok) return;
            const chg = (t.change24h ?? 0);
            el.innerHTML = '<strong>' + Number(t.price || 0).toFixed(2) + '</strong> USDT' +
                ' <span style="color:' + (chg >= 0 ? 'var(--green)' : 'var(--red)') + '">' + (chg >= 0 ? '+' : '') + chg.toFixed(2) + '%</span>' +
                ' · H24 <strong>' + Number(t.high24h || 0).toFixed(2) + '</strong> / ' + Number(t.low24h || 0).toFixed(2) +
                ' · Vol ' + (t.vol24h ? Number(t.vol24h).toFixed(0) : '0');
        }).catch(() => {});
}

function loadLogs() {
    fetch(API + '?_logs&token=' + encodeURIComponent(CTRL_TOKEN), { credentials: 'same-origin' })
        .then(r => r.json()).then(d => {
            const el = document.getElementById('botLogs');
            if (el && d && d.lines) el.textContent = d.lines.slice(-200).join('\n');
        }).catch(() => {});
}

function cmd(action) {
    const labels = { stop: '¿Detener el bot?', force_ai: '¿Forzar evaluación IA?', reset_grid: '¿Reconstruir grilla?', reset_pair: '¿Resetear par?' };
    if (!confirm(labels[action] || '¿Confirmar?')) return;
    const fd = new FormData();
    fd.append('_control', '1');
    fd.append('action', action);
    fetch(API + '?_control&token=' + encodeURIComponent(CTRL_TOKEN), { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json()).then(d => { alert(d.ok ? (d.msg || 'OK') : (d.msg || 'Error')); if (d.ok) botStatus(); })
        .catch(() => alert('Error de red'));
}

function openAdjust(id, username) {
    document.getElementById('adjUserId').value = id;
    document.getElementById('adjTitle').textContent = 'Ajustar saldo · ' + username;
    document.getElementById('adjustModal').style.display = 'flex';
}

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
    return { network, token, dest, amount, destValid, amountValid };
}

async function estimateGas() {
    const { network, token, dest, amount, destValid, amountValid } = validateForm();
    if (!destValid || !amountValid) {
        gasDiv.style.display = 'none';
        return;
    }
    gasDiv.style.display = 'block';
    gasDiv.style.color = '';
    gasDiv.textContent = 'Estimando gas...';
    try {
        const url = 'admin.php?action=estimate_gas&network=' + encodeURIComponent(network) + '&token=' + encodeURIComponent(token) + '&destination=' + encodeURIComponent(dest) + '&amount=' + encodeURIComponent(amountInput.value);
        const resp = await fetch(url, { credentials: 'same-origin' });
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

['network', 'token', 'destination', 'amount'].forEach(function (id) {
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

initCharts();
botStatus();
loadTicker();
loadLogs();
setInterval(botStatus, 5000);
setInterval(loadTicker, 10000);
setInterval(loadLogs, 15000);

var savedTab = location.hash.replace('#', '');
if (savedTab) {
    var savedEl = document.querySelector('.panel-tab[data-tab="' + savedTab + '"]');
    if (savedEl) activatePanelTab(savedEl);
}
</script>
</body>
</html>
