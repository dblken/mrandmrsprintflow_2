<?php
/**
 * POS custom-size validation must sync visible width/height before validating hidden fields.
 */

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$pos = (string) file_get_contents($root . '/staff/pos.php');
$renderer = (string) file_get_contents($root . '/includes/service_field_renderer.php');

$assert(str_contains($renderer, 'function pfPrepareServiceFormDimensions'), 'shared prepare helper must exist');
$assert(str_contains($renderer, 'function pfDimensionPairIsValid'), 'dimension pair validator must exist');
$assert(str_contains($renderer, 'pfSyncSelectCustomSizeHidden'), 'select custom-size sync must exist');
$assert(str_contains($pos, 'pfPrepareServiceFormDimensions(body)'), 'POS validate must sync dimensions before checks');
$assert(str_contains($pos, 'initServiceFieldRenderer(body)'), 'POS modal load must bind custom-size inputs');
$assert(str_contains($pos, 'pfValidateCustomSizePanel'), 'POS must use custom-size panel validation');
$assert(str_contains($pos, 'customization.width = widthVal'), 'POS cart payload must include width/height for custom size');

if ($failures !== []) {
    fwrite(STDERR, "POS custom size validation test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "POS custom size validation test passed.\n";
