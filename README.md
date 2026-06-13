# Grid Bot v15.4 - Guía de Instalación para cPanel y Hosting Compartido

## 📋 Requisitos Previos

- **PHP 7.4 o superior**
- **MySQL 5.7+ o MariaDB 10.2+**
- **Extensión PDO MySQL habilitada**
- **cURL habilitado**
- **OpenSSL habilitado**
- **Acceso a la API de Bybit**

---

## 🚀 Pasos de Instalación

### 1. Subir Archivos al Hosting

Sube todos los archivos del Grid Bot a tu hosting vía FTP o el Administrador de Archivos de cPanel:

```
/public_html/gridbot/
├── install.php          ← Ejecuta este primero
├── install.sql
├── index.php            ← Dashboard
├── bot.php              ← Bot principal
├── websocket_server.php ← Servidor WebSocket
├── ... (otros archivos)
```

### 2. Crear Base de Datos en cPanel

1. Ingresa a **cPanel** → **MySQL Databases**
2. Crea una nueva base de datos (ej: `usuario_gridbot`)
3. Crea un usuario de base de datos (ej: `usuario_db`)
4. Asigna el usuario a la base de datos con **todos los privilegios**
5. Anota: Host (generalmente `localhost`), nombre de BD, usuario y contraseña

### 3. Obtener Credenciales de Bybit

1. Ve a [Bybit API Management](https://www.bybit.com/app/user/api-management)
2. Crea una nueva API Key
3. Permisos necesarios:
   - **Order**: Lectura y escritura
   - **Position**: Lectura
   - **Account**: Lectura
4. Para pruebas, marca la opción **Testnet**
5. Guarda la **API Key** y **API Secret**

### 4. Ejecutar el Instalador Web

1. Abre tu navegador y ve a:
   ```
   https://tudominio.com/gridbot/install.php
   ```

2. Sigue los 5 pasos del instalador:
   - ✅ **Paso 1**: Verificación de requisitos
   - ⚙️ **Paso 2**: Configuración (ingresa datos de BD y Bybit)
   - 🔄 **Paso 3**: Pruebas de conexión
   - 🔨 **Paso 4**: Creación de tablas
   - 🎉 **Paso 5**: Finalización

3. **¡Importante!** Elimina `install.php` después de instalar por seguridad

---

## ⚙️ Configuración Post-Instalación

### Iniciar el Bot

#### Opción A: Desde SSH (Recomendado)
```bash
cd /home/usuario/public_html/gridbot
php bot.php
```

#### Opción B: Usando Cron Job (cPanel)
1. Ve a **cPanel** → **Cron Jobs**
2. Agrega un nuevo cron job:
   ```
   * * * * * cd /home/usuario/public_html/gridbot && php bot.php
   ```
3. Esto ejecutará el bot cada minuto

#### Opción C: Usando Screen o tmux
```bash
screen -S gridbot
cd /home/usuario/public_html/gridbot
php bot.php
# Presiona Ctrl+A, luego D para salir de screen sin detener el bot
```

### Iniciar el Servidor WebSocket

Para actualizaciones en tiempo real del dashboard:

```bash
cd /home/usuario/public_html/gridbot
php websocket_server.php
```

**Nota**: El servidor WebSocket debe estar corriendo continuamente. Usa `screen` o configura un servicio.

---

## 📊 Acceder al Dashboard

Abre en tu navegador:
```
https://tudominio.com/gridbot/index.php
```

El dashboard mostrará:
- Posiciones abiertas
- Órdenes activas
- Estadísticas de trading
- Gráficos de rendimiento
- Logs en tiempo real (si WebSocket está activo)

---

## 🔧 Solución de Problemas

### Error: "No se pudo conectar a MySQL"
- Verifica que el host sea correcto (usualmente `localhost`)
- Confirma que el usuario tenga privilegios sobre la base de datos
- En cPanel, el host puede ser `127.0.0.1` o la IP del servidor

### Error: "PDO no disponible"
- Contacta a tu hosting para habilitar la extensión `pdo_mysql`
- O verifica en PHP Selector de cPanel

### Error: "Conexión a Bybit fallida"
- Verifica que las credenciales API sean correctas
- Asegúrate de que la IP del servidor esté whitelisteada en Bybit (si aplica)
- Prueba con Testnet primero

### El bot no ejecuta órdenes
- Verifica que haya saldo suficiente en la cuenta de Bybit
- Confirma que los permisos de la API Key sean correctos
- Revisa los logs en `grid_logs` tabla

### WebSocket no conecta
- Asegúrate de que el puerto 8080 (o el configurado) esté abierto
- Verifica que `websocket_server.php` esté corriendo
- Revisa firewall del servidor

---

## 📁 Estructura de Archivos Generados

Después de la instalación se crearán:

```
/gridbot/
├── .env              ← Configuración (¡NO COMPARTIR!)
├── .htaccess         ← Protección de .env
├── .installed        ← Marca de instalación completada
├── install.php       ← Eliminar después de usar
└── ... (archivos del bot)
```

---

## 🔐 Seguridad

1. **Elimina `install.php`** después de la instalación
2. **Protege el directorio** con autenticación HTTP si es posible
3. **Usa HTTPS** siempre
4. **Rota las API Keys** periódicamente
5. **No compartas** el archivo `.env`

---

## 📞 Soporte

Para problemas específicos:
1. Revisa la tabla `grid_logs` en la base de datos
2. Verifica los logs de error de PHP en cPanel
3. Prueba en modo Testnet antes de usar fondos reales

---

## ⚠️ Advertencia de Riesgo

El trading de criptomonedas implica riesgos significativos. Este bot es una herramienta automatizada pero:
- No garantiza ganancias
- Puede ocurrir pérdida de capital
- Usa solo fondos que puedas permitirte perder
- Prueba exhaustivamente en Testnet primero
