<?php
// StayInRiad — mises à jour automatiques de la base (sans réinstaller)
declare(strict_types=1);
if (!defined('SIR_APP')) exit;

const SCHEMA_VERSION = 10;

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
    if ($v < 3) {
        db()->exec("CREATE TABLE IF NOT EXISTS bookings (
            id $id, ref VARCHAR(12) NOT NULL UNIQUE, token VARCHAR(40) NOT NULL,
            riad_id $fk NOT NULL, room_id $fk NOT NULL, user_id $fk NULL,
            first_name VARCHAR(80) NOT NULL, last_name VARCHAR(80) NOT NULL, email VARCHAR(190) NOT NULL,
            phone VARCHAR(40) NOT NULL DEFAULT '', country VARCHAR(60) NOT NULL DEFAULT '', request VARCHAR(500) NOT NULL DEFAULT '',
            check_in DATE NOT NULL, check_out DATE NOT NULL, nights INT NOT NULL, guests INT NOT NULL,
            room_name VARCHAR(120) NOT NULL, price_night DECIMAL(8,2) NOT NULL, subtotal DECIMAL(10,2) NOT NULL,
            commission_rate DECIMAL(5,2) NOT NULL, commission DECIMAL(10,2) NOT NULL, net DECIMAL(10,2) NOT NULL,
            total DECIMAL(10,2) NOT NULL, eur_mad DECIMAL(8,4) NOT NULL, net_mad DECIMAL(12,2) NOT NULL,
            status VARCHAR(20) NOT NULL, hold_until DATETIME NULL, paid_at DATETIME NULL, payment_ref VARCHAR(80) NOT NULL DEFAULT '',
            source VARCHAR(30) NOT NULL DEFAULT 'StayInRiad', created_at DATETIME NOT NULL,
            FOREIGN KEY (riad_id) REFERENCES riads(id), FOREIGN KEY (room_id) REFERENCES rooms(id)
        )$tail");
        try { db()->exec("CREATE INDEX idx_book_room ON bookings (room_id, check_in, check_out)"); } catch (PDOException $e) {}
        db()->exec("CREATE TABLE IF NOT EXISTS closed_days (room_id $fk NOT NULL, day DATE NOT NULL, PRIMARY KEY (room_id, day))$tail");
    }
    if ($v < 4) {
        foreach (["cancelled_at DATETIME NULL", "refund DECIMAL(10,2) NOT NULL DEFAULT 0", "penalty DECIMAL(10,2) NOT NULL DEFAULT 0",
                  "refund_status VARCHAR(20) NOT NULL DEFAULT ''", "payout_id $fk NULL", "police_reminded TINYINT NOT NULL DEFAULT 0", "police_filled_at DATETIME NULL"] as $c) {
            try { db()->exec("ALTER TABLE bookings ADD COLUMN $c"); } catch (PDOException $e) {}
        }
        db()->exec("CREATE TABLE IF NOT EXISTS police_forms (id $id, booking_id $fk NOT NULL, guest_no INT NOT NULL, data TEXT NOT NULL, created_at DATETIME NOT NULL, FOREIGN KEY (booking_id) REFERENCES bookings(id))$tail");
        db()->exec("CREATE TABLE IF NOT EXISTS payouts (id $id, riad_id $fk NOT NULL, amount_eur DECIMAL(12,2) NOT NULL, amount_mad DECIMAL(12,2) NOT NULL, refs TEXT NOT NULL, bank_rib VARCHAR(24) NOT NULL, bank_ref VARCHAR(80) NOT NULL DEFAULT '', paid_at DATETIME NOT NULL, FOREIGN KEY (riad_id) REFERENCES riads(id))$tail");
        db()->exec("CREATE TABLE IF NOT EXISTS email_log (id $id, to_addr VARCHAR(190) NOT NULL, subject VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL)$tail");
    }
    if ($v < 5) {
        foreach (["email_verified_at DATETIME NULL", "pw_changed_at DATETIME NULL"] as $c) { try { db()->exec("ALTER TABLE users ADD COLUMN $c"); } catch (PDOException $e) {} }
        q("UPDATE users SET email_verified_at = created_at WHERE email_verified_at IS NULL AND status = 'active'"); // comptes existants : déjà validés
        db()->exec("CREATE TABLE IF NOT EXISTS auth_codes (id $id, user_id $fk NOT NULL, purpose VARCHAR(20) NOT NULL, code_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, attempts INT NOT NULL DEFAULT 0, used TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, FOREIGN KEY (user_id) REFERENCES users(id))$tail");
        db()->exec("CREATE TABLE IF NOT EXISTS admin_log (id $id, user_id $fk NULL, action VARCHAR(60) NOT NULL, details VARCHAR(500) NOT NULL DEFAULT '', ip VARCHAR(45) NOT NULL DEFAULT '', created_at DATETIME NOT NULL)$tail");
    }
    if ($v < 6) {
        foreach (["ical_token VARCHAR(40) NOT NULL DEFAULT ''", "seen_booking DATETIME NULL", "seen_airbnb DATETIME NULL", "seen_other DATETIME NULL"] as $c) {
            try { db()->exec("ALTER TABLE rooms ADD COLUMN $c"); } catch (PDOException $e) {}
        }
        foreach (["channels VARCHAR(40) NULL", "sync_booking_invite DATETIME NULL", "sync_airbnb_call DATETIME NULL", "sync_done_note VARCHAR(255) NOT NULL DEFAULT ''"] as $c) {
            try { db()->exec("ALTER TABLE riads ADD COLUMN $c"); } catch (PDOException $e) {}
        }
        db()->exec("CREATE TABLE IF NOT EXISTS ical_feeds (id $id, room_id $fk NOT NULL, source VARCHAR(20) NOT NULL, url VARCHAR(600) NOT NULL,
            last_sync DATETIME NULL, last_ok DATETIME NULL, last_error VARCHAR(255) NOT NULL DEFAULT '', events INT NOT NULL DEFAULT 0,
            fail_since DATETIME NULL, alerted TINYINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, FOREIGN KEY (room_id) REFERENCES rooms(id))$tail");
        db()->exec("CREATE TABLE IF NOT EXISTS ext_blocks (id $id, feed_id $fk NOT NULL, room_id $fk NOT NULL, start_day DATE NOT NULL, end_day DATE NOT NULL)$tail");
        try { db()->exec("CREATE INDEX idx_ext_room ON ext_blocks (room_id, start_day, end_day)"); } catch (PDOException $e) {}
        try { db()->exec("CREATE INDEX idx_ext_feed ON ext_blocks (feed_id)"); } catch (PDOException $e) {}
    }
    if ($v < 7) {
        try { db()->exec("ALTER TABLE bookings ADD COLUMN review_sent DATETIME NULL"); } catch (PDOException $e) {}
        db()->exec("CREATE TABLE IF NOT EXISTS reviews (id $id, booking_id $fk NOT NULL UNIQUE, riad_id $fk NOT NULL,
            staff TINYINT NOT NULL, clean TINYINT NOT NULL, comfort TINYINT NOT NULL, location TINYINT NOT NULL, value TINYINT NOT NULL,
            breakfast TINYINT NULL, score DECIMAL(3,1) NOT NULL, recommend TINYINT NOT NULL DEFAULT 1,
            liked VARCHAR(1000) NOT NULL DEFAULT '', disliked VARCHAR(1000) NOT NULL DEFAULT '', private_note VARCHAR(1000) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL, FOREIGN KEY (booking_id) REFERENCES bookings(id), FOREIGN KEY (riad_id) REFERENCES riads(id))$tail");
        try { db()->exec("CREATE INDEX idx_reviews_riad ON reviews (riad_id, created_at)"); } catch (PDOException $e) {}
    }
    if ($v < 8) {
        foreach (["booking_url VARCHAR(400) NOT NULL DEFAULT ''", "import_requested DATETIME NULL", "import_done DATETIME NULL"] as $c) {
            try { db()->exec("ALTER TABLE riads ADD COLUMN $c"); } catch (PDOException $e) {}
        }
    }
    if ($v < 9) {
        try { db()->exec("ALTER TABLE users ADD COLUMN lang VARCHAR(5) NOT NULL DEFAULT 'fr'"); } catch (PDOException $e) {}
    }
    if ($v < 10) {
        db()->exec("CREATE TABLE IF NOT EXISTS visits (id $id, at DATETIME NOT NULL, day DATE NOT NULL, vid CHAR(16) NOT NULL,
            ev VARCHAR(10) NOT NULL, path VARCHAR(190) NOT NULL, src VARCHAR(30) NOT NULL DEFAULT '', country VARCHAR(2) NOT NULL DEFAULT '',
            dev VARCHAR(12) NOT NULL DEFAULT '', lang VARCHAR(5) NOT NULL DEFAULT '', extra VARCHAR(120) NOT NULL DEFAULT '')$tail");
        try { db()->exec("CREATE INDEX idx_visits_day ON visits (day, vid)"); } catch (PDOException $e) {}
    }
    q("DELETE FROM settings WHERE k = 'schema_version'");
    q("INSERT INTO settings (k, v) VALUES ('schema_version', ?)", [(string)SCHEMA_VERSION]);
}
run_migrations();
