<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use Nyra\Bip39\Bip39;
use BIP\BIP44;
use BIP\HDKey;
use Elliptic\EC;
use kornrunner\Keccak;
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

        $ec = new EC('secp256k1');
        $keyPair = $ec->keyFromPrivate($derived->privateKey, 'hex');
        $publicKeyUncompressed = $keyPair->getPublic(false, 'hex');
        $pubKeyNoPrefix = substr($publicKeyUncompressed, 2);

        $hash = Keccak::hash(hex2bin($pubKeyNoPrefix), 256);
        return '0x' . strtolower(substr($hash, -40));
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
}