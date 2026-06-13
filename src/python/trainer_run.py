#!/usr/bin/env python3
"""
Entrenamiento de modelo de regresión para predecir ATR% futuro (volatilidad).
Útil para ajustar el espaciado de la grilla y el número de niveles.
"""
import numpy as np
import pandas as pd
import json
import requests
import time
import joblib
from sklearn.ensemble import RandomForestRegressor
from sklearn.preprocessing import RobustScaler
from sklearn.metrics import mean_absolute_error, r2_score

SYMBOL       = 'ETHUSDT'
INTERVAL = '5'
MAX_CANDLES  = 12000
HORIZON      = 8
OUTPUT_MODEL = 'volatility_model.pkl'
OUTPUT_SCALER = 'volatility_scaler.pkl'
OUTPUT_WEIGHTS = 'volatility_weights.json'   # para usar desde PHP (coeficientes lineales)

def fetch_candles(symbol, interval, limit=1000, end_time=None):
    url = "https://api.bybit.com/v5/market/kline"
    params = {"category": "linear", "symbol": symbol, "interval": interval, "limit": limit}
    if end_time:
        params["endTime"] = str(end_time)
    r = requests.get(url, params=params, timeout=15).json()
    return r['result']['list'] if r.get('retCode') == 0 else []

def get_all_candles():
    all_k = []
    end_time = None
    print(f"📥 Descargando {MAX_CANDLES} velas para {SYMBOL}...")
    while len(all_k) < MAX_CANDLES:
        data = fetch_candles(SYMBOL, INTERVAL, 1000, end_time)
        if not data:
            break
        all_k.extend(data)
        end_time = int(data[-1][0]) - 1
        if len(data) < 1000:
            break
        time.sleep(0.3)
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
    ema12 = df['c'].ewm(span=12).mean()
    ema26 = df['c'].ewm(span=26).mean()
    macd = ema12 - ema26
    signal = macd.ewm(span=9).mean()
    df['macd_hist'] = macd - signal

    # EMA diff
    ema9 = df['c'].ewm(span=9).mean()
    ema21 = df['c'].ewm(span=21).mean()
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
    df['vwap_ratio'] = df['c'] / ( (typical * df['v']).cumsum() / df['v'].cumsum() )

    # Spread
    df['spread_pct'] = (df['h'] - df['l']) / df['c'] * 100

    # Momentum 5
    df['momentum_5'] = df['c'].pct_change(5) * 100

    return df.dropna().reset_index(drop=True)

def train():
    df = get_all_candles()
    df = add_features(df)

    # Target: ATR% en el futuro (volatilidad esperada)
    df['atr_future'] = df['atr_pct'].shift(-HORIZON)
    df = df.dropna().reset_index(drop=True)
    print(f"📈 Datos con features: {len(df)} filas.")

    feature_cols = ['rsi_14', 'stoch_14', 'macd_hist', 'ema_diff_9_21',
                    'vol_ratio', 'bb_width', 'atr_pct', 'vwap_ratio',
                    'spread_pct', 'momentum_5']
    X = df[feature_cols]
    y = df['atr_future']

    # División temporal (walk‑forward)
    split = int(len(df) * 0.8)
    X_train, X_test = X.iloc[:split], X.iloc[split:]
    y_train, y_test = y.iloc[:split], y.iloc[split:]

    # Escalado robusto
    scaler = RobustScaler()
    X_train_sc = scaler.fit_transform(X_train)
    X_test_sc = scaler.transform(X_test)

    # Random Forest Regressor (puedes ajustar hiperparámetros)
    model = RandomForestRegressor(n_estimators=100, max_depth=8, random_state=42, n_jobs=-1)
    model.fit(X_train_sc, y_train)

    # Evaluación
    y_pred = model.predict(X_test_sc)
    mae = mean_absolute_error(y_test, y_pred)
    r2 = r2_score(y_test, y_pred)
    print(f"\n📊 Evaluación en test (20% final):")
    print(f"   MAE = {mae:.4f}% (error absoluto medio en la predicción de ATR%)")
    print(f"   R²  = {r2:.4f}")

    # Guardar modelo y scaler para usar desde Python (opcional)
    joblib.dump(model, OUTPUT_MODEL)
    joblib.dump(scaler, OUTPUT_SCALER)
    print(f"✅ Modelo guardado como {OUTPUT_MODEL}")

    # Para usarlo desde PHP sin instalar joblib, podemos exportar coeficientes de una regresión lineal
    # (entrenamos una regresión lineal con los mismos datos escalados)
    from sklearn.linear_model import LinearRegression
    lr = LinearRegression()
    lr.fit(X_train_sc, y_train)
    weights = {feat: float(lr.coef_[i]) for i, feat in enumerate(feature_cols)}
    output = {
        "weights": weights,
        "intercept": float(lr.intercept_),
        "scaler_mean": [float(x) for x in scaler.center_.tolist()],
        "scaler_scale": [float(x) for x in scaler.scale_.tolist()],
        "features": feature_cols,
        "symbol": SYMBOL,
        "mae": round(mae, 4),
        "r2": round(r2, 4),
        "updated_at": time.strftime('%Y-%m-%d %H:%M:%S')
    }
    with open(OUTPUT_WEIGHTS, 'w') as f:
        json.dump(output, f, indent=2)
    print(f"✅ Pesos lineales guardados en {OUTPUT_WEIGHTS}")
    print("🎯 Ahora el bot puede usar 'volatility_weights.json' para ajustar spacing_pct dinámicamente.")

if __name__ == "__main__":
    train()
