<?php
declare(strict_types=1);

namespace app\service;

use NightPanel\Backends;
use NightPanel\NsException;
use NightPanel\NsClient;

/**
 * Creates (and removes) the Nightscout identity that backs a panel account.
 *
 * Order matters: the role and subject are created first, then the profile
 * store entry is written with the USER token. Nightscout merges a scoped PUT
 * into the live record, so two registrations racing here cannot overwrite each
 * other's entry the way an admin read-modify-write would.
 */
final class UserProvisioner
{
    /**
     * The caller picks the backend: registration passes the one the new user
     * chose, migration passes the target backend. Migration also hands over
     * the profile store entry carried over from the source backend, so the
     * rebuilt account keeps its treatment settings instead of starting from
     * the default template.
     *
     * @param array<string,mixed>|null $profileOverride
     * @return array{subjectId:string,roleName:string,token:string}
     */
    public static function provision(string $username, NsClient $client, ?array $profileOverride = null): array
    {
        $roleName = $username . '-profile';
        $permissions = [
            'api:profile:' . $username . ':read',
            'api:profile:' . $username . ':write',
            'api:profile:' . $username . ':create',
            // Scoped read: the token can pull its own CGM readings straight
            // from Nightscout, but never another device's.
            'api:entries:' . $username . ':read',
            'api:entries:create',
            'api:treatments:create',
            'api:activity:create',
            'api:devicestatus:create',
        ];

        try {
            $client->createRole($roleName, $permissions);
        } catch (NsException $e) {
            // Already there from a previous attempt: idempotent retry.
            if ($e->httpStatus() !== 400) {
                throw $e;
            }
        }

        $subject = $client->createSubject($username, [$roleName]);
        if (empty($subject['accessToken'])) {
            throw new NsException('Nightscout did not return an access token for the new subject.');
        }
        $token = (string)$subject['accessToken'];

        $record = $client->currentProfileRecord();
        $own = $profileOverride ?? self::defaultProfileTemplate();
        // A carried-over profile always overwrites: it is the source backend's
        // truth. The default template is only written when the entry is new,
        // so two registrations cannot clobber each other.
        $alreadyThere = $record !== null && isset($record['store'][$username]);

        if ($record === null) {
            // No profile record exists yet: a scoped create makes the first one.
            $client->requestOrThrow('POST', '/api/v1/profile.json', [
                'store' => [$username => $own],
                'defaultProfile' => $username,
                'startDate' => gmdate('c'),
            ], $token);
        } elseif (!$alreadyThere || $profileOverride !== null) {
            $client->requestOrThrow('PUT', '/api/v1/profile.json', [
                '_id' => (string)$record['_id'],
                'store' => [$username => $own],
            ], $token);
        }

        return [
            'subjectId' => (string)$subject['_id'],
            'roleName' => $roleName,
            'token' => $token,
        ];
    }

    /** Undo a provisioning run whose local insert failed. */
    public static function rollback(array $provisioned, NsClient $client): void
    {
        try {
            $client->deleteSubject((string)$provisioned['subjectId']);
        } catch (NsException $e) {
            // already gone, or Nightscout unreachable: nothing to undo
        }
        try {
            $client->deleteRoleByName((string)$provisioned['roleName']);
        } catch (NsException $e) {
            // same
        }
    }

    /** Remove the Nightscout side of a deleted account (its own backend). */
    public static function revoke(array $user): void
    {
        $client = Backends::clientFor((int)($user['backend_id'] ?? 0));
        if (!empty($user['ns_subject_id'])) {
            try {
                $client->deleteSubject((string)$user['ns_subject_id']);
            } catch (NsException $e) {
                // Nightscout may be down; keep deleting the local account.
            }
        }
        if (!empty($user['ns_role_name'])) {
            try {
                $client->deleteRoleByName((string)$user['ns_role_name']);
            } catch (NsException $e) {
                // same
            }
        }
    }

    /** @return array<string,mixed> */
    public static function defaultProfileTemplate(): array
    {
        return [
            'units' => 'mg/dl',
            'dia' => 3,
            'carbs_hr' => 30,
            'carbs_req_units' => 'g',
            'sens' => [[0, 50]],
            'target_low' => [[0, 80]],
            'target_high' => [[0, 130]],
            'basal' => [[0, 1]],
            'timezone' => 'Asia/Chongqing',
        ];
    }
}
