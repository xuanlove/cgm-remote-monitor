<?php
declare(strict_types=1);

namespace app\middleware;

use app\service\PanelContext;
use Closure;
use NightPanel\Auth;
use think\Request;
use think\Response;

/** Administrator-only routes answer a bare 403, as before. */
class AdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = PanelContext::user() ?? Auth::user();
        if (($user['role'] ?? '') !== 'admin') {
            return response('Forbidden', 403);
        }

        return $next($request);
    }
}
