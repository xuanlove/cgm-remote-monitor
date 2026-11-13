<?php
// php -S router: serve real files directly, route everything else to index.php.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// The SQLite mirror, WAL files, the sync lock and dotfiles are private; the
// built-in server would otherwise hand them out as static files. The design
// demo page is a development artefact and is not part of the panel.
if (preg_match('#(^|/)\\.#', $path) === 1
    || preg_match('#^/data(/|$)#i', $path) === 1
    || $path === '/glucose-trend-demo.html') {
    http_response_code(404);
    echo 'Not found';
    return true;
}

$file = __DIR__ . $path;
if ($path !== '/' && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
    return false;
}
require __DIR__ . '/index.php';
