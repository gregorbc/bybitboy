<?php
declare(strict_types=1);

namespace BinanceBot\Core;

use PDO;

class Schema
{
    /** @return list<string> */
    public static function ddl(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                email VARCHAR(190) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                role ENUM('admin','investor') NOT NULL DEFAULT 'investor',
                status ENUM('active','suspended') NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_login_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(45) NOT NULL,
                action VARCHAR(20) NOT NULL,
                username VARCHAR(50) NOT NULL DEFAULT '',
                success TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ip_action (ip, action, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS wallets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                seed_encrypted TEXT NOT NULL,
                network VARCHAR(20) NOT NULL DEFAULT 'root',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS deposit_addresses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                network VARCHAR(20) NOT NULL,
                address VARCHAR(42) NOT NULL,
                derivation_index INT NOT NULL,
                status ENUM('active','disabled') NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_addr (network, address),
                UNIQUE KEY uq_user_net (user_id, network)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS deposits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                network VARCHAR(20) NOT NULL,
                token VARCHAR(10) NOT NULL,
                tx_hash VARCHAR(66) NOT NULL,
                block_number BIGINT NOT NULL DEFAULT 0,
                amount DECIMAL(20,8) NOT NULL DEFAULT 0,
                confirmations INT NOT NULL DEFAULT 0,
                deployed TINYINT(1) NOT NULL DEFAULT 0,
                status ENUM('pending','credited','failed') NOT NULL DEFAULT 'pending',
                detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                credited_at DATETIME NULL,
                UNIQUE KEY uq_tx (tx_hash),
                INDEX idx_status (status),
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS shares (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                units DECIMAL(20,8) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS movements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                type ENUM('deposit','withdrawal','adjust') NOT NULL,
                amount DECIMAL(20,8) NOT NULL DEFAULT 0,
                units DECIMAL(20,8) NOT NULL DEFAULT 0,
                nav DECIMAL(20,8) NOT NULL DEFAULT 0,
                balance_after DECIMAL(20,8) NOT NULL DEFAULT 0,
                note VARCHAR(255) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS withdrawals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                network VARCHAR(20) NOT NULL,
                token VARCHAR(10) NOT NULL,
                amount DECIMAL(20,8) NOT NULL DEFAULT 0,
                units_to_burn DECIMAL(20,8) NOT NULL DEFAULT 0,
                destination_address VARCHAR(42) NOT NULL,
                status ENUM('pending','approved','sent','rejected') NOT NULL DEFAULT 'pending',
                admin_note VARCHAR(255) NOT NULL DEFAULT '',
                tx_hash VARCHAR(66) NOT NULL DEFAULT '',
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                processed_at DATETIME NULL,
                INDEX idx_status (status),
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS nav_snapshots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                total_equity DECIMAL(20,8) NOT NULL DEFAULT 0,
                total_units DECIMAL(20,8) NOT NULL DEFAULT 0,
                nav DECIMAL(20,8) NOT NULL DEFAULT 0,
                bot_pnl_total DECIMAL(20,8) NOT NULL DEFAULT 0,
                snapshot_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS scan_state (
                id INT AUTO_INCREMENT PRIMARY KEY,
                network VARCHAR(20) NOT NULL UNIQUE,
                last_block BIGINT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS admin_sends (
                id INT AUTO_INCREMENT PRIMARY KEY,
                admin_id INT NOT NULL,
                network VARCHAR(20) NOT NULL,
                token VARCHAR(10) NOT NULL,
                amount DECIMAL(20,8) NOT NULL,
                destination_address VARCHAR(42) NOT NULL,
                tx_hash VARCHAR(66) DEFAULT '',
                status ENUM('pending','sent','failed') DEFAULT 'pending',
                error_message TEXT DEFAULT '',
                gas_used BIGINT DEFAULT 0,
                gas_price BIGINT DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                sent_at DATETIME NULL,
                INDEX idx_admin (admin_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS admin_audit (
                id INT AUTO_INCREMENT PRIMARY KEY,
                admin_id INT NOT NULL,
                username VARCHAR(50) NOT NULL DEFAULT '',
                action VARCHAR(50) NOT NULL,
                detail VARCHAR(500) NOT NULL DEFAULT '',
                ip VARCHAR(45) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_admin (admin_id, created_at),
                INDEX idx_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS logs_ia (
                id INT AUTO_INCREMENT PRIMARY KEY,
                fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                senal VARCHAR(20) NOT NULL,
                confianza DECIMAL(6,4) NOT NULL DEFAULT 0,
                razon VARCHAR(400) NOT NULL DEFAULT '',
                accion_tomada VARCHAR(50) NOT NULL DEFAULT '',
                precio DECIMAL(20,8) NOT NULL DEFAULT 0,
                INDEX idx_fecha (fecha)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS logs_acceso (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NULL,
                username VARCHAR(60) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                user_agent VARCHAR(255) NOT NULL DEFAULT '',
                resultado ENUM('exitoso','fallido') NOT NULL,
                fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_usuario (usuario_id),
                INDEX idx_fecha (fecha)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS alertas_config (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tipo VARCHAR(40) NOT NULL,
                umbral DECIMAL(12,4) NOT NULL,
                habilitado TINYINT(1) NOT NULL DEFAULT 1,
                telegram_chat_id VARCHAR(50) NOT NULL DEFAULT '',
                ultima_notificacion DATETIME NULL,
                intervalo_min INT NOT NULL DEFAULT 30,
                actualizado_por INT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tipo (tipo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }

    public static function createTables(PDO $pdo): void
    {
        foreach (self::ddl() as $sql) {
            $pdo->exec($sql);
        }
        try {
            $pdo->exec("ALTER TABLE movements ADD COLUMN note VARCHAR(255) NOT NULL DEFAULT ''");
        } catch (\Throwable $e) {
            // columna ya existe en BD existentes
        }
        foreach ([
            "ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) NULL",
            "ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE grid_configs ADD COLUMN max_daily_loss DECIMAL(5,2) NULL",
            "ALTER TABLE grid_configs ADD COLUMN recovery_loss_pct DECIMAL(5,2) NULL",
        ] as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\Throwable $e) {
                // columna ya existe en BD existentes
            }
        }
    }
}
