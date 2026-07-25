<?php
/**
 * Instalador Web para Grid Bot en HestiaCP
 * 
 * Este script verifica y configura el entorno en servidores con HestiaCP.
 * - Verifica versión de PHP y extensiones.
 * - Crea directorios necesarios (data, logs, config).
 * - Genera archivos de configuración iniciales.
 * - Configura permisos adecuados.
 * - Opcional: Instala dependencias de Python y Redis.
 * 
 * @author Grid Bot Team
 * @version 2.0.0
 */

// Deshabilitar temporalmente la ejecución máxima para instalaciones largas
set_time_limit(300);
ini_set('memory_limit', '512M');

// Configuración inicial
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$message = '';
$messageType = ''; // success, error, warning
$configCreated = false;

// Rutas base
$basePath = dirname(__DIR__); // Sube desde src/php/ a la raíz del proyecto
$dataPath = $basePath . '/data';
$logPath = $dataPath . '/logs';
$modelPath = $dataPath . '/models';
$cachePath = $dataPath . '/cache';
$configPath = $basePath . '/config';
$configFile = $configPath . '/config.json';

/**
 * Función para verificar si una función o clase existe
 */
function checkRequirement($type, $name, $required = true) {
    $exists = false;
    if ($type === 'extension') {
        $exists = extension_loaded($name);
    } elseif ($type === 'function') {
        $exists = function_exists($name);
    } elseif ($type === 'class') {
        $exists = class_exists($name);
    } elseif ($type === 'exec') {
        // Verificar si exec está habilitado y funciona
        if (function_exists('exec')) {
            $disabled = explode(',', ini_get('disable_functions'));
            if (!in_array('exec', $disabled)) {
                $exists = true;
            }
        }
    }

    return [
        'name' => $name,
        'status' => $exists,
        'required' => $required
    ];
}

/**
 * Función para ejecutar comandos de shell de forma segura
 */
function runCommand($cmd, &$output = null) {
    $output = [];
    $returnVar = 0;
    
    // En HestiaCP, a veces necesitamos usar sudo si el usuario tiene permisos, 
    // pero generalmente ejecutamos como el usuario del sitio.
    exec($cmd . ' 2>&1', $output, $returnVar);
    
    return $returnVar === 0;
}

/**
 * Paso 1: Verificación de Requisitos
 */
if ($step === 1) {
    $requirements = [
        checkRequirement('extension', 'json', true),
        checkRequirement('extension', 'pdo', true),
        checkRequirement('extension', 'curl', true),
        checkRequirement('extension', 'mbstring', true),
        checkRequirement('extension', 'redis', false), // Opcional pero recomendado
        checkRequirement('exec', 'exec', true),
        checkRequirement('function', 'shell_exec', true),
    ];

    $allPassed = true;
    foreach ($requirements as $req) {
        if ($req['required'] && !$req['status']) {
            $allPassed = false;
            break;
        }
    }

    $phpVersion = phpversion();
    $minPhpVersion = '7.4';
    $phpOk = version_compare($phpVersion, $minPhpVersion, '>=');
    
    if (!$phpOk) $allPassed = false;

    // Detectar usuario de HestiaCP (usualmente el nombre del dominio o usuario)
    $currentUser = get_current_user();
    $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
    $isNginx = stripos($serverSoftware, 'nginx') !== false;
    $isApache = stripos($serverSoftware, 'apache') !== false;

    include 'templates/install_header.php';
    ?>
    <div class="card">
        <h2>🔍 Verificación de Requisitos</h2>
        <p>Detectando entorno HestiaCP para el usuario: <strong><?php echo htmlspecialchars($currentUser); ?></strong></p>
        
        <table class="table">
            <tr>
                <th>Requisito</th>
                <th>Estado</th>
                <th>Detalle</th>
            </tr>
            <tr>
                <td>Versión PHP (>= <?php echo $minPhpVersion; ?>)</td>
                <td><span class="badge <?php echo $phpOk ? 'success' : 'error'; ?>"><?php echo $phpOk ? 'OK' : 'FAIL'; ?></span></td>
                <td><?php echo $phpVersion; ?></td>
            </tr>
            <tr>
                <td>Servidor Web</td>
                <td><span class="badge info">INFO</span></td>
                <td><?php echo $isNginx ? 'Nginx' : ($isApache ? 'Apache' : 'Desconocido'); ?> detected</td>
            </tr>
            <?php foreach ($requirements as $req): ?>
            <tr>
                <td><?php echo ucfirst($req['type']) . ': ' . $req['name']; ?></td>
                <td>
                    <?php if ($req['status']): ?>
                        <span class="badge success">OK</span>
                    <?php elseif (!$req['required']): ?>
                        <span class="badge warning">Optional</span>
                    <?php else: ?>
                        <span class="badge error">FAIL</span>
                    <?php endif; ?>
                </td>
                <td><?php echo $req['required'] ? 'Requerido' : 'Opcional'; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>

        <?php if ($allPassed): ?>
            <div class="alert success">✅ Todos los requisitos críticos se han cumplido.</div>
            <a href="?step=2" class="btn btn-primary">Continuar con la Instalación &rarr;</a>
        <?php else: ?>
            <div class="alert error">❌ Faltan requisitos críticos. Por favor, contacta al administrador del servidor o instala las extensiones faltantes.</div>
            <p><small>Nota: En HestiaCP, puedes instalar extensiones PHP desde el panel > Web > Edit Domain > PHP Settings o vía SSH como root.</small></p>
        <?php endif; ?>
    </div>
    <?php
    include 'templates/install_footer.php';
    exit;
}

/**
 * Paso 2: Creación de Directorios y Archivos
 */
if ($step === 2) {
    $actions = [];
    $success = true;

    // 1. Crear directorios
    $dirs = [$dataPath, $logPath, $modelPath, $cachePath, $configPath];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            if (mkdir($dir, 0755, true)) {
                $actions[] = "Directorio creado: " . str_replace($basePath, '', $dir);
            } else {
                $actions[] = "❌ Error al crear: " . str_replace($basePath, '', $dir);
                $success = false;
            }
        } else {
            $actions[] = "Directorio ya existe: " . str_replace($basePath, '', $dir);
        }
    }

    // 2. Crear config.json si no existe
    if (!file_exists($configFile)) {
        $defaultConfig = [
            "bot_enabled" => false,
            "symbol" => "EURUSD",
            "timeframe" => "M15",
            "grid_step" => 100,
            "max_orders" => 10,
            "lot_size" => 0.01,
            "magic_number" => 123456,
            "use_ml" => false,
            "redis_enabled" => false,
            "db_host" => "localhost",
            "db_name" => "grid_bot",
            "db_user" => $currentUser, // Asumir mismo usuario que DB en Hestia
            "db_pass" => "",
            "security" => [
                "api_key" => bin2hex(random_bytes(16)),
                "allowed_ips" => ["127.0.0.1"]
            ]
        ];

        if (file_put_contents($configFile, json_encode($defaultConfig, JSON_PRETTY_PRINT))) {
            $actions[] = "✅ Archivo de configuración generado: /config/config.json";
            // Intentar proteger el archivo
            @chmod($configFile, 0644);
        } else {
            $actions[] = "❌ Error al escribir config.json";
            $success = false;
        }
    } else {
        $actions[] = "ℹ️ config.json ya existe.";
    }

    // 3. Crear archivo .env opcional para variables sensibles
    $envFile = $basePath . '/.env';
    if (!file_exists($envFile)) {
        $envContent = "APP_ENV=production\nDB_HOST=localhost\nREDIS_HOST=127.0.0.1\n";
        file_put_contents($envFile, $envContent);
        @chmod($envFile, 0600);
        $actions[] = "✅ Archivo .env creado con permisos restringidos.";
    }

    // 4. Verificar permisos de escritura en data/logs
    if (is_writable($logPath)) {
        $actions[] = "✅ Permisos de escritura verificados en /data/logs";
    } else {
        $actions[] = "⚠️ Advertencia: No se puede escribir en /data/logs. Revisa los permisos chown/chmod.";
        // En HestiaCP, a veces hay que hacer chown user:user
        $actions[] = "💡 Sugerencia: Ejecuta en SSH: <code>chown -R {$currentUser}:{$currentUser} {$dataPath}</code>";
    }

    include 'templates/install_header.php';
    ?>
    <div class="card">
        <h2>🛠️ Configuración del Sistema</h2>
        <div class="log-output">
            <?php foreach ($actions as $action): ?>
                <div><?php echo $action; ?></div>
            <?php endforeach; ?>
        </div>

        <?php if ($success): ?>
            <div class="alert success">✅ Estructura de directorios y configuración básica completada.</div>
            <a href="?step=3" class="btn btn-primary">Siguiente: Dependencias &rarr;</a>
        <?php else: ?>
            <div class="alert error">⚠️ Hubo errores durante la configuración. Revísalos arriba antes de continuar.</div>
            <a href="?step=2" class="btn btn-secondary">Reintentar</a>
        <?php endif; ?>
    </div>
    <?php
    include 'templates/install_footer.php';
    exit;
}

/**
 * Paso 3: Instalación de Dependencias (Python, Redis, etc.)
 */
if ($step === 3) {
    $output = [];
    $stepsCompleted = 0;
    $totalSteps = 3;

    include 'templates/install_header.php';
    ?>
    <div class="card">
        <h2>📦 Instalación de Dependencias</h2>
        <p>Este paso puede tardar unos minutos. Se intentarán instalar las herramientas necesarias.</p>
        
        <div class="progress-bar">
            <div class="progress" style="width: 10%;"></div>
        </div>

        <h3>1. Verificando Python 3</h3>
        <?php
        if (runCommand('python3 --version', $output)) {
            echo "<div class='text-success'>✅ " . htmlspecialchars($output[0]) . "</div>";
            $stepsCompleted++;
        } else {
            echo "<div class='text-error'>❌ Python 3 no encontrado. El módulo ML no funcionará.</div>";
            echo "<small>Para instalar en HestiaCP (requiere root/SSH): <code>apt install python3-pip python3-venv</code></small>";
        }
        ?>

        <h3>2. Verificando PIP y Librerías ML</h3>
        <?php
        // Intentar instalar pandas y scikit-learn si pip existe
        if (runCommand('pip3 list | grep -i pandas', $output)) {
             echo "<div class='text-success'>✅ Pandas ya instalado.</div>";
        } else {
            echo "<div class='text-warning'>⚠️ Pandas no encontrado. Intentando instalar...</div>";
            // Nota: En hosting compartido esto fallará sin permisos root
            if (runCommand('pip3 install --user pandas scikit-learn numpy', $output)) {
                echo "<div class='text-success'>✅ Librerías ML instaladas correctamente.</div>";
                $stepsCompleted++;
            } else {
                echo "<div class='text-error'>❌ No se pudieron instalar las librerías. Probablemente requiera acceso root.</div>";
                echo "<pre>" . htmlspecialchars(implode("\n", $output)) . "</pre>";
            }
        }

        // Actualizar barra de progreso visualmente con JS después, aquí solo texto
        ?>

        <h3>3. Verificando Redis</h3>
        <?php
        if (extension_loaded('redis')) {
            echo "<div class='text-success'>✅ Extensión PHP Redis detectada.</div>";
            $stepsCompleted++;
        } else {
            echo "<div class='text-warning'>⚠️ Extensión Redis no cargada en PHP.</div>";
            echo "<small>En HestiaCP: Ve a Panel > Server > Configure System > PHP Extensions e instala 'php-redis'.</small>";
        }
        ?>

        <hr>
        
        <?php if ($stepsCompleted >= 2): // Al menos Python y algo más ?>
            <div class="alert success">✅ Las dependencias críticas están listas o son opcionales.</div>
            <a href="?step=4" class="btn btn-primary">Finalizar Instalación &rarr;</a>
        <?php else: ?>
            <div class="alert warning">⚠️ Algunas dependencias fallaron, pero puedes continuar en modo básico (sin ML/Redis).</div>
            <a href="?step=4" class="btn btn-primary">Continuar de todos modos &rarr;</a>
        <?php endif; ?>
    </div>
    
    <script>
        // Simple script para animar la barra de progreso simulada
        setTimeout(() => { document.querySelector('.progress').style.width = '40%'; }, 500);
        setTimeout(() => { document.querySelector('.progress').style.width = '70%'; }, 1500);
        setTimeout(() => { document.querySelector('.progress').style.width = '100%'; }, 2500);
    </script>
    <?php
    include 'templates/install_footer.php';
    exit;
}

/**
 * Paso 4: Finalización y Seguridad
 */
if ($step === 4) {
    // Sugerencia para borrar el instalador
    $self = basename(__FILE__);
    
    include 'templates/install_header.php';
    ?>
    <div class="card" style="text-align: center;">
        <div style="font-size: 3rem; margin-bottom: 20px;">🎉</div>
        <h2>¡Instalación Completada!</h2>
        <p>Tu Grid Bot está listo para configurarse en HestiaCP.</p>

        <div class="alert info" style="text-align: left;">
            <strong>Próximos pasos recomendados:</strong>
            <ol>
                <li>Editar <code>/config/config.json</code> con tus credenciales de MT5 y parámetros de trading.</li>
                <li>Si usas ML, asegúrate de que el script <code>train_ml_weights.py</code> tenga permisos de ejecución.</li>
                <li>Configura un Cron Job en HestiaCP para ejecutar <code>src/php/bot.php</code> cada minuto si no usas el daemon.</li>
                <li><strong>IMPORTANTE:</strong> Borra este archivo instalador por seguridad.</li>
            </ol>
        </div>

        <form method="post" action="">
            <button type="button" onclick="if(confirm('¿Estás seguro de que quieres eliminar el instalador? Esta acción no se puede deshacer.')) { window.location.href='?delete_installer=true'; }" class="btn btn-danger">
                🗑️ Eliminar Instalador Ahora
            </button>
        </form>
        
        <br>
        <a href="../index.php" class="btn btn-primary">Ir al Dashboard del Bot</a>
    </div>

    <?php
    // Lógica para auto-eliminación
    if (isset($_GET['delete_installer'])) {
        if (unlink(__FILE__)) {
            echo "<script>alert('Instalador eliminado correctamente.'); window.location.href='../index.php';</script>";
        } else {
            echo "<script>alert('Error al eliminar. Por favor, borra install_hestia.php manualmente desde el administrador de archivos o SSH.');</script>";
        }
    }
    include 'templates/install_footer.php';
    exit;
}
?>
