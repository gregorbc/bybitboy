<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PDO;
use BinanceBot\Core\Accounting;
use BinanceBot\Core\AdminHttp;
use BinanceBot\Core\Csrf;
use Tests\Support\SqliteSchema;

class AlertasConfigTest extends TestCase
{
    protected ?PDO $pdo = null;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        SqliteSchema::apply($this->pdo);
        $this->pdo->exec("INSERT INTO users (id, username, email, password_hash, role) VALUES (1, 'admin', 'a@e.com', 'x', 'admin')");
        Accounting::init($this->pdo, 100000.0);
    }

    public function testAlertasListReturnsConfiguredRows(): void
    {
        $this->pdo->exec("INSERT INTO alertas_config (tipo, umbral) VALUES ('drawdown_pct', 30)");
        $session = ['user_id' => 1, 'role' => 'admin'];
        $session['csrf'] = Csrf::token($session);
        $out = AdminHttp::handle($this->pdo, $session, ['action' => 'alertas_list', 'csrf' => $session['csrf']]);
        $this->assertSame('drawdown_pct', $out['data']['alertas'][0]['tipo']);
        $this->assertSame('30', (string)$out['data']['alertas'][0]['umbral']);
    }

    public function testAlertaSaveUpserts(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $session['csrf'] = Csrf::token($session);
        $out = AdminHttp::handle($this->pdo, $session, [
            'action' => 'alerta_save', 'tipo' => 'daily_loss_pct', 'umbral' => '5',
            'habilitado' => '1', 'telegram_chat_id' => '777', 'intervalo_min' => '60',
            'csrf' => $session['csrf'],
        ]);
        $this->assertSame('Alerta guardada', $out['data']['flash'] ?? '');
        $row = $this->pdo->query("SELECT * FROM alertas_config WHERE tipo = 'daily_loss_pct'")->fetch();
        $this->assertSame('5', (string)$row['umbral']);
        $this->assertSame('777', $row['telegram_chat_id']);
        $this->assertSame(60, (int)$row['intervalo_min']);
    }

    public function testAlertaSaveRejectsUnknownTipo(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $session['csrf'] = Csrf::token($session);
        $out = AdminHttp::handle($this->pdo, $session, ['action' => 'alerta_save', 'tipo' => 'foo', 'umbral' => '1', 'csrf' => $session['csrf']]);
        $this->assertSame('Tipo de alerta no válido', $out['data']['error']);
    }

    public function testAlertaDeleteRemovesRow(): void
    {
        $this->pdo->exec("INSERT INTO alertas_config (tipo, umbral) VALUES ('drawdown_pct', 30)");
        $id = (int)$this->pdo->lastInsertId();
        $session = ['user_id' => 1, 'role' => 'admin'];
        $session['csrf'] = Csrf::token($session);
        AdminHttp::handle($this->pdo, $session, ['action' => 'alerta_delete', 'id' => (string)$id, 'csrf' => $session['csrf']]);
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM alertas_config')->fetchColumn());
    }

    public function testSetTelegramTokenPersistsInBotMeta(): void
    {
        $session = ['user_id' => 1, 'role' => 'admin'];
        $session['csrf'] = Csrf::token($session);
        AdminHttp::handle($this->pdo, $session, ['action' => 'set_telegram_token', 'token' => '123:abc', 'csrf' => $session['csrf']]);
        $row = $this->pdo->query("SELECT meta_value FROM bot_meta WHERE meta_key = 'telegram_bot_token'")->fetch();
        $this->assertSame('123:abc', $row['meta_value']);
    }
}
