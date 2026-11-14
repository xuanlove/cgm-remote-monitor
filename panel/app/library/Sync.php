<?php
declare(strict_types=1);

namespace NightPanel;

/**
 * Pulls Nightscout data into the local mirror. Invoked from bin/sync.php by
 * cron (and inline after a write so the panel reflects the change right away).
 *
 * Every stream keeps its own millisecond cursor in panel_sync_state and
 * re-reads a six-hour overlap window, because CGM uploaders routinely backfill
 * late records. Upserts are keyed by Nightscout ids, so overlaps are free.
 */
final class Sync
{
    private const OVERLAP_MS = 6 * 3600 * 1000;
    private const INITIAL_MS = 2 * 86400 * 1000;
    private const ALERT_DEDUPE_MS = 15 * 60 * 1000;

    /** @var callable|null */
    private $log;

    public function __construct(private NsClient $client, ?callable $log = null)
    {
        $this->log = $log;
    }

    /**
     * Sync one panel user.
     *
     * @param array<string,mixed> $user
     * @param array<int,string> $streams entries, treatments, devicestatus
     * @return array<string,mixed> summary with per-stream row counts and errors
     */
    public function user(array $user, array $streams = ['entries', 'treatments', 'devicestatus'], bool $full = false, int $days = 2): array
    {
        $userId = (int)$user['id'];
        $device = (string)$user['username'];
        $summary = ['user' => $device, 'errors' => []];

        if (in_array('entries', $streams, true)) {
            foreach (['sgv', 'mbg'] as $type) {
                try {
                    $summary['entries:' . $type] = $this->syncEntries($userId, $device, $type, $full, $days);
                } catch (\Throwable $e) {
                    $summary['errors'][] = 'entries:' . $type . ': ' . $e->getMessage();
                }
            }
        }

        if (in_array('treatments', $streams, true)) {
            try {
                $summary['treatments'] = $this->syncTreatments($userId, $device, $full, $days);
            } catch (\Throwable $e) {
                $summary['errors'][] = 'treatments: ' . $e->getMessage();
            }
        }

        if (in_array('devicestatus', $streams, true)) {
            try {
                $summary['devicestatus'] = $this->syncDeviceStatus($userId, $device);
            } catch (\Throwable $e) {
                $summary['errors'][] = 'devicestatus: ' . $e->getMessage();
            }
        }

        try {
            $summary['alerts'] = $this->evaluateAlerts($userId);
        } catch (\Throwable $e) {
            $summary['errors'][] = 'alerts: ' . $e->getMessage();
        }

        return $summary;
    }

    /**
     * Refresh the (global) profile snapshot: every user's store lives in the
     * same records, so this runs once per schedule instead of per user.
     */
    public function profiles(): int
    {
        $started = microtime(true);
        $stmt = Db::pdo()->prepare(
            'INSERT INTO panel_profiles (ns_id, start_date, payload, fetched_at)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(ns_id) DO UPDATE SET
                start_date = excluded.start_date,
                payload = excluded.payload,
                fetched_at = excluded.fetched_at'
        );

        $count = 0;
        try {
            $records = $this->client->profiles();
            Db::pdo()->beginTransaction();
            foreach (array_slice($records, 0, 30) as $record) {
                $id = (string)($record['_id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $stmt->execute([
                    $id,
                    (string)($record['startDate'] ?? ''),
                    json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    gmdate('c'),
                ]);
                $count++;
            }
            Db::pdo()->exec(
                'DELETE FROM panel_profiles WHERE ns_id NOT IN (
                    SELECT ns_id FROM panel_profiles
                     ORDER BY start_date DESC, fetched_at DESC LIMIT 30
                )'
            );
            Db::pdo()->commit();
        } catch (\Throwable $e) {
            if (Db::pdo()->inTransaction()) {
                Db::pdo()->rollBack();
            }
            self::markError(0, 'profiles', $e->getMessage());
            $this->logRow(0, 'profiles', $started, 0, 'error', $e->getMessage());
            throw $e;
        }

        self::markOk(0, 'profiles', 0);
        $this->logRow(0, 'profiles', $started, $count, 'ok', null);
        return $count;
    }

    private function syncEntries(int $userId, string $device, string $type, bool $full, int $days): int
    {
        $stream = 'entries:' . $type;
        $started = microtime(true);
        $now = self::nowMs();
        $cursor = self::cursor($userId, $stream);
        $start = self::windowStart($cursor, $now, $full, $days);

        // Day-sized chunks keep every request below Nightscout's result cap.
        $records = [];
        for ($chunk = $start; $chunk < $now; $chunk += 86400000) {
            $end = min($chunk + 86400000, $now);
            foreach ($this->client->entriesInRange($device, $type, $chunk, $end, 600) as $entry) {
                $records[] = $entry;
            }
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

        $count = 0;
        $maxTs = $cursor;
        Db::pdo()->beginTransaction();
        try {
            foreach ($records as $entry) {
                $ts = self::entryTs($entry);
                if ($ts <= 0) {
                    continue;
                }
                $value = null;
                if (isset($entry['sgv'])) {
                    $value = (float)$entry['sgv'];
                } elseif (isset($entry['mbg'])) {
                    $value = (float)$entry['mbg'];
                }
                $nsId = (string)($entry['_id'] ?? '');
                if ($nsId === '') {
                    $nsId = hash('sha1', $type . '|' . $device . '|' . $ts);
                }
                $stmt->execute([
                    $userId,
                    $nsId,
                    $type,
                    (string)($entry['device'] ?? $device),
                    $ts,
                    $value,
                    isset($entry['direction']) ? (string)$entry['direction'] : null,
                    isset($entry['delta']) ? (float)$entry['delta'] : null,
                    json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
                $count++;
                if ($ts > $maxTs) {
                    $maxTs = $ts;
                }
            }
            self::markOk($userId, $stream, $maxTs);
            Db::pdo()->commit();
        } catch (\Throwable $e) {
            Db::pdo()->rollBack();
            self::markError($userId, $stream, $e->getMessage());
            $this->logRow($userId, $stream, $started, 0, 'error', $e->getMessage());
            throw $e;
        }

        $this->logRow($userId, $stream, $started, $count, 'ok', null);
        return $count;
    }

    private function syncTreatments(int $userId, string $device, bool $full, int $days): int
    {
        $stream = 'treatments';
        $started = microtime(true);
        $now = self::nowMs();
        $cursor = self::cursor($userId, $stream);
        $start = self::windowStart($cursor, $now, $full, $days);

        $records = $this->client->treatmentsBetween($device, $start, $now);

        $stmt = Db::pdo()->prepare(
            'INSERT INTO panel_treatments (user_id, ns_id, created_at, event_type, payload, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(user_id, ns_id) DO UPDATE SET
                created_at = excluded.created_at,
                event_type = excluded.event_type,
                payload = excluded.payload,
                updated_at = excluded.updated_at'
        );

        $count = 0;
        $maxTs = $cursor;
        Db::pdo()->beginTransaction();
        try {
            foreach ($records as $record) {
                $createdAt = (string)($record['created_at'] ?? '');
                if ($createdAt === '') {
                    continue;
                }
                $ts = (int)(strtotime($createdAt) * 1000);
                $nsId = (string)($record['_id'] ?? '');
                if ($nsId === '') {
                    $nsId = hash('sha1', 't|' . $device . '|' . $createdAt . '|' . (string)($record['eventType'] ?? ''));
                }
                $stmt->execute([
                    $userId,
                    $nsId,
                    gmdate('c', intdiv($ts, 1000)),
                    isset($record['eventType']) ? (string)$record['eventType'] : null,
                    json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    gmdate('c'),
                ]);
                $count++;
                if ($ts > $maxTs) {
                    $maxTs = $ts;
                }
            }
            self::markOk($userId, $stream, $maxTs);
            Db::pdo()->commit();
        } catch (\Throwable $e) {
            Db::pdo()->rollBack();
            self::markError($userId, $stream, $e->getMessage());
            $this->logRow($userId, $stream, $started, 0, 'error', $e->getMessage());
            throw $e;
        }

        $this->logRow($userId, $stream, $started, $count, 'ok', null);
        return $count;
    }

    private function syncDeviceStatus(int $userId, string $device): int
    {
        $stream = 'devicestatus';
        $started = microtime(true);

        $status = $this->client->latestDeviceStatus($device);
        if ($status === null) {
            self::markOk($userId, $stream, self::cursor($userId, $stream));
            $this->logRow($userId, $stream, $started, 0, 'ok', null);
            return 0;
        }

        $ts = self::entryTs($status);
        $stmt = Db::pdo()->prepare(
            'INSERT INTO panel_devicestatus (user_id, ns_id, ts, payload, fetched_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(user_id) DO UPDATE SET
                ns_id = excluded.ns_id,
                ts = excluded.ts,
                payload = excluded.payload,
                fetched_at = excluded.fetched_at'
        );
        $stmt->execute([
            $userId,
            (string)($status['_id'] ?? ''),
            $ts > 0 ? $ts : null,
            json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            gmdate('c'),
        ]);

        self::markOk($userId, $stream, max($ts, self::cursor($userId, $stream)));
        $this->logRow($userId, $stream, $started, 1, 'ok', null);
        return 1;
    }

    /**
     * Server-side threshold check: produces alert events even when no browser
     * is open, so notifications no longer depend on a dashboard session.
     */
    private function evaluateAlerts(int $userId): int
    {
        $settings = Alerts::load($userId);
        if (empty($settings['enabled'])) {
            return 0;
        }

        $stmt = Db::pdo()->prepare(
            'SELECT ts, sgv FROM panel_entries
              WHERE user_id = ? AND type = \'sgv\' ORDER BY ts DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return 0;
        }

        $value = round(((float)$row['sgv']) / 18, 2);
        $kind = Alerts::state($value, $settings);
        if ($kind === null) {
            return 0;
        }
        $limit = $kind === 'low' ? (float)$settings['low'] : (float)$settings['high'];
        $ts = (int)$row['ts'];

        // Dedupe on when the event was *created*, not on the reading time: a
        // CGM that stopped uploading keeps an old reading timestamp, which made
        // the window miss every time and produced one event per sync run.
        $dup = Db::pdo()->prepare(
            'SELECT 1 FROM panel_alert_events
              WHERE user_id = ? AND kind = ? AND created_at >= ? LIMIT 1'
        );
        $dup->execute([$userId, $kind, gmdate('Y-m-d H:i:s', time() - intdiv(self::ALERT_DEDUPE_MS, 1000))]);
        if ($dup->fetchColumn() !== false) {
            return 0;
        }

        $insert = Db::pdo()->prepare(
            'INSERT INTO panel_alert_events (user_id, ts, kind, value_mmol, limit_mmol)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([$userId, $ts, $kind, $value, $limit]);
        return 1;
    }

    /** Cursor start: full backfill, overlap window, or the initial window. */
    private static function windowStart(int $cursor, int $now, bool $full, int $days): int
    {
        $days = max(1, $days);
        if ($full) {
            return $now - $days * 86400000;
        }
        if ($cursor > 0) {
            return max($cursor - self::OVERLAP_MS, $now - $days * 86400000);
        }
        return $now - min(self::INITIAL_MS, $days * 86400000);
    }

    /** Millisecond timestamp of an entry/devicestatus record. */
    private static function entryTs(array $record): int
    {
        if (isset($record['date']) && is_numeric($record['date'])) {
            return (int)$record['date'];
        }
        if (isset($record['mills']) && is_numeric($record['mills'])) {
            return (int)$record['mills'];
        }
        $iso = (string)($record['dateString'] ?? $record['created_at'] ?? '');
        if ($iso !== '') {
            $parsed = strtotime($iso);
            return $parsed === false ? 0 : $parsed * 1000;
        }
        return 0;
    }

    private static function cursor(int $userId, string $stream): int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT cursor_ms FROM panel_sync_state WHERE user_id = ? AND stream = ?'
        );
        $stmt->execute([$userId, $stream]);
        $value = $stmt->fetchColumn();
        return $value === false ? 0 : (int)$value;
    }

    private static function markOk(int $userId, string $stream, int $cursor): void
    {
        $stmt = Db::pdo()->prepare(
            'INSERT INTO panel_sync_state (user_id, stream, cursor_ms, last_ok_at, last_error, error_count)
             VALUES (?, ?, ?, ?, NULL, 0)
             ON CONFLICT(user_id, stream) DO UPDATE SET
                cursor_ms = excluded.cursor_ms,
                last_ok_at = excluded.last_ok_at,
                last_error = NULL,
                error_count = 0'
        );
        $stmt->execute([$userId, $stream, $cursor, gmdate('c')]);
    }

    private static function markError(int $userId, string $stream, string $message): void
    {
        $stmt = Db::pdo()->prepare(
            'INSERT INTO panel_sync_state (user_id, stream, cursor_ms, last_ok_at, last_error, error_count)
             VALUES (?, ?, 0, NULL, ?, 1)
             ON CONFLICT(user_id, stream) DO UPDATE SET
                last_error = excluded.last_error,
                error_count = error_count + 1'
        );
        $stmt->execute([$userId, $stream, $message]);
    }

    private function logRow(int $userId, string $stream, float $started, int $inserted, string $status, ?string $message): void
    {
        $stmt = Db::pdo()->prepare(
            'INSERT INTO panel_sync_log (user_id, stream, started_at, duration_ms, inserted, status, message)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $stream,
            gmdate('c', (int)$started),
            (int)round((microtime(true) - $started) * 1000),
            $inserted,
            $status,
            $message,
        ]);

        if ($this->log !== null) {
            ($this->log)(sprintf(
                '%s %s: %d rows in %dms%s',
                $stream,
                $status,
                $inserted,
                (int)round((microtime(true) - $started) * 1000),
                $message !== null ? ' — ' . $message : ''
            ));
        }
    }

    private static function nowMs(): int
    {
        return (int)round(microtime(true) * 1000);
    }
}
