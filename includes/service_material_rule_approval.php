<?php
declare(strict_types=1);

/** Mirror the application's exact Admin role label; this is not a new permission tier. */
function printflow_service_material_rule_admin_allowed(?string $userType): bool
{
    return $userType === 'Admin';
}

/** Validate the exact DATETIME representation returned by MySQL. */
function printflow_service_material_approval_timestamp_valid($value): bool
{
    if (!is_string($value)
        || !preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', trim($value), $parts)) {
        return false;
    }
    return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])
        && (int)$parts[4] <= 23
        && (int)$parts[5] <= 59
        && (int)$parts[6] <= 59;
}

/** Validate and canonicalize a user-entered DECIMAL(10,2) multiplier. */
function printflow_service_material_parse_multiplier($value): ?string
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) return null;
    $text = trim((string)$value);
    if (!preg_match('/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/', $text)) return null;
    if ((float)$text <= 0) return null;
    return number_format((float)$text, 2, '.', '');
}

/** Apply the approval state transition when a multiplier is edited. */
function printflow_service_material_multiplier_edit_state(?string $current, string $next, bool $approved, ?int $approvedBy, ?string $approvedAt): array
{
    $same = $current !== null && number_format((float)$current, 2, '.', '') === $next;
    $validAudit = $approvedBy !== null && $approvedBy > 0
        && printflow_service_material_approval_timestamp_valid($approvedAt);
    if ($same && (($approved && $validAudit) || (!$approved && $approvedBy === null && $approvedAt === null))) {
        return ['changed' => false, 'approved' => $approved, 'approved_by' => $approvedBy, 'approved_at' => $approvedAt];
    }
    return ['changed' => true, 'approved' => false, 'approved_by' => null, 'approved_at' => null];
}
