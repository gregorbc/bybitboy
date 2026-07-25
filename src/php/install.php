<?php
/**
 * Grid Bot v15.4 - Instalador Web
 * Compatible con cPanel y hosting compartido
 * 
 * Características:
 * - Verificación de requisitos del servidor
 * - Configuración de .env desde interfaz web
 * - Creación automática de tablas MySQL
 * - Configuración de permisos de archivos
 * - Pruebas de conexión a Bybit y MySQL
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar errores en producción

// Deshabilitar timeout para instalaciones largas
set_time_limit(300);

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$errors = [];
$success = [];
$config = [];

// Cargar configuración guardada en sesión
if (isset($_SESSION['config'])) {
    $config = $_SESSION['config'];
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'save_config') {
        // Guardar configuración en sesión
        $_SESSION['config'] = [
            'db_host' => trim($_POST['db_host'] ?? 'localhost'),
            'db_name' => trim($_POST['db_name'] ?? ''),
            'db_user' => trim($_POST['db_user'] ?? ''),
            'db_pass' => $_POST['db_pass'] ?? '',
            'bybit_api_key' => trim($_POST['bybit_api_key'] ?? ''),
            'bybit_api_secret' => trim($_POST['bybit_api_secret'] ?? ''),
            'bybit_testnet' => isset($_POST['bybit_testnet']) ? 1 : 0,
            'symbol' => strtoupper(trim($_POST['symbol'] ?? 'BTCUSDT')),
            'installation_path' => dirname(__DIR__),
        ];
        $config = $_SESSION['config'];
        $step = 2;
    }
    
    if ($action === 'test_connections') {
        $config = $_SESSION['config'] ?? [];
        
        // Test MySQL
        try {
            $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $success[] = "✓ Conexión a MySQL exitosa";
            $_SESSION['mysql_ok'] = true;
        } catch (Exception $e) {
            $errors[] = "✗ Error MySQL: " . $e->getMessage();
            $_SESSION['mysql_ok'] = false;
        }
        
        // Test Bybit (solo si hay credenciales)
        if (!empty($config['bybit_api_key']) && !empty($config['bybit_api_secret'])) {
            $baseUrl = $config['bybit_testnet'] 
                ? 'https://api-testnet.bybit.com' 
                : 'https://api.bybit.com';
            
            $ch = curl_init($baseUrl . '/v5/market/tickers?category=linear&symbol=' . $config['symbol']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $success[] = "✓ Conexión a Bybit exitosa (" . ($config['bybit_testnet'] ? 'Testnet' : 'Mainnet') . ")";
                $_SESSION['bybit_ok'] = true;
            } else {
                $errors[] = "✗ Error Bybit: HTTP $httpCode";
                $_SESSION['bybit_ok'] = false;
            }
        } else {
            $errors[] = "⚠ Credenciales de Bybit no configuradas";
            $_SESSION['bybit_ok'] = false;
        }
        
        $step = 3;
    }
    
    if ($action === 'create_tables') {
        $config = $_SESSION['config'] ?? [];
        
        try {
            $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            
            // Leer SQL desde archivo
            $sqlFile = __DIR__ . '/install.sql';
            if (!file_exists($sqlFile)) {
                throw new Exception("Archivo install.sql no encontrado");
            }
            
            $sql = file_get_contents($sqlFile);
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            
            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    $pdo->exec($statement);
                }
            }
            
            $success[] = "✓ Tablas creadas exitosamente";
            $_SESSION['tables_created'] = true;
            $step = 4;
            
        } catch (Exception $e) {
            $errors[] = "✗ Error creando tablas: " . $e->getMessage();
            $step = 3;
        }
    }
    
    if ($action === 'finalize') {
        $config = $_SESSION['config'] ?? [];
        
        // Crear archivo .env
        $envContent = <<<ENV
# Grid Bot v15.4 - Configuración
# Generado automáticamente por el instalador

# Base de Datos
DB_HOST={$config['db_host']}
DB_NAME={$config['db_name']}
DB_USER={$config['db_user']}
DB_PASS={$config['db_pass']}

# Bybit API
BYBIT_API_KEY={$config['bybit_api_key']}
BYBIT_API_SECRET={$config['bybit_api_secret']}
BYBIT_TESTNET={$config['bybit_testnet']}

# Símbolo de Trading
SYMBOL={$config['symbol']}

# Ruta de instalación
INSTALL_PATH={$config['installation_path']}

# Entorno
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=info
ENV;
        
        $envPath = __DIR__ . '/.env';
        if (file_put_contents($envPath, $envContent) === false) {
            $errors[] = "✗ No se pudo crear el archivo .env";
        } else {
            // Proteger .env con .htaccess
            $htaccessContent = "<Files \".env\">\n    Order allow,deny\n    Deny from all\n</Files>\n";
            file_put_contents(__DIR__ . '/.htaccess', $htaccessContent, FILE_APPEND);
            
            $success[] = "✓ Archivo .env creado";
            
            // Marcar como instalado
            file_put_contents(__DIR__ . '/.installed', date('Y-m-d H:i:s'));
            $success[] = "✓ Instalación completada";
            
            // Limpiar sesión
            session_destroy();
            $step = 5;
        }
    }
}

// Función para verificar requisitos
function checkRequirements() {
    $requirements = [
        'PHP >= 7.4' => version_compare(PHP_VERSION, '7.4.0', '>='),
        'PDO MySQL' => extension_loaded('pdo_mysql'),
        'cURL' => extension_loaded('curl'),
        'JSON' => extension_loaded('json'),
        'OpenSSL' => extension_loaded('openssl'),
        'writeable' => is_writable(__DIR__),
    ];
    
    return $requirements;
}

$requirements = checkRequirements();
$allRequirementsMet = !in_array(false, $requirements, true);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador Grid Bot v15.4</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { font-size: 28px; margin-bottom: 10px; }
        .header p { opacity: 0.9; }
        .progress {
            display: flex;
            background: #f8f9fa;
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
        }
        .step {
            flex: 1;
            text-align: center;
            padding: 10px;
            position: relative;
        }
        .step-number {
            display: inline-block;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #dee2e6;
            color: #6c757d;
            line-height: 30px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .step.active .step-number {
            background: #667eea;
            color: white;
        }
        .step.completed .step-number {
            background: #28a745;
            color: white;
        }
        .content { padding: 40px; }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        .form-group input[type="text"],
        .form-group input[type="password"],
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .checkbox-group input { width: auto; }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .btn-secondary {
            background: #6c757d;
        }
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        .requirement-list {
            list-style: none;
        }
        .requirement-list li {
            padding: 10px 0;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .requirement-list li:last-child {
            border-bottom: none;
        }
        .status-ok { color: #28a745; font-weight: bold; }
        .status-fail { color: #dc3545; font-weight: bold; }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .hidden { display: none; }
        .code-block {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 15px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            overflow-x: auto;
            margin: 15px 0;
        }
        h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 22px;
        }
        h3 {
            color: #555;
            margin: 20px 0 10px;
            font-size: 18px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Grid Bot v15.4</h1>
            <p>Instalador Automático para cPanel y Hosting Compartido</p>
        </div>
        
        <div class="progress">
            <div class="step <?= $step >= 1 ? 'active' : '' ?> <?= $step > 1 ? 'completed' : '' ?>">
                <div class="step-number">1</div>
                <div>Requisitos</div>
            </div>
            <div class="step <?= $step >= 2 ? 'active' : '' ?> <?= $step > 2 ? 'completed' : '' ?>">
                <div class="step-number">2</div>
                <div>Configuración</div>
            </div>
            <div class="step <?= $step >= 3 ? 'active' : '' ?> <?= $step > 3 ? 'completed' : '' ?>">
                <div class="step-number">3</div>
                <div>Pruebas</div>
            </div>
            <div class="step <?= $step >= 4 ? 'active' : '' ?> <?= $step > 4 ? 'completed' : '' ?>">
                <div class="step-number">4</div>
                <div>Tablas</div>
            </div>
            <div class="step <?= $step >= 5 ? 'active' : '' ?>">
                <div class="step-number">5</div>
                <div>Finalizar</div>
            </div>
        </div>
        
        <div class="content">
            <?php if (!empty($errors)): ?>
                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <?php foreach ($success as $success_msg): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- Paso 1: Verificación de Requisitos -->
            <?php if ($step === 1): ?>
                <h2>Verificación de Requisitos del Servidor</h2>
                
                <?php if (!$allRequirementsMet): ?>
                    <div class="alert alert-warning">
                        ⚠ Algunos requisitos no se cumplen. Por favor, contacta a tu proveedor de hosting o corrige los problemas antes de continuar.
                    </div>
                <?php else: ?>
                    <div class="alert alert-success">
                        ✓ Todos los requisitos se cumplen. Puedes continuar con la instalación.
                    </div>
                <?php endif; ?>
                
                <ul class="requirement-list">
                    <?php foreach ($requirements as $req => $met): ?>
                        <li>
                            <span><?= htmlspecialchars($req) ?></span>
                            <span class="<?= $met ? 'status-ok' : 'status-fail' ?>">
                                <?= $met ? '✓ Cumple' : '✗ No cumple' ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                
                <div class="info-box">
                    <strong>ℹ️ Información:</strong><br>
                    Este instalador creará las tablas necesarias en tu base de datos MySQL y generará un archivo <code>.env</code> con tu configuración.
                    Asegúrate de tener a mano tus credenciales de Bybit y los datos de tu base de datos.
                </div>
                
                <div style="text-align: right; margin-top: 30px;">
                    <a href="?step=2" class="btn" <?= !$allRequirementsMet ? 'style="opacity:0.5;pointer-events:none;"' : '' ?>>
                        Continuar →
                    </a>
                </div>
            
            <!-- Paso 2: Configuración -->
            <?php elseif ($step === 2): ?>
                <h2>Configuración del Grid Bot</h2>
                
                <form method="POST">
                    <input type="hidden" name="action" value="save_config">
                    
                    <h3>📊 Base de Datos MySQL</h3>
                    <div class="form-group">
                        <label for="db_host">Host de la Base de Datos</label>
                        <input type="text" id="db_host" name="db_host" value="<?= htmlspecialchars($config['db_host'] ?? 'localhost') ?>" required>
                        <small>Generalmente es "localhost" en cPanel</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="db_name">Nombre de la Base de Datos</label>
                        <input type="text" id="db_name" name="db_name" value="<?= htmlspecialchars($config['db_name'] ?? '') ?>" required placeholder="ej: usuario_gridbot">
                    </div>
                    
                    <div class="form-group">
                        <label for="db_user">Usuario de la Base de Datos</label>
                        <input type="text" id="db_user" name="db_user" value="<?= htmlspecialchars($config['db_user'] ?? '') ?>" required placeholder="ej: usuario_db">
                    </div>
                    
                    <div class="form-group">
                        <label for="db_pass">Contraseña de la Base de Datos</label>
                        <input type="password" id="db_pass" name="db_pass" value="<?= htmlspecialchars($config['db_pass'] ?? '') ?>" required>
                    </div>
                    
                    <h3>🔑 API de Bybit</h3>
                    <div class="form-group">
                        <label for="bybit_api_key">API Key de Bybit</label>
                        <input type="text" id="bybit_api_key" name="bybit_api_key" value="<?= htmlspecialchars($config['bybit_api_key'] ?? '') ?>" required>
                        <small>Genera una API Key en <a href="https://www.bybit.com/app/user/api-management" target="_blank">Bybit</a></small>
                    </div>
                    
                    <div class="form-group">
                        <label for="bybit_api_secret">API Secret de Bybit</label>
                        <input type="password" id="bybit_api_secret" name="bybit_api_secret" value="<?= htmlspecialchars($config['bybit_api_secret'] ?? '') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="bybit_testnet" name="bybit_testnet" <?= isset($config['bybit_testnet']) && $config['bybit_testnet'] ? 'checked' : '' ?>>
                            <label for="bybit_testnet" style="margin:0;font-weight:normal;">Usar Bybit Testnet (modo prueba)</label>
                        </div>
                    </div>
                    
                    <h3>💹 Configuración de Trading</h3>
                    <div class="form-group">
                        <label for="symbol">Símbolo de Trading</label>
                        <input type="text" id="symbol" name="symbol" value="<?= htmlspecialchars($config['symbol'] ?? 'BTCUSDT') ?>" required placeholder="ej: BTCUSDT, ETHUSDT">
                        <small>Debe ser un par válido en Bybit (formato: XXXUSDT)</small>
                    </div>
                    
                    <div style="text-align: right; margin-top: 30px;">
                        <a href="?step=1" class="btn btn-secondary">← Atrás</a>
                        <button type="submit" class="btn">Guardar y Continuar →</button>
                    </div>
                </form>
            
            <!-- Paso 3: Pruebas de Conexión -->
            <?php elseif ($step === 3): ?>
                <h2>Pruebas de Conexión</h2>
                
                <div class="info-box">
                    Vamos a verificar que las conexiones a MySQL y Bybit funcionen correctamente con tu configuración.
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="test_connections">
                    <button type="submit" class="btn">🔄 Ejecutar Pruebas</button>
                </form>
                
                <?php if (isset($_SESSION['mysql_ok']) || isset($_SESSION['bybit_ok'])): ?>
                    <div style="margin-top: 30px;">
                        <h3>Resultados:</h3>
                        <ul class="requirement-list">
                            <li>
                                <span>Conexión MySQL</span>
                                <span class="<?= $_SESSION['mysql_ok'] ?? false ? 'status-ok' : 'status-fail' ?>">
                                    <?= ($_SESSION['mysql_ok'] ?? false) ? '✓ Exitosa' : '✗ Fallida' ?>
                                </span>
                            </li>
                            <li>
                                <span>Conexión Bybit</span>
                                <span class="<?= $_SESSION['bybit_ok'] ?? false ? 'status-ok' : 'status-fail' ?>">
                                    <?= ($_SESSION['bybit_ok'] ?? false) ? '✓ Exitosa' : '✗ Fallida' ?>
                                </span>
                            </li>
                        </ul>
                    </div>
                    
                    <?php if (($_SESSION['mysql_ok'] ?? false) && ($_SESSION['bybit_ok'] ?? false)): ?>
                        <form method="POST" style="margin-top: 30px;">
                            <input type="hidden" name="action" value="create_tables">
                            <div style="text-align: right;">
                                <a href="?step=2" class="btn btn-secondary">← Volver a Configurar</a>
                                <button type="submit" class="btn">Crear Tablas →</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning" style="margin-top: 20px;">
                            ⚠ Corrige los errores antes de continuar.
                        </div>
                        <div style="text-align: right; margin-top: 20px;">
                            <a href="?step=2" class="btn btn-secondary">← Volver a Configurar</a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            
            <!-- Paso 4: Creación de Tablas -->
            <?php elseif ($step === 4): ?>
                <h2>Creación de Tablas en la Base de Datos</h2>
                
                <?php if (!isset($_SESSION['tables_created']) || !$_SESSION['tables_created']): ?>
                    <div class="info-box">
                        El instalador creará las siguientes tablas en tu base de datos:<br>
                        <code>grid_positions</code>, <code>grid_orders</code>, <code>grid_fills</code>, 
                        <code>grid_stats</code>, <code>grid_logs</code>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="create_tables">
                        <button type="submit" class="btn">🔨 Crear Tablas</button>
                    </form>
                    
                    <div style="text-align: right; margin-top: 20px;">
                        <a href="?step=3" class="btn btn-secondary">← Atrás</a>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success">
                        ✓ Las tablas han sido creadas exitosamente.
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="finalize">
                        <div style="text-align: right; margin-top: 30px;">
                            <button type="submit" class="btn">Finalizar Instalación 🎉</button>
                        </div>
                    </form>
                <?php endif; ?>
            
            <!-- Paso 5: Finalización -->
            <?php elseif ($step === 5): ?>
                <div style="text-align: center; padding: 40px 0;">
                    <div style="font-size: 80px; margin-bottom: 20px;">🎉</div>
                    <h2>¡Instalación Completada!</h2>
                    <p style="color: #666; margin: 20px 0;">
                        Tu Grid Bot v15.4 ha sido instalado exitosamente.
                    </p>
                    
                    <div class="info-box" style="text-align: left; display: inline-block;">
                        <strong>Próximos pasos:</strong><br><br>
                        1. <strong>Configura el bot:</strong> Edita <code>bot.php</code> si necesitas ajustar parámetros<br>
                        2. <strong>Inicia el bot:</strong> Ejecuta <code>php bot.php</code> desde SSH o configura un cron job<br>
                        3. <strong>Accede al dashboard:</strong> Abre <code>index.php</code> en tu navegador<br>
                        4. <strong>Inicia WebSocket:</strong> Ejecuta <code>php websocket_server.php</code> para actualizaciones en tiempo real
                    </div>
                    
                    <div class="code-block">
                        <strong>Comandos para iniciar:</strong><br><br>
                        # Iniciar el bot (desde SSH)<br>
                        php bot.php<br><br>
                        # Iniciar servidor WebSocket<br>
                        php websocket_server.php<br><br>
                        # O agregar al crontab<br>
                        * * * * * cd <?= htmlspecialchars(dirname(__DIR__)) ?> && php bot.php
                    </div>
                    
                    <div style="margin-top: 30px;">
                        <a href="index.php" class="btn">Ir al Dashboard 📊</a>
                    </div>
                    
                    <p style="margin-top: 30px; color: #999; font-size: 14px;">
                        ⚠️ <strong>Importante:</strong> Elimina el archivo <code>install.php</code> después de usarlo por seguridad.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
