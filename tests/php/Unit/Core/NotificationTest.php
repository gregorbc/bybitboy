<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Notification;

class NotificationTest extends TestCase
{
    public function testSendTelegramReturnsFalseOnEmptyChatId(): void
    {
        $this->assertFalse(Notification::sendTelegram('token', '', 'hola'));
    }

    public function testSendTelegramReturnsFalseOnEmptyToken(): void
    {
        $this->assertFalse(Notification::sendTelegram('', '123', 'hola'));
    }
}
