<?php
declare(strict_types=1);

namespace app\controller;

use NightPanel\Auth;
use think\Response;

class IndexController extends BaseController
{
    /** Root sends visitors to the dashboard or the login page. */
    public function index(): Response
    {
        return redirect(Auth::check() ? '/dashboard' : '/login');
    }
}
