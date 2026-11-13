<?php
declare(strict_types=1);

namespace NightPanel;

/**
 * Nightscout connection settings. The environment wins (php-fpm pools and the
 * sync CLI provide it), the database is the fallback edited on the admin page.
 */
final class Config
{
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
        return (string)(Db::secret('ns_secret') ?? '');
    }

    /** Shared client for panel-originated calls (reads and admin writes). */
    public static function client(): NsClient
    {
        static $client = null;
        if ($client === null) {
            $url = self::baseUrl();
            $secret = self::adminSecret();
            if ($url === '' || $secret === '') {
                throw new NsException(Lang::t('err.nsNotConfigured'));
            }
            $client = new NsClient($url, $secret);
        }
        return $client;
    }
}
