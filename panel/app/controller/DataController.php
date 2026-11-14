<?php
declare(strict_types=1);

namespace app\controller;

use app\service\PanelContext;
use app\service\ProfileService;
use app\service\ReadingService;
use NightPanel\Alerts;
use NightPanel\Mirror;
use NightPanel\NsException;
use think\Request;
use think\Response;

class DataController extends BaseController
{
    /**
     * Dashboard payload, read from the local mirror so a poll never waits on
     * Nightscout (and keeps working while it is unreachable).
     */
    public function glucose(Request $request): Response
    {
        $user = PanelContext::requireUser();
        $userId = (int)$user['id'];

        // ?date=YYYY-MM-DD rewinds to a whole historical day; without it the
        // window is "today" (the client narrows it to its hour span).
        $date = (string)$request->get('date', '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            [$startMs, $endMs] = ReadingService::dayWindow($date, (string)$user['username']);
        } else {
            [$startMs, $endMs] = ReadingService::liveWindow();
        }

        $freshness = Mirror::freshness($userId);
        if ($freshness['age_sec'] !== null) {
            header('X-Data-Age: ' . $freshness['age_sec']);
        }

        try {
            $profileRecord = ProfileService::record($user);
        } catch (NsException $e) {
            // Mirror-only mode: a cold start with Nightscout down still renders
            // whatever has been synced so far.
            $profileRecord = null;
        }

        return $this->jsonOut([
            'entries' => Mirror::rawEntries($userId, 'sgv', $startMs, $endMs, 2000),
            'mbg' => Mirror::rawEntries($userId, 'mbg', $startMs, $endMs, 200),
            'devicestatus' => Mirror::latestDeviceStatus($userId),
            'profile' => $profileRecord['store'][$user['username']] ?? null,
            'alerts' => Alerts::load($userId),
            'sync' => $freshness,
            'alert_events' => Mirror::undeliveredAlerts(
                $userId,
                (int)round(microtime(true) * 1000) - 15 * 60 * 1000
            ),
        ]);
    }

    public function treatments(): Response
    {
        $user = PanelContext::requireUser();
        return $this->jsonOut(['treatments' => Mirror::recentTreatments((int)$user['id'], 100)]);
    }

    /** Acknowledge alert events the dashboard has shown. */
    public function ackAlerts(Request $request): Response
    {
        $user = PanelContext::requireUser();
        $payload = json_decode((string)$request->getContent(), true);
        $ids = is_array($payload) && isset($payload['ids']) && is_array($payload['ids']) ? $payload['ids'] : [];

        return $this->jsonOut(['acked' => Mirror::ackAlerts((int)$user['id'], $ids)]);
    }
}
