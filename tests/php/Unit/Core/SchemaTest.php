<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Schema;

class SchemaTest extends TestCase
{
    public function testDdlCreatesAllTables(): void
    {
        $ddl = implode("\n", Schema::ddl());
        foreach (['users', 'login_attempts', 'wallets', 'deposit_addresses', 'deposits', 'shares', 'movements', 'withdrawals', 'nav_snapshots', 'scan_state'] as $table) {
            $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS $table", $ddl, "falta tabla $table");
        }
    }

    public function testDepositsTxHashUnique(): void
    {
        $ddl = implode("\n", Schema::ddl());
        $this->assertStringContainsString('UNIQUE KEY uq_tx', $ddl);
    }

    public function testDepositAddressesUniquePerNetwork(): void
    {
        $ddl = implode("\n", Schema::ddl());
        $this->assertStringContainsString('UNIQUE KEY uq_addr (network, address)', $ddl);
    }

    public function testCreateTablesExecutesEachStatement(): void
    {
        $pdo = \Mockery::mock(\PDO::class);
        $n = count(Schema::ddl());
        $pdo->shouldReceive('exec')->times($n)->andReturn(true);
        Schema::createTables($pdo);
        $this->addToAssertionCount(1);
        \Mockery::close();
    }

    public function testAdminSendsTableExists(): void
    {
        $ddl = implode("\n", Schema::ddl());
        $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS admin_sends", $ddl, "falta tabla admin_sends");
    }

    public function testAdminSendsIndexes(): void
    {
        $ddl = implode("\n", Schema::ddl());
        $this->assertStringContainsString('INDEX idx_admin (admin_id)', $ddl);
        $this->assertStringContainsString('INDEX idx_status (status)', $ddl);
    }
}
