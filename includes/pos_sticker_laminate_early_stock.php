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

/** Matte/Gloss laminate SKUs reserved for Sticker/Decals (not Tarpaulin). */
function printflow_pos_sticker_laminate_is_mapped_inventory_item(int $itemId): bool
{
    return in_array($itemId, [20, 21], true);
}

/** Resolve sticker line quantity from the cart row or embedded customization fields. */
function printflow_pos_sticker_laminate_line_quantity(array $line): int
{
    $customization = is_array($line['customization'] ?? null) ? $line['customization'] : [];
    foreach ([$line['qty'] ?? null, $customization['quantity'] ?? null, $customization['qty'] ?? null] as $candidate) {
        if ($candidate === null || $candidate === '') {
            continue;
        }
        try {
            return printflow_pos_parse_positive_quantity($candidate);
        } catch (InvalidArgumentException) {
            continue;
        }
    }
    throw new InvalidArgumentException('Quantity must be a positive whole number.');
}

/** Linear footage demand summed from one or more validated panel plans. */
function printflow_pos_sticker_laminate_required_ft_for_plans(array $plansForItem): float
{
    $total = 0.0;
    foreach ($plansForItem as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $plan = is_array($entry['plan'] ?? null) ? $entry['plan'] : [];
        if (isset($plan['calculation']['required_linear_ft']) && is_numeric($plan['calculation']['required_linear_ft'])) {
            $total += (float)$plan['calculation']['required_linear_ft'];
            continue;
        }
        $count = (int)($plan['panel_count_per_piece'] ?? 0) * (int)($plan['finished_quantity'] ?? 0);
        $length = (float)($plan['run_length_per_panel_ft'] ?? 0);
        $total += $count * $length;
    }
    return $total;
}

/** Staff-facing message when the sticker exceeds the laminate roll's printable width. */
function printflow_pos_sticker_laminate_size_does_not_fit_message(
    array $selection,
    float $rollWidthFt,
    string $itemName
): string {
    $widthFt = (float)($selection['width_ft'] ?? 0);
    $heightFt = (float)($selection['height_ft'] ?? 0);
    $qty = (int)($selection['quantity'] ?? 0);
    $maxDim = max($widthFt, $heightFt);
    $minDim = min($widthFt, $heightFt);
    $rollLabel = rtrim(rtrim(number_format($rollWidthFt, 3, '.', ''), '0'), '.');
    $widthLabel = rtrim(rtrim(number_format($widthFt, 3, '.', ''), '0'), '.');
    $heightLabel = rtrim(rtrim(number_format($heightFt, 3, '.', ''), '0'), '.');
    $needWidth = rtrim(rtrim(number_format($maxDim, 3, '.', ''), '0'), '.');
    $pieceLabel = $qty === 1 ? '1 piece' : $qty . ' pieces';
    return 'This ' . $widthLabel . ' × ' . $heightLabel . ' ft sticker (' . $pieceLabel
        . ') does not fit on the configured ' . $rollLabel . ' ft wide ' . $itemName
        . ' roll. Each piece needs a roll at least ' . $needWidth . ' ft wide'
        . ($rollWidthFt + 0.001 < $minDim
            ? ' (the shortest run along the roll would be ' . rtrim(rtrim(number_format($minDim * $qty, 3, '.', ''), '0'), '.') . ' ft once width allows).'
            : '.');
}

/** First non-empty customization value for the live field name or its older alias. */
function printflow_pos_sticker_custom_spec_value(array $customization, array $keys): string
{
    foreach ($keys as $key) {
        $value = trim((string)($customization[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return '';
}

/** Map the structured custom-size finish controls to the same laminate items. */
function printflow_pos_sticker_laminate_item_for_custom_finish(string $finish, string $process): ?int
{
    $finish = strtolower(trim($finish));
    $process = strtolower(trim(preg_replace('/\s+/', ' ', $process) ?? ''));
    if (!in_array($process, ['printed', 'cut out', 'cutout'], true)) return null;
    if (in_array($finish, ['gloss', 'glossy'], true)) return 20;
    if ($finish === 'matte') return 21;
    return null;
}

/** The combined preset size dropdown, not separate Finish / Sticker Type controls. */
function printflow_pos_sticker_is_preset_size_select_field(array $row): bool
{
    if (!is_array($row) || empty($row['is_visible'])) return false;
    if (strtolower(trim((string)($row['field_type'] ?? ''))) !== 'select') return false;
    $key = strtolower(trim((string)($row['field_key'] ?? '')));
    if (in_array($key, ['finish', 'sticker_type', 'sticker_custom_finish', 'sticker_custom_process'], true)) {
        return false;
    }
    $label = strtolower(trim((string)($row['field_label'] ?? '')));
    return preg_match('/\b(dimensions?|sizes?)\b/', $key . ' ' . $label) === 1;
}

/** Whether a dimensions select value matches a configured preset (not custom size). */
function printflow_pos_sticker_value_is_configured_preset(string $value, array $fieldRows): bool
{
    $value = trim($value);
    if ($value === ''
        || (function_exists('printflow_service_field_is_custom_size_option_value')
            && printflow_service_field_is_custom_size_option_value($value))) {
        return false;
    }
    foreach ($fieldRows as $row) {
        if (!printflow_pos_sticker_is_preset_size_select_field($row)) {
            continue;
        }
        $options = $row['_options'] ?? $row['field_options'] ?? [];
        if (is_string($options)) {
            $options = json_decode($options, true);
        }
        if (!is_array($options)) {
            continue;
        }
        foreach ($options as $option) {
            $optVal = trim((string)(is_array($option) ? ($option['value'] ?? '') : $option));
            if ($optVal !== '' && $optVal === $value) {
                return true;
            }
        }
    }
    return false;
}

/** Custom size with separate Finish / Sticker Type and width/height fields. */
function printflow_pos_sticker_separated_custom_size_ready(array $customization): bool
{
    $width = printflow_pos_sticker_custom_spec_value($customization, ['dimensions_width', 'width', 'Width']);
    $height = printflow_pos_sticker_custom_spec_value($customization, ['dimensions_height', 'height', 'Height']);
    if ($width === '' || $height === '' || !is_numeric($width) || !is_numeric($height)
        || (float)$width <= 0 || (float)$height <= 0) {
        $dims = trim((string)($customization['dimensions'] ?? ''));
        if ($dims === '' || printflow_pos_dimension_pair_from_option($dims) === null) {
            return false;
        }
    }
    $finish = printflow_pos_sticker_custom_spec_value($customization, ['sticker_custom_finish', 'finish', 'Finish']);
    $process = printflow_pos_sticker_custom_spec_value($customization, ['sticker_custom_process', 'sticker_type', 'Sticker Type']);
    return printflow_pos_sticker_laminate_item_for_custom_finish($finish, $process) !== null;
}

/** True when staff chose Custom Size / Others or the POSTed dimensions sentinel matches. */
function printflow_pos_sticker_custom_size_active(array $customization, array $fieldRows): bool
{
    foreach ($fieldRows as $row) {
        if (!printflow_pos_sticker_is_preset_size_select_field($row)) continue;
        $key = trim((string)($row['field_key'] ?? ''));
        $label = trim((string)($row['field_label'] ?? ''));
        $selected = trim((string)($customization[$key] ?? $customization[$label] ?? ''));
        if ($selected === '') continue;
        if (function_exists('printflow_service_field_is_custom_size_option_value')
            && printflow_service_field_is_custom_size_option_value($selected)) {
            return true;
        }
        if (preg_match('/^custom(?:ize)?\s*size\b/i', $selected)) {
            return true;
        }
    }
    $dimensionsValue = printflow_pos_sticker_custom_spec_value($customization, [
        'dimensions', 'Dimensions', 'Dimensions And Finish',
    ]);
    if ($dimensionsValue !== ''
        && function_exists('printflow_service_field_is_custom_size_option_value')
        && printflow_service_field_is_custom_size_option_value($dimensionsValue)) {
        return true;
    }
    if (printflow_pos_sticker_separated_custom_size_ready($customization)
        && !printflow_pos_sticker_value_is_configured_preset($dimensionsValue, $fieldRows)) {
        return true;
    }
    return false;
}

/** Resolve the selected configured sticker option; incomplete form edits stay pending. */
function printflow_pos_sticker_laminate_selection(array $line, array $fieldRows): ?array
{
    $customization = is_array($line['customization'] ?? null) ? $line['customization'] : [];
    $customSizeSelected = printflow_pos_sticker_custom_size_active($customization, $fieldRows);
    $finishSelections = [];
    $dimensionSelections = [];
    if (!$customSizeSelected) {
        foreach ($fieldRows as $row) {
            if (!printflow_pos_sticker_is_preset_size_select_field($row)) continue;
            $key = trim((string)($row['field_key'] ?? ''));
            $label = trim((string)($row['field_label'] ?? ''));
            $selected = trim((string)($customization[$key] ?? $customization[$label] ?? ''));
            if ($selected === '') continue;
            $options = $row['_options'] ?? $row['field_options'] ?? [];
            if (is_string($options)) $options = json_decode($options, true);
            if (!is_array($options)) throw new RuntimeException('The configured Sticker/Decals options could not be verified.');
            if (function_exists('printflow_service_field_is_custom_size_option_value')
                && printflow_service_field_is_custom_size_option_value($selected)) {
                $customSizeSelected = true;
                break;
            }
            $optionFound = false;
            foreach ($options as $option) {
                $value = trim((string)(is_array($option) ? ($option['value'] ?? '') : $option));
                if ($value === $selected) { $optionFound = true; break; }
            }
            if (!$optionFound) {
                throw new RuntimeException('A selected Sticker/Decals option is no longer configured. Refresh the POS form.');
            }
            $mappedItemId = printflow_pos_sticker_laminate_item_for_finish($selected);
            if ($mappedItemId !== null) $finishSelections[] = ['row' => $row, 'value' => $selected, 'item_id' => $mappedItemId];
            $pair = printflow_pos_dimension_pair_from_option($selected);
            if ($pair !== null) {
                $unit = printflow_pos_dimension_option_unit($selected, $row);
                if ($unit === '') throw new RuntimeException('The selected Sticker/Decals size has no valid measurement unit.');
                $dimensionSelections[] = ['row' => $row, 'value' => $selected, 'pair' => $pair, 'unit' => $unit];
            }
        }
    }
    if ($customSizeSelected) {
        if ($finishSelections !== [] || $dimensionSelections !== []) {
            throw new RuntimeException('Choose either a listed Sticker/Decals size or a custom size, not both.');
        }
        $finishValue = printflow_pos_sticker_custom_spec_value($customization, ['sticker_custom_finish', 'finish', 'Finish']);
        $processValue = printflow_pos_sticker_custom_spec_value($customization, ['sticker_custom_process', 'sticker_type', 'Sticker Type']);
        $itemId = printflow_pos_sticker_laminate_item_for_custom_finish($finishValue, $processValue);
        $width = $customization['width'] ?? $customization['dimensions_width'] ?? $customization['Width'] ?? null;
        $height = $customization['height'] ?? $customization['dimensions_height'] ?? $customization['Height'] ?? null;
        $unit = strtolower(trim(printflow_pos_sticker_custom_spec_value($customization, [
            'pos_material_dimension_unit', 'dimensions_unit', 'unit', 'Unit',
        ])));
        if ($itemId === null || !is_numeric($width) || (float)$width <= 0 || !is_numeric($height) || (float)$height <= 0 || $unit === '') {
            return null;
        }
        try {
            $factor = printflow_tarpaulin_dimension_unit_to_ft_factor($unit);
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException('The custom Sticker/Decals size unit is not supported.');
        }
        $quantity = printflow_pos_sticker_laminate_line_quantity($line);
        return [
            'item_id' => $itemId,
            'finish' => trim($finishValue . ' ' . $processValue),
            'finish_value' => $finishValue,
            'process_value' => $processValue,
            'width_ft' => (float)$width * $factor,
            'height_ft' => (float)$height * $factor,
            'quantity' => $quantity,
            'custom_size' => true,
        ];
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
    $quantity = printflow_pos_sticker_laminate_line_quantity($line);
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

/** Match open positive-stock rolls by the verified inventory item's width in feet. */
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

/** Return open positive-stock rolls whose recorded width conflicts with the verified item width. */
function printflow_pos_sticker_laminate_roll_width_mismatches(array $rolls, float $configuredWidthFt): array
{
    if (!is_finite($configuredWidthFt) || $configuredWidthFt <= 0) return [];
    $mismatches = [];
    foreach ($rolls as $roll) {
        if (!is_array($roll)
            || (int)($roll['id'] ?? 0) <= 0
            || strtoupper(trim((string)($roll['status'] ?? ''))) !== 'OPEN'
            || !is_numeric($roll['remaining_length_ft'] ?? null)
            || (float)$roll['remaining_length_ft'] <= 0) continue;
        $width = $roll['width_ft'] ?? null;
        if (!is_numeric($width) || !is_finite((float)$width) || (float)$width <= 0) continue;
        if (abs((float)$width - $configuredWidthFt) > 0.01) {
            $mismatches[] = [
                'id' => (int)$roll['id'],
                'width_ft' => (float)$width,
                'remaining_length_ft' => (float)$roll['remaining_length_ft'],
            ];
        }
    }
    return $mismatches;
}

/** Explain a stock/width reconciliation without converting unverified roll data. */
function printflow_pos_sticker_laminate_reconciliation_message(string $name, array $reconciliation): string
{
    $format = static fn($value): string => rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
    $display = $format($reconciliation['display_balance'] ?? 0);
    $branchRolls = $format($reconciliation['branch_roll_balance'] ?? 0);
    $eligible = $format($reconciliation['eligible_roll_balance'] ?? 0);
    $message = $name . ' inventory needs reconciliation: Inventory Items shows '
        . $display . ' ft, but open rolls at this branch total ' . $branchRolls
        . ' ft and rolls matching the configured width total ' . $eligible . ' ft.';
    $mismatches = (array)($reconciliation['width_mismatches'] ?? []);
    if ($mismatches === []) return $message . ' Ask an Admin to review the branch roll records.';

    $configuredWidth = $format($reconciliation['configured_width_ft'] ?? 0);
    $rollDetails = array_map(static function (array $roll) use ($format): string {
        return 'roll #' . (int)($roll['id'] ?? 0) . ' is recorded as '
            . $format($roll['width_ft'] ?? 0) . ' ft wide with '
            . $format($roll['remaining_length_ft'] ?? 0) . ' ft remaining';
    }, $mismatches);
    return $message . ' The configured width is ' . $configuredWidth . ' ft; '
        . implode('; ', $rollDetails) . '. Verify the physical width and correct the roll record in Admin if needed.';
}

/** Load the server-validated laminate allocation persisted on a POS sticker order line. */
function printflow_pos_sticker_saved_laminate_selection_for_job(int $jobId, ?array $knownJob = null): ?array
{
    if (!function_exists('printflow_tarpaulin_authoritative_job_service_id')) {
        require_once __DIR__ . '/tarpaulin_material_calculation.php';
    }
    if (printflow_tarpaulin_authoritative_job_service_id($jobId, $knownJob) !== 36) return null;
    $job = $knownJob;
    if ($job === null || !isset($job['order_id'], $job['order_item_id'])) {
        $rows = db_query('SELECT id, order_id, order_item_id FROM job_orders WHERE id = ? LIMIT 2', 'i', [$jobId]);
        if (!is_array($rows) || count($rows) !== 1) return null;
        $job = $rows[0];
    }
    $orderId = (int)($job['order_id'] ?? 0);
    $itemId = (int)($job['order_item_id'] ?? 0);
    if ($orderId <= 0 || $itemId <= 0) return null;
    $rows = db_query('SELECT customization_data FROM order_items WHERE order_item_id = ? AND order_id = ? LIMIT 2', 'ii', [$itemId, $orderId]);
    if (!is_array($rows) || count($rows) !== 1) return null;
    $customization = json_decode((string)($rows[0]['customization_data'] ?? ''), true);
    $selection = is_array($customization) ? ($customization['pos_sticker_laminate_selection'] ?? null) : null;
    if (!is_array($selection) || ($selection['version'] ?? '') !== 'sticker_laminate_v1') return null;
    $allocations = array_values(array_filter((array)($selection['roll_allocations'] ?? []), 'is_array'));
    if ((int)($selection['item_id'] ?? 0) <= 0 || (int)($selection['branch_id'] ?? 0) <= 0 || $allocations === []) return null;
    $selection['roll_allocations'] = $allocations;
    return $selection;
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

