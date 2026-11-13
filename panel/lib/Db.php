<?php
declare(strict_types=1);

namespace NightPanel;

/**
 * SQLite storage for the panel. One file, created on first use.
 */
final class Db
{
    private static ?\PDO $pdo = null;

    public static function pdo(): \PDO
    {
        if (self::$pdo === null) {
            $dir = __DIR__ . '/../data';
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }
            // PANEL_DB lets deployments (and tests) point at another file; the
            // default lives next to the code so a checkout runs out of the box.
            $env = getenv('PANEL_DB');
            $file = ($env !== false && $env !== '') ? $env : $dir . '/panel.db';
            self::$pdo = new \PDO('sqlite:' . $file);
            self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            // WAL keeps the web readers and the sync CLI from blocking each
            // other; busy_timeout absorbs the short write locks.
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
            self::$pdo->exec('PRAGMA synchronous = NORMAL');
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::migrate();
        }
        return self::$pdo;
    }

    private static function migrate(): void
    {
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS panel_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "user",
                ns_subject_id TEXT,
                ns_role_name TEXT,
                ns_access_token_enc TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS panel_settings (
                key TEXT PRIMARY KEY,
                value TEXT
            )'
        );
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS panel_alerts (
                user_id INTEGER PRIMARY KEY,
                low_mmol REAL NOT NULL,
                high_mmol REAL NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 0,
                browser INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES panel_users(id) ON DELETE CASCADE
            )'
        );
        // Local mirror filled by bin/sync.php. The web layer only reads these
        // tables, so a page load never waits on Nightscout.
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS panel_entries (
                user_id INTEGER NOT NULL,
                ns_id TEXT NOT NULL,
                type TEXT NOT NULL,
                device TEXT NOT NULL,
                ts INTEGER NOT NULL,
                sgv REAL,
                direction TEXT,
                delta REAL,
                raw TEXT NOT NULL,
                fetched_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, ns_id)
            )'
        );
        self::$pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_panel_entries_user_ts
                ON panel_entries (user_id, type, ts)'
        );
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS panel_treatments (
                user_id INTEGER NOT NULL,
                ns_id TEXT NOT NULL,
                created_at TEXT,
                event_type TEXT,
                payload TEXT NOT NULL,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, ns_id)
            )'
        );
        self::$pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_panel_treatments_user_created
                ON panel_treatments (user_id, created_at)'
        );
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS panel_devicestatus (
                user_id INTEGER PRIMARY KEY,
                ns_id TEXT,
                ts INTEGER,
                payload TEXT NOT NULL,
                fetched_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS panel_profiles (
                ns_id TEXT PRIMARY KEY,
                start_date TEXT,
                payload TEXT NOT NULL,
                fetched_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS panel_sync_state (
                user_id INTEGER NOT NULL,
                stream TEXT NOT NULL,
                cursor_ms INTEGER NOT NULL DEFAULT 0,
                last_ok_at TEXT,
                last_error TEXT,
                error_count INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, stream)
            )'
        );
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS panel_sync_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                stream TEXT NOT NULL,
                started_at TEXT NOT NULL,
                duration_ms INTEGER NOT NULL DEFAULT 0,
                inserted INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL,
                message TEXT
            )'
        );
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS panel_alert_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                ts INTEGER NOT NULL,
                kind TEXT NOT NULL,
                value_mmol REAL NOT NULL,
                limit_mmol REAL NOT NULL,
                delivered INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        self::$pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_panel_alert_events_user_ts
                ON panel_alert_events (user_id, delivered, ts)'
        );
    }

    /** Encrypt a secret for storage; the key comes from the environment. */
    public static function encrypt(string $plaintext): string
    {
        $key = self::secretKey();
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $blob): string
    {
        $key = self::secretKey();
        $raw = base64_decode($blob);
        $iv = substr($raw, 0, 16);
        $tag = substr($raw, 16, 16);
        $cipher = substr($raw, 32);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed');
        }
        return $plain;
    }

    private static function secretKey(): string
    {
        $env = getenv('PANEL_SECRET_KEY');
        if ($env === false || $env === '') {
            // Stable per-installation fallback so the panel works out of the box;
            // production deployments should set PANEL_SECRET_KEY.
            $seed = __FILE__ . '|night-panel|' . (self::setting('key_seed') ?? '');
            if (self::setting('key_seed') === null) {
                $seedValue = bin2hex(random_bytes(32));
                self::setSetting('key_seed', $seedValue);
                $seed = __FILE__ . '|night-panel|' . $seedValue;
            }
            return hash('sha256', $seed, true);
        }
        return hash('sha256', $env, true);
    }

    public static function setting(string $key): ?string
    {
        $stmt = self::pdo()->prepare('SELECT value FROM panel_settings WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row === false ? null : (string)$row['value'];
    }

    public static function setSetting(string $key, string $value): void
    {
        $stmt = self::pdo()->prepare(
            'INSERT INTO panel_settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([$key, $value]);
    }
}
