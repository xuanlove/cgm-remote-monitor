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
    private const SCHEMA_VERSION = 1;

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
            // PANEL_DB lets deployments (and tests) point at another file; the
            // default lives next to the code so a checkout runs out of the box.
            self::$pdo = new \PDO('sqlite:' . self::file());
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

        // Refuse to run against a database written by a newer release, and
        // stamp the current schema on a fresh (or upgraded) file.
        $version = (int)self::$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version > self::SCHEMA_VERSION) {
            throw new \RuntimeException(
                'Panel database schema ' . $version . ' is newer than this code (' . self::SCHEMA_VERSION . ')'
            );
        }
        if ($version < self::SCHEMA_VERSION) {
            self::$pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
        }
    }

    /** Encrypt a secret for storage; the key comes from the environment. */
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

        // Ciphertexts written before the move to app/library use a salt that
        // embedded the old file path; try both so an upgrade does not strand
        // existing tokens. New writes only ever use the first key.
        foreach (self::keys() as $key) {
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plain !== false) {
                return $plain;
            }
        }
        throw new \RuntimeException('Decryption failed');
    }

    /**
     * Candidate keys, newest first. The environment key wins outright; the
     * fallback derives one stable key plus the legacy path-based one.
     *
     * @return array<int,string>
     */
    private static function keys(): array
    {
        $env = getenv('PANEL_SECRET_KEY');
        if ($env !== false && $env !== '') {
            return [hash('sha256', $env, true)];
        }

        $seed = self::keySeed();
        return [
            // Stable across installations and refactors: no file path involved.
            hash('sha256', 'night-panel|db-v1|' . $seed, true),
            // Pre-refactor derivation (<panel>/lib/Db.php used __FILE__).
            hash('sha256', dirname(__DIR__, 2) . '/lib/Db.php' . '|night-panel|' . $seed, true),
        ];
    }

    /** The key used for new ciphertext. */
    private static function encryptionKey(): string
    {
        return self::keys()[0];
    }

    /** Read (or create) the on-disk key seed; migrates the old in-DB seed. */
    private static function keySeed(): string
    {
        $file = self::file() . '.key';
        if (is_file($file)) {
            $seed = trim((string)file_get_contents($file));
            if ($seed !== '') {
                return $seed;
            }
        }

        // An installation upgraded from an earlier release still has the seed
        // in the settings table. Adopt it so existing ciphertexts stay readable,
        // then remove the in-database copy.
        $legacy = self::setting('key_seed');
        $seed = ($legacy !== null && $legacy !== '') ? $legacy : bin2hex(random_bytes(32));

        if (@file_put_contents($file, $seed . "\n", LOCK_EX) !== false) {
            @chmod($file, 0600);
            if ($legacy !== null) {
                self::pdo()->prepare('DELETE FROM panel_settings WHERE key = ?')->execute(['key_seed']);
            }
        }
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

    /** Marker that separates encrypted values from pre-encryption plaintext. */
    private const SECRET_PREFIX = 'enc:v1:';

    /**
     * Read a secret setting (e.g. the Nightscout admin secret). Values written
     * before encryption was introduced are plaintext and are still returned, so
     * an existing installation keeps working until the next save.
     */
    public static function secret(string $key): ?string
    {
        $value = self::setting($key);
        if ($value === null || $value === '') {
            return null;
        }
        if (!str_starts_with($value, self::SECRET_PREFIX)) {
            return $value;
        }
        try {
            return self::decrypt(substr($value, strlen(self::SECRET_PREFIX)));
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                'Cannot decrypt ' . $key . '; PANEL_SECRET_KEY was probably changed. Re-enter the value.',
                0,
                $e
            );
        }
    }

    /** Encrypt a secret setting at rest. */
    public static function setSecret(string $key, string $value): void
    {
        self::setSetting($key, self::SECRET_PREFIX . self::encrypt($value));
    }

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
