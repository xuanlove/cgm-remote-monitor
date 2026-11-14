<?php
declare(strict_types=1);

namespace app\service;

use NightPanel\Backends;
use NightPanel\Lang;
use NightPanel\Mirror;
use NightPanel\NsClient;
use NightPanel\NsException;
use NightPanel\TreatmentTypes;

/**
 * Treatment records: the panel's own writes, plus ownership checks that decide
 * whether an edit or delete may touch a Nightscout document.
 */
final class TreatmentService
{
    /** The Nightscout client of the user's backend. */
    private static function client(array $user): NsClient
    {
        return Backends::clientFor((int)($user['backend_id'] ?? 0));
    }

    /** One treatment for the read-only edit form, mirror first. */
    public static function find(array $user, string $id): ?array
    {
        $record = Mirror::treatmentById((int)$user['id'], $id);
        if ($record !== null) {
            return $record;
        }
        try {
            return self::client($user)->treatmentById($id);
        } catch (NsException $e) {
            return null;
        }
    }

    /**
     * The live Nightscout copy, for write paths.
     *
     * Editing or deleting from the mirrored copy would send back fields that
     * changed after the last sync and could silently revert them. A Nightscout
     * failure propagates instead of looking like "not found", so the caller
     * answers 502 rather than 403.
     */
    public static function findLive(array $user, string $id): ?array
    {
        return $id === '' ? null : self::client($user)->treatmentById($id);
    }

    /** A record belongs to the session user only when the stored attribution says so. */
    public static function owned(array $record, array $user): bool
    {
        return isset($record['enteredBy']) && (string)$record['enteredBy'] === (string)$user['username'];
    }

    /**
     * Nightscout answers writes with one record or a one-item array.
     *
     * @param array<int|string,mixed> $body
     * @return array<string,mixed>|null
     */
    public static function created(array $body): ?array
    {
        if (isset($body[0]) && is_array($body[0])) {
            return $body[0];
        }
        return $body === [] ? null : $body;
    }

    /**
     * Handle the "fill in data" form: glucose, a treatment event or an activity.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $post
     */
    public static function submit(array $user, array $post): void
    {
        $client = self::client($user);

        switch ((string)($post['kind'] ?? '')) {
            case 'glucose':
                $sgv = (float)($post['sgv'] ?? 0);
                if ($sgv < 10 || $sgv > 600) {
                    throw new \InvalidArgumentException(Lang::t('val.glucoseRange'));
                }
                $created = self::created($client->createEntry(
                    (string)$user['username'],
                    $sgv,
                    ($post['timestamp'] ?? '') ?: null
                ));
                if ($created !== null) {
                    Mirror::storeEntry((int)$user['id'], $created, (string)$user['username']);
                }
                return;

            case 'treatment':
                // Every event type goes through the registry, so only the fields
                // that belong to the chosen type are written.
                $eventType = (string)($post['eventType'] ?? '');
                if (!TreatmentTypes::has($eventType)) {
                    throw new \InvalidArgumentException(Lang::t('val.unsupportedEvent'));
                }
                $created = self::created($client->createTreatment(
                    TreatmentTypes::build($eventType, $post),
                    (string)$user['username']
                ));
                if ($created !== null) {
                    Mirror::storeTreatment((int)$user['id'], $created);
                }
                return;

            case 'activity':
                $created = self::created($client->createActivity([
                    'eventType' => trim((string)($post['activityType'] ?? 'Exercise')),
                    'duration' => (($post['duration'] ?? '') !== '') ? (int)$post['duration'] : null,
                    'notes' => trim((string)($post['notes'] ?? '')),
                ], (string)$user['username']));
                if ($created !== null) {
                    Mirror::storeTreatment((int)$user['id'], $created);
                }
                return;

            default:
                throw new \InvalidArgumentException(Lang::t('val.unknownKind'));
        }
    }

    /**
     * Update an existing treatment the caller owns.
     *
     * @return array<string,mixed> the stored record
     */
    public static function save(array $user, array $post, array $existing): array
    {
        $eventType = (string)($post['eventType'] ?? $existing['eventType'] ?? 'Note');
        if (!TreatmentTypes::has($eventType)) {
            throw new \InvalidArgumentException(Lang::t('val.unsupportedEvent'));
        }

        $updated = TreatmentTypes::build($eventType, $post);
        // Identity and attribution are carried from the stored record, so an
        // edit can never hand a record to another user.
        $updated['_id'] = (string)$existing['_id'];
        $updated['enteredBy'] = (string)$existing['enteredBy'];
        $updated['created_at'] = (string)($existing['created_at'] ?? gmdate('c'));

        $saved = self::created(self::client($user)->updateTreatment($updated)) ?? $updated;
        Mirror::storeTreatment((int)$user['id'], $saved);

        return $saved;
    }

    /** Delete a treatment the caller owns. */
    public static function delete(array $user, string $id, array $existing): void
    {
        if (!self::owned($existing, $user)) {
            return;
        }
        self::client($user)->deleteTreatment($id);
        Mirror::forgetTreatment((int)$user['id'], $id);
    }

    /**
     * The event registry with translated labels, ready for the page scripts.
     */
    public static function typesJson(): string
    {
        $out = [];
        foreach (TreatmentTypes::types() as $name => $def) {
            $out[$name] = ['label' => Lang::t($def[0]), 'fields' => $def[1], 'group' => $def[2]];
        }
        return js_json($out);
    }

    /** Field definitions (label, unit, bounds) for the dynamic forms. */
    public static function fieldsJson(): string
    {
        $out = [];
        foreach (TreatmentTypes::fields() as $name => $def) {
            $out[$name] = [
                'kind' => $def[0],
                'label' => Lang::t($def[1]),
                'unit' => $def[2],
                'min' => $def[3],
                'max' => $def[4],
                'step' => $def[5],
            ];
        }
        return js_json($out);
    }

    /** Group headings used by the event-type selector. */
    public static function groupsJson(): string
    {
        $out = [];
        foreach (['bolus', 'monitor', 'temp', 'care'] as $group) {
            $out[$group] = Lang::t('group.' . $group);
        }
        return js_json($out);
    }

    /**
     * Render the treatment list as display rows.
     *
     * Shaping happens here so the template only prints already-prepared text
     * (and the default escaping applies to every cell).
     *
     * @param array<int,array<string,mixed>> $treatments
     * @return array<int,array<string,mixed>>
     */
    public static function displayRows(array $treatments): array
    {
        $rows = [];
        foreach ($treatments as $t) {
            $rows[] = [
                'time' => date('Y-m-d H:i', strtotime((string)($t['created_at'] ?? 'now'))),
                'type' => (string)($t['eventType'] ?? '-'),
                'glucose' => (string)($t['glucose'] ?? '-'),
                'carbs' => (string)($t['carbs'] ?? '-'),
                'insulin' => (string)($t['insulin'] ?? '-'),
                'extra' => self::extra($t),
                'notes' => (string)($t['notes'] ?? ''),
                'id' => (string)($t['_id'] ?? ''),
                'editUrl' => '/treatments?edit=' . rawurlencode((string)($t['_id'] ?? '')),
            ];
        }
        return $rows;
    }

    /** The "extra" column: duration, temp basal, percent and temp targets. */
    private static function extra(array $t): string
    {
        $extra = [];
        if (isset($t['duration'])) {
            $extra[] = Lang::t('col.minutes', ['n' => (int)$t['duration']]);
        }
        if (isset($t['absolute'])) {
            $extra[] = Lang::t('col.unitPerHour', ['v' => (float)$t['absolute']]);
        }
        if (isset($t['percent'])) {
            $extra[] = (int)$t['percent'] . '%';
        }
        if (isset($t['targetLow']) || isset($t['targetBottom'])) {
            $extra[] = Lang::t('col.target', [
                'low' => (int)($t['targetBottom'] ?? $t['targetLow'] ?? 0),
                'high' => (int)($t['targetTop'] ?? $t['targetHigh'] ?? 0),
            ]);
        }
        return $extra ? implode(' / ', $extra) : '-';
    }
}
