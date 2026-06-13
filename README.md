# Grid Bot v15.4 - Trading Automático para Bybit

Sistema avanzado de trading automatizado diseñado para operar en el exchange **Bybit** utilizando una estrategia de **Grid Trading** potenciada con indicadores técnicos (EMA, RSI, MACD) y un panel de control en tiempo real.

## 🚀 Características Principales

- **Estrategia Grid Inteligente**: Compra y venta automática en rangos de precio definidos.
- **Indicadores Técnicos**: Integración de EMA, RSI y MACD para filtrar operaciones y optimizar entradas.
- **Panel de Control en Tiempo Real**: Dashboard web con gráficos, posiciones abiertas, historial de órdenes y P&L actualizado vía WebSocket.
- **Gestión de Riesgo**: Configuración de Stop Loss, Take Profit y tamaño de posición.
- **Base de Datos MySQL**: Almacenamiento histórico de operaciones, logs y configuración.
- **Instalador Web**: Sistema de instalación automática compatible con cPanel y hosting compartido.
- **Optimizado para Rendimiento**: Caché de datos, consultas eficientes y gestión de memoria mejorada (v15.4).

## 📋 Requisitos del Servidor

Para ejecutar este sistema necesitas un hosting o VPS con las siguientes características:

- **PHP**: Versión 7.4 o superior (recomendado 8.0+).
- **MySQL/MariaDB**: Base de datos para almacenar el historial.
- **Extensiones PHP**: `pdo`, `pdo_mysql`, `curl`, `json`, `openssl`.
- **Acceso a Terminal (SSH)**: Necesario para ejecutar el bot en segundo plano (`nohup` o `screen`).
- **Permisos de Escritura**: Para generar archivos de configuración y logs.
- **WebSocket**: El servidor debe permitir conexiones persistentes (puerto 8090 por defecto).

## 🛠️ Instalación

### Opción A: Instalador Web (Recomendado para cPanel/Hosting Compartido)

1. Sube todos los archivos del proyecto a tu carpeta pública (`public_html` o similar).
2. Accede a `http://tudominio.com/install.php` desde tu navegador.
3. Sigue los pasos del asistente:
   - Verificación de requisitos.
   - Configuración de la base de datos.
   - Credenciales de API de Bybit.
   - Configuración del bot.
4. El instalador creará las tablas automáticamente y generará el archivo `.env`.
5. **Importante**: Elimina el archivo `install.php` después de la instalación por seguridad.

### Opción B: Instalación Manual

1. Clona o sube los archivos al servidor.
2. Crea una base de datos MySQL y un usuario con privilegios.
3. Importa el esquema inicial desde `install.sql`.
4. Copia el archivo `.env.example` a `.env` y edita tus credenciales:
   ```env
   DB_HOST=localhost
   DB_NAME=tu_base_datos
   DB_USER=tu_usuario
   DB_PASS=tu_contraseña
   BYBIT_API_KEY=tu_api_key
   BYBIT_API_SECRET=tu_api_secret
   ```
5. Ajusta los permisos de la carpeta `logs` (chmod 755 o 777 según sea necesario).

## ▶️ Ejecución

El sistema consta de dos procesos principales que deben ejecutarse simultáneamente:

### 1. Iniciar el Bot de Trading
Este script se encarga de la lógica de operación, monitoreo de precios y envío de órdenes a Bybit. Debe ejecutarse en segundo plano.

```bash
# Usando nohup (recomendado para que siga corriendo al cerrar sesión)
nohup php bot.php > logs/bot.log 2>&1 &

# O usando screen
screen -S gridbot
php bot.php
# Presiona Ctrl+A, luego D para salir de la pantalla sin detener el bot
```

### 2. Iniciar el Servidor WebSocket
Necesario para que el dashboard reciba datos en tiempo real sin recargar la página.

```bash
nohup php websocket_server.php > logs/websocket.log 2>&1 &
```

> **Nota**: Asegúrate de que el puerto del WebSocket (por defecto 8090) esté abierto en tu firewall o configurado correctamente en tu hosting.

### 3. Acceder al Dashboard
Abre tu navegador y ve a `http://tudominio.com/index.php`. Verás el panel de control con toda la información en tiempo real.

## ⚙️ Configuración

La configuración principal se gestiona desde la interfaz web o directamente en la base de datos (tabla `configuraciones`). Los parámetros clave incluyen:

- **Símbolo**: Par de trading (ej. `BTCUSDT`).
- **Rango de Grid**: Precio mínimo y máximo para operar.
- **Número de Órdenes**: Cantidad de niveles de compra/venta.
- **Inversión por Orden**: Cantidad USDT a utilizar en cada nivel.
- **Indicadores**: Activación y parámetros de EMA, RSI y MACD.
- **Stop Loss / Take Profit**: Niveles de seguridad globales.

## 📁 Estructura de Archivos

```
/
├── bot.php                 # Núcleo del bot de trading
├── websocket_server.php    # Servidor para datos en tiempo real
├── index.php               # Panel de control (Dashboard)
├── install.php             # Instalador web automático
├── install.sql             # Esquema de base de datos
├── .env                    # Archivo de configuración (generado)
├── .env.example            # Ejemplo de configuración
├── INSTALACION.md          # Guía detallada de instalación
└── logs/                   # Carpeta para registros de actividad
```

## 🔒 Seguridad

- Las claves de API se guardan en el archivo `.env` fuera del acceso público directo (si es posible) o protegidas por el instalador.
- El instalador web debe eliminarse tras su uso.
- Se recomienda usar claves de API con permisos limitados solo a "Trading" (sin retiros).
- Los logs no deben exponer información sensible en entornos públicos.

## 🆘 Solución de Problemas

- **El bot no inicia**: Revisa los permisos de ejecución de PHP y la ruta del archivo `bot.php`. Verifica el archivo `logs/bot.log` para errores.
- **Error de conexión a Bybit**: Comprueba que tu API Key y Secret sean correctos y tengan permisos de trading. Verifica que la IP del servidor no esté bloqueada en Bybit.
- **El dashboard no muestra datos en tiempo real**: Asegúrate de que `websocket_server.php` esté ejecutándose y que el puerto correspondiente esté abierto. Revisa `logs/websocket.log`.
- **Errores de base de datos**: Verifica las credenciales en `.env` y que el usuario tenga privilegios sobre la base de datos.

## 📄 Licencia

Este proyecto es de uso personal. Úsalo bajo tu propia responsabilidad. El trading de criptomonedas implica riesgos significativos.

## 🤝 Contribuciones

Las mejoras y reportes de errores son bienvenidos. Por favor, revisa la documentación antes de sugerir cambios.

---
**Versión**: 15.4 (Optimizada)
**Última actualización**: 2024
