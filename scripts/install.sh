#!/bin/bash
#===============================================================================
# INSTALADOR UNIFICADO - Grid Bot ML Trader
# Instala dependencias, configura el entorno y entrena modelos iniciales
#===============================================================================

set -e  # Detener en caso de error

COLOR_RESET="\033[0m"
COLOR_GREEN="\033[32m"
COLOR_YELLOW="\033[33m"
COLOR_BLUE="\033[34m"
COLOR_RED="\033[31m"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="${SCRIPT_DIR}/install.log"

#-------------------------------------------------------------------------------
# Funciones de logging
#-------------------------------------------------------------------------------
log() {
    echo -e "$1" | tee -a "$LOG_FILE"
}

info() {
    log "${COLOR_BLUE}[INFO]${COLOR_RESET} $1"
}

success() {
    log "${COLOR_GREEN}[OK]${COLOR_RESET} $1"
}

warn() {
    log "${COLOR_YELLOW}[WARN]${COLOR_RESET} $1"
}

error() {
    log "${COLOR_RED}[ERROR]${COLOR_RESET} $1"
}

#-------------------------------------------------------------------------------
# Verificar si es root
#-------------------------------------------------------------------------------
check_root() {
    if [[ $EUID -ne 0 ]]; then
        warn "No se ejecuta como root. Algunas instalaciones pueden fallar."
        warn "Se recomienda ejecutar: sudo bash $0"
    fi
}

#-------------------------------------------------------------------------------
# Detectar sistema operativo
#-------------------------------------------------------------------------------
detect_os() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS=$NAME
        VER=$VERSION_ID
    elif type lsb_release >/dev/null 2>&1; then
        OS=$(lsb_release -si)
        VER=$(lsb_release -sr)
    else
        OS=$(uname -s)
        VER="unknown"
    fi
    info "Sistema detectado: $OS $VER"
}

#-------------------------------------------------------------------------------
# Instalar dependencias del sistema
#-------------------------------------------------------------------------------
install_system_deps() {
    info "Instalando dependencias del sistema..."
    
    if command -v apt-get >/dev/null 2>&1; then
        # Debian/Ubuntu
        apt-get update -qq
        apt-get install -y -qq python3 python3-pip python3-venv git curl wget > /dev/null 2>&1
        success "Dependencias Debian/Ubuntu instaladas"
    elif command -v yum >/dev/null 2>&1; then
        # CentOS/RHEL
        yum install -y python3 python3-pip git curl wget > /dev/null 2>&1
        success "Dependencias CentOS/RHEL instaladas"
    elif command -v dnf >/dev/null 2>&1; then
        # Fedora
        dnf install -y python3 python3-pip git curl wget > /dev/null 2>&1
        success "Dependencias Fedora instaladas"
    elif command -v pacman >/dev/null 2>&1; then
        # Arch Linux
        pacman -S --noconfirm python python-pip git curl wget > /dev/null 2>&1
        success "Dependencias Arch Linux instaladas"
    else
        warn "Gestor de paquetes no reconocido. Instale Python 3 y pip manualmente."
        return 1
    fi
}

#-------------------------------------------------------------------------------
# Verificar e instalar Python packages
#-------------------------------------------------------------------------------
install_python_deps() {
    info "Instalando paquetes de Python..."
    
    # Crear entorno virtual si no existe
    if [ ! -d "${SCRIPT_DIR}/venv" ]; then
        python3 -m venv "${SCRIPT_DIR}/venv"
        success "Entorno virtual creado en venv/"
    fi
    
    # Activar entorno virtual
    source "${SCRIPT_DIR}/venv/bin/activate"
    
    # Actualizar pip
    pip install --upgrade pip -q
    
    # Instalar dependencias
    pip install -q numpy pandas scikit-learn requests joblib
    
    success "Paquetes de Python instalados (numpy, pandas, scikit-learn, requests, joblib)"
}

#-------------------------------------------------------------------------------
# Configurar permisos
#-------------------------------------------------------------------------------
setup_permissions() {
    info "Configurando permisos..."
    
    # Hacer ejecutables los scripts
    chmod +x "${SCRIPT_DIR}"/*.sh 2>/dev/null || true
    chmod +x "${SCRIPT_DIR}"/*.py 2>/dev/null || true
    
    # Crear directorios necesarios
    mkdir -p "${SCRIPT_DIR}/logs"
    mkdir -p "${SCRIPT_DIR}/models"
    
    success "Permisos configurados"
}

#-------------------------------------------------------------------------------
# Verificar conexión a Bybit API
#-------------------------------------------------------------------------------
check_api_connection() {
    info "Verificando conexión a Bybit API..."
    
    source "${SCRIPT_DIR}/venv/bin/activate"
    
    python3 -c "
import requests
try:
    r = requests.get('https://api.bybit.com/v5/market/kline?category=linear&symbol=BTCUSDT&interval=5&limit=1', timeout=10)
    if r.json().get('retCode') == 0:
        print('API_OK')
    else:
        print('API_ERROR')
except Exception as e:
    print(f'API_ERROR: {e}')
" | grep -q "API_OK" && success "Conexión a Bybit API verificada" || warn "No se pudo conectar a Bybit API"
}

#-------------------------------------------------------------------------------
# Entrenar modelos iniciales
#-------------------------------------------------------------------------------
train_models() {
    info "Entrenando modelos iniciales..."
    
    source "${SCRIPT_DIR}/venv/bin/activate"
    
    cd "${SCRIPT_DIR}"
    
    # Entrenar clasificador
    if [ -f "${SCRIPT_DIR}/train_ml_weights.py" ]; then
        info "Entrenando clasificador de dirección..."
        python3 train_ml_weights.py --type classifier --symbol ETHUSDT --horizon 4 --up_thr 0.5 --down_thr 0.5 --c_reg 0.1 --candles 5000 --model logistic 2>&1 | tee -a "$LOG_FILE"
        if [ -f "${SCRIPT_DIR}/ml_weights_v2.json" ]; then
            success "Clasificador entrenado: ml_weights_v2.json"
        else
            warn "No se generó ml_weights_v2.json"
        fi
    fi
    
    # Entrenar regresor de volatilidad (Linear)
    if [ -f "${SCRIPT_DIR}/trainer_run.py" ]; then
        info "Entrenando regresor de volatilidad (Linear)..."
        python3 trainer_run.py 2>&1 | tee -a "$LOG_FILE"
        if [ -f "${SCRIPT_DIR}/volatility_weights.json" ]; then
            success "Regresor Linear entrenado: volatility_weights.json"
        else
            warn "No se generó volatility_weights.json"
        fi
    fi
    
    # Entrenar regresor de volatilidad (Ridge)
    if [ -f "${SCRIPT_DIR}/train_volatility_ridge.py" ]; then
        info "Entrenando regresor de volatilidad (Ridge)..."
        python3 train_volatility_ridge.py 2>&1 | tee -a "$LOG_FILE"
        if [ -f "${SCRIPT_DIR}/volatility_weights_ridge.json" ]; then
            success "Regresor Ridge entrenado: volatility_weights_ridge.json"
        else
            warn "No se generó volatility_weights_ridge.json"
        fi
    fi
}

#-------------------------------------------------------------------------------
# Ejecutar pruebas unitarias
#-------------------------------------------------------------------------------
run_tests() {
    info "Ejecutando pruebas unitarias..."
    
    source "${SCRIPT_DIR}/venv/bin/activate"
    
    cd "${SCRIPT_DIR}"
    
    if [ -f "${SCRIPT_DIR}/test_ml_models.py" ]; then
        python3 -m pytest test_ml_models.py -v 2>&1 | tee -a "$LOG_FILE" || \
        python3 test_ml_models.py 2>&1 | tee -a "$LOG_FILE"
        success "Pruebas unitarias completadas"
    else
        warn "No se encontró test_ml_models.py"
    fi
}

#-------------------------------------------------------------------------------
# Generar archivo de resumen
#-------------------------------------------------------------------------------
generate_summary() {
    info "Generando resumen de instalación..."
    
    cat > "${SCRIPT_DIR}/INSTALL_SUMMARY.txt" << EOF
================================================================================
INSTALACIÓN COMPLETADA - Grid Bot ML Trader
Fecha: $(date '+%Y-%m-%d %H:%M:%S')
================================================================================

ARCHIVOS PRINCIPALES:
$(ls -la ${SCRIPT_DIR}/*.py 2>/dev/null | awk '{print "  " $9 " (" $5 " bytes)"}')

MODELOS GENERADOS:
$(ls -la ${SCRIPT_DIR}/*weights*.json ${SCRIPT_DIR}/*.pkl 2>/dev/null | awk '{print "  " $9 " (" $5 " bytes)"}')

COMANDOS ÚTILES:
  - Entrenar clasificador:  ./venv/bin/python3 train_ml_weights.py --type classifier --symbol ETHUSDT
  - Entrenar volatilidad:   ./venv/bin/python3 trainer_run.py
  - Ejecutar tests:         ./venv/bin/python3 -m pytest test_ml_models.py -v
  - Activar entorno:        source venv/bin/activate

CRON JOBS SUGERIDOS:
  */30 * * * * cd ${SCRIPT_DIR} && ./venv/bin/python3 train_ml_weights.py --type classifier --symbol ETHUSDT >> logs/train_classifier.log 2>&1
  0 */6 * * * cd ${SCRIPT_DIR} && ./venv/bin/python3 trainer_run.py >> logs/train_volatility.log 2>&1

================================================================================
EOF
    
    success "Resumen generado: INSTALL_SUMMARY.txt"
}

#-------------------------------------------------------------------------------
# MAIN
#-------------------------------------------------------------------------------
main() {
    echo ""
    echo "==============================================================================="
    echo "  INSTALADOR DE GRID BOT ML TRADER"
    echo "==============================================================================="
    echo ""
    
    # Iniciar log
    echo "=== Instalación iniciada: $(date) ===" > "$LOG_FILE"
    
    check_root
    detect_os
    install_system_deps
    install_python_deps
    setup_permissions
    check_api_connection
    train_models
    run_tests
    generate_summary
    
    echo ""
    success "=========================================================================="
    success "  INSTALACIÓN COMPLETADA EXITOSAMENTE"
    success "=========================================================================="
    echo ""
    info "Para activar el entorno virtual: source venv/bin/activate"
    info "Log completo disponible en: $LOG_FILE"
    info "Resumen de instalación: INSTALL_SUMMARY.txt"
    echo ""
}

# Ejecutar main
main "$@"
