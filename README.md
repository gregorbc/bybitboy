# 🤖 Bybit Grid Bot v14.1 - ETH/USDT

Bot de grid trading profesional para Bybit Futures con IA, gestión de riesgo, journal de trades y auto-reentrenamiento ML.

## 🚀 Características Principales

- **Grid Trading Adaptativo**: Spacing dinámico basado en ATR + predicción de volatilidad
- **IA Híbrida**: ML (Logistic Regression) + Heurísticas + Regímenes de mercado (UP/DOWN/SIDEWAYS)
- **RiskEngine**: VaR 95%, Kelly Criterion, Hard-stop 3% capital / 80% margen
- **NotificationManager**: Alertas Telegram/Discord con rate-limiting
- **Trade Journal**: Auto-población en EXIT fills, tags, notas, export CSV
- **ML Auto-Retrain**: Pipeline walk-forward 70/30, deploy si accuracy mejora >1%
- **Theme System**: Dark/Light mode con persistencia localStorage
- **Keyboard Shortcuts**: h=help, t=theme, 1-5=tabs, Space=pause log, f=speed, Esc=close

## 📋 Requisitos

- Docker 20+ y Docker Compose 2+
- Puerto 8080 libre (web dashboard)
- Cuenta Bybit (testnet/mainnet) con API keys

## 🛠️ Despliegue Rápido

```bash
# 1. Clonar
git clone https://github.com/gregorbc/bybitboy.git
cd bybitboy

# 2. Configurar variables
cp .env.example .env
# Editar .env con tus credenciales Bybit

# 3. Levantar stack
docker compose up -d --build

# 4. Verificar
docker compose logs -f bot
```

## 🌐 Acceso

| Servicio | URL |
|----------|-----|
| Dashboard | http://localhost:8080 |
| API Endpoints | http://localhost:8080/grid_ajax.php |
| Bot Logs | `docker compose logs -f bot` |

## 🔧 Comandos Útiles

```bash
# Ver logs del bot
docker compose logs -f bot

# Reiniciar bot
docker compose restart bot

# Ejecutar reentrenamiento ML manual
docker compose --profile tools run --rm retrain

# Backup BD
docker compose exec mysql mysqldump -u erika_bot -p erika_bot > backup.sql

# Acceso shell al contenedor PHP
docker compose exec php sh
```

## 📊 Dashboard Tabs

1. **Stats** - KPIs sesión, métricas mercado, PnL charts
2. **Posiciones** - 12 columnas: ROE, IM/MM, dist liq, ADL, tiempo, mark price
3. **Fills** - Historial paginado con PnL
4. **Journal** - Trade journal con tags, notas, export CSV
5. **ML** - Accuracy, feature importance bars, last update
6. **Log** - Logs en tiempo real con búsqueda y pausa

## ⌨️ Atajos de Teclado

| Tecla | Acción |
|-------|--------|
| `h` | Toggle help modal |
| `t` | Toggle dark/light theme |
| `1-5` | Cambiar tab (Stats→Log) |
| `r` | Force refresh all data |
| `Space` | Pause/resume log scroll |
| `f` | Toggle fast/normal speed |
| `Esc` | Close modals/drawers |

## 🧠 ML Pipeline

- **Features**: RSI, Stoch, MACD, EMA diff, ATR%, BB%, VWAP ratio, spread, momentum
- **Model**: Logistic Regression (multinomial) + decision stumps ensemble
- **Retrain**: Diario 03:00 UTC si hay >100 fills y accuracy mejora >1%
- **Manual**: `docker compose --profile tools run --rm retrain`

## 🛡️ Risk Management

| Parámetro | Valor | Descripción |
|-----------|-------|-------------|
| Hard Stop Capital | 3% | Cierra todo si pérdida >3% capital |
| Hard Stop Margin | 80% | Cierra si margen usado >80% |
| Kelly Max Fraction | 0.25 | Tamaño máximo según Kelly |
| VaR Confidence | 95% | Value at Risk diario |
| Max Daily DD | 10% | Drawdown máximo diario |

## 📁 Estructura

```
├── bot.php              # Bot principal (~2200 líneas)
├── grid_ajax.php        # API endpoints (~850 líneas)
├── index.php            # Dashboard SPA (~1500 líneas)
├── retrain.php          # ML auto-retrain CLI
├── config.json          # Configuración completa
├── setup_mysql.sql      # Schema BD
├── Dockerfile           # Imagen PHP 8.3 + extensiones
├── docker-compose.yml   # Stack completo
├── docker/nginx.conf    # Nginx config
└── .env.example         # Variables de entorno
```

## 🔐 Seguridad

- Testnet obligatorio hasta aprobación explícita
- Tokens en `.env` (no en repo)
- Rate-limiting en alertas (60s default)
- No exponer puerto 3306 (MySQL solo interno)

## 📈 Monitoreo

```bash
# Health check
curl http://localhost:8080/grid_ajax.php?_status=1

# PnL flotante
curl http://localhost:8080/grid_ajax.php?_pnl_float=1

# ML info
curl http://localhost:8080/grid_ajax.php?_ml_info=1
```

## 🤝 Contribuir

1. Fork → Feature branch → PR
2. Tests: `php -l *.php` (syntax check)
3. Commits convencionales: `feat:`, `fix:`, `chore:`, `docs:`

## 📄 Licencia

MIT License - Uso libre con atribución.

---

**⚠️ Disclaimer**: Trading conlleva riesgo. Úsalo en testnet primero. Autor no responsable de pérdidas.