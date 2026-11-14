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
 * process is exactly what a real request gets.
 */

require __DIR__ . '/../vendor/autoload.php';

/** name => [method, uri, post, expected status, body-needle] */
$cases = [
    'root redirects' => ['GET', '/', [], 302, ''],
    'login renders the form' => ['GET', '/login', [], 200, 'name="csrf"'],
    'dashboard needs a session' => ['GET', '/dashboard', [], 302, ''],
    'logout is POST only' => ['GET', '/logout', [], 405, ''],
    'login without CSRF is refused' => ['POST', '/login', ['username' => 'x', 'password' => 'y'], 403, ''],
    'unknown path renders the 404 page' => ['GET', '/nope', [], 404, '404'],
    'trailing slash redirects' => ['GET', '/login/', [], 301, ''],
    'JSON endpoint needs a session' => ['GET', '/data/glucose.json', [], 302, ''],
    'admin needs a session' => ['GET', '/admin/users', [], 302, ''],
    'export needs a session' => ['GET', '/export/entries.csv', [], 302, ''],
];

// ---------------------------------------------------------------- child mode
$caseIndex = getenv('PANEL_HTTP_CASE');
if ($caseIndex !== false) {
    $case = array_values($cases)[(int)$caseIndex];

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

    echo $response->getCode() . "\n" . base64_encode((string)$response->getContent());
    exit(0);
}

// --------------------------------------------------------------- parent mode
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

foreach (array_values($cases) as $index => $case) {
    [$method, $uri, $post, $expected, $needle] = $case;

    $command = 'PANEL_HTTP_CASE=' . $index . ' '
        . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1';

    $output = (string)shell_exec($command);
    $lines = explode("\n", $output, 2);
    $code = (int)($lines[0] ?? 0);
    $body = base64_decode($lines[1] ?? '', true) ?: '';

    $ok = $code === $expected && ($needle === '' || str_contains($body, $needle));
    check(
        $method . ' ' . $uri . ' -> ' . $expected,
        $ok,
        'got ' . $code . ($needle !== '' && !str_contains($body, $needle) ? ', missing "' . $needle . '"' : '')
    );
}

echo $failures === 0 ? "\nOK — {$checks} checks passed\n" : "\n{$failures} of {$checks} checks failed\n";
exit($failures === 0 ? 0 : 1);
