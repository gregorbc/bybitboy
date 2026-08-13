<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;
use PDOException;

class Database
{
    private static ?self $instance = null;
    private ?PDO $pdo = null;

    private function __construct()
    {
        $this->connect();
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
        self::$instance = null;
    }

    private function connect(): void
    {
        $config = Config::getInstance();
        $host = $config->get('mysql.host', 'localhost');
        $dbname = $config->get('mysql.dbname', '');
        $user = $config->get('mysql.user', '');
        $pass = $config->get('mysql.password', '');

        if (empty($host) || empty($dbname)) {
            Logger::warn('MySQL config missing, DB unavailable');
            return;
        }

        $hosts = array_unique([$host, '127.0.0.1', 'localhost']);

        foreach ($hosts as $h) {
            try {
                $this->pdo = new PDO(
                    "mysql:host={$h};dbname={$dbname};charset=utf8mb4",
                    $user, $pass,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => 3,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]
                );
                $this->pdo->exec("SET time_zone = '+00:00'");
                $this->pdo->query('SELECT 1');
                return;
            } catch (PDOException $e) {
                Logger::warn("DB connect failed on {$h}: " . $e->getMessage());
            }
        }

        Logger::error('Could not connect to any MySQL host');
    }

    public function getPdo(): ?PDO
    {
        return $this->pdo;
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function query(string $sql, array $params = []): array
    {
        if (!$this->pdo) return [];

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            Logger::error("Query failed: " . $e->getMessage());
            return [];
        }
    }

    public function execute(string $sql, array $params = []): bool
    {
        if (!$this->pdo) return false;

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            Logger::error("Execute failed: " . $e->getMessage());
            return false;
        }
    }

    public function init(): void
    {
        if (!$this->pdo) return;

        $this->execute("CREATE TABLE IF NOT EXISTS bot_meta (
            meta_key VARCHAR(50) PRIMARY KEY,
            meta_value VARCHAR(100) DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->execute("CREATE TABLE IF NOT EXISTS grid_configs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            symbol VARCHAR(20) NOT NULL UNIQUE,
            status VARCHAR(10) DEFAULT 'ACTIVE',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->execute("CREATE TABLE IF NOT EXISTS grid_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            config_id INT,
            symbol VARCHAR(20),
            order_id VARCHAR(80),
            status VARCHAR(12) DEFAULT 'OPEN',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_oid (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->execute("INSERT INTO bot_meta (meta_key, meta_value)
            VALUES ('db_inited', '1')
            ON DUPLICATE KEY UPDATE meta_value='1'");
    }
}
