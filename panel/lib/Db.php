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
            self::$pdo = new \PDO('sqlite:' . $dir . '/panel.db');
            self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            self::$pdo->exec('PRAGMA journal_mode = WAL');
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
