<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use NightPanel\Auth;
use think\Request;
use think\Response;

/**
 * CSRF guard for state-changing requests.
 *
 * Accepts the form field `csrf` or the `X-CSRF-Token` header (the dashboard's
 * alert acknowledgement posts JSON, so the header is its only channel).
 * `json` mode answers with a JSON body instead of plain text.
 *
 * Safe methods (GET/HEAD) pass through: logout is the one `any`-method route
 * carrying this guard, and its GET probe must keep answering 405 with
 * Allow: POST instead of a 403.
 */
class CsrfGuard
{
    public function handle(Request $request, Closure $next, string $mode = 'form'): Response
    {
        if ($request->isGet() || $request->isHead()) {
            return $next($request);
        }

        $token = (string)($request->post('csrf') ?? '');
        if ($token === '') {
            $token = (string)$request->header('X-CSRF-Token', '');
        }

        if (!Auth::csrfValid($token)) {
            return $mode === 'json'
                ? json(['error' => 'invalid csrf token'], 403)
                : response('Invalid CSRF token', 403);
        }

        return $next($request);
    }
}
