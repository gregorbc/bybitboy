<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Cli;
use Tests\Support\SqliteSchema;

class CliRunnerTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
    }

    public function testWalletInitCreatesWallet(): void
    {
        $out = Cli::run($this->pdo, 'wallet:init', 'secret');
        $this->assertSame('Wallet inicializada (mnemonic cifrado guardado).', $out[0]);
        $out2 = Cli::run($this->pdo, 'wallet:init', 'secret');
        $this->assertSame('Wallet ya inicializada.', $out2[0]);
    }

    public function testAccountingInitSeeds(): void
    {
        Cli::run($this->pdo, 'accounting:init', 'secret', ['50000']);
        $this->assertSame(50000.0, \BinanceBot\Core\Accounting::ownerUnits($this->pdo));
    }

    public function testWalletAddressPrintsAddress(): void
    {
        Cli::run($this->pdo, 'wallet:init', 'secret');
        $out = Cli::run($this->pdo, 'wallet:address', 'secret', ['7', 'eth']);
        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{40}$/', $out[0]);
    }

    public function testUnknownCommand(): void
    {
        $out = Cli::run($this->pdo, 'nope', 'secret');
        $this->assertStringContainsString('Uso:', $out[0]);
    }
}