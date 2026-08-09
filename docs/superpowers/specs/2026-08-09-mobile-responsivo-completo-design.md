# Spec: Versión móvil completa responsiva (todo el sitio)

**Fecha:** 2026-08-09
**Estado:** aprobado por el usuario

## Contexto

El sitio tiene páginas con distinto grado de responsive:

- **Landing (`index.php`)** — tiene media queries básicas (hero, stats-grid, features, steps) pero el nav (`nav-links`) desborda horizontalmente en todo el rango móvil (320–480px → scrollWidth hasta 506px).
- **Login / Register** — tarjetas centradas con `max-width:380px`, sin overflow (verificado).
- **Panel inversor (`src/php/panel.php`)** — usa `layout.css`/`components.css` con media queries propias; sin overflow (verificado en 390/768/1024).
- **Dashboard (`src/php/index.php`)** — tuvo plan responsive completo (2026-07-31) con breakpoints 991/767/480; requiere sesión admin para auditar.
- **Admin (`src/php/admin.php`)** — mismo CSS del panel; requiere sesión admin para auditar.

## Decisiones confirmadas con el usuario

1. **Alcance:** todo el sitio (landing, dashboard, panel, admin, login/register).
2. **Nivel:** "responsive sólido" — arreglar overflow/roturas, mantener el diseño visual actual. No es rediseño mobile-first ni cambio estético mayor.
3. **Enfoque:** **A — Auditoría + fixes dirigidos.** Arreglar el nav de la landing con menú hamburguesa, auditar cada sección y viewport (320→1024), y re-auditar dashboard/admin con credencial admin (que el usuario aporta al momento de implementar).
4. **Dashboard/admin:** re-auditar con credencial admin del usuario si la aporta; si no, se documenta como pendiente (ya tienen responsive previo).

## Cambios de código

### 1. `index.php` — nav de la landing (único cambio de código)

Problema: `.nav-links` es `display:flex` con `gap:20px` y 5 links sin wrap; en ≤480px su ancho intrínseco (~390px) desborda el viewport (scrollWidth 506px).

**HTML:** añadir botón hamburguesa en `.land-nav`, antes de `.nav-links`:

```html
<button class="menu-btn" aria-label="Menú">☰</button>
```

**CSS base:**
```css
.menu-btn{display:none;background:none;border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:20px;width:40px;height:40px;cursor:pointer}
```

**CSS ≤767px** (dentro del media query existente o uno nuevo):
```css
.menu-btn{display:flex;align-items:center;justify-content:center}
.nav-links{display:none;flex-direction:column;align-items:flex-start;gap:0;position:absolute;top:60px;left:0;right:0;background:var(--bg2);border-bottom:1px solid var(--border);padding:8px 24px 16px}
.nav-links.open{display:flex}
.nav-links a{padding:10px 0;width:100%}
```

**JS:** toggle en el botón, cierre al hacer click en un link:
```js
const mb = document.querySelector('.menu-btn'), nl = document.querySelector('.nav-links');
if(mb && nl){
  mb.addEventListener('click', () => nl.classList.toggle('open'));
  nl.querySelectorAll('a').forEach(a => a.addEventListener('click', () => nl.classList.remove('open')));
}
```

- El botón debe ir antes de `.nav-links` en el DOM (flex row, a la derecha del brand).
- `.nav-links a` con `width:100%` y `padding:10px 0` → targets táctiles cómodos.
- El link "Crear cuenta" (`.btn btn-primary`) mantiene su estilo en el panel desplegado.

### 2. Sin cambios en dashboard/panel/admin/login/register

Ya son responsive (verificado para los accesibles; dashboard/admin pendientes de re-auditoría con admin).

## Verificación

### Auditoría por página (Chrome headless, CDP)

Batería de viewports: **320, 360, 390, 414, 480, 768, 1024**.

Por página y viewport, confirmar:

1. `document.documentElement.scrollWidth <= clientWidth` (sin overflow horizontal de página).
2. No hay elementos con `getBoundingClientRect().right > viewport + 1`.

| Página | Requisito específico |
|---|---|
| Landing | scrollWidth ≤ width en todos; hamburguesa visible ≤767px y oculta >767px; clic abre/cierra el panel; clic en un link lo cierra |
| Login / Register | sin overflow (regresión) |
| Panel | sin overflow en 390/768/1024 (regresión) |
| Dashboard | sin overflow en todos los viewports (requiere admin) |
| Admin | sin overflow en todos los viewports (requiere admin) |

### Evidencia

- Output de scrollWidth/clientWidth por viewport y página.
- Screenshot de la landing en 390px (nav cerrado y abierto) guardado en `/tmp/opencode/`.

### Pruebas de regresión

- `php -l src/php/index.php` limpio.
- Suite PHPUnit (`vendor/bin/phpunit -c phpunit.xml.dist`): 241 tests / 993 assertions PASS (baseline warning+deprecación pre-existentes). No debería cambiar (no se toca lógica PHP/JS del dashboard).

## Criterios de aceptación

1. Landing sin overflow horizontal en 320–480px.
2. Hamburguesa funcional: abre/cierra el panel, clic en link lo cierra.
3. Sin regresiones visuales en login/register/panel (scrollWidth verificado).
4. Dashboard/admin sin overflow en todos los viewports (si hay credencial admin).
5. `php -l` y suite PHPUnit verdes.

## Fuera de alcance

- Rediseño estético/mobile-first de ninguna página.
- Breakpoints nuevos en dashboard/panel/admin más allá de los existentes (salvo que la re-auditoría con admin encuentre roturas).
- Verificación del dashboard/admin sin credencial admin (se documenta como pendiente).
