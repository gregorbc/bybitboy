<?php
/**
 * Test de Configuración - Grid Bot v15.4
 * Verifica que ConfigLoader funcione correctamente
 */

require_once __DIR__ . '/ConfigLoader.php';

echo "============================================\n";
echo "  Grid Bot - Test de Configuración\n";
echo "============================================\n\n";

// Obtener instancia
$configLoader = ConfigLoader::getInstance();

// Test 1: Verificar carga de variables de entorno
echo "[Test 1] Carga de variables de entorno:\n";
$envLoaded = $configLoader->isEnvLoaded() ? '✓ PASÓ' : '✗ FALLÓ';
echo "  .env cargado: {$envLoaded}\n\n";

// Test 2: Verificar credenciales críticas
echo "[Test 2] Credenciales críticas:\n";
$apiKey = $configLoader->get('bybit.api_key');
$apiSecret = $configLoader->get('bybit.api_secret');
$dbPassword = $configLoader->get('mysql.password');
$securityToken = $configLoader->get('security_token');
$wsToken = $configLoader->get('ws_token');

echo "  BYBIT_API_KEY: " . ($apiKey ? '✓ Configurada (' . substr($apiKey, 0, 8) . '...)' : '✗ Faltante') . "\n";
echo "  BYBIT_API_SECRET: " . ($apiSecret ? '✓ Configurada (' . substr($apiSecret, 0, 8) . '...)' : '✗ Faltante') . "\n";
echo "  MYSQL_PASSWORD: " . ($dbPassword ? '✓ Configurada (' . substr($dbPassword, 0, 8) . '...)' : '✗ Faltante') . "\n";
echo "  SECURITY_TOKEN: " . ($securityToken ? '✓ Generado (' . substr($securityToken, 0, 16) . '...)' : '✗ Faltante') . "\n";
echo "  WS_TOKEN: " . ($wsToken ? '✓ Generado (' . substr($wsToken, 0, 16) . '...)' : '✗ Faltante') . "\n\n";

// Test 3: Validación automática
echo "[Test 3] Validación de configuración:\n";
$errors = $configLoader->validate();
if (empty($errors)) {
    echo "  ✓ Todas las validaciones pasaron\n\n";
} else {
    echo "  ✗ Errores encontrados:\n";
    foreach ($errors as $error) {
        echo "    - $error\n";
    }
    echo "\n";
}

// Test 4: Verificar configuración del bot
echo "[Test 4] Configuración del bot:\n";
$symbol = $configLoader->get('bot.symbol', 'NO CONFIGURADO');
$leverage = $configLoader->get('bot.leverage', 0);
$capital = $configLoader->get('bot.capital_usd', 0);
$timeframe = $configLoader->get('bot.timeframe', '0');

echo "  Símbolo: {$symbol}\n";
echo "  Apalancamiento: {$leverage}x\n";
echo "  Capital: \${$capital} USD\n";
echo "  Timeframe: M{$timeframe}\n\n";

// Test 5: Verificar paths
echo "[Test 5] Rutas configuradas:\n";
$logPath = $configLoader->get('paths.log', 'NO CONFIGURADO');
$webDir = $configLoader->get('paths.web_dir', 'NO CONFIGURADO');
$pidPath = $configLoader->get('paths.pid', 'NO CONFIGURADO');

echo "  Log: {$logPath}\n";
echo "  Web Dir: {$webDir}\n";
echo "  PID: {$pidPath}\n\n";

// Resumen final
echo "============================================\n";
if (empty($errors) && $apiKey && $apiSecret && $dbPassword && $securityToken && $wsToken) {
    echo "  ✅ TODOS LOS TESTS PASARON\n";
    echo "  La migración de seguridad fue exitosa!\n";
} else {
    echo "  ⚠️ ALGUNOS TESTS FALLARON\n";
    echo "  Revisar los errores arriba y corregir .env\n";
}
echo "============================================\n";

?>
