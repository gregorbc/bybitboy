<?php
declare(strict_types=1);

namespace BinanceBot\Dashboard;

use BinanceBot\Core\Config;
use BinanceBot\Core\Database;

class Api
{
    public function health(): array
    {
        $config = Config::getInstance();
        $logFile = $config->get('paths.log', __DIR__ . '/bot.log');
        $pidFile = $config->get('paths.pid', __DIR__ . '/grid_bot.pid');

        $health = [
            'ok' => true,
            'ts' => date('Y-m-d H:i:s'),
            'bot_running' => $this->isBotRunning($pidFile, $logFile),
            'bot_uptime' => $this->getUptime($pidFile),
            'log_mtime' => file_exists($logFile) ? date('Y-m-d H:i:s', filemtime($logFile)) : null,
            'log_size' => file_exists($logFile) ? filesize($logFile) : 0,
        ];

        $db = Database::getInstance();
        $health['mysql'] = $db->isConnected();

        $pubBase = 'https://api.bybit.com';
        $ticker = getBybitTicker($pubBase, 'ETHUSDT');
        $health['bybit_api'] = !empty($ticker);

        return $health;
    }

    public function logs(): array
    {
        $config = Config::getInstance();
        $logFile = $config->get('paths.log', __DIR__ . '/bot.log');
        $lines = [];

        if (file_exists($logFile) && filesize($logFile) > 0) {
            $fp = fopen($logFile, 'r');
            $size = filesize($logFile);
            fseek($fp, max(0, $size - 80000));
            $raw = fread($fp, 80000);
            fclose($fp);
            $lines = array_values(array_filter(explode("\n", $raw), fn($l) => trim($l) !== ''));
        }

        return ['lines' => array_slice($lines, -400), 'size' => file_exists($logFile) ? filesize($logFile) : 0];
    }

    private function isBotRunning(string $pidFile, string $logFile): bool
    {
        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            if ($pid && ctype_digit($pid) && file_exists("/proc/$pid")) {
                return true;
            }
        }
        return file_exists($logFile) && (time() - filemtime($logFile)) < 90;
    }

    private function getUptime(string $pidFile): string
    {
        if (!file_exists($pidFile)) {
            return '--';
        }
        $pid = trim(file_get_contents($pidFile));
        if (!$pid || !ctype_digit($pid) || !file_exists("/proc/$pid/stat")) {
            return '--';
        }

        $up = (float) explode(' ', (string) @file_get_contents('/proc/uptime'))[0];
        $stat = (string) @file_get_contents("/proc/$pid/stat");
        $rp = strrpos($stat, ')');
        if ($rp === false) {
            return '--';
        }

        $flds = explode(' ', trim(substr($stat, $rp + 2)));
        $age = max(0, (int) ($up - (float) ($flds[19] ?? 0) / 100));

        if ($age >= 3600) {
            return intdiv($age, 3600) . 'h ' . intdiv($age % 3600, 60) . 'm';
        }
        if ($age >= 60) {
            return intdiv($age, 60) . 'm ' . ($age % 60) . 's';
        }
        return $age . 's';
    }
}
