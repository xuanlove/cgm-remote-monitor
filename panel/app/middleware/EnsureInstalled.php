<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use NightPanel\Db;
use think\Request;
use think\Response;

/**
 * Until install.php has run, every routed page redirects to the installer.
 * The check is cheap: the lock file appears during install and never again.
 */
class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!is_file(panel_lock_file())) {
            return redirect('/install.php');
        }

        // The lock exists but the database went away (wiped while locked):
        // send the admin back to the installer rather than crashing on PDO.
        if (!is_file(Db::file())) {
            return redirect('/install.php');
        }

        return $next($request);
    }
}
