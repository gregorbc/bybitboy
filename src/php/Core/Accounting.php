<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class Accounting
{
    public static function init(PDO $pdo, float $ownerCapital): void
    {
        $stmt = $pdo->query("SELECT meta_value FROM bot_meta WHERE meta_key = 'owner_units'");
        if ($stmt->fetch()) {
            return;
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO bot_meta (meta_key, meta_value) VALUES ('owner_units', ?)");
            $stmt->execute([(string)$ownerCapital]);
            self::snapshot($pdo, $ownerCapital, $ownerCapital, 1.0, 0.0);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function ownerUnits(PDO $pdo): float
    {
        $stmt = $pdo->query("SELECT meta_value FROM bot_meta WHERE meta_key = 'owner_units'");
        $row = $stmt->fetch();
        return $row ? (float)$row['meta_value'] : 0.0;
    }

    public static function totalUnits(PDO $pdo): float
    {
        $stmt = $pdo->query('SELECT COALESCE(SUM(units), 0) AS t FROM shares');
        return (float)$stmt->fetch()['t'] + self::ownerUnits($pdo);
    }

    public static function currentNav(PDO $pdo): float
    {
        $stmt = $pdo->query('SELECT nav FROM nav_snapshots ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch();
        return $row ? (float)$row['nav'] : 1.0;
    }

    public static function userUnits(PDO $pdo, int $userId): float
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(units), 0) AS u FROM shares WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (float)$stmt->fetch()['u'];
    }

    public static function userEquity(PDO $pdo, int $userId): float
    {
        return round(self::userUnits($pdo, $userId) * self::currentNav($pdo), 8);
    }

    public static function creditDeposit(PDO $pdo, int $depositId): void
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM deposits WHERE id = ?');
            $stmt->execute([$depositId]);
            $dep = $stmt->fetch();
            if (!$dep || $dep['status'] !== 'pending') {
                $pdo->rollBack();
                return;
            }
            $nav = self::currentNav($pdo);
            $units = round((float)$dep['amount'] / $nav, 8);
            $pdo->prepare('INSERT INTO shares (user_id, units) VALUES (?, ?)')->execute([$dep['user_id'], $units]);
            $pdo->prepare("UPDATE deposits SET status = 'credited', credited_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $depositId]);
            self::addMovement($pdo, (int)$dep['user_id'], 'deposit', (float)$dep['amount'], $units, $nav);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function requestWithdrawal(PDO $pdo, int $userId, string $network, string $token, float $amount, string $destination, float $minAmount): array
    {
        if ($amount < $minAmount) {
            return ['ok' => false, 'error' => 'El monto es menor al mínimo permitido'];
        }
        $nav = self::currentNav($pdo);
        $units = round($amount / $nav, 8);
        if ($units > self::userUnits($pdo, $userId)) {
            return ['ok' => false, 'error' => 'Saldo insuficiente'];
        }
        $stmt = $pdo->prepare('INSERT INTO withdrawals (user_id, network, token, amount, units_to_burn, destination_address) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $network, $token, $amount, $units, $destination]);
        return ['ok' => true, 'withdrawal_id' => (int)$pdo->lastInsertId()];
    }

    public static function approveWithdrawal(PDO $pdo, int $withdrawalId): array
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM withdrawals WHERE id = ?');
            $stmt->execute([$withdrawalId]);
            $w = $stmt->fetch();
            if (!$w || $w['status'] !== 'pending') {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'El retiro no está pendiente'];
            }
            $nav = self::currentNav($pdo);
            $units = round((float)$w['units_to_burn'], 8);
            $available = self::userUnits($pdo, (int)$w['user_id']);
            $burn = min($units, $available);
            if ($burn > 0) {
                $pdo->prepare('INSERT INTO shares (user_id, units) VALUES (?, ?)')->execute([(int)$w['user_id'], -$burn]);
            }
            $pdo->prepare("UPDATE withdrawals SET status = 'approved', processed_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $withdrawalId]);
            self::addMovement($pdo, (int)$w['user_id'], 'withdrawal', -(float)$w['amount'], -$burn, $nav);
            $pdo->commit();
            return ['ok' => true];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function markSent(PDO $pdo, int $withdrawalId, string $txHash): void
    {
        $pdo->prepare("UPDATE withdrawals SET status = 'sent', tx_hash = ? WHERE id = ?")->execute([$txHash, $withdrawalId]);
    }

    public static function markDeployed(PDO $pdo, int $depositId): void
    {
        $pdo->prepare('UPDATE deposits SET deployed = 1 WHERE id = ?')->execute([$depositId]);
    }

    public static function adjustUnits(PDO $pdo, int $userId, float $amountUsd, string $type, string $reason = ''): bool
    {
        $nav = self::currentNav($pdo);
        $units = round($amountUsd / $nav, 8);
        if ($units <= 0) {
            return false;
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO shares (user_id, units) VALUES (?, ?)')->execute([$userId, $units]);
            self::addMovement($pdo, $userId, 'adjust', $amountUsd, $units, $nav, $reason);
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return false;
        }
    }

    public static function updateNav(PDO $pdo, float $realBalance, float $walletHeld, float $botPnlTotal): void
    {
        $pdo->beginTransaction();
        try {
            $units = self::totalUnits($pdo);
            $nav = $units > 0 ? round(($realBalance + $walletHeld) / $units, 8) : 1.0;
            self::snapshot($pdo, $realBalance + $walletHeld, $units, $nav, $botPnlTotal);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function walletHeld(PDO $pdo): float
    {
        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) AS t FROM deposits WHERE status = 'credited' AND deployed = 0");
        return (float)$stmt->fetch()['t'];
    }

    public static function pendingWithdrawals(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT w.*, u.username FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.status = 'pending' ORDER BY w.id");
        return $stmt->fetchAll();
    }

    private static function addMovement(PDO $pdo, int $userId, string $type, float $amount, float $units, float $nav, string $note = ''): void
    {
        $balanceAfter = round(self::userUnits($pdo, $userId) * $nav, 8);
        $stmt = $pdo->prepare('INSERT INTO movements (user_id, type, amount, units, nav, balance_after, note) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $type, $amount, $units, $nav, $balanceAfter, $note]);
    }

    private static function snapshot(PDO $pdo, float $equity, float $units, float $nav, float $pnl): void
    {
        $stmt = $pdo->prepare('INSERT INTO nav_snapshots (total_equity, total_units, nav, bot_pnl_total) VALUES (?, ?, ?, ?)');
        $stmt->execute([$equity, $units, $nav, $pnl]);
    }
}