<?php
/** Pure helpers for Counter Staff POS service material stock validation. */

require_once __DIR__ . '/pos_quantity_validation.php';
require_once __DIR__ . '/service_material_rule_approval.php';
require_once __DIR__ . '/pos_mugs_release_rule.php';

/** Reject the two confirmed Plates media when a rule maps them to Sintraboard Standees. */
function printflow_pos_known_service_material_conflict(string $serviceType, string $materialName): ?string
{
    $service = strtoupper(trim(preg_replace('/\\s+/', ' ', $serviceType) ?? $serviceType));
    $material = strtoupper(trim(preg_replace('/\\s+/', ' ', $materialName) ?? $materialName));
    if (in_array($material, ['AC MC', 'SP MC'], true) && str_contains($service, 'SINTRABOARD STANDEE')) {
        return 'Material ' . $material . ' is configured for Plates and cannot be assigned to Sintraboard Standees. Correct the service material mapping before approving or consuming it.';
    }
    return null;
}

/** Canonical service_type values for the POS service IDs checked by configuration. */
function printflow_pos_service_material_expected_type(int $serviceId): ?string
{
    return [
        36 => 'Decals/Stickers (Print/Cut)',
        39 => 'Plates',
        64 => 'Poster Printing',
        67 => 'Brochure',
        71 => 'Sintraboard Standees',
    ][$serviceId] ?? null;
}

/** Existing accepted labels that normalize to service 36's stored rule key. */
function printflow_pos_service_material_type_aliases(int $serviceId): array
{
    $canonical = printflow_pos_service_material_expected_type($serviceId);
    if ($canonical === null) return [];
    if ($serviceId !== 36) return [$canonical];
    return [
        $canonical,
        'Decals / Stickers (Print/Cut)',
        'Decals / Stickers (Print & Cut)',
        'Stickers/Decals',
        'Stickers',
        'Sticker',
        'Decal',
        'Decals',
    ];
}

function printflow_pos_canonical_service_material_type(int $serviceId, string $serviceType): ?string
{
    foreach (printflow_pos_service_material_type_aliases($serviceId) as $alias) {
        if (strcasecmp(trim($serviceType), $alias) === 0) {
            return printflow_pos_service_material_expected_type($serviceId);
        }
    }
    return null;
}

/**
 * Resolve one rule set only. If records are duplicated across canonical and
 * alias service_type values, fail closed rather than double-counting or
 * silently dropping one set.
 */
function printflow_pos_select_service_material_rule_set(int $serviceId, array $rows): array
{
    $allowed = array_map('strtolower', printflow_pos_service_material_type_aliases($serviceId));
    if ($allowed === []) return $rows;
    $groups = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $serviceType = trim((string)($row['service_type'] ?? ''));
        if (!in_array(strtolower($serviceType), $allowed, true)) continue;
        $groups[$serviceType][] = $row;
    }
    if (count($groups) > 1) {
        throw new RuntimeException('Material rules exist under multiple aliases for service ID ' . $serviceId . '. Resolve the duplicate service_type configuration before POS stock validation.');
    }
    return $groups === [] ? [] : array_values(reset($groups));
}

function printflow_pos_assert_service_stock_multiplier_schema(
    bool $hasMultiplierColumn,
    bool $hasApprovalColumn,
    bool $hasApproverColumn,
    bool $hasApprovalTimestampColumn
): void
{
    if (!$hasMultiplierColumn || !$hasApprovalColumn || !$hasApproverColumn || !$hasApprovalTimestampColumn) {
        throw new RuntimeException(
            'POS service material stock validation is unavailable because the multiplier approval or audit schema is missing or could not be verified. Contact an Admin.'
        );
    }
}

/** Only a positive multiplier with explicit approval and complete Admin audit creates demand. */
function printflow_pos_approved_service_multiplier($multiplier, $approved, $approvedBy = null, $approvedAt = null): float
{
    $isApproved = in_array($approved, [1, '1', true], true);
    $validTimestamp = printflow_service_material_approval_timestamp_valid($approvedAt);
    if (!$isApproved || $multiplier === null || !is_numeric($multiplier)
        || !is_finite((float)$multiplier) || (float)$multiplier <= 0
        || filter_var($approvedBy, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
        || !$validTimestamp) {
        throw new RuntimeException(
            'A REQUIRED service material has no valid Admin-approved multiplier and approval audit. An Admin must enter the intended multiplier and explicitly approve it before this service can be added.'
        );
    }
    return (float)$multiplier;
}

function printflow_pos_assert_service_material_type(int $serviceId, string $serviceType): void
{
    $expectedType = printflow_pos_service_material_expected_type($serviceId);
    if ($expectedType !== null && printflow_pos_canonical_service_material_type($serviceId, $serviceType) === null) {
        throw new RuntimeException(
            'The POS material rule type for service ID ' . $serviceId . ' must be configured as "' . $expectedType . '"; received "' . $serviceType . '".'
        );
    }
}

function printflow_pos_assert_required_service_material_rule(int $serviceId, string $serviceType, bool $hasActiveRequiredRule): void
{
    if (!in_array($serviceId, [39, 64, 67], true) || $hasActiveRequiredRule) return;
    $serviceLabel = printflow_pos_service_material_expected_type($serviceId) ?? $serviceType;
    throw new RuntimeException(
        'No active REQUIRED material rule is configured for ' . $serviceLabel . ' (service ID ' . $serviceId . '). Ask an Admin to configure and approve the material multiplier before accepting this service.'
    );
}

function printflow_pos_service_required_material_quantity($serviceQuantity, $multiplier): float
{
    $qty = printflow_pos_parse_positive_quantity($serviceQuantity);
    if (!is_numeric($multiplier) || !is_finite((float)$multiplier) || (float)$multiplier <= 0) {
        throw new InvalidArgumentException('A required material consumption ratio is not configured.');
    }
    $required = $qty * (float)$multiplier;
    if (!is_finite($required) || $required <= 0) {
        throw new InvalidArgumentException('The required material quantity could not be calculated.');
    }
    return $required;
}

/**
 * The owner-confirmed Mugs rule is deliberately narrow: one active Mugs
 * service item (63) consumes one blank Mug (37) per finished unit. Validate
 * the live catalog item before returning it so an ID reuse or catalog change
 * fails closed. This feeds the existing POS stock validator, not a second
 * material-mapping store.
 */
function printflow_pos_confirmed_mugs_material_rule(int $serviceId, string $serviceName, ?array $item): ?array
{
    return printflow_pos_mugs_release_material_rule($serviceId, $serviceName, $item);
}

function printflow_pos_service_stock_add_demand(array &$demand, int $itemId, float $quantity, string $name, string $uom): void
{
    if ($itemId <= 0 || !is_finite($quantity) || $quantity <= 0) {
        throw new RuntimeException('The required POS service material quantity could not be accumulated.');
    }
    if (!isset($demand[$itemId])) $demand[$itemId] = ['quantity' => 0.0, 'name' => $name, 'uom' => $uom];
    $demand[$itemId]['quantity'] += $quantity;
}

function printflow_pos_service_stock_exceeds_available(float $required, float $available): bool
{
    if (!is_finite($required) || !is_finite($available) || $required < 0 || $available < 0) {
        throw new RuntimeException('POS service material stock could not be validated safely.');
    }
    return $required > $available;
}
