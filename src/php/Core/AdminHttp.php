<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class AdminHttp
{
    public static function handle(PDO $pdo, array &$session, array $post, ?callable $sendDirect = null): array
    {
        if (empty($session['user_id']) || ($session['role'] ?? '') !== 'admin') {
            return ['view' => 'forbidden', 'data' => []];
        }
        $action = (string)($post['action'] ?? '');
        $error = null;
        if ($action !== '' && !Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
            $error = 'Token CSRF inválido';
        } elseif ($action === 'approve') {
            Accounting::approveWithdrawal($pdo, (int)($post['id'] ?? 0));
        } elseif ($action === 'sent') {
            Accounting::markSent($pdo, (int)($post['id'] ?? 0), (string)($post['tx_hash'] ?? ''));
        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE withdrawals SET status = 'rejected', admin_note = ? WHERE id = ?")
                ->execute([(string)($post['note'] ?? ''), (int)($post['id'] ?? 0)]);
        } elseif ($action === 'suspend') {
            $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([(int)($post['id'] ?? 0)]);
        } elseif ($action === 'activate') {
            $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([(int)($post['id'] ?? 0)]);
        } elseif ($action === 'deploy') {
            Accounting::markDeployed($pdo, (int)($post['id'] ?? 0));
        } elseif ($action === 'send_direct') {
            if (empty($post['confirm'])) {
                $error = 'Debes confirmar que la dirección y monto son correctos';
            } else {
                $network = (string)($post['network'] ?? '');
                $token = strtoupper((string)($post['token'] ?? ''));
                $destination = (string)($post['destination'] ?? '');
                $amount = trim((string)($post['amount'] ?? ''));

                if (!Networks::validateAddress($network, $destination)) {
                    $error = 'Dirección destino inválida para la red';
                } elseif (!in_array($token, ['USDT', 'USDC'], true)) {
                    $error = 'Token no soportado';
                } elseif (!preg_match('/^\d{1,18}(\.\d{1,18})?$/', $amount)) {
                    $error = 'Monto inválido';
                } else {
                    $secret = getenv('PLATFORM_SECRET') ?: '';
                    if ($secret === '') {
                        $error = 'PLATFORM_SECRET no configurado';
                    } else {
                        $result = $sendDirect
                            ? $sendDirect($pdo, $secret, $network, $token, $destination, $amount)
                            : Wallet::signAndSendERC20($pdo, $secret, $network, $token, $destination, $amount);
                        if ($result['ok']) {
                            $stmt = $pdo->prepare('INSERT INTO admin_sends (admin_id, network, token, amount, destination_address, tx_hash, status, gas_used, gas_price, sent_at) VALUES (?, ?, ?, ?, ?, ?, "sent", ?, ?, datetime("now"))');
                            $stmt->execute([
                                $session['user_id'],
                                $network,
                                $token,
                                $amount,
                                $destination,
                                $result['tx_hash'],
                                $result['gas_used'] ?? 0,
                                $result['gas_price'] ?? 0,
                            ]);
                            $session['flash'] = 'Envío exitoso. Tx: ' . $result['tx_hash'];
                        } else {
                            $stmt = $pdo->prepare('INSERT INTO admin_sends (admin_id, network, token, amount, destination_address, status, error_message) VALUES (?, ?, ?, ?, ?, "failed", ?)');
                            $stmt->execute([
                                $session['user_id'],
                                $network,
                                $token,
                                $amount,
                                $destination,
                                $result['error'],
                            ]);
                            $error = $result['error'];
                        }
                    }
                }
            }
        }

        $data = [
            'users' => $pdo->query('SELECT id, username, email, role, status, created_at FROM users ORDER BY id')->fetchAll(),
            'pending_withdrawals' => Accounting::pendingWithdrawals($pdo),
            'withdrawals' => $pdo->query('SELECT w.*, u.username FROM withdrawals w JOIN users u ON u.id = w.user_id ORDER BY w.id DESC LIMIT 50')->fetchAll(),
            'deposits' => $pdo->query('SELECT d.*, u.username FROM deposits d JOIN users u ON u.id = d.user_id ORDER BY d.id DESC LIMIT 50')->fetchAll(),
            'nav' => Accounting::currentNav($pdo),
            'total_units' => Accounting::totalUnits($pdo),
            'wallet_held' => Accounting::walletHeld($pdo),
            'error' => $error,
        ];
        return ['view' => 'overview', 'data' => $data];
    }
}