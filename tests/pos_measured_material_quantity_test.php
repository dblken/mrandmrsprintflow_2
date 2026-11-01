<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/pos_quantity_validation.php';

$ftByLength = ['unit_of_measure' => 'ft', 'consumption_method' => 'by_length'];
$ftManual = ['unit_of_measure' => 'ft', 'consumption_method' => 'manual'];
$pieces = ['unit_of_measure' => 'pcs', 'consumption_method' => 'per_piece'];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(printflow_pos_parse_positive_material_quantity('0.25', $ftByLength) === 0.25, 'Small measured lengths remain fractional.');
$assert(printflow_pos_parse_positive_material_quantity('24.75', $ftByLength) === 24.75, 'Large measured lengths preserve two decimal places.');
$assert(printflow_pos_parse_positive_material_quantity('0.5', $ftManual) === 0.5, 'Manual confirmation accepts fractional feet only for configured measured items.');
$assert(printflow_pos_parse_positive_material_quantity('24', $pieces) === 24.0, 'Piece quantities remain whole.');
foreach ([['0.5', $pieces], ['0.5', ['unit_of_measure' => 'ft', 'consumption_method' => 'per_piece']], ['0.5', ['unit_of_measure' => 'm', 'consumption_method' => 'by_length']]] as [$value, $item]) {
    try {
        printflow_pos_parse_positive_material_quantity($value, $item);
    } catch (InvalidArgumentException $error) {
        continue;
    }
    throw new RuntimeException('Fractional quantity passed without approved ft measurement configuration.');
}

$files = [
    'service stock validation' => file_get_contents(__DIR__ . '/../includes/pos_service_stock_validation.php'),
    'job material persistence' => file_get_contents(__DIR__ . '/../includes/JobOrderService.php'),
    'POS Set Price assignment endpoint' => file_get_contents(__DIR__ . '/../admin/job_orders_api.php'),
    'staff measured-usage UI' => file_get_contents(__DIR__ . '/../staff/customizations.php'),
];
foreach ($files as $label => $source) {
    $method = $label === 'staff measured-usage UI' ? 'parseMaterialQuantity' : 'parse_positive_material_quantity';
    $assert(is_string($source) && str_contains($source, $method), $label . ' uses the configured quantity contract.');
}
$assert(str_contains($files['staff measured-usage UI'], 'getMaterialEntryUom(itemId)')
    && !str_contains(substr($files['staff measured-usage UI'], strpos($files['staff measured-usage UI'], 'getMaterialEntryUom(itemId)'), 520), "return 'pcs';\n                }"),
    'Sticker selections retain the inventory item UOM instead of forcing pcs.');

echo "POS measured material quantity tests passed.\n";
