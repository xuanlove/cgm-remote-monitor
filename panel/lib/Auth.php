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
    public static function boot(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => (($_SERVER['HTTPS'] ?? '') !== ''),
            ]);
            session_name('nightpanel');
            session_start();
        }
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function assertCsrf(?string $token): void
    {
        if ($token === null || $token === '' || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
            http_response_code(403);
            echo 'Invalid CSRF token';
            exit;
        }
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
            header('Location: /login');
            exit;
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireUser();
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
        return $user;
    }

    /** Decrypt the stored Nightscout token for a user row. */
    public static function userToken(array $user): string
    {
        if (empty($user['ns_access_token_enc'])) {
            throw new \RuntimeException('This account has no Nightscout token bound.');
        }
        return Db::decrypt((string)$user['ns_access_token_enc']);
    }

    /** First-time bootstrap: create the admin account from env or defaults. */
    public static function bootstrapAdmin(): void
    {
        $pdo = Db::pdo();
        $exists = $pdo->query('SELECT COUNT(*) AS c FROM panel_users WHERE role = "admin"')->fetch();
        if ((int)$exists['c'] > 0) {
            return;
        }
        $username = getenv('PANEL_ADMIN_USER') ?: 'admin';
        $password = getenv('PANEL_ADMIN_PASS') ?: bin2hex(random_bytes(8));
        $stmt = $pdo->prepare('INSERT INTO panel_users (username, password_hash, role) VALUES (?, ?, "admin")');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
        error_log('[night-panel] bootstrap admin created: ' . $username . ' / ' . $password);
    }
}
