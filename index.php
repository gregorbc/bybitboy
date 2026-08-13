<?php
/**
 * index.php — Landing pública Grid Bot · ETH/USDT
 */
error_reporting(0); ini_set('display_errors', '0');
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Grid Bot · ETH/USDT · Trading Automatizado</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="src/php/assets/css/design-system.css">
<style>
:root{--bg:#0a0e17;--bg2:#111827;--bg3:#1a2333;--border:#1e3a5f;--accent:#0ea5e9;--green:#22c55e;--red:#ef4444;--text:#f1f5f9;--muted:#94a3b8;--dim:#64748b;--radius:12px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);line-height:1.6}
a{color:var(--accent);text-decoration:none}
.land-nav{position:sticky;top:0;z-index:100;background:rgba(10,14,23,.92);backdrop-filter:blur(16px);border-bottom:1px solid var(--border);padding:0 24px;height:60px;display:flex;align-items:center;justify-content:space-between}
.brand{display:flex;align-items:center;gap:10px;font-weight:700}
.brand-icon{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,var(--accent),#7c3aed);display:grid;place-items:center;font-size:18px}
.brand-sub{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);display:block;margin-top:1px}
.nav-links{display:flex;align-items:center;gap:20px}
.nav-links a{font-size:13px;color:var(--muted)}
.nav-links a:hover{color:var(--text)}
.nav-links .btn.btn-primary{padding:8px 16px}
.menu-btn{display:none;background:none;border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:20px;width:40px;height:40px;cursor:pointer;align-items:center;justify-content:center;flex-shrink:0}
.menu-btn:hover{border-color:var(--accent);color:var(--accent)}
.hero{display:grid;grid-template-columns:1.2fr .8fr;gap:40px;max-width:1100px;margin:0 auto;padding:80px 24px 60px;align-items:center}
@media(max-width:767px){
  .hero{grid-template-columns:1fr;padding:48px 24px 40px}
  .brand-sub{white-space:nowrap}
  .menu-btn{display:flex}
  .nav-links{display:none;flex-direction:column;align-items:stretch;gap:0;position:absolute;top:60px;left:0;right:0;background:var(--bg2);border-bottom:1px solid var(--border);padding:8px 24px 16px;box-shadow:0 12px 24px rgba(0,0,0,.4)}
  .nav-links.open{display:flex}
  .nav-links a{padding:12px 0;width:100%;border-bottom:1px solid var(--border)}
  .nav-links a:last-child{border-bottom:none}
  .nav-links .btn.btn-primary{margin-top:12px;justify-content:center;padding:12px 16px}
}
.hero h1{font-size:clamp(28px,4.5vw,46px);line-height:1.15;margin-bottom:16px}
.hero h1 .grad{background:linear-gradient(90deg,var(--accent),#7c3aed);-webkit-background-clip:text;background-clip:text;color:transparent}
.hero p.lead{color:var(--muted);font-size:17px;max-width:520px;margin-bottom:28px}
.price-big{font-family:'JetBrains Mono',monospace;font-size:30px;font-weight:600}
.price-chg-up{color:var(--green);font-size:13px;font-weight:700}
.price-chg-dn{color:var(--red);font-size:13px;font-weight:700}
.cta-row{display:flex;gap:12px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 20px;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer;border:1px solid var(--border);background:var(--bg3);color:var(--text);transition:.15s}
.btn:hover{transform:translateY(-1px)}
.btn-primary{background:var(--accent);color:#fff;border-color:var(--accent)}
.btn-primary:hover{background:#38bdf8}
.btn-outline{background:transparent;border-color:var(--border)}
.btn-outline:hover{border-color:var(--accent);color:var(--accent)}
.stats-dash{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:24px}
.stats-dash h3{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:16px}
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
@media(max-width:767px){.stats-grid{grid-template-columns:1fr 1fr}}
@media(max-width:480px){.stats-grid{grid-template-columns:1fr}}
.stat{border-top:2px solid var(--border)}
.stat-lbl{font-size:11px;color:var(--muted);margin:8px 0 4px}
.stat-val{font-family:'JetBrains Mono',monospace;font-size:18px;font-weight:600}
.stat-val.up{color:var(--green)}.stat-val.down{color:var(--red)}.stat-val.accent{color:var(--accent)}
.section{max-width:1100px;margin:0 auto;padding:70px 24px}
.section-hd{text-align:center;margin-bottom:44px}
.section-hd h2{font-size:30px;margin-bottom:8px}
.section-hd p{color:var(--muted)}
.features{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
@media(max-width:767px){.features{grid-template-columns:1fr}}
.feat{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:22px}
.feat-icon{font-size:24px;margin-bottom:10px}
.feat h3{font-size:16px;margin-bottom:6px}
.feat p{color:var(--muted);font-size:13px}
.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
@media(max-width:767px){.steps{grid-template-columns:1fr}}
.step{background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:22px;position:relative}
.step-num{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--accent);font-weight:700;margin-bottom:8px}
.step h3{font-size:16px;margin-bottom:6px}
.step p{color:var(--muted);font-size:13px}
.demo-section{max-width:1100px;margin:0 auto;padding:40px 24px 80px}
.demo-card{background:linear-gradient(135deg,var(--bg2),var(--bg3));border:1px solid var(--border);border-radius:var(--radius);padding:44px;text-align:center}
.demo-card h2{font-size:26px;margin-bottom:8px}
.demo-card p{color:var(--muted);margin-bottom:24px}
.land-footer{border-top:1px solid var(--border);padding:32px 24px;text-align:center;color:var(--dim);font-size:12px}
.land-footer p{margin:6px 0}
.spin{display:inline-block;width:14px;height:14px;border:2px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:sp 1s linear infinite;vertical-align:-2px}
@keyframes sp{to{transform:rotate(360deg)}}
</style>
</head>
<body>
<nav class="land-nav">
  <div class="brand">
    <div class="brand-icon">⚡</div>
    <div>
      <div>Grid Bot</div>
      <div class="brand-sub">ETH/USDT · BYBIT · 24/7</div>
    </div>
  </div>
  <button class="menu-btn" aria-label="Menú" aria-expanded="false" aria-controls="nav-links">☰</button>
  <div class="nav-links" id="nav-links">
    <a href="#demo">Demo</a>
    <a href="#como-funciona">Cómo funciona</a>
    <a href="#caracteristicas">Características</a>
    <a href="src/php/login.php">Ingresar</a>
    <a href="src/php/register.php" class="btn btn-primary">Crear cuenta</a>
  </div>
</nav>

<section class="hero">
  <div>
    <h1>Trading automatizado con <span class="grad">Grid Bot + IA</span> en ETH/USDT</h1>
    <p class="lead">Estrategia grid en Bybit con señales de machine learning, rebalanceo en tiempo real y gestión de riesgo. Sin errores humanos, operando 24/7.</p>
    <div class="cta-row">
      <a href="#demo" class="btn btn-primary">▶ Ver demo en vivo</a>
      <a href="src/php/register.php" class="btn btn-outline">Crear cuenta gratis</a>
    </div>
  </div>
  <div class="stats-dash">
    <h3>Rendimiento en vivo</h3>
    <div class="price-big" id="ldPrice">$<span id="ldPriceVal">--</span></div>
    <div id="ldChg" class="price-chg-up">--</div>
    <div class="stats-grid" style="margin-top:18px">
      <div class="stat"><div class="stat-lbl">PnL de hoy</div><div class="stat-val" id="ldPnl">--</div></div>
      <div class="stat"><div class="stat-lbl">PnL total</div><div class="stat-val" id="ldPnlTotal">--</div></div>
      <div class="stat"><div class="stat-lbl">Est. 30 días</div>
        <div class="stat-val accent" id="ldProj">--</div>
        <div style="font-size:10px;color:var(--dim)" id="ldProjDays">--</div>
      </div>
      <div class="stat"><div class="stat-lbl">Win rate</div><div class="stat-val accent" id="ldWin">--</div></div>
      <div class="stat"><div class="stat-lbl">Fills totales</div><div class="stat-val" id="ldFills">--</div></div>
      <div class="stat"><div class="stat-lbl">Órdenes abiertas</div><div class="stat-val" id="ldOpen">--</div></div>
    </div>
    <div style="margin-top:14px;font-size:11px;color:var(--dim)" id="ldUpd">Actualizado: --</div>
  </div>
</section>

<section id="caracteristicas" class="section">
  <div class="section-hd"><h2>Características</h2><p>Todo lo que el bot hace por ti</p></div>
  <div class="features">
    <div class="feat"><div class="feat-icon">📊</div><h3>Grid Trading</h3><p>Compra y vende en niveles predefinidos automáticamente, capturando la volatilidad del mercado.</p></div>
    <div class="feat"><div class="feat-icon">🧠</div><h3>Señales con IA</h3><p>Modelos de machine learning evalúan dirección, confianza y razón de cada operación.</p></div>
    <div class="feat"><div class="feat-icon">⚡</div><h3>Tiempo real</h3><p>WebSocket bidireccional con actualización instantánea de precio, PnL y órdenes.</p></div>
    <div class="feat"><div class="feat-icon">🛡️</div><h3>Gestión de riesgo</h3><p>Modo recovery, límites de apalancamiento y paradas para proteger el capital.</p></div>
    <div class="feat"><div class="feat-icon">🕐</div><h3>24/7</h3><p>Servicio systemd con reinicio automático. Sin dependencia de estar pendiente de la pantalla.</p></div>
    <div class="feat"><div class="feat-icon">📈</div><h3>PnL trazable</h3><p>Historial completo de fills, win rate y acumulado por día/hora en el dashboard.</p></div>
  </div>
</section>

<section id="como-funciona" class="section">
  <div class="section-hd"><h2>Cómo funciona</h2><p>Empieza en tres pasos</p></div>
  <div class="steps">
    <div class="step"><div class="step-num">PASO 1</div><h3>Regístrate</h3><p>Crea tu cuenta de inversor con usuario, email y contraseña.</p></div>
    <div class="step"><div class="step-num">PASO 2</div><h3>Recibe tu dashboard</h3><p>Accede al panel de inversor con tu capital, movimientos y rendimiento.</p></div>
    <div class="step"><div class="step-num">PASO 3</div><h3>Sigue tu PnL</h3><p>Visualiza en tiempo real cómo el bot opera y crece tu equidad.</p></div>
  </div>
</section>

<section id="demo" class="demo-section">
  <div class="demo-card">
    <h2>¿Quieres ver el bot en acción?</h2>
    <p>Abre el dashboard completo con el gráfico, el ladder de órdenes y el PnL en tiempo real.</p>
    <a href="src/php/index.php" target="_blank" rel="noopener" class="btn btn-primary">▶ Abrir dashboard en vivo</a>
  </div>
</section>

<footer class="land-footer">
  <p><strong>Grid Bot · ETH/USDT</strong> · Trading automatizado sobre Bybit</p>
  <p>⚠️ El trading de criptomonedas con apalancamiento conlleva un alto riesgo. Este producto no es una recomendación de inversión.</p>
  <p>© <?= date('Y') ?> binance.gregorbritez.cat</p>
</footer>

<script>
const API = 'src/php/grid_ajax.php';
const fmt = v => (parseFloat(v)||0).toLocaleString('es-PY',{maximumFractionDigits:2});
const fmt2 = v => (parseFloat(v)||0).toLocaleString('es-PY',{minimumFractionDigits:2,maximumFractionDigits:2});
async function loadStats(){
  try{
    const r = await fetch(API+'?_landing_stats=1');
    const d = await r.json();
    if(!d || !d.ok) throw new Error('bad response');
    const price = parseFloat(d.price||0);
    const pnl = parseFloat(d.pnl_today||0);
    const pnlTotal = parseFloat(d.pnl_total||0);
    document.getElementById('ldPriceVal').textContent = fmt2(price);
    const chgEl = document.getElementById('ldChg');
    chgEl.textContent = 'ETH/USDT · Bybit';
    chgEl.className = 'price-chg-up';
    const pnlEl = document.getElementById('ldPnl');
    pnlEl.textContent = (pnl>=0?'+':'')+fmt2(pnl)+' $';
    pnlEl.className = 'stat-val '+(pnl>=0?'up':'down');
    const pnlTotalEl = document.getElementById('ldPnlTotal');
    pnlTotalEl.textContent = (pnlTotal>=0?'+':'')+fmt2(pnlTotal)+' $';
    pnlTotalEl.className = 'stat-val '+(pnlTotal>=0?'up':'down');
    const proj = parseFloat(d.pnl_proj_30d||0);
    const projDays = parseInt(d.pnl_proj_days||0, 10);
    const projEl = document.getElementById('ldProj');
    if(projDays>0){
      projEl.textContent = (proj>=0?'+':'')+fmt2(proj)+' $';
      projEl.className = 'stat-val '+(proj>=0?'up':'down');
      document.getElementById('ldProjDays').textContent = 'basado en '+projDays+' día'+(projDays!==1?'s':'');
    }else{
      projEl.textContent = '--';
      projEl.className = 'stat-val accent';
      document.getElementById('ldProjDays').textContent = 'sin historial aún';
    }
    document.getElementById('ldWin').textContent = fmt(d.win_rate)+'%';
    document.getElementById('ldFills').textContent = fmt(d.fills_total);
    document.getElementById('ldOpen').textContent = fmt(d.open_orders);
    document.getElementById('ldUpd').textContent = 'Actualizado: '+(d.updated_at||'').replace('T',' ').slice(0,19);
  }catch(e){
    document.getElementById('ldUpd').textContent = 'No se pudieron cargar las estadísticas';
  }
}
loadStats();
setInterval(loadStats, 10000);
const _mb = document.querySelector('.menu-btn'), _nl = document.querySelector('.nav-links');
if(_mb && _nl){
  _mb.addEventListener('click', () => {
    const _open = _nl.classList.toggle('open');
    _mb.setAttribute('aria-expanded', _open ? 'true' : 'false');
  });
  _nl.querySelectorAll('a').forEach(a => a.addEventListener('click', () => {
    _nl.classList.remove('open');
    _mb.setAttribute('aria-expanded', 'false');
  }));
  window.addEventListener('resize', () => {
    if(window.innerWidth > 767){ _nl.classList.remove('open'); _mb.setAttribute('aria-expanded', 'false'); }
  });
}
</script>
</body>
</html>
