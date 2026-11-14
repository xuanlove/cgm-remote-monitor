<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use NightPanel\Db;
use think\Request;
use think\Response;

/**
 * Fixed-window throttling for login and registration.
 *
 * The middleware only records the verdict; the controller renders the page
 * with the "too many attempts" message (status 200), which is what the panel
 * has always done. Returning 429 here would change the response contract.
 */
class Throttle
{
    public function handle(Request $request, Closure $next, string $bucket = 'login', int $limit = 20, int $window = 900): Response
    {
        if (!Db::rateLimit($bucket, Db::clientKey(), $limit, $window)) {
            $request->withMiddleware(['throttled' => $bucket]);
        }

        return $next($request);
    }
}
