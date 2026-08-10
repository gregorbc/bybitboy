<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\LogAccess;
use Tests\Support\SqliteSchema;

class LogAccessTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
    }

    public function testRecordInsertsRow(): void
    {
        LogAccess::record($this->pdo, 7, 'juan', '1.2.3.4', 'curl/8', 'exitoso');
        $row = $this->pdo->query('SELECT * FROM logs_acceso')->fetch();
        $this->assertSame(7, (int)$row['usuario_id']);
        $this->assertSame('juan', $row['username']);
        $this->assertSame('exitoso', $row['resultado']);
    }

    public function testRecordAcceptsNullUser(): void
    {
        LogAccess::record($this->pdo, null, 'desconocido', '1.2.3.4', '', 'fallido');
        $row = $this->pdo->query('SELECT * FROM logs_acceso')->fetch();
        $this->assertNull($row['usuario_id']);
        $this->assertSame('fallido', $row['resultado']);
    }
}
