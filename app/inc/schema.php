<?php
// StayInRiad — structure de la base (MySQL en production, SQLite pour les tests)
declare(strict_types=1);
if (!defined('SIR_APP')) exit;

function schema_sql(string $driver): array {
    $my = $driver !== 'sqlite';
    $id   = $my ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $fk   = $my ? 'INT UNSIGNED' : 'INTEGER';
    $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    return [
        "CREATE TABLE IF NOT EXISTS users (
            id $id,
            role VARCHAR(20) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            first_name VARCHAR(80) NOT NULL,
            last_name VARCHAR(80) NOT NULL DEFAULT '',
            phone VARCHAR(40) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            last_login DATETIME NULL
        )$tail",
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id $id,
            email VARCHAR(190) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            ok TINYINT NOT NULL DEFAULT 0,
            at DATETIME NOT NULL
        )$tail",
        "CREATE INDEX " . ($my ? '' : 'IF NOT EXISTS ') . "idx_attempts ON login_attempts (email, ip, at)",
        "CREATE TABLE IF NOT EXISTS riads (
            id $id,
            owner_id $fk NOT NULL,
            name VARCHAR(120) NOT NULL,
            city VARCHAR(60) NOT NULL,
            rooms_count INT NOT NULL DEFAULT 0,
            commission DECIMAL(5,2) NOT NULL DEFAULT 15.00,
            status VARCHAR(20) NOT NULL DEFAULT 'brouillon',
            created_at DATETIME NOT NULL,
            FOREIGN KEY (owner_id) REFERENCES users(id)
        )$tail",
        "CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(60) PRIMARY KEY,
            v TEXT NOT NULL
        )$tail",
    ];
}
