#!/bin/bash
# ===========================================
# Script de Migración de Seguridad
# ===========================================
# Este script ayuda a migrar de config.json a variables de entorno
# y genera tokens de seguridad seguros

set -e

echo "============================================"
echo "  Grid Bot - Migración de Seguridad v1.0"
echo "============================================"
echo ""

# Colores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Función para generar token seguro
generate_secure_token() {
    openssl rand -hex 32
}

# Paso 1: Verificar si existe .env
echo -e "${YELLOW}[1/5]${NC} Verificando archivo .env..."
if [ -f ".env" ]; then
    echo -e "${RED}¡ALERTA!${NC} El archivo .env ya existe."
    read -p "¿Desea sobrescribirlo? (y/N): " confirm
    if [[ ! $confirm =~ ^[Yy]$ ]]; then
        echo "Operación cancelada."
        exit 0
    fi
fi

# Paso 2: Crear .env desde .env.example
echo -e "${YELLOW}[2/5]${NC} Creando archivo .env..."
if [ -f ".env.example" ]; then
    cp .env.example .env
    echo -e "${GREEN}✓${NC} Archivo .env creado desde .env.example"
else
    echo -e "${RED}✗${NC} Error: No se encontró .env.example"
    exit 1
fi

# Paso 3: Generar tokens de seguridad
echo -e "${YELLOW}[3/5]${NC} Generando tokens de seguridad..."

SECURITY_TOKEN=$(generate_secure_token)
WS_TOKEN=$(generate_secure_token)

# Actualizar tokens en .env
sed -i "s/SECURITY_TOKEN=.*/SECURITY_TOKEN=${SECURITY_TOKEN}/" .env
sed -i "s/WS_TOKEN=.*/WS_TOKEN=${WS_TOKEN}/" .env

echo -e "${GREEN}✓${NC} SECURITY_TOKEN generado"
echo -e "${GREEN}✓${NC} WS_TOKEN generado"

# Paso 4: Extraer credenciales de config.json (si existe)
echo -e "${YELLOW}[4/5]${NC} Extrayendo configuración existente..."

if [ -f "config.json" ]; then
    # Extraer valores con grep y sed (compatible con macOS y Linux)
    BYBIT_API_KEY=$(grep -o '"api_key": *"[^"]*"' config.json | cut -d'"' -f4 | head -1)
    BYBIT_API_SECRET=$(grep -o '"api_secret": *"[^"]*"' config.json | cut -d'"' -f4 | head -1)
    MYSQL_PASSWORD=$(grep -o '"password": *"[^"]*"' config.json | cut -d'"' -f4 | head -1)
    
    if [ -n "$BYBIT_API_KEY" ]; then
        # Escapar caracteres especiales para sed
        ESCAPED_KEY=$(printf '%s\n' "$BYBIT_API_KEY" | sed 's/[&/\]/\\&/g')
        sed -i "s|BYBIT_API_KEY=.*|BYBIT_API_KEY=${ESCAPED_KEY}|" .env
        echo -e "${GREEN}✓${NC} API Key extraída de config.json"
    fi
    
    if [ -n "$BYBIT_API_SECRET" ]; then
        # Escapar caracteres especiales para sed
        ESCAPED_SECRET=$(printf '%s\n' "$BYBIT_API_SECRET" | sed 's/[&/\]/\\&/g')
        sed -i "s|BYBIT_API_SECRET=.*|BYBIT_API_SECRET=${ESCAPED_SECRET}|" .env
        echo -e "${GREEN}✓${NC} API Secret extraída de config.json"
    fi
    
    if [ -n "$MYSQL_PASSWORD" ]; then
        # Escapar caracteres especiales para sed
        ESCAPED_PASS=$(printf '%s\n' "$MYSQL_PASSWORD" | sed 's/[&/\]/\\&/g')
        sed -i "s|MYSQL_PASSWORD=.*|MYSQL_PASSWORD=${ESCAPED_PASS}|" .env
        echo -e "${GREEN}✓${NC} MySQL password extraído de config.json"
    fi
else
    echo -e "${YELLOW}⚠${NC} No se encontró config.json, configure manualmente el archivo .env"
fi

# Paso 5: Crear backup de config.json sin credenciales sensibles
echo -e "${YELLOW}[5/5]${NC} Creando plantilla config.json segura..."

if [ -f "config.json" ]; then
    # Crear backup
    cp config.json "config.json.backup.$(date +%Y%m%d_%H%M%S)"
    echo -e "${GREEN}✓${NC} Backup creado: config.json.backup.*"
    
    # Crear versión sanitizada (con placeholders)
    cat > config.json.safe << 'EOF'
{
  "exchange": "bybit",
  "bybit": {
    "api_key": "USE_ENV_VAR",
    "api_secret": "USE_ENV_VAR",
    "testnet": true
  },
  "bot": {
    "symbol": "ETHUSDT",
    "capital_usd": 30,
    "leverage": 100,
    "timeframe": "5",
    "candles_feed": 150,
    "cycle_sec": 8,
    "ai_interval_sec": 120,
    "levels": 16,
    "long_levels": 8,
    "short_levels": 8
  },
  "grid": {
    "min_levels": 8,
    "max_levels": 20,
    "min_spacing": 0.0003,
    "max_spacing": 0.0012,
    "base_spacing": 0.0003,
    "spacing_atr_mult": 0.28,
    "min_build_interval_sec": 90
  },
  "risk": {
    "margin_safety": 0.65,
    "max_daily_loss": 12.0,
    "hard_stop_pct": 3.0,
    "recovery_thr": 1.0,
    "recovery_loss_pct": 3.0
  },
  "fees": {
    "maker": 0.0001,
    "taker": 0.0006
  },
  "compound": {
    "threshold": 1.5,
    "multiplier": 1.05,
    "cooldown_sec": 300
  },
  "exchange_rules": {
    "min_notional": 3.0
  },
  "ml": {
    "weights_file": "/path/to/ml_weights_v2.json",
    "min_confidence": 45,
    "min_accuracy": 0.85,
    "blend_weight": 0.90,
    "reload_cycles": 120
  },
  "nvidia": {
    "api_key": "USE_ENV_VAR",
    "enabled": false,
    "interval_sec": 480,
    "blend_weight": 0.10
  },
  "volatility": {
    "reload_cycles": 120
  },
  "mysql": {
    "host": "localhost",
    "dbname": "erika_bot",
    "user": "erika_bot",
    "password": "USE_ENV_VAR"
  },
  "paths": {
    "log": "/var/log/grid_bot/bot.log",
    "web_dir": "/var/www/html/grid_bot",
    "config_dir": "/etc/grid_bot/private",
    "status": "/etc/grid_bot/private/grid_status.json",
    "pid": "/var/run/grid_bot/grid_bot.pid",
    "conf_hist": "/etc/grid_bot/private/grid_confidence.json",
    "ctrl": "/etc/grid_bot/private/grid_control.json"
  },
  "security_token": "USE_ENV_VAR",
  "ws_token": "USE_ENV_VAR"
}
EOF
    echo -e "${GREEN}✓${NC} Plantilla segura creada: config.json.safe"
fi

echo ""
echo "============================================"
echo -e "${GREEN}✓ MIGRACIÓN COMPLETADA${NC}"
echo "============================================"
echo ""
echo -e "${YELLOW}PRÓXIMOS PASOS:${NC}"
echo "1. Revisar y editar el archivo .env con valores correctos"
echo "2. Configurar permisos: chmod 600 .env"
echo "3. Actualizar bot.php para usar ConfigLoader.php"
echo "4. Eliminar config.json o reemplazar con config.json.safe"
echo "5. Agregar .env al .gitignore"
echo ""
echo -e "${RED}IMPORTANTE:${NC} Nunca commitear .env al repositorio!"
echo ""

# Mostrar resumen de tokens generados
echo -e "${YELLOW}TOKENS GENERADOS:${NC}"
echo "SECURITY_TOKEN: ${SECURITY_TOKEN:0:16}..."
echo "WS_TOKEN: ${WS_TOKEN:0:16}..."
echo ""

# Verificar permisos recomendados
echo -e "${YELLOW}CONFIGURANDO PERMISOS SEGUROS:${NC}"
chmod 600 .env
echo -e "${GREEN}✓${NC} Permisos 600 aplicados a .env (solo lectura/escritura para propietario)"

echo ""
echo "Para más información, consulte SECURITY_MIGRATION.md"
