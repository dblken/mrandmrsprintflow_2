<?php
declare(strict_types=1);

/**
 * Scan POS + service field renderer JS for duplicate const/let names in the same block scope.
 */
$files = [
    dirname(__DIR__) . '/staff/pos.php',
    dirname(__DIR__) . '/includes/service_field_renderer.php',
];

$failures = [];

foreach ($files as $file) {
    $content = (string) file_get_contents($file);
    if ($file === $files[0]) {
        $start = strpos($content, '<script>');
        $end = strpos($content, 'window.PFConfig = Object.assign');
        if ($start === false || $end === false) {
            $failures[] = basename($file) . ': could not extract main script';
            continue;
        }
        $js = substr($content, $start + strlen('<script>'), $end - ($start + strlen('<script>')));
    } else {
        if (!preg_match('/\$js = <<<' . "'JS'\r?\n(.*)\r?\nJS;/s", $content, $m)) {
            $failures[] = basename($file) . ': could not extract JS heredoc';
            continue;
        }
        $js = $m[1];
    }

    $lines = preg_split('/\r?\n/', $js);
    $stack = [];
    $scopes = [[]];

    foreach ($lines as $lineNo => $line) {
        $trim = trim($line);
        if (preg_match('/\b(const|let)\s+([A-Za-z_$][\w$]*)\s*=/', $trim, $m)) {
            $name = $m[2];
            $scope = &$scopes[count($scopes) - 1];
            if (isset($scope[$name])) {
                $failures[] = sprintf(
                    '%s:%d duplicate %s %s (first at line %d)',
                    basename($file),
                    $lineNo + 1,
                    $m[1],
                    $name,
                    $scope[$name]
                );
            } else {
                $scope[$name] = $lineNo + 1;
            }
        }
        if (str_contains($trim, '{')) {
            $scopes[] = [];
        }
        if (str_contains($trim, '}')) {
            if (count($scopes) > 1) {
                array_pop($scopes);
            }
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "pos_js_duplicate_const_scan failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "pos_js_duplicate_const_scan passed.\n";
