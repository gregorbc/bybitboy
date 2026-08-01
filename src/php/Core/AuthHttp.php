<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class AuthHttp
{
    public static function handle(PDO $pdo, array &$session, array $get, array $post, string $ip): array
    {
        $action = (string)($get['action'] ?? ($post['action'] ?? ''));
        if (!Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
            return ['redirect' => null, 'error' => 'Token CSRF inválido', 'view' => 'login'];
        }
        if ($action === 'register') {
            $res = Auth::register($pdo, (string)($post['username'] ?? ''), (string)($post['email'] ?? ''), (string)($post['password'] ?? ''));
            if ($res['ok']) {
                $session['user_id'] = $res['user_id'];
                $session['username'] = (string)($post['username'] ?? '');
                $session['role'] = 'investor';
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
                session_regenerate_id(true);
                $session['user_id'] = (int)$user['id'];
                $session['username'] = (string)$user['username'];
                $session['role'] = (string)$user['role'];
                return ['redirect' => 'panel.php', 'view' => 'login', 'error' => null];
            }
            return ['redirect' => null, 'error' => 'Usuario o contraseña incorrectos', 'view' => 'login'];
        }
        return ['redirect' => null, 'error' => null, 'view' => 'login'];
    }
}