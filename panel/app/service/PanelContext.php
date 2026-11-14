<?php
declare(strict_types=1);

namespace app\service;

/**
 * Request-scoped state shared between middleware and controllers.
 *
 * Kept out of the ThinkPHP container because the CLI runners use the same
 * services without booting an application.
 */
final class PanelContext
{
    /** @var array<string,mixed>|null */
    private static ?array $user = null;

    /** @param array<string,mixed>|null $user */
    public static function setUser(?array $user): void
    {
        self::$user = $user;
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        return self::$user;
    }

    /**
     * The signed-in user, or an exception when the route forgot to authenticate.
     *
     * @return array<string,mixed>
     */
    public static function requireUser(): array
    {
        if (self::$user === null) {
            throw new \RuntimeException('No authenticated user in this request');
        }
        return self::$user;
    }

    /** @return array<string,mixed> */
    public static function requireAdmin(): array
    {
        $user = self::requireUser();
        if (($user['role'] ?? '') !== 'admin') {
            throw new \RuntimeException('Administrator role required');
        }
        return $user;
    }
}
