<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\AdminHttp;
use BinanceBot\Core\Wallet;
use BinanceBot\Core\RpcClient;
use BinanceBot\Core\Csrf;
use Tests\Support\SqliteSchema;

class AdminDirectSendTest extends TestCase
{
    private \PDO $pdo;
    private const SECRET = 'integration-secret';
    private const DEST_ADDR = '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B';

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
        $this->pdo->exec("INSERT INTO users (id, username, email, password_hash, role) VALUES (1, 'admin', 'a@e.com', 'x', 'admin')");
        Wallet::init($this->pdo, self::SECRET);
        putenv('PLATFORM_SECRET=' . self::SECRET);
    }

    protected function tearDown(): void
    {
        putenv('PLATFORM_SECRET');
    }

    public function testFullDirectSendFlow(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $session['csrf'] = Csrf::token($session);
        $post = [
            'action' => 'send_direct',
            'network' => 'bsc',
            'token' => 'USDT',
            'destination' => self::DEST_ADDR,
            'amount' => '10.0',
            'confirm' => '1',
            'csrf' => $session['csrf'],
        ];

        $fakeRpc = new RpcClient('http://fake', function (string $url, string $payload) {
            $req = json_decode($payload, true);
            if ($req['method'] === 'eth_getTransactionCount') return '{"jsonrpc":"2.0","id":1,"result":"0x0"}';
            if ($req['method'] === 'eth_gasPrice') return '{"jsonrpc":"2.0","id":1,"result":"0x3b9aca00"}';
            if ($req['method'] === 'eth_call') return '{"jsonrpc":"2.0","id":1,"result":"0x56bc75e2d63100000"}'; // 100 tokens
            if ($req['method'] === 'eth_estimateGas') return '{"jsonrpc":"2.0","id":1,"result":"0x5208"}';
            if ($req['method'] === 'eth_sendRawTransaction') return '{"jsonrpc":"2.0","id":1,"result":"0xabcdef1234567890"}';
            return '{"jsonrpc":"2.0","id":1,"result":null}';
        });

        $result = AdminHttp::handle($this->pdo, $session, $post, null, $fakeRpc);

        $this->assertSame('overview', $result['view']);
        $this->assertStringContainsString('0xabcdef1234567890', $result['data']['flash'] ?? '');

        $row = $this->pdo->query("SELECT * FROM admin_sends")->fetch();
        $this->assertSame('bsc', $row['network']);
        $this->assertSame('USDT', $row['token']);
        $this->assertSame(10.0, (float)$row['amount']);
        $this->assertSame(self::DEST_ADDR, $row['destination_address']);
        $this->assertSame('sent', $row['status']);
        $this->assertSame('0xabcdef1234567890', $row['tx_hash']);
        $this->assertSame(25200, (int)$row['gas_used']);
        $this->assertSame(1100000000, (int)$row['gas_price']);
    }
}
