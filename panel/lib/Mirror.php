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
     * All cached profile records, newest first (the first one is active).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function profiles(): array
    {
        $stmt = Db::pdo()->query(
            'SELECT payload FROM panel_profiles ORDER BY start_date DESC, fetched_at DESC'
        );
        return self::decodeAll($stmt->fetchAll());
    }

    /** The profile record currently in effect, or null before the first sync. */
    public static function profileRecord(): ?array
    {
        $records = self::profiles();
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
