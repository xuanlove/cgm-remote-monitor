<?php
declare(strict_types=1);

namespace app;

use NightPanel\Lang;
use NightPanel\NsException;
use think\exception\Handle;
use think\exception\HttpException;
use think\facade\View;
use think\Response;
use Throwable;

/**
 * Renders failures with the panel's own pages instead of ThinkPHP's debug
 * screen, and keeps the audited status mapping: Nightscout trouble is a 502,
 * everything else a 500, unknown URLs the panel 404.
 */
class ExceptionHandle extends Handle
{
    public function render($request, Throwable $e): Response
    {
        // The Nightscout-compatible API must never answer with an HTML page:
        // an uploading client parses JSON, and a body it cannot read looks
        // like a protocol failure rather than a backend outage.
        $isApi = $request instanceof \think\Request
            && str_starts_with(ltrim($request->pathinfo(), '/'), 'api/v1/');

        if ($e instanceof NsException) {
            return $isApi
                ? json([
                    'status' => 502,
                    'message' => 'Nightscout unavailable',
                    'description' => $e->getMessage(),
                ], 502)
                : $this->page('error', t('err.nsUnavailable', ['msg' => $e->getMessage()]), 502);
        }

        if ($e instanceof HttpException) {
            $status = $e->getStatusCode();
            if ($status === 404) {
                return $this->page('404', '', 404);
            }
            if ($status === 405) {
                return $this->page('error', t('err.method'), 405);
            }
        }

        // A generic failure can carry anything: SQL fragments, file paths,
        // vendor internals. The framework's report() already logged the
        // detail; the page stays deliberately vague.
        return $isApi
            ? json(['status' => 500, 'message' => 'Internal Server Error', 'description' => 'Internal error'], 500)
            : $this->page('error', t('err.generic'), 500);
    }

    /** Template rendering must never mask the original failure. */
    private function page(string $template, string $message, int $status): Response
    {
        try {
            // The error may be raised before ViewGlobals ran (a broken session,
            // for instance), so give the layout something to render.
            View::assign([
                'user' => null,
                'isAdmin' => false,
                'csrf' => '',
                'htmlLang' => Lang::htmlLang(),
                'locales' => [],
                'backUrl' => '/dashboard',
                'layoutI18n' => Lang::js(['theme.toLight', 'theme.toDark']),
                'panelLocaleJson' => js_json(Lang::htmlLang()),
            ]);

            $html = View::fetch('/' . $template, ['message' => $message]);
        } catch (Throwable $inner) {
            $html = $message !== '' ? $message : 'Internal Server Error';
        }

        return response($html, $status);
    }
}
