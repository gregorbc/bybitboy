<?php
declare(strict_types=1);

namespace BinanceBot\Core;

class RpcClient
{
    /** @var callable|null */
    private $http;

    /** @param callable(string $url, string $payload): string|null $http transporte inyectable para tests */
    public function __construct(private string $url, ?callable $http = null)
    {
        $this->http = $http;
    }

    public function call(string $method, array $params): mixed
    {
        $payload = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        $raw = $this->http ? ($this->http)($this->url, $payload) : $this->curlPost($payload);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || isset($decoded['error'])) {
            $msg = is_array($decoded) ? json_encode($decoded['error']) : 'respuesta inválida';
            throw new \RuntimeException('RPC error: ' . $msg);
        }
        return $decoded['result'] ?? null;
    }

    private function curlPost(string $payload): string
    {
        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $raw === '') {
            throw new \RuntimeException('RPC transport error: ' . $err);
        }
        return (string)$raw;
    }

    public function blockNumber(): int
    {
        return (int)hexdec((string)$this->call('eth_blockNumber', []));
    }

    /** @param list<string> $contracts @param list<string> $paddedTo */
    public function getLogs(string $fromBlockHex, string $toBlockHex, array $contracts, string $transferTopic0, array $paddedTo): array
    {
        $result = $this->call('eth_getLogs', [[
            'fromBlock' => $fromBlockHex,
            'toBlock' => $toBlockHex,
            'address' => array_values($contracts),
            'topics' => [$transferTopic0, null, $paddedTo],
        ]]);
        return is_array($result) ? $result : [];
    }

    public function transactionReceipt(string $txHash): ?array
    {
        $result = $this->call('eth_getTransactionReceipt', [$txHash]);
        return is_array($result) ? $result : null;
    }
}