<?php
/**
 * Poster Printing (service 64) primary substrate usage.
 * Cap Actual Usage of the selected poster_paper alternative (item 212 / 211)
 * at the ordered quantity when that item’s inventory UOM is sheet or pcs.
 * Identified by the POS material-selection registry, not by display name.
 */

require_once __DIR__ . '/pos_material_selection.php';

/** Mapped Poster Printing primary print substrates (mutually exclusive alternatives). */
function printflow_pos_poster_is_primary_paper_item(int $serviceId, int $itemId): bool
{
    if ($serviceId !== 64 || $itemId <= 0) {
        return false;
    }
    $policy = printflow_pos_material_selection_registry()[64][$itemId] ?? null;
    return is_array($policy)
        && ($policy['group'] ?? '') === 'poster_paper'
        && ($policy['role'] ?? '') === 'alternative';
}

function printflow_pos_poster_uom_is_order_qty_capped(string $uom): bool
{
    return in_array(strtolower(trim($uom)), [
        'sheet', 'sheets', 'pc', 'pcs', 'piece', 'pieces',
    ], true);
}

/**
 * Apply the order-qty cap only to the registry primary paper when UOM is sheet/pcs.
 *
 * @param array<string,mixed>|null $item Inventory row (preferred for unit_of_measure / name)
 */
function printflow_pos_poster_primary_usage_is_capped(
    int $serviceId,
    int $itemId,
    ?array $item = null,
    string $fallbackUom = ''
): bool {
    if (!printflow_pos_poster_is_primary_paper_item($serviceId, $itemId)) {
        return false;
    }
    $uom = trim((string)($item['unit_of_measure'] ?? $fallbackUom));
    return printflow_pos_poster_uom_is_order_qty_capped($uom);
}

/**
 * Reject primary Poster sheet/pcs usage above the ordered quantity.
 * Message includes the allowed amount and the material inventory UOM.
 */
function printflow_pos_poster_assert_primary_usage(
    int $serviceId,
    int $itemId,
    float $usageQty,
    int $orderQty,
    ?array $item = null,
    string $fallbackUom = ''
): void {
    if (!printflow_pos_poster_primary_usage_is_capped($serviceId, $itemId, $item, $fallbackUom)) {
        return;
    }
    if ($orderQty < 1) {
        throw new InvalidArgumentException('Poster order quantity is required to validate primary material usage.');
    }
    if (!is_finite($usageQty) || $usageQty <= 0) {
        throw new InvalidArgumentException('Poster material usage must be a positive amount in its inventory unit.');
    }
    if ($usageQty > ((float)$orderQty + 1.0e-9)) {
        $uom = trim((string)($item['unit_of_measure'] ?? $fallbackUom));
        $name = trim((string)($item['name'] ?? ''));
        if ($name === '') {
            $names = printflow_pos_material_selection_item_names(64);
            $name = trim((string)($names[$itemId] ?? ('Material #' . $itemId)));
        }
        if ($uom === '') {
            $uom = 'units';
        }
        throw new InvalidArgumentException(
            $name . ' usage cannot exceed the order quantity (' . $orderQty . ' ' . $uom . ').'
        );
    }
}
