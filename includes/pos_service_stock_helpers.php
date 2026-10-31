<?php
/** Pure helpers for Counter Staff POS service material stock validation. */

require_once __DIR__ . '/pos_quantity_validation.php';

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
    if ($serviceId !== 63) return null;
    if (strtolower(trim($serviceName)) !== 'mugs') {
        throw new RuntimeException('The confirmed Mugs material rule no longer matches the active service record.');
    }
    if (!$item
        || (int)($item['id'] ?? 0) !== 37
        || strtolower(trim((string)($item['name'] ?? ''))) !== 'mug'
        || strtoupper(trim((string)($item['status'] ?? ''))) !== 'ACTIVE'
        || strtolower(trim((string)($item['unit_of_measure'] ?? ''))) !== 'pcs'
        || !empty($item['track_by_roll'])) {
        throw new RuntimeException('The confirmed blank Mug material is unavailable or no longer matches inventory item #37.');
    }
    return [[
        'item_id' => 37,
        'name' => (string)$item['name'],
        'uom' => (string)$item['unit_of_measure'],
        'track_by_roll' => false,
        'multiplier' => 1.0,
        'service_type' => 'Mugs',
    ]];
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
