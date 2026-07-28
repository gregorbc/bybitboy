<?php
declare(strict_types=1);

namespace Tests\Unit\Exchange
{
    use PHPUnit\Framework\TestCase;
    use BinanceBot\Exchange\BybitFutures;
    use BinanceBot\Exchange\ExchangeInterface;

    class BybitFuturesTest extends TestCase
    {
        public function testImplementsInterface(): void
        {
            $api = new BybitFutures('test_key', 'test_secret', true);
            $this->assertInstanceOf(ExchangeInterface::class, $api);
        }

        public function testPriceReturnsFloat(): void
        {
            $api = new BybitFutures('test_key', 'test_secret', true);
            $price = $api->price('ETHUSDT');
            $this->assertIsFloat($price);
        }

        public function testFiltersReturnsArrayWithKeys(): void
        {
            $api = new BybitFutures('test_key', 'test_secret', true);
            $filters = $api->filters('ETHUSDT');
            $this->assertIsArray($filters);
            $this->assertArrayHasKey('step', $filters);
            $this->assertArrayHasKey('tick', $filters);
            $this->assertArrayHasKey('mn', $filters);
            $this->assertArrayHasKey('qp', $filters);
            $this->assertArrayHasKey('pp', $filters);
        }

        public function testGetOrderReturnsArrayWithKeys(): void
        {
            $api = new BybitFutures('test_key', 'test_secret', true);
            $order = $api->getOrder('ETHUSDT', 'test_order_id');
            $this->assertIsArray($order);
            $this->assertArrayHasKey('status', $order);
            $this->assertArrayHasKey('avgPrice', $order);
            $this->assertArrayHasKey('qty', $order);
        }

        public function testGetOpenOrdersReturnsArray(): void
        {
            $api = new BybitFutures('test_key', 'test_secret', true);
            $orders = $api->getOpenOrders('ETHUSDT');
            $this->assertIsArray($orders);
        }
    }
}

namespace
{
    function lI($m) {}
    function lW($m) {}
    function lE($m) {}
    function hPost($url, $payload, $headers = [], $timeout = 25) {
        return ['body' => [], 'raw' => ''];
    }
}
