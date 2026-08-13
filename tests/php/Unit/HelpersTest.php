<?php
declare(strict_types=1);

namespace Tests\Unit;

use function sanitize;
use function checkToken;
use function getUptime;
use function botRunning;
use function analyzeChartWithVL;
use function isAdminSession;
use function projection30d;
use Tests\Support\SqliteSchema;
use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    public function testSanitizeRemovesNonAlphanumeric(): void
    {
        $this->assertSame('ETHUSDT', sanitize('ETH/USDT'));
        $this->assertSame('BTCUSDT', sanitize('btc-usdt'));
        $this->assertSame('', sanitize('!!!'));
        $this->assertSame('A1B2', sanitize('a1-b2'));
    }

    public function testSanitizeLimitsLength(): void
    {
        $this->assertEquals(20, strlen(sanitize(str_repeat('A', 30))));
    }

    public function testCheckTokenEmptyRejects(): void
    {
        $_GET['token'] = 'wrong';
        $this->assertFalse(checkToken(''));
    }

    public function testCheckTokenValidMatches(): void
    {
        $_GET['token'] = 'secret123';
        $this->assertTrue(checkToken('secret123'));
    }

    public function testCheckTokenInvalidRejects(): void
    {
        $_GET['token'] = 'wrong';
        $this->assertFalse(checkToken('secret123'));
    }

    public function testGetUptimeFileNotExists(): void
    {
        $this->assertSame('--', getUptime('/nonexistent'));
    }

    public function testGetUptimeInvalidPid(): void
    {
        $tmp = sys_get_temp_dir() . '/pid_test_' . uniqid();
        file_put_contents($tmp, 'not-a-number');
        $this->assertSame('--', getUptime($tmp));
        unlink($tmp);
    }

    public function testBotRunningNoPidFile(): void
    {
        $this->assertFalse(botRunning('/nonexistent/pid', '/nonexistent/log'));
    }

    public function testAnalyzeChartWithVlReturnsNullForMissingFile(): void
    {
        $result = analyzeChartWithVL('/tmp/nonexistent_image_' . uniqid() . '.png', 'fake_api_key');
        $this->assertNull($result);
    }

    public function testIsAdminSessionFalseWhenEmpty(): void
    {
        $this->assertFalse(isAdminSession([]));
    }

    public function testIsAdminSessionFalseForInvestor(): void
    {
        $this->assertFalse(isAdminSession(['role' => 'investor']));
    }

    public function testIsAdminSessionTrueForAdmin(): void
    {
        $this->assertTrue(isAdminSession(['role' => 'admin']));
    }

    // --- fixture y tests de projection30d() ---
    public function testProjection30dEmptyDbReturnsZeroDays(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($pdo);
        $r = projection30d($pdo, 'ETHUSDT');
        $this->assertSame(0.0, $r['proj_30d']);
        $this->assertSame(0, $r['days']);
    }

    public function testProjection30dIgnoresTodayAndSumsCompletedDays(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($pdo);
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $twoDaysAgo = date('Y-m-d', strtotime('-2 days'));
        $today = date('Y-m-d');
        $ins = $pdo->prepare("INSERT INTO grid_orders (symbol, grid_role, status, pnl_usd, filled_at) VALUES (?, 'EXIT', 'FILLED', ?, ?)");
        $ins->execute(['ETHUSDT', 1.0, $yesterday . ' 10:00:00']);
        $ins->execute(['ETHUSDT', 2.0, $yesterday . ' 11:00:00']);   // segundo fill mismo día: suma al día, no crea día nuevo
        $ins->execute(['ETHUSDT', 3.0, $twoDaysAgo . ' 10:00:00']);
        $ins->execute(['ETHUSDT', 99.0, $today . ' 10:00:00']);      // hoy: excluido
        $ins->execute(['BTCUSDT', 99.0, $yesterday . ' 10:00:00']);  // otro símbolo: excluido
        $r = projection30d($pdo, 'ETHUSDT');
        $this->assertSame(2, $r['days']);
        $this->assertSame(round((1.0 + 2.0 + 3.0) / 2 * 30, 2), $r['proj_30d']); // = 90.0
    }

    public function testProjection30dSingleCompletedDay(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        SqliteSchema::apply($pdo);
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $pdo->prepare("INSERT INTO grid_orders (symbol, grid_role, status, pnl_usd, filled_at) VALUES ('ETHUSDT', 'EXIT', 'FILLED', 2.5, ?)")
            ->execute([$yesterday . ' 10:00:00']);
        $r = projection30d($pdo, 'ETHUSDT');
        $this->assertSame(1, $r['days']);
        $this->assertSame(round(2.5 * 30, 2), $r['proj_30d']);
    }
}