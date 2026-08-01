<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BinanceBot\Core\AdminHttp;
use BinanceBot\Core\Csrf;
use BinanceBot\Core\Database;
use BinanceBot\Core\Schema;

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
<style>
body{font-family:system-ui,sans-serif;background:#0d1117;color:#e6edf3;margin:0;padding:16px}
.card{background:#161b22;border:1px solid #30363d;border-radius:10px;padding:16px;margin-bottom:14px}
h1{font-size:18px;margin:0 0 4px} h2{font-size:15px;margin:0 0 10px}
.g{color:#3fb950} .m{color:#8b949e} .mono{font-family:monospace;font-size:12px;word-break:break-all}
table{width:100%;border-collapse:collapse;font-size:12px}
td,th{text-align:left;padding:6px 4px;border-bottom:1px solid #21262d}
button{padding:6px 10px;border:0;border-radius:6px;cursor:pointer}
.b-ok{background:#238636;color:#fff}.b-sent{background:#1f6feb;color:#fff}.b-ko{background:#da3633;color:#fff}.b-neu{background:#30363d;color:#e6edf3}
input{width:340px;max-width:100%;padding:6px;border-radius:6px;border:1px solid #30363d;background:#0d1117;color:#e6edf3}
a{color:#58a6ff}
</style>
</head>
<body>
<h1>Admin</h1>
<p class="m"><a href="auth.php?action=logout">Salir</a> · <a href="panel.php">Mi panel</a></p>
<?php if (!empty($d['error'])): ?><p class="g" style="color:#f85149"><?= htmlspecialchars($d['error']) ?></p><?php endif; ?>

<div class="card">
    <h2>Estado del fondo</h2>
    <p>NAV: <strong class="g"><?= number_format($d['nav'], 6) ?></strong> · Unidades totales: <?= number_format($d['total_units'], 2) ?> · En wallet (sin desplegar): <?= number_format($d['wallet_held'], 2) ?> USDT</p>
</div>

<div class="card">
    <h2>Retiros pendientes</h2>
    <table><tr><th>Usuario</th><th>Red</th><th>Token</th><th>Monto</th><th>Destino</th><th>Acciones</th></tr>
    <?php foreach ($d['pending_withdrawals'] as $w): ?>
        <tr>
            <td><?= htmlspecialchars($w['username']) ?></td>
            <td><?= htmlspecialchars($w['network']) ?></td>
            <td><?= htmlspecialchars($w['token']) ?></td>
            <td><?= number_format((float)$w['amount'], 2) ?></td>
            <td class="mono"><?= htmlspecialchars($w['destination_address']) ?></td>
            <td>
                <form method="post" style="display:inline"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="b-ok">Aprobar</button></form>
                <form method="post" style="display:inline"><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="b-ko">Rechazar</button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    </table>
</div>

<div class="card">
    <h2>Retiros (historial)</h2>
    <table><tr><th>Usuario</th><th>Estado</th><th>Monto</th><th>Tx</th></tr>
    <?php foreach ($d['withdrawals'] as $w): ?>
        <tr>
            <td><?= htmlspecialchars($w['username']) ?></td>
            <td><?= htmlspecialchars($w['status']) ?></td>
            <td><?= number_format((float)$w['amount'], 2) ?></td>
            <td class="mono"><?= htmlspecialchars($w['tx_hash'] ?: '-') ?></td>
        </tr>
    <?php endforeach; ?>
    </table>
    <?php if ($d['withdrawals']): ?>
    <form method="post" style="margin-top:10px">
        <input type="hidden" name="action" value="sent">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <label class="m">ID retiro aprobado</label>
        <select name="id"><?php foreach ($d['withdrawals'] as $w): ?><?php if ($w['status'] === 'approved'): ?><option value="<?= (int)$w['id'] ?>">#<?= (int)$w['id'] ?> · <?= htmlspecialchars($w['username']) ?> · <?= number_format((float)$w['amount'], 2) ?></option><?php endif; ?><?php endforeach; ?></select>
        <label class="m">Tx hash del envío</label>
        <input name="tx_hash" placeholder="0x...">
        <button class="b-sent">Marcar enviado</button>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Depósitos</h2>
    <table><tr><th>Usuario</th><th>Estado</th><th>Red</th><th>Token</th><th>Monto</th><th>Desplegado</th><th>Tx</th></tr>
    <?php foreach ($d['deposits'] as $dep): ?>
        <tr>
            <td><?= htmlspecialchars($dep['username']) ?></td>
            <td><?= htmlspecialchars($dep['status']) ?></td>
            <td><?= htmlspecialchars($dep['network']) ?></td>
            <td><?= htmlspecialchars($dep['token']) ?></td>
            <td><?= number_format((float)$dep['amount'], 2) ?></td>
            <td>
                <?php if ($dep['status'] === 'credited' && !$dep['deployed']): ?>
                    <form method="post" style="display:inline"><input type="hidden" name="action" value="deploy"><input type="hidden" name="id" value="<?= (int)$dep['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="b-ok">Marcar desplegado</button></form>
                <?php else: ?><?= (int)$dep['deployed'] ? 'Sí' : 'No' ?><?php endif; ?>
            </td>
            <td class="mono"><?= htmlspecialchars($dep['tx_hash']) ?></td>
        </tr>
    <?php endforeach; ?>
    </table>
</div>

<div class="card">
    <h2>Usuarios</h2>
    <table><tr><th>ID</th><th>Usuario</th><th>Email</th><th>Rol</th><th>Estado</th><th>Acciones</th></tr>
    <?php foreach ($d['users'] as $u): ?>
        <tr>
            <td><?= (int)$u['id'] ?></td>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= htmlspecialchars($u['role']) ?></td>
            <td><?= htmlspecialchars($u['status']) ?></td>
            <td>
                <?php if ($u['status'] === 'active'): ?>
                    <form method="post" style="display:inline"><input type="hidden" name="action" value="suspend"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="b-ko">Suspender</button></form>
                <?php else: ?>
                    <form method="post" style="display:inline"><input type="hidden" name="action" value="activate"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="b-neu">Activar</button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </table>
</div>
</body>
</html>