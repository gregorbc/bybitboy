<?php
/**
 * Grid Bot Dashboard v16.0 - Modern TypeScript Architecture
 * Uses compiled assets from Vite build
 */
error_reporting(0);
ini_set('display_errors', '0');
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$cfg = botCfg();

function trimRecursive(array $arr): array {
    $out = [];
    foreach ($arr as $k => $v) {
        $tk = trim($k);
        $out[$tk] = is_array($v) ? trimRecursive($v) : (is_string($v) ? trim($v) : $v);
    }
    return $out;
}

$cfg = trimRecursive($cfg);
$mc = $cfg['mysql'] ?? [];
define('EXPORT_TOKEN', getenv('SECURITY_TOKEN') ?: '');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => true,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

if (!isAdminSession($_SESSION)) {
    header('Location: login.php');
    exit;
}

$IS_ADMIN = true;
$CTRL_TOKEN = EXPORT_TOKEN;
$AI_INT   = (int)($cfg['bot']['ai_interval_sec'] ?? 120);
$CAPITAL  = (int)($cfg['bot']['capital_usd']     ?? 20);
$LEVERAGE = (int)($cfg['bot']['leverage']        ?? 100);

// Export PnL CSV
if (isset($_GET['export_pnl'])) {
    if (!$IS_ADMIN) { http_response_code(403); exit("Acceso denegado"); }
    if (!isset($_GET['token']) || !hash_equals(EXPORT_TOKEN, (string)$_GET['token'])) { http_response_code(403); exit("Acceso denegado"); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pnl_diario_ethusdt_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    if (!empty($mc['host'])) {
        try {
            $db = new PDO("mysql:host={$mc['host']};dbname={$mc['dbname']};charset=utf8mb4", $mc['user'], $mc['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $db->exec("SET time_zone = '+00:00'");
            $rows = $db->query("SELECT DATE(filled_at) AS fecha, COUNT(*) AS ops,
                SUM(CASE WHEN pnl_usd>0 THEN 1 ELSE 0 END) AS gan,
                SUM(CASE WHEN pnl_usd<0 THEN 1 ELSE 0 END) AS perd,
                ROUND(SUM(pnl_usd),6) AS pnl_dia, ROUND(AVG(pnl_usd),6) AS prom,
                ROUND(MAX(pnl_usd),6) AS max_pnl, ROUND(MIN(pnl_usd),6) AS min_pnl
                FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' AND filled_at IS NOT NULL
                GROUP BY DATE(filled_at) ORDER BY fecha ASC")->fetchAll();
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            $headers = ['Fecha','Ops','Ganadas','Perdidas','Win%','PnL Día','Promedio','Máximo','Mínimo','Acumulado'];
            fputcsv($out, $headers, "\t");
            $acum = 0.0;
            foreach ($rows as $r) {
                $p = (float)$r['pnl_dia']; $acum += $p;
                $wr = $r['ops'] > 0 ? round($r['gan'] / $r['ops'] * 100, 1) : 0.0;
                $fmt = function($num) { return str_replace('.', ',', (string)$num); };
                fputcsv($out, [
                    $r['fecha'],
                    (int)$r['ops'],
                    (int)$r['gan'],
                    (int)$r['perd'],
                    $fmt($wr) . '%',
                    $fmt(round($p, 6)),
                    $fmt(round((float)$r['prom'], 6)),
                    $fmt(round((float)$r['max_pnl'], 6)),
                    $fmt(round((float)$r['min_pnl'], 6)),
                    $fmt(round($acum, 6))
                ], "\t");
            }
            fclose($out);
        } catch (Exception $e) { echo "Error DB: " . $e->getMessage(); }
    }
    exit;
}

// Initial data for SSR
$init = null;
if (!empty($mc['host'])) {
    try {
        $db = new PDO("mysql:host={$mc['host']};dbname={$mc['dbname']};charset=utf8mb4", $mc['user'], $mc['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec("SET time_zone = '+00:00'");
        $row   = $db->query("SELECT * FROM grid_configs WHERE symbol='ETHUSDT' ORDER BY id DESC LIMIT 1")->fetch() ?: [];
        $pnlT  = $db->query("SELECT COALESCE(SUM(pnl_usd),0) p FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE()")->fetch();
        $fills = $db->query("SELECT COUNT(*) c FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED'")->fetch();
        $openO = (int)$db->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='ETHUSDT' AND status='OPEN'")->fetchColumn();
        $mlAcc = (float)($row['ml_accuracy'] ?? 0);
        $init  = [
            'pnl_today'    => (float)($pnlT['p']??0),
            'fills_total'  => (int)($fills['c']??0),
            'open_orders'  => $openO,
            'direction'    => $row['direction']??'SIDEWAYS',
            'confidence'   => (int)($row['confidence']??50),
            'ai_reason'    => $row['ai_reason']??'Evaluando...',
            'levels'       => (int)($row['levels']??8),
            'long_levels'  => (int)($row['long_levels']??4),
            'short_levels' => (int)($row['short_levels']??4),
            'spacing_pct'  => (float)($row['spacing_pct']??0.0008),
            'recovery_active' => (bool)($row['recovery_active']??false),
            'capital'      => $CAPITAL,
            'ml_accuracy'  => $mlAcc,
        ];
    } catch (Exception $e) {}
}

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=yes">
<title>ETH/USDT · Grid Bot v16.0 · Tiempo Real</title>

<!-- Preload critical assets -->
<link rel="preload" href="/src/php/assets/css/design-system.css" as="style">
<link rel="preload" href="/src/php/assets/css/components.css" as="style">
<link rel="preload" href="/src/php/assets/css/layout.css" as="style">

<!-- Design System CSS -->
<link rel="stylesheet" href="/src/php/assets/css/design-system.css">
<link rel="stylesheet" href="/src/php/assets/css/components.css">
<link rel="stylesheet" href="/src/php/assets/css/layout.css">

<!-- Chart.js and Lightweight Charts (from CDN) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://unpkg.com/lightweight-charts@4.1.1/dist/lightweight-charts.standalone.production.js"></script>

<!-- PHP-injected constants for JS -->
<meta name="capital-cfg" content="<?= $CAPITAL ?>">
<meta name="ai-interval" content="<?= $AI_INT ?>">
<meta name="ctrl-token" content="<?= htmlspecialchars($CTRL_TOKEN) ?>">
<meta name="export-token" content="<?= htmlspecialchars(EXPORT_TOKEN) ?>">

<!-- Initial data for hydration -->
<script>
window.__INITIAL_DATA__ = <?= json_encode($init ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.__CONSTANTS__ = {
  CAPITAL_CFG: <?= $CAPITAL ?>,
  AI_INT: <?= $AI_INT ?>,
  CTRL_TOKEN: <?= json_encode($CTRL_TOKEN) ?>,
  EXPORT_TOKEN: <?= json_encode(EXPORT_TOKEN) ?>,
  IS_ADMIN: <?= $IS_ADMIN ? 'true' : 'false' ?>
};
</script>

<style>
/* Hide loader once JS takes over */
#ldr.hidden { opacity: 0; pointer-events: none; transition: opacity .6s; }

/* Skeleton loading */
.skel { background: linear-gradient(90deg,var(--bg3) 25%,var(--bg2) 50%,var(--bg3) 75%); background-size: 200% 100%; animation: skShimmer 1.5s infinite; border-radius: 6px; }
@keyframes skShimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

/* Stale state */
body.stale #app { opacity: .6; transition: opacity .8s; }

/* Hidden utility */
.hidden { display: none !important; }

/* Modal active state */
.modal-overlay.active { display: grid; }
</style>
</head>
<body>
<!-- Loader -->
<div id="ldr">
  <div class="ldr-logo">⚡</div>
  <div class="ldr-bar"><div class="ldr-prog"></div></div>
  <div class="ldr-txt">Grid Bot v16.0 · Iniciando</div>
</div>

<div class="app" id="app">
  <nav class="topbar">
    <button class="menu-btn" id="menuToggle" aria-label="Menú">☰</button>
    <div class="brand">
      <div class="brand-icon">⚡</div>
      <div>
        <div class="brand-name">ETH/USDT GRID</div>
        <div class="brand-sub">BYBIT · <?= $LEVERAGE ?>× · <?= $CAPITAL ?> USDT · v16.0</div>
      </div>
    </div>
    <div class="tb-sep"></div>
    <div class="ticker-block">
      <div id="priceLive" class="price-live">$0.00</div>
      <div>
        <div id="priceChg" class="price-chg ntr">+0.00%</div>
        <div id="priceHL" class="price-meta">H: — · L: — · Vol: —</div>
      </div>
      <div class="bid-ask">
        <span class="bid">Bid: <span id="bidPx">—</span></span>
        <span class="spread" id="spreadVal"></span>
        <span class="ask">Ask: <span id="askPx">—</span></span>
      </div>
      <div id="upnlChip" class="upnl-chip" style="display:none">
        <span>uPnL</span><span id="upnlChipVal">--</span>
      </div>
      <div style="font-size:9px;color:var(--muted);font-family:var(--mono)">
        <div>Funding: <span id="tbFunding">--%</span></div>
        <div>Mark: <span id="tbMark">$--</span></div>
      </div>
    </div>
    <div class="status-block">
      <div class="live-pill">
        <span id="liveIndicator" class="dot"></span>
        <span id="sysTxt">Conectando…</span>
        <span id="wsIndicator" style="margin-left:6px;width:6px;height:6px;border-radius:50%;background:var(--muted);display:inline-block"></span>
      </div>
      <span id="uptTxt" class="uptime">--</span>
      <span id="lastUpdate" class="last-upd">ahora</span>
      <span id="modeBadge" class="mode-badge m-NORMAL">NORMAL</span>
      <span id="mlBadge" class="ml-badge">ML --%</span>
      <button class="btn" id="rightToggle" style="display:none">📊</button>
    </div>
    <div class="btns">
      <button class="btn btn-b" data-action="toggle_speed" id="speedBtn">⚡ Rápido</button>
      <?php if ($IS_ADMIN): ?>
      <button class="btn btn-b" data-action="open_config">⚙️</button>
      <button class="btn btn-b" data-action="force_ai">🧠 IA</button>
      <button class="btn btn-g" data-action="reset_grid">↻ Grid</button>
      <button class="btn btn-b" data-action="export_pnl">📥</button>
      <button class="btn btn-r" data-action="stop">■ Stop</button>
      <?php endif; ?>
    </div>
  </nav>

  <div class="main-grid">
    <div class="hero-col" id="heroCol">
      <div class="kpi-grid">
        <div class="kpi pos" id="kpiPnlH">
          <div class="kpi-lbl">PnL Hoy</div>
          <div class="kpi-val c-pos" id="kPnlH"><span class="skel" style="display:inline-block;width:60px;height:14px"></span></div>
          <div class="kpi-sub" id="kPnlHP">0.00% capital</div>
        </div>
        <div class="kpi neu">
          <div class="kpi-lbl">PnL Total</div>
          <div class="kpi-val" id="kPnlT"><span class="skel" style="display:inline-block;width:60px;height:14px"></span></div>
          <div class="kpi-sub" id="kFillsT">-- fills</div>
        </div>
        <div class="kpi neu">
          <div class="kpi-lbl">Proyección 30d</div>
          <div class="kpi-val" id="kProj"><span class="skel" style="display:inline-block;width:60px;height:14px"></span></div>
          <div class="kpi-sub" id="kProjD">--</div>
        </div>
        <div class="kpi neu">
          <div class="kpi-lbl">Win Rate</div>
          <div class="kpi-val c-neu" id="kWin"><span class="skel" style="display:inline-block;width:40px;height:14px"></span></div>
          <div class="kpi-sub" id="kFillsH">-- fills hoy</div>
        </div>
        <div class="kpi yl">
          <div class="kpi-lbl">Uptime</div>
          <div class="kpi-val c-yl" id="kUpt"><span class="skel" style="display:inline-block;width:50px;height:14px"></span></div>
          <div class="kpi-sub" id="kOpenO">-- órd. abiertas</div>
        </div>
      </div>

      <div class="upnl-float" id="upnlBox" style="display:none">
        <div><div class="upnl-lbl">uPnL Posición</div><div class="upnl-val" id="upnlVal">--</div></div>
        <span>💰</span>
      </div>

      <div class="grid-status-bar">
        <span class="gs-dot" id="gridDot"></span>
        <span id="gridStatusTxt" style="color:var(--muted)">Grid --</span>
        <span style="color:var(--muted);margin-left:auto">Ciclo <span id="cycleN" style="color:var(--dim)">--</span></span>
      </div>

      <div class="card">
        <div class="card-hd"><b>Señal IA · ML v16.0</b><span id="aiEngBadge" style="font-family:var(--mono);font-size:8px">--</span></div>
        <div class="gauge-wrap">
          <div class="gauge-arc">
            <svg viewBox="0 0 160 88">
              <path class="g-bg-arc" d="M 16 80 A 64 64 0 0 1 144 80"/>
              <path class="g-fill-arc" id="gArc" d="M 16 80 A 64 64 0 0 1 144 80"/>
            </svg>
            <div class="gauge-center">
              <div class="gauge-pct" id="gLbl">--%</div>
              <div class="gauge-dir-lbl" id="gDir">--</div>
            </div>
          </div>
          <div class="gauge-ticks"><span>DOWN</span><span>SIDE</span><span>UP</span></div>
        </div>
        <div class="gauge-reason" id="gRsn">Evaluando…</div>
      </div>

      <div class="card">
        <div class="card-hd"><b>🎯 Estrategia & Estatus</b><span id="strategyMode" class="mode-badge m-NORMAL">NORMAL</span></div>
        <div class="cfg-grid">
          <span class="cfg-k">Estrategia</span><span class="cfg-v" id="strategyName">--</span>
          <span class="cfg-k">Dirección</span><span class="cfg-v" id="strategyDir">--</span>
          <span class="cfg-k">Confianza</span><span class="cfg-v" id="strategyConf">--</span>
          <span class="cfg-k">ML precisión</span><span class="cfg-v c-neu" id="strategyMl">--</span>
          <span class="cfg-k">Estado bot</span><span class="cfg-v" id="strategyBot">--</span>
          <span class="cfg-k">Grid</span><span class="cfg-v" id="strategyGrid">--</span>
          <span class="cfg-k">Ciclo</span><span class="cfg-v" id="strategyCycle">--</span>
          <span class="cfg-k">Última IA</span><span class="cfg-v" id="strategyAiTs">--</span>
        </div>
        <div class="strategy-reason" id="strategyReason" title="">--</div>
      </div>

      <div class="card">
        <div class="card-hd"><b>💰 Wallet</b></div>
        <div class="cfg-grid">
          <span class="cfg-k">Balance</span><span class="cfg-v" id="wBalance">--</span>
          <span class="cfg-k">Margen usado</span><span class="cfg-v" id="wMarginUsed">--</span>
          <span class="cfg-k">Margen disp.</span><span class="cfg-v c-neu" id="wMarginFree">--</span>
          <span class="cfg-k">uPnL</span><span id="wUpnl">--</span>
          <span class="cfg-k">ROI diario</span><span id="wRoiD">--</span>
          <span class="cfg-k">ROI total</span><span id="wRoiT">--</span>
          <span class="cfg-k">Proy. 30d</span><span class="cfg-v c-pos" id="wProj">--</span>
          <span class="cfg-k">Fees estim.</span><span class="cfg-v c-dim" id="wFees">--</span>
          <span class="cfg-k">Uptime</span><span class="cfg-v" id="wUpt">--</span>
        </div>
      </div>

      <div class="card">
        <div class="ai-bar-wrap">
          <div class="ai-hd"><span>⏳ Próxima eval. IA</span><span id="aiSec">--s</span></div>
          <div class="ai-track"><div class="ai-fill" id="aiBar"></div></div>
        </div>
      </div>
    </div>

    <div class="sidebar-left" id="sidebarLeft">
      <div class="card">
        <div class="card-hd"><b>Configuración Grid</b></div>
        <div class="cfg-grid">
          <span class="cfg-k">Par</span><span class="cfg-v">ETHUSDT</span>
          <span class="cfg-k">Capital</span><span class="cfg-v"><?= $CAPITAL ?> USDT</span>
          <span class="cfg-k">Leverage</span><span class="cfg-v"><?= $LEVERAGE ?>×</span>
          <span class="cfg-k">Niveles</span><span class="cfg-v" id="cNiv">--</span>
          <span class="cfg-k">L / S</span><span class="cfg-v" id="cLS">--</span>
          <span class="cfg-k">Spacing</span><span class="cfg-v" id="cSpc">--</span>
          <span class="cfg-k">Entradas</span><span class="cfg-v" id="cEnt">--</span>
          <span class="cfg-k">Salidas</span><span class="cfg-v" id="cSal">--</span>
          <span class="cfg-k">ML acc.</span><span class="cfg-v c-neu" id="cMlAcc">--%</span>
          <span class="cfg-k">Recovery</span><span class="cfg-v" id="stRecov2">No</span>
        </div>
      </div>
      <div class="card" style="flex:1">
        <div class="card-hd"><b>Confianza IA (histórico)</b></div>
        <div class="conf-chart-wrap"><canvas id="confChart"></canvas></div>
      </div>
    </div>

    <div class="drawer-overlay" id="drawerOverlay"></div>

    <div class="chart-sect card">
      <div class="chart-hd">
        <b>ETH/USDT · 5m · Bybit</b>
        <span id="mktRange" style="color:var(--dim);font-size:9px"></span>
      </div>
      <div class="chart-tabs">
        <button class="chart-tab active" id="chartTabPro" data-tab="pro">TradingView</button>
        <button class="chart-tab" id="chartTabFast" data-tab="fast">Rápido</button>
      </div>
      <div id="tvChartWrap" class="tv-wrap" style="display:block">
        <iframe id="tvFrame" title="TradingView ETHUSDT" loading="lazy" src="https://s.tradingview.com/widgetembed/?frameElementId=tv_ethusdt&symbol=BYBIT:ETHUSDT&interval=5&hidesidetoolbar=0&hideideas=1&theme=dark&style=1&timezone=Etc%2FUTC&studies=%5B%5D&show_popup_button=1&popup_width=1000&popup_height=650"></iframe>
      </div>
      <div id="candleChart" class="hidden" style="height:360px"></div>
      <div id="chartLegend" class="chart-legend" style="display:none">Sin órdenes pendientes</div>
    </div>

    <div class="card mkt-card">
      <div class="chart-hd" style="padding:6px 13px">
        <b>📊 Análisis de Mercado</b>
        <span id="mktUpdTs" style="font-size:8px;color:var(--muted)">--</span>
      </div>
      <div class="mkt-analysis">
        <div class="mkt-cell"><div class="mkt-lbl">RSI-14</div><div class="mkt-val" id="mRsi">--</div><div class="mkt-sub" id="mRsiLbl">Neutral</div><div class="rsi-track"><div class="rsi-zone-os"></div><div class="rsi-zone-ob"></div><div class="rsi-fill" id="mRsiBar" style="width:50%"></div><div class="rsi-dot" id="mRsiDot" style="left:50%"></div></div></div>
        <div class="mkt-cell"><div class="mkt-lbl">MACD Hist</div><div class="mkt-val" id="mMacd">--</div><div class="mkt-sub" id="mMacdLbl">Señal: --</div><div class="macd-hist-bar" id="mMacdBar" style="width:60%;background:var(--accent)"></div></div>
        <div class="mkt-cell"><div class="mkt-lbl">ADX-14</div><div class="mkt-val" id="mAdx">--</div><div class="mkt-sub" id="mAdxLbl">Tendencia</div><div class="rsi-track"><div class="rsi-fill" id="mAdxBar" style="width:0%;background:var(--purple)"></div></div></div>
        <div class="mkt-cell"><div class="mkt-lbl">ATR% / Vol</div><div class="mkt-val" id="mAtr">--</div><div class="mkt-sub" id="mVolR">Vol ratio: --</div></div>
        <div class="mkt-cell"><div class="mkt-lbl">Funding Rate</div><div class="mkt-val" id="mFunding">--</div><div class="mkt-sub" id="mFundNext">Próximo: --</div></div>
        <div class="mkt-cell"><div class="mkt-lbl">Open Interest</div><div class="mkt-val" id="mOi">--</div><div class="mkt-sub" id="mOiVal">Valor: --</div></div>
        <div class="mkt-cell"><div class="mkt-lbl">Bollinger %B</div><div class="mkt-val" id="mBb">--</div><div class="mkt-sub" id="mBbRange">--</div></div>
        <div class="mkt-cell"><div class="mkt-lbl">EMA 9/21/50</div><div style="font-family:var(--mono);font-size:10px;margin-top:3px;line-height:1.8"><span style="color:var(--cyan)">E9: <span id="mE9">--</span></span><br><span style="color:var(--accent)">E21: <span id="mE21">--</span></span><br><span style="color:var(--purple)">E50: <span id="mE50">--</span></span></div></div>
      </div>
    </div>

    <div class="pnl-charts pnl-charts-wrap card">
      <div class="pnl-chart-block"><div class="pnl-chart-hd"><span>PnL Horario 48h</span><span id="hTot" style="font-family:var(--mono);font-size:9px"></span></div><div class="pnl-chart-wrap"><canvas id="hChart"></canvas></div></div>
      <div class="pnl-chart-block"><div class="pnl-chart-hd"><span>PnL Diario 14d</span><span id="dTot" style="font-family:var(--mono);font-size:9px"></span></div><div class="pnl-chart-wrap"><canvas id="dChart"></canvas></div></div>
    </div>

    <div class="card pnl-cum-block"><div class="pnl-cum-hd"><span>PnL Acumulado</span><span id="cumTot" style="font-family:var(--mono);font-size:9px"></span></div><div class="pnl-cum-wrap"><canvas id="cumChart"></canvas></div></div>

    <div class="card ladder-card">
      <div class="chart-hd"><b>Order Ladder</b><span id="ladderPx" style="font-family:var(--mono);font-size:10px;color:var(--accent)">$0.00</span></div>
      <div class="ladder-wrap" id="ladderWrap"><div class="empty-ladder">Sin órdenes activas</div></div>
    </div>

    <div class="sidebar-right" id="sidebarRight">
      <div class="tabs-hd">
        <button class="tab-btn active" data-tab="stats">Stats</button>
        <button class="tab-btn" data-tab="positions">Posic.</button>
        <button class="tab-btn" data-tab="fills">Fills</button>
        <button class="tab-btn" data-tab="ml">ML</button>
        <button class="tab-btn" data-tab="log">Log</button>
      </div>
      <div class="tab-panels">
        <div class="tab-panel active" id="tab-stats">
          <div class="stat-section"><div class="stat-title">Sesión</div>
          <div class="stat-grid">
            <div class="stat-cell"><div class="stat-lbl">Órd. abiertas</div><div class="stat-val c-neu" id="stOpen">--</div></div>
            <div class="stat-cell"><div class="stat-lbl">Fills total</div><div class="stat-val" id="stFills">--</div></div>
            <div class="stat-cell"><div class="stat-lbl">Fills hoy</div><div class="stat-val" id="stFillsH">--</div></div>
            <div class="stat-cell"><div class="stat-lbl">Peak PnL</div><div class="stat-val c-pos" id="stPeak">--</div></div>
            <div class="stat-cell"><div class="stat-lbl">Recovery</div><div class="stat-val" id="stRecov">No</div></div>
            <div class="stat-cell"><div class="stat-lbl">Win Rate</div><div class="stat-val c-neu" id="stWr">--%</div></div>
            <div class="stat-cell"><div class="stat-lbl">Fills/hora</div><div class="stat-val" id="stFillH">--</div></div>
            <div class="stat-cell"><div class="stat-lbl">PnL 1h</div><div class="stat-val" id="stPnl1h">--</div></div>
          </div></div>
          <div class="stat-section"><div class="stat-title">Mercado</div>
          <div class="stat-grid">
            <div class="stat-cell"><div class="stat-lbl">Precio</div><div class="stat-val c-neu" id="stPx">--</div></div>
            <div class="stat-cell"><div class="stat-lbl">Cambio 24h</div><div class="stat-val" id="stChg">--%</div></div>
            <div class="stat-cell"><div class="stat-lbl">High 24h</div><div class="stat-val c-pos" id="stH">--</div></div>
            <div class="stat-cell"><div class="stat-lbl">Low 24h</div><div class="stat-val c-neg" id="stL">--</div></div>
            <div class="stat-cell"><div class="stat-lbl">Vol 24h</div><div class="stat-val" id="stVol">--</div></div>
            <div class="stat-cell"><div class="stat-lbl">Spread</div><div class="stat-val c-yl" id="stSpr">--</div></div>
            <div class="stat-cell"><div class="stat-lbl">RSI-14</div><div class="stat-val" id="stRsi">--</div></div>
            <div class="stat-cell"><div class="stat-lbl">MACD Hist</div><div class="stat-val" id="stMacd">--</div></div>
            <div class="stat-cell"><div class="stat-lbl">Funding Rate</div><div class="stat-val" id="stFund">--</div></div>
            <div class="stat-cell"><div class="stat-lbl">Open Interest</div><div class="stat-val" id="stOi">--</div></div>
            <div class="stat-cell"><div class="stat-lbl">Mark Price</div><div class="stat-val c-neu" id="stMark">--</div></div>
            <div class="stat-cell"><div class="stat-lbl">ADX</div><div class="stat-val" id="stAdx">--</div></div>
          </div></div>
        </div>
        <div class="tab-panel" id="tab-positions">
          <div class="pos-table-wrap">
            <div class="tbl-wrap"><table><thead><tr><th>Lado</th><th>Qty</th><th class="hide-mobile">Entry $</th><th>uPnL</th><th class="hide-mobile">Liq $</th></tr></thead><tbody id="posBody"><tr><td colspan="5" class="no-data">Sin posición abierta</td></tr></tbody></table></div>
          </div>
        </div>
        <div class="tab-panel" id="tab-fills">
          <div class="fills-hd"><span>Últimos Fills</span><span class="fills-cnt" id="fillCnt">0</span></div>
          <div class="tbl-wrap"><table><thead><tr><th>Hora</th><th>Lado</th><th class="hide-mobile">Rol</th><th class="tr">PnL</th><th class="hide-mobile">Price</th><th class="hide-mobile">R</th></tr></thead><tbody id="fillBody"><tr><td colspan="6" class="no-data">Sin historial</td></tr></tbody></table></div>
          <div class="fills-pg">
            <button class="btn" id="fillPrev" onclick="fillsPrev()">◀</button>
            <span id="fillsPage" style="font-family:var(--mono);font-size:9px;color:var(--muted)">1/1</span>
            <button class="btn" id="fillNext" onclick="fillsNext()">▶</button>
            <button class="btn btn-b" id="loadFillsBtn" onclick="loadFillsHistory()" style="margin-left:auto">🔄 Historial</button>
          </div>
        </div>
        <div class="tab-panel" id="tab-ml">
          <div class="stat-section">
            <div class="stat-title">Modelo ML · Regresión Logística</div>
            <div class="stat-grid">
              <div class="stat-cell"><div class="stat-lbl">Precisión (RF OOS)</div><div class="stat-val c-neu" id="mlAccStat">--%</div></div>
              <div class="stat-cell"><div class="stat-lbl">Features</div><div class="stat-val" id="mlFeatCount">--</div></div>
              <div class="stat-cell"><div class="stat-lbl">Símbolo</div><div class="stat-val">ETHUSDT</div></div>
              <div class="stat-cell"><div class="stat-lbl">Actualizado</div><div class="stat-val" id="mlUpdated" style="font-size:9px">--</div></div>
            </div>
            <div class="stat-title" style="margin-top:8px">Importancia de Features</div>
          </div>
          <div id="mlFeatBars" style="padding:0 12px 12px"><div style="color:var(--muted);font-size:9px;text-align:center;padding:10px">Cargando...</div></div>
        </div>
        <div class="tab-panel" id="tab-log">
          <div class="log-container">
            <div class="log-toolbar">
              <input type="text" class="log-search" id="logSearch" placeholder="Filtrar…" oninput="filterLog()">
              <button class="btn" onclick="clearLog()" style="font-size:9px;padding:3px 7px">Limpiar</button>
              <button class="btn" onclick="toggleLogPause()" title="Pausar scroll" style="font-size:9px;padding:3px 7px">⏸</button>
            </div>
            <div class="log-box" id="logBox"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Config Modal -->
<div class="modal-overlay" id="configModalOverlay">
  <div class="modal" id="configModal">
    <div class="modal-hd">⚙️ Configuración Grid</div>
    <div class="modal-bd">
      <div class="cfg-field">
        <label>Capital (USDT)</label>
        <input type="number" id="cfgCapital" step="1" min="1" class="cfg-input">
      </div>
      <div class="cfg-field">
        <label>Leverage</label>
        <input type="number" id="cfgLeverage" step="1" min="1" max="125" class="cfg-input">
      </div>
      <div class="cfg-row">
        <div class="cfg-field">
          <label>Niveles totales</label>
          <input type="number" id="cfgLevels" step="1" min="2" max="50" class="cfg-input">
        </div>
        <div class="cfg-field">
          <label>Spacing (% ×100)</label>
          <input type="number" id="cfgSpacing" step="0.01" min="0.01" max="0.5" class="cfg-input">
        </div>
      </div>
      <div class="cfg-row">
        <div class="cfg-field">
          <label>Long Levels</label>
          <input type="number" id="cfgLongLevels" step="1" min="1" max="25" class="cfg-input">
        </div>
        <div class="cfg-field">
          <label>Short Levels</label>
          <input type="number" id="cfgShortLevels" step="1" min="1" max="25" class="cfg-input">
        </div>
      </div>
    </div>
    <div class="modal-ft">
      <button class="btn" onclick="closeConfig()">Cancelar</button>
      <button class="btn btn-b" data-action="apply_config">Aplicar</button>
    </div>
  </div>
</div>

<div id="toasts"></div>

<!-- Vite compiled entry point -->
<script type="module" src="/src/php/dist/assets/main.js"></script>
</body>
</html>