<?php
declare(strict_types=1);

namespace NightPanel;

/**
 * Session, CSRF and current-user handling. The session binds a user to
 * exactly one Nightscout subject; nothing downstream accepts a username or
 * project from the request.
 */
final class Auth
{
    /**
     * Whether the request reached us over HTTPS. nginx terminating TLS reports
     * plain HTTP to php-fpm, so X-Forwarded-Proto is honoured, but only from a
     * loopback/private peer (or when the installer-enabled trust_proxy setting
     * is on) to keep a direct client from forcing Secure cookies on a
     * plain-HTTP install.
     */
    public static function isHttps(): bool
    {
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) !== 'https') {
            return false;
        }
        if (Db::setting('trust_proxy') === '1') {
            return true;
        }
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    /** Whether a submitted token matches the session token. */
    public static function csrfValid(?string $token): bool
    {
        return $token !== null
            && $token !== ''
            && isset($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $token);
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = (string)$user['username'];
        $_SESSION['role'] = (string)$user['role'];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function isAdmin(): bool
    {
        return ($_SESSION['role'] ?? '') === 'admin';
    }

    /** The logged-in user row, or null. */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        $stmt = Db::pdo()->prepare('SELECT * FROM panel_users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        return $user === false ? null : $user;
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if ($user === null) {
            throw new \RuntimeException('Not authenticated');
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireUser();
        if ($user['role'] !== 'admin') {
            throw new \RuntimeException('Not an administrator');
        }
        return $user;
    }

    /** Decrypt the stored Nightscout token for a user row. */
    public static function userToken(array $user): string
    {
        if (empty($user['ns_access_token_enc'])) {
            throw new \RuntimeException(Lang::t('err.noToken'));
        }
        return Db::decrypt((string)$user['ns_access_token_enc']);
    }
}
