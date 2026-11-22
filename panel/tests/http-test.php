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

/** name => [method, uri, post, expected status, body-needle, location-needle, uninstalled?, options?] */
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
    'api entries needs a token' => ['GET', '/api/v1/entries.json', [], 401, 'Unauthorized', ''],
    'api writes need a token' => ['POST', '/api/v1/entries.json', [], 401, 'Unauthorized', ''],
    'api profile writes need a token' => ['PUT', '/api/v1/profile.json', [], 401, 'Unauthorized', ''],
    'api entries rejects a wrong token' => ['GET', '/api/v1/entries.json', [], 401, 'Unauthorized', '', false,
        ['headers' => ['api-secret' => 'wrong-token']]],
    'api entries serves own records newest first' => ['GET', '/api/v1/entries.json', [], 200, '"sgv":120', '', false,
        ['headers' => ['api-secret' => 'test-token-123'], 'query' => ['count' => '2'], 'absent' => '"sgv":100']],
    'api entries answers without the json suffix' => ['GET', '/api/v1/entries', [], 200, '"sgv":120', '', false,
        ['headers' => ['api-secret' => 'test-token-123']]],
    'api entries refuses unknown find filters' => ['GET', '/api/v1/entries.json', [], 400, 'Bad Request', '', false,
        ['headers' => ['api-secret' => 'test-token-123'], 'query' => ['find' => ['foo' => '1']]]],
    'api profile projects the caller store only' => ['GET', '/api/v1/profile.json', [], 200, '"apitester"', '', false,
        ['headers' => ['api-secret' => 'test-token-123'], 'absent' => '"bob"']],
    'api status reports the panel' => ['GET', '/api/v1/status.json', [], 200, 'NightPanel', '', false,
        ['headers' => ['api-secret' => 'test-token-123']]],
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

    // Optional headers and query params for this case, handed through the
    // environment because a child process has no other channel for them.
    $options = $case[7] ?? [];
    $headersJson = getenv('PANEL_HTTP_HEADERS');
    if ($headersJson !== false && $headersJson !== '') {
        foreach ((array)json_decode($headersJson, true) as $name => $value) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', (string)$name))] = (string)$value;
        }
    }
    $queryJson = getenv('PANEL_HTTP_QUERY');
    if ($queryJson !== false && $queryJson !== '') {
        $_GET = (array)json_decode($queryJson, true);
        $_REQUEST = array_merge($_GET, $case[2]);
    }

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

define('PANEL_DB_FILE', $dbFile);

// The session cookie flags are pinned by the middleware, not inherited from
// the host's php.ini: a non-root cookie_path would silently drop the session
// on every page but one. Run the real middleware once here — before this
// script has printed anything, because after the first echo PHP refuses to
// apply session_set_cookie_params, and after the define above so the DB
// handle binds to the throwaway database — and assert the values below.
(new \app\middleware\StartSession())->handle(new \think\Request(), static fn() => \think\Response::create('ok'));
$cookie = session_get_cookie_params();

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
\NightPanel\Db::pdo();
$backendId = \NightPanel\Backends::create('NS Test', 'http://127.0.0.1:1337', 'secret');
file_put_contents(dirname($dbFile) . '/install.lock', 'installed ' . gmdate('c') . "\n");

// A user with a known token plus mirror rows for the compat API cases.
\NightPanel\Db::pdo()->prepare(
    'INSERT INTO panel_users (id, username, password_hash, role, backend_id, ns_access_token_enc)
     VALUES (2, "apitester", ?, "user", ?, ?)'
)->execute([
    password_hash('secret123', PASSWORD_DEFAULT),
    $backendId,
    \NightPanel\Db::encrypt('test-token-123'),
]);
$now = (int)round(microtime(true) * 1000);
$stmt = \NightPanel\Db::pdo()->prepare(
    'INSERT INTO panel_entries (user_id, ns_id, type, device, ts, sgv, raw) VALUES (?, ?, "sgv", "apitester", ?, ?, ?)'
);
foreach ([['e-old', $now - 1200000, 100], ['e-mid', $now - 600000, 110], ['e-new', $now, 120]] as [$entryId, $entryTs, $entrySgv]) {
    $stmt->execute([
        2,
        $entryId,
        $entryTs,
        $entrySgv,
        json_encode(['_id' => $entryId, 'type' => 'sgv', 'sgv' => $entrySgv, 'date' => $entryTs, 'dateString' => gmdate('c', (int)($entryTs / 1000)), 'device' => 'apitester'], JSON_UNESCAPED_SLASHES),
    ]);
}
\NightPanel\Mirror::storeTreatment(2, [
    '_id' => 'tr-old', 'eventType' => 'Note', 'notes' => 'old',
    'created_at' => gmdate('c', (int)($now / 1000) - 7200), 'enteredBy' => 'apitester',
]);
\NightPanel\Mirror::storeTreatment(2, [
    '_id' => 'tr-new', 'eventType' => 'Meal Bolus', 'carbs' => 30, 'insulin' => 3,
    'created_at' => gmdate('c', (int)($now / 1000)), 'enteredBy' => 'apitester',
]);
// A profile document shared with another user: the scoped projection must strip them.
\NightPanel\Db::pdo()->prepare(
    'INSERT INTO panel_profiles (backend_id, ns_id, start_date, payload) VALUES (?, ?, ?, ?)'
)->execute([
    $backendId,
    'p1',
    gmdate('c', (int)($now / 1000)),
    json_encode([
        '_id' => 'p1',
        'startDate' => gmdate('c', (int)($now / 1000)),
        'store' => ['apitester' => ['dia' => 3, 'timezone' => 'UTC'], 'bob' => ['dia' => 6, 'timezone' => 'UTC']],
        'defaultProfile' => 'apitester',
    ]),
]);

// The /install.php case is answered by the framework's redirect (the file is
// not behind the router), so guard that the real file is reachable at all.
check('public/install.php exists', is_file(dirname(__DIR__) . '/public/install.php'));

foreach (array_values($cases) as $index => $case) {
    [$method, $uri, $post, $expected, $needle, $locationNeedle] = $case;
    $options = $case[7] ?? [];

    $extras = '';
    if (!empty($options['headers'])) {
        $extras .= 'PANEL_HTTP_HEADERS=' . escapeshellarg((string)json_encode($options['headers'])) . ' ';
    }
    if (!empty($options['query'])) {
        $extras .= 'PANEL_HTTP_QUERY=' . escapeshellarg((string)json_encode($options['query'])) . ' ';
    }

    $command = 'PANEL_HTTP_CASE=' . $index . ' PANEL_TEST_DB=' . escapeshellarg($dbFile) . ' ' . $extras
        . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1';

    $output = (string)shell_exec($command);
    $lines = explode("\n", $output, 3);
    $code = (int)($lines[0] ?? 0);
    $body = base64_decode($lines[1] ?? '', true) ?: '';
    $location = (string)($lines[2] ?? '');

    $absent = (string)($options['absent'] ?? '');
    $ok = $code === $expected
        && ($needle === '' || str_contains($body, $needle))
        && ($absent === '' || !str_contains($body, $absent))
        && ($locationNeedle === '' || str_contains($location, $locationNeedle));
    check(
        $method . ' ' . $uri . ' -> ' . $expected,
        $ok,
        'got ' . $code
            . ($needle !== '' && !str_contains($body, $needle) ? ', missing "' . $needle . '"' : '')
            . ($absent !== '' && str_contains($body, $absent) ? ', must not contain "' . $absent . '"' : '')
            . ($locationNeedle !== '' && !str_contains($location, $locationNeedle)
                ? ', location "' . $location . '" misses "' . $locationNeedle . '"' : '')
    );
}

// The compat API rate limit answers 429 itself — no controller to fall back
// to. Exhaust the api bucket for this process's identity (the child cases use
// their own REMOTE_ADDR identity) and run the middleware directly.
for ($i = 0; $i < 121; $i++) {
    \NightPanel\Db::rateLimit('api', \NightPanel\Db::clientKey(), 120, 60);
}
// Throttle answers with the framework's json() helper, which normally loads
// with the App boot; this parent never boots one, so include it directly.
require_once dirname(__DIR__) . '/vendor/topthink/framework/src/helper.php';
$throttled = (new \app\middleware\Throttle())->handle(
    new \think\Request(),
    static fn() => \think\Response::create('ok'),
    'api',
    120,
    60,
    'json'
);
check(
    'api throttle answers 429 in the NS shape',
    $throttled->getCode() === 429 && str_contains((string)$throttled->getContent(), 'Too many requests'),
    'got ' . $throttled->getCode()
);

check('session cookie path is pinned to /', $cookie['path'] === '/', 'path=' . $cookie['path']);
check('session cookie is httponly + samesite lax', $cookie['httponly'] === true && strtolower((string)$cookie['samesite']) === 'lax', 'httponly=' . var_export($cookie['httponly'], true) . ' samesite=' . var_export($cookie['samesite'], true));

@unlink($dbFile);
@unlink($dbFile . '-wal');
@unlink($dbFile . '-shm');
@unlink(dirname($dbFile) . '/install.lock');
@rmdir($dbDir);
$empty = sys_get_temp_dir() . '/panel-http-test-empty-' . getmypid();
@rmdir($empty);

echo $failures === 0 ? "\nOK — {$checks} checks passed\n" : "\n{$failures} of {$checks} checks failed\n";
exit($failures === 0 ? 0 : 1);
