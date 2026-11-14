<?php
declare(strict_types=1);

/**
 * Global helpers shared by controllers, templates and the CLI.
 *
 * Loaded through Composer's `files` autoload, so they are available in every
 * entry point (web, think console, bin/*.php and the self-checks).
 */

use NightPanel\Lang;

/**
 * Absolute path of the SQLite database. No environment configuration: the
 * panel is configured through install.php, which writes everything to the
 * database. Tests may inject a temp path via the PANEL_DB_FILE constant.
 */
function panel_db_file(): string
{
    if (defined('PANEL_DB_FILE')) {
        return (string)constant('PANEL_DB_FILE');
    }
    return dirname(__DIR__, 2) . '/data/panel.db';
}

/**
 * Absolute path of the installer lock. Written once by install.php, and both
 * the installer and the EnsureInstalled middleware must resolve it the same
 * way — derived from the database path, so tests can move both together.
 */
function panel_lock_file(): string
{
    return dirname(panel_db_file()) . '/install.lock';
}

/** Translate a key, substituting `:name` placeholders. */
function t(string $key, array $params = []): string
{
    return Lang::t($key, $params);
}

/**
 * Translate and HTML-escape in one step.
 *
 * Templates call this for any string that carries user or Nightscout data:
 * `{:t_html('key', ['name' => $name])}` bypasses the template's default
 * escaping (functions are not filtered), so the escaping has to happen here.
 */
function t_html(string $key, array $params = []): string
{
    return htmlspecialchars(Lang::t($key, $params), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * JSON for embedding inside a <script> block.
 *
 * JSON_HEX_* keeps `</script>` and attribute-breaking quotes inert, which is
 * what the audit required of every value written into a page.
 */
function js_json($value): string
{
    return (string)json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
}
