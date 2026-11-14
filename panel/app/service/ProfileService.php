<?php
declare(strict_types=1);

namespace app\service;

use NightPanel\Auth;
use NightPanel\Lang;
use NightPanel\Mirror;
use NightPanel\NsConfig;
use NightPanel\NsException;
use NightPanel\Sync;

/**
 * Nightscout profiles: the shared store document, the signed-in user's entry,
 * and the versioned plans built out of older records.
 */
final class ProfileService
{
    /**
     * All profile records, newest first. Reads stay mirror-first: a page load
     * must not block on Nightscout, and the mirror survives an outage.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function records(): array
    {
        $records = Mirror::profiles();
        if ($records !== []) {
            return $records;
        }
        return NsConfig::client()->profiles();
    }

    /** The profile record currently in effect. */
    public static function record(): ?array
    {
        $record = Mirror::profileRecord();
        if ($record !== null) {
            return $record;
        }
        return NsConfig::client()->currentProfileRecord();
    }

    /**
     * Live records for write paths.
     *
     * A mirrored snapshot lags behind Nightscout: saving with a stale `_id`
     * answers 404, and activating a plan from a stale list rebuilds the record
     * from an outdated store, dropping entries written after the last sync.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recordsLive(): array
    {
        return NsConfig::client()->profiles();
    }

    /** The live current record, for write paths. */
    public static function recordLive(): ?array
    {
        return NsConfig::client()->currentProfileRecord();
    }

    /** The signed-in user's own store entry, or null. */
    public static function forUser(array $user): ?array
    {
        try {
            $record = self::record();
        } catch (NsException $e) {
            return null;
        }
        return $record['store'][$user['username']] ?? null;
    }

    /** Latest value of a profile time-shifted array (mg/dL) as mmol/L. */
    public static function target(array $profile, string $field, float $fallback): float
    {
        $pairs = $profile[$field] ?? null;
        if (!is_array($pairs) || count($pairs) === 0) {
            return $fallback;
        }
        $last = $pairs[count($pairs) - 1];
        $value = is_array($last) ? (float)($last[1] ?? 0) : (float)$last;
        return $value > 0 ? round($value / 18, 2) : $fallback;
    }

    /**
     * One plan row per profile record that holds this user's store entry.
     * Nightscout orders records by startDate descending, so index 0 is active.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function plans(string $username): array
    {
        $plans = [];
        foreach (self::records() as $index => $record) {
            if (!isset($record['store'][$username])) {
                continue;
            }
            $own = $record['store'][$username];
            $targetLow = $own['target_low'] ?? [];
            $targetHigh = $own['target_high'] ?? [];
            $plans[] = [
                'id' => (string)$record['_id'],
                'startDate' => (string)($record['startDate'] ?? ''),
                'active' => $index === 0,
                'shared' => count(array_keys($record['store'])) > 1,
                'dia' => $own['dia'] ?? null,
                'basalCount' => count($own['basal'] ?? []),
                'targetLow' => $targetLow ? $targetLow[count($targetLow) - 1][1] : null,
                'targetHigh' => $targetHigh ? $targetHigh[count($targetHigh) - 1][1] : null,
            ];
        }
        return $plans;
    }

    /**
     * Parse "offset,value" lines into Nightscout profile arrays.
     *
     * @return array<int,array{0:float,1:float}>
     */
    public static function parsePairs(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode(',', $line));
            if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                throw new \InvalidArgumentException(Lang::t('val.pairFormat', ['line' => $line]));
            }
            $out[] = [(float)$parts[0], (float)$parts[1]];
        }
        return $out;
    }

    /**
     * Save the signed-in user's own store entry.
     *
     * The PUT goes out with the USER token: Nightscout enforces that only
     * store[<username>] changes, no matter what the body contains.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $post
     * @return array<string,mixed> the saved store entry
     */
    public static function saveOwn(array $user, array $post): array
    {
        $record = self::recordLive();
        if ($record === null || !isset($record['store'][$user['username']])) {
            throw new NsException('Profile record missing');
        }

        $own = $record['store'][$user['username']];
        $own['sens'] = self::parsePairs((string)($post['sens'] ?? ''));
        $own['target_low'] = self::parsePairs((string)($post['target_low'] ?? ''));
        $own['target_high'] = self::parsePairs((string)($post['target_high'] ?? ''));
        $own['basal'] = self::parsePairs((string)($post['basal'] ?? ''));
        $own['dia'] = (float)($post['dia'] ?? 3);
        $own['carbs_hr'] = (float)($post['carbs_hr'] ?? 30);
        $own['timezone'] = trim((string)($post['timezone'] ?? 'UTC'));
        $own['units'] = 'mg/dl';

        NsConfig::client()->requestOrThrow('PUT', '/api/v1/profile.json', [
            '_id' => (string)$record['_id'],
            'store' => [$user['username'] => $own],
        ], Auth::userToken($user));

        self::refreshSnapshot();

        return $own;
    }

    /** Make an older record the active plan. */
    public static function activate(array $user, string $planId): void
    {
        $records = self::recordsLive();
        $source = null;
        foreach ($records as $record) {
            if ((string)$record['_id'] === $planId) {
                $source = $record;
                break;
            }
        }
        if ($source === null || !isset($source['store'][$user['username']])) {
            throw new \InvalidArgumentException(Lang::t('val.planNotFound'));
        }

        // The new record has to carry every other user's store entry as well:
        // Nightscout reads the current profile from the newest record, so a
        // record holding only this user would make everyone else's profile
        // disappear. Only this user's own entry is replaced, and only with a
        // value taken from a record they can already read.
        $active = $records[0] ?? [];
        $store = is_array($active['store'] ?? null) ? $active['store'] : [];
        $store[$user['username']] = $source['store'][$user['username']];

        NsConfig::client()->requestOrThrow('POST', '/api/v1/profile.json', [
            'store' => $store,
            'defaultProfile' => $active['defaultProfile'] ?? $user['username'],
            'startDate' => gmdate('c'),
        ]);

        self::refreshSnapshot();
    }

    /** Delete a plan record that only belongs to this user. */
    public static function deletePlan(array $user, string $planId): void
    {
        $records = self::recordsLive();
        $target = null;
        $index = -1;
        foreach ($records as $i => $record) {
            if ((string)$record['_id'] === $planId) {
                $target = $record;
                $index = $i;
                break;
            }
        }
        if ($target === null) {
            throw new \InvalidArgumentException(Lang::t('val.planNotFound'));
        }

        // A shared record holds other users' entries too: deleting it would
        // destroy their plans, so only single-owner records go.
        $keys = array_keys($target['store'] ?? []);
        if ($keys !== [$user['username']]) {
            throw new \InvalidArgumentException(Lang::t('val.planShared'));
        }
        if ($index === 0) {
            throw new \InvalidArgumentException(Lang::t('val.planActive'));
        }

        NsConfig::client()->requestOrThrow('DELETE', '/api/v1/profile/' . rawurlencode($planId));
        self::refreshSnapshot();
    }

    /** Refresh the shared profile snapshot after a profile write. */
    public static function refreshSnapshot(): void
    {
        try {
            (new Sync(NsConfig::client()))->profiles();
        } catch (\Throwable $e) {
            // ignore: the scheduled run is the safety net
        }
    }
}
