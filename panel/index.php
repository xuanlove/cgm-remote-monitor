<?php
declare(strict_types=1);

require __DIR__ . '/lib/Db.php';
require __DIR__ . '/lib/Auth.php';
require __DIR__ . '/lib/NsClient.php';
require __DIR__ . '/lib/TreatmentTypes.php';
require __DIR__ . '/lib/GlucoseStats.php';
require __DIR__ . '/lib/Alerts.php';
require __DIR__ . '/lib/ReportCache.php';
require __DIR__ . '/lib/Lang.php';
require __DIR__ . '/lib/helpers.php';

use NightPanel\Alerts;
use NightPanel\Auth;
use NightPanel\Db;
use NightPanel\GlucoseStats;
use NightPanel\Lang;
use NightPanel\NsClient;
use NightPanel\NsException;
use NightPanel\ReportCache;
use NightPanel\TreatmentTypes;

Auth::boot();
Lang::init();
Auth::bootstrapAdmin();

/* ------------------------------------------------------------------ config */

function nsBaseUrl(): string
{
    $env = getenv('NIGHTSCOUT_URL');
    if ($env !== false && $env !== '') {
        return rtrim($env, '/');
    }
    return rtrim((string)(Db::setting('ns_url') ?? ''), '/');
}

function nsAdminSecret(): string
{
    $env = getenv('NIGHTSCOUT_ADMIN_SECRET');
    if ($env !== false && $env !== '') {
        return $env;
    }
    return (string)(Db::setting('ns_secret') ?? '');
}

function nsClient(): NsClient
{
    static $client = null;
    if ($client === null) {
        $url = nsBaseUrl();
        $secret = nsAdminSecret();
        if ($url === '' || $secret === '') {
            throw new NsException(Lang::t('err.nsNotConfigured'));
        }
        $client = new NsClient($url, $secret);
    }
    return $client;
}

function view(string $name, array $vars = []): void
{
    extract($vars);
    $user = Auth::user();
    $template = $name;
    require __DIR__ . '/views/layout.php';
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------------------------------------------------------------------- model */

function findUser(string $username): ?array
{
    $stmt = Db::pdo()->prepare('SELECT * FROM panel_users WHERE username = ? COLLATE NOCASE');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function validUsername(string $username): bool
{
    return (bool)preg_match('/^[a-z0-9][a-z0-9_-]{2,23}$/', $username);
}

/**
 * Provision a user inside the single Nightscout instance:
 *  1. ensure store[<username>] exists in the active profile record
 *  2. create the per-user role with scoped profile permissions
 *  3. create the subject bound to that role
 * All three steps are idempotent, so a retry after a partial failure heals.
 */
function provisionUser(string $username): array
{
    $client = nsClient();

    // 1. profile store entry
    $record = $client->currentProfileRecord();
    if ($record === null) {
        // First ever profile document: create it with the user's default entry.
        $default = defaultProfileTemplate();
        $client->requestOrThrow('POST', '/api/v1/profile.json', [
            'store' => [$username => $default],
            'defaultProfile' => $username,
            'startDate' => gmdate('c'),
        ]);
    } else {
        $store = $record['store'] ?? [];
        if (!isset($store[$username])) {
            $store[$username] = defaultProfileTemplate();
            $record['store'] = $store;
            $client->requestOrThrow('PUT', '/api/v1/profile.json', $record);
        }
    }

    // 2. role with scoped profile grants (write-only data permissions)
    $roleName = $username . '-profile';
    $permissions = [
        'api:profile:' . $username . ':read',
        'api:profile:' . $username . ':write',
        'api:profile:' . $username . ':create',
        // Scoped read: the token can pull its own CGM readings straight from
        // Nightscout, but never another device's.
        'api:entries:' . $username . ':read',
        'api:entries:create',
        'api:treatments:create',
        'api:activity:create',
        'api:devicestatus:create',
    ];
    try {
        $client->createRole($roleName, $permissions);
    } catch (NsException $e) {
        if (strpos($e->getMessage(), '400') === false) {
            throw $e;
        }
        // already exists from a previous attempt - keep going
    }

    // 3. subject
    $subject = $client->createSubject($username, [$roleName]);
    if (empty($subject['accessToken'])) {
        throw new NsException('Nightscout did not return an access token for the new subject.');
    }

    return [
        'subjectId' => (string)$subject['_id'],
        'roleName' => $roleName,
        'token' => (string)$subject['accessToken'],
    ];
}

function defaultProfileTemplate(): array
{
    return [
        'units' => 'mg/dl',
        'dia' => 3,
        'carbs_hr' => 30,
        'carbs_req_units' => 'g',
        'sens' => [[0, 50]],
        'target_low' => [[0, 80]],
        'target_high' => [[0, 130]],
        'basal' => [[0, 1]],
        'timezone' => 'Asia/Chongqing',
    ];
}

/**
 * Readings for the session user across a range (cached per finished day).
 *
 * @return array<int,array{ts:int,v:float,sgv:float,direction:?string,delta:?float,dateString:string}>
 */
function fetch_readings(array $user, int $startMs, int $endMs): array
{
    return ReportCache::readings(
        nsClient(),
        (int)$user['id'],
        (string)$user['username'],
        $startMs,
        $endMs
    );
}

/** Clamp the export/report window to the supported range. */
function requested_days(): int
{
    $days = (int)($_GET['days'] ?? 14);
    return max(1, min(90, $days));
}

/** Stream a CSV download (with BOM so Excel reads UTF-8 correctly). */
function csv_out(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

/**
 * A record belongs to the session user only when the stored attribution says
 * so. Both fields are written by the server, so a client cannot claim them.
 */
function self_owned(array $record, array $user): bool
{
    return isset($record['enteredBy']) && (string)$record['enteredBy'] === (string)$user['username'];
}

/**
 * Latest value of a profile time-shifted array (stored in mg/dL) as mmol/L.
 */
function self_target(array $profile, string $field, float $fallback): float
{
    $pairs = $profile[$field] ?? null;
    if (!is_array($pairs) || count($pairs) === 0) {
        return $fallback;
    }
    $last = $pairs[count($pairs) - 1];
    $value = is_array($last) ? (float)($last[1] ?? 0) : (float)$last;
    return $value > 0 ? round($value / 18, 2) : $fallback;
}

/**
 * The session user's treatment plans (profile records). Nightscout sorts
 * profile records by startDate descending, so the first record is the one
 * currently in effect.
 *
 * @return array<int,array<string,mixed>>
 */
function collect_plans(string $username): array
{
    $plans = [];
    foreach (nsClient()->profiles() as $index => $record) {
        if (!isset($record['store'][$username])) {
            continue;
        }
        $own = $record['store'][$username];
        $targetLow = $own['target_low'] ?? [];
        $targetHigh = $own['target_high'] ?? [];
        $plans[] = [
            'id' => (string)$record['_id'],
            'startDate' => (string)($record['startDate'] ?? ''),
            'active' => $index === 0,
            'shared' => count(array_keys($record['store'])) > 1,
            'dia' => $own['dia'] ?? null,
            'basalCount' => count($own['basal'] ?? []),
            'targetLow' => $targetLow ? $targetLow[count($targetLow) - 1][1] : null,
            'targetHigh' => $targetHigh ? $targetHigh[count($targetHigh) - 1][1] : null,
        ];
    }
    return $plans;
}

/* ------------------------------------------------------------------ routes */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch (true) {

        case $path === '/' && $method === 'GET':
            header('Location: ' . (Auth::check() ? '/dashboard' : '/login'));
            exit;

        case $path === '/login' && $method === 'GET':
            if (Auth::check()) { header('Location: /dashboard'); exit; }
            view('login', ['error' => null]);
            break;

        case $path === '/login' && $method === 'POST':
            Auth::assertCsrf($_POST['csrf'] ?? null);
            $user = findUser(trim((string)($_POST['username'] ?? '')));
            if ($user === null || !password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
                view('login', ['error' => Lang::t('login.invalid')]);
                break;
            }
            Auth::login($user);
            header('Location: /dashboard');
            exit;

        case $path === '/logout':
            Auth::logout();
            header('Location: /login');
            exit;

        case $path === '/lang' && $method === 'POST':
            Auth::assertCsrf($_POST['csrf'] ?? null);
            Lang::set((string)($_POST['lang'] ?? ''));
            // Back to the page the switcher was used on, defaulting to the
            // dashboard for a fresh (or forged) request.
            $back = (string)($_POST['back'] ?? '');
            $target = preg_match('#^/[a-z0-9/?=_.&-]*$#i', $back) === 1 ? $back : '/dashboard';
            header('Location: ' . $target);
            exit;

        case $path === '/register' && $method === 'GET':
            view('register', ['error' => null, 'success' => null, 'info' => null]);
            break;

        case $path === '/register' && $method === 'POST':
            Auth::assertCsrf($_POST['csrf'] ?? null);
            $username = strtolower(trim((string)($_POST['username'] ?? '')));
            $password = (string)($_POST['password'] ?? '');
            $error = null;

            if (!validUsername($username)) {
                $error = Lang::t('val.usernameFormat');
            } elseif (strlen($password) < 8) {
                $error = Lang::t('val.passwordLength');
            } elseif (findUser($username) !== null) {
                $error = Lang::t('val.usernameTaken');
            }

            if ($error !== null) {
                view('register', ['error' => $error, 'success' => null, 'info' => null]);
                break;
            }

            try {
                $provisioned = provisionUser($username);
            } catch (NsException $e) {
                view('register', ['error' => Lang::t('val.provisionFailed', ['msg' => $e->getMessage()]), 'success' => null, 'info' => null]);
                break;
            }

            $stmt = Db::pdo()->prepare(
                'INSERT INTO panel_users (username, password_hash, role, ns_subject_id, ns_role_name, ns_access_token_enc)
                 VALUES (?, ?, "user", ?, ?, ?)'
            );
            $stmt->execute([
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $provisioned['subjectId'],
                $provisioned['roleName'],
                Db::encrypt($provisioned['token']),
            ]);

            view('register', [
                'error' => null,
                'success' => true,
                'info' => [
                    'username' => $username,
                    'nsUrl' => nsBaseUrl(),
                    'token' => $provisioned['token'],
                ],
            ]);
            break;

        case $path === '/dashboard' && $method === 'GET':
            $user = Auth::requireUser();
            view('dashboard', ['user' => $user]);
            break;

        case $path === '/data/glucose.json' && $method === 'GET':
            $user = Auth::requireUser();
            // Attribution is taken from the session, never from the request.
            $device = $user['username'];
            $client = nsClient();

            // A ?date=YYYY-MM-DD parameter rewinds to a whole historical day;
            // without it the default window is "today" (the client narrows it
            // to its selected hour span).
            if (!empty($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date'])) {
                $startMs = strtotime($_GET['date'] . ' 00:00:00') * 1000;
                $endMs = strtotime($_GET['date'] . ' 23:59:59') * 1000 + 60000;
            } else {
                $endMs = (int)round(microtime(true) * 1000);
                $startMs = $endMs - 30 * 3600 * 1000;
            }

            $entries = $client->entriesInRange($device, 'sgv', $startMs, $endMs, 1500);
            $mbg = $client->entriesInRange($device, 'mbg', $startMs, $endMs, 100);
            $profileRecord = $client->currentProfileRecord();
            $own = $profileRecord['store'][$user['username']] ?? null;
            $status = $client->latestDeviceStatus($device);
            json_out([
                'entries' => $entries,
                'mbg' => $mbg,
                'devicestatus' => $status,
                'profile' => $own,
                'alerts' => Alerts::load((int)$user['id']),
            ]);

        case $path === '/data/treatments.json' && $method === 'GET':
            $user = Auth::requireUser();
            $treatments = nsClient()->treatmentsByUser($user['username'], 100);
            json_out(['treatments' => $treatments]);

        /* ------------------------------------------- D1 实时推送（SSE） */

        case $path === '/stream' && $method === 'GET':
            $user = Auth::requireUser();

            // The stream stays open, so the session lock has to be released
            // first: otherwise this browser could not load any other panel page
            // while the dashboard is listening.
            session_write_close();
            set_time_limit(0);

            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-store');
            header('X-Accel-Buffering: no');
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $device = (string)$user['username'];
            $deadline = time() + 110;
            $lastTs = 0;
            $first = true;

            while (time() < $deadline && !connection_aborted()) {
                try {
                    $records = nsClient()->entriesByDevice($device, 1);
                    $entry = $records[0] ?? null;
                    if ($entry !== null) {
                        $ts = (int)($entry['date'] ?? 0);
                        // The first pass always sends the latest reading so the
                        // page renders immediately after connecting.
                        if ($ts > $lastTs || $first) {
                            $lastTs = $ts;
                            echo "event: reading\n";
                            echo 'data: ' . json_encode([
                                'ts' => $ts,
                                'sgv' => (float)$entry['sgv'],
                                'v' => round(((float)$entry['sgv']) / 18, 2),
                                'direction' => $entry['direction'] ?? null,
                                'delta' => $entry['delta'] ?? null,
                            ], JSON_UNESCAPED_SLASHES) . "\n\n";
                        } else {
                            echo ": ping\n\n";
                        }
                    } else {
                        echo ": ping\n\n";
                    }
                    @flush();
                } catch (NsException $e) {
                    echo "event: streamerror\n";
                    echo 'data: ' . json_encode(['message' => $e->getMessage()], JSON_UNESCAPED_SLASHES) . "\n\n";
                    @flush();
                    break;
                } catch (\Throwable $e) {
                    break;
                }
                $first = false;
                sleep(8);
            }

            // Leaving ends the response; EventSource reconnects on its own, so
            // a long-lived stream never pins a worker for good.
            echo "event: bye\n";
            echo "data: {}\n\n";
            @flush();
            exit;

        /* ------------------------------------------- D2 个人提醒线设置 */

        case $path === '/settings/alerts' && $method === 'GET':
            $user = Auth::requireUser();
            view('alerts', [
                'user' => $user,
                'settings' => Alerts::load((int)$user['id']),
                'error' => null,
                'success' => null,
            ]);
            break;

        case $path === '/settings/alerts' && $method === 'POST':
            $user = Auth::requireUser();
            Auth::assertCsrf($_POST['csrf'] ?? null);
            [$settings, $error] = Alerts::save((int)$user['id'], $_POST);
            view('alerts', [
                'user' => $user,
                'settings' => $settings,
                'error' => $error,
                'success' => $error === null ? Lang::t('msg.alertSaved') : null,
            ]);
            break;

        case $path === '/profile' && $method === 'GET':
            $user = Auth::requireUser();
            $record = nsClient()->currentProfileRecord();
            $own = $record['store'][$user['username']] ?? null;
            view('profile', ['user' => $user, 'profile' => $own, 'error' => null, 'success' => null]);
            break;

        case $path === '/profile' && $method === 'POST':
            $user = Auth::requireUser();
            Auth::assertCsrf($_POST['csrf'] ?? null);
            try {
                $record = nsClient()->currentProfileRecord();
                if ($record === null || !isset($record['store'][$user['username']])) {
                    throw new NsException('Profile record missing');
                }

                // Parse "offset,value" lines into Nightscout profile arrays.
                function parsePairs(string $raw): array
                {
                    $out = [];
                    foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
                        $line = trim($line);
                        if ($line === '') continue;
                        $parts = array_map('trim', explode(',', $line));
                        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                            throw new \InvalidArgumentException(Lang::t('val.pairFormat', ['line' => $line]));
                        }
                        $out[] = [(float)$parts[0], (float)$parts[1]];
                    }
                    return $out;
                }

                $own = $record['store'][$user['username']];
                $own['sens'] = parsePairs((string)($_POST['sens'] ?? ''));
                $own['target_low'] = parsePairs((string)($_POST['target_low'] ?? ''));
                $own['target_high'] = parsePairs((string)($_POST['target_high'] ?? ''));
                $own['basal'] = parsePairs((string)($_POST['basal'] ?? ''));
                $own['dia'] = (float)($_POST['dia'] ?? 3);
                $own['carbs_hr'] = (float)($_POST['carbs_hr'] ?? 30);
                $own['timezone'] = trim((string)($_POST['timezone'] ?? 'UTC'));
                $own['units'] = 'mg/dl';

                // Scoped PUT with the USER token: Nightscout enforces that only
                // store[<username>] changes, no matter what this body contains.
                $token = Auth::userToken($user);
                $client = nsClient();
                $body = ['_id' => (string)$record['_id'], 'store' => [$user['username'] => $own]];
                $client->requestOrThrow('PUT', '/api/v1/profile.json', $body, $token);

                view('profile', ['user' => $user, 'profile' => $own, 'error' => null, 'success' => true]);
            } catch (\InvalidArgumentException $e) {
                $record = nsClient()->currentProfileRecord();
                view('profile', [
                    'user' => $user,
                    'profile' => $record['store'][$user['username']] ?? null,
                    'error' => Lang::t('msg.saveFailed', ['msg' => $e->getMessage()]),
                    'success' => null,
                ]);
            } catch (NsException $e) {
                $record = nsClient()->currentProfileRecord();
                view('profile', [
                    'user' => $user,
                    'profile' => $record['store'][$user['username']] ?? null,
                    'error' => Lang::t('msg.saveFailed', ['msg' => $e->getMessage()]),
                    'success' => null,
                ]);
            }
            break;

        case $path === '/entry' && $method === 'GET':
            $user = Auth::requireUser();
            view('entry', [
                'user' => $user,
                'types' => TreatmentTypes::types(),
                'fields' => TreatmentTypes::fields(),
                'error' => null,
                'success' => null,
                'preset' => (string)($_GET['type'] ?? ''),
            ]);
            break;

        case $path === '/entry' && $method === 'POST':
            $user = Auth::requireUser();
            Auth::assertCsrf($_POST['csrf'] ?? null);
            $kind = (string)($_POST['kind'] ?? '');
            try {
                $client = nsClient();
                switch ($kind) {
                    case 'glucose':
                        $sgv = (float)($_POST['sgv'] ?? 0);
                        if ($sgv < 10 || $sgv > 600) { throw new \InvalidArgumentException(Lang::t('val.glucoseRange')); }
                        $client->createEntry($user['username'], $sgv, ($_POST['timestamp'] ?? '') ?: null);
                        break;
                    case 'treatment':
                        // Every event type goes through the registry, so only the
                        // fields that belong to the chosen type are written.
                        $eventType = (string)($_POST['eventType'] ?? '');
                        if (!TreatmentTypes::has($eventType)) {
                            throw new \InvalidArgumentException(Lang::t('val.unsupportedEvent'));
                        }
                        $record = TreatmentTypes::build($eventType, $_POST);
                        $client->createTreatment($record, $user['username']);
                        break;
                    case 'activity':
                        $client->createActivity([
                            'eventType' => trim((string)($_POST['activityType'] ?? 'Exercise')),
                            'duration' => (($_POST['duration'] ?? '') !== '') ? (int)$_POST['duration'] : null,
                            'notes' => trim((string)($_POST['notes'] ?? '')),
                        ], $user['username']);
                        break;
                    default:
                        throw new \InvalidArgumentException(Lang::t('val.unknownKind'));
                }
                view('entry', [
                    'user' => $user,
                    'types' => TreatmentTypes::types(),
                    'fields' => TreatmentTypes::fields(),
                    'error' => null,
                    'success' => Lang::t('msg.dataSubmitted'),
                    'preset' => '',
                ]);
            } catch (\InvalidArgumentException $e) {
                view('entry', [
                    'user' => $user,
                    'types' => TreatmentTypes::types(),
                    'fields' => TreatmentTypes::fields(),
                    'error' => $e->getMessage(),
                    'success' => null,
                    'preset' => (string)($_POST['eventType'] ?? ''),
                ]);
            } catch (NsException $e) {
                view('entry', [
                    'user' => $user,
                    'types' => TreatmentTypes::types(),
                    'fields' => TreatmentTypes::fields(),
                    'error' => Lang::t('msg.submitFailed', ['msg' => $e->getMessage()]),
                    'success' => null,
                    'preset' => (string)($_POST['eventType'] ?? ''),
                ]);
            }
            break;

        /* ---------------------------------------------------- B3 记录编辑/删除 */

        case $path === '/treatments' && $method === 'GET':
            $user = Auth::requireUser();
            $client = nsClient();
            $editing = null;
            $editId = (string)($_GET['edit'] ?? '');
            if ($editId !== '') {
                $existing = $client->treatmentById($editId);
                // Ownership is decided here, never by what the request claims.
                if ($existing !== null && self_owned($existing, $user)) {
                    $editing = $existing;
                }
            }
            view('treatments', [
                'user' => $user,
                'types' => TreatmentTypes::types(),
                'fields' => TreatmentTypes::fields(),
                'treatments' => $client->treatmentsByUser($user['username'], 100),
                'editing' => $editing,
                'error' => null,
                'success' => null,
            ]);
            break;

        case $path === '/treatments/save' && $method === 'POST':
            $user = Auth::requireUser();
            Auth::assertCsrf($_POST['csrf'] ?? null);
            $client = nsClient();
            $id = (string)($_POST['_id'] ?? '');
            $existing = $id !== '' ? $client->treatmentById($id) : null;
            if ($existing === null || !self_owned($existing, $user)) {
                http_response_code(403);
                view('treatments', [
                    'user' => $user,
                    'types' => TreatmentTypes::types(),
                    'fields' => TreatmentTypes::fields(),
                    'treatments' => $client->treatmentsByUser($user['username'], 100),
                    'editing' => null,
                    'error' => Lang::t('val.notOwner'),
                    'success' => null,
                ]);
                break;
            }
            try {
                $eventType = (string)($_POST['eventType'] ?? $existing['eventType'] ?? 'Note');
                if (!TreatmentTypes::has($eventType)) {
                    throw new \InvalidArgumentException('不支持的事件类型');
                }
                $updated = TreatmentTypes::build($eventType, $_POST);
                // Identity and attribution are carried from the stored record,
                // so an edit can never hand a record to another user.
                $updated['_id'] = (string)$existing['_id'];
                $updated['enteredBy'] = (string)$existing['enteredBy'];
                $updated['created_at'] = (string)($existing['created_at'] ?? gmdate('c'));
                $client->updateTreatment($updated);
                header('Location: /treatments');
                exit;
            } catch (\InvalidArgumentException $e) {
                view('treatments', [
                    'user' => $user,
                    'types' => TreatmentTypes::types(),
                    'fields' => TreatmentTypes::fields(),
                    'treatments' => $client->treatmentsByUser($user['username'], 100),
                    'editing' => $existing,
                    'error' => Lang::t('msg.saveFailed', ['msg' => $e->getMessage()]),
                    'success' => null,
                ]);
            } catch (NsException $e) {
                view('treatments', [
                    'user' => $user,
                    'types' => TreatmentTypes::types(),
                    'fields' => TreatmentTypes::fields(),
                    'treatments' => $client->treatmentsByUser($user['username'], 100),
                    'editing' => $existing,
                    'error' => Lang::t('msg.saveFailed', ['msg' => $e->getMessage()]),
                    'success' => null,
                ]);
            }
            break;

        case $path === '/treatments/delete' && $method === 'POST':
            $user = Auth::requireUser();
            Auth::assertCsrf($_POST['csrf'] ?? null);
            $client = nsClient();
            $id = (string)($_POST['_id'] ?? '');
            $existing = $id !== '' ? $client->treatmentById($id) : null;
            if ($existing !== null && self_owned($existing, $user)) {
                $client->deleteTreatment($id);
            }
            header('Location: /treatments');
            exit;

        /* ------------------------------------------------ B2 治疗方案（版本）*/

        case $path === '/profile/plans' && $method === 'GET':
            $user = Auth::requireUser();
            view('plans', [
                'user' => $user,
                'plans' => collect_plans($user['username']),
                'error' => null,
                'success' => null,
            ]);
            break;

        case $path === '/profile/plans/activate' && $method === 'POST':
            $user = Auth::requireUser();
            Auth::assertCsrf($_POST['csrf'] ?? null);
            $planId = (string)($_POST['_id'] ?? '');
            $error = null;
            try {
                $records = nsClient()->profiles();
                $source = null;
                foreach ($records as $record) {
                    if ((string)$record['_id'] === $planId) { $source = $record; break; }
                }
                if ($source === null || !isset($source['store'][$user['username']])) {
                    throw new \InvalidArgumentException(Lang::t('val.planNotFound'));
                }

                // The new record has to carry every other user's store entry as
                // well: Nightscout reads the current profile from the newest
                // record, so a record holding only this user would make everyone
                // else's profile disappear. Only this user's own entry is
                // replaced, and only with a value taken from a record they can
                // already read.
                $active = $records[0] ?? [];
                $store = is_array($active['store'] ?? null) ? $active['store'] : [];
                $store[$user['username']] = $source['store'][$user['username']];

                nsClient()->requestOrThrow('POST', '/api/v1/profile.json', [
                    'store' => $store,
                    'defaultProfile' => $active['defaultProfile'] ?? $user['username'],
                    'startDate' => gmdate('c'),
                ]);
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            } catch (NsException $e) {
                $error = Lang::t('msg.enableFailed', ['msg' => $e->getMessage()]);
            }
            if ($error === null) { header('Location: /profile/plans'); exit; }
            view('plans', ['user' => $user, 'plans' => collect_plans($user['username']), 'error' => $error, 'success' => null]);
            break;

        case $path === '/profile/plans/delete' && $method === 'POST':
            $user = Auth::requireUser();
            Auth::assertCsrf($_POST['csrf'] ?? null);
            $planId = (string)($_POST['_id'] ?? '');
            $error = null;
            try {
                $records = nsClient()->profiles();
                $target = null;
                $index = -1;
                foreach ($records as $i => $record) {
                    if ((string)$record['_id'] === $planId) { $target = $record; $index = $i; break; }
                }
                if ($target === null) {
                    throw new \InvalidArgumentException(Lang::t('val.planNotFound'));
                }
                // A shared record holds other users' entries too: deleting it
                // would destroy their plans, so only single-owner records go.
                $keys = array_keys($target['store'] ?? []);
                if ($keys !== [$user['username']]) {
                    throw new \InvalidArgumentException(Lang::t('val.planShared'));
                }
                if ($index === 0) {
                    throw new \InvalidArgumentException(Lang::t('val.planActive'));
                }
                nsClient()->requestOrThrow('DELETE', '/api/v1/profile/' . rawurlencode($planId));
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            } catch (NsException $e) {
                $error = Lang::t('msg.deleteFailed', ['msg' => $e->getMessage()]);
            }
            if ($error === null) { header('Location: /profile/plans'); exit; }
            view('plans', ['user' => $user, 'plans' => collect_plans($user['username']), 'error' => $error, 'success' => null]);
            break;

        /* ------------------------------------------- C1 TIR / 血糖分布报告 */

        case $path === '/reports' && $method === 'GET':
            $user = Auth::requireUser();
            $days = requested_days();

            $endMs = (int)round(microtime(true) * 1000);
            $startMs = $endMs - $days * 86400 * 1000;

            $readings = fetch_readings($user, $startMs, $endMs);

            // The user's own profile target decides "目标范围"; the consensus
            // 3.9-10.0 band decides TIR/TAR/TBR.
            $record = nsClient()->currentProfileRecord();
            $own = $record['store'][$user['username']] ?? [];
            $targetLow = self_target($own, 'target_low', 3.9);
            $targetHigh = self_target($own, 'target_high', 10.0);

            $stats = GlucoseStats::compute($readings, $targetLow, $targetHigh);

            view('reports', [
                'user' => $user,
                'days' => $days,
                'stats' => $stats,
                'from' => date('Y-m-d H:i', (int)($startMs / 1000)),
                'to' => date('Y-m-d H:i', (int)($endMs / 1000)),
                'error' => null,
            ]);
            break;

        /* -------------------------------------------------- E1 数据导出 */

        case $path === '/export/entries.csv' && $method === 'GET':
            $user = Auth::requireUser();
            $days = requested_days();
            $endMs = (int)round(microtime(true) * 1000);
            $startMs = $endMs - $days * 86400 * 1000;
            $rows = [];
            foreach (fetch_readings($user, $startMs, $endMs) as $reading) {
                $rows[] = [
                    gmdate('Y-m-d H:i:s', (int)($reading['ts'] / 1000)),
                    $reading['sgv'],
                    $reading['v'],
                    $reading['direction'] ?? '',
                    $reading['delta'] ?? '',
                ];
            }
            csv_out(
                'glucose-' . $user['username'] . '-' . $days . 'd.csv',
                [Lang::t('csv.timeUtc'), Lang::t('csv.glucoseMgdl'), Lang::t('csv.glucoseMmol'), Lang::t('csv.direction'), Lang::t('csv.delta')],
                $rows
            );

        case $path === '/export/entries.json' && $method === 'GET':
            $user = Auth::requireUser();
            $days = requested_days();
            $endMs = (int)round(microtime(true) * 1000);
            $startMs = $endMs - $days * 86400 * 1000;
            $readings = fetch_readings($user, $startMs, $endMs);
            header('Content-Disposition: attachment; filename="glucose-' . $user['username'] . '-' . $days . 'd.json"');
            json_out([
                'user' => $user['username'],
                'from' => gmdate('c', (int)($startMs / 1000)),
                'to' => gmdate('c', (int)($endMs / 1000)),
                'count' => count($readings),
                'readings' => array_map(static fn(array $r): array => [
                    'time' => gmdate('c', (int)($r['ts'] / 1000)),
                    'mgdl' => $r['sgv'],
                    'mmol' => $r['v'],
                    'direction' => $r['direction'],
                    'delta' => $r['delta'],
                ], $readings),
            ]);

        case $path === '/export/treatments.csv' && $method === 'GET':
            $user = Auth::requireUser();
            $days = requested_days();
            $endMs = (int)round(microtime(true) * 1000);
            $startMs = $endMs - $days * 86400 * 1000;
            $rows = [];
            $treatments = nsClient()->treatmentsBetween($user['username'], $startMs, $endMs);
            usort($treatments, static fn(array $a, array $b): int => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')));
            foreach ($treatments as $t) {
                $rows[] = [
                    (string)($t['created_at'] ?? ''),
                    (string)($t['eventType'] ?? ''),
                    $t['glucose'] ?? '',
                    $t['carbs'] ?? '',
                    $t['insulin'] ?? '',
                    $t['duration'] ?? '',
                    $t['absolute'] ?? '',
                    $t['percent'] ?? '',
                    (string)($t['notes'] ?? ''),
                ];
            }
            csv_out(
                'treatments-' . $user['username'] . '-' . $days . 'd.csv',
                [Lang::t('csv.time'), Lang::t('csv.type'), Lang::t('csv.glucoseMgdl'), Lang::t('csv.carbs'), Lang::t('csv.insulin'), Lang::t('csv.duration'), Lang::t('csv.basalRate'), Lang::t('csv.percent'), Lang::t('csv.notes')],
                $rows
            );

        case $path === '/myapi' && $method === 'GET':
            $user = Auth::requireUser();
            $token = Auth::userToken($user);
            view('myapi', ['user' => $user, 'nsUrl' => nsBaseUrl(), 'token' => $token]);
            break;

        case $path === '/admin/users' && $method === 'GET':
            $admin = Auth::requireAdmin();
            $users = Db::pdo()->query('SELECT id, username, role, ns_subject_id, created_at FROM panel_users ORDER BY id')->fetchAll();
            $nsStatus = null;
            $nsError = null;
            try { $nsStatus = nsClient()->status(); } catch (NsException $e) { $nsError = $e->getMessage(); }
            view('admin', ['admin' => $admin, 'users' => $users, 'nsStatus' => $nsStatus, 'nsError' => $nsError]);
            break;

        case $path === '/admin/users/delete' && $method === 'POST':
            Auth::assertCsrf($_POST['csrf'] ?? null);
            Auth::requireAdmin();
            $id = (int)($_POST['id'] ?? 0);
            $stmt = Db::pdo()->prepare('SELECT * FROM panel_users WHERE id = ? AND role != "admin"');
            $stmt->execute([$id]);
            $victim = $stmt->fetch();
            if ($victim !== false) {
                if (!empty($victim['ns_subject_id'])) {
                    try { nsClient()->deleteSubject((string)$victim['ns_subject_id']); } catch (NsException $e) { /* keep going */ }
                }
                $del = Db::pdo()->prepare('DELETE FROM panel_users WHERE id = ?');
                $del->execute([$id]);
            }
            header('Location: /admin/users');
            exit;

        case $path === '/admin/settings' && $method === 'POST':
            Auth::assertCsrf($_POST['csrf'] ?? null);
            Auth::requireAdmin();
            Db::setSetting('ns_url', rtrim(trim((string)($_POST['ns_url'] ?? '')), '/'));
            Db::setSetting('ns_secret', trim((string)($_POST['ns_secret'] ?? '')));
            header('Location: /admin/users');
            exit;

        default:
            http_response_code(404);
            view('404', []);
    }
} catch (NsException $e) {
    http_response_code(502);
    view('error', ['message' => Lang::t('err.nsUnavailable', ['msg' => $e->getMessage()])]);
} catch (\Throwable $e) {
    http_response_code(500);
    view('error', ['message' => Lang::t('err.panel', ['msg' => $e->getMessage()])]);
}
