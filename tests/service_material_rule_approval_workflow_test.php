<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/service_material_rule_approval.php';
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$assert(printflow_service_material_rule_admin_allowed('Admin'), 'Admin role may use the rule approval workflow.');
$assert(!printflow_service_material_rule_admin_allowed('Manager'), 'Manager role is denied even when calling the API directly.');
$assert(!printflow_service_material_rule_admin_allowed('Staff'), 'Staff role is denied even when calling the API directly.');
$assert(!printflow_service_material_rule_admin_allowed(null), 'Unauthenticated role values are denied.');
$assert(printflow_service_material_approval_timestamp_valid('2026-11-01 10:15:30'), 'A valid MySQL DATETIME is accepted as approval evidence.');
$assert(!printflow_service_material_approval_timestamp_valid('2026-99-99 99:99:99'), 'Malformed approval timestamps are rejected.');

$assert(printflow_service_material_parse_multiplier('1') === '1.00', 'Positive whole multipliers canonicalize to two decimals.');
$assert(printflow_service_material_parse_multiplier('0.25') === '0.25', 'Positive fractional multiplier is accepted.');
foreach (['', '0', '0.00', '-1', '1.001', '1e2', '100000000', 'NaN'] as $invalid) {
    $assert(printflow_service_material_parse_multiplier($invalid) === null, 'Invalid multiplier rejected: ' . $invalid);
}

$same = printflow_service_material_multiplier_edit_state('1.00', '1.00', true, 17, '2026-10-31 12:00:00');
$assert(!$same['changed'] && $same['approved'] && $same['approved_by'] === 17, 'Saving an unchanged approved multiplier preserves its audit record.');
$sameWithoutAudit = printflow_service_material_multiplier_edit_state('1.00', '1.00', true, null, null);
$assert($sameWithoutAudit['changed'] && !$sameWithoutAudit['approved'] && $sameWithoutAudit['approved_by'] === null && $sameWithoutAudit['approved_at'] === null,
    'An approved row missing audit metadata is cleared even when its multiplier text is unchanged.');
$sameWithInvalidAudit = printflow_service_material_multiplier_edit_state('1.00', '1.00', true, 17, '2026-99-99 99:99:99');
$assert($sameWithInvalidAudit['changed'] && !$sameWithInvalidAudit['approved'], 'An approved row with malformed audit evidence is cleared and requires reapproval.');
$changed = printflow_service_material_multiplier_edit_state('1.00', '1.25', true, 17, '2026-10-31 12:00:00');
$assert($changed['changed'] && !$changed['approved'] && $changed['approved_by'] === null && $changed['approved_at'] === null, 'Changing an approved multiplier clears approval and audit for explicit reapproval.');
$new = printflow_service_material_multiplier_edit_state(null, '2.00', false, null, null);
$assert($new['changed'] && !$new['approved'] && $new['approved_by'] === null && $new['approved_at'] === null, 'A new multiplier starts unapproved with no audit metadata.');

$api = (string)file_get_contents(dirname(__DIR__) . '/admin/service_material_rules_api.php');
$ui = (string)file_get_contents(dirname(__DIR__) . '/admin/inv_items_management.php');
$assert(str_contains($api, 'printflow_service_material_rule_admin_allowed(get_user_type())')
    && str_contains($api, "http_response_code(403)"), 'Direct API requests from Manager and Staff are denied by the tested exact Admin role guard.');
$assert(str_contains($api, "verify_csrf_token((string)(\$_POST['csrf_token'] ?? '')") && str_contains($ui, "form.set('csrf_token', window.PF_INVENTORY_CSRF || '')"), 'Mutations require the existing CSRF token and UI submits it.');
$assert(str_contains($api, "\$_POST['action'] ?? ''") && str_contains($api, 'qty_multiplier_approved_by = ?, qty_multiplier_approved_at = NOW()'), 'Approval records Admin ID and timestamp.');
$assert(str_contains($api, 'qty_multiplier_approved_by') && str_contains($api, 'qty_multiplier_approved_at'), 'API reads and exposes approval audit fields.');
$assert(str_contains($api, 'Only REQUIRED service material rules can be approved.') && str_contains($api, 'Set a valid positive multiplier before approving'), 'OPTIONAL approval and invalid/missing multipliers are rejected.');
$assert(str_contains($api, 'qty_multiplier_approved = 0') && str_contains($api, 'qty_multiplier_approved_by = NULL, qty_multiplier_approved_at = NULL'), 'Multiplier changes clear approval and require an explicit separate approval action.');
$assert(str_contains($ui, 'service_name') && str_contains($ui, 'material_name') && str_contains($ui, 'inventory_uom') && str_contains($ui, 'rule_type') && str_contains($ui, 'qty_multiplier_approved'), 'Admin UI shows service, material, UOM, rule type, multiplier, and approval status.');
$assert(str_contains($ui, 'if (is_admin())') && !str_contains((string)file_get_contents(dirname(__DIR__) . '/admin/inventory_management.php'), 'serviceMaterialApprovalPanel'), 'Approval UI is rendered only in the Admin view; redirect stub remains untouched.');
$assert(str_contains($api, 'WHERE id = ? FOR UPDATE'), 'Mutations lock the existing rule and preserve its ID and inventory mapping.');

echo "Service material approval workflow tests passed.\n";
