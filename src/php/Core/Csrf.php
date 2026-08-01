<?php
declare(strict_types=1);

namespace BinanceBot\Core;

class Csrf
{
    public static function token(array &$session): string
    {
        if (empty($session['csrf'])) {
            $session['csrf'] = bin2hex(random_bytes(32));
        }
        return $session['csrf'];
    }

    public static function verify(array &$session, ?string $token): bool
    {
        return is_string($token) && !empty($session['csrf']) && hash_equals($session['csrf'], $token);
    }
}
