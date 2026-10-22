<?php
/** Shared, versioned Tarpaulin roll-length calculations for POS and production. */

require_once __DIR__ . '/pos_quantity_validation.php';

const PRINTFLOW_TARPAULIN_CALCULATION_VERSION = 'linear_ft_v1';

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

/** Validate the one recognized calculation marker against persisted service identity. */
function printflow_tarpaulin_assert_versioned_job(array $metadata, int $jobId, ?array $knownJob = null): bool
{
    if (($metadata['tarpaulin_calculation_version'] ?? '') !== PRINTFLOW_TARPAULIN_CALCULATION_VERSION) return false;
    if (!printflow_tarpaulin_job_is_authoritative_service($jobId, $knownJob)) {
        throw new RuntimeException('The linear_ft_v1 calculation is valid only for a uniquely linked Tarpaulin service job.');
    }
    return true;
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

function printflow_tarpaulin_parse_nominal_width(array $item): float
{
    if (empty($item['track_by_roll'])) return 0.0;
    $name = trim((string)($item['name'] ?? ''));
    $category = strtoupper(trim((string)($item['category_name'] ?? '')));
    if (preg_match('/\bINK\b/i', $name) || str_starts_with($category, 'INK')) return 0.0;
    $uom = strtolower(trim((string)($item['unit_of_measure'] ?? 'ft')));
    if (!in_array($uom, ['ft', 'feet', 'foot'], true)) return 0.0;
    if (!preg_match('/\b(?:TARPAULIN|TARP)\b/i', $name) && $category !== 'TARPAULIN') return 0.0;
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

