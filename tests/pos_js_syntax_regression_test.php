<?php
declare(strict_types=1);

$path = dirname(__DIR__) . '/staff/pos.php';
$content = (string) file_get_contents($path);
$failures = [];

if (!preg_match('/async function posStageDesignUploadsFromModal\(body, customization\) \{(.*?)\r?\n        \}\r?\n\r?\n        async function confirmServiceModalLegacy/s', $content, $m)) {
    $failures[] = 'posStageDesignUploadsFromModal block not found';
} else {
    $body = $m[1];
    $rowDecls = preg_match_all('/const row = group\.closest\(\'\.shopee-form-row\'\)/', $body, $_);
    if ($rowDecls !== 1) {
        $failures[] = 'posStageDesignUploadsFromModal must declare const row once per loop (found ' . $rowDecls . ')';
    }
}

if (strpos($content, 'window.showPOSMode = showPOSMode;') === false) {
    $failures[] = 'showPOSMode must be exposed on window for inline onclick handlers';
}
if (strpos($content, 'window.backToSelection = backToSelection;') === false) {
    $failures[] = 'backToSelection must be exposed on window for inline onclick handlers';
}
if (strpos($content, 'function showPOSMode(mode)') === false) {
    $failures[] = 'showPOSMode function definition missing';
}

if ($failures !== []) {
    fwrite(STDERR, "pos_js_syntax_regression_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "pos_js_syntax_regression_test passed.\n";
