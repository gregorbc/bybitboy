<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PDO;
use BinanceBot\Core\Accounting;
use BinanceBot\Core\Reconciliation;
use BinanceBot\Exchange\BybitFutures;
use Tests\Support\SqliteSchema;

class ReconciliationTest extends TestCase
{
    private PDO $pdo;
    private BybitFutures $client;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
        $this->client = $this->createMock(BybitFutures::class);
    }

    public function testReconcileReturnsOkWhenMatch(): void
    {
        Accounting::init($this->pdo, 1050.0);
        $this->client->method('balance')->willReturn(1050.0);
        $this->client->method('positions')->willReturn([]);
        $res = Reconciliation::reconcile($this->pdo, $this->client);
        $this->assertTrue($res['ok']);
        $this->assertSame(1050.0, $res['ledger_total']);
        $this->assertSame(1050.0, $res['exchange_total']);
        $this->assertLessThan(0.50, abs($res['diferencia']));
    }

    public function testReconcileReportsDifference(): void
    {
        Accounting::init($this->pdo, 1050.0);
        $this->client->method('balance')->willReturn(1000.0);
        $this->client->method('positions')->willReturn([]);
        $res = Reconciliation::reconcile($this->pdo, $this->client);
        $this->assertFalse($res['ok']);
        $this->assertSame(-50.0, round($res['diferencia'], 2));
    }

    public function testReconcileIncludesUnrealizedPnl(): void
    {
        Accounting::init($this->pdo, 1000.0);
        $this->client->method('balance')->willReturn(900.0);
        $this->client->method('positions')->willReturn([
            ['symbol' => 'ETHUSDT', 'positionAmt' => 0.1, 'entryPrice' => 3000, 'unRealizedProfit' => 100.0, 'liquidationPrice' => 2500],
        ]);
        $res = Reconciliation::reconcile($this->pdo, $this->client);
        $this->assertSame(1000.0, $res['exchange_total']);
        $this->assertTrue($res['ok']);
    }
}
