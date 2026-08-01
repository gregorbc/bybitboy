<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class AdminHttp
{
    public static function handle(PDO $pdo, array &$session, array $post): array
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