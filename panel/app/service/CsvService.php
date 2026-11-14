<?php
declare(strict_types=1);

namespace app\service;

use NightPanel\Lang;

/**
 * Text output helpers that do not belong to any single feature.
 */
final class CsvService
{
    /**
     * Render a CSV document with a UTF-8 BOM so Excel opens it correctly.
     *
     * @param array<int,string> $header
     * @param array<int,array<int,mixed>> $rows
     */
    public static function render(array $header, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        // The escape argument is explicit: PHP 8.4 deprecates relying on the
        // default, and the default changes in a future release.
        fputcsv($out, $header, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '\\');
        }
        rewind($out);
        $body = (string)stream_get_contents($out);
        fclose($out);

        return $body;
    }

    /** Clamp the requested reporting window to the supported range. */
    public static function requestedDays(int $requested): int
    {
        return max(1, min(90, $requested));
    }

    /** @return array<int,string> */
    public static function header(string ...$keys): array
    {
        return array_map(static fn(string $key): string => Lang::t($key), $keys);
    }
}
