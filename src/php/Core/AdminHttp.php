<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class AdminHttp
{
    public static function estimateGas(PDO $pdo, string $network, string $token, string $destination, string $amount, string $secret, ?RpcClient $rpc = null): array
    {
        return Wallet::estimateGas($pdo, $secret, $network, $token, $destination, $amount, $rpc);
    }

    public static function handle(PDO $pdo, array &$session, array $post, ?callable $sendDirect = null, ?RpcClient $rpc = null): array
    {
        if (empty($session['user_id']) || ($session['role'] ?? '') !== 'admin') {
            return ['view' => 'forbidden', 'data' => []];
        }
        $action = (string)($post['action'] ?? '');
        $error = null;
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

        if ($action !== '' && !Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
            $error = 'Token CSRF inválido';
        } elseif ($action === 'approve') {
            Accounting::approveWithdrawal($pdo, (int)($post['id'] ?? 0));
            self::audit($pdo, $session, 'approve_withdrawal', 'retiro #' . (int)($post['id'] ?? 0), $ip);
        } elseif ($action === 'sent') {
            Accounting::markSent($pdo, (int)($post['id'] ?? 0), (string)($post['tx_hash'] ?? ''));
            self::audit($pdo, $session, 'mark_sent', 'retiro #' . (int)($post['id'] ?? 0), $ip);
        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE withdrawals SET status = 'rejected', admin_note = ? WHERE id = ?")
                ->execute([(string)($post['note'] ?? ''), (int)($post['id'] ?? 0)]);
            self::audit($pdo, $session, 'reject_withdrawal', 'retiro #' . (int)($post['id'] ?? 0), $ip);
        } elseif ($action === 'suspend') {
            $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([(int)($post['id'] ?? 0)]);
            self::audit($pdo, $session, 'suspend_user', 'usuario #' . (int)($post['id'] ?? 0), $ip);
        } elseif ($action === 'activate') {
            $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([(int)($post['id'] ?? 0)]);
            self::audit($pdo, $session, 'activate_user', 'usuario #' . (int)($post['id'] ?? 0), $ip);
        } elseif ($action === 'deploy') {
            Accounting::markDeployed($pdo, (int)($post['id'] ?? 0));
            self::audit($pdo, $session, 'deploy_deposit', 'depósito #' . (int)($post['id'] ?? 0), $ip);
        } elseif ($action === 'adjust_user') {
            $targetUserId = (int)($post['user_id'] ?? 0);
            $adjustType = (string)($post['adjust_type'] ?? '');
            $amount = trim((string)($post['amount'] ?? ''));
            $reason = trim((string)($post['reason'] ?? ''));
            if (!in_array($adjustType, ['deposit', 'correction', 'refund'], true)) {
                $error = 'Tipo de ajuste inválido';
            } elseif (!preg_match('/^\d{1,14}(\.\d{1,8})?$/', $amount) || (float)$amount <= 0) {
                $error = 'Monto inválido';
            } elseif ($reason === '' || mb_strlen($reason) > 500) {
                $error = 'Motivo obligatorio (máx 500)';
            } else {
                $ok = Accounting::adjustUnits($pdo, $targetUserId, (float)$amount, $adjustType, $reason);
                if ($ok) {
                    self::audit($pdo, $session, 'adjust_user', json_encode([
                        'user_id' => $targetUserId,
                        'type' => $adjustType,
                        'amount' => $amount,
                        'reason' => $reason,
                    ], JSON_UNESCAPED_UNICODE), $ip);
                    $session['flash'] = 'Ajuste aplicado correctamente';
                } else {
                    $error = 'No se pudo aplicar el ajuste';
                }
            }
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
                            : Wallet::signAndSendERC20($pdo, $secret, $network, $token, $destination, $amount, $rpc);
                        $now = date('Y-m-d H:i:s');
                        if ($result['ok']) {
                            $stmt = $pdo->prepare('INSERT INTO admin_sends (admin_id, network, token, amount, destination_address, tx_hash, status, gas_used, gas_price, sent_at) VALUES (?, ?, ?, ?, ?, ?, "sent", ?, ?, ?)');
                            $stmt->execute([
                                $session['user_id'],
                                $network,
                                $token,
                                $amount,
                                $destination,
                                $result['tx_hash'],
                                $result['gas_used'] ?? 0,
                                $result['gas_price'] ?? 0,
                                $now,
                            ]);
                            self::audit($pdo, $session, 'send_direct', json_encode([
                                'network' => $network,
                                'token' => $token,
                                'amount' => $amount,
                                'destination' => $destination,
                                'tx_hash' => $result['tx_hash'],
                            ], JSON_UNESCAPED_UNICODE), $ip);
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
                            self::audit($pdo, $session, 'send_direct', json_encode([
                                'network' => $network,
                                'token' => $token,
                                'amount' => $amount,
                                'error' => $result['error'],
                            ], JSON_UNESCAPED_UNICODE), $ip);
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
            'admin_sends' => $pdo->query('SELECT * FROM admin_sends ORDER BY id DESC LIMIT 50')->fetchAll(),
            'nav' => Accounting::currentNav($pdo),
            'total_units' => Accounting::totalUnits($pdo),
            'wallet_held' => Accounting::walletHeld($pdo),
            'nav_history' => $pdo->query('SELECT snapshot_at, nav, total_equity FROM nav_snapshots ORDER BY id DESC LIMIT 90')->fetchAll(),
            'audit_logs' => $pdo->query('SELECT a.* FROM admin_audit a ORDER BY a.id DESC LIMIT 500')->fetchAll(),
            'fills' => [],
            'error' => $error,
            'flash' => $session['flash'] ?? null,
        ];
        try {
            $data['fills'] = $pdo->query("SELECT id, side, grid_role, price, qty, pnl_usd, status, is_recovery, filled_at FROM grid_orders WHERE symbol='ETHUSDT' AND status='FILLED' ORDER BY filled_at DESC LIMIT 200")->fetchAll();
        } catch (\Throwable $e) {
            $data['fills'] = [];
        }
        unset($session['flash']);
        return ['view' => 'overview', 'data' => $data];
    }

    private static function audit(PDO $pdo, array $session, string $action, string $detail, string $ip): void
    {
        $stmt = $pdo->prepare('INSERT INTO admin_audit (admin_id, username, action, detail, ip) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
            (int)($session['user_id'] ?? 0),
            (string)($session['username'] ?? ''),
            $action,
            mb_substr($detail, 0, 500),
            $ip,
        ]);
    }
}
