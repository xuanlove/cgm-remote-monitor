<?php
declare(strict_types=1);

namespace app\controller;

use app\service\CsvService;
use app\service\PanelContext;
use app\service\ProfileService;
use app\service\ReadingService;
use NightPanel\GlucoseStats;
use NightPanel\Lang;
use think\Request;
use think\Response;

class ReportController extends BaseController
{
    public function index(Request $request): Response
    {
        $user = PanelContext::requireUser();
        $days = CsvService::requestedDays((int)$request->get('days', 14));

        [$startMs, $endMs] = ReadingService::daysWindow($days);
        $readings = ReadingService::stats($user, $startMs, $endMs);

        // The user's own profile target decides "目标范围"; the consensus
        // 3.9-10.0 band decides TIR/TAR/TBR.
        $own = ProfileService::forUser($user) ?? [];
        $targetLow = ProfileService::target($own, 'target_low', 3.9);
        $targetHigh = ProfileService::target($own, 'target_high', 10.0);

        $stats = GlucoseStats::compute($readings, $targetLow, $targetHigh);

        return $this->render('reports', [
            'days' => $days,
            'stats' => $stats,
            'statsJson' => js_json($stats),
            'reportsI18n' => Lang::js([
                'rep.share',
                'rep.percentage',
                'bucket.veryLow',
                'bucket.low',
                'bucket.inRange',
                'bucket.high',
                'bucket.veryHigh',
                'seg.below',
                'seg.inRange',
                'seg.above',
                'rep.lowEventsCount',
            ]),
            'from' => date('Y-m-d H:i', (int)($startMs / 1000)),
            'to' => date('Y-m-d H:i', (int)($endMs / 1000)),
            'error' => null,
        ]);
    }
}
