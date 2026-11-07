<?php
/** Owner-confirmed early POS stock rule for the active Mugs service. */

function printflow_pos_mugs_release_material_rule(int $serviceId, string $serviceName, ?array $item): ?array
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

/**
 * Set Price / Production Assignment for Mugs (service 63) may include shared
 * mapped materials such as Subli Paper or Box Mug. Those rows are valid when
 * staff actually used them. The dedicated blank Mug inventory item (#37) must
 * still be present among the assigned rows; other mapped item IDs must not be
 * rejected merely for not being #37.
 *
 * @param list<array{item_id?:int|string}> $assigned
 */
function printflow_pos_mugs_assert_dedicated_mug_assigned(array $assigned): void
{
    foreach ($assigned as $material) {
        if (!is_array($material)) {
            continue;
        }
        if ((int)($material['item_id'] ?? 0) === 37) {
            return;
        }
    }
    throw new RuntimeException('The dedicated Mug material must be assigned for this service.');
}
