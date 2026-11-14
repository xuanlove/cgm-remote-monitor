<?php
declare(strict_types=1);

namespace app\controller;

use NightPanel\Lang;
use think\Request;
use think\Response;

class LangController extends BaseController
{
    /**
     * Switch the interface language and return to the page the switcher was
     * used on. Only a local absolute path is accepted: `//evil.example` is a
     * protocol-relative URL and would leave the panel.
     */
    public function switchLang(Request $request): Response
    {
        Lang::set((string)$request->post('lang', ''));

        $back = (string)$request->post('back', '');
        $target = str_starts_with($back, '/')
            && !str_starts_with($back, '//')
            && preg_match('#^/[a-z0-9/?=_.&-]*$#i', $back) === 1
            ? $back
            : '/dashboard';

        return redirect($target);
    }
}
