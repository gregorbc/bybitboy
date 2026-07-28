# Web Dashboard Responsiveness — Grid Bot

## 1. Architecture

**Objetivo:** Hacer que todo el dashboard sea scrollable verticalmente en dispositivos móviles manteniendo el patrón existente de drawers laterales y toda la funcionalidad existente.

**Cambios clave:**
1. Modificar `.main-grid` de `overflow:hidden` a `overflow-y:auto` para permitir scroll vertical cuando el contenido exceda la altura de la ventana
2. Asegurar que `.sidebar-right` tenga `height:100%` y `overflow-y:auto` cuando esté abierto para permitir scroll interno
3. En media queries móviles, asegurar que los sidebars abiertos tengan `overflow-y:auto` para contenido que exceda su altura
4. Mantener `.topbar` como position fijo para que permanezca visible durante el scroll
5. Asegurar que todos los elementos internos tengan alturas adecuadas y no forzan un overflow horizontal innecesario

**Ventajas del enfoque:**
- Cambios mínimos de CSS/HTML, bajo riesgo de romper funcionalidad existente
- Mantiene el patrón de drawer lateral existente que los usuarios ya conocen
- Permite scroll independiente en diferentes paneles cuando sea necesario
- Preserva el header fijo para acceso constante a controles críticos
- Funciona bien con el sistema existente de drawers laterales que se deslizan desde los lados

## 2. Componentes

**1. Estructura principal (index.php):**
- `.app`: Contenedor raíz con `display:flex; flex-direction:column; height:100vh` (sin cambios)
- `.topbar`: Header fijo con altura de 50px (sin cambios)
- `.main-grid`: **Cambiar de `overflow:hidden` a `overflow-y:auto`** para permitir scroll vertical
- `.sidebar-left`: Drawer izquierdo (position:fixed) - mantener ancho actual pero asegurar `overflow-y:auto` cuando abierto
- `.center-col`: Columna central con `flex:1; overflow-y:auto` (mantener scroll interno)
- `.sidebar-right`: Drawer derecho (position:fixed) - asegurar `height:100%; overflow-y:auto` cuando abierto

**2. Componentes de UI (sin cambios estructurales):**
- **KPIs y métricas**: En sidebar-left (PnL hoy, total, win rate, uptime)
- **Paneles de información**: Wallet, señal IA, grid status, configuración grid, confianza IA (chart)
- **Sección principal**: 
  - Gráfico de velas (candleChart)
  - Análisis de mercado (RSI, MACD, ADX, ATR/Vol, Funding, OI, Bollinger, EMA)
  - Gráficos de PnL (horario, diario, acumulado)
  - Order ladder
- **Sidebar derecho**: Pestañas con Stats, Posiciones, Fills, ML, Logs

**3. Elementos interactivos:**
- Botones en topbar (menu toggle, configuración, IA, reset grid, export PnL, stop)
- Controles en modals (configuración)
- Controles en tablas (paginación de fills)
- Botones en pestañas del sidebar derecho

**Flujo de datos y eventos:**
1. El WebSocket actualiza datos en tiempo real mediante `updateUIFromWebSocket()`
2. Las funciones de polling (`fetchTicker()`, `fetchStatus()`, etc.) actualizan datos periódicos
3. Los eventos de usuario (clicks en botones, apertura de drawers, etc.) manejan cambios de estado UI
4. Los cambios de tamaño de ventana (resize) son manejados por las media queries existentes

**Manejo de estado:**
- Estado de drawers abiertos/cerrados: manejado por clases `.open` en elementos y `.active` en overlay
- Estado de pestañas en sidebar derecho: manejado por clase `.active` en botones y paneles
- Estado de modales: manejado por `display:none/grid` y manipulación de clases
- Estado de temas/visuales: manejado por CSS custom properties y clases

## 3. Flujo de datos

1. **Inicialización de la página**
   - PHP procesa la configuración y pasa variables de entorno (CAPITAL, LEVERAGE, etc.) al HTML mediante bloques PHP embebidos
   - Se inicializan variables globales de JavaScript (API, AI_INT, CAPITAL_CFG, etc.)
   - Se establecen temporizadores para polling periódico (fetchTicker, fetchStatus, fetchMarket, fetchUpnl, fetchScalp, loadFillsHistory)

2. **Conexión WebSocket**
   - `connectWebSocket()` establece conexión wss:// con token de autenticación
   - Al conectar (`ws.onopen`), se actualiza indicador WS a verde
   - Al recibir mensaje (`ws.onmessage`), se procesa mediante `updateUIFromWebSocket(data)`
   - Al desconectar o error, se programa reconexión exponencial (3s, 6s, 12s, ...)

3. **Actualización de UI desde WebSocket**
   - `updateUIFromWebSocket(data)` procesa diferentes secciones del payload:
     * Ticker: actualiza precio, cambio, bid/ask, funding, mark price, uPnL chip
     * Estado del bot: running/stopped, uptime, modo de operación
     * Datos del par: dirección IA, confianza, niveles, spacing, entradas/salidas, recovery activo, ML accuracy, P&L diario/total
     * Balances: actualiza wallet balance, margen usado/disponible, uPnL wallet, ROI diario/total, proyección 30d, fees estimados
     * Posiciones: actualiza tabla de posiciones abiertas
     * Órdenes: actualiza order ladder (visualización de niveles de precio/quantidad/rol)
     * Fills recientes: actualiza tabla de fills y contador
     * PnL horario/diario/acumulado: alimenta los datasets de los Chart.js
     * Historial de confianza IA: alimenta el chart de confianza
     * Logs: agrega al buffer de logs y renderiza últimas 100 líneas (virtual scroll)
     * Features ML: actualiza barra de importancia de características

4. **Actualización periódica mediante polling**
   - Funciones como `fetchTicker()`, `fetchStatus()`, `fetchMarket()`, `fetchUpnl()`, `fetchScalp()` hacen llamadas AJAX a `grid_ajax.php` con acciones específicas
   - Los resultados actualizan la UI de forma similar a las actualizaciones WS pero con menor frecuencia
   - `tickAI()` controla el temporizador para la próxima evaluación IA basada en `AI_INT`

5. **Interacciones de usuario**
   - Menú hamburgesa (`#menuToggle`): toggle de clase `.open` en `.sidebar-left` y `.drawer-overlay.active`
   - Botón de toggle derecha (`#rightToggle`): toggle de clase `.open` en `.sidebar-right` y actualiza visibilidad del overlay
   - Pestañas del sidebar derecho: `switchTab(tab, element)` muestra/oculta paneles y actualiza clases activas
   - Botón de configuración: abre modal con valores actuales pre-poblados
   - Funciones de modal: `closeConfig()` oculta modal, `applyConfig()` envía POST a `grid_ajax.php?action=update_config`, muestra toast y opcionalmente resetea grid
   - Controles de tabla: paginación de fills (`fillsPrev()`, `fillsNext()`), carga de historial (`loadFillsHistory()`)
   - Otros botones: velocidad (`toggleSpeed()`), IA forzada (`cmd('force_ai')`), reset grid (`cmd('reset_grid')`), export PnL (`exportPnl()`), stop (`cmd('stop')`)

6. **Renderizado diferido y optimizaciones**
   - Funciones de chart utilizan `renderIfVisible(chartId, renderFn)` para solo actualizar gráficos cuando están en viewport
   - Funciones de actualización usan debounce donde apropiado para limitar frecuencia de actualizaciones DOM
   - Log rendering usa virtual scroll mostrando solo últimas 100 líneas del buffer de 500

## 4. Manejo de errores

1. **Errores de WebSocket**
   - `ws.onerror`: muestra indicador WS en rojo, loggea warning en consola
   - `ws.onclose`: muestra indicador WS en muted, programa reconexión exponencial con `wsReconnectTimer`
   - Reconexión: intentos en 3s, 6s, 12s, 24s, 48s (máximo 5 reintentos antes de espera fija de 60s)

2. **Errores de AJAX/polling**
   - Funciones `fetchWithRetry(params, type, retry)` implementan reintento exponencial (1s, 2s, 4s, 8s, 16s)
   - Después de 4 reintentos fallidos, devuelve `null` y la función llamadora maneja el dato faltante (normalmente muestra `--` o mantiene último valor conocido)
   - Errores específicos se loggean en consola para debug

3. **Errores de parsing de datos**
   - En `updateUIFromWebSocket(data)` y funciones de polling, se usan verificaciones de existencia (`if (data.property !== undefined)`)
   - Funciones de formateo como `fP(v,d)` y `fM(v,d)` manejan valores nulos/NaN mostrando `--` con estilos apropiados
   - Actualizaciones de DOM usan chequeos de existencia de elementos (`if ($(id))`) antes de modificar propiedades

4. **Errores de rendering de charts**
   - Funciones de renderizado de charts verifican existencia de canvas antes de intentar dibujar
   - Chart.js internamente maneja errores de datos inválidos sin romper la aplicación
   - Si falla la creación/actualización de un chart, se captura exception y se continúa con otros charts

5. **Errores de almacenamiento/configuración**
   - En `grid_ajax.php`, el handler `update_config` verifica existencia del archivo de control y crea estructura vacía si no existe
   - Escritura atemporal: se escribe a archivo temporal y luego se renombra (implícito en `file_put_contents`)
   - Lectura de configuración en PHP usa `@file_exists()` y `@file_get_contents()` para evitar warnings si archivo falta
   - Fallbacks a valores por defecto definidos en PHP (`$cfg['bot']['capital_usd'] ?? 20`)

6. **Errores de UI/UX**
   - Modals y drawers usan transiciones CSS con fallback instantáneo si transiciones fallan
   - Botones deshabilitados visualmente durante operaciones en progreso (aunque actualmente no se implementa explícitamente, es fácil añadir)
   - Toasts muestran mensajes de éxito/error desde respuestas AJAX
   - Estado de conexión (WS/polling) visible constantemente en el topbar para feedback inmediato al usuario

7. **Logging y monitoreo**
   - Errores críticos se loggean a consola del navegador para desarrollo
   - En producción, se podría extender a envío de errores a endpoint de logging (futuro)
   - Los logs de WebSocket y polling aparecen en el panel de Logs del dashboard para diagnóstico

## 5. Estrategia de pruebas

1. **Pruebas unitarias de JavaScript (existentes y a extender)**
   - Mantener y posiblemente extender pruebas existentes en `/tests/js/` (Jest/TypeScript)
   - Añadir tests para funciones de utilidad: `debounce`, `renderIfVisible`, `fP`, `fM`
   - Tests para funciones de actualización UI con datos mock de WebSocket/polling
   - Tests para manejo de errores en funciones de fetch y parsing

2. **Pruebas de integración (manual/automatizadas)**
   - Verificar que todos los elementos sean accesibles y funcionales en viewport móvil (320px, 375px, 425px, 768px)
   - Probar apertura/cierre de drawers laterales en diversos tamaños de pantalla
   - Probar que el scroll vertical funcione correctamente cuando contenido excede altura de viewport
   - Probar que el scroll interno de drawers y paneles funcione cuando están abiertos
   - Probar que los charts se renderizan correctamente al entrar al viewport (lazy load)
   - Probar que los modals funcionan correctamente y no quedan atrapados detrás de otros elementos

3. **Pruebas de regresión visual**
   - Comparar screenshots de vistas clave (desktop, tablet, mobile) antes y después de cambios
   - Verificar que no haya regressions en layout, espaciado, tipografía o colores
   - Verificar que todos los elementos interactivos mantengan su posición y tamaño relativo apropiado

4. **Pruebas de rendimiento**
   - Medir FPS durante scroll con contenido pesado (muchos fills, logs, etc.)
   - Verificar que el debounce limite actualizaciones excesivas durante actualizaciones rápidas de WS
   - Verificar que el renderizado diferido de charts reduzca trabajo innecesario cuando elementos están fuera de vista

5. **Pruebas de usabilidad**
   - Verificar que targets táctiles (botones, enlaces) tengan mínimo 48x48px según guías de accesibilidad móvil
   - Verificar que el menú hamburgesa y botón de toggle derecha sean fácilmente accesibles con pulgar
   - Verificar que los campos de entrada en el modal de configuración sean usable en teclado táctil

6. **Pruebas de compatibilidad cruzada**
   - Probar en navegadores móviles principales: Chrome Android, Safari iOS, Firefox Android
   - Verificar comportamiento consistente en diferentes sistemas operativos y versiones

7. **Monitoreo en producción**
   - Los errores de JavaScript serán capturados por console.error y podrían enviarse a servicio de logging si se implementa
   - Métricas de rendimiento básicas (tiempo de carga, tiempo hasta interactividad) pueden monitorizarse mediante navegador