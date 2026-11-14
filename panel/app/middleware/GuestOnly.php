<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use NightPanel\Auth;
use think\Request;
use think\Response;

/** Signed-in users have no business on the login page. */
class GuestOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            return redirect('/dashboard');
        }
        return $next($request);
    }
}
