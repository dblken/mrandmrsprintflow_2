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

/**
 * Materials that follow the owner-confirmed Mugs order-qty usage rule:
 * 1 Mug (#37) pcs + 0.5 sheet Subli Paper (#55) per ordered mug quantity.
 * Box Mug and other mapped rows stay on generic proportional scaling.
 */
function printflow_pos_mugs_is_quantity_linked_material(int $itemId, string $itemName = ''): bool
{
    if ($itemId === 37 || $itemId === 55) {
        return true;
    }
    return strtolower(trim($itemName)) === 'subli paper';
}

function printflow_pos_mugs_is_subli_paper_material(int $itemId, string $itemName = ''): bool
{
    if ($itemId === 55) {
        return true;
    }
    return strtolower(trim($itemName)) === 'subli paper';
}

/** Confirmed Subli Paper usage rate: 0.5 sheet per ordered mug. */
function printflow_pos_mugs_subli_sheets_per_mug(): float
{
    return 0.5;
}

/** Canonical Subli Paper sheets for an order quantity (1→0.5, 2→1, 4→2). */
function printflow_pos_mugs_canonical_subli_sheets(int $orderQty): float
{
    if ($orderQty < 1) {
        throw new InvalidArgumentException('Mugs order quantity must be a positive whole number.');
    }
    return round(printflow_pos_mugs_subli_sheets_per_mug() * $orderQty, 2);
}

/**
 * Resolve Mug / Subli Paper usage for a cart quantity change.
 * Scales the saved per-order rate so intentional staff Actual Usage is kept.
 * Subli Paper falls back to 0.5 sheet/mug only when the saved rate cannot be
 * represented as a positive two-decimal inventory quantity.
 * Mug blanks stay whole pcs (1:1 fallback).
 */
function printflow_pos_mugs_scale_quantity_linked_usage(
    float $currentQty,
    int $fromQty,
    int $toQty,
    int $itemId = 0,
    string $itemName = ''
): float {
    if ($fromQty < 1 || $toQty < 1 || !is_finite($currentQty) || $currentQty <= 0) {
        throw new InvalidArgumentException('Mugs material scale inputs are invalid.');
    }
    $rate = $currentQty / $fromQty;

    if (printflow_pos_mugs_is_subli_paper_material($itemId, $itemName)) {
        $rate2 = round($rate, 2);
        if ($rate2 > 0.0 && abs($rate - $rate2) < 1.0e-9) {
            return round($rate2 * $toQty, 2);
        }
        return printflow_pos_mugs_canonical_subli_sheets($toQty);
    }

    $rateNearest = (int)round($rate);
    if ($rateNearest >= 1 && abs($rate - $rateNearest) < 1.0e-6) {
        return (float)($rateNearest * $toQty);
    }
    return (float)$toQty;
}
