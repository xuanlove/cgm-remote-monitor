<?php
declare(strict_types=1);

namespace app\middleware;

use app\service\PanelContext;
use Closure;
use NightPanel\Auth;
use NightPanel\Lang;
use think\facade\View;
use think\Request;
use think\Response;

/**
 * Variables every template needs: the signed-in user, the CSRF token and the
 * language metadata used by the layout switcher.
 */
class ViewGlobals
{
    public function handle(Request $request, Closure $next): Response
    {
        // One SELECT per request: route middleware (Authenticate, AdminOnly)
        // reads the context this fills instead of hitting the database again.
        $user = PanelContext::user() ?? Auth::user();
        PanelContext::setUser($user);

        $locales = [];
        foreach (Lang::available() as $code => $name) {
            $locales[] = [
                'code' => $code,
                'name' => $name,
                'active' => Lang::current() === $code,
            ];
        }

        View::assign([
            'user' => $user,
            'isAdmin' => (($user['role'] ?? '') === 'admin'),
            'csrf' => Auth::csrfToken(),
            'htmlLang' => Lang::htmlLang(),
            'locales' => $locales,
            'backUrl' => (string)($_SERVER['REQUEST_URI'] ?? '/dashboard'),
            'layoutI18n' => Lang::js(['theme.toLight', 'theme.toDark']),
            'panelLocaleJson' => js_json(Lang::htmlLang()),
        ]);

        return $next($request);
    }
}
