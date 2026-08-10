<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use OTPHP\TOTP;

/**
 * Envoltorio de OTPHP para 2FA TOTP (RFC 6238).
 * Compatible con Google Authenticator, Authy, 1Password, etc.
 */
class TwoFactor
{
    public static function generateSecret(): string
    {
        return TOTP::generate()->getSecret();
    }

    public static function otpauthUri(string $secret, string $account, string $issuer = 'Grid Bot'): string
    {
        $otp = TOTP::create($secret);
        $otp->setIssuer($issuer);
        $otp->setLabel($account);
        return $otp->getProvisioningUri();
    }

    public static function verify(string $code, string $secret): bool
    {
        if ($code === '' || $secret === '') {
            return false;
        }
        $current = TOTP::create($secret)->now();
        return hash_equals($current, trim($code));
    }
}
