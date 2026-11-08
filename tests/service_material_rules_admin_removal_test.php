<?php
declare(strict_types=1);

/**
 * Regression: Admin Service Material Rules configuration is removed from
 * Inventory Items without destructive data changes or workflow rewrites.
 */

$root = dirname(__DIR__);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

$ui = (string)file_get_contents($root . '/admin/inv_items_management.php');
$api = (string)file_get_contents($root . '/admin/service_material_rules_api.php');
$inventoryApi = (string)file_get_contents($root . '/admin/inventory_items_api.php');
$staff = (string)file_get_contents($root . '/staff/customizations.php');
$posValidation = (string)file_get_contents($root . '/includes/pos_service_stock_validation.php');
$assignments = (string)file_get_contents($root . '/includes/service_material_rule_assignments.php');
$materialSelection = (string)file_get_contents($root . '/includes/pos_material_selection.php');
$manualUsage = (string)file_get_contents($root . '/tests/manual_material_usage_workflow_test.php');

// 1) Service Material Rules section and controls no longer appear.
$assert(!str_contains($ui, 'serviceMaterialApprovalPanel'), 'Inventory Items no longer renders the Service Material Rules panel.');
$assert(!str_contains($ui, 'Service Material Rules'), 'Inventory Items no longer shows the Service Material Rules heading.');
$assert(!str_contains($ui, 'pfLoadServiceMaterialRules')
    && !str_contains($ui, 'pfProcessAllServiceMaterialRules')
    && !str_contains($ui, 'serviceMaterialProcessAll')
    && !str_contains($ui, 'Save multiplier')
    && !str_contains($ui, 'Approve REQUIRED'),
    'Inventory Items no longer ships Service Material Rules controls or frontend mutation helpers.');

// 2) Exclusive admin API is disabled with a clear unavailable response; no writes.
$assert(str_contains($api, "http_response_code(410)")
    && str_contains($api, "'available' => false")
    && str_contains($api, 'Service Material Rules configuration is unavailable'),
    'Direct service_material_rules_api callers receive a feature-unavailable response.');
$assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE)\b/i', $api),
    'Disabled admin API performs no INSERT/UPDATE/DELETE against service material rules.');
$assert(!str_contains($api, 'DROP TABLE') && !str_contains($api, 'TRUNCATE'),
    'Disabled admin API does not drop or truncate rule tables.');

// 3) Prior manual Production Assignment calculation workflow remains in place.
$assert(str_contains($staff, 'Actual Usage')
    && str_contains($staff, 'Enter the actual quantity used in the inventory unit.')
    && !str_contains($staff, 'Estimated Usage:'),
    'Production Assignment still records actual usage in the inventory unit without estimate-driven fill.');
$assert(str_contains($manualUsage, 'manual_confirmation_required')
    || (str_contains($staff, 'manual_confirmation_required: true')
        && str_contains($staff, 'manual_quantity_confirmed: false')),
    'Manual usage confirmation workflow remains available for regression coverage.');

// 4) POS validation, assignment helpers, ledger-facing inventory APIs stay wired.
$assert(str_contains($posValidation, 'printflow_pos_mugs_release_material_rule')
    || str_contains($posValidation, 'printflow_pos_service_required_material_quantity'),
    'POS stock validation still uses established material quantity helpers.');
$assert(str_contains($assignments, 'printflow_filter_approved_required_material_rules')
    || str_contains($assignments, 'qty_multiplier_approved'),
    'Shared assignment rule helpers remain for workflows that still read approved mappings.');
$assert(str_contains($materialSelection, 'FROM service_material_rules')
    && str_contains($materialSelection, 'printflow_production_assignment_material_rules_with_registry'),
    'Material selection/assignment still reads preserved service_material_rules mappings.');
$assert(str_contains($inventoryApi, "db_table_has_column('service_material_rules', 'qty_multiplier', true)"),
    'Inventory Items API still schema-guards optional rule metadata reads without owning the removed admin panel.');

// 5) Shared helper modules and historical data artifacts are retained (no destructive migration in this change).
foreach ([
    'includes/service_material_rule_approval.php',
    'includes/service_material_rule_display.php',
    'includes/service_material_rule_bulk.php',
    'includes/service_material_rule_identity.php',
    'includes/service_material_rule_assignments.php',
] as $relative) {
    $assert(is_file($root . '/' . $relative), 'Shared helper retained: ' . $relative);
}
$assert(!preg_match('/(?:DROP\s+TABLE|TRUNCATE)\s+[`\']?service_material_rules/i', $ui . $api),
    'Removal change does not include destructive operations on service_material_rules.');

echo "Service material rules admin removal regression tests passed.\n";
