<?php
/**
 * index.php v15.0 – Dashboard ETH/USDT Grid Bot
 * MEJORAS:
 *  - Cliente WebSocket en tiempo real (con reconexión)
 *  - Notificaciones desktop y toasts mejorados
 *  - Gráfico de velas corregido (resize, visibilidad)
 *  - Sincronización de datos en vivo
 *  - Compatibilidad con polling como fallback
 *  - Fills: caché WebSocket, carga inicial, paginación corregida
 */
error_reporting(0); ini_set('display_errors', '0');
// Buscar config.json: primero en private/ (fuera de HTTP), luego en public_html/
$_cfgOpts = [dirname(__DIR__) . '/private/config.json', __DIR__ . '/config.json'];
$cfgFile = null;
foreach ($_cfgOpts as $_opt) { if (@file_exists($_opt)) { $cfgFile = $_opt; break; } }
$cfg = ($cfgFile && file_exists($cfgFile)) ? json_decode(file_get_contents($cfgFile), true) : [];
function trimRecursive(array $arr): array {
    $out = [];
    foreach ($arr as $k => $v) {
        $tk = trim($k);
        $out[$tk] = is_array($v) ? trimRecursive($v) : (is_string($v) ? trim($v) : $v);
    }
    return $out;
}
$cfg = trimRecursive($cfg); $mc = $cfg['mysql'] ?? [];
define('EXPORT_TOKEN', getenv('SECURITY_TOKEN') ?: 'g273f123');
$AI_INT   = (int)($cfg['bot']['ai_interval_sec'] ?? 120);
$CAPITAL  = (int)($cfg['bot']['capital_usd']     ?? 20);
$LEVERAGE = (int)($cfg['bot']['leverage']        ?? 100);

if (isset($_GET['export_pnl'])) {
    if (!isset($_GET['token']) || $_GET['token'] !== EXPORT_TOKEN) { http_response_code(403); exit("Acceso denegado"); }
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
        $init  = ['pnl_today'=>(float)($pnlT['p']??0), 'fills_total'=>(int)($fills['c']??0), 'open_orders'=>$openO,
            'direction'=>$row['direction']??'SIDEWAYS', 'confidence'=>(int)($row['confidence']??50),
            'ai_reason'=>$row['ai_reason']??'Evaluando...', 'levels'=>(int)($row['levels']??8),
            'long_levels'=>(int)($row['long_levels']??4), 'short_levels'=>(int)($row['short_levels']??4),
            'spacing_pct'=>(float)($row['spacing_pct']??0.0008), 'recovery_active'=>(bool)($row['recovery_active']??false),
            'capital'=>$CAPITAL, 'ml_accuracy' => $mlAcc];
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
<title>ETH/USDT · Grid Bot v15.0 · Tiempo Real</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://unpkg.com/lightweight-charts@4.1.1/dist/lightweight-charts.standalone.production.js"></script>
<style>
:root{
  --bg:#06080e;--bg2:#0b0f18;--bg3:#10151f;--bg4:#141b26;
  --border:#1a2535;--border2:#243448;
  --text:#c8daf0;--muted:#3a5270;--dim:#7a99bb;
  --accent:#2d8cff;--acc2:#1a6fdd;--acc-g:rgba(45,140,255,.12);
  --green:#00c97a;--gn-g:rgba(0,201,122,.1);--gn-s:rgba(0,201,122,.4);
  --red:#f03c52;--rd-g:rgba(240,60,82,.1);--rd-s:rgba(240,60,82,.4);
  --yellow:#f5a623;--yl-g:rgba(245,166,35,.1);
  --purple:#9b72f5;--cyan:#00d4cc;
  --mono:'JetBrains Mono',monospace;--sans:'Inter',system-ui,sans-serif;
  --r:10px;--r2:6px;--sh:0 4px 24px rgba(0,0,0,.4);
  --drawer-width:280px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden}
body{background:var(--bg);color:var(--text);font-family:var(--sans);font-size:13px}
::-webkit-scrollbar{width:3px;height:3px}::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px}
#ldr{position:fixed;inset:0;z-index:9999;background:var(--bg);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:20px;transition:opacity .6s}
#ldr.hidden{opacity:0;pointer-events:none}
.ldr-logo{width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--accent),var(--purple));display:grid;place-items:center;font-size:24px;box-shadow:0 0 30px rgba(45,140,255,.4);animation:ldr-pulse 1.5s ease-in-out infinite}
@keyframes ldr-pulse{0%,100%{box-shadow:0 0 20px rgba(45,140,255,.3)}50%{box-shadow:0 0 40px rgba(45,140,255,.6)}}
.ldr-bar{width:180px;height:2px;background:var(--border);border-radius:2px;overflow:hidden}
.ldr-prog{height:100%;background:linear-gradient(90deg,var(--accent),var(--purple));border-radius:2px;animation:ldr-slide 1.5s ease-in-out infinite}
@keyframes ldr-slide{0%{width:0;margin-left:0}50%{width:60%;margin-left:20%}100%{width:0;margin-left:100%}}
.ldr-txt{font-family:var(--mono);font-size:10px;color:var(--muted);letter-spacing:4px;text-transform:uppercase}
.app{display:flex;flex-direction:column;height:100vh}
.topbar{background:rgba(6,8,14,.98);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 12px;gap:8px;height:50px;flex-shrink:0;z-index:200}
.main-grid{display:flex;flex:1;overflow:hidden;position:relative}
.sidebar-left{position:fixed;top:50px;left:-100%;width:var(--drawer-width);height:calc(100% - 50px);background:var(--bg2);border-right:1px solid var(--border);transition:left .3s ease;z-index:150;overflow-y:auto;display:flex;flex-direction:column;gap:1px}
.sidebar-left.open{left:0}
.drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:140;display:none}
.drawer-overlay.active{display:block}
.center-col{flex:1;overflow-y:auto;background:var(--bg);display:flex;flex-direction:column;gap:1px}
.sidebar-right{width:300px;background:var(--bg2);border-left:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;transition:transform .2s}
@media(max-width:900px){
  .sidebar-right{position:fixed;right:0;top:50px;height:calc(100% - 50px);width:85%;max-width:320px;z-index:160;transform:translateX(100%);box-shadow:-2px 0 12px rgba(0,0,0,.4)}
  .sidebar-right.open{transform:translateX(0)}
}
.menu-btn{background:transparent;border:none;color:var(--dim);font-size:20px;cursor:pointer;padding:6px;margin-right:4px;display:flex;align-items:center}
.menu-btn:hover{color:var(--accent)}
.brand{display:flex;align-items:center;gap:8px;flex-shrink:0}
.brand-icon{width:28px;height:28px;border-radius:7px;background:linear-gradient(135deg,var(--accent),var(--purple));display:grid;place-items:center;font-size:14px}
.brand-name{font-size:12px;font-weight:700;color:#fff}
.brand-sub{font-family:var(--mono);font-size:8px;color:var(--muted);margin-top:1px}
.tb-sep{width:1px;height:28px;background:var(--border);margin:0 4px}
.ticker-block{display:flex;align-items:center;gap:10px;flex:1;flex-wrap:wrap}
.price-live{font-family:var(--mono);font-size:18px;font-weight:600;color:#fff}
@media(min-width:600px){.price-live{font-size:20px}}
.price-chg{font-family:var(--mono);font-size:10px;padding:2px 7px;border-radius:4px;font-weight:600}
.up{background:var(--gn-g);color:var(--green)}.dn{background:var(--rd-g);color:var(--red)}.ntr{background:var(--acc-g);color:var(--accent)}
.price-meta{font-family:var(--mono);font-size:9px;color:var(--muted);line-height:1.8}
.bid-ask{display:flex;gap:6px;align-items:center}
.bid{font-family:var(--mono);font-size:10px;color:var(--green)}.ask{font-family:var(--mono);font-size:10px;color:var(--red)}
.spread{font-family:var(--mono);font-size:9px;color:var(--muted)}
.status-block{display:flex;align-items:center;gap:8px;margin-left:auto;flex-shrink:0}
.live-pill{display:flex;align-items:center;gap:6px;background:var(--bg3);border:1px solid var(--border);border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600}
.dot{width:7px;height:7px;border-radius:50%;background:var(--muted);flex-shrink:0;transition:.3s}
.dot.active{background:var(--green);box-shadow:0 0 6px var(--green);animation:dpulse 2s infinite}
.dot.stale{background:var(--muted);box-shadow:none;animation:none}
@keyframes dpulse{0%,100%{opacity:1}50%{opacity:.4}}
.uptime{font-family:var(--mono);font-size:9px;color:var(--muted)}
.last-upd{font-family:var(--mono);font-size:9px;color:var(--muted);background:var(--bg3);padding:2px 7px;border-radius:10px;border:1px solid var(--border)}
.btns{display:flex;gap:5px;flex-shrink:0}
.btn{border:1px solid var(--border2);background:transparent;color:var(--dim);font-family:var(--sans);font-size:11px;font-weight:600;padding:4px 9px;border-radius:6px;cursor:pointer;transition:.15s;display:flex;align-items:center;gap:4px;white-space:nowrap}
.btn:hover{background:var(--bg3)}
.btn-b{color:var(--accent);border-color:rgba(45,140,255,.3)}.btn-b:hover{background:var(--acc-g);border-color:var(--accent)}
.btn-r{color:var(--red);border-color:rgba(240,60,82,.3)}.btn-r:hover{background:var(--rd-g);border-color:var(--red)}
.btn-g{color:var(--green);border-color:rgba(0,201,122,.3)}.btn-g:hover{background:var(--gn-g);border-color:var(--green)}
.mode-badge{font-size:8px;font-weight:700;padding:2px 7px;border-radius:4px;text-transform:uppercase;letter-spacing:.6px}
.m-NORMAL{background:var(--gn-g);color:var(--green)}.m-RECOVERY{background:var(--yl-g);color:var(--yellow)}.m-grid-off{background:var(--rd-g);color:var(--red)}
.ml-badge{font-size:8px;font-weight:700;padding:2px 7px;border-radius:4px;font-family:var(--mono);background:var(--acc-g);color:var(--accent);border:1px solid rgba(45,140,255,.2)}
.mkt-analysis{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;padding:8px 12px}
.mkt-cell{background:var(--bg3);border:1px solid var(--border);border-radius:var(--r2);padding:7px 10px}
.mkt-lbl{font-size:8px;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px}
.mkt-val{font-family:var(--mono);font-size:13px;font-weight:600;line-height:1}
.mkt-sub{font-size:8px;color:var(--muted);margin-top:3px}
.rsi-track{height:5px;background:var(--border);border-radius:3px;margin-top:5px;position:relative;overflow:hidden}
.rsi-zone-ob{position:absolute;right:0;top:0;height:100%;width:30%;background:rgba(240,60,82,.15)}
.rsi-zone-os{position:absolute;left:0;top:0;height:100%;width:30%;background:rgba(0,201,122,.15)}
.rsi-fill{position:absolute;top:0;height:100%;background:var(--accent);border-radius:3px;transition:width .5s}
.rsi-dot{position:absolute;top:50%;transform:translateY(-50%);width:7px;height:7px;border-radius:50%;background:#fff;margin-left:-3px;transition:left .5s;box-shadow:0 0 4px rgba(255,255,255,.6)}
.macd-hist-bar{height:4px;border-radius:2px;margin-top:5px;transition:all .4s}
.funding-positive{color:var(--red)}.funding-negative{color:var(--green)}
.upnl-chip{display:flex;align-items:center;gap:5px;background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:3px 9px;font-family:var(--mono);font-size:11px;font-weight:600;transition:all .3s}
.pnl-cum-block{border-top:1px solid var(--border);padding:0}
.pnl-cum-hd{padding:6px 12px;background:var(--bg3);display:flex;align-items:center;justify-content:space-between;font-size:9px;color:var(--muted);letter-spacing:.6px;font-weight:700;text-transform:uppercase}
.pnl-cum-wrap{height:80px;padding:4px 8px 6px}
.card{background:var(--bg2);border-bottom:1px solid var(--border)}
.card-hd{padding:8px 13px;background:var(--bg3);border-bottom:1px solid var(--border);font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.9px;display:flex;align-items:center;justify-content:space-between}
.card-hd b{color:var(--text);font-size:10px}
.card-bd{padding:10px 12px}
.kpi-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;padding:10px 12px}
.kpi{background:var(--bg3);border:1px solid var(--border);border-radius:var(--r2);padding:9px 10px;position:relative;overflow:hidden;cursor:default}
.kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;border-radius:2px 2px 0 0}
.kpi.pos::before{background:linear-gradient(90deg,var(--green),transparent)}
.kpi.neg::before{background:linear-gradient(90deg,var(--red),transparent)}
.kpi.neu::before{background:linear-gradient(90deg,var(--accent),transparent)}
.kpi.yl::before{background:linear-gradient(90deg,var(--yellow),transparent)}
.kpi-lbl{font-size:8px;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:5px}
.kpi-val{font-family:var(--mono);font-size:17px;font-weight:600;line-height:1;transition:all .3s}
.kpi-sub{font-size:8px;color:var(--muted);margin-top:4px}
.c-pos{color:var(--green)}.c-neg{color:var(--red)}.c-neu{color:var(--accent)}.c-yl{color:var(--yellow)}.c-dim{color:var(--dim)}
.gauge-wrap{padding:10px 12px 4px}
.gauge-arc{position:relative;width:140px;height:72px;margin:0 auto 4px}
.gauge-arc svg{width:100%;height:100%}
.g-bg-arc{fill:none;stroke:var(--border2);stroke-width:8;stroke-linecap:round}
.g-fill-arc{fill:none;stroke:var(--accent);stroke-width:8;stroke-linecap:round;transition:stroke-dashoffset .7s cubic-bezier(.4,0,.2,1),stroke .4s}
.gauge-center{position:absolute;bottom:0;left:50%;transform:translateX(-50%);text-align:center}
.gauge-pct{font-family:var(--mono);font-size:16px;font-weight:600;line-height:1}
.gauge-dir-lbl{font-size:11px;font-weight:700;margin-top:2px;letter-spacing:.3px}
.gauge-reason{font-size:9px;color:var(--muted);text-align:center;padding:0 12px 10px;line-height:1.5;word-break:break-word}
.gauge-ticks{display:flex;justify-content:space-between;padding:0 18px;font-family:var(--mono);font-size:8px;color:var(--muted)}
.ai-bar-wrap{padding:0 12px 10px}
.ai-hd{display:flex;justify-content:space-between;font-size:9px;color:var(--muted);margin-bottom:5px}
.ai-track{height:3px;background:var(--border);border-radius:3px;overflow:hidden}
.ai-fill{height:100%;background:var(--accent);border-radius:3px;width:0%;transition:width 1s linear,background .3s}
.cfg-grid{display:grid;grid-template-columns:auto 1fr;gap:1px 0;padding:0 12px 10px}
.cfg-k{font-family:var(--mono);font-size:9px;color:var(--muted);padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)}
.cfg-v{font-family:var(--mono);font-size:9px;color:var(--text);font-weight:600;text-align:right;padding:3px 0;border-bottom:1px solid rgba(26,37,53,.5)}
.chart-sect{padding:0;position:relative}
.chart-hd{padding:8px 13px;background:var(--bg3);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.9px}
.chart-hd b{color:var(--text);font-size:10px}
#candleChart{width:100%;height:200px}
.conf-chart-wrap{height:80px;padding:4px 12px 8px}
.pnl-charts{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--border)}
.pnl-chart-block{background:var(--bg2)}
.pnl-chart-hd{padding:6px 12px;background:var(--bg3);display:flex;align-items:center;justify-content:space-between;font-size:9px;color:var(--muted);letter-spacing:.6px;font-weight:700;text-transform:uppercase}
.pnl-chart-wrap{height:90px;padding:4px 8px 6px}
.ladder-wrap{flex:1;overflow-y:auto;display:flex;flex-direction:column}
.ladder-row{display:grid;grid-template-columns:70px 1fr 70px;align-items:center;gap:4px;padding:1px 10px;min-height:20px;position:relative;transition:background .15s}
.ladder-row:hover{background:rgba(45,140,255,.04)}
.ladder-row.current-price-row{background:var(--bg4);border-top:1px solid rgba(45,140,255,.3);border-bottom:1px solid rgba(45,140,255,.3);min-height:24px}
.lr-price{font-family:var(--mono);font-size:9px;font-weight:600;text-align:right}
.lr-bar-wrap{position:relative;height:10px;border-radius:2px;overflow:hidden}
.lr-bar{position:absolute;top:0;height:100%;border-radius:2px;transition:width .4s}
.lr-bar.buy{background:var(--gn-s);right:0}.lr-bar.sell{background:var(--rd-s);left:0}
.lr-qty{font-family:var(--mono);font-size:8px;color:var(--muted);text-align:left}
.lr-price.buy{color:var(--green)}.lr-price.sell{color:var(--red)}.lr-price.cur{color:var(--accent);font-size:10px}
.ladder-hd{display:grid;grid-template-columns:70px 1fr 70px;gap:4px;padding:4px 10px;background:var(--bg3);border-bottom:1px solid var(--border);font-size:8px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.5px}
.ladder-hd span:nth-child(1){text-align:right}.ladder-hd span:nth-child(3){text-align:left}
.empty-ladder{padding:20px;text-align:center;font-size:10px;color:var(--muted)}
.tabs-hd{display:flex;background:var(--bg3);border-bottom:1px solid var(--border);flex-shrink:0;overflow-x:auto}
.tab-btn{flex:1;min-width:60px;padding:9px 4px;font-size:9px;font-weight:600;color:var(--muted);background:transparent;border:none;border-bottom:2px solid transparent;cursor:pointer;transition:.15s;font-family:var(--sans);letter-spacing:.3px;white-space:nowrap}
.tab-btn.active{color:var(--accent);border-bottom-color:var(--accent);background:rgba(45,140,255,.04)}
.tab-btn:hover:not(.active){color:var(--text)}
.tab-panels{flex:1;overflow:hidden;position:relative}
.tab-panel{position:absolute;inset:0;overflow-y:auto;display:none}
.tab-panel.active{display:block}
.stat-section{padding:10px 12px 4px}
.stat-title{font-size:8px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:6px}
.stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:4px;margin-bottom:8px}
.stat-cell{background:var(--bg3);border:1px solid var(--border);border-radius:var(--r2);padding:7px 9px}
.stat-lbl{font-size:8px;color:var(--muted);margin-bottom:3px}
.stat-val{font-family:var(--mono);font-size:12px;font-weight:600}
.pos-table-wrap{padding:8px 12px}
.no-data{text-align:center;padding:20px;font-size:10px;color:var(--muted)}
.fills-hd{display:flex;align-items:center;justify-content:space-between;padding:8px 12px 4px}
.fills-cnt{font-size:9px;font-family:var(--mono);padding:1px 7px;border-radius:4px;background:var(--acc-g);color:var(--accent);font-weight:700}
.tbl-wrap{overflow-x:auto;padding:0 4px}
table{width:100%;border-collapse:collapse;font-family:var(--mono);font-size:9px}
th{position:sticky;top:0;z-index:2;background:rgba(11,15,24,.97);color:var(--muted);font-family:var(--sans);font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:5px 8px;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap}
td{padding:4px 8px;border-bottom:1px solid rgba(26,37,53,.4);white-space:nowrap;color:var(--text)}
tr:hover td{background:rgba(45,140,255,.03)}.tr{text-align:right}
.badge{padding:1px 5px;border-radius:3px;font-size:8px;font-weight:700;font-family:var(--sans);text-transform:uppercase}
.b-buy{background:var(--gn-g);color:var(--green)}.b-sell{background:var(--rd-g);color:var(--red)}
.b-neu{background:var(--acc-g);color:var(--accent)}.b-yl{background:var(--yl-g);color:var(--yellow)}
.b-rec{background:var(--yl-g);color:var(--yellow);font-size:7px}
.log-box{height:100%;overflow-y:auto;font-family:var(--mono);font-size:9px;line-height:1.9;padding:6px 10px}
.ll{display:flex;gap:6px;padding:1px 0}
.ll:hover{background:rgba(45,140,255,.03)}
.lt{color:var(--muted);flex-shrink:0;font-size:8px}.li{color:var(--accent);flex-shrink:0;font-weight:600}
.lw{color:var(--yellow);flex-shrink:0;font-weight:600}.le{color:var(--red);flex-shrink:0;font-weight:600}
.lm{color:var(--dim);word-break:break-all}
.log-toolbar{display:flex;align-items:center;gap:6px;padding:6px 10px;background:var(--bg3);border-bottom:1px solid var(--border);flex-shrink:0}
.log-search{flex:1;background:var(--bg);border:1px solid var(--border2);border-radius:4px;padding:3px 7px;font-family:var(--mono);font-size:9px;color:var(--text);outline:none}
.log-search:focus{border-color:var(--accent)}
.log-container{display:flex;flex-direction:column;height:100%}
.ml-bar-wrap{padding:6px 12px}
.ml-feat-row{display:flex;align-items:center;gap:6px;margin-bottom:4px}
.ml-feat-name{font-family:var(--mono);font-size:8px;color:var(--muted);width:90px;flex-shrink:0}
.ml-feat-bar-bg{flex:1;height:6px;background:var(--border);border-radius:3px;overflow:hidden}
.ml-feat-bar{height:100%;background:var(--accent);border-radius:3px;transition:width .5s}
.ml-feat-val{font-family:var(--mono);font-size:8px;color:var(--dim);width:35px;text-align:right;flex-shrink:0}
#toasts{position:fixed;bottom:20px;right:20px;z-index:9000;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.toast{background:var(--bg3);border:1px solid var(--border2);border-radius:var(--r);padding:10px 14px;display:flex;align-items:center;gap:10px;box-shadow:var(--sh);animation:toast-in .3s ease;max-width:280px;pointer-events:all}
.toast.out{animation:toast-out .3s ease forwards}
@keyframes toast-in{from{transform:translateX(100%);opacity:0}to{transform:none;opacity:1}}
@keyframes toast-out{to{transform:translateX(110%);opacity:0}}
.toast-icon{font-size:18px;flex-shrink:0}
.toast-body{flex:1;min-width:0}
.toast-title{font-size:11px;font-weight:700;color:#fff;margin-bottom:2px}
.toast-msg{font-family:var(--mono);font-size:10px;color:var(--dim)}
.toast.fill-pos{border-color:rgba(0,201,122,.4)}.toast.fill-neg{border-color:rgba(240,60,82,.4)}
.toast-close{background:none;border:none;color:var(--muted);cursor:pointer;font-size:14px;padding:0;flex-shrink:0}
@keyframes fup{0%,100%{color:#fff}40%{color:var(--green)}}
@keyframes fdn{0%,100%{color:#fff}40%{color:var(--red)}}
.fup{animation:fup .5s ease}.fdn{animation:fdn .5s ease}
.upnl-float{margin:0 12px 8px;background:var(--bg3);border:1px solid var(--border);border-radius:var(--r2);padding:7px 10px;display:none;align-items:center;justify-content:space-between}
.upnl-lbl{font-size:8px;color:var(--muted);text-transform:uppercase;letter-spacing:.6px}
.upnl-val{font-family:var(--mono);font-size:14px;font-weight:600}
.grid-status-bar{display:flex;align-items:center;gap:5px;padding:3px 12px 6px;font-size:8px;font-family:var(--mono)}
.gs-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.gs-dot.on{background:var(--green);box-shadow:0 0 5px var(--green)}
.gs-dot.off{background:var(--red)}
.fills-pg{display:flex;gap:5px;align-items:center;padding:4px 12px 8px}
.fills-pg button{font-size:9px;padding:2px 7px}
</style>
</head>
<body>
<div id="ldr">
  <div class="ldr-logo">⚡</div>
  <div class="ldr-bar"><div class="ldr-prog"></div></div>
  <div class="ldr-txt">Grid Bot v15.0 · Iniciando</div>
</div>

<div class="app">
  <nav class="topbar">
    <button class="menu-btn" id="menuToggle" aria-label="Menú">☰</button>
    <div class="brand">
      <div class="brand-icon">⚡</div>
      <div>
        <div class="brand-name">ETH/USDT GRID</div>
        <div class="brand-sub">BYBIT · <?= $LEVERAGE ?>× · <?= $CAPITAL ?> USDT · v15.0</div>
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
      <button class="btn btn-b" onclick="toggleSpeed()" id="speedBtn">⚡ Rápido</button>
      <button class="btn btn-b" onclick="cmd('force_ai')">🧠 IA</button>
      <button class="btn btn-g" onclick="cmd('reset_grid')">↻ Grid</button>
      <button class="btn btn-b" onclick="exportPnl()">📥</button>
      <button class="btn btn-r" onclick="cmd('stop')">■ Stop</button>
    </div>
  </nav>

  <div class="main-grid">
    <div class="sidebar-left" id="sidebarLeft">
      <div class="kpi-grid">
        <div class="kpi pos" id="kpiPnlH">
          <div class="kpi-lbl">PnL Hoy</div>
          <div class="kpi-val c-pos" id="kPnlH">--</div>
          <div class="kpi-sub" id="kPnlHP">0.00% capital</div>
        </div>
        <div class="kpi neu">
          <div class="kpi-lbl">PnL Total</div>
          <div class="kpi-val" id="kPnlT">--</div>
          <div class="kpi-sub" id="kFillsT">-- fills</div>
        </div>
        <div class="kpi neu">
          <div class="kpi-lbl">Win Rate</div>
          <div class="kpi-val c-neu" id="kWin">--%</div>
          <div class="kpi-sub" id="kFillsH">-- fills hoy</div>
        </div>
        <div class="kpi yl">
          <div class="kpi-lbl">Uptime</div>
          <div class="kpi-val c-yl" id="kUpt">--</div>
          <div class="kpi-sub" id="kOpenO">-- órd. abiertas</div>
        </div>
      </div>
      <div class="upnl-float" id="upnlBox">
        <div><div class="upnl-lbl">uPnL Posición</div><div class="upnl-val" id="upnlVal">--</div></div>
        <span>💰</span>
      </div>
      <div class="grid-status-bar">
        <span class="gs-dot" id="gridDot"></span>
        <span id="gridStatusTxt" style="color:var(--muted)">Grid --</span>
        <span style="color:var(--muted);margin-left:auto">Ciclo <span id="cycleN" style="color:var(--dim)">--</span></span>
      </div>
      <div class="card">
        <div class="card-hd"><b>Señal IA · ML v15.0</b><span id="aiEngBadge" style="font-family:var(--mono);font-size:8px">--</span></div>
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
        <div class="ai-bar-wrap">
          <div class="ai-hd"><span>⏳ Próxima eval. IA</span><span id="aiSec">--s</span></div>
          <div class="ai-track"><div class="ai-fill" id="aiBar"></div></div>
        </div>
      </div>
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

    <div class="center-col" id="centerCol">
      <div class="chart-sect card">
        <div class="chart-hd">
          <b>ETH/USDT · 5m · Bybit</b>
          <span id="mktRange" style="color:var(--dim);font-size:9px"></span>
        </div>
        <div id="candleChart"></div>
      </div>
      <div class="card">
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
      <div class="pnl-charts card">
        <div class="pnl-chart-block"><div class="pnl-chart-hd"><span>PnL Horario 48h</span><span id="hTot" style="font-family:var(--mono);font-size:9px"></span></div><div class="pnl-chart-wrap"><canvas id="hChart"></canvas></div></div>
        <div class="pnl-chart-block" style="border-left:1px solid var(--border)"><div class="pnl-chart-hd"><span>PnL Diario 14d</span><span id="dTot" style="font-family:var(--mono);font-size:9px"></span></div><div class="pnl-chart-wrap"><canvas id="dChart"></canvas></div></div>
      </div>
      <div class="card pnl-cum-block"><div class="pnl-cum-hd"><span>PnL Acumulado</span><span id="cumTot" style="font-family:var(--mono);font-size:9px"></span></div><div class="pnl-cum-wrap"><canvas id="cumChart"></canvas></div></div>
      <div class="card" style="flex:1;display:flex;flex-direction:column;min-height:240px">
        <div class="chart-hd"><b>Order Ladder</b><span id="ladderPx" style="font-family:var(--mono);font-size:10px;color:var(--accent)">$0.00</span></div>
        <div class="ladder-hd"><span>Precio</span><span style="text-align:center">Qty</span><span>Rol</span></div>
        <div class="ladder-wrap" id="ladderWrap"><div class="empty-ladder">Sin órdenes activas</div></div>
      </div>
    </div>

    <div class="sidebar-right" id="sidebarRight">
      <div class="tabs-hd">
        <button class="tab-btn active" onclick="switchTab('stats',this)">Stats</button>
        <button class="tab-btn" onclick="switchTab('positions',this)">Posic.</button>
        <button class="tab-btn" onclick="switchTab('fills',this)">Fills</button>
        <button class="tab-btn" onclick="switchTab('ml',this)">ML</button>
        <button class="tab-btn" onclick="switchTab('log',this)">Log</button>
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
            <div class="tbl-wrap"><table><thead><tr><th>Lado</th><th>Qty</th><th>Entry $</th><th>uPnL</th><th>Liq $</th></tr></thead><tbody id="posBody"><tr><td colspan="5" class="no-data">Sin posición abierta</td></tr>
</tbody></table></div>
          </div>
        </div>
        <div class="tab-panel" id="tab-fills">
          <div class="fills-hd"><span>Últimos Fills</span><span class="fills-cnt" id="fillCnt">0</span></div>
          <div class="tbl-wrap"><table><thead><tr><th>Hora</th><th>Lado</th><th>Rol</th><th class="tr">PnL</th><th>Price</th><th>R</th></tr></thead><tbody id="fillBody"><tr><td colspan="6" class="no-data">Sin historial</td></tr>
</tbody></table></div>
          <div class="fills-pg">
            <button class="btn" onclick="fillsPrev()">◀</button>
            <span id="fillsPage" style="font-family:var(--mono);font-size:9px;color:var(--muted)">1/1</span>
            <button class="btn" onclick="fillsNext()">▶</button>
            <button class="btn btn-b" onclick="loadFillsHistory()" style="margin-left:auto">🔄 Historial</button>
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
              <button class="btn" onclick="logPaused=!logPaused;this.style.color=logPaused?'var(--yellow)':''" title="Pausar scroll" style="font-size:9px;padding:3px 7px">⏸</button>
            </div>
            <div class="log-box" id="logBox"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<div id="toasts"></div>

<script>
const API = './grid_ajax.php';
const AI_INT = <?= $AI_INT ?>;
const CAPITAL_CFG = <?= $CAPITAL ?>;
let SPEED = 'fast';
const IV = { fast:{tick:1000,stat:3000,log:4000,mkt:30000,upnl:2500,scalp:15000}, normal:{tick:2000,stat:5000,log:8000,mkt:60000,upnl:5000,scalp:30000} };
let charts = {};
let lastPrice=0, lastAICheck=null, loaded=false, logPaused=false, lastStatUpdate=0;
let CAPITAL = CAPITAL_CFG;
let tickerTimer, statusTimer, logTimer, mktTimer, upnlTimer, scalpTimer;
let lastFillIds=new Set(), allLogLines=[], logFilter='';
let lwChart=null, lwSeries=null, lastCandleTime=0;
let fillsOffset=0, fillsTotal=0, fillsLimit=40;

// WebSocket globals
let ws = null;
let wsReconnectTimer = null;
let wsInitialDataReceived = false;
let lastDirection = null;
let lastRecentFillsCache = []; // caché de los últimos fills recibidos por WS

const $ = id => document.getElementById(id);
const fP = (v,d=2) => '$'+parseFloat(v||0).toFixed(d);
function fM(v,d=4){
  v=parseFloat(v||0); if(isNaN(v)) return '<span style="color:var(--muted)">--</span>';
  const cls=v>0?'c-pos':v<0?'c-neg':'c-dim';
  return `<span class="${cls}">${v>0?'+':''}${v.toFixed(d)}</span>`;
}
function hideLdr(){$('ldr').classList.add('hidden');loaded=true;}
function markUpdate(){lastStatUpdate=Date.now();$('lastUpdate').textContent='ahora';$('liveIndicator').classList.remove('stale');}
setInterval(()=>{
  const s=Math.floor((Date.now()-lastStatUpdate)/1000);
  $('lastUpdate').textContent=s<=0?'ahora':`hace ${s}s`;
  if(s>8)$('liveIndicator').classList.add('stale');
},1000);

// ==================== WEBSOCKET CLIENT ====================
function connectWebSocket() {
    const token = '<?= EXPORT_TOKEN ?>';
    const protocol = location.protocol === 'https:' ? 'wss:' : 'ws:';
    const wsUrl = `${protocol}//${location.hostname}:8082?token=${token}`;
    ws = new WebSocket(wsUrl);
    ws.onopen = () => {
        console.log('[WS] Conectado');
        if (wsReconnectTimer) clearTimeout(wsReconnectTimer);
        const ind = $('wsIndicator');
        if (ind) ind.style.background = 'var(--green)';
    };
    ws.onmessage = (e) => {
        try {
            const data = JSON.parse(e.data);
            if (!wsInitialDataReceived) {
                wsInitialDataReceived = true;
                console.log('[WS] Datos iniciales recibidos');
            }
            updateUIFromWebSocket(data);
        } catch (err) { console.warn('[WS] parse error', err); }
    };
    ws.onerror = (err) => {
        console.warn('[WS] Error:', err);
        const ind = $('wsIndicator');
        if (ind) ind.style.background = 'var(--red)';
    };
    ws.onclose = () => {
        console.log('[WS] Desconectado, reconectando en 3s...');
        const ind = $('wsIndicator');
        if (ind) ind.style.background = 'var(--muted)';
        wsReconnectTimer = setTimeout(connectWebSocket, 3000);
    };
}

function updateUIFromWebSocket(data) {
    if (data.ticker) updateTickerUI(data.ticker);
    if (data.bot_running !== undefined) setBotStatus(data.bot_running);
    if (data.uptime) {
        $('kUpt').textContent = data.uptime;
        $('uptTxt').textContent = data.uptime;
    }
    if (data.mode) {
        $('modeBadge').textContent = data.mode;
        $('modeBadge').className = `mode-badge m-${data.mode}`;
    }
    if (data.pair) {
        updatePairUI(data.pair);
        updatePairNumbers(data.pair);
        if (data.pair.open_entries !== undefined && data.pair.open_exits !== undefined) {
            $('cEnt').textContent = data.pair.open_entries;
            $('cSal').textContent = data.pair.open_exits;
        }
        if (lastDirection !== null && data.pair.direction !== lastDirection) {
            toast('Cambio de dirección IA', `Nueva dirección: ${data.pair.direction} (confianza ${data.pair.confidence}%)`, 'info');
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification('Grid Bot - Cambio de dirección', { body: `Nueva dirección: ${data.pair.direction} (confianza ${data.pair.confidence}%)`, icon: '/favicon.ico' });
            }
        }
        lastDirection = data.pair.direction;
    }
    if (data.win_rate !== undefined) { $('kWin').textContent = data.win_rate + '%'; $('stWr').textContent = data.win_rate + '%'; }
    if (data.open_orders !== undefined) $('stOpen').textContent = data.open_orders;
    if (data.orders) updateLadder(data.orders);
    if (data.recent_fills) updateRecentFillsFromWS(data.recent_fills);
    if (data.pnl_hourly) renderHourly(data.pnl_hourly);
    if (data.pnl_cumulative) renderCumulativeFromWS(data.pnl_cumulative);
    if (data.positions) updatePositionsUI(data.positions, data.total_upnl, data.real_balance);
    if (data.logs) appendLogsFromWS(data.logs);
    if (data.confidence_history) renderConf(data.confidence_history);
    markUpdate();
}

function renderFillsTable(fills) {
    const fb = $('fillBody');
    if (!fb) return;
    if (!fills.length) {
        fb.innerHTML = '<tr><td colspan="6" class="no-data">Sin historial</td></tr>';
        return;
    }
    fb.innerHTML = fills.map(f => {
        const bc = f.side === 'BUY' ? 'b-buy' : 'b-sell';
        const rec = f.is_recovery ? '<span class="badge b-rec">R</span>' : '';
        return `<tr>
            <td style="color:var(--muted)">${(f.filled_at || '').slice(11,19)}</td>
            <td><span class="badge ${bc}">${f.side}</span></td>
            <td style="color:var(--muted)">${f.grid_role || ''}</td>
            <td class="tr">${fM(f.pnl_usd || 0)}</td>
            <td style="color:var(--dim)">${fP(f.exit_price || f.price || 0)}</td>
            <td>${rec}</td>
        </tr>`;
    }).join('');
}

function updateRecentFillsFromWS(fills) {
    if (!fills || !fills.length) return;
    // Guardar en caché
    lastRecentFillsCache = fills;
    const fb = $('fillBody');
    const isActive = $('tab-fills').classList.contains('active');
    if (fb && isActive && fillsOffset === 0) {
        renderFillsTable(fills.slice(0, fillsLimit));
        $('fillCnt').textContent = fills.length;
    }
    fills.forEach(f => {
        const id = `${f.filled_at}_${f.side}_${f.pnl_usd}`;
        if (!lastFillIds.has(id) && lastFillIds.size > 0 && f.grid_role === 'EXIT') {
            const pnl = parseFloat(f.pnl_usd || 0);
            toast('Fill completado', `${f.side} EXIT · PnL: ${pnl>=0?'+':''}${pnl.toFixed(4)} USDT`, pnl >= 0 ? 'fill_pos' : 'fill_neg');
            if ('Notification' in window && Notification.permission === 'granted' && Math.abs(pnl) > 0.3) {
                new Notification('Fill completado', { body: `${f.side} EXIT · PnL: ${pnl>=0?'+':''}${pnl.toFixed(4)} USDT`, icon: '/favicon.ico' });
            }
        }
        lastFillIds.add(id);
        if (lastFillIds.size > 200) lastFillIds.delete(lastFillIds.values().next().value);
    });
}

function updatePositionsUI(positions, totalUpnl, realBalance) {
    const pb = $('posBody');
    if (pb) {
        if (positions && positions.length > 0) {
            pb.innerHTML = positions.map(p => {
                const amt = parseFloat(p.positionAmt);
                const side = amt > 0 ? 'BUY' : 'SELL';
                return `<tr>
                    <td><span class="badge ${amt>0?'b-buy':'b-sell'}">${side}</span></td>
                    <td>${Math.abs(amt).toFixed(4)}</td>
                    <td>${fP(p.entryPrice)}</td>
                    <td>${fM(p.unRealizedProfit||0)}</td>
                    <td style="color:var(--red)">${fP(p.liquidationPrice)}</td>
                </tr>`;
            }).join('');
        } else {
            pb.innerHTML = '<tr><td colspan="5" class="no-data">Sin posición abierta</td></tr>';
        }
    }
    const chip = $('upnlChip');
    const chipVal = $('upnlChipVal');
    const box = $('upnlBox');
    const boxVal = $('upnlVal');
    const hasPos = positions && positions.length > 0;
    const upnl = totalUpnl || 0;
    if (chip) {
        if (hasPos || Math.abs(upnl) > 0.0001) {
            chip.style.display = 'flex';
            chipVal.innerHTML = fM(upnl);
            chip.style.borderColor = upnl >= 0 ? 'rgba(0,201,122,.4)' : 'rgba(240,60,82,.4)';
        } else chip.style.display = 'none';
    }
    if (box) {
        if (hasPos) { box.style.display = 'flex'; boxVal.innerHTML = fM(upnl); }
        else box.style.display = 'none';
    }
}

function renderCumulativeFromWS(cumulative) {
    if (!cumulative || !cumulative.length) return;
    const sorted = [...cumulative].reverse();
    const labels = sorted.map(r => r.d.slice(5));
    const vals = sorted.map(r => parseFloat(r.p));
    const total = vals.reduce((a,b)=>a+b,0);
    $('cumTot').innerHTML = `<span class="${total>=0?'c-pos':'c-neg'}">${total>=0?'+':''}${total.toFixed(4)} USDT</span>`;
    const cumBd = total >= 0 ? '#00c97a' : '#f03c52';
    if (charts['cum']) {
        charts['cum'].data.labels = labels;
        charts['cum'].data.datasets[0].data = vals;
        charts['cum'].data.datasets[0].borderColor = cumBd;
        charts['cum'].data.datasets[0].backgroundColor = total>=0?'rgba(0,201,122,.06)':'rgba(240,60,82,.06)';
        charts['cum'].update('none');
    } else {
        charts['cum'] = chartDef('cumChart','line',labels,vals,{borderColor:cumBd,borderWidth:2,pointRadius:2,fill:true,backgroundColor:total>=0?'rgba(0,201,122,.06)':'rgba(240,60,82,.06)',tension:.3,pointBackgroundColor:cumBd});
    }
}

function appendLogsFromWS(logLines) {
    if (!logLines || !logLines.length) return;
    const last10 = allLogLines.slice(-10);
    const newLines = logLines.filter(l => !last10.includes(l));
    if (newLines.length) {
        allLogLines.push(...newLines);
        if (allLogLines.length > 2000) allLogLines = allLogLines.slice(-2000);
        renderLog();
    }
}

async function fetchWithRetry(params, type, retry=0){
  try{
    const r=await fetch(`${API}?${params}&t=${Date.now()}`);
    if(!r.ok) throw new Error(`HTTP ${r.status}`);
    return await r.json();
  }catch(e){
    if(retry<4){await new Promise(r=>setTimeout(r,1000*Math.pow(2,retry)));return fetchWithRetry(params,type,retry+1);}
    return null;
  }
}

// ─── TICKER ───
async function fetchTicker(){
  const d=await fetchWithRetry('_ticker=1','ticker');
  if(d&&d.ok) updateTickerUI(d);
}

// ─── STATUS ───
async function fetchStatus(){
  const d=await fetchWithRetry('_status=1','status');
  if(!d) return;
  if(!loaded) hideLdr();
  markUpdate();
  const running = d.running ?? d.bot_running ?? false;
  setBotStatus(running);
  $('kUpt').textContent=d.uptime||'--';
  $('uptTxt').textContent=d.uptime||'--';
  const mode=d.mode||'NORMAL';
  $('modeBadge').textContent=mode;
  $('modeBadge').className=`mode-badge m-${mode}`;
  const pair=d.pairs?.ETHUSDT;
  if(pair){
    updatePairUI(pair);
    updatePairNumbers(pair);
    if(pair.orders) updateLadder(pair.orders);
    const gridOn = pair.grid_built !== false;
    const gd = $('gridDot'); const gt = $('gridStatusTxt');
    if(gd){ gd.className='gs-dot '+(gridOn?'on':'off'); }
    if(gt) gt.textContent=`Grid ${gridOn?'ON':'OFF'} · ${pair.open_entries||0}E ${pair.open_exits||0}S`;
    if($('cycleN')) $('cycleN').textContent = pair.cycle_n||'--';
    const mlAcc = pair.ml_accuracy||0;
    if($('mlBadge')) $('mlBadge').textContent=`ML ${mlAcc>0?(mlAcc*100).toFixed(0)+'%':'--'}`;
    if($('cMlAcc')) $('cMlAcc').textContent=mlAcc>0?(mlAcc*100).toFixed(1)+'%':'--';
  }
  if(d.pnl_hourly) renderHourly(d.pnl_hourly);
  if(d.pnl_daily)  renderDaily(d.pnl_daily);
}

// ─── LOGS ───
async function fetchLogs(){
  const d=await fetchWithRetry('_logs=1','logs');
  if(d&&d.lines) appendLogs(d.lines);
}

// ─── MARKET ───
function initLwChart() {
    if (lwChart) return;
    const el = document.getElementById('candleChart');
    if (!el) return;
    const create = () => {
        if (el.clientWidth === 0) return false;
        lwChart = LightweightCharts.createChart(el, {
            width: el.clientWidth,
            height: 200,
            layout: { background: { type: 'solid', color: 'transparent' }, textColor: '#7a99bb' },
            grid: { vertLines: { color: 'rgba(26,37,53,.4)' }, horzLines: { color: 'rgba(26,37,53,.4)' } },
            crosshair: { mode: LightweightCharts.CrosshairMode.Normal },
            rightPriceScale: { borderColor: 'rgba(26,37,53,.4)' },
            timeScale: {
                borderColor: 'rgba(26,37,53,.4)',
                timeVisible: true,
                secondsVisible: false,
                tickMarkFormatter: (t) => {
                    const d = new Date(t * 1000);
                    return d.getUTCHours().toString().padStart(2,'0') + ':' + d.getUTCMinutes().toString().padStart(2,'0');
                }
            }
        });
        lwSeries = lwChart.addCandlestickSeries({
            upColor: '#00c97a', downColor: '#f03c52',
            borderVisible: false,
            wickUpColor: '#00c97a', wickDownColor: '#f03c52'
        });
        const resizeObserver = new ResizeObserver(() => {
            if (el.clientWidth > 0 && lwChart) {
                lwChart.applyOptions({ width: el.clientWidth });
            }
        });
        resizeObserver.observe(el);
        return true;
    };
    if (!create()) {
        const observer = new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting && el.clientWidth > 0) {
                create();
                observer.disconnect();
            }
        }, { threshold: 0.1 });
        observer.observe(el);
    }
}

async function fetchMarket(){
  const d=await fetchWithRetry('_market=1','market');
  if(!d||!d.ok) return;
  const klines=d.klines||[];
  if(klines.length){
    const seen=new Set();
    const ohlc=klines
      .map(c=>({time:(c.t/1000)|0,open:+c.o,high:+c.h,low:+c.l,close:+c.c}))
      .filter(c=>{if(seen.has(c.time))return false;seen.add(c.time);return true;})
      .sort((a,b)=>a.time-b.time);
    if(ohlc.length){
      initLwChart();
      if(lwSeries){
        try{lwSeries.setData(ohlc);}catch(e){console.warn('[LW] setData:',e.message);}
        lastCandleTime=ohlc[ohlc.length-1].time;
      }
      const mn=Math.min(...ohlc.map(c=>c.low)),mx=Math.max(...ohlc.map(c=>c.high));
      $('mktRange').textContent=`↓$${mn.toFixed(2)} · ↑$${mx.toFixed(2)}`;
    }
  }
  renderIndicators(d);
  $('mktUpdTs').textContent=d.ts||'--';
}

// ─── SCALP STATS ───
async function fetchScalp(){
  const d=await fetchWithRetry('_scalp=1','scalp');
  if(!d||!d.ok) return;
  if($('stFillH')) $('stFillH').textContent=d.fills_per_hour||'0';
  if($('stPnl1h')) $('stPnl1h').innerHTML=fM(d.pnl_1h);
  if($('kWin')) $('kWin').textContent=d.win_rate+'%';
  if($('stWr')) $('stWr').textContent=d.win_rate+'%';
  if($('kFillsH')) $('kFillsH').textContent=d.fills_24h+' fills hoy';
  if($('stFillsH')) $('stFillsH').textContent=d.fills_24h;
}

// ─── UPNL ───
async function fetchUpnl(){
  const d=await fetchWithRetry('_pnl_float=1','upnl');
  if(!d||!d.ok) return;
  const upnl=parseFloat(d.total_upnl)||0;
  const hasPos=d.positions&&d.positions.length>0;
  const chip=$('upnlChip'),chipVal=$('upnlChipVal'),box=$('upnlBox'),boxVal=$('upnlVal');
  if(chip){if(hasPos||Math.abs(upnl)>0.0001){chip.style.display='flex';chipVal.innerHTML=fM(upnl);chip.style.borderColor=upnl>=0?'rgba(0,201,122,.4)':'rgba(240,60,82,.4)';}else chip.style.display='none';}
  if(box){if(hasPos){box.style.display='flex';boxVal.innerHTML=fM(upnl);}else box.style.display='none';}
  const pb=$('posBody');
  if(pb){
    if(hasPos){
      pb.innerHTML=d.positions.map(p=>{
        const amt=parseFloat(p.positionAmt);
        const side=amt>0?'BUY':'SELL';
        return `<tr><td><span class="badge ${amt>0?'b-buy':'b-sell'}">${side}</span></td><td>${Math.abs(amt).toFixed(4)}</td><td>${fP(p.entryPrice)}</td><td>${fM(p.unRealizedProfit||0)}</td><td style="color:var(--red)">${fP(p.liquidationPrice)}</td></tr>`;
      }).join('');
    } else pb.innerHTML='<tr><td colspan="5" class="no-data">Sin posición abierta</td></tr>';
  }
}

// ─── ML INFO ───
async function fetchMLInfo(){
  const d=await fetchWithRetry('_ml_info=1','ml');
  if(!d||!d.ok) return;
  if($('mlAccStat')) $('mlAccStat').textContent=((d.accuracy||0)*100).toFixed(1)+'%';
  if($('mlFeatCount')) $('mlFeatCount').textContent=d.features||'--';
  if($('mlUpdated')) $('mlUpdated').textContent=(d.updated_at||'--').slice(0,16);
  const barDiv=$('mlFeatBars');
  if(!barDiv) return;
  const imps=d.importances||{};
  const keys=Object.keys(imps);
  if(!keys.length){barDiv.innerHTML='<div style="color:var(--muted);font-size:9px;padding:10px;text-align:center">Sin datos</div>';return;}
  const maxVal=Math.max(...Object.values(imps));
  barDiv.innerHTML=keys.map(k=>{
    const v=imps[k]; const pct=maxVal>0?v/maxVal*100:0;
    return `<div class="ml-feat-row"><span class="ml-feat-name">${k}</span><div class="ml-feat-bar-bg"><div class="ml-feat-bar" style="width:${pct.toFixed(1)}%"></div></div><span class="ml-feat-val">${v.toFixed(3)}</span></div>`;
  }).join('');
}

// ─── FILLS HISTORY ───
async function loadFillsHistory() {
    if (fillsOffset === 0 && lastRecentFillsCache.length) {
        renderFillsTable(lastRecentFillsCache.slice(0, fillsLimit));
        $('fillCnt').textContent = lastRecentFillsCache.length;
        const totalPages = Math.ceil(lastRecentFillsCache.length / fillsLimit) || 1;
        const curPage = Math.floor(fillsOffset / fillsLimit) + 1;
        if ($('fillsPage')) $('fillsPage').textContent = `${curPage}/${totalPages}`;
        return;
    }
    const d = await fetchWithRetry(`_fills_history=1&limit=${fillsLimit}&offset=${fillsOffset}`, 'fills');
    if (!d || !d.ok) return;
    fillsTotal = d.total || 0;
    const totalPages = Math.ceil(fillsTotal / fillsLimit) || 1;
    const curPage = Math.floor(fillsOffset / fillsLimit) + 1;
    if ($('fillsPage')) $('fillsPage').textContent = `${curPage}/${totalPages}`;
    renderFillsTable(d.fills || []);
}
function fillsPrev(){if(fillsOffset>0){fillsOffset=Math.max(0,fillsOffset-fillsLimit);loadFillsHistory();}}
function fillsNext(){if(fillsOffset+fillsLimit<fillsTotal){fillsOffset+=fillsLimit;loadFillsHistory();}}
function updateFillsHistory(fills){
  const fb=$('fillBody');$('fillCnt').textContent=fillsTotal;
  if(!fills.length){fb.innerHTML='<tr><td colspan="6" class="no-data">Sin historial</td></tr>';return;}
  fb.innerHTML=fills.map(f=>{
    const bc=f.side==='BUY'?'b-buy':'b-sell';
    const rec=f.is_recovery?'<span class="badge b-rec">R</span>':'';
    return `<tr><td style="color:var(--muted)">${(f.filled_at||'').slice(11,19)}</td><td><span class="badge ${bc}">${f.side}</span></td><td style="color:var(--muted)">${f.grid_role||''}</td><td class="tr">${fM(f.pnl_usd||0)}</td><td style="color:var(--dim)">${fP(f.exit_price||f.price||0)}</td><td>${rec}</td></tr>`;
  }).join('');
}

// ─── UI helpers ───
function switchTab(name,btn){
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  btn.classList.add('active');
  $('tab-'+name).classList.add('active');
  if(name==='ml') fetchMLInfo();
  if(name==='fills') loadFillsHistory();
}
const G_LEN=Math.PI*64;
function setGauge(conf,dir){
  const col={UP:'var(--green)',DOWN:'var(--red)',SIDEWAYS:'var(--accent)'}[dir]||'var(--accent)';
  const ico={UP:'▲',DOWN:'▼',SIDEWAYS:'↔'}[dir]||'';
  const arc=$('gArc');
  arc.style.strokeDasharray=G_LEN;
  arc.style.strokeDashoffset=G_LEN-(conf/100)*G_LEN;
  arc.style.stroke=col;
  $('gLbl').textContent=conf+'%';$('gLbl').style.color=col;
  $('gDir').innerHTML=`<span style="color:${col}">${ico} ${dir}</span>`;
}
function toast(title,msg,type='info'){
  const icons={info:'ℹ️',fill_pos:'✅',fill_neg:'⚠️',warn:'🔶'};
  const t=document.createElement('div');
  t.className=`toast ${type}`;
  t.innerHTML=`<span class="toast-icon">${icons[type]||'ℹ️'}</span><div class="toast-body"><div class="toast-title">${title}</div><div class="toast-msg">${msg}</div></div><button class="toast-close" onclick="dismissToast(this.parentNode)">×</button>`;
  $('toasts').prepend(t);
  setTimeout(()=>dismissToast(t),5000);
}
function dismissToast(t){t.classList.add('out');setTimeout(()=>t.remove(),300);}
function chartDef(id,type,labels,data,opts){
  const ctx=$(id)?.getContext('2d'); if(!ctx) return null;
  return new Chart(ctx,{type,data:{labels,datasets:[{...opts,data}]},options:{
    responsive:true,maintainAspectRatio:false,animation:{duration:400},
    plugins:{legend:{display:false}},
    scales:{x:{ticks:{color:'#3a5270',font:{size:7}},grid:{color:'rgba(26,37,53,.4)'}},
            y:{ticks:{color:'#3a5270',font:{size:7}},grid:{color:'rgba(26,37,53,.4)'}}}
  }});
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
function setBotStatus(r){
  $('sysTxt').textContent=r?'Activo':'Detenido';
  $('sysTxt').style.color=r?'var(--green)':'var(--red)';
  $('liveIndicator').className='dot'+(r?' active':'');
}
function updateTickerUI(d){
  const el=$('priceLive');
  if(lastPrice&&d.price!==lastPrice){el.classList.remove('fup','fdn');void el.offsetWidth;el.classList.add(d.price>lastPrice?'fup':'fdn');}
  lastPrice=d.price;
  el.textContent='$'+d.price.toLocaleString('en-US',{minimumFractionDigits:2});
  $('ladderPx').textContent='$'+d.price.toFixed(2);
  const chg=d.change24h||0;
  $('priceChg').textContent=(chg>=0?'+':'')+chg.toFixed(2)+'%';
  $('priceChg').className='price-chg '+(chg>0?'up':chg<0?'dn':'ntr');
  const h=d.high24h||0,l=d.low24h||0,v=d.vol24h||0;
  $('priceHL').textContent=`H: $${h.toFixed(2)} · L: $${l.toFixed(2)} · Vol: ${v>0?(v/1000).toFixed(0)+'K':'--'}`;
  $('stPx').textContent='$'+d.price.toFixed(2);
  $('stChg').innerHTML=`<span class="${chg>=0?'c-pos':'c-neg'}">${(chg>=0?'+':'')+chg.toFixed(2)}%</span>`;
  $('stH').textContent='$'+h.toFixed(2);
  $('stL').textContent='$'+l.toFixed(2);
  $('stVol').textContent=v>0?(v/1000).toFixed(0)+'K':'--';
  if(d.bid){$('bidPx').textContent='$'+parseFloat(d.bid).toFixed(2);}
  if(d.ask){$('askPx').textContent='$'+parseFloat(d.ask).toFixed(2);}
  if(d.bid&&d.ask){$('spreadVal').textContent='·';$('stSpr').textContent='$'+(parseFloat(d.ask)-parseFloat(d.bid)).toFixed(2);}
  const fr=(d.fundRate||0)*100;
  const frStr=(fr>=0?'+':'')+fr.toFixed(4)+'%';
  const frCls=fr>=0?'funding-positive':'funding-negative';
  $('tbFunding').innerHTML=`<span class="${frCls}">${frStr}</span>`;
  $('mFunding').innerHTML=`<span class="${frCls}">${frStr}</span>`;
  $('stFund').innerHTML=`<span class="${frCls}">${frStr}</span>`;
  if(d.markPrice){$('tbMark').textContent='$'+parseFloat(d.markPrice).toFixed(2);$('stMark').textContent='$'+parseFloat(d.markPrice).toFixed(2);}
  if(d.oi){const oiK=(d.oi/1000).toFixed(1)+'K ETH';$('mOi').textContent=oiK;$('stOi').textContent=oiK;}
  if(lwSeries && d.price && lastCandleTime > 0){
    try{
      const t5 = Math.floor(Date.now()/1000/300)*300;
      const ut  = Math.max(t5, lastCandleTime);
      lwSeries.update({time: ut, open: d.price, high: d.price, low: d.price, close: d.price});
    }catch(e){}
  }
}
function updatePairUI(pair){
  $('cNiv').textContent=pair.levels||'--';
  $('cLS').textContent=`${pair.long_levels||'--'} / ${pair.short_levels||'--'}`;
  $('cSpc').textContent=((pair.spacing_pct||0)*100).toFixed(3)+'%';
  $('cEnt').textContent=pair.open_entries||0;
  $('cSal').textContent=pair.open_exits||0;
  setGauge(pair.confidence||0,pair.direction||'SIDEWAYS');
  $('gRsn').textContent=pair.ai_reason||'—';
  lastAICheck=pair.last_ai_check||null;
  if($('stRecov')) $('stRecov').textContent=pair.recovery_active?'Sí 🔄':'No';
  if($('stRecov2')) $('stRecov2').textContent=pair.recovery_active?'Sí 🔄':'No';
  if($('aiEngBadge')) $('aiEngBadge').textContent=`${pair.direction||'?'} ${pair.confidence||0}%`;
}
function updatePairNumbers(pair){
  if(pair.pnl_today!==undefined){
    $('kPnlH').innerHTML=fM(pair.pnl_today);
    $('kPnlHP').textContent=(pair.pnl_today/CAPITAL*100).toFixed(2)+'% capital';
    const kpiEl=$('kpiPnlH');
    if(kpiEl) kpiEl.className='kpi '+(pair.pnl_today>=0?'pos':'neg');
  }
  if(pair.pnl_total!==undefined) $('kPnlT').innerHTML=fM(pair.pnl_total);
  if(pair.fills_total!==undefined){$('kFillsT').textContent=pair.fills_total+' fills';$('stFills').textContent=pair.fills_total;}
  if($('stOpen')) $('stOpen').textContent=(pair.open_entries||0)+(pair.open_exits||0);
  if($('kOpenO')) $('kOpenO').textContent=((pair.open_entries||0)+(pair.open_exits||0))+' órd. abiertas';
  if($('stPeak')) $('stPeak').innerHTML=fM(pair.peak_pnl||0);
  const mlAcc=pair.ml_accuracy||0;
  if($('mlBadge')) $('mlBadge').textContent=`ML ${mlAcc>0?(mlAcc*100).toFixed(0)+'%':'--'}`;
}
function renderIndicators(d){
  if(!d) return;
  const rsi=d.rsi||50; $('mRsi').textContent=rsi.toFixed(1);
  $('mRsiLbl').textContent=rsi>70?'Sobrecomprado':rsi<30?'Sobrevendido':'Neutral';
  $('mRsiBar').style.width=rsi+'%'; $('mRsiDot').style.left=rsi+'%';
  $('mRsiBar').style.background=rsi>70?'var(--red)':rsi<30?'var(--green)':'var(--accent)';
  $('stRsi').innerHTML=`<span class="${rsi>70?'c-neg':rsi<30?'c-pos':'c-neu'}">${rsi.toFixed(1)}</span>`;
  const mh=d.macd_hist||0; $('mMacd').innerHTML=`<span class="${mh>0?'c-pos':'c-neg'}">${mh.toFixed(5)}</span>`;
  $('mMacdLbl').textContent=mh>0?'Alcista':'Bajista';
  $('mMacdBar').style.width=Math.min(100,Math.abs(mh)*5000)+'%';
  $('mMacdBar').style.background=mh>0?'var(--green)':'var(--red)';
  $('stMacd').innerHTML=`<span class="${mh>0?'c-pos':'c-neg'}">${mh.toFixed(5)}</span>`;
  const adx=d.adx||0; $('mAdx').textContent=adx.toFixed(1);
  $('mAdxLbl').textContent=adx>25?'Tendencia fuerte':adx>15?'Tendencia débil':'Lateral';
  $('mAdxBar').style.width=Math.min(100,adx*2)+'%'; $('stAdx').textContent=adx.toFixed(1);
  const atr=d.atr_pct||0; $('mAtr').textContent=atr.toFixed(3)+'%';
  const vr=d.vol_ratio||1; $('mVolR').textContent=`Vol ratio: ${vr.toFixed(2)}x`;
  const bbPct=d.bb_pct||.5; $('mBb').textContent=(bbPct*100).toFixed(0)+'%';
  $('mBbRange').textContent=`Width: ${(d.bb_width||0).toFixed(3)}%`;
  if(d.ema9)  $('mE9').textContent='$'+d.ema9.toFixed(2);
  if(d.ema21) $('mE21').textContent='$'+d.ema21.toFixed(2);
  if(d.ema50) $('mE50').textContent='$'+d.ema50.toFixed(2);
  const fr=(d.funding||0)*100; const frCls=fr>=0?'funding-positive':'funding-negative';
  $('mFunding').innerHTML=`<span class="${frCls}">${(fr>=0?'+':'')+fr.toFixed(4)}%</span>`;
  if(d.oi_value) $('mOiVal').textContent='$'+(d.oi_value/1e6).toFixed(2)+'M';
}
function updateLadder(orders){
  const wrap=$('ladderWrap');
  if(!orders||!orders.length){wrap.innerHTML='<div class="empty-ladder">Sin órdenes activas</div>';return;}
  const sorted=[...orders].sort((a,b)=>parseFloat(b.price)-parseFloat(a.price));
  const maxQty=Math.max(...sorted.map(o=>parseFloat(o.qty||0)));
  const curPx=lastPrice||0;
  let rows='',priceInserted=false;
  for(let i=0;i<sorted.length;i++){
    const o=sorted[i]; const px=parseFloat(o.price);
    if(!priceInserted&&curPx>0&&px<curPx){
      rows+=`<div class="ladder-row current-price-row"><span class="lr-price cur" style="text-align:right">${fP(curPx)}</span><div class="lr-bar-wrap"><div style="text-align:center;font-size:8px;color:var(--accent);line-height:10px">── PRECIO ──</div></div><span class="lr-qty"></span></div>`;
      priceInserted=true;
    }
    const isBuy=o.side==='BUY'; const pct=maxQty>0?parseFloat(o.qty)/maxQty*100:0;
    const dist=curPx>0?((px-curPx)/curPx*100).toFixed(2):'';
    const role=o.grid_role==='EXIT'?'<span style="color:var(--yellow)">EXIT</span>':'<span style="color:var(--muted)">ENT</span>';
    rows+=`<div class="ladder-row"><span class="lr-price ${isBuy?'buy':'sell'}">${fP(px)}<span style="font-size:7px;color:var(--muted);margin-left:3px">${dist>0?'+':''}${dist}%</span></span><div class="lr-bar-wrap"><div class="lr-bar ${isBuy?'buy':'sell'}" style="width:${pct.toFixed(1)}%"></div></div><span class="lr-qty">${parseFloat(o.qty).toFixed(4)} ${role}</span></div>`;
  }
  if(!priceInserted&&curPx>0) rows+=`<div class="ladder-row current-price-row"><span class="lr-price cur" style="text-align:right">${fP(curPx)}</span><div class="lr-bar-wrap"><div style="text-align:center;font-size:8px;color:var(--accent);line-height:10px">── PRECIO ──</div></div><span class="lr-qty"></span></div>`;
  wrap.innerHTML=rows;
}
function appendLogs(lines){allLogLines=lines;renderLog();}
function renderLog(){
  const box=$('logBox');
  const atBot=box.scrollHeight-box.clientHeight<=box.scrollTop+40;
  const f=logFilter.toLowerCase();
  const filt=f?allLogLines.filter(l=>l.toLowerCase().includes(f)):allLogLines;
  box.innerHTML=filt.map(line=>{
    const m=line.match(/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+\[(\w+)\]\s+(.*)$/);
    if(m){
      const t=m[1].slice(11),lv=m[2];
      const msg=m[3].replace(/</g,'&lt;').replace(/>/g,'&gt;');
      const cls=lv==='ERROR'?'le':lv==='WARN'?'lw':'li';
      return `<div class="ll"><span class="lt">${t}</span><span class="${cls}">[${lv}]</span><span class="lm">${msg}</span></div>`;
    }
    return `<div class="ll"><span class="lm">${line.replace(/</g,'&lt;')}</span></div>`;
  }).join('');
  if(atBot&&!logPaused) box.scrollTop=box.scrollHeight;
}
function filterLog(){logFilter=$('logSearch').value;renderLog();}
function clearLog(){allLogLines=[];$('logBox').innerHTML='';}
function renderConf(hist){
  if(!hist.length) return;
  const vals=hist.map(h=>h.confidence);
  const labels=hist.map(h=>(h.time||'').slice(11,16));
  const colors=hist.map(h=>h.direction==='UP'?'rgba(0,201,122,.8)':h.direction==='DOWN'?'rgba(240,60,82,.8)':'rgba(45,140,255,.8)');
  if(charts['conf']){
    charts['conf'].data.labels=labels;
    charts['conf'].data.datasets[0].data=vals;
    charts['conf'].data.datasets[0].borderColor=colors[colors.length-1]||'#2d8cff';
    charts['conf'].update('none');
  } else {
    charts['conf']=chartDef('confChart','line',labels,vals,{borderColor:'#2d8cff',borderWidth:1.5,pointRadius:0,fill:true,backgroundColor:'rgba(45,140,255,.06)',tension:.3});
  }
}
function renderHourly(data){
  const labels=[],vals=[];let total=0;
  for(let i=0;i<24;i++){
    labels.push(String(i).padStart(2,'0')+'h');
    const f=data.find(x=>parseInt(x.h)===i); const p=f?parseFloat(f.p):0;
    vals.push(p);total+=p;
  }
  $('hTot').innerHTML=`<span class="${total>=0?'c-pos':'c-neg'}">${total>=0?'+':''}${total.toFixed(4)} USDT</span>`;
  const bg=vals.map(v=>v>=0?'rgba(0,201,122,.5)':'rgba(240,60,82,.5)');
  const bd=vals.map(v=>v>=0?'#00c97a':'#f03c52');
  if(charts['h']){charts['h'].data.datasets[0].data=vals;charts['h'].data.datasets[0].backgroundColor=bg;charts['h'].data.datasets[0].borderColor=bd;charts['h'].update('none');}
  else{charts['h']=chartDef('hChart','bar',labels,vals,{backgroundColor:bg,borderColor:bd,borderWidth:1,borderRadius:3});}
}
function renderDaily(data){
  if(!data||!data.length) return;
  const sorted=[...data].reverse();
  const labels=sorted.map(r=>r.d.slice(5));
  const vals=sorted.map(r=>parseFloat(r.p));
  const total=vals.reduce((a,b)=>a+b,0);
  $('dTot').innerHTML=`<span class="${total>=0?'c-pos':'c-neg'}">${total>=0?'+':''}${total.toFixed(4)}</span>`;
  const bg=vals.map(v=>v>=0?'rgba(0,201,122,.5)':'rgba(240,60,82,.5)');
  if(charts['d']){charts['d'].data.labels=labels;charts['d'].data.datasets[0].data=vals;charts['d'].data.datasets[0].backgroundColor=bg;charts['d'].update('none');}
  else{charts['d']=chartDef('dChart','bar',labels,vals,{backgroundColor:bg,borderRadius:4});}
  let acc=0; const cumVals=vals.map(v=>{acc+=v;return parseFloat(acc.toFixed(6));});
  $('cumTot').innerHTML=`<span class="${acc>=0?'c-pos':'c-neg'}">${acc>=0?'+':''}${acc.toFixed(4)} USDT</span>`;
  const cumBd=acc>=0?'#00c97a':'#f03c52';
  if(charts['cum']){
    charts['cum'].data.labels=labels;charts['cum'].data.datasets[0].data=cumVals;
    charts['cum'].data.datasets[0].borderColor=cumBd;
    charts['cum'].data.datasets[0].backgroundColor=acc>=0?'rgba(0,201,122,.06)':'rgba(240,60,82,.06)';
    charts['cum'].update('none');
  } else {
    charts['cum']=chartDef('cumChart','line',labels,cumVals,{borderColor:cumBd,borderWidth:2,pointRadius:2,fill:true,backgroundColor:acc>=0?'rgba(0,201,122,.06)':'rgba(240,60,82,.06)',tension:.3,pointBackgroundColor:cumBd});
  }
}
function tickAI(){
  if(!lastAICheck){$('aiSec').textContent='--s';$('aiBar').style.width='0%';return;}
  const elapsed=(Date.now()-new Date(lastAICheck+'Z').getTime())/1000;
  const remain=Math.max(0,AI_INT-elapsed);
  $('aiSec').textContent=Math.ceil(remain)+'s';
  $('aiBar').style.width=Math.min(100,(elapsed/AI_INT)*100).toFixed(1)+'%';
  $('aiBar').style.background=elapsed>=AI_INT?'var(--green)':'var(--accent)';
}
function cmd(action){
  const labels={stop:'¿Detener el bot?',force_ai:'¿Forzar evaluación IA?',reset_grid:'¿Reconstruir grilla?'};
  if(!confirm(labels[action]||'¿Confirmar?')) return;
  const fd=new FormData();fd.append('_control','1');fd.append('action',action);
  fetch(API,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.ok)toast('Comando enviado',action,'info');else alert(d.msg);}).catch(()=>alert('Error'));
}
function exportPnl(){window.open('?export_pnl=1&token=<?= EXPORT_TOKEN ?>','_blank');}
function toggleSpeed(){
  SPEED=SPEED==='fast'?'normal':'fast';
  $('speedBtn').textContent=SPEED==='fast'?'⚡ Rápido':'🐢 Normal';
  [tickerTimer,statusTimer,logTimer,mktTimer,upnlTimer,scalpTimer].forEach(clearInterval);
  tickerTimer=statusTimer=logTimer=mktTimer=upnlTimer=scalpTimer=null;
  startPolling();
}

document.getElementById('menuToggle').addEventListener('click',()=>{
  document.getElementById('sidebarLeft').classList.toggle('open');
  document.getElementById('drawerOverlay').classList.toggle('active');
});
document.getElementById('drawerOverlay').addEventListener('click',()=>{
  document.getElementById('sidebarLeft').classList.remove('open');
  document.getElementById('drawerOverlay').classList.remove('active');
});
if(window.innerWidth<=900){
  const rightBtn=document.getElementById('rightToggle');
  if(rightBtn){rightBtn.style.display='flex';rightBtn.addEventListener('click',()=>{document.getElementById('sidebarRight').classList.toggle('open');});}
  document.addEventListener('click',e=>{
    const right=document.getElementById('sidebarRight');
    if(right.classList.contains('open')&&!right.contains(e.target)&&e.target!==document.getElementById('rightToggle')) right.classList.remove('open');
  });
}

// Init con datos PHP
<?php if($init): ?>
(function(){
  const i=<?= json_encode($init) ?>;
  $('kPnlH').innerHTML=fM(i.pnl_today);
  $('kPnlHP').textContent=(i.pnl_today/CAPITAL*100).toFixed(2)+'% capital';
  $('kFillsT').textContent=i.fills_total+' fills';
  $('kOpenO').textContent=i.open_orders+' órd. abiertas';
  $('stOpen').textContent=i.open_orders;
  $('stFills').textContent=i.fills_total;
  $('cNiv').textContent=i.levels;
  $('cLS').textContent=i.long_levels+' / '+i.short_levels;
  $('cSpc').textContent=(i.spacing_pct*100).toFixed(3)+'%';
  $('stRecov').textContent=i.recovery_active?'Sí 🔄':'No';
  $('stRecov2').textContent=i.recovery_active?'Sí 🔄':'No';
  setGauge(i.confidence,i.direction);
  $('gRsn').textContent=i.ai_reason;
  const mlAcc=i.ml_accuracy||0;
  if(mlAcc>0){$('mlBadge').textContent='ML '+(mlAcc*100).toFixed(0)+'%';$('cMlAcc').textContent=(mlAcc*100).toFixed(1)+'%';}
})();
<?php endif; ?>

// Solicitar permisos de notificación
if ('Notification' in window && Notification.permission !== 'denied') {
    Notification.requestPermission();
}

// Iniciar WebSocket y polling
connectWebSocket();
startPolling();
fetchTicker();
fetchStatus();
fetchMarket();
fetchUpnl();
fetchScalp();
loadFillsHistory(); // Carga inicial de fills
setInterval(tickAI,1000);
</script>
</body>
</html>