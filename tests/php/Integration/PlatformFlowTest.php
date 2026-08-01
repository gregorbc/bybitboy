<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Accounting;
use BinanceBot\Core\Auth;
use BinanceBot\Core\DepositScanner;
use BinanceBot\Core\RpcClient;
use BinanceBot\Core\Wallet;
use BinanceBot\Core\Networks;
use Tests\Support\SqliteSchema;

class PlatformFlowTest extends TestCase
{
    private \PDO $pdo;
    private const SECRET = 'integration-secret';
    private const USDT = '0x55d398326f99059fF775485246999027B3197955';
    private const ADDR = '0xab5801a7d398351b8be11c439e05c5b3259aec9b';

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
    }

    public function testFullInvestorJourney(): void
    {
        // 1. Init
        Accounting::init($this->pdo, 100000.0);
        Wallet::init($this->pdo, self::SECRET);
        // 2. Register user + deposit address
        Auth::register($this->pdo, 'juan', 'j@e.com', 'secreto123');
        $userId = (int)$this->pdo->query('SELECT id FROM users')->fetch()['id'];
        $address = Wallet::getDepositAddress($this->pdo, $userId, 'bsc', self::SECRET);
        // 3. Scanner detects transfer and credits after confirmations
        $fakeRpc = new RpcClient('http://fake', function (string $url, string $payload) use ($address) {
            $req = json_decode($payload, true);
            if ($req['method'] === 'eth_blockNumber') return '{"jsonrpc":"2.0","id":1,"result":"0x1000"}';
            if ($req['method'] === 'eth_getLogs') return '{"jsonrpc":"2.0","id":1,"result":[' . $this->logFor($address) . ']}';
            if ($req['method'] === 'eth_getTransactionReceipt') return '{"jsonrpc":"2.0","id":1,"result":{"status":"0x1"}}';
            return '{"jsonrpc":"2.0","id":1,"result":null}';
        });
        $scanner = new DepositScanner($this->pdo, $fakeRpc, 'bsc', 15, ['USDT' => self::USDT], 1.0);
        $scanner->tick();
        $scanner->processPending();
        $this->assertSame('credited', $this->pdo->query('SELECT status FROM deposits')->fetch()['status']);
        $this->assertEqualsWithDelta(100.0, Accounting::userEquity($this->pdo, $userId), 0.01);
        // 4. Nav growth
        Accounting::updateNav($this->pdo, 110110.0, 0.0, 10110.0);
        $this->assertEqualsWithDelta(110.0, Accounting::userEquity($this->pdo, $userId), 0.01);
        // 5. Withdrawal request + approve
        $wd = Accounting::requestWithdrawal($this->pdo, $userId, 'bsc', 'USDT', 55.0, self::ADDR, 10.0);
        $this->assertTrue($wd['ok']);
        Accounting::approveWithdrawal($this->pdo, $wd['withdrawal_id']);
        $this->assertEqualsWithDelta(50.0, Accounting::userUnits($this->pdo, $userId), 0.01);
    }

    private function logFor(string $address): string
    {
        return json_encode([
            'address' => self::USDT,
            'blockNumber' => '0xfa0',
            'transactionHash' => '0x' . bin2hex(random_bytes(20)),
            'topics' => [Networks::TRANSFER_TOPIC0, '0x' . str_repeat('0', 64), DepositScanner::padAddress($address)],
            'data' => '0x' . str_pad('56bc75e2d63100000', 64, '0', STR_PAD_LEFT),
        ]);
    }
}