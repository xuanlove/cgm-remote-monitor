<?php
declare(strict_types=1);

/**
 * Cron-compatible shim for the retention job.
 *
 *  30 3 * * * php /path/to/panel/bin/cleanup.php
 *  45 3 * * 0 php /path/to/panel/bin/cleanup.php --vacuum
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../vendor/autoload.php';

$opts = getopt('', ['days::', 'treatments-days::', 'logs-days::', 'alerts-days::', 'vacuum', 'help']);

if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php bin/cleanup.php [--days=120] [--treatments-days=365] [--logs-days=7] [--alerts-days=30] [--vacuum]\n");
    exit(0);
}

exit((new \app\service\CleanupRunner())->run($opts));
