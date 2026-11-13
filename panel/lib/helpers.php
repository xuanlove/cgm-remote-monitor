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

if (!function_exists('js_json')) {
    /**
     * Encode data for embedding inside a <script> block.
     *
     * The HEX flags neutralise `<`, `>`, `&`, `'` and `"`, so a value that
     * contains `</script>` or an attribute-breaking quote can no longer escape
     * the block. Use this instead of a bare json_encode() in views.
     *
     * @param mixed $data
     */
    function js_json($data): string
    {
        return (string)json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
    }
}
