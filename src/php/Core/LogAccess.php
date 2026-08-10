<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

/**
 * Registro de accesos al sistema (logins exitosos y fallidos).
 * Independiente de login_attempts, que sigue como protección anti-brute-force.
 */
class LogAccess
{
    public static function record(PDO $pdo, ?int $userId, string $username, string $ip, string $userAgent, string $resultado): void
    {
        $stmt = $pdo->prepare('INSERT INTO logs_acceso (usuario_id, username, ip, user_agent, resultado) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, mb_substr($username, 0, 60), mb_substr($ip, 0, 45), mb_substr($userAgent, 0, 255), $resultado]);
    }
}
