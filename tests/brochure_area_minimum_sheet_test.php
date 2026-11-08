<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/pos_quantity_validation.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$item = ['unit_of_measure' => 'sheet', 'consumption_method' => 'dimension_sheet', 'qty_multiplier' => '0.01'];
$quantity = printflow_pos_parse_positive_material_quantity('1', $item);
$assert($quantity === 1.0, 'A staff-entered 1 sheet remains valid despite dimensions or a legacy multiplier implying a former minimum of 2.');

$stock = (string)file_get_contents(dirname(__DIR__) . '/includes/pos_service_stock_validation.php');
$api = (string)file_get_contents(dirname(__DIR__) . '/admin/job_orders_api.php');
$service = (string)file_get_contents(dirname(__DIR__) . '/includes/JobOrderService.php');
$assert(str_contains($stock, 'function printflow_pos_service_stock_rules')
    && str_contains(substr($stock, strpos($stock, 'function printflow_pos_service_stock_rules'), strpos($stock, 'function printflow_pos_service_line_job_materials') - strpos($stock, 'function printflow_pos_service_stock_rules')), 'return [];'),
    'Area and multiplier rule rows no longer create active service-material quantity demand.');
$assert(str_contains($api, '$rawQuantity = trim((string)($_POST[\'quantity\'] ?? \'\'))')
    && str_contains($api, 'printflow_pos_parse_positive_material_quantity($rawQuantity, $quantityItem)'),
    'The assignment endpoint validates the staff-entered inventory quantity directly.');
$assert(str_contains($api, "\$metadata['actual_usage_version'] = 'manual_v1';")
    && str_contains($service, "['actual_usage_version'] ?? '') === 'manual_v1'"),
    'The saved manual quantity is explicitly identified for subsequent stock checking and deduction.');

echo "brochure_area_minimum_sheet_test passed.\n";
