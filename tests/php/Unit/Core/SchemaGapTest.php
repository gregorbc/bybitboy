<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Schema;
use Tests\Support\SqliteSchema;

class SchemaGapTest extends TestCase
{
    public function testDdlContainsGapTables(): void
    {
        $ddl = implode("\n", Schema::ddl());
        foreach (['logs_ia', 'logs_acceso', 'alertas_config'] as $t) {
            $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS $t", $ddl, "falta tabla $t");
        }
    }

    public function testCreateTablesExecutesGapStatements(): void
    {
        $pdo = \Mockery::mock(\PDO::class);
        $n = count(Schema::ddl());
        $pdo->shouldReceive('exec')->times($n + 1 + 4)->andReturn(true); // ddl + movements.note + 4 ALTERs gap
        Schema::createTables($pdo);
        $this->addToAssertionCount(1);
        \Mockery::close();
    }

    public function testSqliteSchemaHasGapTablesAndColumns(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($pdo);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['logs_ia', 'logs_acceso', 'alertas_config'] as $t) {
            $this->assertContains($t, $tables, "tabla $t debe existir");
        }
        $cols = function (string $table) use ($pdo): array {
            return array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        };
        $this->assertContains('totp_secret', $cols('users'));
        $this->assertContains('totp_enabled', $cols('users'));
        $this->assertContains('max_daily_loss', $cols('grid_configs'));
        $this->assertContains('recovery_loss_pct', $cols('grid_configs'));
    }
}
