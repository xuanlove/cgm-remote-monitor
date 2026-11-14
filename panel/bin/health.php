<?php
declare(strict_types=1);

/**
 * Cron/monitoring-compatible shim for the freshness probe.
 *
 *   php /path/to/panel/bin/health.php --stale=600
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../vendor/autoload.php';

$opts = getopt('', ['stale::', 'help']);

if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php bin/health.php [--stale=600]\n");
    exit(0);
}

exit((new \app\service\HealthRunner())->run($opts));
