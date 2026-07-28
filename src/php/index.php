<?php
declare(strict_types=1);

error_reporting(0); ini_set('display_errors', '0');

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use BinanceBot\Core\Config;
use BinanceBot\Core\Database;
use BinanceBot\Core\Logger;

$cfg = Config::getInstance()->all();
$mc  = $cfg['mysql'] ?? [];
define('EXPORT_TOKEN', getenv('SECURITY_TOKEN') ?: 'g273f123');
$AI_INT   = (int)($cfg['bot']['ai_interval_sec'] ?? 120);
$CAPITAL  = (int)($cfg['bot']['capital_usd'] ?? 100);
$LEVERAGE = (int)($cfg['bot']['leverage'] ?? 5);

// CSV export
if (isset($_GET['export_pnl'])) {
    if (!isset($_GET['token']) || $_GET['token'] !== EXPORT_TOKEN) { http_response_code(403); exit("Acceso denegado"); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pnl_diario_ethusdt_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    if (!empty($mc['host'])) {
        try {
            $pdo = new PDO("mysql:host={$mc['host']};dbname={$mc['dbname']};charset=utf8mb4", $mc['user'], $mc['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $pdo->exec("SET time_zone = '+00:00'");
            $rows = $pdo->query("SELECT DATE(filled_at) AS fecha, COUNT(*) AS ops,
                SUM(CASE WHEN pnl_usd>0 THEN 1 ELSE 0 END) AS gan,
                SUM(CASE WHEN pnl_usd<0 THEN 1 ELSE 0 END) AS perd,
                ROUND(SUM(pnl_usd),6) AS pnl_dia, ROUND(AVG(pnl_usd),6) AS prom,
                ROUND(MAX(pnl_usd),6) AS max_pnl, ROUND(MIN(pnl_usd),6) AS min_pnl
                FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' AND filled_at IS NOT NULL
                GROUP BY DATE(filled_at) ORDER BY fecha ASC")->fetchAll();
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, ['Fecha','Ops','Ganadas','Perdidas','Win%','PnL Día','Promedio','Máximo','Mínimo','Acumulado'], "\t");
            $acum = 0.0;
            foreach ($rows as $r) {
                $p = (float)$r['pnl_dia']; $acum += $p;
                $wr = $r['ops'] > 0 ? round($r['gan'] / $r['ops'] * 100, 1) : 0.0;
                $fmt = function($n) { return str_replace('.', ',', (string)$n); };
                fputcsv($out, [$r['fecha'], (int)$r['ops'], (int)$r['gan'], (int)$r['perd'],
                    $fmt($wr).'%', $fmt(round($p,6)), $fmt(round((float)$r['prom'],6)),
                    $fmt(round((float)$r['max_pnl'],6)), $fmt(round((float)$r['min_pnl'],6)), $fmt(round($acum,6))], "\t");
            }
            fclose($out);
        } catch (Exception $e) { echo "Error DB: " . $e->getMessage(); }
    }
    exit;
}

// Load initial data
$init = [];
try {
    $db = Database::getInstance();
    if ($db->isConnected()) {
        $pdo = $db->getPdo();
        $row  = $pdo->query("SELECT * FROM grid_configs WHERE symbol='ETHUSDT' ORDER BY id DESC LIMIT 1")->fetch() ?: [];
        $pnlT = $pdo->query("SELECT COALESCE(SUM(pnl_usd),0) p FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE()")->fetch();
        $fills= $pdo->query("SELECT COUNT(*) c FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED'")->fetch();
        $openO= $pdo->query("SELECT COUNT(*) c FROM grid_orders WHERE symbol='ETHUSDT' AND status='OPEN'")->fetch();
        $wr   = $pdo->query("SELECT COUNT(*) t, SUM(CASE WHEN pnl_usd>0 THEN 1 ELSE 0 END) w FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED'")->fetch();
        $init['pnl_today']       = (float)($pnlT['p'] ?? 0);
        $init['fills_total']     = (int)($fills['c'] ?? 0);
        $init['open_orders']     = (int)($openO['c'] ?? 0);
        $init['win_rate']        = ($wr && (int)$wr['t'] > 0) ? round((int)$wr['w'] / (int)$wr['t'] * 100, 1) : 0;
        $init['direction']       = $row['direction'] ?? $cfg['bot']['direction'] ?? 'SIDEWAYS';
        $init['confidence']      = (int)($row['confidence'] ?? $cfg['ml']['min_confidence'] ?? 50);
        $init['ai_reason']       = $row['ai_reason'] ?? 'Grid initialized';
        $init['levels']          = (int)($row['levels'] ?? $cfg['grid']['levels'] ?? 16);
        $init['long_levels']     = (int)($row['long_levels'] ?? $cfg['grid']['long_levels'] ?? 8);
        $init['short_levels']    = (int)($row['short_levels'] ?? $cfg['grid']['short_levels'] ?? 8);
        $init['spacing_pct']     = (float)($row['spacing_pct'] ?? ($cfg['grid']['base_spacing'] ?? 0.0003) * 100);
        $init['recovery_active'] = (bool)($row['recovery_active'] ?? false);
        $init['capital']         = $CAPITAL;
        $init['leverage']        = $LEVERAGE;
        $init['ml_accuracy']     = (float)($cfg['ml']['min_accuracy'] ?? 0.85);
        $init['maker_fee']       = (float)($cfg['fees']['maker'] ?? 0.0001);
        $init['taker_fee']       = (float)($cfg['fees']['taker'] ?? 0.0006);
    }
} catch (\Throwable $e) {
    Logger::warn("[Dashboard] DB init: " . $e->getMessage());
}
$init['ws_token'] = EXPORT_TOKEN;
$init['ai_interval'] = $AI_INT;

// Read Vite manifest for hashed assets
$manifestPath = __DIR__ . '/dist/.vite/manifest.json';
$jsFile  = 'assets/js/main.js';
$cssFile = 'assets/js/main.css';
if (file_exists($manifestPath)) {
    $manifest = json_decode(file_get_contents($manifestPath), true);
    if (isset($manifest['assets/js/main.js'])) {
        $jsFile  = $manifest['assets/js/main.js']['file'];
        $cssFile = $manifest['assets/js/main.js']['css'][0] ?? $cssFile;
    }
}

?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=yes">
<title>ETH/USDT · Grid Bot · Tiempo Real</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://unpkg.com/lightweight-charts@4.1.1/dist/lightweight-charts.standalone.production.js"></script>
<link rel="stylesheet" href="dist/<?= htmlspecialchars($cssFile) ?>">
</head>
<body>
<div id="ldr" style="position:fixed;inset:0;z-index:9999;background:var(--bg);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:20px;transition:opacity .6s">
  <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--accent),#9b72f5);display:grid;place-items:center;font-size:24px;box-shadow:0 0 30px rgba(45,140,255,.4);animation:ldrPulse 1.5s ease-in-out infinite">⚡</div>
  <div style="width:180px;height:2px;background:var(--border);border-radius:2px;overflow:hidden"><div style="height:100%;background:linear-gradient(90deg,var(--accent),#9b72f5);border-radius:2px;animation:ldrSlide 1.5s ease-in-out infinite"></div></div>
  <div style="font-family:var(--font-mono);font-size:10px;color:var(--text-muted);letter-spacing:4px;text-transform:uppercase">Grid Bot · Iniciando</div>
</div>
<style>
@keyframes ldrPulse{0%,100%{box-shadow:0 0 20px rgba(45,140,255,.3)}50%{box-shadow:0 0 40px rgba(45,140,255,.6)}}
@keyframes ldrSlide{0%{width:0;margin-left:0}50%{width:60%;margin-left:20%}100%{width:0;margin-left:100%}}
</style>

<div id="app" style="display:none;flex-direction:column;height:100vh">
  <nav class="navbar">
    <button id="menuToggle" style="background:none;border:none;color:var(--text-muted);font-size:20px;cursor:pointer;padding:6px;display:flex;align-items:center">☰</button>
    <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
      <div style="width:28px;height:28px;border-radius:7px;background:linear-gradient(135deg,var(--accent),#9b72f5);display:grid;place-items:center;font-size:14px">⚡</div>
      <div><div style="font-size:12px;font-weight:700;color:#fff">ETH/USDT GRID</div><div style="font-family:var(--font-mono);font-size:8px;color:var(--text-muted)">BYBIT · <?= $LEVERAGE ?>× · <?= $CAPITAL ?> USDT</div></div>
    </div>
    <div style="width:1px;height:28px;background:var(--border);margin:0 4px;flex-shrink:0"></div>
    <div style="display:flex;align-items:center;gap:10px;flex:1;flex-wrap:wrap">
      <div style="font-family:var(--font-mono);font-size:18px;font-weight:600;color:#fff" id="priceLive">$0.00</div>
      <div><div id="priceChg" style="font-family:var(--font-mono);font-size:10px;padding:2px 7px;border-radius:4px;font-weight:600;background:var(--accent);color:#fff">+0.00%</div><div id="priceHL" style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);line-height:1.8">H: — · L: — · Vol: —</div></div>
      <div id="bidAskBox" style="display:flex;gap:6px;align-items:center;font-family:var(--font-mono);font-size:10px"><span style="color:var(--green)">Bid: <span id="bidPx">—</span></span><span id="spreadVal" style="color:var(--text-muted)"></span><span style="color:var(--red)">Ask: <span id="askPx">—</span></span></div>
      <div id="upnlChip" style="display:none;align-items:center;gap:5px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:6px;padding:3px 9px;font-family:var(--font-mono);font-size:11px;font-weight:600"><span>uPnL</span><span id="upnlChipVal">--</span></div>
      <div style="font-size:9px;color:var(--text-muted);font-family:var(--font-mono)"><div>Funding: <span id="tbFunding">--%</span></div><div>Mark: <span id="tbMark">$--</span></div></div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
      <div style="display:flex;align-items:center;gap:6px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600">
        <span id="liveIndicator" style="width:7px;height:7px;border-radius:50%;background:var(--text-muted);flex-shrink:0"></span>
        <span id="sysTxt">Conectando…</span>
        <span id="wsIndicator" style="margin-left:6px;width:6px;height:6px;border-radius:50%;background:var(--text-muted);display:inline-block"></span>
      </div>
      <span id="uptTxt" style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted)">--</span>
      <span id="lastUpdate" style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);background:var(--bg-elevated);padding:2px 7px;border-radius:10px;border:1px solid var(--border)">ahora</span>
      <span id="modeBadge" style="font-size:8px;font-weight:700;padding:2px 7px;border-radius:4px;text-transform:uppercase;letter-spacing:.6px;background:rgba(14,165,233,0.15);color:var(--accent)">NORMAL</span>
      <span id="mlBadge" style="font-size:8px;font-weight:700;padding:2px 7px;border-radius:4px;font-family:var(--font-mono);background:rgba(14,165,233,0.15);color:var(--accent);border:1px solid rgba(45,140,255,.2)">ML --%</span>
    </div>
    <div style="display:flex;gap:5px;flex-shrink:0">
      <button class="btn btn-primary" onclick="toggleSpeed()" id="speedBtn" style="font-size:9px;padding:4px 9px">⚡ Rápido</button>
      <button class="btn btn-primary" onclick="openConfig()" style="font-size:9px;padding:4px 9px">⚙️</button>
      <button class="btn btn-primary" onclick="cmd('force_ai')" style="font-size:9px;padding:4px 9px">🧠 IA</button>
      <button class="btn btn-primary" onclick="cmd('reset_grid')" style="font-size:9px;padding:4px 9px">↻ Grid</button>
      <button class="btn btn-primary" onclick="exportPnl()" style="font-size:9px;padding:4px 9px">📥</button>
      <button class="btn btn-primary" onclick="cmd('stop')" style="font-size:9px;padding:4px 9px">■ Stop</button>
    </div>
  </nav>

  <div style="display:flex;flex:1;overflow:hidden">
    <div id="sidebarLeft" style="position:fixed;top:50px;left:-280px;width:280px;height:calc(100% - 50px);background:var(--bg-elevated);border-right:1px solid var(--border);transition:left .3s ease;z-index:150;overflow-y:auto">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;padding:10px 12px">
        <div id="kpiPnlH" style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:9px 10px">
          <div style="font-size:8px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:5px">PnL Hoy</div>
          <div style="font-family:var(--font-mono);font-size:17px;font-weight:600;line-height:1;color:var(--green)" id="kPnlH">--</div>
          <div style="font-size:8px;color:var(--text-muted);margin-top:4px" id="kPnlHP">0.00% capital</div>
        </div>
        <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:9px 10px">
          <div style="font-size:8px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:5px">PnL Total</div>
          <div style="font-family:var(--font-mono);font-size:17px;font-weight:600;line-height:1" id="kPnlT">--</div>
          <div style="font-size:8px;color:var(--text-muted);margin-top:4px" id="kFillsT">-- fills</div>
        </div>
        <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:9px 10px">
          <div style="font-size:8px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:5px">Win Rate</div>
          <div style="font-family:var(--font-mono);font-size:17px;font-weight:600;line-height:1;color:var(--accent)" id="kWin">--%</div>
          <div style="font-size:8px;color:var(--text-muted);margin-top:4px" id="kFillsH">-- fills hoy</div>
        </div>
        <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:9px 10px">
          <div style="font-size:8px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:5px">Uptime</div>
          <div style="font-family:var(--font-mono);font-size:17px;font-weight:600;line-height:1;color:var(--yellow)" id="kUpt">--</div>
          <div style="font-size:8px;color:var(--text-muted);margin-top:4px" id="kOpenO">-- órd. abiertas</div>
        </div>
      </div>
      <div id="upnlBox" style="margin:0 12px 8px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 10px;display:none;align-items:center;justify-content:space-between">
        <div><div style="font-size:8px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.6px">uPnL Posición</div><div style="font-family:var(--font-mono);font-size:14px;font-weight:600" id="upnlVal">--</div></div>
        <span>💰</span>
      </div>
      <div style="display:flex;align-items:center;gap:5px;padding:3px 12px 6px;font-size:8px;font-family:var(--font-mono)">
        <span id="gridDot" style="width:6px;height:6px;border-radius:50%;background:var(--text-muted);flex-shrink:0"></span>
        <span id="gridStatusTxt" style="color:var(--text-muted)">Grid --</span>
        <span style="color:var(--text-muted);margin-left:auto">Ciclo <span id="cycleN" style="color:var(--text-secondary)">--</span></span>
      </div>
      <div class="card" style="background:var(--bg-elevated);border-bottom:1px solid var(--border)">
        <div class="card-header"><span class="card-title">Señal IA · ML</span><span id="aiEngBadge" style="font-family:var(--font-mono);font-size:8px">--</span></div>
        <div style="text-align:center;padding:6px 12px 2px">
          <div style="position:relative;width:120px;height:66px;margin:0 auto">
            <svg viewBox="0 0 140 76" style="width:100%;height:100%">
              <path d="M 14 72 A 56 56 0 0 1 126 72" fill="none" stroke="var(--border)" stroke-width="7" stroke-linecap="round"/>
              <path id="gArc" d="M 14 72 A 56 56 0 0 1 126 72" fill="none" stroke="var(--accent)" stroke-width="7" stroke-linecap="round" style="transition:stroke-dashoffset .5s"/>
            </svg>
            <div style="position:absolute;bottom:2px;left:50%;transform:translateX(-50%);text-align:center">
              <div style="font-family:var(--font-mono);font-size:15px;font-weight:600;line-height:1" id="gLbl">--%</div>
              <div style="font-size:11px;font-weight:700;margin-top:2px;letter-spacing:.3px" id="gDir">--</div>
            </div>
          </div>
          <div style="display:flex;justify-content:space-between;padding:0 14px;font-family:var(--font-mono);font-size:8px;color:var(--text-muted)"><span>DOWN</span><span>SIDE</span><span>UP</span></div>
        </div>
        <div style="font-size:9px;color:var(--text-muted);text-align:center;padding:0 12px 10px;line-height:1.5;word-break:break-word" id="gRsn">Evaluando…</div>
      </div>
      <div class="card" style="background:var(--bg-elevated);border-bottom:1px solid var(--border)">
        <div class="card-header"><span class="card-title">💰 Wallet</span></div>
        <div style="display:grid;grid-template-columns:auto 1fr;gap:1px 0;padding:0 12px 10px">
          <span style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)">Balance</span><span style="font-family:var(--font-mono);font-size:9px;color:var(--text-primary);font-weight:600;text-align:right;padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)" id="wBalance">--</span>
          <span style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)">Margen usado</span><span style="font-family:var(--font-mono);font-size:9px;color:var(--text-primary);font-weight:600;text-align:right;padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)" id="wMarginUsed">--</span>
          <span style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)">Margen disp.</span><span style="font-family:var(--font-mono);font-size:9px;color:var(--accent);font-weight:600;text-align:right;padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)" id="wMarginFree">--</span>
          <span style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)">uPnL</span><span style="font-family:var(--font-mono);font-size:9px;font-weight:600;text-align:right;padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)" id="wUpnl">--</span>
          <span style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)">ROI diario</span><span style="font-family:var(--font-mono);font-size:9px;font-weight:600;text-align:right;padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)" id="wRoiD">--</span>
          <span style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)">ROI total</span><span style="font-family:var(--font-mono);font-size:9px;font-weight:600;text-align:right;padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)" id="wRoiT">--</span>
          <span style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)">Proy. 30d</span><span style="font-family:var(--font-mono);font-size:9px;color:var(--green);font-weight:600;text-align:right;padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)" id="wProj">--</span>
          <span style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);padding:3px 0">Fees estim.</span><span style="font-family:var(--font-mono);font-size:9px;color:var(--text-secondary);font-weight:600;text-align:right;padding:3px 0" id="wFees">--</span>
        </div>
      </div>
      <div class="card" style="background:var(--bg-elevated);border-bottom:1px solid var(--border)">
        <div style="padding:0 12px 10px">
          <div style="display:flex;justify-content:space-between;font-size:9px;color:var(--text-muted);margin-bottom:5px"><span>⏳ Próxima eval. IA</span><span id="aiSec">--s</span></div>
          <div style="height:3px;background:var(--border);border-radius:3px;overflow:hidden"><div id="aiBar" style="height:100%;background:var(--accent);border-radius:3px;width:0%;transition:width 1s linear,background .3s"></div></div>
        </div>
      </div>
      <div class="card" style="background:var(--bg-elevated);border-bottom:1px solid var(--border)">
        <div class="card-header"><span class="card-title">Configuración Grid</span></div>
        <div style="display:grid;grid-template-columns:auto 1fr;gap:1px 0;padding:0 12px 10px">
          <span style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)">Par</span><span style="font-family:var(--font-mono);font-size:9px;color:var(--text-primary);font-weight:600;text-align:right;padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)">ETHUSDT</span>
          <span style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)">Capital</span><span style="font-family:var(--font-mono);font-size:9px;color:var(--text-primary);font-weight:600;text-align:right;padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)"><?= $CAPITAL ?> USDT</span>
          <span style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)">Leverage</span><span style="font-family:var(--font-mono);font-size:9px;color:var(--text-primary);font-weight:600;text-align:right;padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)"><?= $LEVERAGE ?>×</span>
          <span style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)">Niveles</span><span style="font-family:var(--font-mono);font-size:9px;color:var(--text-primary);font-weight:600;text-align:right;padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)" id="cNiv">--</span>
          <span style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)">L / S</span><span style="font-family:var(--font-mono);font-size:9px;color:var(--text-primary);font-weight:600;text-align:right;padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)" id="cLS">--</span>
          <span style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)">Spacing</span><span style="font-family:var(--font-mono);font-size:9px;color:var(--text-primary);font-weight:600;text-align:right;padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)" id="cSpc">--</span>
          <span style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)">Entradas</span><span style="font-family:var(--font-mono);font-size:9px;color:var(--text-primary);font-weight:600;text-align:right;padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)" id="cEnt">--</span>
          <span style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)">Salidas</span><span style="font-family:var(--font-mono);font-size:9px;color:var(--text-primary);font-weight:600;text-align:right;padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)" id="cSal">--</span>
          <span style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)">ML acc.</span><span style="font-family:var(--font-mono);font-size:9px;color:var(--accent);font-weight:600;text-align:right;padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)" id="cMlAcc">--%</span>
          <span style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted);padding:3px 0">Recovery</span><span style="font-family:var(--font-mono);font-size:9px;color:var(--text-primary);font-weight:600;text-align:right;padding:3px 0" id="stRecov2">No</span>
        </div>
      </div>
      <div class="card" style="background:var(--bg-elevated);flex:1">
        <div class="card-header"><span class="card-title">Confianza IA (histórico)</span></div>
        <div style="height:80px;padding:4px 12px 8px"><canvas id="confChart"></canvas></div>
      </div>
    </div>
    <div id="drawerOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:140;display:none"></div>

    <div id="centerCol" style="flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:1px;background:var(--bg)">
      <div style="background:var(--bg-elevated);border-bottom:1px solid var(--border)">
        <div class="card-header"><span class="card-title">ETH/USDT · Bybit</span><span id="mktRange" style="color:var(--text-secondary);font-size:9px"></span></div>
        <div id="candleChart" style="height:200px"></div>
      </div>
      <div style="background:var(--bg-elevated)">
        <div class="card-header"><span class="card-title">📊 Análisis de Mercado</span><span id="mktUpdTs" style="font-size:8px;color:var(--text-muted)">--</span></div>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;padding:8px 12px">
          <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 10px">
            <div style="font-size:8px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px">RSI-14</div>
            <div style="font-family:var(--font-mono);font-size:13px;font-weight:600;line-height:1" id="mRsi">--</div>
            <div style="font-size:8px;color:var(--text-muted);margin-top:3px" id="mRsiLbl">Neutral</div>
            <div style="height:5px;background:var(--border);border-radius:3px;margin-top:5px;position:relative;overflow:hidden">
              <div style="position:absolute;right:0;top:0;height:100%;width:30%;background:rgba(240,60,82,.15)"></div>
              <div style="position:absolute;left:0;top:0;height:100%;width:30%;background:rgba(0,201,122,.15)"></div>
              <div id="mRsiBar" style="position:absolute;top:0;height:100%;background:var(--accent);border-radius:3px;transition:width .5s"></div>
              <div id="mRsiDot" style="position:absolute;top:50%;transform:translateY(-50%);width:7px;height:7px;border-radius:50%;background:#fff;margin-left:-3px;transition:left .5s;box-shadow:0 0 4px rgba(255,255,255,.6)"></div>
            </div>
          </div>
          <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 10px">
            <div style="font-size:8px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px">MACD Hist</div>
            <div style="font-family:var(--font-mono);font-size:13px;font-weight:600;line-height:1" id="mMacd">--</div>
            <div style="font-size:8px;color:var(--text-muted);margin-top:3px" id="mMacdLbl">Señal: --</div>
            <div id="mMacdBar" style="height:4px;border-radius:2px;margin-top:5px;transition:all .4s;background:var(--accent)"></div>
          </div>
          <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 10px">
            <div style="font-size:8px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px">ADX-14</div>
            <div style="font-family:var(--font-mono);font-size:13px;font-weight:600;line-height:1" id="mAdx">--</div>
            <div style="font-size:8px;color:var(--text-muted);margin-top:3px" id="mAdxLbl">Tendencia</div>
            <div style="height:5px;background:var(--border);border-radius:3px;margin-top:5px;position:relative;overflow:hidden"><div id="mAdxBar" style="position:absolute;top:0;height:100%;background:#9b72f5;border-radius:3px;transition:width .5s"></div></div>
          </div>
          <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 10px">
            <div style="font-size:8px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px">ATR% / Vol</div>
            <div style="font-family:var(--font-mono);font-size:13px;font-weight:600;line-height:1" id="mAtr">--</div>
            <div style="font-size:8px;color:var(--text-muted);margin-top:3px" id="mVolR">Vol ratio: --</div>
          </div>
          <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 10px">
            <div style="font-size:8px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px">Funding Rate</div>
            <div style="font-family:var(--font-mono);font-size:13px;font-weight:600;line-height:1" id="mFunding">--</div>
            <div style="font-size:8px;color:var(--text-muted);margin-top:3px" id="mFundNext">Próximo: --</div>
          </div>
          <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 10px">
            <div style="font-size:8px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px">Open Interest</div>
            <div style="font-family:var(--font-mono);font-size:13px;font-weight:600;line-height:1" id="mOi">--</div>
            <div style="font-size:8px;color:var(--text-muted);margin-top:3px" id="mOiVal">Valor: --</div>
          </div>
          <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 10px">
            <div style="font-size:8px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px">Bollinger %B</div>
            <div style="font-family:var(--font-mono);font-size:13px;font-weight:600;line-height:1" id="mBb">--</div>
            <div style="font-size:8px;color:var(--text-muted);margin-top:3px" id="mBbRange">--</div>
          </div>
          <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 10px">
            <div style="font-size:8px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px">EMA 9/21/50</div>
            <div style="font-family:var(--font-mono);font-size:10px;margin-top:3px;line-height:1.8">
              <span style="color:var(--cyan)">E9: <span id="mE9">--</span></span><br>
              <span style="color:var(--accent)">E21: <span id="mE21">--</span></span><br>
              <span style="color:#9b72f5">E50: <span id="mE50">--</span></span>
            </div>
          </div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--border)">
        <div style="background:var(--bg-elevated)"><div class="card-header"><span class="card-title">PnL Horario 48h</span><span id="hTot" style="font-family:var(--font-mono);font-size:9px"></span></div><div style="height:90px;padding:4px 8px 6px"><canvas id="hChart"></canvas></div></div>
        <div style="background:var(--bg-elevated);border-left:1px solid var(--border)"><div class="card-header"><span class="card-title">PnL Diario 14d</span><span id="dTot" style="font-family:var(--font-mono);font-size:9px"></span></div><div style="height:90px;padding:4px 8px 6px"><canvas id="dChart"></canvas></div></div>
      </div>
      <div style="background:var(--bg-elevated);border-top:1px solid var(--border);padding:0">
        <div class="card-header"><span class="card-title">PnL Acumulado</span><span id="cumTot" style="font-family:var(--font-mono);font-size:9px"></span></div>
        <div style="height:80px;padding:4px 8px 6px"><canvas id="cumChart"></canvas></div>
      </div>
      <div style="background:var(--bg-elevated);flex:1;display:flex;flex-direction:column;min-height:240px">
        <div class="card-header"><span class="card-title">Order Ladder</span><span id="ladderPx" style="font-family:var(--font-mono);font-size:10px;color:var(--accent)">$0.00</span></div>
        <div id="orderLadder" style="flex:1;overflow-y:auto"><div class="empty-state">Sin órdenes activas</div></div>
      </div>
    </div>

    <div id="sidebarRight" style="width:300px;background:var(--bg-elevated);border-left:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden">
      <div style="display:flex;background:var(--bg-elevated);border-bottom:1px solid var(--border);flex-shrink:0">
        <button class="tab-btn" onclick="switchTab('stats')" style="flex:1;padding:9px 4px;font-size:9px;font-weight:600;border:none;border-bottom:2px solid var(--accent);background:transparent;cursor:pointer;font-family:var(--font-ui);color:var(--accent)">Stats</button>
        <button class="tab-btn" onclick="switchTab('positions')" style="flex:1;padding:9px 4px;font-size:9px;font-weight:600;border:none;border-bottom:2px solid transparent;background:transparent;cursor:pointer;font-family:var(--font-ui);color:var(--text-muted)">Posic.</button>
        <button class="tab-btn" onclick="switchTab('fills')" style="flex:1;padding:9px 4px;font-size:9px;font-weight:600;border:none;border-bottom:2px solid transparent;background:transparent;cursor:pointer;font-family:var(--font-ui);color:var(--text-muted)">Fills</button>
        <button class="tab-btn" onclick="switchTab('ml')" style="flex:1;padding:9px 4px;font-size:9px;font-weight:600;border:none;border-bottom:2px solid transparent;background:transparent;cursor:pointer;font-family:var(--font-ui);color:var(--text-muted)">ML</button>
        <button class="tab-btn" onclick="switchTab('log')" style="flex:1;padding:9px 4px;font-size:9px;font-weight:600;border:none;border-bottom:2px solid transparent;background:transparent;cursor:pointer;font-family:var(--font-ui);color:var(--text-muted)">Log</button>
      </div>
      <div style="flex:1;overflow:hidden;position:relative">
        <div id="tab-stats" class="tab-panel" style="position:absolute;inset:0;overflow-y:auto;display:block">
          <div style="padding:10px 12px 4px"><div style="font-size:8px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px">Sesión</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;margin-bottom:8px">
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Órd. abiertas</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600;color:var(--accent)" id="stOpen">--</div></div>
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Fills total</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600" id="stFills">--</div></div>
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Fills hoy</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600" id="stFillsH">--</div></div>
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Peak PnL</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600;color:var(--green)" id="stPeak">--</div></div>
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Recovery</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600" id="stRecov">No</div></div>
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Win Rate</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600;color:var(--accent)" id="stWr">--%</div></div>
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Fills/hora</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600" id="stFillH">--</div></div>
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">PnL 1h</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600" id="stPnl1h">--</div></div>
          </div></div>
          <div style="padding:0 12px 4px"><div style="font-size:8px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px">Mercado</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;margin-bottom:8px">
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Precio</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600;color:var(--accent)" id="stPx">--</div></div>
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Cambio 24h</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600" id="stChg">--%</div></div>
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">High 24h</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600;color:var(--green)" id="stH">--</div></div>
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Low 24h</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600;color:var(--red)" id="stL">--</div></div>
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Vol 24h</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600" id="stVol">--</div></div>
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Spread</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600;color:var(--yellow)" id="stSpr">--</div></div>
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">RSI-14</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600" id="stRsi">--</div></div>
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">MACD Hist</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600" id="stMacd">--</div></div>
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Funding Rate</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600" id="stFund">--</div></div>
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Open Interest</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600" id="stOi">--</div></div>
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Mark Price</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600;color:var(--accent)" id="stMark">--</div></div>
            <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">ADX</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600" id="stAdx">--</div></div>
          </div></div>
        </div>
        <div id="tab-positions" class="tab-panel" style="position:absolute;inset:0;overflow-y:auto;display:none">
          <div style="padding:8px 12px"><table class="data-table"><thead><tr><th>Lado</th><th>Qty</th><th>Entry $</th><th>uPnL</th><th>Liq $</th></tr></thead><tbody id="posBody"><tr><td colspan="5" class="empty-state">Sin posición abierta</td></tr></tbody></table></div>
        </div>
        <div id="tab-fills" class="tab-panel" style="position:absolute;inset:0;overflow-y:auto;display:none">
          <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px 4px"><span>Últimos Fills</span><span id="fillCnt" style="font-size:9px;font-family:var(--font-mono);padding:1px 7px;border-radius:4px;background:rgba(45,140,255,.15);color:var(--accent);font-weight:700">0</span></div>
          <div style="padding:0 4px"><table class="data-table"><thead><tr><th>Hora</th><th>Lado</th><th>Rol</th><th class="tr">PnL</th><th>Price</th><th>R</th></tr></thead><tbody id="fillBody"><tr><td colspan="6" class="empty-state">Sin historial</td></tr></tbody></table></div>
          <div style="display:flex;gap:5px;align-items:center;padding:4px 12px 8px">
            <button class="btn btn-primary" onclick="fillsPrev()" style="font-size:9px;padding:2px 7px">◀</button>
            <span id="fillsPage" style="font-family:var(--font-mono);font-size:9px;color:var(--text-muted)">1/1</span>
            <button class="btn btn-primary" onclick="fillsNext()" style="font-size:9px;padding:2px 7px">▶</button>
            <button class="btn btn-primary" onclick="loadFillsHistory()" style="margin-left:auto;font-size:9px;padding:2px 7px">🔄 Historial</button>
          </div>
        </div>
        <div id="tab-ml" class="tab-panel" style="position:absolute;inset:0;overflow-y:auto;display:none">
          <div style="padding:10px 12px 4px">
            <div style="font-size:8px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px">Modelo ML · Regresión Logística</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;margin-bottom:8px">
              <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Precisión (RF OOS)</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600;color:var(--accent)" id="mlAccStat">--%</div></div>
              <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Features</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600" id="mlFeatCount">--</div></div>
              <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Símbolo</div><div style="font-family:var(--font-mono);font-size:12px;font-weight:600">ETHUSDT</div></div>
              <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 9px"><div style="font-size:8px;color:var(--text-muted);margin-bottom:3px">Actualizado</div><div style="font-family:var(--font-mono);font-size:9px;font-weight:600" id="mlUpdated">--</div></div>
            </div>
            <div style="font-size:8px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px">Importancia de Features</div>
          </div>
          <div id="mlFeatBars" style="padding:0 12px 12px"><div style="color:var(--text-muted);font-size:9px;text-align:center;padding:10px">Cargando...</div></div>
        </div>
        <div id="tab-log" class="tab-panel" style="position:absolute;inset:0;overflow-y:auto;display:none">
          <div style="display:flex;flex-direction:column;height:100%">
            <div style="display:flex;align-items:center;gap:6px;padding:6px 10px;background:var(--bg-elevated);border-bottom:1px solid var(--border);flex-shrink:0">
              <input type="text" id="logSearch" placeholder="Filtrar…" oninput="filterLog()" style="flex:1;background:var(--bg);border:1px solid var(--border);border-radius:4px;padding:3px 7px;font-family:var(--font-mono);font-size:9px;color:var(--text-primary);outline:none">
              <button class="btn btn-primary" onclick="clearLog()" style="font-size:9px;padding:3px 7px">Limpiar</button>
              <button class="btn btn-primary" onclick="logPaused=!logPaused;this.style.color=logPaused?'var(--yellow)':''" style="font-size:9px;padding:3px 7px">⏸</button>
            </div>
            <div id="logBox" style="flex:1;overflow-y:auto;font-family:var(--font-mono);font-size:9px;line-height:1.9;padding:6px 10px"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div id="toasts" style="position:fixed;bottom:20px;right:20px;z-index:9000;display:flex;flex-direction:column;gap:8px;pointer-events:none"></div>

<div id="configModal" style="position:fixed;inset:0;z-index:9001;background:rgba(0,0,0,.7);display:none;place-items:center;padding:16px">
  <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-lg);width:100%;max-width:380px;box-shadow:0 8px 40px rgba(0,0,0,.5)">
    <div style="padding:12px 16px;background:var(--bg-elevated);border-bottom:1px solid var(--border);font-size:12px;font-weight:700;color:#fff">⚙️ Configuración en Vivo</div>
    <div style="padding:14px 16px;display:flex;flex-direction:column;gap:10px">
      <div style="display:flex;flex-direction:column;gap:3px"><label style="font-size:9px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px">Capital (USDT)</label><input type="number" id="cfgCapital" class="cfg-input" min="10" step="10" style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 10px;font-family:var(--font-mono);font-size:12px;color:var(--text-primary);outline:none;width:100%"></div>
      <div style="display:flex;flex-direction:column;gap:3px"><label style="font-size:9px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px">Apalancamiento (×)</label><input type="number" id="cfgLeverage" class="cfg-input" min="1" max="100" style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 10px;font-family:var(--font-mono);font-size:12px;color:var(--text-primary);outline:none;width:100%"></div>
      <div style="display:flex;flex-direction:column;gap:3px"><label style="font-size:9px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px">Niveles totales</label><input type="number" id="cfgLevels" class="cfg-input" min="4" max="50" style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 10px;font-family:var(--font-mono);font-size:12px;color:var(--text-primary);outline:none;width:100%"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div style="display:flex;flex-direction:column;gap:3px"><label style="font-size:9px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px">Long</label><input type="number" id="cfgLong" class="cfg-input" min="1" style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 10px;font-family:var(--font-mono);font-size:12px;color:var(--text-primary);outline:none;width:100%"></div>
        <div style="display:flex;flex-direction:column;gap:3px"><label style="font-size:9px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px">Short</label><input type="number" id="cfgShort" class="cfg-input" min="1" style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 10px;font-family:var(--font-mono);font-size:12px;color:var(--text-primary);outline:none;width:100%"></div>
      </div>
      <div style="display:flex;flex-direction:column;gap:3px"><label style="font-size:9px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px">Spacing (%)</label><input type="number" id="cfgSpacing" class="cfg-input" min="0.01" step="0.005" style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-md);padding:7px 10px;font-family:var(--font-mono);font-size:12px;color:var(--text-primary);outline:none;width:100%"></div>
    </div>
    <div style="padding:10px 16px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end">
      <button class="btn btn-primary" onclick="closeConfig()" style="font-size:11px;font-weight:600;padding:4px 9px;border:1px solid var(--border);background:transparent;color:var(--text-muted);border-radius:6px;cursor:pointer">Cancelar</button>
      <button class="btn btn-primary" onclick="applyConfig()" style="font-size:11px;font-weight:600;padding:4px 9px;background:var(--green);color:#fff;border:none;border-radius:6px;cursor:pointer">Aplicar y Reconstruir</button>
    </div>
  </div>
</div>

<script>
const CAPITAL = <?= $CAPITAL ?>;
const AI_INT = <?= $AI_INT ?>;
let SPEED = 'fast';
const IV = { fast:{tick:1000,stat:3000,log:4000,mkt:30000,upnl:2500,scalp:15000}, normal:{tick:2000,stat:5000,log:8000,mkt:60000,upnl:5000,scalp:30000} };
let charts = {};
let lastPrice=0, lastAICheck=null, loaded=false, logPaused=false;
let tickerTimer, statusTimer, logTimer, mktTimer, upnlTimer, scalpTimer;
let lastFillIds=new Set(), allLogLines=[], logFilter='';
let fillsOffset=0, fillsTotal=0, fillsLimit=40;
let lwChart=null, lwSeries=null, lastCandleTime=0;
let lastDirection = null;
let lastRecentFillsCache = [];

const $ = id => document.getElementById(id);
const fP = (v,d=2) => '$'+parseFloat(v||0).toFixed(d);
function fM(v,d=4){
  v=parseFloat(v||0); if(isNaN(v)) return '<span style="color:var(--text-muted)">--</span>';
  const cls=v>0?'c-pos':v<0?'c-neg':'c-dim';
  return `<span class="${cls}">${v>0?'+':''}${v.toFixed(d)}</span>`;
}

// ─── Loader ───
function hideLdr() { $('ldr').style.display='none'; $('app').style.display='flex'; loaded=true; }
function markUpdate(){ $('lastUpdate').textContent='ahora'; }
setInterval(()=>{
  const s=Math.floor((Date.now()-startTs)/1000);
  $('lastUpdate').textContent=s<=0?'ahora':`hace ${s}s`;
},1000);
const startTs = Date.now();

// ─── Navigation ───
document.getElementById('menuToggle').addEventListener('click',()=>{
  document.getElementById('sidebarLeft').style.left='0';
  document.getElementById('drawerOverlay').style.display='block';
});
document.getElementById('drawerOverlay').addEventListener('click',()=>{
  document.getElementById('sidebarLeft').style.left='-280px';
  document.getElementById('drawerOverlay').style.display='none';
});
if(window.innerWidth<=900){
  const rightBtn=document.getElementById('rightToggle');
  if(rightBtn){rightBtn.style.display='flex';rightBtn.addEventListener('click',()=>{document.getElementById('sidebarRight').classList.toggle('open');});}
}

function switchTab(name){
  document.querySelectorAll('.tab-btn').forEach(b=>{b.style.color='var(--text-muted)';b.style.borderBottomColor='transparent';});
  document.querySelectorAll('.tab-panel').forEach(p=>p.style.display='none');
  const btn = document.querySelectorAll('.tab-btn')[['stats','positions','fills','ml','log'].indexOf(name)];
  if(btn) { btn.style.color='var(--accent)'; btn.style.borderBottomColor='var(--accent)'; }
  $('tab-'+name).style.display='block';
  if(name==='ml') fetchMLInfo();
  if(name==='fills') loadFillsHistory();
}

// ─── Toast ───
function toast(title, msg, type){
  const icons={info:'ℹ️',fill_pos:'✅',fill_neg:'⚠️',warn:'🔶'};
  const t=document.createElement('div');
  t.style.cssText = 'background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-md);padding:10px 14px;display:flex;align-items:center;gap:10px;box-shadow:0 4px 12px rgba(0,0,0,0.3);max-width:280px;pointer-events:all;animation:toastIn .3s ease;';
  t.innerHTML = '<span style="font-size:18px;flex-shrink:0">'+(icons[type]||'ℹ️')+'</span><div style="flex:1;min-width:0"><div style="font-size:11px;font-weight:700;color:#fff;margin-bottom:2px">'+title+'</div><div style="font-family:var(--font-mono);font-size:10px;color:var(--text-secondary)">'+msg+'</div></div><button onclick="dismissToast(this.parentNode)" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:14px;padding:0;flex-shrink:0">×</button>';
  $('toasts').prepend(t);
  setTimeout(()=>dismissToast(t),5000);
}
function dismissToast(t){t.style.opacity='0';t.style.transition='opacity .3s';setTimeout(()=>t.remove(),300);}

// ─── WebSocket events (bridge from module) ───
window.addEventListener('ws:status', (e) => {
  const ind = $('wsIndicator');
  if (ind) ind.style.background = e.detail.connected ? 'var(--green)' : 'var(--red)';
});
window.addEventListener('data:ticker', (e) => updateTickerUI(e.detail));
window.addEventListener('data:grid', (e) => {
  const d = e.detail;
  if (d.orders) updateLadder(d.orders);
  if (d.mode) { $('modeBadge').textContent=d.mode; $('modeBadge').style.background = d.mode==='NORMAL' ? 'rgba(0,201,122,.15)' : 'rgba(245,166,35,.15)'; $('modeBadge').style.color = d.mode==='NORMAL' ? 'var(--green)' : 'var(--yellow)'; }
  if (d.open_orders !== undefined) $('stOpen').textContent = d.open_orders;
});
window.addEventListener('data:kpi', (e) => {
  const d = e.detail;
  if (d.pnl_today !== undefined) { $('kPnlH').innerHTML = fM(d.pnl_today); $('stFills').textContent = d.fills_total || '--'; }
  if (d.pnl_total !== undefined) $('kPnlT').innerHTML = fM(d.pnl_total);
  if (d.win_rate !== undefined) { $('kWin').textContent = d.win_rate + '%'; $('stWr').textContent = d.win_rate + '%'; }
  if (d.uptime) { $('kUpt').textContent = d.uptime; $('uptTxt').textContent = d.uptime; }
  if (d.mode) { $('modeBadge').textContent=d.mode; $('modeBadge').style.background = d.mode==='NORMAL' ? 'rgba(0,201,122,.15)' : 'rgba(245,166,35,.15)'; $('modeBadge').style.color = d.mode==='NORMAL' ? 'var(--green)' : 'var(--yellow)'; }
  if (d.open_orders !== undefined) $('stOpen').textContent = d.open_orders;
  markUpdate();
});
window.addEventListener('data:ai', (e) => {
  const d = e.detail;
  if (d.direction && d.confidence) { setGauge(d.confidence, d.direction); }
  if (d.reason) $('gRsn').textContent = d.reason;
  if (d.next_eval) { lastAICheck = d.next_eval; }
});
window.addEventListener('data:positions', (e) => updatePositionsUI(e.detail));
window.addEventListener('data:fills', (e) => updateRecentFillsFromWS(e.detail));
window.addEventListener('data:pnl', (e) => {
  const d = e.detail;
  if (d.hourly) renderHourly(d.hourly);
  if (d.cumulative) renderCumulativeFromWS(d.cumulative);
});
window.addEventListener('data:market', (e) => {
  const d = e.detail;
  if (d.indicators) renderIndicators(d.indicators);
});
window.addEventListener('data:logs', (e) => appendLogsFromWS(e.detail));

// ─── Gauge ───
const G_LEN = Math.PI * 56;
function setGauge(conf, dir){
  const col={UP:'var(--green)',DOWN:'var(--red)',SIDEWAYS:'var(--accent)'}[dir]||'var(--accent)';
  const ico={UP:'▲',DOWN:'▼',SIDEWAYS:'↔'}[dir]||'';
  const arc=$('gArc');
  arc.style.strokeDasharray = G_LEN;
  arc.style.strokeDashoffset = G_LEN - (conf/100)*G_LEN;
  arc.style.stroke = col;
  $('gLbl').textContent = conf+'%'; $('gLbl').style.color = col;
  $('gDir').innerHTML = '<span style="color:'+col+'">'+ico+' '+dir+'</span>';
  if($('aiEngBadge')) $('aiEngBadge').textContent = dir+' '+conf+'%';
}

// ─── Ticker ───
function updateTickerUI(d){
  const el = $('priceLive');
  if(lastPrice && d.price !== lastPrice){ el.classList.remove('fup','fdn'); void el.offsetWidth; el.classList.add(d.price > lastPrice ? 'fup' : 'fdn'); }
  lastPrice = d.price;
  el.textContent = '$'+d.price.toLocaleString('en-US',{minimumFractionDigits:2});
  if($('ladderPx')) $('ladderPx').textContent = '$'+d.price.toFixed(2);
  const chg = d.change24h || d.change_pct || 0;
  $('priceChg').textContent = (chg>=0?'+':'')+chg.toFixed(2)+'%';
  $('priceChg').className = chg>0?'up':'dn';
  $('priceChg').style.background = chg>0?'rgba(0,201,122,.15)':chg<0?'rgba(240,60,82,.15)':'var(--accent)';
  $('priceChg').style.color = chg>0?'var(--green)':chg<0?'var(--red)':'var(--accent)';
  const h=d.high24h||0, l=d.low24h||0, v=d.vol24h||d.volume24h||0;
  $('priceHL').textContent = 'H: $'+h.toFixed(2)+' · L: $'+l.toFixed(2)+' · Vol: '+(v>0?(v/1000).toFixed(0)+'K':'--');
  $('stPx').textContent = '$'+d.price.toFixed(2);
  $('stChg').innerHTML = '<span class="'+(chg>=0?'c-pos':'c-neg')+'">'+(chg>=0?'+':'')+chg.toFixed(2)+'%</span>';
  $('stH').textContent = '$'+h.toFixed(2);
  $('stL').textContent = '$'+l.toFixed(2);
  $('stVol').textContent = v>0?(v/1000).toFixed(0)+'K':'--';
  if(d.bid){ $('bidPx').textContent = '$'+parseFloat(d.bid).toFixed(2); }
  if(d.ask){ $('askPx').textContent = '$'+parseFloat(d.ask).toFixed(2); }
  if(d.bid && d.ask){ $('spreadVal').textContent = '·'; $('stSpr').textContent = '$'+(parseFloat(d.ask)-parseFloat(d.bid)).toFixed(2); }
  const fr=(d.fundRate||0)*100;
  $('tbFunding').innerHTML = '<span class="'+(fr>=0?'funding-pos':'funding-neg')+'">'+(fr>=0?'+':'')+fr.toFixed(4)+'%</span>';
  $('stFund').innerHTML = '<span class="'+(fr>=0?'funding-pos':'funding-neg')+'">'+(fr>=0?'+':'')+fr.toFixed(4)+'%</span>';
  if(d.markPrice){ $('tbMark').textContent = '$'+parseFloat(d.markPrice).toFixed(2); $('stMark').textContent = '$'+parseFloat(d.markPrice).toFixed(2); }
  if(d.oi){ const oiK=(d.oi/1000).toFixed(1)+'K ETH'; $('stOi').textContent=oiK; }
  if(lwSeries && d.price && lastCandleTime > 0){
    const t5 = Math.floor(Date.now()/1000/300)*300;
    const ut = Math.max(t5, lastCandleTime);
    lwSeries.update({time: ut, open: d.price, high: d.price, low: d.price, close: d.price});
  }
}

// ─── Bot status ───
function setBotStatus(r){
  $('sysTxt').textContent = r ? 'Activo' : 'Detenido';
  $('sysTxt').style.color = r ? 'var(--green)' : 'var(--red)';
  $('liveIndicator').style.background = r ? 'var(--green)' : 'var(--red)';
  $('liveIndicator').style.boxShadow = r ? '0 0 6px var(--green)' : 'none';
}

// ─── Pair ───
function updatePairUI(pair){
  $('cNiv').textContent = pair.levels || '--';
  $('cLS').textContent = (pair.long_levels||'--')+' / '+(pair.short_levels||'--');
  $('cSpc').textContent = ((pair.spacing_pct||0)*100).toFixed(3)+'%';
  $('cEnt').textContent = pair.open_entries || 0;
  $('cSal').textContent = pair.open_exits || 0;
  setGauge(pair.confidence || 0, pair.direction || 'SIDEWAYS');
  $('gRsn').textContent = pair.ai_reason || '—';
  lastAICheck = pair.last_ai_check || null;
  if($('stRecov')) $('stRecov').textContent = pair.recovery_active ? 'Sí 🔄' : 'No';
  if($('stRecov2')) $('stRecov2').textContent = pair.recovery_active ? 'Sí 🔄' : 'No';
}
function updatePairNumbers(pair){
  if(pair.pnl_today !== undefined){
    $('kPnlH').innerHTML = fM(pair.pnl_today);
    $('kPnlHP').textContent = (pair.pnl_today/CAPITAL*100).toFixed(2)+'% capital';
  }
  if(pair.pnl_total !== undefined) $('kPnlT').innerHTML = fM(pair.pnl_total);
  if(pair.fills_total !== undefined){ $('kFillsT').textContent = pair.fills_total+' fills'; $('stFills').textContent = pair.fills_total; }
  if($('stOpen')) $('stOpen').textContent = (pair.open_entries||0)+(pair.open_exits||0);
  if($('kOpenO')) $('kOpenO').textContent = ((pair.open_entries||0)+(pair.open_exits||0))+' órd. abiertas';
  if(pair.real_balance !== undefined){
    const bal = parseFloat(pair.real_balance) || 0;
    $('wBalance').textContent = '$' + bal.toFixed(2);
    $('wMarginUsed').textContent = '$' + CAPITAL.toFixed(2);
    $('wMarginFree').textContent = '$' + Math.max(0, bal - CAPITAL).toFixed(2);
  }
  if(pair.total_upnl !== undefined) $('wUpnl').innerHTML = fM(pair.total_upnl);
  if(pair.pnl_today !== undefined){
    const pnlD = parseFloat(pair.pnl_today) || 0;
    const pnlT = parseFloat(pair.pnl_total) || 0;
    const roiD = CAPITAL > 0 ? (pnlD / CAPITAL * 100) : 0;
    const roiT = CAPITAL > 0 ? (pnlT / CAPITAL * 100) : 0;
    $('wRoiD').textContent = (roiD >= 0 ? '+' : '') + roiD.toFixed(2) + '%';
    $('wRoiT').textContent = (roiT >= 0 ? '+' : '') + roiT.toFixed(2) + '%';
    const daysRunning = Math.max(1, (Date.now() - startTs) / 86400000);
    const avgDaily = daysRunning > 0 ? pnlT / daysRunning : pnlD;
    $('wProj').innerHTML = fM(avgDaily * 30);
    const makerFee = pair.makerFee || 0.0001;
    const takerFee = pair.takerFee || 0.0006;
    const avgFeeRate = (makerFee + takerFee) / 2;
    const fillsNotional = pair.fills_notional || 0;
    const fillsCnt = pair.fills_total || 0;
    const avgNotional = fillsCnt > 0 ? fillsNotional / fillsCnt : 115;
    const fees = fillsCnt * avgNotional * avgFeeRate;
    $('wFees').textContent = '$' + fees.toFixed(2);
  }
  if($('stPeak')) $('stPeak').innerHTML = fM(pair.peak_pnl || 0);
  const mlAcc = pair.ml_accuracy || 0;
  if($('mlBadge')) $('mlBadge').textContent = 'ML '+(mlAcc>0?(mlAcc*100).toFixed(0)+'%':'--');
  if($('cMlAcc')) $('cMlAcc').textContent = mlAcc>0?(mlAcc*100).toFixed(1)+'%':'--';
}

// ─── Indicators ───
function renderIndicators(d){
  if(!d) return;
  const rsi = d.rsi || 50; $('mRsi').textContent = rsi.toFixed(1);
  $('mRsiLbl').textContent = rsi>70?'Sobrecomprado':rsi<30?'Sobrevendido':'Neutral';
  $('mRsiBar').style.width = rsi+'%'; $('mRsiDot').style.left = rsi+'%';
  $('mRsiBar').style.background = rsi>70?'var(--red)':rsi<30?'var(--green)':'var(--accent)';
  $('stRsi').innerHTML = '<span class="'+(rsi>70?'c-neg':rsi<30?'c-pos':'c-neu')+'">'+rsi.toFixed(1)+'</span>';
  const mh = d.macd_hist || 0; $('mMacd').innerHTML = '<span class="'+(mh>0?'c-pos':'c-neg')+'">'+mh.toFixed(5)+'</span>';
  $('mMacdLbl').textContent = mh>0?'Alcista':'Bajista';
  $('mMacdBar').style.width = Math.min(100, Math.abs(mh)*5000)+'%';
  $('mMacdBar').style.background = mh>0?'var(--green)':'var(--red)';
  $('stMacd').innerHTML = '<span class="'+(mh>0?'c-pos':'c-neg')+'">'+mh.toFixed(5)+'</span>';
  const adx = d.adx || 0; $('mAdx').textContent = adx.toFixed(1);
  $('mAdxLbl').textContent = adx>25?'Tendencia fuerte':adx>15?'Tendencia débil':'Lateral';
  $('mAdxBar').style.width = Math.min(100, adx*2)+'%'; $('stAdx').textContent = adx.toFixed(1);
  const atr = d.atr_pct || 0; $('mAtr').textContent = atr.toFixed(3)+'%';
  const vr = d.vol_ratio || 1; $('mVolR').textContent = 'Vol ratio: '+vr.toFixed(2)+'x';
  const bbPct = d.bb_pct || .5; $('mBb').textContent = (bbPct*100).toFixed(0)+'%';
  $('mBbRange').textContent = 'Width: '+(d.bb_width||0).toFixed(3)+'%';
  if(d.ema9)  $('mE9').textContent = '$'+d.ema9.toFixed(2);
  if(d.ema21) $('mE21').textContent = '$'+d.ema21.toFixed(2);
  if(d.ema50) $('mE50').textContent = '$'+d.ema50.toFixed(2);
  const fr = (d.funding||0)*100;
  $('mFunding').innerHTML = '<span class="'+(fr>=0?'funding-pos':'funding-neg')+'">'+(fr>=0?'+':'')+fr.toFixed(4)+'%</span>';
  if(d.oi_value) $('mOiVal').textContent = '$'+(d.oi_value/1e6).toFixed(2)+'M';
}

// ─── Order Ladder ───
function updateLadder(orders){
  const wrap = $('orderLadder');
  if(!orders || !orders.length){ wrap.innerHTML = '<div class="empty-state">Sin órdenes activas</div>'; return; }
  const sorted = [...orders].sort((a,b)=>parseFloat(b.price)-parseFloat(a.price));
  const maxQty = Math.max(...sorted.map(o=>parseFloat(o.qty||0)));
  const curPx = lastPrice || 0;
  let rows='', priceInserted=false;
  sorted.forEach(o => {
    const px = parseFloat(o.price);
    if(!priceInserted && curPx>0 && px<curPx){
      rows += '<div class="ladder-row current-price-row"><span class="lr-price cur" style="font-family:var(--font-mono);font-size:10px;color:var(--accent)">'+fP(curPx)+'</span><div class="lr-bar-wrap"><div style="text-align:center;font-size:8px;color:var(--accent);line-height:10px">── PRECIO ──</div></div><span class="lr-qty"></span></div>';
      priceInserted=true;
    }
    const isBuy = o.side==='BUY'; const pct = maxQty>0?parseFloat(o.qty)/maxQty*100:0;
    const barOpacity = maxQty > 0 ? Math.max(0.2, parseFloat(o.qty) / maxQty) : 1;
    const dist = curPx>0 ? ((px-curPx)/curPx*100).toFixed(2) : '';
    const role = o.grid_role==='EXIT' ? '<span style="color:var(--yellow)">EXIT</span>' : '<span style="color:var(--text-muted)">ENT</span>';
    rows += '<div class="ladder-row"><span class="lr-price '+(isBuy?'buy':'sell')+'" style="font-family:var(--font-mono);font-size:9px;font-weight:600;text-align:right;'+(isBuy?'color:var(--green)':'color:var(--red)')+'">'+fP(px)+'<span style="font-size:7px;color:var(--text-muted);margin-left:3px">'+(dist>0?'+':'')+dist+'%</span></span><div class="lr-bar-wrap"><div class="lr-bar '+(isBuy?'buy':'sell')+'" style="width:'+pct.toFixed(1)+'%;opacity:'+barOpacity.toFixed(2)+';'+(isBuy?'background:rgba(0,201,122,.4);right:0':'background:rgba(240,60,82,.4);left:0')+';position:absolute;top:0;height:100%;border-radius:2px;transition:width .4s"></div></div><span class="lr-qty" style="font-family:var(--font-mono);font-size:8px;color:var(--text-muted);text-align:left">'+parseFloat(o.qty).toFixed(4)+' '+role+'</span></div>';
  });
  if(!priceInserted && curPx>0) rows += '<div class="ladder-row current-price-row"><span class="lr-price cur" style="font-family:var(--font-mono);font-size:10px;color:var(--accent)">'+fP(curPx)+'</span><div class="lr-bar-wrap"><div style="text-align:center;font-size:8px;color:var(--accent);line-height:10px">── PRECIO ──</div></div><span class="lr-qty"></span></div>';
  wrap.innerHTML = '<div class="ladder-hd"><span style="text-align:right">Precio</span><span style="text-align:center">Qty</span><span style="text-align:left">Rol</span></div>'+rows;
}

// ─── Positions ───
function updatePositionsUI(positions){
  const pb = $('posBody');
  if(!pb) return;
  if(positions && positions.length > 0){
    pb.innerHTML = positions.map(p=>{
      const amt = parseFloat(p.positionAmt);
      const side = amt>0?'BUY':'SELL';
      return '<tr><td><span class="badge '+(amt>0?'badge-green':'badge-red')+'">'+side+'</span></td><td>'+Math.abs(amt).toFixed(4)+'</td><td>'+fP(p.entryPrice)+'</td><td>'+fM(p.unRealizedProfit||0)+'</td><td style="color:var(--red)">'+fP(p.liquidationPrice)+'</td></tr>';
    }).join('');
  } else {
    pb.innerHTML = '<tr><td colspan="5" class="empty-state">Sin posición abierta</td></tr>';
  }
  const chip = $('upnlChip');
  const chipVal = $('upnlChipVal');
  const box = $('upnlBox');
  const boxVal = $('upnlVal');
  const hasPos = positions && positions.length>0;
  const upnl = positions?positions.reduce((s,p)=>s+parseFloat(p.unRealizedProfit||0),0):0;
  if(chip){ if(hasPos || Math.abs(upnl)>0.0001){ chip.style.display='flex'; chipVal.innerHTML=fM(upnl); } else chip.style.display='none'; }
  if(box){ if(hasPos){ box.style.display='flex'; boxVal.innerHTML=fM(upnl); } else box.style.display='none'; }
}

// ─── Fills ───
function renderFillsTable(fills){
  const fb = $('fillBody');
  if(!fb) return;
  if(!fills.length){ fb.innerHTML = '<tr><td colspan="6" class="empty-state">Sin historial</td></tr>'; return; }
  fb.innerHTML = fills.map(f=>{
    const bc = f.side === 'BUY' ? 'badge-green' : 'badge-red';
    const rec = f.is_recovery ? '<span class="badge" style="background:rgba(245,166,35,.15);color:var(--yellow);font-size:7px">R</span>' : '';
    return '<tr><td style="color:var(--text-muted)">'+(f.filled_at||'').slice(11,19)+'</td><td><span class="badge '+bc+'">'+f.side+'</span></td><td style="color:var(--text-muted)">'+(f.grid_role||'')+'</td><td class="tr">'+fM(f.pnl_usd||0)+'</td><td style="color:var(--text-secondary)">'+fP(f.exit_price||f.price||0)+'</td><td>'+rec+'</td></tr>';
  }).join('');
}
function updateRecentFillsFromWS(fills){
  if(!fills||!fills.length) return;
  lastRecentFillsCache = fills;
  if($('tab-fills').style.display==='block'&&fillsOffset===0){
    renderFillsTable(fills.slice(0, fillsLimit));
    $('fillCnt').textContent = fills.length;
  }
  fills.forEach(f=>{
    const id = f.filled_at+'_'+f.side+'_'+f.pnl_usd;
    if(!lastFillIds.has(id)&&lastFillIds.size>0&&f.grid_role==='EXIT'){
      const pnl = parseFloat(f.pnl_usd||0);
      toast('Fill completado', f.side+' EXIT · PnL: '+(pnl>=0?'+':'')+pnl.toFixed(4)+' USDT', pnl>=0?'fill_pos':'fill_neg');
    }
    lastFillIds.add(id);
    if(lastFillIds.size>200) lastFillIds.delete(lastFillIds.values().next().value);
  });
}
function loadFillsHistory(){
  if(fillsOffset===0 && lastRecentFillsCache.length){
    renderFillsTable(lastRecentFillsCache.slice(0, fillsLimit));
    $('fillCnt').textContent = lastRecentFillsCache.length;
    const totalPages = Math.ceil(lastRecentFillsCache.length / fillsLimit) || 1;
    const curPage = Math.floor(fillsOffset / fillsLimit) + 1;
    if($('fillsPage')) $('fillsPage').textContent = curPage+'/'+totalPages;
    return;
  }
  fetch('grid_ajax.php?_fills_history=1&limit='+fillsLimit+'&offset='+fillsOffset+'&t='+Date.now())
    .then(r=>r.json()).then(d=>{
      if(!d||!d.ok) return;
      fillsTotal = d.total || 0;
      const totalPages = Math.ceil(fillsTotal / fillsLimit) || 1;
      const curPage = Math.floor(fillsOffset / fillsLimit) + 1;
      if($('fillsPage')) $('fillsPage').textContent = curPage+'/'+totalPages;
      renderFillsTable(d.fills||[]);
    }).catch(()=>{});
}
function fillsPrev(){ if(fillsOffset>0){ fillsOffset=Math.max(0,fillsOffset-fillsLimit); loadFillsHistory(); } }
function fillsNext(){ if(fillsOffset+fillsLimit<fillsTotal){ fillsOffset+=fillsLimit; loadFillsHistory(); } }

// ─── Logs ───
function appendLogsFromWS(logLines){
  if(!logLines||!logLines.length) return;
  const last10 = allLogLines.slice(-10);
  const newLines = logLines.filter(l=>!last10.includes(l));
  if(newLines.length){ allLogLines.push(...newLines); if(allLogLines.length>500) allLogLines=allLogLines.slice(-500); renderLog(); }
}
function appendLogs(lines){ allLogLines = lines; renderLog(); }
function renderLog(){
  const box = $('logBox'); if(!box) return;
  const atBot = box.scrollHeight-box.clientHeight <= box.scrollTop+40;
  const f = logFilter.toLowerCase();
  const filt = f ? allLogLines.filter(l=>l.toLowerCase().includes(f)) : allLogLines;
  box.innerHTML = filt.map(line=>{
    const m = line.match(/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+\[(\w+)\]\s+(.*)$/);
    if(m){
      const t=m[1].slice(11), lv=m[2], msg=m[3].replace(/</g,'&lt;').replace(/>/g,'&gt;');
      const cls = lv==='ERROR'?'le':lv==='WARN'?'lw':'li';
      return '<div class="ll"><span class="lt">'+t+'</span><span class="'+cls+'">['+lv+']</span><span class="lm">'+msg+'</span></div>';
    }
    return '<div class="ll"><span class="lm">'+line.replace(/</g,'&lt;')+'</span></div>';
  }).join('');
  if(atBot && !logPaused) box.scrollTop = box.scrollHeight;
}
function filterLog(){ logFilter = $('logSearch').value; renderLog(); }
function clearLog(){ allLogLines=[]; $('logBox').innerHTML=''; }

// ─── Charts ───
function chartDef(id,type,labels,data,opts){
  const ctx = $(id)?.getContext('2d'); if(!ctx) return null;
  return new Chart(ctx,{type,data:{labels,datasets:[{...opts,data}]},options:{
    responsive:true,maintainAspectRatio:false,animation:{duration:400},
    plugins:{legend:{display:false}},
    scales:{x:{ticks:{color:'#3a5270',font:{size:7}},grid:{color:'rgba(26,37,53,.4)'}},
            y:{ticks:{color:'#3a5270',font:{size:7}},grid:{color:'rgba(26,37,53,.4)'}}}
  }});
}
function renderHourly(data){
  const labels=[],vals=[]; let total=0;
  for(let i=0;i<24;i++){
    labels.push(String(i).padStart(2,'0')+'h');
    const f=data.find(x=>parseInt(x.h)===i); const p=f?parseFloat(f.p):0;
    vals.push(p); total+=p;
  }
  $('hTot').innerHTML = '<span class="'+(total>=0?'c-pos':'c-neg')+'">'+(total>=0?'+':'')+total.toFixed(4)+' USDT</span>';
  const bg=vals.map(v=>v>=0?'rgba(0,201,122,.5)':'rgba(240,60,82,.5)');
  const bd=vals.map(v=>v>=0?'#00c97a':'#f03c52');
  if(charts['h']){ charts['h'].data.datasets[0].data=vals; charts['h'].data.datasets[0].backgroundColor=bg; charts['h'].data.datasets[0].borderColor=bd; charts['h'].update('none'); }
  else charts['h']=chartDef('hChart','bar',labels,vals,{backgroundColor:bg,borderColor:bd,borderWidth:1,borderRadius:3});
}
function renderDaily(data){
  if(!data||!data.length) return;
  const sorted=[...data].reverse();
  const labels=sorted.map(r=>r.d.slice(5));
  const vals=sorted.map(r=>parseFloat(r.p));
  const total=vals.reduce((a,b)=>a+b,0);
  $('dTot').innerHTML = '<span class="'+(total>=0?'c-pos':'c-neg')+'">'+(total>=0?'+':'')+total.toFixed(4)+'</span>';
  const bg=vals.map(v=>v>=0?'rgba(0,201,122,.5)':'rgba(240,60,82,.5)');
  if(charts['d']){ charts['d'].data.labels=labels; charts['d'].data.datasets[0].data=vals; charts['d'].data.datasets[0].backgroundColor=bg; charts['d'].update('none'); }
  else charts['d']=chartDef('dChart','bar',labels,vals,{backgroundColor:bg,borderRadius:4});
}
function renderCumulativeFromWS(cumulative){
  if(!cumulative||!cumulative.length) return;
  const sorted=[...cumulative].reverse();
  const labels=sorted.map(r=>r.d.slice(5));
  const vals=sorted.map(r=>parseFloat(r.p));
  const total=vals.reduce((a,b)=>a+b,0);
  $('cumTot').innerHTML = '<span class="'+(total>=0?'c-pos':'c-neg')+'">'+(total>=0?'+':'')+total.toFixed(4)+' USDT</span>';
  const cumBd = total>=0?'#00c97a':'#f03c52';
  if(charts['cum']){ charts['cum'].data.labels=labels; charts['cum'].data.datasets[0].data=vals; charts['cum'].data.datasets[0].borderColor=cumBd; charts['cum'].data.datasets[0].backgroundColor=total>=0?'rgba(0,201,122,.06)':'rgba(240,60,82,.06)'; charts['cum'].update('none'); }
  else charts['cum']=chartDef('cumChart','line',labels,vals,{borderColor:cumBd,borderWidth:2,pointRadius:2,fill:true,backgroundColor:total>=0?'rgba(0,201,122,.06)':'rgba(240,60,82,.06)',tension:.3,pointBackgroundColor:cumBd});
}
function renderConf(hist){
  if(!hist.length) return;
  const vals=hist.map(h=>h.confidence);
  const labels=hist.map(h=>(h.time||'').slice(11,16));
  const colors=hist.map(h=>h.direction==='UP'?'rgba(0,201,122,.8)':h.direction==='DOWN'?'rgba(240,60,82,.8)':'rgba(45,140,255,.8)');
  if(charts['conf']){ charts['conf'].data.labels=labels; charts['conf'].data.datasets[0].data=vals; charts['conf'].data.datasets[0].borderColor=colors[colors.length-1]||'#2d8cff'; charts['conf'].update('none'); }
  else charts['conf']=chartDef('confChart','line',labels,vals,{borderColor:'#2d8cff',borderWidth:1.5,pointRadius:0,fill:true,backgroundColor:'rgba(45,140,255,.06)',tension:.3});
}

// ─── AI Timer ───
function tickAI(){
  if(!lastAICheck){ $('aiSec').textContent='--s'; $('aiBar').style.width='0%'; return; }
  const elapsed = (Date.now()-new Date(lastAICheck+'Z').getTime())/1000;
  const remain = Math.max(0, AI_INT-elapsed);
  $('aiSec').textContent = Math.ceil(remain)+'s';
  $('aiBar').style.width = Math.min(100,(elapsed/AI_INT)*100).toFixed(1)+'%';
  $('aiBar').style.background = elapsed>=AI_INT?'var(--green)':'var(--accent)';
}

// ─── Commands ───
function cmd(action){
  if(!confirm({stop:'¿Detener el bot?',force_ai:'¿Forzar evaluación IA?',reset_grid:'¿Reconstruir grilla?'}[action]||'¿Confirmar?')) return;
  fetch('grid_ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({_control:'1',action:action})})
    .then(r=>r.json()).then(d=>{if(d.ok)toast('Comando enviado',action,'info');else toast('Error',d.msg,'warn');}).catch(()=>toast('Error','Error de red','warn'));
}
function exportPnl(){ window.open('?export_pnl=1&token=<?= EXPORT_TOKEN ?>','_blank'); }
function toggleSpeed(){
  SPEED = SPEED==='fast'?'normal':'fast';
  $('speedBtn').textContent = SPEED==='fast'?'⚡ Rápido':'🐢 Normal';
  [tickerTimer,statusTimer,logTimer,mktTimer,upnlTimer,scalpTimer].forEach(clearInterval);
  tickerTimer=statusTimer=logTimer=mktTimer=upnlTimer=scalpTimer=null;
  startPolling();
}
function openConfig(){
  $('cfgCapital').value = CAPITAL;
  const levMatch = document.querySelector('.brand-sub')?.textContent?.match(/(\d+)×/);
  $('cfgLeverage').value = levMatch ? parseInt(levMatch[1]) : 5;
  $('cfgLevels').value = parseInt($('cNiv').textContent || '16');
  const ls = ($('cLS').textContent || '4/4').split('/');
  $('cfgLong').value = parseInt(ls[0] || '4');
  $('cfgShort').value = parseInt(ls[1] || '4');
  $('cfgSpacing').value = (parseFloat(($('cSpc').textContent || '0.08').replace('%','')) || 0.08).toFixed(4);
  $('configModal').style.display = 'grid';
}
function closeConfig(){ $('configModal').style.display = 'none'; }
async function applyConfig(){
  const body = new URLSearchParams({action:'update_config',
    capital_usd: $('cfgCapital').value,
    leverage: $('cfgLeverage').value,
    levels: $('cfgLevels').value,
    long_levels: $('cfgLong').value,
    short_levels: $('cfgShort').value,
    spacing_pct: (parseFloat($('cfgSpacing').value)/100).toFixed(6)
  });
  const r = await fetch('grid_ajax.php',{method:'POST',body}).then(x=>x.json());
  toast('Configuración', r.msg || 'Aplicada', r.ok?'info':'warn');
  closeConfig();
  if(r.ok) setTimeout(()=>cmd('reset_grid'),1000);
}

// ─── Polling ───
async function fetchWithRetry(params, retry=0){
  try{
    const r = await fetch('grid_ajax.php?'+params+'&t='+Date.now(),{headers:{'X-Requested-With':'XMLHttpRequest'}});
    if(!r.ok) throw new Error('HTTP '+r.status);
    return await r.json();
  }catch(e){
    if(retry<4){ await new Promise(r=>setTimeout(r,1000*Math.pow(2,retry))); return fetchWithRetry(params,retry+1); }
    return null;
  }
}
async function fetchTicker(){
  const d=await fetchWithRetry('_ticker=1'); if(d&&d.ok) updateTickerUI(d);
}
async function fetchStatus(){
  const d=await fetchWithRetry('_status=1'); if(!d) return;
  if(!loaded) hideLdr();
  markUpdate();
  const running = d.running??d.bot_running??false;
  setBotStatus(running);
  $('kUpt').textContent = d.uptime||'--';
  $('uptTxt').textContent = d.uptime||'--';
  const mode = d.mode||'NORMAL';
  $('modeBadge').textContent = mode;
  $('modeBadge').style.background = mode==='NORMAL'?'rgba(0,201,122,.15)':'rgba(245,166,35,.15)';
  $('modeBadge').style.color = mode==='NORMAL'?'var(--green)':'var(--yellow)';
  const pair = d.pairs?.ETHUSDT;
  if(pair){
    updatePairUI(pair);
    updatePairNumbers(pair);
    if(pair.orders) updateLadder(pair.orders);
    const gridOn = pair.grid_built !== false;
    if($('gridDot')) $('gridDot').style.background = gridOn ? 'var(--green)' : 'var(--red)';
    if($('gridDot')) $('gridDot').style.boxShadow = gridOn ? '0 0 5px var(--green)' : 'none';
    if($('gridStatusTxt')) $('gridStatusTxt').textContent = 'Grid '+(gridOn?'ON':'OFF')+' · '+(pair.open_entries||0)+'E '+(pair.open_exits||0)+'S';
    if($('cycleN')) $('cycleN').textContent = pair.cycle_n||'--';
  }
  if(d.pnl_hourly) renderHourly(d.pnl_hourly);
  if(d.pnl_daily) renderDaily(d.pnl_daily);
  if(d.confidence_history) renderConf(d.confidence_history);
}
async function fetchLogs(){
  const d=await fetchWithRetry('_logs=1'); if(d&&d.lines) appendLogs(d.lines);
}
async function fetchMarket(){
  const d=await fetchWithRetry('_market=1');
  if(!d||!d.ok) return;
  const klines=d.klines||[];
  if(klines.length){
    const seen=new Set();
    const ohlc=klines.map(c=>({time:(c.t/1000)|0,open:+c.o,high:+c.h,low:+c.l,close:+c.c})).filter(c=>{if(seen.has(c.time))return false;seen.add(c.time);return true;}).sort((a,b)=>a.time-b.time);
    if(ohlc.length){
      if(!lwChart&&$('candleChart')){
        lwChart = LightweightCharts.createChart($('candleChart'),{
          width:$('candleChart').clientWidth||400,height:200,
          layout:{background:{type:'solid',color:'transparent'},textColor:'#7a99bb'},
          grid:{vertLines:{color:'rgba(26,37,53,.4)'},horzLines:{color:'rgba(26,37,53,.4)'}},
          crosshair:{mode:LightweightCharts.CrosshairMode.Normal},
          rightPriceScale:{borderColor:'rgba(26,37,53,.4)'},
          timeScale:{borderColor:'rgba(26,37,53,.4)',timeVisible:true,secondsVisible:false}
        });
        lwSeries = lwChart.addCandlestickSeries({upColor:'#00c97a',downColor:'#f03c52',borderVisible:false,wickUpColor:'#00c97a',wickDownColor:'#f03c52'});
        if($('candleChart')&&$('candleChart').clientWidth){
          new ResizeObserver(()=>{if($('candleChart').clientWidth>0&&lwChart)lwChart.applyOptions({width:$('candleChart').clientWidth});}).observe($('candleChart'));
        }
      }
      if(lwSeries){ try{lwSeries.setData(ohlc);}catch(e){} lastCandleTime=ohlc[ohlc.length-1].time; }
      const mn=Math.min(...ohlc.map(c=>c.low)),mx=Math.max(...ohlc.map(c=>c.high));
      $('mktRange').textContent = '↓$'+mn.toFixed(2)+' · ↑$'+mx.toFixed(2);
    }
  }
  renderIndicators(d);
  $('mktUpdTs').textContent = d.ts||'--';
}
async function fetchScalp(){
  const d=await fetchWithRetry('_scalp=1'); if(!d||!d.ok) return;
  if($('stFillH')) $('stFillH').textContent = d.fills_per_hour||'0';
  if($('stPnl1h')) $('stPnl1h').innerHTML = fM(d.pnl_1h);
  if($('kWin')) $('kWin').textContent = d.win_rate+'%';
  if($('stWr')) $('stWr').textContent = d.win_rate+'%';
  if($('kFillsH')) $('kFillsH').textContent = d.fills_24h+' fills hoy';
  if($('stFillsH')) $('stFillsH').textContent = d.fills_24h;
}
async function fetchUpnl(){
  const d=await fetchWithRetry('_pnl_float=1'); if(!d||!d.ok) return;
  const upnl = parseFloat(d.total_upnl)||0;
  const hasPos = d.positions&&d.positions.length>0;
  const chip=$('upnlChip'),chipVal=$('upnlChipVal'),box=$('upnlBox'),boxVal=$('upnlVal');
  if(chip){ if(hasPos||Math.abs(upnl)>0.0001){ chip.style.display='flex'; chipVal.innerHTML=fM(upnl); } else chip.style.display='none'; }
  if(box){ if(hasPos){ box.style.display='flex'; boxVal.innerHTML=fM(upnl); } else box.style.display='none'; }
  const pb=$('posBody');
  if(pb){
    if(hasPos){
      pb.innerHTML = d.positions.map(p=>{
        const amt=parseFloat(p.positionAmt);
        const side=amt>0?'BUY':'SELL';
        return '<tr><td><span class="badge '+(amt>0?'badge-green':'badge-red')+'">'+side+'</span></td><td>'+Math.abs(amt).toFixed(4)+'</td><td>'+fP(p.entryPrice)+'</td><td>'+fM(p.unRealizedProfit||0)+'</td><td style="color:var(--red)">'+fP(p.liquidationPrice)+'</td></tr>';
      }).join('');
    } else pb.innerHTML='<tr><td colspan="5" class="empty-state">Sin posición abierta</td></tr>';
  }
}
async function fetchMLInfo(){
  const d=await fetchWithRetry('_ml_info=1'); if(!d||!d.ok) return;
  if($('mlAccStat')) $('mlAccStat').textContent = ((d.accuracy||0)*100).toFixed(1)+'%';
  if($('mlFeatCount')) $('mlFeatCount').textContent = d.features||'--';
  if($('mlUpdated')) $('mlUpdated').textContent = (d.updated_at||'--').slice(0,16);
  const barDiv=$('mlFeatBars'); if(!barDiv) return;
  const imps=d.importances||{}; const keys=Object.keys(imps);
  if(!keys.length){ barDiv.innerHTML='<div style="color:var(--text-muted);font-size:9px;padding:10px;text-align:center">Sin datos</div>'; return; }
  const maxVal=Math.max(...Object.values(imps));
  barDiv.innerHTML = keys.map(k=>{
    const v=imps[k]; const pct=maxVal>0?v/maxVal*100:0;
    return '<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px"><span style="font-family:var(--font-mono);font-size:8px;color:var(--text-muted);width:90px;flex-shrink:0">'+k+'</span><div style="flex:1;height:6px;background:var(--border);border-radius:3px;overflow:hidden"><div style="height:100%;background:var(--accent);border-radius:3px;width:'+pct.toFixed(1)+'%;transition:width .5s"></div></div><span style="font-family:var(--font-mono);font-size:8px;color:var(--text-secondary);width:35px;text-align:right;flex-shrink:0">'+v.toFixed(3)+'</span></div>';
  }).join('');
}
function startPolling(){
  const iv=IV[SPEED];
  tickerTimer=setInterval(fetchTicker,iv.tick);
  statusTimer=setInterval(fetchStatus,iv.stat);
  logTimer=setInterval(fetchLogs,iv.log);
  mktTimer=setInterval(fetchMarket,iv.mkt);
  upnlTimer=setInterval(fetchUpnl,iv.upnl);
  scalpTimer=setInterval(fetchScalp,iv.scalp);
}

// ─── Init ───
(function(){
  const i=<?= json_encode($init) ?>;
  if(i && i.pnl_today!==undefined){
    $('kPnlH').innerHTML = fM(i.pnl_today);
    $('kPnlHP').textContent = (i.pnl_today/CAPITAL*100).toFixed(2)+'% capital';
    $('kFillsT').textContent = i.fills_total+' fills';
    $('kOpenO').textContent = i.open_orders+' órd. abiertas';
    $('stOpen').textContent = i.open_orders;
    $('stFills').textContent = i.fills_total;
    $('cNiv').textContent = i.levels;
    $('cLS').textContent = i.long_levels+' / '+i.short_levels;
    $('cSpc').textContent = (i.spacing_pct*100).toFixed(3)+'%';
    $('stRecov').textContent = i.recovery_active?'Sí 🔄':'No';
    $('stRecov2').textContent = i.recovery_active?'Sí 🔄':'No';
    setGauge(i.confidence, i.direction);
    $('gRsn').textContent = i.ai_reason;
    const mlAcc=i.ml_accuracy||0;
    if(mlAcc>0){ $('mlBadge').textContent='ML '+(mlAcc*100).toFixed(0)+'%'; $('cMlAcc').textContent=(mlAcc*100).toFixed(1)+'%'; }
  }
})();

// Notifications
if('Notification'in window&&Notification.permission!=='denied') Notification.requestPermission();

// WS + Polling
import('./dist/<?= str_replace(['../','./'],'',$jsFile) ?>').then(m => {
  if(m.initWs) m.initWs();
}).catch(() => {});

startPolling();
fetchTicker();
fetchStatus();
fetchMarket();
fetchUpnl();
fetchScalp();
setInterval(tickAI, 1000);
</script>
</body>
</html>
