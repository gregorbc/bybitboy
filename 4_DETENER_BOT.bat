@echo off
setlocal
cd /d "%~dp0"
echo Enviando orden de detener...
php -r "$c=json_decode(file_get_contents('config.json'),true); $f=$c['paths']['ctrl']??'private/grid_control.json'; if(!is_dir(dirname($f))) mkdir(dirname($f),0777,true); file_put_contents($f,json_encode(['action'=>'stop','sym'=>$c['bot']['symbol']??'ETHUSDT','ts'=>date('Y-m-d H:i:s')],JSON_PRETTY_PRINT)); echo 'Comando stop escrito en '.$f.PHP_EOL;"
if exist grid_bot.pid (
  for /f %%p in (grid_bot.pid) do (
    echo Cerrando proceso %%p si sigue activo...
    taskkill /PID %%p /T /F >nul 2>nul
  )
  del grid_bot.pid >nul 2>nul
)
pause
