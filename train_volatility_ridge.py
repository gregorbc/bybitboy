#!/usr/bin/env python3
"""
Entrenamiento de regresor Ridge para predecir ATR% futuro (volatilidad).
Genera volatility_weights_ridge.json con clipping para predicciones extremas.
"""

import numpy as np
import pandas as pd
import json
import requests
import time
from sklearn.linear_model import Ridge
from sklearn.preprocessing import RobustScaler
from sklearn.metrics import mean_absolute_error, r2_score
import warnings
warnings.filterwarnings('ignore')

SYMBOL = 'ETHUSDT'
INTERVAL = '5'
MAX_CANDLES = 40000
HORIZON = 4   # 4 velas * 5 min = 20 min al futuro

def fetch_candles(symbol, interval, limit=1000, end_time=None):
    url = "https://api.bybit.com/v5/market/kline"
    params = {"category": "linear", "symbol": symbol, "interval": interval, "limit": limit}
    if end_time:
        params["end"] = str(end_time)
    try:
        r = requests.get(url, params=params, timeout=15).json()
        return r['result']['list'] if r.get('retCode') == 0 else []
    except Exception as e:
        print(f"⚠ Error: {e}")
        return []

def get_all_candles():
    all_k = []
    end_time = None
    print(f"📥 Descargando {MAX_CANDLES} velas de {SYMBOL}...")
    while len(all_k) < MAX_CANDLES:
        data = fetch_candles(SYMBOL, INTERVAL, 1000, end_time)
        if not data:
            break
        all_k.extend(data)
        end_time = int(data[-1][0]) - 1
        if len(data) < 1000:
            break
        time.sleep(0.3)
    if not all_k:
        print("❌ No se obtuvieron velas.")
        return pd.DataFrame()
    df = pd.DataFrame(all_k, columns=['ts','o','h','l','c','v','turnover'])
    for col in ['o','h','l','c','v']:
        df[col] = pd.to_numeric(df[col], errors='coerce')
    df = df.sort_values('ts').reset_index(drop=True)
    print(f"✅ Descargadas {len(df)} velas.")
    return df

def add_features(df):
    # RSI
    delta = df['c'].diff()
    gain = delta.where(delta > 0, 0).rolling(14).mean()
    loss = (-delta.where(delta < 0, 0)).rolling(14).mean()
    rs = gain / loss
    df['rsi_14'] = 100 - (100 / (1 + rs))
    # Estocástico
    low_min = df['l'].rolling(14).min()
    high_max = df['h'].rolling(14).max()
    df['stoch_14'] = 100 * (df['c'] - low_min) / (high_max - low_min)
    # MACD hist
    ema12 = df['c'].ewm(span=12, adjust=False).mean()
    ema26 = df['c'].ewm(span=26, adjust=False).mean()
    macd = ema12 - ema26
    signal = macd.ewm(span=9, adjust=False).mean()
    df['macd_hist'] = macd - signal
    # EMA diff
    ema9 = df['c'].ewm(span=9, adjust=False).mean()
    ema21 = df['c'].ewm(span=21, adjust=False).mean()
    df['ema_diff_9_21'] = (ema9 - ema21) / df['c']
    # Volumen ratio
    df['vol_ratio'] = df['v'] / df['v'].rolling(20).mean()
    # Bollinger width
    bb_avg = df['c'].rolling(20).mean()
    bb_std = df['c'].rolling(20).std()
    df['bb_width'] = (2 * bb_std) / bb_avg
    # ATR% actual
    tr = pd.concat([df['h'] - df['l'],
                    (df['h'] - df['c'].shift()).abs(),
                    (df['l'] - df['c'].shift()).abs()], axis=1).max(axis=1)
    atr = tr.rolling(14).mean()
    df['atr_pct'] = atr / df['c'] * 100
    # VWAP ratio
    typical = (df['h'] + df['l'] + df['c']) / 3
    df['vwap_ratio'] = df['c'] / ((typical * df['v']).cumsum() / df['v'].cumsum())
    # Spread
    df['spread_pct'] = (df['h'] - df['l']) / df['c'] * 100
    # Momentum 5
    df['momentum_5'] = df['c'].pct_change(5) * 100
    return df.dropna().reset_index(drop=True)

def train():
    df = get_all_candles()
    if df.empty:
        return
    df = add_features(df)
    df['atr_future'] = df['atr_pct'].shift(-HORIZON)
    df = df.dropna().reset_index(drop=True)

    feature_cols = ['rsi_14', 'stoch_14', 'macd_hist', 'ema_diff_9_21',
                    'vol_ratio', 'bb_width', 'atr_pct', 'vwap_ratio',
                    'spread_pct', 'momentum_5']
    X = df[feature_cols]
    y = df['atr_future']

    # División temporal
    split = int(len(df) * 0.8)
    X_train, X_test = X.iloc[:split], X.iloc[split:]
    y_train, y_test = y.iloc[:split], y.iloc[split:]

    scaler = RobustScaler()
    X_train_sc = scaler.fit_transform(X_train)
    X_test_sc = scaler.transform(X_test)

    # Ridge regression (L2 regularización)
    # alpha = 1.0 (puede ajustarse)
    model = Ridge(alpha=1.0, random_state=42)
    model.fit(X_train_sc, y_train)

    y_pred = model.predict(X_test_sc)
    mae = mean_absolute_error(y_test, y_pred)
    r2 = r2_score(y_test, y_pred)
    error_std = np.std(y_test - y_pred)
    print(f"MAE = {mae:.4f}%   R² = {r2:.4f}   σ_error = {error_std:.4f}")

    # Calcular clips de predicción basados en percentiles de las predicciones en test
    lower_clip = max(0.05, np.percentile(y_pred, 5))
    upper_clip = np.percentile(y_pred, 95)
    print(f"Prediction clips: lower={lower_clip:.4f}%  upper={upper_clip:.4f}%")

    weights = {feat: float(model.coef_[i]) for i, feat in enumerate(feature_cols)}
    output = {
        "weights": weights,
        "intercept": float(model.intercept_),
        "scaler_mean": [float(x) for x in scaler.center_.tolist()],
        "scaler_scale": [float(x) for x in scaler.scale_.tolist()],
        "features": feature_cols,
        "symbol": SYMBOL,
        "mae": round(mae, 4),
        "r2": round(r2, 4),
        "prediction_clip_lower": round(lower_clip, 4),
        "prediction_clip_upper": round(upper_clip, 4),
        "error_std": round(error_std, 4),
        "updated_at": time.strftime('%Y-%m-%d %H:%M:%S')
    }
    with open('volatility_weights_ridge.json', 'w') as f:
        json.dump(output, f, indent=2)
    print("✅ Regresor Ridge guardado en volatility_weights_ridge.json")

if __name__ == "__main__":
    train()
