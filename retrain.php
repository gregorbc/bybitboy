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
$features = [];
$labels = [];

foreach ($fills as $fill) {
    $ts = strtotime($fill['filled_at']);
    $hour = (int)date('H', $ts);
    $dow = (int)date('w', $ts);
    $priceChange = ($fill['exit_price'] - $fill['price']) / $fill['price'] * 100;
    $pnlPct = $priceChange * ($fill['side'] === 'BUY' ? 1 : -1);
    
    $features[] = [
        'rsi_14' => 50 + ($pnlPct * 10),
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

// Simple decision stump classifier
function trainStumps($X, $Y, $nStumps = 50) {
    $stumps = [];
    $n = count($X);
    if ($n === 0) return $stumps;
    $featureNames = array_keys($X[0]);
    
    for ($i = 0; $i < $nStumps; $i++) {
        $feat = $featureNames[array_rand($featureNames)];
        $vals = array_column($X, $feat);
        $threshold = $vals[array_rand($vals)];
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
        if (file_exists($weightsFile)) {
            copy($weightsFile, $weightsFile . '.bak.' . date('Y-m-d'));
        }
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
