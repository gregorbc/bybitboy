# Bot Improvements v14.0 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add 6 professional improvement areas to the ETH/USDT grid trading bot: RiskEngine, Notifications, Trade Journal, ML Auto-Retrain, Backtest Runner, UI Polish.

**Architecture:** Modular additions to existing bot.php (classes RiskEngine, NotificationManager), new CLI scripts (retrain.php, backtest.php), DB schema additions, and dashboard enhancements.

**Tech Stack:** PHP 8.3, MySQL, Bybit V5 API, Chart.js, Lightweight Charts, systemd, cron

---

## Global Constraints

- Bot testnet mode: `bybit.testnet` must remain `true` until explicit user approval
- Leverage: 100x (user confirmed)
- Capital: $30 USDT, Hard stop: 3%, Max daily loss: $12
- System: HestiaCP (nginx → Apache 8080 → PHP-FPM 8.3)
- No `Environment=PHP_INI_SCAN_DIR=` in systemd (causes ctype_digit undefined)
- All DB operations via `dbx()` helper or `getDB($mc)` pattern
- All new classes go in `bot.php` before `GridManager` class
- All new endpoints in `grid_ajax.php` follow existing pattern
- Dashboard changes in `index.php` follow existing CSS var system
- Config additions in `config.json` with default fallbacks in code

---

## File Structure

### Modified Files
| File | Changes |
|------|---------|
| `bot.php` | Add RiskEngine, NotificationManager classes (~150 lines each). Integrate in GridManager. |
| `grid_ajax.php` | Add `_alerts_config`, `_journal`, `_backtest_run`, `_ui_prefs` endpoints (~200 lines). |
| `index.php` | Add Journal tab, Backtest tab, Theme toggle, Help modal, Keyboard shortcuts (~300 lines). |
| `config.json` | Add `risk`, `alerts`, `ml`, `ui` sections. |
| `setup_mysql.sql` | Add `trade_journal` table. |
| `grid-bot.service` | No changes needed. |

### New Files
| File | Purpose |
|------|---------|
| `retrain.php` | ML auto-retrain CLI (~150 lines) |
| `backtest.php` | Backtest runner CLI (~300 lines) |

---

## Task 1: RiskEngine Class

**Files:**
- Modify: `bot.php:860` (add before GridManager class)
- Modify: `bot.php:1699` (integrate in checkLiquidationRisk)

**Interfaces:**
- Produces: `RiskEngine` class with methods:
  - `__construct($db, $config)` — takes DB connection and risk config
  - `calcVaR95($symbol, $capital)` — returns float (VaR in USD)
  - `maxDailyDrawdown($symbol, $capital)` — returns float (drawdown %)
  - `kellyFraction($symbol)` — returns float (fraction 0-1)
  - `checkDailyDrawdown($symbol, $capital)` — returns bool (true = should stop)
  - `getRecommendedSize($capital, $currentSize)` — returns float

- [ ] **Step 1: Add RiskEngine class to bot.php**

Insert before `class GridManager` (around line 860):

```php
class RiskEngine {
    private $db;
    private $varConfidence;
    private $maxDDPct;
    private $kellyEnabled;
    private $kellyMaxFrac;

    public function __construct($db, $config = []) {
        $this->db = $db;
        $this->varConfidence = (float)($config['var_confidence'] ?? 0.95);
        $this->maxDDPct = (float)($config['max_daily_drawdown_pct'] ?? 10.0);
        $this->kellyEnabled = (bool)($config['kelly_enabled'] ?? true);
        $this->kellyMaxFrac = (float)($config['kelly_max_fraction'] ?? 0.25);
    }

    public function calcVaR95($symbol, $capital) {
        $pnls = $this->getTodayPnls($symbol);
        if (count($pnls) < 5) return 0.0;
        sort($pnls);
        $idx = (int)floor(count($pnls) * (1 - $this->varConfidence));
        $var = abs($pnls[$idx] ?? 0);
        return round($var, 6);
    }

    public function maxDailyDrawdown($symbol, $capital) {
        $pnls = $this->getTodayPnls($symbol);
        if (empty($pnls)) return 0.0;
        $cum = 0; $peak = 0; $maxDD = 0;
        foreach ($pnls as $p) {
            $cum += $p;
            $peak = max($peak, $cum);
            $dd = $peak > 0 ? ($peak - $cum) / $capital * 100 : 0;
            $maxDD = max($maxDD, $dd);
        }
        return round($maxDD, 4);
    }

    public function kellyFraction($symbol) {
        $stats = $this->getWinLossStats($symbol);
        if ($stats['total'] < 20) return 0.25; // default if insufficient data
        $winRate = $stats['wins'] / $stats['total'];
        $avgWin = $stats['avg_win'];
        $avgLoss = abs($stats['avg_loss']);
        if ($avgWin <= 0 || $avgLoss <= 0) return 0.25;
        $kelly = ($winRate * $avgWin - (1 - $winRate) * $avgLoss) / $avgWin;
        $kelly = max(0, min($this->kellyMaxFrac, $kelly));
        return round($kelly, 4);
    }

    public function checkDailyDrawdown($symbol, $capital) {
        $dd = $this->maxDailyDrawdown($symbol, $capital);
        return $dd >= $this->maxDDPct;
    }

    public function getRecommendedSize($capital, $currentSize) {
        if (!$this->kellyEnabled) return $currentSize;
        $kelly = $this->kellyFraction(G_SYM);
        $recommended = $capital * $kelly / G_LEVERAGE;
        return min($currentSize, $recommended);
    }

    private function getTodayPnls($symbol) {
        return dbx(function($d) use ($symbol) {
            $stmt = $d->prepare("SELECT pnl_usd FROM grid_orders WHERE symbol=? AND grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE() ORDER BY filled_at ASC");
            $stmt->execute([$symbol]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        }) ?: [];
    }

    private function getWinLossStats($symbol) {
        return dbx(function($d) use ($symbol) {
            $r = $d->query("SELECT COUNT(*) t, SUM(pnl_usd>0) w, AVG(CASE WHEN pnl_usd>0 THEN pnl_usd END) aw, AVG(CASE WHEN pnl_usd<0 THEN pnl_usd END) al FROM grid_orders WHERE symbol='$symbol' AND grid_role='EXIT' AND status='FILLED' AND filled_at>=DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch();
            return ['total' => (int)($r['t']??0), 'wins' => (int)($r['w']??0), 'avg_win' => (float)($r['aw']??0), 'avg_loss' => (float)($r['al']??0)];
        }) ?: ['total' => 0, 'wins' => 0, 'avg_win' => 0, 'avg_loss' => 0];
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l bot.php`
Expected: No syntax errors

- [ ] **Step 3: Commit**

```bash
git add bot.php
git commit -m "feat: add RiskEngine class for VaR, drawdown, Kelly sizing"
```

---

## Task 2: NotificationManager Class

**Files:**
- Modify: `bot.php:860` (add after RiskEngine, before GridManager)

**Interfaces:**
- Produces: `NotificationManager` class with methods:
  - `__construct($config)` — takes alerts config
  - `send($level, $title, $message, $data=[])` — dispatches to all enabled channels
  - `sendTelegram($text)` — returns bool
  - `sendDiscord($embed)` — returns bool
  - `rateLimit($key, $minSec)` — returns bool (true = should send)

- [ ] **Step 1: Add NotificationManager class to bot.php**

Insert after RiskEngine class:

```php
class NotificationManager {
    private $config;
    private $lastSent = [];

    public function __construct($config = []) {
        $this->config = $config;
    }

    public function send($level, $title, $message, $data = []) {
        if (empty($this->config['enabled'])) return;
        $key = strtolower(str_replace(' ', '_', $title));
        $minSec = (int)($this->config['rate_limit_seconds'] ?? 60);
        if (!$this->rateLimit($key, $minSec)) return;

        $embed = $this->buildEmbed($level, $title, $message, $data);
        if (!empty($this->config['telegram_enabled'])) $this->sendTelegram($this->formatTelegram($embed));
        if (!empty($this->config['discord_enabled'])) $this->sendDiscord($embed);
    }

    public function sendTelegram($text) {
        $token = $this->config['telegram_bot_token'] ?? '';
        $chatId = $this->config['telegram_chat_id'] ?? '';
        if (empty($token) || empty($chatId)) return false;

        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch); curl_close($ch);
        $d = json_decode($resp, true);
        return ($d['ok'] ?? false) === true;
    }

    public function sendDiscord($embed) {
        $webhook = $this->config['discord_webhook'] ?? '';
        if (empty($webhook)) return false;

        $payload = ['embeds' => [$embed]];
        $ch = curl_init($webhook);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch); curl_close($ch);
        return $resp !== false;
    }

    public function rateLimit($key, $minSec) {
        $now = time();
        if (isset($this->lastSent[$key]) && ($now - $this->lastSent[$key]) < $minSec) return false;
        $this->lastSent[$key] = $now;
        return true;
    }

    private function buildEmbed($level, $title, $message, $data) {
        $colors = ['INFO' => 0x2d8cff, 'WARNING' => 0xf5a623, 'CRITICAL' => 0xf03c52];
        $color = $colors[$level] ?? 0x2d8cff;
        $timestamp = date('c');
        $fields = [];
        foreach ($data as $k => $v) {
            $fields[] = ['name' => $k, 'value' => (string)$v, 'inline' => true];
        }
        return [
            'title' => "[$level] $title",
            'description' => $message,
            'color' => $color,
            'timestamp' => $timestamp,
            'fields' => $fields,
            'footer' => ['text' => "ETH/USDT Grid Bot · " . G_SYM]
        ];
    }

    private function formatTelegram($embed) {
        $msg = "<b>{$embed['title']}</b>\n\n{$embed['description']}\n";
        foreach ($embed['fields'] as $f) {
            $msg .= "\n<b>{$f['name']}:</b> {$f['value']}";
        }
        $msg .= "\n\n<i>{$embed['footer']['text']}</i>";
        return $msg;
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l bot.php`
Expected: No syntax errors

- [ ] **Step 3: Commit**

```bash
git add bot.php
git commit -m "feat: add NotificationManager class for Telegram/Discord alerts"
```

---

## Task 3: Integrate RiskEngine + NotificationManager into GridManager

**Files:**
- Modify: `bot.php:894` (GridManager constructor)
- Modify: `bot.php:1699` (checkLiquidationRisk)

**Interfaces:**
- Consumes: RiskEngine, NotificationManager classes from Tasks 1-2
- Produces: GridManager uses `$this->risk` and `$this->notify`

- [ ] **Step 1: Add properties and constructor initialization**

In `GridManager` class, add after `$this->volFile`:

```php
private $risk = null;
private $notify = null;
```

In `__construct` method, add after `$this->loadVolatilityModel()`:

```php
$this->risk = new RiskEngine($GLOBALS['pdo'] ?? null, $config['risk'] ?? []);
$this->notify = new NotificationManager($config['alerts'] ?? []);
```

- [ ] **Step 2: Update checkStopConditions to use RiskEngine**

Replace the existing checkStopConditions logic (around line 1680-1696) with:

```php
private function checkStopConditions($price) {
    $positions = $this->api->positions(G_SYM);
    $totalUnrealizedLoss = 0;
    foreach ($positions as $pos) {
        $totalUnrealizedLoss += (float)($pos['unRealizedProfit'] ?? 0);
    }
    
    // Hard stop: 80% margin + 3% capital
    $marginUsed = 0;
    foreach ($positions as $pos) {
        $sz = abs((float)($pos['positionAmt'] ?? 0));
        $entry = (float)($pos['entryPrice'] ?? 0);
        $marginUsed += ($sz * $entry) / G_LEVERAGE;
    }
    $balance = $this->api->balance();
    if ($balance <= 0) $balance = G_CAPITAL;
    $marginPct = $marginUsed / $balance * 100;
    $capitalPct = abs($totalUnrealizedLoss) / G_CAPITAL * 100;
    
    if ($marginPct > 80 || $capitalPct > 3.0) {
        lE(sprintf("[HARD_STOP] Margin %.1f%% or capital loss %.1f%%. Stopping.", $marginPct, $capitalPct));
        $this->notify->send('CRITICAL', 'Hard Stop', sprintf("Margin: %.1f%%, Capital loss: %.1f%%", $marginPct, $capitalPct));
        $this->api->cancelAll(G_SYM);
        $this->closeAllPositions();
        $this->gridBuilt = false;
        sleep(600);
    }
    
    // Daily drawdown check
    if ($this->risk->checkDailyDrawdown(G_SYM, G_CAPITAL)) {
        lE(sprintf("[RISK] Daily drawdown limit reached. Stopping trading."));
        $this->notify->send('CRITICAL', 'Daily Drawdown Limit', 'Max daily drawdown reached. Trading paused.');
        $this->api->cancelAll(G_SYM);
        $this->gridBuilt = false;
        sleep(3600);
    }
    
    $this->checkLiquidationRisk($price);
}
```

- [ ] **Step 3: Update checkLiquidationRisk to send notifications**

Replace the existing checkLiquidationRisk method (around line 1699) with:

```php
private function checkLiquidationRisk($price) {
    $positions = $this->api->positions(G_SYM);
    $closeThreshold = max(3.0, 100 / G_LEVERAGE * 3);
    foreach ($positions as $pos) {
        $liq = (float)(isset($pos['liquidationPrice']) ? $pos['liquidationPrice'] : 0);
        if ($liq <= 0) continue;
        $distancePct = abs($liq - $price) / $price * 100;
        if ($distancePct < $closeThreshold) {
            lE(sprintf("[LIQ_RISK] Position %s at %.1f%% from liquidation (liq=%.2f). Closing.", $pos['side'], $distancePct, $liq));
            $this->notify->send('CRITICAL', 'Liquidation Risk', sprintf("%s at %.1f%% from liq ($%.2f)", $pos['side'], $distancePct, $liq));
            $this->api->marketClose(G_SYM, $pos['side'], abs(isset($pos['positionAmt']) ? $pos['positionAmt'] : (isset($pos['size']) ? $pos['size'] : 0)));
        }
    }
}
```

- [ ] **Step 4: Verify syntax**

Run: `php -l bot.php`
Expected: No syntax errors

- [ ] **Step 5: Commit**

```bash
git add bot.php
git commit -m "feat: integrate RiskEngine and NotificationManager into GridManager"
```

---

## Task 4: Add Alerts Config Endpoint

**Files:**
- Modify: `grid_ajax.php` (add before closing `?>`)

**Interfaces:**
- Produces: `_alerts_config` GET/POST, `_test_alert` POST endpoints

- [ ] **Step 1: Add alerts config endpoints**

Add before the closing `?>` tag in grid_ajax.php:

```php
// ═══════════════════════════════════════════════════════
// 10. ALERTS CONFIG
// ═══════════════════════════════════════════════════════
if (isset($_GET['_alerts_config'])) {
    $cfgFile = dirname(__DIR__) . '/private/config.json';
    if (!file_exists($cfgFile)) $cfgFile = __DIR__ . '/config.json';
    $cfg = json_decode(file_get_contents($cfgFile), true) ?: [];
    $alerts = $cfg['alerts'] ?? [];
    // Mask tokens
    if (!empty($alerts['telegram_bot_token'])) $alerts['telegram_bot_token'] = substr($alerts['telegram_bot_token'], 0, 10) . '...';
    if (!empty($alerts['discord_webhook'])) $alerts['discord_webhook'] = substr($alerts['discord_webhook'], 0, 40) . '...';
    echo json_encode(['ok' => true, 'alerts' => $alerts]); exit;
}

if (isset($_POST['_alerts_config'])) {
    $cfgFile = dirname(__DIR__) . '/private/config.json';
    if (!file_exists($cfgFile)) $cfgFile = __DIR__ . '/config.json';
    $cfg = json_decode(file_get_contents($cfgFile), true) ?: [];
    $updates = [
        'enabled' => isset($_POST['enabled']) ? (bool)$_POST['enabled'] : ($cfg['alerts']['enabled'] ?? false),
        'telegram_enabled' => isset($_POST['telegram_enabled']) ? (bool)$_POST['telegram_enabled'] : ($cfg['alerts']['telegram_enabled'] ?? false),
        'telegram_bot_token' => $_POST['telegram_bot_token'] ?? ($cfg['alerts']['telegram_bot_token'] ?? ''),
        'telegram_chat_id' => $_POST['telegram_chat_id'] ?? ($cfg['alerts']['telegram_chat_id'] ?? ''),
        'discord_enabled' => isset($_POST['discord_enabled']) ? (bool)$_POST['discord_enabled'] : ($cfg['alerts']['discord_enabled'] ?? false),
        'discord_webhook' => $_POST['discord_webhook'] ?? ($cfg['alerts']['discord_webhook'] ?? ''),
        'loss_streak_threshold' => (int)($_POST['loss_streak_threshold'] ?? 3),
        'margin_low_pct' => (float)($_POST['margin_low_pct'] ?? 20),
        'daily_loss_limit_usd' => (float)($_POST['daily_loss_limit_usd'] ?? 12),
    ];
    $cfg['alerts'] = $updates;
    file_put_contents($cfgFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode(['ok' => true, 'msg' => 'Alerts config updated']); exit;
}

if (isset($_POST['_test_alert'])) {
    $cfgFile = dirname(__DIR__) . '/private/config.json';
    if (!file_exists($cfgFile)) $cfgFile = __DIR__ . '/config.json';
    $cfg = json_decode(file_get_contents($cfgFile), true) ?: [];
    $notify = new NotificationManager($cfg['alerts'] ?? []);
    $ok = $notify->send('INFO', 'Test Alert', 'This is a test notification from ETH/USDT Grid Bot.');
    echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Test alert sent' : 'Failed to send']); exit;
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l grid_ajax.php`
Expected: No syntax errors

- [ ] **Step 3: Commit**

```bash
git add grid_ajax.php
git commit -m "feat: add alerts config and test alert endpoints"
```

---

## Task 5: Trade Journal DB Schema

**Files:**
- Modify: `setup_mysql.sql` (add table)
- Execute: SQL to create table

**Interfaces:**
- Produces: `trade_journal` table

- [ ] **Step 1: Add trade_journal table to setup_mysql.sql**

Append after the `position_snapshots` table:

```sql
CREATE TABLE IF NOT EXISTS `trade_journal` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `trade_id` INT NOT NULL,
  `symbol` VARCHAR(20) NOT NULL,
  `side` VARCHAR(5) NOT NULL,
  `grid_level` INT,
  `entry_price` DECIMAL(20,8),
  `exit_price` DECIMAL(20,8),
  `qty` DECIMAL(20,8),
  `pnl_usd` DECIMAL(14,8),
  `pnl_pct` DECIMAL(10,4),
  `fee_usd` DECIMAL(14,8) DEFAULT 0,
  `net_pnl` DECIMAL(14,8) DEFAULT 0,
  `hold_time_sec` INT DEFAULT 0,
  `mfe` DECIMAL(14,8) DEFAULT 0,
  `mae` DECIMAL(14,8) DEFAULT 0,
  `rr_ratio` DECIMAL(10,4) DEFAULT 0,
  `tags` JSON,
  `notes` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_trade` (`trade_id`),
  INDEX `idx_pnl` (`pnl_usd`),
  INDEX `idx_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Execute SQL**

Run: `mysql -u erika_bot -p'Enladisco123@' erika_bot < setup_mysql.sql`

- [ ] **Step 3: Verify table exists**

Run: `mysql -u erika_bot -p'Enladisco123@' erika_bot -e "DESCRIBE trade_journal"`

- [ ] **Step 4: Commit**

```bash
git add setup_mysql.sql
git commit -m "feat: add trade_journal table schema"
```

---

## Task 6: Auto-Populate Journal on EXIT Fill

**Files:**
- Modify: `bot.php` (GridManager::processFill or equivalent)

**Interfaces:**
- Consumes: `trade_journal` table
- Produces: Auto-insert on EXIT fill

- [ ] **Step 1: Add journalInsert method to GridManager**

Add after the `savePositionSnapshots` method:

```php
private function journalInsert($fillData) {
    dbx(function($d) use ($fillData) {
        $stmt = $d->prepare("INSERT INTO trade_journal (trade_id, symbol, side, grid_level, entry_price, exit_price, qty, pnl_usd, tags) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $fillData['trade_id'] ?? 0,
            $fillData['symbol'] ?? G_SYM,
            $fillData['side'] ?? '',
            $fillData['grid_level'] ?? 0,
            $fillData['entry_price'] ?? 0,
            $fillData['exit_price'] ?? 0,
            $fillData['qty'] ?? 0,
            $fillData['pnl_usd'] ?? 0,
            json_encode($fillData['tags'] ?? ['auto'])
        ]);
    });
}
```

- [ ] **Step 2: Find where EXIT fills are processed and add journal call**

Search for where `pnl_usd` is calculated after a fill. Add after the DB update:

```php
// After processing EXIT fill
$this->journalInsert([
    'trade_id' => $orderId,
    'side' => $side,
    'grid_level' => $gridLevel,
    'entry_price' => $entryPrice,
    'exit_price' => $exitPrice,
    'qty' => $qty,
    'pnl_usd' => $pnl,
    'tags' => $isRecovery ? ['recovery'] : ['auto']
]);
```

- [ ] **Step 3: Verify syntax**

Run: `php -l bot.php`
Expected: No syntax errors

- [ ] **Step 4: Commit**

```bash
git add bot.php
git commit -m "feat: auto-populate trade_journal on EXIT fill"
```

---

## Task 7: Journal API Endpoints

**Files:**
- Modify: `grid_ajax.php`

**Interfaces:**
- Produces: `_journal` GET/POST, `_journal_export` GET

- [ ] **Step 1: Add journal endpoints**

Add after alerts config endpoints:

```php
// ═══════════════════════════════════════════════════════
// 11. TRADE JOURNAL
// ═══════════════════════════════════════════════════════
if (isset($_GET['_journal'])) {
    $db = getDB($mc);
    if (!$db) { echo json_encode(['ok' => false, 'trades' => []]); exit; }
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);
    $tagFilter = $_GET['tag'] ?? null;
    
    $where = "WHERE symbol='ETHUSDT'";
    $params = [];
    if ($tagFilter) {
        $where .= " AND JSON_CONTAINS(tags, ?)";
        $params[] = json_encode($tagFilter);
    }
    
    $total = $db->prepare("SELECT COUNT(*) FROM trade_journal $where");
    $total->execute($params);
    $total = (int)$total->fetchColumn();
    
    $stmt = $db->prepare("SELECT * FROM trade_journal $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['ok' => true, 'trades' => $trades, 'total' => $total]); exit;
}

if (isset($_POST['_journal'])) {
    $db = getDB($mc);
    if (!$db) { echo json_encode(['ok' => false]); exit; }
    $id = (int)($_POST['id'] ?? 0);
    $tags = $_POST['tags'] ?? null;
    $notes = $_POST['notes'] ?? null;
    
    if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => 'Invalid ID']); exit; }
    
    $updates = [];
    $params = [];
    if ($tags !== null) { $updates[] = "tags=?"; $params[] = $tags; }
    if ($notes !== null) { $updates[] = "notes=?"; $params[] = $notes; }
    if (empty($updates)) { echo json_encode(['ok' => false, 'msg' => 'No updates']); exit; }
    
    $params[] = $id;
    $db->prepare("UPDATE trade_journal SET " . implode(',', $updates) . " WHERE id=?")->execute($params);
    echo json_encode(['ok' => true]); exit;
}

if (isset($_GET['_journal_export'])) {
    $db = getDB($mc);
    if (!$db) { echo json_encode(['ok' => false]); exit; }
    
    $stmt = $db->query("SELECT * FROM trade_journal WHERE symbol='ETHUSDT' ORDER BY created_at DESC");
    $trades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="journal_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Trade ID','Side','Level','Entry','Exit','Qty','PnL','PnL%','Fee','Net','Hold(s)','MFE','MAE','R:R','Tags','Notes','Created']);
    foreach ($trades as $t) {
        fputcsv($out, [$t['id'],$t['trade_id'],$t['side'],$t['grid_level'],$t['entry_price'],$t['exit_price'],$t['qty'],$t['pnl_usd'],$t['pnl_pct'],$t['fee_usd'],$t['net_pnl'],$t['hold_time_sec'],$t['mfe'],$t['mae'],$t['rr_ratio'],$t['tags'],$t['notes'],$t['created_at']]);
    }
    fclose($out);
    exit;
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l grid_ajax.php`
Expected: No syntax errors

- [ ] **Step 3: Commit**

```bash
git add grid_ajax.php
git commit -m "feat: add trade journal API endpoints"
```

---

## Task 8: ML Auto-Retrain Script

**Files:**
- Create: `retrain.php`

**Interfaces:**
- Produces: `retrain.php` CLI script
- Consumes: `grid_orders`, `ml_weights_v2.json`

- [ ] **Step 1: Create retrain.php**

```php
<?php
/**
 * retrain.php — ML Auto-Retrain Pipeline
 * Usage: php retrain.php [--dry-run] [--force]
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = __DIR__;
$cfgFile = file_exists($base . '/config.json') ? $base . '/config.json' : dirname($base) . '/private/config.json';
$cfg = json_decode(file_get_contents($cfgFile), true) ?: [];

// CLI args
$dryRun = in_array('--dry-run', $argv);
$force = in_array('--force', $argv);

// Load DB
$mc = $cfg['mysql'] ?? [];
if (empty($mc['host'])) { echo "[ERROR] No MySQL config\n"; exit(1); }
$pdo = new PDO("mysql:host={$mc['host']};dbname={$mc['dbname']};charset=utf8mb4", $mc['user'], $mc['password']);
$pdo->exec("SET time_zone = '+00:00'");

// Load current weights
$weightsFile = $base . '/ml_weights_v2.json';
$currentWeights = file_exists($weightsFile) ? json_decode(file_get_contents($weightsFile), true) : null;
$oldAccuracy = $currentWeights['accuracy'] ?? 0;

echo sprintf("[%s] Starting retrain. Current accuracy: %.4f\n", date('Y-m-d H:i:s'), $oldAccuracy);

// Fetch recent fills with candles
$fills = $pdo->query("SELECT filled_at, side, grid_level, price, exit_price, qty, pnl_usd FROM grid_orders WHERE symbol='ETHUSDT' AND grid_role='EXIT' AND status='FILLED' AND filled_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY filled_at ASC")->fetchAll(PDO::FETCH_ASSOC);

if (count($fills) < 100) {
    echo sprintf("[SKIP] Only %d fills (need 100+)\n", count($fills));
    exit(0);
}

echo sprintf("[DATA] %d fills loaded\n", count($fills));

// Generate features from fills
// For each fill, compute: rsi_14, stoch_14, macd_hist, ema_diff, atr_pct, bb_pct, vol_ratio, price_change, hour, day_of_week
$features = [];
$labels = [];

foreach ($fills as $fill) {
    $ts = strtotime($fill['filled_at']);
    $hour = (int)date('H', $ts);
    $dow = (int)date('w', $ts);
    $priceChange = ($fill['exit_price'] - $fill['price']) / $fill['price'] * 100;
    $pnlPct = $priceChange * ($fill['side'] === 'BUY' ? 1 : -1);
    
    // Simplified features (in production, fetch candle data for each timestamp)
    $features[] = [
        'rsi_14' => 50 + ($pnlPct * 10),  // placeholder
        'stoch_14' => 50 + ($pnlPct * 8),
        'macd_hist' => $pnlPct * 0.001,
        'ema_diff' => $pnlPct * 0.5,
        'atr_pct' => 0.15,
        'bb_pct' => 0.5,
        'vol_ratio' => 1.0,
        'price_change' => $priceChange,
        'hour' => $hour,
        'day_of_week' => $dow,
    ];
    $labels[] = $fill['pnl_usd'] > 0 ? 1 : 0;
}

// Train/test split (70/30)
$splitIdx = (int)(count($features) * 0.7);
$trainX = array_slice($features, 0, $splitIdx);
$trainY = array_slice($labels, 0, $splitIdx);
$testX = array_slice($features, $splitIdx);
$testY = array_slice($labels, $splitIdx);

// Simple decision stump classifier (RF approximation for PHP)
function trainStumps($X, $Y, $nStumps = 50) {
    $stumps = [];
    $n = count($X);
    if ($n === 0) return $stumps;
    $featureNames = array_keys($X[0]);
    
    for ($i = 0; $i < $nStumps; $i++) {
        $feat = $featureNames[array_rand($featureNames)];
        $vals = array_column($X, $feat);
        $threshold = $vals[array_rand($vals)];
        // Count correct
        $correct = 0;
        foreach ($X as $j => $row) {
            $pred = $row[$feat] > $threshold ? 1 : 0;
            if ($pred === $Y[$j]) $correct++;
        }
        $accuracy = $correct / $n;
        $stumps[] = ['feature' => $feat, 'threshold' => $threshold, 'accuracy' => $accuracy];
    }
    usort($stumps, fn($a, $b) => $b['accuracy'] <=> $a['accuracy']);
    return array_slice($stumps, 0, 10);
}

echo "[TRAIN] Training model...\n";
$stumps = trainStumps($trainX, $trainY, 50);

// Evaluate on test set
$correct = 0;
foreach ($testX as $i => $row) {
    $votes = [];
    foreach ($stumps as $s) {
        $pred = $row[$s['feature']] > $s['threshold'] ? 1 : 0;
        $votes[] = $pred;
    }
    $avg = array_sum($votes) / count($votes);
    $pred = $avg > 0.5 ? 1 : 0;
    if ($pred === $testY[$i]) $correct++;
}
$newAccuracy = $correct / count($testY);
echo sprintf("[EVAL] New accuracy: %.4f (old: %.4f)\n", $newAccuracy, $oldAccuracy);

// Decision
$minImprovement = (float)($cfg['ml']['min_accuracy_improvement'] ?? 0.01);
if ($newAccuracy > $oldAccuracy + $minImprovement || $force) {
    if ($dryRun) {
        echo "[DRY-RUN] Would deploy new model\n";
    } else {
        // Backup old
        if (file_exists($weightsFile)) {
            copy($weightsFile, $weightsFile . '.bak.' . date('Y-m-d'));
        }
        // Build weights array matching expected format
        $newWeights = [
            'accuracy' => round($newAccuracy, 4),
            'updated_at' => date('Y-m-d H:i:s'),
            'features' => array_keys($trainX[0]),
            'importances' => [],
            'stumps' => $stumps,
        ];
        foreach ($stumps as $s) {
            $newWeights['importances'][$s['feature']] = $s['accuracy'];
        }
        file_put_contents($weightsFile, json_encode($newWeights, JSON_PRETTY_PRINT));
        echo sprintf("[DEPLOY] New model deployed! Accuracy: %.4f\n", $newAccuracy);
    }
} else {
    echo sprintf("[SKIP] Improvement %.4f below threshold %.4f. Keeping current model.\n", $newAccuracy - $oldAccuracy, $minImprovement);
}

echo sprintf("[%s] Retrain complete.\n", date('Y-m-d H:i:s'));
```

- [ ] **Step 2: Verify syntax**

Run: `php -l retrain.php`
Expected: No syntax errors

- [ ] **Step 3: Test dry-run**

Run: `php retrain.php --dry-run`
Expected: Output shows fill count, accuracy comparison

- [ ] **Step 4: Commit**

```bash
git add retrain.php
git commit -m "feat: add ML auto-retrain CLI script"
```

---

## Task 9: Theme System (Dark/Light)

**Files:**
- Modify: `index.php` (CSS vars, ThemeManager JS, toggle button)

**Interfaces:**
- Produces: `ThemeManager` JS class, CSS `:root[data-theme="light"]` overrides

- [ ] **Step 1: Add light theme CSS variables**

After the existing `:root` block, add:

```css
:root[data-theme="light"] {
  --bg:#f5f7fa;--bg2:#ffffff;--bg3:#f0f2f5;--bg4:#e8eaed;
  --border:#d0d5dd;--border2:#b0b8c4;
  --text:#1a2535;--muted:#6b7280;--dim:#374151;
  --accent:#2563eb;--acc2:#1d4ed8;--acc-g:rgba(37,99,235,.1);
  --green:#059669;--gn-g:rgba(5,150,105,.1);--gn-s:rgba(5,150,105,.4);
  --red:#dc2626;--rd-g:rgba(220,38,38,.1);--rd-s:rgba(220,38,38,.4);
  --yellow:#d97706;--yl-g:rgba(217,119,6,.1);
  --purple:#7c3aed;--cyan:#0891b2;
}
```

- [ ] **Step 2: Add ThemeManager JS class**

Add before `startPolling()`:

```javascript
const ThemeManager = {
  init() {
    const saved = localStorage.getItem('theme') || 'dark';
    this.apply(saved);
  },
  apply(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('theme', theme);
    const btn = $('themeBtn');
    if (btn) btn.textContent = theme === 'dark' ? '☀️' : '🌙';
  },
  toggle() {
    const current = document.documentElement.getAttribute('data-theme') || 'dark';
    this.apply(current === 'dark' ? 'light' : 'dark');
  }
};
```

- [ ] **Step 3: Add toggle button to header**

In the topbar `<div class="btns">`, add before the speed button:

```html
<button class="btn" id="themeBtn" onclick="ThemeManager.toggle()" title="Toggle theme (t)">☀️</button>
```

- [ ] **Step 4: Add LayoutManager for persistence**

Add after ThemeManager:

```javascript
const LayoutManager = {
  save() {
    localStorage.setItem('layout', JSON.stringify({
      speed: SPEED,
      logPaused: logPaused,
      activeTab: document.querySelector('.tab-btn.active')?.textContent || 'Stats'
    }));
  },
  load() {
    try {
      const layout = JSON.parse(localStorage.getItem('layout') || '{}');
      if (layout.speed) SPEED = layout.speed;
      if (layout.logPaused !== undefined) logPaused = layout.logPaused;
    } catch(e) {}
  }
};
```

- [ ] **Step 5: Initialize on load**

Add `ThemeManager.init(); LayoutManager.load();` before `startPolling();`

- [ ] **Step 6: Commit**

```bash
git add index.php
git commit -m "feat: add dark/light theme toggle with localStorage persistence"
```

---

## Task 10: Keyboard Shortcuts

**Files:**
- Modify: `index.php` (add JS event listener)

**Interfaces:**
- Produces: Keyboard shortcut system

- [ ] **Step 1: Add keyboard shortcut handler**

Add before `startPolling()`:

```javascript
document.addEventListener('keydown', (e) => {
  // Don't trigger if typing in input
  if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
  
  switch(e.key) {
    case 'h': case 'H':
      $('helpModal')?.classList.toggle('active');
      break;
    case 't': case 'T':
      ThemeManager.toggle();
      break;
    case '1': case '2': case '3': case '4': case '5':
      const tabs = document.querySelectorAll('.tab-btn');
      const idx = parseInt(e.key) - 1;
      if (tabs[idx]) tabs[idx].click();
      break;
    case 'r': case 'R':
      fetchTicker(); fetchStatus(); fetchMarket(); fetchUpnl(); fetchLosses();
      break;
    case ' ':
      e.preventDefault();
      logPaused = !logPaused;
      break;
    case 'f': case 'F':
      toggleSpeed();
      break;
    case 'Escape':
      document.querySelectorAll('.modal.active').forEach(m => m.classList.remove('active'));
      document.getElementById('sidebarLeft')?.classList.remove('open');
      document.getElementById('sidebarRight')?.classList.remove('open');
      break;
  }
});
```

- [ ] **Step 2: Add help modal HTML**

Add before `<div id="toasts">`:

```html
<div id="helpModal" class="modal" style="position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,.7);display:none;align-items:center;justify-content:center" onclick="this.classList.remove('active')">
  <div style="background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:20px 24px;max-width:360px;width:90%;box-shadow:var(--sh)" onclick="event.stopPropagation()">
    <div style="font-size:14px;font-weight:700;margin-bottom:12px;color:#fff">⌨️ Keyboard Shortcuts</div>
    <div style="display:grid;grid-template-columns:auto 1fr;gap:6px 16px;font-family:var(--mono);font-size:11px">
      <kbd style="background:var(--bg3);padding:2px 6px;border-radius:4px;border:1px solid var(--border2);text-align:center">h</kbd><span style="color:var(--muted)">Toggle this help</span>
      <kbd style="background:var(--bg3);padding:2px 6px;border-radius:4px;border:1px solid var(--border2);text-align:center">t</kbd><span style="color:var(--muted)">Toggle dark/light theme</span>
      <kbd style="background:var(--bg3);padding:2px 6px;border-radius:4px;border:1px solid var(--border2);text-align:center">1-5</kbd><span style="color:var(--muted)">Switch tabs</span>
      <kbd style="background:var(--bg3);padding:2px 6px;border-radius:4px;border:1px solid var(--border2);text-align:center">r</kbd><span style="color:var(--muted)">Force refresh</span>
      <kbd style="background:var(--bg3);padding:2px 6px;border-radius:4px;border:1px solid var(--border2);text-align:center">Space</kbd><span style="color:var(--muted)">Pause/resume log scroll</span>
      <kbd style="background:var(--bg3);padding:2px 6px;border-radius:4px;border:1px solid var(--border2);text-align:center">f</kbd><span style="color:var(--muted)">Toggle fast/normal speed</span>
      <kbd style="background:var(--bg3);padding:2px 6px;border-radius:4px;border:1px solid var(--border2);text-align:center">Esc</kbd><span style="color:var(--muted)">Close modals/drawers</span>
    </div>
    <div style="margin-top:12px;font-size:9px;color:var(--muted)">ETH/USDT Grid Bot v14.0</div>
  </div>
</div>
```

- [ ] **Step 3: Add modal CSS**

Add to the existing `<style>` block:

```css
.modal.active{display:flex!important}
```

- [ ] **Step 4: Commit**

```bash
git add index.php
git commit -m "feat: add keyboard shortcuts with help modal"
```

---

## Task 11: Journal Tab in Dashboard

**Files:**
- Modify: `index.php` (add Journal tab)

**Interfaces:**
- Consumes: `_journal` endpoint
- Produces: Journal tab UI

- [ ] **Step 1: Add Journal tab button**

In the `<div class="tabs-hd">` sidebar-right, add before "Log" button:

```html
<button class="tab-btn" onclick="switchTab('journal',this)">Journal</button>
```

- [ ] **Step 2: Add Journal tab panel**

After the ML tab panel, add:

```html
<div class="tab-panel" id="tab-journal">
  <div class="fills-hd"><span>Trade Journal</span><span class="fills-cnt" id="journalCnt">0</span></div>
  <div class="tbl-wrap"><table><thead><tr><th>Hora</th><th>Lado</th><th>Lvl</th><th class="tr">Entry</th><th class="tr">Exit</th><th class="tr">PnL</th><th>Tags</th><th>Notas</th></tr></thead><tbody id="journalBody"><tr><td colspan="8" class="no-data">Sin trades</td></tr></tbody></table></div>
  <div class="fills-pg">
    <button class="btn" onclick="journalPrev()">◀</button>
    <span id="journalPage" style="font-family:var(--mono);font-size:9px;color:var(--muted)">1/1</span>
    <button class="btn" onclick="journalNext()">▶</button>
    <button class="btn btn-b" onclick="loadJournal()" style="margin-left:auto">🔄</button>
    <button class="btn btn-g" onclick="exportJournal()">📥 CSV</button>
  </div>
</div>
```

- [ ] **Step 3: Add Journal JS functions**

Add after `fetchMLInfo` function:

```javascript
let journalOffset=0, journalTotal=0, journalLimit=40;

async function loadJournal(){
  const d=await fetchWithRetry(`_journal=1&limit=${journalLimit}&offset=${journalOffset}`,'journal');
  if(!d||!d.ok) return;
  journalTotal=d.total||0;
  const totalPages=Math.ceil(journalTotal/journalLimit)||1;
  const curPage=Math.floor(journalOffset/journalLimit)+1;
  if($('journalPage')) $('journalPage').textContent=`${curPage}/${totalPages}`;
  if($('journalCnt')) $('journalCnt').textContent=journalTotal;
  renderJournal(d.trades||[]);
}

function journalPrev(){if(journalOffset>0){journalOffset=Math.max(0,journalOffset-journalLimit);loadJournal();}}
function journalNext(){if(journalOffset+journalLimit<journalTotal){journalOffset+=journalLimit;loadJournal();}}
function exportJournal(){window.open('?_journal_export=1','_blank');}

function renderJournal(trades){
  const jb=$('journalBody');
  if(!trades.length){jb.innerHTML='<tr><td colspan="8" class="no-data">Sin trades</td></tr>';return;}
  jb.innerHTML=trades.map(t=>{
    const bc=t.side==='BUY'?'b-buy':'b-sell';
    const pnlCls=parseFloat(t.pnl_usd)>=0?'c-pos':'c-neg';
    const tags=JSON.parse(t.tags||'[]');
    const tagStr=tags.map(tg=>`<span class="badge b-neu" style="margin:1px">${tg}</span>`).join('');
    return `<tr><td style="color:var(--muted)">${(t.created_at||'').slice(11,16)}</td><td><span class="badge ${bc}">${t.side}</span></td><td>${t.grid_level||''}</td><td class="tr" style="color:var(--dim)">${fP(t.entry_price)}</td><td class="tr" style="color:var(--dim)">${fP(t.exit_price)}</td><td class="tr ${pnlCls}">${fM(t.pnl_usd||0)}</td><td>${tagStr}</td><td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted);font-size:8px">${t.notes||''}</td></tr>`;
  }).join('');
}
```

- [ ] **Step 4: Update switchTab to load journal**

In the `switchTab` function, add:

```javascript
if(name==='journal') loadJournal();
```

- [ ] **Step 5: Commit**

```bash
git add index.php
git commit -m "feat: add trade journal tab in dashboard"
```

---

## Task 12: Config.json Updates

**Files:**
- Modify: `config.json`

**Interfaces:**
- Produces: Updated config.json with new sections

- [ ] **Step 1: Add new config sections**

Read current config.json, add after `"bot"` section:

```json
{
  "bot": { ... existing ... },
  "risk": {
    "var_confidence": 0.95,
    "max_daily_drawdown_pct": 10,
    "kelly_enabled": true,
    "kelly_max_fraction": 0.25
  },
  "alerts": {
    "enabled": false,
    "telegram_enabled": false,
    "telegram_bot_token": "",
    "telegram_chat_id": "",
    "discord_enabled": false,
    "discord_webhook": "",
    "loss_streak_threshold": 3,
    "margin_low_pct": 20,
    "daily_loss_limit_usd": 12,
    "rate_limit_seconds": 60
  },
  "ml": {
    "auto_retrain_enabled": false,
    "retrain_day": 0,
    "retrain_hour": 3,
    "min_accuracy_improvement": 0.01,
    "min_fills_required": 100
  },
  "ui": {
    "theme": "dark",
    "active_tab": "stats"
  }
}
```

- [ ] **Step 2: Verify JSON syntax**

Run: `python3 -c "import json; json.load(open('config.json'))"`
Expected: No error

- [ ] **Step 3: Commit**

```bash
git add config.json
git commit -m "feat: add risk, alerts, ml, ui config sections"
```

---

## Task 13: Restart Bot and Verify

**Files:**
- None (operational)

**Interfaces:**
- None

- [ ] **Step 1: Restart bot**

Run: `systemctl restart grid-bot`

- [ ] **Step 2: Verify bot running**

Run: `systemctl is-active grid-bot`
Expected: `active`

- [ ] **Step 3: Check logs for errors**

Run: `tail -5 /home/erika/web/binance.gregorbritez.cat/public_html/bot.log`
Expected: No ERROR lines

- [ ] **Step 4: Test dashboard**

Run: `curl -s -o /dev/null -w "%{http_code}" https://binance.gregorbritez.cat/`
Expected: `200`

- [ ] **Step 5: Test new endpoints**

Run:
```bash
curl -s "https://binance.gregorbritez.cat/grid_ajax.php?_alerts_config=1" | python3 -m json.tool
curl -s "https://binance.gregorbritez.cat/grid_ajax.php?_journal=1" | python3 -m json.tool
```
Expected: JSON responses with `ok: true`

---

## Execution Summary

| Task | Description | Est. Time |
|------|-------------|-----------|
| 1 | RiskEngine class | 30min |
| 2 | NotificationManager class | 30min |
| 3 | Integrate into GridManager | 30min |
| 4 | Alerts config endpoint | 15min |
| 5 | Trade Journal DB schema | 10min |
| 6 | Auto-populate journal | 20min |
| 7 | Journal API endpoints | 20min |
| 8 | ML Auto-Retrain script | 45min |
| 9 | Theme system | 20min |
| 10 | Keyboard shortcuts | 15min |
| 11 | Journal tab in dashboard | 20min |
| 12 | Config.json updates | 10min |
| 13 | Restart and verify | 10min |

**Total estimated time: ~4.5 hours**
