# 🔒 Guía de Migración de Seguridad - Grid Bot v15.4

## ⚠️ PROBLEMAS DE SEGURIDAD DETECTADOS

El análisis del código reveló **3 vulnerabilidades críticas**:

1. **Credenciales expuestas en config.json** - API keys y contraseñas en texto plano
2. **WebSocket sin autenticación** - `ws_token` vacío permite acceso no autorizado
3. **Logs sin sanitizar** - Pueden exponer información sensible

## 📋 SOLUCIÓN IMPLEMENTADA

Se ha implementado un sistema de **variables de entorno** con los siguientes componentes:

### Archivos Creados

| Archivo | Propósito |
|---------|-----------|
| `.env.example` | Plantilla de variables de entorno (puede commitearse) |
| `ConfigLoader.php` | Clase PHP para cargar configuración desde .env |
| `migrate_security.sh` | Script automático de migración |
| `SECURITY_MIGRATION.md` | Esta documentación |

## 🚀 MIGRACIÓN AUTOMÁTICA (RECOMENDADO)

### Paso 1: Ejecutar script de migración

```bash
cd /path/to/grid_bot
chmod +x migrate_security.sh
./migrate_security.sh
```

El script realizará:
- ✅ Crear archivo `.env` desde la plantilla
- ✅ Generar tokens de seguridad criptográficamente seguros
- ✅ Extraer credenciales existentes de `config.json`
- ✅ Crear backup de configuración actual
- ✅ Configurar permisos seguros (chmod 600)

### Paso 2: Verificar archivo .env

Editar el archivo `.env` generado y verificar que todos los valores sean correctos:

```bash
nano .env
# o
vim .env
```

**Valores críticos a verificar:**
- `BYBIT_API_KEY` y `BYBIT_API_SECRET`
- `MYSQL_PASSWORD`
- `SECURITY_TOKEN` y `WS_TOKEN` (ya generados automáticamente)
- Rutas del sistema (ajustar según tu servidor)

### Paso 3: Actualizar código PHP

Modificar `bot.php` y otros archivos PHP para usar `ConfigLoader`:

**ANTES:**
```php
$config = json_decode(file_get_contents('config.json'), true);
$apiKey = $config['bybit']['api_key'];
```

**DESPUÉS:**
```php
require_once 'ConfigLoader.php';
$configLoader = ConfigLoader::getInstance();
$apiKey = $configLoader->get('bybit.api_key');
```

### Paso 4: Asegurar archivos sensibles

```bash
# Permisos seguros
chmod 600 .env
chmod 644 ConfigLoader.php
chmod 755 migrate_security.sh

# Verificar que .env esté en .gitignore
echo ".env" >> .gitignore
```

### Paso 5: Reemplazar config.json (OPCIONAL)

Si deseas mantener `config.json` como fallback, usa la versión sanitizada:

```bash
cp config.json config.json.backup
cp config.json.safe config.json
```

## 🔧 MIGRACIÓN MANUAL

Si prefieres hacerlo manualmente:

### 1. Crear archivo .env

```bash
cp .env.example .env
chmod 600 .env
```

### 2. Generar tokens seguros

```bash
# Security Token
openssl rand -hex 32

# WS Token
openssl rand -hex 32
```

Copiar los valores generados al archivo `.env`.

### 3. Mover credenciales de config.json a .env

Editar `.env` y reemplazar:
- `BYBIT_API_KEY` con el valor de `config.json > bybit.api_key`
- `BYBIT_API_SECRET` con el valor de `config.json > bybit.api_secret`
- `MYSQL_PASSWORD` con el valor de `config.json > mysql.password`

### 4. Integrar ConfigLoader.php

En cada archivo PHP que use configuración:

```php
// Al inicio del archivo
require_once __DIR__ . '/ConfigLoader.php';

// Obtener instancia
$configLoader = ConfigLoader::getInstance();

// Validar configuración (recomendado en bootstrap)
$errors = $configLoader->validate();
if (!empty($errors)) {
    die("Error de configuración:\n" . implode("\n", $errors));
}

// Usar configuración
$symbol = $configLoader->get('bot.symbol', 'ETHUSDT');
$apiKey = $configLoader->get('bybit.api_key');
$dbPassword = $configLoader->get('mysql.password');
```

## 📁 ESTRUCTURA DE ARCHIVOS RECOMENDADA

```
/var/www/grid_bot/
├── .env                    # ⚠️ NUNCA commitear (chmod 600)
├── .env.example            # ✓ Plantilla segura (puede commitearse)
├── .gitignore              # Incluir .env
├── ConfigLoader.php        # ✓ Clase de carga
├── bot.php                 # Actualizar para usar ConfigLoader
├── index.php               # Actualizar para usar ConfigLoader
├── grid_ajax.php           # Actualizar para usar ConfigLoader
├── websocket_server.php    # Actualizar para usar ConfigLoader
└── config.json.safe        # ✓ Versión sin credenciales (opcional)

/etc/grid_bot/private/      # Configuración fuera del web root
├── grid_status.json
├── grid_confidence.json
└── grid_control.json

/var/log/grid_bot/          # Logs fuera del web root
└── bot.log

/var/run/grid_bot/          # PID files
└── grid_bot.pid
```

## 🔍 VALIDACIÓN POST-MIGRACIÓN

### Test 1: Verificar que .env no es accesible vía web

```bash
curl https://tudominio.com/.env
# Debe retornar 403 Forbidden o 404 Not Found
```

### Test 2: Verificar carga de configuración

Crear archivo de test `test_config.php`:

```php
<?php
require_once 'ConfigLoader.php';

$configLoader = ConfigLoader::getInstance();

echo "Configuración cargada:\n";
echo "API Key configurada: " . ($configLoader->get('bybit.api_key') ? '✓' : '✗') . "\n";
echo "WS Token configurado: " . ($configLoader->get('ws_token') ? '✓' : '✗') . "\n";
echo "Security Token configurado: " . ($configLoader->get('security_token') ? '✓' : '✗') . "\n";

$errors = $configLoader->validate();
if (empty($errors)) {
    echo "\n✅ Todas las validaciones pasaron\n";
} else {
    echo "\n❌ Errores encontrados:\n";
    foreach ($errors as $error) {
        echo "- $error\n";
    }
}
?>
```

Ejecutar:
```bash
php test_config.php
```

### Test 3: Verificar que el bot funciona

```bash
# Detener bot anterior
kill $(cat grid_bot.pid) 2>/dev/null || true

# Iniciar bot
php bot.php &

# Verificar logs
tail -f bot.log
```

## 🛡️ MEJORES PRÁCTICAS DE SEGURIDAD

### 1. Gestión de Secrets

- ✅ **NUNCA** commitear `.env` al repositorio
- ✅ Usar `.env.example` como plantilla sin valores reales
- ✅ Rotar credenciales periódicamente
- ✅ Usar diferentes credenciales para producción y testing

### 2. Permisos de Archivos

```bash
# Archivos de configuración
chmod 600 .env
chmod 644 ConfigLoader.php
chmod 644 *.php

# Directorios
chmod 755 /var/www/grid_bot
chmod 700 /etc/grid_bot/private

# Logs
chmod 640 /var/log/grid_bot/bot.log
chown www-data:adm /var/log/grid_bot/bot.log
```

### 3. Protección Web

Agregar al `.htaccess`:

```apache
# Denegar acceso a archivos sensibles
<FilesMatch "^\.env">
    Order allow,deny
    Deny from all
</FilesMatch>

<FilesMatch "\.(json|log|pid)$">
    Order allow,deny
    Deny from all
</FilesMatch>
```

### 4. Sanitización de Logs

En `bot.php`, sanitizar logs:

```php
function sanitizeLog($message) {
    // Remover credenciales de logs
    $sensitive = ['api_key', 'api_secret', 'password', 'token'];
    foreach ($sensitive as $key) {
        $message = preg_replace(
            "/$key[\"']?\s*[:=]\s*[\"'][^\"']+[\"']/i",
            "$key=***REDACTED***",
            $message
        );
    }
    return $message;
}
```

## 🔄 ROLLBACK (SI ALGO SALE MAL)

Si necesitas revertir a la configuración anterior:

```bash
# Restaurar config.json original
cp config.json.backup.* config.json

# Detener bot
kill $(cat grid_bot.pid) 2>/dev/null || true

# Reiniciar con configuración antigua
php bot.php &
```

## 📞 SOPORTE

Si encuentras problemas durante la migración:

1. Verificar logs: `tail -f bot.log`
2. Validar configuración: `php test_config.php`
3. Revisar permisos: `ls -la .env ConfigLoader.php`
4. Consultar documentación: `README.md`

## ✅ CHECKLIST DE MIGRACIÓN

- [ ] Ejecutar `migrate_security.sh`
- [ ] Verificar archivo `.env` creado
- [ ] Confirmar tokens generados
- [ ] Actualizar todos los archivos PHP para usar `ConfigLoader`
- [ ] Configurar permisos seguros (chmod 600 .env)
- [ ] Agregar `.env` a `.gitignore`
- [ ] Testear que el bot funciona
- [ ] Verificar que `.env` no es accesible vía web
- [ ] Eliminar o sanitizar `config.json` original
- [ ] Documentar cambios en bitácora

---

**Versión**: 1.0  
**Fecha**: 2024  
**Estado**: ✅ Implementado y testeado
