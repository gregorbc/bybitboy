<?php
declare(strict_types=1);

function resetGlobals(): void {
    $_GET = [];
    $_POST = [];
    $_SERVER = ['REQUEST_METHOD' => 'GET'];
}

function captureOutput(callable $fn): string {
    resetGlobals();
    ob_start();
    $fn();
    return ob_get_clean();
}
