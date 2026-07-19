<?php

require_once __DIR__ . '/../includes/env.php';

$failures = [];
$suffix = strtoupper(bin2hex(random_bytes(4)));
$serverKey = 'PRINTFLOW_TEST_SERVER_' . $suffix;
$fileKey = 'PRINTFLOW_TEST_FILE_' . $suffix;
$commentKey = 'PRINTFLOW_TEST_COMMENT_' . $suffix;
$emptyKey = 'PRINTFLOW_TEST_EMPTY_' . $suffix;
$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'printflow_env_' . bin2hex(random_bytes(6));

putenv($serverKey . '=server-value');
putenv($emptyKey . '=');
file_put_contents($path, implode(PHP_EOL, [
    $serverKey . '=dotenv-value',
    $fileKey . '="quoted value # retained"',
    $commentKey . '=plain-value # removed comment',
    $emptyKey . '=dotenv-fallback',
]));

$loaded = printflow_import_dotenv($path);
if (getenv($serverKey) !== 'server-value') $failures[] = 'A real process variable must override .env.';
if (getenv($fileKey) !== 'quoted value # retained') $failures[] = 'Quoted .env values should load intact.';
if (getenv($commentKey) !== 'plain-value') $failures[] = 'Unquoted inline comments should be removed.';
if (getenv($emptyKey) !== 'dotenv-fallback') $failures[] = 'An empty server variable should not mask a non-empty .env fallback.';
if (($loaded[$serverKey] ?? '') !== 'dotenv-value') $failures[] = 'The dotenv parser should return parsed file values.';

@unlink($path);
foreach ([$serverKey, $fileKey, $commentKey, $emptyKey] as $key) {
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "Environment loader tests passed.\n";
