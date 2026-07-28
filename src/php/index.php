<?php
declare(strict_types=1);

error_reporting(0); ini_set('display_errors', '0');

require_once __DIR__ . '/vendor/autoload.php';

use BinanceBot\Core\Config;
use BinanceBot\Core\Database;
use BinanceBot\Core\Logger;

$cfg = Config::getInstance()->all();
$mc  = $cfg['mysql'] ?? [];
define('EXPORT_TOKEN', getenv('SECURITY_TOKEN') ?: 'g273f123');

// CSV export (preserved from v15)
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
        $init['capital']         = (int)($cfg['bot']['capital_usd'] ?? 100);
        $init['ml_accuracy']     = (float)($cfg['ml']['min_accuracy'] ?? 0.85);
        $init['maker_fee']       = (float)($cfg['fees']['maker'] ?? 0.0001);
        $init['taker_fee']       = (float)($cfg['fees']['taker'] ?? 0.0006);
    }
} catch (\Throwable $e) {
    Logger::warn("[Dashboard] DB init: " . $e->getMessage());
}

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
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Grid Bot Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script src="https://unpkg.com/lightweight-charts@4.1.1/dist/lightweight-charts.standalone.production.js"></script>
  <link rel="stylesheet" href="dist/<?= htmlspecialchars($cssFile) ?>">
</head>
<body>
  <div id="app">
    <nav class="navbar">
      <span class="navbar-brand">⬡ Grid Bot</span>
      <ul class="navbar-tabs" id="nav-tabs">
        <li class="navbar-tab active" data-section="dashboard">Dashboard</li>
        <li class="navbar-tab" data-section="positions">Positions</li>
        <li class="navbar-tab" data-section="ml">ML</li>
        <li class="navbar-tab" data-section="logs">Logs</li>
      </ul>
      <button class="navbar-hamburger" id="hamburger">☰</button>
    </nav>

    <div class="app-container">
      <div class="kpi-row">
        <div class="card">
          <div class="card-title">ETHUSDT</div>
          <div class="kpi-card-value accent" id="ticker-price">—</div>
          <div class="kpi-card-value" id="ticker-change">—</div>
        </div>
        <div class="card">
          <div class="card-title">PnL Hoy</div>
          <div class="kpi-card-value" id="kpi-pnl-today">—</div>
        </div>
        <div class="card">
          <div class="card-title">Win Rate</div>
          <div class="kpi-card-value" id="kpi-win-rate">—</div>
        </div>
        <div class="card">
          <div class="card-title">Uptime</div>
          <div class="kpi-card-value" id="kpi-uptime">—</div>
        </div>
      </div>

      <div class="main-grid">
        <div class="card" id="ai-gauge">
          <div class="card-header">
            <span class="card-title">AI Signal</span>
            <span class="badge badge-accent" id="ai-direction">—</span>
          </div>
          <div class="gauge-container">
            <svg class="gauge-svg" viewBox="0 0 120 80">
              <path d="M10 70 A50 50 0 0 1 110 70" fill="none" stroke="#1e3a5f" stroke-width="8" stroke-linecap="round"/>
              <path id="gauge-arc" d="M10 70 A50 50 0 0 1 110 70" fill="none" stroke="#0ea5e9" stroke-width="8" stroke-linecap="round" stroke-dasharray="157 157" stroke-dashoffset="78" style="transition: stroke-dashoffset 0.5s;"/>
            </svg>
            <div style="font-size:1.5rem;font-weight:700;font-family:var(--font-mono);margin-top:-8px;" id="ai-confidence">—</div>
            <div class="gauge-label" id="ai-reason"></div>
            <div class="gauge-label">Próxima eval: <span id="ai-next-eval">—</span></div>
          </div>
        </div>
        <div class="card">
          <div class="card-title" style="margin-bottom:var(--space-md);">Market Analysis</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-sm);font-size:0.85rem;">
            <div>RSI: <span id="market-rsi" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
            <div>MACD: <span id="market-macd" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
            <div>ADX: <span id="market-adx" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
            <div>ATR%: <span id="market-atr" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
            <div>Boll %B: <span id="market-bollinger" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
            <div>EMA 9: <span id="market-ema9" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
            <div>EMA 21: <span id="market-ema21" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
            <div>EMA 50: <span id="market-ema50" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
            <div>Funding: <span id="market-funding" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
            <div>OI: <span id="market-oi" class="kpi-card-value" style="font-size:0.85rem;">—</span></div>
          </div>
        </div>
      </div>

      <div class="full-width">
        <div class="card">
          <div class="card-header">
            <span class="card-title">Order Ladder</span>
          </div>
          <div id="order-ladder">
            <div class="empty-state">Cargando...</div>
          </div>
        </div>
      </div>

      <div class="bottom-grid">
        <div class="card">
          <div class="card-title" style="margin-bottom:var(--space-md);">PnL Charts</div>
          <div style="display:grid;gap:var(--space-md);">
            <canvas id="chart-pnl-hourly" height="120"></canvas>
            <canvas id="chart-pnl-daily" height="120"></canvas>
            <canvas id="chart-pnl-cumulative" height="120"></canvas>
            <div id="candle-chart" style="height:300px;"></div>
          </div>
        </div>

        <div class="card">
          <div class="panel-tabs">
            <div class="panel-tab active" data-panel="panel-positions">Positions</div>
            <div class="panel-tab" data-panel="panel-fills">Fills</div>
            <div class="panel-tab" data-panel="panel-ml">ML</div>
            <div class="panel-tab" data-panel="panel-logs">Log</div>
          </div>

          <div class="panel-content active" id="panel-positions">
            <table class="data-table">
              <thead><tr><th>Side</th><th>Qty</th><th>Entry</th><th>uPnL</th><th>Liq</th></tr></thead>
              <tbody id="positions-body"><tr><td colspan="5" class="empty-state">Sin posiciones</td></tr></tbody>
            </table>
          </div>

          <div class="panel-content" id="panel-fills">
            <table class="data-table">
              <thead><tr><th>Time</th><th>Side</th><th>Role</th><th>PnL</th><th>Price</th><th>Rec</th></tr></thead>
              <tbody id="fills-body"><tr><td colspan="6" class="empty-state">Sin fills</td></tr></tbody>
            </table>
          </div>

          <div class="panel-content" id="panel-ml">
            <div id="ml-info"><div class="empty-state">Esperando datos ML...</div></div>
          </div>

          <div class="panel-content" id="panel-logs">
            <div class="log-viewer" id="log-viewer"><div class="empty-state">Esperando logs...</div></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    window.__INIT__ = <?= json_encode($init) ?>;
  </script>
  <script type="module" src="dist/<?= htmlspecialchars($jsFile) ?>"></script>
</body>
</html>
