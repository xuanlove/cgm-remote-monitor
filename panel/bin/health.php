<?php
declare(strict_types=1);

/**
 * Freshness probe for monitoring: prints each user's last successful sync and
 * exits non-zero when the CGM stream is older than the allowed age.
 *
 *   php /path/to/panel/bin/health.php --stale=600
 *
 * Relies on the database only, so it also works while Nightscout is down.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../lib/Db.php';

use NightPanel\Db;

$opts = getopt('', ['stale::', 'help']);

if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php bin/health.php [--stale=600]\n");
    exit(0);
}

$maxAge = isset($opts['stale']) ? max(1, (int)$opts['stale']) : 600;

$sql = "SELECT u.username, s.stream, s.last_ok_at, s.last_error, s.error_count
          FROM panel_users u
          LEFT JOIN panel_sync_state s ON s.user_id = u.id AND s.stream = 'entries:sgv'
         WHERE u.role = 'user'
         ORDER BY u.id";
$rows = Db::pdo()->query($sql)->fetchAll();

$failed = false;
printf("%-16s %-20s %-10s %s\n", 'user', 'last_sync_sgv', 'age', 'status');
foreach ($rows as $row) {
    $last = (string)($row['last_ok_at'] ?? '');
    $age = $last === '' ? null : max(0, time() - (int)strtotime($last));
    $status = 'ok';
    if ($age === null) {
        $status = 'never synced';
    } elseif ($age > $maxAge) {
        $status = 'stale';
    }
    if (!empty($row['last_error'])) {
        $status .= ' · ' . $row['last_error'];
    }
    if ($status !== 'ok') {
        $failed = true;
    }
    printf(
        "%-16s %-20s %-10s %s\n",
        (string)$row['username'],
        $last === '' ? '-' : $last,
        $age === null ? '-' : $age . 's',
        $status
    );
}

exit($failed ? 1 : 0);
