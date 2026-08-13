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

        public function testNormalizePositionIncludesSymbolLeverageAndLiqPrice(): void
        {
            $ref = new \ReflectionMethod(BybitFutures::class, 'normalizePosition');
            $ref->setAccessible(true);

            $raw = [
                'size' => '0.5',
                'side' => 'Buy',
                'avgPrice' => '10000',
                'unrealisedPnl' => '-100',
                'liqPrice' => '0',
                'leverage' => '100',
            ];
            $out = $ref->invoke(null, $raw, 'ETHUSDT');

            $this->assertSame('ETHUSDT', $out['symbol']);
            $this->assertSame(0.5, $out['positionAmt']);
            $this->assertSame(0.5, $out['size']);
            $this->assertSame(10000.0, $out['entryPrice']);
            $this->assertSame('Buy', $out['side']);
            $this->assertSame(0.0, $out['liquidationPrice']);
            $this->assertSame(0.0, $out['liqPrice']);
            $this->assertSame(100.0, $out['leverage']);
        }

        public function testNormalizePositionMarksShortWithNegativeAmount(): void
        {
            $ref = new \ReflectionMethod(BybitFutures::class, 'normalizePosition');
            $ref->setAccessible(true);

            $raw = [
                'size' => '0.4',
                'side' => 'Sell',
                'avgPrice' => '3000',
                'liqPrice' => '3100',
                'leverage' => '10',
            ];
            $out = $ref->invoke(null, $raw, 'BTCUSDT');

            $this->assertSame('BTCUSDT', $out['symbol']);
            $this->assertSame(-0.4, $out['positionAmt']);
            $this->assertSame(3100.0, $out['liquidationPrice']);
            $this->assertSame(3100.0, $out['liqPrice']);
            $this->assertSame(10.0, $out['leverage']);
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
