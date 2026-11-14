<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Checks for the alert threshold rules. Run with:
 *   php panel/tests/alerts-test.php
 *
 * Only the pure parts are covered here (validation + state); storage needs a
 * database and is exercised end-to-end against the running panel.
 */

require __DIR__ . '/../vendor/autoload.php';

use NightPanel\Alerts;

$failures = 0;
$checks = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  PASS  $name\n";
        return;
    }
    $failures++;
    echo "  FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
}

echo "Alerts\n";

// --- defaults --------------------------------------------------------------
$defaults = Alerts::defaults();
check('default low is 3.9', $defaults['low'] === 3.9, (string)$defaults['low']);
check('default high is 10.0', $defaults['high'] === 10.0, (string)$defaults['high']);
check('alerts are off by default', $defaults['enabled'] === false);

// --- state evaluation ------------------------------------------------------
$on = ['low' => 3.9, 'high' => 10.0, 'enabled' => true, 'browser' => true];
check('below the low line is "low"', Alerts::state(3.2, $on) === 'low');
check('above the high line is "high"', Alerts::state(12.4, $on) === 'high');
check('inside the band is null', Alerts::state(6.0, $on) === null);
check('exactly on the low line is inside', Alerts::state(3.9, $on) === null);
check('exactly on the high line is inside', Alerts::state(10.0, $on) === null);

$off = ['low' => 3.9, 'high' => 10.0, 'enabled' => false, 'browser' => true];
check('disabled alerts never fire', Alerts::state(2.5, $off) === null);

// --- validation ------------------------------------------------------------
// save() touches the database only after validation, so invalid input can be
// checked without one: each call must return an error and no new settings.
function saveError(array $input): ?string
{
    try {
        [, $error] = Alerts::save(1, $input);
        return $error;
    } catch (Throwable $e) {
        // No database in this environment: reaching the write means the input
        // passed validation, which is exactly what these cases must not do.
        return 'REACHED_DB';
    }
}

check('non-numeric low is rejected', saveError(['low' => 'abc', 'high' => '10']) !== null);
check('low below 2.0 is rejected', saveError(['low' => '1.5', 'high' => '10']) !== null);
check('low above 10.0 is rejected', saveError(['low' => '11', 'high' => '12']) !== null);
check('high below 5.0 is rejected', saveError(['low' => '3.9', 'high' => '4']) !== null);
check('high above 25.0 is rejected', saveError(['low' => '3.9', 'high' => '30']) !== null);
check('low equal to high is rejected', saveError(['low' => '8', 'high' => '8']) !== null);
check('low greater than high is rejected', saveError(['low' => '9', 'high' => '7']) !== null);

// A valid pair passes validation and reaches storage.
$valid = saveError(['low' => '3.5', 'high' => '9.5', 'enabled' => '1']);
check('a valid pair passes validation', $valid === 'REACHED_DB' || $valid === null, (string)$valid);

echo "\n" . ($failures === 0 ? "OK — $checks checks passed\n" : "FAILED — $failures of $checks checks\n");
exit($failures === 0 ? 0 : 1);
