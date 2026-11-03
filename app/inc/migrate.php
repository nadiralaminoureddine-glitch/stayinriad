<?php
// StayInRiad — mises à jour automatiques de la base (sans réinstaller)
declare(strict_types=1);
if (!defined('SIR_APP')) exit;

const SCHEMA_VERSION = 2;

function run_migrations(): void {
    global $CONFIG;
    try { $v = (int)(q("SELECT v FROM settings WHERE k = 'schema_version'")->fetchColumn() ?: 1); }
    catch (Throwable $e) { return; } // base pas encore installée
    if ($v >= SCHEMA_VERSION) return;
    $my = ($CONFIG['db']['driver'] ?? 'mysql') !== 'sqlite';
    $id = $my ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $fk = $my ? 'INT UNSIGNED' : 'INTEGER';
    $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    if ($v < 2) {
        $cols = [
            "area VARCHAR(120) NOT NULL DEFAULT ''", "address VARCHAR(255) NOT NULL DEFAULT ''",
            "description TEXT NULL", "stars TINYINT NOT NULL DEFAULT 0", "amenities TEXT NULL",
            "breakfast TINYINT NOT NULL DEFAULT 0", "free_cancel TINYINT NOT NULL DEFAULT 1",
            "lat DECIMAL(9,6) NULL", "lng DECIMAL(9,6) NULL",
            "co_legal VARCHAR(160) NOT NULL DEFAULT ''", "co_form VARCHAR(30) NOT NULL DEFAULT ''",
            "co_ice VARCHAR(20) NOT NULL DEFAULT ''", "co_rc VARCHAR(60) NOT NULL DEFAULT ''",
            "co_if VARCHAR(30) NOT NULL DEFAULT ''", "co_license VARCHAR(80) NOT NULL DEFAULT ''",
            "co_address VARCHAR(255) NOT NULL DEFAULT ''", "co_rep VARCHAR(120) NOT NULL DEFAULT ''",
            "co_rep_role VARCHAR(60) NOT NULL DEFAULT ''",
            "bank_holder VARCHAR(160) NOT NULL DEFAULT ''", "bank_name VARCHAR(80) NOT NULL DEFAULT ''",
            "bank_rib VARCHAR(24) NOT NULL DEFAULT ''", "bank_swift VARCHAR(11) NOT NULL DEFAULT ''",
            "bank_doc VARCHAR(120) NOT NULL DEFAULT ''", "bank_status VARCHAR(20) NOT NULL DEFAULT 'non renseigné'",
            "bank_updated DATETIME NULL",
            "admin_note TEXT NULL", "submitted_at DATETIME NULL", "approved_at DATETIME NULL", "updated_at DATETIME NULL",
        ];
        foreach ($cols as $c) {
            try { db()->exec("ALTER TABLE riads ADD COLUMN $c"); } catch (PDOException $e) { /* déjà présente */ }
        }
        db()->exec("CREATE TABLE IF NOT EXISTS rooms (
            id $id, riad_id $fk NOT NULL, name VARCHAR(120) NOT NULL, description VARCHAR(255) NOT NULL DEFAULT '',
            capacity TINYINT NOT NULL DEFAULT 2, qty TINYINT NOT NULL DEFAULT 1, price_eur DECIMAL(8,2) NOT NULL DEFAULT 0,
            active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL,
            FOREIGN KEY (riad_id) REFERENCES riads(id)
        )$tail");
        db()->exec("CREATE TABLE IF NOT EXISTS photos (
            id $id, riad_id $fk NOT NULL, room_id $fk NULL, file VARCHAR(80) NOT NULL, public_file VARCHAR(80) NOT NULL DEFAULT '',
            hash VARCHAR(16) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'en attente', sort INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL, FOREIGN KEY (riad_id) REFERENCES riads(id)
        )$tail");
        db()->exec("CREATE TABLE IF NOT EXISTS photo_ok (pair VARCHAR(40) PRIMARY KEY, at DATETIME NOT NULL)$tail");
    }
    q("DELETE FROM settings WHERE k = 'schema_version'");
    q("INSERT INTO settings (k, v) VALUES ('schema_version', ?)", [(string)SCHEMA_VERSION]);
}
run_migrations();
