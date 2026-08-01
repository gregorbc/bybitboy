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

        if ($action === 'withdraw') {
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
        }

        $addresses = [];
        foreach (array_keys(Networks::all()) as $network) {
            try {
                $addresses[$network] = Wallet::getDepositAddress($pdo, $userId, $network, $secret);
            } catch (\Throwable $e) {
                $addresses[$network] = null;
            }
        }

        $stmt = $pdo->prepare('SELECT * FROM withdrawals WHERE user_id = ? ORDER BY id DESC LIMIT 20');
        $stmt->execute([$userId]);
        $withdrawals = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM movements WHERE user_id = ? ORDER BY id DESC LIMIT 50');
        $stmt->execute([$userId]);
        $movements = $stmt->fetchAll();

        $stmt = $pdo->prepare('SELECT * FROM deposits WHERE user_id = ? ORDER BY id DESC LIMIT 20');
        $stmt->execute([$userId]);
        $deposits = $stmt->fetchAll();

        $data = [
            'equity' => Accounting::userEquity($pdo, $userId),
            'units' => Accounting::userUnits($pdo, $userId),
            'nav' => Accounting::currentNav($pdo),
            'addresses' => $addresses,
            'withdrawals' => $withdrawals,
            'movements' => $movements,
            'deposits' => $deposits,
            'error' => $error,
            'flash' => $session['flash'] ?? null,
            'networks' => array_keys(Networks::all()),
        ];
        if ($withdrawalId !== null) {
            $data['withdrawal_id'] = $withdrawalId;
        }
        unset($session['flash']);
        return ['view' => 'panel', 'data' => $data];
    }
}