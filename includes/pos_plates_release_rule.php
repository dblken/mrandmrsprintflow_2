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

/**
 * POS Order Details plate type → mapped plate_media inventory item.
 * Address/Adress Plate → AC HOME #6, Car And Euro Plate → AC EURO #3,
 * New Plate → AC PH #2. Unlisted selections fail closed until an Admin verifies a mapping.
 *
 * @return array{item_id:int,name:string}|null
 */
function printflow_pos_plates_early_stock_item_for_type(string $type): ?array
{
    $normalized = strtolower(trim((string)preg_replace('/\s+/', ' ', $type)));
    $itemId = [
        'new plate' => 2,
        'car and euro plate' => 3,
        'address plate' => 6,
        'adress plate' => 6,
    ][$normalized] ?? 0;
    if ($itemId <= 0) {
        return null;
    }
    $name = printflow_pos_material_selection_item_names(39)[$itemId] ?? '';
    if ($name === '' || !printflow_pos_plates_is_blank_item(39, $itemId)) {
        throw new RuntimeException(
            'The Plates material mapping for "' . trim($type) . '" is missing inventory item #' . $itemId . '.'
        );
    }
    return ['item_id' => $itemId, 'name' => $name];
}

/**
 * Fail closed unless the live inventory row is the mapped plate blank.
 *
 * @param array<string,mixed>|null $item
 */
function printflow_pos_plates_assert_early_stock_item(int $itemId, string $expectedName, ?array $item): array
{
    $uom = strtolower(trim((string)($item['unit_of_measure'] ?? '')));
    if (!$item
        || (int)($item['id'] ?? 0) !== $itemId
        || strcasecmp(trim((string)($item['name'] ?? '')), $expectedName) !== 0
        || strtoupper(trim((string)($item['status'] ?? ''))) !== 'ACTIVE'
        || !in_array($uom, ['pc', 'pcs', 'piece', 'pieces'], true)
        || !empty($item['track_by_roll'])) {
        throw new RuntimeException(
            'The mapped Plates material (' . $expectedName . ', inventory item #' . $itemId
            . ') is inactive, renamed, unavailable, or not inventoried in pieces. Ask an Admin to restore the mapping before adding this plate.'
        );
    }
    return $item;
}

/**
 * Early POS demand: 1 inventory piece of the mapped blank per ordered plate.
 * Read-only. An absent plate selection returns null; an unrecognized selected type fails closed.
 *
 * @param array<string,mixed> $line
 * @param array<string,mixed>|null $itemOverride
 * @return array{item_id:int,name:string,uom:string,required:float,branch_id:int}|null
 */
function printflow_pos_plates_early_demand(array $line, int $branchId, ?array $itemOverride = null): ?array
{
    if ($branchId <= 0) {
        throw new RuntimeException('The POS branch could not be identified for Plates stock validation.');
    }
    $customization = is_array($line['customization'] ?? null) ? $line['customization'] : [];
    $serviceId = (int)($customization['service_id'] ?? $line['product_id'] ?? $line['id'] ?? 0);
    if ($serviceId !== 39) {
        return null;
    }
    $plateType = printflow_pos_plates_resolve_type($customization);
    if ($plateType === '') {
        return null;
    }
    $mapped = printflow_pos_plates_early_stock_item_for_type($plateType);
    if ($mapped === null) {
        throw new RuntimeException(
            'No verified inventory mapping exists for the selected Plate type "' . $plateType
            . '". Ask an Admin to verify its stock mapping before adding this plate.'
        );
    }
    $item = $itemOverride;
    if ($item === null) {
        if (!class_exists('InventoryManager')) {
            throw new RuntimeException('Inventory lookup is unavailable for Plates stock validation.');
        }
        $item = InventoryManager::getItem((int)$mapped['item_id']);
    }
    $item = printflow_pos_plates_assert_early_stock_item((int)$mapped['item_id'], (string)$mapped['name'], $item);
    $quantity = printflow_pos_parse_positive_quantity($line['qty'] ?? null);
    $required = printflow_pos_plates_canonical_blank_usage($quantity);
    $uom = trim((string)$item['unit_of_measure']);
    return [
        'item_id' => (int)$item['id'],
        'name' => (string)$item['name'],
        'uom' => $uom,
        'required' => $required,
        'branch_id' => $branchId,
    ];
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
