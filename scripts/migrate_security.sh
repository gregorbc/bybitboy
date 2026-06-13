#!/bin/bash
# =============================================================================
# migrate_security.sh - Script de migración de seguridad para Grid Bot
# =============================================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="${SCRIPT_DIR}/config.json"
ENV_FILE="${SCRIPT_DIR}/.env"

echo "╔════════════════════════════════════════════════════════╗"
echo "║   MIGRACIÓN DE SEGURIDAD - GRID BOT                   ║"
echo "╚════════════════════════════════════════════════════════╝"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "❌ ERROR: config.json no encontrado"
    exit 1
fi

echo "✓ Encontrado config.json"

# Generar tokens
SECURITY_TOKEN=$(openssl rand -hex 32)
WS_TOKEN=$(openssl rand -hex 32)
echo "✓ Tokens de seguridad generados"

# Extraer credenciales (fallback sin jq)
BYBIT_API_KEY=$(grep -o '"api_key"[[:space:]]*:[[:space:]]*"[^"]*"' "$CONFIG_FILE" | head -1 | sed 's/.*: *"\([^"]*\)".*/\1/')
BYBIT_API_SECRET=$(grep -o '"api_secret"[[:space:]]*:[[:space:]]*"[^"]*"' "$CONFIG_FILE" | head -1 | sed 's/.*: *"\([^"]*\)".*/\1/')
BYBIT_TESTNET=$(grep -o '"testnet"[[:space:]]*:[[:space:]]*[a-z]*' "$CONFIG_FILE" | head -1 | sed 's/.*: *//')
MYSQL_HOST=$(grep -o '"host"[[:space:]]*:[[:space:]]*"[^"]*"' "$CONFIG_FILE" | head -1 | sed 's/.*: *"\([^"]*\)".*/\1/')
MYSQL_DBNAME=$(grep -o '"dbname"[[:space:]]*:[[:space:]]*"[^"]*"' "$CONFIG_FILE" | head -1 | sed 's/.*: *"\([^"]*\)".*/\1/')
MYSQL_USER=$(grep -o '"user"[[:space:]]*:[[:space:]]*"[^"]*"' "$CONFIG_FILE" | head -1 | sed 's/.*: *"\([^"]*\)".*/\1/')
MYSQL_PASSWORD=$(grep -o '"password"[[:space:]]*:[[:space:]]*"[^"]*"' "$CONFIG_FILE" | head -1 | sed 's/.*: *"\([^"]*\)".*/\1/')
NVIDIA_API_KEY=$(grep -o '"api_key"[[:space:]]*:[[:space:]]*"[^"]*"' "$CONFIG_FILE" | tail -1 | sed 's/.*: *"\([^"]*\)".*/\1/')

echo "✓ Credenciales extraídas"

# Crear .env
cat > "$ENV_FILE" << EOF
# =============================================================================
# ARCHIVO .ENV - CONFIGURACIÓN SEGURA PARA GRID BOT
# Generado: $(date '+%Y-%m-%d %H:%M:%S')
# ⚠️ NUNCA suba este archivo al repositorio
# =============================================================================

BYBIT_API_KEY=${BYBIT_API_KEY}
BYBIT_API_SECRET=${BYBIT_API_SECRET}
BYBIT_TESTNET=${BYBIT_TESTNET}

MYSQL_HOST=${MYSQL_HOST}
MYSQL_DBNAME=${MYSQL_DBNAME}
MYSQL_USER=${MYSQL_USER}
MYSQL_PASSWORD=${MYSQL_PASSWORD}

SECURITY_TOKEN=${SECURITY_TOKEN}
WS_TOKEN=${WS_TOKEN}

NVIDIA_API_KEY=${NVIDIA_API_KEY}
NVIDIA_ENABLED=false

BOT_SYMBOL=ETHUSDT
BOT_CAPITAL_USD=30
BOT_LEVERAGE=100
EOF

chmod 600 "$ENV_FILE"
echo "✓ Archivo .env creado con permisos seguros (600)"

# Backup
BACKUP_FILE="${CONFIG_FILE}.backup.$(date '+%Y%m%d_%H%M%S')"
cp "$CONFIG_FILE" "$BACKUP_FILE"
echo "✓ Backup de config.json: $BACKUP_FILE"

echo ""
echo "╔════════════════════════════════════════════════════════╗"
echo "║              MIGRACIÓN COMPLETADA                      ║"
echo "╚════════════════════════════════════════════════════════╝"
echo ""
echo "PRÓXIMOS PASOS:"
echo "1. Agregue '.env' a .gitignore"
echo "2. Mueva .env fuera del directorio público si es posible"
echo "3. Use ConfigLoader.php en sus scripts PHP"
echo ""
