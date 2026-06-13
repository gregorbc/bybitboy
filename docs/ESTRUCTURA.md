# Grid Bot MT5 - Estructura del Proyecto

## Descripción General
Este repositorio contiene un sistema de trading automatizado Grid Bot para MetaTrader 5, con componentes PHP para la interfaz web y gestión del bot, scripts Python para machine learning, y el experto asesor en MQL5.

## Estructura de Directorios

```
/workspace
├── src/                    # Código fuente principal
│   ├── php/                # Aplicación PHP (backend y frontend)
│   │   ├── bot.php         # Lógica principal del bot
│   │   ├── index.php       # Interfaz web principal
│   │   ├── grid_ajax.php   # AJAX handlers para la interfaz
│   │   ├── websocket_server.php  # Servidor WebSocket
│   │   ├── trainer.php     # Sistema de entrenamiento ML
│   │   ├── install.php     # Script de instalación web
│   │   ├── save_chart.php  # Guardado de gráficos
│   │   ├── test_config.php # Testing de configuración
│   │   ├── CacheManager.php    # Gestión de caché
│   │   ├── ConfigLoader.php    # Carga de configuración
│   │   └── SecurityHelpers.php # Funciones de seguridad
│   ├── python/             # Scripts Python para ML
│   │   ├── train_ml_weights.py      # Entrenamiento de pesos ML
│   │   ├── train_volatility_ridge.py # Modelo de volatilidad
│   │   ├── trainer_run.py           # Runner del entrenador
│   │   └── test_ml_models.py        # Tests de modelos ML
│   └── mt5/                # MetaTrader 5 Expert Advisor
│       ├── GridBotMT5.mq5  # Código fuente del EA
│       └── GridBotMT5.ex5  # Compilado del EA
│
├── config/                 # Archivos de configuración
│   ├── config.json         # Configuración principal
│   ├── config.json.safe    # Configuración de respaldo segura
│   ├── volatility_weights.json  # Pesos del modelo de volatilidad
│   └── trainer_history.json     # Historial de entrenamiento
│
├── data/                   # Datos y archivos generados
│   ├── models/             # Modelos de ML entrenados
│   │   ├── volatility_model.pkl
│   │   └── volatility_scaler.pkl
│   ├── logs/               # Logs del sistema
│   │   ├── bot.log
│   │   ├── train_ml_cron.log
│   │   ├── GridBotMT5_compile.log
│   │   └── xampp-control.log
│   ├── cache/              # Caché de la aplicación
│   └── grid_bot.pid        # PID del proceso del bot
│
├── scripts/                # Scripts de utilidad e instalación
│   ├── install.sh          # Script de instalación principal
│   ├── install_redis.sh    # Instalación de Redis
│   ├── migrate_security.sh # Migración de seguridad
│   ├── train_ml_cron.sh    # Cron para entrenamiento ML
│   ├── vola.sh             # Script de volatilidad
│   └── install.sql         # Script SQL de base de datos
│
├── systemd/                # Configuración de servicios systemd
│   ├── grid-bot.service    # Definición del servicio
│   ├── install_systemd.sh  # Instalación del servicio
│   ├── uninstall_systemd.sh # Desinstalación del servicio
│   └── README_SYSTEMD.md   # Documentación de systemd
│
├── docs/                   # Documentación
│   ├── README.md           # README principal
│   ├── INSTALACION.md      # Guía de instalación
│   ├── IMPLEMENTACION_COMPLETA.md  # Documentación de implementación
│   ├── REDIS_CACHE.md      # Documentación de Redis
│   ├── SECURITY_MIGRATION.md # Migración de seguridad
│   ├── RESUMEN_MEJORAS.md  # Resumen de mejoras
│   └── LICENSE             # Licencia del proyecto
│
├── tests/                  # Tests y pruebas
│
└── .gitignore              # Configuración de Git
```

## Uso

### Instalación
Ver `docs/INSTALACION.md` para instrucciones detalladas.

### Ejecución del Bot
```bash
cd src/php
php bot.php
```

### Entrenamiento ML
```bash
cd src/python
python train_ml_weights.py
python train_volatility_ridge.py
```

### Servicio Systemd
Ver `systemd/README_SYSTEMD.md` para configurar el bot como servicio.

## Requisitos
- PHP 7.4+
- Python 3.8+
- MetaTrader 5
- MySQL/MariaDB
- Redis (opcional, para caché)
- Extensiones PHP: pdo, pdo_mysql, redis

## Licencia
Ver `docs/LICENSE` para más información.
