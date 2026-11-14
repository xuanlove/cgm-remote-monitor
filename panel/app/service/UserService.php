<?php
declare(strict_types=1);

namespace app\service;

use NightPanel\Backends;
use NightPanel\Db;
use NightPanel\Lang;
use NightPanel\NsException;

/**
 * Panel accounts: lookup, validation, registration and deletion.
 */
final class UserService
{
    /** @return array<string,mixed>|null */
    public static function find(string $username): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM panel_users WHERE username = ? COLLATE NOCASE');
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function validUsername(string $username): bool
    {
        return (bool)preg_match('/^[a-z0-9][a-z0-9_-]{2,23}$/', $username);
    }

    /**
     * Validate and create an account on the chosen backend, provisioning the
     * Nightscout side first.
     *
     * @return array{0:?string,1:?array{username:string,backendName:string,nsUrl:string,token:string}} error and success payload
     */
    public static function register(string $username, string $password, int $backendId): array
    {
        $username = strtolower(trim($username));

        if (!self::validUsername($username)) {
            return [Lang::t('val.usernameFormat'), null];
        }
        if (strlen($password) < 8) {
            return [Lang::t('val.passwordLength'), null];
        }
        if (self::find($username) !== null) {
            return [Lang::t('val.usernameTaken'), null];
        }

        $backend = null;
        foreach (Backends::enabled() as $row) {
            if ((int)$row['id'] === $backendId) {
                $backend = $row;
                break;
            }
        }
        if ($backend === null) {
            return [Lang::t('val.backendUnavailable'), null];
        }

        $client = Backends::clientFor($backend);
        try {
            $provisioned = UserProvisioner::provision($username, $client);
        } catch (NsException $e) {
            return [$e->getMessage(), null];
        }

        $stmt = Db::pdo()->prepare(
            'INSERT INTO panel_users (username, password_hash, role, backend_id, ns_subject_id, ns_role_name, ns_access_token_enc)
             VALUES (?, ?, "user", ?, ?, ?, ?)'
        );

        try {
            $stmt->execute([
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $backendId,
                $provisioned['subjectId'],
                $provisioned['roleName'],
                Db::encrypt($provisioned['token']),
            ]);
        } catch (\PDOException $e) {
            // Two registrations can pass find() at once; the unique index picks
            // a winner. Undo the loser's Nightscout subject and role so no
            // orphan grant is left behind.
            UserProvisioner::rollback($provisioned, $client);
            return [Lang::t('val.usernameTaken'), null];
        }

        return [null, [
            'username' => $username,
            'backendName' => (string)$backend['name'],
            'nsUrl' => (string)$backend['url'],
            'token' => $provisioned['token'],
        ]];
    }

    /**
     * Delete a non-admin account together with its Nightscout identity and
     * every mirrored row. Mirror tables predate foreign keys, so the cascade is
     * explicit.
     */
    public static function delete(int $id): bool
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM panel_users WHERE id = ? AND role != "admin"');
        $stmt->execute([$id]);
        $victim = $stmt->fetch();
        if ($victim === false) {
            return false;
        }

        UserProvisioner::revoke($victim);

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            foreach ([
                'panel_entries',
                'panel_treatments',
                'panel_devicestatus',
                'panel_sync_state',
                'panel_sync_log',
                'panel_alert_events',
                'panel_alerts',
            ] as $table) {
                $pdo->prepare('DELETE FROM ' . $table . ' WHERE user_id = ?')->execute([$id]);
            }
            $pdo->prepare('DELETE FROM panel_users WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return true;
    }
}
