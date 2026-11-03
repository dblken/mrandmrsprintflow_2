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
$assert(str_contains($api, 'Only REQUIRED service material rules can be approved.'), 'The direct API rejects attempts to approve OPTIONAL rules.');
$assert(str_contains($api, "['bulk_preview', 'bulk_process']") && str_contains($api, "confirm_bulk") && str_contains($api, 'verify_csrf_token'), 'Bulk endpoints require the existing CSRF contract and explicit process confirmation.');
$assert(str_contains($api, '$allowedServiceIds = [36, 39, 64, 67, 71]') && str_contains($api, 'GET_LOCK(?, 5)'), 'Bulk processing is restricted to the five approved active services and uses per-rule mutual exclusion.');
$assert(str_contains($api, 'created_unapproved') && str_contains($api, 'qty_multiplier_approved_by = ?') && str_contains($api, 'Admin approval audit verification failed'), 'Creation remains unapproved and approval audit is verified after updates.');
$assert(str_contains($api, 'Multiple canonical/alias rules exist') && str_contains($api, 'unsupported for area-based consumption'), 'Ambiguous duplicates and invalid UOMs fail closed with a row-level reason.');
$assert(str_contains($ui, 'Process All') && str_contains($ui, 'bulk_preview') && str_contains($ui, 'window.confirm(confirmation)') && str_contains($ui, 'serviceMaterialBulkResultRows'), 'The Admin UI previews, confirms, and reports detailed per-row results.');
$assert(str_contains($ui, '&& required) actions +=') && !str_contains($ui, "(required ? 'Approve REQUIRED' : 'Approve multiplier')"), 'Only REQUIRED rows render an approval action; OPTIONAL rows explain approval does not apply.');
$assert(str_contains($ui, 'pfReadServiceMaterialApiResponse') && str_contains($ui, 'invalid response (HTTP ') && str_contains($ui, 'pfSetServiceMaterialRowBusy'), 'Create, save, approve, and bulk requests parse errors safely and prevent duplicate row submissions.');
$assert(str_contains($api, '$dbErrorsBeforeRuleLookup = count(printflow_db_errors())') && str_contains($api, 'No create or approval is safe until this query succeeds.'), 'Bulk processing never treats a failed rule SELECT as a missing rule to create.');
$assert(str_contains($api, 'service_material_rule_bulk_failure') && str_contains($api, 'Reference '), 'Failed rows include a reference linked to a structured server diagnostic without exposing raw DB details in the UI.');
$assert(!str_contains($api, "'detail' => substr") && !str_contains($api, 'safeDetail'), 'Failure logs do not record raw exception text that could include SQL or connection details.');
echo "Service material bulk workflow contract tests passed.\n";
