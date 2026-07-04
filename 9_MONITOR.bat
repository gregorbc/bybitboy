@echo off
setlocal
cd /d "%~dp0"
echo Monitor de log. Cierra esta ventana para salir.
powershell -NoProfile -ExecutionPolicy Bypass -Command "if (!(Test-Path '.\bot.log')) { New-Item -ItemType File '.\bot.log' | Out-Null }; Get-Content '.\bot.log' -Wait -Tail 80"
