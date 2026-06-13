# SYSTEMD SERVICE - Grid Bot v15.5

## 📋 Descripción

Implementación completa de **systemd service** para gestión profesional del Grid Bot, reemplazando métodos manuales como `nohup`, `screen` o `tmux`.

### ✅ Ventajas sobre métodos tradicionales

| Característica | nohup/screen | systemd |
|---------------|--------------|---------|
| Inicio automático | ❌ Manual | ✅ Automático |
| Reinicio en fallos | ❌ No | ✅ Sí |
| Logs centralizados | ❌ Archivos sueltos | ✅ journalctl |
| Gestión de recursos | ❌ Sin límites | ✅ CPU/Memoria |
| Watchdog | ❌ No | ✅ Sí |
| Seguridad | ❌ Básica | ✅ Aislamiento |

---

## 🚀 Instalación

### Requisitos Previos

- Ubuntu/Debian 18.04+ o CentOS 7+
- PHP 7.4+ instalado
- MySQL/MariaDB corriendo
- Usuario `www-data` existente (Apache)

### Pasos de Instalación

```bash
# 1. Navegar al directorio systemd
cd /workspace/systemd

# 2. Ejecutar instalador (requiere root)
sudo ./install_systemd.sh
```

### Proceso de Instalación

El instalador realizará automáticamente:

1. ✅ Verificación de dependencias (systemctl, php, mysql)
2. ✅ Creación de directorios (`/var/log/grid_bot`, `/etc/grid_bot/private`)
3. ✅ Copia segura del archivo `.env` a `/etc/grid_bot/.env`
4. ✅ Instalación del servicio en `/etc/systemd/system/grid-bot.service`
5. ✅ Habilitación para inicio automático
6. ✅ Opción de inicio inmediato

---

## 🎮 Comandos de Gestión

### Control Básico

```bash
# Iniciar el bot
sudo systemctl start grid-bot

# Detener el bot
sudo systemctl stop grid-bot

# Reiniciar el bot
sudo systemctl restart grid-bot

# Recargar configuración (sin detener)
sudo systemctl reload grid-bot

# Ver estado
sudo systemctl status grid-bot
```

### Monitoreo de Logs

```bash
# Ver logs en tiempo real
sudo journalctl -u grid-bot -f

# Ver últimas 100 líneas
sudo journalctl -u grid-bot -n 100

# Ver logs de las últimas 2 horas
sudo journalctl -u grid-bot --since "2 hours ago"

# Ver logs con timestamps detallados
sudo journalctl -u grid-bot -o verbose

# Filtrar por prioridad (errores solamente)
sudo journalctl -u grid-bot -p err
```

### Gestión Avanzada

```bash
# Habilitar inicio automático en boot
sudo systemctl enable grid-bot

# Deshabilitar inicio automático
sudo systemctl disable grid-bot

# Verificar si está habilitado
sudo systemctl is-enabled grid-bot

# Forzar reinicio completo
sudo systemctl reset-failed grid-bot
sudo systemctl start grid-bot
```

---

## 🔧 Configuración del Servicio

### Archivo de Servicio

Ubicación: `/etc/systemd/system/grid-bot.service`

#### Secciones Principales

**[Unit]**
- `After=network.target mysql.service` - Espera a que MySQL esté listo
- `Wants=mysql.service` - Dependencia suave de MySQL

**[Service]**
- `EnvironmentFile=/etc/grid_bot/.env` - Carga variables de entorno
- `ExecStart=/usr/bin/php /var/www/html/grid_bot/bot.php` - Comando de ejecución
- `Restart=always` - Reinicio automático en fallos
- `RestartSec=10` - Espera 10 segundos antes de reiniciar
- `MemoryMax=512M` - Límite de memoria
- `CPUQuota=80%` - Límite de CPU
- `WatchdogSec=120` - Detección de bloqueos (2 min)

**[Install]**
- `WantedBy=multi-user.target` - Nivel de ejecución multi-usuario

### Personalización

Puedes editar el servicio directamente:

```bash
sudo nano /etc/systemd/system/grid-bot.service
sudo systemctl daemon-reload
```

#### Parámetros Ajustables

| Parámetro | Valor Default | Descripción |
|-----------|--------------|-------------|
| `MemoryMax` | 512M | Memoria máxima permitida |
| `CPUQuota` | 80% | Porcentaje de CPU máximo |
| `WatchdogSec` | 120 | Tiempo para detectar fallos |
| `RestartSec` | 10 | Espera antes de reiniciar |
| `Nice` | -5 | Prioridad del proceso (-20 a 19) |

---

## 🔐 Seguridad

### Aislamiento del Proceso

El servicio incluye protecciones avanzadas:

```ini
NoNewPrivileges=true        # Previene escalada de privilegios
ProtectSystem=strict        # Sistema de archivos solo lectura
ProtectHome=read-only       # Directorios home protegidos
ReadWritePaths=...          # Rutas explícitas de escritura
```

### Permisos de Archivos

```bash
# Verificar permisos del archivo .env
ls -la /etc/grid_bot/.env
# Debe mostrar: -rw------- root root

# Verificar propietario de logs
ls -la /var/log/grid_bot/
# Debe ser: www-data:www-data
```

---

## 🐛 Troubleshooting

### El servicio no inicia

```bash
# Verificar estado detallado
sudo systemctl status grid-bot --no-pager -l

# Ver logs de error
sudo journalctl -u grid-bot -p err --no-pager

# Probar ejecución manual
sudo -u www-data php /var/www/html/grid_bot/bot.php
```

### El servicio se reinicia constantemente

```bash
# Ver historial de reinicios
sudo journalctl -u grid-bot --grep="Started" --no-pager

# Verificar si hay errores en el código
tail -100 /var/log/grid_bot/bot.log

# Aumentar tiempo entre reinicios (editar servicio)
sudo nano /etc/systemd/system/grid-bot.service
# Cambiar: RestartSec=30
sudo systemctl daemon-reload
```

### Falso positivo del Watchdog

```bash
# Desactivar watchdog temporalmente
sudo systemctl edit grid-bot
# Agregar:
[Service]
WatchdogSec=0

sudo systemctl daemon-reload
sudo systemctl restart grid-bot
```

### Problemas de permisos

```bash
# Corregir propietarios
sudo chown -R www-data:www-data /var/www/html/grid_bot
sudo chown -R www-data:www-data /var/log/grid_bot
sudo chown -R www-data:www-data /etc/grid_bot/private

# Corregir permisos
sudo chmod 640 /etc/grid_bot/.env
sudo chmod 750 /etc/grid_bot/private
```

---

## 📊 Métricas y Monitoreo

### Uso de Recursos

```bash
# Ver uso de memoria y CPU
systemctl show grid-bot | grep -E "Memory|CPU"

# Ver consumo en tiempo real
systemd-cgtop

# Ver límite de memoria actual
cat /sys/fs/cgroup/memory/system.slice/grid-bot.service/memory.limit_in_bytes
```

### Estado del Servicio

```bash
# Información completa
systemctl show grid-bot

# Verificar si el watchdog está activo
systemctl show grid-bot | grep Watchdog
```

---

## 🔄 Actualización del Servicio

### Actualizar desde versión anterior

```bash
# 1. Detener servicio antiguo
sudo systemctl stop grid-bot

# 2. Eliminar servicio anterior (si existe)
sudo systemctl disable grid-bot
sudo rm /etc/systemd/system/grid-bot.service
sudo systemctl daemon-reload

# 3. Instalar nueva versión
cd /workspace/systemd
sudo ./install_systemd.sh
```

### Migración desde nohup/screen

```bash
# 1. Identificar proceso actual
ps aux | grep bot.php

# 2. Detener proceso manual
kill <PID>

# 3. Instalar servicio systemd
cd /workspace/systemd
sudo ./install_systemd.sh
```

---

## 🗑️ Desinstalación

```bash
# Usar script de desinstalación
cd /workspace/systemd
sudo ./uninstall_systemd.sh
```

El script realizará:
- ✅ Backup de configuración
- ✅ Detención del servicio
- ✅ Eliminación del archivo de servicio
- ✅ Limpieza de directorios temporales

---

## 📝 Notas Importantes

1. **Nunca ejecutar bot.php manualmente** mientras el servicio systemd está activo
2. **Siempre usar journalctl** para ver logs en lugar de tail -f
3. **El archivo .env** debe estar en `/etc/grid_bot/.env` con permisos 600
4. **Los logs de aplicación** siguen yendo a `/var/log/grid_bot/bot.log`
5. **El watchdog** requiere que el bot envíe señales periódicas (implementar en código)

---

## 🆘 Soporte

Para problemas específicos del servicio systemd:

```bash
# Recopilar información de diagnóstico
sudo journalctl -u grid-bot --since today > /tmp/gridbot_debug.log
sudo systemctl status grid-bot >> /tmp/gridbot_debug.log
ps aux | grep grid-bot >> /tmp/gridbot_debug.log

# Enviar archivo de debug
cat /tmp/gridbot_debug.log
```

---

**Versión**: 15.5  
**Última actualización**: 2026-06-13  
**Compatibilidad**: Ubuntu 18.04+, Debian 10+, CentOS 7+
