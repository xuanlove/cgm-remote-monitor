<?php
declare(strict_types=1);

/**
 * Plain-PHP checks for GlucoseStats. Run with:  php panel/tests/glucose-stats-test.php
 * Kept dependency-free so it runs anywhere the panel does.
 */

require __DIR__ . '/../lib/GlucoseStats.php';

use NightPanel\GlucoseStats;

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

function near(float $a, float $b, float $tol = 0.05): bool
{
    return abs($a - $b) <= $tol;
}

/** Build readings from a list of mmol/L values, 5 minutes apart. */
function series(array $values, int $startTs = 1700000000000): array
{
    $out = [];
    foreach ($values as $i => $v) {
        $out[] = ['ts' => $startTs + $i * 300000, 'v' => (float)$v];
    }
    return $out;
}

echo "GlucoseStats\n";

// --- buckets, TIR/TAR/TBR ---------------------------------------------------
// 10 readings: 2 very low, 1 low, 4 in range, 2 high, 1 very high.
$s = GlucoseStats::compute(series([2.5, 2.9, 3.5, 4.0, 5.5, 7.0, 9.9, 10.5, 13.0, 14.5]), 3.9, 10.0);
check('count', $s['count'] === 10, 'got ' . $s['count']);
check('percent.veryLow = 20%', near($s['percent']['veryLow'], 20.0), (string)$s['percent']['veryLow']);
check('percent.low = 10%', near($s['percent']['low'], 10.0), (string)$s['percent']['low']);
check('percent.inRange = 40%', near($s['percent']['inRange'], 40.0), (string)$s['percent']['inRange']);
check('percent.high = 20%', near($s['percent']['high'], 20.0), (string)$s['percent']['high']);
check('percent.veryHigh = 10%', near($s['percent']['veryHigh'], 10.0), (string)$s['percent']['veryHigh']);
check('tir = 40%', near($s['tir'], 40.0), (string)$s['tir']);
check('tbr = 30%', near($s['tbr'], 30.0), (string)$s['tbr']);
check('tbr2 = 20%', near($s['tbr2'], 20.0), (string)$s['tbr2']);
check('tar = 30%', near($s['tar'], 30.0), (string)$s['tar']);
check('tar2 = 10%', near($s['tar2'], 10.0), (string)$s['tar2']);
check('buckets add up', array_sum($s['buckets']) === 10);

// --- boundary values -------------------------------------------------------
// 3.9 and 10.0 are inside the target band; 3.0 and 13.9 fall in the higher bucket.
$b = GlucoseStats::compute(series([3.0, 3.9, 10.0, 13.9, 13.91]), 3.9, 10.0);
check('3.0 counts as low', $b['buckets']['low'] === 1, json_encode($b['buckets']));
check('3.9 counts as in range', $b['buckets']['inRange'] === 2, json_encode($b['buckets']));
check('13.9 counts as high', $b['buckets']['high'] === 1, json_encode($b['buckets']));
check('13.91 counts as very high', $b['buckets']['veryHigh'] === 1, json_encode($b['buckets']));

// --- summary numbers -------------------------------------------------------
$m = GlucoseStats::compute(series([5.0, 5.0, 5.0, 5.0]), 3.9, 10.0);
check('mean', near($m['mean'], 5.0), (string)$m['mean']);
check('std is zero for constant series', near($m['std'], 0.0), (string)$m['std']);
check('cv is zero for constant series', near((float)$m['cv'], 0.0), (string)$m['cv']);
check('median', near($m['median'], 5.0), (string)$m['median']);
// GMI = 3.31 + 0.02392 * (5.0 * 18.0182) = 5.46
check('gmi', near((float)$m['gmi'], 5.5, 0.1), (string)$m['gmi']);

$odd = GlucoseStats::compute(series([4.0, 5.0, 9.0]), 3.9, 10.0);
check('median of odd count', near($odd['median'], 5.0), (string)$odd['median']);

// --- coverage --------------------------------------------------------------
$coverage = GlucoseStats::compute(series(array_fill(0, 12, 5.5)), 3.9, 10.0);
check('coverage 100% for a contiguous 5-min series', near((float)$coverage['coverage'], 100.0), (string)$coverage['coverage']);
check('expected readings counted', $coverage['expected'] === 12, (string)$coverage['expected']);

// --- hypoglycaemia events --------------------------------------------------
// A 10-minute dip (two readings 5 minutes apart) is below the 15-minute rule,
// and an isolated reading is not an event either.
$short = GlucoseStats::compute(series([5.0, 3.5, 3.4, 5.0, 5.0, 3.2, 5.0]), 3.9, 10.0);
check('short dips are not events', $short['lowEvents']['events'] === 0, json_encode($short['lowEvents']));

// Four consecutive low readings span 15 minutes and count as one event.
$longDip = GlucoseStats::compute(series([5.0, 3.5, 3.4, 3.3, 3.2, 5.0]), 3.9, 10.0);
check('a 15-minute dip counts as one event', $longDip['lowEvents']['events'] === 1, json_encode($longDip['lowEvents']));
check('longest dip reported in minutes', $longDip['lowEvents']['longest'] === 15, (string)$longDip['lowEvents']['longest']);

// Two separate long dips count twice.
$twoDips = GlucoseStats::compute(series([5.0, 3.5, 3.4, 3.3, 3.2, 5.0, 5.0, 3.1, 3.2, 3.3, 3.4, 5.0]), 3.9, 10.0);
check('two long dips count as two events', $twoDips['lowEvents']['events'] === 2, json_encode($twoDips['lowEvents']));
check('dip minutes accumulate', $twoDips['lowEvents']['minutes'] === 30, json_encode($twoDips['lowEvents']));

// --- empty series ----------------------------------------------------------
$empty = GlucoseStats::compute([], 3.9, 10.0);
check('empty series has no counts', $empty['count'] === 0);
check('empty series reports 0% everywhere', $empty['tir'] === 0.0 && $empty['tbr'] === 0.0);
check('empty series has no gmi', $empty['gmi'] === null);
check('empty series has no coverage', $empty['coverage'] === null);

// --- profile target band ---------------------------------------------------
// 4.44-7.22 mmol/L is the 80-130 mg/dL default profile target.
$target = GlucoseStats::compute(series([4.5, 5.0, 7.5, 8.0]), 4.44, 7.22);
check('profile target counts 2 of 4 readings', near($target['target']['percent'], 50.0), (string)$target['target']['percent']);
check('profile target echoed back', $target['target']['low'] === 4.44 && $target['target']['high'] === 7.22);

echo "\n" . ($failures === 0 ? "OK — $checks checks passed\n" : "FAILED — $failures of $checks checks\n");
exit($failures === 0 ? 0 : 1);
