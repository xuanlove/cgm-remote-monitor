<?php
declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
    http_response_code(403);
    exit;
}

if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
    // Invoked directly (not as a php -S router): say what this file is for.
    // This keeps `for t in tests/*.php` loops from treating it as a test.
    echo "stub backend for tests/api-write-test.php — started automatically, do not run directly\n";
    exit(0);
}

/**
 * A stand-in Nightscout backend for the write-path integration test. Started
 * with `php -S 127.0.0.1:<port> tests/stub-nightscout.php` by the parent test.
 *
 * It records the last request body to a log file (so the test can assert the
 * panel's forced attribution) and answers with the shape the real backend
 * uses: POSTs return the created records as an array with fresh _ids, the
 * profile PUT echoes the merged document, GET profile.json serves a fixed
 * shared document so the panel can resolve the record id for scoped PUTs.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// The test points this path at the stub through env: last-request log.
$logFile = getenv('PANEL_STUB_LOG') ?: (sys_get_temp_dir() . '/panel-stub-' . getmypid() . '.log');

header('Content-Type: application/json');

if ($method === 'GET') {
    if ($path === '/api/v1/status.json' || $path === '/api/v1/status') {
        echo json_encode(['status' => 'ok', 'version' => 'stub', 'runtimeState' => 'loaded']);
        exit;
    }
    if ($path === '/api/v1/profile.json' || $path === '/api/v1/profile') {
        echo json_encode([[
            '_id' => 'stub-p1',
            'defaultProfile' => 'apitester',
            'startDate' => gmdate('c'),
            'store' => [
                'apitester' => ['dia' => 3, 'units' => 'mg/dl'],
                'bob' => ['dia' => 6, 'units' => 'mg/dl'],
            ],
        ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(404);
    echo json_encode(['status' => 404, 'message' => 'Not Found', 'description' => 'stub has no ' . $path]);
    exit;
}

if ($method !== 'POST' && $method !== 'PUT') {
    http_response_code(405);
    echo json_encode(['status' => 405, 'message' => 'Method Not Allowed', 'description' => 'stub accepts POST/PUT/GET']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode((string)$raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['status' => 400, 'message' => 'Bad Request', 'description' => 'stub got invalid JSON']);
    exit;
}

// Remember exactly what arrived (body only, so the test can json_decode it
// and verify the panel forced the attribution fields before proxying).
file_put_contents($logFile, $raw);

$assign = static function (array $record, string $prefix): array {
    $record['_id'] = $prefix . '-' . substr(uniqid('', true), -8);
    return $record;
};

$created = [];
if ($body !== [] && isset($body[0]) && is_array($body[0])) {
    foreach ($body as $record) {
        $created[] = $assign($record, 'stub');
    }
} else {
    $created[] = $assign($body, 'stub');
}

http_response_code(200);
echo json_encode($created, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
