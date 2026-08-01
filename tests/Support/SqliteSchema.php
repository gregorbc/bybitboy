<?php
declare(strict_types=1);

namespace Tests\Support;

use PDO;

class SqliteSchema
{
    public static function apply(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE bot_meta (meta_key TEXT PRIMARY KEY, meta_value TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT "investor", status TEXT DEFAULT "active", created_at TEXT DEFAULT CURRENT_TIMESTAMP, last_login_at TEXT)');
        $pdo->exec('CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT, action TEXT, username TEXT, success INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE wallets (id INTEGER PRIMARY KEY AUTOINCREMENT, seed_encrypted TEXT, network TEXT DEFAULT "root", created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE deposit_addresses (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, network TEXT, address TEXT, derivation_index INTEGER, status TEXT DEFAULT "active", created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE (network, address), UNIQUE (user_id, network))');
        $pdo->exec('CREATE TABLE deposits (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, network TEXT, token TEXT, tx_hash TEXT UNIQUE, block_number INTEGER DEFAULT 0, amount REAL DEFAULT 0, confirmations INTEGER DEFAULT 0, deployed INTEGER DEFAULT 0, status TEXT DEFAULT "pending", detected_at TEXT DEFAULT CURRENT_TIMESTAMP, credited_at TEXT)');
        $pdo->exec('CREATE TABLE shares (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, units REAL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE movements (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type TEXT, amount REAL DEFAULT 0, units REAL DEFAULT 0, nav REAL DEFAULT 0, balance_after REAL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE withdrawals (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, network TEXT, token TEXT, amount REAL DEFAULT 0, units_to_burn REAL DEFAULT 0, destination_address TEXT, status TEXT DEFAULT "pending", admin_note TEXT DEFAULT "", tx_hash TEXT DEFAULT "", requested_at TEXT DEFAULT CURRENT_TIMESTAMP, processed_at TEXT)');
        $pdo->exec('CREATE TABLE nav_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, total_equity REAL DEFAULT 0, total_units REAL DEFAULT 0, nav REAL DEFAULT 0, bot_pnl_total REAL DEFAULT 0, snapshot_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE scan_state (id INTEGER PRIMARY KEY AUTOINCREMENT, network TEXT UNIQUE, last_block INTEGER DEFAULT 0, updated_at TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS grid_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id TEXT)');
    }
}
