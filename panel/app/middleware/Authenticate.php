<?php
declare(strict_types=1);

namespace app\middleware;

use app\service\PanelContext;
use Closure;
use NightPanel\Auth;
use think\Request;
use think\Response;

/** Every route in the authenticated group needs a session user. */
class Authenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if ($user === null) {
            return redirect('/login');
        }

        PanelContext::setUser($user);

        return $next($request);
    }
}
