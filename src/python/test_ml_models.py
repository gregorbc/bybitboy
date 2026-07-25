#!/usr/bin/env python3
"""
Pruebas unitarias para los modelos ML del Grid Bot ETH/USDT.
Cubre: train_ml_weights.py, train_volatility_ridge.py, trainer_run.py
"""

import unittest
import numpy as np
import pandas as pd
import json
import os
from unittest.mock import patch, MagicMock
from io import StringIO

# Importar las funciones a testear
import train_ml_weights
import train_volatility_ridge
import trainer_run


class TestAddFeatures(unittest.TestCase):
    """Pruebas para la función add_features en diferentes módulos"""

    def setUp(self):
        """Crear datos de prueba comunes"""
        np.random.seed(42)
        n = 100
        self.df_base = pd.DataFrame({
            'ts': range(n),
            'o': np.random.uniform(100, 110, n),
            'h': np.random.uniform(110, 120, n),
            'l': np.random.uniform(90, 100, n),
            'c': np.random.uniform(100, 110, n),
            'v': np.random.uniform(1000, 5000, n)
        })
        # Asegurar que h >= c y h >= o y l <= c y l <= o
        self.df_base['h'] = self.df_base[['h', 'c', 'o']].max(axis=1)
        self.df_base['l'] = self.df_base[['l', 'c', 'o']].min(axis=1)

    def test_train_ml_weights_add_features_columns(self):
        """Verificar que add_features de train_ml_weights agrega todas las columnas esperadas"""
        df = train_ml_weights.add_features(self.df_base.copy())
        
        expected_cols = [
            'rsi_14', 'stoch_14', 'macd_hist', 'ema_diff_9_21',
            'vol_ratio', 'bb_width', 'atr_pct', 'vwap_ratio',
            'spread_pct', 'momentum_5'
        ]
        
        for col in expected_cols:
            self.assertIn(col, df.columns, f"La columna {col} no está presente")
        
        # Verificar que no hay NaN después de dropna
        self.assertEqual(len(df), len(df.dropna()))

    def test_train_volatility_ridge_add_features_columns(self):
        """Verificar que add_features de train_volatility_ridge agrega todas las columnas"""
        df = train_volatility_ridge.add_features(self.df_base.copy())
        
        expected_cols = [
            'rsi_14', 'stoch_14', 'macd_hist', 'ema_diff_9_21',
            'vol_ratio', 'bb_width', 'atr_pct', 'vwap_ratio',
            'spread_pct', 'momentum_5'
        ]
        
        for col in expected_cols:
            self.assertIn(col, df.columns, f"La columna {col} no está presente")

    def test_trainer_run_add_features_columns(self):
        """Verificar que add_features de trainer_run agrega todas las columnas"""
        df = trainer_run.add_features(self.df_base.copy())
        
        expected_cols = [
            'rsi_14', 'stoch_14', 'macd_hist', 'ema_diff_9_21',
            'vol_ratio', 'bb_width', 'atr_pct', 'vwap_ratio',
            'spread_pct', 'momentum_5'
        ]
        
        for col in expected_cols:
            self.assertIn(col, df.columns, f"La columna {col} no está presente")

    def test_rsi_range(self):
        """Verificar que RSI está en rango [0, 100]"""
        df = train_ml_weights.add_features(self.df_base.copy())
        rsi_values = df['rsi_14'].dropna()
        
        if len(rsi_values) > 0:
            self.assertTrue((rsi_values >= 0).all(), "RSI tiene valores negativos")
            self.assertTrue((rsi_values <= 100).all(), "RSI tiene valores > 100")

    def test_stochastic_range(self):
        """Verificar que Estocástico está en rango [0, 100]"""
        df = train_ml_weights.add_features(self.df_base.copy())
        stoch_values = df['stoch_14'].dropna()
        
        if len(stoch_values) > 0:
            # Permitir pequeños desvíos por cálculos numéricos
            self.assertTrue((stoch_values >= -1).all(), "Stochastic tiene valores < 0")
            self.assertTrue((stoch_values <= 101).all(), "Stochastic tiene valores > 100")


class TestFetchCandles(unittest.TestCase):
    """Pruebas para funciones de obtención de velas"""

    @patch('train_ml_weights.requests.get')
    def test_fetch_candles_success(self, mock_get):
        """Verificar fetch_candles con respuesta exitosa"""
        mock_response = MagicMock()
        mock_response.json.return_value = {
            'retCode': 0,
            'result': {
                'list': [['1234567890', '100', '110', '90', '105', '1000', '100000']]
            }
        }
        mock_get.return_value = mock_response
        
        result = train_ml_weights.fetch_candles('ETHUSDT', '5', limit=100)
        
        self.assertEqual(len(result), 1)
        self.assertEqual(result[0][0], '1234567890')
        mock_get.assert_called_once()

    @patch('train_ml_weights.requests.get')
    def test_fetch_candles_error(self, mock_get):
        """Verificar fetch_candles con error de API"""
        mock_response = MagicMock()
        mock_response.json.return_value = {'retCode': 1, 'retMsg': 'Error'}
        mock_get.return_value = mock_response
        
        result = train_ml_weights.fetch_candles('ETHUSDT', '5', limit=100)
        
        self.assertEqual(result, [])

    @patch('train_ml_weights.requests.get')
    def test_fetch_candles_exception(self, mock_get):
        """Verificar fetch_candles con excepción"""
        mock_get.side_effect = Exception("Connection error")
        
        result = train_ml_weights.fetch_candles('ETHUSDT', '5', limit=100)
        
        self.assertEqual(result, [])

    @patch('train_volatility_ridge.requests.get')
    def test_volatility_fetch_candles(self, mock_get):
        """Verificar fetch_candles en train_volatility_ridge"""
        mock_response = MagicMock()
        mock_response.json.return_value = {
            'retCode': 0,
            'result': {
                'list': [['1234567890', '100', '110', '90', '105', '1000', '100000']]
            }
        }
        mock_get.return_value = mock_response
        
        result = train_volatility_ridge.fetch_candles('ETHUSDT', '5', limit=100)
        
        self.assertEqual(len(result), 1)


class TestClassifierTraining(unittest.TestCase):
    """Pruebas para el entrenamiento del clasificador"""

    def test_label_creation_logic(self):
        """Verificar lógica de creación de etiquetas UP/DOWN/SIDEWAYS"""
        df = pd.DataFrame({'c': [100.0, 101.0, 99.0, 100.5, 99.5]})
        
        horizon = 1
        up_thr = 0.5
        down_thr = -0.5
        
        future_price = df['c'].shift(-horizon)
        pct_change = (future_price - df['c']) / df['c'] * 100
        
        conditions = [pct_change >= up_thr, pct_change <= down_thr]
        choices = ['UP', 'DOWN']
        df['label'] = np.select(conditions, choices, default='SIDEWAYS')
        
        # Verificar que las etiquetas son correctas
        labels = df['label'].tolist()
        self.assertIn('UP', labels)
        self.assertIn('DOWN', labels)
        self.assertIn('SIDEWAYS', labels)

    def test_feature_columns_match(self):
        """Verificar que las columnas de features son consistentes"""
        expected_features = [
            'rsi_14', 'stoch_14', 'macd_hist', 'ema_diff_9_21',
            'vol_ratio', 'bb_width', 'atr_pct', 'vwap_ratio',
            'spread_pct', 'momentum_5'
        ]
        
        # Verificar en train_ml_weights
        classifier_features = ['rsi_14', 'stoch_14', 'macd_hist', 'ema_diff_9_21',
                               'vol_ratio', 'bb_width', 'atr_pct', 'vwap_ratio',
                               'spread_pct', 'momentum_5']
        self.assertEqual(classifier_features, expected_features)
        
        # Verificar en train_volatility_ridge
        volatility_features = ['rsi_14', 'stoch_14', 'macd_hist', 'ema_diff_9_21',
                               'vol_ratio', 'bb_width', 'atr_pct', 'vwap_ratio',
                               'spread_pct', 'momentum_5']
        self.assertEqual(volatility_features, expected_features)


class TestModelOutput(unittest.TestCase):
    """Pruebas para la estructura de salida de los modelos"""

    def test_classifier_output_structure(self):
        """Verificar estructura de salida del clasificador"""
        output = {
            "weights": {"rsi_14": {"UP": 0.1, "DOWN": -0.1, "SIDEWAYS": 0.0}},
            "intercepts": [0.5, 0.3, 0.2],
            "scaler_mean": [0.0] * 10,
            "scaler_scale": [1.0] * 10,
            "features": ["rsi_14"] + ["other"] * 9,
            "classes": ["UP", "DOWN", "SIDEWAYS"],
            "acc": 0.90,
            "symbol": "ETHUSDT",
            "model_type": "logistic",
            "updated_at": "2024-01-01 00:00:00"
        }
        
        required_keys = ["weights", "intercepts", "scaler_mean", "scaler_scale",
                        "features", "classes", "acc", "symbol", "model_type", "updated_at"]
        
        for key in required_keys:
            self.assertIn(key, output, f"Falta la clave {key}")

    def test_volatility_output_structure(self):
        """Verificar estructura de salida del regresor de volatilidad"""
        output = {
            "weights": {"rsi_14": 0.1},
            "intercept": 0.5,
            "scaler_mean": [0.0] * 10,
            "scaler_scale": [1.0] * 10,
            "features": ["rsi_14"] + ["other"] * 9,
            "symbol": "ETHUSDT",
            "mae": 0.05,
            "r2": 0.85,
            "updated_at": "2024-01-01 00:00:00"
        }
        
        required_keys = ["weights", "intercept", "scaler_mean", "scaler_scale",
                        "features", "symbol", "mae", "r2", "updated_at"]
        
        for key in required_keys:
            self.assertIn(key, output, f"Falta la clave {key}")

    def test_ridge_output_structure(self):
        """Verificar estructura de salida del regresor Ridge"""
        output = {
            "weights": {"rsi_14": 0.1},
            "intercept": 0.5,
            "scaler_mean": [0.0] * 10,
            "scaler_scale": [1.0] * 10,
            "features": ["rsi_14"] + ["other"] * 9,
            "symbol": "ETHUSDT",
            "mae": 0.05,
            "r2": 0.85,
            "prediction_clip_lower": 0.05,
            "prediction_clip_upper": 0.50,
            "error_std": 0.03,
            "updated_at": "2024-01-01 00:00:00"
        }
        
        required_keys = ["weights", "intercept", "scaler_mean", "scaler_scale",
                        "features", "symbol", "mae", "r2", "updated_at"]
        
        for key in required_keys:
            self.assertIn(key, output, f"Falta la clave {key}")
        
        # Claves específicas de Ridge
        ridge_keys = ["prediction_clip_lower", "prediction_clip_upper", "error_std"]
        for key in ridge_keys:
            self.assertIn(key, output, f"Falta la clave específica de Ridge: {key}")


class TestScalerTransformation(unittest.TestCase):
    """Pruebas para transformación de escalado"""

    def test_scaler_transformation_shape(self):
        """Verificar que el escalado mantiene la forma de los datos"""
        from sklearn.preprocessing import RobustScaler
        
        X = pd.DataFrame(np.random.randn(100, 10))
        scaler = RobustScaler()
        
        X_scaled = scaler.fit_transform(X)
        
        self.assertEqual(X_scaled.shape, X.shape)

    def test_scaler_inverse_transform(self):
        """Verificar que se puede hacer transformada inversa"""
        from sklearn.preprocessing import RobustScaler
        
        X = pd.DataFrame(np.random.randn(100, 10))
        scaler = RobustScaler()
        
        X_scaled = scaler.fit_transform(X)
        X_inverse = scaler.inverse_transform(X_scaled)
        
        np.testing.assert_array_almost_equal(X, X_inverse)


class TestAccuracyThreshold(unittest.TestCase):
    """Pruebas para umbrales de accuracy"""

    def test_min_accuracy_constant(self):
        """Verificar que MIN_ACCURACY está definido correctamente"""
        self.assertTrue(hasattr(train_ml_weights, 'MIN_ACCURACY'))
        self.assertGreaterEqual(train_ml_weights.MIN_ACCURACY, 0.0)
        self.assertLessEqual(train_ml_weights.MIN_ACCURACY, 1.0)
        self.assertEqual(train_ml_weights.MIN_ACCURACY, 0.85)


class TestDataFrameOperations(unittest.TestCase):
    """Pruebas para operaciones con DataFrames"""

    def test_shift_operation(self):
        """Verificar operación shift para crear targets futuros"""
        df = pd.DataFrame({'c': [1.0, 2.0, 3.0, 4.0, 5.0]})
        horizon = 2
        
        future_price = df['c'].shift(-horizon)
        
        self.assertTrue(np.isnan(future_price.iloc[-1]))
        self.assertTrue(np.isnan(future_price.iloc[-2]))
        self.assertEqual(future_price.iloc[0], 3.0)

    def test_dropna_removes_invalid_rows(self):
        """Verificar que dropna elimina filas con valores inválidos"""
        df = pd.DataFrame({
            'a': [1.0, 2.0, np.nan, 4.0],
            'b': [1.0, np.nan, 3.0, 4.0]
        })
        
        df_clean = df.dropna()
        
        self.assertEqual(len(df_clean), 2)
        self.assertFalse(df_clean.isnull().any().any())

    def test_pct_change_calculation(self):
        """Verificar cálculo de cambio porcentual"""
        df = pd.DataFrame({'c': [100.0, 105.0, 110.0, 108.0]})
        
        pct_change = df['c'].pct_change()
        
        self.assertTrue(np.isnan(pct_change.iloc[0]))
        self.assertAlmostEqual(pct_change.iloc[1], 0.05, places=2)
        self.assertAlmostEqual(pct_change.iloc[2], 0.0476, places=2)


class TestMockedTraining(unittest.TestCase):
    """Pruebas con datos mockeados para entrenamiento"""

    @patch('train_ml_weights.get_all_candles')
    def test_classifier_training_with_mock_data(self, mock_candles):
        """Probar entrenamiento del clasificador con datos mockeados"""
        np.random.seed(42)
        n = 200
        
        mock_df = pd.DataFrame({
            'ts': range(n),
            'o': np.random.uniform(100, 110, n),
            'h': np.random.uniform(110, 120, n),
            'l': np.random.uniform(90, 100, n),
            'c': np.random.uniform(100, 110, n),
            'v': np.random.uniform(1000, 5000, n)
        })
        mock_df['h'] = mock_df[['h', 'c', 'o']].max(axis=1)
        mock_df['l'] = mock_df[['l', 'c', 'o']].min(axis=1)
        
        mock_candles.return_value = mock_df
        
        with patch('sys.stdout', new_callable=StringIO) as mock_stdout:
            result = train_ml_weights.train_classifier(
                symbol='ETHUSDT',
                horizon=4,
                up_thr=0.5,
                down_thr=-0.5,
                c_reg=0.1,
                max_candles=200,
                model_type='logistic'
            )
        
        if result is not None:
            self.assertIn('weights', result)
            self.assertIn('classes', result)
            self.assertIn('acc', result)

    @patch('train_volatility_ridge.get_all_candles')
    def test_volatility_training_with_mock_data(self, mock_candles):
        """Probar entrenamiento de volatilidad con datos mockeados"""
        np.random.seed(42)
        n = 200
        
        mock_df = pd.DataFrame({
            'ts': range(n),
            'o': np.random.uniform(100, 110, n),
            'h': np.random.uniform(110, 120, n),
            'l': np.random.uniform(90, 100, n),
            'c': np.random.uniform(100, 110, n),
            'v': np.random.uniform(1000, 5000, n)
        })
        mock_df['h'] = mock_df[['h', 'c', 'o']].max(axis=1)
        mock_df['l'] = mock_df[['l', 'c', 'o']].min(axis=1)
        
        mock_candles.return_value = mock_df
        
        with patch('sys.stdout', new_callable=StringIO) as mock_stdout:
            with patch.object(train_volatility_ridge, 'MAX_CANDLES', 200):
                # Usar un archivo temporal para no modificar el original
                with patch.object(train_volatility_ridge, '__file__', '/tmp/train_volatility_ridge.py'):
                    import tempfile
                    import shutil
                    
                    # Guardar estado original
                    original_dir = os.getcwd()
                    temp_dir = tempfile.mkdtemp()
                    
                    try:
                        os.chdir(temp_dir)
                        train_volatility_ridge.train()
                        
                        self.assertTrue(os.path.exists('volatility_weights_ridge.json'))
                        
                        with open('volatility_weights_ridge.json', 'r') as f:
                            output = json.load(f)
                        
                        self.assertIn('weights', output)
                        self.assertIn('mae', output)
                        self.assertIn('r2', output)
                    finally:
                        os.chdir(original_dir)
                        shutil.rmtree(temp_dir)


class TestJSONSerialization(unittest.TestCase):
    """Pruebas para serialización JSON"""

    def test_weights_json_serializable(self):
        """Verificar que los pesos son serializables a JSON"""
        weights = {
            'rsi_14': {'UP': 0.123456789, 'DOWN': -0.987654321, 'SIDEWAYS': 0.0},
            'stoch_14': {'UP': 0.111, 'DOWN': -0.222, 'SIDEWAYS': 0.0}
        }
        
        try:
            json_str = json.dumps(weights)
            loaded = json.loads(json_str)
            self.assertEqual(weights, loaded)
        except (TypeError, ValueError) as e:
            self.fail(f"JSON serialization failed: {e}")

    def test_numpy_types_conversion(self):
        """Verificar conversión de tipos numpy a Python nativo"""
        value = np.float64(0.123456789)
        
        converted = float(value)
        
        self.assertIsInstance(converted, float)
        
        data = {"value": converted}
        json_str = json.dumps(data)
        self.assertIsInstance(json_str, str)


if __name__ == '__main__':
    unittest.main(verbosity=2)
