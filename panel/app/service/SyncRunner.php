<?php
declare(strict_types=1);

namespace app\service;

use NightPanel\Backends;
use NightPanel\Db;
use NightPanel\Sync;

/**
 * Nightscout -> local mirror synchroniser.
 *
 * Shared by `php think sync` and the cron-compatible `bin/sync.php` shim, so
 * both entry points keep identical locking and exit codes. Each panel user
 * syncs from the backend their account lives on.
 */
final class SyncRunner
{
    public const EXIT_OK = 0;
    public const EXIT_ERROR = 1;
    public const EXIT_LOCKED = 2;

    /**
     * @param array<string,mixed> $options user|full|days|profiles
     * @return int exit code
     */
    public function run(array $options, ?callable $log = null): int
    {
        $log = $log ?? static function (string $line): void {
            fwrite(STDOUT, '[' . gmdate('c') . '] ' . $line . "\n");
        };

        $lockFile = Db::file() . '.sync.lock';
        $lockDir = dirname($lockFile);
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0770, true);
        }

        $lock = fopen($lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            fwrite(STDERR, "sync: another run is already in progress\n");
            return self::EXIT_LOCKED;
        }

        $full = !empty($options['full']);
        $days = isset($options['days']) && $options['days'] !== '' && $options['days'] !== null
            ? max(1, (int)$options['days'])
            : ($full ? 30 : 2);
        $user = $options['user'] ?? null;
        $onlyProfiles = !empty($options['profiles']) && ($user === null || $user === false || $user === '');
        $errors = 0;

        if (!empty($options['profiles'])) {
            foreach (Backends::all() as $backend) {
                try {
                    $count = (new Sync(Backends::clientFor($backend), (int)$backend['id'], $log))->profiles();
                    $log('profiles[' . $backend['name'] . ']: ' . $count . ' records');
                } catch (\Throwable $e) {
                    fwrite(STDERR, 'profiles[' . $backend['name'] . '] failed: ' . $e->getMessage() . "\n");
                    $errors++;
                }
            }
        }

        if (!$onlyProfiles) {
            $sql = 'SELECT * FROM panel_users WHERE role = \'user\'';
            $params = [];
            if (is_string($user) && $user !== '') {
                $sql .= ' AND username = ?';
                $params[] = $user;
            }
            $stmt = Db::pdo()->prepare($sql . ' ORDER BY id');
            $stmt->execute($params);
            $users = $stmt->fetchAll();

            if ($users === []) {
                $log('no panel users to sync');
            }

            foreach ($users as $row) {
                try {
                    $client = Backends::clientFor((int)$row['backend_id']);
                } catch (\Throwable $e) {
                    fwrite(STDERR, $row['username'] . ' error: backend unavailable: ' . $e->getMessage() . "\n");
                    $errors++;
                    continue;
                }
                $summary = (new Sync($client, (int)$row['backend_id'], $log))
                    ->user($row, ['entries', 'treatments', 'devicestatus'], $full, $days);
                $parts = [];
                foreach ($summary as $key => $value) {
                    if ($key === 'user' || $key === 'errors') {
                        continue;
                    }
                    $parts[] = $key . '=' . $value;
                }
                $log($summary['user'] . ': ' . implode(' ', $parts));
                foreach ($summary['errors'] as $error) {
                    fwrite(STDERR, $summary['user'] . ' error: ' . $error . "\n");
                    $errors++;
                }
            }
        }

        flock($lock, LOCK_UN);
        fclose($lock);

        return $errors === 0 ? self::EXIT_OK : self::EXIT_ERROR;
    }
}
