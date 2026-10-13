<?php
declare(strict_types=1);
$path = dirname(__DIR__) . '/staff/pos.php';
$lines = file($path, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    exit(1);
}
$start = null;
$end = null;
foreach ($lines as $i => $line) {
    if ($start === null && trim($line) === '<script>') {
        $start = $i + 1;
        continue;
    }
    if ($start !== null && $end === null && str_contains($line, 'window.PFConfig = Object.assign')) {
        $end = $i;
        break;
    }
}
if ($start === null || $end === null) {
    fwrite(STDERR, "Could not locate POS script block\n");
    exit(1);
}
$js = implode("\n", array_slice($lines, $start, $end - $start));
$out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos_script_check.js';
file_put_contents($out, $js);
echo $out . "\n";
