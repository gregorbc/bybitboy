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

    public function testHealthEndpointReturnsStructure(): void
    {
        $data = $this->executeEndpoint(['_health' => '1']);
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
        $data = $this->executeEndpoint(['_logs' => '1']);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('lines', $data);
        $this->assertIsArray($data['lines']);
        $this->assertArrayHasKey('size', $data);
    }

    public function testLogsEndpointWithEmptyLog(): void
    {
        $data = $this->executeEndpoint(['_logs' => '1']);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('lines', $data);
        $this->assertNotEmpty($data['lines']);
    }

    public function testHealthEndpointBotRunningField(): void
    {
        $data = $this->executeEndpoint(['_health' => '1']);
        $this->assertArrayHasKey('bot_running', $data);
        $this->assertIsBool($data['bot_running']);
    }

    public function testHealthEndpointMysqlField(): void
    {
        $data = $this->executeEndpoint(['_health' => '1']);
        $this->assertArrayHasKey('mysql', $data);
        $this->assertIsBool($data['mysql']);
    }
}
