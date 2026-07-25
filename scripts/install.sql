-- Grid Bot v15.4 - Script de Instalación de Base de Datos
-- Compatible con MySQL 5.7+ y MariaDB 10.2+

-- Tabla de posiciones del grid
CREATE TABLE IF NOT EXISTS `grid_positions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `symbol` VARCHAR(20) NOT NULL,
    `side` ENUM('Buy', 'Sell') NOT NULL,
    `size` DECIMAL(20, 8) NOT NULL,
    `entry_price` DECIMAL(20, 8) NOT NULL,
    `mark_price` DECIMAL(20, 8) DEFAULT 0,
    `leverage` INT DEFAULT 1,
    `unrealized_pnl` DECIMAL(20, 8) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_symbol` (`symbol`),
    INDEX `idx_side` (`side`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de órdenes del grid
CREATE TABLE IF NOT EXISTS `grid_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` VARCHAR(50) UNIQUE NOT NULL,
    `symbol` VARCHAR(20) NOT NULL,
    `side` ENUM('Buy', 'Sell') NOT NULL,
    `order_type` VARCHAR(20) NOT NULL,
    `price` DECIMAL(20, 8) NOT NULL,
    `qty` DECIMAL(20, 8) NOT NULL,
    `status` VARCHAR(20) DEFAULT 'New',
    `grid_level` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_order_id` (`order_id`),
    INDEX `idx_symbol` (`symbol`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de fills/ejecuciones
CREATE TABLE IF NOT EXISTS `grid_fills` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `fill_id` VARCHAR(50) UNIQUE NOT NULL,
    `order_id` VARCHAR(50) NOT NULL,
    `symbol` VARCHAR(20) NOT NULL,
    `side` ENUM('Buy', 'Sell') NOT NULL,
    `price` DECIMAL(20, 8) NOT NULL,
    `qty` DECIMAL(20, 8) NOT NULL,
    `fee` DECIMAL(20, 8) DEFAULT 0,
    `fee_asset` VARCHAR(10) DEFAULT 'USDT',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_fill_id` (`fill_id`),
    INDEX `idx_order_id` (`order_id`),
    INDEX `idx_symbol` (`symbol`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de estadísticas del bot
CREATE TABLE IF NOT EXISTS `grid_stats` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `symbol` VARCHAR(20) NOT NULL,
    `total_trades` INT DEFAULT 0,
    `winning_trades` INT DEFAULT 0,
    `losing_trades` INT DEFAULT 0,
    `total_pnl` DECIMAL(20, 8) DEFAULT 0,
    `total_volume` DECIMAL(20, 8) DEFAULT 0,
    `total_fees` DECIMAL(20, 8) DEFAULT 0,
    `grid_levels` INT DEFAULT 0,
    `last_trade_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_symbol` (`symbol`),
    INDEX `idx_symbol` (`symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de logs del sistema
CREATE TABLE IF NOT EXISTS `grid_logs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `level` VARCHAR(20) DEFAULT 'INFO',
    `message` TEXT NOT NULL,
    `context` JSON DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_level` (`level`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de configuración del grid
CREATE TABLE IF NOT EXISTS `grid_config` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `symbol` VARCHAR(20) NOT NULL UNIQUE,
    `min_price` DECIMAL(20, 8) NOT NULL,
    `max_price` DECIMAL(20, 8) NOT NULL,
    `grid_levels` INT NOT NULL,
    `investment_amount` DECIMAL(20, 8) NOT NULL,
    `leverage` INT DEFAULT 1,
    `is_active` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_symbol` (`symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar registro inicial de estadísticas (se actualizará automáticamente)
INSERT IGNORE INTO `grid_stats` (`symbol`, `total_trades`, `winning_trades`, `losing_trades`) 
VALUES ('BTCUSDT', 0, 0, 0);

-- Vista para resumen de trading
CREATE OR REPLACE VIEW `grid_trading_summary` AS
SELECT 
    gs.symbol,
    gs.total_trades,
    gs.winning_trades,
    gs.losing_trades,
    ROUND(gs.total_pnl, 2) as total_pnl,
    ROUND(gs.total_volume, 2) as total_volume,
    ROUND(gs.total_fees, 2) as total_fees,
    gs.grid_levels,
    gp.side as current_position_side,
    gp.size as current_position_size,
    gp.unrealized_pnl,
    gs.last_trade_at
FROM grid_stats gs
LEFT JOIN grid_positions gp ON gs.symbol = gp.symbol
WHERE gs.symbol IS NOT NULL;
