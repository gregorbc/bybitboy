# Grid Bot MT5 - Sistema de Trading Automatizado

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](docs/LICENSE)
[![PHP](https://img.shields.io/badge/PHP-7.4+-blue.svg)](https://php.net)
[![Python](https://img.shields.io/badge/Python-3.8+-blue.svg)](https://python.org)
[![MT5](https://img.shields.io/badge/MT5-Expert%20Advisor-green.svg)](https://www.metatrader5.com)

Sistema avanzado de trading automatizado tipo Grid para MetaTrader 5, con interfaz web en PHP, machine learning en Python, y gestión inteligente de operaciones.

## 📋 Características Principales

- **Grid Trading**: Estrategia automatizada de compras y ventas en niveles predefinidos
- **Machine Learning**: Modelos predictivos para optimización de parámetros y volatilidad
- **Interfaz Web**: Panel de control completo con gráficos en tiempo real
- **WebSocket**: Comunicación bidireccional para actualizaciones instantáneas
- **Caché Redis**: Alto rendimiento en el manejo de datos
- **Systemd**: Ejecución como servicio del sistema
- **Seguridad**: Implementación de mejores prácticas de seguridad

## 📁 Estructura del Proyecto

```
├── src/                    # Código fuente
│   ├── php/                # Backend y frontend PHP
│   ├── python/             # Scripts de Machine Learning
│   └── mt5/                # Expert Advisor para MetaTrader 5
├── config/                 # Archivos de configuración
├── data/                   # Datos, logs y modelos
│   ├── models/             # Modelos ML entrenados
│   ├── logs/               # Logs del sistema
│   └── cache/              # Caché de la aplicación
├── scripts/                # Scripts de instalación y utilidad
├── systemd/                # Configuración de servicios
├── docs/                   # Documentación completa
└── tests/                  # Pruebas y tests
```

Ver [ESTRUCTURA.md](docs/ESTRUCTURA.md) para detalles completos.

## 🚀 Instalación Rápida

### Requisitos Previos

- PHP 7.4 o superior
- Python 3.8 o superior
- MySQL/MariaDB
- Redis (opcional pero recomendado)
- MetaTrader 5

### Pasos de Instalación

1. **Clonar el repositorio**
```bash
git clone <repository-url>
cd grid-bot-mt5
```

2. **Ejecutar script de instalación**
```bash
chmod +x scripts/install.sh
./scripts/install.sh
```

3. **Configurar base de datos**
```bash
mysql -u root -p < scripts/install.sql
```

4. **Configurar Redis (opcional)**
```bash
chmod +x scripts/install_redis.sh
./scripts/install_redis.sh
```

5. **Instalar servicio systemd (Linux)**
```bash
cd systemd
chmod +x install_systemd.sh
./install_systemd.sh
```

Para instrucciones detalladas, ver [INSTALACION.md](docs/INSTALACION.md).

## 💻 Uso

### Iniciar el Bot

**Como servicio:**
```bash
sudo systemctl start grid-bot
sudo systemctl enable grid-bot
```

**Manual:**
```bash
cd src/php
php bot.php
```

### Entrenar Modelos ML

```bash
cd src/python
python train_ml_weights.py
python train_volatility_ridge.py
```

### Acceder a la Interfaz Web

Abrir en el navegador: `http://localhost/index.php`

## 📖 Documentación

| Documento | Descripción |
|-----------|-------------|
| [INSTALACION.md](docs/INSTALACION.md) | Guía completa de instalación (cPanel y genérico) |
| [INSTALACION_HESTIA.md](docs/INSTALACION_HESTIA.md) | **Instalador web para HestiaCP** |
| [ESTRUCTURA.md](docs/ESTRUCTURA.md) | Estructura del proyecto |
| [IMPLEMENTACION_COMPLETA.md](docs/IMPLEMENTACION_COMPLETA.md) | Detalles de implementación |
| [REDIS_CACHE.md](docs/REDIS_CACHE.md) | Configuración de Redis |
| [SECURITY_MIGRATION.md](docs/SECURITY_MIGRATION.md) | Migración de seguridad |
| [README_SYSTEMD.md](systemd/README_SYSTEMD.md) | Configuración de systemd |

## 🔧 Configuración

El archivo principal de configuración es `config/config.json`:

```json
{
  "database": {
    "host": "localhost",
    "name": "grid_bot",
    "user": "grid_user",
    "password": "your_password"
  },
  "mt5": {
    "path": "/path/to/mt5",
    "symbol": "EURUSD",
    "timeframe": "M15"
  },
  "grid": {
    "levels": 10,
    "distance": 0.001,
    "lot_size": 0.01
  },
  "ml": {
    "enabled": true,
    "model_path": "data/models/"
  }
}
```

## 🧪 Testing

```bash
cd src/php
php test_config.php

cd src/python
python test_ml_models.py
```

## 📊 Arquitectura

```
┌─────────────────┐     ┌──────────────┐     ┌─────────────┐
│   MetaTrader 5  │◄───►│  Bot PHP     │◄───►│  MySQL DB   │
│   (EA MQL5)     │     │  (Core)      │     │             │
└─────────────────┘     └──────┬───────┘     └─────────────┘
                               │
                        ┌──────▼───────┐
                        │   Redis      │
                        │   Cache      │
                        └──────┬───────┘
                               │
                        ┌──────▼───────┐
                        │  WebSocket   │
                        │   Server     │
                        └──────┬───────┘
                               │
                        ┌──────▼───────┐
                        │  Frontend    │
                        │  (Web UI)    │
                        └──────────────┘
                               ▲
                        ┌──────┴───────┐
                        │  Python ML   │
                        │  Training    │
                        └──────────────┘
```

## 🤝 Contribuir

1. Fork el proyecto
2. Crea una rama (`git checkout -b feature/nueva-funcionalidad`)
3. Commit tus cambios (`git commit -am 'Añadir nueva funcionalidad'`)
4. Push a la rama (`git push origin feature/nueva-funcionalidad`)
5. Abre un Pull Request

## 📄 Licencia

Este proyecto está bajo la licencia MIT. Ver [LICENSE](docs/LICENSE) para más detalles.

## ⚠️ Descargo de Responsabilidad

**ADVERTENCIA**: El trading de divisas y CFDs conlleva un alto nivel de riesgo y puede no ser adecuado para todos los inversores. El uso de este software es bajo tu propia responsabilidad. Nunca inviertas dinero que no puedas permitirte perder.

Este software se proporciona "TAL CUAL", sin garantía de ningún tipo.

## 📞 Soporte

Para problemas, preguntas o sugerencias:
- Abrir un issue en el repositorio
- Revisar la documentación existente
- Verificar los logs en `data/logs/`

---

**Versión**: 2.0  
**Última actualización**: 2024  
**Desarrollado con ❤️ para traders**
