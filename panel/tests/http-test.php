<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Route-level regression checks. Run with:
 *   php panel/tests/http-test.php
 *
 * Every case is dispatched in its own PHP process: one process can only boot
 * ThinkPHP once (the session and the container are process-wide), and a fresh
 * process is exactly what a real request gets. The parent prepares an
 * installed throwaway database (schema + one backend + lock) that the
 * children share through the PANEL_TEST_DB environment variable; the
 * "uninstalled" case points at an empty directory instead.
 */

$dbDir = sys_get_temp_dir() . '/panel-http-test-' . getmypid();
$dbFile = $dbDir . '/panel.db';

/** name => [method, uri, post, expected status, body-needle, location-needle] */
$cases = [
    'uninstalled redirects to the installer' => ['GET', '/login', [], 302, '', '/install.php', true],
    'root redirects' => ['GET', '/', [], 302, '', ''],
    'login renders the form' => ['GET', '/login', [], 200, 'name="csrf"', ''],
    'dashboard needs a session' => ['GET', '/dashboard', [], 302, '', '/login'],
    'logout is POST only' => ['GET', '/logout', [], 405, '', ''],
    'logout without CSRF is refused' => ['POST', '/logout', [], 403, '', ''],
    'login without CSRF is refused' => ['POST', '/login', ['username' => 'x', 'password' => 'y'], 403, '', ''],
    'unknown path renders the 404 page' => ['GET', '/nope', [], 404, '404', ''],
    'trailing slash redirects' => ['GET', '/login/', [], 301, '', ''],
    'JSON endpoint needs a session' => ['GET', '/data/glucose.json', [], 302, '', '/login'],
    'admin needs a session' => ['GET', '/admin/users', [], 302, '', '/login'],
    'admin migration page needs a session' => ['GET', '/admin/migrate', [], 302, '', '/login'],
    'backend add needs a session' => ['POST', '/admin/backends/add', ['name' => 'x'], 302, '', '/login'],
    'migration run needs a session' => ['POST', '/admin/migrate/run', ['from' => '1'], 302, '', '/login'],
    'export needs a session' => ['GET', '/export/entries.csv', [], 302, '', '/login'],
    'register offers the backend choice' => ['GET', '/register', [], 200, 'name="backend"', ''],
];

// ---------------------------------------------------------------- child mode
$caseIndex = getenv('PANEL_HTTP_CASE');
if ($caseIndex !== false) {
    $case = array_values($cases)[(int)$caseIndex];

    if (!empty($case[6])) {
        // The "uninstalled" case: point both the database and the lock at a
        // directory that has neither.
        $empty = sys_get_temp_dir() . '/panel-http-test-empty-' . getmypid();
        if (!is_dir($empty)) {
            mkdir($empty, 0770, true);
        }
        define('PANEL_DB_FILE', $empty . '/panel.db');
    } elseif (getenv('PANEL_TEST_DB')) {
        define('PANEL_DB_FILE', getenv('PANEL_TEST_DB'));
    }

    require dirname(__DIR__) . '/vendor/autoload.php';

    $_SERVER['REQUEST_METHOD'] = $case[0];
    $_SERVER['REQUEST_URI'] = $case[1];
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/public/index.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_POST = $case[2];
    $_GET = [];
    $_REQUEST = $case[2];

    $app = new \think\App();
    $app->register(\app\AppService::class);
    $response = $app->http->run();

    $location = $response->getHeader('Location');
    if (is_array($location)) {
        $location = $location[0] ?? '';
    }
    echo $response->getCode() . "\n" . base64_encode((string)$response->getContent()) . "\n" . (string)$location;
    exit(0);
}

// --------------------------------------------------------------- parent mode
require __DIR__ . '/../vendor/autoload.php';

$failures = 0;
$checks = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  PASS  {$name}\n";
        return;
    }
    $failures++;
    echo "  FAIL  {$name}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
}

echo "HTTP routing\n";

// Prepare an installed panel: schema, one enabled backend, lock file.
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0770, true);
}
define('PANEL_DB_FILE', $dbFile);
\NightPanel\Db::pdo();
\NightPanel\Backends::create('NS Test', 'http://127.0.0.1:1337', 'secret');
file_put_contents(dirname($dbFile) . '/install.lock', 'installed ' . gmdate('c') . "\n");

// The /install.php case is answered by the framework's redirect (the file is
// not behind the router), so guard that the real file is reachable at all.
check('public/install.php exists', is_file(dirname(__DIR__) . '/public/install.php'));

foreach (array_values($cases) as $index => $case) {
    [$method, $uri, $post, $expected, $needle, $locationNeedle] = $case;

    $command = 'PANEL_HTTP_CASE=' . $index . ' PANEL_TEST_DB=' . escapeshellarg($dbFile) . ' '
        . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1';

    $output = (string)shell_exec($command);
    $lines = explode("\n", $output, 3);
    $code = (int)($lines[0] ?? 0);
    $body = base64_decode($lines[1] ?? '', true) ?: '';
    $location = (string)($lines[2] ?? '');

    $ok = $code === $expected
        && ($needle === '' || str_contains($body, $needle))
        && ($locationNeedle === '' || str_contains($location, $locationNeedle));
    check(
        $method . ' ' . $uri . ' -> ' . $expected,
        $ok,
        'got ' . $code
            . ($needle !== '' && !str_contains($body, $needle) ? ', missing "' . $needle . '"' : '')
            . ($locationNeedle !== '' && !str_contains($location, $locationNeedle)
                ? ', location "' . $location . '" misses "' . $locationNeedle . '"' : '')
    );
}

@unlink($dbFile);
@unlink($dbFile . '-wal');
@unlink($dbFile . '-shm');
@unlink(dirname($dbFile) . '/install.lock');
@rmdir($dbDir);
$empty = sys_get_temp_dir() . '/panel-http-test-empty-' . getmypid();
@rmdir($empty);

echo $failures === 0 ? "\nOK — {$checks} checks passed\n" : "\n{$failures} of {$checks} checks failed\n";
exit($failures === 0 ? 0 : 1);
