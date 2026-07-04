@echo off
setlocal
cd /d "%~dp0"
echo Entrenando modelo direccional...
python train_ml_weights.py --type classifier --symbol ETHUSDT
echo.
echo Entrenando modelo de volatilidad...
python train_volatility_ridge.py --symbol ETHUSDT --output volatility_weights_ridge.json
pause
