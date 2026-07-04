<?php
/**
 * save_chart.php – Recibe captura del gráfico desde el frontend y la guarda para el bot.
 */

header('Content-Type: application/json');

// Si se ejecuta en CLI, mostrar mensaje amigable y salir
if (php_sapi_name() === 'cli') {
    echo json_encode([
        'ok' => false,
        'error' => 'Este script debe ejecutarse mediante petición HTTP POST desde el navegador.'
    ]);
    exit;
}

// Verificar método HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// Leer y validar datos
$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['image'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No image data received.']);
    exit;
}

$data = $input['image'];
if (strpos($data, 'base64,') !== false) {
    $data = explode(',', $data)[1];
}

$img = base64_decode($data);
if ($img === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid base64 image data.']);
    exit;
}

$path = '/tmp/latest_chart.png';
if (file_put_contents($path, $img) !== false) {
    chmod($path, 0644);
    echo json_encode(['ok' => true, 'path' => $path]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to write file to ' . $path]);
}
?>
