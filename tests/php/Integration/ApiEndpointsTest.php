<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

class ApiEndpointsTest extends TestCase
{
    private function executeEndpoint(array $getParams): array
    {
        $getParamJson = json_encode($getParams);
        $script = <<<PHP
<?php
error_reporting(0);
ini_set("display_errors", "0");
\$_GET = json_decode('{$getParamJson}', true);
\$_SERVER = ["REQUEST_METHOD" => "GET"];
chdir('/home/erika/web/binance.gregorbritez.cat/public_html');
ob_start();
require '/home/erika/web/binance.gregorbritez.cat/public_html/src/php/grid_ajax.php';
\$output = ob_get_clean();
echo \$output;
PHP;

        $tmpFile = sys_get_temp_dir() . '/test_endpoint_' . uniqid() . '.php';
        file_put_contents($tmpFile, $script);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            'php ' . escapeshellarg($tmpFile),
            $descriptors,
            $pipes
        );

        if (!is_resource($process)) {
            unlink($tmpFile);
            return [];
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($tmpFile);

        $result = json_decode($output ?: '{}', true);
        return is_array($result) ? $result : ['error' => $output, 'stderr' => $stderr];
    }

    private function executeEndpointAsAdmin(array $getParams): array
    {
        $getParamJson = json_encode($getParams);
        $script = <<<PHP
<?php
error_reporting(0);
ini_set("display_errors", "0");
session_start();
\$_SESSION['role'] = 'admin';
\$_GET = json_decode('{$getParamJson}', true);
\$_SERVER = ["REQUEST_METHOD" => "GET"];
chdir('/home/erika/web/binance.gregorbritez.cat/public_html');
ob_start();
require '/home/erika/web/binance.gregorbritez.cat/public_html/src/php/grid_ajax.php';
\$output = ob_get_clean();
echo \$output;
PHP;

        $tmpFile = sys_get_temp_dir() . '/test_admin_' . uniqid() . '.php';
        file_put_contents($tmpFile, $script);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open('php ' . escapeshellarg($tmpFile), $descriptors, $pipes);
        if (!is_resource($process)) { unlink($tmpFile); return []; }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($tmpFile);

        $result = json_decode($output ?: '{}', true);
        return is_array($result) ? $result : ['error' => $output, 'stderr' => $stderr];
    }

    public function testHealthEndpointReturnsStructure(): void
    {
        $data = $this->executeEndpointAsAdmin(['_health' => '1']);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('ok', $data);
        $this->assertTrue($data['ok']);
        $this->assertArrayHasKey('ts', $data);
        $this->assertArrayHasKey('bot_running', $data);
        $this->assertArrayHasKey('mysql', $data);
        $this->assertArrayHasKey('bybit_api', $data);
    }

    public function testLogsEndpointReturnsArray(): void
    {
        $data = $this->executeEndpointAsAdmin(['_logs' => '1']);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('lines', $data);
        $this->assertIsArray($data['lines']);
        $this->assertArrayHasKey('size', $data);
    }

    public function testLogsEndpointWithEmptyLog(): void
    {
        $data = $this->executeEndpointAsAdmin(['_logs' => '1']);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('lines', $data);
        $this->assertNotEmpty($data['lines']);
    }

    public function testHealthEndpointBotRunningField(): void
    {
        $data = $this->executeEndpointAsAdmin(['_health' => '1']);
        $this->assertArrayHasKey('bot_running', $data);
        $this->assertIsBool($data['bot_running']);
    }

    public function testHealthEndpointMysqlField(): void
    {
        $data = $this->executeEndpointAsAdmin(['_health' => '1']);
        $this->assertArrayHasKey('mysql', $data);
        $this->assertIsBool($data['mysql']);
    }

    public function testLandingStatsEndpointReturnsStructure(): void
    {
        $data = $this->executeEndpoint(['_landing_stats' => '1']);
        $this->assertIsArray($data);
        $this->assertTrue($data['ok']);
        $this->assertArrayHasKey('price', $data);
        $this->assertArrayHasKey('pnl_today', $data);
        $this->assertArrayHasKey('pnl_total', $data);
        $this->assertArrayHasKey('pnl_proj_30d', $data);
        $this->assertArrayHasKey('pnl_proj_days', $data);
        $this->assertArrayHasKey('win_rate', $data);
        $this->assertArrayHasKey('fills_total', $data);
        $this->assertArrayHasKey('open_orders', $data);
        $this->assertArrayHasKey('updated_at', $data);
    }

    public function testLandingStatsReturnsNumericFields(): void
    {
        $data = $this->executeEndpoint(['_landing_stats' => '1']);
        $this->assertIsFloat($data['price']);
        $this->assertIsFloat($data['pnl_today']);
        $this->assertIsFloat($data['win_rate']);
        $this->assertIsInt($data['fills_total']);
        $this->assertIsInt($data['open_orders']);
        $this->assertIsFloat($data['pnl_proj_30d']);
        $this->assertIsInt($data['pnl_proj_days']);
    }

    public function testLandingStatsPnlTotalIsNumeric(): void
    {
        $data = $this->executeEndpoint(['_landing_stats' => '1']);
        $this->assertIsFloat($data['pnl_total']);
    }

    public function testStatusPairIncludesProjectionFields(): void
    {
        $data = $this->executeEndpointAsAdmin(['_status' => '1']);
        $this->assertIsArray($data);
        $pair = $data['pairs']['ETHUSDT'] ?? null;
        $this->assertIsArray($pair);
        $this->assertArrayHasKey('pnl_proj_30d', $pair);
        $this->assertArrayHasKey('pnl_proj_days', $pair);
        $this->assertIsFloat($pair['pnl_proj_30d']);
        $this->assertIsInt($pair['pnl_proj_days']);
    }

    public function testDataEndpointsRejectAnonymous(): void
    {
        foreach (['_status', '_logs', '_ticker', '_market', '_pnl_float',
                  '_ai_decisions', '_scalp', '_ml_info', '_fills_history', '_health'] as $ep) {
            $data = $this->executeEndpoint([$ep => '1']);
            $this->assertIsArray($data, $ep);
            $this->assertFalse($data['ok'] ?? true, "$ep debería requerir sesión");
            $this->assertSame('No autorizado', $data['msg'] ?? null, $ep);
        }
    }

    public function testControlWithoutAdminSessionRejected(): void
    {
        $script = <<<PHP
<?php
error_reporting(0);
ini_set("display_errors", "0");
\$_SESSION = [];
\$_POST = ['_control' => '1', 'action' => 'stop'];
\$_SERVER = ["REQUEST_METHOD" => "POST"];
register_shutdown_function(function () { echo "\nSTATUS:" . http_response_code(); });
chdir('/home/erika/web/binance.gregorbritez.cat/public_html');
ob_start();
require '/home/erika/web/binance.gregorbritez.cat/public_html/src/php/grid_ajax.php';
PHP;
        $tmpFile = sys_get_temp_dir() . '/test_control_' . uniqid() . '.php';
        file_put_contents($tmpFile, $script);
        $process = proc_open('php ' . escapeshellarg($tmpFile), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);
        unlink($tmpFile);
        $status = null;
        if (preg_match('/STATUS:(\d+)/', $output, $m)) {
            $status = (int)$m[1];
        }
        $json = preg_replace('/\n?STATUS:\d+\s*$/', '', $output);
        $result = json_decode($json ?: '{}', true);
        $this->assertIsArray($result);
        $this->assertFalse($result['ok']);
        $this->assertSame('No autorizado', $result['msg']);
        $this->assertSame(403, $status);
    }
}
