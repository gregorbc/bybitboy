#!/usr/bin/env python3
"""
ETH/USDT Grid Bot v15.4 – Python / MetaTrader 5
Puerto completo de bot.php v15.4

Características:
  - ML logístico (ml_weights_v2.json) + modelo de volatilidad (volatility_weights.json)
  - Blending ML 90% + heurístico 10%
  - ATR dinámico con predicción de volatilidad
  - Confirmación de dirección (2 ciclos consecutivos)
  - Recovery mode, compounding, breakout check
  - Hard stop, liquidation risk, daily loss limit
  - MySQL para tracking de órdenes/configs
  - MT5 como exchange (CFD ETHUSD / ETHUSDT)
  - Klines desde Binance FAPI (fallback Bybit público)
"""

import MetaTrader5 as mt5
import json, math, time, logging, os, sys, signal, traceback
import requests
import mysql.connector
from mysql.connector import pooling
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional

# ════════════════════════════════════════════════════════
# 1. CONFIGURACIÓN
# ════════════════════════════════════════════════════════
BASE_DIR = Path(__file__).parent

def load_config() -> dict:
    for p in [BASE_DIR.parent / "private" / "config.json", BASE_DIR / "config.json"]:
        if p.exists():
            return json.loads(p.read_text())
    sys.exit("ERROR: config.json no encontrado")

CFG = load_config()

# Credenciales MT5
MT5_LOGIN    = int(CFG.get("mt5", {}).get("login", 0))
MT5_PASSWORD = CFG.get("mt5", {}).get("password", "")
MT5_SERVER   = CFG.get("mt5", {}).get("server", "XMGlobal-MT5 3")

# MySQL
DB_HOST = CFG["mysql"]["host"]
DB_NAME = CFG["mysql"]["dbname"]
DB_USER = CFG["mysql"]["user"]
DB_PASS = CFG["mysql"]["password"]

# Paths
LOG_FILE     = CFG.get("paths", {}).get("log",        str(BASE_DIR / "bot.log"))
STATUS_FILE  = CFG.get("paths", {}).get("status",     str(BASE_DIR.parent / "private" / "grid_status.json"))
CTRL_FILE    = CFG.get("paths", {}).get("ctrl",       str(BASE_DIR.parent / "private" / "grid_control.json"))
CONF_HIST    = CFG.get("paths", {}).get("conf_hist",  str(BASE_DIR.parent / "private" / "grid_confidence.json"))
ML_WEIGHTS   = CFG.get("ml",   {}).get("weights_file", str(BASE_DIR / "ml_weights_v2.json"))
VOL_WEIGHTS  = str(BASE_DIR / "volatility_weights.json")

# ════════════════════════════════════════════════════════
# 2. CONSTANTES ESTRATÉGICAS (idénticas al PHP)
# ════════════════════════════════════════════════════════
G_SYM           = "ETHUSD"       # nombre exacto en MT5 (puede ser ETHUSDm, ETHUSDT)
G_CAPITAL       = 30.0
G_LEVERAGE      = 100
G_CYCLE_SEC     = 8
G_AI_INTERVAL   = 120
G_TF            = "5"
G_CANDLES       = 150
G_MIN_LEVELS    = 8
G_MAX_LEVELS    = 20
G_MIN_SPACING   = 0.0003
G_MAX_SPACING   = 0.0012
G_MARGIN_SAFETY = 0.65
G_MAKER_FEE     = 0.0001
G_MAX_DAILY_LOSS= 12.0
G_HARD_STOP_PCT = 3.0
G_RECOVERY_THR  = 1.0
G_COMPOUND_THR  = 1.5
G_COMPOUND_MULT = 1.05
G_COMPOUND_CD   = 300
G_MIN_NOTIONAL  = 3.0
G_FIXED_LEVELS  = 16
G_BASE_SPACING  = 0.0003
G_SPACING_ATR_MULT = 0.28
G_RECOVERY_LOSS_PCT = 3.0
G_MIN_BUILD_INTERVAL = 90
G_ML_BLEND_WEIGHT = 0.90
G_ML_MIN_ACCURACY = 0.85
G_MAGIC         = 15400

# ════════════════════════════════════════════════════════
# 3. LOGGER
# ════════════════════════════════════════════════════════
log = logging.getLogger("GridBot")
log.setLevel(logging.DEBUG)
fmt = logging.Formatter("%(asctime)s [%(levelname)s] %(message)s", "%Y-%m-%d %H:%M:%S")
fh = logging.FileHandler(LOG_FILE, encoding="utf-8")
fh.setFormatter(fmt)
sh = logging.StreamHandler(sys.stdout)
sh.setFormatter(fmt)
log.addHandler(fh)
log.addHandler(sh)

# Rotar log >12MB
def _rotate_log():
    if os.path.exists(LOG_FILE) and os.path.getsize(LOG_FILE) > 12 * 1024 * 1024:
        bak = LOG_FILE + "." + datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S") + ".bak"
        os.rename(LOG_FILE, bak)

# ════════════════════════════════════════════════════════
# 4. DATABASE
# ════════════════════════════════════════════════════════
_db_pool: Optional[pooling.MySQLConnectionPool] = None

def get_pool() -> pooling.MySQLConnectionPool:
    global _db_pool
    if _db_pool is None:
        _db_pool = pooling.MySQLConnectionPool(
            pool_name="gridpool", pool_size=5,
            host=DB_HOST, database=DB_NAME,
            user=DB_USER, password=DB_PASS,
            autocommit=True, charset="utf8mb4",
            time_zone="+00:00"
        )
    return _db_pool

def dbx(fn):
    """Ejecuta fn(cursor) con reconexión automática."""
    for attempt in range(3):
        try:
            conn = get_pool().get_connection()
            cur  = conn.cursor(dictionary=True)
            result = fn(cur)
            cur.close(); conn.close()
            return result
        except Exception as e:
            log.warning(f"[DB] intento {attempt+1}: {e}")
            time.sleep(1)
    return None

def db_init():
    def _f(cur):
        cur.execute("""
        CREATE TABLE IF NOT EXISTS grid_configs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            symbol VARCHAR(20) NOT NULL,
            direction VARCHAR(20) DEFAULT 'NEUTRAL',
            confidence INT DEFAULT 50,
            ai_reason VARCHAR(400) DEFAULT '',
            last_ai_check DATETIME,
            capital_usd DECIMAL(12,4),
            leverage INT DEFAULT 100,
            levels INT DEFAULT 10,
            spacing_pct DECIMAL(10,6) DEFAULT 0.000800,
            long_levels INT DEFAULT 5,
            short_levels INT DEFAULT 5,
            qty_per_level DECIMAL(20,8) DEFAULT 0,
            pp INT DEFAULT 2, qp INT DEFAULT 3,
            mode VARCHAR(20) DEFAULT 'NORMAL',
            recovery_active TINYINT(1) DEFAULT 0,
            peak_pnl_today DECIMAL(14,6) DEFAULT 0,
            status VARCHAR(10) DEFAULT 'ACTIVE',
            ml_accuracy DECIMAL(6,4) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sym (symbol)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4""")
        cur.execute("""
        CREATE TABLE IF NOT EXISTS grid_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            config_id INT, symbol VARCHAR(20),
            direction VARCHAR(20), grid_level INT,
            side VARCHAR(5), grid_role VARCHAR(5),
            order_id VARCHAR(80), price DECIMAL(20,8),
            exit_price DECIMAL(20,8), qty DECIMAL(20,8),
            status VARCHAR(12) DEFAULT 'OPEN',
            linked_order INT DEFAULT NULL,
            pnl_usd DECIMAL(14,8), is_recovery TINYINT(1) DEFAULT 0,
            filled_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sym (symbol), INDEX idx_status (status),
            INDEX idx_oid (order_id), INDEX idx_cfg (config_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4""")
        try:
            cur.execute("ALTER TABLE grid_configs ADD COLUMN ml_accuracy DECIMAL(6,4) DEFAULT 0")
        except Exception:
            pass
    dbx(_f)
    log.info("[DB] Tablas v15.4 OK")

# ════════════════════════════════════════════════════════
# 5. INDICADORES TÉCNICOS
# ════════════════════════════════════════════════════════
def ema(values: list, period: int) -> list:
    if len(values) < period:
        return [None] * len(values)
    k = 2 / (period + 1)
    result = [None] * (period - 1)
    e = sum(values[:period]) / period
    result.append(e)
    for v in values[period:]:
        e = v * k + e * (1 - k)
        result.append(e)
    return result

def rsi_last(closes: list, period: int = 14) -> float:
    n = len(closes)
    if n <= period:
        return 50.0
    ag = al = 0.0
    for i in range(1, period + 1):
        d = closes[i] - closes[i - 1]
        if d > 0: ag += d
        else:     al += abs(d)
    ag /= period; al /= period
    for i in range(period + 1, n):
        d = closes[i] - closes[i - 1]
        ag = (ag * (period - 1) + max(d, 0)) / period
        al = (al * (period - 1) + max(-d, 0)) / period
    return 100.0 if al == 0 else round(100 - 100 / (1 + ag / al), 2)

def macd_hist_last(closes: list) -> float:
    ef = ema(closes, 12); es = ema(closes, 26)
    ml = [f - s for f, s in zip(ef, es) if f is not None and s is not None]
    if len(ml) < 9:
        return 0.0
    sig = ema(ml, 9)
    sv = next((v for v in reversed(sig) if v is not None), 0.0)
    return round(ml[-1] - sv, 8)

def atr_pct_last(candles: list, period: int = 14) -> float:
    if len(candles) < 2:
        return 0.0
    trs = []
    for i in range(1, len(candles)):
        h, l, c = candles[i]['h'], candles[i]['l'], candles[i - 1]['c']
        trs.append(max(h - l, abs(h - c), abs(l - c)))
    atr = sum(trs[-period:]) / min(len(trs), period)
    price = candles[-1]['c']
    return round(atr / price * 100, 4) if price > 0 else 0.0

def stoch_last(candles: list, period: int = 14) -> float:
    if len(candles) < period:
        return 50.0
    sl = candles[-period:]
    hh = max(c['h'] for c in sl)
    ll = min(c['l'] for c in sl)
    lc = sl[-1]['c']
    return (lc - ll) / (hh - ll) * 100 if hh != ll else 50.0

def vol_ratio_last(candles: list) -> float:
    vols = [c['v'] for c in candles]
    avg  = sum(vols[-20:]) / 20 if len(vols) >= 20 else sum(vols) / len(vols)
    return round(vols[-1] / avg, 2) if avg > 0 else 1.0

def bb_width(candles: list, period: int = 20) -> float:
    cl = [c['c'] for c in candles]
    if len(cl) < period:
        return 0.0
    sl = cl[-period:]
    avg = sum(sl) / period
    std = math.sqrt(sum((v - avg) ** 2 for v in sl) / period)
    return round(std * 4 / cl[-1] * 100, 4) if cl[-1] > 0 else 0.0

def vwap_ratio(candles: list) -> float:
    cum_tv = cum_v = 0.0
    for c in candles:
        typ = (c['h'] + c['l'] + c['c']) / 3
        cum_tv += typ * c['v']
        cum_v  += c['v']
    vwap = cum_tv / cum_v if cum_v > 0 else candles[-1]['c']
    return candles[-1]['c'] / vwap if vwap > 0 else 1.0

# ════════════════════════════════════════════════════════
# 6. KLINES (Binance FAPI → Bybit público)
# ════════════════════════════════════════════════════════
def fetch_klines(symbol: str = "ETHUSDT", interval: str = "5", limit: int = 150) -> list:
    bin_sym = symbol.upper()
    bin_iv  = f"{interval}m"
    sources = [
        {"url": f"https://fapi.binance.com/fapi/v1/klines?symbol={bin_sym}&interval={bin_iv}&limit={limit}", "parser": "binance"},
        {"url": f"https://api.binance.com/api/v3/klines?symbol={bin_sym}&interval={bin_iv}&limit={limit}",   "parser": "binance"},
        {"url": f"https://api.bybit.com/v5/market/kline?category=linear&symbol={symbol}&interval={interval}&limit={limit}", "parser": "bybit"},
    ]
    for src in sources:
        try:
            r = requests.get(src["url"], timeout=12, headers={"User-Agent": "GridBot/15.4"})
            data = r.json()
            out = []
            if src["parser"] == "binance":
                if not isinstance(data, list) or len(data) == 0:
                    continue
                for k in data:
                    out.append({"t": int(k[0]), "o": float(k[1]), "h": float(k[2]),
                                "l": float(k[3]), "c": float(k[4]), "v": float(k[5])})
            else:
                if data.get("retCode") != 0:
                    continue
                for k in reversed(data["result"]["list"]):
                    out.append({"t": int(k[0]), "o": float(k[1]), "h": float(k[2]),
                                "l": float(k[3]), "c": float(k[4]), "v": float(k[5])})
            if len(out) >= 30:
                log.info(f"[klines] ✓ {len(out)} velas [{src['url'][:40]}]")
                return out
        except Exception as e:
            log.warning(f"[klines] {src['url'][:40]}: {e}")
    log.error("[klines] TODAS las fuentes fallaron")
    return []

# ════════════════════════════════════════════════════════
# 7. ML MODEL (Logistic Regression — puerto directo del PHP)
# ════════════════════════════════════════════════════════
class GridML:
    def __init__(self, weights_file: str):
        self.weights      = {}
        self.intercepts   = [0.0, 0.0, 0.0]
        self.scaler_mean  = []
        self.scaler_scale = []
        self.feature_names= []
        self.classes      = ["DOWN", "SIDEWAYS", "UP"]
        self.accuracy     = 0.0
        self._load(weights_file)

    def _load(self, path: str):
        try:
            data = json.loads(Path(path).read_text())
            acc = float(data.get("acc", 0))
            if acc < G_ML_MIN_ACCURACY:
                log.warning(f"[ML] Accuracy {acc:.2%} < mínimo {G_ML_MIN_ACCURACY:.0%} — usando fallback")
                return
            self.weights       = data["weights"]
            self.intercepts    = data["intercepts"]
            self.scaler_mean   = data["scaler_mean"]
            self.scaler_scale  = data["scaler_scale"]
            self.feature_names = data["features"]
            self.classes       = data["classes"]
            self.accuracy      = acc
            log.info(f"[ML] Modelo cargado | acc={acc:.2%} | features={len(self.feature_names)}")
        except Exception as e:
            log.warning(f"[ML] Error cargando pesos: {e}")

    def _build_features(self, candles: list, price: float) -> dict:
        closes = [c['c'] for c in candles]
        e9  = ema(closes, 9);  e9l  = next((v for v in reversed(e9)  if v is not None), None)
        e21 = ema(closes, 21); e21l = next((v for v in reversed(e21) if v is not None), None)
        feats = {
            "rsi_14":       rsi_last(closes),
            "stoch_14":     stoch_last(candles),
            "macd_hist":    macd_hist_last(closes),
            "ema_diff_9_21": ((e9l - e21l) / price) if e9l and e21l and price > 0 else 0.0,
            "vol_ratio":    vol_ratio_last(candles),
            "bb_width":     bb_width(candles),
            "atr_pct":      atr_pct_last(candles),
            "vwap_ratio":   vwap_ratio(candles),
            "spread_pct":   (candles[-1]['h'] - candles[-1]['l']) / candles[-1]['c'] * 100 if candles[-1]['c'] > 0 else 0.0,
            "momentum_5":   (closes[-1] - closes[-6]) / closes[-6] * 100 if len(closes) >= 6 and closes[-6] != 0 else 0.0,
        }
        for i, fn in enumerate(self.feature_names):
            if i < len(self.scaler_mean) and i < len(self.scaler_scale):
                sc = self.scaler_scale[i] or 1.0
                feats[fn] = max(-3.0, min(3.0, (feats[fn] - self.scaler_mean[i]) / sc))
        return feats

    @staticmethod
    def _softmax(scores: list) -> list:
        mx = max(scores)
        ex = [math.exp(s - mx) for s in scores]
        sm = sum(ex)
        return [e / sm for e in ex]

    def predict(self, candles: list) -> dict:
        if not self.weights:
            return self._fallback(candles)
        try:
            price = candles[-1]['c']
            feats = self._build_features(candles, price)
            scores = []
            for i, cls in enumerate(self.classes):
                score = float(self.intercepts[i]) if i < len(self.intercepts) else 0.0
                for fn in self.feature_names:
                    score += feats.get(fn, 0.0) * float(self.weights.get(fn, {}).get(cls, 0.0))
                scores.append(score)
            probs   = self._softmax(scores)
            max_idx = probs.index(max(probs))
            direction = self.classes[max_idx]
            confidence = int(round(probs[max_idx] * 100))
            log.info(f"[ML] {direction} {confidence}% (D={probs[0]*100:.0f}% S={probs[1]*100:.0f}% U={probs[2]*100:.0f}%) acc={self.accuracy*100:.1f}%")
            return {"direction": direction, "confidence": confidence, "probs": probs}
        except Exception as e:
            log.warning(f"[ML] {e}")
            return self._fallback(candles)

    def _fallback(self, candles: list) -> dict:
        rsi = rsi_last([c['c'] for c in candles])
        d   = "UP" if rsi > 58 else ("DOWN" if rsi < 42 else "SIDEWAYS")
        return {"direction": d, "confidence": 35, "probs": [0.33, 0.34, 0.33]}

# ════════════════════════════════════════════════════════
# 8. VOLATILITY MODEL (Ridge Regression — predictFutureATR)
# ════════════════════════════════════════════════════════
class VolatilityModel:
    def __init__(self, path: str):
        self.weights      = None
        self.intercept    = 0.0
        self.scaler_mean  = []
        self.scaler_scale = []
        self.clip_lower   = 0.05
        self.clip_upper   = 1.5
        self._load(path)

    def _load(self, path: str):
        if not Path(path).exists():
            log.warning("[Vol] Sin modelo de volatilidad. Usando ATR actual.")
            return
        try:
            data = json.loads(Path(path).read_text())
            self.weights      = data["weights"]
            self.intercept    = float(data.get("intercept", 0.0))
            self.scaler_mean  = data["scaler_mean"]
            self.scaler_scale = data["scaler_scale"]
            self.clip_lower   = float(data.get("prediction_clip_lower", 0.05))
            self.clip_upper   = float(data.get("prediction_clip_upper", 1.5))
            log.info(f"[Vol] Modelo cargado | MAE={data.get('mae',0):.3f}% R²={data.get('r2',0):.2f}")
        except Exception as e:
            log.warning(f"[Vol] Error: {e}")

    def predict(self, candles: list) -> Optional[float]:
        if not self.weights or len(candles) < 30:
            return None
        closes = [c['c'] for c in candles]
        price  = closes[-1]
        e9     = ema(closes, 9);  e9l  = next((v for v in reversed(e9)  if v), None)
        e21    = ema(closes, 21); e21l = next((v for v in reversed(e21) if v), None)
        raw = {
            "rsi_14":       rsi_last(closes),
            "stoch_14":     stoch_last(candles),
            "macd_hist":    macd_hist_last(closes),
            "ema_diff_9_21": ((e9l - e21l) / price) if e9l and e21l and price > 0 else 0.0,
            "vol_ratio":    vol_ratio_last(candles),
            "bb_width":     bb_width(candles),
            "atr_pct":      atr_pct_last(candles),
            "vwap_ratio":   vwap_ratio(candles),
            "spread_pct":   (candles[-1]['h'] - candles[-1]['l']) / price * 100 if price > 0 else 0.0,
            "momentum_5":   (closes[-1] - closes[-6]) / closes[-6] * 100 if len(closes) >= 6 and closes[-6] != 0 else 0.0,
        }
        feat_order = ["rsi_14","stoch_14","macd_hist","ema_diff_9_21",
                      "vol_ratio","bb_width","atr_pct","vwap_ratio","spread_pct","momentum_5"]
        pred = self.intercept
        for i, fn in enumerate(feat_order):
            if i < len(self.scaler_mean) and i < len(self.scaler_scale):
                sc   = self.scaler_scale[i] or 1.0
                scaled = (raw[fn] - self.scaler_mean[i]) / sc
                pred  += scaled * float(self.weights.get(fn, 0.0))
        if pred < 0:
            log.warning(f"[Vol] Pred negativa ({pred:.4f}) → usando ATR actual")
            return None
        pred = max(self.clip_lower, min(self.clip_upper, pred))
        atr_actual = raw["atr_pct"]
        if atr_actual > 0.01:
            ratio = pred / atr_actual
            if ratio < 0.5:
                pred = 0.4 * pred + 0.6 * atr_actual
            elif ratio > 3.0:
                pred = 0.65 * atr_actual + 0.35 * pred
        log.info(f"[Vol] ATR actual={atr_actual:.2f}% → predicho={pred:.2f}%")
        return pred

# ════════════════════════════════════════════════════════
# 9. MT5 ADAPTER
# ════════════════════════════════════════════════════════
class MT5Adapter:
    def __init__(self, login: int, password: str, server: str):
        self._login    = login
        self._password = password
        self._server   = server
        self._sym_info = {}

    def connect(self):
        if not mt5.initialize():
            raise RuntimeError(f"MT5 initialize() failed: {mt5.last_error()}")
        if self._login:
            ok = mt5.login(self._login, password=self._password, server=self._server)
            if not ok:
                raise RuntimeError(f"MT5 login failed: {mt5.last_error()}")
        info = mt5.account_info()
        log.info(f"[MT5] Login OK | Balance={info.balance:.2f} | Equity={info.equity:.2f} | Leverage=1:{info.leverage}")

    def is_autotrading_enabled(self) -> bool:
        """Retorna True si el trading automatizado está permitido."""
        info = mt5.terminal_info()
        if info is None:
            return False
        return info.trade_allowed

    def _sym(self, symbol: str = None) -> mt5.SymbolInfo:
        s = symbol or G_SYM
        if s not in self._sym_info:
            mt5.symbol_select(s, True)
            self._sym_info[s] = mt5.symbol_info(s)
        return self._sym_info[s]

    def price(self, symbol: str = None) -> float:
        t = mt5.symbol_info_tick(symbol or G_SYM)
        return t.bid if t else 0.0

    def balance(self) -> float:
        info = mt5.account_info()
        return info.equity if info else 0.0

    def filters(self, symbol: str = None) -> dict:
        si = self._sym(symbol)
        step = si.volume_step
        tick = si.point
        pp   = max(0, round(-math.log10(max(tick, 1e-8))))
        qp   = max(0, round(-math.log10(max(step, 1e-8))))
        return {
            "step": step,
            "tick": tick,
            "mn":   si.volume_min,
            "mx":   si.volume_max,
            "pp":   pp,
            "qp":   qp,
            "contract_size": si.trade_contract_size,
        }

    def _round_price(self, price: float, symbol: str = None) -> float:
        si = self._sym(symbol)
        return round(round(price / si.point) * si.point, int(math.ceil(-math.log10(si.point + 1e-15))))

    def _round_qty(self, qty: float, symbol: str = None) -> float:
        si = self._sym(symbol)
        step = si.volume_step
        qty  = round(round(qty / step) * step, 8)
        return max(si.volume_min, min(si.volume_max, qty))

    def limit_order(self, symbol: str, side: str, qty: float, price: float,
                    reduce_only: bool = False, comment: str = "") -> Optional[int]:
        qty   = self._round_qty(qty, symbol)
        price = self._round_price(price, symbol)
        otype = mt5.ORDER_TYPE_BUY_LIMIT if side.upper() == "BUY" else mt5.ORDER_TYPE_SELL_LIMIT
        req = {
            "action":    mt5.TRADE_ACTION_PENDING,
            "symbol":    symbol,
            "volume":    qty,
            "type":      otype,
            "price":     price,
            "deviation": 30,
            "magic":     G_MAGIC,
            "comment":   comment[:31],
            "type_time": mt5.ORDER_TIME_GTC,
            "type_filling": mt5.ORDER_FILLING_IOC,
        }
        res = mt5.order_send(req)
        if res and res.retcode == mt5.TRADE_RETCODE_DONE:
            return res.order
        log.warning(f"[MT5] limit_order {side} {qty}@{price} err={res.retcode if res else 'None'}: {res.comment if res else ''}")
        return None

    def market_close(self, symbol: str, side: str, qty: float) -> bool:
        qty = self._round_qty(qty, symbol)
        positions = mt5.positions_get(symbol=symbol)
        if not positions:
            return False
        for pos in positions:
            if pos.magic != G_MAGIC:
                continue
            ptype = pos.type
            if side.upper() == "BUY"  and ptype != 0: continue
            if side.upper() == "SELL" and ptype != 1: continue
            tick = mt5.symbol_info_tick(symbol)
            close_price = tick.bid if ptype == 0 else tick.ask
            req = {
                "action":    mt5.TRADE_ACTION_DEAL,
                "symbol":    symbol,
                "volume":    min(qty, pos.volume),
                "type":      mt5.ORDER_TYPE_SELL if ptype == 0 else mt5.ORDER_TYPE_BUY,
                "position":  pos.ticket,
                "price":     close_price,
                "deviation": 50,
                "magic":     G_MAGIC,
                "comment":   "grid_close",
                "type_filling": mt5.ORDER_FILLING_IOC,
            }
            res = mt5.order_send(req)
            if res and res.retcode == mt5.TRADE_RETCODE_DONE:
                return True
            log.warning(f"[MT5] market_close {side} err={res.retcode if res else 'None'}")
        return False

    def cancel_all(self, symbol: str):
        orders = mt5.orders_get(symbol=symbol)
        if not orders:
            return
        for o in orders:
            if o.magic != G_MAGIC:
                continue
            req = {"action": mt5.TRADE_ACTION_REMOVE, "order": o.ticket}
            res = mt5.order_send(req)
            if res and res.retcode != mt5.TRADE_RETCODE_DONE:
                log.warning(f"[MT5] cancel {o.ticket} err={res.retcode}")

    def get_positions(self, symbol: str) -> list:
        pos = mt5.positions_get(symbol=symbol)
        if not pos:
            return []
        out = []
        for p in pos:
            if p.magic != G_MAGIC:
                continue
            out.append({
                "side":           "Buy" if p.type == 0 else "Sell",
                "positionAmt":    p.volume if p.type == 0 else -p.volume,
                "size":           p.volume,
                "entryPrice":     p.price_open,
                "unRealizedProfit": p.profit,
                "ticket":         p.ticket,
            })
        return out

    def get_order_status(self, ticket: int) -> Optional[str]:
        orders = mt5.orders_get()
        if orders:
            for o in orders:
                if o.ticket == ticket:
                    return "OPEN"
        hist = mt5.history_orders_get(ticket=ticket)
        if hist:
            o = hist[0]
            state_map = {1: "FILLED", 2: "CANCELED", 3: "CANCELED", 4: "CANCELED"}
            return state_map.get(o.state, "UNKNOWN")
        return None

    def get_margin_level(self) -> float:
        info = mt5.account_info()
        if info and info.margin > 0:
            return info.equity / info.margin * 100
        return 9999.0

# ════════════════════════════════════════════════════════
# 10. GRID MANAGER
# ════════════════════════════════════════════════════════
class GridManager:
    def __init__(self, api: MT5Adapter, ml: GridML, vol_model: VolatilityModel):
        self.api      = api
        self.ml       = ml
        self.vol      = vol_model
        self.running  = True

        self.cfg              = None
        self.last_ai          = 0
        self.grid_built       = False
        self.cycle_n          = 0
        self.peak_pnl         = 0.0
        self.last_compound    = 0
        self.last_grid_build  = 0
        self.last_direction   = None
        self.dir_change_count = 0
        self.last_atr_pred    = None

    # ─── CONFIG DB ───────────────────────────────────────
    def _load_config(self):
        self.cfg = dbx(lambda cur: cur.execute(
            "SELECT * FROM grid_configs WHERE symbol=%s LIMIT 1", (G_SYM,)
        ) or cur.fetchone())
        if not self.cfg:
            dbx(lambda cur: cur.execute(
                "INSERT IGNORE INTO grid_configs (symbol,capital_usd,leverage,levels,spacing_pct,long_levels,short_levels) VALUES(%s,%s,%s,%s,%s,%s,%s)",
                (G_SYM, G_CAPITAL, G_LEVERAGE, G_FIXED_LEVELS, G_BASE_SPACING, G_FIXED_LEVELS//2, G_FIXED_LEVELS//2)
            ))
            self._load_config()

    def _save_config(self, **kwargs):
        if not self.cfg:
            return
        sets = ", ".join(f"{k}=%s" for k in kwargs)
        vals = list(kwargs.values()) + [G_SYM]
        dbx(lambda cur: cur.execute(f"UPDATE grid_configs SET {sets} WHERE symbol=%s", vals))
        self._load_config()

    # ─── CALC QTY ─────────────────────────────────────────
    def _calc_qty(self, price: float, levels: int, f: dict) -> float:
        equity = self.api.balance()
        if equity <= 0:
            equity = G_CAPITAL
        eff_cap   = min(equity, G_CAPITAL) * G_MARGIN_SAFETY
        margin_lv = eff_cap / max(1, levels)
        qty       = (margin_lv * G_LEVERAGE) / (price * f.get("contract_size", 1.0))
        hard_cap  = (eff_cap * 0.12 * G_LEVERAGE) / (price * f.get("contract_size", 1.0))
        qty = min(qty, hard_cap)
        step = f["step"]; lmn = f["mn"]; lmx = f["mx"]
        qty  = max(step, round(round(qty / step) * step, 8))
        qty  = max(lmn, min(lmx, qty))
        if qty * price * f.get("contract_size", 1.0) < G_MIN_NOTIONAL:
            qty = G_MIN_NOTIONAL / (price * f.get("contract_size", 1.0))
            qty = max(lmn, min(lmx, max(step, round(round(qty / step) * step, 8))))
        return qty

    def _calc_pnl(self, exit_side: str, entry_px: float, exit_px: float, qty: float, contract_size: float = 1.0) -> float:
        gross = ((exit_px - entry_px) * qty * contract_size
                 if exit_side == "SELL"
                 else (entry_px - exit_px) * qty * contract_size)
        fee = (entry_px * qty * G_MAKER_FEE + exit_px * qty * G_MAKER_FEE) * contract_size
        return round(gross - fee, 8)

    # ─── AI EVALUATE ─────────────────────────────────────
    def _ai_evaluate(self, price: float):
        log.info("[AI] Evaluando ML + heurístico...")
        raw = fetch_klines("ETHUSDT", G_TF, G_CANDLES)
        if len(raw) < 30:
            self._apply_fallback(price)
            return
        candles = raw

        ml_result = self.ml.predict(candles)
        ml_probs  = ml_result["probs"]

        closes = [c['c'] for c in candles]
        rsi    = rsi_last(closes)
        macd   = macd_hist_last(closes)
        e9l    = next((v for v in reversed(ema(closes, 9))  if v), None)
        e21l   = next((v for v in reversed(ema(closes, 21)) if v), None)
        ema_bull = e9l and e21l and e9l > e21l and price > e21l
        ema_bear = e9l and e21l and e9l < e21l and price < e21l
        h_score = 0.0
        if rsi > 55:  h_score += 1
        elif rsi < 45: h_score -= 1
        if macd > 0:  h_score += 0.5
        elif macd < 0: h_score -= 0.5
        if ema_bull:   h_score += 0.5
        elif ema_bear: h_score -= 0.5
        norm   = (h_score + 2.0) / 4.0
        hp     = [max(0, 0.5 - norm), max(0, abs(0.5 - norm) * 0.4 + 0.2), max(0, norm - 0.1)]
        hp_sum = sum(hp)
        hp = [v / hp_sum for v in hp] if hp_sum > 0 else [0.33, 0.34, 0.33]

        w_ml = G_ML_BLEND_WEIGHT; w_h = 1 - w_ml
        blended = [w_ml * ml_probs[i] + w_h * hp[i] for i in range(3)]
        classes = ["DOWN", "SIDEWAYS", "UP"]
        max_idx = blended.index(max(blended))
        direction  = classes[max_idx]
        confidence = int(round(blended[max_idx] * 100))

        prev_dir = (self.cfg or {}).get("direction", "SIDEWAYS")
        if direction != prev_dir:
            if direction == self.last_direction:
                self.dir_change_count += 1
                if self.dir_change_count < 2:
                    log.info(f"[AI] {direction} propuesto — requiere confirmación. Manteniendo {prev_dir}")
                    direction  = prev_dir
                    confidence = int((confidence + (self.cfg or {}).get("confidence", 50)) / 2)
                else:
                    self.dir_change_count = 0
            else:
                self.last_direction   = direction
                self.dir_change_count = 1
                log.info(f"[AI] Posible cambio → {direction}. Pendiente confirmación.")
                direction  = prev_dir
                confidence = int((confidence + (self.cfg or {}).get("confidence", 50)) / 2)
        else:
            self.dir_change_count = 0
            self.last_direction   = direction

        atr_actual   = atr_pct_last(candles)
        atr_predicho = self.vol.predict(candles)
        self.last_atr_pred = atr_predicho
        if atr_predicho and atr_predicho > 0.01:
            atr_ef = 0.70 * atr_actual + 0.30 * atr_predicho
        else:
            atr_ef = atr_actual

        spacing_raw = G_BASE_SPACING + (atr_ef * G_SPACING_ATR_MULT / 100)
        spacing = min(G_MAX_SPACING, max(G_MIN_SPACING, spacing_raw))
        if direction == "SIDEWAYS":
            spacing = max(G_MIN_SPACING, spacing * 0.90)

        levels = G_FIXED_LEVELS
        if direction == "UP":
            long_lev  = int(round(levels * 0.625)); short_lev = levels - long_lev
        elif direction == "DOWN":
            short_lev = int(round(levels * 0.625)); long_lev  = levels - short_lev
        else:
            long_lev = short_lev = levels // 2

        f   = self.api.filters(G_SYM)
        qty = self._calc_qty(price, levels, f)

        self._save_config(
            direction=direction, confidence=confidence,
            ai_reason=f"ML:{ml_result['direction']}({ml_result['confidence']}%) Blend:{direction}({confidence}%)",
            last_ai_check=datetime.now(timezone.utc),
            levels=levels, spacing_pct=spacing,
            long_levels=long_lev, short_levels=short_lev,
            qty_per_level=qty, pp=f["pp"], qp=f["qp"],
            ml_accuracy=self.ml.accuracy
        )
        self.last_ai = time.time()
        self._append_conf(confidence, direction)

        pred_str = f"{atr_predicho:.2f}%" if atr_predicho else "null"
        log.info(f"[AI] {direction} conf={confidence}% | spacing={spacing*100:.4f}% | "
                 f"atr_real={atr_actual:.2f}% atr_pred={pred_str} | levels={levels} | qty={qty:.4f}")

        if direction != prev_dir and self.dir_change_count == 0:
            log.info(f"[AI] {prev_dir} → {direction} → Reconstruyendo grid")
            self.api.cancel_all(G_SYM)
            dbx(lambda cur: cur.execute(
                "UPDATE grid_orders SET status='CANCELED' WHERE symbol=%s AND status='OPEN'", (G_SYM,)))
            self.grid_built = False; self.last_grid_build = 0

    def _apply_fallback(self, price: float):
        prev_dir  = (self.cfg or {}).get("direction", "SIDEWAYS")
        prev_conf = int((self.cfg or {}).get("confidence", 50))
        confidence = max(30, prev_conf - 10)
        self._save_config(
            direction=prev_dir, confidence=confidence,
            ai_reason="Sin velas: heurístico puro",
            last_ai_check=datetime.now(timezone.utc)
        )
        self.last_ai = time.time()
        log.info(f"[AI-FALLBACK] {prev_dir} conf={confidence}%")

    # ─── BUILD GRID ───────────────────────────────────────
    def _build_grid(self, price: float):
        elapsed = time.time() - self.last_grid_build
        if self.last_grid_build > 0 and elapsed < G_MIN_BUILD_INTERVAL:
            return

        equity = self.api.balance()
        if equity <= 0:
            equity = G_CAPITAL
        if equity < G_CAPITAL * 0.1:
            log.warning(f"[GRID] Equity insuficiente: {equity:.2f}"); return

        cfg     = self.cfg or {}
        f       = self.api.filters(G_SYM)
        spacing = float(cfg.get("spacing_pct", G_BASE_SPACING))
        levels  = int(cfg.get("levels", G_FIXED_LEVELS))
        long_lv = int(cfg.get("long_levels", levels // 2))
        short_lv= int(cfg.get("short_levels", levels // 2))
        qty     = float(cfg.get("qty_per_level") or 0)
        if qty <= 0:
            qty = self._calc_qty(price, levels, f)
            self._save_config(qty_per_level=qty)

        cfg_id  = int(cfg.get("id", 0))
        direction = cfg.get("direction", "SIDEWAYS")
        eff_cap = min(equity, G_CAPITAL) * G_MARGIN_SAFETY

        self.api.cancel_all(G_SYM)
        dbx(lambda cur: cur.execute(
            "UPDATE grid_orders SET status='CANCELED' WHERE symbol=%s AND status='OPEN'", (G_SYM,)))

        placed = 0; errors = 0; used_margin = 0.0
        digits = f["pp"]
        contract_size = f.get("contract_size", 1.0)

        # Ajustar dinámicamente long_lv y short_lv según margen disponible
        def adjust_levels(levels_list, is_long):
            adjusted = 0
            for i in range(1, levels_list + 1):
                p = round(price * (1 - spacing * i), digits) if is_long else round(price * (1 + spacing * i), digits)
                req_margin = (qty * p * contract_size) / G_LEVERAGE
                if req_margin > (eff_cap - used_margin) * 0.95:
                    break
                adjusted += 1
                used_margin += req_margin
            return adjusted

        # Para BUY (long)
        long_placed = 0
        for i in range(1, long_lv + 1):
            p = round(price * (1 - spacing * i), digits)
            req_margin = (qty * p * contract_size) / G_LEVERAGE
            if req_margin > (eff_cap - used_margin) * 0.95:
                log.warning(f"[GRID] Margen insuf BUY L{i} (deteniendo long)")
                break
            ticket = self.api.limit_order(G_SYM, "BUY", qty, p, comment=f"GE_B_{i}")
            if ticket:
                dbx(lambda cur, _i=i, _p=p, _t=ticket: cur.execute(
                    "INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status) VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,'OPEN')",
                    (cfg_id, G_SYM, direction, _i, "BUY", "ENTRY", str(_t), _p, qty)))
                placed += 1; used_margin += req_margin; long_placed += 1
            else:
                errors += 1
            time.sleep(0.12)

        # Para SELL (short)
        short_placed = 0
        for i in range(1, short_lv + 1):
            p = round(price * (1 + spacing * i), digits)
            req_margin = (qty * p * contract_size) / G_LEVERAGE
            if req_margin > (eff_cap - used_margin) * 0.95:
                log.warning(f"[GRID] Margen insuf SELL L{i} (deteniendo short)")
                break
            ticket = self.api.limit_order(G_SYM, "SELL", qty, p, comment=f"GE_S_{i}")
            if ticket:
                dbx(lambda cur, _i=i, _p=p, _t=ticket: cur.execute(
                    "INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status) VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,'OPEN')",
                    (cfg_id, G_SYM, direction, -_i, "SELL", "ENTRY", str(_t), _p, qty)))
                placed += 1; used_margin += req_margin; short_placed += 1
            else:
                errors += 1
            time.sleep(0.12)

        self.grid_built     = placed > 0
        self.last_grid_build = time.time() if self.grid_built else 0
        log.info(f"[GRID] ✓ {placed}/{levels} (long={long_placed}/{long_lv} short={short_placed}/{short_lv}) | {errors} err | "
                 f"margen={used_margin:.2f} USD | dir={direction} spc={spacing*100:.4f}% qty={qty:.4f}")

    # ─── CHECK FILLS ──────────────────────────────────────
    def _check_fills(self, price: float):
        cfg     = self.cfg or {}
        cfg_id  = int(cfg.get("id", 0))
        spacing = float(cfg.get("spacing_pct", G_BASE_SPACING))
        f       = self.api.filters(G_SYM)
        digits  = f["pp"]
        contract_size = f.get("contract_size", 1.0)
        direction = cfg.get("direction", "SIDEWAYS")

        open_orders = dbx(lambda cur: cur.execute(
            "SELECT * FROM grid_orders WHERE symbol=%s AND status='OPEN' AND grid_role='ENTRY'", (G_SYM,)
        ) or cur.fetchall()) or []

        for order in open_orders:
            ticket = int(order["order_id"]) if str(order["order_id"]).isdigit() else None
            if ticket is None:
                continue
            # Convertir created_at (naive) a aware UTC para comparación
            if order["created_at"]:
                if order["created_at"].tzinfo is None:
                    created_aware = order["created_at"].replace(tzinfo=timezone.utc)
                else:
                    created_aware = order["created_at"]
                age = (datetime.now(timezone.utc) - created_aware).total_seconds()
            else:
                age = 999
            if age < 30:
                continue
            status = self.api.get_order_status(ticket)
            if status != "FILLED":
                if status == "CANCELED":
                    dbx(lambda cur, _id=order["id"]: cur.execute(
                        "UPDATE grid_orders SET status='CANCELED' WHERE id=%s", (_id,)))
                continue

            fill_px = float(order["price"])
            qty     = float(order["qty"])
            side    = order["side"]
            level   = int(order["grid_level"])
            is_rec  = int(order.get("is_recovery", 0))

            dbx(lambda cur, _id=order["id"], _fp=fill_px: cur.execute(
                "UPDATE grid_orders SET status='FILLED',filled_at=NOW(),exit_price=%s WHERE id=%s", (_fp, _id)))

            pos_found = self._confirm_position(side, qty)
            if not pos_found:
                log.warning(f"[FILL] ENTRY {side} ${fill_px:.2f} sin posición — reciclando")
                self._recycle_entry_direct(order, fill_px, price, spacing, qty, f, is_rec)
                continue

            exit_side = "SELL" if side == "BUY" else "BUY"
            exit_px   = (round(fill_px * (1 + spacing), digits) if side == "BUY"
                         else round(fill_px * (1 - spacing), digits))
            if exit_px <= 0:
                continue

            exit_ticket = self.api.limit_order(G_SYM, exit_side, qty, exit_px,
                                                reduce_only=True, comment=f"GX_{level}")
            if exit_ticket:
                dbx(lambda cur, _t=exit_ticket, _ep=exit_px, _es=exit_side, _lv=level, _is=is_rec, _oid=order["id"]: cur.execute(
                    "INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,linked_order,is_recovery) VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,'OPEN',%s,%s)",
                    (cfg_id, G_SYM, direction, _lv, _es, "EXIT", str(_t), _ep, qty, _oid, _is)))
                log.info(f"[FILL] ENTRY {side} ${fill_px:.2f} → EXIT {exit_side} ${exit_px:.2f} qty={qty:.4f}")
                self._recycle_entry(order, fill_px, price, spacing, qty, f, is_rec)
            else:
                log.warning(f"[FILL] ❌ Error EXIT L{level}")

        self._check_exit_fills(price, spacing, f)

    def _confirm_position(self, side: str, expected_qty: float) -> bool:
        target = "Buy" if side == "BUY" else "Sell"
        for attempt in range(6):
            positions = self.api.get_positions(G_SYM)
            for pos in positions:
                if pos["side"] == target and pos["size"] >= expected_qty * 0.98:
                    return True
            if attempt < 5:
                log.warning(f"[POSCONF] Esperando posición {target} (intento {attempt+1}/6)")
                time.sleep(2)
        log.warning(f"[POSCONF] No detectada posición {target} después de 12s")
        return False

    def _check_exit_fills(self, price: float, spacing: float, f: dict):
        contract_size = f.get("contract_size", 1.0)
        open_exits = dbx(lambda cur: cur.execute(
            "SELECT * FROM grid_orders WHERE symbol=%s AND status='OPEN' AND grid_role='EXIT'", (G_SYM,)
        ) or cur.fetchall()) or []

        for order in open_exits:
            ticket = int(order["order_id"]) if str(order["order_id"]).isdigit() else None
            if ticket is None:
                continue
            status = self.api.get_order_status(ticket)
            if status != "FILLED":
                continue
            fill_px  = float(order["price"])
            qty      = float(order["qty"])
            side     = order["side"]
            is_rec   = int(order.get("is_recovery", 0))

            entry = dbx(lambda cur, _lid=order["linked_order"]: cur.execute(
                "SELECT * FROM grid_orders WHERE id=%s", (_lid,)) or cur.fetchone()) if order["linked_order"] else None
            entry_px = float(entry["price"]) if entry else float(order["price"])

            pnl = self._calc_pnl(side, entry_px, fill_px, qty, contract_size)
            dbx(lambda cur, _id=order["id"], _fp=fill_px, _pnl=pnl: cur.execute(
                "UPDATE grid_orders SET status='FILLED',filled_at=NOW(),exit_price=%s,pnl_usd=%s WHERE id=%s",
                (_fp, _pnl, _id)))

            today_pnl = self._get_pnl_today()
            if today_pnl > self.peak_pnl:
                self.peak_pnl = today_pnl
            log.info(f"[FILL] EXIT {side} ${fill_px:.2f} PnL={pnl:.4f} USDT | Hoy={today_pnl:.4f}")
            self._recycle_entry(order, fill_px, price, spacing, qty, f, is_rec)

    def _recycle_entry(self, exit_order: dict, fill_px: float, price: float,
                       spacing: float, qty: float, f: dict, is_rec: int):
        cfg     = self.cfg or {}
        cfg_id  = int(cfg.get("id", 0))
        new_side= "BUY" if exit_order["side"] == "SELL" else "SELL"
        level   = int(exit_order["grid_level"])
        if self._has_open_entry_for_level(level):
            return
        digits  = f["pp"]
        new_px  = (round(price * (1 - spacing), digits) if new_side == "BUY"
                   else round(price * (1 + spacing), digits))
        ticket = self.api.limit_order(G_SYM, new_side, qty, new_px, comment=f"GR_{level}")
        if ticket:
            dbx(lambda cur, _t=ticket, _p=new_px, _s=new_side, _lv=level, _is=is_rec: cur.execute(
                "INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,is_recovery) VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,'OPEN',%s)",
                (cfg_id, G_SYM, (cfg.get("direction","SIDEWAYS")), _lv, _s, "ENTRY", str(_t), _p, qty, _is)))
            log.info(f"[RECYCLE] ENTRY {new_side} ${new_px:.2f}")

    def _recycle_entry_direct(self, order: dict, fill_px: float, price: float,
                               spacing: float, qty: float, f: dict, is_rec: int):
        cfg    = self.cfg or {}
        cfg_id = int(cfg.get("id", 0))
        side   = order["side"]
        level  = int(order["grid_level"])
        if self._has_open_entry_for_level(level):
            return
        digits = f["pp"]
        new_px = (round(price * (1 - spacing), digits) if side == "BUY"
                  else round(price * (1 + spacing), digits))
        ticket = self.api.limit_order(G_SYM, side, qty, new_px, comment=f"GRD_{level}")
        if ticket:
            dbx(lambda cur, _t=ticket, _p=new_px, _s=side, _lv=level, _is=is_rec: cur.execute(
                "INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,is_recovery) VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,'OPEN',%s)",
                (cfg_id, G_SYM, (cfg.get("direction","SIDEWAYS")), _lv, _s, "ENTRY", str(_t), _p, qty, _is)))
            log.info(f"[RECYCLE_D] ENTRY {side} ${new_px:.2f}")

    def _has_open_entry_for_level(self, level: int) -> bool:
        r = dbx(lambda cur: cur.execute(
            "SELECT COUNT(*) AS c FROM grid_orders WHERE symbol=%s AND grid_level=%s AND grid_role='ENTRY' AND status='OPEN'",
            (G_SYM, level)) or cur.fetchone())
        return bool(r and int(r["c"]) > 0)

    # ─── RISK CHECK ───────────────────────────────────────
    def _risk_check(self, price: float):
        pnl_today = self._get_pnl_today()
        loss_pct  = abs(pnl_today) / G_CAPITAL * 100 if pnl_today < 0 else 0.0

        if loss_pct >= G_RECOVERY_LOSS_PCT and not (self.cfg or {}).get("recovery_active"):
            log.warning(f"[RECOVERY] Pérdida {loss_pct:.2f}% → activando")
            self._enter_recovery(price, pnl_today); return

        if loss_pct >= G_MAX_DAILY_LOSS:
            log.error(f"[RISK] Límite diario {G_MAX_DAILY_LOSS}% → pausa 20min")
            self.api.cancel_all(G_SYM)
            self._close_all_positions()
            self.grid_built = False
            time.sleep(1200); return

        for pos in self.api.get_positions(G_SYM):
            upnl     = pos["unRealizedProfit"]
            notional = pos["size"] * pos["entryPrice"]
            if notional > 0 and upnl < 0 and abs(upnl) / notional * 100 >= G_HARD_STOP_PCT:
                log.error(f"[HARD_STOP] uPnL {upnl:.4f} → cierre forzoso")
                self.api.market_close(G_SYM, pos["side"], pos["size"])

        ml = self.api.get_margin_level()
        if ml < 120:
            log.error(f"[LIQ_RISK] Margin level {ml:.1f}% < 120% → cerrando")
            self._close_all_positions()
            self.api.cancel_all(G_SYM)
            self.grid_built = False

    def _enter_recovery(self, price: float, pnl_today: float):
        cfg     = self.cfg or {}
        f       = self.api.filters(G_SYM)
        spacing = min(G_MAX_SPACING, float(cfg.get("spacing_pct", G_BASE_SPACING)) * 1.8)
        qty     = self._calc_qty(price, G_MIN_LEVELS * 2, f)
        digits  = f["pp"]
        self.api.cancel_all(G_SYM)
        dbx(lambda cur: cur.execute(
            "UPDATE grid_orders SET status='CANCELED' WHERE symbol=%s AND status='OPEN'", (G_SYM,)))
        dbx(lambda cur: cur.execute(
            "UPDATE grid_configs SET recovery_active=1,spacing_pct=%s WHERE symbol=%s", (spacing, G_SYM)))
        self._load_config()
        self.grid_built = False; self.last_grid_build = 0
        cfg_id    = int((self.cfg or {}).get("id", 0))
        direction = (self.cfg or {}).get("direction", "SIDEWAYS")
        placed    = 0
        for i in range(1, G_MIN_LEVELS + 1):
            p = round(price * (1 - spacing * i), digits)
            if p <= 0: continue
            t = self.api.limit_order(G_SYM, "BUY", qty, p, comment=f"RecovB_{i}")
            if t:
                dbx(lambda cur, _i=i, _p=p, _t=t: cur.execute(
                    "INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,is_recovery) VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,'OPEN',1)",
                    (cfg_id, G_SYM, direction, _i, "BUY", "ENTRY", str(_t), _p, qty)))
                placed += 1
            time.sleep(0.12)
        for i in range(1, G_MIN_LEVELS + 1):
            p = round(price * (1 + spacing * i), digits)
            t = self.api.limit_order(G_SYM, "SELL", qty, p, comment=f"RecovS_{i}")
            if t:
                dbx(lambda cur, _i=i, _p=p, _t=t: cur.execute(
                    "INSERT INTO grid_orders (config_id,symbol,direction,grid_level,side,grid_role,order_id,price,qty,status,is_recovery) VALUES(%s,%s,%s,%s,%s,%s,%s,%s,%s,'OPEN',1)",
                    (cfg_id, G_SYM, direction, -_i, "SELL", "ENTRY", str(_t), _p, qty)))
                placed += 1
            time.sleep(0.12)
        self.grid_built = placed > 0
        if self.grid_built:
            self.last_grid_build = time.time()
        log.info(f"[RECOVERY] {placed} órdenes | spc={spacing*100:.4f}%")

    def _close_all_positions(self):
        for pos in self.api.get_positions(G_SYM):
            for _ in range(3):
                if self.api.market_close(G_SYM, pos["side"], pos["size"]):
                    break
                time.sleep(1)

    # ─── PROFIT OPTIMIZE (compounding) ───────────────────
    def _profit_optimize(self, price: float):
        pnl_today = self._get_pnl_today()
        pct = pnl_today / G_CAPITAL * 100
        if pct < G_COMPOUND_THR:
            return
        if (self.cfg or {}).get("recovery_active"):
            return
        if time.time() - self.last_compound < G_COMPOUND_CD:
            return
        f    = self.api.filters(G_SYM)
        qty  = float((self.cfg or {}).get("qty_per_level") or 0)
        if qty <= 0:
            return
        hard_cap = (G_CAPITAL * 0.12 * G_LEVERAGE) / (price * f.get("contract_size", 1.0))
        new_qty  = min(qty * G_COMPOUND_MULT, min(qty * 3.0, min(hard_cap, f["mx"])))
        step     = f["step"]
        new_qty  = max(step, round(round(new_qty / step) * step, 8))
        if abs(new_qty - qty) > step * 0.3:
            self._save_config(qty_per_level=new_qty)
            self.last_compound = time.time()
            log.info(f"[COMPOUND] PnL +{pct:.2f}% → qty {qty:.4f} → {new_qty:.4f}")

    # ─── BREAKOUT CHECK ───────────────────────────────────
    def _breakout_check(self, price: float):
        if not self.grid_built:
            return
        r = dbx(lambda cur: cur.execute(
            "SELECT MIN(price) mn, MAX(price) mx FROM grid_orders WHERE symbol=%s AND status='OPEN'", (G_SYM,)
        ) or cur.fetchone())
        if not r or not r["mn"]:
            return
        mn = float(r["mn"]); mx = float(r["mx"])
        rng = mx - mn; margin = rng * 0.30
        if price < mn - margin or price > mx + margin:
            last_fill = dbx(lambda cur: cur.execute(
                "SELECT MAX(filled_at) AS lf FROM grid_orders WHERE symbol=%s AND status='FILLED' AND filled_at IS NOT NULL", (G_SYM,)
            ) or cur.fetchone())
            if last_fill and last_fill["lf"]:
                lf = last_fill["lf"]
                if lf.tzinfo is None:
                    lf = lf.replace(tzinfo=timezone.utc)
                age = (datetime.now(timezone.utc) - lf).total_seconds()
                if age < 90:
                    log.info(f"[BREAKOUT] ${price:.2f} fuera rango pero fill reciente ({age:.0f}s)")
                    return
            log.info(f"[BREAKOUT] ${price:.2f} fuera [{mn:.2f}-{mx:.2f}] → rebuild")
            self.api.cancel_all(G_SYM)
            dbx(lambda cur: cur.execute(
                "UPDATE grid_orders SET status='CANCELED' WHERE symbol=%s AND status='OPEN'", (G_SYM,)))
            self.grid_built = False; self.last_grid_build = 0

    # ─── HELPERS ──────────────────────────────────────────
    def _get_pnl_today(self) -> float:
        r = dbx(lambda cur: cur.execute(
            "SELECT COALESCE(SUM(pnl_usd),0) AS p FROM grid_orders "
            "WHERE symbol=%s AND grid_role='EXIT' AND status='FILLED' AND DATE(filled_at)=CURDATE()", (G_SYM,)
        ) or cur.fetchone())
        return float(r["p"]) if r else 0.0

    def _count_open_orders(self) -> int:
        r = dbx(lambda cur: cur.execute(
            "SELECT COUNT(*) AS c FROM grid_orders WHERE symbol=%s AND status='OPEN'", (G_SYM,)
        ) or cur.fetchone())
        return int(r["c"]) if r else 0

    def _append_conf(self, conf: int, direction: str):
        hist = []
        if os.path.exists(CONF_HIST):
            try:
                hist = json.loads(Path(CONF_HIST).read_text())
            except Exception:
                pass
        hist.append({"time": datetime.now(timezone.utc).isoformat(), "confidence": conf, "direction": direction})
        hist = hist[-500:]
        Path(CONF_HIST).write_text(json.dumps(hist))

    def _write_status(self, price: float):
        cfg      = self.cfg or {}
        pnl      = self._get_pnl_today()
        open_cnt = self._count_open_orders()
        status   = {
            "ts":         datetime.now(timezone.utc).isoformat(),
            "mode":       "RECOVERY" if cfg.get("recovery_active") else "NORMAL",
            "ai_engine":  "Grid v15.4 Python MT5",
            "leverage":   G_LEVERAGE,
            "real_balance": self.api.balance(),
            "ml_accuracy":  self.ml.accuracy,
            "pairs": {
                G_SYM: {
                    "price":           price,
                    "direction":       cfg.get("direction", "SIDEWAYS"),
                    "confidence":      cfg.get("confidence", 50),
                    "spacing_pct":     float(cfg.get("spacing_pct", G_BASE_SPACING)),
                    "pnl_today":       round(pnl, 6),
                    "peak_pnl":        round(self.peak_pnl, 6),
                    "recovery_active": bool(cfg.get("recovery_active")),
                    "grid_built":      self.grid_built,
                    "open_orders":     open_cnt,
                    "cycle_n":        self.cycle_n,
                    "atr_predicted":  self.last_atr_pred,
                }
            }
        }
        Path(STATUS_FILE).write_text(json.dumps(status, indent=2, default=str))

    def _check_control(self):
        if not os.path.exists(CTRL_FILE):
            return
        try:
            cmd = json.loads(Path(CTRL_FILE).read_text())
            os.unlink(CTRL_FILE)
            action = cmd.get("action", "")
            if action == "stop":
                self.running = False; log.info("[CTL] Stop")
            elif action == "force_ai":
                self.last_ai = 0; log.info("[CTL] Forzando IA")
            elif action == "reset_grid":
                self.api.cancel_all(G_SYM)
                dbx(lambda cur: cur.execute(
                    "UPDATE grid_orders SET status='CANCELED' WHERE symbol=%s AND status='OPEN'", (G_SYM,)))
                self.grid_built = False; self.last_grid_build = 0
                log.info("[CTL] Grid reset")
        except Exception as e:
            log.warning(f"[CTL] {e}")

    # ─── MAIN LOOP ────────────────────────────────────────
    def run(self):
        log.info("╔══════════════════════════════════════════╗")
        log.info("║  ETH/USDT Grid Bot v15.4 – Python MT5   ║")
        log.info(f"║  Capital: {G_CAPITAL:.0f} USDT  Levels: {G_FIXED_LEVELS}  PID: {os.getpid()}  ║")
        log.info("╚══════════════════════════════════════════╝")

        # Conectar MT5
        for attempt in range(10):
            try:
                self.api.connect()
                break
            except Exception as e:
                log.warning(f"[INIT] Intento {attempt+1}/10: {e}")
                if attempt >= 9:
                    log.error("[INIT] Sin conexión MT5. Abortando.")
                    return
                time.sleep(30)

        # Verificar AutoTrading
        if not self.api.is_autotrading_enabled():
            log.error("❌ AutoTrading desactivado en MT5. Actívalo manualmente (botón 'Algo Trading') y reinicia el bot.")
            return

        self._load_config()

        # Cancelar órdenes previas
        self.api.cancel_all(G_SYM)
        dbx(lambda cur: cur.execute(
            "UPDATE grid_orders SET status='CANCELED' WHERE symbol=%s AND status='OPEN'", (G_SYM,)))
        log.info("[INIT] Órdenes anteriores canceladas. Iniciando ciclo.")

        while self.running:
            try:
                _rotate_log()
                self.cycle_n += 1
                price = self.api.price(G_SYM)
                if price <= 0:
                    log.warning("[MAIN] Precio 0"); time.sleep(G_CYCLE_SEC); continue

                self._check_control()

                if time.time() - self.last_ai >= G_AI_INTERVAL:
                    self._ai_evaluate(price)

                self._check_fills(price)

                if not self.grid_built:
                    self._build_grid(price)
                else:
                    open_cnt = self._count_open_orders()
                    if open_cnt < G_FIXED_LEVELS - 3:
                        log.info(f"[MAIN] Solo {open_cnt} órdenes → rebuild")
                        self.grid_built = False; self.last_grid_build = 0

                self._risk_check(price)
                self._profit_optimize(price)
                self._breakout_check(price)

                if self.cycle_n % 10 == 0:
                    pnl = self._get_pnl_today()
                    log.info(f"[CICLO #{self.cycle_n}] ${price:.2f} | PnL={pnl:.4f} USDT | "
                             f"Abiertos={self._count_open_orders()} | Grid={'ON' if self.grid_built else 'OFF'} | "
                             f"Dir={(self.cfg or {}).get('direction','?')}")

                self._write_status(price)
                time.sleep(G_CYCLE_SEC)

            except KeyboardInterrupt:
                self.running = False
            except Exception as e:
                log.error(f"[LOOP] {e}\n{traceback.format_exc()}")
                time.sleep(15)

        log.info("[BOT] Detenido. Cancelando órdenes grid...")
        self.api.cancel_all(G_SYM)
        mt5.shutdown()

# ════════════════════════════════════════════════════════
# 11. BOOTSTRAP
# ════════════════════════════════════════════════════════
if __name__ == "__main__":
    db_init()
    api = MT5Adapter(MT5_LOGIN, MT5_PASSWORD, MT5_SERVER)
    ml  = GridML(ML_WEIGHTS)
    vol = VolatilityModel(VOL_WEIGHTS)
    bot = GridManager(api, ml, vol)

    def _stop(sig, frame):
        log.info(f"[SIGNAL] {sig} recibido — deteniendo bot")
        bot.running = False

    signal.signal(signal.SIGTERM, _stop)
    signal.signal(signal.SIGINT,  _stop)

    bot.run()