<?php
declare(strict_types=1);

namespace app\controller;

use NightPanel\Lang;
use think\Response;

class DashboardController extends BaseController
{
    public function index(): Response
    {
        return $this->render('dashboard', [
            'dashI18n' => Lang::js([
                'dash.updatedAt',
                'dash.loadFailed',
                'dash.empty',
                'dash.reference',
                'dash.legend.glucose',
                'dash.legend.mbg',
                'dash.targetBand',
                'dash.chip.iob',
                'dash.chip.cob',
                'dash.chip.battery',
                'dash.live.connecting',
                'dash.live.live',
                'dash.live.retry',
                'dash.live.error',
                'dash.live.polling',
                'dash.live.stale',
                'status.low',
                'status.high',
                'status.veryHigh',
                'status.inRange',
                'alert.lowBanner',
                'alert.highBanner',
                'alert.lowTitle',
                'alert.highTitle',
                'alert.body',
                'col.edit',
                'col.time',
            ]),
            'csrfJson' => js_json(\NightPanel\Auth::csrfToken()),
        ]);
    }
}
