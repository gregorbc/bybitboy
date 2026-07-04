# Grid Bot ETH/USDT

Proyecto completo para ejecutar un dashboard web, bot grid PHP/Bybit, variante Python/MT5, entrenamiento ML y WebSocket opcional.

## Instalacion rapida en Windows/XAMPP

1. Copia la carpeta del proyecto dentro de `htdocs`.
2. Inicia Apache y MySQL desde XAMPP.
3. Ejecuta `1_INSTALAR.bat`.
4. Completa `install.php` con MySQL, Bybit/MT5 y tokens.
5. Ejecuta `2_VERIFICAR.bat`.
6. Abre `index.php` desde el navegador.
7. Ejecuta `3_INICIAR_BOT.bat` para iniciar el bot.

## Archivos principales

- `install.php`: instalador web, genera configuracion y tablas MySQL.
- `setup_mysql.sql`: esquema SQL importable manualmente.
- `index.php`: dashboard.
- `grid_ajax.php`: endpoints AJAX del dashboard.
- `bot.php`: bot principal PHP para Bybit.
- `grid_bot_mt5.py`: version Python/MetaTrader 5.
- `trainer.php`: panel web de entrenamiento.
- `websocket_server.php`: servidor WebSocket opcional.
- `1_INSTALAR.bat` a `9_MONITOR.bat`: accesos rapidos Windows.

## Requisitos

- PHP 8.x con `pdo_mysql`, `curl`, `json` y `openssl`.
- MySQL/MariaDB.
- Python 3.10+ para entrenamiento y MT5.
- Dependencias Python: `pip install -r requirements.txt`.
- API key de Bybit para el bot PHP.
- MetaTrader 5 instalado si usas `grid_bot_mt5.py`.

## Seguridad

`config.json` contiene credenciales. No lo subas a repositorios publicos. Usa `config.example.json` como plantilla limpia.

En produccion, elimina o protege `install.php` despues de instalar.
