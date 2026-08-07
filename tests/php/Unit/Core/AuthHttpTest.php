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
}