<?php
declare(strict_types=1);

/**
 * Cron-compatible shim: existing crontab entries keep calling this path.
 *
 *   * * * * *   php /path/to/panel/bin/sync.php
 *   *\/15 * * * * php /path/to/panel/bin/sync.php --profiles
 *   5 3 * * *   php /path/to/panel/bin/sync.php --full --days=30
 *
 * `php panel/think sync` runs the same code through the console command.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../vendor/autoload.php';

$opts = getopt('', ['user::', 'full', 'days::', 'profiles', 'help']);

if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php bin/sync.php [--user=<name>] [--full] [--days=N] [--profiles]\n");
    exit(0);
}

exit((new \app\service\SyncRunner())->run($opts));
