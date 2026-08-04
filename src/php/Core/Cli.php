<?php
declare(strict_types=1);

namespace BinanceBot\Core;

require_once __DIR__ . '/../BotLogging.php';

use PDO;
use BinanceBot\Exchange\BybitFutures;

class Cli
{
    /** @return list<string> líneas de salida */
    public static function run(PDO $pdo, string $command, string $secret, array $args = []): array
    {
        return match ($command) {
            'wallet:init' => self::walletInit($pdo, $secret),
            'accounting:init' => self::accountingInit($pdo, (float)($args[0] ?? 0)),
            'accounting:sync-bot' => self::accountingSyncBot($pdo, $secret),
            'wallet:address' => self::walletAddress($pdo, (int)($args[0] ?? 0), (string)($args[1] ?? 'eth'), $secret),
            default => ['Uso: php cli.php {wallet:init|accounting:init [capital]|accounting:sync-bot|wallet:address <userId> <network>}'],
        };
    }

    private static function walletInit(PDO $pdo, string $secret): array
    {
        $res = Wallet::init($pdo, $secret);
        return $res['existing']
            ? ['Wallet ya inicializada.']
            : ['Wallet inicializada (mnemonic cifrado guardado).'];
    }

    private static function accountingInit(PDO $pdo, float $capital): array
    {
        Accounting::init($pdo, $capital);
        return ["Contabilidad inicializada (owner_units = $capital)."];
    }

    private static function accountingSyncBot(PDO $pdo, string $secret): array
    {
        $cfgFile = __DIR__ . '/../config.json';
        if (!file_exists($cfgFile)) {
            return ['ERROR: config.json no encontrado en ' . $cfgFile];
        }
        $cfg = json_decode(file_get_contents($cfgFile), true);
        if (!is_array($cfg)) {
            return ['ERROR: config.json inválido'];
        }

        $bk = trim((string)($cfg['bybit']['api_key'] ?? ''));
        $bs = trim((string)($cfg['bybit']['api_secret'] ?? ''));
        $tn = (bool)($cfg['bybit']['testnet'] ?? false);
        $env = trim((string)($cfg['bybit']['environment'] ?? ''));

        if (empty($bk) || empty($bs)) {
            return ['ERROR: Faltan credenciales Bybit en config.json'];
        }

        try {
            $api = new BybitFutures($bk, $bs, $tn, $env ?: null);
            $symbol = strtoupper(trim((string)($cfg['bot']['symbol'] ?? 'ETHUSDT')));
            $result = BotAccountingSync::sync($pdo, $api, $symbol);
            if ($result['ok']) {
                return [
                    'Sincronización completada.',
                    'Real balance: ' . number_format($result['real_balance'], 2) . ' USDT',
                    'Wallet held: ' . number_format($result['wallet_held'], 2) . ' USDT',
                    'Realized PnL: ' . number_format($result['realized_pnl'], 4) . ' USDT',
                    'Unrealized PnL: ' . number_format($result['unrealized_pnl'], 4) . ' USDT',
                    'Total bot PnL: ' . number_format($result['bot_pnl_total'], 4) . ' USDT',
                    'NAV: ' . number_format($result['nav'], 8),
                ];
            }
            return ['ERROR: ' . ($result['error'] ?? 'Unknown error')];
        } catch (\Throwable $e) {
            return ['ERROR: ' . $e->getMessage()];
        }
    }

    private static function walletAddress(PDO $pdo, int $userId, string $network, string $secret): array
    {
        if ($userId <= 0) {
            return ['Uso: php cli.php wallet:address <userId> <network>'];
        }
        return [Wallet::getDepositAddress($pdo, $userId, $network, $secret)];
    }
}