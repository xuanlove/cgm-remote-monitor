<?php
declare(strict_types=1);

/**
 * Routing is explicit: no controller/action inference, no `.html` suffix.
 * The panel's public URLs must stay exactly what they were before the
 * refactor (`/dashboard`, `/data/glucose.json`, `/export/entries.csv`, ...),
 * otherwise bookmarks, cron jobs and the dashboard's fetch calls break.
 */
return [
    'pathinfo_depr' => '/',
    'url_html_suffix' => '',
    'url_common_param' => false,
    'url_param_type' => 0,
    'url_route_must' => true,
    'url_lazy_route' => true,
    'url_convert' => false,
    'route_complete_match' => true,
    'default_route_pattern' => '[\w\.\-]+',
    'controller_suffix' => true,
    'default_controller' => 'Index',
    'default_action' => 'index',
    'empty_controller' => 'Error',
    'request_cache_key' => false,
    'request_cache_expire' => null,
    'request_cache_except' => [],
];
