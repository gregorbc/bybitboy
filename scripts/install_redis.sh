#!/bin/bash
# =============================================================================
# INSTALADOR DE REDIS - Grid Bot v15.5
# Instala y configura Redis para caché de consultas
# =============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[OK]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

check_root() {
    if [[ $EUID -ne 0 ]]; then
        log_error "Este script debe ejecutarse como root (sudo ./install_redis.sh)"
        exit 1
    fi
}

install_redis() {
    log_info "Instalando Redis..."
    
    if command -v redis-server &> /dev/null; then
        log_warn "Redis ya está instalado"
        return 0
    fi
    
    apt update
    apt install redis-server redis-tools -y
    
    log_success "Redis instalado correctamente"
}

configure_redis() {
    log_info "Configurando Redis para Grid Bot..."
    
    # Backup configuración original
    cp /etc/redis/redis.conf /etc/redis/redis.conf.backup.$(date +%Y%m%d_%H%M%S)
    
    # Configurar límites de memoria y política LRU
    cat >> /etc/redis/redis.conf << 'EOF'

# Configuración para Grid Bot v15.5
maxmemory 256mb
maxmemory-policy allkeys-lru
timeout 300
tcp-keepalive 60
EOF
    
    log_success "Configuración aplicada"
}

start_redis() {
    log_info "Iniciando Redis..."
    
    systemctl enable redis-server
    systemctl start redis-server
    systemctl restart redis-server
    
    sleep 2
    
    if systemctl is-active --quiet redis-server; then
        log_success "Redis iniciado correctamente"
    else
        log_error "Redis no se inició. Verificar logs: journalctl -u redis-server"
        exit 1
    fi
}

test_connection() {
    log_info "Probando conexión..."
    
    if redis-cli ping | grep -q "PONG"; then
        log_success "Conexión a Redis verificada"
    else
        log_error "No se pudo conectar a Redis"
        exit 1
    fi
}

update_env() {
    log_info "Actualizando archivo .env..."
    
    ENV_FILE="/workspace/.env"
    
    if [[ ! -f "$ENV_FILE" ]]; then
        log_warn "Archivo .env no encontrado, creando..."
        touch "$ENV_FILE"
    fi
    
    # Agregar variables de Redis si no existen
    grep -q "^REDIS_HOST=" "$ENV_FILE" || echo "REDIS_HOST=localhost" >> "$ENV_FILE"
    grep -q "^REDIS_PORT=" "$ENV_FILE" || echo "REDIS_PORT=6379" >> "$ENV_FILE"
    grep -q "^REDIS_PASSWORD=" "$ENV_FILE" || echo "REDIS_PASSWORD=" >> "$ENV_FILE"
    
    log_success "Variables de entorno configuradas"
}

show_stats() {
    echo ""
    log_info "Estadísticas de Redis:"
    redis-cli info server | grep -E "redis_version|uptime_in_days"
    redis-cli info memory | grep -E "used_memory_human|maxmemory_human"
    redis-cli info stats | grep -E "keyspace_hits|keyspace_misses"
}

show_usage() {
    cat << EOF

===============================================================================
REDIS INSTALADO EXITOSAMENTE - Grid Bot v15.5
===============================================================================

Comandos útiles:

  # Ver estado de Redis
  sudo systemctl status redis-server

  # Reiniciar Redis
  sudo systemctl restart redis-server

  # Probar conexión
  redis-cli ping

  # Ver estadísticas en tiempo real
  redis-cli --stat

  # Monitorear comandos en vivo
  redis-cli monitor

  # Ver todas las claves del Grid Bot
  redis-cli keys "gridbot:*"

  # Limpiar caché
  redis-cli FLUSHDB

Integración con PHP:

  El archivo CacheManager.php ya está creado en:
  /workspace/CacheManager.php
  
  Uso en tu código:
  
  \$cache = CacheManager::getInstance();
  
  // Guardar en caché (5 minutos)
  \$cache->set('precio_eth', 1850.50, 300);
  
  // Obtener de caché
  \$precio = \$cache->get('precio_eth', 0);
  
  // Ver estadísticas
  \$stats = \$cache->getStats();

===============================================================================
EOF
}

main() {
    echo ""
    echo "==============================================================================="
    echo "  GRID BOT - REDIS CACHE INSTALLER v15.5"
    echo "==============================================================================="
    echo ""
    
    check_root
    install_redis
    configure_redis
    start_redis
    test_connection
    update_env
    show_stats
    show_usage
    
    echo ""
    log_success "¡Instalación de Redis completada!"
    echo ""
}

main "$@"
