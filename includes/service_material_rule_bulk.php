<?php
declare(strict_types=1);

require_once __DIR__ . '/service_material_rule_approval.php';

/** Inventory units supported by the selected-material area-rate workflow. */
function printflow_service_material_bulk_uom_valid($uom): bool
{
    if (!is_string($uom)) return false;
    $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $uom) ?? ''));
    return in_array($normalized, ['pc', 'pcs', 'piece', 'pieces', 'sheet', 'sheets'], true);
}

/** Pure row eligibility/state check shared by bulk preview and its tests. */
function printflow_service_material_bulk_rule_state(array $rule, $submittedMultiplier, $submittedBasis, string $expectedRuleType): array
{
    $multiplier = printflow_service_material_parse_multiplier($submittedMultiplier);
    $basis = strtoupper(trim(is_scalar($submittedBasis) ? (string)$submittedBasis : ''));
    $storedType = strtoupper(trim((string)($rule['rule_type'] ?? '')));
    $expectedRuleType = strtoupper(trim($expectedRuleType));
    if (!in_array($expectedRuleType, ['REQUIRED', 'OPTIONAL'], true) || $storedType !== $expectedRuleType) {
        return ['action' => 'pending', 'multiplier' => $multiplier, 'basis' => $basis, 'message' => 'Stored rule classification does not match the verified service selection policy.'];
    }
    if ($multiplier === null) {
        return ['action' => 'pending', 'multiplier' => null, 'basis' => $basis, 'message' => 'Enter a positive multiplier with up to two decimal places.'];
    }
    if ($basis !== 'AREA_SQFT') {
        return ['action' => 'pending', 'multiplier' => $multiplier, 'basis' => $basis, 'message' => 'Set the consumption basis to inventory units per square foot.'];
    }
    $approved = (int)($rule['qty_multiplier_approved'] ?? 0) === 1;
    $approver = (int)($rule['qty_multiplier_approved_by'] ?? 0);
    $timestamp = $rule['qty_multiplier_approved_at'] ?? null;
    $validAudit = $approved && $approver > 0 && printflow_service_material_approval_timestamp_valid($timestamp);
    $sameMultiplier = ($current = printflow_service_material_parse_multiplier($rule['qty_multiplier'] ?? null)) !== null && $current === $multiplier;
    $sameBasis = strtoupper(trim((string)($rule['qty_multiplier_basis'] ?? ''))) === $basis
        && strtoupper(trim((string)($rule['qty_multiplier_approved_basis'] ?? ''))) === $basis;
    if ($validAudit && $sameMultiplier && $sameBasis) {
        return ['action' => 'already_approved', 'multiplier' => $multiplier, 'basis' => $basis, 'message' => 'Already approved with matching Admin audit.'];
    }
    if ($expectedRuleType !== 'REQUIRED') {
        return ['action' => 'pending', 'multiplier' => $multiplier, 'basis' => $basis, 'message' => 'OPTIONAL material rules are not eligible for approval; they remain unapproved and selectable only by staff.'];
    }
    return ['action' => 'approve', 'multiplier' => $multiplier, 'basis' => $basis, 'message' => $validAudit ? 'A changed multiplier or basis will be saved and explicitly reapproved.' : 'Valid configuration will receive a new Admin approval audit.'];
}
