<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Self-check for public/install.php. The installer is a procedural script
 * that exits when done, so each scenario runs in its own PHP subprocess
 * against a throwaway directory; the parent process then inspects the result
 * with a raw PDO handle (the panel constant PANEL_DB_FILE can only be
 * defined once per process).
 */

$root = dirname(__DIR__);
$base = sys_get_temp_dir() . '/panel-install-test-' . getmypid();

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

/**
 * Run one installer scenario in a subprocess; returns the captured stdout.
 * The installer's CSRF token lives in its PHP session, and session_start()
 * discards anything assigned to $_SESSION beforehand — so the fixture pins a
 * session id whose file pre-carries the token, exactly like a browser that
 * first rendered the form.
 */
function runInstaller(string $dir, string $method, array $post): array
{
    global $root;
    $dbFile = $dir . '/panel.db';
    $fixture = $dir . '/fixture.php';
    $sessFile = $dir . '/sess_testsess';
    if (!is_file($sessFile)) {
        file_put_contents($sessFile, 'install_csrf|s:9:"testtoken";');
    }
    $code = "<?php\n"
        . 'define(\'PANEL_DB_FILE\', ' . var_export($dbFile, true) . ");\n"
        . 'ini_set(\'session.save_path\', ' . var_export($dir, true) . ");\n"
        . 'session_id(\'testsess\');' . "\n"
        . '$_SERVER[\'REQUEST_METHOD\'] = ' . var_export($method, true) . ";\n"
        . '$_POST = ' . var_export($post, true) . ";\n"
        . 'require ' . var_export($root . '/public/install.php', true) . ";\n";
    file_put_contents($fixture, $code);

    $stdout = shell_exec('php ' . escapeshellarg($fixture) . ' 2>&1');
    return [(string)$stdout];
}

/** A fresh scenario directory. */
function scenario(string $name): string
{
    global $base;
    $dir = $base . '/' . $name;
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
    } else {
        mkdir($dir, 0770, true);
    }
    return $dir;
}

/** Raw read-only handle onto a scenario database. */
function db(string $dir): PDO
{
    $pdo = new PDO('sqlite:' . $dir . '/panel.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

echo "Installer\n";

// --- GET renders the wizard on a fresh directory ------------------------------
$dir = scenario('render');
[$out] = runInstaller($dir, 'GET', []);
check('wizard renders', str_contains($out, '安装 Nightscout 多用户面板') && str_contains($out, 'name="admin_user"'));
check('wizard checks the environment', str_contains($out, '环境自检'));
check('wizard lists the site options', str_contains($out, 'trust_proxy'));
check('fresh run creates no lock', !is_file($dir . '/install.lock'));
check('fresh run creates no database', !is_file($dir . '/panel.db'));

// --- a valid POST installs ------------------------------------------------------
$dir = scenario('install');
[$out] = runInstaller($dir, 'POST', [
    'csrf' => 'testtoken',
    'admin_user' => 'Admin',
    'admin_pass' => 'password123',
    'admin_pass2' => 'password123',
    'ns_name' => '主后端',
    'ns_url' => 'http://127.0.0.1:1337/',
    'ns_secret' => 'topsecret',
    'lang' => 'en',
    'trust_proxy' => '1',
]);
check('install exits cleanly', !str_contains($out, 'Fatal') && !str_contains($out, 'Warning')
    && !str_contains($out, 'class="error"'));
check('install writes the lock file', is_file($dir . '/install.lock')
    && str_starts_with((string)file_get_contents($dir . '/install.lock'), 'installed '));

$pdo = db($dir);
$admin = $pdo->query('SELECT * FROM panel_users')->fetch();
check('admin account is created', ($admin['username'] ?? '') === 'admin');
check('admin username is lowercased', $admin !== false && $admin['username'] === 'admin');
check('admin has the admin role', ($admin['role'] ?? '') === 'admin');
check('admin belongs to no backend', $admin !== false && $admin['backend_id'] === null);
check('admin password is hashed and verifies',
    password_verify('password123', (string)($admin['password_hash'] ?? '')));

$backend = $pdo->query('SELECT * FROM panel_ns_backends')->fetch();
check('the first backend is created', ($backend['name'] ?? '') === '主后端');
check('backend url keeps no trailing slash', ($backend['url'] ?? '') === 'http://127.0.0.1:1337');
check('backend secret is stored encrypted', str_starts_with((string)($backend['secret_enc'] ?? ''), 'enc:v1:')
    && !str_contains((string)$backend['secret_enc'], 'topsecret'));

$settings = [];
foreach ($pdo->query('SELECT key, value FROM panel_settings') as $row) {
    $settings[$row['key']] = $row['value'];
}
check('key seed is written (64 hex)', isset($settings['key_seed']) && strlen((string)$settings['key_seed']) === 64
    && ctype_xdigit((string)$settings['key_seed']));
check('default language is written', ($settings['default_lang'] ?? '') === 'en');
check('trust_proxy is written', ($settings['trust_proxy'] ?? '') === '1');

// A second install in a different directory must pick a different seed and
// a different admin salt: ciphertext from one must not decrypt in the other.
$dirB = scenario('install-b');
runInstaller($dirB, 'POST', [
    'csrf' => 'testtoken',
    'admin_user' => 'admin',
    'admin_pass' => 'password456',
    'admin_pass2' => 'password456',
    'ns_name' => 'Backup',
    'ns_url' => 'http://127.0.0.1:1338',
    'ns_secret' => 'othersecret',
    'lang' => 'zh_cn',
]);
$settingsB = [];
foreach (db($dirB)->query('SELECT key, value FROM panel_settings') as $row) {
    $settingsB[$row['key']] = $row['value'];
}
check('key seed differs between installs', $settings['key_seed'] !== $settingsB['key_seed']);
check('second install wrote its own options', ($settingsB['default_lang'] ?? '') === 'zh_cn'
    && ($settingsB['trust_proxy'] ?? 'x') === '0');

// The installer's encrypted secret must decrypt with the freshly written seed.
define('PANEL_DB_FILE', $dir . '/panel.db');
require $root . '/vendor/autoload.php';
check('backend secret decrypts with the installed seed',
    \NightPanel\Backends::secret(\NightPanel\Backends::find((int)$backend['id'])) === 'topsecret');

// --- installed: the wizard locks itself ---------------------------------------
$dir2 = $dir;
[$out] = runInstaller($dir2, 'GET', []);
check('installed panel shows the lock page', str_contains($out, '已安装'));
check('lock page links to login', str_contains($out, '/login'));
check('lock page hides the form', !str_contains($out, 'name="admin_user"'));

// --- validation failures leave nothing behind -----------------------------------
$cases = [
    'bad admin username' => ['admin_user' => 'ab', 'error' => '管理员用户名'],
    'short password' => ['admin_pass' => 'short', 'admin_pass2' => 'short', 'error' => '密码至少'],
    'password mismatch' => ['admin_pass2' => 'different123', 'error' => '两次输入的密码不一致'],
    'missing backend name' => ['ns_name' => '', 'error' => '名称'],
    'missing backend url' => ['ns_url' => '', 'error' => '地址'],
    'missing secret' => ['ns_secret' => '', 'error' => '管理密钥'],
    'wrong url scheme' => ['ns_url' => 'ftp://x', 'error' => 'http/https'],
    'bad language' => ['lang' => 'fr', 'error' => '语言无效'],
];
foreach ($cases as $name => $spec) {
    $dir = scenario('fail-' . md5($name));
    $post = [
        'csrf' => 'testtoken',
        'admin_user' => 'admin',
        'admin_pass' => 'password123',
        'admin_pass2' => 'password123',
        'ns_name' => '主后端',
        'ns_url' => 'http://127.0.0.1:1337',
        'ns_secret' => 'topsecret',
        'lang' => 'zh_cn',
    ];
    foreach ($spec as $key => $value) {
        if ($key !== 'error') {
            $post[$key] = $value;
        }
    }
    [$out] = runInstaller($dir, 'POST', $post);
    check("{$name} is refused", str_contains($out, (string)$spec['error']));
    check("{$name} leaves no lock", !is_file($dir . '/install.lock'));
}

// CSRF mismatch: valid fields, wrong token.
$dir = scenario('fail-csrf');
$post = [
    'csrf' => 'WRONG',
    'admin_user' => 'admin',
    'admin_pass' => 'password123',
    'admin_pass2' => 'password123',
    'ns_name' => '主后端',
    'ns_url' => 'http://127.0.0.1:1337',
    'ns_secret' => 'topsecret',
    'lang' => 'zh_cn',
];
[$out] = runInstaller($dir, 'POST', $post);
check('csrf mismatch is refused', str_contains($out, '令牌'));
check('csrf mismatch leaves no lock', !is_file($dir . '/install.lock'));

// --- a legacy v1 database is refused --------------------------------------------
$dir = scenario('legacy');
$legacy = new PDO('sqlite:' . $dir . '/panel.db');
$legacy->exec('PRAGMA user_version = 1');
$legacy = null;
[$out] = runInstaller($dir, 'POST', [
    'csrf' => 'testtoken',
    'admin_user' => 'admin',
    'admin_pass' => 'password123',
    'admin_pass2' => 'password123',
    'ns_name' => '主后端',
    'ns_url' => 'http://127.0.0.1:1337',
    'ns_secret' => 'topsecret',
    'lang' => 'zh_cn',
]);
check('legacy v1 database is refused', str_contains($out, 'Legacy'));
check('legacy refusal writes no lock', !is_file($dir . '/install.lock'));

// --- cleanup --------------------------------------------------------------------
foreach (glob($base . '/*') ?: [] as $dir) {
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}
@rmdir($base);

echo $failures === 0 ? "\nOK — {$checks} checks passed\n" : "\n{$failures} of {$checks} checks failed\n";
exit($failures === 0 ? 0 : 1);
