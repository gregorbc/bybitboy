<?php
/**
 * ConfigLoader.php
 * Carga configuración desde variables de entorno con fallback a config.json
 * Mejora de seguridad: Elimina credenciales hardcodeadas
 */

class ConfigLoader {
    private static $instance = null;
    private $config = [];
    private $envLoaded = false;
    
    private function __construct() {
        $this->loadEnv();
        $this->loadConfig();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Carga variables de entorno desde archivo .env si existe
     */
    private function loadEnv() {
        $envFile = dirname(__DIR__) . '/.env';
        
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            
            foreach ($lines as $line) {
                // Ignorar comentarios
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }
                
                // Parsear KEY=VALUE
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    
                    // Remover comillas si existen
                    if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                        (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                        $value = substr($value, 1, -1);
                    }
                    
                    putenv("$key=$value");
                    $_ENV[$key] = $value;
                }
            }
            
            $this->envLoaded = true;
        }
    }
    
    /**
     * Carga configuración combinando variables de entorno y config.json
     * Las variables de entorno tienen prioridad
     */
    private function loadConfig() {
        $configFile = dirname(__DIR__) . '/config.json';
        
        if (file_exists($configFile)) {
            $this->config = json_decode(file_get_contents($configFile), true);
        }
        
        // Sobrescribir con variables de entorno (más seguras)
        $this->mergeEnvConfig();
    }
    
    /**
     * Fusiona variables de entorno sobre la configuración JSON
     */
    private function mergeEnvConfig() {
        // Exchange credentials
        if ($apiKey = getenv('BYBIT_API_KEY')) {
            $this->config['bybit']['api_key'] = $apiKey;
        }
        if ($apiSecret = getenv('BYBIT_API_SECRET')) {
            $this->config['bybit']['api_secret'] = $apiSecret;
        }
        if ($testnet = getenv('BYBIT_TESTNET')) {
            $this->config['bybit']['testnet'] = filter_var($testnet, FILTER_VALIDATE_BOOLEAN);
        }
        
        // MySQL credentials
        if ($host = getenv('MYSQL_HOST')) {
            $this->config['mysql']['host'] = $host;
        }
        if ($dbname = getenv('MYSQL_DBNAME')) {
            $this->config['mysql']['dbname'] = $dbname;
        }
        if ($user = getenv('MYSQL_USER')) {
            $this->config['mysql']['user'] = $user;
        }
        if ($password = getenv('MYSQL_PASSWORD')) {
            $this->config['mysql']['password'] = $password;
        }
        
        // Security tokens
        if ($securityToken = getenv('SECURITY_TOKEN')) {
            $this->config['security_token'] = $securityToken;
        }
        if ($wsToken = getenv('WS_TOKEN')) {
            $this->config['ws_token'] = $wsToken;
        }
        
        // NVIDIA API
        if ($nvidiaKey = getenv('NVIDIA_API_KEY')) {
            $this->config['nvidia']['api_key'] = $nvidiaKey;
        }
        if ($nvidiaEnabled = getenv('NVIDIA_ENABLED')) {
            $this->config['nvidia']['enabled'] = filter_var($nvidiaEnabled, FILTER_VALIDATE_BOOLEAN);
        }
        
        // Paths
        if ($logPath = getenv('LOG_PATH')) {
            $this->config['paths']['log'] = $logPath;
        }
        if ($webDir = getenv('WEB_DIR')) {
            $this->config['paths']['web_dir'] = $webDir;
        }
        if ($configDir = getenv('CONFIG_DIR')) {
            $this->config['paths']['config_dir'] = $configDir;
        }
        if ($statusPath = getenv('STATUS_PATH')) {
            $this->config['paths']['status'] = $statusPath;
        }
        if ($pidPath = getenv('PID_PATH')) {
            $this->config['paths']['pid'] = $pidPath;
        }
        if ($confPath = getenv('CONFIDENCE_PATH')) {
            $this->config['paths']['conf_hist'] = $confPath;
        }
        if ($ctrlPath = getenv('CONTROL_PATH')) {
            $this->config['paths']['ctrl'] = $ctrlPath;
        }
        
        // Bot configuration
        if ($symbol = getenv('BOT_SYMBOL')) {
            $this->config['bot']['symbol'] = $symbol;
        }
        if ($capital = getenv('BOT_CAPITAL_USD')) {
            $this->config['bot']['capital_usd'] = floatval($capital);
        }
        if ($leverage = getenv('BOT_LEVERAGE')) {
            $this->config['bot']['leverage'] = intval($leverage);
        }
        if ($timeframe = getenv('BOT_TIMEFRAME')) {
            $this->config['bot']['timeframe'] = $timeframe;
        }
        if ($candles = getenv('BOT_CANDLES_FEED')) {
            $this->config['bot']['candles_feed'] = intval($candles);
        }
        if ($cycleSec = getenv('BOT_CYCLE_SEC')) {
            $this->config['bot']['cycle_sec'] = intval($cycleSec);
        }
        if ($aiInterval = getenv('BOT_AI_INTERVAL_SEC')) {
            $this->config['bot']['ai_interval_sec'] = intval($aiInterval);
        }
        if ($levels = getenv('BOT_LEVELS')) {
            $this->config['bot']['levels'] = intval($levels);
        }
        if ($longLevels = getenv('BOT_LONG_LEVELS')) {
            $this->config['bot']['long_levels'] = intval($longLevels);
        }
        if ($shortLevels = getenv('BOT_SHORT_LEVELS')) {
            $this->config['bot']['short_levels'] = intval($shortLevels);
        }
        
        // Grid configuration
        if ($minLevels = getenv('GRID_MIN_LEVELS')) {
            $this->config['grid']['min_levels'] = intval($minLevels);
        }
        if ($maxLevels = getenv('GRID_MAX_LEVELS')) {
            $this->config['grid']['max_levels'] = intval($maxLevels);
        }
        if ($minSpacing = getenv('GRID_MIN_SPACING')) {
            $this->config['grid']['min_spacing'] = floatval($minSpacing);
        }
        if ($maxSpacing = getenv('GRID_MAX_SPACING')) {
            $this->config['grid']['max_spacing'] = floatval($maxSpacing);
        }
        if ($baseSpacing = getenv('GRID_BASE_SPACING')) {
            $this->config['grid']['base_spacing'] = floatval($baseSpacing);
        }
        if ($spacingAtr = getenv('GRID_SPACING_ATR_MULT')) {
            $this->config['grid']['spacing_atr_mult'] = floatval($spacingAtr);
        }
        if ($minBuildInterval = getenv('GRID_MIN_BUILD_INTERVAL_SEC')) {
            $this->config['grid']['min_build_interval_sec'] = intval($minBuildInterval);
        }
        
        // Risk management
        if ($marginSafety = getenv('RISK_MARGIN_SAFETY')) {
            $this->config['risk']['margin_safety'] = floatval($marginSafety);
        }
        if ($maxDailyLoss = getenv('RISK_MAX_DAILY_LOSS')) {
            $this->config['risk']['max_daily_loss'] = floatval($maxDailyLoss);
        }
        if ($hardStop = getenv('RISK_HARD_STOP_PCT')) {
            $this->config['risk']['hard_stop_pct'] = floatval($hardStop);
        }
        if ($recoveryThr = getenv('RISK_RECOVERY_THR')) {
            $this->config['risk']['recovery_thr'] = floatval($recoveryThr);
        }
        if ($recoveryLoss = getenv('RISK_RECOVERY_LOSS_PCT')) {
            $this->config['risk']['recovery_loss_pct'] = floatval($recoveryLoss);
        }
        
        // Fees
        if ($maker = getenv('FEES_MAKER')) {
            $this->config['fees']['maker'] = floatval($maker);
        }
        if ($taker = getenv('FEES_TAKER')) {
            $this->config['fees']['taker'] = floatval($taker);
        }
        
        // Compounding
        if ($threshold = getenv('COMPOUND_THRESHOLD')) {
            $this->config['compound']['threshold'] = floatval($threshold);
        }
        if ($multiplier = getenv('COMPOUND_MULTIPLIER')) {
            $this->config['compound']['multiplier'] = floatval($multiplier);
        }
        if ($cooldown = getenv('COMPOUND_COOLDOWN_SEC')) {
            $this->config['compound']['cooldown_sec'] = intval($cooldown);
        }
        
        // Exchange rules
        if ($minNotional = getenv('EXCHANGE_MIN_NOTIONAL')) {
            $this->config['exchange_rules']['min_notional'] = floatval($minNotional);
        }
        
        // ML configuration
        if ($mlWeights = getenv('ML_WEIGHTS_FILE')) {
            $this->config['ml']['weights_file'] = $mlWeights;
        }
        if ($minConfidence = getenv('ML_MIN_CONFIDENCE')) {
            $this->config['ml']['min_confidence'] = intval($minConfidence);
        }
        if ($minAccuracy = getenv('ML_MIN_ACCURACY')) {
            $this->config['ml']['min_accuracy'] = floatval($minAccuracy);
        }
        if ($blendWeight = getenv('ML_BLEND_WEIGHT')) {
            $this->config['ml']['blend_weight'] = floatval($blendWeight);
        }
        if ($reloadCycles = getenv('ML_RELOAD_CYCLES')) {
            $this->config['ml']['reload_cycles'] = intval($reloadCycles);
        }
        
        // Volatility
        if ($volReload = getenv('VOLATILITY_RELOAD_CYCLES')) {
            $this->config['volatility']['reload_cycles'] = intval($volReload);
        }
    }
    
    /**
     * Obtiene un valor de configuración por clave
     * @param string $key Clave en formato dot notation (ej: 'bybit.api_key')
     * @param mixed $default Valor por defecto si no existe
     * @return mixed
     */
    public function get($key, $default = null) {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (isset($value[$k])) {
                $value = $value[$k];
            } else {
                return $default;
            }
        }
        
        return $value;
    }
    
    /**
     * Obtiene toda la configuración
     * @return array
     */
    public function getAll() {
        return $this->config;
    }
    
    /**
     * Verifica si las variables de entorno fueron cargadas
     * @return bool
     */
    public function isEnvLoaded() {
        return $this->envLoaded;
    }
    
    /**
     * Valida que las credenciales críticas estén configuradas
     * @return array Lista de errores
     */
    public function validate() {
        $errors = [];
        
        if (empty($this->get('bybit.api_key'))) {
            $errors[] = 'BYBIT_API_KEY no configurada';
        }
        if (empty($this->get('bybit.api_secret'))) {
            $errors[] = 'BYBIT_API_SECRET no configurada';
        }
        if (empty($this->get('mysql.password'))) {
            $errors[] = 'MYSQL_PASSWORD no configurada';
        }
        if (empty($this->get('security_token'))) {
            $errors[] = 'SECURITY_TOKEN no configurado - generar con openssl_random_pseudo_bytes';
        }
        if (empty($this->get('ws_token'))) {
            $errors[] = 'WS_TOKEN no configurado - generar con openssl_random_pseudo_bytes';
        }
        
        return $errors;
    }
}
