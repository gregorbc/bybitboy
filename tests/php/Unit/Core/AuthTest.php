<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Auth;
use Tests\Support\SqliteSchema;

class AuthTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
    }

    public function testRegisterCreatesUser(): void
    {
        $res = Auth::register($this->pdo, 'juan', 'juan@example.com', 'secreto123');
        $this->assertTrue($res['ok']);
        $this->assertGreaterThan(0, $res['user_id']);
        $row = $this->pdo->query('SELECT * FROM users')->fetch();
        $this->assertSame('juan', $row['username']);
        $this->assertNotSame('secreto123', $row['password_hash']);
        $this->assertTrue(password_verify('secreto123', $row['password_hash']));
    }

    public function testRegisterRejectsDuplicate(): void
    {
        Auth::register($this->pdo, 'juan', 'juan@example.com', 'secreto123');
        $res = Auth::register($this->pdo, 'juan', 'otro@example.com', 'secreto123');
        $this->assertFalse($res['ok']);
    }

    public function testRegisterValidatesPassword(): void
    {
        $res = Auth::register($this->pdo, 'juan', 'juan@example.com', 'corta');
        $this->assertFalse($res['ok']);
    }

    public function testLoginOk(): void
    {
        Auth::register($this->pdo, 'juan', 'juan@example.com', 'secreto123');
        $user = Auth::login($this->pdo, 'juan', 'secreto123');
        $this->assertNotNull($user);
        $this->assertSame('juan', $user['username']);
    }

    public function testLoginWrongPassword(): void
    {
        Auth::register($this->pdo, 'juan', 'juan@example.com', 'secreto123');
        $this->assertNull(Auth::login($this->pdo, 'juan', 'mala'));
    }

    public function testLoginSuspendedUserBlocked(): void
    {
        Auth::register($this->pdo, 'juan', 'juan@example.com', 'secreto123');
        $this->pdo->exec("UPDATE users SET status='suspended' WHERE username='juan'");
        $this->assertNull(Auth::login($this->pdo, 'juan', 'secreto123'));
    }

    public function testRateLimitBlocksAfterMaxFailures(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Auth::recordAttempt($this->pdo, '1.2.3.4', 'login', 'x', false);
        }
        $this->assertFalse(Auth::checkRateLimit($this->pdo, '1.2.3.4', 'login', 3, 900));
        $this->assertTrue(Auth::checkRateLimit($this->pdo, '1.2.3.4', 'login', 5, 900));
    }
}
