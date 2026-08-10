<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;
use BinanceBot\Exchange\BybitFutures;

class AdminHttp
{
    public static function estimateGas(PDO $pdo, string $network, string $token, string $destination, string $amount, string $secret, ?RpcClient $rpc = null): array
    {
        return Wallet::estimateGas($pdo, $secret, $network, $token, $destination, $amount, $rpc);
    }

    public static function handle(PDO $pdo, array &$session, array $post, ?callable $sendDirect = null, ?RpcClient $rpc = null, ?BybitFutures $client = null): array
    {
        if (empty($session['user_id']) || ($session['role'] ?? '') !== 'admin') {
            return ['view' => 'forbidden', 'data' => []];
        }
        $action = (string)($post['action'] ?? '');
        $error = null;
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $twoFactorData = null;
        $reconciliacion = null;
        $modelos = null;
        $logsView = null;

        if ($action !== '' && !Csrf::verify($session, isset($post['csrf']) ? (string)$post['csrf'] : null)) {
            $error = 'Token CSRF inválido';
        } elseif ($action === 'enable_2fa') {
            $secret = TwoFactor::generateSecret();
            $session['pending_2fa_secret'] = $secret;
            $uri = TwoFactor::otpauthUri($secret, (string)($session['username'] ?? ''), 'Grid Bot');
            $twoFactorData = [
                'secret' => $secret,
                'qr' => 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($uri),
            ];
        } elseif ($action === 'confirm_2fa') {
            $secret = (string)($session['pending_2fa_secret'] ?? '');
            if ($secret === '' || !TwoFactor::verify((string)($post['code'] ?? ''), $secret)) {
                $error = 'Código incorrecto';
            } else {
                $stmt = $pdo->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?');
                $stmt->execute([$secret, (int)$session['user_id']]);
                unset($session['pending_2fa_secret']);
                $session['flash'] = '2FA activada correctamente';
            }
        } elseif ($action === 'disable_2fa') {
            $stmt = $pdo->prepare('SELECT totp_secret FROM users WHERE id = ?');
            $stmt->execute([(int)$session['user_id']]);
            $dbSecret = (string)($stmt->fetchColumn() ?: '');
            if (!TwoFactor::verify((string)($post['code'] ?? ''), $dbSecret)) {
                $error = 'Código incorrecto';
            } else {
                $stmt = $pdo->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?');
                $stmt->execute([(int)$session['user_id']]);
                $session['flash'] = '2FA desactivada';
            }
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
        } elseif ($action === 'alerta_save') {
            $tipos = ['drawdown_pct', 'daily_loss_pct', 'distancia_liquidacion_pct', 'saldo_min_usd'];
            $tipo = (string)($post['tipo'] ?? '');
            if (!in_array($tipo, $tipos, true)) {
                $error = 'Tipo de alerta no válido';
            } else {
                $umbral = (float)($post['umbral'] ?? 0);
                $habilitado = isset($post['habilitado']) && (int)$post['habilitado'] === 1 ? 1 : 0;
                $chatId = mb_substr((string)($post['telegram_chat_id'] ?? ''), 0, 50);
                $intervalo = max(1, (int)($post['intervalo_min'] ?? 30));
                $adminId = (int)$session['user_id'];
                $exists = $pdo->prepare('SELECT COUNT(*) FROM alertas_config WHERE tipo = ?');
                $exists->execute([$tipo]);
                if ((int)$exists->fetchColumn() > 0) {
                    $pdo->prepare('UPDATE alertas_config SET umbral=?, habilitado=?, telegram_chat_id=?, intervalo_min=?, actualizado_por=? WHERE tipo=?')
                        ->execute([$umbral, $habilitado, $chatId, $intervalo, $adminId, $tipo]);
                } else {
                    $pdo->prepare('INSERT INTO alertas_config (tipo, umbral, habilitado, telegram_chat_id, intervalo_min, actualizado_por) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([$tipo, $umbral, $habilitado, $chatId, $intervalo, $adminId]);
                }
                $session['flash'] = 'Alerta guardada';
            }
        } elseif ($action === 'alerta_delete') {
            $pdo->prepare('DELETE FROM alertas_config WHERE id = ?')->execute([(int)($post['id'] ?? 0)]);
            $session['flash'] = 'Alerta eliminada';
        } elseif ($action === 'set_telegram_token') {
            $token = mb_substr((string)($post['token'] ?? ''), 0, 200);
            if ($token === '') {
                $error = 'Token vacío';
            } else {
                $now = date('Y-m-d H:i:s');
                try {
                    $pdo->prepare('INSERT INTO bot_meta (meta_key, meta_value, updated_at) VALUES (?, ?, ?)')
                        ->execute(['telegram_bot_token', $token, $now]);
                } catch (\Throwable $e) {
                    $pdo->prepare('UPDATE bot_meta SET meta_value = ?, updated_at = ? WHERE meta_key = ?')
                        ->execute([$token, $now, 'telegram_bot_token']);
                }
                $session['flash'] = 'Token Telegram guardado';
            }
        } elseif ($action === 'test_telegram') {
            $token = self::botMeta($pdo, 'telegram_bot_token');
            $chatId = (string)($post['chat_id'] ?? '');
            $ok = false;
            try {
                $ok = Notification::sendTelegram($token, $chatId, 'Prueba de alerta desde el panel de Grid Bot');
            } catch (\Throwable $e) {
                $ok = false;
            }
            if ($ok) {
                $session['flash'] = 'Mensaje de prueba enviado';
            } else {
                $error = 'No se pudo enviar el mensaje de prueba';
            }
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
        } elseif ($action === 'reconciliar') {
            try {
                $cfg = Config::getInstance();
                $client = $client ?? new BybitFutures(
                    (string)$cfg->get('bybit.api_key', ''),
                    (string)$cfg->get('bybit.api_secret', ''),
                    (bool)$cfg->get('bybit.testnet', false),
                    (string)$cfg->get('bybit.environment', '') ?: null
                );
                $reconciliacion = Reconciliation::reconcile($pdo, $client, (string)$cfg->get('bot.symbol', 'ETHUSDT'));
            } catch (\Throwable $e) {
                $error = 'No se pudo reconciliar con el exchange: ' . $e->getMessage();
            }
        } elseif ($action === 'modelos_list') {
            $modelosArchivos = [];
            $dir = dirname(__DIR__, 3) . '/data/models';
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') as $f) {
                    $modelosArchivos[] = [
                        'archivo' => basename($f),
                        'tamano' => (int)@filesize($f),
                        'modificado' => date('Y-m-d H:i:s', (int)@filemtime($f)),
                    ];
                }
            }
            $historial = '';
            $hfile = dirname(__DIR__, 3) . '/config/trainer_history.json';
            if (is_file($hfile)) {
                $historial = (string)file_get_contents($hfile);
            }
            $precision = null;
            $pfile = dirname(__DIR__, 3) . '/config/volatility_weights.json';
            if (is_file($pfile)) {
                $precision = json_decode((string)file_get_contents($pfile), true);
            }
            $modelos = [
                'modelos' => $modelosArchivos,
                'historial' => $historial,
                'precision' => $precision,
                'ml_accuracy' => Config::getInstance()->get('ml.min_accuracy'),
            ];
        } elseif ($action === 'logs_ia') {
            $logsView = self::paginate($pdo, 'logs_ia', $post);
        } elseif ($action === 'logs_acceso') {
            $logsView = self::paginate($pdo, 'logs_acceso', $post);
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
            'alertas' => $pdo->query('SELECT * FROM alertas_config ORDER BY tipo')->fetchAll(),
            'telegram_token' => self::botMeta($pdo, 'telegram_bot_token'),
            'filas' => $logsView['filas'] ?? [],
            'total' => $logsView['total'] ?? 0,
            'paginas' => $logsView['paginas'] ?? 1,
            'pagina' => $logsView['pagina'] ?? 1,
            'log_view' => $logsView['view'] ?? null,
            'reconciliacion' => $reconciliacion,
            'modelos' => $modelos,
            'error' => $error,
            'flash' => $session['flash'] ?? null,
            '2fa_enabled' => 0,
        ];
        if ($twoFactorData !== null) {
            $data['two_factor'] = $twoFactorData;
        }
        try {
            $data['fills'] = $pdo->query("SELECT id, side, grid_role, price, qty, pnl_usd, status, is_recovery, filled_at FROM grid_orders WHERE symbol='ETHUSDT' AND status='FILLED' ORDER BY filled_at DESC LIMIT 200")->fetchAll();
        } catch (\Throwable $e) {
            $data['fills'] = [];
        }
        try {
            $stmt = $pdo->prepare('SELECT totp_enabled FROM users WHERE id = ?');
            $stmt->execute([(int)$session['user_id']]);
            $data['2fa_enabled'] = (int)($stmt->fetchColumn() ?: 0) === 1 ? 1 : 0;
        } catch (\Throwable $e) {
            $data['2fa_enabled'] = 0;
        }
        unset($session['flash']);
        return ['view' => 'overview', 'data' => $data];
    }

    private static function paginate(PDO $pdo, string $table, array $post): array
    {
        $pagina = max(1, (int)($post['pagina'] ?? 1));
        $por = min(100, max(10, (int)($post['por_pagina'] ?? 25)));
        $total = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        $paginas = max(1, (int)ceil($total / $por));
        $off = ($pagina - 1) * $por;
        $stmt = $pdo->prepare("SELECT * FROM {$table} ORDER BY fecha DESC LIMIT ? OFFSET ?");
        $stmt->execute([$por, $off]);
        return [
            'view' => $table,
            'filas' => $stmt->fetchAll(),
            'total' => $total,
            'paginas' => $paginas,
            'pagina' => $pagina,
        ];
    }

    private static function botMeta(PDO $pdo, string $metaKey): string
    {
        $stmt = $pdo->prepare('SELECT meta_value FROM bot_meta WHERE meta_key = ?');
        $stmt->execute([$metaKey]);
        return (string)($stmt->fetchColumn() ?: '');
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
