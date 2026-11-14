<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Self-check for the Nightscout backend registry (app/library/Backends.php).
 * Everything runs against a throwaway database; no network access happens —
 * the NsClient construction is verified through reflection instead.
 */

$dbFile = sys_get_temp_dir() . '/panel-backends-test-' . getmypid() . '.db';
@unlink($dbFile);
define('PANEL_DB_FILE', $dbFile);

require __DIR__ . '/../vendor/autoload.php';

use NightPanel\Backends;
use NightPanel\NsException;

$failures = 0;
$checks = 0;
function check(string $name, bool $ok): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  PASS  {$name}\n";
    } else {
        $failures++;
        echo "  FAIL  {$name}\n";
    }
}

/** Read a private NsClient property, so no request is needed to verify wiring. */
function clientProp(object $client, string $prop): string
{
    $ref = new ReflectionProperty($client, $prop);
    return (string)$ref->getValue($client);
}

echo "Backends\n";

// --- create -----------------------------------------------------------------
$id1 = Backends::create('主后端', 'http://127.0.0.1:1337/', 'secret-one');
$id2 = Backends::create('Backup', 'https://ns2.example.com', 'secret-two');
check('create returns increasing ids', $id1 > 0 && $id2 > $id1);
check('all() returns both backends', count(Backends::all()) === 2);
check('stored url keeps no trailing slash', Backends::find($id1)['url'] === 'http://127.0.0.1:1337');

// --- encrypted secrets ------------------------------------------------------
$row = Backends::find($id1);
check('secret is stored encrypted', str_starts_with((string)$row['secret_enc'], 'enc:v1:'));
check('stored secret is not the plaintext', !str_contains((string)$row['secret_enc'], 'secret-one'));
check('secret() decrypts the stored value', Backends::secret($row) === 'secret-one');

try {
    Backends::secret(['secret_enc' => 'plaintext']);
    check('secret() refuses unencrypted rows', false);
} catch (\RuntimeException $e) {
    check('secret() refuses unencrypted rows', true);
}

// --- clientFor --------------------------------------------------------------
$client = Backends::clientFor($id1);
check('clientFor builds the backend url', clientProp($client, 'baseUrl') === 'http://127.0.0.1:1337');
check('clientFor decrypts the admin secret', clientProp($client, 'adminSecret') === 'secret-one');
$client = Backends::clientFor(Backends::find($id2));
check('clientFor accepts a row as well', clientProp($client, 'baseUrl') === 'https://ns2.example.com');
try {
    Backends::clientFor(999);
    check('clientFor rejects unknown ids', false);
} catch (\RuntimeException $e) {
    check('clientFor rejects unknown ids', true);
}

// --- validation --------------------------------------------------------------
try {
    Backends::create('', 'http://x.example.com', 's');
    check('empty name is rejected', false);
} catch (NsException $e) {
    check('empty name is rejected', str_contains($e->getMessage(), '名称') || $e->httpStatus() === 400);
}
try {
    Backends::create('dup', 'http://x.example.com', 's');
    Backends::create('DUP', 'https://y.example.com', 's');
    check('duplicate names are rejected', false);
} catch (NsException $e) {
    check('duplicate names are rejected', str_contains($e->getMessage(), '占用') || $e->httpStatus() === 400);
}
try {
    Backends::create('ftp', 'ftp://x.example.com', 's');
    check('non-http urls are rejected', false);
} catch (NsException $e) {
    check('non-http urls are rejected', true);
}
try {
    Backends::create('bad secret', 'http://x.example.com', '');
    check('empty secret is rejected', false);
} catch (NsException $e) {
    check('empty secret is rejected', true);
}

// --- users + enable/disable + delete protection -------------------------------
$pdo = Backends::class; // keep the analyzer quiet about unused imports
$pdo = \NightPanel\Db::pdo();
$pdo->prepare(
    'INSERT INTO panel_users (username, password_hash, backend_id) VALUES ("alice", "x", ?)'
)->execute([$id1]);
check('countUsers counts assigned accounts', Backends::countUsers($id1) === 1);
check('countUsers ignores other backends', Backends::countUsers($id2) === 0);
check('nameFor resolves the friendly name', Backends::nameFor($id1) === '主后端');
check('nameFor dashes unknown ids', Backends::nameFor(999) === '-');

try {
    Backends::delete($id1);
    check('a backend with users cannot be deleted', false);
} catch (NsException $e) {
    check('a backend with users cannot be deleted', str_contains($e->getMessage(), '用户') || $e->httpStatus() === 400);
}
check('delete() keeps the protected backend', Backends::find($id1) !== null);

Backends::setEnabled($id1, false);
check('disable keeps the row in all()', count(Backends::all()) === 3);
$enabledRows = Backends::enabled();
check(
    'disable removes the row from enabled()',
    count($enabledRows) === 2 && !in_array('主后端', array_column($enabledRows, 'name'), true)
);
Backends::setEnabled($id1, true);
$enabledRows = Backends::enabled();
check('re-enable restores the backend', count($enabledRows) === 3);
check('enabled() sorts by name', $enabledRows[0]['name'] === 'Backup');

// --- save keeps a blank secret ------------------------------------------------
Backends::save($id1, '主后端 2', 'http://10.0.0.5:1337', '');
$row = Backends::find($id1);
check('save() renames and moves the url', $row['name'] === '主后端 2' && $row['url'] === 'http://10.0.0.5:1337');
check('save() with blank secret keeps the ciphertext', Backends::secret($row) === 'secret-one');
Backends::save($id1, '主后端', 'http://127.0.0.1:1337', 'secret-three');
check('save() with a new secret replaces it', Backends::secret(Backends::find($id1)) === 'secret-three');

// --- delete -----------------------------------------------------------------
$pdo->prepare('DELETE FROM panel_users WHERE username = "alice"')->execute();
try {
    Backends::delete($id1);
    check('an empty backend can be deleted', Backends::find($id1) === null);
} catch (NsException $e) {
    check('an empty backend can be deleted', false);
}
try {
    Backends::delete($id1);
    check('deleting twice is rejected', false);
} catch (NsException $e) {
    check('deleting twice is rejected', true);
}

@unlink($dbFile);
@unlink($dbFile . '-wal');
@unlink($dbFile . '-shm');

echo $failures === 0 ? "\nOK — {$checks} checks passed\n" : "\n{$failures} of {$checks} checks failed\n";
exit($failures === 0 ? 0 : 1);
