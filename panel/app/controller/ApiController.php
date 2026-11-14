<?php
declare(strict_types=1);

namespace app\controller;

use app\service\PanelContext;
use NightPanel\Auth;
use NightPanel\NsConfig;
use think\Response;

class ApiController extends BaseController
{
    public function index(): Response
    {
        $user = PanelContext::requireUser();
        $token = Auth::userToken($user);

        return $this->render('myapi', [
            'nsUrl' => NsConfig::baseUrl(),
            'token' => $token,
            'tokenMask' => substr($token, 0, 6) . '-••••••••••••••••',
        ]);
    }
}
