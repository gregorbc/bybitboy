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

    public function testEnableTwoFactorReturnsSecretAndQr(): void
    {
        $session = ['user_id' => 1, 'role' => 'investor', 'username' => 'juan'];
        $session['csrf'] = Csrf::token($session);
        $out = InvestorHttp::handle($this->pdo, $session, [], ['action' => 'enable_2fa', 'csrf' => $session['csrf']], self::SECRET);
        $this->assertArrayHasKey('two_factor', $out['data']);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $out['data']['two_factor']['secret']);
        $this->assertStringContainsString('api.qrserver.com', $out['data']['two_factor']['qr']);
        $this->assertSame($out['data']['two_factor']['secret'], $session['pending_2fa_secret']);
    }

    public function testConfirmTwoFactorActivatesIt(): void
    {
        $session = ['user_id' => 1, 'role' => 'investor', 'username' => 'juan'];
        $session['csrf'] = Csrf::token($session);
        InvestorHttp::handle($this->pdo, $session, [], ['action' => 'enable_2fa', 'csrf' => $session['csrf']], self::SECRET);
        $secret = $session['pending_2fa_secret'];
        $code = \OTPHP\TOTP::create($secret)->now();
        $out2 = InvestorHttp::handle($this->pdo, $session, [], ['action' => 'confirm_2fa', 'code' => $code, 'csrf' => $session['csrf']], self::SECRET);
        $this->assertSame('2FA activada correctamente', $out2['data']['flash'] ?? $out2['flash'] ?? '');
        $row = $this->pdo->query('SELECT totp_enabled, totp_secret FROM users WHERE id = 1')->fetch();
        $this->assertSame(1, (int)$row['totp_enabled']);
        $this->assertSame($secret, $row['totp_secret']);
        $this->assertArrayNotHasKey('pending_2fa_secret', $session);
    }

    public function testConfirmTwoFactorWrongCodeDoesNotActivate(): void
    {
        $session = ['user_id' => 1, 'role' => 'investor', 'username' => 'juan'];
        $session['csrf'] = Csrf::token($session);
        InvestorHttp::handle($this->pdo, $session, [], ['action' => 'enable_2fa', 'csrf' => $session['csrf']], self::SECRET);
        $out2 = InvestorHttp::handle($this->pdo, $session, [], ['action' => 'confirm_2fa', 'code' => '000000', 'csrf' => $session['csrf']], self::SECRET);
        $this->assertSame('Código incorrecto', $out2['data']['error'] ?? $out2['error'] ?? '');
        $row = $this->pdo->query('SELECT totp_enabled FROM users WHERE id = 1')->fetch();
        $this->assertSame(0, (int)$row['totp_enabled']);
    }

    public function testDisableTwoFactorRequiresCode(): void
    {
        $this->pdo->exec('UPDATE users SET totp_enabled = 1, totp_secret = "ABCDEFGHIJKLMNOP" WHERE id = 1');
        $session = ['user_id' => 1, 'role' => 'investor', 'username' => 'juan'];
        $session['csrf'] = Csrf::token($session);
        $code = \OTPHP\TOTP::create('ABCDEFGHIJKLMNOP')->now();
        $out = InvestorHttp::handle($this->pdo, $session, [], ['action' => 'disable_2fa', 'code' => $code, 'csrf' => $session['csrf']], self::SECRET);
        $this->assertSame('2FA desactivada', $out['data']['flash'] ?? $out['flash'] ?? '');
        $row = $this->pdo->query('SELECT totp_enabled FROM users WHERE id = 1')->fetch();
        $this->assertSame(0, (int)$row['totp_enabled']);
    }
}