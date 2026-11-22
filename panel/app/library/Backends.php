<?php
declare(strict_types=1);

namespace NightPanel;

/**
 * Repository for the Nightscout backends this panel manages.
 *
 * Every backend (friendly name, URL, admin secret) lives in the database;
 * the installer writes the first row, admins add more later. Nothing is
 * read from the environment any more.
 */
final class Backends
{
    /** Every backend row, oldest first. */
    public static function all(): array
    {
        return Db::pdo()
            ->query('SELECT id, name, url, secret_enc, enabled, created_at FROM panel_ns_backends ORDER BY id')
            ->fetchAll();
    }

    /** Enabled backends only — what registration offers. */
    public static function enabled(): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, name, url, secret_enc, enabled, created_at
               FROM panel_ns_backends WHERE enabled = 1 ORDER BY name COLLATE NOCASE'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, name, url, secret_enc, enabled, created_at FROM panel_ns_backends WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Panel users currently assigned to this backend. */
    public static function countUsers(int $id): int
    {
        $stmt = Db::pdo()->prepare('SELECT COUNT(*) FROM panel_users WHERE backend_id = ?');
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn();
    }

    /** Friendly display name; '—' style dash when the backend vanished. */
    public static function nameFor(int $id): string
    {
        $row = self::find($id);
        return $row === null ? '-' : (string)$row['name'];
    }

    /** The decrypted admin secret of a backend row. */
    public static function secret(array $row): string
    {
        $blob = (string)$row['secret_enc'];
        if (!str_starts_with($blob, 'enc:v1:')) {
            throw new \RuntimeException('Backend secret is not encrypted; reinstall the panel');
        }
        return Db::decrypt(substr($blob, strlen('enc:v1:')));
    }

    /** A client configured for one backend (row or id). */
    public static function clientFor(array|int|null $backend): NsClient
    {
        $row = is_array($backend) ? $backend : self::find((int)$backend);
        if ($row === null || $row === false) {
            throw new \RuntimeException('Unknown Nightscout backend');
        }
        return new NsClient(rtrim((string)$row['url'], '/'), self::secret($row));
    }

    public static function create(string $name, string $url, string $secret): int
    {
        $name = trim($name);
        $url = self::cleanUrl($url);
        if ($name === '') {
            throw new NsException(t('admin.backendNameRequired'), 400);
        }
        if ($secret === '') {
            throw new NsException(t('admin.backendSecretRequired'), 400);
        }
        try {
            Db::pdo()->prepare(
                'INSERT INTO panel_ns_backends (name, url, secret_enc, enabled) VALUES (?, ?, ?, 1)'
            )->execute([$name, $url, 'enc:v1:' . Db::encrypt($secret)]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                throw new NsException(t('admin.backendNameTaken'), 400);
            }
            throw $e;
        }
        return (int)Db::pdo()->lastInsertId();
    }

    /** Update name/url; a blank secret keeps the stored one. */
    public static function save(int $id, string $name, string $url, string $secret): void
    {
        $row = self::find($id);
        if ($row === null) {
            throw new NsException(t('admin.backendMissing'), 404);
        }
        $name = trim($name);
        $url = self::cleanUrl($url);
        if ($name === '') {
            throw new NsException(t('admin.backendNameRequired'), 400);
        }
        try {
            if ($secret !== '') {
                Db::pdo()->prepare(
                    'UPDATE panel_ns_backends SET name = ?, url = ?, secret_enc = ? WHERE id = ?'
                )->execute([$name, $url, 'enc:v1:' . Db::encrypt($secret), $id]);
            } else {
                Db::pdo()->prepare(
                    'UPDATE panel_ns_backends SET name = ?, url = ? WHERE id = ?'
                )->execute([$name, $url, $id]);
            }
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                throw new NsException(t('admin.backendNameTaken'), 400);
            }
            throw $e;
        }
    }

    public static function setEnabled(int $id, bool $enabled): void
    {
        Db::pdo()->prepare('UPDATE panel_ns_backends SET enabled = ? WHERE id = ?')
            ->execute([$enabled ? 1 : 0, $id]);
    }

    /** Backends still carrying users cannot be deleted. */
    public static function delete(int $id): void
    {
        if (self::find($id) === null) {
            throw new NsException(t('admin.backendMissing'), 404);
        }
        if (self::countUsers($id) > 0) {
            throw new NsException(t('admin.backendInUse'), 400);
        }
        Db::pdo()->prepare('DELETE FROM panel_ns_backends WHERE id = ?')->execute([$id]);
    }

    /** http/https URLs only, no trailing slash. */
    private static function cleanUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new NsException(t('admin.backendUrlInvalid'), 400);
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new NsException(t('admin.backendUrlInvalid'), 400);
        }
        return $url;
    }
}
