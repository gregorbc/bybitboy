<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Csrf;

class CsrfTest extends TestCase
{
    public function testTokenIsStablePerSession(): void
    {
        $session = [];
        $a = Csrf::token($session);
        $b = Csrf::token($session);
        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a));
    }

    public function testVerify(): void
    {
        $session = [];
        $token = Csrf::token($session);
        $this->assertTrue(Csrf::verify($session, $token));
        $this->assertFalse(Csrf::verify($session, 'otro'));
        $this->assertFalse(Csrf::verify($session, null));
    }
}
