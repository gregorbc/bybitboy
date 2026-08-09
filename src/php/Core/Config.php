<?php
declare(strict_types=1);

namespace BinanceBot\Core;

class Config
{
    private static ?self $instance = null;
    private array $config = [];
    private static array $envKeys = [];

    private function __construct()
    {
        $this->load();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        foreach (self::$envKeys as $key) {
            putenv($key);
        }
        self::$envKeys = [];
        self::$instance = null;
    }

    private function load(): void
    {
        $this->loadEnv();

        $homeCfg = getenv('HOME') ? getenv('HOME') . '/config/config.json' : null;
        $paths = [
            dirname(__DIR__, 4) . '/private/config.json',
            dirname(__DIR__) . '/config.json',
        ];
        if ($homeCfg) { $paths[] = $homeCfg; }

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $decoded = json_decode(file_get_contents($path), true);
                if (is_array($decoded)) {
                    $this->config = $decoded;
                }
                break;
            }
        }

        $this->mergeEnv();
    }

    private function loadEnv(): void
    {
        foreach ($this->envFileCandidates() as $envFile) {
            if (file_exists($envFile)) {
                $this->loadEnvFile($envFile);
            }
        }
    }

    /**
     * Rutas candidatas para el archivo .env, en orden de preferencia.
     * El .env puede vivir en src/, en la raíz del proyecto o junto al código.
     */
    public static function envFileCandidates(): array
    {
        return [
            dirname(__DIR__, 2) . '/.env',
            dirname(__DIR__, 3) . '/.env',
            dirname(__DIR__) . '/.env',
        ];
    }

    private function loadEnvFile(string $envFile): void
    {
        if (!file_exists($envFile)) {
            return;
        }

        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (getenv($key) === false) {
                putenv($key . '=' . trim($value, '"\' '));
            }
            self::$envKeys[] = $key;
        }
    }

    private function mergeEnv(): void
    {
        $envMap = [
            'BYBIT_API_KEY'     => ['bybit', 'api_key'],
            'BYBIT_API_SECRET'  => ['bybit', 'api_secret'],
            'BYBIT_TESTNET'     => ['bybit', 'testnet'],
            'MYSQL_HOST'        => ['mysql', 'host'],
            'MYSQL_DBNAME'      => ['mysql', 'dbname'],
            'MYSQL_USER'        => ['mysql', 'user'],
            'MYSQL_PASSWORD'    => ['mysql', 'password'],
            'SECURITY_TOKEN'    => ['security_token'],
            'WS_TOKEN'          => ['ws_token'],
            'NVIDIA_API_KEY'    => ['nvidia', 'api_key'],
            'NVIDIA_ENABLED'    => ['nvidia', 'enabled'],
            'BOT_SYMBOL'        => ['bot', 'symbol'],
            'BOT_CAPITAL_USD'   => ['bot', 'capital_usd'],
            'BOT_LEVERAGE'      => ['bot', 'leverage'],
            'BOT_CYCLE_SEC'     => ['bot', 'cycle_sec'],
            'BOT_AI_INTERVAL_SEC' => ['bot', 'ai_interval_sec'],
            'BOT_TIMEFRAME'     => ['bot', 'timeframe'],
            'BOT_CANDLES_FEED'  => ['bot', 'candles_feed'],
            'BOT_LEVELS'        => ['bot', 'levels'],
            'BOT_LONG_LEVELS'   => ['bot', 'long_levels'],
            'BOT_SHORT_LEVELS'  => ['bot', 'short_levels'],
            'GRID_MIN_LEVELS'   => ['grid', 'min_levels'],
            'GRID_MAX_LEVELS'   => ['grid', 'max_levels'],
            'GRID_MIN_SPACING'  => ['grid', 'min_spacing'],
            'GRID_MAX_SPACING'  => ['grid', 'max_spacing'],
            'GRID_BASE_SPACING' => ['grid', 'base_spacing'],
            'GRID_SPACING_ATR_MULT' => ['grid', 'spacing_atr_mult'],
            'GRID_MIN_BUILD_INTERVAL_SEC' => ['grid', 'min_build_interval_sec'],
            'RISK_MARGIN_SAFETY' => ['risk', 'margin_safety'],
            'RISK_MAX_DAILY_LOSS' => ['risk', 'max_daily_loss'],
            'RISK_HARD_STOP_PCT' => ['risk', 'hard_stop_pct'],
            'RISK_RECOVERY_THR'  => ['risk', 'recovery_thr'],
            'RISK_RECOVERY_LOSS_PCT' => ['risk', 'recovery_loss_pct'],
            'FEES_MAKER'        => ['fees', 'maker'],
            'FEES_TAKER'        => ['fees', 'taker'],
            'COMPOUND_THRESHOLD' => ['compound', 'threshold'],
            'COMPOUND_MULTIPLIER' => ['compound', 'multiplier'],
            'COMPOUND_COOLDOWN_SEC' => ['compound', 'cooldown_sec'],
            'EXCHANGE_MIN_NOTIONAL' => ['exchange_rules', 'min_notional'],
            'ML_WEIGHTS_FILE'   => ['ml', 'weights_file'],
            'ML_MIN_ACCURACY'   => ['ml', 'min_accuracy'],
            'ML_BLEND_WEIGHT'   => ['ml', 'blend_weight'],
            'ML_RELOAD_CYCLES'  => ['ml', 'reload_cycles'],
            'VOLATILITY_RELOAD_CYCLES' => ['volatility', 'reload_cycles'],
            'LOG_PATH'          => ['paths', 'log'],
            'STATUS_PATH'       => ['paths', 'status'],
            'CONTROL_PATH'      => ['paths', 'ctrl'],
            'CONFIDENCE_PATH'   => ['paths', 'conf_hist'],
            'PID_PATH'          => ['paths', 'pid'],
        ];

        foreach ($envMap as $envKey => $configPath) {
            $value = getenv($envKey);
            if ($value !== false) {
                $this->set($configPath, $value);
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    public function set(array $path, mixed $value): void
    {
        $ref = &$this->config;
        foreach ($path as $k) {
            if (!is_array($ref)) {
                $ref = [];
            }
            $ref = &$ref[$k];
        }
        $ref = $value;
    }

    public function all(): array
    {
        return $this->config;
    }
}
