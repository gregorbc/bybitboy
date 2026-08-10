<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class InvestorHttp
{
    public static function handle(PDO $pdo, array &$session, array $get, array $post, string $secret): array
    {
        if (empty($session['user_id'])) {
            return ['view' => 'login', 'data' => []];
        }
        $userId = (int)$session['user_id'];
        $action = (string)($post['action'] ?? '');
        $error = null;
        $withdrawalId = null;

        if ($action === 'enable_2fa') {
            if (!Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
                $error = 'Token CSRF inválido';
            } else {
                $secret = TwoFactor::generateSecret();
                $session['pending_2fa_secret'] = $secret;
                $uri = TwoFactor::otpauthUri($secret, (string)($session['username'] ?? ''), 'Grid Bot');
                $data = [
                    'two_factor' => [
                        'secret' => $secret,
                        'qr' => 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($uri),
                    ],
                    'error' => null,
                    'flash' => null,
                    'networks' => array_keys(Networks::all()),
                ];
                return ['view' => 'panel', 'data' => $data];
            }
        } elseif ($action === 'confirm_2fa') {
            if (!Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
                $error = 'Token CSRF inválido';
            } else {
                $secret = (string)($session['pending_2fa_secret'] ?? '');
                if ($secret === '' || !TwoFactor::verify((string)($post['code'] ?? ''), $secret)) {
                    $error = 'Código incorrecto';
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?');
                    $stmt->execute([$secret, $userId]);
                    unset($session['pending_2fa_secret']);
                    $session['flash'] = '2FA activada correctamente';
                }
            }
        } elseif ($action === 'disable_2fa') {
            if (!Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
                $error = 'Token CSRF inválido';
            } else {
                $stmt = $pdo->prepare('SELECT totp_secret FROM users WHERE id = ?');
                $stmt->execute([$userId]);
                $dbSecret = (string)($stmt->fetchColumn() ?: '');
                if (!TwoFactor::verify((string)($post['code'] ?? ''), $dbSecret)) {
                    $error = 'Código incorrecto';
                } else {
                    $stmt = $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?');
                    $stmt->execute([$userId]);
                    $session['flash'] = '2FA desactivada';
                }
            }
        } elseif ($action === 'withdraw') {
            $network = (string)($post['network'] ?? '');
            $token = strtoupper((string)($post['token'] ?? ''));
            $amount = (float)($post['amount'] ?? 0);
            $destination = (string)($post['destination'] ?? '');
            if (!Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
                $error = 'Token CSRF inválido';
            } elseif (!Networks::validateAddress($network, $destination)) {
                $error = 'Dirección inválida para la red';
            } elseif (!in_array($token, ['USDT', 'USDC'], true)) {
                $error = 'Token no soportado';
            } else {
                $min = (float)Config::getInstance()->get('platform.min_withdrawal', 10.0);
                $res = Accounting::requestWithdrawal($pdo, $userId, $network, $token, $amount, $destination, $min);
                if (!$res['ok']) {
                    $error = $res['error'];
                } else {
                    $session['flash'] = 'Retiro solicitado correctamente';
                    $withdrawalId = $res['withdrawal_id'];
                }
            }
        } elseif ($action === 'change_password') {
            if (!Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
                $error = 'Token CSRF inválido';
            } else {
                $current = (string)($post['current_password'] ?? '');
                $new = (string)($post['new_password'] ?? '');
                $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
                $stmt->execute([$userId]);
                $row = $stmt->fetch();
                if (!$row || !password_verify($current, $row['password_hash'])) {
                    $error = 'Contraseña actual incorrecta';
                } elseif (strlen($new) < 8) {
                    $error = 'La nueva contraseña debe tener al menos 8 caracteres';
                } else {
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                        ->execute([password_hash($new, PASSWORD_BCRYPT), $userId]);
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_regenerate_id(true);
                    }
                    $session['flash'] = 'Contraseña actualizada correctamente';
                }
            }
        } elseif ($action === 'update_profile') {
            if (!Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
                $error = 'Token CSRF inválido';
            } else {
                $email = strtolower(trim((string)($post['email'] ?? '')));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Email inválido';
                } else {
                    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
                    $stmt->execute([$email, $userId]);
                    if ($stmt->fetch()) {
                        $error = 'Ese email ya está en uso';
                    } else {
                        $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $userId]);
                        $session['flash'] = 'Perfil actualizado';
                    }
                }
            }
        }

        $addresses = [];
        foreach (array_keys(Networks::all()) as $network) {
            try {
                $addresses[$network] = Wallet::getDepositAddress($pdo, $userId, $network, $secret);
            } catch (\Throwable $e) {
                $addresses[$network] = null;
            }
        }

        $stmt = $pdo->prepare('SELECT * FROM withdrawals WHERE user_id = ? ORDER BY id DESC LIMIT 100');
        $stmt->execute([$userId]);
        $withdrawals = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM movements WHERE user_id = ? ORDER BY id DESC LIMIT 200');
        $stmt->execute([$userId]);
        $movements = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM deposits WHERE user_id = ? ORDER BY id DESC LIMIT 100');
        $stmt->execute([$userId]);
        $deposits = $stmt->fetchAll();

        $equity = Accounting::userEquity($pdo, $userId);
        $stmt = $pdo->prepare('SELECT created_at, balance_after FROM movements WHERE user_id = ? ORDER BY id ASC');
        $stmt->execute([$userId]);
        $equityHistory = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS t FROM movements WHERE user_id = ? AND type = 'deposit'");
        $stmt->execute([$userId]);
        $totalDeposited = (float)$stmt->fetch()['t'];
        $growthPct = $totalDeposited > 0 ? round(($equity - $totalDeposited) / $totalDeposited * 100, 2) : 0.0;

        $stmt = $pdo->prepare('SELECT email, totp_enabled FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $userRow = $stmt->fetch();
        $email = $userRow['email'] ?? '';

        $data = [
            'equity' => $equity,
            'units' => Accounting::userUnits($pdo, $userId),
            'nav' => Accounting::currentNav($pdo),
            'growth_pct' => $growthPct,
            'equity_history' => $equityHistory,
            'email' => $email,
            'addresses' => $addresses,
            'withdrawals' => $withdrawals,
            'movements' => $movements,
            'deposits' => $deposits,
            'error' => $error,
            'flash' => $session['flash'] ?? null,
            'networks' => array_keys(Networks::all()),
            '2fa_enabled' => (int)($userRow['totp_enabled'] ?? 0) === 1,
        ];
        if ($withdrawalId !== null) {
            $data['withdrawal_id'] = $withdrawalId;
        }
        unset($session['flash']);
        return ['view' => 'panel', 'data' => $data];
    }
}
