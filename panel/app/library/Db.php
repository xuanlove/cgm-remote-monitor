<?php
declare(strict_types=1);

namespace NightPanel;

/**
 * SQLite storage for the panel. One file, created on first use.
 */
final class Db
{
    private static ?\PDO $pdo = null;

    /** Bump when a migration step is added; stored in `PRAGMA user_version`. */
    private const SCHEMA_VERSION = 2;

    /** The resolved database file, so callers can place locks next to it. */
    public static function file(): string
    {
        return panel_db_file();
    }

    public static function pdo(): \PDO
    {
        if (self::$pdo === null) {
            $dir = dirname(self::file());
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }
            // The database file holds the key seed, so keep it private to the
            // web/CLI user even on a default umask.
            $existed = is_file(self::file());
            self::$pdo = new \PDO('sqlite:' . self::file());
            self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            // WAL keeps the web readers and the sync CLI from blocking each
            // other; busy_timeout absorbs the short write locks.
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
            self::$pdo->exec('PRAGMA synchronous = NORMAL');
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            try {
                self::migrate();
            } catch (\Throwable $e) {
                // A half-opened handle (e.g. a legacy database was refused)
                // must not be reused by the next caller, skipping the check.
                self::$pdo = null;
                throw $e;
            }
            if (!$existed) {
                @chmod(self::file(), 0600);
                @chmod(self::file() . '-wal', 0600);
                @chmod(self::file() . '-shm', 0600);
            }
        }
        return self::$pdo;
    }

    private static function migrate(): void
    {
        $version = (int)self::$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version > self::SCHEMA_VERSION) {
            throw new \RuntimeException(
                'Panel database schema ' . $version . ' is newer than this code (' . self::SCHEMA_VERSION . ')'
            );
        }
        if ($version === self::SCHEMA_VERSION) {
            return;
        }
        // This release installs fresh only: there is no v1 -> v2 upgrade path.
        if ($version >= 1) {
            throw new \RuntimeException(
                'Legacy panel database found (schema v' . $version . '). '
                . 'Back up and remove data/panel.db, then open install.php to reinstall.'
            );
        }

        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS panel_ns_backends (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE COLLATE NOCASE,
                url TEXT NOT NULL,
                secret_enc TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS panel_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "user",
                backend_id INTEGER,
                ns_subject_id TEXT,
                ns_role_name TEXT,
                ns_access_token_enc TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (backend_id) REFERENCES panel_ns_backends(id)
            )'
        );
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS panel_settings (
                key TEXT PRIMARY KEY,
                value TEXT
            )'
        );
        // Fixed-window counters for login/registration throttling.
        self::$pdo->exec(
            'CREATE TABLE IF NOT EXISTS panel_rate_limits (
                bucket TEXT NOT NULL,
                identity TEXT NOT NULL,
                window_start INTEGER NOT NULL,
                hits INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (bucket, identity, window_start)
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
                backend_id INTEGER NOT NULL,
                ns_id TEXT NOT NULL,
                start_date TEXT,
                payload TEXT NOT NULL,
                fetched_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (backend_id, ns_id)
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

        self::$pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    /** Encrypt a secret for storage; the master seed lives in panel_settings. */
    public static function encrypt(string $plaintext): string
    {
        $key = self::encryptionKey();
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $blob): string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 33) {
            throw new \RuntimeException('Decryption failed');
        }
        $iv = substr($raw, 0, 16);
        $tag = substr($raw, 16, 16);
        $cipher = substr($raw, 32);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed');
        }
        return $plain;
    }

    /** The single key, derived from the in-database seed. */
    private static function keys(): array
    {
        return [self::encryptionKey()];
    }

    /** The key used for new ciphertext. */
    private static function encryptionKey(): string
    {
        return hash('sha256', 'night-panel|db-v2|' . self::keySeed(), true);
    }

    /**
     * Resolve the key seed from panel_settings. The installer pre-writes it;
     * a missing row (database created outside the installer) is generated
     * and stored on first use so the panel is self-healing.
     */
    private static function keySeed(): string
    {
        $seed = self::setting('key_seed');
        if ($seed !== null && $seed !== '') {
            return $seed;
        }

        $seed = bin2hex(random_bytes(32));
        self::setSetting('key_seed', $seed);
        return $seed;
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

    /** Marker that separates encrypted values from plaintext. */
    public const SECRET_PREFIX = 'enc:v1:';

    /**
     * Count one attempt in a fixed window; false once the allowance is used up.
     * `identity` is expected to be a hash, not a raw IP address.
     */
    public static function rateLimit(string $bucket, string $identity, int $limit, int $windowSec): bool
    {
        $now = time();
        $window = intdiv($now, $windowSec) * $windowSec;
        $pdo = self::pdo();

        // Count and read inside one write transaction: two concurrent attempts
        // for the same identity would otherwise both read the pre-increment
        // value and slip past the limit together.
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            // Expired windows are never read again.
            $pdo->prepare('DELETE FROM panel_rate_limits WHERE window_start < ?')->execute([$now - 86400]);
            $pdo->prepare(
                'INSERT INTO panel_rate_limits (bucket, identity, window_start, hits) VALUES (?, ?, ?, 1)
                 ON CONFLICT(bucket, identity, window_start) DO UPDATE SET hits = hits + 1'
            )->execute([$bucket, $identity, $window]);

            $stmt = $pdo->prepare(
                'SELECT hits FROM panel_rate_limits WHERE bucket = ? AND identity = ? AND window_start = ?'
            );
            $stmt->execute([$bucket, $identity, $window]);
            $hits = (int)$stmt->fetchColumn();
            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }

        return $hits <= $limit;
    }

    /** Key for rate limiting: the client address, hashed so it is not stored raw. */
    public static function clientKey(): string
    {
        return hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    }
}
