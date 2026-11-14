<?php
declare(strict_types=1);

namespace app\controller;

use app\service\CsvService;
use app\service\PanelContext;
use app\service\ReadingService;
use NightPanel\Mirror;
use think\Request;
use think\Response;

class ExportController extends BaseController
{
    public function entriesCsv(Request $request): Response
    {
        $user = PanelContext::requireUser();
        $days = CsvService::requestedDays((int)$request->get('days', 14));
        [$startMs, $endMs] = ReadingService::daysWindow($days);

        $rows = [];
        foreach (ReadingService::readings($user, $startMs, $endMs) as $reading) {
            $rows[] = [
                gmdate('Y-m-d H:i:s', (int)($reading['ts'] / 1000)),
                $reading['sgv'],
                $reading['v'],
                $reading['direction'] ?? '',
                $reading['delta'] ?? '',
            ];
        }

        return $this->attachment(
            CsvService::render(
                CsvService::header('csv.timeUtc', 'csv.glucoseMgdl', 'csv.glucoseMmol', 'csv.direction', 'csv.delta'),
                $rows
            ),
            'glucose-' . $user['username'] . '-' . $days . 'd.csv',
            'text/csv; charset=utf-8'
        );
    }

    public function entriesJson(Request $request): Response
    {
        $user = PanelContext::requireUser();
        $days = CsvService::requestedDays((int)$request->get('days', 14));
        [$startMs, $endMs] = ReadingService::daysWindow($days);
        $readings = ReadingService::readings($user, $startMs, $endMs);

        $body = json_encode([
            'user' => $user['username'],
            'from' => gmdate('c', (int)($startMs / 1000)),
            'to' => gmdate('c', (int)($endMs / 1000)),
            'count' => count($readings),
            'readings' => array_map(static fn(array $r): array => [
                'time' => gmdate('c', (int)($r['ts'] / 1000)),
                'mgdl' => $r['sgv'],
                'mmol' => $r['v'],
                'direction' => $r['direction'],
                'delta' => $r['delta'],
            ], $readings),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->attachment(
            (string)$body,
            'glucose-' . $user['username'] . '-' . $days . 'd.json',
            'application/json'
        );
    }

    public function treatmentsCsv(Request $request): Response
    {
        $user = PanelContext::requireUser();
        $days = CsvService::requestedDays((int)$request->get('days', 14));
        [$startMs, $endMs] = ReadingService::daysWindow($days);

        $treatments = Mirror::treatmentsBetween((int)$user['id'], $startMs, $endMs);
        usort(
            $treatments,
            static fn(array $a, array $b): int => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? ''))
        );

        $rows = [];
        foreach ($treatments as $t) {
            $rows[] = [
                (string)($t['created_at'] ?? ''),
                (string)($t['eventType'] ?? ''),
                $t['glucose'] ?? '',
                $t['carbs'] ?? '',
                $t['insulin'] ?? '',
                $t['duration'] ?? '',
                $t['absolute'] ?? '',
                $t['percent'] ?? '',
                (string)($t['notes'] ?? ''),
            ];
        }

        return $this->attachment(
            CsvService::render(
                CsvService::header(
                    'csv.time',
                    'csv.type',
                    'csv.glucoseMgdl',
                    'csv.carbs',
                    'csv.insulin',
                    'csv.duration',
                    'csv.basalRate',
                    'csv.percent',
                    'csv.notes'
                ),
                $rows
            ),
            'treatments-' . $user['username'] . '-' . $days . 'd.csv',
            'text/csv; charset=utf-8'
        );
    }
}
