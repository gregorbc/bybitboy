<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use Nyra\Bip39\Bip39;
use BIP\BIP44;
use BIP\HDKey;
use kornrunner\Keccak;
use kornrunner\Secp256k1;
use kornrunner\Serializer\HexPrivateKeySerializer;
use kornrunner\Ethereum\Address;
use PDO;

class Wallet
{
    public static function init(PDO $pdo, string $secretKey): array
    {
        $stmt = $pdo->query("SELECT id FROM wallets WHERE network = 'root' LIMIT 1");
        if ($stmt->fetch()) {
            return ['ok' => true, 'existing' => true];
        }
        $mnemonic = Bip39::generateMnemonic(128);
        $stmt = $pdo->prepare("INSERT INTO wallets (network, seed_encrypted) VALUES ('root', ?)");
        $stmt->execute([self::encrypt($mnemonic, $secretKey)]);
        return ['ok' => true, 'existing' => false];
    }

    public static function mnemonic(PDO $pdo, string $secretKey): string
    {
        $stmt = $pdo->query("SELECT seed_encrypted FROM wallets WHERE network = 'root' LIMIT 1");
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('Wallet no inicializada. Ejecuta: php cli.php wallet:init');
        }
        return self::decrypt($row['seed_encrypted'], $secretKey);
    }

    public static function getDepositAddress(PDO $pdo, int $userId, string $network, string $secretKey): string
    {
        $stmt = $pdo->prepare('SELECT address FROM deposit_addresses WHERE user_id = ? AND network = ?');
        $stmt->execute([$userId, $network]);
        $row = $stmt->fetch();
        if ($row) {
            return $row['address'];
        }
        $index = self::nextIndex($pdo);
        $address = self::deriveAddress(self::mnemonic($pdo, $secretKey), $index);
        $stmt = $pdo->prepare('INSERT INTO deposit_addresses (user_id, network, address, derivation_index) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $network, $address, $index]);
        return $address;
    }

    public static function deriveAddress(string $mnemonic, int $index): string
    {
        $seedHex = Bip39::mnemonicToSeedHex($mnemonic);
        $hdKey = BIP44::fromMasterSeed($seedHex);
        $derived = $hdKey->derive("m/44'/60'/0'/0/{$index}");

        $privateKey = strtolower($derived->privateKey);
        $address = new Address($privateKey);
        return '0x' . strtolower($address->get());
    }

    public static function encrypt(string $plain, string $key): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('No se pudo cifrar');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload, string $key): string
    {
        $raw = base64_decode($payload);
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('No se pudo descifrar la wallet');
        }
        return $plain;
    }

    private static function nextIndex(PDO $pdo): int
    {
        $row = $pdo->query('SELECT COALESCE(MAX(derivation_index), -1) + 1 AS n FROM deposit_addresses')->fetch();
        return (int)$row['n'];
    }

    /**
     * Firma y envía una transacción ERC20 (USDT/USDC) desde la wallet maestra (index 0)
     *
     * @param PDO $pdo
     * @param string $secretKey Clave para descifrar el mnemonic (PLATFORM_SECRET)
     * @param string $network Red: 'eth' o 'bsc'
     * @param string $token Token: 'USDT' o 'USDC'
     * @param string $to Dirección destino (0x...)
     * @param float $amount Monto en tokens (ej. 10.5)
     * @param \BinanceBot\Core\RpcClient|null $rpc Cliente RPC inyectable (para tests)
     * @return array{ok:bool, tx_hash?:string, error?:string, gas_used?:int, gas_price?:int}
     */
    public static function signAndSendERC20(PDO $pdo, string $secretKey, string $network, string $token, string $to, float $amount, ?\BinanceBot\Core\RpcClient $rpc = null): array
    {
        // 1. Validaciones básicas
        if (!\BinanceBot\Core\Networks::validateAddress($network, $to)) {
            return ['ok' => false, 'error' => 'Dirección destino inválida para la red'];
        }
        $contracts = \BinanceBot\Core\Networks::contracts($network);
        $contract = $contracts[$token] ?? '';
        if ($contract === '') {
            return ['ok' => false, 'error' => 'Token no soportado en esta red'];
        }
        if ($amount <= 0) {
            return ['ok' => false, 'error' => 'Monto debe ser > 0'];
        }

        // 2. Obtener RPC y chain ID
        $rpcUrl = \BinanceBot\Core\Networks::rpc($network);
        if ($rpcUrl === '') {
            return ['ok' => false, 'error' => 'RPC no configurado para la red'];
        }
        $allNetworks = \BinanceBot\Core\Networks::all();
        $chainId = $allNetworks[$network]['chain_id'] ?? 0;

        // 3. RPC client (inyectable para tests)
        $rpcClient = $rpc ?? new \BinanceBot\Core\RpcClient($rpcUrl);

        // 4. Obtener mnemonic y derivar cuenta index 0 (wallet maestra)
        $mnemonic = self::mnemonic($pdo, $secretKey);
        $seedHex = \Nyra\Bip39\Bip39::mnemonicToSeedHex($mnemonic);
        $hdKey = \BIP\BIP44::fromMasterSeed($seedHex);
        $derived = $hdKey->derive("m/44'/60'/0'/0/0");
        $privateKey = '0x' . strtolower($derived->privateKey);
        $fromAddress = self::deriveAddress($mnemonic, 0);

        // 4b. Verificar balance del token
        $balanceHex = self::callBalanceOf($rpcClient, $contract, $fromAddress);
        $balance = self::parseAmount($balanceHex);
        $amountWei = self::toWei($amount);
        if ($balance < $amountWei) {
            return ['ok' => false, 'error' => 'Balance insuficiente en wallet (disponible: ' . self::fromWei($balance) . ' ' . $token . ')'];
        }

        // 5. Nonce
        $nonceHex = $rpcClient->call('eth_getTransactionCount', [$fromAddress, 'latest']);
        $nonce = (int)hexdec((string)$nonceHex);

        // 6. Gas price
        $gasPriceHex = $rpcClient->call('eth_gasPrice', []);
        $gasPrice = (int)hexdec((string)$gasPriceHex);
        $gasPrice = (int)($gasPrice * 1.1); // +10% buffer

        // 7. Estimar gas (eth_call a contract.transfer)
        $data = self::encodeTransferData($to, $amountWei);
        try {
            $gasEstimateHex = $rpcClient->call('eth_estimateGas', [[
                'from' => $fromAddress,
                'to' => $contract,
                'data' => $data,
                'value' => '0x0',
            ]]);
            $gasLimit = (int)hexdec((string)$gasEstimateHex);
            $gasLimit = (int)($gasLimit * 1.2); // +20% buffer
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Error estimando gas: ' . $e->getMessage()];
        }

        // 8. Construir transacción
        $allNetworks = \BinanceBot\Core\Networks::all();
        $chainId = $allNetworks[$network]['chain_id'] ?? 0;
        $tx = [
            'nonce' => '0x' . dechex($nonce),
            'gasPrice' => '0x' . dechex($gasPrice),
            'gasLimit' => '0x' . dechex($gasLimit),
            'to' => $contract,
            'value' => '0x0',
            'data' => $data,
            'chainId' => '0x' . dechex($chainId),
        ];

        // 9. Firmar
        $signed = self::signTransaction($privateKey, $tx);
        if (!$signed) {
            return ['ok' => false, 'error' => 'Error firmando transacción'];
        }

        // 10. Broadcast
        try {
            $txHash = $rpcClient->call('eth_sendRawTransaction', [$signed]);
            return [
                'ok' => true,
                'tx_hash' => $txHash,
                'gas_used' => $gasLimit,
                'gas_price' => $gasPrice,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Error enviando tx: ' . $e->getMessage()];
        }
    }

    // ===== Helpers privados =====

    private static function callBalanceOf(\BinanceBot\Core\RpcClient $rpc, string $contract, string $from): string
    {
        // balanceOf(address) = 0x70a08231 + address padded to 32 bytes
        $data = '0x70a08231' . str_pad(ltrim($from, '0x'), 64, '0', STR_PAD_LEFT);
        $result = $rpc->call('eth_call', [[
            'to' => $contract,
            'data' => $data,
        ], 'latest']);
        return (string)$result;
    }

    private static function encodeTransferData(string $to, int $amountWei): string
    {
        // transfer(address,uint256) = 0xa9059cbb + to(32 bytes) + amount(32 bytes)
        return '0xa9059cbb' . str_pad(ltrim($to, '0x'), 64, '0', STR_PAD_LEFT) . str_pad(dechex($amountWei), 64, '0', STR_PAD_LEFT);
    }

    private static function parseAmount(string $hex): int
    {
        $hex = ltrim(ltrim($hex, '0x'), '0') ?: '0';
        $dec = '0';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $dec = bcadd(bcmul($dec, '16', 0), (string)hexdec($hex[$i]), 0);
        }
        return (int)$dec;
    }

    private static function toWei(float $amount): int
    {
        return (int)bcmul((string)$amount, '1000000000000000000', 0);
    }

    private static function fromWei(int $wei): string
    {
        return bcdiv((string)$wei, '1000000000000000000', 8);
    }

    private static function signTransaction(string $privateKey, array $tx): ?string
    {
        try {
            // RLP encode: [nonce, gasPrice, gasLimit, to, value, data, chainId, 0, 0]
            $rlpItems = [
                self::rlpEncode(hex2bin(ltrim($tx['nonce'], '0x'))),
                self::rlpEncode(hex2bin(ltrim($tx['gasPrice'], '0x'))),
                self::rlpEncode(hex2bin(ltrim($tx['gasLimit'], '0x'))),
                self::rlpEncode(hex2bin(ltrim($tx['to'], '0x'))),
                self::rlpEncode(hex2bin(ltrim($tx['value'], '0x'))),
                self::rlpEncode(hex2bin(ltrim($tx['data'], '0x'))),
                self::rlpEncode(hex2bin(ltrim($tx['chainId'], '0x'))),
                self::rlpEncode(''),
                self::rlpEncode(''),
            ];
            $rlp = self::rlpEncodeList($rlpItems);
            $hash = bin2hex(hash('sha256', $rlp, true)); // hex string for secp256k1

            // Sign using kornrunner/secp256k1
            $secp256k1 = new Secp256k1();
            $signature = $secp256k1->sign($hash, $privateKey, ['canonical' => true]);
            
            $r = $signature->getR();
            $s = $signature->getS();
            $recoveryParam = $signature->getRecoveryParam();
            
            // EIP-155: v = recoveryParam + 35 + chainId * 2
            $chainId = (int)hexdec($tx['chainId']);
            $v = $recoveryParam + 35 + $chainId * 2;

            // RLP encode signed transaction: [nonce, gasPrice, gasLimit, to, value, data, chainId, r, s, v]
            $signedRlp = self::rlpEncodeList([
                self::rlpEncode(hex2bin(ltrim($tx['nonce'], '0x'))),
                self::rlpEncode(hex2bin(ltrim($tx['gasPrice'], '0x'))),
                self::rlpEncode(hex2bin(ltrim($tx['gasLimit'], '0x'))),
                self::rlpEncode(hex2bin(ltrim($tx['to'], '0x'))),
                self::rlpEncode(hex2bin(ltrim($tx['value'], '0x'))),
                self::rlpEncode(hex2bin(ltrim($tx['data'], '0x'))),
                self::rlpEncode(hex2bin(ltrim($tx['chainId'], '0x'))),
                self::rlpEncode(hex2bin(str_pad(gmp_strval($r, 16), 64, '0', STR_PAD_LEFT))),
                self::rlpEncode(hex2bin(str_pad(gmp_strval($s, 16), 64, '0', STR_PAD_LEFT))),
                self::rlpEncode(chr($v)),
            ]);

            return '0x' . bin2hex($signedRlp);
        } catch (\Throwable $e) {
            error_log('[Wallet::signTransaction] ' . $e->getMessage());
            return null;
        }
    }

    // RLP encoding helpers
    private static function rlpEncode(string $data): string
    {
        $len = strlen($data);
        if ($len === 1 && $data[0] >= 0x00 && $data[0] <= 0x7f) {
            return $data;
        }
        if ($len <= 55) {
            return chr(0x80 + $len) . $data;
        }
        $lenBytes = self::intToBytes($len);
        return chr(0xb7 + strlen($lenBytes)) . $lenBytes . $data;
    }

    private static function rlpEncodeList(array $items): string
    {
        $data = '';
        foreach ($items as $item) {
            $data .= $item;
        }
        $len = strlen($data);
        if ($len <= 55) {
            return chr(0xc0 + $len) . $data;
        }
        $lenBytes = self::intToBytes($len);
        return chr(0xf7 + strlen($lenBytes)) . $lenBytes . $data;
    }

    private static function intToBytes(int $value): string
    {
        if ($value === 0) return '';
        $bytes = '';
        while ($value > 0) {
            $bytes = chr($value & 0xff) . $bytes;
            $value >>= 8;
        }
        return $bytes;
    }
}