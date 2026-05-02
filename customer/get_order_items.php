<?php
/**
 * AJAX: Get Order Items (Customer)
 * Returns order items + full order details as JSON for modal display
 */

error_reporting(0);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

function customer_order_items_json($payload, $status = 200) {
    while (ob_get_level()) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        http_response_code(500);
        $json = json_encode(['error' => 'Server error while encoding order details.']);
    }

    echo $json;
    exit;
}

register_shutdown_function(function() {
    $error = error_get_last();
    $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if ($error && in_array($error['type'], $fatal_types, true)) {
        while (ob_get_level()) {
            ob_end_clean();
        }

        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Server error while loading order details.'
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
});

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_ui_helper.php';
require_once __DIR__ . '/../includes/service_field_config_helper.php';

require_role('Customer');

function pf_asset_kind(?string $mime, ?string $path): string {
    $mime = strtolower(trim((string)$mime));
    $path = strtolower(trim((string)$path));

    if ($mime === 'application/pdf') {
        return 'pdf';
    }

    if ($path !== '' && preg_match('/\.pdf(?:$|\?)/', $path)) {
        return 'pdf';
    }

    return 'image';
}

function customer_order_items_decode_customization_payload($raw): array {
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (is_string($decoded)) {
        $decoded = json_decode($decoded, true);
    }
    return is_array($decoded) ? $decoded : [];
}

function customer_order_items_overlay_nonempty_specs(array $base, array $overlay): array {
    $out = $base;
    foreach ($overlay as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        if (is_string($v) && trim($v) === '') {
            continue;
        }
        if (is_array($v) && $v === []) {
            continue;
        }
        $out[$k] = $v;
    }

    return $out;
}

function customer_order_items_merge_customization_payload(array $primary, array $fallback, string $fallbackServiceType = ''): array {
    // Prefer non-empty order_item keys; keep customization-table keys when the line only has blanks or sparse JSON.
    $merged = customer_order_items_overlay_nonempty_specs($fallback, $primary);
    if ($fallbackServiceType !== '' && empty($merged['service_type'])) {
        $merged['service_type'] = $fallbackServiceType;
    }

    return $merged;
}

/**
 * Align branch / quantity labels with admin-configured service_field_configs (no hardcoded service-specific labels).
 * Fills branch from orders.branch_name when specs omit it (legacy rows).
 */
function customer_order_items_normalize_service_specs_for_modal(array $custom_data, array $order, array $item, bool $is_service_order): array {
    if (!$is_service_order) {
        return $custom_data;
    }

    $sid = (int)($custom_data['service_id'] ?? 0);
    if ($sid <= 0 && strtolower(trim((string)($order['order_type'] ?? ''))) === 'custom') {
        $sid = (int)($order['reference_id'] ?? 0);
    }

    $branchLabel = 'Branch';
    $quantityLabel = 'Quantity';
    if ($sid > 0 && function_exists('get_service_field_config')) {
        $configs = get_service_field_config($sid);
        if (is_array($configs)) {
            foreach ($configs as $fk => $cfg) {
                if (!is_array($cfg)) {
                    continue;
                }
                $l = trim((string)($cfg['label'] ?? ''));
                $disp = $l !== '' ? $l : trim((string)$fk);
                if ($fk === 'branch') {
                    $branchLabel = $disp;
                }
                if (($cfg['type'] ?? '') === 'quantity') {
                    $quantityLabel = $disp;
                }
            }
        }
    }

    if (array_key_exists('branch', $custom_data)) {
        $legacyBranch = trim((string)$custom_data['branch']);
        unset($custom_data['branch']);
        if ($legacyBranch !== '') {
            $already = false;
            foreach ($custom_data as $k => $v) {
                if (strcasecmp(trim((string)$k), $branchLabel) === 0 && trim((string)$v) !== '') {
                    $already = true;
                    break;
                }
            }
            if (!$already) {
                $custom_data[$branchLabel] = $legacyBranch;
            }
        }
    }

    $hasBranchSpec = false;
    foreach ($custom_data as $k => $v) {
        $kk = strtolower(trim((string)$k));
        $vv = is_scalar($v) || $v === null ? trim((string)$v) : '';
        if ($vv === '') {
            continue;
        }
        if (strcasecmp($kk, strtolower($branchLabel)) === 0 || str_contains($kk, 'branch') || str_contains($kk, 'pickup')) {
            $hasBranchSpec = true;
            break;
        }
    }
    $orderBranch = trim((string)($order['branch_name'] ?? ''));
    if (!$hasBranchSpec && $orderBranch !== '') {
        $custom_data[$branchLabel] = $orderBranch;
    }

    $qtyVal = max(1, (int)($item['quantity'] ?? 0));
    $hasQty = false;
    foreach (array_keys($custom_data) as $k) {
        $kk = trim((string)$k);
        if (strcasecmp($kk, $quantityLabel) === 0 || strcasecmp($kk, 'quantity') === 0) {
            $hasQty = true;
            break;
        }
    }
    if (!$hasQty && $qtyVal > 0) {
        $custom_data[$quantityLabel] = (string)$qtyVal;
    }

    return $custom_data;
}

/**
 * Reorder customization keys to match service_field_configs display order (labels only; extras appended).
 */
function customer_order_items_sort_customization_by_service_config(array $custom_data, int $serviceId): array {
    $serviceId = (int)$serviceId;
    if ($serviceId <= 0 || !function_exists('get_service_field_config')) {
        return $custom_data;
    }

    $configs = get_service_field_config($serviceId);
    if (!is_array($configs) || $configs === []) {
        return $custom_data;
    }

    $ordered = [];
    $used = [];
    foreach ($configs as $fk => $cfg) {
        if (!is_array($cfg) || empty($cfg['visible'])) {
            continue;
        }
        $l = trim((string)($cfg['label'] ?? ''));
        $label = $l !== '' ? $l : trim((string)$fk);
        foreach ($custom_data as $ck => $cv) {
            if (!empty($used[$ck])) {
                continue;
            }
            if (strcasecmp(trim((string)$ck), $label) === 0) {
                $ordered[$ck] = $cv;
                $used[$ck] = true;
                break;
            }
        }
    }
    foreach ($custom_data as $ck => $cv) {
        if (empty($used[$ck])) {
            $ordered[$ck] = $cv;
        }
    }

    return $ordered;
}

$order_id = (int)($_GET['id'] ?? 0);
$customer_id = get_user_id();

if (!$order_id) {
    customer_order_items_json(['error' => 'Invalid order ID'], 400);
}

// Verify order belongs to this customer
$order_result = db_query("
    SELECT o.*, b.branch_name,
           (SELECT jo.payment_proof_status
            FROM job_orders jo
            WHERE jo.order_id = o.order_id
            ORDER BY jo.payment_verified_at DESC, jo.id DESC
            LIMIT 1) as latest_payment_proof_status,
           (SELECT jo.payment_status
            FROM job_orders jo
            WHERE jo.order_id = o.order_id
            ORDER BY jo.payment_verified_at DESC, jo.id DESC
            LIMIT 1) as latest_job_payment_status,
           (SELECT jo.payment_rejection_reason
            FROM job_orders jo
            WHERE jo.order_id = o.order_id
              AND jo.payment_rejection_reason IS NOT NULL
              AND jo.payment_rejection_reason != ''
            ORDER BY jo.payment_verified_at DESC, jo.id DESC
            LIMIT 1) as payment_rejection_reason,
           (SELECT GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR '-') FROM order_items oi LEFT JOIN products p ON oi.product_id = p.product_id WHERE oi.order_id = o.order_id) as order_sku,
           IFNULL((
                SELECT jo.job_title FROM job_orders jo
                WHERE jo.order_id = o.order_id ORDER BY jo.id ASC LIMIT 1
            ), '') as first_job_title,
           IFNULL((
                SELECT jo.service_type FROM job_orders jo
                WHERE jo.order_id = o.order_id ORDER BY jo.id ASC LIMIT 1
            ), '') as first_job_service_type
    FROM orders o 
    LEFT JOIN branches b ON o.branch_id = b.id 
    WHERE o.order_id = ? AND o.customer_id = ?
", 'ii', [$order_id, $customer_id]);

if (empty($order_result)) {
    customer_order_items_json(['error' => 'Order not found'], 404);
}
$order = $order_result[0];
$latest_payment_proof_status = strtoupper((string)($order['latest_payment_proof_status'] ?? ''));
$latest_job_payment_status = strtoupper((string)($order['latest_job_payment_status'] ?? ''));
$is_rejected_payment = (strcasecmp((string)($order['status'] ?? ''), 'Rejected') === 0)
    || ($latest_payment_proof_status === 'REJECTED');

$payment_status = (string)($order['payment_status'] ?? 'Not Specified');
if ($is_rejected_payment) {
    $payment_status = 'Unpaid';
} elseif ($latest_job_payment_status === 'PAID') {
    $payment_status = 'Paid';
} elseif ($latest_job_payment_status === 'UNPAID') {
    $payment_status = 'Unpaid';
} elseif ($latest_job_payment_status === 'PARTIAL') {
    $payment_status = 'Partial';
}

$service_final_price_pending_statuses = ['Pending', 'Pending Approval', 'Pending Review', 'For Revision', 'Approved'];
$service_final_price_locked = in_array((string)($order['status'] ?? ''), $service_final_price_pending_statuses, true);

$valid_order_item_ids = [];
$valid_line_rows = db_query('SELECT order_item_id FROM order_items WHERE order_id = ?', 'i', [$order_id]) ?: [];
foreach ($valid_line_rows as $vr) {
    $lid = (int)($vr['order_item_id'] ?? 0);
    if ($lid > 0) {
        $valid_order_item_ids[$lid] = true;
    }
}

$customization_rows = db_query(
    "SELECT customization_id, order_item_id, service_type, customization_details
     FROM customizations
     WHERE order_id = ?
     ORDER BY customization_id ASC",
    'i',
    [$order_id]
) ?: [];

$customization_by_item_id = [];
$orphan_customization_details = [];
$first_customization_payload = [];
$first_customization_service_type = '';
foreach ($customization_rows as $cRow) {
    $serviceType = trim((string)($cRow['service_type'] ?? ''));
    $details = customer_order_items_decode_customization_payload((string)($cRow['customization_details'] ?? ''));
    if ($serviceType !== '' && empty($details['service_type'])) {
        $details['service_type'] = $serviceType;
    }

    if ((empty($first_customization_payload) && !empty($details)) || ($first_customization_service_type === '' && $serviceType !== '')) {
        if (!empty($details)) {
            $first_customization_payload = $details;
        }
        if ($serviceType !== '') {
            $first_customization_service_type = $serviceType;
        }
    }

    $orderItemId = (int)($cRow['order_item_id'] ?? 0);
    // Order-level rows, or stale/wrong order_item_id (specs still need to show on every line).
    if ($orderItemId <= 0 || !isset($valid_order_item_ids[$orderItemId])) {
        $orphan_customization_details = array_replace($orphan_customization_details, $details);
        if ($serviceType !== '' && trim((string)($orphan_customization_details['service_type'] ?? '')) === '') {
            $orphan_customization_details['service_type'] = $serviceType;
        }
        continue;
    }

    if (!isset($customization_by_item_id[$orderItemId])) {
        $customization_by_item_id[$orderItemId] = [
            'details' => [],
            'service_type' => '',
        ];
    }
    $customization_by_item_id[$orderItemId]['details'] = array_replace(
        $customization_by_item_id[$orderItemId]['details'],
        $details
    );
    if ($serviceType !== '' && trim((string)$customization_by_item_id[$orderItemId]['service_type']) === '') {
        $customization_by_item_id[$orderItemId]['service_type'] = $serviceType;
    }
}

$first_item_customization = [];
$first_item_raw_customization = db_query(
    "SELECT customization_data FROM order_items WHERE order_id = ? ORDER BY order_item_id ASC LIMIT 1",
    'i',
    [$order_id]
);
if (!empty($first_item_raw_customization[0]['customization_data'])) {
    $first_item_customization = customer_order_items_decode_customization_payload((string)$first_item_raw_customization[0]['customization_data']);
}
$first_item_customization = customer_order_items_merge_customization_payload(
    $first_item_customization,
    array_replace($orphan_customization_details, $first_customization_payload),
    $first_customization_service_type !== ''
        ? $first_customization_service_type
        : trim((string)($orphan_customization_details['service_type'] ?? ''))
);

$is_service_order = !empty($first_item_customization['service_type']) || $first_customization_service_type !== '';
if (!$is_service_order) {
    $order_type_normalized = strtolower(trim((string)($order['order_type'] ?? '')));
    $is_service_order = $order_type_normalized === 'custom' && empty($first_item_customization['product_type']);
}

$display_status = (string)($order['status'] ?? '');
$order_type_normalized = strtolower(trim((string)($order['order_type'] ?? '')));
if ($order_type_normalized === 'product' && !$is_service_order) {
    if (in_array($display_status, ['Pending', 'Pending Approval', 'Pending Review', 'For Revision', 'Approved', 'To Pay', 'To Verify', 'Downpayment Submitted', 'Pending Verification'], true)) {
        $display_status = 'TO VERIFY';
    } elseif (in_array($display_status, ['Ready for Pickup', 'Approved Design', 'To Receive', 'In Production', 'Processing', 'Printing', 'Paid - In Process', 'Paid – In Process', 'Paid â€“ In Process'], true)) {
        $display_status = 'TO PICK UP';
    } elseif (in_array($display_status, ['Completed', 'To Rate', 'Rated'], true)) {
        $display_status = 'COMPLETED';
    } elseif ($display_status === 'Cancelled') {
        $display_status = 'CANCELLED';
    }
}

// Get items with design info
$items = db_query("
    SELECT oi.*, p.name as product_name, p.category
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.product_id
    WHERE oi.order_id = ?
", 'i', [$order_id]);

$items = is_array($items) ? $items : [];
$job_orders_list = db_query(
    'SELECT job_title, service_type, width_ft, height_ft, notes, total_sqft
     FROM job_orders WHERE order_id = ? ORDER BY id ASC',
    'i',
    [$order_id]
) ?: [];

// Some legacy or interrupted checkouts leave an orders row (and customizations / jobs) with no order_items.
// Without a line, the customer modal renders an empty table; synthesize one row from order-level data.
if ($items === []) {
    $order_type_lc = strtolower(trim((string)($order['order_type'] ?? '')));
    if (
        $is_service_order
        || $order_type_lc === 'custom'
        || $customization_rows !== []
        || $first_item_customization !== []
        || $job_orders_list !== []
    ) {
        $synthName = '';
        $synthCat = '';
        $ref = (int)($order['reference_id'] ?? 0);
        if ($ref > 0) {
            $sv = db_query('SELECT name, category FROM services WHERE service_id = ? LIMIT 1', 'i', [$ref]);
            if (!empty($sv)) {
                $synthName = (string)($sv[0]['name'] ?? '');
                $synthCat = (string)($sv[0]['category'] ?? '');
            }
        }
        $ta = (float)($order['total_amount'] ?? 0);
        $est = (float)($order['estimated_price'] ?? 0);
        $synthUnit = $ta > 0 ? $ta : ($est > 0 ? $est : 0.0);
        $items = [[
            'order_item_id' => 0,
            'product_id' => $ref,
            'quantity' => 1,
            'unit_price' => $synthUnit,
            'customization_data' => null,
            'product_name' => $synthName,
            'category' => $synthCat,
            'design_image' => null,
            'design_image_mime' => null,
            'design_file' => null,
            'reference_image_file' => null,
        ]];
    }
}

$first_order_item_id = null;
foreach ($items as $it) {
    $oid = (int)($it['order_item_id'] ?? 0);
    if ($oid > 0) {
        $first_order_item_id = $first_order_item_id === null ? $oid : min($first_order_item_id, $oid);
    }
}

$items_out = [];
$service_items_raw = [];
$order_total_amount = (float)($order['total_amount'] ?? 0);
$item_count = count($items);

$any_design_order_item_id = 0;
foreach ($items as $_it) {
    $_oid = (int)($_it['order_item_id'] ?? 0);
    if ($_oid <= 0) {
        continue;
    }
    if (!empty($_it['design_image']) || (isset($_it['design_file']) && trim((string)$_it['design_file']) !== '')) {
        $any_design_order_item_id = $_oid;
        break;
    }
}
if ($any_design_order_item_id <= 0) {
    $design_row = db_query(
        "SELECT order_item_id FROM order_items WHERE order_id = ?
         AND (design_image IS NOT NULL OR (design_file IS NOT NULL AND TRIM(COALESCE(design_file, '')) != ''))
         ORDER BY order_item_id ASC LIMIT 1",
        'i',
        [$order_id]
    );
    if (!empty($design_row)) {
        $any_design_order_item_id = (int)$design_row[0]['order_item_id'];
    }
}

$fallback_design_meta = null;
if ($any_design_order_item_id > 0) {
    $fm = db_query(
        'SELECT design_image_mime, design_file FROM order_items WHERE order_item_id = ? LIMIT 1',
        'i',
        [$any_design_order_item_id]
    );
    if (!empty($fm)) {
        $fallback_design_meta = $fm[0];
    }
}

foreach ($items as $lineIndex => $item) {
    $custom_data = customer_order_items_decode_customization_payload((string)($item['customization_data'] ?? ''));
    $itemCustomizationFallback = $customization_by_item_id[(int)($item['order_item_id'] ?? 0)] ?? ['details' => [], 'service_type' => ''];
    $mergedTableDetails = customer_order_items_overlay_nonempty_specs(
        $orphan_customization_details,
        (array)($itemCustomizationFallback['details'] ?? [])
    );
    $orphanSvc = trim((string)($orphan_customization_details['service_type'] ?? ''));
    $itemTblSvc = trim((string)($itemCustomizationFallback['service_type'] ?? ''));
    $fallbackSvc = $itemTblSvc !== '' ? $itemTblSvc : $orphanSvc;
    $custom_data = customer_order_items_merge_customization_payload(
        $custom_data,
        $mergedTableDetails,
        $fallbackSvc
    );
    if (empty($custom_data['service_type']) && !empty($first_item_customization['service_type'])) {
        $custom_data['service_type'] = (string)$first_item_customization['service_type'];
    }

    if ($is_service_order && $job_orders_list !== []) {
        $jc = count($job_orders_list);
        $jobRow = null;
        if ($item_count === 1 && $jc >= 1) {
            $jobRow = $job_orders_list[0];
        } elseif ($jc === $item_count && isset($job_orders_list[$lineIndex])) {
            $jobRow = $job_orders_list[$lineIndex];
        } elseif (isset($job_orders_list[$lineIndex])) {
            $jobRow = $job_orders_list[$lineIndex];
        }
        if (is_array($jobRow)) {
            $custom_data = customer_orders_merge_job_order_row_into_customization($custom_data, $jobRow);
        }
    }

    $custom_data = customer_orders_enrich_line_customization($custom_data, $order);
    unset($custom_data['design_upload'], $custom_data['reference_upload']);

    $custom_data = customer_order_items_normalize_service_specs_for_modal($custom_data, $order, $item, $is_service_order);

    if ($is_service_order) {
        $sidForSort = (int)($custom_data['service_id'] ?? 0);
        if ($sidForSort <= 0 && strtolower(trim((string)($order['order_type'] ?? ''))) === 'custom') {
            $sidForSort = (int)($order['reference_id'] ?? 0);
        }
        $custom_data = customer_order_items_sort_customization_by_service_config($custom_data, $sidForSort);
    }

    $raw_quantity = max(1, (int)($item['quantity'] ?? 0));
    $raw_unit_price = (float)($item['unit_price'] ?? 0);
    $raw_subtotal = $raw_quantity * $raw_unit_price;

    // Some single-item custom/service orders store the final amount only on orders.total_amount.
    if ($raw_subtotal <= 0 && $item_count === 1 && $order_total_amount > 0) {
        $raw_subtotal = $order_total_amount;
        $raw_unit_price = $order_total_amount / $raw_quantity;
    } elseif ($raw_subtotal <= 0 && $item_count === 1) {
        $est_order = (float)($order['estimated_price'] ?? 0);
        if ($est_order > 0) {
            $raw_subtotal = $est_order;
            $raw_unit_price = $est_order / $raw_quantity;
        }
    }

    $use_job_line_fallback = ($item_count === 1) || ($first_order_item_id !== null && (int)($item['order_item_id'] ?? 0) === (int)$first_order_item_id);
    $orderLike = [
        'order_type' => $order['order_type'] ?? '',
        'reference_id' => $order['reference_id'] ?? null,
        'first_product_name' => (string)($item['product_name'] ?? ''),
        'first_customization_service_type' => (string)$fallbackSvc,
        'first_job_title' => (string)($order['first_job_title'] ?? ''),
        'first_job_service_type' => (string)($order['first_job_service_type'] ?? ''),
        '_merged_customization' => $custom_data,
        '_use_job_title_fallback' => $use_job_line_fallback,
    ];
    $resolved_item_name = customer_orders_primary_item_name($orderLike);
    $customForPayload = function_exists('printflow_flatten_customization_for_customer_order_modal')
        ? printflow_flatten_customization_for_customer_order_modal($custom_data)
        : (function_exists('printflow_flatten_order_customization_for_customer_modal')
            ? printflow_flatten_order_customization_for_customer_modal($custom_data)
            : $custom_data);
    if (is_array($customForPayload) && isset($customForPayload['service_type'])) {
        $dn = strtolower(trim((string)$resolved_item_name));
        $stv = strtolower(trim((string)$customForPayload['service_type']));
        if ($dn !== '' && $stv === $dn) {
            unset($customForPayload['service_type']);
        }
    }

    $line_oid = (int)($item['order_item_id'] ?? 0);
    $has_own_design = !empty($item['design_image']) || (isset($item['design_file']) && trim((string)$item['design_file']) !== '');
    $design_serve_id = $has_own_design
        ? $line_oid
        : (($line_oid === 0 && $any_design_order_item_id > 0) ? $any_design_order_item_id : 0);
    $has_design_thumb = $design_serve_id > 0;

    $service_items_raw[] = [
        'raw_subtotal' => $raw_subtotal,
        'payload' => [
        'order_item_id' => (int)$item['order_item_id'],
        'product_name'  => $resolved_item_name,
        'category'      => (strtolower($item['category'] ?? '') === 'merchandise') ? '' : ($item['category'] ?? ''),
        'quantity'      => $raw_quantity,
        'unit_price'    => format_currency($raw_unit_price),
        'subtotal'      => format_currency($raw_subtotal),
        'estimated_price' => format_currency($raw_subtotal),
        'final_price'   => format_currency($raw_subtotal),
        'customization' => $customForPayload,
        'has_design'    => $has_design_thumb,
        'has_reference' => !empty($item['reference_image_file']),
        'design_kind'   => $has_own_design
            ? pf_asset_kind($item['design_image_mime'] ?? '', $item['design_file'] ?? '')
            : ($fallback_design_meta
                ? pf_asset_kind($fallback_design_meta['design_image_mime'] ?? '', $fallback_design_meta['design_file'] ?? '')
                : pf_asset_kind($item['design_image_mime'] ?? '', $item['design_file'] ?? '')),
        'reference_kind'=> pf_asset_kind('', $item['reference_image_file'] ?? ''),
        'design_url'    => $has_design_thumb
                            ? $base_path . '/public/serve_design.php?type=order_item&id=' . $design_serve_id
                            : null,
        'reference_url' => !empty($item['reference_image_file'])
                            ? $base_path . '/public/serve_design.php?type=order_item&id=' . $line_oid . '&field=reference'
                            : null,
        ],
    ];
}

$estimated_order_amount = (float)($order['estimated_price'] ?? 0);
if ($estimated_order_amount <= 0) {
    foreach ($service_items_raw as $entry) {
        $estimated_order_amount += (float)($entry['raw_subtotal'] ?? 0);
    }
}

$estimated_sum = 0.0;
foreach ($service_items_raw as $entry) {
    $estimated_sum += (float)($entry['raw_subtotal'] ?? 0);
}

foreach ($service_items_raw as $index => $entry) {
    $payload = $entry['payload'];
    $raw_subtotal = (float)($entry['raw_subtotal'] ?? 0);
    $final_item_amount = null;

    if (!$service_final_price_locked && $order_total_amount > 0 && $item_count === 1) {
        $final_item_amount = $order_total_amount > 0 ? $order_total_amount : $raw_subtotal;
    } elseif (!$service_final_price_locked && $estimated_sum > 0 && $order_total_amount > 0) {
        $final_item_amount = ($raw_subtotal / $estimated_sum) * $order_total_amount;
    }

    $payload['estimated_price'] = format_currency($raw_subtotal);
    $payload['final_price'] = ($final_item_amount !== null && $final_item_amount > 0)
        ? format_currency($final_item_amount)
        : 'To Be Discussed';
    $items_out[] = $payload;
}

// Cancellation / revision details
$cancel_info = '';
if ($order['status'] === 'Cancelled') {
    $cancel_info = trim(($order['cancelled_by'] ? 'By: ' . $order['cancelled_by'] : '') . ' | ' . ($order['cancel_reason'] ?? ''), ' |');
}

$can_cancel = can_customer_cancel_order($order);
$restriction_msg = '';
if (!$can_cancel && !in_array($order['status'], ['Cancelled', 'Completed'])) {
    switch ($order['status']) {
        case 'To Pay':
            $restriction_msg = printflow_format_order_code($order['order_id'], $order['order_sku'] ?? '') . " is already ready for payment.";
            break;
        case 'In Production':
        case 'Printing':
            $restriction_msg = printflow_format_order_code($order['order_id'], $order['order_sku'] ?? '') . " is already in production.";
            break;
        case 'Ready for Pickup':
            $restriction_msg = printflow_format_order_code($order['order_id'], $order['order_sku'] ?? '') . " is already ready for pickup.";
            break;
        default:
            $restriction_msg = printflow_format_order_code($order['order_id'], $order['order_sku'] ?? '') . " is already being processed.";
            break;
    }
}

// Rating details
$rating_data = null;
if (in_array($order['status'], ['Completed', 'To Rate', 'Rated'], true)) {
    $rating_res = db_query("SELECT * FROM reviews WHERE order_id = ?", 'i', [$order_id]);
    if (!empty($rating_res)) {
        $r = $rating_res[0];
        $rating_data = [
            'rating' => (int)$r['rating'],
            'comment' => $r['comment'] ?? '',
            'image_url' => null, // Multiple images handled by review_images table
            'created_at' => format_datetime($r['created_at']),
            'view_url' => ($r['review_type'] === 'custom') 
                ? "/printflow/customer/order_service_dynamic.php?service_id=" . $r['reference_id'] . "#review-" . $r['id']
                : "/printflow/customer/order_create.php?product_id=" . $r['reference_id'] . "#review-" . $r['id']
        ];
    }
}

customer_order_items_json([
    'order_id'         => $order['order_id'],
    'order_code'       => printflow_format_order_code($order['order_id'], $order['order_sku'] ?? ''),
    'order_date'       => format_datetime($order['order_date']),
    'is_service_order' => $is_service_order,
    'estimated_price'  => $is_service_order
        ? ($estimated_order_amount > 0
            ? format_currency($estimated_order_amount)
            : 'Pending')
        : null,
    'total_amount'     => ($is_service_order && ($service_final_price_locked || $order_total_amount <= 0))
        ? 'To Be Discussed'
        : format_currency($order['total_amount']),
    'status'           => $order['status'],
    'display_status'   => $display_status,
    'payment_status'   => $payment_status,
    'payment_method'   => $order['payment_method'] ?? 'Not Specified',
    'payment_proof_status' => $latest_payment_proof_status,
    'branch_name'      => $order['branch_name'] ?? 'Not Specified',
    'estimated_comp'   => ($order['estimated_completion'] ?? null) ? format_date($order['estimated_completion']) : 'Waiting for confirmation from the shop',
    'notes'            => $order['notes'] ?? '',
    'cancelled_by'     => $order['cancelled_by'] ?? '',
    'cancel_reason'    => $order['cancel_reason'] ?? '',
    'cancelled_at'     => !empty($order['cancelled_at']) ? format_datetime($order['cancelled_at']) : '',
    'design_status'    => $order['design_status'] ?? 'Pending',
    'revision_reason'  => $order['revision_reason'] ?? '',
    'payment_rejection_reason' => $order['payment_rejection_reason'] ?? '',
    'items'            => $items_out,
    'can_cancel'       => $can_cancel,
    'cancel_restriction_msg' => $restriction_msg,
    'rating_data'      => $rating_data,
    'csrf_token'       => generate_csrf_token()
]);
