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
$assert(str_contains($api, "http_response_code(410)")
    && str_contains($api, "'available' => false")
    && str_contains($api, 'Service Material Rules configuration is unavailable'),
    'The exclusive Admin configuration API is retired with a clear feature-unavailable response.');
$assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE)\b/i', $api), 'Retired API no longer mutates service material rules.');
$assert(!str_contains($ui, 'serviceMaterialApprovalPanel')
    && !str_contains($ui, 'Service Material Rules')
    && !str_contains($ui, 'pfApproveServiceMaterialRule'),
    'Inventory Items no longer hosts the approval UI or mutation controls.');
$assert(!str_contains((string)file_get_contents(dirname(__DIR__) . '/admin/inventory_management.php'), 'serviceMaterialApprovalPanel'),
    'Redirect stub remains untouched and does not reintroduce the panel.');
$assert(is_file(dirname(__DIR__) . '/includes/service_material_rule_approval.php'),
    'Shared approval helper remains available for readers and historical audit interpretation.');

echo "Service material approval workflow tests passed.\n";
