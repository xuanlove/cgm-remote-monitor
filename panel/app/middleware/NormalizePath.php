<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * Trailing-slash normalisation.
 *
 * ThinkPHP matches routes strictly, so `/dashboard/` would 404 where the old
 * front controller answered 200. A GET can be redirected permanently; a POST
 * must use 308 so the body and method survive the hop.
 */
class NormalizePath
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->pathinfo();

        if ($path !== '' && $path !== '/' && str_ends_with($path, '/')) {
            $target = '/' . rtrim($path, '/');
            $query = $request->get();
            if ($query !== []) {
                $target .= '?' . http_build_query($query);
            }
            return redirect($target, $request->isGet() ? 301 : 308);
        }

        return $next($request);
    }
}
