<?php
declare(strict_types=1);

namespace app\controller;

use app\service\PanelContext;
use NightPanel\Auth;
use NightPanel\Backends;
use think\Response;

class ApiController extends BaseController
{
    public function index(): Response
    {
        $user = PanelContext::requireUser();
        $token = Auth::userToken($user);
        $backend = Backends::find((int)($user['backend_id'] ?? 0));

        return $this->render('settings', [
            'tab' => 'api',
            'nsUrl' => $backend['url'] ?? '-',
            'backendName' => $backend['name'] ?? '-',
            'token' => $token,
            'tokenMask' => substr($token, 0, 6) . '-••••••••••••••••',
        ]);
    }
}
