#!/bin/bash
# =============================================================================
# SYSTEMD SERVICE INSTALLER - Grid Bot v15.5
# Gestiona el servicio systemd para el Grid Bot con integración de variables de entorno
# =============================================================================

set -e

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Variables de configuración
BOT_NAME="grid-bot"
SERVICE_FILE="$(dirname "$0")/grid-bot.service"
ENV_SOURCE="/workspace/.env"
ENV_DEST="/etc/grid_bot/.env"
SERVICE_DEST="/etc/systemd/system/${BOT_NAME}.service"
LOG_DIR="/var/log/grid_bot"
RUN_DIR="/var/run/grid_bot"
PRIVATE_DIR="/etc/grid_bot/private"
WEB_DIR="/var/www/html/grid_bot"

# Función para imprimir mensajes
log_info() { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[OK]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# Verificar si se ejecuta como root
check_root() {
    if [[ $EUID -ne 0 ]]; then
        log_error "Este script debe ejecutarse como root (sudo ./install_systemd.sh)"
        exit 1
    fi
}

# Verificar dependencias
check_dependencies() {
    log_info "Verificando dependencias..."
    
    local deps=("systemctl" "php" "mysql")
    for dep in "${deps[@]}"; do
        if ! command -v "$dep" &> /dev/null; then
            log_error "Dependencia no encontrada: $dep"
            exit 1
        fi
    done
    
    log_success "Todas las dependencias verificadas"
}

# Crear directorios necesarios
create_directories() {
    log_info "Creando directorios..."
    
    mkdir -p "$LOG_DIR" "$RUN_DIR" "$PRIVATE_DIR" "$(dirname "$ENV_DEST")"
    
    # Establecer permisos correctos
    chown -R www-data:www-data "$WEB_DIR" 2>/dev/null || true
    chown -R www-data:www-data "$LOG_DIR"
    chown -R www-data:www-data "$PRIVATE_DIR"
    chmod 750 "$PRIVATE_DIR"
    chmod 640 "$ENV_DEST" 2>/dev/null || true
    
    log_success "Directorios creados con permisos correctos"
}

# Copiar archivo .env
copy_env_file() {
    log_info "Copiando archivo de variables de entorno..."
    
    if [[ ! -f "$ENV_SOURCE" ]]; then
        log_error "Archivo .env no encontrado en $ENV_SOURCE"
        exit 1
    fi
    
    cp "$ENV_SOURCE" "$ENV_DEST"
    chmod 600 "$ENV_DEST"
    chown root:root "$ENV_DEST"
    
    log_success "Archivo .env copiado a $ENV_DEST"
}

# Instalar servicio systemd
install_service() {
    log_info "Instalando servicio systemd..."
    
    if [[ ! -f "$SERVICE_FILE" ]]; then
        log_error "Archivo de servicio no encontrado: $SERVICE_FILE"
        exit 1
    fi
    
    # Copiar archivo de servicio
    cp "$SERVICE_FILE" "$SERVICE_DEST"
    chmod 644 "$SERVICE_DEST"
    
    # Recargar systemd
    systemctl daemon-reload
    
    log_success "Servicio instalado en $SERVICE_DEST"
}

# Habilitar e iniciar servicio
enable_service() {
    log_info "Habilitando servicio para inicio automático..."
    
    systemctl enable "$BOT_NAME"
    log_success "Servicio habilitado"
}

start_service() {
    log_info "Iniciando servicio..."
    
    systemctl start "$BOT_NAME"
    
    sleep 2
    
    if systemctl is-active --quiet "$BOT_NAME"; then
        log_success "Servicio iniciado correctamente"
    else
        log_warn "El servicio no se inició automáticamente. Verifica los logs:"
        log_warn "  journalctl -u ${BOT_NAME} -n 50 --no-pager"
    fi
}

# Mostrar estado del servicio
show_status() {
    echo ""
    log_info "Estado del servicio:"
    systemctl status "$BOT_NAME" --no-pager -l
}

# Mostrar instrucciones de uso
show_usage() {
    cat << EOF

===============================================================================
SERVICIO SYSTEMD INSTALADO EXITOSAMENTE
===============================================================================

Comandos útiles:

  # Iniciar el bot
  sudo systemctl start ${BOT_NAME}

  # Detener el bot
  sudo systemctl stop ${BOT_NAME}

  # Reiniciar el bot
  sudo systemctl restart ${BOT_NAME}

  # Ver estado en tiempo real
  sudo systemctl status ${BOT_NAME}

  # Ver logs en vivo
  sudo journalctl -u ${BOT_NAME} -f

  # Ver logs de las últimas 2 horas
  sudo journalctl -u ${BOT_NAME} --since "2 hours ago"

  # Recargar configuración (sin detener)
  sudo systemctl reload ${BOT_NAME}

  # Deshabilitar inicio automático
  sudo systemctl disable ${BOT_NAME}

Archivos importantes:

  Servicio:     $SERVICE_DEST
  Logs:         $LOG_DIR/bot.log
  PID:          $RUN_DIR/grid_bot.pid
  Config:       $WEB_DIR/config.json
  Variables:    $ENV_DEST

===============================================================================
EOF
}

# Main
main() {
    echo ""
    echo "==============================================================================="
    echo "  GRID BOT - SYSTEMD SERVICE INSTALLER v15.5"
    echo "  Instalación de servicio con gestión automática y variables de entorno"
    echo "==============================================================================="
    echo ""
    
    check_root
    check_dependencies
    create_directories
    copy_env_file
    install_service
    enable_service
    
    echo ""
    read -p "¿Deseas iniciar el servicio ahora? [y/N]: " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        start_service
    else
        log_info "Puedes iniciar el servicio manualmente con: sudo systemctl start ${BOT_NAME}"
    fi
    
    show_status
    show_usage
    
    echo ""
    log_success "¡Instalación completada!"
    echo ""
}

# Ejecutar main
main "$@"
