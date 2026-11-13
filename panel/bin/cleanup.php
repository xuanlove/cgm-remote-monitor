<?php
declare(strict_types=1);

/**
 * Retention housekeeping for the mirror.
 *
 *  30 3 * * * php /path/to/panel/bin/cleanup.php
 *  45 3 * * 0 php /path/to/panel/bin/cleanup.php --vacuum
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require __DIR__ . '/../lib/Db.php';

use NightPanel\Db;

$opts = getopt('', ['days::', 'treatments-days::', 'logs-days::', 'alerts-days::', 'vacuum', 'help']);

if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php bin/cleanup.php [--days=120] [--treatments-days=365] [--logs-days=7] [--alerts-days=30] [--vacuum]\n");
    exit(0);
}

// Reports cover up to 90 days; keeping 120 leaves room for the sync overlap
// window without pruning data a 90-day report still needs.
$entryDays = isset($opts['days']) ? max(1, (int)$opts['days']) : 120;
$treatmentDays = isset($opts['treatments-days']) ? max(1, (int)$opts['treatments-days']) : 365;
$logDays = isset($opts['logs-days']) ? max(1, (int)$opts['logs-days']) : 7;
$alertDays = isset($opts['alerts-days']) ? max(1, (int)$opts['alerts-days']) : 30;

$pdo = Db::pdo();
$now = time();

$pdo->prepare('DELETE FROM panel_entries WHERE ts < ?')
    ->execute([($now - $entryDays * 86400) * 1000]);

$pdo->prepare('DELETE FROM panel_treatments WHERE created_at < ?')
    ->execute([gmdate('c', $now - $treatmentDays * 86400)]);

$pdo->prepare('DELETE FROM panel_sync_log WHERE started_at < ?')
    ->execute([gmdate('c', $now - $logDays * 86400)]);

$pdo->prepare('DELETE FROM panel_alert_events WHERE ts < ?')
    ->execute([($now - $alertDays * 86400) * 1000]);

// The per-day report cache is obsolete now that reports read the mirror.
$pdo->exec('DROP TABLE IF EXISTS panel_day_cache');

// Keep the snapshot bounded even if a sync failed halfway through pruning.
$pdo->exec(
    'DELETE FROM panel_profiles WHERE ns_id NOT IN (
        SELECT ns_id FROM panel_profiles ORDER BY start_date DESC, fetched_at DESC LIMIT 30
    )'
);

$pdo->exec('PRAGMA optimize');

if (isset($opts['vacuum'])) {
    $pdo->exec('VACUUM');
}

fwrite(STDOUT, sprintf(
    "cleanup: entries>%dd, treatments>%dd, logs>%dd, alerts>%dd%s\n",
    $entryDays,
    $treatmentDays,
    $logDays,
    $alertDays,
    isset($opts['vacuum']) ? ', vacuumed' : ''
));
