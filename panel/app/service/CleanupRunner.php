<?php
declare(strict_types=1);

namespace app\service;

use NightPanel\Db;

/**
 * Retention housekeeping for the mirror. Shared by `php think cleanup` and the
 * cron-compatible `bin/cleanup.php` shim.
 */
final class CleanupRunner
{
    /**
     * @param array<string,mixed> $options days|treatments-days|logs-days|alerts-days|vacuum
     */
    public function run(array $options, ?callable $log = null): int
    {
        $log = $log ?? static function (string $line): void {
            fwrite(STDOUT, $line . "\n");
        };

        // Reports cover up to 90 days; keeping 120 leaves room for the sync
        // overlap window without pruning data a 90-day report still needs.
        $entryDays = isset($options['days']) ? max(1, (int)$options['days']) : 120;
        $treatmentDays = isset($options['treatments-days']) ? max(1, (int)$options['treatments-days']) : 365;
        $logDays = isset($options['logs-days']) ? max(1, (int)$options['logs-days']) : 7;
        $alertDays = isset($options['alerts-days']) ? max(1, (int)$options['alerts-days']) : 30;

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

        $vacuum = !empty($options['vacuum']);
        if ($vacuum) {
            $pdo->exec('VACUUM');
        }

        $log(sprintf(
            'cleanup: entries>%dd, treatments>%dd, logs>%dd, alerts>%dd%s',
            $entryDays,
            $treatmentDays,
            $logDays,
            $alertDays,
            $vacuum ? ', vacuumed' : ''
        ));

        return 0;
    }
}
