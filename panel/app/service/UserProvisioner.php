<?php
declare(strict_types=1);

namespace app\service;

use NightPanel\NsConfig;
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
     * @return array{subjectId:string,roleName:string,token:string}
     */
    public static function provision(string $username): array
    {
        $client = NsConfig::client();

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
        $own = self::defaultProfileTemplate();
        $alreadyThere = $record !== null && isset($record['store'][$username]);

        if ($record === null) {
            // No profile record exists yet: a scoped create makes the first one.
            $client->requestOrThrow('POST', '/api/v1/profile.json', [
                'store' => [$username => $own],
                'defaultProfile' => $username,
                'startDate' => gmdate('c'),
            ], $token);
        } elseif (!$alreadyThere) {
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
    public static function rollback(array $provisioned): void
    {
        $client = NsConfig::client();
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

    /** Remove the Nightscout side of a deleted account. */
    public static function revoke(array $user): void
    {
        $client = NsConfig::client();
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
