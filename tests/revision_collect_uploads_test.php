<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/service_order_helper.php';

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$revisionSource = (string)file_get_contents($root . '/includes/revision_workflow.php');
$expect(strpos($revisionSource, 'function printflow_revision_collect_design_uploads') !== false, 'revision collect helper defined');
$expect(strpos($revisionSource, "require_once __DIR__ . '/service_order_helper.php'") !== false, 'revision workflow requires service_order_helper');

$_FILES = [
    'design_file' => [
        'name' => ['a.jpg', 'b.png'],
        'type' => ['image/jpeg', 'image/png'],
        'tmp_name' => ['/tmp/a', '/tmp/b'],
        'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
        'size' => [100, 200],
    ],
];
$parsed = printflow_normalize_php_file_uploads('design_file');
$expect(count($parsed) === 2, 'normalize parses multi design_file upload array');
$expect(($parsed[0]['name'] ?? '') === 'a.jpg', 'first upload name preserved');

$_FILES = [];
$expect(printflow_normalize_php_file_uploads('design_file') === [], 'normalize returns empty when no files');

if ($failures !== []) {
    fwrite(STDERR, "revision_collect_uploads_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "revision_collect_uploads_test passed.\n";
