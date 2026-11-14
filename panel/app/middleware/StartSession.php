<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use NightPanel\Auth;
use NightPanel\Lang;
use think\Request;
use think\Response;

/**
 * Starts the panel's own PHP session.
 *
 * ThinkPHP's SessionInit middleware is deliberately not used: the cookie name,
 * HttpOnly/SameSite flags and the proxy-aware Secure flag have to match the
 * audited behaviour exactly.
 */
class StartSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Same cookie flags as install.php's session, including the
            // explicit path: a host php.ini with a non-root cookie_path
            // would otherwise drop the session for every other page.
            session_set_cookie_params([
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => Auth::isHttps(),
                'path' => '/',
            ]);
            session_name('nightpanel');
            session_start();
        }

        Lang::init();

        return $next($request);
    }
}
