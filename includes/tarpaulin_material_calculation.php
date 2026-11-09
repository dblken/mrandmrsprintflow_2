<?php
/** Shared, versioned Tarpaulin roll-length calculations for POS and production. */

require_once __DIR__ . '/pos_quantity_validation.php';

const PRINTFLOW_TARPAULIN_CALCULATION_VERSION = 'linear_ft_v1';
const PRINTFLOW_TARPAULIN_PANEL_CALCULATION_VERSION = 'panel_joining_v2';
const PRINTFLOW_TARPAULIN_ROLL_FIT_LEGACY_VERSION = 'roll_fit_v1';
const PRINTFLOW_TARPAULIN_ROLL_FIT_VERSION = 'roll_fit_v2';

/** Resolve service identity through the persisted job -> order-item link only. */
function printflow_tarpaulin_authoritative_job_service_id(int $jobId, ?array $knownJob = null): int
{
    if ($jobId <= 0 || !function_exists('db_query')) return 0;
    if (!function_exists('db_table_has_column') || !db_table_has_column('job_orders', 'order_item_id')) return 0;
    $job = $knownJob;
    if ($job === null || !array_key_exists('order_id', $job) || !array_key_exists('order_item_id', $job)) {
        $rows = db_query('SELECT id, order_id, order_item_id FROM job_orders WHERE id = ? LIMIT 2', 'i', [$jobId]);
        if (!is_array($rows) || count($rows) !== 1) return 0;
        $job = $rows[0];
    }
    $orderId = (int)($job['order_id'] ?? 0);
    $orderItemId = (int)($job['order_item_id'] ?? 0);
    if ($orderId <= 0 || $orderItemId <= 0) return 0;

    $hasServiceColumn = function_exists('db_table_has_column') && db_table_has_column('order_items', 'service_id');
    $serviceColumn = $hasServiceColumn ? 'service_id,' : '';
    $items = db_query(
        "SELECT order_item_id, order_id, {$serviceColumn} customization_data FROM order_items WHERE order_item_id = ? AND order_id = ? LIMIT 2",
        'ii',
        [$orderItemId, $orderId]
    );
    if (!is_array($items) || count($items) !== 1) return 0;
    $item = $items[0];
    $savedIds = [];
    if ($hasServiceColumn && (int)($item['service_id'] ?? 0) > 0) $savedIds[] = (int)$item['service_id'];
    $customization = json_decode((string)($item['customization_data'] ?? ''), true);
    if (is_array($customization) && (int)($customization['service_id'] ?? 0) > 0) $savedIds[] = (int)$customization['service_id'];
    $savedIds = array_values(array_unique($savedIds));
    if (count($savedIds) !== 1) return 0;
    $serviceId = $savedIds[0];
    $service = db_query(
        "SELECT service_id FROM services WHERE service_id = ? AND LOWER(TRIM(COALESCE(status,''))) <> 'archived' LIMIT 2",
        'i',
        [$serviceId]
    );
    return is_array($service) && count($service) === 1 ? $serviceId : 0;
}

function printflow_tarpaulin_job_is_authoritative_service(int $jobId, ?array $knownJob = null): bool
{
    return printflow_tarpaulin_authoritative_job_service_id($jobId, $knownJob) === 40;
}

/** Load the server-validated POS selection through the unique job/order-item link. */
function printflow_tarpaulin_saved_panel_selection_for_job(int $jobId, ?array $knownJob = null): ?array
{
    if (!printflow_tarpaulin_job_is_authoritative_service($jobId, $knownJob)) return null;
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
    $selection = is_array($customization) ? ($customization['pos_tarpaulin_selection'] ?? null) : null;
    return is_array($selection) && ($selection['version'] ?? '') === PRINTFLOW_TARPAULIN_PANEL_CALCULATION_VERSION ? $selection : null;
}

/** Load the server-validated, width-fit roll allocation persisted on a POS order line. */
function printflow_tarpaulin_saved_roll_fit_selection_for_job(int $jobId, ?array $knownJob = null): ?array
{
    if (!printflow_tarpaulin_job_is_authoritative_service($jobId, $knownJob)) return null;
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
    $selection = is_array($customization) ? ($customization['pos_tarpaulin_selection'] ?? null) : null;
    return is_array($selection) && ($selection['version'] ?? '') === PRINTFLOW_TARPAULIN_ROLL_FIT_VERSION ? $selection : null;
}

/** Validate the one recognized calculation marker against persisted service identity. */
function printflow_tarpaulin_assert_versioned_job(array $metadata, int $jobId, ?array $knownJob = null): bool
{
    if (!printflow_tarpaulin_is_versioned_metadata($metadata)) return false;
    if (!printflow_tarpaulin_job_is_authoritative_service($jobId, $knownJob)) {
        throw new RuntimeException('The linear_ft_v1 calculation is valid only for a uniquely linked Tarpaulin service job.');
    }
    return true;
}

function printflow_tarpaulin_is_versioned_metadata(array $metadata): bool
{
    return in_array((string)($metadata['tarpaulin_calculation_version'] ?? ''), [PRINTFLOW_TARPAULIN_CALCULATION_VERSION, PRINTFLOW_TARPAULIN_PANEL_CALCULATION_VERSION], true);
}

/** Pure one-to-one pairing used to bind checkout payloads to session cart lines. */
function printflow_tarpaulin_pair_cart_lines(array $items, array $sessionCart, int $branchId): array
{
    $sessionById = [];
    foreach ($sessionCart as $line) {
        if (!is_array($line) || (int)($line['product_id'] ?? 0) !== 40 || empty($line['is_service'])) continue;
        $id = trim((string)($line['pos_cart_line_id'] ?? ''));
        if ($id === '' || isset($sessionById[$id])) return ['ok' => false, 'error' => 'The POS cart contains a missing or duplicate Tarpaulin line identifier.'];
        $sessionById[$id] = $line;
    }
    $seen = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) return ['ok' => false, 'error' => 'The checkout item list is invalid.'];
        $customization = is_array($item['customization'] ?? null) ? $item['customization'] : [];
        $isTarpaulin = (!empty($item['is_service']) || !empty($customization['service_id']))
            && ((int)($item['id'] ?? $item['product_id'] ?? 0) === 40 || (int)($customization['service_id'] ?? 0) === 40);
        if (!$isTarpaulin) continue;
        $id = trim((string)($item['pos_cart_line_id'] ?? ''));
        if ($id === '' || isset($seen[$id]) || !isset($sessionById[$id])) return ['ok' => false, 'error' => 'Each Tarpaulin checkout item must match one unique POS cart line.'];
        $seen[$id] = true;
        $line = $sessionById[$id];
        if ((int)($item['id'] ?? $item['product_id'] ?? 0) !== (int)($line['product_id'] ?? 0)
            || empty($item['is_service'])) {
            return ['ok' => false, 'error' => 'The Tarpaulin checkout item no longer matches its server cart service.'];
        }
        $lineCustomization = is_array($line['customization'] ?? null) ? $line['customization'] : [];
        if ((int)($line['qty'] ?? 0) !== (int)($item['qty'] ?? 0)
            || ((int)($customization['service_id'] ?? 40) !== 40)
            || abs((float)($lineCustomization['width'] ?? 0) - (float)($customization['width'] ?? 0)) >= 0.000001
            || abs((float)($lineCustomization['height'] ?? 0) - (float)($customization['height'] ?? 0)) >= 0.000001) {
            return ['ok' => false, 'error' => 'Tarpaulin cart quantity or dimensions changed after stock validation.'];
        }
        foreach (['joining_direction', 'joining_allowance_mode'] as $key) {
            $sessionValue = strtolower(trim((string)($lineCustomization[$key] ?? 'automatic')));
            $checkoutValue = strtolower(trim((string)($customization[$key] ?? 'automatic')));
            if ($sessionValue !== $checkoutValue) return ['ok' => false, 'error' => 'Tarpaulin joining options changed after stock validation.'];
        }
        if (abs((float)($lineCustomization['joining_allowance_ft'] ?? 0) - (float)($customization['joining_allowance_ft'] ?? 0)) >= 0.000001) {
            return ['ok' => false, 'error' => 'Tarpaulin allowance changed after stock validation.'];
        }
        $selection = $lineCustomization['pos_tarpaulin_selection'] ?? null;
        if (!is_array($selection) || (int)($selection['branch_id'] ?? 0) !== $branchId) {
            return ['ok' => false, 'error' => 'The server-validated Tarpaulin material selection is missing for the selected branch.'];
        }
        $customization['pos_tarpaulin_selection'] = $selection;
        $items[$index]['customization'] = $customization;
    }
    if (count($seen) !== count($sessionById)) return ['ok' => false, 'error' => 'The checkout payload is missing a Tarpaulin cart line.'];
    return ['ok' => true, 'items' => $items];
}

function printflow_tarpaulin_positive_number($value, string $label): float
{
    if (!is_numeric($value) || !is_finite((float)$value) || (float)$value <= 0) {
        throw new InvalidArgumentException($label . ' must be a positive number of feet.');
    }
    return (float)$value;
}

function printflow_tarpaulin_dimension($value, string $label): float
{
    $number = printflow_tarpaulin_positive_number($value, $label);
    if ($number > 100.0) throw new InvalidArgumentException($label . ' must not exceed 100 feet.');
    return $number;
}

/** Convert a supported customer dimension unit into a feet multiplier. */
function printflow_tarpaulin_dimension_unit_to_ft_factor($unit): float
{
    $normalized = strtolower(trim((string)$unit));
    return match ($normalized) {
        '', 'ft', 'feet', 'foot' => 1.0,
        'in', 'inch', 'inches', '"' => 1.0 / 12.0,
        'cm' => 1.0 / 30.48,
        'mm' => 1.0 / 304.8,
        default => throw new InvalidArgumentException('Unsupported Tarpaulin dimension unit. Use ft, in, cm, or mm.'),
    };
}

/** Canonical server-side parser for separate or saved WxH Tarpaulin dimensions. */
function printflow_tarpaulin_normalize_dimensions(array $customization): array
{
    $width = $customization['width'] ?? $customization['width_ft'] ?? $customization['Width'] ?? null;
    $height = $customization['height'] ?? $customization['height_ft'] ?? $customization['Height'] ?? null;
    $unit = $customization['pos_material_dimension_unit']
        ?? $customization['dimensions_unit']
        ?? $customization['unit']
        ?? $customization['Dimensions Unit']
        ?? 'ft';
    if (($width === null || $height === null) && isset($customization['dimensions'])) {
        $dimensions = trim((string)$customization['dimensions']);
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*[x×*]\s*(\d+(?:\.\d+)?)\s*(?:ft|feet|foot|in|inch|inches|cm|mm)?\s*$/i', $dimensions, $match)) {
            $width ??= $match[1];
            $height ??= $match[2];
            if (preg_match('/(ft|feet|foot|in|inch|inches|cm|mm)\s*$/i', $dimensions, $unitMatch)) {
                $unit = $unitMatch[1];
            }
        }
    }
    $factor = printflow_tarpaulin_dimension_unit_to_ft_factor($unit);
    $widthFt = is_numeric($width) ? ((float)$width * $factor) : $width;
    $heightFt = is_numeric($height) ? ((float)$height * $factor) : $height;
    return [
        printflow_tarpaulin_dimension($widthFt, 'Width'),
        printflow_tarpaulin_dimension($heightFt, 'Height'),
    ];
}

/** Joined widths are reserved for explicit custom-size choices in the POS. */
function printflow_tarpaulin_custom_size_selected(array $customization): bool
{
    $choice = strtolower(trim((string)($customization['dimensions'] ?? '')));
    return in_array($choice, ['others', 'other', 'custom size', 'custom'], true);
}

function printflow_tarpaulin_parse_nominal_width(array $item): float
{
    if (empty($item['track_by_roll'])) return 0.0;
    $name = trim((string)($item['name'] ?? ''));
    $category = strtoupper(trim((string)($item['category_name'] ?? '')));
    if (preg_match('/\bINK\b/i', $name) || str_starts_with($category, 'INK')) return 0.0;
    $uom = strtolower(trim((string)($item['unit_of_measure'] ?? 'ft')));
    if (!in_array($uom, ['ft', 'feet', 'foot'], true)) return 0.0;
    if (!preg_match('/\b(?:TARPAULIN|TARP)\b/i', $name) && $category !== 'TARPAULIN') return 0.0;
    if (isset($item['material_width']) && trim((string)$item['material_width']) !== '') {
        require_once __DIR__ . '/roll_width.php';
        try {
            return printflow_roll_width_to_feet($item['material_width'], (string)($item['material_dimension_unit'] ?? ''));
        } catch (InvalidArgumentException $error) {
            throw new RuntimeException('Tarpaulin roll widths need reconciliation. Item #' . (int)($item['id'] ?? 0) . ' has invalid configured roll width or unit. Ask an Admin to review its width configuration.');
        }
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*FT\b/i', $name, $match) !== 1) return 0.0;
    $width = (float)$match[1];
    return is_finite($width) && $width > 0 ? $width : 0.0;
}

/**
 * Pick the orientation with the least linear roll length. Round upward to
 * millifeet so inventory validation can never understate demand.
 */
function printflow_tarpaulin_calculate_linear_length($width, $height, $finishedQuantity, $rollWidth): array
{
    $w = printflow_tarpaulin_positive_number($width, 'Width');
    $h = printflow_tarpaulin_positive_number($height, 'Height');
    $q = (int)printflow_pos_parse_positive_quantity($finishedQuantity);
    $r = printflow_tarpaulin_positive_number($rollWidth, 'Roll width');
    $orientations = [];
    if ($w <= $r) $orientations[] = ['orientation' => 'original', 'run_ft' => $h];
    if ($h <= $r) $orientations[] = ['orientation' => 'rotated', 'run_ft' => $w];
    if ($orientations === []) {
        throw new InvalidArgumentException('Neither Tarpaulin dimension fits the selected roll width.');
    }
    usort($orientations, static fn(array $a, array $b): int => $a['run_ft'] <=> $b['run_ft']);
    $chosen = $orientations[0]; // ties prefer original orientation
    $rawLength = (float)$chosen['run_ft'] * $q;
    $requiredLength = ceil(($rawLength - 1.0e-10) * 1000) / 1000;
    return [
        'version' => PRINTFLOW_TARPAULIN_CALCULATION_VERSION,
        'width_ft' => $w,
        'height_ft' => $h,
        'finished_quantity' => $q,
        'print_area_sqft' => $w * $h * $q,
        'roll_width_ft' => $r,
        'orientation' => $chosen['orientation'],
        'run_length_per_piece_ft' => (float)$chosen['run_ft'],
        'required_linear_ft' => $requiredLength,
    ];
}

/**
 * Calculate Tarpaulin demand while keeping the selected order width as the
 * minimum usable roll width. Rotation is allowed only when the same roll can
 * cover both physical dimensions; a narrower roll is never selected by
 * rotating the job onto its shorter side.
 */
function printflow_tarpaulin_width_fit_plan($width, $height, $finishedQuantity, $rollWidth): array
{
    $w = printflow_tarpaulin_dimension($width, 'Width');
    $h = printflow_tarpaulin_dimension($height, 'Height');
    $q = (int)printflow_pos_parse_positive_quantity($finishedQuantity);
    $r = printflow_tarpaulin_positive_number($rollWidth, 'Roll width');
    if ($r + 1.0e-9 < $w) throw new InvalidArgumentException('The Tarpaulin roll is narrower than the selected order width.');
    $orientation = 'original';
    $run = $h;
    if ($r + 1.0e-9 >= $h && $w < $h) {
        $orientation = 'rotated';
        $run = $w;
    }
    $required = ceil(($run * $q - 1.0e-10) * 1000) / 1000;
    return [
        'version' => PRINTFLOW_TARPAULIN_ROLL_FIT_VERSION,
        'width_ft' => $w,
        'height_ft' => $h,
        'finished_quantity' => $q,
        'roll_width_ft' => $r,
        'orientation' => $orientation,
        'run_length_per_piece_ft' => $run,
        'required_linear_ft' => $required,
        'print_area_sqft' => $w * $h * $q,
        'material_area_sqft' => $r * $required,
    ];
}

/** Calculate positive demand before inspecting physical stock or allocations. */
function printflow_tarpaulin_unallocated_demand_plan($width, $height, $quantity, array $candidates, bool $allowJoinedWidths = false): array
{
    $w = printflow_tarpaulin_dimension($width, 'Width');
    $h = printflow_tarpaulin_dimension($height, 'Height');
    $q = (int)printflow_pos_parse_positive_quantity($quantity);
    $branchId = (int)($candidates[0]['branch_id'] ?? 0);
    $plans = [];
    $widths = [];
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)
            || strtoupper(trim((string)($candidate['status'] ?? ''))) !== 'ACTIVE'
            || ($branchId > 0 && (int)($candidate['branch_id'] ?? 0) !== $branchId)) continue;
        $rollWidth = printflow_tarpaulin_parse_nominal_width($candidate);
        if ($rollWidth > 0) $widths[] = $rollWidth;
    }
    if (!$allowJoinedWidths) {
        foreach (array_unique($widths) as $rollWidth) {
            if ($rollWidth + 1.0e-9 < $w) continue;
            try {
                $plan = printflow_tarpaulin_width_fit_plan($w, $h, $q, $rollWidth);
                $plans[] = ['plan' => $plan, 'preference' => [(float)$plan['material_area_sqft'], $rollWidth]];
            } catch (InvalidArgumentException $error) {
                continue;
            }
        }
    } else {
        $widths = array_values(array_unique($widths));
        sort($widths, SORT_NUMERIC);
        foreach ([['across' => $w, 'run' => $h], ['across' => $h, 'run' => $w]] as $orientation) {
            if ($widths === []) break;
            $minimumWidth = min($widths);
            $limit = min(500, (int)ceil($orientation['across'] / $minimumWidth) + 1);
            $pick = static function (int $start, float $covered, float $sum, int $count) use (&$pick, &$plans, $widths, $orientation, $q, $limit): void {
                if ($covered + 1.0e-9 >= $orientation['across']) {
                    $linear = $count * $orientation['run'] * $q;
                    $plans[] = ['plan' => ['roll_width_ft' => $sum, 'orientation' => 'combined',
                        'run_length_per_piece_ft' => $orientation['run'], 'required_linear_ft' => $linear,
                        'material_area_sqft' => $sum * $orientation['run'] * $q],
                        'preference' => [$sum * $orientation['run'] * $q, $count]];
                    return;
                }
                if ($count >= $limit) return;
                for ($i = $start; $i < count($widths); $i++) $pick($i, $covered + $widths[$i], $sum + $widths[$i], $count + 1);
            };
            $pick(0, 0.0, 0.0, 0);
        }
    }
    if ($plans !== []) {
        usort($plans, static fn(array $a, array $b): int => ($a['preference'][0] <=> $b['preference'][0])
            ?: ($a['preference'][1] <=> $b['preference'][1]));
        $plan = $plans[0]['plan'];
        $plan['demand_basis'] = 'configured_roll_width';
        return $plan;
    }

    // With no usable roll configuration, retain a positive geometric demand
    // for diagnostics but mark it as a minimum-fit estimate. It must never be
    // presented as proof that a material configuration or allocation exists.
    $fallbackWidth = max($w, $h);
    $plan = printflow_tarpaulin_width_fit_plan($w, $h, $q, $fallbackWidth);
    $plan['demand_basis'] = 'minimum_dimension_fit';
    $plan['configuration_available'] = false;
    return $plan;
}

/**
 * Resolve one full-width roll type and allocate each finished piece to a
 * specific physical roll. Every candidate roll must be an active, matching
 * width roll supplied by the branch-scoped inventory loader.
 */
function printflow_tarpaulin_resolve_width_fit(array $candidates, $width, $height, $quantity, array $usedByItemDemand = [], array $usedByItemRoll = [], bool $allowJoinedWidths = false): array
{
    $w = printflow_tarpaulin_dimension($width, 'Width');
    $h = printflow_tarpaulin_dimension($height, 'Height');
    $q = (int)printflow_pos_parse_positive_quantity($quantity);

    $requestedBranchId = (int)($candidates[0]['branch_id'] ?? 0);
    // Demand is dimension/configuration-derived and must not depend on whether
    // the stock query returns rolls or the allocator can find a feasible set.
    $demandPlan = printflow_tarpaulin_unallocated_demand_plan($w, $h, $q, $candidates, $allowJoinedWidths);
    if ($candidates === []) {
        return ['ok' => false, 'reason' => 'configuration', 'selection' => null, 'shortage' => [
            'name' => 'Tarpaulin', 'uom' => 'Ft', 'required_ft' => (float)$demandPlan['required_linear_ft'],
            'available_ft' => 0.0, 'calculation' => $demandPlan,
            'message' => 'No active Tarpaulin roll-width configuration is available for these dimensions. Calculated demand is '
                . rtrim(rtrim(number_format((float)$demandPlan['required_linear_ft'], 3, '.', ''), '0'), '.')
                . ' ft; available eligible stock: 0 ft.',
        ]];
    }
    // Only explicitly entered custom sizes may join configured widths. The
    // standard-size path below retains its existing single-width policy.
    if ($allowJoinedWidths) {
        return printflow_tarpaulin_resolve_roll_fit($candidates, $w, $h, $q, $usedByItemDemand, $usedByItemRoll);
    }

    $reconciliation = [];
    $recordedRolls = [];
    $usableMatchingFt = 0.0;
    foreach ($candidates as $candidate) {
        if (!is_array($candidate) || $requestedBranchId <= 0
            || (int)($candidate['branch_id'] ?? 0) !== $requestedBranchId
            || strtoupper(trim((string)($candidate['status'] ?? ''))) !== 'ACTIVE') continue;
        $itemId = (int)($candidate['id'] ?? 0);
        $expectedWidth = printflow_tarpaulin_parse_nominal_width($candidate);
        $matchingRolls = [];
        foreach ((array)($candidate['open_rolls'] ?? []) as $roll) {
            if (!is_array($roll) || strtoupper(trim((string)($roll['status'] ?? 'OPEN'))) !== 'OPEN'
                || (int)($roll['branch_id'] ?? 0) !== $requestedBranchId
                || (float)($roll['remaining_length_ft'] ?? 0) <= 0) continue;
            $rollId = (int)($roll['id'] ?? 0);
            if ($rollId > 0) $recordedRolls[$rollId] = max(0.0, (float)($roll['remaining_length_ft'] ?? 0));
            // The linked ACTIVE Tarpaulin item is the authoritative width
            // source. inv_rolls.width_ft is legacy receipt metadata and is
            // deliberately not consulted for Tarpaulin fit/allocation.
            if ($expectedWidth <= 0) continue;
            // A candidate width may be narrower than the print across
            // dimension; the layout enumerator can join it with other widths.
            // Validate dimensions independently, then let each full layout
            // determine whether one or more configured widths cover the piece.
            $matchingRolls[] = $roll;
        }
        foreach ((array)($candidate['roll_width_issues'] ?? []) as $issue) {
            if (!is_array($issue)) continue;
            $issue += ['item_id' => $itemId, 'item_name' => (string)($candidate['name'] ?? 'Tarpaulin')];
            $rollId = (int)($issue['roll_id'] ?? 0);
            $issueKey = $itemId . ':' . ($rollId > 0 ? $rollId : md5(json_encode($issue) ?: 'issue'));
            $reconciliation[$issueKey] = $issue;
            if ($rollId > 0 && !array_key_exists($rollId, $recordedRolls)) {
                $recordedRolls[$rollId] = max(0.0, (float)($issue['remaining_length_ft'] ?? 0));
            }
        }
        if ($expectedWidth <= 0) continue;
        // Report usable footage for the requested dimensions after accounting
        // for earlier lines in this cart, using the same branch-scoped FIFO
        // consumption convention as the allocator.
        $rollsForItem = $matchingRolls;
        $explicitByRoll = (array)($usedByItemRoll[$itemId] ?? []);
        foreach ($rollsForItem as &$roll) {
            $id = (int)($roll['id'] ?? 0);
            $roll['remaining_length_ft'] = max(0.0, (float)$roll['remaining_length_ft'] - max(0.0, (float)($explicitByRoll[$id] ?? 0)));
        }
        unset($roll);
        $explicitUsed = array_sum(array_map('floatval', $explicitByRoll));
        $legacyDemand = max(0.0, (float)($usedByItemDemand[$itemId] ?? 0) - $explicitUsed);
        foreach ($rollsForItem as &$roll) {
            $fifoUse = min((float)$roll['remaining_length_ft'], $legacyDemand);
            $roll['remaining_length_ft'] = max(0.0, (float)$roll['remaining_length_ft'] - $fifoUse);
            $legacyDemand -= $fifoUse;
        }
        unset($roll);
        $usableMatchingFt += array_sum(array_map(static fn(array $roll): float => max(0.0, (float)$roll['remaining_length_ft']), $rollsForItem));
    }
    if ($reconciliation !== []) {
        $labels = array_map(static function (array $issue): string {
            $id = (int)($issue['roll_id'] ?? 0);
            $widthValue = $issue['width_ft'] ?? 'missing';
            $width = is_numeric($widthValue) ? rtrim(rtrim(number_format((float)$widthValue, 3, '.', ''), '0'), '.') : (string)$widthValue;
            $item = trim((string)($issue['item_name'] ?? 'Tarpaulin roll'));
            $remaining = max(0.0, (float)($issue['remaining_length_ft'] ?? 0));
            $expected = max(0.0, (float)($issue['expected_width_ft'] ?? 0));
            return $item . ' roll #' . $id . ' (recorded width ' . $width . ' ft; expected ' . $expected
                . ' ft; ' . rtrim(rtrim(number_format($remaining, 3, '.', ''), '0'), '.') . ' ft remaining)';
        }, array_values($reconciliation));
        $recordedFt = array_sum($recordedRolls);
        $formatFt = static fn(float $value): string => rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
        return ['ok' => false, 'reason' => 'reconciliation', 'selection' => null, 'shortage' => [
            'message' => 'Tarpaulin roll widths need reconciliation. Recorded open-roll footage: ' . $formatFt($recordedFt)
                . ' ft; usable matching footage for this order: ' . $formatFt($usableMatchingFt) . ' ft. Affected rolls: '
                . implode(', ', $labels) . '. Ask an Admin to verify the physical roll widths.',
            'roll_width_issues' => array_values($reconciliation),
            'required_ft' => (float)$demandPlan['required_linear_ft'],
            'recorded_open_ft' => $recordedFt,
            'usable_matching_ft' => $usableMatchingFt,
            'available_ft' => $usableMatchingFt,
            'calculation' => $demandPlan,
        ]];
    }

    $eligible = [];
    foreach ($candidates as $candidate) {
        if (!is_array($candidate) || (int)($candidate['branch_id'] ?? 0) !== $requestedBranchId
            || strtoupper(trim((string)($candidate['status'] ?? ''))) !== 'ACTIVE') continue;
        $itemId = (int)($candidate['id'] ?? 0);
        $rollWidth = printflow_tarpaulin_parse_nominal_width($candidate);
        if ($itemId <= 0 || $rollWidth + 1.0e-9 < $w) continue;
        try { $plan = printflow_tarpaulin_width_fit_plan($w, $h, $q, $rollWidth); }
        catch (InvalidArgumentException $error) { continue; }

        $rolls = array_values(array_filter((array)($candidate['open_rolls'] ?? []), static fn(array $roll): bool =>
            strtoupper(trim((string)($roll['status'] ?? 'OPEN'))) === 'OPEN'
            && (int)($roll['branch_id'] ?? 0) === $requestedBranchId
            && (float)($roll['remaining_length_ft'] ?? 0) > 0));
        foreach ($rolls as &$roll) {
            $id = (int)($roll['id'] ?? 0);
            $remaining = (float)($roll['remaining_length_ft'] ?? 0);
            $explicit = max(0.0, (float)(($usedByItemRoll[$itemId] ?? [])[$id] ?? 0));
            $roll['remaining_length_ft'] = max(0.0, $remaining - $explicit);
        }
        unset($roll);
        $explicitUsed = array_sum(array_map('floatval', (array)($usedByItemRoll[$itemId] ?? [])));
        $legacyDemand = max(0.0, (float)($usedByItemDemand[$itemId] ?? 0) - $explicitUsed);
        foreach ($rolls as &$roll) {
            $fifoUse = min((float)$roll['remaining_length_ft'], $legacyDemand);
            $roll['remaining_length_ft'] = max(0.0, (float)$roll['remaining_length_ft'] - $fifoUse);
            $legacyDemand -= $fifoUse;
        }
        unset($roll);

        $allocation = printflow_tarpaulin_allocate_whole_panels($rolls, [
            'panel_count_per_piece' => 1,
            'finished_quantity' => $q,
            'run_length_per_panel_ft' => ceil(((float)$plan['run_length_per_piece_ft'] - 1.0e-10) * 1000) / 1000,
        ]);
        $available = array_sum(array_map(static fn(array $roll): float => max(0.0, (float)($roll['remaining_length_ft'] ?? 0)), $rolls));
        $eligible[] = [
            'item' => $candidate,
            'item_id' => $itemId,
            'roll_width_ft' => $rollWidth,
            'plan' => $plan,
            'allocation' => $allocation,
            'available_ft' => $available,
            'preference' => [(float)$plan['material_area_sqft'], $rollWidth, $itemId],
        ];
    }
    if ($eligible === []) {
        $requiredFt = (float)$demandPlan['required_linear_ft'];
        return ['ok' => false, 'reason' => 'configuration', 'selection' => null, 'shortage' => [
            'name' => 'Tarpaulin', 'uom' => 'Ft', 'required_ft' => $requiredFt, 'available_ft' => 0.0,
            'calculation' => $demandPlan,
            'message' => 'No active configured Tarpaulin roll width can cover the selected dimensions. Calculated demand is at least '
                . rtrim(rtrim(number_format($requiredFt, 3, '.', ''), '0'), '.')
                . ' ft; available eligible stock: 0 ft. Review the Tarpaulin roll configuration.',
        ]];
    }

    usort($eligible, static fn(array $a, array $b): int => ($a['preference'][0] <=> $b['preference'][0])
        ?: ($a['preference'][1] <=> $b['preference'][1]) ?: ($a['preference'][2] <=> $b['preference'][2]));
    foreach ($eligible as $entry) {
        if (empty($entry['allocation']['ok'])) continue;
        $itemId = (int)$entry['item_id'];
        $allocationRows = array_map(static function (array $row) use ($entry): array {
            $row['roll_width_ft'] = (float)$entry['roll_width_ft'];
            return $row;
        }, (array)($entry['allocation']['allocations'] ?? []));
        return ['ok' => true, 'reason' => '', 'selection' => array_merge($entry['plan'], [
            'item_id' => $itemId,
            'item_name' => (string)($entry['item']['name'] ?? 'Tarpaulin roll'),
            'branch_id' => (int)($entry['item']['branch_id'] ?? 0),
            'required_by_item' => [$itemId => (float)$entry['plan']['required_linear_ft']],
            'material_items' => [$itemId => ['name' => (string)($entry['item']['name'] ?? 'Tarpaulin roll'), 'roll_width_ft' => (float)$entry['roll_width_ft']]],
            'roll_allocations_by_item' => [$itemId => $allocationRows],
            'width_policy' => 'selected width is the minimum; equal or wider rolls only; pieces are assigned whole to listed physical rolls',
        ])];
    }

    $short = $eligible[0];
    $message = $short['available_ft'] <= 0
        ? (string)($short['item']['name'] ?? 'Tarpaulin') . ' is out of stock in rolls that meet the selected order width.'
        : 'Insufficient ' . (string)($short['item']['name'] ?? 'Tarpaulin') . ' stock: required '
            . rtrim(rtrim(number_format((float)$short['plan']['required_linear_ft'], 3, '.', ''), '0'), '.')
            . ' ft; available ' . rtrim(rtrim(number_format((float)$short['available_ft'], 3, '.', ''), '0'), '.')
            . ' ft. Each piece must fit continuously on one eligible roll.';
    return ['ok' => false, 'reason' => (string)($short['allocation']['reason'] ?? 'insufficient'), 'selection' => null,
        'shortage' => ['id' => (int)$short['item_id'], 'name' => (string)($short['item']['name'] ?? 'Tarpaulin'),
            'uom' => 'Ft', 'required_ft' => (float)$short['plan']['required_linear_ft'], 'available_ft' => (float)$short['available_ft'], 'message' => $message]];
}

/** Stable roll-width preference used by the production picker. */
function printflow_tarpaulin_preference_key(float $width, float $height, float $rollWidth): array
{
    $larger = max($width, $height);
    $smaller = min($width, $height);
    if ($rollWidth >= $larger) return [0, $rollWidth];
    if ($rollWidth >= $smaller) return [1, $rollWidth];
    return [2, $rollWidth];
}

/** Pure deterministic candidate resolver; $demand is already committed cart Ft. */
function printflow_tarpaulin_resolve_candidate(array $candidates, $width, $height, $finishedQuantity, array $demand = []): array
{
    $w = printflow_tarpaulin_positive_number($width, 'Width');
    $h = printflow_tarpaulin_positive_number($height, 'Height');
    $q = (int)printflow_pos_parse_positive_quantity($finishedQuantity);
    $fits = [];
    $compatible = [];
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) continue;
        $id = (int)($candidate['id'] ?? 0);
        $rollWidth = printflow_tarpaulin_parse_nominal_width($candidate);
        if ($id <= 0 || $rollWidth <= 0 || strtoupper((string)($candidate['status'] ?? '')) !== 'ACTIVE') continue;
        try {
            $calc = printflow_tarpaulin_calculate_linear_length($w, $h, $q, $rollWidth);
        } catch (InvalidArgumentException $error) {
            continue;
        }
        $preference = printflow_tarpaulin_preference_key($w, $h, $rollWidth);
        $entry = $candidate + [];
        $entry['calculation'] = $calc;
        $entry['preference'] = $preference;
        $entry['remaining_ft'] = (float)($candidate['available_ft'] ?? 0) - (float)($demand[$id] ?? 0);
        $compatible[] = $entry;
        if ($entry['remaining_ft'] + 1.0e-9 >= $calc['required_linear_ft']) $fits[] = $entry;
    }
    if ($compatible === []) {
        return ['ok' => false, 'reason' => 'incompatible', 'selection' => null, 'candidates' => []];
    }
    if ($fits === []) {
        usort($compatible, static function (array $a, array $b): int {
            return ($a['preference'][0] <=> $b['preference'][0]) ?: ($a['preference'][1] <=> $b['preference'][1]);
        });
        $shortage = $compatible[0];
        return ['ok' => false, 'reason' => 'insufficient', 'selection' => null, 'shortage' => [
            'id' => (int)$shortage['id'],
            'name' => (string)($shortage['name'] ?? 'Tarpaulin roll'),
            'uom' => 'Ft',
            'required_ft' => (float)$shortage['calculation']['required_linear_ft'],
            'available_ft' => max(0.0, (float)$shortage['remaining_ft']),
            'remaining_ft' => (float)$shortage['remaining_ft'],
            'calculation' => $shortage['calculation'],
        ], 'candidates' => $compatible];
    }
    usort($fits, static function (array $a, array $b): int {
        return ($a['preference'][0] <=> $b['preference'][0]) ?: ($a['preference'][1] <=> $b['preference'][1]);
    });
    $winner = $fits[0];
    $sameRank = array_values(array_filter($fits, static fn(array $entry): bool => $entry['preference'] === $winner['preference']));
    if (count($sameRank) !== 1) {
        return ['ok' => false, 'reason' => 'ambiguous', 'selection' => null, 'candidates' => $sameRank];
    }
    $selection = [
        'version' => PRINTFLOW_TARPAULIN_CALCULATION_VERSION,
        'item_id' => (int)$winner['id'],
        'item_name' => (string)$winner['name'],
        'branch_id' => (int)($winner['branch_id'] ?? 0),
        'width_ft' => $w,
        'height_ft' => $h,
        'finished_quantity' => $q,
        'roll_width_ft' => (float)$winner['calculation']['roll_width_ft'],
        'orientation' => (string)$winner['calculation']['orientation'],
        'run_length_per_piece_ft' => (float)$winner['calculation']['run_length_per_piece_ft'],
        'required_linear_ft' => (float)$winner['calculation']['required_linear_ft'],
    ];
    return ['ok' => true, 'reason' => '', 'selection' => $selection, 'candidates' => $fits];
}


/** Tarpaulin panel plan, v2. A single inventory item/nominal width is used for every panel. */
function printflow_tarpaulin_panel_plan($width, $height, $quantity, $rollWidth, string $direction = 'automatic', string $allowanceMode = 'included', $allowance = 0): array
{
    $w = printflow_tarpaulin_dimension($width, 'Width');
    $h = printflow_tarpaulin_dimension($height, 'Height');
    $q = printflow_pos_parse_positive_quantity($quantity);
    $r = printflow_tarpaulin_positive_number($rollWidth, 'Roll width');
    $direction = strtolower(trim($direction));
    $allowanceMode = strtolower(trim($allowanceMode));
    if (!in_array($direction, ['automatic', 'vertical', 'horizontal'], true)) throw new InvalidArgumentException('Choose Automatic, Vertical seams, or Horizontal seams.');
    if (!in_array($allowanceMode, ['included', 'additional'], true)) throw new InvalidArgumentException('Choose a valid joining allowance mode.');
    if (!is_numeric($allowance) || !is_finite((float)$allowance) || (float)$allowance < 0) throw new InvalidArgumentException('Additional allowance must be a finite nonnegative number of feet.');
    $allowance = $allowanceMode === 'additional' ? (float)$allowance : 0.0;
    $plans = [];
    $single = [];
    if ($w <= $r) $single[] = ['orientation' => 'original', 'run' => $h, 'across' => $w];
    if ($h <= $r) $single[] = ['orientation' => 'rotated', 'run' => $w, 'across' => $h];
    usort($single, static fn(array $a, array $b): int => ($a['run'] <=> $b['run']) ?: strcmp($a['orientation'], $b['orientation']));
    if ($single !== []) {
        $x = $single[0];
        $plans[] = printflow_tarpaulin_make_panel_plan($w, $h, $q, $r, 'single', $x['orientation'], 1, $x['run'], $x['across'], $allowance, $allowanceMode);
    }
    if (($direction === 'automatic' || $direction === 'vertical') && $w > $r) {
        $coverage = $w + $allowance;
        $count = (int)ceil($coverage / $r - 1.0e-12);
        $plans[] = printflow_tarpaulin_make_panel_plan($w, $h, $q, $r, 'vertical', 'original', $count, $h, $coverage, $allowance, $allowanceMode);
    }
    if (($direction === 'automatic' || $direction === 'horizontal') && $h > $r) {
        $coverage = $h + $allowance;
        $count = (int)ceil($coverage / $r - 1.0e-12);
        $plans[] = printflow_tarpaulin_make_panel_plan($w, $h, $q, $r, 'horizontal', 'rotated', $count, $w, $coverage, $allowance, $allowanceMode);
    }
    if ($direction === 'vertical') $plans = array_values(array_filter($plans, static fn(array $p): bool => in_array($p['direction'], ['single', 'vertical'], true)));
    if ($direction === 'horizontal') $plans = array_values(array_filter($plans, static fn(array $p): bool => in_array($p['direction'], ['single', 'horizontal'], true)));
    if ($plans === []) throw new InvalidArgumentException('The selected seam direction cannot fit the requested dimensions on this roll width.');
    usort($plans, static fn(array $a, array $b): int => ($a['required_linear_ft'] <=> $b['required_linear_ft'])
        ?: (($a['direction'] === 'single' ? 0 : 1) <=> ($b['direction'] === 'single' ? 0 : 1))
        ?: (($a['direction'] === 'vertical' ? 0 : 1) <=> ($b['direction'] === 'vertical' ? 0 : 1))
        ?: ((int)$a['panel_count_per_piece'] <=> (int)$b['panel_count_per_piece'])
        ?: strcmp((string)$a['direction'], (string)$b['direction']));
    return $plans[0];
}

function printflow_tarpaulin_make_panel_plan(float $w, float $h, int $q, float $r, string $direction, string $orientation, int $count, float $runLength, float $coverage, float $allowance, string $allowanceMode): array
{
    if ($count < 1 || $count > 10000) throw new InvalidArgumentException('The requested panel plan exceeds the supported safe range.');
    $run = ceil(($runLength - 1.0e-10) * 1000) / 1000;
    $required = ceil(($run * $count * $q - 1.0e-10) * 1000) / 1000;
    $lastWidth = $coverage - ($r * ($count - 1));
    return [
        'version' => PRINTFLOW_TARPAULIN_PANEL_CALCULATION_VERSION, 'width_ft' => $w, 'height_ft' => $h,
        'finished_quantity' => $q, 'roll_width_ft' => $r, 'orientation' => $orientation, 'direction' => $direction,
        'allowance_mode' => $allowanceMode, 'allowance_ft_per_piece' => $allowance,
        'applied_allowance_ft_per_piece' => $direction === 'single' ? 0.0 : $allowance,
        'coverage_target_ft' => $coverage,
        'panel_count_per_piece' => $count, 'panel_widths_ft' => $direction === 'single' ? [$coverage] : array_merge(array_fill(0, $count - 1, $r), [$lastWidth]),
        'run_length_per_panel_ft' => $run, 'required_linear_ft' => $required, 'print_area_sqft' => $w * $h * $q,
        'offcut_credit_ft' => 0.0, 'width_policy' => 'full roll-width panels; final panel may be narrower; no offcut credit',
    ];
}

/** Compare a client/assignment snapshot to the server-persisted panel plan. */
function printflow_tarpaulin_panel_plans_match(array $candidate, array $authoritative): bool
{
    foreach (['version', 'item_id', 'branch_id', 'finished_quantity', 'orientation', 'direction', 'requested_direction', 'allowance_mode', 'panel_count_per_piece'] as $key) {
        if ((string)($candidate[$key] ?? '') !== (string)($authoritative[$key] ?? '')) return false;
    }
    foreach (['width_ft', 'height_ft', 'roll_width_ft', 'allowance_ft_per_piece', 'applied_allowance_ft_per_piece', 'coverage_target_ft', 'run_length_per_panel_ft', 'required_linear_ft'] as $key) {
        if (!is_numeric($candidate[$key] ?? null) || !is_numeric($authoritative[$key] ?? null)
            || abs((float)$candidate[$key] - (float)$authoritative[$key]) > 0.001) return false;
    }
    $candidateWidths = $candidate['panel_widths_ft'] ?? null;
    $authoritativeWidths = $authoritative['panel_widths_ft'] ?? null;
    if (!is_array($candidateWidths) || !is_array($authoritativeWidths) || count($candidateWidths) !== count($authoritativeWidths)) return false;
    foreach ($candidateWidths as $index => $width) {
        if (!is_numeric($width) || !is_numeric($authoritativeWidths[$index] ?? null)
            || abs((float)$width - (float)$authoritativeWidths[$index]) > 0.001) return false;
    }
    return true;
}

/** Deterministic FIFO first-fit; every individual panel must fit continuously on one open roll. */
function printflow_tarpaulin_allocate_whole_panels(array $rolls, array $plan, array $usedByRoll = []): array
{
    $count = (int)($plan['panel_count_per_piece'] ?? 0) * (int)($plan['finished_quantity'] ?? 0);
    $length = (float)($plan['run_length_per_panel_ft'] ?? 0);
    if ($count < 1 || !is_finite($length) || $length <= 0) return ['ok' => false, 'reason' => 'invalid_plan', 'allocations' => []];
    foreach ($rolls as &$roll) {
        $id = (int)($roll['id'] ?? 0);
        $roll['remaining_length_ft'] = max(0.0, (float)($roll['remaining_length_ft'] ?? 0) - (float)($usedByRoll[$id] ?? 0));
    }
    unset($roll);
    $result = printflow_tarpaulin_allocate_cart_panels(
        ['single' => ['item_id' => 1, 'plan' => $plan]],
        [1 => $rolls]
    );
    return ['ok' => !empty($result['ok']), 'reason' => (string)($result['reason'] ?? ''),
        'allocations' => $result['allocations_by_line']['single'] ?? []];
}

/**
 * Allocate every whole panel in a cart against physical rolls. FIFO is the
 * deterministic preference, while bounded backtracking prevents an early
 * short panel from consuming the only roll that can fit a later long panel.
 * Each line plan must already be resolved to its authoritative inventory item.
 */
function printflow_tarpaulin_allocate_cart_panels(array $linePlans, array $rollsByItem, int $maxPanels = 500, int $maxNodes = 100000): array
{
    $tasksByItem = [];
    $requiredByItem = [];
    $panelTotal = 0;
    foreach ($linePlans as $lineKey => $entry) {
        if (!is_array($entry)) return ['ok' => false, 'reason' => 'invalid_plan', 'allocations_by_line' => []];
        $itemId = (int)($entry['item_id'] ?? 0);
        $plan = is_array($entry['plan'] ?? null) ? $entry['plan'] : $entry;
        $count = (int)($plan['panel_count_per_piece'] ?? 0) * (int)($plan['finished_quantity'] ?? 0);
        $length = (float)($plan['run_length_per_panel_ft'] ?? 0);
        if ($itemId <= 0 || $count < 1 || !is_finite($length) || $length <= 0) {
            return ['ok' => false, 'reason' => 'invalid_plan', 'allocations_by_line' => []];
        }
        $panelTotal += $count;
        if ($panelTotal > $maxPanels) return ['ok' => false, 'reason' => 'search_limit', 'allocations_by_line' => []];
        $requiredByItem[$itemId] = ($requiredByItem[$itemId] ?? 0.0) + $count * $length;
        for ($panel = 0; $panel < $count; $panel++) {
            $tasksByItem[$itemId][] = ['line_key' => (string)$lineKey, 'panel' => $panel, 'length_milli' => (int)ceil(($length - 1.0e-10) * 1000)];
        }
    }

    $allocationsByLine = [];
    $nodes = 0;
    $work = 0;
    $maxWork = 250000;
    foreach ($tasksByItem as $itemId => $tasks) {
        $rolls = array_values((array)($rollsByItem[$itemId] ?? []));
        usort($rolls, static fn(array $a, array $b): int => strcmp((string)($a['received_at'] ?? ''), (string)($b['received_at'] ?? '')) ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0)));
        $bins = [];
        foreach ($rolls as $roll) {
            $id = (int)($roll['id'] ?? 0);
            $length = (float)($roll['remaining_length_ft'] ?? 0);
            if ($id <= 0 || !is_finite($length) || $length <= 0) continue;
            $bins[] = ['id' => $id, 'code' => (string)($roll['roll_code'] ?? ('Roll #' . $id)), 'remaining_milli' => (int)floor(($length + 1.0e-10) * 1000)];
        }
        if (count($bins) > 5000) return ['ok' => false, 'reason' => 'search_limit', 'allocations_by_line' => [], 'nodes' => $nodes];
        // Largest panels first is a search-order heuristic only; completeness
        // comes from backtracking, not from assuming largest-first is sufficient.
        usort($tasks, static fn(array $a, array $b): int => ($b['length_milli'] <=> $a['length_milli'])
            ?: strcmp($a['line_key'], $b['line_key']) ?: ($a['panel'] <=> $b['panel']));
        $path = [];
        $failed = [];
        $search = function (int $index) use (&$search, &$bins, &$tasks, &$path, &$failed, &$nodes, &$work, $maxNodes, $maxWork): bool {
            if ($index >= count($tasks)) return true;
            if (++$nodes > $maxNodes || ($work += count($bins)) > $maxWork) return false;
            $capacities = array_map(static fn(array $bin): int => $bin['remaining_milli'], $bins);
            sort($capacities, SORT_NUMERIC);
            $stateKey = $index . ':' . implode(',', $capacities);
            if (isset($failed[$stateKey])) return false;
            $needed = $tasks[$index]['length_milli'];
            $seen = [];
            foreach ($bins as $binIndex => &$bin) {
                $remaining = $bin['remaining_milli'];
                if ($remaining < $needed || isset($seen[$remaining])) continue;
                $seen[$remaining] = true;
                $bin['remaining_milli'] -= $needed;
                $path[$index] = $binIndex;
                if ($search($index + 1)) { unset($bin); return true; }
                $bin['remaining_milli'] = $remaining;
                if ($nodes > $maxNodes || $work > $maxWork) { unset($bin); return false; }
            }
            unset($bin);
            $failed[$stateKey] = true;
            return false;
        };
        $ok = $search(0);
        if (!$ok) {
            if ($nodes > $maxNodes || $work > $maxWork) return ['ok' => false, 'reason' => 'search_limit', 'allocations_by_line' => [], 'nodes' => $nodes];
            return ['ok' => false, 'reason' => 'physical_roll_capacity', 'allocations_by_line' => [], 'required_by_item' => $requiredByItem, 'nodes' => $nodes];
        }
        foreach ($tasks as $index => $task) {
            $bin = $bins[$path[$index]];
            $lineKey = $task['line_key'];
            $rollId = $bin['id'];
            $allocationsByLine[$lineKey][$rollId] ??= ['roll_id' => $rollId, 'roll_code' => $bin['code'], 'panels' => 0, 'length_ft' => 0.0];
            $allocationsByLine[$lineKey][$rollId]['panels']++;
            $allocationsByLine[$lineKey][$rollId]['length_ft'] += $task['length_milli'] / 1000;
        }
    }
    foreach ($allocationsByLine as &$allocations) $allocations = array_values($allocations);
    unset($allocations);
    return ['ok' => true, 'reason' => '', 'allocations_by_line' => $allocationsByLine, 'required_by_item' => $requiredByItem, 'nodes' => $nodes];
}

function printflow_tarpaulin_resolve_panel_candidate(array $candidates, $width, $height, $quantity, string $direction, string $allowanceMode, $allowance, array $usedByItemRoll = []): array
{
    $compatible = [];
    $fits = [];
    $searchLimited = false;
    foreach ($candidates as $candidate) {
        if (!is_array($candidate) || strtoupper((string)($candidate['status'] ?? '')) !== 'ACTIVE') continue;
        $id = (int)($candidate['id'] ?? 0);
        $rollWidth = printflow_tarpaulin_parse_nominal_width($candidate);
        if ($id <= 0 || $rollWidth <= 0) continue;
        try { $plan = printflow_tarpaulin_panel_plan($width, $height, $quantity, $rollWidth, $direction, $allowanceMode, $allowance); }
        catch (InvalidArgumentException $error) { continue; }
        $allocation = printflow_tarpaulin_allocate_whole_panels((array)($candidate['open_rolls'] ?? []), $plan, (array)($usedByItemRoll[$id] ?? []));
        if (($allocation['reason'] ?? '') === 'search_limit') $searchLimited = true;
        $candidate['plan'] = $plan;
        $candidate['allocation'] = $allocation;
        $candidate['preference'] = printflow_tarpaulin_preference_key((float)$width, (float)$height, $rollWidth);
        $candidate['physically_available_ft'] = array_sum(array_map(static fn(array $r): float => max(0.0, (float)($r['remaining_length_ft'] ?? 0) - (float)(($usedByItemRoll[$id] ?? [])[(int)($r['id'] ?? 0)] ?? 0)), (array)($candidate['open_rolls'] ?? [])));
        $compatible[] = $candidate;
        if (!empty($allocation['ok'])) $fits[] = $candidate;
    }
    if ($compatible === []) return ['ok' => false, 'reason' => 'incompatible', 'selection' => null, 'candidates' => []];
    $sort = static fn(array $a, array $b): int => ((float)$a['plan']['required_linear_ft'] <=> (float)$b['plan']['required_linear_ft'])
        ?: (($a['plan']['direction'] === 'single' ? 0 : 1) <=> ($b['plan']['direction'] === 'single' ? 0 : 1))
        ?: ($a['preference'][0] <=> $b['preference'][0]) ?: ($a['preference'][1] <=> $b['preference'][1])
        ?: ((int)$a['id'] <=> (int)$b['id']);
    if ($fits === []) {
        if ($searchLimited) return ['ok' => false, 'reason' => 'search_limit', 'selection' => null, 'candidates' => $compatible];
        usort($compatible, $sort);
        $short = $compatible[0];
        return ['ok' => false, 'reason' => 'insufficient', 'selection' => null, 'shortage' => [
            'id' => (int)$short['id'], 'name' => (string)$short['name'], 'uom' => 'Ft',
            'required_ft' => (float)$short['plan']['required_linear_ft'], 'available_ft' => (float)$short['physically_available_ft'],
            'calculation' => $short['plan'], 'physical' => true,
        ], 'candidates' => $compatible];
    }
    usort($fits, $sort);
    $winner = $fits[0];
    return ['ok' => true, 'reason' => '', 'selection' => array_merge($winner['plan'], [
        'item_id' => (int)$winner['id'], 'item_name' => (string)$winner['name'], 'branch_id' => (int)($winner['branch_id'] ?? 0),
        'roll_allocations' => $winner['allocation']['allocations'],
    ]), 'candidates' => $fits];
}

/**
 * Resolve a no-allowance POS tarp layout across the actual active roll-width
 * items. Each strip is a continuous panel on one nominal width; strips may use
 * different widths, while each physical roll must hold every allocated panel.
 * The returned snapshot is an availability estimate only; production staff
 * still records actual materials/usage and completion revalidates stock.
 */
function printflow_tarpaulin_resolve_roll_fit(array $candidates, $width, $height, $quantity, array $usedByItemDemand = [], array $usedByItemRoll = []): array
{
    $w = printflow_tarpaulin_dimension($width, 'Width');
    $h = printflow_tarpaulin_dimension($height, 'Height');
    $q = printflow_pos_parse_positive_quantity($quantity);
    $usable = [];
    $requestedBranchId = (int)($candidates[0]['branch_id'] ?? 0);
    foreach ($candidates as $candidate) {
        if (!is_array($candidate) || strtoupper(trim((string)($candidate['status'] ?? ''))) !== 'ACTIVE'
            || $requestedBranchId <= 0 || (int)($candidate['branch_id'] ?? 0) !== $requestedBranchId) continue;
        $id = (int)($candidate['id'] ?? 0);
        $rollWidth = printflow_tarpaulin_parse_nominal_width($candidate);
        if ($id <= 0 || $rollWidth <= 0) continue;
        $rolls = array_values(array_filter((array)($candidate['open_rolls'] ?? []), static function (array $roll) use ($requestedBranchId): bool {
            return strtoupper((string)($roll['status'] ?? '')) === 'OPEN'
                && (int)($roll['branch_id'] ?? 0) === $requestedBranchId
                && (float)($roll['remaining_length_ft'] ?? 0) > 0;
        }));
        usort($rolls, static fn(array $a, array $b): int => strcmp((string)($a['received_at'] ?? ''), (string)($b['received_at'] ?? '')) ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0)));
        $explicitUsed = 0.0;
        foreach ((array)($usedByItemRoll[$id] ?? []) as $rollId => $used) $explicitUsed += max(0.0, (float)$used);
        $legacyDemand = max(0.0, (float)($usedByItemDemand[$id] ?? 0) - $explicitUsed);
        foreach ($rolls as &$roll) {
            $rollId = (int)($roll['id'] ?? 0);
            $remaining = max(0.0, (float)($roll['remaining_length_ft'] ?? 0) - max(0.0, (float)(($usedByItemRoll[$id] ?? [])[$rollId] ?? 0)));
            $fifoUse = min($remaining, $legacyDemand);
            $legacyDemand -= $fifoUse;
            $roll['remaining_length_ft'] = max(0.0, $remaining - $fifoUse);
        }
        unset($roll);
        $candidate['roll_width_ft'] = $rollWidth;
        foreach ($rolls as &$roll) $roll['allocation_width_ft'] = $rollWidth;
        unset($roll);
        $candidate['open_rolls'] = $rolls;
        $usable[$id] = $candidate;
    }
    if ($usable === []) return ['ok' => false, 'reason' => 'incompatible', 'selection' => null];

    $layouts = [];
    $searchNodes = 0;
    foreach ([['across' => $w, 'run' => $h, 'orientation' => 'original'], ['across' => $h, 'run' => $w, 'orientation' => 'rotated']] as $orientation) {
        $across = (float)$orientation['across'];
        $run = (float)$orientation['run'];
        $availableCandidates = array_values($usable);
        usort($availableCandidates, static fn(array $a, array $b): int => ((float)$a['roll_width_ft'] <=> (float)$b['roll_width_ft']) ?: ((int)$a['id'] <=> (int)$b['id']));
        if ($availableCandidates === []) continue;
        $minWidth = min(array_map(static fn(array $row): float => (float)$row['roll_width_ft'], $availableCandidates));
        $maxPanels = min(500, (int)ceil($across / $minWidth) + 1);
        $enumerate = function (int $start, array $chosen, float $covered) use (&$enumerate, &$layouts, &$searchNodes, $availableCandidates, $across, $run, $orientation, $q, $maxPanels): void {
            if (++$searchNodes > 50000 || count($layouts) > 12000) return;
            if ($covered + 1.0e-9 >= $across) {
                $counts = [];
                $rollWidths = [];
                foreach ($chosen as $row) {
                    $id = (int)$row['id'];
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                    $rollWidths[$id] = (float)$row['roll_width_ft'];
                }
                ksort($counts, SORT_NUMERIC);
                $layouts[] = ['orientation' => $orientation['orientation'], 'across_ft' => $across, 'run_ft' => $run,
                    'counts' => $counts, 'roll_widths' => $rollWidths, 'panel_count' => count($chosen),
                    'trim_waste_ft' => max(0.0, $covered - $across),
                    'required_linear_ft' => ceil((count($chosen) * $run * $q - 1.0e-10) * 1000) / 1000,
                    'quantity' => $q];
                return;
            }
            if (count($chosen) >= $maxPanels) return;
            foreach ($availableCandidates as $index => $row) {
                if ($index < $start) continue;
                $next = $chosen;
                $next[] = $row;
                $enumerate($index, $next, $covered + (float)$row['roll_width_ft']);
                if ($searchNodes > 50000 || count($layouts) > 12000) return;
            }
        };
        $enumerate(0, [], 0.0);
    }
    if ($layouts === []) return ['ok' => false, 'reason' => 'incompatible', 'selection' => null];
    if ($searchNodes > 50000 || count($layouts) > 12000) return ['ok' => false, 'reason' => 'search_limit', 'selection' => null];

    $fits = [];
    $failedLayouts = [];
    $sawSearchLimit = false;
    foreach ($layouts as $layout) {
        $linePlans = [];
        $rollsByItem = [];
        $requiredByItem = [];
        foreach ($layout['counts'] as $itemId => $panelsPerPiece) {
            $itemId = (int)$itemId;
            $candidate = $usable[$itemId];
            $linePlans[(string)$itemId] = ['item_id' => $itemId, 'plan' => [
                'panel_count_per_piece' => (int)$panelsPerPiece, 'finished_quantity' => $q,
                'run_length_per_panel_ft' => ceil(($layout['run_ft'] - 1.0e-10) * 1000) / 1000,
            ]];
            $rollsByItem[$itemId] = (array)$candidate['open_rolls'];
            $requiredByItem[$itemId] = ceil(((int)$panelsPerPiece * $q * $layout['run_ft'] - 1.0e-10) * 1000) / 1000;
        }
        $allocation = printflow_tarpaulin_allocate_cart_panels($linePlans, $rollsByItem);
        if (($allocation['reason'] ?? '') === 'search_limit') { $sawSearchLimit = true; continue; }
        if (empty($allocation['ok'])) {
            $layout['required_by_item'] = $requiredByItem;
            $layout['allocation_reason'] = (string)($allocation['reason'] ?? 'physical_roll_capacity');
            $failedLayouts[] = $layout;
            continue;
        }
        $allocationsByItem = [];
        foreach ($allocation['allocations_by_line'] ?? [] as $itemId => $items) {
            $itemId = (int)$itemId;
            $configuredWidth = (float)$usable[$itemId]['roll_width_ft'];
            foreach ($items as &$allocationRow) {
                $allocationRow['roll_width_ft'] = $configuredWidth;
                $allocationRow['item_id'] = $itemId;
            }
            unset($allocationRow);
            $allocationsByItem[$itemId] = $items;
        }
        $fits[] = $layout + ['required_by_item' => $requiredByItem, 'allocations_by_item' => $allocationsByItem,
            'material_items' => array_map(static fn(int $id): array => ['name' => (string)$usable[$id]['name'], 'roll_width_ft' => (float)$usable[$id]['roll_width_ft']], array_map('intval', array_keys($layout['counts'])))];
    }
    // Prefer the least purchased material area (including full-width trim
    // waste), then fewer panels and narrower stock. This makes 3x6 prefer the
    // 3 ft roll and 4x6 prefer the 4 ft roll when equally feasible, while a
    // 6 ft roll can still win for 7x6 when it is the least-waste feasible fit.
    // Stable IDs make equal plans deterministic.
    $compareLayouts = static function (array $a, array $b): int {
        $aWidth = array_sum(array_map(static fn($id, $count) => (float)$a['roll_widths'][(int)$id] * (int)$count, array_keys($a['counts']), array_values($a['counts'])));
        $bWidth = array_sum(array_map(static fn($id, $count) => (float)$b['roll_widths'][(int)$id] * (int)$count, array_keys($b['counts']), array_values($b['counts'])));
        $aArea = $aWidth * (float)$a['run_ft'] * (int)$a['quantity'];
        $bArea = $bWidth * (float)$b['run_ft'] * (int)$b['quantity'];
        return ($aArea <=> $bArea)
            ?: ($a['required_linear_ft'] <=> $b['required_linear_ft'])
            ?: ($a['panel_count'] <=> $b['panel_count'])
            ?: ($a['trim_waste_ft'] <=> $b['trim_waste_ft'])
            ?: ($aWidth <=> $bWidth)
            ?: ($a['required_linear_ft'] <=> $b['required_linear_ft'])
            ?: strcmp($a['orientation'], $b['orientation'])
            ?: strcmp(json_encode(array_keys($a['counts'])), json_encode(array_keys($b['counts'])));
    };
    if ($fits === []) {
        if ($sawSearchLimit) return ['ok' => false, 'reason' => 'search_limit', 'selection' => null];
        usort($failedLayouts, $compareLayouts);
        $failed = $failedLayouts[0] ?? null;
        if (!is_array($failed)) return ['ok' => false, 'reason' => 'insufficient', 'selection' => null];
        $shortageId = (int)(array_key_first($failed['required_by_item']) ?? 0);
        foreach ($failed['required_by_item'] as $candidateId => $candidateRequired) {
            $candidateId = (int)$candidateId;
            $candidateItem = $usable[$candidateId] ?? [];
            $candidateRolls = (array)($candidateItem['open_rolls'] ?? []);
            $candidateAvailable = array_sum(array_map(static fn(array $roll): float => max(0.0, (float)($roll['remaining_length_ft'] ?? 0)), $candidateRolls));
            $candidateMaxRoll = $candidateRolls === [] ? 0.0 : max(array_map(static fn(array $roll): float => max(0.0, (float)($roll['remaining_length_ft'] ?? 0)), $candidateRolls));
            $candidatePanelCount = (int)($failed['counts'][$candidateId] ?? 0) * (int)$failed['quantity'];
            if ((float)$candidateRequired > $candidateAvailable + 1.0e-9 || ($candidatePanelCount > 0 && $candidateMaxRoll + 1.0e-9 < (float)$failed['run_ft'])) {
                $shortageId = $candidateId;
                break;
            }
        }
        $shortageItem = $usable[$shortageId] ?? [];
        $requiredFt = (float)($failed['required_by_item'][$shortageId] ?? 0);
        $availableFt = array_sum(array_map(static fn(array $roll): float => max(0.0, (float)($roll['remaining_length_ft'] ?? 0)), (array)($shortageItem['open_rolls'] ?? [])));
        $physical = ($failed['allocation_reason'] ?? '') === 'physical_roll_capacity';
        $message = $physical
            ? 'Available ' . (string)($shortageItem['name'] ?? 'Tarpaulin roll') . ' rolls cannot hold every required continuous panel (requires ' . $requiredFt . ' ft; ' . $availableFt . ' ft remains in usable rolls).'
            : 'Insufficient ' . (string)($shortageItem['name'] ?? 'Tarpaulin roll') . ' stock (requires ' . $requiredFt . ' ft; ' . $availableFt . ' ft available).';
        return ['ok' => false, 'reason' => 'insufficient', 'selection' => null, 'shortage' => [
            'id' => $shortageId, 'name' => (string)($shortageItem['name'] ?? 'Tarpaulin roll'), 'uom' => 'Ft',
            'required_ft' => (float)$requiredFt, 'available_ft' => (float)$availableFt, 'message' => $message, 'physical' => $physical,
        ], 'candidates' => $failedLayouts];
    }
    usort($fits, $compareLayouts);
    $winner = $fits[0];
    $selection = [
        // This exact-roll allocation is the current persisted format consumed
        // by checkout, Production Assignment, and completion deduction.
        'version' => PRINTFLOW_TARPAULIN_ROLL_FIT_VERSION, 'branch_id' => (int)($candidates[0]['branch_id'] ?? 0),
        'width_ft' => $w, 'height_ft' => $h, 'finished_quantity' => $q,
        'orientation' => $winner['orientation'], 'panel_count_per_piece' => $winner['panel_count'],
        'required_linear_ft' => (float)$winner['required_linear_ft'], 'required_by_item' => $winner['required_by_item'],
        'material_items' => $winner['material_items'], 'roll_allocations_by_item' => $winner['allocations_by_item'],
        'trim_waste_ft' => (float)$winner['trim_waste_ft'], 'width_policy' => 'Joined full-width strips; final strip is trimmed; no offcut credit',
    ];
    if (count($winner['counts']) === 1) {
        $itemId = (int)array_key_first($winner['counts']);
        $selection['item_id'] = $itemId;
        $selection['item_name'] = (string)$usable[$itemId]['name'];
        $selection['roll_width_ft'] = (float)$usable[$itemId]['roll_width_ft'];
    }
    $selection['material_items'] = [];
    foreach ($winner['counts'] as $itemId => $_count) {
        $itemId = (int)$itemId;
        $selection['material_items'][$itemId] = [
            'name' => (string)$usable[$itemId]['name'],
            'roll_width_ft' => (float)$usable[$itemId]['roll_width_ft'],
        ];
    }
    return ['ok' => true, 'reason' => '', 'selection' => $selection, 'candidates' => $fits];
}
