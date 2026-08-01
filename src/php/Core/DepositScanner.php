<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;
use BinanceBot\Core\RpcClient;
use BinanceBot\Core\Networks;
use BinanceBot\Core\Accounting;

class DepositScanner
{
    public const RANGE_LIMIT = 5000;

    /** @param array{USDT:string,USDC:string} $contracts */
    public function __construct(
        private PDO $pdo,
        private RpcClient $rpc,
        private string $network,
        private int $confirmations,
        private array $contracts,
        private float $minAmount = 1.0,
    ) {
    }

    public static function padAddress(string $address): string
    {
        return '0x' . str_pad(strtolower(ltrim($address, '0x')), 64, '0', STR_PAD_LEFT);
    }

    /** @param list<string> $addresses */
    public static function topicAddresses(array $addresses): array
    {
        return array_map([self::class, 'padAddress'], $addresses);
    }

    public static function unpadAddress(string $topic): string
    {
        return '0x' . substr($topic, 26);
    }

    public static function parseAmount(string $hexData): float
    {
        $hex = ltrim(ltrim($hexData, '0x'), '0') ?: '0';
        $dec = '0';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $dec = bcadd(bcmul($dec, '16', 0), (string)hexdec($hex[$i]), 0);
        }
        return (float)bcdiv($dec, (string)(10 ** Networks::DECIMALS), 8);
    }

    public function tick(): array
    {
        $head = $this->rpc->blockNumber();
        $state = $this->getState();
        $from = $state > 0 ? $state + 1 : max(0, $head - self::RANGE_LIMIT);
        if ($from > $head) {
            return ['head' => $head, 'from' => $from, 'to' => $from, 'logs' => 0, 'inserted' => 0];
        }
        $to = min($from + self::RANGE_LIMIT - 1, $head);
        $addresses = $this->activeAddresses();
        $logs = [];
        if ($addresses) {
            $logs = $this->rpc->getLogs(
                '0x' . dechex($from),
                '0x' . dechex($to),
                array_values($this->contracts),
                Networks::TRANSFER_TOPIC0,
                self::topicAddresses($addresses),
            );
        }
        $inserted = 0;
        foreach ($logs as $log) {
            if ($this->insertLog($log)) {
                $inserted++;
            }
        }
        $this->setState($to);
        return ['head' => $head, 'from' => $from, 'to' => $to, 'logs' => count($logs), 'inserted' => $inserted];
    }

    public function processPending(): array
    {
        $head = $this->rpc->blockNumber();
        $stmt = $this->pdo->prepare("SELECT * FROM deposits WHERE network = ? AND status = 'pending'");
        $stmt->execute([$this->network]);
        $credited = 0;
        $failed = 0;
        foreach ($stmt->fetchAll() as $dep) {
            $confirmations = $head - (int)$dep['block_number'];
            if ($confirmations < 0) {
                continue;
            }
            $this->pdo->prepare('UPDATE deposits SET confirmations = ? WHERE id = ?')->execute([$confirmations, $dep['id']]);
            if ($confirmations >= $this->confirmations) {
                $receipt = $this->rpc->transactionReceipt($dep['tx_hash']);
                if ($receipt !== null && strtolower((string)($receipt['status'] ?? '0x1')) === '0x0') {
                    $this->pdo->prepare("UPDATE deposits SET status = 'failed' WHERE id = ?")->execute([$dep['id']]);
                    $failed++;
                    continue;
                }
                Accounting::creditDeposit($this->pdo, (int)$dep['id']);
                $credited++;
            }
        }
        return ['head' => $head, 'credited' => $credited, 'failed' => $failed];
    }

    private function insertLog(array $log): bool
    {
        $txHash = (string)($log['transactionHash'] ?? '');
        if ($txHash === '') {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT id FROM deposits WHERE tx_hash = ?');
        $stmt->execute([$txHash]);
        if ($stmt->fetch()) {
            return false;
        }
        $amount = self::parseAmount((string)($log['data'] ?? '0x0'));
        if ($amount < $this->minAmount) {
            return false;
        }
        $token = $this->tokenFor((string)($log['address'] ?? ''));
        $to = self::unpadAddress((string)($log['topics'][2] ?? ''));
        $user = $this->userForAddress($to);
        if ($token === '' || !$user) {
            return false;
        }
        $stmt = $this->pdo->prepare('INSERT INTO deposits (user_id, network, token, tx_hash, block_number, amount, confirmations) VALUES (?, ?, ?, ?, ?, ?, 0)');
        $stmt->execute([$user['id'], $this->network, $token, $txHash, (int)hexdec((string)($log['blockNumber'] ?? '0x0')), $amount]);
        return true;
    }

    private function tokenFor(string $contract): string
    {
        $lower = strtolower($contract);
        foreach ($this->contracts as $token => $addr) {
            if (strtolower((string)$addr) === $lower) {
                return $token;
            }
        }
        return '';
    }

    private function userForAddress(string $address): ?array
    {
        $stmt = $this->pdo->prepare("SELECT u.* FROM deposit_addresses a JOIN users u ON u.id = a.user_id WHERE a.address = ? AND a.status = 'active' AND u.status = 'active'");
        $stmt->execute([strtolower($address)]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<string> */
    private function activeAddresses(): array
    {
        $stmt = $this->pdo->query("SELECT address FROM deposit_addresses WHERE status = 'active'");
        return array_map(static fn(array $r): string => strtolower((string)$r['address']), $stmt->fetchAll());
    }

    private function getState(): int
    {
        $stmt = $this->pdo->prepare('SELECT last_block FROM scan_state WHERE network = ?');
        $stmt->execute([$this->network]);
        return (int)($stmt->fetch()['last_block'] ?? 0);
    }

    private function setState(int $block): void
    {
        // Portable upsert: works on both SQLite and MySQL
        $stmt = $this->pdo->prepare('UPDATE scan_state SET last_block = ? WHERE network = ?');
        $stmt->execute([$block, $this->network]);
        if ($stmt->rowCount() === 0) {
            $stmt = $this->pdo->prepare('INSERT INTO scan_state (network, last_block) VALUES (?, ?)');
            $stmt->execute([$this->network, $block]);
        }
        /* MySQL variant (for production):
        $stmt = $this->pdo->prepare('INSERT INTO scan_state (network, last_block) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_block = VALUES(last_block)');
        $stmt->execute([$this->network, $block]);
        */
    }
}