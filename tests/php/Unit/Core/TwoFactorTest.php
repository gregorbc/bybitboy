<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\TwoFactor;

class TwoFactorTest extends TestCase
{
    public function testGenerateSecretReturnsBase32OfExpectedLength(): void
    {
        $secret = TwoFactor::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertGreaterThanOrEqual(16, strlen($secret));
    }

    public function testVerifyAcceptsCurrentTOTPCode(): void
    {
        $secret = TwoFactor::generateSecret();
        $code = \OTPHP\TOTP::create($secret)->now();
        $this->assertTrue(TwoFactor::verify($code, $secret));
    }

    public function testVerifyRejectsWrongCode(): void
    {
        $secret = TwoFactor::generateSecret();
        $this->assertFalse(TwoFactor::verify('000000', $secret));
    }

    public function testVerifyRejectsEmptyInput(): void
    {
        $this->assertFalse(TwoFactor::verify('', 'ABCDEFGHIJKLMNOP'));
    }

    public function testOtpauthUriContainsAccountAndIssuer(): void
    {
        $uri = TwoFactor::otpauthUri('ABCDEFGHIJKLMNOP', 'admin@grid.com', 'Grid Bot');
        $this->assertStringContainsString('otpauth://totp/', $uri);
        $this->assertStringContainsString('issuer=Grid%20Bot', $uri);
        $this->assertStringContainsString('secret=ABCDEFGHIJKLMNOP', $uri);
    }
}
