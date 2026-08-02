<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use Nyra\Bip39\Bip39;
use BIP\BIP44;
use kornrunner\Keccak;
use kornrunner\Secp256k1;
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
     * @param string $amount Monto en tokens como string decimal (ej. '10.5') para evitar pérdida de precisión
     * @param \BinanceBot\Core\RpcClient|null $rpc Cliente RPC inyectable (para tests)
     * @return array{ok:bool, tx_hash?:string, error?:string, gas_used?:int, gas_price?:int}
     */
    public static function signAndSendERC20(PDO $pdo, string $secretKey, string $network, string $token, string $to, string $amount, ?\BinanceBot\Core\RpcClient $rpc = null): array
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
        $amount = trim($amount);
        if (!preg_match('/^\d{1,18}(\.\d{1,18})?$/', $amount)) {
            return ['ok' => false, 'error' => 'Monto inválido'];
        }
        if (bccomp($amount, '0', 18) <= 0) {
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
        if (bccomp($balance, $amountWei, 0) < 0) {
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
            error_log('[Wallet::signAndSendERC20] gas estimate: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Error estimando gas para el envío'];
        }

        // 8. Construir transacción
        $allNetworks = \BinanceBot\Core\Networks::all();
        $chainId = $allNetworks[$network]['chain_id'] ?? 0;
        $tx = [
            'nonce' => self::intToHex($nonce),
            'gasPrice' => self::intToHex($gasPrice),
            'gasLimit' => self::intToHex($gasLimit),
            'to' => $contract,
            'value' => '0x0',
            'data' => $data,
            'chainId' => self::intToHex($chainId),
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
            error_log('[Wallet::signAndSendERC20] sendRaw: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Error enviando la transacción'];
        }
    }

    // ===== Helpers privados =====

    private static function callBalanceOf(\BinanceBot\Core\RpcClient $rpc, string $contract, string $from): string
    {
        // balanceOf(address) = 0x70a08231 + address padded to 32 bytes
        $data = '0x70a08231' . str_pad(self::strip0x($from), 64, '0', STR_PAD_LEFT);
        $result = $rpc->call('eth_call', [[
            'to' => $contract,
            'data' => $data,
        ], 'latest']);
        return (string)$result;
    }

    private static function encodeTransferData(string $to, string $amountWei): string
    {
        // transfer(address,uint256) = 0xa9059cbb + to(32 bytes) + amount(32 bytes)
        $amountHex = self::decToHex($amountWei);
        return '0xa9059cbb' . str_pad(self::strip0x($to), 64, '0', STR_PAD_LEFT) . str_pad($amountHex, 64, '0', STR_PAD_LEFT);
    }

    private static function parseAmount(string $hex): string
    {
        $hex = ltrim(self::strip0x($hex), '0') ?: '0';
        $dec = '0';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $dec = bcadd(bcmul($dec, '16', 0), (string)hexdec($hex[$i]), 0);
        }
        return $dec;
    }

    private static function toWei(string $amount): string
    {
        return bcmul($amount, '1000000000000000000', 0);
    }

    private static function fromWei(string $wei): string
    {
        return bcdiv($wei, '1000000000000000000', 8);
    }

    private static function intToHex(int $value): string
    {
        return '0x' . dechex($value);
    }

    private static function strip0x(string $hex): string
    {
        return str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;
    }

    private static function decToHex(string $dec): string
    {
        $dec = ltrim($dec, '0');
        if ($dec === '') {
            return '0';
        }
        $hex = '';
        while (bccomp($dec, '0', 0) > 0) {
            $hex = dechex((int)bcmod($dec, '16', 0)) . $hex;
            $dec = bcdiv($dec, '16', 0);
        }
        return $hex === '' ? '0' : $hex;
    }

    private static function signTransaction(string $privateKey, array $tx): ?string
    {
        try {
            // EIP-155: hash = keccak256(rlp([nonce, gasPrice, gasLimit, to, value, data, chainId, 0, 0]))
            $unsignedItems = [
                self::rlpEncode(self::bytesFromHex($tx['nonce'])),
                self::rlpEncode(self::bytesFromHex($tx['gasPrice'])),
                self::rlpEncode(self::bytesFromHex($tx['gasLimit'])),
                self::rlpEncode(self::bytesFromHex($tx['to'])),
                self::rlpEncode(self::bytesFromHex($tx['value'])),
                self::rlpEncode(self::bytesFromHex($tx['data'])),
                self::rlpEncode(self::bytesFromHex($tx['chainId'])),
                self::rlpEncode(''),
                self::rlpEncode(''),
            ];
            $unsignedRlp = self::rlpEncodeList($unsignedItems);
            $hash = Keccak::hash($unsignedRlp, 256);

            // Firmar
            $secp256k1 = new Secp256k1();
            $signature = $secp256k1->sign($hash, $privateKey, ['canonical' => true]);

            $r = gmp_strval($signature->getR(), 16);
            $s = gmp_strval($signature->getS(), 16);
            $recoveryParam = $signature->getRecoveryParam();

            // EIP-155: v = recoveryParam + 35 + chainId * 2
            $chainId = (int)hexdec($tx['chainId']);
            $v = $recoveryParam + 35 + $chainId * 2;

            // Tx firmada: [nonce, gasPrice, gasLimit, to, value, data, v, r, s]
            $signedRlp = self::rlpEncodeList([
                self::rlpEncode(self::bytesFromHex($tx['nonce'])),
                self::rlpEncode(self::bytesFromHex($tx['gasPrice'])),
                self::rlpEncode(self::bytesFromHex($tx['gasLimit'])),
                self::rlpEncode(self::bytesFromHex($tx['to'])),
                self::rlpEncode(self::bytesFromHex($tx['value'])),
                self::rlpEncode(self::bytesFromHex($tx['data'])),
                self::rlpEncode(self::bytesFromHex(self::intToHex($v))),
                self::rlpEncode(hex2bin(str_pad($r, 64, '0', STR_PAD_LEFT))),
                self::rlpEncode(hex2bin(str_pad($s, 64, '0', STR_PAD_LEFT))),
            ]);

            return '0x' . bin2hex($signedRlp);
        } catch (\Throwable $e) {
            error_log('[Wallet::signTransaction] ' . $e->getMessage());
            return null;
        }
    }

    // RLP encoding helpers
    private static function bytesFromHex(string $hex): string
    {
        $hex = self::strip0x($hex);
        if ($hex === '' || $hex === '0') {
            return '';
        }
        if (strlen($hex) % 2 === 1) {
            $hex = '0' . $hex;
        }
        return hex2bin($hex);
    }

    private static function rlpEncode(string $data): string
    {
        $len = strlen($data);
        if ($len === 1 && ord($data[0]) <= 0x7f) {
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