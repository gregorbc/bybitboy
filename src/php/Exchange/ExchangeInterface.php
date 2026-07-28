<?php
declare(strict_types=1);

namespace BinanceBot\Exchange;

interface ExchangeInterface
{
    public function validate();
    public function price($symbol);
    public function klines($symbol, $interval, $limit);
    public function filters($symbol);
    public function balance();
    public function positions($symbol);
    public function setLeverage($symbol, $lev);
    public function limitOrder($symbol, $side, $qty, $price, $reduceOnly = false, $postOnly = true);
    public function marketClose($symbol, $side, $qty);
    public function getOrder($symbol, $orderId);
    public function getOpenOrders($symbol);
    public function cancelAll($symbol);
}
