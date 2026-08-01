<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class Cli
{
    /** @return list<string> líneas de salida */
    public static function run(PDO $pdo, string $command, string $secret, array $args = []): array
    {
        return match ($command) {
            'wallet:init' => self::walletInit($pdo, $secret),
            'accounting:init' => self::accountingInit($pdo, (float)($args[0] ?? 0)),
            'wallet:address' => self::walletAddress($pdo, (int)($args[0] ?? 0), (string)($args[1] ?? 'eth'), $secret),
            default => ['Uso: php cli.php {wallet:init|accounting:init [capital]|wallet:address <userId> <network>}'],
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

    private static function walletAddress(PDO $pdo, int $userId, string $network, string $secret): array
    {
        if ($userId <= 0) {
            return ['Uso: php cli.php wallet:address <userId> <network>'];
        }
        return [Wallet::getDepositAddress($pdo, $userId, $network, $secret)];
    }
}