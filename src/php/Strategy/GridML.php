<?php
declare(strict_types=1);

namespace BinanceBot\Strategy;

class GridML
{
    private $wf;
    private $weights = [];
    private $intercepts = [];
    private $scalerMean = null;
    private $scalerScale = null;
    private $featureNames = [];
    private $classes = ['DOWN', 'SIDEWAYS', 'UP'];
    private $lastMtime = 0;
    private $accuracy = 0.0;
    private $loadedOk = false;

    public function __construct($wf)
    {
        $this->wf = $wf;
        $this->load();
    }

    public function getAccuracy()
    {
        return $this->accuracy;
    }

    public function reloadIfUpdated()
    {
        $paths = [dirname(__DIR__) . '/' . basename($this->wf), $this->wf];
        foreach ($paths as $f) {
            if (!file_exists($f)) {
                continue;
            }
            $mtime = (int) filemtime($f);
            if ($mtime > $this->lastMtime) {
                $this->load();
                lI("[ML] Pesos actualizados desde disco (mtime=" . date('H:i:s', $mtime) . " acc={$this->accuracy})");
                return true;
            }
        }
        return false;
    }

    private function load()
    {
        $paths = [dirname(__DIR__) . '/' . basename($this->wf), $this->wf];
        foreach ($paths as $f) {
            if (!file_exists($f)) {
                continue;
            }
            $d = json_decode(file_get_contents($f), true);
            if (!is_array($d)) {
                continue;
            }
            $acc = (float) (isset($d['acc']) ? $d['acc'] : 0);
            if ($acc < G_ML_MIN_ACCURACY) {
                lW("[ML] Archivo $f tiene accuracy " . round($acc * 100, 1) . "% < " . (G_ML_MIN_ACCURACY * 100) . "% → IGNORADO. Manteniendo modelo anterior.");
                continue;
            }
            $this->weights      = isset($d['weights']) ? $d['weights'] : [];
            $this->intercepts   = isset($d['intercepts']) ? $d['intercepts'] : [0, 0, 0];
            $this->scalerMean   = isset($d['scaler_mean']) ? $d['scaler_mean'] : null;
            $this->scalerScale  = isset($d['scaler_scale']) ? $d['scaler_scale'] : null;
            $this->classes      = isset($d['classes']) ? $d['classes'] : ['DOWN', 'SIDEWAYS', 'UP'];
            $this->featureNames = array_keys($this->weights);
            $this->accuracy     = $acc;
            $this->lastMtime    = (int) filemtime($f);
            $this->loadedOk     = true;
            lI(sprintf(
                "[ML] Cargado: %s | acc=%.1f%% | features=%d | updated=%s",
                basename($f),
                $this->accuracy * 100,
                count($this->featureNames),
                isset($d['updated_at']) ? $d['updated_at'] : '?'
            ));
            return;
        }
        if (!$this->loadedOk) {
            lW("[ML] Sin archivo de pesos válido (accuracy < 85% o inexistente) — usando fallback RSI");
            $this->weights = [];
            $this->accuracy = 0;
        }
    }

    private function buildFeatures($candles, $price)
    {
        $cl = array_column($candles, 'c');
        $features = [];
        foreach ($this->featureNames as $feat) {
            switch ($feat) {
                case 'rsi_14':
                    $features[$feat] = rsiLast($cl);
                    break;
                case 'stoch_14':
                    $features[$feat] = stochLast($candles);
                    break;
                case 'macd_hist':
                    $features[$feat] = macdHistLast($cl);
                    break;
                case 'ema_diff_9_21':
                    $e9 = ema($cl, 9);
                    $e21 = ema($cl, 21);
                    $e9l = end($e9);
                    $e21l = end($e21);
                    $features[$feat] = ($e9l && $e21l && $price > 0) ? (($e9l - $e21l) / $price) : 0;
                    break;
                case 'vol_ratio':
                    $features[$feat] = volRatioLast($candles);
                    break;
                case 'bb_width':
                    $features[$feat] = bbWidth($candles);
                    break;
                case 'atr_pct':
                    $features[$feat] = atrPctLast($candles);
                    break;
                case 'vwap_ratio':
                    $vols = array_column($candles, 'v');
                    $cumTV = 0;
                    $cumV = 0;
                    for ($i = 0; $i < count($candles); $i++) {
                        $cc = $candles[$i];
                        $typ = ($cc['h'] + $cc['l'] + $cc['c']) / 3;
                        $cumTV += $typ * $vols[$i];
                        $cumV += $vols[$i];
                    }
                    $vwap = $cumV > 0 ? $cumTV / $cumV : $price;
                    $features[$feat] = $vwap > 0 ? $price / $vwap : 1;
                    break;
                case 'spread_pct':
                    $last = end($candles);
                    $features[$feat] = ($last['h'] - $last['l']) / $last['c'] * 100;
                    break;
                case 'momentum_5':
                    if (count($cl) >= 6) {
                        $prev = $cl[count($cl) - 6];
                        $curr = end($cl);
                        $features[$feat] = ($curr - $prev) / $prev * 100;
                    } else {
                        $features[$feat] = 0;
                    }
                    break;
                default:
                    $features[$feat] = 0;
            }
        }
        if ($this->scalerMean !== null && $this->scalerScale !== null) {
            $i = 0;
            foreach ($this->featureNames as $fn) {
                $val = $features[$fn];
                $sc  = (float) (isset($this->scalerScale[$i]) ? $this->scalerScale[$i] : 1);
                if ($sc == 0) {
                    $sc = 1;
                }
                $scaled = ($val - (float) (isset($this->scalerMean[$i]) ? $this->scalerMean[$i] : 0)) / $sc;
                $features[$fn] = max(-3.0, min(3.0, $scaled));
                $i++;
            }
        }
        return $features;
    }

    private function softmax($s)
    {
        $mx = max($s);
        $ex = array_map(function ($v) use ($mx) {
            return exp($v - $mx);
        }, $s);
        $sum = array_sum($ex);
        return array_map(function ($e) use ($sum) {
            return $e / $sum;
        }, $ex);
    }

    public function predict($candles)
    {
        if (empty($this->weights)) {
            return $this->fallback($candles);
        }
        try {
            $cl = array_column($candles, 'c');
            $price = end($cl);
            $feats = $this->buildFeatures($candles, $price);
            $scores = [];
            foreach ($this->classes as $i => $cls) {
                $score = (float) (isset($this->intercepts[$i]) ? $this->intercepts[$i] : 0);
                foreach ($this->featureNames as $fn) {
                    if (isset($this->weights[$fn][$cls])) {
                        $score += $feats[$fn] * (float) $this->weights[$fn][$cls];
                    }
                }
                $scores[] = $score;
            }
            $probs  = $this->softmax($scores);
            $maxIdx = (int) array_search(max($probs), $probs);
            $dir    = $this->classes[$maxIdx];
            $conf   = (int) round($probs[$maxIdx] * 100);
            lI(sprintf(
                "[ML] %s %d%% (D=%.0f%% S=%.0f%% U=%.0f%%) acc=%.1f%%",
                $dir,
                $conf,
                $probs[0] * 100,
                $probs[1] * 100,
                $probs[2] * 100,
                $this->accuracy * 100
            ));
            return [
                'direction'  => $dir,
                'confidence' => $conf,
                'probs'      => $probs,
                'reason'     => "ML:{$dir}({$conf}%)",
            ];
        } catch (Exception $e) {
            lW("[ML] " . $e->getMessage());
            return $this->fallback($candles);
        }
    }

    private function fallback($c)
    {
        $rsi = rsiLast(array_column($c, 'c'));
        $dir = $rsi > 58 ? 'UP' : ($rsi < 42 ? 'DOWN' : 'SIDEWAYS');
        return [
            'direction'  => $dir,
            'confidence' => 35,
            'probs'      => [0.33, 0.34, 0.33],
            'reason'     => "ML-fallback RSI=" . round($rsi, 1),
        ];
    }
}
