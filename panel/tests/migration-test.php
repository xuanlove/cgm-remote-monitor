<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Self-check for the pure helpers inside BackendMigration: cursor maths and
 * record scrubbing. The network half (copy loops, provisioning) is exercised
 * by the manual smoke test; this keeps the risky pagination logic covered
 * without a live Nightscout.
 */

require __DIR__ . '/../vendor/autoload.php';

use app\service\BackendMigration;

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

/** Call a private static helper. */
function invoke(string $method, array $args): mixed
{
    $ref = new ReflectionMethod(BackendMigration::class, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs(null, $args);
}

echo "Migration helpers\n";

// --- portable(): Mongo's own keys must go, payload keys must stay -------------
$batch = [
    ['_id' => 'a', '_v' => 2, 'type' => 'sgv', 'sgv' => 120, 'device' => 'alice'],
    ['_id' => 'b', 'type' => 'mbg', 'mbg' => 95, '_something' => 1, 'created_at' => '2026-01-01T00:00:00.000Z'],
];
$out = invoke('portable', [$batch]);
check('portable strips _id and _v', !isset($out[0]['_id']) && !isset($out[0]['_v']));
check('portable keeps payload keys', $out[0]['sgv'] === 120 && $out[1]['created_at'] === '2026-01-01T00:00:00.000Z');
check('portable keeps the record count', count($out) === 2);
check('portable never reorders keys into _ again',
    array_keys($out[1]) === ['type', 'mbg', 'created_at']);

// --- oldestMs(): entries cursor ----------------------------------------------
$entries = [
    ['date' => 1767225600000],
    ['date' => 1767139200000, 'dateString' => '2026-01-01T00:00:00.000Z'],
    ['dateString' => '2025-12-01T00:00:00.000Z'], // no ms epoch: parsed from the string
];
check('oldestMs picks the smallest date', invoke('oldestMs', [$entries]) === (int)(strtotime('2025-12-01T00:00:00.000Z') * 1000));
check('oldestMs falls back to dateString',
    invoke('oldestMs', [ [ ['dateString' => '2025-06-01T00:00:00.000Z'] ] ]) === (int)(strtotime('2025-06-01T00:00:00.000Z') * 1000));
check('oldestMs is 0 when nothing parses', invoke('oldestMs', [ [[]] ]) === 0);

// --- oldestString(): treatments/devicestatus cursor ---------------------------
$treatments = [
    ['created_at' => '2026-03-01T10:00:00.000Z'],
    ['created_at' => '2026-02-01T10:00:00.000Z'],
    ['created_at' => '2026-02-01T09:00:00.000Z'],
];
check('oldestString returns the raw earliest value',
    invoke('oldestString', [$treatments, 'created_at']) === '2026-02-01T09:00:00.000Z');
check('oldestString ignores records without the field',
    invoke('oldestString', [array_merge($treatments, [['eventType' => 'x']]), 'created_at']) === '2026-02-01T09:00:00.000Z');
check('oldestString is empty when the field is missing everywhere',
    invoke('oldestString', [ [['a' => 1]], 'created_at' ]) === '');

echo $failures === 0 ? "\nOK — {$checks} checks passed\n" : "\n{$failures} of {$checks} checks failed\n";
exit($failures === 0 ? 0 : 1);
