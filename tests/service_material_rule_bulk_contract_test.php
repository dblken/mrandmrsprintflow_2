<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/service_material_rule_bulk.php';

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$assert(printflow_service_material_bulk_uom_valid('Pcs'), 'Pcs is a supported inventory UOM.');
$assert(printflow_service_material_bulk_uom_valid('Sheet'), 'Sheet is a supported inventory UOM.');
$assert(!printflow_service_material_bulk_uom_valid(''), 'Blank UOM is invalid.');
$assert(!printflow_service_material_bulk_uom_valid('Unknown'), 'Unknown UOM is invalid.');
$base = ['rule_type' => 'REQUIRED', 'qty_multiplier' => null, 'qty_multiplier_approved' => 0];
$assert(printflow_service_material_bulk_rule_state($base, '', 'AREA_SQFT', 'REQUIRED')['action'] === 'pending', 'Missing multiplier remains pending.');
$assert(printflow_service_material_bulk_rule_state($base, '0', 'AREA_SQFT', 'REQUIRED')['action'] === 'pending', 'Zero multiplier remains pending.');
$assert(printflow_service_material_bulk_rule_state($base, '-1', 'AREA_SQFT', 'REQUIRED')['action'] === 'pending', 'Negative multiplier remains pending.');
$assert(printflow_service_material_bulk_rule_state($base, '1.234', 'AREA_SQFT', 'REQUIRED')['action'] === 'pending', 'More than two decimal places remains pending.');
$assert(printflow_service_material_bulk_rule_state($base, '1.25', '', 'REQUIRED')['action'] === 'pending', 'Missing basis remains pending.');
$assert(printflow_service_material_bulk_rule_state($base, '1.25', 'AREA_SQFT', 'REQUIRED')['action'] === 'approve', 'Valid required configuration can be explicitly approved.');
$assert(printflow_service_material_bulk_rule_state($base, '1.25', 'PER_ITEM', 'REQUIRED', 'PER_ITEM')['action'] === 'approve', 'A valid quantity-based Plates rate can be explicitly approved using PER_ITEM basis.');
$assert(printflow_service_material_bulk_rule_state($base, '1.25', 'AREA_SQFT', 'REQUIRED', 'PER_ITEM')['action'] === 'pending', 'Bulk processing cannot approve a Plates multiplier configured as an area rate.');
$assert(printflow_service_material_bulk_rule_state($base, '1.25', 'PER_ITEM', 'REQUIRED', 'AREA_SQFT')['action'] === 'pending', 'Area-based services cannot be approved with the Plates per-item basis.');
$assert(printflow_service_material_bulk_rule_state(['rule_type' => 'OPTIONAL'], '1.25', 'AREA_SQFT', 'OPTIONAL')['action'] === 'pending', 'Optional rules remain unapproved by bulk processing.');
$assert(printflow_service_material_bulk_rule_state(['rule_type' => 'OPTIONAL'], '1.25', 'AREA_SQFT', 'REQUIRED')['action'] === 'pending', 'Classification mismatch remains pending.');
$approved = [
    'rule_type' => 'REQUIRED', 'qty_multiplier' => '1.25', 'qty_multiplier_approved' => 1,
    'qty_multiplier_approved_by' => 7, 'qty_multiplier_approved_at' => '2026-11-03 12:00:00',
    'qty_multiplier_basis' => 'AREA_SQFT', 'qty_multiplier_approved_basis' => 'AREA_SQFT',
];
$assert(printflow_service_material_bulk_rule_state($approved, '1.25', 'AREA_SQFT', 'REQUIRED')['action'] === 'already_approved', 'Matching approved audit is preserved as a no-op.');
$approved['qty_multiplier_approved_at'] = null;
$assert(printflow_service_material_bulk_rule_state($approved, '1.25', 'AREA_SQFT', 'REQUIRED')['action'] === 'approve', 'Missing audit timestamp requires a new explicit approval.');

$api = (string)file_get_contents(dirname(__DIR__) . '/admin/service_material_rules_api.php');
$ui = (string)file_get_contents(dirname(__DIR__) . '/admin/inv_items_management.php');
$assert(str_contains($api, "http_response_code(410)")
    && str_contains($api, "'available' => false")
    && str_contains($api, 'Service Material Rules configuration is unavailable'),
    'Bulk/create/approve admin routes are retired behind a feature-unavailable response.');
$assert(!str_contains($api, 'bulk_process') && !str_contains($api, 'bulk_preview') && !preg_match('/\b(?:INSERT|UPDATE|DELETE)\b/i', $api),
    'Retired API no longer exposes bulk mutation actions or SQL writes.');
$assert(!str_contains($ui, 'Process All')
    && !str_contains($ui, 'serviceMaterialBulkResultRows')
    && !str_contains($ui, 'pfProcessAllServiceMaterialRules'),
    'Inventory Items no longer hosts Process All / bulk preview UI.');
$assert(is_file(dirname(__DIR__) . '/includes/service_material_rule_bulk.php'),
    'Shared bulk helper remains on disk for historical contract coverage without an active admin writer.');
echo "Service material bulk workflow contract tests passed.\n";
