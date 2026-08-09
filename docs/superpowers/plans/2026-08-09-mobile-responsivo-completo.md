# Versión móvil completa responsiva — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminar el overflow horizontal del nav de la landing en móvil (320–480px) con un menú hamburguesa, y verificar que ninguna página del sitio desborde horizontalmente en viewports 320–1024.

**Architecture:** Cambio de código único y autocontenido en `index.php` (landing): botón hamburguesa + media query ≤767px que convierte `.nav-links` en panel desplegable vertical + JS toggle mínimo. El resto de páginas ya es responsive (verificado: login/register/panel) o tiene responsive propio (dashboard/admin, pendientes de re-auditoría con credencial admin). Verificación empírica con Chrome headless vía CDP.

**Tech Stack:** PHP (sin cambios de lógica), CSS vanilla, JS vanilla (toggle de clase), Chrome headless + harness browser-use 0.1.8 para verificación.

## Global Constraints

- **Único archivo de código a modificar:** `index.php` (raíz). NO tocar `src/php/*` salvo que la re-auditoría con admin encuentre una rotura real.
- **No rediseño estético:** mantener paleta, fuentes y estructura visual actual. Solo añadir botón + media query + JS.
- **No tocar** `vendor/`, `docs/` (salvo el plan), `index2.php`, `.env`.
- **Patrón del dashboard:** replicar el patrón `.menu-btn` ya existente en `src/php/index.php` (botón `☰` + toggle `classList` + panel). Nada de librerías.
- **Evidencia:** guardar screenshots de la landing en 390px en `/tmp/opencode/`.
- **Verificación de regresión:** `php -l index.php` limpio; suite PHPUnit `vendor/bin/phpunit -c phpunit.xml.dist` = 241 tests / 993 assertions PASS (baseline warning+deprecación pre-existentes, NO atribuibles).

---
### Task 1: Menú hamburguesa en la landing

**Files:**
- Modify: `index.php:27` (CSS base), `index.php:89-95` (HTML nav), `index.php:203` (JS), y añadir media query ≤767px en el bloque CSS.

**Interfaces:**
- Consumes: nada (primer task).
- Produces: `.menu-btn` visible ≤767px; `.nav-links` colapsado en móvil con clase `.open`; JS que toglea.

- [ ] **Step 1: Añadir el botón hamburguesa al HTML del nav**

En `index.php`, dentro de `<nav class="land-nav">`, entre el `</div>` de `.brand` (línea 88) y `<div class="nav-links">` (línea 89), insertar el botón:

```html
  <button class="menu-btn" aria-label="Menú">☰</button>
```

Resultado esperado del bloque (líneas 87-90):

```html
    </div>
  </div>
  <button class="menu-btn" aria-label="Menú">☰</button>
  <div class="nav-links">
```

- [ ] **Step 2: Añadir el CSS base del botón**

En `index.php`, junto a las reglas del nav (después de la línea 29 `.nav-links a:hover{color:var(--text)}`), añadir:

```css
.menu-btn{display:none;background:none;border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:20px;width:40px;height:40px;cursor:pointer;align-items:center;justify-content:center;flex-shrink:0}
.menu-btn:hover{border-color:var(--accent);color:var(--accent)}
```

- [ ] **Step 3: Añadir el media query móvil**

En `index.php`, después del bloque `@media(max-width:767px){.hero{...}}` (línea 31), añadir el bloque:

```css
@media(max-width:767px){
  .menu-btn{display:flex}
  .nav-links{display:none;flex-direction:column;align-items:stretch;gap:0;position:absolute;top:60px;left:0;right:0;background:var(--bg2);border-bottom:1px solid var(--border);padding:8px 24px 16px;box-shadow:0 12px 24px rgba(0,0,0,.4)}
  .nav-links.open{display:flex}
  .nav-links a{padding:12px 0;width:100%;border-bottom:1px solid var(--border)}
  .nav-links a:last-child{border-bottom:none}
  .nav-links .btn.btn-primary{margin-top:12px;justify-content:center}
}
```

Nota: `top:60px` = altura exacta de `.land-nav`. El `.land-nav` es `position:sticky` (línea 23), que establece containing block para el panel absoluto.

- [ ] **Step 4: Añadir el JS de toggle**

En `index.php`, al final del bloque `<script>` (después de la línea 204 `setInterval(loadStats, 10000);`, antes de `</script>`), añadir:

```js
const _mb = document.querySelector('.menu-btn'), _nl = document.querySelector('.nav-links');
if(_mb && _nl){
  _mb.addEventListener('click', () => _nl.classList.toggle('open'));
  _nl.querySelectorAll('a').forEach(a => a.addEventListener('click', () => _nl.classList.remove('open')));
}
```

- [ ] **Step 5: Verificar sintaxis y ausencia de regresión**

Run: `php -l index.php`
Expected: `No syntax errors detected in index.php`

Run: `vendor/bin/phpunit -c phpunit.xml.dist 2>&1 | tail -4`
Expected: `Tests: 241, Assertions: 993, ... Warnings: 1, PHPUnit Deprecations: 1` (baseline pre-existente, sin fallos)

- [ ] **Step 6: Commit**

```bash
git add index.php
git commit -m "feat(landing): menú hamburguesa responsive para móvil"
```

---
### Task 2: Verificación empírica en viewports móviles

**Files:**
- None (solo lectura/verificación). Se usa Chrome headless vía `browser-use`.

**Interfaces:**
- Consumes: Task 1 (`.menu-btn` + `.nav-links.open` en `index.php`).

- [ ] **Step 1: Levantar Chrome headless**

```bash
nohup /opt/google/chrome/chrome --remote-debugging-port=9222 --user-data-dir=/tmp/opencode/chrome-profile --no-first-run --no-default-browser-check --no-sandbox --headless=new >/tmp/opencode/chrome.log 2>&1 &
sleep 5
curl -s http://127.0.0.1:9222/json/version | head -c 120
```
Expected: JSON con `"Browser": "Chrome/151..."`.

- [ ] **Step 2: Auditar la landing en 320/360/390/414/480**

```bash
export PATH="$HOME/.local/bin:$PATH"; export BU_CDP_URL="http://127.0.0.1:9222"
browser-use <<'PY'
import json, time
new_tab("https://binance.gregorbritez.cat/")
wait_for_load()
for w in [320, 360, 390, 414, 480]:
    cdp("Emulation.setDeviceMetricsOverride", width=w, height=844, deviceScaleFactor=2, mobile=True)
    time.sleep(0.3)
    info = json.loads(js("JSON.stringify({sw: document.documentElement.scrollWidth, hScroll: document.documentElement.scrollWidth > %d})" % w))
    offenders = json.loads(js("""JSON.stringify(Array.from(document.querySelectorAll('*')).filter(el => { const r = el.getBoundingClientRect(); return r.right > %d || r.left < -1; }).slice(0,5).map(el => (el.tagName + (el.id?('#'+el.id):'') + '.' + (typeof el.className==='string'?el.className.slice(0,30):''))))""" % (w+1)))
    print(w, "->", info, offenders)
PY
```
Expected: `hScroll: False` y `offenders: []` en los 5 viewports. (Antes del fix: `nav-links` en offenders con sw≈506.)

- [ ] **Step 3: Verificar el toggle de la hamburguesa en 390px**

```bash
export PATH="$HOME/.local/bin:$PATH"; export BU_CDP_URL="http://127.0.0.1:9222"
browser-use <<'PY'
import json, time
cdp("Emulation.setDeviceMetricsOverride", width=390, height=844, deviceScaleFactor=2, mobile=True)
time.sleep(0.3)
# visible y oculto
print("menu-btn visible:", json.loads(js("JSON.stringify(getComputedStyle(document.querySelector('.menu-btn')).display !== 'none')")))
print("nav-links closed:", json.loads(js("JSON.stringify(!document.querySelector('.nav-links').classList.contains('open'))")))
# click abre
q = cdp("Accessibility.getFullAXTree")["nodes"]
btn = [n for n in q if (n.get("name") or {}).get("value","") == "Menú"][0]
bm = cdp("DOM.getBoxModel", backendNodeId=btn["backendDOMNodeId"])["model"]["content"]
click_at_xy(sum(bm[0::2])/4, sum(bm[1::2])/4)
time.sleep(0.5)
print("open after click:", json.loads(js("JSON.stringify(document.querySelector('.nav-links').classList.contains('open'))")))
# click en un link cierra
print("links visible:", json.loads(js("JSON.stringify(getComputedStyle(document.querySelector('.nav-links')).display !== 'none')")))
js("document.querySelector('.nav-links a').click()")
time.sleep(0.4)
print("closed after link click:", json.loads(js("JSON.stringify(!document.querySelector('.nav-links').classList.contains('open'))")))
PY
```
Expected: `menu-btn visible: True`, `nav-links closed: True`, `open after click: True`, `links visible: True`, `closed after link click: True`.

- [ ] **Step 4: Guardar screenshots de evidencia (390px, cerrado y abierto)**

```bash
export PATH="$HOME/.local/bin:$PATH"; export BU_CDP_URL="http://127.0.0.1:9222"
browser-use <<'PY'
import base64, json, time
cdp("Emulation.setDeviceMetricsOverride", width=390, height=844, deviceScaleFactor=2, mobile=True)
time.sleep(0.3)
shot = cdp("Page.captureScreenshot", format="png", captureBeyondViewport=False)
open("/tmp/opencode/mobile-landing-nav-closed.png","wb").write(base64.b64decode(shot["data"]))
js("document.querySelector('.menu-btn').click()")
time.sleep(0.5)
shot = cdp("Page.captureScreenshot", format="png", captureBeyondViewport=False)
open("/tmp/opencode/mobile-landing-nav-open.png","wb").write(base64.b64decode(shot["data"]))
print("saved 2 screenshots")
PY
```
Expected: dos PNG en `/tmp/opencode/`.

- [ ] **Step 5: Regresión login/register/panel en 390px**

```bash
export PATH="$HOME/.local/bin:$PATH"; export BU_CDP_URL="http://127.0.0.1:9222"
browser-use <<'PY'
import json, time
for name, url in [("login","https://binance.gregorbritez.cat/src/php/login.php"),
                  ("register","https://binance.gregorbritez.cat/src/php/register.php"),
                  ("panel","https://binance.gregorbritez.cat/src/php/panel.php")]:
    new_tab(url)
    wait_for_load()
    cdp("Emulation.setDeviceMetricsOverride", width=390, height=844, deviceScaleFactor=2, mobile=True)
    time.sleep(0.5)
    info = json.loads(js("JSON.stringify({sw: document.documentElement.scrollWidth, hScroll: document.documentElement.scrollWidth > 391})"))
    print(name, "->", info)
PY
```
Expected: `hScroll: False` en las tres. Nota: panel.php sin sesión redirige a auth.php — ambos 200 y sin overflow es aceptable.

- [ ] **Step 6: Dashboard/admin (requiere credencial admin)**

Si el usuario aportó credencial admin: loguear en `src/php/login.php` con usuario admin, navegar a `src/php/index.php` y `src/php/admin.php`, y repetir la auditoría de scrollWidth en 320/390/768/1024 con el mismo patrón del Step 2.

Si NO hay credencial: registrar en el ledger como pendiente (dashboard/admin ya tienen responsive del plan de julio-2026 y CSS de panel; no hay evidencia de rotura).

- [ ] **Step 7: Cerrar Chrome y registrar evidencia**

```bash
pkill -f "remote-debugging-port=9222"
```

Añadir al ledger `.superpowers/sdd/progress.md` la sección del plan: resultado por viewport (sw/hScroll), verificación del toggle, screenshots, resultado de regresión, y estatus de dashboard/admin.

- [ ] **Step 8: Commit**

```bash
git add .superpowers/sdd/progress.md
git commit -m "docs: record mobile responsive verification"
```

---
## Self-Review

**Spec coverage:**
- §1 (nav hamburguesa) → Task 1 (Steps 1-6).
- §2 (sin cambios en otras páginas) → respetado: solo `index.php` en Task 1; Task 2 es verificación.
- §Verificación (batería 320-1024, toggle, regresión, evidencia) → Task 2 Steps 2-5.
- §Dashboard/admin re-auditoría → Task 2 Step 6 (condicional a credencial admin).
- Criterios de aceptación 1-5 → Steps 2-6.
- `php -l` + PHPUnit → Task 1 Step 5.

**Placeholder scan:** sin TBD/TODO; todo el CSS/HTML/JS escrito inline; comandos con output esperado.

**Type consistency:** `.menu-btn`, `.nav-links`, `.nav-links.open`, `_mb`, `_nl` — mismos nombres en Task 1 y Task 2. El HTML insertado (Step 1) coincide con lo que los selectores CSS/JS esperan (Step 3/4).
