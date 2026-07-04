@echo off
setlocal
cd /d "%~dp0"
php -r "$c=json_decode(file_get_contents('config.json'),true); $f=$c['paths']['ctrl']??'private/grid_control.json'; if(!is_dir(dirname($f))) mkdir(dirname($f),0777,true); file_put_contents($f,json_encode(['action'=>'reset_grid','sym'=>$c['bot']['symbol']??'ETHUSDT','ts'=>date('Y-m-d H:i:s')],JSON_PRETTY_PRINT)); echo 'Reset grid enviado'.PHP_EOL;"
pause
