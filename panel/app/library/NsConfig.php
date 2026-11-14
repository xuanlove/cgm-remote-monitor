<?php
declare(strict_types=1);

namespace NightPanel;

/**
 * Nightscout connection settings.
 *
 * Stored in the panel database (that is what the admin page edits); the
 * environment is only an override, so a php-fpm pool or a systemd unit can
 * pin the connection without touching the database.
 */
final class NsConfig
{
    public const SOURCE_ENV = 'env';
    public const SOURCE_DB = 'db';
    public const SOURCE_NONE = 'none';

    public static function baseUrl(): string
    {
        $env = getenv('NIGHTSCOUT_URL');
        if ($env !== false && $env !== '') {
            return rtrim($env, '/');
        }
        return rtrim((string)(Db::setting('ns_url') ?? ''), '/');
    }

    public static function adminSecret(): string
    {
        $env = getenv('NIGHTSCOUT_ADMIN_SECRET');
        if ($env !== false && $env !== '') {
            return $env;
        }
        try {
            return (string)(Db::secret('ns_secret') ?? '');
        } catch (\RuntimeException $e) {
            // A key change makes the stored secret unreadable; treat it as
            // unset so the admin page can show "re-enter the secret" instead of
            // failing every request with a 500.
            return '';
        }
    }

    /**
     * Where the effective configuration comes from.
     *
     * The admin page shows this so an operator is never left guessing why a
     * freshly saved value appears to be ignored (env wins over the database).
     */
    public static function source(): array
    {
        $envUrl = getenv('NIGHTSCOUT_URL');
        $envSecret = getenv('NIGHTSCOUT_ADMIN_SECRET');

        return [
            'url' => ($envUrl !== false && $envUrl !== '') ? self::SOURCE_ENV
                : (Db::setting('ns_url') !== null && Db::setting('ns_url') !== '' ? self::SOURCE_DB : self::SOURCE_NONE),
            'secret' => ($envSecret !== false && $envSecret !== '') ? self::SOURCE_ENV
                : (Db::setting('ns_secret') !== null && Db::setting('ns_secret') !== '' ? self::SOURCE_DB : self::SOURCE_NONE),
        ];
    }

    /**
     * Persist the configuration edited on the admin page.
     *
     * Blank means "keep the current value": the form is an override form, so a
     * blank field must never wipe a working connection.
     *
     * @return string|null validation error, or null on success
     */
    public static function save(string $url, string $secret): ?string
    {
        $url = trim($url);
        if ($url !== '') {
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                return Lang::t('adm.errUrl');
            }
            Db::setSetting('ns_url', rtrim($url, '/'));
        }

        $secret = trim($secret);
        if ($secret !== '') {
            if (strlen($secret) > 255 || preg_match('/[\x00-\x1F\x7F]/', $secret) === 1) {
                return Lang::t('adm.errSecret');
            }
            Db::setSecret('ns_secret', $secret);
        }

        Db::setSetting('ns_config_updated_at', gmdate('c'));
        return null;
    }

    /**
     * A client for panel-originated calls (reads and admin writes).
     *
     * Deliberately not cached: the admin can change the connection and the
     * next request has to pick it up. Callers that need one per request hold it
     * locally.
     */
    public static function client(): NsClient
    {
        $url = self::baseUrl();
        $secret = self::adminSecret();
        if ($url === '' || $secret === '') {
            throw new NsException(Lang::t('err.nsNotConfigured'));
        }
        return new NsClient($url, $secret);
    }
}
