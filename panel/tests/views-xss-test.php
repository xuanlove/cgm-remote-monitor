<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * XSS regression checks for the panel templates. Run with:
 *   php panel/tests/views-xss-test.php
 *
 * Covers the two classes of bug found in the audit: data written into
 * innerHTML, and data embedded in a <script> block with a bare json_encode().
 * Templates are rendered for real so the default escaping is exercised too.
 */

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

echo "View XSS\n";

// --- js_json ---------------------------------------------------------------
$payload = '</script><script>alert(1)</script>';
$encoded = js_json($payload);
check('js_json escapes the closing script tag', !str_contains($encoded, '</script>'), $encoded);
check('js_json emits the hex escape', str_contains($encoded, '\u003C'), $encoded);
check('js_json neutralises quotes', js_json('a"b\'c') === '"a\u0022b\u0027c"', js_json('a"b\'c'));
check('js_json neutralises ampersands', str_contains(js_json('a&b'), '\u0026'), js_json('a&b'));
check('js_json keeps unicode readable', str_contains(js_json('血糖'), '血糖'), js_json('血糖'));

// --- template rendering ----------------------------------------------------
$app = new \think\App(dirname(__DIR__));
$app->initialize();

$driver = new \think\view\driver\Think($app, [
    'view_path' => dirname(__DIR__) . '/view/',
    'view_suffix' => 'html',
    'default_filter' => 'htmlentities',
    'tpl_cache' => false,
    'cache_path' => sys_get_temp_dir() . '/panel-tpl-test/',
]);

/** Render a template and return the HTML. */
function render(\think\view\driver\Think $driver, string $template, array $vars): string
{
    $globals = [
        'user' => ['id' => 1, 'username' => 'alice', 'role' => 'user'],
        'isAdmin' => false,
        'csrf' => 'deadbeef',
        'htmlLang' => 'zh-CN',
        'locales' => [['code' => 'zh_cn', 'name' => '中文', 'active' => true]],
        'backUrl' => '/dashboard',
        'layoutI18n' => '{}',
        'panelLocaleJson' => '"zh-CN"',
    ];

    ob_start();
    $driver->fetch($template, array_merge($globals, $vars));
    return (string)ob_get_clean();
}

// --- entry template with a malicious ?type= --------------------------------
$entryHtml = render($driver, 'entry', [
    'types' => ['Note' => ['type.note', ['notes'], 'monitor']],
    'fields' => ['notes' => ['text', 'field.notes', '', 0, 0, '']],
    'typesJson' => js_json(['Note' => ['label' => 'Note', 'fields' => ['notes'], 'group' => 'monitor']]),
    'fieldsJson' => js_json(['notes' => ['kind' => 'text', 'label' => 'Notes', 'unit' => '', 'min' => 0, 'max' => 0, 'step' => '']]),
    'groupsJson' => js_json(['monitor' => 'Monitor']),
    'presetJson' => js_json($payload),
    'usernameJson' => js_json('alice'),
    'preset' => $payload,
    'error' => null,
    'success' => null,
]);

check('entry template does not echo the raw payload', !str_contains($entryHtml, $payload));
check('entry template carries the escaped payload', str_contains($entryHtml, '\u003C'));

// --- treatments template with a malicious stored record ---------------------
$evil = '<img src=x onerror=alert(1)>';
$treatHtml = render($driver, 'treatments', [
    'types' => [],
    'fields' => [],
    'typesJson' => '{}',
    'fieldsJson' => '{}',
    'recordJson' => js_json([]),
    'rows' => [[
        'time' => '2026-01-01 00:00',
        'type' => 'Note',
        'glucose' => '-',
        'carbs' => '-',
        'insulin' => '-',
        'extra' => '-',
        'notes' => $evil,
        'id' => 'x1',
        'editUrl' => '/treatments?edit=x1',
    ]],
    'editing' => null,
    'error' => null,
    'success' => null,
]);

check('treatments template escapes stored notes', !str_contains($treatHtml, $evil));
check('treatments template still renders the list', str_contains($treatHtml, 'treatments?edit=x1'));

// --- error page escapes its message ---------------------------------------
$errHtml = render($driver, 'error', ['message' => $evil]);
check('error template escapes the message', !str_contains($errHtml, $evil) && str_contains($errHtml, '&lt;img'));

// --- static guards ---------------------------------------------------------
$viewDir = dirname(__DIR__) . '/view';
$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($viewDir, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    if ($file->getExtension() === 'html') {
        $files[] = $file->getPathname();
    }
}
sort($files);

$bareEncode = [];
$innerHtmlRows = [];
$rawVars = [];
$rawWhitelist = '/^\$(?:dashI18n|panelLocaleJson|csrfJson|statsJson|reportsI18n|typesJson|fieldsJson|groupsJson|presetJson|usernameJson|recordJson|layoutI18n|permText)\|raw$/';

foreach ($files as $file) {
    $source = (string)file_get_contents($file);
    $name = basename($file);

    // Any json_encode() left in a template is a script-embedding hazard.
    if (preg_match('/json_encode\(/', $source) === 1) {
        $bareEncode[] = $name;
    }
    // Row/cell HTML built by string concatenation is how audit finding H1 arose.
    if (preg_match('/row\.innerHTML\s*=\s*[\'"]/', $source) === 1) {
        $innerHtmlRows[] = $name;
    }
    // Only controller-generated JSON blobs may bypass the default escaping.
    if (preg_match_all('/\{(\$[a-zA-Z0-9_]+)\|raw\}/', $source, $m)) {
        foreach ($m[1] as $var) {
            if (preg_match($rawWhitelist, $var . '|raw') !== 1) {
                $rawVars[] = $name . ':' . $var;
            }
        }
    }
}

check('no bare json_encode() in templates', $bareEncode === [], implode(', ', $bareEncode));
check('no row.innerHTML string building in templates', $innerHtmlRows === [], implode(', ', $innerHtmlRows));
check('|raw is limited to injected JSON', $rawVars === [], implode(', ', $rawVars));
check('dashboard builds treatment rows with the DOM', str_contains(
    (string)file_get_contents($viewDir . '/dashboard.html'),
    'cell.textContent = String(value)'
));

// --- every template compiles and renders -----------------------------------
$emptyStats = \NightPanel\GlucoseStats::compute([], 3.9, 10.0);

$backendRow = static fn(int $id, string $name, int $users = 0): array => [
    'id' => $id,
    'name' => $name,
    'url' => 'http://127.0.0.1:133' . $id,
    'secret_enc' => 'enc:v1:xxx',
    'enabled' => 1,
    'created_at' => '2026-01-01 00:00:00',
    'user_count' => $users,
];

$fixtures = [
    'login' => ['error' => null],
    'register' => ['error' => null, 'success' => null, 'info' => null, 'backends' => [$backendRow(1, 'NS One')]],
    'dashboard' => ['dashI18n' => '{}', 'csrfJson' => '""'],
    'reports' => [
        'days' => 14,
        'stats' => $emptyStats,
        'statsJson' => '{}',
        'reportsI18n' => '{}',
        'from' => '2026-01-01 00:00',
        'to' => '2026-01-15 00:00',
        'error' => null,
    ],
    'settings:profile' => [
        'profile' => [
            'dia' => 3.0,
            'carbs_hr' => 30,
            'timezone' => 'UTC',
            'basal' => [['00:00', 1.0]],
            'sens' => [['00:00', 50]],
            'target_low' => [['00:00', 4]],
            'target_high' => [['00:00', 10]],
        ],
        'error' => null,
        'success' => null,
    ],
    'settings:plans' => [
        'plans' => [[
            'id' => 'p1',
            'active' => true,
            'startDate' => '2026-01-01T00:00:00.000Z',
            'dia' => 3.0,
            'basalCount' => 3,
            'targetLow' => 4,
            'targetHigh' => 10,
            'shared' => false,
        ]],
        'error' => null,
        'success' => null,
    ],
    'entry' => [
        'types' => [],
        'fields' => [],
        'typesJson' => '{}',
        'fieldsJson' => '{}',
        'groupsJson' => '{}',
        'presetJson' => '""',
        'usernameJson' => '"alice"',
        'preset' => '',
        'error' => null,
        'success' => null,
    ],
    'treatments' => [
        'types' => [],
        'fields' => [],
        'typesJson' => '{}',
        'fieldsJson' => '{}',
        'recordJson' => 'null',
        'rows' => [],
        'editing' => null,
        'error' => null,
        'success' => null,
    ],
    'settings:alerts' => [
        'settings' => ['low' => 3.9, 'high' => 10.0, 'enabled' => false, 'browser' => false],
        'permText' => '{}',
        'error' => null,
        'success' => null,
    ],
    'settings:api' => [
        'nsUrl' => 'http://127.0.0.1:1337',
        'backendName' => 'NS One',
        'token' => 'abcdef1234567890',
        'tokenMask' => 'abcdef-••••',
    ],
    'admin' => [
        'users' => [[
            'id' => 2,
            'username' => 'alice',
            'role' => 'user',
            'backend_id' => 1,
            'backend_name' => 'NS One',
            'ns_subject_id' => 's1',
            'created_at' => '2026-01-01 00:00:00',
        ]],
        'backends' => [$backendRow(1, 'NS One', 1), $backendRow(2, 'NS Two')],
        'error' => null,
        'notice' => null,
    ],
    'admin_migrate' => [
        'backends' => [$backendRow(1, 'NS One'), $backendRow(2, 'NS Two')],
        'error' => null,
    ],
    'admin_migrate_confirm' => [
        'from' => $backendRow(1, 'NS One'),
        'to' => $backendRow(2, 'NS Two'),
        'cleanup' => true,
        'affected' => [['id' => 2, 'username' => 'alice', 'created_at' => '2026-01-01 00:00:00']],
    ],
    'admin_migrate_done' => [
        'from' => $backendRow(1, 'NS One'),
        'to' => $backendRow(2, 'NS Two'),
        'cleanup' => true,
        'results' => [
            ['username' => 'alice', 'ok' => true, 'entries' => 120, 'treatments' => 3,
             'devicestatus' => 10, 'error' => null, 'cleanupError' => null],
            ['username' => 'bob', 'ok' => false, 'entries' => 0, 'treatments' => 0,
             'devicestatus' => 0, 'error' => 'Nightscout API ...', 'cleanupError' => null],
        ],
    ],
    '404' => [],
    'error' => ['message' => 'boom'],
];

$broken = [];
foreach ($fixtures as $name => $vars) {
    try {
        // "settings:profile" renders the shared settings template with that
        // tab; anything without a colon is a plain template name.
        [$template, $tab] = array_pad(explode(':', (string)$name, 2), 2, '');
        if ($tab !== '') {
            $vars['tab'] = $tab;
        }
        $html = render($driver, $template, $vars);
        if (!str_contains($html, '<!DOCTYPE html>')) {
            $broken[] = $name . ' (no layout)';
        }
    } catch (\Throwable $e) {
        $broken[] = $name . ' (' . $e->getMessage() . ')';
    }
}
check('every template renders', $broken === [], implode('; ', $broken));

echo $failures === 0 ? "\nOK — {$checks} checks passed\n" : "\n{$failures} of {$checks} checks failed\n";
exit($failures === 0 ? 0 : 1);
