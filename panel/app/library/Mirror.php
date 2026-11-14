<?php
declare(strict_types=1);

namespace NightPanel;

/**
 * Read side of the local Nightscout mirror.
 *
 * bin/sync.php fills the panel_* tables; the web layer only ever queries this
 * class, so requests never block on Nightscout (and keep working while it is
 * unreachable). Every query is scoped by user id, so one account can never be
 * served another account's rows.
 */
final class Mirror
{
    private const MGDL_TO_MMOL = 18;

    /**
     * Glucose readings shaped like the old per-day cache: ts, mmol/L value,
     * mg/dL value, direction, delta and an ISO timestamp.
     *
     * @return array<int,array{ts:int,v:float,sgv:float,direction:?string,delta:?float,dateString:string}>
     */
    public static function readings(int $userId, int $startMs, int $endMs): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT ts, sgv, direction, delta FROM panel_entries
              WHERE user_id = ? AND type = \'sgv\' AND ts >= ? AND ts < ?
              ORDER BY ts'
        );
        $stmt->execute([$userId, $startMs, $endMs]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $ts = (int)$row['ts'];
            $sgv = (float)$row['sgv'];
            $out[] = [
                'ts' => $ts,
                'v' => round($sgv / self::MGDL_TO_MMOL, 2),
                'sgv' => $sgv,
                'direction' => $row['direction'] !== null ? (string)$row['direction'] : null,
                'delta' => $row['delta'] !== null ? (float)$row['delta'] : null,
                'dateString' => gmdate('c', (int)($ts / 1000)),
            ];
        }
        return $out;
    }

    /**
     * Minimal projection for the statistics page: time and mmol/L only.
     *
     * Median, sample deviation and hypo-episode detection all need the ordered
     * series, so the rows are read instead of aggregated in SQL; dropping the
     * unused columns and the per-row ISO timestamp keeps a 90-day window light.
     *
     * @return array<int,array{ts:int,v:float}>
     */
    public static function statsReadings(int $userId, int $startMs, int $endMs): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT ts, sgv FROM panel_entries
              WHERE user_id = ? AND type = \'sgv\' AND ts >= ? AND ts < ?
              ORDER BY ts'
        );
        $stmt->execute([$userId, $startMs, $endMs]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'ts' => (int)$row['ts'],
                'v' => round((float)$row['sgv'] / self::MGDL_TO_MMOL, 2),
            ];
        }
        return $out;
    }

    /**
     * Raw Nightscout entry objects for the dashboard (already JSON-encoded in
     * the mirror, so extra uploader fields survive unchanged).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function rawEntries(int $userId, string $type, int $startMs, int $endMs, int $limit = 2000): array
    {
        $limit = max(1, $limit);
        $stmt = Db::pdo()->prepare(
            'SELECT raw FROM (
                SELECT raw, ts FROM panel_entries
                 WHERE user_id = ? AND type = ? AND ts >= ? AND ts < ?
                 ORDER BY ts DESC LIMIT ' . $limit . '
             ) ORDER BY ts'
        );
        $stmt->execute([$userId, $type, $startMs, $endMs]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $decoded = json_decode((string)$row['raw'], true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        return $out;
    }

    /**
     * Most recent treatments, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recentTreatments(int $userId, int $limit = 100): array
    {
        $limit = max(1, $limit);
        $stmt = Db::pdo()->prepare(
            'SELECT payload FROM panel_treatments
              WHERE user_id = ?
              ORDER BY created_at DESC LIMIT ' . $limit
        );
        $stmt->execute([$userId]);
        return self::decodeAll($stmt->fetchAll());
    }

    /**
     * Treatments inside a millisecond range, oldest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function treatmentsBetween(int $userId, int $startMs, int $endMs): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT payload FROM panel_treatments
              WHERE user_id = ? AND created_at >= ? AND created_at < ?
              ORDER BY created_at'
        );
        $stmt->execute([
            $userId,
            gmdate('c', (int)($startMs / 1000)),
            gmdate('c', (int)($endMs / 1000)),
        ]);
        return self::decodeAll($stmt->fetchAll());
    }

    /** One treatment by its Nightscout id, or null. */
    public static function treatmentById(int $userId, string $nsId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT payload FROM panel_treatments WHERE user_id = ? AND ns_id = ?'
        );
        $stmt->execute([$userId, $nsId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $decoded = json_decode((string)$row['payload'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Latest device status record (iob/cob/battery), or null. */
    public static function latestDeviceStatus(int $userId): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT payload FROM panel_devicestatus WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $decoded = json_decode((string)$row['payload'], true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * All cached profile records of one backend, newest first (the first one
     * is active). Profiles are shared per Nightscout backend, so the mirror
     * is keyed by backend, not by user.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function profiles(int $backendId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT payload FROM panel_profiles WHERE backend_id = ? ORDER BY start_date DESC, fetched_at DESC'
        );
        $stmt->execute([$backendId]);
        return self::decodeAll($stmt->fetchAll());
    }

    /** The profile record currently in effect, or null before the first sync. */
    public static function profileRecord(int $backendId): ?array
    {
        $records = self::profiles($backendId);
        return $records[0] ?? null;
    }

    /**
     * Freshness of a user's mirrored data, used by the dashboard banner.
     *
     * @return array{last_ok_at:?string,last_reading_ts:?int,age_sec:?int}
     */
    public static function freshness(int $userId): array
    {
        $stmt = Db::pdo()->prepare('SELECT MAX(ts) AS ts FROM panel_entries WHERE user_id = ?');
        $stmt->execute([$userId]);
        $ts = $stmt->fetchColumn();
        $lastTs = ($ts === false || $ts === null) ? null : (int)$ts;

        $stmt = Db::pdo()->prepare(
            'SELECT MAX(last_ok_at) AS last_ok FROM panel_sync_state WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $lastOk = $stmt->fetchColumn();

        return [
            'last_ok_at' => $lastOk === false || $lastOk === null ? null : (string)$lastOk,
            'last_reading_ts' => $lastTs,
            'age_sec' => $lastTs === null ? null : max(0, (int)round(microtime(true) - $lastTs / 1000)),
        ];
    }

    /**
     * Alert events the dashboard has not shown yet and that are recent enough
     * to be actionable.
     *
     * @return array<int,array{id:int,ts:int,kind:string,value_mmol:float,limit_mmol:float}>
     */
    public static function undeliveredAlerts(int $userId, int $sinceMs): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, ts, kind, value_mmol, limit_mmol FROM panel_alert_events
              WHERE user_id = ? AND delivered = 0 AND ts >= ?
              ORDER BY ts'
        );
        $stmt->execute([$userId, $sinceMs]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'ts' => (int)$row['ts'],
                'kind' => (string)$row['kind'],
                'value_mmol' => (float)$row['value_mmol'],
                'limit_mmol' => (float)$row['limit_mmol'],
            ];
        }
        return $out;
    }

    /**
     * Mark alert events as shown. Only ids owned by the user are touched.
     *
     * @param array<int,mixed> $ids
     */
    public static function ackAlerts(int $userId, array $ids): int
    {
        $clean = [];
        foreach ($ids as $id) {
            $value = (int)$id;
            if ($value > 0) {
                $clean[$value] = $value;
            }
        }
        if ($clean === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($clean), '?'));
        $stmt = Db::pdo()->prepare(
            'UPDATE panel_alert_events SET delivered = 1
              WHERE user_id = ? AND id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$userId], array_values($clean)));
        return $stmt->rowCount();
    }

    /**
     * Sync state rows for one user (or all users), keyed by stream.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function syncState(?int $userId = null): array
    {
        if ($userId === null) {
            return Db::pdo()->query('SELECT * FROM panel_sync_state ORDER BY user_id, stream')->fetchAll();
        }
        $stmt = Db::pdo()->prepare('SELECT * FROM panel_sync_state WHERE user_id = ? ORDER BY stream');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Store a treatment the panel just wrote. Lists read the mirror, so the new
     * record shows up immediately without a second trip to Nightscout.
     */
    public static function storeTreatment(int $userId, array $record): void
    {
        $nsId = (string)($record['_id'] ?? '');
        $createdAt = (string)($record['created_at'] ?? '');
        if ($nsId === '' || $createdAt === '') {
            return;
        }
        // Nightscout answers with a "…000Z" timestamp while the sync writes
        // "+00:00"; both mean the same instant, but mixing the two formats in
        // one column breaks string ordering. Store the canonical form.
        $parsed = strtotime($createdAt);
        if ($parsed === false) {
            return;
        }
        $createdAt = gmdate('c', $parsed);
        $stmt = Db::pdo()->prepare(
            'INSERT INTO panel_treatments (user_id, ns_id, created_at, event_type, payload, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(user_id, ns_id) DO UPDATE SET
                created_at = excluded.created_at,
                event_type = excluded.event_type,
                payload = excluded.payload,
                updated_at = excluded.updated_at'
        );
        $stmt->execute([
            $userId,
            $nsId,
            $createdAt,
            isset($record['eventType']) ? (string)$record['eventType'] : null,
            json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            gmdate('c'),
        ]);
    }

    /** Drop a treatment the panel just deleted. */
    public static function forgetTreatment(int $userId, string $nsId): void
    {
        $stmt = Db::pdo()->prepare('DELETE FROM panel_treatments WHERE user_id = ? AND ns_id = ?');
        $stmt->execute([$userId, $nsId]);
    }

    /** Store an sgv entry the panel just wrote. */
    public static function storeEntry(int $userId, array $record, string $device): void
    {
        $ts = (int)($record['date'] ?? 0);
        $nsId = (string)($record['_id'] ?? '');
        if ($ts <= 0 || $nsId === '') {
            return;
        }
        $stmt = Db::pdo()->prepare(
            'INSERT INTO panel_entries (user_id, ns_id, type, device, ts, sgv, direction, delta, raw)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(user_id, ns_id) DO UPDATE SET
                ts = excluded.ts,
                sgv = excluded.sgv,
                direction = excluded.direction,
                delta = excluded.delta,
                raw = excluded.raw,
                fetched_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            $userId,
            $nsId,
            'sgv',
            (string)($record['device'] ?? $device),
            $ts,
            isset($record['sgv']) ? (float)$record['sgv'] : null,
            isset($record['direction']) ? (string)$record['direction'] : null,
            isset($record['delta']) ? (float)$record['delta'] : null,
            json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function decodeAll(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string)$row['payload'], true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        return $out;
    }
}
