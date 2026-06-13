#!/bin/bash
# =============================================================================
# SYSTEMD SERVICE UNINSTALLER - Grid Bot v15.5
# Elimina el servicio systemd y limpia archivos de configuración
# =============================================================================

set -e

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

BOT_NAME="grid-bot"
SERVICE_DEST="/etc/systemd/system/${BOT_NAME}.service"
ENV_DEST="/etc/grid_bot/.env"
LOG_DIR="/var/log/grid_bot"
RUN_DIR="/var/run/grid_bot"
PRIVATE_DIR="/etc/grid_bot/private"

log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[OK]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

check_root() {
    if [[ $EUID -ne 0 ]]; then
        log_error "Este script debe ejecutarse como root (sudo ./uninstall_systemd.sh)"
        exit 1
    fi
}

stop_service() {
    log_info "Deteniendo servicio..."
    
    if systemctl is-active --quiet "$BOT_NAME" 2>/dev/null; then
        systemctl stop "$BOT_NAME"
        log_success "Servicio detenido"
    else
        log_info "El servicio no está activo"
    fi
}

disable_service() {
    log_info "Deshabilitando servicio..."
    
    systemctl disable "$BOT_NAME" 2>/dev/null || true
    log_success "Servicio deshabilitado"
}

remove_service_file() {
    log_info "Eliminando archivo de servicio..."
    
    if [[ -f "$SERVICE_DEST" ]]; then
        rm -f "$SERVICE_DEST"
        systemctl daemon-reload
        log_success "Archivo de servicio eliminado"
    else
        log_info "Archivo de servicio no encontrado"
    fi
}

backup_config() {
    log_info "Creando backup de configuración..."
    
    local backup_dir="/tmp/gridbot_backup_$(date +%Y%m%d_%H%M%S)"
    mkdir -p "$backup_dir"
    
    # Copiar archivos importantes si existen
    [[ -f "$ENV_DEST" ]] && cp "$ENV_DEST" "$backup_dir/" 2>/dev/null || true
    [[ -d "$PRIVATE_DIR" ]] && cp -r "$PRIVATE_DIR" "$backup_dir/" 2>/dev/null || true
    
    log_success "Backup creado en: $backup_dir"
    echo "  Archivos guardados:"
    ls -la "$backup_dir/"
}

cleanup_directories() {
    log_info "Limpiando directorios..."
    
    # Solo eliminar directorios temporales, no los de logs o config
    rm -rf "$RUN_DIR" 2>/dev/null || true
    
    log_success "Directorios temporales limpiados"
}

show_manual_steps() {
    cat << EOF

===============================================================================
PASOS MANUALES RECOMENDADOS
===============================================================================

El servicio systemd ha sido eliminado, pero puedes querer limpiar manualmente:

1. Eliminar directorio de configuración (OPCIONAL - perderás configs):
   sudo rm -rf /etc/grid_bot

2. Eliminar logs antiguos (OPCIONAL):
   sudo rm -rf /var/log/grid_bot

3. Eliminar archivos del bot web (OPCIONAL):
   sudo rm -rf /var/www/html/grid_bot

4. Verificar que no queden procesos del bot:
   ps aux | grep bot.php
   kill -9 <PID> si es necesario

===============================================================================
EOF
}

confirm_action() {
    echo ""
    log_warn "Esta acción eliminará el servicio systemd del Grid Bot"
    read -p "¿Estás seguro de continuar? [y/N]: " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        log_info "Operación cancelada"
        exit 0
    fi
}

main() {
    echo ""
    echo "==============================================================================="
    echo "  GRID BOT - SYSTEMD SERVICE UNINSTALLER v15.5"
    echo "==============================================================================="
    echo ""
    
    check_root
    confirm_action
    backup_config
    stop_service
    disable_service
    remove_service_file
    cleanup_directories
    
    echo ""
    log_success "¡Desinstalación completada!"
    show_manual_steps
    echo ""
}

main "$@"
