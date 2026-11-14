<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Self-check for the local mirror (panel/lib/Mirror.php) and the schema added
 * for the scheduled sync. Runs standalone: php panel/tests/mirror-test.php
 */

$dbFile = sys_get_temp_dir() . '/panel-mirror-test-' . getmypid() . '.db';
@unlink($dbFile);
define('PANEL_DB_FILE', $dbFile);

require __DIR__ . '/../vendor/autoload.php';

use app\service\ProfileService;
use app\service\ReadingService;
use app\service\TreatmentService;
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

$statsReadings = Mirror::statsReadings(1, $now - 3600000, $now + 60000);
check('statsReadings keeps only ts and mmol', count($statsReadings) === 3 && array_keys($statsReadings[0]) === ['ts', 'v']);

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
    1, $id, $start, json_encode(['_id' => $id, 'startDate' => $start, 'store' => ['alice' => ['dia' => 3, 'timezone' => 'Asia/Shanghai']]]),
];
$pIns = $pdo->prepare(
    'INSERT INTO panel_profiles (backend_id, ns_id, start_date, payload) VALUES (?, ?, ?, ?)'
);
$pIns->execute($profile('p1', '2026-01-01T00:00:00.000Z'));
$pIns->execute($profile('p2', '2026-06-01T00:00:00.000Z'));
check('profileRecord picks the newest record', Mirror::profileRecord(1)['_id'] === 'p2');
check('profileRecord is backend scoped', Mirror::profileRecord(2) === null);

// Timezone resolution: the profile store entry is the panel's single source
// for every wall-clock interpretation (historical days, hand-entered
// readings, treatment lists).
$tz = ProfileService::timezone(['username' => 'alice', 'backend_id' => 1]);
check('profile timezone resolves from the mirror', $tz->getName() === 'Asia/Shanghai');
check('unknown profile falls back to UTC', ProfileService::timezone(['username' => 'nobody', 'backend_id' => 1])->getName() === 'UTC');
check('missing backend falls back to UTC', ProfileService::timezone(['username' => 'alice', 'backend_id' => 0])->getName() === 'UTC');

// dayWindow must resolve the user's profile timezone (UTC+8), not the host
// clock: a regression here silently replayed historical days in UTC.
[$dayStartMs, $dayEndMs] = ReadingService::dayWindow('2026-11-14', ['username' => 'alice', 'backend_id' => 1]);
check('dayWindow honours the profile timezone', gmdate('Y-m-d H:i', (int)($dayStartMs / 1000)) === '2026-11-13 16:00');
check('dayWindow covers exactly one local day', gmdate('Y-m-d H:i', (int)($dayEndMs / 1000)) === '2026-11-14 16:00');
[$utcStartMs, ] = ReadingService::dayWindow('2026-11-14', ['username' => 'nobody', 'backend_id' => 1]);
check('dayWindow falls back to UTC without a profile', gmdate('Y-m-d H:i', (int)($utcStartMs / 1000)) === '2026-11-14 00:00');

// Hand-entered glucose times are interpreted in the profile timezone and
// re-emitted with an explicit offset instead of the server clock's zone.
$norm = new \ReflectionMethod(TreatmentService::class, 'withProfileOffset');
$norm->setAccessible(true);
$sh = new \DateTimeZone('Asia/Shanghai');
check('local timestamp gains the profile offset', $norm->invoke(null, '2026-11-14 10:00', $sh) === '2026-11-14T10:00:00+08:00');
check('explicit offset input is kept', $norm->invoke(null, '2026-11-14T10:00:00+05:00', $sh) === '2026-11-14T10:00:00+05:00');
check('garbage input passes through for the client to reject', $norm->invoke(null, 'not-a-date', $sh) === 'not-a-date');

// The treatments list renders in the profile timezone, matching the
// dashboard's browser-local rendering of the same records.
$rows = TreatmentService::displayRows(
    [['_id' => 't3', 'created_at' => '2026-11-14T02:00:00+00:00', 'eventType' => 'Note']],
    new \DateTimeZone('Asia/Shanghai')
);
check('displayRows renders in the profile timezone', $rows[0]['time'] === '2026-11-14 10:00');

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

// Secrets are encrypted with the in-database seed: a stolen panel.db is
// useless without it, and it never leaves the database.
$blob = Db::encrypt('shiro-secret');
check('encrypt produces ciphertext', $blob !== 'shiro-secret' && base64_decode($blob, true) !== false);
check('decrypt round-trips', Db::decrypt($blob) === 'shiro-secret');
check('two encryptions differ (random IV)', Db::encrypt('shiro-secret') !== $blob);
check('key seed lives in panel_settings', strlen((string)Db::setting('key_seed')) === 64);
check('no key file beside the database', !is_file($dbFile . '.key'));
clearstatcache();
check('database file is private', (fileperms($dbFile) & 0777) === 0600);

// M5: fixed-window throttling.
check('rateLimit allows attempts inside the window', Db::rateLimit('test', 'k', 2, 60));
check('rateLimit allows the limit itself', Db::rateLimit('test', 'k', 2, 60));
check('rateLimit blocks past the limit', !Db::rateLimit('test', 'k', 2, 60));
check('rateLimit keeps identities apart', Db::rateLimit('test', 'other', 2, 60));

@unlink($dbFile);
@unlink($dbFile . '-wal');
@unlink($dbFile . '-shm');

echo $failures === 0 ? "\nOK — {$checks} checks passed\n" : "\n{$failures} of {$checks} checks failed\n";
exit($failures === 0 ? 0 : 1);
