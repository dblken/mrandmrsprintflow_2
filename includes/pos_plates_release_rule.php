<?php
/**
 * Plates (service 39) blank usage: one plate blank per ordered quantity when a
 * new physical plate is consumed. Applies only to plate_media alternatives
 * (AC/SP blanks); ink and other consumables are unchanged.
 */

require_once __DIR__ . '/pos_material_selection.php';

/** Inventory IDs in the Plates plate_media alternative group are the plate blanks. */
function printflow_pos_plates_is_blank_item(int $serviceId, int $itemId): bool
{
    if ($serviceId !== 39 || $itemId <= 0) {
        return false;
    }
    $policy = printflow_pos_material_selection_registry()[39][$itemId] ?? null;
    return is_array($policy)
        && ($policy['group'] ?? '') === 'plate_media'
        && ($policy['role'] ?? '') === 'alternative';
}

/** Resolve Type Of Plates from POS / order customization. */
function printflow_pos_plates_resolve_type(array $customization): string
{
    foreach (['type_of_plates', 'Type Of Plates', 'plate_type', 'Plate Type'] as $key) {
        if (!array_key_exists($key, $customization)) {
            continue;
        }
        $value = $customization[$key];
        if (is_scalar($value) && trim((string)$value) !== '') {
            return trim((string)$value);
        }
    }
    return '';
}

/**
 * Whether this Plates order consumes a new physical blank.
 * New Plate / Motor Cycle / Car And Euro all consume blanks today.
 * Explicit reprint / existing / reuse workflows do not.
 */
function printflow_pos_plates_order_consumes_blank(array $customization): bool
{
    $type = strtolower(trim(preg_replace('/\s+/', ' ', printflow_pos_plates_resolve_type($customization)) ?? ''));
    if ($type !== '' && preg_match('/\b(reprint|existing|reuse|customer[ -]?owned)\b/', $type) === 1) {
        return false;
    }
    // Missing type still consumes a blank when staff assigns a plate_media item.
    return true;
}

function printflow_pos_plates_is_quantity_linked_blank(
    int $serviceId,
    int $itemId,
    array $customization = []
): bool {
    return printflow_pos_plates_is_blank_item($serviceId, $itemId)
        && printflow_pos_plates_order_consumes_blank($customization);
}

/** Exact blank usage for an order quantity (1→1, 2→2). */
function printflow_pos_plates_canonical_blank_usage(int $orderQty): float
{
    if ($orderQty < 1) {
        throw new InvalidArgumentException('Plate order quantity must be a positive whole number.');
    }
    return (float)$orderQty;
}

/**
 * Reject plate blank Actual Usage above the ordered quantity.
 * Message matches the Counter Staff / Production Assignment contract.
 */
function printflow_pos_plates_assert_blank_usage(
    int $serviceId,
    int $itemId,
    float $usageQty,
    int $orderQty,
    array $customization = []
): void {
    if (!printflow_pos_plates_is_quantity_linked_blank($serviceId, $itemId, $customization)) {
        return;
    }
    if ($orderQty < 1) {
        throw new InvalidArgumentException('Plate order quantity is required to validate blank usage.');
    }
    if (!is_finite($usageQty) || $usageQty <= 0 || abs($usageQty - round($usageQty)) > 1.0e-9) {
        throw new InvalidArgumentException('Plate blank usage must be a positive whole number of plates.');
    }
    $usageInt = (int)round($usageQty);
    if ($usageInt > $orderQty) {
        $label = $orderQty === 1 ? 'plate' : 'plates';
        throw new InvalidArgumentException(
            'Plate usage cannot exceed the order quantity (' . $orderQty . ' ' . $label . ').'
        );
    }
}

/** Cart/job quantity changes keep blank usage equal to the new order quantity. */
function printflow_pos_plates_scale_blank_usage(int $toQty): float
{
    return printflow_pos_plates_canonical_blank_usage($toQty);
}
