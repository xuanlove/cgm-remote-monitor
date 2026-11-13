<?php
declare(strict_types=1);

/**
 * Global helpers used by the views.
 *
 * Kept outside `NightPanel` so a template can write `t('nav.dashboard')`
 * instead of the fully qualified call on every line.
 */

if (!function_exists('t')) {
    /**
     * Translate a key, replacing `:name` placeholders.
     *
     * @param array<string,string|int|float> $params
     */
    function t(string $key, array $params = []): string
    {
        return \NightPanel\Lang::t($key, $params);
    }
}
