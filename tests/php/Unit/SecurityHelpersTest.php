<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SecurityHelpersTest extends TestCase
{
    public function testPrivateConfigPathIsOutsideWebRoot(): void
    {
        $path = privateConfigPath();
        $this->assertStringEndsWith('/private/config.json', $path);
        $this->assertStringNotContainsString('/public_html', $path);
        $this->assertDirectoryExists(dirname($path));
    }

    public function testEnvLoadOnceIsIdempotentAndReadsEnvFile(): void
    {
        envLoadOnce();
        envLoadOnce();
        $this->assertNotSame('', getenv('PLATFORM_SECRET'));
    }

    public function testBotCfgReturnsArrayAndMergesEnv(): void
    {
        $cfg = botCfg();
        $this->assertIsArray($cfg);
        $this->assertIsArray($cfg['bybit'] ?? []);
    }

    public function testCheckTokenIsFailClosed(): void
    {
        $orig = $_GET['token'] ?? null;
        $_GET['token'] = '';
        $this->assertFalse(checkToken(''));
        $this->assertFalse(checkToken('abc'));
        $_GET['token'] = 'abc';
        $this->assertTrue(checkToken('abc'));
        $this->assertFalse(checkToken('abd'));
        unset($_GET['token']);
        $this->assertFalse(checkToken('abc'));
        if ($orig !== null) { $_GET['token'] = $orig; }
    }

    public function testRequireAdminSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['role'] = 'investor';
        $this->assertFalse(requireAdminSession());
        $_SESSION['role'] = 'admin';
        $this->assertTrue(requireAdminSession());
    }

    public function testPrivateConfigHasNoInlineSecrets(): void
    {
        $path = privateConfigPath();
        $this->assertFileExists($path);
        $raw = (string)file_get_contents($path);
        $this->assertStringNotContainsString('api_key', $raw);
        $this->assertStringNotContainsString('api_secret', $raw);
        $this->assertStringNotContainsString('password', $raw);
        $this->assertStringNotContainsString('token', $raw);
    }
}
