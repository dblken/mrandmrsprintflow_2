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
    return is_array($decoded) ? $decoded : [];
}

function customer_order_items_merge_customization_payload(array $primary, array $fallback, string $fallbackServiceType = ''): array {
    // Keep explicit order_item values, fill the missing keys from customizations table.
    $merged = array_replace($fallback, $primary);
    if ($fallbackServiceType !== '' && empty($merged['service_type'])) {
        $merged['service_type'] = $fallbackServiceType;
    }
    return $merged;
}

function customer_order_items_is_generic_name(string $name): bool {
    $normalized = strtolower(trim((string)preg_replace('/\s+/', ' ', $name)));
    if ($normalized === '') {
        return true;
    }
    $generic = [
        'order item',
        'service order',
        'service item',
        'custom order',
        'customer order',
        'merchandise',
        'sticker pack',
        'pos service item',
        'pos-service item',
        'pos service',
        'pos-service',
    ];
    return in_array($normalized, $generic, true);
}

$base_path = defined('BASE_PATH') ? BASE_PATH : (function_exists('pf_app_base_path') ? pf_app_base_path() : '');

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
           (SELECT GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR '-') FROM order_items oi LEFT JOIN products p ON oi.product_id = p.product_id WHERE oi.order_id = o.order_id) as order_sku
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

$customization_rows = db_query(
    "SELECT customization_id, order_item_id, service_type, customization_details
     FROM customizations
     WHERE order_id = ?
     ORDER BY customization_id ASC",
    'i',
    [$order_id]
) ?: [];

$customization_by_item_id = [];
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
    if ($orderItemId > 0 && !isset($customization_by_item_id[$orderItemId])) {
        $customization_by_item_id[$orderItemId] = [
            'details' => $details,
            'service_type' => $serviceType,
        ];
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
    $first_customization_payload,
    $first_customization_service_type
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

$items_out = [];
$service_items_raw = [];
$order_total_amount = (float)($order['total_amount'] ?? 0);
$item_count = is_array($items) ? count($items) : 0;
foreach ($items as $item) {
    $custom_data = customer_order_items_decode_customization_payload((string)($item['customization_data'] ?? ''));
    $itemCustomizationFallback = $customization_by_item_id[(int)($item['order_item_id'] ?? 0)] ?? ['details' => [], 'service_type' => ''];
    $custom_data = customer_order_items_merge_customization_payload(
        $custom_data,
        (array)($itemCustomizationFallback['details'] ?? []),
        (string)($itemCustomizationFallback['service_type'] ?? '')
    );
    if (empty($custom_data['service_type']) && !empty($first_item_customization['service_type'])) {
        $custom_data['service_type'] = (string)$first_item_customization['service_type'];
    }
    unset($custom_data['design_upload'], $custom_data['reference_upload']);

    $raw_quantity = max(1, (int)($item['quantity'] ?? 0));
    $raw_unit_price = (float)($item['unit_price'] ?? 0);
    $raw_subtotal = $raw_quantity * $raw_unit_price;

    // Some single-item custom/service orders store the final amount only on orders.total_amount.
    if ($raw_subtotal <= 0 && $item_count === 1 && $order_total_amount > 0) {
        $raw_subtotal = $order_total_amount;
        $raw_unit_price = $order_total_amount / $raw_quantity;
    }

    $fallback_item_name = trim((string)($itemCustomizationFallback['service_type'] ?? ($first_item_customization['service_type'] ?? 'Order Item')));
    $resolved_item_name = printflow_resolve_order_item_name(
        $item['product_name'] ?? $fallback_item_name,
        $custom_data,
        $fallback_item_name !== '' ? $fallback_item_name : 'Order Item'
    );
    if (customer_order_items_is_generic_name($resolved_item_name) && $fallback_item_name !== '') {
        $resolved_item_name = normalize_service_name($fallback_item_name, $fallback_item_name);
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
        'customization' => $custom_data,
        'has_design'    => !empty($item['design_image']) || !empty($item['design_file']),
        'has_reference' => !empty($item['reference_image_file']),
        'design_kind'   => pf_asset_kind($item['design_image_mime'] ?? '', $item['design_file'] ?? ''),
        'reference_kind'=> pf_asset_kind('', $item['reference_image_file'] ?? ''),
        'design_url'    => (!empty($item['design_image']) || !empty($item['design_file']))
                            ? $base_path . '/public/serve_design.php?type=order_item&id=' . (int)$item['order_item_id']
                            : null,
        'reference_url' => !empty($item['reference_image_file'])
                            ? $base_path . '/public/serve_design.php?type=order_item&id=' . (int)$item['order_item_id'] . '&field=reference'
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
