<?php
declare(strict_types=1);

/**
 * Templates live in panel/view and are compiled into panel/runtime/temp.
 *
 * `default_filter` is htmlentities: every `{$var}` is escaped unless the
 * template explicitly marks it raw. Only controller-generated JSON blobs may
 * use `|raw`; views-xss-test.php enforces the whitelist.
 */
return [
    // Without this the framework falls back to the plain PHP driver, which
    // would echo the template source instead of rendering it.
    'type' => 'think',
    'view_suffix' => 'html',
    'view_dir_name' => 'view',
    'view_depr' => DIRECTORY_SEPARATOR,
    'tpl_begin' => '{',
    'tpl_end' => '}',
    'taglib_begin' => '{',
    'taglib_end' => '}',
    'tpl_cache' => true,
    'default_filter' => 'htmlentities',
];
