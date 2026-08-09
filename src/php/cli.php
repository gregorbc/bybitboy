#!/usr/bin/env php
<?php
declare(strict_types=1);

// Herramienta CLI: rechazar acceso web
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require __DIR__ . '/../../vendor/autoload.php';

use BinanceBot\Core\Cli;
use BinanceBot\Core\Config;
use BinanceBot\Core\Database;
use BinanceBot\Core\Schema;

$db = Database::getInstance();
if (!$db->isConnected()) {
    fwrite(STDERR, "ERROR: sin conexión MySQL\n");
    exit(1);
}
$pdo = $db->getPdo();
Schema::createTables($pdo);

$command = $argv[1] ?? '';
$secret = getenv('PLATFORM_SECRET') ?: '';
if ($secret === '') {
    fwrite(STDERR, "ERROR: define PLATFORM_SECRET en .env o en el entorno\n");
    exit(1);
}

echo implode("\n", Cli::run($pdo, $command, $secret, array_slice($argv, 2))) . "\n";