<?php
declare(strict_types=1);

namespace BinanceBot\Dashboard;

class Router
{
    private Api $api;

    public function __construct()
    {
        $this->api = new Api();
    }

    public function dispatch(array $get): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        $response = match (true) {
            isset($get['_health']) => $this->api->health(),
            isset($get['_logs']) => $this->api->logs(),
            default => ['error' => 'Unknown endpoint'],
        };

        echo json_encode($response);
    }
}
