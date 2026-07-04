CREATE DATABASE IF NOT EXISTS `erika_bot`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `erika_bot`;

CREATE TABLE IF NOT EXISTS `grid_configs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `symbol` VARCHAR(20) NOT NULL,
  `direction` VARCHAR(20) DEFAULT 'NEUTRAL',
  `confidence` INT DEFAULT 50,
  `ai_reason` VARCHAR(400) DEFAULT '',
  `last_ai_check` DATETIME DEFAULT NULL,
  `capital_usd` DECIMAL(12,4) DEFAULT NULL,
  `leverage` INT DEFAULT 100,
  `levels` INT DEFAULT 10,
  `spacing_pct` DECIMAL(10,6) DEFAULT 0.000800,
  `long_levels` INT DEFAULT 5,
  `short_levels` INT DEFAULT 5,
  `qty_per_level` DECIMAL(20,8) DEFAULT 0,
  `pp` INT DEFAULT 2,
  `qp` INT DEFAULT 3,
  `mode` VARCHAR(20) DEFAULT 'NORMAL',
  `recovery_active` TINYINT(1) DEFAULT 0,
  `peak_pnl_today` DECIMAL(14,6) DEFAULT 0,
  `status` VARCHAR(10) DEFAULT 'ACTIVE',
  `paused_reason` VARCHAR(100) DEFAULT NULL,
  `ml_accuracy` DECIMAL(6,4) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_sym` (`symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `grid_orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `config_id` INT DEFAULT NULL,
  `symbol` VARCHAR(20) DEFAULT NULL,
  `direction` VARCHAR(20) DEFAULT NULL,
  `grid_level` INT DEFAULT NULL,
  `side` VARCHAR(5) DEFAULT NULL,
  `grid_role` VARCHAR(5) DEFAULT NULL,
  `order_id` VARCHAR(80) DEFAULT NULL,
  `price` DECIMAL(20,8) DEFAULT NULL,
  `exit_price` DECIMAL(20,8) DEFAULT NULL,
  `qty` DECIMAL(20,8) DEFAULT NULL,
  `status` VARCHAR(12) DEFAULT 'OPEN',
  `linked_order` INT DEFAULT NULL,
  `pnl_usd` DECIMAL(14,8) DEFAULT NULL,
  `is_recovery` TINYINT(1) DEFAULT 0,
  `filled_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_sym` (`symbol`),
  INDEX `idx_status` (`status`),
  INDEX `idx_oid` (`order_id`),
  INDEX `idx_cfg` (`config_id`),
  INDEX `idx_linked` (`linked_order`),
  INDEX `idx_filled` (`filled_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `grid_configs`
  (`symbol`, `direction`, `confidence`, `ai_reason`, `capital_usd`, `leverage`, `levels`, `long_levels`, `short_levels`, `spacing_pct`, `status`)
VALUES
  ('ETHUSDT', 'SIDEWAYS', 50, 'Configuracion inicial', 30, 100, 16, 8, 8, 0.000800, 'ACTIVE')
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

CREATE TABLE IF NOT EXISTS `position_snapshots` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `symbol` VARCHAR(20) NOT NULL,
  `side` VARCHAR(10) NOT NULL,
  `size` DECIMAL(20,8) NOT NULL,
  `entry_price` DECIMAL(20,8) NOT NULL,
  `mark_price` DECIMAL(20,8) NOT NULL,
  `unrealised_pnl` DECIMAL(14,8) NOT NULL,
  `liquidation_price` DECIMAL(20,8) DEFAULT 0,
  `leverage` INT DEFAULT 1,
  `position_im` DECIMAL(14,6) DEFAULT 0,
  `position_mm` DECIMAL(14,6) DEFAULT 0,
  `roe_pct` DECIMAL(10,4) DEFAULT 0,
  `dist_to_liq_pct` DECIMAL(10,4) DEFAULT 0,
  `mark_to_entry_pct` DECIMAL(10,4) DEFAULT 0,
  `adl_rank` TINYINT DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_sym_time` (`symbol`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
