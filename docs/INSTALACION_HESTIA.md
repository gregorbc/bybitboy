# Instalador Web para HestiaCP

## 📋 Descripción

Este instalador web está diseñado específicamente para servidores que utilizan **HestiaCP** como panel de control. Facilita la instalación del Grid Bot mediante una interfaz gráfica paso a paso.

## 🚀 Uso

1. **Subir archivos**: Sube todo el contenido del repositorio a tu dominio en HestiaCP.

2. **Acceder al instalador**: Navega a `https://tudominio.com/src/php/install_hestia.php`

3. **Seguir los pasos**:
   - **Paso 1**: Verificación de requisitos (PHP, extensiones, permisos)
   - **Paso 2**: Creación de directorios y archivos de configuración
   - **Paso 3**: Instalación de dependencias (Python, librerías ML, Redis)
   - **Paso 4**: Finalización y recomendaciones de seguridad

## 🔧 Requisitos Previos

### En HestiaCP:

1. **Dominio configurado** con SSL recomendado
2. **PHP 7.4 o superior** con las siguientes extensiones:
   - `php-json`
   - `php-pdo`
   - `php-curl`
   - `php-mbstring`
   - `php-redis` (opcional pero recomendado)

3. **Acceso SSH** (recomendado para instalar dependencias de Python)

### Extensiones PHP Requeridas:

```bash
# Como root en tu servidor HestiaCP
apt install php8.1-json php8.1-pdo php8.1-curl php8.1-mbstring php8.1-redis
# Ajusta la versión de PHP según tu configuración (8.0, 8.1, 8.2, etc.)
```

### Para funcionalidad ML (Machine Learning):

```bash
# Instalar Python 3 y pip
apt install python3 python3-pip python3-venv

# Instalar librerías de Python
pip3 install pandas scikit-learn numpy
```

## 📁 Estructura de Archivos Creados

El instalador creará automáticamente:

```
/
├── config/
│   ├── config.json          # Configuración principal
│   └── .env                 # Variables de entorno sensibles
├── data/
│   ├── logs/                # Logs del sistema
│   ├── models/              # Modelos ML entrenados
│   └── cache/               # Caché Redis
└── src/php/
    └── install_hestia.php   # Este instalador (eliminar después)
```

## 🔐 Seguridad

### Después de la instalación:

1. **Eliminar el instalador**: El propio instalador ofrece un botón para auto-eliminarse.
   
2. **O manualmente vía SSH**:
   ```bash
   cd /home/USUARIO/web/DOMINIO/public_html/src/php
   rm install_hestia.php
   ```

3. **Proteger config.json**:
   ```bash
   chmod 644 config/config.json
   chown USUARIO:USUARIO config/config.json
   ```

4. **Configurar .htaccess** (si usas Apache):
   ```apache
   <Files "config.json">
       Order allow,deny
       Deny from all
   </Files>
   ```

## ⚙️ Configuración Post-Instalación

### 1. Editar config.json

```json
{
    "bot_enabled": true,
    "symbol": "EURUSD",
    "timeframe": "M15",
    "grid_step": 100,
    "max_orders": 10,
    "lot_size": 0.01,
    "magic_number": 123456,
    "use_ml": false,
    "redis_enabled": true,
    "db_host": "localhost",
    "db_name": "grid_bot",
    "db_user": "tu_usuario_db",
    "db_pass": "tu_contraseña_db"
}
```

### 2. Configurar Cron Job en HestiaCP

Ve a **Cron Jobs** en el panel de HestiaCP y añade:

```bash
# Ejecutar bot cada minuto
* * * * * /usr/bin/php /home/USUARIO/web/DOMINIO/public_html/src/php/bot.php >> /home/USUARIO/web/DOMINIO/logs/grid_bot_cron.log 2>&1
```

### 3. Base de Datos (Opcional)

Si vas a usar MySQL/MariaDB:

1. Crea una base de datos desde HestiaCP > Databases
2. Ejecuta el script SQL:
   ```bash
   mysql -u USUARIO_DB -p NOMBRE_DB < scripts/install.sql
   ```

## 🐛 Solución de Problemas

### Error: "No se pueden crear directorios"

**Solución**: Verifica los permisos del usuario de HestiaCP:
```bash
chown -R USUARIO:USUARIO /home/USUARIO/web/DOMINIO/public_html
chmod -R 755 /home/USUARIO/web/DOMINIO/public_html/data
```

### Error: "exec() ha sido deshabilitado"

**Solución**: 
1. Ve a HestiaCP > Web > Edit Domain > PHP Settings
2. Busca `disable_functions` y elimina `exec` de la lista
3. Reinicia PHP-FPM: `systemctl restart php8.1-fpm`

### Error: "Python no encontrado"

**Solución**: Instala Python 3:
```bash
apt update && apt install python3 python3-pip
```

### Error: "Redis no disponible"

**Solución**:
1. Instala Redis: `apt install redis-server php-redis`
2. Habilita la extensión en PHP
3. Reinicia servicios: `systemctl restart redis-server php8.1-fpm`

## 📞 Soporte

Para problemas específicos de HestiaCP, consulta:
- [Documentación oficial de HestiaCP](https://docs.hestiacp.com/)
- [Foro de la comunidad](https://forum.hestiacp.com/)

## 📝 Notas Importantes

- ⚠️ **Nunca** ejecutes el instalador como root. Debe ejecutarse como el usuario del sitio web.
- ⚠️ **Siempre** elimina el archivo `install_hestia.php` después de la instalación.
- ⚠️ **Revisa** los logs en `/data/logs/` si algo falla.

---

**Versión**: 2.0.0  
**Compatible con**: HestiaCP 1.4+  
**Última actualización**: 2024
