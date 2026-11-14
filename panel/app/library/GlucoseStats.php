<?php
declare(strict_types=1);

namespace NightPanel;

/**
 * Glucose statistics over a set of readings (mmol/L).
 *
 * Thresholds follow the international consensus on time in range:
 *   very low < 3.0, low 3.0-3.9, in range 3.9-10.0, high 10.0-13.9, very high > 13.9
 * The user's own profile target is reported separately as "目标范围".
 */
final class GlucoseStats
{
    public const VERY_LOW = 3.0;
    public const LOW = 3.9;
    public const HIGH = 10.0;
    public const VERY_HIGH = 13.9;

    /** mg/dL per mmol/L, used for GMI. */
    private const MGDL_PER_MMOL = 18.0182;

    /** A dip counts as one hypoglycaemia event once it lasts this long. */
    private const LOW_EVENT_MIN_MINUTES = 15;

    /**
     * @param array<int,array{ts:int,v:float}> $readings Sorted ascending by ts
     * @param float $targetLow  User profile lower target (mmol/L)
     * @param float $targetHigh User profile upper target (mmol/L)
     * @return array<string,mixed>
     */
    public static function compute(array $readings, float $targetLow, float $targetHigh): array
    {
        $count = count($readings);

        $buckets = ['veryLow' => 0, 'low' => 0, 'inRange' => 0, 'high' => 0, 'veryHigh' => 0];
        $values = [];
        $inTarget = 0;

        foreach ($readings as $reading) {
            $v = (float)$reading['v'];
            $values[] = $v;
            if ($v < self::VERY_LOW) {
                $buckets['veryLow']++;
            } elseif ($v < self::LOW) {
                $buckets['low']++;
            } elseif ($v <= self::HIGH) {
                $buckets['inRange']++;
            } elseif ($v <= self::VERY_HIGH) {
                $buckets['high']++;
            } else {
                $buckets['veryHigh']++;
            }
            if ($v >= $targetLow && $v <= $targetHigh) {
                $inTarget++;
            }
        }

        $pct = static function (int $part) use ($count): float {
            return $count === 0 ? 0.0 : round($part * 100 / $count, 1);
        };

        $mean = $count > 0 ? array_sum($values) / $count : 0.0;
        $std = 0.0;
        if ($count > 1) {
            $sumSq = 0.0;
            foreach ($values as $v) {
                $sumSq += ($v - $mean) ** 2;
            }
            // Sample standard deviation: the readings are a sample of the period.
            $std = sqrt($sumSq / ($count - 1));
        }

        $sorted = $values;
        sort($sorted);
        $median = 0.0;
        if ($count > 0) {
            $mid = intdiv($count, 2);
            $median = $count % 2 === 1 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2;
        }

        $spanMs = 0;
        if ($count > 1) {
            $spanMs = (int)$readings[$count - 1]['ts'] - (int)$readings[0]['ts'];
        }
        // Sensor readings arrive every 5 minutes.
        $expected = $spanMs > 0 ? (int)round($spanMs / 300000) + 1 : 0;

        $lowEvents = self::countLowEvents($readings);

        // GMI = 3.31 + 0.02392 × mean glucose (mg/dL)
        $gmi = $count > 0 ? round(3.31 + 0.02392 * ($mean * self::MGDL_PER_MMOL), 1) : null;

        return [
            'count' => $count,
            'expected' => $expected,
            'coverage' => $expected > 0 ? round($count * 100 / $expected, 1) : null,
            'mean' => round($mean, 2),
            'median' => round($median, 2),
            'std' => round($std, 2),
            'cv' => $mean > 0 ? round($std * 100 / $mean, 1) : null,
            'min' => $count > 0 ? round(min($values), 2) : null,
            'max' => $count > 0 ? round(max($values), 2) : null,
            'gmi' => $gmi,
            'buckets' => $buckets,
            'percent' => [
                'veryLow' => $pct($buckets['veryLow']),
                'low' => $pct($buckets['low']),
                'inRange' => $pct($buckets['inRange']),
                'high' => $pct($buckets['high']),
                'veryHigh' => $pct($buckets['veryHigh']),
            ],
            'tir' => $pct($buckets['inRange']),
            'tbr' => $pct($buckets['veryLow'] + $buckets['low']),
            'tbr2' => $pct($buckets['veryLow']),
            'tar' => $pct($buckets['high'] + $buckets['veryHigh']),
            'tar2' => $pct($buckets['veryHigh']),
            'target' => [
                'low' => round($targetLow, 2),
                'high' => round($targetHigh, 2),
                'percent' => $pct($inTarget),
            ],
            'lowEvents' => $lowEvents,
        ];
    }

    /**
     * Count hypoglycaemic episodes: consecutive readings below the low
     * threshold, kept only when the dip lasts at least 15 minutes.
     *
     * @param array<int,array{ts:int,v:float}> $readings
     * @return array{events:int,minutes:int,longest:int}
     */
    private static function countLowEvents(array $readings): array
    {
        $events = 0;
        $totalMinutes = 0;
        $longest = 0;
        $runStart = null;
        $runEnd = null;

        $close = static function () use (&$runStart, &$runEnd, &$events, &$totalMinutes, &$longest): void {
            if ($runStart === null || $runEnd === null) {
                return;
            }
            $minutes = (int)round(($runEnd - $runStart) / 60000);
            if ($minutes >= self::LOW_EVENT_MIN_MINUTES) {
                $events++;
                $totalMinutes += $minutes;
                $longest = max($longest, $minutes);
            }
            $runStart = null;
            $runEnd = null;
        };

        foreach ($readings as $reading) {
            if ((float)$reading['v'] < self::LOW) {
                if ($runStart === null) {
                    $runStart = (int)$reading['ts'];
                }
                $runEnd = (int)$reading['ts'];
            } else {
                $close();
            }
        }
        $close();

        return ['events' => $events, 'minutes' => $totalMinutes, 'longest' => $longest];
    }
}
