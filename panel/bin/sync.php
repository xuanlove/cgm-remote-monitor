<?php
declare(strict_types=1);

/**
 * Nightscout -> local mirror synchroniser.
 *
 * Cron entry points:
 *   * * * * *   php /path/to/panel/bin/sync.php
 *   *\/15 * * * * php /path/to/panel/bin/sync.php --profiles
 *   5 3 * * *   php /path/to/panel/bin/sync.php --full --days=30
 *
 * Only one instance runs at a time; a second invocation exits immediately.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../lib/Db.php';
require __DIR__ . '/../lib/Lang.php';
require __DIR__ . '/../lib/helpers.php';
require __DIR__ . '/../lib/NsClient.php';
require __DIR__ . '/../lib/Config.php';
require __DIR__ . '/../lib/Alerts.php';
require __DIR__ . '/../lib/Sync.php';

use NightPanel\Config;
use NightPanel\Db;
use NightPanel\Sync;

$opts = getopt('', ['user::', 'full', 'days::', 'profiles', 'help']);

if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php bin/sync.php [--user=<name>] [--full] [--days=N] [--profiles]\n");
    exit(0);
}

$dataDir = __DIR__ . '/../data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0770, true);
}

$lock = fopen($dataDir . '/sync.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "sync: another run is already in progress\n");
    exit(2);
}

$logger = static function (string $line): void {
    fwrite(STDOUT, '[' . gmdate('c') . '] ' . $line . "\n");
};

try {
    $sync = new Sync(Config::client(), $logger);
} catch (\Throwable $e) {
    fwrite(STDERR, 'sync: ' . $e->getMessage() . "\n");
    exit(1);
}

$full = isset($opts['full']);
$days = isset($opts['days']) ? max(1, (int)$opts['days']) : ($full ? 30 : 2);
$onlyProfiles = isset($opts['profiles']) && !isset($opts['user']);
$errors = 0;

if (isset($opts['profiles'])) {
    try {
        $count = $sync->profiles();
        $logger('profiles: ' . $count . ' records');
    } catch (\Throwable $e) {
        fwrite(STDERR, 'profiles failed: ' . $e->getMessage() . "\n");
        $errors++;
    }
}

if (!$onlyProfiles) {
    $sql = 'SELECT * FROM panel_users WHERE role = \'user\'';
    $params = [];
    if (isset($opts['user']) && $opts['user'] !== false && $opts['user'] !== '') {
        $sql .= ' AND username = ?';
        $params[] = (string)$opts['user'];
    }
    $stmt = Db::pdo()->prepare($sql . ' ORDER BY id');
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    if ($users === []) {
        $logger('no panel users to sync');
    }

    foreach ($users as $user) {
        $summary = $sync->user($user, ['entries', 'treatments', 'devicestatus'], $full, $days);
        $parts = [];
        foreach ($summary as $key => $value) {
            if ($key === 'user' || $key === 'errors') {
                continue;
            }
            $parts[] = $key . '=' . $value;
        }
        $logger($summary['user'] . ': ' . implode(' ', $parts));
        foreach ($summary['errors'] as $error) {
            fwrite(STDERR, $summary['user'] . ' error: ' . $error . "\n");
            $errors++;
        }
    }
}

flock($lock, LOCK_UN);
fclose($lock);

exit($errors === 0 ? 0 : 1);
