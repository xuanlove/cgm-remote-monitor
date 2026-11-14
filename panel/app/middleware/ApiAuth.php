<?php
declare(strict_types=1);

namespace app\middleware;

use app\service\PanelContext;
use Closure;
use NightPanel\Auth;
use NightPanel\Db;
use think\Request;
use think\Response;

/**
 * Authentication for the Nightscout-compatible read API.
 *
 * Two callers: the panel session (browser use), and the `api-secret` header
 * carrying the same Nightscout token the myapi page hands out (scripts, CGM
 * uploaders). Unauthorised calls answer the exact JSON shape the Nightscout
 * fork uses (lib/middleware/send-json-status.js), so a client cannot tell the
 * panel and the backend apart by the error body.
 */
class ApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = PanelContext::user() ?? Auth::user();
        if ($user !== null) {
            PanelContext::setUser($user);
            return $next($request);
        }

        // Header only: a ?token= query parameter would leak the token into
        // access logs, Referer headers and browser history.
        $provided = trim((string)$request->header('api-secret', ''));
        if ($provided === '') {
            return self::unauthorized();
        }

        // Self-hosted installs hold a handful of accounts, so decrypt-and-
        // compare per row is microseconds and needs no token-hash column.
        // hash_equals keeps every single comparison constant time; the loop
        // length only reveals how many accounts exist, never which one
        // matched.
        $stmt = Db::pdo()->query(
            'SELECT id, username, role, backend_id, ns_access_token_enc
               FROM panel_users WHERE ns_access_token_enc IS NOT NULL'
        );
        foreach ($stmt->fetchAll() as $row) {
            try {
                $stored = Db::decrypt((string)$row['ns_access_token_enc']);
            } catch (\RuntimeException $notThisRow) {
                continue;
            }
            if (hash_equals($stored, $provided)) {
                PanelContext::setUser($row);
                return $next($request);
            }
        }

        return self::unauthorized();
    }

    private static function unauthorized(): Response
    {
        return json(['status' => 401, 'message' => 'Unauthorized', 'description' => 'Invalid/Missing'], 401);
    }
}
