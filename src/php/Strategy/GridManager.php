<?php
declare(strict_types=1);

namespace BinanceBot\Strategy;

use BinanceBot\Exchange\BybitFutures;

/**
 * Grid trading orchestration: build grids, detect fills, recycle entries, manage risk.
 * Extracted verbatim from src/php/bot.php (Task 8b of the PHP refactoring plan).
 *
 * Relies on global helpers defined by bot.php bootstrap:
 *   - logging:   lI(), lW(), lE(), lg()
 *   - database:   dbx(), db(), dbInit()
 *   - indicators: rsiLast(), ema(), macdHistLast(), atrPctLast(), volRatioLast(), bbWidth(), stochLast(), emaTrend(), multiTFMomentum()
 *   - constants: G_SYM, G_CAPITAL, G_LEVERAGE, ... (see plan for full list)
 *   - globals:   $NV_ENABLED, $NV_API_KEY, $NV_INTERVAL  (via "global" in aiEvaluate)
 *   - function:  analyzeChartWithVL()  (defined in src/php/Helpers.php)
 */

class GridManager {
    private $api;
    private $ai;
    private $ml;
    private ?LiquidationProtector $liquidationProtector = null;
    private $running = true;
    private $cfg = null;
    private $lastAI = 0;
    private $lastVL = 0;
    private $gridBuilt = false;
    private $cycleN = 0;
    private $peakPnl = 0.0;
    private $lastCompound = 0;
    private $lastGridBuild = 0;
    private $mlReloadCycle = 0;
    private $volReloadCycle = 0;
    private $lastPauseLog = 0;
    
    private $last_atr_predicho = null;
    private $last_vl_result = null;
    private $lastLoggedQty = 0.0;
    
    private $volWeights = null;
    private $volScalerMean = null;
    private $volScalerScale = null;
    private $volIntercept = 0.0;
    private $volMtime = 0;
    private $volFile = null;
    private $volClipLower = 0.05;
    private $volClipUpper = 1.5;

    private $lastDirection = null;
    private $directionChangeCount = 0;

    public function __construct($api, $ai, $ml, ?LiquidationProtector $liquidationProtector = null) {
        $this->api = $api;
        $this->ai = $ai;
        $this->ml = $ml;
        $this->liquidationProtector = $liquidationProtector;
        $this->volFile = dirname(__DIR__) . '/volatility_weights_ridge.json';
        $this->loadVolatilityModel();
    }

    private function loadVolatilityModel() {
        $ridgeFile = dirname(__DIR__) . '/volatility_weights_ridge.json';
        $linearFile = dirname(__DIR__) . '/volatility_weights.json';
        $chosen = null;
        if (file_exists($ridgeFile)) $chosen = $ridgeFile;
        elseif (file_exists($linearFile)) $chosen = $linearFile;
        if (!$chosen) {
            lW("[Vol] Sin modelo de volatilidad. Usando ATR actual.");
            return;
        }
        $data = json_decode(file_get_contents($chosen), true);
        if (!is_array($data) || !isset($data['weights']) || !isset($data['scaler_mean'])) {
            lW("[Vol] Archivo de modelo inválido: $chosen");
            return;
        }
        $this->volWeights     = $data['weights'];
        $this->volIntercept   = (float)($data['intercept'] ?? 0.0);
        $this->volScalerMean  = $data['scaler_mean'];
        $this->volScalerScale = $data['scaler_scale'];
        $this->volMtime       = filemtime($chosen);
        if (isset($data['prediction_clip_lower'])) {
            $this->volClipLower = (float)$data['prediction_clip_lower'];
            $this->volClipUpper = (float)$data['prediction_clip_upper'];
            lI(sprintf("[Vol] Modelo cargado: %s | MAE=%.3f%% R²=%.2f | clip=[%.3f%%, %.3f%%]",
                basename($chosen), $data['mae'] ?? 0, $data['r2'] ?? 0,
                $this->volClipLower, $this->volClipUpper));
        } else {
            lI(sprintf("[Vol] Modelo cargado: %s | MAE=%.3f%% R²=%.2f",
                basename($chosen), $data['mae'] ?? 0, $data['r2'] ?? 0));
        }
    }

    private function reloadVolatilityIfUpdated() {
        if (!file_exists($this->volFile)) return;
        $mtime = filemtime($this->volFile);
        if ($mtime > $this->volMtime) {
            lI("[Vol] Detectada actualización de volatility_weights_ridge.json, recargando...");
            $this->loadVolatilityModel();
        }
    }

    private function predictFutureATR($candles) {
        if ($this->volWeights === null || $this->volScalerMean === null) return null;
        if (count($candles) < 30) return null;
        
        $last = end($candles);
        $price = $last['c'];
        $closes = array_column($candles, 'c');
        
        $features = [];
        $features['rsi_14'] = rsiLast($closes);
        $features['stoch_14'] = stochLast($candles);
        $features['macd_hist'] = macdHistLast($closes);
        
        $e9 = ema($closes, 9);
        $e21 = ema($closes, 21);
        $e9l = end($e9);
        $e21l = end($e21);
        $features['ema_diff_9_21'] = ($e9l && $e21l && $price > 0) ? (($e9l - $e21l) / $price) : 0;
        
        $features['vol_ratio'] = volRatioLast($candles);
        $features['bb_width'] = bbWidth($candles);
        $features['atr_pct'] = atrPctLast($candles);
        
        $vols = array_column($candles, 'v');
        $cumTV = 0; $cumV = 0;
        foreach ($candles as $i => $cc) {
            $typ = ($cc['h'] + $cc['l'] + $cc['c']) / 3;
            $cumTV += $typ * $vols[$i];
            $cumV += $vols[$i];
        }
        $vwap = $cumV > 0 ? $cumTV / $cumV : $price;
        $features['vwap_ratio'] = $vwap > 0 ? $price / $vwap : 1;
        
        $features['spread_pct'] = ($last['h'] - $last['l']) / $last['c'] * 100;
        
        if (count($closes) >= 6) {
            $prev = $closes[count($closes) - 6];
            $curr = end($closes);
            $features['momentum_5'] = ($curr - $prev) / $prev * 100;
        } else {
            $features['momentum_5'] = 0;
        }
        
        $featOrder = ['rsi_14', 'stoch_14', 'macd_hist', 'ema_diff_9_21', 
                      'vol_ratio', 'bb_width', 'atr_pct', 'vwap_ratio', 
                      'spread_pct', 'momentum_5'];
        
        $scaled = [];
        for ($i = 0; $i < count($featOrder); $i++) {
            $feat = $featOrder[$i];
            $val = $features[$feat];
            $mean = isset($this->volScalerMean[$i]) ? (float)$this->volScalerMean[$i] : 0;
            $scale = isset($this->volScalerScale[$i]) ? (float)$this->volScalerScale[$i] : 1;
            if ($scale == 0) $scale = 1;
            $scaled[] = ($val - $mean) / $scale;
        }
        
        $pred = $this->volIntercept;
        for ($i = 0; $i < count($featOrder); $i++) {
            $feat = $featOrder[$i];
            if (isset($this->volWeights[$feat])) {
                $pred += $scaled[$i] * (float)$this->volWeights[$feat];
            }
        }
        
        $atr_actual = $features['atr_pct'];
        $pred_original = $pred;
        
        if ($pred_original < 0) {
            lW(sprintf("[Vol] Pred negativa (%.4f) — modelo fuera de rango, usando ATR actual %.2f%%", $pred_original, $atr_actual));
            return null;
        }
        
        $pred = max($this->volClipLower, min($this->volClipUpper, $pred));
        
        if ($atr_actual > 0.01) {
            $ratio = $pred / $atr_actual;
            if ($ratio < 0.5) {
                $alpha = 0.4;
                $pred_adj = $alpha * $pred + (1 - $alpha) * $atr_actual;
                lW(sprintf("[Vol] Pred baja (ratio %.2f), ajuste: %.2f%% → %.2f%%", $ratio, $pred, $pred_adj));
                $pred = $pred_adj;
            } elseif ($ratio > 3.0) {
                $pred = 0.65 * $atr_actual + 0.35 * $pred;
                if ($ratio > 5.0) lW(sprintf("[Vol] Pred muy alta (ratio %.2f), blend suave: %.4f%%", $ratio, $pred));
                else lI(sprintf("[Vol] Pred alta (ratio %.2f), blend suave: %.4f%%", $ratio, $pred));
            }
        }
        
        lI(sprintf("[Vol] ATR actual=%.2f%% → predicho=%.2f%% (original=%.2f%%)", $atr_actual, $pred, $pred_original));
        return $pred;
    }

    public function run() {
        lI("╔══════════════════════════════════════════╗");
        lI("║  ETH/USDT Grid Bot v15.4 – FINAL        ║");
        lI(sprintf("║  Capital: %.0f USDT  AI: %ds  PID: %d",
            G_CAPITAL, G_AI_INTERVAL, getmypid()) . str_repeat(' ', 10) . "║");
        lI("╚══════════════════════════════════════════╝");

        for ($attempt = 0; $attempt < 10; $attempt++) {
            try {
                $this->api->validate();
                $this->api->setLeverage(G_SYM, G_LEVERAGE);
                lI("[INIT] Conexión exitosa"); break;
            } catch (\Exception $e) {
                lW("[INIT] Intento " . ($attempt + 1) . "/10: " . $e->getMessage());
                if ($attempt >= 9) { lE("[INIT] Sin conexión."); return; }
                sleep(30);
            }
        }

        $balance = $this->api->balance();
        if ($balance <= 0) { lW("[INIT] Saldo 0, usando capital teórico: " . G_CAPITAL); $balance = G_CAPITAL; }
        else { lI("[INIT] Saldo disponible: {$balance} USDT"); }

        $this->loadConfig();
        $this->cleanupSession();
        $this->syncPositions();
        $this->peakPnl = 0.0;
        dbx(function($d) { return $d->prepare("UPDATE grid_configs SET peak_pnl_today=0, paused_reason=NULL WHERE symbol=?")->execute([G_SYM]); });
        lI("[INIT] Estado inicial reseteado. Entrando al loop principal...");

        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
            $this->checkControl();
            if ($this->handlePause()) continue;
            $this->cycleN++;

            $this->mlReloadCycle++;
            if ($this->mlReloadCycle >= G_ML_RELOAD_CYCLES) {
                $this->mlReloadCycle = 0;
                $this->ml->reloadIfUpdated();
            }

            $this->volReloadCycle++;
            if ($this->volReloadCycle >= G_VOL_RELOAD_CYCLES) {
                $this->volReloadCycle = 0;
                $this->reloadVolatilityIfUpdated();
            }

            try {
                $price = $this->api->price(G_SYM);
                if ($price <= 0) { lW("[MAIN] Precio 0"); sleep(G_CYCLE_SEC); continue; }
                $adaptiveInterval = G_AI_INTERVAL;
                $conf = (int)(isset($this->cfg['confidence']) ? $this->cfg['confidence'] : 50);
                if ($conf >= 85) $adaptiveInterval = G_AI_INTERVAL * 2;
                elseif ($conf < 50) $adaptiveInterval = max(60, G_AI_INTERVAL / 2);
                if (time() - $this->lastAI >= $adaptiveInterval) $this->aiEvaluate($price);
                if (!$this->gridBuilt) $this->buildGrid($price);
                elseif ($this->gridBuilt) {
                    $openCnt = dbx(function($d) {
                        return (int)$d->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='" . G_SYM . "' AND status='OPEN'")->fetchColumn();
                    }) ?? 0;
                    if ($openCnt < (G_FIXED_LEVELS - 3)) {
                        lW("[MAIN] Solo $openCnt órdenes abiertas (mín " . (G_FIXED_LEVELS - 3) . ") → rebuild");
                        $this->gridBuilt = false; $this->lastGridBuild = 0;
                    }
                }
                $this->checkFills($price);
                $this->riskCheck($price);
                $this->profitOptimize($price);
                $this->breakoutCheck($price);
                if ($this->cycleN % 5 === 0) $this->writeStatus($price);
                if ($this->cycleN % 10 === 0) $this->logCycleSummary($price);
            } catch (\Exception $e) { lE("[MAIN] " . $e->getMessage()); }
            sleep(G_CYCLE_SEC);
        }
        lI("[MAIN] Bot detenido limpiamente.");
    }

    private function loadConfig() {
        $row = dbx(function($d) { return $d->query("SELECT * FROM grid_configs WHERE symbol='" . G_SYM . "' AND status='ACTIVE' LIMIT 1")->fetch(); });
        if (!$row) {
            dbx(function($d) { return $d->prepare("INSERT INTO grid_configs (symbol,direction,confidence,capital_usd,leverage,levels,spacing_pct,long_levels,short_levels,qty_per_level,pp,qp,mode) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status='ACTIVE'")
                ->execute([G_SYM, 'SIDEWAYS', 50, G_CAPITAL, G_LEVERAGE, G_FIXED_LEVELS,
                           G_BASE_SPACING, G_LONG_LEVELS, G_SHORT_LEVELS, 0, 2, 2, 'NORMAL']); });
            $row = dbx(function($d) { return $d->query("SELECT * FROM grid_configs WHERE symbol='" . G_SYM . "' LIMIT 1")->fetch(); });
        }
        $this->cfg = $row;

        $currentSpacing = (float)($row['spacing_pct'] ?? G_BASE_SPACING);

        // Fee floor calculation with configurable mode
        $makerFee   = G_MAKER_FEE;
        $takerFee   = G_TAKER_FEE;
        $safety     = G_FEE_SAFETY;
        $enforceFee = (bool)($this->cfg['enforce_fee_floor'] ?? true);
        $feeMode    = $this->cfg['fee_floor_mode'] ?? 'optimistic'; // 'conservative' | 'optimistic' | 'disabled'

        // Fee floor modes:
        // - conservative: (maker + taker) * safety  -> assumes exit might be market order
        // - optimistic:   (maker + maker) * safety  -> assumes both entry/exit are PostOnly limit
        // - disabled:     no fee floor enforcement
        switch ($feeMode) {
            case 'conservative':
                $feeFloor = ($makerFee + $takerFee) * $safety;
                break;
            case 'optimistic':
                $feeFloor = ($makerFee + $makerFee) * $safety;
                break;
            case 'disabled':
                $feeFloor = 0;
                break;
            default:
                $feeFloor = ($makerFee + $makerFee) * $safety;
        }

        $dynamicMin = max(G_MIN_SPACING, $feeFloor);

        if ($feeMode !== 'disabled' && $enforceFee && $currentSpacing < $dynamicMin) {
            $adjustedSpacing = min(G_MAX_SPACING, $dynamicMin);
            lI(sprintf("[CFG] Spacing %.4f%% below fee floor %.4f%% (mode=%s, conservative=%.4f%%, optimistic=%.4f%%) -> ajustando a %.4f%%",
                $currentSpacing * 100, $feeFloor * 100, $feeMode, ($makerFee + $takerFee) * $safety * 100, ($makerFee + $makerFee) * $safety * 100, $adjustedSpacing * 100));
            dbx(function($d) use ($adjustedSpacing) {
                return $d->prepare("UPDATE grid_configs SET spacing_pct=? WHERE symbol=?")
                    ->execute([$adjustedSpacing, G_SYM]);
            });
            $this->cfg['spacing_pct'] = $adjustedSpacing;
            $currentSpacing = $adjustedSpacing;
        } elseif (($feeMode === 'disabled' || !$enforceFee) && $currentSpacing < $dynamicMin) {
            lW(sprintf("[CFG] Spacing %.4f%% below fee floor %.4f%% (mode=%s/enforce=%s, sin ajustar)",
                $currentSpacing * 100, $dynamicMin * 100, $feeMode, $enforceFee ? 'true' : 'false'));
        }

        lI(sprintf("[CFG] niv=%d spc=%.4f%% long=%d short=%d capital=%.0f | feeFloor=%.4f%% (mode=%s, maker=%.4f%% taker=%.4f%% safety=%.2f)",
            isset($row['levels']) ? $row['levels'] : G_FIXED_LEVELS, $currentSpacing * 100,
            isset($row['long_levels']) ? $row['long_levels'] : G_LONG_LEVELS, isset($row['short_levels']) ? $row['short_levels'] : G_SHORT_LEVELS, G_CAPITAL,
            $feeFloor * 100, $feeMode, $makerFee * 100, $takerFee * 100, $safety));
    }

    private function syncPositions() {
        $positions = $this->api->positions(G_SYM);
        if (empty($positions)) {
            lI("[SYNC] No hay posiciones abiertas. Todo limpio.");
            return;
        }
        lI("[SYNC] Detectadas " . count($positions) . " posiciones abiertas. Sincronizando...");
        $cfg = $this->cfg;
        if (!$cfg) { lW("[SYNC] Configuración no cargada, no se pueden sincronizar."); return; }
        $cfgId = (int)$cfg['id'];
        $f = $this->api->filters(G_SYM);
        $spacing = (float)($cfg['spacing_pct'] ?? G_BASE_SPACING);
        
        foreach ($positions as $pos) {
            $side = $pos['side']; // 'Buy' o 'Sell'
            $qty = abs((float)($pos['positionAmt'] ?? ($pos['size'] ?? 0)));
            $entryPrice = (float)$pos['entryPrice'];
            if ($qty < 0.001) continue;
            
            $price = $this->api->price(G_SYM);
            $level = null;
            if ($side === 'Buy') {
                $diff = ($price - $entryPrice) / $price;
                $nivel = round($diff / $spacing);
                if ($nivel >= 1 && $nivel <= G_FIXED_LEVELS) $level = $nivel;
            } else {
                $diff = ($entryPrice - $price) / $price;
                $nivel = round($diff / $spacing);
                if ($nivel >= 1 && $nivel <= G_FIXED_LEVELS) $level = -$nivel;
            }
            if ($level === null) $level = ($side === 'Buy') ? 1 : -1;
            
            $existing = dbx(function($d) use ($entryPrice, $side, $qty) {
                $s = $d->prepare("SELECT id FROM grid_orders WHERE symbol=? AND side=? AND price=? AND qty=? AND grid_role='ENTRY' AND status='FILLED' LIMIT 1");
                $s->execute([G_SYM, strtoupper($side), $entryPrice, $qty]);
                return $s->fetch();
            });
            if ($existing) {
                lI("[SYNC] Ya existe registro ENTRY para posición {$side} {$qty} @ {$entryPrice}");
                continue;
            }
            
            $orderId = 'SYNC_' . uniqid();
            $entryId = dbx(function($d) use ($cfgId, $side, $level, $orderId, $entryPrice, $qty) {
                return $d->prepare("INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,filled_at) 
                    VALUES(?,?,?,?,?,?,?,?,?,'FILLED',NOW())")
                    ->execute([$cfgId, G_SYM, $cfg['direction'], $level, strtoupper($side), 'ENTRY', $orderId, $entryPrice, $qty]);
            });
            // Note: execute() returns bool, need to get lastInsertId differently
            $entryId = dbx(function($d) { return $d->lastInsertId(); });
            lI("[SYNC] Registrada ENTRY {$side} nivel {$level} qty={$qty} price={$entryPrice}");
            
            $exitSide = ($side === 'Buy') ? 'SELL' : 'BUY';
            $exitPrice = ($side === 'Buy') ? round($entryPrice * (1 + $spacing), $f['pp']) : round($entryPrice * (1 - $spacing), $f['pp']);
            if ($exitPrice <= 0) continue;
            
            $exitExisting = dbx(function($d) use ($exitPrice, $exitSide, $qty) {
                $s = $d->prepare("SELECT id FROM grid_orders WHERE symbol=? AND side=? AND price=? AND qty=? AND grid_role='EXIT' AND status='OPEN' LIMIT 1");
                $s->execute([G_SYM, $exitSide, $exitPrice, $qty]);
                return $s->fetch();
            });
            if ($exitExisting) {
                lI("[SYNC] Ya existe EXIT para esta posición");
                continue;
            }
            
            try {
                $res = $this->api->limitOrder(G_SYM, $exitSide, $qty, $exitPrice, true, true);
                dbx(function($d) use ($cfgId, $level, $exitSide, $res, $exitPrice, $qty, $entryId) {
                    return $d->prepare("INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,linked_order) 
                        VALUES(?,?,?,?,?,?,?,?,?,'OPEN',?)")
                        ->execute([$cfgId, G_SYM, $cfg['direction'], $level, $exitSide, 'EXIT', $res['orderId'], $exitPrice, $qty, $entryId]);
                });
                lI("[SYNC] Creada EXIT {$exitSide} @ {$exitPrice} para posición existente (linked_entry={$entryId})");
            } catch (\Exception $e) {
                lW("[SYNC] Error creando EXIT: " . $e->getMessage());
            }
        }
    }

    private function cleanupSession() {
        $this->api->cancelAll(G_SYM);
        sleep(1);
        dbx(function($d) {
            return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE symbol=? AND status='OPEN'")
                    ->execute([G_SYM]);
        });
        $this->gridBuilt = false;
        lI("[INIT] Órdenes canceladas. Posiciones existentes conservadas.");
    }

    private function closeAllPositions() {
        $positions = $this->api->positions(G_SYM);
        foreach ($positions as $p) {
            $sz = abs((float)($p['positionAmt'] ?? ($p['size'] ?? 0)));
            if ($sz < 0.001) continue;
            $side = $p['side'];
            for ($retry = 0; $retry < 3; $retry++) {
                try {
                    $this->api->marketClose(G_SYM, $side, $sz);
                    lI(sprintf("[CLOSE] %s %.4f (intento %d)", $side, $sz, $retry+1));
                    break;
                } catch (\Exception $e) {
                    lW("[CLOSE] Error cerrando {$side} {$sz}: " . $e->getMessage());
                    if ($retry < 2) sleep(1);
                }
            }
        }
    }

    private function applyAIResultFallback($price) {
        $prevDir  = isset($this->cfg['direction']) ? $this->cfg['direction'] : 'SIDEWAYS';
        $prevConf = isset($this->cfg['confidence']) ? (int)$this->cfg['confidence'] : 50;
        $f        = $this->api->filters(G_SYM);
        $levels   = G_FIXED_LEVELS;
        $spacing  = G_BASE_SPACING;
        $qty      = isset($this->cfg['qty_per_level']) ? (float)$this->cfg['qty_per_level'] : 0;
        if ($qty <= 0) $qty = $this->calcQty($price, $levels, $f);
        $reason   = "Sin-velas: heurístico-puro dir={$prevDir} conf={$prevConf}";
        $direction  = $prevDir;
        $confidence = max(30, $prevConf - 10);
        $longLev = G_LONG_LEVELS;
        $shortLev = G_SHORT_LEVELS;
        dbx(function($d) use ($direction, $confidence, $reason, $levels, $spacing, $longLev, $shortLev, $qty) {
            return $d->prepare("UPDATE grid_configs SET direction=?,confidence=?,ai_reason=?,last_ai_check=NOW(),levels=?,spacing_pct=?,long_levels=?,short_levels=?,qty_per_level=? WHERE symbol=?")
                ->execute([$direction, $confidence, $reason, $levels, $spacing, $longLev, $shortLev, $qty, G_SYM]);
        });
        $this->cfg = dbx(function($d) { return $d->query("SELECT * FROM grid_configs WHERE symbol='" . G_SYM . "' LIMIT 1")->fetch(); });
        $this->lastAI = time();
        lI(sprintf("[AI-FALLBACK] %s conf=%d%% (sin velas, manteniendo última dir conocida)", $direction, $confidence));
        $this->appendConf($confidence, $direction);
        if ($direction !== $prevDir) {
            $this->api->cancelAll(G_SYM);
            dbx(function($d) { return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE symbol=? AND status='OPEN'")->execute([G_SYM]); });
            $this->gridBuilt = false; $this->lastGridBuild = 0;
        }
    }

    private function aiEvaluate($price) {
        global $NV_ENABLED, $NV_API_KEY, $NV_INTERVAL;
        lI("[AI] Evaluando ML + heurístico" . (G_VL_BLEND_WEIGHT > 0 && $NV_ENABLED ? " + VL" : "") . "...");
        $raw = $this->api->klines(G_SYM, G_TF, G_CANDLES);
        if (count($raw) < 30) {
            $this->applyAIResultFallback($price);
            return;
        }
        $candles = array_map(function($k) {
            return ['t'=>$k[0],'o'=>$k[1],'h'=>$k[2],'l'=>$k[3],'c'=>$k[4],'v'=>$k[5]];
        }, $raw);
        
        $mlResult = $this->ml->predict($candles);
        $mlProbs = $mlResult['probs'];
        
        $closes = array_column($candles, 'c');
        $rsi = rsiLast($closes);
        $macd = macdHistLast($closes);
        $ema9l = end(ema($closes, 9)); $ema21l = end(ema($closes, 21));
        $emaBull = ($ema9l && $ema21l && $ema9l > $ema21l && $price > $ema21l);
        $emaBear = ($ema9l && $ema21l && $ema9l < $ema21l && $price < $ema21l);
        $hScore = 0;
        if ($rsi > 55) $hScore += 1; elseif ($rsi < 45) $hScore -= 1;
        if ($macd > 0) $hScore += 0.5; elseif ($macd < 0) $hScore -= 0.5;
        if ($emaBull) $hScore += 0.5; elseif ($emaBear) $hScore -= 0.5;
        $norm = ($hScore + 2.0) / 4.0;
        $hProbs = [max(0, 0.5 - $norm), max(0, abs(0.5 - $norm) * 0.4 + 0.2), max(0, $norm - 0.1)];
        $hSum = array_sum($hProbs);
        if ($hSum > 0) $hProbs = array_map(function($p) use ($hSum) { return $p / $hSum; }, $hProbs);
        else $hProbs = [0.33, 0.34, 0.33];
        
        $vlProbs = null;
        $vlResult = null;
        if (G_VL_BLEND_WEIGHT > 0 && $NV_ENABLED && (time() - $this->lastVL) >= $NV_INTERVAL) {
            $chartPath = '/tmp/latest_chart.png';
            if (file_exists($chartPath)) {
                $vlResult = analyzeChartWithVL($chartPath, $NV_API_KEY);
                if ($vlResult) {
                    $vlDir = $vlResult['direction'];
                    $vlConf = $vlResult['confidence'] / 100;
                    $vlProbs = ['DOWN' => 0.33, 'SIDEWAYS' => 0.34, 'UP' => 0.33];
                    $vlProbs[$vlDir] = $vlConf;
                    $sum = array_sum($vlProbs);
                    foreach ($vlProbs as $k => $v) $vlProbs[$k] = $v / $sum;
                    lI("[VL] $vlDir {$vlResult['confidence']}% - {$vlResult['reason']}");
                }
            }
            $this->lastVL = time();
        }
        
        $w_ml = G_ML_BLEND_WEIGHT;
        $w_heur = 1 - $w_ml;
        if ($vlProbs) {
            $w_vl = G_VL_BLEND_WEIGHT;
            $w_ml = $w_ml * (1 - $w_vl);
            $w_heur = $w_heur * (1 - $w_vl);
            $blended = [
                $w_ml * $mlProbs[0] + $w_heur * $hProbs[0] + $w_vl * $vlProbs['DOWN'],
                $w_ml * $mlProbs[1] + $w_heur * $hProbs[1] + $w_vl * $vlProbs['SIDEWAYS'],
                $w_ml * $mlProbs[2] + $w_heur * $hProbs[2] + $w_vl * $vlProbs['UP']
            ];
        } else {
            $blended = [
                $w_ml * $mlProbs[0] + $w_heur * $hProbs[0],
                $w_ml * $mlProbs[1] + $w_heur * $hProbs[1],
                $w_ml * $mlProbs[2] + $w_heur * $hProbs[2]
            ];
        }
        
        $maxIdx = (int)array_search(max($blended), $blended);
        $classes = ['DOWN', 'SIDEWAYS', 'UP'];
        $direction = $classes[$maxIdx];
        $confidence = (int)round($blended[$maxIdx] * 100);
        
        $prevDir = isset($this->cfg['direction']) ? $this->cfg['direction'] : 'SIDEWAYS';
        if ($direction !== $prevDir) {
            if ($direction === $this->lastDirection) {
                $this->directionChangeCount++;
                if ($this->directionChangeCount < 2) {
                    lI("[AI] Dirección $direction propuesta, pero se requiere confirmación (2 ciclos). Manteniendo $prevDir.");
                    $direction = $prevDir;
                    $confidence = (int)round(($confidence + (isset($this->cfg['confidence']) ? $this->cfg['confidence'] : 50)) / 2);
                } else {
                    $this->directionChangeCount = 0;
                }
            } else {
                $this->lastDirection = $direction;
                $this->directionChangeCount = 1;
                lI("[AI] Posible cambio de dirección a $direction, pendiente de confirmación.");
                $direction = $prevDir;
                $confidence = (int)round(($confidence + (isset($this->cfg['confidence']) ? $this->cfg['confidence'] : 50)) / 2);
            }
        } else {
            $this->directionChangeCount = 0;
            $this->lastDirection = $direction;
        }
        
        $atr_actual = atrPctLast($candles);
        $atr_predicho = $this->predictFutureATR($candles);
        $this->last_atr_predicho = $atr_predicho;
        
        if ($atr_predicho !== null && $atr_predicho > 0.01) {
            $atr_efectivo = 0.70 * $atr_actual + 0.30 * $atr_predicho;
            lI("[Spacing] ATR blend (siempre): real={$atr_actual}% pred={$atr_predicho}% → efectivo={$atr_efectivo}%");
        } else {
            $atr_efectivo = $atr_actual;
            lI("[Spacing] Usando solo ATR real: {$atr_actual}%");
        }
        
        $spacing_raw = G_BASE_SPACING + ($atr_efectivo * G_SPACING_ATR_MULT / 100);
        $spacing = min(G_MAX_SPACING, max(G_MIN_SPACING, $spacing_raw));
        if ($direction === 'SIDEWAYS') $spacing = max(G_MIN_SPACING, $spacing * 0.90);
        
        $levels = G_FIXED_LEVELS;
        if ($direction === 'UP') {
            $longLev  = (int)round($levels * 0.625);
            $shortLev = $levels - $longLev;
        } elseif ($direction === 'DOWN') {
            $shortLev = (int)round($levels * 0.625);
            $longLev  = $levels - $shortLev;
        } else {
            $longLev  = (int)($levels * 0.5);
            $shortLev = $levels - $longLev;
        }
        $f = $this->api->filters(G_SYM);
        $qty = $this->calcQty($price, $levels, $f);
        $mlAcc = $this->ml->getAccuracy();
        $reason = sprintf("ML:%s(%d%%) H:%.1f Blend:%s(%d%%) acc=%.0f%% VolPred=%.2f%%",
            $mlResult['direction'], $mlResult['confidence'], $hScore, $direction, $confidence, $mlAcc * 100, $atr_predicho);
        
        dbx(function($d) use ($direction, $confidence, $reason, $levels, $spacing, $longLev, $shortLev, $qty, $f, $mlAcc) {
            return $d->prepare("UPDATE grid_configs SET direction=?,confidence=?,ai_reason=?,last_ai_check=NOW(),levels=?,spacing_pct=?,long_levels=?,short_levels=?,qty_per_level=?,pp=?,qp=?,ml_accuracy=? WHERE symbol=?")
                ->execute([$direction, $confidence, $reason, $levels, $spacing, $longLev, $shortLev, $qty, $f['pp'], $f['qp'], $mlAcc, G_SYM]);
        });
        $this->cfg = dbx(function($d) { return $d->query("SELECT * FROM grid_configs WHERE symbol='" . G_SYM . "' LIMIT 1")->fetch(); });
        $this->lastAI = time();
        $this->appendConf($confidence, $direction);
        $this->last_vl_result = $vlResult;
        
        $atr_pred_str = ($atr_predicho === null) ? 'null' : number_format($atr_predicho, 2).'%';
        lI(sprintf("[AI] %s conf=%d%% | spacing=%.4f%% | atr_real=%.2f%% atr_pred=%s | niveles=%d | qty=%.4f ETH", 
            $direction, $confidence, $spacing*100, $atr_actual, $atr_pred_str, $levels, $qty));
        
        if ($direction !== $prevDir && $this->directionChangeCount == 0) {
            lI("[AI] Dirección $prevDir → $direction → Reconstruyendo grid");
            // Wait for any in-flight fills on ENTRY orders before canceling
            $this->waitForPendingEntryFills(10);
            $this->api->cancelAll(G_SYM);
            usleep(800000);
            
            $positions = $this->api->positions(G_SYM);
            $exitsByLevel = [];
            dbx(function($d) use (&$exitsByLevel) {
                $rows = $d->query("SELECT grid_level, side FROM grid_orders WHERE symbol='" . G_SYM . "' AND status='OPEN' AND grid_role='EXIT'")->fetchAll();
                foreach ($rows as $r) $exitsByLevel[$r['grid_level']] = $r['side'];
            });
            foreach ($positions as $pos) {
                $side = $pos['side'];
                $qtyPos = abs((float)($pos['positionAmt'] ?? ($pos['size'] ?? 0)));
                $hasExitForSide = false;
                foreach ($exitsByLevel as $level => $exitSide) {
                    if (($exitSide === 'SELL' && $side === 'Buy') || ($exitSide === 'BUY' && $side === 'Sell')) {
                        $hasExitForSide = true;
                        break;
                    }
                }
                if (!$hasExitForSide && $qtyPos > 0.001) {
                    lI("[AI] Cerrando posición huérfana {$side} {$qtyPos} (sin EXIT asociada)");
                    $this->api->marketClose(G_SYM, $side, $qtyPos);
                }
            }
            dbx(function($d) { return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE symbol=? AND status='OPEN'")->execute([G_SYM]); });
            $this->gridBuilt = false;
            $this->lastGridBuild = 0;
        }
    }

    private function calcQty($price, $levels, $f, $knownBalance = null) {
        $balance = $knownBalance ?? $this->api->balance();
        if ($balance <= 0) $balance = G_CAPITAL;
        $effectiveCap = min($balance, G_CAPITAL) * G_MARGIN_SAFETY;
        $marginPerLevel = $effectiveCap / max(1, $levels);
        $qty = ($marginPerLevel * G_LEVERAGE) / $price;
        $maxQty = ($effectiveCap * 0.12 * G_LEVERAGE) / $price;
        if ($qty > $maxQty) $qty = $maxQty;
        
        $qty = max($f['mn'], $f['step'], round($qty / $f['step']) * $f['step']);
        $notional = $qty * $price;
        if ($notional < G_MIN_NOTIONAL) {
            $qty = G_MIN_NOTIONAL / $price;
            $qty = max($f['mn'], $f['step'], round($qty / $f['step']) * $f['step']);
            lI(sprintf("[CALC] Ajuste por notional mínimo: qty %.4f ETH (notional %.2f USDT)", $qty, $qty * $price));
        }
        if (abs($qty - $this->lastLoggedQty) > 0.0001) {
            lI(sprintf("[CALC] Qty: %.4f ETH (cap=%.2f mrg/niv=%.4f notional=%.2f)", $qty, $effectiveCap, $marginPerLevel, $qty * $price));
            $this->lastLoggedQty = $qty;
        }
        return $qty;
    }

    private function buildGrid($price) {
        $cfg = $this->cfg; if (!$cfg) return;
        $elapsed = time() - $this->lastGridBuild;
        if ($this->lastGridBuild > 0 && $elapsed < G_MIN_BUILD_INTERVAL) {
            lW(sprintf("[GRID] Anti-churn: última build hace %ds (mín %ds)", $elapsed, G_MIN_BUILD_INTERVAL));
            return;
        }
        $levels   = G_FIXED_LEVELS;
        $spacing  = (float)(isset($cfg['spacing_pct']) ? $cfg['spacing_pct'] : G_BASE_SPACING);
        $longLev  = (int)(isset($cfg['long_levels']) ? $cfg['long_levels'] : G_LONG_LEVELS);
        $shortLev = (int)(isset($cfg['short_levels']) ? $cfg['short_levels'] : G_SHORT_LEVELS);
        $dir      = isset($cfg['direction']) ? $cfg['direction'] : 'SIDEWAYS';
        $qty      = (float)(isset($cfg['qty_per_level']) ? $cfg['qty_per_level'] : 0);
        $f        = $this->api->filters(G_SYM);
        $cfgId    = (int)$cfg['id'];
        $balance = $this->api->balance();
        if ($balance <= 0) { lW("[GRID] Balance 0, usando capital teórico"); $balance = G_CAPITAL; }
        else { lI(sprintf("[GRID] Balance disponible: %.4f USDT", $balance)); }
        if ($balance < G_CAPITAL * 0.1) {
            lE("[GRID] Balance real ({$balance}) < 10% capital (" . G_CAPITAL . "). Pausando.");
            dbx(function($d) { return $d->prepare("UPDATE grid_configs SET paused_reason='Saldo insuficiente' WHERE symbol=?")->execute([G_SYM]); });
            $this->gridBuilt = false; return;
        } else {
            dbx(function($d) { return $d->prepare("UPDATE grid_configs SET paused_reason=NULL WHERE symbol=?")->execute([G_SYM]); });
        }
        if ($qty <= 0) {
            $qty = $this->calcQty($price, $levels, $f, $balance);
            dbx(function($d) use ($qty, $cfgId) { return $d->prepare("UPDATE grid_configs SET qty_per_level=? WHERE id=?")->execute([$qty, $cfgId]); });
        }
        // Ensure qty meets exchange minimum
        if ($qty < $f['mn']) {
            $qty = $f['mn'];
            dbx(function($d) use ($qty, $cfgId) { return $d->prepare("UPDATE grid_configs SET qty_per_level=? WHERE id=?")->execute([$qty, $cfgId]); });
            lI(sprintf("[GRID] Ajustando qty a mínimo exchange: %.4f ETH", $qty));
        }
        lI(sprintf("[GRID] Construyendo $%.2f | %s: L=%d S=%d spc=%.4f%% qty=%.4f", $price, $dir, $longLev, $shortLev, $spacing * 100, $qty));
        $placed = 0; $errors = 0; $usedMargin = 0.0;
        for ($i = 1; $i <= $longLev; $i++) {
            $p = round($price * (1 - $spacing * $i), $f['pp']); if ($p <= 0) continue;
            $reqMargin = ($qty * $p) / G_LEVERAGE;
            if ($reqMargin > ($balance - $usedMargin) * 0.95) { lW("[GRID] Margen insuficiente BUY L$i"); continue; }
            try {
                $res = $this->api->limitOrder(G_SYM, 'Buy', $qty, $p, false, true);
                dbx(function($d) use ($cfgId, $dir, $i, $res, $p, $qty) {
                    return $d->prepare("INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status) VALUES(?,?,?,?,?,?,?,?,?,'OPEN')")
                        ->execute([$cfgId, G_SYM, $dir, $i, 'BUY', 'ENTRY', $res['orderId'], $p, $qty]);
                });
                $placed++; $usedMargin += $reqMargin;
            } catch (\Exception $e) { lW("[GRID] BUY L{$i}: " . $e->getMessage()); $errors++; }
            usleep(120000);
        }
        for ($i = 1; $i <= $shortLev; $i++) {
            $p = round($price * (1 + $spacing * $i), $f['pp']);
            $reqMargin = ($qty * $p) / G_LEVERAGE;
            if ($reqMargin > ($balance - $usedMargin) * 0.95) { lW("[GRID] Margen insuficiente SELL L$i"); continue; }
            try {
                $res = $this->api->limitOrder(G_SYM, 'Sell', $qty, $p, false, true);
                dbx(function($d) use ($cfgId, $dir, $i, $res, $p, $qty) {
                    return $d->prepare("INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status) VALUES(?,?,?,?,?,?,?,?,?,'OPEN')")
                        ->execute([$cfgId, G_SYM, $dir, -$i, 'SELL', 'ENTRY', $res['orderId'], $p, $qty]);
                });
                $placed++; $usedMargin += $reqMargin;
            } catch (\Exception $e) { lW("[GRID] SELL L{$i}: " . $e->getMessage()); $errors++; }
            usleep(120000);
        }
        $this->gridBuilt = ($placed > 0);
        $this->lastGridBuild = $placed > 0 ? time() : 0;
        lI(sprintf("[GRID] ✓ %d/%d órdenes | %d err | Margen: %.2f USDT", $placed, $levels, $errors, $usedMargin));
        if ($placed == 0 && $errors > 0) {
            lW("[GRID] Sin órdenes colocadas. Reduciendo niveles...");
            $newLong  = max(G_MIN_LEVELS, $longLev - 1);
            $newShort = max(G_MIN_LEVELS, $shortLev - 1);
            $newLevels = $newLong + $newShort;
            dbx(function($d) use ($newLevels, $newLong, $newShort) {
                return $d->prepare("UPDATE grid_configs SET levels=?, long_levels=?, short_levels=? WHERE symbol=?")
                    ->execute([$newLevels, $newLong, $newShort, G_SYM]);
            });
            $this->cfg['levels'] = $newLevels;
            $this->cfg['long_levels']  = $newLong;
            $this->cfg['short_levels'] = $newShort;
            $this->gridBuilt = false;
        }
    }

    private function checkFills($price) {
        $openOrders = $this->api->getOpenOrders(G_SYM);
        $localOpens = dbx(function($d) {
            return $d->query("SELECT * FROM grid_orders WHERE symbol='" . G_SYM . "' AND status='OPEN' AND order_id IS NOT NULL LIMIT 60")->fetchAll();
        }) ?? [];
        if (empty($localOpens)) return;
        $apiEmpty = empty($openOrders);
        if ($apiEmpty) {
            lW("[FILLS] API devolvió 0 órdenes abiertas. Verificando individualmente (" . count($localOpens) . " locales)...");
        }
        foreach ($localOpens as $order) {
            $oid  = $order['order_id'];
            $real = isset($openOrders[$oid]) ? $openOrders[$oid] : null;
            if ($real && !$apiEmpty) {
                $statusMap = ['New' => 'NEW', 'PartiallyFilled' => 'PARTIALLY_FILLED',
                              'Filled' => 'FILLED', 'Cancelled' => 'CANCELED',
                              'Rejected' => 'CANCELED', 'Expired' => 'CANCELED'];
                $status = isset($statusMap[$real['status']]) ? $statusMap[$real['status']] : 'UNKNOWN';
                if ($status === 'FILLED') {
                    $this->onFill($order, $real, $price);
                } elseif (in_array($status, ['CANCELED', 'EXPIRED'])) {
                    dbx(function($d) use ($order) { return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE id=?")->execute([$order['id']]); });
                }
            } else {
                $age = time() - strtotime(isset($order['created_at']) ? $order['created_at'] : date('Y-m-d H:i:s'));
                if ($age < 30) continue;
                try {
                    $info = $this->api->getOrder(G_SYM, $oid);
                    if ($info['status'] === 'FILLED') {
                        $this->onFill($order, $info, $price);
                    } elseif (in_array($info['status'], ['CANCELED', 'EXPIRED'])) {
                        dbx(function($d) use ($order) { return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE id=?")->execute([$order['id']]); });
                    }
                } catch (\Exception $e) {}
            }
        }
    }

    // ========== FUNCIONES CORREGIDAS ==========
    
    /**
     * Confirma que la posición exista en el exchange.
     * Ahora permite que el tamaño real sea MAYOR o igual al esperado
     * (posiciones acumuladas) y extiende el tiempo de espera a ~12 segundos.
     */
    private function confirmPositionExists($entrySide, $expectedQty) {
        // Aumentado a 12s (6 intentos de 2s cada uno, total 12s)
        $sleepMs = [2000000, 2000000, 2000000, 2000000, 2000000, 2000000];
        $targetSide = ($entrySide === 'BUY') ? 'Buy' : 'Sell';
        for ($i = 0; $i < count($sleepMs); $i++) {
            $positions = $this->api->positions(G_SYM);
            foreach ($positions as $pos) {
                if (($pos['side'] ?? '') !== $targetSide) continue;
                $sz = abs($pos['positionAmt'] ?? ($pos['size'] ?? 0));
                if ($sz >= $expectedQty * 0.98) {
                    lI("[POSCONF] Posición {$targetSide} detectada (qty={$sz}, esperada={$expectedQty})");
                    return true;
                }
            }
            if ($i < count($sleepMs)-1) {
                lW("[POSCONF] Esperando posición {$targetSide} (intento " . ($i+1) . "/" . count($sleepMs) . ")");
                usleep($sleepMs[$i]);
            }
        }
        lW("[POSCONF] No se detectó posición {$targetSide} después de " . (array_sum($sleepMs)/1e6) . "s");
        return false;
    }

    /**
     * Verifica si ya existe una posición abierta en el exchange para un lado dado,
     * con tamaño al menos el 95% del esperado.
     */
    private function hasOpenPositionForSide($side, $expectedQty) {
        $positions = $this->api->positions(G_SYM);
        $targetSide = ($side === 'BUY') ? 'Buy' : 'Sell';
        foreach ($positions as $pos) {
            if (($pos['side'] ?? '') !== $targetSide) continue;
            $sz = abs($pos['positionAmt'] ?? ($pos['size'] ?? 0));
            if ($sz >= $expectedQty * 0.95) return true;
        }
        return false;
    }

    private function hasOpenEntryForLevel($level) {
        $count = dbx(function($d) use ($level) {
            return $d->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='" . G_SYM . "' AND grid_level={$level} AND grid_role='ENTRY' AND status='OPEN'")->fetchColumn();
        }) ?? 0;
        return $count > 0;
    }

    private function onFill($order, $info, $price) {
        $cfg = $this->cfg;
        $spacing = (float)(isset($cfg['spacing_pct']) ? $cfg['spacing_pct'] : G_BASE_SPACING);
        $qty     = (float)$order['qty'];
        $fillPx  = (float)(isset($info['avgPrice']) && $info['avgPrice'] > 0 ? $info['avgPrice'] : $order['price']);
        $side    = $order['side']; $role = $order['grid_role'];
        $f       = $this->api->filters(G_SYM);
        $isRec   = (int)(isset($order['is_recovery']) ? $order['is_recovery'] : 0);
        dbx(function($d) use ($fillPx, $order) {
            return $d->prepare("UPDATE grid_orders SET status='FILLED',filled_at=NOW(),exit_price=? WHERE id=?")->execute([$fillPx, $order['id']]);
        });
        if ($role === 'ENTRY') {
            $positionFound = $this->confirmPositionExists($side, $qty);
            if ($positionFound) {
                $exitSide = ($side === 'BUY') ? 'SELL' : 'BUY';
                $bySide   = ($exitSide === 'BUY') ? 'Buy' : 'Sell';
                $exitPx   = ($side === 'BUY') ? round($fillPx * (1 + $spacing), $f['pp']) : round($fillPx * (1 - $spacing), $f['pp']);
                if ($exitPx <= 0) { lW("[FILL] exitPx inválido"); return; }
                try {
                    $res = $this->api->limitOrder(G_SYM, $bySide, $qty, $exitPx, true, true);
                    dbx(function($d) use ($cfg, $order, $exitSide, $res, $exitPx, $qty, $isRec) {
                        return $d->prepare("INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,linked_order,is_recovery) VALUES(?,?,?,?,?,?,?,?,?,'OPEN',?,?)")
                            ->execute([(int)$cfg['id'], G_SYM, $cfg['direction'], $order['grid_level'], $exitSide, 'EXIT', $res['orderId'], $exitPx, $qty, $order['id'], $isRec]);
                    });
                    lI(sprintf("[FILL] ENTRY %s $%.2f → EXIT %s $%.2f qty=%.4f", $side, $fillPx, $exitSide, $exitPx, $qty));
                } catch (\Exception $e) { lW("[FILL] EXIT fail: " . $e->getMessage()); }
            } else {
                usleep(2000000);
                $positionFound = $this->confirmPositionExists($side, $qty);
                if ($positionFound) {
                    lI("[FILL] Posición detectada en segundo intento, procediendo con EXIT normal");
                    $exitSide = ($side === 'BUY') ? 'SELL' : 'BUY';
                    $bySide   = ($exitSide === 'BUY') ? 'Buy' : 'Sell';
                    $exitPx   = ($side === 'BUY') ? round($fillPx * (1 + $spacing), $f['pp']) : round($fillPx * (1 - $spacing), $f['pp']);
                    if ($exitPx > 0) {
                        try {
                            $res = $this->api->limitOrder(G_SYM, $bySide, $qty, $exitPx, true, true);
                            dbx(function($d) use ($cfg, $order, $exitSide, $res, $exitPx, $qty, $isRec) {
                                return $d->prepare("INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,linked_order,is_recovery) VALUES(?,?,?,?,?,?,?,?,?,'OPEN',?,?)")
                                    ->execute([(int)$cfg['id'], G_SYM, $cfg['direction'], $order['grid_level'], $exitSide, 'EXIT', $res['orderId'], $exitPx, $qty, $order['id'], $isRec]);
                            });
                            lI(sprintf("[FILL] (reintento) ENTRY %s $%.2f → EXIT %s $%.2f qty=%.4f", $side, $fillPx, $exitSide, $exitPx, $qty));
                            return;
                        } catch (\Exception $e) { lW("[FILL] EXIT fail en reintento: " . $e->getMessage()); }
                    }
                }
                if ($this->hasOpenPositionForSide($side, $qty)) {
                    lI("[FILL] Posición {$side} existe en la API (acumulada). Creando EXIT normalmente.");
                    $exitSide = ($side === 'BUY') ? 'SELL' : 'BUY';
                    $bySide   = ($exitSide === 'BUY') ? 'Buy' : 'Sell';
                    $exitPx   = ($side === 'BUY') ? round($fillPx * (1 + $spacing), $f['pp']) : round($fillPx * (1 - $spacing), $f['pp']);
                    if ($exitPx > 0) {
                        try {
                            $res = $this->api->limitOrder(G_SYM, $bySide, $qty, $exitPx, true, true);
                            dbx(function($d) use ($cfg, $order, $exitSide, $res, $exitPx, $qty, $isRec) {
                                return $d->prepare("INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,linked_order,is_recovery) VALUES(?,?,?,?,?,?,?,?,?,'OPEN',?,?)")
                                    ->execute([(int)$cfg['id'], G_SYM, $cfg['direction'], $order['grid_level'], $exitSide, 'EXIT', $res['orderId'], $exitPx, $qty, $order['id'], $isRec]);
                            });
                            lI(sprintf("[FILL] (posición acumulada) ENTRY %s $%.2f → EXIT %s $%.2f qty=%.4f", $side, $fillPx, $exitSide, $exitPx, $qty));
                            return;
                        } catch (\Exception $e) { lW("[FILL] EXIT fail en posición acumulada: " . $e->getMessage()); }
                    }
                }
                lW(sprintf("[FILL] ENTRY %s $%.2f sin posición → reciclando (último recurso)", $side, $fillPx));
                $this->recycleEntryDirect($order, $fillPx, $price, $spacing, $qty, $f, $isRec);
            }
        } elseif ($role === 'EXIT') {
            $entryRow = dbx(function($d) use ($order) {
                $s = $d->prepare("SELECT * FROM grid_orders WHERE id=?");
                $s->execute([$order['linked_order']]);
                return $s->fetch();
            });
            $entryPx  = $entryRow ? (float)$entryRow['price'] : (float)$order['price'];
            $isTaker  = isset($info['orderType']) && $info['orderType'] === 'Market';
            $pnl      = $this->calcPnl($side, $entryPx, $fillPx, $qty, $isTaker);
            dbx(function($d) use ($pnl, $order) { return $d->prepare("UPDATE grid_orders SET pnl_usd=? WHERE id=?")->execute([$pnl, $order['id']]); });
            lI(sprintf("[FILL] EXIT %s $%.2f PnL=%.4f USDT %s%s", $side, $fillPx, $pnl, $pnl >= 0 ? '✅' : '⚠️', $isTaker ? ' (taker)' : ''));
            $today = $this->getPnlToday();
            if ($today > $this->peakPnl) $this->peakPnl = $today;
            $this->recycleEntry($order, $fillPx, $price, $spacing, $qty, $f, $isRec);
        }
    }

    private function calcPnl($exitSide, $entryPx, $exitPx, $qty, $isTaker = false) {
        $gross = ($exitSide === 'SELL') ? ($exitPx - $entryPx) * $qty : ($entryPx - $exitPx) * $qty;
        // Entry order was PostOnly limit (maker fee). Exit: taker if market, maker if limit PostOnly.
        $entryFee = $entryPx * $qty * G_MAKER_FEE;
        $exitFee  = $exitPx * $qty * ($isTaker ? G_TAKER_FEE : G_MAKER_FEE);
        return round($gross - $entryFee - $exitFee, 8);
    }

    private function recycleEntry($exitOrder, $fillPx, $currentPrice, $spacing, $qty, $f, $isRec) {
        $cfg = $this->cfg; $cfgId = (int)$cfg['id'];
        $newSide = ($exitOrder['side'] === 'SELL') ? 'BUY' : 'SELL';
        $level = $exitOrder['grid_level'];
        if ($this->hasOpenEntryForLevel($level)) {
            lI("[RECYCLE] Ya existe ENTRY abierta para nivel {$level}, omitiendo reciclaje.");
            return;
        }
        $bySide  = ($newSide === 'BUY') ? 'Buy' : 'Sell';
        $newPx   = ($newSide === 'BUY') ? round($currentPrice * (1 - $spacing), $f['pp']) : round($currentPrice * (1 + $spacing), $f['pp']);
        try {
            $res = $this->api->limitOrder(G_SYM, $bySide, $qty, $newPx, false, true);
            dbx(function($d) use ($cfgId, $cfg, $exitOrder, $newSide, $res, $newPx, $qty, $isRec) {
                return $d->prepare("INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,is_recovery) VALUES(?,?,?,?,?,?,?,?,?,'OPEN',?)")
                    ->execute([$cfgId, G_SYM, $cfg['direction'], $exitOrder['grid_level'], $newSide, 'ENTRY', $res['orderId'], $newPx, $qty, $isRec]);
            });
            lI(sprintf("[RECYCLE] ENTRY %s $%.2f", $newSide, $newPx));
        } catch (\Exception $e) { lW("[RECYCLE] " . $e->getMessage()); }
    }

    private function recycleEntryDirect($order, $fillPx, $price, $spacing, $qty, $f, $isRec) {
        $cfg = $this->cfg; $cfgId = (int)$cfg['id'];
        $newSide = $order['side'];
        $level = $order['grid_level'];
        if ($this->hasOpenEntryForLevel($level)) {
            lI("[RECYCLE_D] Ya existe ENTRY abierta para nivel {$level}, omitiendo reciclaje.");
            return;
        }
        if ($this->hasOpenPositionForSide($newSide, $qty)) {
            lI("[RECYCLE_D] Ya existe posición {$newSide} abierta, omitiendo reciclaje.");
            return;
        }
        $bySide  = ($newSide === 'BUY') ? 'Buy' : 'Sell';
        $newPx   = ($newSide === 'BUY') ? round($price * (1 - $spacing), $f['pp']) : round($price * (1 + $spacing), $f['pp']);
        try {
            $res = $this->api->limitOrder(G_SYM, $bySide, $qty, $newPx, false, true);
            dbx(function($d) use ($cfgId, $cfg, $order, $newSide, $res, $newPx, $qty, $isRec) {
                return $d->prepare("INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,is_recovery) VALUES(?,?,?,?,?,?,?,?,?,'OPEN',?)")
                    ->execute([$cfgId, G_SYM, $cfg['direction'], $order['grid_level'], $newSide, 'ENTRY', $res['orderId'], $newPx, $qty, $isRec]);
            });
            lI(sprintf("[RECYCLE_D] ENTRY %s $%.2f", $newSide, $newPx));
        } catch (\Exception $e) { lW("[RECYCLE_D] " . $e->getMessage()); }
    }

    private function riskCheck($price) {
        $pnlTdy  = $this->getPnlToday();
        $lossPct = $pnlTdy < 0 ? abs($pnlTdy) / G_CAPITAL * 100 : 0;
        if ($lossPct >= G_RECOVERY_LOSS_PCT && !(isset($this->cfg['recovery_active']) && $this->cfg['recovery_active'])) {
            lW(sprintf("[RECOVERY] Pérdida diaria %.2f%% → activando", $lossPct));
            $this->enterRecovery($price, $pnlTdy);
        }
        if ($lossPct >= G_MAX_DAILY_LOSS) {
            lE(sprintf("[RISK] Límite diario %.1f%% → pausa 20min", G_MAX_DAILY_LOSS));
            $this->api->cancelAll(G_SYM);
            $this->closeAllPositions();
            $this->gridBuilt = false;
            sleep(1200);
        }
        $positions = $this->api->positions(G_SYM);
        foreach ($positions as $pos) {
            $upnl = (float)(isset($pos['unRealizedProfit']) ? $pos['unRealizedProfit'] : 0);
            $posAmt = (float)(isset($pos['positionAmt']) ? $pos['positionAmt'] : (isset($pos['size']) ? $pos['size'] : 0));
            $entryPx = (float)(isset($pos['entryPrice']) ? $pos['entryPrice'] : 0);
            $notional = abs($posAmt) * abs($entryPx);
            if ($notional > 0 && abs($upnl) / $notional * 100 >= G_HARD_STOP_PCT && $upnl < 0) {
                lE(sprintf("[HARD_STOP] uPnL $%.4f → cierre forzoso", $upnl));
                try { $this->api->marketClose(G_SYM, $pos['side'], abs($posAmt)); }
                catch (\Exception $e) { lW("[HARD_STOP] " . $e->getMessage()); }
            }
        }
        if ($this->liquidationProtector !== null) {
            $lpPositions = $this->api->positions(G_SYM);
            $balance = $this->api->balance();
            $this->liquidationProtector->evaluate($price, $lpPositions, $balance);
        }
        $this->checkLiquidationRisk($price);
    }

    private function checkLiquidationRisk($price) {
        $positions = $this->api->positions(G_SYM);
        foreach ($positions as $pos) {
            $liq = (float)(isset($pos['liquidationPrice']) ? $pos['liquidationPrice'] : 0);
            if ($liq <= 0) continue;
            $distancePct = abs($liq - $price) / $price * 100;
            if ($distancePct < 15) {
                $posAmt = (float)(isset($pos['positionAmt']) ? $pos['positionAmt'] : (isset($pos['size']) ? $pos['size'] : 0));
                lE(sprintf("[LIQ_RISK] Posición %s a solo %.1f%% de liquidación (liq=%.2f). Cerrando.", $pos['side'], $distancePct, $liq));
                $this->api->marketClose(G_SYM, $pos['side'], abs($posAmt));
            }
        }
    }

    /**
     * Espera a que se llenen las ENTRYs pendientes antes de cancelar/reconstruir grid.
     * Evita race condition: fill ocurre justo después de cancelAll → posición huérfana.
     */
    private function waitForPendingEntryFills(int $maxWaitSec = 10): void {
        $deadline = time() + $maxWaitSec;
        while (time() < $deadline) {
            $pendingEntries = dbx(function($d) {
                return $d->query("SELECT order_id FROM grid_orders WHERE symbol='" . G_SYM . "' AND grid_role='ENTRY' AND status='OPEN'")->fetchAll();
            }) ?? [];
            if (empty($pendingEntries)) return;
            $openOrders = $this->api->getOpenOrders(G_SYM);
            $allFilled = true;
            foreach ($pendingEntries as $ord) {
                $oid = $ord['order_id'];
                if (isset($openOrders[$oid])) {
                    $allFilled = false;
                    break;
                }
                // Check if filled via individual query
                try {
                    $info = $this->api->getOrder(G_SYM, $oid);
                    if ($info['status'] !== 'FILLED') { $allFilled = false; break; }
                } catch (\Exception $e) { $allFilled = false; break; }
            }
            if ($allFilled) return;
            sleep(1);
        }
        lW("[WAIT] Timeout esperando fills de ENTRYs pendientes ({$maxWaitSec}s)");
    }

    private function enterRecovery($price, $pnlTdy) {
        $cfg = $this->cfg; $f = $this->api->filters(G_SYM);
        $spacing = min(G_MAX_SPACING, (float)(isset($cfg['spacing_pct']) ? $cfg['spacing_pct'] : G_BASE_SPACING) * 1.8);
        $levels  = G_MIN_LEVELS * 2;
        $qty     = $this->calcQty($price, $levels, $f);
        $dir     = isset($cfg['direction']) ? $cfg['direction'] : 'SIDEWAYS';
        $this->api->cancelAll(G_SYM);
        dbx(function($d) { return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE symbol=? AND status='OPEN'")->execute([G_SYM]); });
        dbx(function($d) use ($spacing, $qty) { return $d->prepare("UPDATE grid_configs SET recovery_active=1,spacing_pct=?,qty_per_level=? WHERE symbol=?")->execute([$spacing, $qty, G_SYM]); });
        $this->cfg['recovery_active'] = 1; $this->cfg['spacing_pct'] = $spacing; $this->cfg['qty_per_level'] = $qty;
        $this->gridBuilt = false; $this->lastGridBuild = 0;
        $balance = $this->api->balance(); if ($balance <= 0) $balance = G_CAPITAL;
        $halfLev = (int)(G_MIN_LEVELS); $placed = 0;
        for ($i = 1; $i <= $halfLev; $i++) {
            $p = round($price * (1 - $spacing * $i), $f['pp']); if ($p <= 0) continue;
            $reqM = ($qty * $p) / G_LEVERAGE; if ($reqM > $balance * 0.9) { lW("[REC] Margen insuficiente BUY L$i"); continue; }
            try {
                $res = $this->api->limitOrder(G_SYM, 'Buy', $qty, $p, false, true);
                dbx(function($d) use ($cfg, $dir, $i, $res, $p, $qty) {
                    return $d->prepare("INSERT INTO grid_orders(config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,is_recovery)VALUES(?,?,?,?,?,?,?,?,?,'OPEN',1)")
                        ->execute([(int)$cfg['id'], G_SYM, $dir, $i, 'BUY', 'ENTRY', $res['orderId'], $p, $qty]);
                });
                $placed++;
            } catch (\Exception $e) { lW("[REC] BUY $i: " . $e->getMessage()); }
            usleep(120000);
        }
        for ($i = 1; $i <= $halfLev; $i++) {
            $p = round($price * (1 + $spacing * $i), $f['pp']);
            $reqM = ($qty * $p) / G_LEVERAGE; if ($reqM > $balance * 0.9) { lW("[REC] Margen insuficiente SELL L$i"); continue; }
            try {
                $res = $this->api->limitOrder(G_SYM, 'Sell', $qty, $p, false, true);
                dbx(function($d) use ($cfg, $dir, $i, $res, $p, $qty) {
                    return $d->prepare("INSERT INTO grid_orders(config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,is_recovery)VALUES(?,?,?,?,?,?,?,?,?,'OPEN',1)")
                        ->execute([(int)$cfg['id'], G_SYM, $dir, -$i, 'SELL', 'ENTRY', $res['orderId'], $p, $qty]);
                });
                $placed++;
            } catch (\Exception $e) { lW("[REC] SELL $i: " . $e->getMessage()); }
            usleep(120000);
        }
        $this->gridBuilt = ($placed > 0);
        if ($placed > 0) $this->lastGridBuild = time();
        lI(sprintf("[RECOVERY] %d órdenes recovery spacing=%.3f%%", $placed, $spacing * 100));
    }

    private function profitOptimize($price) {
        $pnlTdy = $this->getPnlToday();
        $balance = $this->api->balance(); if ($balance <= 0) $balance = G_CAPITAL;
        $pct = $pnlTdy / $balance * 100;
        if ($pnlTdy > $this->peakPnl) {
            $this->peakPnl = $pnlTdy;
            dbx(function($d) { return $d->prepare("UPDATE grid_configs SET peak_pnl_today=? WHERE symbol=?")->execute([$this->peakPnl, G_SYM]); });
        }
        $cooldownOk = (time() - $this->lastCompound) >= G_COMPOUND_CD;
        if ($pct >= G_COMPOUND_THR && !(isset($this->cfg['recovery_active']) && $this->cfg['recovery_active']) && $cooldownOk) {
            $f = $this->api->filters(G_SYM); $levels = G_FIXED_LEVELS;
            $oldQty  = (float)(isset($this->cfg['qty_per_level']) ? $this->cfg['qty_per_level'] : 0);
            if ($oldQty <= 0) $oldQty = $this->calcQty($price, $levels, $f);
            $maxAllowed = ($oldQty * 3.0);
            $hardCap    = ($balance * 0.12 * G_LEVERAGE) / $price;
            $newQty  = min($oldQty * G_COMPOUND_MULT, $maxAllowed, $hardCap);
            $newQty  = max($f['step'], round($newQty / $f['step']) * $f['step']);
            if (abs($newQty - $oldQty) > $f['step'] * 0.3) {
                dbx(function($d) use ($newQty) { return $d->prepare("UPDATE grid_configs SET qty_per_level=? WHERE symbol=?")->execute([$newQty, G_SYM]); });
                $this->cfg['qty_per_level'] = $newQty;
                $this->lastCompound = time();
                lI(sprintf("[COMPOUND] PnL +%.2f%% (bal=%.2f) → qty %.4f→%.4f ETH", $pct, $balance, $oldQty, $newQty));
            }
        }
        if ((isset($this->cfg['recovery_active']) && $this->cfg['recovery_active']) && $pnlTdy >= 0) {
            lI("[RECOVERY] PnL recuperado → modo normal");
            dbx(function($d) { return $d->prepare("UPDATE grid_configs SET recovery_active=0,spacing_pct=? WHERE symbol=?")->execute([G_BASE_SPACING, G_SYM]); });
            $this->cfg['recovery_active'] = 0; $this->cfg['spacing_pct'] = G_BASE_SPACING;
            $this->api->cancelAll(G_SYM);
            dbx(function($d) { return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE symbol=? AND status='OPEN'")->execute([G_SYM]); });
            $this->gridBuilt = false; $this->lastGridBuild = 0;
        }
    }

    private function breakoutCheck($price) {
        if (!$this->gridBuilt) return;
        $r = dbx(function($d) {
            return $d->query("SELECT MIN(price) mn,MAX(price) mx FROM grid_orders WHERE symbol='" . G_SYM . "' AND status='OPEN'")->fetch();
        });
        if (!$r || !$r['mn']) return;
        $range = (float)$r['mx'] - (float)$r['mn']; $margin = $range * 0.30;
        if ($price < (float)$r['mn'] - $margin || $price > (float)$r['mx'] + $margin) {
            $lastFill = dbx(function($d) {
                return $d->query("SELECT MAX(filled_at) FROM grid_orders WHERE symbol='" . G_SYM . "' AND status='FILLED' AND filled_at IS NOT NULL")->fetchColumn();
            });
            if ($lastFill && (time() - strtotime($lastFill)) < 90) {
                lI(sprintf("[BREAKOUT] $%.2f fuera rango pero fill reciente (%ds), esperando...", $price, time() - strtotime($lastFill)));
                return;
            }
            lI(sprintf("[BREAKOUT] $%.2f fuera [%.2f-%.2f] → rebuild", $price, $r['mn'], $r['mx']));
            $this->api->cancelAll(G_SYM);
            dbx(function($d) { return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE symbol=? AND status='OPEN'")->execute([G_SYM]); });
            $this->gridBuilt = false; $this->lastGridBuild = 0;
        }
    }

    private function getPnlToday() {
        try {
            $r = dbx(function($d) {
                return $d->query("SELECT COALESCE(SUM(pnl_usd),0) AS p FROM grid_orders WHERE symbol='" . G_SYM . "' AND grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE()")->fetch();
            });
            $pnl = $r ? (float)$r['p'] : 0.0;
            foreach ($this->api->positions(G_SYM) as $pos) {
                $pnl += (float)($pos['unRealizedProfit'] ?? 0);
            }
            return $pnl;
        } catch (\Exception $e) { lE("[PNL] " . $e->getMessage()); return 0.0; }
    }

    private function logCycleSummary($price) {
        $pnl = $this->getPnlToday();
        $openCnt = dbx(function($d) { return $d->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='" . G_SYM . "' AND status='OPEN'")->fetchColumn(); }) ?? 0;
        $fillsCnt = dbx(function($d) { return $d->query("SELECT COUNT(*) FROM grid_orders WHERE symbol='" . G_SYM . "' AND grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE()")->fetchColumn(); }) ?? 0;
        lI(sprintf("[CICLO #%d] $%.2f | PnL hoy=%.4f USDT | Abiertos=%d | Fills hoy=%d | Grid=%s | Dir=%s",
            $this->cycleN, $price, $pnl, $openCnt, $fillsCnt,
            $this->gridBuilt ? 'ON' : 'OFF', isset($this->cfg['direction']) ? $this->cfg['direction'] : '?'));
    }

    private function checkControl() {
        global $CTRL; if (!file_exists($CTRL)) return;
        $cmd = json_decode(file_get_contents($CTRL), true); @unlink($CTRL);
        if (!is_array($cmd)) return;
        if (isset($cmd['config_update']) && is_array($cmd['config_update'])) {
            $this->applyConfigUpdate($cmd['config_update']);
        }
        switch (isset($cmd['action']) ? $cmd['action'] : '') {
            case 'stop': $this->running = false; lI("[CTL] Stop"); break;
            case 'force_ai': $this->lastAI = 0; lI("[CTL] Forzando IA"); break;
            case 'reset_grid':
            case 'reset_pair':
                $this->api->cancelAll(G_SYM);
                dbx(function($d) { return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE symbol=? AND status='OPEN'")->execute([G_SYM]); });
                $this->gridBuilt = false; $this->lastGridBuild = 0; lI("[CTL] Grid reset"); break;
        }
    }

    private function applyConfigUpdate(array $updates) {
        $allowed = ['capital_usd', 'leverage', 'levels', 'long_levels', 'short_levels', 'spacing_pct'];
        $fields = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $updates) && is_numeric($updates[$k])) {
                $fields[$k] = (float)$updates[$k];
            }
        }
        if (empty($fields)) { lW("[CTL] config_update sin campos válidos"); return; }
        $set = []; $params = [];
        foreach ($fields as $k => $v) { $set[] = $k . '=?'; $params[] = $v; }
        $params[] = G_SYM;
        dbx(function($d) use ($set, $params) {
            return $d->prepare("UPDATE grid_configs SET " . implode(',', $set) . " WHERE symbol=?")->execute($params);
        });
        lI("[CTL] Config actualizada: " . json_encode($fields, JSON_UNESCAPED_UNICODE));
        $this->api->cancelAll(G_SYM);
        dbx(function($d) { return $d->prepare("UPDATE grid_orders SET status='CANCELED' WHERE symbol=? AND status='OPEN'")->execute([G_SYM]); });
        if (is_array($this->cfg)) {
            foreach ($fields as $k => $v) { $this->cfg[$k] = $v; }
        }
        $this->gridBuilt = false; $this->lastGridBuild = 0;
    }

    /**
     * Respeta el archivo de pausa escrito por LiquidationProtector::pause_bot_sec.
     * Devuelve true mientras el bot deba quedarse dormido (ya se hizo el sleep).
     */
    private function handlePause() {
        $pauseFile = sys_get_temp_dir() . '/bot_pause_' . getmypid() . '.tmp';
        if (!file_exists($pauseFile)) return false;
        $end = (int)file_get_contents($pauseFile);
        $remaining = $end - time();
        if ($remaining <= 0) { @unlink($pauseFile); return false; }
        if (time() - $this->lastPauseLog >= 60) {
            lW("[PAUSE] Bot en pausa por protección (restantes {$remaining}s)");
            $this->lastPauseLog = time();
        }
        sleep(min(G_CYCLE_SEC, $remaining));
        return true;
    }

    private function writeStatus($price) {
        global $STATUS; $cfg = $this->cfg ?? []; $pnlTdy = $this->getPnlToday(); $positions = [];
        try { $positions = $this->api->positions(G_SYM); } catch (\Exception $e) {}
        $pnl1h = dbx(function($d) {
            return $d->query("SELECT COALESCE(SUM(pnl_usd),0) p, COUNT(*) c FROM grid_orders WHERE symbol='" . G_SYM . "' AND grid_role='EXIT' AND status='FILLED' AND filled_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)")->fetch();
        });
        $avgPnlPerFill = (float)(dbx(function($d) {
            return $d->query("SELECT COALESCE(AVG(pnl_usd),0) FROM grid_orders WHERE symbol='" . G_SYM . "' AND grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE()")->fetchColumn();
        }) ?? 0);
        $mode   = (isset($cfg['recovery_active']) && $cfg['recovery_active']) ? 'RECOVERY' : 'NORMAL';
        $balance = $this->api->balance();
        
        file_put_contents($STATUS, json_encode([
            'ts' => date('Y-m-d H:i:s'), 'mode' => $mode, 'ai_engine' => 'Grid v15.4',
            'leverage' => G_LEVERAGE, 'real_balance' => $balance,
            'ml_accuracy' => $this->ml->getAccuracy(),
            'pairs' => [G_SYM => [
                'price'          => $price,
                'direction'      => isset($cfg['direction']) ? $cfg['direction'] : 'SIDEWAYS',
                'confidence'     => (int)(isset($cfg['confidence']) ? $cfg['confidence'] : 50),
                'ai_reason'      => isset($cfg['ai_reason']) ? $cfg['ai_reason'] : '',
                'last_ai_check'  => isset($cfg['last_ai_check']) ? $cfg['last_ai_check'] : null,
                'levels'         => (int)(isset($cfg['levels']) ? $cfg['levels'] : G_FIXED_LEVELS),
                'long_levels'    => (int)(isset($cfg['long_levels']) ? $cfg['long_levels'] : G_LONG_LEVELS),
                'short_levels'   => (int)(isset($cfg['short_levels']) ? $cfg['short_levels'] : G_SHORT_LEVELS),
                'spacing_pct'    => (float)(isset($cfg['spacing_pct']) ? $cfg['spacing_pct'] : G_BASE_SPACING),
                'leverage'       => G_LEVERAGE,
                'pnl_today'      => round($pnlTdy, 6),
                'peak_pnl'       => round($this->peakPnl, 6),
                'recovery_active'=> (bool)(isset($cfg['recovery_active']) && $cfg['recovery_active']),
                'grid_built'     => $this->gridBuilt,
                'fills_per_hour' => (int)(isset($pnl1h['c']) ? $pnl1h['c'] : 0),
                'pnl_1h'         => round((float)(isset($pnl1h['p']) ? $pnl1h['p'] : 0), 4),
                'avg_pnl_fill'   => round((float)$avgPnlPerFill, 4),
                'cycle_n'        => $this->cycleN,
                'real_positions' => $positions,
                'atr_predicted'  => $this->last_atr_predicho,
                'vl_used'        => ($this->last_vl_result !== null),
                'vl_direction'   => $this->last_vl_result['direction'] ?? null,
                'vl_confidence'  => $this->last_vl_result['confidence'] ?? null,
            ]],
        ], JSON_PRETTY_PRINT));
    }

    private function appendConf($conf, $dir) {
        global $CONF_HIST; $arr = [];
        if (file_exists($CONF_HIST)) $arr = json_decode(file_get_contents($CONF_HIST), true) ?? [];
        $arr[] = ['time' => date('Y-m-d H:i:s'), 'confidence' => $conf, 'direction' => $dir];
        if (count($arr) > 500) $arr = array_slice($arr, -500);
        file_put_contents($CONF_HIST, json_encode($arr));
    }

    public function stop() { $this->running = false; lI("[BOT] Stop señal recibida"); }
}
