<?php
declare(strict_types=1);

namespace BinanceBot\Core;

/**
 * Envío de notificaciones vía Telegram (Bot API).
 */
class Notification
{
    public static function sendTelegram(string $token, string $chatId, string $text): bool
    {
        if ($token === '' || $chatId === '') {
            return false;
        }
        $payload = json_encode([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
        $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false || $code !== 200) {
            return false;
        }
        $data = json_decode((string)$res, true);
        return isset($data['ok']) && $data['ok'] === true;
    }
}
