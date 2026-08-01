<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Wallet;
use Tests\Support\SqliteSchema;

class WalletTest extends TestCase
{
    private \PDO $pdo;
    private const SECRET = 'clave-de-prueba';

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
    }

    public function testDeriveAddressDeterministicKnownVector(): void
    {
        $mnemonic = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
        $address = Wallet::deriveAddress($mnemonic, 0);
        $this->assertSame('0x9858effd232b4033e47d90003d41ec34ecaeda94', $address);
        $this->assertSame($address, Wallet::deriveAddress($mnemonic, 0));
    }

    public function testDeriveAddressDifferentIndexes(): void
    {
        $mnemonic = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
        $this->assertNotSame(Wallet::deriveAddress($mnemonic, 0), Wallet::deriveAddress($mnemonic, 1));
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $cipher = Wallet::encrypt('mi mnemonic secreto', self::SECRET);
        $this->assertNotSame('mi mnemonic secreto', $cipher);
        $this->assertSame('mi mnemonic secreto', Wallet::decrypt($cipher, self::SECRET));
    }

    public function testInitCreatesWalletOnce(): void
    {
        $first = Wallet::init($this->pdo, self::SECRET);
        $second = Wallet::init($this->pdo, self::SECRET);
        $this->assertTrue($first['ok']);
        $this->assertTrue($second['existing']);
        $row = $this->pdo->query('SELECT seed_encrypted FROM wallets')->fetch();
        $this->assertNotSame('', $row['seed_encrypted']);
    }

    public function testGetDepositAddressIsStablePerUserNetwork(): void
    {
        Wallet::init($this->pdo, self::SECRET);
        $a1 = Wallet::getDepositAddress($this->pdo, 1, 'eth', self::SECRET);
        $a2 = Wallet::getDepositAddress($this->pdo, 1, 'eth', self::SECRET);
        $b = Wallet::getDepositAddress($this->pdo, 2, 'eth', self::SECRET);
        $this->assertSame($a1, $a2);
        $this->assertNotSame($a1, $b);
        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{40}$/', $a1);
    }
}