<?php
declare(strict_types=1);

namespace BinanceBot\Core;

class Networks
{
    public const TRANSFER_TOPIC0 = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    public const DECIMALS = 18;

    /** @return array<string, array{chain_id:int, name:string, rpc:list<string>, confirmations:int, contracts:array{USDT:string,USDC:string}}> */
    public static function defaults(): array
    {
        return [
            'eth' => [
                'chain_id' => 1,
                'name' => 'Ethereum',
                'rpc' => ['https://eth.api.onfinality.io/public', 'https://ethereum-rpc.publicnode.com', 'https://eth.llamarpc.com'],
                'confirmations' => 12,
                'contracts' => [
                    'USDT' => '0xdAC17F958D2ee523a2206206994597C13D831ec7',
                    'USDC' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
                ],
            ],
            'bsc' => [
                'chain_id' => 56,
                'name' => 'BNB Smart Chain',
                'rpc' => ['https://bsc-rpc.publicnode.com', 'https://bsc-dataseed.binance.org'],
                'confirmations' => 15,
                'contracts' => [
                    'USDT' => '0x55d398326f99059fF775485246999027B3197955',
                    'USDC' => '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d',
                ],
            ],
        ];
    }

    public static function all(): array
    {
        $cfg = Config::getInstance()->get('platform.networks', []);
        $extra = is_array($cfg) ? $cfg : [];
        return array_merge(self::defaults(), $extra);
    }

    public static function rpc(string $network): string
    {
        $rpc = self::all()[$network]['rpc'] ?? [];
        $list = is_array($rpc) ? $rpc : [];
        return (string)($list[0] ?? '');
    }

    public static function confirmations(string $network): int
    {
        return (int)(self::all()[$network]['confirmations'] ?? 15);
    }

    public static function contracts(string $network): array
    {
        return self::all()[$network]['contracts'] ?? ['USDT' => '', 'USDC' => ''];
    }

    public static function validateAddress(string $network, string $address): bool
    {
        if (!isset(self::all()[$network])) {
            return false;
        }
        return (bool)preg_match('/^0x[0-9a-fA-F]{40}$/', trim($address));
    }
}