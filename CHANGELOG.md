# Changelog

Todos los cambios notables en este proyecto serán documentados en este archivo.

## [Unreleased] - Gap de paneles (seguridad · bot · visibilidad)

### Añadido
- **2FA TOTP** para el panel de administración (obligatorio) e inversores (opcional): login con código de 6 dígitos (RFC 6238, lib `otphp`), activación/confirmación/desactivación desde Perfil y Ajustes, registro de accesos en `logs_acceso`.
- **Alertas del bot + Telegram**: tabla `alertas_config` con umbrales (drawdown, pérdida diaria, distancia a liquidación, saldo mínimo), CRUD desde el panel, token de Telegram en `bot_meta`, envío de notificaciones por `Core/Notification` y log en `logs_ia` con `senal='ALERTA'`.
- **Riesgo editable en vivo**: campos `max_daily_loss` y `recovery_loss_pct` en `grid_configs`, editables desde el panel Bot y aplicados por `GridManager::applyRiskConfig()` en cada ciclo.
- **Reconciliación ledger vs exchange**: `Core/Reconciliation` compara NAV × unidades contra saldo Bybit (wallet + uPnL), con umbral de 0.50 USDT; botón "Ejecutar reconciliación" en el panel.
- **Modelos ML solo lectura**: listado de `data/models/*`, historial del entrenador y pesos de volatilidad desde el panel (sin ejecutar ni entrenar).
- **Logs IA y de acceso paginados** en el panel (25 por página, navegable).

### Seguridad
- CSRF reforzado en todas las acciones del panel de administración.

### Despliegue
- Migración idempotente en `scripts/migracion_gap.sql` (rollback en `scripts/rollback_gap.sql`) o automática vía `Schema::createTables` en el próximo arranque del bot.

## [2.0.0] - 2024-XX-XX

### Cambiado
- **Reestructuración completa del proyecto**: Nueva organización de directorios
  - `src/php/` - Todo el código PHP
  - `src/python/` - Scripts de Machine Learning
  - `src/mt5/` - Expert Advisor MQL5
  - `config/` - Archivos de configuración
  - `data/` - Datos, logs y modelos
  - `scripts/` - Scripts de instalación y utilidad
  - `systemd/` - Configuración de servicios
  - `docs/` - Documentación
  - `tests/` - Pruebas

### Mejorado
- README.md completamente renovado con mejor documentación
- .gitignore actualizado para excluir archivos generados
- Documentación de estructura del proyecto (ESTRUCTURA.md)

## [1.0.0] - Versión Anterior

### Añadido
- Implementación inicial del Grid Bot
- Interfaz web PHP
- Expert Advisor MT5
- Modelos de Machine Learning
- Sistema de caché Redis
- Servicio systemd
