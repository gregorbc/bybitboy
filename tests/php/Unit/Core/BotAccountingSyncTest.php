<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Accounting;
use BinanceBot\Core\BotAccountingSync;
use BinanceBot\Exchange\BybitFutures;
use Tests\Support\SqliteSchema;

class FakeBybit extends BybitFutures
{
    public float $balanceVal = 0.0;
    public array $positionsArr = [];

    public function __construct()
    {
        // Evita parent::__construct: no toca red ni usa lI()
    }

    public function balance()
    {
        return $this->balanceVal;
    }

    public function positions($symbol)
    {
        return $this->positionsArr;
    }
}

class BotAccountingSyncTest extends TestCase
{
    private \PDO $pdo;
    private FakeBybit $api;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
        $this->api = new FakeBybit();
    }

    private function seedExit(string $symbol, float $pnl): void
    {
        $this->pdo->prepare("INSERT INTO grid_orders (symbol, grid_role, status, pnl_usd, filled_at)
            VALUES (?, 'EXIT', 'FILLED', ?, DATETIME('now'))")
            ->execute([$symbol, $pnl]);
    }

    private function seedOpenGridOrder(): void
    {
        $this->pdo->exec("INSERT INTO grid_orders (symbol, grid_role, status) VALUES ('ETHUSDT', 'ENTRY', 'OPEN')");
    }

    public function testSyncWithNoPnlRecordsZeroAndInsertsSnapshot(): void
    {
        Accounting::init($this->pdo, 100000.0);
        $this->api->balanceVal = 1000.0;

        $result = BotAccountingSync::sync($this->pdo, $this->api, 'ETHUSDT');

        $this->assertTrue($result['ok']);
        $this->assertSame(0.0, $result['realized_pnl']);
        $this->assertSame(0.0, $result['unrealized_pnl']);
        $this->assertSame(0.0, $result['bot_pnl_total']);
        $this->assertSame(1000.0, $result['real_balance']);
        $this->assertSame(0.0, $result['wallet_held']);
        $this->assertSame(0.01, $result['nav']); // 1000 / 100000 totalUnits
        $row = $this->pdo->query('SELECT * FROM nav_snapshots ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertSame(0.0, (float)$row['bot_pnl_total']);
    }

    public function testSyncComputesRealizedUnrealizedAndBalance(): void
    {
        Accounting::init($this->pdo, 100000.0);
        $this->seedExit('ETHUSDT', 10.1987);
        $this->seedExit('ETHUSDT', -3.5);
        $this->seedOpenGridOrder(); // no debe contar
        $this->seedExit('BTCUSDT', 99.0); // otro symbol no debe contar
        $this->api->balanceVal = 1673409.20;
        $this->api->positionsArr = [
            ['unRealizedProfit' => 0.2802],
            ['unRealizedProfit' => -1.0],
        ];

        $result = BotAccountingSync::sync($this->pdo, $this->api, 'ETHUSDT');

        $this->assertEqualsWithDelta(6.6987, $result['realized_pnl'], 0.000001);
        $this->assertSame(-0.7198, $result['unrealized_pnl']);
        $this->assertSame(5.9789, $result['bot_pnl_total']);
        $this->assertSame(1673409.20, $result['real_balance']);
        $this->assertEqualsWithDelta(16.734092, $result['nav'], 0.000001);
        $row = $this->pdo->query('SELECT * FROM nav_snapshots ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertEqualsWithDelta(5.9789, (float)$row['bot_pnl_total'], 0.000001);
        $this->assertEqualsWithDelta(16.734092, (float)$row['nav'], 0.000001);
    }

    public function testSyncIgnoresApiFailureForBalanceAndPositions(): void
    {
        Accounting::init($this->pdo, 100000.0);

        // Simula fallo de red: balance() lanza, positions() lanza
        $throwing = new class extends FakeBybit {
            public function balance()
            {
                throw new \RuntimeException('timeout');
            }
            public function positions($symbol)
            {
                throw new \RuntimeException('timeout');
            }
        };

        $result = BotAccountingSync::sync($this->pdo, $throwing, 'ETHUSDT');

        $this->assertTrue($result['ok']);
        $this->assertSame(0.0, $result['real_balance']);
        $this->assertSame(0.0, $result['unrealized_pnl']);
        $this->assertSame(0.0, $result['bot_pnl_total']);
        $row = $this->pdo->query('SELECT * FROM nav_snapshots ORDER BY id DESC LIMIT 1')->fetch();
        $this->assertNotFalse($row);
        $this->assertSame(0.0, (float)$row['bot_pnl_total']);
    }

    public function testSyncRespectsWalletHeld(): void
    {
        Accounting::init($this->pdo, 100000.0);
        $this->pdo->prepare("INSERT INTO deposits (user_id, network, token, tx_hash, block_number, amount, status, deployed)
            VALUES (1, 'eth', 'USDT', '0xaa', 1, 500.0, 'credited', 0)")
            ->execute();
        $this->api->balanceVal = 900.0;

        $result = BotAccountingSync::sync($this->pdo, $this->api, 'ETHUSDT');

        $this->assertSame(500.0, $result['wallet_held']);
        $this->assertEqualsWithDelta(0.014, $result['nav'], 0.000001); // (900+500)/100000
    }
}
