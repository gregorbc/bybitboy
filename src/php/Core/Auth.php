<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class Auth
{
    public static function register(PDO $pdo, string $username, string $email, string $password): array
    {
        if (!self::isValidPassword($password)) {
            return ['ok' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Email inválido'];
        }
        $u = trim($username);
        if (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $u)) {
            return ['ok' => false, 'error' => 'Usuario inválido (3-50 caracteres alfanuméricos o _)'];
        }
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$u, $email]);
        if ($stmt->fetch()) {
            return ['ok' => false, 'error' => 'El usuario o email ya está registrado'];
        }
        $stmt = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)');
        $stmt->execute([$u, $email, password_hash($password, PASSWORD_BCRYPT)]);
        return ['ok' => true, 'user_id' => (int)$pdo->lastInsertId()];
    }

    public static function login(PDO $pdo, string $username, string $password): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([trim($username)]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }
        if ($user['status'] !== 'active') {
            return null;
        }
        $stmt = $pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?');
        $stmt->execute([date('Y-m-d H:i:s'), $user['id']]);
        return $user;
    }

    public static function isValidPassword(string $password): bool
    {
        return strlen($password) >= 8;
    }

    public static function checkRateLimit(PDO $pdo, string $ip, string $action, int $max, int $windowSec): bool
    {
        $cutoff = date('Y-m-d H:i:s', time() - $windowSec);
        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM login_attempts WHERE ip = ? AND action = ? AND success = 0 AND created_at > ?');
        $stmt->execute([$ip, $action, $cutoff]);
        return (int)$stmt->fetch()['c'] < $max;
    }

    public static function recordAttempt(PDO $pdo, string $ip, string $action, string $username, bool $success): void
    {
        $stmt = $pdo->prepare('INSERT INTO login_attempts (ip, action, username, success) VALUES (?, ?, ?, ?)');
        $stmt->execute([$ip, $action, $username, (int)$success]);
    }
}
