<?php
declare(strict_types=1);

namespace app\service;

use NightPanel\Backends;
use NightPanel\Db;
use NightPanel\NsException;
use NightPanel\NsClient;

/**
 * Moves every account of one Nightscout backend to another, in place.
 *
 * The panel is the source of truth for usernames, so each user is rebuilt on
 * the target backend (role + subject + profile store entry carried over from
 * the source), their full history is re-uploaded, and the local account is
 * then pointed at the new backend. The per-user mirror is dropped instead of
 * merged: the next sync run refills it from the target, which is what keeps
 * old and new Nightscout ids from double-counting in reports.
 *
 * Every step is idempotent, so a failed migration can simply be run again:
 * the target's leftover data for a user is wiped before the re-upload, and
 * the subject is rebuilt (delete + create) rather than reused.
 */
final class BackendMigration
{
    /** Page size for history reads; also the bulk-write chunk size. */
    private const PAGE = 500;

    /**
     * @param array<string,mixed> $from source backend row
     * @param array<string,mixed> $to target backend row
     * @param bool $cleanup wipe the source data after a successful move
     * @return array<int,array<string,mixed>> one result row per user
     */
    public static function run(array $from, array $to, bool $cleanup): array
    {
        $clientFrom = Backends::clientFor($from);
        $clientTo = Backends::clientFor($to);

        $stmt = Db::pdo()->prepare(
            'SELECT * FROM panel_users WHERE backend_id = ? AND role != "admin" ORDER BY id'
        );
        $stmt->execute([(int)$from['id']]);

        $results = [];
        foreach ($stmt->fetchAll() as $user) {
            $result = [
                'username' => (string)$user['username'],
                'ok' => false,
                'entries' => 0,
                'treatments' => 0,
                'devicestatus' => 0,
                'error' => null,
                'cleanupError' => null,
            ];
            try {
                $counts = self::migrateUser($user, $clientFrom, $clientTo, (int)$to['id'], $cleanup);
                $result = array_merge($result, $counts, ['ok' => true]);
            } catch (\Throwable $e) {
                $result['error'] = $e->getMessage();
            }
            $results[] = $result;
        }
        return $results;
    }

    /**
     * One account. Throws on the first hard failure; a soft cleanup failure
     * is reported separately because the move itself already succeeded.
     *
     * @param array<string,mixed> $user
     * @return array{entries:int,treatments:int,devicestatus:int,cleanupError:?string}
     */
    private static function migrateUser(
        array $user,
        NsClient $from,
        NsClient $to,
        int $toId,
        bool $cleanup
    ): array {
        $username = (string)$user['username'];
        $device = $username;

        // 1. The profile store entry is read first: everything after this
        //    point may wipe or rebuild state, but the source content is
        //    needed before that.
        $profile = self::sourceProfile($from, $username);

        // 2. Rebuild the identity on the target. The panel owns the name, so
        //    a leftover same-name subject (e.g. from a previous failed run)
        //    is removed first; provision() then recreates it with the right
        //    role and a fresh token.
        $to->deleteSubjectByName($username);
        $provisioned = UserProvisioner::provision($username, $to, $profile);

        // 3. Wipe the target's leftover attribution data for this user so a
        //    retry re-uploads instead of stacking duplicates.
        $to->deleteEntriesByDevice($device);
        $to->deleteTreatmentsByEnteredBy($username);
        $to->deleteDeviceStatusByDevice($device);

        // 4. Full history, oldest-ward pagination, bulk re-upload.
        $entries = self::copyEntries($from, $to, $device);
        $treatments = self::copyTreatments($from, $to, $username);
        $devicestatus = self::copyDeviceStatus($from, $to, $device);

        // 5. Point the account at the target backend.
        Db::pdo()->prepare(
            'UPDATE panel_users
               SET backend_id = ?, ns_subject_id = ?, ns_role_name = ?, ns_access_token_enc = ?
             WHERE id = ?'
        )->execute([
            $toId,
            $provisioned['subjectId'],
            $provisioned['roleName'],
            Db::encrypt($provisioned['token']),
            (int)$user['id'],
        ]);

        // 6. Drop the local mirror and cursors: the next sync run refills
        //    from the target with fresh ids, so nothing is counted twice.
        $pdo = Db::pdo();
        foreach ([
            'panel_entries',
            'panel_treatments',
            'panel_devicestatus',
            'panel_sync_state',
            'panel_alert_events',
        ] as $table) {
            $pdo->prepare('DELETE FROM ' . $table . ' WHERE user_id = ?')->execute([(int)$user['id']]);
        }

        // 7. Optional source cleanup. Data deletion only; the subject/role
        //    grant goes with it so no orphan token survives on the source.
        $cleanupError = null;
        if ($cleanup) {
            try {
                $from->deleteEntriesByDevice($device);
                $from->deleteTreatmentsByEnteredBy($username);
                $from->deleteDeviceStatusByDevice($device);
                if (!empty($user['ns_subject_id'])) {
                    $from->deleteSubject((string)$user['ns_subject_id']);
                }
                if (!empty($user['ns_role_name'])) {
                    $from->deleteRoleByName((string)$user['ns_role_name']);
                }
            } catch (NsException $e) {
                // The account is already migrated; re-running with cleanup
                // checked finishes the job.
                $cleanupError = $e->getMessage();
            }
        }

        return [
            'entries' => $entries,
            'treatments' => $treatments,
            'devicestatus' => $devicestatus,
            'cleanupError' => $cleanupError,
        ];
    }

    /** The user's store entry on the source backend, or null for defaults. */
    private static function sourceProfile(NsClient $from, string $username): ?array
    {
        try {
            $records = $from->profiles();
        } catch (NsException $e) {
            // Source profile unreadable: provision() falls back to defaults.
            return null;
        }
        foreach ($records as $record) {
            if (isset($record['store'][$username]) && is_array($record['store'][$username])) {
                return $record['store'][$username];
            }
        }
        return null;
    }

    /**
     * Copy every entry of a device. Cursor on `date` (ms epoch): each page is
     * the newest PAGE records strictly older than the previous page's oldest.
     */
    private static function copyEntries(NsClient $from, NsClient $to, string $device): int
    {
        $total = 0;
        $beforeMs = 0;
        while (true) {
            $batch = $from->entriesBefore($device, $beforeMs, self::PAGE);
            if ($batch === []) {
                break;
            }
            $to->bulkPostEntries(self::portable($batch));
            $total += count($batch);
            if (count($batch) < self::PAGE) {
                break;
            }
            $oldest = self::oldestMs($batch);
            if ($oldest <= 0) {
                break;
            }
            $beforeMs = $oldest - 1;
        }
        return $total;
    }

    /** Copy every treatment of one user, cursor on the raw created_at string. */
    private static function copyTreatments(NsClient $from, NsClient $to, string $username): int
    {
        $total = 0;
        $beforeIso = '';
        while (true) {
            $batch = $from->treatmentsBefore($username, $beforeIso, self::PAGE);
            if ($batch === []) {
                break;
            }
            $to->bulkPostTreatments(self::portable($batch));
            $total += count($batch);
            if (count($batch) < self::PAGE) {
                break;
            }
            $next = self::oldestString($batch, 'created_at');
            if ($next === '' || $next === $beforeIso) {
                break;
            }
            $beforeIso = $next;
        }
        return $total;
    }

    /** Copy every devicestatus record of a device, cursor on created_at. */
    private static function copyDeviceStatus(NsClient $from, NsClient $to, string $device): int
    {
        $total = 0;
        $beforeIso = '';
        while (true) {
            $batch = $from->deviceStatusBefore($device, $beforeIso, self::PAGE);
            if ($batch === []) {
                break;
            }
            $to->bulkPostDeviceStatus(self::portable($batch));
            $total += count($batch);
            if (count($batch) < self::PAGE) {
                break;
            }
            $next = self::oldestString($batch, 'created_at');
            if ($next === '' || $next === $beforeIso) {
                break;
            }
            $beforeIso = $next;
        }
        return $total;
    }

    /**
     * Strip Mongo's own keys (`_id`, `_v`, ...) so Nightscout assigns fresh
     * ids on the target instead of colliding with the source's.
     *
     * @param array<int,array<string,mixed>> $batch
     * @return array<int,array<string,mixed>>
     */
    private static function portable(array $batch): array
    {
        $out = [];
        foreach ($batch as $record) {
            $out[] = array_filter(
                $record,
                fn($key) => !str_starts_with((string)$key, '_'),
                ARRAY_FILTER_USE_KEY
            );
        }
        return $out;
    }

    /** Oldest `date` field (ms epoch) of a batch; 0 when absent. */
    private static function oldestMs(array $batch): int
    {
        $min = PHP_INT_MAX;
        foreach ($batch as $record) {
            $ts = (int)($record['date'] ?? 0);
            if ($ts <= 0) {
                $iso = (string)($record['dateString'] ?? '');
                $parsed = $iso !== '' ? strtotime($iso) : false;
                $ts = $parsed === false ? 0 : $parsed * 1000;
            }
            if ($ts > 0 && $ts < $min) {
                $min = $ts;
            }
        }
        return $min === PHP_INT_MAX ? 0 : $min;
    }

    /** Oldest raw string value of a field; '' when absent. */
    private static function oldestString(array $batch, string $field): string
    {
        $oldest = '';
        $min = PHP_INT_MAX;
        foreach ($batch as $record) {
            $value = (string)($record[$field] ?? '');
            if ($value === '') {
                continue;
            }
            $ts = strtotime($value);
            if ($ts !== false && $ts < $min) {
                $min = $ts;
                $oldest = $value;
            }
        }
        return $oldest;
    }
}
