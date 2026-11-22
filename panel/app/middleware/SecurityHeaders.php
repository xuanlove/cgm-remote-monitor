<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * The response headers the security audit required, applied to every response
 * (including error pages, which is why they are written before $next runs).
 */
class SecurityHeaders
{
    private const CSP = "default-src 'self'; script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; "
        . "font-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; "
        . "frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: same-origin');
            header('Content-Security-Policy: ' . self::CSP);
        }

        return $next($request);
    }
}
