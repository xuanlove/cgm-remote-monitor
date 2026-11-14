<?php
declare(strict_types=1);

namespace app\controller;

use app\service\PanelContext;
use NightPanel\Alerts;
use NightPanel\Lang;
use think\Request;
use think\Response;

class SettingsController extends BaseController
{
    public function alerts(): Response
    {
        $user = PanelContext::requireUser();

        return $this->render('alerts', [
            'settings' => Alerts::load((int)$user['id']),
            'permText' => $this->permText(),
            'error' => null,
            'success' => null,
        ]);
    }

    public function saveAlerts(Request $request): Response
    {
        $user = PanelContext::requireUser();
        [$settings, $error] = Alerts::save((int)$user['id'], $request->post());

        return $this->render('alerts', [
            'settings' => $settings,
            'permText' => $this->permText(),
            'error' => $error,
            'success' => $error === null ? t('msg.alertSaved') : null,
        ]);
    }

    /** Notification permission strings for the page script. */
    private function permText(): string
    {
        return Lang::js(['al.perm.unsupported', 'al.perm.granted', 'al.perm.denied', 'al.perm.default']);
    }
}
