<?php
declare(strict_types=1);

namespace NightPanel;

/**
 * Day-level cache for glucose readings.
 *
 * A 90-day report otherwise issues one Nightscout request per day on every
 * page load. Finished days are cached for a short while so repeat loads only
 * touch the un-cached edge (today). The TTL keeps uploads that backfill a past
 * day visible: the cache is a short-lived convenience, not the source of truth.
 *
 * The cache is keyed by user id, so one user's readings can never be served to
 * another even if the device names collide.
 */
final class ReportCache
{
    /** How long a cached day stays usable. */
    private const TTL_SECONDS = 600;

    private const DAY_MS = 86400000;

    /**
     * Readings for a device and range, using the cache where possible. Each
     * reading carries ts, v (mmol/L), sgv (mg/dL), direction, delta and
     * dateString, so both the reports and the exports can use it as is.
     *
     * @return array<int,array{ts:int,v:float,sgv:float,direction:?string,delta:?float,dateString:string}>
     */
    public static function readings(NsClient $client, int $userId, string $device, int $startMs, int $endMs): array
    {
        $out = [];
        $todayKey = gmdate('Y-m-d');
        $cursor = $startMs;

        while ($cursor < $endMs) {
            $dayKey = gmdate('Y-m-d', (int)($cursor / 1000));
            $dayStart = (int)strtotime($dayKey . ' 00:00:00 UTC') * 1000;
            $dayEnd = $dayStart + self::DAY_MS;
            $from = max($cursor, $dayStart);
            $to = min($endMs, $dayEnd);

            $wholeDay = ($from === $dayStart && $to === $dayEnd);
            $cacheable = $wholeDay && $dayKey !== $todayKey;

            $batch = $cacheable ? self::load($userId, $dayKey) : null;

            if ($batch === null) {
                $batch = [];
                foreach ($client->entriesInRange($device, 'sgv', $from, $to, 400) as $entry) {
                    $ts = isset($entry['date']) ? (int)$entry['date'] : (int)strtotime((string)($entry['dateString'] ?? ''));
                    if ($ts <= 0 || !isset($entry['sgv'])) {
                        continue;
                    }
                    $batch[] = [
                        'ts' => $ts,
                        'v' => round(((float)$entry['sgv']) / 18, 2),
                        'sgv' => (float)$entry['sgv'],
                        'direction' => $entry['direction'] ?? null,
                        'delta' => isset($entry['delta']) ? (float)$entry['delta'] : null,
                        'dateString' => (string)($entry['dateString'] ?? gmdate('c', (int)($ts / 1000))),
                    ];
                }
                if ($cacheable) {
                    self::store($userId, $dayKey, $batch);
                }
            }

            foreach ($batch as $reading) {
                $out[] = $reading;
            }
            $cursor = $to;
        }

        usort($out, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);
        return $out;
    }

    private static function load(int $userId, string $day): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT payload, cached_at FROM panel_day_cache
              WHERE user_id = ? AND day = ? AND cached_at > ?'
        );
        $stmt->execute([$userId, $day, gmdate('c', time() - self::TTL_SECONDS)]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $decoded = json_decode((string)$row['payload'], true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function store(int $userId, string $day, array $readings): void
    {
        $stmt = Db::pdo()->prepare(
            'INSERT INTO panel_day_cache (user_id, day, payload, cached_at)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(user_id, day) DO UPDATE SET
                payload = excluded.payload,
                cached_at = excluded.cached_at'
        );
        $stmt->execute([$userId, $day, json_encode($readings), gmdate('c')]);
    }

    /** Drop a user's cached days (used when their history is rewritten). */
    public static function clear(int $userId): void
    {
        $stmt = Db::pdo()->prepare('DELETE FROM panel_day_cache WHERE user_id = ?');
        $stmt->execute([$userId]);
    }
}
