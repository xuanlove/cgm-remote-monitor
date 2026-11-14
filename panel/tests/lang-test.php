<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * i18n checks. Run with:  php panel/tests/lang-test.php
 *
 * Guards the two failure modes that matter: a locale missing keys another
 * locale has, and a view asking for a key nobody defined (which would render
 * the raw key to the user).
 */

require __DIR__ . '/../vendor/autoload.php';

use NightPanel\Lang;

$failures = 0;
$checks = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  PASS  $name\n";
        return;
    }
    $failures++;
    echo "  FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
}

echo "i18n\n";

$zh = require __DIR__ . '/../lang/zh_cn.php';
$en = require __DIR__ . '/../lang/en.php';

check('zh_cn is a non-empty array', is_array($zh) && count($zh) > 100, (string)count($zh));
check('en is a non-empty array', is_array($en) && count($en) > 100, (string)count($en));

// --- key parity ------------------------------------------------------------
$missingInEn = array_diff(array_keys($zh), array_keys($en));
$missingInZh = array_diff(array_keys($en), array_keys($zh));
check('every zh_cn key exists in en', $missingInEn === [], implode(', ', $missingInEn));
check('every en key exists in zh_cn', $missingInZh === [], implode(', ', $missingInZh));

// --- no empty translations -------------------------------------------------
$empty = [];
foreach ($zh as $key => $value) {
    if (trim((string)$value) === '') {
        $empty[] = 'zh:' . $key;
    }
}
foreach ($en as $key => $value) {
    if (trim((string)$value) === '') {
        $empty[] = 'en:' . $key;
    }
}
check('no empty translation values', $empty === [], implode(', ', $empty));

// --- placeholders match ----------------------------------------------------
$placeholderMismatch = [];
foreach ($zh as $key => $value) {
    if (!isset($en[$key])) {
        continue;
    }
    preg_match_all('/:([a-zA-Z][a-zA-Z0-9_]*)/', (string)$value, $a);
    preg_match_all('/:([a-zA-Z][a-zA-Z0-9_]*)/', (string)$en[$key], $b);
    $left = array_unique($a[1]);
    $right = array_unique($b[1]);
    sort($left);
    sort($right);
    if ($left !== $right) {
        $placeholderMismatch[] = $key;
    }
}
check('placeholders match across locales', $placeholderMismatch === [], implode(', ', $placeholderMismatch));

// --- every key used in the code exists -------------------------------------
$files = [];
foreach ([__DIR__ . '/../app', __DIR__ . '/../view'] as $dir) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (in_array($file->getExtension(), ['php', 'html'], true)) {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);

$used = [];
foreach ($files as $file) {
    $source = (string)file_get_contents($file);
    // t('key') and Lang::t('key') calls.
    preg_match_all('/\bt\(\'([a-z][a-z0-9_]*(?:\.[a-zA-Z0-9_]+)+)\'/', $source, $direct);
    foreach ($direct[1] as $key) {
        $used[$key] = basename($file);
    }
    // t_html('key', [...]) escapes the translated string with its data.
    preg_match_all('/\bt_html\(\'([a-z][a-z0-9_]*(?:\.[a-zA-Z0-9_]+)+)\'/', $source, $escaped);
    foreach ($escaped[1] as $key) {
        $used[$key] = basename($file);
    }
    // Keys listed in Lang::js([...]) blocks.
    if (preg_match_all('/Lang::js\(\[(.*?)\]\)/s', $source, $blocks)) {
        foreach ($blocks[1] as $block) {
            preg_match_all('/\'([a-z][a-z0-9_]*(?:\.[a-zA-Z0-9_]+)+)\'/', $block, $quoted);
            foreach ($quoted[1] as $key) {
                $used[$key] = basename($file);
            }
        }
    }
    // Dynamic keys built from a fixed prefix, e.g. t('group.' . $group).
    if (preg_match_all('/t\(\'([a-z][a-z0-9_]*\.)\'\s*\./', $source, $prefixes)) {
        foreach ($prefixes[1] as $prefix) {
            foreach (array_keys($zh) as $key) {
                if (str_starts_with($key, $prefix)) {
                    $used[$key] = basename($file);
                }
            }
        }
    }
}

check('the scan found translation keys', count($used) > 80, (string)count($used));

$undefined = [];
foreach ($used as $key => $file) {
    if (!isset($zh[$key])) {
        $undefined[] = $key . ' (' . $file . ')';
    }
}
check('every key used in the code is defined', $undefined === [], implode(', ', $undefined));

// --- translator behaviour --------------------------------------------------
check('default locale is zh_cn', Lang::DEFAULT_LOCALE === 'zh_cn');
Lang::set('en');
check('set() switches the locale', Lang::current() === 'en');
check('t() translates after switching', Lang::t('nav.dashboard') === 'Dashboard');
check('t() replaces placeholders', Lang::t('dash.lastHours', ['n' => 6]) === 'Last 6 hours');
check('unknown locale is refused', Lang::set('fr') === false && Lang::current() === 'en');
Lang::set('zh_cn');
check('t() falls back to the active locale value', Lang::t('nav.dashboard') === '首页');
check('missing key returns the key itself', Lang::t('no.such.key') === 'no.such.key');
check('htmlLang maps english', (function () { Lang::set('en'); $v = Lang::htmlLang(); Lang::set('zh_cn'); return $v; })() === 'en');

// js() must yield parseable JSON with the translated strings.
Lang::set('en');
$js = Lang::js(['nav.dashboard', 'theme.toDark']);
$decoded = json_decode($js, true);
check('js() emits valid JSON', is_array($decoded));
check('js() carries translated values', ($decoded['nav.dashboard'] ?? null) === 'Dashboard');
Lang::set('zh_cn');

echo "\n" . ($failures === 0 ? "OK — $checks checks passed\n" : "FAILED — $failures of $checks checks\n");
exit($failures === 0 ? 0 : 1);
