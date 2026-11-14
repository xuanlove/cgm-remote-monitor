<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Write-path integration for the Nightscout-compatible API. Run with:
 *   php panel/tests/api-write-test.php
 *
 * Every case is dispatched in its own PHP process with the request body
 * piped through stdin (php://input), exactly like a real POST. The backend
 * is a stub Nightscout (tests/stub-nightscout.php) served by php -S: it
 * records the last request body so the parent can assert that the panel
 * forced the attribution fields, and it answers with real created records
 * whose _ids the parent then finds in the mirror.
 */

$dbDir = sys_get_temp_dir() . '/panel-api-write-' . getmypid();
$dbFile = $dbDir . '/panel.db';
$logFile = $dbDir . '/stub.log';
$port = 18000 + (getmypid() % 2000);
$stub = 'http://127.0.0.1:' . $port;

/** name => [method, uri, headers, body, expected status, needle, absent] */
$cases = [
    'post entries needs a token' => ['POST', '/api/v1/entries.json', [], '{"sgv":140}', 401, 'Unauthorized', ''],
    'post entries rejects a wrong token' => ['POST', '/api/v1/entries.json', ['api-secret' => 'nope'], '{"sgv":140}', 401, 'Unauthorized', ''],
    'post entries serves the created record' => ['POST', '/api/v1/entries.json', ['api-secret' => 'write-token-1'],
        '{"type":"sgv","sgv":142,"dateString":"2026-11-14T10:00:00+08:00"}', 200, '"sgv":142', ''],
    'zoneless dateString uses the profile timezone' => ['POST', '/api/v1/entries.json', ['api-secret' => 'write-token-1'],
        '{"type":"sgv","sgv":160,"dateString":"2026-11-13 10:00:00"}', 200, '"sgv":160', ''],
    'post entries accepts an array' => ['POST', '/api/v1/entries.json', ['api-secret' => 'write-token-1'],
        '[{"type":"sgv","sgv":150,"date":1700000000000},{"type":"mbg","mbg":90,"date":1700000300000}]', 200, '"mbg":90', ''],
    'post entries without a date is a no-op' => ['POST', '/api/v1/entries.json', ['api-secret' => 'write-token-1'],
        '{"sgv":100}', 200, '[]', ''],
    'post treatments forces attribution' => ['POST', '/api/v1/treatments.json', ['api-secret' => 'write-token-1'],
        '{"eventType":"Meal Bolus","carbs":30,"insulin":3}', 200, '"eventType":"Meal Bolus"', ''],
    'post devicestatus upserts the mirror' => ['POST', '/api/v1/devicestatus.json', ['api-secret' => 'write-token-1'],
        '{"uploader":{"battery":55}}', 200, '"battery":55', ''],
    'put profile projects the store to the caller' => ['PUT', '/api/v1/profile.json', ['api-secret' => 'write-token-1'],
        '{"store":{"apitester":{"dia":4,"units":"mg/dl"},"bob":{"dia":9}}}', 200, '"dia":4', '"bob"'],
    'put profile without the own store key is refused' => ['PUT', '/api/v1/profile.json', ['api-secret' => 'write-token-1'],
        '{"store":{"bob":{"dia":9}}}', 400, 'your own entry', ''],
    'invalid json body is refused' => ['POST', '/api/v1/entries.json', ['api-secret' => 'write-token-1'],
        'not-json{', 400, 'Invalid JSON body', ''],
    'scalar payload is refused' => ['POST', '/api/v1/entries.json', ['api-secret' => 'write-token-1'],
        '123', 400, 'Invalid entries payload', ''],
    // Reads through the same groups, so the mirror just written to is visible.
    'find[type]=cal answers an empty collection' => ['GET', '/api/v1/entries.json?find[type]=cal',
        ['api-secret' => 'write-token-1'], '', 200, '[]', ''],
    'find[type][$in] filters instead of failing' => ['GET', '/api/v1/entries.json?find[type][$in][0]=sgv',
        ['api-secret' => 'write-token-1'], '', 200, '"sgv":142', '"mbg":90'],
    // Last: the stub is killed before this one runs (see the parent loop).
    'a dead backend answers a JSON 502' => ['POST', '/api/v1/entries.json', ['api-secret' => 'write-token-1'],
        '{"sgv":151,"date":1700000005000}', 502, 'Nightscout unavailable', ''],
];

// ---------------------------------------------------------------- child mode
$caseIndex = getenv('PANEL_WRITE_CASE');
if ($caseIndex === 'read') {
    // The read-after-write check: newest stub record as the mirror sees it.
    define('PANEL_DB_FILE', getenv('PANEL_TEST_DB'));
    require dirname(__DIR__) . '/vendor/autoload.php';
    $row = \NightPanel\Db::pdo()->query(
        "SELECT sgv FROM panel_entries WHERE user_id = 2 AND ns_id LIKE 'stub-%' ORDER BY ts DESC LIMIT 1"
    )->fetch();
    echo (string)($row === false ? '' : $row['sgv']);
    exit(0);
}
if ($caseIndex !== false) {
    $case = array_values($cases)[(int)$caseIndex];
    [$method, $uri, $headers, $body] = $case;

    define('PANEL_DB_FILE', getenv('PANEL_TEST_DB'));
    require dirname(__DIR__) . '/vendor/autoload.php';

    // Nothing must leak between requests; each child is a fresh process.
    foreach (array_keys($_SERVER) as $key) {
        if (str_starts_with($key, 'HTTP_')) {
            unset($_SERVER[$key]);
        }
    }
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    foreach ($headers as $name => $value) {
        $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', (string)$name))] = (string)$value;
    }
    // ThinkPHP reads the parsed superglobal: hand it what a web SAPI would
    // build from the URI's query string.
    $query = parse_url($uri, PHP_URL_QUERY);
    $_GET = [];
    if ($query !== null) {
        parse_str($query, $_GET);
    }
    $_REQUEST = $_GET;

    // php://input is not populated from stdin in this CLI build, so the body
    // rides on a manually built Request (withInput is the framework's own
    // setter, the same thing its tests use) handed to Http::run().
    $request = \think\Request::__make(new \think\App());
    $request->setMethod($method)->withInput($body);
    $app = new \think\App();
    $app->register(\app\AppService::class);
    $response = $app->http->run($request);
    $ctype = $response->getHeader('Content-Type');
    echo $response->getCode() . "\n" . base64_encode((string)$response->getContent()) . "\n"
        . (is_array($ctype) ? implode(',', $ctype) : (string)$ctype);
    exit(0);
}

// --------------------------------------------------------------- parent mode
define('PANEL_DB_FILE', $dbFile);
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

echo "API write path\n";

// Prepare an installed panel whose backend points at the stub.
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0770, true);
}
$pdo = \NightPanel\Db::pdo();
$backendId = \NightPanel\Backends::create('Stub NS', $stub, 'secret');
file_put_contents(dirname($dbFile) . '/install.lock', 'installed ' . gmdate('c') . "\n");
$pdo->prepare(
    'INSERT INTO panel_users (id, username, password_hash, role, backend_id, ns_access_token_enc)
     VALUES (2, "apitester", ?, "user", ?, ?)'
)->execute([
    password_hash('secret123', PASSWORD_DEFAULT),
    $backendId,
    \NightPanel\Db::encrypt('write-token-1'),
]);
// The profile timezone decides how zoneless write timestamps are read.
$pdo->prepare(
    'INSERT INTO panel_profiles (backend_id, ns_id, start_date, payload) VALUES (?, "p1", ?, ?)'
)->execute([
    $backendId,
    gmdate('c'),
    json_encode([
        '_id' => 'p1',
        'startDate' => gmdate('c'),
        'store' => ['apitester' => ['dia' => 3, 'timezone' => 'Asia/Shanghai', 'units' => 'mg/dl']],
    ], JSON_UNESCAPED_SLASHES),
]);

// Start the stub backend and wait until it accepts connections.
$pid = (int)shell_exec(
    'PANEL_STUB_LOG=' . escapeshellarg($logFile) . ' '
    . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' '
    . escapeshellarg(__DIR__ . '/stub-nightscout.php')
    . ' > /dev/null 2>&1 & echo $!'
);
$up = false;
for ($i = 0; $i < 40; $i++) {
    $up = @fsockopen('127.0.0.1', $port, timeout: 0.2) !== false;
    if ($up) {
        break;
    }
    usleep(50000);
}
check('stub backend came up on ' . $port, $up);
$stub = fopen($logFile, 'w'); // reset the log before the cases run
fclose($stub);
$stubLog = '';

$index = 0;
$downRun = false;
foreach ($cases as $name => $case) {
    [$method, $uri, $headers, $body, $expected, $needle] = $case;
    $absent = (string)($case[6] ?? '');
    $caseIndex = $index++;

    // The dead-backend case needs the stub gone; it runs after the loop.
    if ($name === 'a dead backend answers a JSON 502') {
        continue;
    }

    $command = 'PANEL_WRITE_CASE=' . $caseIndex
        . ' PANEL_TEST_DB=' . escapeshellarg($dbFile) . ' '
        . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1';
    $output = (string)shell_exec($command);
    $lines = explode("\n", $output, 3);
    $code = (int)($lines[0] ?? 0);
    $responseBody = base64_decode($lines[1] ?? '', true) ?: '';
    $ctype = trim((string)($lines[2] ?? ''));

    $ok = $code === $expected
        && ($needle === '' || str_contains($responseBody, $needle))
        && ($absent === '' || !str_contains($responseBody, $absent));
    check(
        $name,
        $ok,
        'got ' . $code
            . ($ok ? '' : ', body: ' . substr($responseBody, 0, 400))
            . ($ok ? '' : ', stub log: ' . substr((string)file_get_contents($logFile), 0, 300))
    );
    if ($name === 'post entries without a date is a no-op') {
        // The no-op must not have reached the backend at all.
        $stubLog = (string)file_get_contents($logFile);
        check('the no-op never reached the backend', !str_contains($stubLog, '"sgv":100'));
    }
    if ($name === 'post entries serves the created record') {
        // The panel forced device attribution before proxying, and filled
        // date from the dateString it received.
        $log = (string)file_get_contents($logFile);
        $sent = (array)(json_decode($log, true)[0] ?? []);
        check('entries attribution forced to the caller', ($sent['device'] ?? '') === 'apitester', 'log: ' . $log);
        check('date derived from dateString', ($sent['date'] ?? 0) === (int)strtotime('2026-11-14T10:00:00+08:00') * 1000, 'log: ' . $log);
        // And the mirror holds the record the stub created.
        $row = $pdo->query(
            "SELECT ts, device, sgv FROM panel_entries WHERE user_id = 2 AND ns_id LIKE 'stub-%' AND sgv = 142"
        )->fetch();
        check('created entry landed in the mirror', $row !== false
            && $row['device'] === 'apitester'
            && (int)$row['ts'] === (int)strtotime('2026-11-14T10:00:00+08:00') * 1000);
    }
    if ($name === 'zoneless dateString uses the profile timezone') {
        // A dateString without an offset belongs to the user's profile zone
        // (Asia/Shanghai below), never the PHP host's default.
        $sent = (array)(json_decode((string)file_get_contents($logFile), true)[0] ?? []);
        check(
            'zoneless dateString interpreted in the profile timezone',
            ($sent['date'] ?? 0) === strtotime('2026-11-13T10:00:00+08:00') * 1000,
            'log: ' . (string)file_get_contents($logFile)
        );
    }
    if ($name === 'post entries accepts an array') {
        $count = (int)$pdo->query(
            "SELECT COUNT(*) FROM panel_entries WHERE user_id = 2 AND ns_id LIKE 'stub-%' AND (sgv = 150 OR sgv = 90)"
        )->fetchColumn();
        check('both array records landed in the mirror', $count === 2);
    }
    if ($name === 'post treatments forces attribution') {
        $log = (string)file_get_contents($logFile);
        $sent = (array)(json_decode($log, true)[0] ?? []);
        check('treatments enteredBy forced', ($sent['enteredBy'] ?? '') === 'apitester', 'log: ' . $log);
        check('created_at assigned', ($sent['created_at'] ?? '') !== '', 'log: ' . $log);
        $row = $pdo->query(
            "SELECT payload FROM panel_treatments WHERE user_id = 2 AND ns_id LIKE 'stub-%' AND event_type = 'Meal Bolus'"
        )->fetch();
        check('created treatment landed in the mirror', $row !== false);
    }
    if ($name === 'post devicestatus upserts the mirror') {
        $row = $pdo->query('SELECT payload FROM panel_devicestatus WHERE user_id = 2')->fetch();
        check('devicestatus landed in the mirror', $row !== false && str_contains((string)$row['payload'], '"battery":55'));
    }
    if ($name === 'put profile projects the store to the caller') {
        $sent = (array)json_decode((string)file_get_contents($logFile), true);
        $store = (array)($sent['store'] ?? []);
        check('only the caller store key was forwarded', array_keys($store) === ['apitester']);
        check('record id was injected from the backend', ($sent['_id'] ?? '') === 'stub-p1');
    }
}

// Reads see what the writes put in the mirror (the dashboard contract).
$read = (string)shell_exec(
    'PANEL_WRITE_CASE=read PANEL_TEST_DB=' . escapeshellarg($dbFile) . ' '
    . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1'
) ?: '';
check('read-after-write serves the uploaded record', $read === '142', 'got ' . $read);

// The backend dies mid-flight: the API must still answer JSON (an uploader
// cannot parse an HTML error page), and it must say Nightscout is unavailable.
@shell_exec('kill ' . escapeshellarg((string)$pid));
usleep(200000);
$downOutput = (string)shell_exec(
    'PANEL_WRITE_CASE=' . (array_search('a dead backend answers a JSON 502', array_keys($cases), true))
    . ' PANEL_TEST_DB=' . escapeshellarg($dbFile) . ' '
    . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1'
);
$downLines = explode("\n", $downOutput, 3);
$downCode = (int)($downLines[0] ?? 0);
$downBody = base64_decode($downLines[1] ?? '', true) ?: '';
$downType = trim((string)($downLines[2] ?? ''));
check(
    'a dead backend answers a JSON 502',
    $downCode === 502
        && str_contains($downBody, 'Nightscout unavailable')
        && str_contains($downType, 'application/json'),
    'got ' . $downCode . ' ' . $downType . ', body: ' . substr($downBody, 0, 200)
);

@unlink($dbFile);
@unlink($dbFile . '-wal');
@unlink($dbFile . '-shm');
@unlink(dirname($dbFile) . '/install.lock');
@unlink($logFile);
@rmdir($dbDir);

echo $failures === 0 ? "\nOK — {$checks} checks passed\n" : "\n{$failures} of {$checks} checks failed\n";
exit($failures === 0 ? 0 : 1);
