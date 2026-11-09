<?php
/**
 * Roll width audit helpers: configured item width vs OPEN roll snapshots.
 * Item material_width lives on inv_items; each inv_rolls.width_ft is set at receipt
 * and changes only through RollService::correctRollWidth().
 */

require_once __DIR__ . '/tarpaulin_material_calculation.php';

/** Convert inv_items material_width + unit into feet for roll matching. */
function printflow_inventory_item_configured_width_ft(array $item): float
{
    $width = $item['material_width'] ?? null;
    $unit = strtolower(trim((string)($item['material_dimension_unit'] ?? '')));
    if (!is_numeric($width) || (float)$width <= 0 || $unit === '') {
        return 0.0;
    }
    try {
        $factor = printflow_tarpaulin_dimension_unit_to_ft_factor($unit);
    } catch (InvalidArgumentException) {
        return 0.0;
    }
    return (float)$width * $factor;
}

/** OPEN positive-stock rolls for an item, optionally scoped to one branch. */
function printflow_inventory_open_rolls(int $itemId, ?int $branchId = null): array
{
    require_once __DIR__ . '/db.php';
    if ($itemId <= 0) {
        return [];
    }
    $types = 'i';
    $params = [$itemId];
    $branchSql = '';
    if ($branchId !== null && (int)$branchId > 0) {
        $branchSql = ' AND branch_id = ?';
        $types .= 'i';
        $params[] = (int)$branchId;
    }
    return db_query(
        "SELECT id, item_id, branch_id, roll_code, width_ft, total_length_ft, remaining_length_ft, status, received_at
         FROM inv_rolls
         WHERE item_id = ? AND status = 'OPEN' AND remaining_length_ft > 0{$branchSql}
         ORDER BY received_at ASC, id ASC",
        $types,
        $params
    ) ?: [];
}

/** Open rolls whose stored width_ft does not match the item's configured width in feet. */
function printflow_inventory_open_roll_width_mismatches(int $itemId, array $item, ?int $branchId = null): array
{
    if ($itemId <= 0 || (int)($item['track_by_roll'] ?? 0) !== 1) {
        return [];
    }
    $configuredWidthFt = printflow_inventory_item_configured_width_ft($item);
    if ($configuredWidthFt <= 0) {
        return [];
    }
    require_once __DIR__ . '/pos_sticker_laminate_early_stock.php';
    return printflow_pos_sticker_laminate_roll_width_mismatches(
        printflow_inventory_open_rolls($itemId, $branchId),
        $configuredWidthFt
    );
}

/** Staff-facing note after saving a new configured width while legacy rolls still differ. */
function printflow_inventory_roll_width_mismatch_admin_note(string $itemName, float $configuredWidthFt, array $mismatches): string
{
    if ($mismatches === []) {
        return '';
    }
    $format = static fn($value): string => rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
    $rollBits = array_map(static function (array $roll) use ($format): string {
        return 'roll #' . (int)($roll['id'] ?? 0) . ' (' . $format($roll['width_ft'] ?? 0) . ' ft recorded)';
    }, $mismatches);
    return $itemName . ' is now configured as ' . $format($configuredWidthFt) . ' ft wide, but '
        . count($mismatches) . ' open roll(s) still record a different width: '
        . implode(', ', $rollBits) . '. '
        . 'Use Inventory → Roll Tracking → CORRECT WIDTH after measuring the physical roll. '
        . 'Footage and status are not changed by updating material settings alone.';
}
