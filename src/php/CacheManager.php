<?php
/**
 * Gestor de Caché Redis para Grid Bot v15.5
 * Optimiza consultas repetitivas a la base de datos
 */

class CacheManager {
    private static $instance = null;
    private $redis = null;
    private $prefix = 'gridbot:';
    private $enabled = false;
    
    private function __construct() {
        $this->connect();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function connect() {
        try {
            $host = getenv('REDIS_HOST') ?: 'localhost';
            $port = (int)(getenv('REDIS_PORT') ?: 6379);
            $password = getenv('REDIS_PASSWORD') ?: null;
            
            $this->redis = new Redis();
            $this->redis->connect($host, $port, 2.5);
            
            if ($password) {
                $this->redis->auth($password);
            }
            
            if ($this->redis->ping() === '+PONG') {
                $this->enabled = true;
                error_log("[CACHE] Redis conectado en {$host}:{$port}");
            }
        } catch (Exception $e) {
            error_log("[CACHE] Error conectando a Redis: " . $e->getMessage());
            $this->enabled = false;
        }
    }
    
    public function isEnabled() {
        return $this->enabled;
    }
    
    public function get($key, $default = null) {
        if (!$this->enabled) return $default;
        
        $fullKey = $this->prefix . $key;
        $value = $this->redis->get($fullKey);
        
        if ($value === false) return $default;
        
        return json_decode($value, true) ?? $value;
    }
    
    public function set($key, $value, $ttl = 300) {
        if (!$this->enabled) return false;
        
        $fullKey = $this->prefix . $key;
        $serialized = is_array($value) || is_object($value) 
            ? json_encode($value, JSON_NUMERIC_CHECK) 
            : $value;
        
        return $this->redis->setex($fullKey, $ttl, $serialized);
    }
    
    public function delete($key) {
        if (!$this->enabled) return false;
        
        $fullKey = $this->prefix . $key;
        return $this->redis->del($fullKey) > 0;
    }
    
    public function deletePattern($pattern) {
        if (!$this->enabled) return false;
        
        $fullPattern = $this->prefix . $pattern;
        $keys = $this->redis->keys($fullPattern);
        
        if (!empty($keys)) {
            return $this->redis->del($keys) > 0;
        }
        
        return false;
    }
    
    public function increment($key, $amount = 1) {
        if (!$this->enabled) return false;
        
        $fullKey = $this->prefix . $key;
        return $this->redis->incrBy($fullKey, $amount);
    }
    
    public function getStats() {
        if (!$this->enabled) return ['enabled' => false];
        
        $info = $this->redis->info('stats');
        $dbSize = $this->redis->dbSize();
        $memInfo = $this->redis->info('memory');
        
        return [
            'enabled' => true,
            'connected' => true,
            'total_keys' => $dbSize,
            'hit_rate' => round($info['keyspace_hits'] / max(1, $info['keyspace_hits'] + $info['keyspace_misses']), 4),
            'memory_used' => $memInfo['used_memory_human'] ?? 'N/A',
            'uptime_days' => $memInfo['uptime_in_days'] ?? 0
        ];
    }
    
    public function flush() {
        if (!$this->enabled) return false;
        
        $keys = $this->redis->keys($this->prefix . '*');
        if (!empty($keys)) {
            return $this->redis->del($keys) > 0;
        }
        return false;
    }
}
?>
