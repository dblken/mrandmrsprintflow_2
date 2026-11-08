<?php
/**
 * Read-only early laminate availability checks for Sticker/Decals POS lines.
 * Uses each laminate item's own roll width and branch-scoped physical rolls;
 * this helper never reserves or deducts stock.
 */

function printflow_pos_sticker_laminate_item_for_finish(string $finish): ?int
{
    $finish = trim($finish);
    if (preg_match('/^Glossy Sticker (?:Printed|Cut Out)\b/i', $finish)) return 20;
    if (preg_match('/^Matte Sticker (?:Printed|Cut Out)\b/i', $finish)) return 21;
    return null;
}

/** Resolve the selected configured sticker option; incomplete form edits stay pending. */
function printflow_pos_sticker_laminate_selection(array $line, array $fieldRows): ?array
{
    $customization = is_array($line['customization'] ?? null) ? $line['customization'] : [];
    $finishSelections = [];
    $dimensionSelections = [];
    foreach ($fieldRows as $row) {
        if (!is_array($row) || empty($row['is_visible'])) continue;
        $type = strtolower(trim((string)($row['field_type'] ?? '')));
        if (!in_array($type, ['select', 'radio'], true)) continue;
        $key = trim((string)($row['field_key'] ?? ''));
        $label = trim((string)($row['field_label'] ?? ''));
        $selected = trim((string)($customization[$key] ?? $customization[$label] ?? ''));
        if ($selected === '') continue;
        if (!preg_match('/\b(dimensions?|sizes?|finish)\b/i', $key . ' ' . $label)) continue;
        $options = $row['_options'] ?? $row['field_options'] ?? [];
        if (is_string($options)) $options = json_decode($options, true);
        if (!is_array($options)) throw new RuntimeException('The configured Sticker/Decals options could not be verified.');
        $optionFound = false;
        foreach ($options as $option) {
            $value = trim((string)(is_array($option) ? ($option['value'] ?? '') : $option));
            if ($value === $selected) { $optionFound = true; break; }
        }
        if (!$optionFound) throw new RuntimeException('A selected Sticker/Decals option is no longer configured. Refresh the POS form.');
        $mappedItemId = printflow_pos_sticker_laminate_item_for_finish($selected);
        if ($mappedItemId !== null) $finishSelections[] = ['row' => $row, 'value' => $selected, 'item_id' => $mappedItemId];
        $pair = printflow_pos_dimension_pair_from_option($selected);
        if ($pair !== null) {
            $unit = printflow_pos_dimension_option_unit($selected, $row);
            if ($unit === '') throw new RuntimeException('The selected Sticker/Decals size has no valid measurement unit.');
            $dimensionSelections[] = ['row' => $row, 'value' => $selected, 'pair' => $pair, 'unit' => $unit];
        }
    }
    if ($finishSelections === []) return null;
    if (count($finishSelections) !== 1) throw new RuntimeException('Select one configured Sticker/Decals finish for laminate stock validation.');
    if (count($dimensionSelections) !== 1) throw new RuntimeException('The selected Sticker/Decals finish must resolve to exactly one configured physical size.');
    $finish = $finishSelections[0];
    $dimension = $dimensionSelections[0];
    $pair = $dimension['pair'];
    $unit = $dimension['unit'];
    $factor = printflow_tarpaulin_dimension_unit_to_ft_factor($unit);
    $widthFt = (float)$pair['width'] * $factor;
    $heightFt = (float)$pair['height'] * $factor;
    $quantity = printflow_pos_parse_positive_quantity($line['qty'] ?? null);
    return [
        'item_id' => (int)$finish['item_id'],
        'finish' => (string)$finish['value'],
        'width_ft' => $widthFt,
        'height_ft' => $heightFt,
        'quantity' => $quantity,
    ];
}

/** Validate the exact inventory identity and derive usable laminate width from saved metadata. */
function printflow_pos_sticker_laminate_roll_width_ft(int $itemId, array $item): float
{
    $expected = [20 => 'Gloss Laminate', 21 => 'Matte Laminate'];
    if (!isset($expected[$itemId]) || (int)($item['id'] ?? 0) !== $itemId
        || strcasecmp(trim((string)($item['name'] ?? '')), $expected[$itemId]) !== 0
        || strtoupper(trim((string)($item['status'] ?? ''))) !== 'ACTIVE'
        || !in_array(strtolower(trim((string)($item['unit_of_measure'] ?? ''))), ['ft', 'feet', 'foot'], true)) {
        throw new RuntimeException('The mapped laminate is not configured as active inventory measured in feet. Contact an administrator.');
    }
    $width = $item['material_width'] ?? null;
    $unit = strtolower(trim((string)($item['material_dimension_unit'] ?? '')));
    if (!is_numeric($width) || (float)$width <= 0 || $unit === '') {
        throw new RuntimeException('The mapped laminate roll width is missing or invalid. Contact an administrator.');
    }
    try {
        $factor = printflow_tarpaulin_dimension_unit_to_ft_factor($unit);
    } catch (InvalidArgumentException $error) {
        throw new RuntimeException('The mapped laminate roll width unit is unsupported. Contact an administrator.');
    }
    return (float)$width * $factor;
}

/** Whether this exact laminate uses physical roll rows rather than ledger footage. */
function printflow_pos_sticker_laminate_uses_physical_rolls(array $item): bool
{
    return !empty($item['track_by_roll']);
}

/** Prepare a physical panel plan using the laminate's own width, never Tarpaulin width presets. */
function printflow_pos_sticker_laminate_panel_plan(array $selection, float $rollWidthFt): array
{
    if (!empty($selection['pending'])) return [];
    $calculation = printflow_tarpaulin_calculate_linear_length(
        $selection['width_ft'] ?? null,
        $selection['height_ft'] ?? null,
        $selection['quantity'] ?? null,
        $rollWidthFt
    );
    return [
        'panel_count_per_piece' => 1,
        'finished_quantity' => (int)$selection['quantity'],
        'run_length_per_panel_ft' => (float)$calculation['run_length_per_piece_ft'],
        'calculation' => $calculation,
    ];
}

/** Defensive branch filter for callers and deterministic test fixtures. */
function printflow_pos_sticker_laminate_filter_branch_rolls(array $rolls, int $branchId): array
{
    if ($branchId <= 0) return [];
    return array_values(array_filter($rolls, static function (array $roll) use ($branchId): bool {
        return printflow_roll_belongs_to_branch($roll['branch_id'] ?? null, $branchId);
    }));
}

/** Match only open positive-stock rolls whose recorded width is compatible with item metadata. */
function printflow_pos_sticker_laminate_filter_rolls_by_width(array $rolls, float $configuredWidthFt): array
{
    if (!is_finite($configuredWidthFt) || $configuredWidthFt <= 0) return [];
    return array_values(array_filter($rolls, static function (array $roll) use ($configuredWidthFt): bool {
        if ((int)($roll['id'] ?? 0) <= 0 || (int)($roll['branch_id'] ?? 0) <= 0
            || strtoupper(trim((string)($roll['status'] ?? ''))) !== 'OPEN'
            || !is_numeric($roll['remaining_length_ft'] ?? null)
            || (float)$roll['remaining_length_ft'] <= 0) return false;
        $physicalWidth = is_numeric($roll['width_ft'] ?? null) ? (float)$roll['width_ft'] : 0.0;
        // Older roll rows with no width inherit only their exact item's verified width.
        return $physicalWidth <= 0 || abs($physicalWidth - $configuredWidthFt) <= 0.01;
    }));
}

/** Sum valid branch-scoped open roll footage in the inventory's recorded unit (feet). */
function printflow_pos_sticker_laminate_roll_balance_ft(array $rolls): float
{
    $total = 0.0;
    foreach ($rolls as $roll) {
        if (!is_array($roll) || (int)($roll['id'] ?? 0) <= 0 || (int)($roll['branch_id'] ?? 0) <= 0
            || strtoupper(trim((string)($roll['status'] ?? ''))) !== 'OPEN'
            || !is_numeric($roll['remaining_length_ft'] ?? null)) continue;
        $length = (float)$roll['remaining_length_ft'];
        if (is_finite($length) && $length > 0) $total += $length;
    }
    return $total;
}

