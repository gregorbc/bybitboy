<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Accounting;
use Tests\Support\SqliteSchema;

class AccountingTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
    }

    private function seedDeposit(int $userId, float $amount, string $status = 'pending'): int
    {
        $this->pdo->prepare('INSERT INTO deposits (user_id, network, token, tx_hash, block_number, amount, status) VALUES (?, "eth", "USDT", ?, 1, ?, ?)')
            ->execute([$userId, '0x' . bin2hex(random_bytes(20)), $amount, $status]);
        return (int)$this->pdo->lastInsertId();
    }

    public function testInitSeedsOwnerUnitsAndNav(): void
    {
        Accounting::init($this->pdo, 100000.0);
        $this->assertSame(100000.0, Accounting::ownerUnits($this->pdo));
        $this->assertSame(100000.0, Accounting::totalUnits($this->pdo));
        $this->assertSame(1.0, Accounting::currentNav($this->pdo));
    }

    public function testInitIsIdempotent(): void
    {
        Accounting::init($this->pdo, 100000.0);
        Accounting::init($this->pdo, 999.0);
        $this->assertSame(100000.0, Accounting::ownerUnits($this->pdo));
    }

    public function testCreditDepositIssuesUnitsAtNav(): void
    {
        Accounting::init($this->pdo, 100000.0);
        $depId = $this->seedDeposit(1, 10000.0);
        Accounting::creditDeposit($this->pdo, $depId);
        $this->assertSame(10000.0, Accounting::userUnits($this->pdo, 1));
        $this->assertSame(10000.0, Accounting::userEquity($this->pdo, 1));
        $this->assertSame('credited', $this->pdo->query("SELECT status FROM deposits WHERE id = $depId")->fetch()['status']);
        $this->assertSame(110000.0, Accounting::totalUnits($this->pdo));
    }

    public function testNavGrowthIncreasesEquityProportionally(): void
    {
        Accounting::init($this->pdo, 100000.0);
        $depId = $this->seedDeposit(1, 10000.0);
        Accounting::creditDeposit($this->pdo, $depId);
        Accounting::updateNav($this->pdo, 110000.0, 0.0, 10000.0);
        $this->assertSame(1.0, Accounting::currentNav($this->pdo));
        Accounting::updateNav($this->pdo, 121000.0, 0.0, 21000.0);
        $nav = Accounting::currentNav($this->pdo);
        $this->assertEqualsWithDelta(1.1, $nav, 0.000001);
        $this->assertEqualsWithDelta(11000.0, Accounting::userEquity($this->pdo, 1), 0.01);
    }

    public function testRequestWithdrawalValidation(): void
    {
        Accounting::init($this->pdo, 100000.0);
        $depId = $this->seedDeposit(1, 10000.0);
        Accounting::creditDeposit($this->pdo, $depId);
        $bad = Accounting::requestWithdrawal($this->pdo, 1, 'eth', 'USDT', 50000.0, '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B', 10.0);
        $this->assertFalse($bad['ok']);
        $ok = Accounting::requestWithdrawal($this->pdo, 1, 'eth', 'USDT', 1000.0, '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B', 10.0);
        $this->assertTrue($ok['ok']);
    }

    public function testApproveWithdrawalBurnsUnits(): void
    {
        Accounting::init($this->pdo, 100000.0);
        $depId = $this->seedDeposit(1, 10000.0);
        Accounting::creditDeposit($this->pdo, $depId);
        $wdId = Accounting::requestWithdrawal($this->pdo, 1, 'eth', 'USDT', 1000.0, '0xAb5801a7D398351b8bE11C439e05C5B3259aeC9B', 10.0)['withdrawal_id'];
        Accounting::approveWithdrawal($this->pdo, $wdId);
        $this->assertEqualsWithDelta(9000.0, Accounting::userUnits($this->pdo, 1), 0.01);
        $row = $this->pdo->query("SELECT status FROM withdrawals WHERE id = $wdId")->fetch();
        $this->assertSame('approved', $row['status']);
        Accounting::markSent($this->pdo, $wdId, '0x' . str_repeat('ab', 32));
        $this->assertSame('sent', $this->pdo->query("SELECT status FROM withdrawals WHERE id = $wdId")->fetch()['status']);
    }

    public function testMarkDeployed(): void
    {
        $depId = $this->seedDeposit(1, 500.0, 'credited');
        Accounting::markDeployed($this->pdo, $depId);
        $this->assertSame(1, $this->pdo->query("SELECT deployed FROM deposits WHERE id = $depId")->fetch()['deployed']);
        $this->assertSame(0.0, Accounting::walletHeld($this->pdo));
    }

    public function testWalletHeldSumsCreditedUndeployed(): void
    {
        $this->seedDeposit(1, 500.0, 'credited');
        $this->seedDeposit(1, 300.0, 'credited');
        $this->seedDeposit(1, 700.0, 'pending');
        $this->assertSame(800.0, Accounting::walletHeld($this->pdo));
    }
}