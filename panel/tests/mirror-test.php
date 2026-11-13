<?php
declare(strict_types=1);

/**
 * Self-check for the local mirror (panel/lib/Mirror.php) and the schema added
 * for the scheduled sync. Runs standalone: php panel/tests/mirror-test.php
 */

$dbFile = sys_get_temp_dir() . '/panel-mirror-test-' . getmypid() . '.db';
@unlink($dbFile);
putenv('PANEL_DB=' . $dbFile);

require __DIR__ . '/../lib/Db.php';
require __DIR__ . '/../lib/Mirror.php';

use NightPanel\Db;
use NightPanel\Mirror;

$failures = 0;
$checks = 0;
function check(string $name, bool $ok): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  PASS  {$name}\n";
    } else {
        $failures++;
        echo "  FAIL  {$name}\n";
    }
}

$pdo = Db::pdo();
$now = (int)round(microtime(true) * 1000);

$pdo->prepare('INSERT INTO panel_users (id, username, password_hash) VALUES (1, "alice", "x")')->execute();

$entry = static fn(string $id, int $ts, float $sgv, ?string $dir, ?float $delta): array => [
    'user_id' => 1,
    'ns_id' => $id,
    'type' => 'sgv',
    'device' => 'alice',
    'ts' => $ts,
    'sgv' => $sgv,
    'direction' => $dir,
    'delta' => $delta,
    'raw' => json_encode([
        '_id' => $id,
        'type' => 'sgv',
        'date' => $ts,
        'dateString' => gmdate('c', (int)($ts / 1000)),
        'sgv' => (int)$sgv,
        'direction' => $dir,
        'delta' => $delta,
        'device' => 'alice',
    ]),
];

$insert = $pdo->prepare(
    'INSERT INTO panel_entries (user_id, ns_id, type, device, ts, sgv, direction, delta, raw)
     VALUES (:user_id, :ns_id, :type, :device, :ts, :sgv, :direction, :delta, :raw)'
);
$insert->execute($entry('e1', $now - 600000, 90, 'Flat', 0));
$insert->execute($entry('e2', $now - 300000, 120, 'SingleUp', 6));
$insert->execute($entry('e3', $now, 153, 'FortyFiveDown', -4));
$insert->execute($entry('e-old', $now - 3 * 86400000, 200, null, null));
$insert->execute([
    'user_id' => 1, 'ns_id' => 'm1', 'type' => 'mbg', 'device' => 'alice',
    'ts' => $now, 'sgv' => 140, 'direction' => null, 'delta' => null,
    'raw' => json_encode(['_id' => 'm1', 'type' => 'mbg', 'mbg' => 140, 'date' => $now]),
]);

$readings = Mirror::readings(1, $now - 3600000, $now + 60000);
check('readings only cover the requested window', count($readings) === 3);
check('readings are sorted oldest first', $readings[0]['ts'] < $readings[2]['ts']);
check('sgv is converted to mmol/L', abs($readings[2]['v'] - 8.5) < 0.01);
check('direction and delta survive', $readings[1]['direction'] === 'SingleUp' && $readings[1]['delta'] === 6.0);
check('dateString is generated when absent', str_starts_with($readings[0]['dateString'], '20'));

$raw = Mirror::rawEntries(1, 'sgv', $now - 3600000, $now + 60000, 2);
check('rawEntries honours the newest-N limit', count($raw) === 2);
check('rawEntries keeps uploader fields', $raw[1]['_id'] === 'e3' && $raw[1]['device'] === 'alice');
$mbg = Mirror::rawEntries(1, 'mbg', $now - 3600000, $now + 60000);
check('rawEntries filters by type', count($mbg) === 1 && $mbg[0]['mbg'] === 140);

$treat = static fn(string $id, string $at, string $type): array => [
    1, $id, $at, $type, json_encode(['_id' => $id, 'created_at' => $at, 'eventType' => $type, 'enteredBy' => 'alice']),
];
$ins = $pdo->prepare(
    'INSERT INTO panel_treatments (user_id, ns_id, created_at, event_type, payload) VALUES (?, ?, ?, ?, ?)'
);
$ins->execute($treat('t1', gmdate('c', (int)($now / 1000) - 3600), 'Carbs'));
$ins->execute($treat('t2', gmdate('c', (int)($now / 1000)), 'Insulin'));

$recent = Mirror::recentTreatments(1, 10);
check('recentTreatments is newest first', $recent[0]['_id'] === 't2');
check('treatmentsBetween covers the range', count(Mirror::treatmentsBetween(1, $now - 7200000, $now + 60000)) === 2);
check('treatmentById returns the payload', Mirror::treatmentById(1, 't1')['eventType'] === 'Carbs');
check('treatmentById is user scoped', Mirror::treatmentById(2, 't1') === null);

$pdo->prepare(
    'INSERT INTO panel_devicestatus (user_id, ns_id, ts, payload) VALUES (1, "d1", ?, ?)'
)->execute([$now, json_encode(['_id' => 'd1', 'iob' => ['iob' => 1.2]])]);
check('latestDeviceStatus decodes the payload', abs(Mirror::latestDeviceStatus(1)['iob']['iob'] - 1.2) < 0.001);

$profile = static fn(string $id, string $start): array => [
    $id, $start, json_encode(['_id' => $id, 'startDate' => $start, 'store' => ['alice' => ['dia' => 3]]]),
];
$pIns = $pdo->prepare('INSERT INTO panel_profiles (ns_id, start_date, payload) VALUES (?, ?, ?)');
$pIns->execute($profile('p1', '2026-01-01T00:00:00.000Z'));
$pIns->execute($profile('p2', '2026-06-01T00:00:00.000Z'));
check('profileRecord picks the newest record', Mirror::profileRecord()['_id'] === 'p2');

$pdo->prepare(
    'INSERT INTO panel_sync_state (user_id, stream, cursor_ms, last_ok_at) VALUES (1, "entries:sgv", ?, ?)'
)->execute([$now, gmdate('c')]);
$fresh = Mirror::freshness(1);
check('freshness reports the newest reading age', $fresh['age_sec'] !== null && $fresh['age_sec'] < 120);
check('freshness reports the last sync time', $fresh['last_ok_at'] !== null);

$pdo->prepare(
    'INSERT INTO panel_alert_events (user_id, ts, kind, value_mmol, limit_mmol) VALUES (1, ?, "low", 3.2, 3.9)'
)->execute([$now]);
$events = Mirror::undeliveredAlerts(1, $now - 60000);
check('undeliveredAlerts returns recent events', count($events) === 1 && $events[0]['kind'] === 'low');
check('ackAlerts marks them delivered', Mirror::ackAlerts(1, [$events[0]['id']]) === 1);
check('acked events disappear from the feed', Mirror::undeliveredAlerts(1, $now - 60000) === []);
check('ackAlerts ignores foreign ids', Mirror::ackAlerts(2, [1]) === 0);

check('syncState lists the user streams', count(Mirror::syncState(1)) === 1);

@unlink($dbFile);
@unlink($dbFile . '-wal');
@unlink($dbFile . '-shm');

echo $failures === 0 ? "\nOK — {$checks} checks passed\n" : "\n{$failures} of {$checks} checks failed\n";
exit($failures === 0 ? 0 : 1);
