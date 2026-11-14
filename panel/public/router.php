<?php
/**
 * Router for PHP's built-in server (development only):
 *   php -S 127.0.0.1:8090 -t public public/router.php
 *
 * Real files (assets) are served as-is; everything else goes through the
 * front controller. Runtime data, app code and vendor live outside public/,
 * so there is nothing else to deny here.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
