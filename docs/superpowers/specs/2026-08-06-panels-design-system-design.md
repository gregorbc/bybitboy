# Design: Paneles de inversor y admin con el design-system (solo maquillaje visual)

**Fecha:** 2026-08-06
**Estado:** Aprobado por el usuario
**Alcance:** Solo capa de presentación — la lógica PHP (handlers, forms, CSRF, authZ) no cambia.

## Contexto

`src/php/panel.php` (127 líneas) y `src/php/admin.php` (286 líneas) usan CSS inline básico estilo GitHub-dark (`#0d1117`, `#161b22`, tablas 12px). El dashboard principal (`src/php/index.php`, 1739 líneas) y las páginas `register.php`/`login.php` ya usan un design-system compartido en `src/php/assets/css/`:

- `design-system.css` — tokens CSS (`--bg-primary #0a0e17`, `--accent #0ea5e9`, `--green #22c55e`, `--red #ef4444`, radios, espaciados, fuentes Inter/JetBrains Mono), reset, scrollbar.
- `components.css` — `.kpi-card-value`, `.data-table`, `.panel-tabs`/`.panel-tab`/`.panel-content`, `.btn`/`.btn-primary`/`.btn-danger`, `.badge`/`.badge-green`/`.badge-red`/`.badge-accent`, `.empty-state`, `.hide-mobile`.
- `layout.css` — `.navbar`/`.navbar-tabs`, `.kpi-row`, `.card`/`.card-header`/`.card-title`, responsive breakpoints 1023/768/480.

Objetivo: subir ambos paneles a ese mismo nivel visual reutilizando los componentes existentes, sin tocar los handlers `InvestorHttp::handle()` y `AdminHttp::handle()` ni el bloque `estimate_gas` de `admin.php`.

## Enfoque (aprobado)

Reescribir las plantillas HTML de `panel.php` y `admin.php` para usar el design-system compartido. Sin partials compartidos nuevos (se descartó el enfoque C por sobre-ingeniería), sin re-estilado selectivo (enfoque B, dejaría HTML con clases propias). Solo se tocan `src/php/panel.php` y `src/php/admin.php`.

## 1. Arquitectura

- `<link rel="stylesheet">` a las tres hojas `assets/css/design-system.css`, `components.css`, `layout.css` en el `<head>`, igual que `index.php`.
- `html { font-size: 14px }` ya viene en el design-system; los paneles heredan body, tipografía y fondo.
- Reutilizar las clases existentes tal cual: `.card`, `.card-header`, `.card-title`, `.kpi-row`, `.kpi-card-value` (+ modificadores `.green`/`.red`/`.accent`), `.data-table`, `.btn`/`.btn-primary`/`.btn-danger`, `.badge`/`.badge-green`/`.badge-red`/`.badge-accent`, `.panel-tabs`/`.panel-tab`/`.panel-content`, `.navbar`/`.navbar-tabs`, `.hide-mobile`, `.empty-state`.
- **Lógica PHP intacta**:
  - `panel.php`: `InvestorHttp::handle($pdo, $_SESSION, $_GET, $_POST, $secret)` igual; `$d['view'] === 'login'` → redirect a `auth.php` igual; `Csrf::token` igual.
  - `admin.php`: guard de rol, bloque `estimate_gas` (JSON con headers), `AdminHttp::handle`, `$result['view'] !== 'overview'` → 403, igual.
  - Todos los forms conservan exactamente sus `name`/`action`/`csrf`/`id`/`method` actuales.
- JS:
  - `admin.php`: el script existente de envío directo (validación + `estimateGas` + confirm checkbox) se conserva funcional y se adapta solo a los nuevos `id` del DOM.
  - Ambos: se añade el toggle de tabs (click en `.panel-tab` activa su `.panel-content` y marca `.active`), mismo patrón que el dashboard. Degradación sin JS: primer tab visible.
- Responsive: columnas secundarias de tablas marcadas `.hide-mobile`; navbar con hamburguesa opcional solo si el contenido lo requiere (los dos paneles tienen pocas entradas — se evalúa al implementar, sin tocar layout.css).

## 2. Panel del inversor (`panel.php`)

Estructura resultante:

```
┌ navbar ────────────────────────────────────────────┐
│ ⚡ Grid Bot · Mi inversión      [Admin?] [Salir]   │
├ kpi-row ───────────────────────────────────────────┤
│ Equidad (g) │ Unidades │ NAV │ Depósitos pendientes│
├ tabs ──────────────────────────────────────────────┤
│ [Resumen] [Depósitos] [Retiros] [Movimientos]      │
└────────────────────────────────────────────────────┘
```

- **Navbar**: branding `⚡ Grid Bot · Mi inversión`; acciones: si `$_SESSION['role'] === 'admin'` link a `admin.php`, y `Salir` → `auth.php?action=logout`. Usuario actual mostrado en `card-header` o navbar.
- **KPIs** (calculados en template sobre los datos ya cargados en `$d`):
  - Equidad — `number_format($d['equity'], 2) USDT`, clase `.green`.
  - Unidades — `number_format($d['units'], 8)`.
  - NAV — `number_format($d['nav'], 6)`.
  - Depósitos pendientes — conteo en template sobre `$d['deposits']` de `status === 'pending'` (badge rojo si >0).
- **Tab Resumen**: saldo/equidad/unidades/NAV + tarjeta de direcciones de depósito (red → dirección en `.mono`).
- **Tab Depósitos**: tabla Estado/Red/Token/Monto/Tx con `.badge` por estado (`pending`/`credited`).
- **Tab Retiros**: form de solicitud (Red, Token, Monto, Destino — mismos nombres) + tabla de retiros con badges de estado.
- **Tab Movimientos**: **nuevo** — renderiza `$d['movements']` (últimos 50) que `InvestorHttp::handle()` ya devuelve (líneas 56-58, 70) pero hoy no se muestran. Columnas: fecha, tipo, cantidad, descripción. Bajo riesgo: dato ya presente en el array; sin tocar el handler. Aprobado por el usuario.
- Flash/error: `$d['flash']` y `$d['error']` re-estilados (verde/rojo), mostrados arriba del contenido activo.

## 3. Panel admin (`admin.php`)

Estructura resultante:

```
┌ navbar ────────────────────────────────────────────────┐
│ ⚡ Grid Bot · Admin            [Mi panel] [Salir]       │
├ kpi-row ───────────────────────────────────────────────┤
│ NAV │ Unidades totales │ Retiros pend. (badge) │ Activos│
├ tabs ──────────────────────────────────────────────────┤
│ [Resumen] [Retiros] [Depósitos] [Usuarios] [Envíos]    │
└────────────────────────────────────────────────────────┘
```

- **Navbar**: branding + links `Mi panel` (`panel.php`) y `Salir`.
- **KPIs** (contables en template sobre datos ya cargados):
  - NAV — `number_format($d['nav'], 6)`.
  - Unidades totales — `number_format($d['total_units'], 2)`.
  - Retiros pendientes — `count($d['pending_withdrawals'])`, badge rojo si >0.
  - Usuarios activos — conteo sobre `$d['users']` de `status === 'active'`.
- **Tab Resumen**: estado del fondo (NAV, unidades totales, wallet_held sin desplegar).
- **Tab Retiros**: retiros pendientes con Aprobar/Rechazar (CSRF intactos) + historial de retiros con "Marcar enviado" (select + tx_hash).
- **Tab Depósitos**: tabla con acción "Marcar desplegado".
- **Tab Usuarios**: tabla con Suspender/Activar.
- **Tab Envíos**: form de envío directo (Red, Token, Destino, Monto, confirm checkbox, botón Enviar, bloque de estimación de gas) + historial de envíos directos. El JS de validación/estimación se conserva; se reanclan los `id` (`network`, `token`, `destination`, `amount`, `confirm`, `sendBtn`, `gasEstimate`) al nuevo DOM.
- Flash/error de `$d['error']` re-estilado (rojo).

## 4. Manejo de errores, accesibilidad y testing

- Flash/error: `.flash`/`.err` con colores del design-system, visibles sobre el contenido activo.
- Estado vacío: tablas sin filas muestran `.empty-state` ("Sin registros") en vez de tabla vacía.
- Degradación sin JS: los tabs caen al primero visible; los forms POST funcionan sin JS (solo el switch de tabs y la estimación de gas requieren JS).
- Seguridad: se conservan todos los `htmlspecialchars` existentes; no cambia authZ ni CSRF.
- **Testing**:
  - `vendor/bin/phpunit` debe seguir verde (no cambian firmas ni lógica).
  - E2E manual: ambos paneles cargan (inversor y admin), round-trips de retiro/aprobación/suspensión/envío, tabs alternan, KPIs muestran valores.
  - `phpcs` best-effort (ruleset pre-existente roto — se reporta, no se bloquea).
- **Archivos tocados**: `src/php/panel.php`, `src/php/admin.php` únicamente. Sin cambios en `Core/`. Sin tests nuevos salvo que un test de integración existente referencie el HTML de estos paneles (a verificar durante la implementación).

## Criterios de éxito

1. `panel.php` y `admin.php` renderizan con el aspecto del dashboard (fondo `#0a0e17`, tarjetas, KPIs, tabs, tablas/badges del sistema).
2. Toda acción existente funciona idéntico (retirar, aprobar, rechazar, desplegar, suspender, activar, enviar directo, estimar gas).
3. Suite `vendor/bin/phpunit` verde.
4. Responsive en móvil sin romper la tabla de KPIs ni los forms.
