<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\AuthHttp;
use BinanceBot\Core\Csrf;
use Tests\Support\SqliteSchema;

class AuthHttpTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
    }

    public function testRegisterSetsSessionAndRedirects(): void
    {
        $session = [];
        $post = ['action' => 'register', 'username' => 'juan', 'email' => 'j@e.com', 'password' => 'secreto123', 'csrf' => Csrf::token($session)];
        $out = AuthHttp::handle($this->pdo, $session, [], $post, '1.2.3.4');
        $this->assertSame('panel.php', $out['redirect']);
        $this->assertSame('juan', $session['username']);
        $this->assertSame('investor', $session['role']);
    }

    public function testLoginWithoutCsrfRejected(): void
    {
        $session = [];
        $out = AuthHttp::handle($this->pdo, $session, [], ['action' => 'login', 'username' => 'x', 'password' => 'y'], '1.2.3.4');
        $this->assertSame('Token CSRF inválido', $out['error']);
    }

    public function testGetActionWithoutCsrfNotRejected(): void
    {
        $session = [];
        $out = AuthHttp::handle($this->pdo, $session, ['action' => 'register'], [], '1.2.3.4');
        $this->assertNotSame('Token CSRF inválido', $out['error']);
        $this->assertSame('register', $out['view']);
    }

    public function testLoginWrongCredentialsShowsError(): void
    {
        $session = [];
        $post = ['action' => 'login', 'username' => 'nobody', 'password' => 'x', 'csrf' => Csrf::token($session)];
        $out = AuthHttp::handle($this->pdo, $session, [], $post, '1.2.3.4');
        $this->assertSame('Usuario o contraseña incorrectos', $out['error']);
    }

    public function testAdminLoginRedirectsToDashboard(): void
    {
        $this->pdo->prepare("INSERT INTO users (username, email, password_hash, role, status) VALUES (?, ?, ?, 'admin', 'active')")
            ->execute(['boss', 'boss@e.com', password_hash('secreto123', PASSWORD_BCRYPT)]);
        $session = [];
        $post = ['action' => 'login', 'username' => 'boss', 'password' => 'secreto123', 'csrf' => Csrf::token($session)];
        $out = AuthHttp::handle($this->pdo, $session, [], $post, '1.2.3.4');
        $this->assertSame('src/php/index.php', $out['redirect']);
        $this->assertSame('admin', $session['role']);
    }

    public function testInvestorLoginRedirectsToPanel(): void
    {
        $this->pdo->prepare("INSERT INTO users (username, email, password_hash, role, status) VALUES (?, ?, ?, 'investor', 'active')")
            ->execute(['inversor', 'inv@e.com', password_hash('secreto123', PASSWORD_BCRYPT)]);
        $session = [];
        $post = ['action' => 'login', 'username' => 'inversor', 'password' => 'secreto123', 'csrf' => Csrf::token($session)];
        $out = AuthHttp::handle($this->pdo, $session, [], $post, '1.2.3.4');
        $this->assertSame('panel.php', $out['redirect']);
        $this->assertSame('investor', $session['role']);
    }

    public function testLoginWithTwoFactorRequiresVerificationStep(): void
    {
        $secret = \BinanceBot\Core\TwoFactor::generateSecret();
        $this->pdo->prepare("INSERT INTO users (username, email, password_hash, role, status, totp_secret, totp_enabled) VALUES (?, ?, ?, 'admin', 'active', ?, 1)")
            ->execute(['boss', 'boss@e.com', password_hash('secreto123', PASSWORD_BCRYPT), $secret]);
        $session = [];
        $post = ['action' => 'login', 'username' => 'boss', 'password' => 'secreto123', 'csrf' => Csrf::token($session)];
        $out = AuthHttp::handle($this->pdo, $session, [], $post, '1.2.3.4');
        $this->assertSame('2fa', $out['view']);
        $this->assertArrayNotHasKey('user_id', $session);
        $this->assertSame('boss', $session['pending_2fa']['username']);
    }

    public function testVerifyTwoFactorCompletesLogin(): void
    {
        $secret = \BinanceBot\Core\TwoFactor::generateSecret();
        $this->pdo->prepare("INSERT INTO users (username, email, password_hash, role, status, totp_secret, totp_enabled) VALUES (?, ?, ?, 'admin', 'active', ?, 1)")
            ->execute(['boss', 'boss@e.com', password_hash('secreto123', PASSWORD_BCRYPT), $secret]);
        $userId = (int)$this->pdo->lastInsertId();
        $session = ['pending_2fa' => ['user_id' => $userId, 'username' => 'boss', 'role' => 'admin', 'redirect' => 'src/php/index.php']];
        $code = \OTPHP\TOTP::create($secret)->now();
        $post = ['action' => 'verify_2fa', 'code' => $code, 'csrf' => Csrf::token($session)];
        $out = AuthHttp::handle($this->pdo, $session, [], $post, '1.2.3.4');
        $this->assertSame('src/php/index.php', $out['redirect']);
        $this->assertSame($userId, $session['user_id']);
        $this->assertArrayNotHasKey('pending_2fa', $session);
        $row = $this->pdo->query('SELECT resultado FROM logs_acceso ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame('exitoso', $row['resultado']);
    }

    public function testVerifyTwoFactorWrongCodeShowsError(): void
    {
        $secret = \BinanceBot\Core\TwoFactor::generateSecret();
        $this->pdo->prepare("INSERT INTO users (username, email, password_hash, role, status, totp_secret, totp_enabled) VALUES (?, ?, ?, 'admin', 'active', ?, 1)")
            ->execute(['boss', 'boss@e.com', password_hash('secreto123', PASSWORD_BCRYPT), $secret]);
        $userId = (int)$this->pdo->lastInsertId();
        $session = ['pending_2fa' => ['user_id' => $userId, 'username' => 'boss', 'role' => 'admin', 'redirect' => 'src/php/index.php']];
        $post = ['action' => 'verify_2fa', 'code' => '000000', 'csrf' => Csrf::token($session)];
        $out = AuthHttp::handle($this->pdo, $session, [], $post, '1.2.3.4');
        $this->assertSame('Código 2FA incorrecto', $out['error']);
        $this->assertArrayNotHasKey('user_id', $session);
        $row = $this->pdo->query('SELECT resultado FROM logs_acceso ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame('fallido', $row['resultado']);
    }
}