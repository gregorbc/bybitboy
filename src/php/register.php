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
