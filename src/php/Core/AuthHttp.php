<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class AuthHttp
{
    public static function handle(PDO $pdo, array &$session, array $get, array $post, string $ip): array
    {
        $action = (string)($get['action'] ?? ($post['action'] ?? ''));
        $isPost = $post !== [];
        if ($isPost && !Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
            return ['redirect' => null, 'error' => 'Token CSRF inválido', 'view' => 'login'];
        }
        if ($action === 'register') {
            $res = Auth::register($pdo, (string)($post['username'] ?? ''), (string)($post['email'] ?? ''), (string)($post['password'] ?? ''));
            if ($res['ok']) {
                $session['user_id'] = $res['user_id'];
                $session['username'] = (string)($post['username'] ?? '');
                $session['role'] = 'investor';
                LogAccess::record($pdo, (int)$res['user_id'], (string)($post['username'] ?? ''), $ip, self::userAgent(), 'exitoso');
                return ['redirect' => 'panel.php', 'view' => 'login', 'error' => null];
            }
            return ['redirect' => null, 'error' => $res['error'], 'view' => 'register'];
        }
        if ($action === 'login') {
            if (!Auth::checkRateLimit($pdo, $ip, 'login', 10, 900)) {
                return ['redirect' => null, 'error' => 'Demasiados intentos. Espera unos minutos.', 'view' => 'login'];
            }
            $user = Auth::login($pdo, (string)($post['username'] ?? ''), (string)($post['password'] ?? ''));
            Auth::recordAttempt($pdo, $ip, 'login', (string)($post['username'] ?? ''), $user !== null);
            if ($user) {
                if (!empty($user['totp_enabled'])) {
                    $session['pending_2fa'] = [
                        'user_id' => (int)$user['id'],
                        'username' => (string)$user['username'],
                        'role' => (string)$user['role'],
                        'redirect' => ($user['role'] === 'admin') ? 'src/php/index.php' : 'panel.php',
                    ];
                    return ['redirect' => null, 'view' => '2fa', 'error' => null];
                }
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                $session['user_id'] = (int)$user['id'];
                $session['username'] = (string)$user['username'];
                $session['role'] = (string)$user['role'];
                LogAccess::record($pdo, (int)$user['id'], (string)$user['username'], $ip, self::userAgent(), 'exitoso');
                $redirect = ($user['role'] === 'admin') ? 'src/php/index.php' : 'panel.php';
                return ['redirect' => $redirect, 'view' => 'login', 'error' => null];
            }
            LogAccess::record($pdo, null, (string)($post['username'] ?? ''), $ip, self::userAgent(), 'fallido');
            return ['redirect' => null, 'error' => 'Usuario o contraseña incorrectos', 'view' => 'login'];
        }
        if ($action === 'verify_2fa') {
            if (empty($session['pending_2fa'])) {
                return ['redirect' => null, 'error' => 'Sesión expirada. Vuelve a ingresar.', 'view' => 'login'];
            }
            $pending = $session['pending_2fa'];
            $user = Auth::getUserById($pdo, (int)$pending['user_id']);
            if (!$user || !TwoFactor::verify((string)($post['code'] ?? ''), (string)$user['totp_secret'])) {
                LogAccess::record($pdo, (int)$pending['user_id'], (string)$pending['username'], $ip, self::userAgent(), 'fallido');
                return ['redirect' => null, 'error' => 'Código 2FA incorrecto', 'view' => '2fa'];
            }
            unset($session['pending_2fa']);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $session['user_id'] = (int)$user['id'];
            $session['username'] = (string)$user['username'];
            $session['role'] = (string)$user['role'];
            LogAccess::record($pdo, (int)$user['id'], (string)$user['username'], $ip, self::userAgent(), 'exitoso');
            return ['redirect' => (string)$pending['redirect'], 'view' => 'login', 'error' => null];
        }
        return ['redirect' => null, 'error' => null, 'view' => 'login'];
    }

    private static function userAgent(): string
    {
        return (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    }
}