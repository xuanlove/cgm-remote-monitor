<?php
declare(strict_types=1);

/**
 * One-shot web installer for the Nightscout multi-user panel.
 *
 * Everything configurable is written to the database here: the admin
 * account, the first Nightscout backend and the site options. No
 * environment variables are consulted. When the install completes, a lock
 * file appears and this script refuses to run again.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

$ROOT = dirname(__DIR__);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

if (session_status() === PHP_SESSION_NONE) {
    session_name('nightpanel');
    session_start();
}

/** HTML-escape. */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Render a page shell. The installer is deliberately self-contained: no
 * framework, no template engine, no layout dependencies.
 */
function page(string $title, string $body): void
{
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . '</title><style>'
        . 'body{font-family:system-ui,-apple-system,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;'
        . 'background:#10151d;color:#e6e9ef;margin:0;padding:32px 16px;line-height:1.6}'
        . '.card{max-width:640px;margin:0 auto;background:#171e29;border:1px solid #263243;'
        . 'border-radius:12px;padding:28px 32px}'
        . 'h1{font-size:20px;margin:0 0 18px}h2{font-size:15px;margin:26px 0 8px;color:#9fb3c8}'
        . 'label{display:block;margin:10px 0 4px;font-size:13px;color:#c8d3e0}'
        . 'input[type=text],input[type=password],input[type=url]{width:100%;box-sizing:border-box;'
        . 'padding:9px 11px;border:1px solid #33415a;border-radius:8px;background:#0d1219;color:#e6e9ef;font-size:14px}'
        . 'button{margin-top:22px;padding:10px 22px;border:0;border-radius:8px;background:#2f81f7;'
        . 'color:#fff;font-size:14px;cursor:pointer}button:disabled{background:#33415a;cursor:not-allowed}'
        . '.btn-ghost{background:#263243;margin-left:10px;padding:10px 14px}'
        . '.ok{color:#4cc38a}.bad{color:#ff6b6b}.warn{color:#e5c07b}'
        . 'ul.checks{list-style:none;padding:0;margin:0;font-size:14px}'
        . 'ul.checks li{padding:5px 0;border-bottom:1px dashed #263243}'
        . '.error{background:#3a1f2b;border:1px solid #7d2f45;color:#ffb3c1;padding:12px 14px;'
        . 'border-radius:8px;margin-bottom:16px;font-size:14px}'
        . '.hint{font-size:12px;color:#8494ab;margin-top:4px}'
        . '.radios label,.checks-box label{display:inline-block;margin-right:16px;font-size:14px;color:#e6e9ef}'
        . '#test-result{font-size:13px;margin-top:8px;min-height:20px}'
        . '.foot{font-size:12px;color:#6c7a91;margin-top:26px;text-align:center}'
        . '</style></head><body><div class="card">' . $body . '<div class="foot">Nightscout 多用户面板安装器</div></div></body></html>';
}

if (!is_file($ROOT . '/vendor/autoload.php')) {
    page('安装前准备', '<h1>缺少依赖</h1><p>未找到 <code>vendor/autoload.php</code>。请先在面板目录执行：</p>'
        . '<p><code>composer install --no-dev --optimize-autoloader</code></p>');
    exit;
}
require $ROOT . '/vendor/autoload.php';

$dbFile = panel_db_file();
$dataDir = dirname($dbFile);
$lockFile = panel_lock_file();

/**
 * Installed = lock file, or a database that already carries accounts or a
 * key seed (someone deleted the lock on a live install).
 */
function isInstalled(string $lockFile, string $dbFile): bool
{
    if (is_file($lockFile)) {
        return true;
    }
    if (!is_file($dbFile)) {
        return false;
    }
    try {
        \NightPanel\Db::setting('key_seed');
        $count = (int)\NightPanel\Db::pdo()
            ->query('SELECT COUNT(*) FROM panel_users')->fetchColumn();
        return $count > 0;
    } catch (\Throwable $notUsable) {
        return false;
    }
}

/** Environment requirements, checked fresh on every render. */
function environment(string $ROOT, string $dataDir): array
{
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0770, true);
    }
    if (!is_dir($ROOT . '/runtime')) {
        @mkdir($ROOT . '/runtime/temp', 0775, true);
    }
    if (!is_dir($ROOT . '/runtime/temp')) {
        @mkdir($ROOT . '/runtime/temp', 0775, true);
    }

    $checks = [];
    $checks[] = ['PHP >= 8.1（当前 ' . PHP_VERSION . '）', version_compare(PHP_VERSION, '8.1.0', '>=')];
    foreach (['pdo_sqlite', 'openssl', 'curl', 'json', 'mbstring'] as $ext) {
        $checks[] = ['PHP 扩展 ' . $ext, extension_loaded($ext)];
    }
    $checks[] = ['data/ 目录可写（' . $dataDir . '）', is_dir($dataDir) && is_writable($dataDir)];
    $checks[] = ['runtime/ 目录可写（模板缓存）', is_dir($ROOT . '/runtime') && is_writable($ROOT . '/runtime')];
    return $checks;
}

/** Resolve the posted string fields. */
function field(string $name, string $default = ''): string
{
    return trim((string)($_POST[$name] ?? $default));
}

function csrfToken(): string
{
    if (empty($_SESSION['install_csrf'])) {
        $_SESSION['install_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['install_csrf'];
}

// ---- already installed? ----------------------------------------------------
if (isInstalled($lockFile, $dbFile)) {
    page('面板已安装', '<h1>已安装</h1>'
        . '<p>面板已经完成安装，出于安全考虑安装器已锁定。</p>'
        . '<p class="hint">如需重新安装：备份并删除 <code>data/panel.db</code> 与 <code>data/install.lock</code> 后再打开本页。</p>'
        . '<p><a href="/login" style="color:#2f81f7">前往登录 →</a></p>');
    exit;
}

// ---- AJAX backend connection test ------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_GET['action'] ?? '') === 'test') {
    header('Content-Type: application/json; charset=utf-8');
    $out = static function (bool $ok, string $message, array $extra = []) {
        echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    };
    if (!hash_equals(csrfToken(), field('csrf'))) {
        $out(false, '会话已过期，请刷新页面');
    }
    $url = rtrim(field('url'), '/');
    $secret = field('secret');
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if (($scheme !== 'http' && $scheme !== 'https') || $secret === '') {
        $out(false, '地址必须是 http/https，管理密钥不能为空');
    }
    try {
        $status = (new \NightPanel\NsClient($url, $secret))->status();
        $out(true, '连接成功：' . ($status['name'] ?? 'Nightscout')
            . '（' . ($status['version'] ?? '未知版本') . '）');
    } catch (\Throwable $e) {
        $out(false, '连接失败：' . $e->getMessage());
    }
}

// ---- install ----------------------------------------------------------------
$checks = environment($ROOT, $dataDir);
$allOk = true;
foreach ($checks as [, $ok]) {
    $allOk = $allOk && $ok;
}

$error = '';
$defaults = [
    'admin_user' => 'admin',
    'ns_name' => '主后端',
    'ns_url' => '',
    'lang' => 'zh_cn',
    'trust_proxy' => true,
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $adminUser = strtolower(field('admin_user'));
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $adminPass2 = (string)($_POST['admin_pass2'] ?? '');
    $nsName = field('ns_name');
    $nsUrl = rtrim(field('ns_url'), '/');
    $nsSecret = (string)($_POST['ns_secret'] ?? '');
    $lang = field('lang', 'zh_cn');
    $trustProxy = isset($_POST['trust_proxy']);
    $defaults = [
        'admin_user' => $adminUser,
        'ns_name' => $nsName,
        'ns_url' => $nsUrl,
        'lang' => $lang,
        'trust_proxy' => $trustProxy,
    ];

    if (!hash_equals(csrfToken(), field('csrf'))) {
        $error = '令牌不匹配，请重新提交';
    } elseif (!$allOk) {
        $error = '环境自检未通过，请先修复上面标红的项目';
    } elseif (!preg_match('/^[a-z0-9][a-z0-9_-]{2,23}$/', $adminUser)) {
        $error = '管理员用户名需 3-24 位小写字母、数字、- 或 _，且以字母或数字开头';
    } elseif (strlen($adminPass) < 8) {
        $error = '管理员密码至少 8 位';
    } elseif ($adminPass !== $adminPass2) {
        $error = '两次输入的密码不一致';
    } elseif ($nsName === '') {
        $error = '请给第一个 Nightscout 后端取一个名称';
    } elseif ($nsUrl === '') {
        $error = '请填写 Nightscout 后端地址';
    } elseif ($nsSecret === '') {
        $error = '请填写 Nightscout 管理密钥（API_SECRET）';
    } elseif (!in_array($lang, ['zh_cn', 'en'], true)) {
        $error = '默认语言无效';
    } else {
        $scheme = strtolower((string)parse_url($nsUrl, PHP_URL_SCHEME));
        if (($scheme !== 'http' && $scheme !== 'https') || filter_var($nsUrl, FILTER_VALIDATE_URL) === false) {
            $error = 'Nightscout 后端地址必须是合法的 http/https URL';
        } else {
            try {
                \NightPanel\Db::pdo(); // creates data/ + schema (refuses legacy DBs)
                \NightPanel\Db::setSetting('key_seed', bin2hex(random_bytes(32)));
                \NightPanel\Db::setSetting('default_lang', $lang);
                \NightPanel\Db::setSetting('trust_proxy', $trustProxy ? '1' : '0');

                $stmt = \NightPanel\Db::pdo()->prepare(
                    'INSERT INTO panel_users (username, password_hash, role, backend_id)
                     VALUES (?, ?, "admin", NULL)'
                );
                $stmt->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT)]);

                \NightPanel\Backends::create($nsName, $nsUrl, $nsSecret);

                if (@file_put_contents($lockFile, 'installed ' . gmdate('c') . "\n") === false) {
                    throw new \RuntimeException('无法写入安装锁文件 ' . $lockFile);
                }

                header('Location: /login');
                exit;
            } catch (\Throwable $e) {
                $error = '安装失败：' . $e->getMessage()
                    . '。如需重试，请先删除 data/panel.db 与 data/install.lock。';
            }
        }
    }
}

// ---- render ------------------------------------------------------------------
$rows = '';
foreach ($checks as [$label, $ok]) {
    $rows .= '<li class="' . ($ok ? 'ok' : 'bad') . '">' . ($ok ? '✓' : '✗') . ' ' . h($label) . '</li>';
}

$csrf = csrfToken();
$body = '<h1>安装 Nightscout 多用户面板</h1>';

if ($error !== '') {
    $body .= '<div class="error">' . h($error) . '</div>';
}

$body .= '<h2>① 环境自检</h2><ul class="checks">' . $rows . '</ul>';

$body .= '<form method="post" action="install.php" id="install-form">'
    . '<input type="hidden" name="csrf" value="' . h($csrf) . '">'
    . '<h2>② 管理员账号</h2>'
    . '<label for="admin_user">用户名</label>'
    . '<input type="text" id="admin_user" name="admin_user" required value="' . h($defaults['admin_user']) . '">'
    . '<label for="admin_pass">密码（至少 8 位）</label>'
    . '<input type="password" id="admin_pass" name="admin_pass" required>'
    . '<label for="admin_pass2">确认密码</label>'
    . '<input type="password" id="admin_pass2" name="admin_pass2" required>'

    . '<h2>③ 第一个 Nightscout 后端</h2>'
    . '<label for="ns_name">名称（注册时显示给用户）</label>'
    . '<input type="text" id="ns_name" name="ns_name" required value="' . h($defaults['ns_name']) . '">'
    . '<label for="ns_url">地址（http/https）</label>'
    . '<input type="url" id="ns_url" name="ns_url" required placeholder="http://127.0.0.1:1337" value="' . h($defaults['ns_url']) . '">'
    . '<label for="ns_secret">管理密钥（该后端的 API_SECRET）</label>'
    . '<input type="password" id="ns_secret" name="ns_secret" required>'
    . '<div class="hint">地址与管理密钥都填好后可先测试连通性（可选，不阻塞安装）。</div>'
    . '<button type="button" class="btn-ghost" id="btn-test">测试连接</button>'
    . '<div id="test-result" aria-live="polite"></div>'

    . '<h2>④ 站点选项</h2>'
    . '<div class="radios">'
    . '<label><input type="radio" name="lang" value="zh_cn"'
    . ($defaults['lang'] === 'zh_cn' ? ' checked' : '') . '> 中文</label>'
    . '<label><input type="radio" name="lang" value="en"'
    . ($defaults['lang'] === 'en' ? ' checked' : '') . '> English</label>'
    . '</div>'
    . '<div class="checks-box" style="margin-top:12px">'
    . '<label><input type="checkbox" name="trust_proxy" value="1"'
    . ($defaults['trust_proxy'] ? ' checked' : '')
    . '> 信任反向代理的 HTTPS 头（nginx 反代终结 TLS 时勾选）</label>'
    . '</div>'

    . '<button type="submit"' . ($allOk ? '' : ' disabled') . '>开始安装</button>'
    . '</form>';

$body .= '<script>'
    . 'document.getElementById("btn-test").addEventListener("click",function(){'
    . 'var r=document.getElementById("test-result");'
    . 'r.textContent="测试中…";r.className="warn";'
    . 'var f=new FormData();'
    . 'f.append("csrf",document.querySelector("input[name=csrf]").value);'
    . 'f.append("url",document.getElementById("ns_url").value);'
    . 'f.append("secret",document.getElementById("ns_secret").value);'
    . 'fetch("install.php?action=test",{method:"POST",body:f})'
    . '.then(function(x){return x.json()})'
    . '.then(function(j){r.textContent=j.message;r.className=j.ok?"ok":"bad"})'
    . '.catch(function(){r.textContent="请求失败";r.className="bad"});'
    . '});'
    . '</script>';

page('安装 Nightscout 多用户面板', $body);
