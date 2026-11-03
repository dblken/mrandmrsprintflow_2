<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/staff/api/pos_service_fields.php';
$source = (string) file_get_contents($path);

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $passed++;
    echo 'PASS: ', $message, PHP_EOL;
};

$output = [];
$lintExit = 0;
exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $lintExit);
$assert($lintExit === 0, 'pos_service_fields.php has valid PHP syntax');

$assert(
    !preg_match('/echo\s+[\'"][^\'"]*date\s*\(\s*[\'"]Y-m-d[\'"]/s', $source)
        && str_contains($source, '$needed_date_min = !empty($GLOBALS[\'printflow_allow_past_needed_date\']) ? \'\' : date(\'Y-m-d\');')
        && str_contains($source, '$needed_date_min_attr = $needed_date_min !== \'\' ? \' min="\''),
    'needed_date min defaults to today but honors the existing POS allow-past-date workflow'
);
$assert(
    str_contains($source, 'catch (Throwable $renderError)')
        && str_contains($source, "'success' => false"),
    'render failures return JSON instead of a bare fatal error'
);
$assert(
    str_contains($source, 'render_service_fields($service_id')
        && str_contains($source, 'service_has_field_config($service_id)'),
    'configured services use the shared field renderer'
);
$assert(
    str_contains($source, 'printflow_pos_service_dimension_configs($service_id)')
        && str_contains($source, "'material_dimension_field_key' =>")
        && str_contains($source, "'material_dimension_unit_per_option' =>")
        && str_contains($source, "\$dimensionDescriptor['field_type'] === 'dimension'"),
    'area-based POS services return field identity and allow validated select/radio options to carry their own units'
);

echo "pos_service_fields endpoint test: {$passed} assertions passed.\n";
