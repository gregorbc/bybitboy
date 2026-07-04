@echo off
setlocal
cd /d "%~dp0"
echo === PHP ===
php -v
echo.
echo === Python ===
python --version
echo.
echo === Archivos principales ===
if exist config.json (echo OK config.json) else (echo FALTA config.json - abre install.php)
if exist setup_mysql.sql (echo OK setup_mysql.sql) else (echo FALTA setup_mysql.sql)
if exist bot.php (echo OK bot.php) else (echo FALTA bot.php)
if exist index.php (echo OK index.php) else (echo FALTA index.php)
echo.
echo === Sintaxis PHP ===
php -l install.php
php -l index.php
php -l grid_ajax.php
php -l bot.php
echo.
echo === Conexion MySQL ===
php -r "$c=json_decode(file_get_contents('config.json'),true); try{$d=new PDO('mysql:host='.$c['mysql']['host'].';dbname='.$c['mysql']['dbname'].';charset=utf8mb4',$c['mysql']['user'],$c['mysql']['password']); echo 'OK MySQL'.PHP_EOL;}catch(Throwable $e){echo 'ERROR MySQL: '.$e->getMessage().PHP_EOL;}"
pause
