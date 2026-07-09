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

function customer_order_items_json_flags(): int {
    $flags = JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    return $flags;
}

function customer_order_items_log_error(string $message, array $context = []): void {
    $suffix = $context !== [] ? ' | ' . json_encode($context, customer_order_items_json_flags()) : '';
    error_log('[customer/get_order_items] ' . $message . $suffix);
}

function customer_order_items_json($payload, $status = 200) {
    while (ob_get_level()) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if (is_array($payload)) {
        if (!array_key_exists('success', $payload)) {
            $payload['success'] = $status < 400;
        }
        if (!array_key_exists('message', $payload) && isset($payload['error']) && is_string($payload['error'])) {
            $payload['message'] = $payload['error'];
        }
    }
    $json = json_encode($payload, customer_order_items_json_flags());
    if ($json === false) {
        http_response_code(500);
        $json = json_encode([
            'success' => false,
            'error' => 'Server error while encoding order details.',
            'message' => 'Server error while encoding order details.'
        ], customer_order_items_json_flags());
    }

    echo $json;
    exit;
}

register_shutdown_function(function() {
    $error = error_get_last();
    $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if ($error && in_array($error['type'], $fatal_types, true)) {
        customer_order_items_log_error('Fatal shutdown error', $error);
        while (ob_get_level()) {
            ob_end_clean();
        }

        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Server error while loading order details.',
            'message' => 'Server error while loading order details.'
        ], customer_order_items_json_flags());
    }
});

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_ui_helper.php';
require_once __DIR__ . '/../includes/service_field_config_helper.php';
require_once __DIR__ . '/../includes/order_items_persistence.php';
require_once __DIR__ . '/../includes/runtime_config.php';

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
    if (function_exists('customer_orders_decode_customization_payload')) {
        return customer_orders_decode_customization_payload($raw);
    }
    if (is_array($raw)) {
        return $raw;
    }
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
    return printflow_overlay_nonempty_assoc($base, $overlay);
}

function customer_order_items_merge_customization_payload(array $primary, array $fallback, string $fallbackServiceType = ''): array {
    // Prefer non-empty order_item keys; keep customization-table keys when the line only has blanks or sparse JSON.
    $merged = customer_order_items_overlay_nonempty_specs($fallback, $primary);
    if ($fallbackServiceType !== '' && empty($merged['service_type'])) {
        $merged['service_type'] = $fallbackServiceType;
    }

    return $merged;
}

function customer_order_items_restore_customization_from_session(array $restoreItem): array {
    $custom = $restoreItem['customization'] ?? [];
    if (is_string($custom)) {
        $custom = customer_order_items_decode_customization_payload($custom);
    } elseif (!is_array($custom)) {
        $custom = [];
    }

    if (!empty($restoreItem['service_id']) && (int)($custom['service_id'] ?? 0) <= 0) {
        $custom['service_id'] = (int)$restoreItem['service_id'];
    }
    if (!empty($restoreItem['name']) && trim((string)($custom['service_type'] ?? '')) === '') {
        $custom['service_type'] = trim((string)$restoreItem['name']);
    }
    if (!empty($restoreItem['source_page']) && trim((string)($custom['source_page'] ?? '')) === '') {
        $custom['source_page'] = trim((string)$restoreItem['source_page']);
    }

    return is_array($custom) ? $custom : [];
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
    if ($sid <= 0) {
        $ot = strtolower(trim((string)($order['order_type'] ?? '')));
        if (in_array($ot, ['custom', 'product'], true)) {
            $sid = (int)($order['reference_id'] ?? 0);
        }
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

    $nf = static function (string $s): string {
        return strtolower(preg_replace('/[\s_\-]+/', '', trim($s)));
    };

    $ordered = [];
    $used = [];
    foreach ($configs as $fk => $cfg) {
        if (!is_array($cfg) || empty($cfg['visible'])) {
            continue;
        }
        $l = trim((string)($cfg['label'] ?? ''));
        $label = $l !== '' ? $l : trim((string)$fk);
        $labelNorm = $nf($label);
        $fkNorm = $nf((string)$fk);
        foreach ($custom_data as $ck => $cv) {
            if (!empty($used[$ck])) {
                continue;
            }
            $ckStr = trim((string)$ck);
            $ckNorm = $nf($ckStr);
            if (
                strcasecmp($ckStr, $label) === 0
                || ($labelNorm !== '' && $ckNorm === $labelNorm)
                || ($fkNorm !== '' && $ckNorm === $fkNorm)
            ) {
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

function customer_receipt_extract_display_contact(array $customer): string {
    $phone = trim((string)($customer['phone'] ?? ''));
    if ($phone !== '') {
        return $phone;
    }

    $email = trim((string)($customer['email'] ?? ''));
    if ($email === '' || strtolower($email) === 'walkin@pos.local') {
        return '';
    }

    return $email;
}

function customer_receipt_build_material_summary(int $orderId): array {
    if ($orderId <= 0 || !function_exists('db_table_has_column') || !db_table_has_column('job_order_materials', 'std_order_id')) {
        return [];
    }

    $materialHasUom = db_table_has_column('job_order_materials', 'uom');
    $materialHasNotes = db_table_has_column('job_order_materials', 'notes');
    $inventoryNameColumn = db_table_has_column('inv_items', 'name')
        ? 'name'
        : (db_table_has_column('inv_items', 'item_name') ? 'item_name' : '');
    if ($inventoryNameColumn === '') {
        return [];
    }

    $materials = db_query(
        "SELECT m.quantity,
                " . ($materialHasUom ? "m.uom" : "'' AS uom") . ",
                " . ($materialHasNotes ? "m.notes" : "'' AS notes") . ",
                i.{$inventoryNameColumn} AS item_name
         FROM job_order_materials m
         INNER JOIN inv_items i ON i.id = m.item_id
         WHERE m.std_order_id = ?
         ORDER BY m.id ASC",
        'i',
        [$orderId]
    ) ?: [];

    $summary = [];
    foreach ($materials as $row) {
        $name = trim((string)($row['item_name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $quantity = (float)($row['quantity'] ?? 0);
        $uom = trim((string)($row['uom'] ?? ''));
        $notes = trim((string)($row['notes'] ?? ''));

        $label = $name;
        if ($quantity > 0) {
            $cleanQty = floor($quantity) == $quantity
                ? (string)(int)$quantity
                : rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
            $label .= ' x ' . $cleanQty . ($uom !== '' ? ' ' . $uom : '');
        }
        if ($notes !== '') {
            $label .= ' (' . $notes . ')';
        }

        $summary[] = $label;
    }

    return array_values(array_unique($summary));
}

function customer_receipt_build_payload(array $order, array $items, string $paymentStatus): array {
    $orderId = (int)($order['order_id'] ?? 0);
    if ($orderId <= 0) {
        return [];
    }

    $subtotal = 0.0;
    $receiptItems = [];
    foreach ($items as $item) {
        $quantity = max(1, (int)($item['quantity'] ?? 0));
        $unitPriceRaw = (float)($item['_unit_price_raw'] ?? 0);
        $lineTotalRaw = (float)($item['_line_total_raw'] ?? ($quantity * $unitPriceRaw));
        if ($unitPriceRaw <= 0 && $quantity > 0 && $lineTotalRaw > 0) {
            $unitPriceRaw = $lineTotalRaw / $quantity;
        }

        $subtotal += $lineTotalRaw;
        $receiptItems[] = [
            'name' => (string)($item['product_name'] ?? 'Item'),
            'quantity' => $quantity,
            'unit_price' => round($unitPriceRaw, 2),
            'line_total' => round($lineTotalRaw, 2),
            'customization' => is_array($item['customization'] ?? null) ? $item['customization'] : [],
        ];
    }

    $storedTotal = (float)($order['total_amount'] ?? 0);
    $totalPaid = $storedTotal > 0 ? $storedTotal : $subtotal;
    $discountAmount = max(0, round($subtotal - $totalPaid, 2));

    $shopCfg = printflow_load_runtime_config('shop', dirname(__DIR__) . '/public/assets/uploads/shop_config.json');
    $shopName = trim((string)($shopCfg['name'] ?? 'Mr. and Mrs. Print'));
    $shopLogo = trim((string)($shopCfg['logo'] ?? ''));
    $logoUrl = $shopLogo !== ''
        ? rtrim((string)(defined('BASE_PATH') ? BASE_PATH : '/printflow'), '/') . '/public/assets/uploads/' . rawurlencode(basename($shopLogo))
        : '';

    $customerName = trim(((string)($order['first_name'] ?? '')) . ' ' . ((string)($order['last_name'] ?? '')));
    if ($customerName === '') {
        $customerName = 'Customer';
    }

    return [
        'receipt_number' => 'WEB-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT),
        'order_number' => printflow_format_order_code($orderId, $order['order_sku'] ?? ''),
        'date_time' => (string)($order['updated_at'] ?? $order['order_date'] ?? date('Y-m-d H:i:s')),
        'company' => [
            'name' => $shopName,
            'logo_url' => $logoUrl,
            'branch_name' => (string)($order['branch_name'] ?? 'Main Branch'),
            'address' => (string)($order['branch_address'] ?? ''),
            'contact' => (string)($order['branch_contact'] ?? ''),
        ],
        'customer' => [
            'name' => $customerName,
            'email' => (string)($order['email'] ?? ''),
            'phone' => (string)($order['customer_contact_number'] ?? ''),
        ],
        'items' => $receiptItems,
        'materials' => customer_receipt_build_material_summary($orderId),
        'subtotal' => round($subtotal, 2),
        'discount' => [
            'amount' => $discountAmount,
        ],
        'total' => round($totalPaid, 2),
        'payment' => [
            'method' => (string)($order['payment_method'] ?? 'Not Specified'),
            'status' => $paymentStatus,
            'amount_paid' => round($totalPaid, 2),
            'change' => 0,
            'reference' => (string)($order['_safe_payment_reference'] ?? ''),
        ],
        'customer_contact' => customer_receipt_extract_display_contact([
            'phone' => (string)($order['customer_contact_number'] ?? ''),
            'email' => (string)($order['email'] ?? ''),
        ]),
    ];
}

function customer_order_items_first_nonempty_datetime(array $values): string {
    foreach ($values as $value) {
        $text = trim((string)$value);
        if ($text !== '' && $text !== '0000-00-00 00:00:00' && $text !== '0000-00-00') {
            return $text;
        }
    }

    return '';
}

function customer_order_items_friendly_payment_method(?string $paymentMethod, string $paymentStatus): string {
    $method = trim((string)$paymentMethod);
    if ($method !== '') {
        return $method;
    }

    return strcasecmp($paymentStatus, 'Paid') === 0
        ? 'Payment method not recorded'
        : 'Payment not submitted yet';
}

function customer_order_items_status_meta(array $order, string $paymentStatus): array {
    $status = trim((string)($order['status'] ?? ''));
    $estimatedCompletionRaw = trim((string)($order['estimated_completion'] ?? ''));
    $updatedAtRaw = customer_order_items_first_nonempty_datetime([
        $order['completed_at'] ?? '',
        $order['updated_at'] ?? '',
        $order['order_date'] ?? '',
    ]);
    $cancelledAtRaw = customer_order_items_first_nonempty_datetime([
        $order['cancelled_at'] ?? '',
        $order['updated_at'] ?? '',
    ]);

    if (in_array($status, ['Pending', 'Pending Approval', 'Pending Review', 'Inquiry'], true)) {
        return [
            'label' => 'Estimated completion',
            'value' => 'Waiting for confirmation from the shop',
        ];
    }

    if (in_array($status, ['Approved', 'In Production', 'Processing', 'Printing', 'Paid - In Process', 'Paid – In Process', 'Paid â€“ In Process'], true)) {
        return [
            'label' => 'Estimated completion',
            'value' => $estimatedCompletionRaw !== ''
                ? format_date($estimatedCompletionRaw)
                : 'Waiting for estimated completion date',
        ];
    }

    if (in_array($status, ['Ready for Pickup', 'To Receive'], true)) {
        return [
            'label' => 'Pickup status',
            'value' => $estimatedCompletionRaw !== ''
                ? 'Ready for pickup on ' . format_date($estimatedCompletionRaw)
                : 'Ready for pickup',
        ];
    }

    if (in_array($status, ['Completed', 'To Rate', 'Rated'], true)) {
        return [
            'label' => 'Order completed',
            'value' => $updatedAtRaw !== ''
                ? 'Completed on ' . format_datetime($updatedAtRaw)
                : 'Order completed successfully',
        ];
    }

    if ($status === 'Cancelled') {
        $value = $cancelledAtRaw !== ''
            ? 'Cancelled on ' . format_datetime($cancelledAtRaw)
            : 'Order cancelled';
        $reason = trim((string)($order['cancel_reason'] ?? ''));
        if ($reason !== '') {
            $value .= ' - ' . $reason;
        }
        return [
            'label' => 'Order cancelled',
            'value' => $value,
        ];
    }

    if ($status === 'Rejected') {
        $value = $cancelledAtRaw !== ''
            ? 'Rejected on ' . format_datetime($cancelledAtRaw)
            : 'Order rejected';
        $reason = trim((string)($order['payment_rejection_reason'] ?? $order['cancel_reason'] ?? ''));
        if ($reason !== '') {
            $value .= ' - ' . $reason;
        }
        return [
            'label' => 'Order rejected',
            'value' => $value,
        ];
    }

    return [
        'label' => 'Estimated completion',
        'value' => $estimatedCompletionRaw !== ''
            ? format_date($estimatedCompletionRaw)
            : 'Waiting for estimated completion date',
    ];
}

try {
$order_id = (int)($_GET['id'] ?? 0);
$customer_id = get_user_id();

if (!$order_id) {
    customer_order_items_json(['error' => 'Invalid order ID'], 400);
}

$orderHasPaymentReference = function_exists('db_table_has_column') && db_table_has_column('orders', 'payment_reference');
$paymentReferenceSelect = $orderHasPaymentReference
    ? "o.payment_reference,"
    : "'' AS payment_reference,";
$jobOrdersHasPaymentProofStatus = function_exists('db_table_has_column') && db_table_has_column('job_orders', 'payment_proof_status');
$jobOrdersHasPaymentStatus = function_exists('db_table_has_column') && db_table_has_column('job_orders', 'payment_status');
$jobOrdersHasPaymentRejectionReason = function_exists('db_table_has_column') && db_table_has_column('job_orders', 'payment_rejection_reason');
$jobOrdersHasPaymentVerifiedAt = function_exists('db_table_has_column') && db_table_has_column('job_orders', 'payment_verified_at');
$jobOrdersPaymentOrderBy = $jobOrdersHasPaymentVerifiedAt
    ? 'jo.payment_verified_at DESC, jo.id DESC'
    : 'jo.id DESC';
$latestPaymentProofStatusSelect = $jobOrdersHasPaymentProofStatus
    ? "(SELECT jo.payment_proof_status
        FROM job_orders jo
        WHERE jo.order_id = o.order_id
        ORDER BY {$jobOrdersPaymentOrderBy}
        LIMIT 1) as latest_payment_proof_status,"
    : "'' as latest_payment_proof_status,";
$latestJobPaymentStatusSelect = $jobOrdersHasPaymentStatus
    ? "(SELECT jo.payment_status
        FROM job_orders jo
        WHERE jo.order_id = o.order_id
        ORDER BY {$jobOrdersPaymentOrderBy}
        LIMIT 1) as latest_job_payment_status,"
    : "'' as latest_job_payment_status,";
$paymentRejectionReasonSelect = $jobOrdersHasPaymentRejectionReason
    ? "(SELECT jo.payment_rejection_reason
        FROM job_orders jo
        WHERE jo.order_id = o.order_id
          AND jo.payment_rejection_reason IS NOT NULL
          AND jo.payment_rejection_reason != ''
        ORDER BY {$jobOrdersPaymentOrderBy}
        LIMIT 1) as payment_rejection_reason,"
    : "'' as payment_rejection_reason,";

// Verify order belongs to this customer
$order_result = db_query("
    SELECT o.*, b.branch_name, b.address AS branch_address, b.contact_number AS branch_contact,
           c.first_name, c.last_name, c.email, c.contact_number AS customer_contact_number,
           {$paymentReferenceSelect}
           {$latestPaymentProofStatusSelect}
           {$latestJobPaymentStatusSelect}
           {$paymentRejectionReasonSelect}
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
    LEFT JOIN customers c ON c.customer_id = o.customer_id
    WHERE o.order_id = ? AND o.customer_id = ?
", 'ii', [$order_id, $customer_id]);

if (empty($order_result)) {
    customer_order_items_json(['error' => 'Order not found'], 404);
}
$order = $order_result[0];
$order['_safe_payment_reference'] = (string)($order['payment_reference'] ?? '');
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

$payment_method_display = customer_order_items_friendly_payment_method($order['payment_method'] ?? null, $payment_status);
$status_meta = customer_order_items_status_meta($order, $payment_status);

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
        $orphan_customization_details = printflow_overlay_nonempty_assoc($orphan_customization_details, $details);
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
    $customization_by_item_id[$orderItemId]['details'] = printflow_overlay_nonempty_assoc(
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
        printflow_overlay_nonempty_assoc($orphan_customization_details, $first_customization_payload),
        $first_customization_service_type !== ''
            ? $first_customization_service_type
            : trim((string)($orphan_customization_details['service_type'] ?? ''))
    );
    $first_item_customization = customer_orders_sanitize_generic_service_labels($first_item_customization);

    $order_type_normalized = strtolower(trim((string)($order['order_type'] ?? '')));
    $first_item_source_page = strtolower(trim((string)($first_item_customization['source_page'] ?? '')));
    $first_table_svc = trim((string)$first_customization_service_type);
    if (customer_orders_is_generic_item_name($first_table_svc)) {
        $first_table_svc = '';
    }
    $is_service_order =
        !empty($first_item_customization['service_type'])
        || $first_table_svc !== ''
        || (int)($first_item_customization['service_id'] ?? 0) > 0
        || in_array($first_item_source_page, ['services', 'service'], true)
        || (function_exists('printflow_order_item_has_service_marker') && printflow_order_item_has_service_marker($first_item_customization));
    if (!$is_service_order) {
        $is_service_order = $order_type_normalized === 'custom' && empty($first_item_customization['product_type']);
    }

    $display_status = (string)($order['status'] ?? '');
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
    SELECT oi.*, p.name as product_name, p.category,
           IFNULL(LENGTH(oi.design_image), 0) AS design_image_bytes
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.product_id
    WHERE oi.order_id = ?
    ORDER BY oi.order_item_id ASC
", 'i', [$order_id]);

$items = is_array($items) ? $items : [];
$job_orders_list = db_query(
    'SELECT job_title, service_type, width_ft, height_ft, notes, total_sqft
     FROM job_orders WHERE order_id = ? ORDER BY id ASC',
    'i',
    [$order_id]
) ?: [];

if (!$is_service_order) {
    foreach ($items as $probeItem) {
        $probeCustom = customer_order_items_decode_customization_payload((string)($probeItem['customization_data'] ?? ''));
        $probeSourcePage = strtolower(trim((string)($probeCustom['source_page'] ?? '')));
        if (
            !empty($probeCustom['service_type'])
            || (int)($probeCustom['service_id'] ?? 0) > 0
            || in_array($probeSourcePage, ['services', 'service'], true)
            || (function_exists('printflow_order_item_has_service_marker') && printflow_order_item_has_service_marker($probeCustom))
        ) {
            $is_service_order = true;
            break;
        }
    }
}
if (
    !$is_service_order
    && $order_type_normalized === 'product'
    && (int)($order['reference_id'] ?? 0) > 0
    && (
        !function_exists('customer_orders_custom_order_is_catalog_product')
        || !customer_orders_custom_order_is_catalog_product($first_item_customization)
    )
) {
    $is_service_order = true;
}

if (
    !$is_service_order
    && $order_type_normalized === 'custom'
    && $job_orders_list !== []
    && (
        !function_exists('customer_orders_custom_order_is_catalog_product')
        || !customer_orders_custom_order_is_catalog_product($first_item_customization)
    )
) {
    $is_service_order = true;
}

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
$session_restore_items = [];
$session_restore_entry = $_SESSION['pending_payment_cart_restore'][(string)$order_id] ?? null;
if (is_array($session_restore_entry) && !empty($session_restore_entry['items']) && is_array($session_restore_entry['items'])) {
    $session_restore_items = array_values(array_filter(
        $session_restore_entry['items'],
        static fn($row) => is_array($row)
    ));
}

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
    $orderItemId = (int)($item['order_item_id'] ?? 0);
    if ($orderItemId > 0 && !printflow_order_item_row_has_retrievable_design($item)) {
        $restoreItem = $session_restore_items[$lineIndex] ?? null;
        if (is_array($restoreItem) && !empty($restoreItem['design_tmp_path']) && is_file((string)$restoreItem['design_tmp_path'])) {
            $healedPath = printflow_persist_order_item_design_media(
                $orderItemId,
                null,
                (string)($restoreItem['design_mime'] ?? ''),
                (string)($restoreItem['design_name'] ?? 'design'),
                (string)$restoreItem['design_tmp_path']
            );
            if ($healedPath !== null) {
                $item['design_file'] = $healedPath;
                $item['design_image_bytes'] = max(1, (int)($item['design_image_bytes'] ?? 0));
            }
        } else {
            printflow_heal_order_item_design_from_payload($orderItemId);
            $healedRows = db_query(
                'SELECT design_file, IFNULL(LENGTH(design_image), 0) AS design_image_bytes
                 FROM order_items WHERE order_item_id = ? LIMIT 1',
                'i',
                [$orderItemId]
            ) ?: [];
            if (!empty($healedRows[0])) {
                $item['design_file'] = (string)($healedRows[0]['design_file'] ?? $item['design_file'] ?? '');
                $item['design_image_bytes'] = (int)($healedRows[0]['design_image_bytes'] ?? 0);
            }
        }
    }

    $custom_data = customer_order_items_decode_customization_payload((string)($item['customization_data'] ?? ''));
    $session_restore_custom = isset($session_restore_items[$lineIndex])
        ? customer_order_items_restore_customization_from_session($session_restore_items[$lineIndex])
        : [];
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
    $custom_data = customer_order_items_merge_customization_payload(
        $custom_data,
        $session_restore_custom,
        trim((string)($session_restore_custom['service_type'] ?? $fallbackSvc))
    );
    if (empty($custom_data['service_type']) && !empty($first_item_customization['service_type'])) {
        $custom_data['service_type'] = (string)$first_item_customization['service_type'];
    }
    $custom_data = customer_orders_sanitize_generic_service_labels($custom_data);

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
        // Align with orders that store full service_id + field configs (e.g. Plates via order_service_dynamic):
        // recover catalog id from reference_id, job rows, product label, or fuzzy service_type when JSON omits service_id.
        if (function_exists('printflow_resolve_service_catalog_service_id_for_order_line')) {
            $resolvedSid = printflow_resolve_service_catalog_service_id_for_order_line($custom_data, $order, $item);
            if ($resolvedSid > 0 && (int)($custom_data['service_id'] ?? 0) <= 0) {
                $custom_data['service_id'] = $resolvedSid;
            }
            if (trim((string)($custom_data['service_type'] ?? '')) === '') {
                $sidTmp = (int)($custom_data['service_id'] ?? 0);
                if ($sidTmp > 0 && function_exists('customer_orders_resolve_service_name_by_id')) {
                    $nm = customer_orders_resolve_service_name_by_id($sidTmp);
                    if ($nm !== '') {
                        $custom_data['service_type'] = $nm;
                    }
                }
            }
        }

        $sidForSort = (int)($custom_data['service_id'] ?? 0);
        if ($sidForSort <= 0) {
            $ot = strtolower(trim((string)($order['order_type'] ?? '')));
            if (in_array($ot, ['custom', 'product'], true)) {
                $sidForSort = (int)($order['reference_id'] ?? 0);
            }
        }
        if ($sidForSort <= 0) {
            $sidForSort = function_exists('printflow_resolve_service_catalog_service_id')
                ? printflow_resolve_service_catalog_service_id((string)($custom_data['service_type'] ?? ''))
                : 0;
        }
        $custom_data = customer_order_items_sort_customization_by_service_config($custom_data, $sidForSort);
    }

    if ($session_restore_custom !== [] && (int)($item['order_item_id'] ?? 0) > 0) {
        $healed_custom_json = printflow_encode_customization_payload($custom_data);
        $healed_order_item_id = (int)$item['order_item_id'];
        $hasSpecificationsColumn = function_exists('printflow_ensure_order_items_specifications_column')
            ? printflow_ensure_order_items_specifications_column()
            : false;
        db_execute(
            'UPDATE order_items SET customization_data = ? WHERE order_item_id = ?',
            'si',
            [$healed_custom_json, $healed_order_item_id]
        );
        if ($hasSpecificationsColumn) {
            db_execute(
                'UPDATE order_items SET specifications = ? WHERE order_item_id = ?',
                'si',
                [$healed_custom_json, $healed_order_item_id]
            );
        }
        db_execute(
            'UPDATE customizations SET customization_details = ?, updated_at = updated_at WHERE order_id = ? AND order_item_id = ?',
            'sii',
            [$healed_custom_json, $order_id, $healed_order_item_id]
        );
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
        ? printflow_flatten_customization_for_customer_order_modal($custom_data, $raw_quantity)
        : (function_exists('printflow_flatten_order_customization_for_customer_modal')
            ? printflow_flatten_order_customization_for_customer_modal($custom_data, $raw_quantity)
            : $custom_data);
    if (is_array($customForPayload) && isset($customForPayload['service_type'])) {
        $dn = strtolower(trim((string)$resolved_item_name));
        $stv = strtolower(trim((string)$customForPayload['service_type']));
        if ($dn !== '' && $stv === $dn) {
            unset($customForPayload['service_type']);
        }
    }

    $line_oid = (int)($item['order_item_id'] ?? 0);
    $designMeta = function_exists('getOrderDesignImage')
        ? getOrderDesignImage($item, ['order_id' => $order_id, 'heal' => true])
        : null;
    $has_own_design = is_array($designMeta) ? !empty($designMeta['exists']) : printflow_order_item_row_has_retrievable_design($item);
    $design_serve_id = $has_own_design
        ? $line_oid
        : (($line_oid === 0 && $any_design_order_item_id > 0) ? $any_design_order_item_id : 0);
    $has_design_thumb = $has_own_design && ($design_serve_id > 0 || !empty($designMeta['direct_url']));
    $design_url = null;
    if ($has_own_design && is_array($designMeta)) {
        $design_url = $designMeta['direct_url'] ?? $designMeta['serve_url'] ?? $designMeta['url'] ?? null;
    }
    if ($design_url === null && $has_design_thumb && $design_serve_id > 0) {
        $design_url = $base_path . '/public/serve_design.php?type=order_item&id=' . $design_serve_id;
    }

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
        'design_url'    => $design_url,
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
    $payload['_unit_price_raw'] = round($raw_unit_price, 2);
    $payload['_line_total_raw'] = round(($final_item_amount !== null && $final_item_amount > 0) ? $final_item_amount : $raw_subtotal, 2);
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

$receipt_available = in_array((string)($order['status'] ?? ''), ['Completed', 'To Rate', 'Rated'], true)
    && strcasecmp($payment_status, 'Paid') === 0;
$receipt_payload = $receipt_available
    ? customer_receipt_build_payload($order, $items_out, $payment_status)
    : null;

customer_order_items_json([
    'success'          => true,
    'message'          => 'Order details loaded.',
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
    'payment_method'   => $payment_method_display,
    'payment_proof_status' => $latest_payment_proof_status,
    'branch_name'      => $order['branch_name'] ?? 'Not Specified',
    'estimated_comp'   => $status_meta['value'],
    'estimated_comp_label' => $status_meta['label'],
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
    'receipt_available' => $receipt_available,
    'receipt'          => $receipt_payload,
    'order'            => [
        'id' => (int)$order['order_id'],
        'code' => printflow_format_order_code($order['order_id'], $order['order_sku'] ?? ''),
        'date' => format_datetime($order['order_date']),
        'status' => (string)$order['status'],
        'display_status' => (string)$display_status,
        'branch_name' => (string)($order['branch_name'] ?? 'Not Specified'),
        'estimated_completion' => $status_meta['value'],
        'estimated_completion_label' => $status_meta['label'],
        'notes' => (string)($order['notes'] ?? ''),
        'total_amount' => ($is_service_order && ($service_final_price_locked || $order_total_amount <= 0))
            ? 'To Be Discussed'
            : format_currency($order['total_amount']),
        'estimated_price' => $is_service_order
            ? ($estimated_order_amount > 0 ? format_currency($estimated_order_amount) : 'Pending')
            : null,
        'is_service_order' => $is_service_order,
    ],
    'payment'          => [
        'status' => $payment_status,
        'method' => $payment_method_display,
        'proof_status' => $latest_payment_proof_status,
        'rejection_reason' => $order['payment_rejection_reason'] ?? '',
    ],
    'csrf_token'       => generate_csrf_token()
]);
} catch (Throwable $e) {
    customer_order_items_log_error('Unhandled exception', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
        'order_id' => (int)($_GET['id'] ?? 0),
        'customer_id' => function_exists('get_user_id') ? (int)get_user_id() : 0,
    ]);
    customer_order_items_json([
        'success' => false,
        'error' => 'Server error while loading order details.',
        'message' => 'Server error while loading order details.'
    ], 500);
}
