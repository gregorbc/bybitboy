<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BinanceBot\Core\AuthHttp;
use BinanceBot\Core\Config;
use BinanceBot\Core\Csrf;
use BinanceBot\Core\Database;
use BinanceBot\Core\Schema;

session_start();
session_set_cookie_params([
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Lax',
    'path' => '/',
]);

$db = Database::getInstance();
$pdo = $db->getPdo();
if (!$pdo) {
    http_response_code(500);
    exit('Base de datos no disponible');
}
Schema::createTables($pdo);

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$result = AuthHttp::handle($pdo, $_SESSION, $_GET, $_POST, $ip);

if ($result['redirect']) {
    header('Location: ' . $result['redirect']);
    exit;
}

$csrf = Csrf::token($_SESSION);
$view = $result['view'] === 'register' ? 'register' : 'login';
$error = $result['error'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ingreso · Grid Bot Inversión</title>
<style>
body{font-family:system-ui,sans-serif;background:#0d1117;color:#e6edf3;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.card{background:#161b22;border:1px solid #30363d;border-radius:12px;padding:32px;width:320px}
h1{font-size:20px;margin:0 0 4px}
p.sub{color:#8b949e;font-size:13px;margin:0 0 20px}
label{display:block;font-size:12px;color:#8b949e;margin:12px 0 4px}
input{width:100%;box-sizing:border-box;padding:10px;border-radius:6px;border:1px solid #30363d;background:#0d1117;color:#e6edf3}
button{width:100%;margin-top:18px;padding:11px;border:0;border-radius:6px;background:#238636;color:#fff;font-weight:600;cursor:pointer}
.error{color:#f85149;font-size:13px;margin-top:12px}
.alt{margin-top:16px;font-size:13px;text-align:center}
a{color:#58a6ff}
</style>
</head>
<body>
<div class="card">
    <h1>Grid Bot · Inversión</h1>
    <p class="sub"><?= $view === 'register' ? 'Crear cuenta de inversor' : 'Ingresar' ?></p>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($view === 'register'): ?>
    <form method="post">
        <input type="hidden" name="action" value="register">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <label>Usuario</label><input name="username" required minlength="3" maxlength="50" autocomplete="username">
        <label>Email</label><input name="email" type="email" required autocomplete="email">
        <label>Contraseña (mín. 8)</label><input name="password" type="password" required minlength="8" autocomplete="new-password">
        <button type="submit">Crear cuenta</button>
    </form>
    <div class="alt">¿Ya tienes cuenta? <a href="auth.php?view=login">Ingresar</a></div>
    <?php else: ?>
    <form method="post">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <label>Usuario</label><input name="username" required autocomplete="username">
        <label>Contraseña</label><input name="password" type="password" required autocomplete="current-password">
        <button type="submit">Ingresar</button>
    </form>
    <div class="alt">¿Sin cuenta? <a href="auth.php?view=register">Registrarse</a></div>
    <?php endif; ?>
</div>
</body>
</html>