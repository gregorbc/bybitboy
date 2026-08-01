<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\DepositScanner;
use BinanceBot\Core\RpcClient;
use BinanceBot\Core\Networks;
use Tests\Support\SqliteSchema;

class DepositScannerTest extends TestCase
{
    private \PDO $pdo;
    private const USDT = '0x55d398326f99059fF775485246999027B3197955';
    private const USER_ADDR = '0xab5801a7d398351b8be11c439e05c5b3259aec9b';
    private const USER2_ADDR = '0xbb5801a7d398351b8be11c439e05c5b3259aec9b';
    private const TX_HASH = '0x' . 'abababababababababababababababababababab';

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
        $this->pdo->exec("INSERT INTO users (username, email, password_hash) VALUES ('u1', 'u1@e.com', 'x'), ('u2', 'u2@e.com', 'x')");
        $this->pdo->exec("INSERT INTO deposit_addresses (user_id, network, address, derivation_index) VALUES (1, 'bsc', '" . self::USER_ADDR . "', 0), (2, 'bsc', '" . self::USER2_ADDR . "', 1)");
    }

    private function scanner(): DepositScanner
    {
        return new DepositScanner($this->pdo, new RpcClient('http://fake', fn() => '{"jsonrpc":"2.0","id":1,"result":[]}'), 'bsc', 15, ['USDT' => self::USDT], 1.0);
    }

    public function testPadAddress(): void
    {
        $padded = DepositScanner::padAddress(self::USER_ADDR);
        $this->assertSame(66, strlen($padded));
        $this->assertSame('0x' . str_repeat('0', 24) . substr(self::USER_ADDR, 2), $padded);
        $this->assertSame(self::USER_ADDR, DepositScanner::unpadAddress($padded));
    }

    public function testParseAmount(): void
    {
        // 0x91b77e5e5d9a0000 = 10.5 * 10^18 (corrected hex for 10.5)
        $this->assertSame(10.5, DepositScanner::parseAmount('0x' . str_pad('91b77e5e5d9a0000', 64, '0', STR_PAD_LEFT)));
        $this->assertSame(0.0, DepositScanner::parseAmount('0x0'));
    }

    public function testTickInsertsNewDeposit(): void
    {
        $fakeRpc = new RpcClient('http://fake', function (string $url, string $payload) {
            $req = json_decode($payload, true);
            if ($req['method'] === 'eth_blockNumber') {
                return '{"jsonrpc":"2.0","id":1,"result":"0x1000"}';
            }
            if ($req['method'] === 'eth_getLogs') {
                return '{"jsonrpc":"2.0","id":1,"result":[' . $this->logFor('0x1010') . ']}';
            }
            return '{"jsonrpc":"2.0","id":1,"result":null}';
        });
        $scanner = new DepositScanner($this->pdo, $fakeRpc, 'bsc', 15, ['USDT' => self::USDT], 1.0);
        $out = $scanner->tick();
        $this->assertSame(1, $out['inserted']);
        $row = $this->pdo->query('SELECT * FROM deposits')->fetch();
        $this->assertSame('pending', $row['status']);
        $this->assertSame(10.0, (float)$row['amount']);
        $this->assertSame(1, (int)$row['user_id']);
    }

    public function testTickDedupesSameTx(): void
    {
        $fakeRpc = new RpcClient('http://fake', function (string $url, string $payload) {
            $req = json_decode($payload, true);
            if ($req['method'] === 'eth_blockNumber') return '{"jsonrpc":"2.0","id":1,"result":"0x1000"}';
            if ($req['method'] === 'eth_getLogs') return '{"jsonrpc":"2.0","id":1,"result":[' . $this->logFor('0x1010') . ']}';
            return '{"jsonrpc":"2.0","id":1,"result":null}';
        });
        $scanner = new DepositScanner($this->pdo, $fakeRpc, 'bsc', 15, ['USDT' => self::USDT], 1.0);
        $scanner->tick();
        $out = $scanner->tick();
        $this->assertSame(0, $out['inserted']);
        $this->assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) c FROM deposits')->fetch()['c']);
    }

    public function testTickIgnoresDust(): void
    {
        $fakeRpc = new RpcClient('http://fake', function (string $url, string $payload) {
            $req = json_decode($payload, true);
            if ($req['method'] === 'eth_blockNumber') return '{"jsonrpc":"2.0","id":1,"result":"0x1000"}';
            // 0.5 tokens = 500000000000000000 wei (below minAmount 1.0)
            if ($req['method'] === 'eth_getLogs') return '{"jsonrpc":"2.0","id":1,"result":[' . $this->logForDust('0x1010') . ']}';
            return '{"jsonrpc":"2.0","id":1,"result":null}';
        });
        $scanner = new DepositScanner($this->pdo, $fakeRpc, 'bsc', 15, ['USDT' => self::USDT], 1.0);
        $out = $scanner->tick();
        $this->assertSame(0, $out['inserted']);
    }

    public function testProcessPendingCreditsAfterConfirmations(): void
    {
        $this->pdo->exec("INSERT INTO deposits (user_id, network, token, tx_hash, block_number, amount, status) VALUES (1, 'bsc', 'USDT', '0x1', 0xfa0, 100, 'pending')");
        AccountingTestSeed::initNav($this->pdo);
        $fakeRpc = new RpcClient('http://fake', function (string $url, string $payload) {
            $req = json_decode($payload, true);
            if ($req['method'] === 'eth_blockNumber') return '{"jsonrpc":"2.0","id":1,"result":"0x1000"}';
            if ($req['method'] === 'eth_getTransactionReceipt') return '{"jsonrpc":"2.0","id":1,"result":{"status":"0x1"}}';
            return '{"jsonrpc":"2.0","id":1,"result":null}';
        });
        $scanner = new DepositScanner($this->pdo, $fakeRpc, 'bsc', 15, ['USDT' => self::USDT], 1.0);
        $out = $scanner->processPending();
        $this->assertSame(1, $out['credited']);
        $this->assertSame('credited', $this->pdo->query('SELECT status FROM deposits WHERE tx_hash = "0x1"')->fetch()['status']);
        $this->assertSame(100.0, AccountingTestSeed::userUnits($this->pdo, 1));
    }

    public function testProcessPendingMarksRevertedAsFailed(): void
    {
        $this->pdo->exec("INSERT INTO deposits (user_id, network, token, tx_hash, block_number, amount, status) VALUES (1, 'bsc', 'USDT', '0x2', 0xfa0, 100, 'pending')");
        $fakeRpc = new RpcClient('http://fake', function (string $url, string $payload) {
            $req = json_decode($payload, true);
            if ($req['method'] === 'eth_blockNumber') return '{"jsonrpc":"2.0","id":1,"result":"0x1000"}';
            if ($req['method'] === 'eth_getTransactionReceipt') return '{"jsonrpc":"2.0","id":1,"result":{"status":"0x0"}}';
            return '{"jsonrpc":"2.0","id":1,"result":null}';
        });
        $scanner = new DepositScanner($this->pdo, $fakeRpc, 'bsc', 15, ['USDT' => self::USDT], 1.0);
        $out = $scanner->processPending();
        $this->assertSame(0, $out['credited']);
        $this->assertSame(1, $out['failed']);
        $this->assertSame('failed', $this->pdo->query('SELECT status FROM deposits WHERE tx_hash = "0x2"')->fetch()['status']);
    }

    private function logFor(string $blockHex): string
    {
        // 10.0 tokens = 10000000000000000000 wei = 0x8ac7230489e80000
        return json_encode([
            'address' => self::USDT,
            'blockNumber' => $blockHex,
            'transactionHash' => self::TX_HASH,
            'topics' => [Networks::TRANSFER_TOPIC0, '0x' . str_repeat('0', 64), DepositScanner::padAddress(self::USER_ADDR)],
            'data' => '0x8ac7230489e80000',
        ]);
    }

    private function logForDust(string $blockHex): string
    {
        // 0.5 tokens = 500000000000000000 wei = 0x6fc100000000000
        return json_encode([
            'address' => self::USDT,
            'blockNumber' => $blockHex,
            'transactionHash' => '0x' . bin2hex(random_bytes(20)),
            'topics' => [Networks::TRANSFER_TOPIC0, '0x' . str_repeat('0', 64), DepositScanner::padAddress(self::USER_ADDR)],
            'data' => '0x0000000000000000000000000000000000000000000000006fc100000000000',
        ]);
    }
}

final class AccountingTestSeed
{
    public static function initNav(\PDO $pdo): void
    {
        $pdo->exec("INSERT INTO bot_meta (meta_key, meta_value) VALUES ('owner_units', '100000')");
        $pdo->exec('INSERT INTO nav_snapshots (total_equity, total_units, nav, bot_pnl_total) VALUES (100000, 100000, 1.0, 0)');
    }

    public static function userUnits(\PDO $pdo, int $userId): float
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(units), 0) AS u FROM shares WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (float)$stmt->fetch()['u'];
    }
}