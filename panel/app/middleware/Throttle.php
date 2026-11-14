<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use NightPanel\Db;
use think\Request;
use think\Response;

/**
 * Fixed-window throttling for login, registration and the compat API.
 *
 * `page` mode (login/register) only records the verdict; the controller
 * renders the page with the "too many attempts" message (status 200), which
 * is what the panel has always done. `json` mode answers 429 itself in the
 * Nightscout error shape, because the compat API has no controller to fall
 * back to.
 */
class Throttle
{
    public function handle(Request $request, Closure $next, string $bucket = 'login', int $limit = 20, int $window = 900, string $mode = 'page'): Response
    {
        if (!Db::rateLimit($bucket, Db::clientKey(), $limit, $window)) {
            if ($mode === 'json') {
                return json(['status' => 429, 'message' => 'Too many requests', 'description' => 'Rate limit exceeded'], 429);
            }
            $request->withMiddleware(['throttled' => $bucket]);
        }

        return $next($request);
    }
}
