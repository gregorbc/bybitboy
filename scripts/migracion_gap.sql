-- Gap real de paneles (2026-08-09) — fuente de verdad del esquema nuevo
-- Se aplica manualmente en producción y de forma idempotente via Core/Schema.php
-- Nota: los IF NOT EXISTS / ADD COLUMN ya-comprobados hacen el script re-ejecutable.

CREATE TABLE IF NOT EXISTS `logs_ia` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `fecha` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `senal` VARCHAR(20) NOT NULL,
  `confianza` DECIMAL(6,4) NOT NULL DEFAULT 0,
  `razon` VARCHAR(400) NOT NULL DEFAULT '',
  `accion_tomada` VARCHAR(50) NOT NULL DEFAULT '',
  `precio` DECIMAL(20,8) NOT NULL DEFAULT 0,
  KEY `idx_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `logs_acceso` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario_id` INT NULL,
  `username` VARCHAR(60) NOT NULL,
  `ip` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  `resultado` ENUM('exitoso','fallido') NOT NULL,
  `fecha` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `alertas_config` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tipo` VARCHAR(40) NOT NULL,
  `umbral` DECIMAL(12,4) NOT NULL,
  `habilitado` TINYINT(1) NOT NULL DEFAULT 1,
  `telegram_chat_id` VARCHAR(50) NOT NULL DEFAULT '',
  `ultima_notificacion` DATETIME NULL,
  `intervalo_min` INT NOT NULL DEFAULT 30,
  `actualizado_por` INT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_tipo` (`tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `totp_secret` VARCHAR(64) NULL AFTER `last_login_at`,
  ADD COLUMN `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `totp_secret`;

ALTER TABLE `grid_configs`
  ADD COLUMN `max_daily_loss` DECIMAL(5,2) NULL AFTER `fee_floor_mode`,
  ADD COLUMN `recovery_loss_pct` DECIMAL(5,2) NULL AFTER `max_daily_loss`;
