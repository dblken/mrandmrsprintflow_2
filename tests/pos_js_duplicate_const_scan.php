<?php
declare(strict_types=1);

/** Use Node's JavaScript parser, which reports duplicate lexical declarations only when scopes conflict. */
function pos_js_syntax_check(string $javascript): array
{
    $base = tempnam(sys_get_temp_dir(), 'pf-js-scope-');
    if ($base === false) return [false, 'Could not create a temporary JavaScript file.'];
    $temp = $base . '.js';
    @unlink($base);
    try {
        if (file_put_contents($temp, $javascript) === false) return [false, 'Could not write the temporary JavaScript file.'];
        $process = proc_open(['node', '--check', $temp], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) return [false, 'Could not start Node.js.'];
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return [$exit === 0, trim((string)$stderr . "\n" . (string)$stdout)];
    } finally {
        @unlink($temp);
    }
}

if (defined('POS_JS_CONST_SCAN_TEST')) {
    [$sameScopeOk] = pos_js_syntax_check('function f(){ const duplicate = 1; const duplicate = 2; }');
    [$nestedScopeOk] = pos_js_syntax_check('function f(){ const value = 1; { const value = 2; } }');
    [$separateFunctionScopesOk] = pos_js_syntax_check('function f(){ const value = 1; } function g(){ const value = 2; }');
    if ($sameScopeOk || !$nestedScopeOk || !$separateFunctionScopesOk) {
        fwrite(STDERR, "Scope-aware JavaScript duplicate declaration checks failed.\n");
        exit(1);
    }
    return;
}

$files = [
    dirname(__DIR__) . '/staff/pos.php',
    dirname(__DIR__) . '/includes/service_field_renderer.php',
];
$failures = [];
foreach ($files as $fileIndex => $file) {
    $content = (string)file_get_contents($file);
    if ($fileIndex === 0) {
        $start = strpos($content, '<script>');
        $end = strpos($content, 'window.PFConfig = Object.assign');
        if ($start === false || $end === false || $end <= $start) {
            $failures[] = basename($file) . ': could not extract main script';
            continue;
        }
        $javascript = substr($content, $start + strlen('<script>'), $end - ($start + strlen('<script>')));
        $javascript = preg_replace('/<\?php.*?\?>/s', 'null', $javascript) ?? $javascript;
        $javascript = str_replace(['</script>', '<script>'], '', $javascript);
    } else {
        if (!preg_match('/\$js = <<<' . "'JS'" . '\r?\n(.*?)\r?\nJS;/s', $content, $match)) {
            $failures[] = basename($file) . ': could not extract JS heredoc';
            continue;
        }
        $javascript = $match[1];
    }

    [$ok, $diagnostic] = pos_js_syntax_check($javascript);
    if (!$ok) $failures[] = basename($file) . ': ' . ($diagnostic !== '' ? $diagnostic : 'JavaScript syntax check failed.');
}

if ($failures !== []) {
    fwrite(STDERR, "pos_js_duplicate_const_scan failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "pos_js_duplicate_const_scan passed.\n";
