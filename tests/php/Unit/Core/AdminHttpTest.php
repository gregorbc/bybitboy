<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PDO;
use BinanceBot\Core\Accounting;
use BinanceBot\Core\AdminHttp;
use BinanceBot\Core\Csrf;
use BinanceBot\Core\RpcClient;
use BinanceBot\Core\Wallet;
use Tests\Support\SqliteSchema;

class AdminHttpTest extends TestCase
{
    protected ?PDO $pdo = null;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
        $this->pdo->exec("INSERT INTO users (id, username, email, password_hash, role) VALUES (1, 'admin', 'a@e.com', 'x', 'admin'), (2, 'inv', 'i@e.com', 'x', 'investor')");
        Accounting::init($this->pdo, 100000.0);
        putenv('PLATFORM_SECRET=test_secret');
    }

    protected function tearDown(): void
    {
        putenv('PLATFORM_SECRET');
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

    public function testSendDirectSuccess(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $session['csrf'] = Csrf::token($session);
        $post = [
            'action' => 'send_direct',
            'network' => 'bsc',
            'token' => 'USDT',
            'destination' => '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B',
            'amount' => '10.0',
            'confirm' => '1',
            'csrf' => $session['csrf'],
        ];
        // Inject fake sender instead of mocking Wallet (overload: breaks the class globally)
        $sendDirect = static function (): array {
            return ['ok' => true, 'tx_hash' => '0x' . str_repeat('ab', 32), 'gas_used' => 50000, 'gas_price' => 20000000000];
        };

        $result = AdminHttp::handle($this->pdo, $session, $post, $sendDirect);
        $this->assertSame('overview', $result['view']);
        $row = $this->pdo->query("SELECT * FROM admin_sends")->fetch();
        $this->assertSame('bsc', $row['network']);
        $this->assertSame('USDT', $row['token']);
        $this->assertSame(10.0, (float)$row['amount']);
        $this->assertSame('sent', $row['status']);
    }

    public function testSendDirectMissingConfirm(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $session['csrf'] = Csrf::token($session);
        $post = [
            'action' => 'send_direct',
            'network' => 'bsc',
            'token' => 'USDT',
            'destination' => '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B',
            'amount' => '10.0',
            'csrf' => $session['csrf'],
        ];
        $result = AdminHttp::handle($this->pdo, $session, $post);
        $this->assertSame('overview', $result['view']);
        $this->assertStringContainsString('confirm', strtolower($result['data']['error'] ?? ''));
    }

    public function testSendDirectInvalidAddress(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $session['csrf'] = Csrf::token($session);
        $post = [
            'action' => 'send_direct',
            'network' => 'bsc',
            'token' => 'USDT',
            'destination' => 'direccion_invalida',
            'amount' => '10.0',
            'confirm' => '1',
            'csrf' => $session['csrf'],
        ];
        $result = AdminHttp::handle($this->pdo, $session, $post);
        $this->assertSame('overview', $result['view']);
        $this->assertStringContainsString('inválida', strtolower($result['data']['error'] ?? ''));
    }

    public function testEstimateGasSuccess(): void
    {
        Wallet::init($this->pdo, 'test_secret');
        $fakeRpc = new RpcClient('http://fake', function (string $url, string $payload): string {
            $req = json_decode($payload, true);
            if ($req['method'] === 'eth_call') {
                return '{"jsonrpc":"2.0","id":1,"result":"0x56bc75e2d63100000"}'; // 100 USDT
            }
            if ($req['method'] === 'eth_estimateGas') {
                return '{"jsonrpc":"2.0","id":1,"result":"0x5208"}'; // 21000
            }
            if ($req['method'] === 'eth_gasPrice') {
                return '{"jsonrpc":"2.0","id":1,"result":"0x3b9aca00"}'; // 1 gwei
            }
            return '{"jsonrpc":"2.0","id":1,"result":null}';
        });
        $result = AdminHttp::estimateGas($this->pdo, 'bsc', 'USDT', '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B', '10.0', 'test_secret', $fakeRpc);
        $this->assertTrue($result['ok']);
        $this->assertSame(25200, $result['gas_limit']);      // 21000 * 1.2
        $this->assertSame(1100000000, $result['gas_price']); // 1 gwei * 1.1
        $this->assertSame('0.00002772', $result['estimated_cost_native']);
    }

    public function testEstimateGasInvalidToken(): void
    {
        Wallet::init($this->pdo, 'test_secret');
        $result = AdminHttp::estimateGas($this->pdo, 'bsc', 'DOGE', '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B', '10.0', 'test_secret');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('soportado', strtolower($result['error']));
    }

    public function testAdjustUserCreditsSharesAndAudits(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin', 'username' => 'admin'];
        $session['csrf'] = Csrf::token($session);
        $post = [
            'action' => 'adjust_user',
            'user_id' => '2',
            'adjust_type' => 'deposit',
            'amount' => '250.00',
            'reason' => 'depósito manual verificado',
            'csrf' => $session['csrf'],
        ];
        $out = AdminHttp::handle($this->pdo, $session, $post);
        $this->assertSame('overview', $out['view']);
        $units = $this->pdo->query('SELECT units FROM shares WHERE user_id = 2')->fetch();
        $this->assertSame(250.0, (float)$units['units']);
        $mov = $this->pdo->query('SELECT * FROM movements WHERE user_id = 2 AND type = "adjust"')->fetch();
        $this->assertSame('depósito manual verificado', $mov['note']);
        $audit = $this->pdo->query('SELECT * FROM admin_audit WHERE action = "adjust_user"')->fetch();
        $this->assertSame(1, (int)$audit['admin_id']);
        $this->assertSame('admin', $audit['username']);
    }

    public function testAdjustUserRejectsInvalidAmount(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $session['csrf'] = Csrf::token($session);
        $post = ['action' => 'adjust_user', 'user_id' => '2', 'adjust_type' => 'refund', 'amount' => 'abc', 'reason' => 'x', 'csrf' => $session['csrf']];
        $out = AdminHttp::handle($this->pdo, $session, $post);
        $this->assertStringContainsString('Monto inválido', $out['data']['error'] ?? '');
        $this->assertFalse($this->pdo->query('SELECT id FROM admin_audit')->fetch());
    }

    public function testAdjustUserRejectsBadCsrf(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $post = ['action' => 'adjust_user', 'user_id' => '2', 'adjust_type' => 'deposit', 'amount' => '10', 'reason' => 'x', 'csrf' => 'wrong'];
        AdminHttp::handle($this->pdo, $session, $post);
        $this->assertFalse($this->pdo->query('SELECT id FROM admin_audit')->fetch());
    }

    public function testApproveWithdrawalWritesAudit(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $this->pdo->exec("INSERT INTO withdrawals (user_id, network, token, amount, units_to_burn, destination_address) VALUES (2, 'eth', 'USDT', 100, 100, '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B')");
        $wdId = (int)$this->pdo->query('SELECT id FROM withdrawals')->fetch()['id'];
        $post = ['action' => 'approve', 'id' => (string)$wdId, 'csrf' => Csrf::token($session)];
        AdminHttp::handle($this->pdo, $session, $post);
        $audit = $this->pdo->query('SELECT * FROM admin_audit WHERE action = "approve_withdrawal"')->fetch();
        $this->assertStringContainsString((string)$wdId, (string)$audit['detail']);
    }

    public function testOverviewIncludesNewDataKeys(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $out = AdminHttp::handle($this->pdo, $session, []);
        $this->assertArrayHasKey('nav_history', $out['data']);
        $this->assertArrayHasKey('audit_logs', $out['data']);
        $this->assertArrayHasKey('fills', $out['data']);
        $this->assertIsArray($out['data']['fills']);
    }

    public function testEnableTwoFactorReturnsSecretAndQr(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin', 'username' => 'admin'];
        $session['csrf'] = Csrf::token($session);
        $out = AdminHttp::handle($this->pdo, $session, ['action' => 'enable_2fa', 'csrf' => $session['csrf']]);
        $this->assertArrayHasKey('two_factor', $out['data']);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $out['data']['two_factor']['secret']);
        $this->assertStringContainsString('api.qrserver.com', $out['data']['two_factor']['qr']);
    }

    public function testConfirmTwoFactorActivatesIt(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin', 'username' => 'admin'];
        $session['csrf'] = Csrf::token($session);
        $out = AdminHttp::handle($this->pdo, $session, ['action' => 'enable_2fa', 'csrf' => $session['csrf']]);
        $secret = $out['data']['two_factor']['secret'];
        $code = \OTPHP\TOTP::create($secret)->now();
        $out2 = AdminHttp::handle($this->pdo, $session, ['action' => 'confirm_2fa', 'code' => $code, 'csrf' => $session['csrf']]);
        $this->assertSame('2FA activada correctamente', $out2['data']['flash'] ?? $out2['flash'] ?? '');
        $row = $this->pdo->query('SELECT totp_enabled, totp_secret FROM users WHERE id = 1')->fetch();
        $this->assertSame(1, (int)$row['totp_enabled']);
        $this->assertSame($secret, $row['totp_secret']);
    }

    public function testConfirmTwoFactorWrongCodeDoesNotActivate(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin', 'username' => 'admin'];
        $session['csrf'] = Csrf::token($session);
        AdminHttp::handle($this->pdo, $session, ['action' => 'enable_2fa', 'csrf' => $session['csrf']]);
        $out2 = AdminHttp::handle($this->pdo, $session, ['action' => 'confirm_2fa', 'code' => '000000', 'csrf' => $session['csrf']]);
        $this->assertSame('Código incorrecto', $out2['data']['error'] ?? $out2['error'] ?? '');
        $row = $this->pdo->query('SELECT totp_enabled FROM users WHERE id = 1')->fetch();
        $this->assertSame(0, (int)$row['totp_enabled']);
    }

    public function testDisableTwoFactorRequiresCode(): void
    {
        $this->pdo->exec('UPDATE users SET totp_enabled = 1, totp_secret = "ABCDEFGHIJKLMNOP" WHERE id = 1');
        $session = ['user_id' => 1, 'role' => 'admin', 'username' => 'admin'];
        $session['csrf'] = Csrf::token($session);
        $code = \OTPHP\TOTP::create('ABCDEFGHIJKLMNOP')->now();
        $out = AdminHttp::handle($this->pdo, $session, ['action' => 'disable_2fa', 'code' => $code, 'csrf' => $session['csrf']]);
        $this->assertSame('2FA desactivada', $out['data']['flash'] ?? $out['flash'] ?? '');
        $row = $this->pdo->query('SELECT totp_enabled FROM users WHERE id = 1')->fetch();
        $this->assertSame(0, (int)$row['totp_enabled']);
    }
}