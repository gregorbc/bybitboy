<?php
declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use BinanceBot\Core\RpcClient;

class RpcClientTest extends TestCase
{
    public function testBlockNumberParsesHex(): void
    {
        $rpc = new RpcClient('http://fake', fn(string $url, string $payload): string =>
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => '0x10f2c3']));
        $this->assertSame(1110723, $rpc->blockNumber());
    }

    public function testGetLogsReturnsResult(): void
    {
        $rpc = new RpcClient('http://fake', fn(string $url, string $payload): string =>
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => [['logIndex' => '0x0']]]));
        $logs = $rpc->getLogs('0x1', '0x2', ['0xa'], '0xt', ['0xp']);
        $this->assertCount(1, $logs);
    }

    public function testCallThrowsOnRpcError(): void
    {
        $this->expectException(\RuntimeException::class);
        $rpc = new RpcClient('http://fake', fn(string $url, string $payload): string =>
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000, 'message' => 'boom']]));
        $rpc->call('eth_blockNumber', []);
    }

    public function testTransactionReceipt(): void
    {
        $rpc = new RpcClient('http://fake', fn(string $url, string $payload): string =>
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['status' => '0x1']]));
        $this->assertSame('0x1', $rpc->transactionReceipt('0xabc')['status']);
    }
}