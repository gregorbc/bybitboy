@echo off
setlocal
cd /d "%~dp0"
echo Iniciando WebSocket en puerto 8082...
php websocket_server.php
pause
