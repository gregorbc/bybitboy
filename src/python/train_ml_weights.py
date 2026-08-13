#!/usr/bin/env python3
"""
Entrenamiento unificado de modelos ML para Grid Bot ETH/USDT.
Soporta:
  - Clasificador de dirección (UP/DOWN/SIDEWAYS) → ml_weights_v2.json
  - Regresor de volatilidad (ATR% futuro) → volatility_weights.json

Uso recomendado para clasificador:
  python train_ml_weights.py --type classifier --model logistic --horizon 4 --up_thr 0.5 --down_thr 0.5 --c_reg 0.1 --candles 40000

NOTA: RandomForest produce pesos planos (no direccionales). Se recomienda usar LogisticRegression.
"""

import argparse
import numpy as np
import pandas as pd
import json
import requests
import time
import warnings
warnings.filterwarnings('ignore')

# Verificar dependencias
try:
    from sklearn.linear_model import LogisticRegression
    from sklearn.ensemble import RandomForestClassifier
    from sklearn.linear_model import LinearRegression
    from sklearn.preprocessing import RobustScaler
    from sklearn.metrics import accuracy_score, mean_absolute_error, r2_score
except ImportError as e:
    print("❌ Error: scikit-learn no instalado. Ejecuta: pip install scikit-learn pandas numpy requests")
    exit(1)

INTERVAL = '5'
MIN_ACCURACY = 0.85  # Umbral mínimo para guardar el modelo

# ========================
# DESCARGA DE VELAS
# ========================
def fetch_candles(symbol, interval, limit=1000, end_time=None):
    url = "https://api.bybit.com/v5/market/kline"
    params = {"category": "linear", "symbol": symbol, "interval": interval, "limit": limit}
    if end_time:
        params["end"] = str(end_time)
    try:
        r = requests.get(url, params=params, timeout=15).json()
        return r['result']['list'] if r.get('retCode') == 0 else []
    except Exception as e:
        print(f"⚠ Error en fetch_candles: {e}")
        return []

def get_all_candles(symbol, limit):
    all_k = []
    end_time = None
    print(f"📥 Descargando {limit} velas de {symbol}...")
    while len(all_k) < limit:
        data = fetch_candles(symbol, INTERVAL, 1000, end_time)
        if not data:
            break
        all_k.extend(data)
        end_time = int(data[-1][0]) - 1
        if len(data) < 1000:
            break
        time.sleep(0.3)
    if not all_k:
        print("❌ No se obtuvieron velas. Verifica conexión a Bybit.")
        return pd.DataFrame()
    df = pd.DataFrame(all_k, columns=['ts','o','h','l','c','v','turnover'])
    for col in ['o','h','l','c','v']:
        df[col] = pd.to_numeric(df[col], errors='coerce')
    df = df.sort_values('ts').reset_index(drop=True)
    print(f"✅ Descargadas {len(df)} velas.")
    return df

# ========================
# CÁLCULO DE FEATURES
# ========================
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
    df['vwap_ratio'] = df['c'] / ( (typical * df['v']).cumsum() / df['v'].cumsum() )
    # Spread
    df['spread_pct'] = (df['h'] - df['l']) / df['c'] * 100
    # Momentum 5
    df['momentum_5'] = df['c'].pct_change(5) * 100
    return df.dropna().reset_index(drop=True)

# ========================
# ENTRENAMIENTO CLASIFICADOR
# ========================
def train_classifier(symbol, horizon, up_thr, down_thr, c_reg, max_candles, model_type):
    print("\n=== ENTRENANDO CLASIFICADOR (dirección) ===\n")
    
    # Forzar logistic si se eligió randomforest (por el problema de pesos planos)
    if model_type == 'randomforest':
        print("⚠ ADVERTENCIA: RandomForest genera pesos planos (no direccionales).")
        print("⚠ Se recomienda usar --model logistic. Cambiando automáticamente a logistic.")
        model_type = 'logistic'
    
    df = get_all_candles(symbol, max_candles)
    if df.empty:
        return
    df = add_features(df)

    future_price = df['c'].shift(-horizon)
    pct_change = (future_price - df['c']) / df['c'] * 100
    conditions = [pct_change >= up_thr, pct_change <= down_thr]
    choices = ['UP', 'DOWN']
    df['label'] = np.select(conditions, choices, default='SIDEWAYS')
    df = df.dropna(subset=['label'])

    feature_cols = ['rsi_14', 'stoch_14', 'macd_hist', 'ema_diff_9_21',
                    'vol_ratio', 'bb_width', 'atr_pct', 'vwap_ratio',
                    'spread_pct', 'momentum_5']
    X = df[feature_cols]
    y = df['label']

    split = int(len(df) * 0.8)
    X_train, X_test = X.iloc[:split], X.iloc[split:]
    y_train, y_test = y.iloc[:split], y.iloc[split:]

    scaler = RobustScaler()
    X_train_sc = scaler.fit_transform(X_train)
    X_test_sc = scaler.transform(X_test)

    print(f"📊 Usando LogisticRegression (C={c_reg}, solver='lbfgs')")
    try:
        model = LogisticRegression(multi_class='multinomial', solver='lbfgs', C=c_reg, max_iter=500, random_state=42)
    except TypeError:
        model = LogisticRegression(solver='lbfgs', C=c_reg, max_iter=500, random_state=42)

    model.fit(X_train_sc, y_train)

    y_pred = model.predict(X_test_sc)
    acc = accuracy_score(y_test, y_pred)
    print(f"Accuracy en test: {acc:.4f} ({acc*100:.1f}%)")

    # Si la accuracy es menor al mínimo, no guardar el modelo
    if acc < MIN_ACCURACY:
        print(f"⚠️ Accuracy {acc*100:.1f}% < {MIN_ACCURACY*100:.0f}% → modelo NO guardado.")
        print("   Se conserva el archivo anterior o se usará fallback.")
        return

    classes = model.classes_.tolist()
    weights = {}
    if len(model.coef_.shape) == 1:
        # Caso binario (solo dos clases)
        classes_bin = [model.classes_[0], model.classes_[1]]
        for i, feat in enumerate(feature_cols):
            weights[feat] = {classes_bin[0]: float(model.coef_[i]), classes_bin[1]: -float(model.coef_[i])}
        for feat in feature_cols:
            weights[feat]['SIDEWAYS'] = 0.0
    else:
        # Multiclase
        for i, feat in enumerate(feature_cols):
            weights[feat] = {}
            for j, cls in enumerate(classes):
                weights[feat][cls] = float(model.coef_[j][i])
    intercepts = [float(inter) for inter in model.intercept_]

    output = {
        "weights": weights,
        "intercepts": intercepts,
        "scaler_mean": [float(x) for x in scaler.center_.tolist()],
        "scaler_scale": [float(x) for x in scaler.scale_.tolist()],
        "features": feature_cols,
        "classes": classes,
        "acc": acc,
        "symbol": symbol,
        "model_type": "logistic",
        "updated_at": time.strftime('%Y-%m-%d %H:%M:%S')
    }
    with open('ml_weights_v2.json', 'w') as f:
        json.dump(output, f, indent=2)
    print("✅ Clasificador guardado en ml_weights_v2.json (accuracy >= 85%)")
    return output

# ========================
# ENTRENAMIENTO REGRESOR (volatilidad)
# ========================
def train_volatility(symbol, horizon, max_candles):
    print("\n=== ENTRENANDO REGRESOR (ATR% futuro) ===\n")
    df = get_all_candles(symbol, max_candles)
    if df.empty:
        return
    df = add_features(df)
    df['atr_future'] = df['atr_pct'].shift(-horizon)
    df = df.dropna().reset_index(drop=True)

    feature_cols = ['rsi_14', 'stoch_14', 'macd_hist', 'ema_diff_9_21',
                    'vol_ratio', 'bb_width', 'atr_pct', 'vwap_ratio',
                    'spread_pct', 'momentum_5']
    X = df[feature_cols]
    y = df['atr_future']

    split = int(len(df) * 0.8)
    X_train, X_test = X.iloc[:split], X.iloc[split:]
    y_train, y_test = y.iloc[:split], y.iloc[split:]

    scaler = RobustScaler()
    X_train_sc = scaler.fit_transform(X_train)
    X_test_sc = scaler.transform(X_test)

    model = LinearRegression()
    model.fit(X_train_sc, y_train)

    y_pred = model.predict(X_test_sc)
    mae = mean_absolute_error(y_test, y_pred)
    r2 = r2_score(y_test, y_pred)
    print(f"MAE = {mae:.4f}%   R² = {r2:.4f}")

    weights = {feat: float(model.coef_[i]) for i, feat in enumerate(feature_cols)}
    output = {
        "weights": weights,
        "intercept": float(model.intercept_),
        "scaler_mean": [float(x) for x in scaler.center_.tolist()],
        "scaler_scale": [float(x) for x in scaler.scale_.tolist()],
        "features": feature_cols,
        "symbol": symbol,
        "mae": round(mae, 4),
        "r2": round(r2, 4),
        "updated_at": time.strftime('%Y-%m-%d %H:%M:%S')
    }
    with open('volatility_weights.json', 'w') as f:
        json.dump(output, f, indent=2)
    print("✅ Regresor guardado en volatility_weights.json")
    return output

# ========================
# MAIN
# ========================
if __name__ == "__main__":
    parser = argparse.ArgumentParser(description='Entrenamiento unificado ML para Grid Bot')
    parser.add_argument('--type', choices=['classifier', 'volatility'], required=True,
                        help='Tipo de modelo a entrenar')
    parser.add_argument('--symbol', type=str, default='ETHUSDT',
                        help='Símbolo (ej. ETHUSDT)')
    parser.add_argument('--horizon', type=int, default=4,
                        help='Horizonte en velas (clasificador: 4 → 20 min; volatilidad: 4 → 20 min)')
    parser.add_argument('--up_thr', type=float, default=0.5,
                        help='Umbral positivo para UP (%)')
    parser.add_argument('--down_thr', type=float, default=0.5,
                        help='Umbral negativo para DOWN (%) (se usará como negativo)')
    parser.add_argument('--c_reg', type=float, default=0.1,
                        help='Regularización C para clasificador logístico')
    parser.add_argument('--candles', type=int, default=40000,
                        help='Número máximo de velas a descargar')
    parser.add_argument('--model', choices=['logistic', 'randomforest'], default='logistic',
                        help='Modelo de clasificación (por defecto logistic)')
    args = parser.parse_args()

    down_thr_val = -abs(args.down_thr)

    if args.type == 'classifier':
        train_classifier(args.symbol, args.horizon, args.up_thr, down_thr_val, args.c_reg, args.candles, args.model)
    else:
        train_volatility(args.symbol, args.horizon, args.candles)