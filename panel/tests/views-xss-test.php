<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * XSS regression checks for the panel views. Run with:
 *   php panel/tests/views-xss-test.php
 *
 * Covers the two classes of bug found in the audit: data written into
 * innerHTML, and data embedded in a <script> block with a bare json_encode().
 */

require __DIR__ . '/../lib/Lang.php';
require __DIR__ . '/../lib/helpers.php';
require __DIR__ . '/../lib/Auth.php';

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

// --- entry view with a malicious ?type= ------------------------------------
$types = ['Note' => ['type.note', ['notes'], 'monitor']];
$fields = ['notes' => ['text', 'field.notes', '', 0, 0, '']];
$user = ['username' => 'alice'];
$preset = $payload;
$error = null;
$success = null;

ob_start();
require __DIR__ . '/../views/entry.php';
$entryHtml = (string)ob_get_clean();

check('entry view does not echo the raw payload', !str_contains($entryHtml, $payload));
check('entry view carries the escaped payload', str_contains($entryHtml, '\u003C'));

// --- treatments view with a malicious stored record ------------------------
$treatments = [];
$editing = [
    '_id' => 'x1',
    'eventType' => 'Note',
    'notes' => '<img src=x onerror=alert(1)>',
    'enteredBy' => 'alice',
];

ob_start();
require __DIR__ . '/../views/treatments.php';
$editHtml = (string)ob_get_clean();

check('treatments view keeps stored notes out of raw script', !str_contains($editHtml, '<img src=x onerror=alert(1)>'));
check('treatments view still renders the form', str_contains($editHtml, 'editFields'));

// --- static guards ---------------------------------------------------------
$viewDir = __DIR__ . '/../views';
$bareEncode = [];
$innerHtmlRows = [];
foreach (glob($viewDir . '/*.php') as $file) {
    $source = (string)file_get_contents($file);
    // Any json_encode() left in a view is a script-embedding hazard.
    if (preg_match('/json_encode\(/', $source) === 1) {
        $bareEncode[] = basename($file);
    }
    // Row/cell HTML built by string concatenation is how audit finding H1 arose.
    if (preg_match('/row\.innerHTML\s*=\s*[\'"]/', $source) === 1) {
        $innerHtmlRows[] = basename($file);
    }
}
check('no bare json_encode() in views', $bareEncode === [], implode(', ', $bareEncode));
check('no row.innerHTML string building in views', $innerHtmlRows === [], implode(', ', $innerHtmlRows));

check('dashboard builds treatment rows with the DOM', str_contains(
    (string)file_get_contents($viewDir . '/dashboard.php'),
    'cell.textContent = String(value)'
));

echo $failures === 0 ? "\nOK — {$checks} checks passed\n" : "\n{$failures} of {$checks} checks failed\n";
exit($failures === 0 ? 0 : 1);
