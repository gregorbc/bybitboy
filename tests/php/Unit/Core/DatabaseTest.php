<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\Database;

class DatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        Database::reset();
    }

    public function testGetInstanceReturnsSingleton(): void
    {
        $db1 = Database::getInstance();
        $db2 = Database::getInstance();
        $this->assertSame($db1, $db2);
    }

    public function testGetInstanceReturnsDatabaseInstance(): void
    {
        $db = Database::getInstance();
        $this->assertInstanceOf(Database::class, $db);
    }
}
