<?php
declare(strict_types=1);

namespace app\service;

use NightPanel\Db;

/**
 * Freshness probe for monitoring: prints each user's last successful sync and
 * fails when the CGM stream is older than the allowed age. Relies on the
 * database only, so it also works while Nightscout is down.
 */
final class HealthRunner
{
    /**
     * @param array<string,mixed> $options stale
     * @return int 0 when everything is current, 1 otherwise
     */
    public function run(array $options, ?callable $log = null): int
    {
        $log = $log ?? static function (string $line): void {
            fwrite(STDOUT, $line . "\n");
        };

        $maxAge = isset($options['stale']) ? max(1, (int)$options['stale']) : 600;

        $sql = "SELECT u.username, s.stream, s.last_ok_at, s.last_error, s.error_count
                  FROM panel_users u
                  LEFT JOIN panel_sync_state s ON s.user_id = u.id AND s.stream = 'entries:sgv'
                 WHERE u.role = 'user'
                 ORDER BY u.id";
        $rows = Db::pdo()->query($sql)->fetchAll();

        $failed = false;
        $log(sprintf('%-16s %-20s %-10s %s', 'user', 'last_sync_sgv', 'age', 'status'));

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
            $log(sprintf(
                '%-16s %-20s %-10s %s',
                (string)$row['username'],
                $last === '' ? '-' : $last,
                $age === null ? '-' : $age . 's',
                $status
            ));
        }

        return $failed ? 1 : 0;
    }
}
