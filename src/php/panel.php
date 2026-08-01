<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BinanceBot\Core\Config;
use BinanceBot\Core\Csrf;
use BinanceBot\Core\Database;
use BinanceBot\Core\InvestorHttp;
use BinanceBot\Core\Schema;

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
<style>
body{font-family:system-ui,sans-serif;background:#0d1117;color:#e6edf3;margin:0;padding:16px}
.card{background:#161b22;border:1px solid #30363d;border-radius:10px;padding:16px;margin-bottom:14px}
h1{font-size:18px;margin:0 0 4px} h2{font-size:15px;margin:0 0 10px}
.g{color:#3fb950} .r{color:#f85149} .m{color:#8b949e}
.mono{font-family:monospace;font-size:12px;word-break:break-all}
table{width:100%;border-collapse:collapse;font-size:12px}
td,th{text-align:left;padding:6px 4px;border-bottom:1px solid #21262d}
label{display:block;font-size:12px;color:#8b949e;margin:8px 0 4px}
input,select{padding:8px;border-radius:6px;border:1px solid #30363d;background:#0d1117;color:#e6edf3}
button{padding:9px 14px;border:0;border-radius:6px;background:#238636;color:#fff;cursor:pointer}
.flash{background:#1f6feb22;border:1px solid #1f6feb;border-radius:6px;padding:8px;margin-bottom:12px}
.err{background:#f8514922;border:1px solid #f85149;border-radius:6px;padding:8px;margin-bottom:12px}
a{color:#58a6ff}
</style>
</head>
<body>
<h1>Mi inversión</h1>
<p class="m">Usuario: <strong><?= htmlspecialchars($_SESSION['username'] ?? '') ?></strong> · <a href="auth.php?action=logout">Salir</a></p>
<?php if (!empty($d['flash'])): ?><div class="flash"><?= htmlspecialchars($d['flash']) ?></div><?php endif; ?>
<?php if (!empty($d['error'])): ?><div class="err"><?= htmlspecialchars($d['error']) ?></div><?php endif; ?>

<div class="card">
    <h2>Mi saldo</h2>
    <p>Equidad: <strong class="g"><?= number_format($d['equity'], 2) ?> USDT</strong></p>
    <p class="m">Unidades: <?= number_format($d['units'], 8) ?> · NAV: <?= number_format($d['nav'], 6) ?></p>
</div>

<div class="card">
    <h2>Direcciones de depósito (USDT / USDC)</h2>
    <?php foreach ($d['networks'] as $network): ?>
        <p><strong><?= htmlspecialchars($networks[$network] ?? $network) ?></strong></p>
        <p class="mono"><?= htmlspecialchars($d['addresses'][$network] ?? 'no disponible') ?></p>
    <?php endforeach; ?>
    <p class="m">Envía USDT o USDC a tu dirección. Solo se acreditan depósitos confirmados.</p>
</div>

<div class="card">
    <h2>Solicitar retiro</h2>
    <form method="post">
        <input type="hidden" name="action" value="withdraw">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <label>Red</label>
        <select name="network"><?php foreach ($d['networks'] as $n): ?><option value="<?= $n ?>"><?= htmlspecialchars($networks[$n] ?? $n) ?></option><?php endforeach; ?></select>
        <label>Token</label>
        <select name="token"><option>USDT</option><option>USDC</option></select>
        <label>Monto (USDT)</label><input name="amount" type="number" step="0.01" min="0" required>
        <label>Dirección destino</label><input name="destination" placeholder="0x..." required>
        <button type="submit">Solicitar retiro</button>
    </form>
</div>

<div class="card">
    <h2>Mis retiros</h2>
    <table><tr><th>Estado</th><th>Red</th><th>Monto</th><th>Tx</th></tr>
    <?php foreach ($d['withdrawals'] as $w): ?>
        <tr><td><?= htmlspecialchars($w['status']) ?></td><td><?= htmlspecialchars($w['network']) ?></td><td><?= number_format((float)$w['amount'], 2) ?></td><td class="mono"><?= htmlspecialchars($w['tx_hash'] ?: '-') ?></td></tr>
    <?php endforeach; ?>
    </table>
</div>

<div class="card">
    <h2>Depósitos</h2>
    <table><tr><th>Estado</th><th>Red</th><th>Token</th><th>Monto</th><th>Tx</th></tr>
    <?php foreach ($d['deposits'] as $dep): ?>
        <tr><td><?= htmlspecialchars($dep['status']) ?></td><td><?= htmlspecialchars($dep['network']) ?></td><td><?= htmlspecialchars($dep['token']) ?></td><td><?= number_format((float)$dep['amount'], 2) ?></td><td class="mono"><?= htmlspecialchars($dep['tx_hash']) ?></td></tr>
    <?php endforeach; ?>
    </table>
</div>
</body>
</html>