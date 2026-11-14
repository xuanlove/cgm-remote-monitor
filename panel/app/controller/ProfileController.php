<?php
declare(strict_types=1);

namespace app\controller;

use app\service\PanelContext;
use app\service\ProfileService;
use NightPanel\NsException;
use think\Request;
use think\Response;

class ProfileController extends BaseController
{
    public function show(): Response
    {
        $user = PanelContext::requireUser();

        return $this->render('profile', [
            'profile' => ProfileService::forUser($user),
            'error' => null,
            'success' => null,
        ]);
    }

    public function save(Request $request): Response
    {
        $user = PanelContext::requireUser();

        try {
            $own = ProfileService::saveOwn($user, $request->post());
            return $this->render('profile', ['profile' => $own, 'error' => null, 'success' => true]);
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
        } catch (NsException $e) {
            $message = t('msg.saveFailed', ['msg' => $e->getMessage()]);
        }

        return $this->render('profile', [
            'profile' => ProfileService::forUser($user),
            'error' => $message,
            'success' => null,
        ]);
    }
}
