#!/usr/bin/env python3
"""
train_volatility_ridge.py – Regresor Ridge mejorado para ATR% futuro (volatilidad)
Uso: python train_volatility_ridge.py --symbol ETHUSDT --horizon 4 --candles 40000
"""

import argparse
import json
import time
import sys
import os
import shutil
import numpy as np
import pandas as pd
import requests
from sklearn.linear_model import Ridge
from sklearn.preprocessing import RobustScaler
from sklearn.metrics import mean_absolute_error, r2_score
import warnings
warnings.filterwarnings('ignore')

# Configuración por defecto (sobrescribibles vía CLI)
DEFAULT_SYMBOL = 'ETHUSDT'
DEFAULT_INTERVAL = '5'
DEFAULT_MAX_CANDLES = 40000
DEFAULT_HORIZON = 4          # velas hacia adelante (20 min)
DEFAULT_TEST_SPLIT = 0.2
DEFAULT_ALPHA = None         # auto búsqueda si None
DEFAULT_OUTPUT = 'volatility_weights_ridge.json'
DEFAULT_BACKUP_DIR = 'ml_backups'
DEFAULT_R2_THRESHOLD = 0.4   # mínimo R² para aceptar el modelo
DEFAULT_MAE_THRESHOLD = 0.1  # máximo MAE (%) para aceptar

# ============================================================
# Descarga de datos
# ============================================================
def fetch_candles(symbol, interval, limit=1000, end_time=None):
    url = "https://api.bybit.com/v5/market/kline"
    params = {
        "category": "linear",
        "symbol": symbol,
        "interval": interval,
        "limit": limit
    }
    if end_time:
        params["end"] = str(end_time)
    try:
        r = requests.get(url, params=params, timeout=15)
        r.raise_for_status()
        data = r.json()
        if data.get('retCode') == 0:
            return data['result']['list']
        else:
            print(f"  ⚠ Bybit error {data.get('retCode')}: {data.get('retMsg')}")
            return []
    except Exception as e:
        print(f"  ⚠ fetch_candles: {e}")
        return []

def download_candles(symbol, interval, max_candles):
    all_k = []
    end_time = None
    print(f"📥 Descargando hasta {max_candles} velas de {symbol} ({interval}m)...")
    while len(all_k) < max_candles:
        batch = fetch_candles(symbol, interval, 1000, end_time)
        if not batch:
            break
        all_k.extend(batch)
        end_time = int(batch[-1][0]) - 1
        if len(batch) < 1000:
            break
        time.sleep(0.3)
    if not all_k:
        print("❌ No se obtuvieron velas. Verifica conexión.")
        return pd.DataFrame()
    df = pd.DataFrame(all_k, columns=['ts','o','h','l','c','v','turnover'])
    for col in ['o','h','l','c','v']:
        df[col] = pd.to_numeric(df[col], errors='coerce')
    df = df.sort_values('ts').reset_index(drop=True)
    print(f"✅ Descargadas {len(df)} velas.")
    return df

# ============================================================
# Features (mismas que en el clasificador)
# ============================================================
def add_features(df):
    # RSI 14
    delta = df['c'].diff()
    gain = delta.where(delta > 0, 0).rolling(14).mean()
    loss = (-delta.where(delta < 0, 0)).rolling(14).mean()
    rs = gain / loss
    df['rsi_14'] = 100 - (100 / (1 + rs))

    # Estocástico %K
    low_min = df['l'].rolling(14).min()
    high_max = df['h'].rolling(14).max()
    df['stoch_14'] = 100 * (df['c'] - low_min) / (high_max - low_min)

    # MACD histograma
    ema12 = df['c'].ewm(span=12, adjust=False).mean()
    ema26 = df['c'].ewm(span=26, adjust=False).mean()
    macd = ema12 - ema26
    signal = macd.ewm(span=9, adjust=False).mean()
    df['macd_hist'] = macd - signal

    # Diferencia EMA9 / EMA21 normalizada
    ema9 = df['c'].ewm(span=9, adjust=False).mean()
    ema21 = df['c'].ewm(span=21, adjust=False).mean()
    df['ema_diff_9_21'] = (ema9 - ema21) / df['c']

    # Volumen ratio
    df['vol_ratio'] = df['v'] / df['v'].rolling(20).mean()

    # Bollinger Width
    bb_avg = df['c'].rolling(20).mean()
    bb_std = df['c'].rolling(20).std()
    df['bb_width'] = (2 * bb_std) / bb_avg

    # ATR% actual
    tr = pd.concat([df['h'] - df['l'],
                    (df['h'] - df['c'].shift()).abs(),
                    (df['l'] - df['c'].shift()).abs()], axis=1).max(axis=1)
    atr = tr.rolling(14).mean()
    df['atr_pct'] = atr / df['c'] * 100

    # VWAP ratio (precio / VWAP)
    typical = (df['h'] + df['l'] + df['c']) / 3
    vwap_cum = (typical * df['v']).cumsum() / df['v'].cumsum()
    df['vwap_ratio'] = df['c'] / vwap_cum

    # Spread porcentual
    df['spread_pct'] = (df['h'] - df['l']) / df['c'] * 100

    # Momentum 5 velas
    df['momentum_5'] = df['c'].pct_change(5) * 100

    return df.dropna().reset_index(drop=True)

# ============================================================
# Backups y validación
# ============================================================
def backup_existing(output_file, backup_dir):
    if os.path.exists(output_file):
        os.makedirs(backup_dir, exist_ok=True)
        timestamp = time.strftime('%Y%m%d_%H%M%S')
        backup_path = os.path.join(backup_dir, f"{os.path.basename(output_file)}.{timestamp}.bak")
        shutil.copy2(output_file, backup_path)
        print(f"📁 Backup creado: {backup_path}")
        return backup_path
    return None

def restore_backup(backup_path, output_file):
    if backup_path and os.path.exists(backup_path):
        shutil.copy2(backup_path, output_file)
        print(f"🔄 Restaurado backup: {backup_path} -> {output_file}")
        return True
    return False

def evaluate_model(y_true, y_pred):
    mae = mean_absolute_error(y_true, y_pred)
    r2 = r2_score(y_true, y_pred)
    # Evitar división por cero en MAPE
    mape = np.mean(np.abs((y_true - y_pred) / np.maximum(y_true, 1e-6))) * 100
    return mae, r2, mape

# ============================================================
# Entrenamiento principal
# ============================================================
def train(args):
    print("\n" + "="*60)
    print(f"Entrenando regresor Ridge para volatilidad ({args.symbol})")
    print(f"Horizonte: {args.horizon} velas ({args.horizon*5} min)")
    print(f"Velas máximas: {args.candles}")
    print("="*60 + "\n")

    # 1. Descargar datos
    df = download_candles(args.symbol, args.interval, args.candles)
    if df.empty:
        print("❌ Abortando: no hay datos.")
        return 1

    # 2. Features y target
    df = add_features(df)
    df['atr_future'] = df['atr_pct'].shift(-args.horizon)
    df = df.dropna().reset_index(drop=True)

    # 3. Eliminar outliers extremos en target (percentil 99.9)
    upper_limit = np.percentile(df['atr_future'], 99.9)
    before = len(df)
    df = df[df['atr_future'] <= upper_limit].reset_index(drop=True)
    print(f"📊 Outliers > {upper_limit:.4f}% eliminados: {before - len(df)} filas")

    feature_cols = [
        'rsi_14', 'stoch_14', 'macd_hist', 'ema_diff_9_21',
        'vol_ratio', 'bb_width', 'atr_pct', 'vwap_ratio',
        'spread_pct', 'momentum_5'
    ]
    X = df[feature_cols]
    y = df['atr_future']

    # 4. División temporal (respetar orden)
    split_idx = int(len(df) * (1 - args.test_split))
    X_train, X_test = X.iloc[:split_idx], X.iloc[split_idx:]
    y_train, y_test = y.iloc[:split_idx], y.iloc[split_idx:]
    print(f"📅 División temporal: train={len(X_train)} test={len(X_test)}")

    # 5. Escalado robusto
    scaler = RobustScaler()
    X_train_sc = scaler.fit_transform(X_train)
    X_test_sc = scaler.transform(X_test)

    # 6. Transformación logarítmica de y (para positividad)
    y_train_log = np.log(y_train)
    y_test_log = np.log(y_test)

    # 7. Búsqueda de alpha óptimo (si no se especificó)
    if args.alpha is None:
        alphas = [0.01, 0.1, 1.0, 10.0, 100.0]
        best_alpha = 1.0
        best_score = -np.inf
        print("🔍 Buscando mejor alpha (R² en train log)...")
        for alpha in alphas:
            model = Ridge(alpha=alpha, random_state=42)
            model.fit(X_train_sc, y_train_log)
            score = model.score(X_train_sc, y_train_log)
            print(f"   alpha={alpha:.2f} -> R²={score:.4f}")
            if score > best_score:
                best_score = score
                best_alpha = alpha
        print(f"✅ Mejor alpha: {best_alpha} (R²={best_score:.4f})")
    else:
        best_alpha = args.alpha
        print(f"📌 Usando alpha fijo: {best_alpha}")

    # 8. Entrenar modelo final
    model = Ridge(alpha=best_alpha, random_state=42)
    model.fit(X_train_sc, y_train_log)
    y_pred_log = model.predict(X_test_sc)
    y_pred = np.exp(y_pred_log)

    # 9. Evaluar en test (espacio original)
    mae, r2, mape = evaluate_model(y_test, y_pred)
    error_std = np.std(y_test - y_pred)
    print(f"\n📈 Resultados en TEST:")
    print(f"   MAE  = {mae:.4f}%")
    print(f"   R²   = {r2:.4f}")
    print(f"   MAPE = {mape:.2f}%")
    print(f"   σ_error = {error_std:.4f}")

    # 10. Verificar calidad
    if r2 < args.r2_threshold:
        print(f"⚠️  R² ({r2:.4f}) es inferior al umbral {args.r2_threshold}. Modelo rechazado.")
        return 1
    if mae > args.mae_threshold:
        print(f"⚠️  MAE ({mae:.4f}%) excede el umbral {args.mae_threshold}%. Modelo rechazado.")
        return 1

    # 11. Clips para predicción
    y_pred_all = np.exp(model.predict(scaler.transform(X)))
    lower_clip = max(0.05, np.percentile(y_pred_all, 1))
    upper_clip = np.percentile(y_pred_all, 99)
    print(f"\n✂️  Clips de predicción: lower={lower_clip:.4f}%  upper={upper_clip:.4f}%")

    # 12. Guardar backup del modelo anterior
    backup_path = backup_existing(args.output, args.backup_dir)

    # 13. Construir JSON de salida
    weights = {feat: float(model.coef_[i]) for i, feat in enumerate(feature_cols)}
    output = {
        "weights": weights,
        "intercept": float(model.intercept_),
        "scaler_mean": [float(x) for x in scaler.center_.tolist()],
        "scaler_scale": [float(x) for x in scaler.scale_.tolist()],
        "features": feature_cols,
        "symbol": args.symbol,
        "horizon": args.horizon,
        "mae": round(mae, 4),
        "r2": round(r2, 4),
        "prediction_clip_lower": round(lower_clip, 4),
        "prediction_clip_upper": round(upper_clip, 4),
        "error_std": round(error_std, 4),
        "log_transform": True,
        "alpha": best_alpha,
        "updated_at": time.strftime('%Y-%m-%d %H:%M:%S')
    }

    try:
        with open(args.output, 'w') as f:
            json.dump(output, f, indent=2)
        print(f"\n✅ Modelo guardado en: {args.output}")
        # Eliminar backup antiguo (opcional, conservamos por si acaso)
        # os.remove(backup_path)  # descomentar si se quiere limpiar
        return 0
    except Exception as e:
        print(f"❌ Error escribiendo archivo: {e}")
        if backup_path:
            restore_backup(backup_path, args.output)
        return 1

# ============================================================
# Punto de entrada
# ============================================================
if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Entrenamiento de regresor Ridge para ATR% futuro")
    parser.add_argument("--symbol", default=DEFAULT_SYMBOL, help="Símbolo de trading (ej. ETHUSDT)")
    parser.add_argument("--interval", default=DEFAULT_INTERVAL, help="Intervalo en minutos (5, 15, 60)")
    parser.add_argument("--candles", type=int, default=DEFAULT_MAX_CANDLES, help="Número máximo de velas a descargar")
    parser.add_argument("--horizon", type=int, default=DEFAULT_HORIZON, help="Horizonte en velas (ej. 4 = 20 min)")
    parser.add_argument("--test_split", type=float, default=DEFAULT_TEST_SPLIT, help="Proporción para test (0.0-1.0)")
    parser.add_argument("--alpha", type=float, default=DEFAULT_ALPHA, help="Alpha para Ridge (auto si None)")
    parser.add_argument("--output", default=DEFAULT_OUTPUT, help="Archivo JSON de salida")
    parser.add_argument("--backup_dir", default=DEFAULT_BACKUP_DIR, help="Directorio de backups")
    parser.add_argument("--r2_threshold", type=float, default=DEFAULT_R2_THRESHOLD, help="R² mínimo aceptable")
    parser.add_argument("--mae_threshold", type=float, default=DEFAULT_MAE_THRESHOLD, help="MAE máximo aceptable (%)")
    parser.add_argument("--no_backup", action="store_true", help="No crear backup del modelo anterior")

    args = parser.parse_args()
    sys.exit(train(args))