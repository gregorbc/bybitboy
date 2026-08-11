# Grid Bot MT5 - Nueva Estructura del Proyecto

## Descripción General
Este documento describe la nueva estructura organizada del proyecto Grid Bot MT5, siguiendo patrones MVC (Modelo-Vista-Controlador) y principios de código limpio.

## Nueva Estructura de Directorios

### PHP Backend (`src/php/`)

```
src/php/
├── Core/                    # Núcleo del sistema
│   ├── CacheManager.php     # Gestor de caché Redis
│   └── ConfigLoader.php     # Carga de configuración
│
├── Helpers/                 # Funciones utilitarias
│   └── SecurityHelpers.php  # Funciones de seguridad
│
├── Controllers/             # Controladores (por definir)
│   └── .gitkeep
│
├── Models/                  # Modelos de datos (por definir)
│   └── .gitkeep
│
├── Services/                # Servicios de negocio (por definir)
│   └── .gitkeep
│
├── templates/               # Vistas/plantillas HTML
│   ├── install_header.php
│   └── install_footer.php
│
├── bot.php                  # Script principal del bot (CLI)
├── index.php                # Dashboard web (Vista principal)
├── grid_ajax.php            # Endpoints AJAX para el frontend
├── websocket_server.php     # Servidor WebSocket en tiempo real
├── trainer.php              # Interfaz de entrenamiento ML
├── install.php              # Script de instalación web
├── install_hestia.php       # Instalador para HestiaCP
├── save_chart.php           # Guardado de gráficos
└── test_config.php          # Testing de configuración
```

### Python ML (`src/python/`)

```
src/python/
├── models/                  # Modelos ML entrenados (generados)
│   └── .gitkeep
├── utils/                   # Utilidades Python (por definir)
│   └── .gitkeep
├── data/                    # Datos de entrenamiento (generados)
│   └── .gitkeep
├── train_ml_weights.py      # Entrenamiento clasificador direccional
├── train_volatility_ridge.py # Entrenamiento modelo volatilidad
├── trainer_run.py           # Runner del entrenador
└── test_ml_models.py        # Tests de modelos ML
```

### MT5 Expert Advisor (`src/mt5/`)

```
src/mt5/
├── GridBotMT5.mq5           # Código fuente del EA
└── GridBotMT5.ex5           # Compilado del EA
```

## Migración de Archivos

### Archivos Movidos

| Archivo Original | Nueva Ubicación | Notas |
|-----------------|-----------------|-------|
| `src/php/CacheManager.php` | `src/php/Core/CacheManager.php` | Añadido namespace `GridBot\Core` |
| `src/php/ConfigLoader.php` | `src/php/Core/ConfigLoader.php` | Añadido namespace `GridBot\Core` |
| `src/php/SecurityHelpers.php` | `src/php/Helpers/SecurityHelpers.php` | Añadido namespace `GridBot\Helpers` |

### Cambios en los Archivos

#### CacheManager.php
- **Namespace añadido**: `GridBot\Core`
- **Cambio en Redis**: `\Redis()` en lugar de `Redis()` para referencia global
- **Excepciones**: `\Exception` en lugar de `Exception`

#### ConfigLoader.php
- **Namespace añadido**: `GridBot\Core`
- **Rutas actualizadas**: `dirname(__DIR__, 2)` para referencias correctas desde Core/
- **Config path**: Ahora busca en `/config/config.json` relativo al root

#### SecurityHelpers.php
- **Namespace añadido**: `GridBot\Helpers`
- **Funciones mantenidas**: Todas las funciones permanecen iguales, solo se añade el namespace

## Uso de Namespaces

### En Controladores y Servicios Futuros

```php
<?php
namespace GridBot\Controllers;

use GridBot\Core\CacheManager;
use GridBot\Core\ConfigLoader;
use GridBot\Helpers\{sanitizeInput, escapeOutput, secureLog};

class ExampleController {
    private $cache;
    private $config;
    
    public function __construct() {
        $this->cache = CacheManager::getInstance();
        $this->config = ConfigLoader::getInstance();
    }
    
    public function handleRequest() {
        // Usar funciones del namespace Helpers
        $input = sanitizeInput($_GET['param'] ?? '', 'string');
        echo escapeOutput($input);
    }
}
```

### En Scripts Existentes (Actualización Pendiente)

Los archivos principales (`bot.php`, `index.php`, `grid_ajax.php`, etc.) aún no han sido migrados a usar los nuevos namespaces. Se recomienda actualizarlos gradualmente:

```php
// Ejemplo para bot.php
require_once __DIR__ . '/Core/ConfigLoader.php';
require_once __DIR__ . '/Core/CacheManager.php';
require_once __DIR__ . '/Helpers/SecurityHelpers.php';

use GridBot\Core\ConfigLoader;
use GridBot\Core\CacheManager;
use function GridBot\Helpers\secureLog;

$config = ConfigLoader::getInstance();
$cache = CacheManager::getInstance();
secureLog('Bot iniciado', 'INFO');
```

## Beneficios de la Reestructuración

1. **Organización clara**: Separación por responsabilidades (Core, Helpers, Controllers, Models, Services)
2. **Namespaces**: Evita colisiones de nombres y mejora la autocompletación en IDEs
3. **Mantenibilidad**: Más fácil encontrar y modificar código específico
4. **Escalabilidad**: Fácil añadir nuevos controladores, modelos y servicios
5. **Reutilización**: Clases bien definidas pueden ser reutilizadas en diferentes partes del código
6. **Testing**: Estructura más amigable para pruebas unitarias

## Próximos Pasos Recomendados

### Fase 1: Actualizar Imports en Archivos Principales
- [ ] Actualizar `bot.php` para usar namespaces
- [ ] Actualizar `index.php` para usar namespaces
- [ ] Actualizar `grid_ajax.php` para usar namespaces
- [ ] Actualizar `websocket_server.php` para usar namespaces
- [ ] Actualizar `trainer.php` para usar namespaces

### Fase 2: Crear Controllers
- [ ] `StatusController` - Manejar estado del bot
- [ ] `TradeController` - Operaciones de trading
- [ ] `ConfigController` - Gestión de configuración
- [ ] `MLController` - Entrenamiento y predicciones ML

### Fase 3: Crear Models
- [ ] `Order` - Modelo de órdenes de trading
- [ ] `Position` - Modelo de posiciones abiertas
- [ ] `GridLevel` - Modelo de niveles del grid
- [ ] `MLPrediction` - Modelo de predicciones ML

### Fase 4: Crear Services
- [ ] `ExchangeService` - Comunicación con exchange (Bybit)
- [ ] `GridService` - Lógica del grid trading
- [ ] `MLService` - Servicios de machine learning
- [ ] `NotificationService` - Notificaciones y alertas

### Fase 5: Refactorizar Python
- [ ] Mover scripts a estructura modular
- [ ] Crear paquetes Python con `__init__.py`
- [ ] Añadir clases para modelos ML
- [ ] Implementar logging estructurado

## Convenciones de Nombres

### Clases
- **Nomenclatura**: PascalCase (ej: `CacheManager`, `ConfigLoader`)
- **Sufijos descriptivos**: `Controller`, `Model`, `Service`, `Helper`

### Funciones
- **Nomenclatura**: camelCase (ej: `sanitizeInput`, `escapeOutput`)
- **Verbos descriptivos**: `get`, `set`, `calculate`, `validate`

### Archivos
- **PHP**: El mismo nombre que la clase principal (ej: `CacheManager.php`)
- **Scripts CLI**: snake_case descriptivo (ej: `train_ml_weights.py`)

## Requisitos Técnicos

- **PHP**: 7.4+ (soporte para namespaces y features modernos)
- **Python**: 3.8+
- **Extensiones PHP**: redis, pdo, pdo_mysql
- **Autoloading**: Se recomienda implementar PSR-4 con Composer en el futuro

## Documentación Relacionada

- [ESTRUCTURA.md](ESTRUCTURA.md) - Estructura original del proyecto
- [INSTALACION.md](INSTALACION.md) - Guía de instalación
- [README.md](../../README.md) - README principal del proyecto

---

**Fecha de actualización**: 2024
**Versión de la estructura**: 2.0
