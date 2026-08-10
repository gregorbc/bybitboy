<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Accounting;
use BinanceBot\Core\Csrf;
use BinanceBot\Core\InvestorHttp;
use BinanceBot\Core\Wallet;
use Tests\Support\SqliteSchema;

class InvestorHttpTest extends TestCase
{
    private \PDO $pdo;
    private const SECRET = 'test-secret';

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
        $this->pdo->exec("INSERT INTO users (id, username, email, password_hash, role) VALUES (1, 'juan', 'j@e.com', 'x', 'investor')");
        Accounting::init($this->pdo, 100000.0);
        Wallet::init($this->pdo, self::SECRET);
    }

    public function testPanelRequiresLogin(): void
    {
        $session = [];
        $out = InvestorHttp::handle($this->pdo, $session, [], [], self::SECRET);
        $this->assertSame('login', $out['view']);
    }

    public function testPanelShowsEquityAndAddress(): void
    {
        $session = ['user_id' => 1, 'role' => 'investor', 'csrf' => bin2hex(random_bytes(32))];
        $out = InvestorHttp::handle($this->pdo, $session, [], [], self::SECRET);
        $this->assertSame('panel', $out['view']);
        $this->assertArrayHasKey('equity', $out['data']);
        $this->assertArrayHasKey('addresses', $out['data']);
        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{40}$/', $out['data']['addresses']['eth']);
    }

    public function testWithdrawalRequestCreatesPending(): void
    {
        $session = ['user_id' => 1, 'role' => 'investor'];
        $this->pdo->prepare('INSERT INTO deposits (user_id, network, token, tx_hash, block_number, amount, status) VALUES (1, "eth", "USDT", "0x1", 1, 1000, "credited")')->execute();
        $this->pdo->prepare('INSERT INTO shares (user_id, units) VALUES (1, 1000)')->execute();
        $post = ['action' => 'withdraw', 'network' => 'eth', 'token' => 'USDT', 'amount' => '100', 'destination' => '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B', 'csrf' => Csrf::token($session)];
        $out = InvestorHttp::handle($this->pdo, $session, [], $post, self::SECRET);
        $this->assertArrayHasKey('withdrawal_id', $out['data']);
        $row = $this->pdo->query('SELECT * FROM withdrawals')->fetch();
        $this->assertSame('pending', $row['status']);
    }

    public function testChangePasswordWrongCurrentFails(): void
    {
        $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE id = 1")
            ->execute([password_hash('vieja-pass', PASSWORD_BCRYPT)]);
        $session = ['user_id' => 1, 'role' => 'investor'];
        $post = ['action' => 'change_password', 'current_password' => 'mal', 'new_password' => 'nueva-pass', 'csrf' => Csrf::token($session)];
        $out = InvestorHttp::handle($this->pdo, $session, [], $post, self::SECRET);
        $this->assertStringContainsString('Contraseña actual incorrecta', $out['data']['error'] ?? '');
    }

    public function testChangePasswordOk(): void
    {
        $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE id = 1")
            ->execute([password_hash('vieja-pass', PASSWORD_BCRYPT)]);
        $session = ['user_id' => 1, 'role' => 'investor'];
        $post = ['action' => 'change_password', 'current_password' => 'vieja-pass', 'new_password' => 'nueva-pass', 'csrf' => Csrf::token($session)];
        $out = InvestorHttp::handle($this->pdo, $session, [], $post, self::SECRET);
        $this->assertNull($out['data']['error']);
        $hash = $this->pdo->query('SELECT password_hash FROM users WHERE id = 1')->fetch()['password_hash'];
        $this->assertTrue(password_verify('nueva-pass', $hash));
    }

    public function testUpdateProfileChangesEmail(): void
    {
        $session = ['user_id' => 1, 'role' => 'investor'];
        $post = ['action' => 'update_profile', 'email' => 'nuevo@e.com', 'csrf' => Csrf::token($session)];
        $out = InvestorHttp::handle($this->pdo, $session, [], $post, self::SECRET);
        $this->assertNull($out['data']['error']);
        $this->assertSame('nuevo@e.com', $this->pdo->query('SELECT email FROM users WHERE id = 1')->fetch()['email']);
    }

    public function testPanelIncludesGrowthData(): void
    {
        $session = ['user_id' => 1, 'role' => 'investor'];
        $out = InvestorHttp::handle($this->pdo, $session, [], [], self::SECRET);
        $this->assertArrayHasKey('growth_pct', $out['data']);
        $this->assertArrayHasKey('equity_history', $out['data']);
        $this->assertArrayHasKey('email', $out['data']);
    }
}