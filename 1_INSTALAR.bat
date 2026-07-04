@echo off
setlocal
cd /d "%~dp0"
echo Iniciando instalador web en http://127.0.0.1:8080/install.php
start "" http://127.0.0.1:8080/install.php
php -S 127.0.0.1:8080 -t "%~dp0"
pause
