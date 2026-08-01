<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Accounting;
use BinanceBot\Core\AdminHttp;
use BinanceBot\Core\Csrf;
use BinanceBot\Core\Wallet;
use Tests\Support\SqliteSchema;

class AdminHttpTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
        $this->pdo->exec("INSERT INTO users (id, username, email, password_hash, role) VALUES (1, 'admin', 'a@e.com', 'x', 'admin'), (2, 'inv', 'i@e.com', 'x', 'investor')");
        Accounting::init($this->pdo, 100000.0);
    }

    public function testAdminOnly(): void
    {
        $session = ['user_id' => 2, 'role' => 'investor'];
        $out = AdminHttp::handle($this->pdo, $session, []);
        $this->assertSame('forbidden', $out['view']);
    }

    public function testOverviewShowsFundState(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $out = AdminHttp::handle($this->pdo, $session, []);
        $this->assertSame('overview', $out['view']);
        $this->assertArrayHasKey('users', $out['data']);
        $this->assertArrayHasKey('pending_withdrawals', $out['data']);
        $this->assertArrayHasKey('deposits', $out['data']);
        $this->assertSame(1.0, $out['data']['nav']);
    }

    public function testApproveWithdrawalAction(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $this->pdo->exec("INSERT INTO withdrawals (user_id, network, token, amount, units_to_burn, destination_address) VALUES (2, 'eth', 'USDT', 100, 100, '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B')");
        $wdId = (int)$this->pdo->query('SELECT id FROM withdrawals')->fetch()['id'];
        $post = ['action' => 'approve', 'id' => (string)$wdId, 'csrf' => Csrf::token($session)];
        AdminHttp::handle($this->pdo, $session, $post);
        $this->assertSame('approved', $this->pdo->query('SELECT status FROM withdrawals')->fetch()['status']);
    }

    public function testMarkSentAction(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $this->pdo->exec("INSERT INTO withdrawals (user_id, network, token, amount, units_to_burn, destination_address, status) VALUES (2, 'eth', 'USDT', 100, 100, '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B', 'approved')");
        $wdId = (int)$this->pdo->query('SELECT id FROM withdrawals')->fetch()['id'];
        $post = ['action' => 'sent', 'id' => (string)$wdId, 'tx_hash' => '0x' . str_repeat('ab', 32), 'csrf' => Csrf::token($session)];
        AdminHttp::handle($this->pdo, $session, $post);
        $this->assertSame('sent', $this->pdo->query('SELECT status FROM withdrawals')->fetch()['status']);
    }

    public function testRejectWithdrawalAction(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $this->pdo->exec("INSERT INTO withdrawals (user_id, network, token, amount, units_to_burn, destination_address) VALUES (2, 'eth', 'USDT', 100, 100, '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B')");
        $wdId = (int)$this->pdo->query('SELECT id FROM withdrawals')->fetch()['id'];
        $post = ['action' => 'reject', 'id' => (string)$wdId, 'note' => 'sin fondos', 'csrf' => Csrf::token($session)];
        AdminHttp::handle($this->pdo, $session, $post);
        $row = $this->pdo->query('SELECT * FROM withdrawals')->fetch();
        $this->assertSame('rejected', $row['status']);
        $this->assertSame('sin fondos', $row['admin_note']);
    }

    public function testSuspendUserAction(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $post = ['action' => 'suspend', 'id' => '2', 'csrf' => Csrf::token($session)];
        AdminHttp::handle($this->pdo, $session, $post);
        $this->assertSame('suspended', $this->pdo->query('SELECT status FROM users WHERE id = 2')->fetch()['status']);
    }

    public function testMarkDeployedAction(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $this->pdo->exec("INSERT INTO deposits (user_id, network, token, tx_hash, block_number, amount, status) VALUES (2, 'eth', 'USDT', '0x1', 1, 500, 'credited')");
        $depId = (int)$this->pdo->query('SELECT id FROM deposits')->fetch()['id'];
        $post = ['action' => 'deploy', 'id' => (string)$depId, 'csrf' => Csrf::token($session)];
        AdminHttp::handle($this->pdo, $session, $post);
        $this->assertSame(1, $this->pdo->query('SELECT deployed FROM deposits')->fetch()['deployed']);
    }
}