<?php
declare(strict_types=1);

namespace BinanceBot\Exchange;

class BybitFutures implements ExchangeInterface
{
    private $key, $secret, $base, $pub;
    private $fc = [], $levMem = [];
    public function __construct($key, $secret, $testnet) {
        $this->key    = $key;
        $this->secret = $secret;
        $this->base = $testnet ? 'https://api-demo.bybit.com' : 'https://api.bybit.com';
        $this->pub  = 'https://api.bybit.com';
        lI("[Bybit] " . ($testnet ? 'DEMO/TESTNET' : 'MAINNET') . " priv=" . $this->base . " pub=" . $this->pub);
    }
    private function ts() { return (string)(intval(microtime(true) * 1000)); }
    private function signGet($params) {
        $ts = $this->ts(); $recv = '8000'; ksort($params);
        $str = $ts . $this->key . $recv . http_build_query($params);
        return ['X-BAPI-API-KEY' => $this->key, 'X-BAPI-TIMESTAMP' => $ts,
                'X-BAPI-RECV-WINDOW' => $recv,
                'X-BAPI-SIGN' => hash_hmac('sha256', $str, $this->secret)];
    }
    private function signPost($body) {
        $ts = $this->ts(); $recv = '8000';
        $str = $ts . $this->key . $recv . $body;
        return ['Content-Type' => 'application/json', 'X-BAPI-API-KEY' => $this->key,
                'X-BAPI-TIMESTAMP' => $ts, 'X-BAPI-RECV-WINDOW' => $recv,
                'X-BAPI-SIGN' => hash_hmac('sha256', $str, $this->secret)];
    }
    private function get($path, $params = [], $retry = 2) {
        ksort($params);
        for ($a = 0; $a <= $retry; $a++) {
            $hdrs = $this->signGet($params);
            $url  = $this->base . $path . '?' . http_build_query($params);
            $ch   = curl_init($url);
            $headersArr = [];
            foreach ($hdrs as $k => $v) { $headersArr[] = "$k: $v"; }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'EthGridBot/15.4',
                CURLOPT_HTTPHEADER => $headersArr,
            ]);
            $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
            if ($resp === false) { if ($a < $retry) { usleep(600000); continue; } throw new \RuntimeException("GET $path: $err"); }
            $d = json_decode((string)$resp, true); $rc = isset($d['retCode']) ? $d['retCode'] : -1;
            if ($rc === 0) return isset($d['result']) ? $d['result'] : [];
            if (in_array($rc, [10002, 10006]) && $a < $retry) { sleep(1); continue; }
            throw new \RuntimeException("Bybit GET [{$rc}]: " . (isset($d['retMsg']) ? $d['retMsg'] : $resp));
        }
        throw new \RuntimeException("GET $path: agotados reintentos");
    }
    private function post($path, $params, $retry = 2) {
        $body = json_encode($params);
        for ($a = 0; $a <= $retry; $a++) {
            $hdrs = $this->signPost($body);
            $headersArr = [];
            foreach ($hdrs as $k => $v) { $headersArr[] = "$k: $v"; }
            $r = hPost($this->base . $path, $body, $headersArr);
            $d = $r['body']; $rc = isset($d['retCode']) ? $d['retCode'] : -1;
            if ($rc === 0) return isset($d['result']) ? $d['result'] : [];
            if (in_array($rc, [10002, 10006, 110007]) && $a < $retry) { sleep(1); continue; }
            throw new \RuntimeException("Bybit POST [{$rc}]: " . (isset($d['retMsg']) ? $d['retMsg'] : json_encode($d)));
        }
        throw new \RuntimeException("POST $path: agotados reintentos");
    }
    public function validate() {
        $r = $this->get('/v5/account/wallet-balance', ['accountType' => 'UNIFIED']);
        lI("[Bybit] API OK – cuenta UNIFIED"); return $r;
    }
    private function getPub($path, $params = [], $retry = 2) {
        ksort($params);
        $url = $this->pub . $path . '?' . http_build_query($params);
        for ($a = 0; $a <= $retry; $a++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'EthGridBot/15.4',
            ]);
            $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
            if ($resp === false) { if ($a < $retry) { usleep(500000); continue; } throw new \RuntimeException("getPub $path: $err"); }
            $d = json_decode((string)$resp, true); $rc = isset($d['retCode']) ? $d['retCode'] : -1;
            if ($rc === 0) return isset($d['result']) ? $d['result'] : [];
            if ($a < $retry) { usleep(400000); continue; }
            throw new \RuntimeException("Bybit PUB [{$rc}]: " . (isset($d['retMsg']) ? $d['retMsg'] : ''));
        }
        return [];
    }
    public function price($symbol) {
        try {
            $r = $this->getPub('/v5/market/tickers', ['category' => 'linear', 'symbol' => $symbol]);
            $px = (float)(isset($r['list'][0]['lastPrice']) ? $r['list'][0]['lastPrice'] : 0);
            if ($px > 0) return $px;
        } catch (\Exception $e) { lW("[Bybit] price (pub): " . $e->getMessage()); }
        try {
            $r = $this->get('/v5/market/tickers', ['category' => 'linear', 'symbol' => $symbol]);
            return (float)(isset($r['list'][0]['lastPrice']) ? $r['list'][0]['lastPrice'] : 0);
        } catch (\Exception $e) { lW("[Bybit] price (auth): " . $e->getMessage()); }
        return 0;
    }
    public function klines($symbol, $interval, $limit) {
        $bybitSymbol = strtoupper($symbol);
        $bybitIv     = $interval . 'm';
        if (in_array($interval, ['60','120','240','360','720'])) {
            $hrs = (int)($interval) / 60;
            $bybitIv = $hrs . 'h';
        } elseif ($interval === '1D' || $interval === 'D') {
            $bybitIv = '1d';
        }
        $sources = [
            ['url' => "https://api.bybit.com/v5/market/kline?category=linear&symbol={$bybitSymbol}&interval={$interval}&limit={$limit}", 'parser' => 'bybit', 'tag' => 'bybit-mainnet'],
        ];
        foreach ($sources as $src) {
            try {
                $ch = curl_init($src['url']);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
                    CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_USERAGENT      => 'EthGridBot/15.4',
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                ]);
                $resp = curl_exec($ch); $curlErr = curl_error($ch); curl_close($ch);
                if ($resp === false || strlen($resp) < 10) {
                    lW(sprintf("[klines] %s curl: %s", $src['tag'], $curlErr));
                    continue;
                }
                $data = json_decode($resp, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    lW(sprintf("[klines] %s JSON inválido", $src['tag']));
                    continue;
                }
                $out = [];
                if ($src['parser'] === 'bybit') {
                    $rc = isset($data['retCode']) ? $data['retCode'] : -1;
                    if ($rc !== 0) {
                        lW(sprintf("[klines] %s retCode=%d", $src['tag'], $rc));
                        continue;
                    }
                    $list = isset($data['result']['list']) ? $data['result']['list'] : [];
                    if (empty($list)) {
                        lW(sprintf("[klines] %s retCode=0 lista vacía (demo sin datos históricos)", $src['tag']));
                        continue;
                    }
                    foreach (array_reverse($list) as $k)
                        $out[] = [(int)$k[0], (float)$k[1], (float)$k[2], (float)$k[3], (float)$k[4], (float)$k[5]];
                }
                if (count($out) >= 30) {
                    lI(sprintf("[klines] ✓ %d velas [%s]", count($out), $src['tag']));
                    return $out;
                }
                lW(sprintf("[klines] %s: solo %d velas (< 30)", $src['tag'], count($out)));
            } catch (\Exception $e) {
                lW(sprintf("[klines] %s ex: %s", $src['tag'], $e->getMessage()));
            }
        }
        lE("[klines] TODAS las fuentes fallaron. Sin datos de velas.");
        return [];
    }
    public function filters($symbol) {
        if (!isset($this->fc[$symbol])) {
            try {
                $r = $this->getPub('/v5/market/instruments-info', ['category' => 'linear', 'symbol' => $symbol]);
                $info = isset($r['list'][0]) ? $r['list'][0] : [];
                $lot = isset($info['lotSizeFilter']) ? $info['lotSizeFilter'] : [];
                $prx = isset($info['priceFilter']) ? $info['priceFilter'] : [];
                $step = (float)(isset($lot['qtyStep']) ? $lot['qtyStep'] : 0.01);
                $tick = (float)(isset($prx['tickSize']) ? $prx['tickSize'] : 0.01);
                $this->fc[$symbol] = ['step' => $step, 'tick' => $tick,
                    'mn' => (float)(isset($lot['minOrderQty']) ? $lot['minOrderQty'] : 0.01),
                    'qp' => max(0, (int)round(-log10(max($step, 1e-8)))),
                    'pp' => max(0, (int)round(-log10(max($tick, 1e-8))))];
            } catch (\Exception $e) {
                $this->fc[$symbol] = ['step' => 0.01, 'tick' => 0.01, 'mn' => 0.01, 'qp' => 2, 'pp' => 2];
            }
        }
        return $this->fc[$symbol];
    }
    public function balance() {
        try {
            $r = $this->get('/v5/account/wallet-balance', ['accountType' => 'UNIFIED']);
            foreach (isset($r['list']) ? $r['list'] : [] as $acc) {
                $accAvail = (float)(isset($acc['totalAvailableBalance']) ? $acc['totalAvailableBalance'] : 0);
                if ($accAvail > 0) return $accAvail;
                foreach (isset($acc['coin']) ? $acc['coin'] : [] as $c) {
                    if (($c['coin'] ?? '') !== 'USDT') continue;
                    foreach (['availableToWithdraw','availableBalance','walletBalance','equity'] as $fld) {
                        $v = (float)(isset($c[$fld]) ? $c[$fld] : 0); if ($v > 0) return $v;
                    }
                }
                $eq = (float)(isset($acc['totalEquity']) ? $acc['totalEquity'] : 0);
                if ($eq > 0) return $eq;
            }
            lW("[Bybit] Balance 0 — sin saldo USDT libre"); return 0.0;
        } catch (\Exception $e) { lW("[Bybit] Error balance: " . $e->getMessage()); return 0.0; }
    }
    public function positions($symbol) {
        $r = $this->get('/v5/position/list', ['category' => 'linear', 'symbol' => $symbol]);
        $out = [];
        foreach (isset($r['list']) ? $r['list'] : [] as $p) {
            $sz = (float)(isset($p['size']) ? $p['size'] : 0); if ($sz < 0.001) continue;
            $out[] = ['positionAmt' => (isset($p['side']) && $p['side'] === 'Buy') ? $sz : -$sz,
                      'entryPrice' => (float)(isset($p['avgPrice']) ? $p['avgPrice'] : 0),
                      'unRealizedProfit' => (float)(isset($p['unrealisedPnl']) ? $p['unrealisedPnl'] : 0),
                      'liquidationPrice' => (float)(isset($p['liqPrice']) ? $p['liqPrice'] : 0),
                      'side' => isset($p['side']) ? $p['side'] : '', 'size' => $sz];
        }
        return $out;
    }
    public function setLeverage($symbol, $lev) {
        if (isset($this->levMem[$symbol]) && $this->levMem[$symbol] === $lev) return;
        try {
            $this->post('/v5/position/set-leverage', ['category' => 'linear', 'symbol' => $symbol,
                'buyLeverage' => (string)$lev, 'sellLeverage' => (string)$lev]);
            $this->levMem[$symbol] = $lev;
            lI("[Bybit] Leverage {$lev}x OK");
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'leverage not modified') !== false) $this->levMem[$symbol] = $lev;
            else lW("[Bybit] setLeverage: " . $e->getMessage());
        }
    }
    public function limitOrder($symbol, $side, $qty, $price, $reduceOnly = false, $postOnly = true) {
        $f = $this->filters($symbol);
        $qty = max($f['mn'], $f['step'], round($qty / $f['step']) * $f['step']);
        $pr  = round($price / $f['tick']) * $f['tick'];
        $r = $this->post('/v5/order/create', [
            'category' => 'linear', 'symbol' => $symbol, 'side' => ucfirst(strtolower($side)),
            'orderType' => 'Limit', 'qty' => number_format($qty, $f['qp'], '.', ''),
            'price' => number_format($pr, $f['pp'], '.', ''),
            'timeInForce' => $postOnly ? 'PostOnly' : 'GTC', 'reduceOnly' => $reduceOnly,
            'orderLinkId' => uniqid('g154_', true),
        ]);
        return ['orderId' => $r['orderId'], 'price' => $pr, 'qty' => $qty];
    }
    public function marketClose($symbol, $side, $qty) {
        $f = $this->filters($symbol);
        $qty = max($f['mn'], $f['step'], round($qty / $f['step']) * $f['step']);
        $cside = $side === 'Buy' ? 'Sell' : 'Buy';
        $r = $this->post('/v5/order/create', [
            'category' => 'linear', 'symbol' => $symbol, 'side' => $cside,
            'orderType' => 'Market', 'qty' => number_format($qty, $f['qp'], '.', ''),
            'timeInForce' => 'IOC', 'reduceOnly' => true, 'orderLinkId' => uniqid('mc_', true),
        ]);
        return isset($r['orderId']) ? $r['orderId'] : null;
    }
    public function getOrder($symbol, $orderId) {
        $map = ['New' => 'NEW', 'PartiallyFilled' => 'PARTIALLY_FILLED', 'Filled' => 'FILLED',
                'Cancelled' => 'CANCELED', 'Rejected' => 'CANCELED', 'Expired' => 'CANCELED'];
        try {
            $r = $this->get('/v5/order/realtime', ['category' => 'linear', 'symbol' => $symbol, 'orderId' => $orderId]);
            if (!empty($r['list'])) {
                $o = $r['list'][0];
                return ['status' => isset($map[$o['orderStatus']]) ? $map[$o['orderStatus']] : 'UNKNOWN',
                        'avgPrice' => (float)(isset($o['avgPrice']) ? $o['avgPrice'] : (isset($o['price']) ? $o['price'] : 0)),
                        'qty' => (float)(isset($o['cumExecQty']) ? $o['cumExecQty'] : (isset($o['qty']) ? $o['qty'] : 0)),
                        'orderType' => $o['orderType'] ?? 'Limit'];
            }
        } catch (\Exception $e) {}
        try {
            $r = $this->get('/v5/order/history', ['category' => 'linear', 'symbol' => $symbol,
                                                   'orderId' => $orderId, 'limit' => 1]);
            if (!empty($r['list'])) {
                $o = $r['list'][0];
                return ['status' => isset($map[$o['orderStatus']]) ? $map[$o['orderStatus']] : 'UNKNOWN',
                        'avgPrice' => (float)(isset($o['avgPrice']) ? $o['avgPrice'] : (isset($o['price']) ? $o['price'] : 0)),
                        'qty' => (float)(isset($o['cumExecQty']) ? $o['cumExecQty'] : (isset($o['qty']) ? $o['qty'] : 0)),
                        'orderType' => $o['orderType'] ?? 'Limit'];
            }
        } catch (\Exception $e) {}
        return ['status' => 'UNKNOWN', 'avgPrice' => 0, 'qty' => 0, 'orderType' => 'Limit'];
    }
    public function getOpenOrders($symbol) {
        try {
            $r = $this->get('/v5/order/realtime', ['category' => 'linear', 'symbol' => $symbol,
                                                     'limit' => 50, 'orderFilter' => 'Order']);
            $orders = [];
            foreach (isset($r['list']) ? $r['list'] : [] as $o) {
                $orders[$o['orderId']] = ['orderId' => $o['orderId'], 'price' => (float)(isset($o['price']) ? $o['price'] : 0),
                    'qty' => (float)(isset($o['qty']) ? $o['qty'] : 0), 'side' => isset($o['side']) ? $o['side'] : '',
                    'status' => isset($o['orderStatus']) ? $o['orderStatus'] : '', 'avgPrice' => (float)(isset($o['avgPrice']) ? $o['avgPrice'] : (isset($o['price']) ? $o['price'] : 0)),
                    'cumExecQty' => (float)(isset($o['cumExecQty']) ? $o['cumExecQty'] : (isset($o['qty']) ? $o['qty'] : 0)),
                    'orderType' => $o['orderType'] ?? 'Limit'];
            }
            return $orders;
        } catch (\Exception $e) {
            lW("[Bybit] getOpenOrders: " . $e->getMessage()); return [];
        }
    }
    public function cancelAll($symbol) {
        try {
            $this->post('/v5/order/cancel-all', ['category' => 'linear', 'symbol' => $symbol]);
            sleep(1); lI("[Bybit] cancelAll $symbol OK");
        } catch (\Exception $e) { lW("[Bybit] cancelAll: " . $e->getMessage()); }
    }
}
