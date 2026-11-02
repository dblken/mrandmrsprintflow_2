<?php
require_once __DIR__ . '/../includes/inventory_material_configuration.php';

function assert_config(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$blank = printflow_inventory_material_configuration([]);
assert_config($blank['errors'] === [], 'legacy material may leave optional configuration blank');
assert_config($blank['config']['size_variant'] === '' && $blank['config']['consumption_method'] === '', 'blank legacy defaults remain manual');

$sheet = printflow_inventory_material_configuration([
    'size_variant' => 'A4', 'material_format' => 'sheet', 'material_width' => '8.27', 'material_height' => '11.69',
    'material_dimension_unit' => 'in', 'consumption_method' => 'sheet_yield', 'designs_per_unit' => '4',
    'usable_margin_width' => '0.25', 'usable_margin_height' => '0.25', 'wastage_percent' => '5'
]);
assert_config($sheet['errors'] === [], 'valid configured sheet is accepted');
assert_config($sheet['config']['designs_per_unit'] === 4, 'configured sheet yield is stored as an integer');

$missingYield = printflow_inventory_material_configuration(['consumption_method' => 'sheet_yield']);
assert_config(isset($missingYield['errors']['designs_per_unit']), 'sheet yield requires an explicit verified yield');

$wrongFormat = printflow_inventory_material_configuration(['material_format' => 'piece', 'consumption_method' => 'dimension_sheet', 'material_width' => '8', 'material_height' => '11', 'material_dimension_unit' => 'in']);
assert_config(isset($wrongFormat['errors']['material_format']), 'dimension sheet method requires sheet format');

$invalid = printflow_inventory_material_configuration(['wastage_percent' => '101', 'designs_per_unit' => '1.5', 'material_width' => '-2']);
assert_config(isset($invalid['errors']['wastage_percent']) && isset($invalid['errors']['designs_per_unit']) && isset($invalid['errors']['material_width']), 'invalid values are rejected');

echo "PASS: inventory material configuration validation\n";
