<?php
declare(strict_types=1);

namespace app\service;

use NightPanel\Mirror;

/**
 * Reading windows used by the dashboard, the reports and the exports.
 */
final class ReadingService
{
    /**
     * @return array<int,array{ts:int,v:float,sgv:float,direction:?string,delta:?float,dateString:string}>
     */
    public static function readings(array $user, int $startMs, int $endMs): array
    {
        return Mirror::readings((int)$user['id'], $startMs, $endMs);
    }

    /** @return array<int,array{ts:int,v:float}> */
    public static function stats(array $user, int $startMs, int $endMs): array
    {
        return Mirror::statsReadings((int)$user['id'], $startMs, $endMs);
    }

    /** Today's rolling window for the dashboard poll. */
    public static function liveWindow(): array
    {
        $endMs = (int)round(microtime(true) * 1000);
        return [$endMs - 30 * 3600 * 1000, $endMs];
    }

    /** The last N days ending now. */
    public static function daysWindow(int $days): array
    {
        $endMs = (int)round(microtime(true) * 1000);
        return [$endMs - $days * 86400 * 1000, $endMs];
    }

    /**
     * Bounds of a historical day in the user's own timezone.
     *
     * A UTC host would otherwise shift the window by the offset, so a user in
     * Asia/Chongqing asking for 2026-11-14 would see the wrong day.
     *
     * @return array{0:int,1:int}
     */
    public static function dayWindow(string $date, string $username, int $backendId): array
    {
        $tz = new \DateTimeZone('UTC');
        try {
            $record = Mirror::profileRecord($backendId);
            $zone = (string)($record['store'][$username]['timezone'] ?? '');
            if ($zone !== '') {
                $tz = new \DateTimeZone($zone);
            }
        } catch (\Throwable $e) {
            // Unknown timezone in the profile: fall back to UTC.
        }

        $start = new \DateTimeImmutable($date . ' 00:00:00', $tz);
        return [$start->getTimestamp() * 1000, $start->modify('+1 day')->getTimestamp() * 1000];
    }
}
