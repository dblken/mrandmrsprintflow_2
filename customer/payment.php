<?php
/**
 * Customer Order Payment Page
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_ui_helper.php';
require_once __DIR__ . '/../includes/runtime_config.php';
require_once __DIR__ . '/../includes/payment_verification.php';
require_once __DIR__ . '/../includes/provider_payments.php';

require_role('Customer');
require_once __DIR__ . '/../includes/require_customer_profile_complete.php';

$order_id = (int)($_GET['order_id'] ?? 0);
$customer_id = get_user_id();
$is_job_order = false;
$restore_cart_requested = isset($_GET['restore_cart']) && $_GET['restore_cart'] === '1';

// Mark notification as read if parameter present
if (isset($_GET['mark_read'])) {
    $notification_id = (int)$_GET['mark_read'];
    db_execute("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND customer_id = ?", 'ii', [$notification_id, $customer_id]);
}

if (!$order_id) {
    die('<div style="text-align:center; padding: 50px; font-family: sans-serif;">
            <h2 style="color: #e11d48;">Invalid Order</h2>
            <p>The order ID is missing or invalid.</p>
            <a href="orders.php" style="color: #2563eb; text-decoration: none; font-weight: bold;">Back to My Orders</a>
         </div>');
}

function pf_payment_service_image_payload(int $service_id, int $order_id = 0): array {
    $service_id = (int)$service_id;
    if ($service_id <= 0) {
        return ['image' => '', 'name' => '', 'category' => ''];
    }

    $rows = db_query(
        "SELECT service_id, name, category, display_image, hero_image, image_path, updated_at
         FROM services
         WHERE service_id = ?
           AND LOWER(TRIM(COALESCE(status, ''))) <> 'archived'
         LIMIT 1",
        'i',
        [$service_id]
    ) ?: [];

    if (empty($rows)) {
        error_log("payment service image: order_id={$order_id} item_type=service service_id={$service_id} selected_image= final_url= reason=service_not_found");
        return ['image' => '', 'name' => '', 'category' => ''];
    }

    $service = $rows[0];
    $base_path = function_exists('pf_app_base_path') ? pf_app_base_path() : '';
    $default_img = rtrim($base_path, '/') . '/public/assets/images/services/default.png';
    $candidates = [];

    foreach (explode(',', (string)($service['display_image'] ?? '')) as $image) {
        $image = trim($image);
        if ($image !== '') {
            $candidates[] = $image;
        }
    }
    foreach (['hero_image', 'image_path'] as $field) {
        $image = trim((string)($service[$field] ?? ''));
        if ($image !== '') {
            $candidates[] = $image;
        }
    }

    $selected = '';
    $selected_raw = '';
    foreach ($candidates as $candidate) {
        if (function_exists('printflow_is_video_media_path') && printflow_is_video_media_path((string)$candidate)) {
            continue;
        }

        $url = function_exists('pf_normalize_service_image_path')
            ? pf_normalize_service_image_path((string)$candidate, $base_path, $default_img)
            : (string)$candidate;

        if ($url !== '' && (!function_exists('printflow_notification_local_media_exists') || printflow_notification_local_media_exists($url))) {
            $selected = $url;
            $selected_raw = (string)$candidate;
            break;
        }
    }

    error_log(
        "payment service image: order_id={$order_id} item_type=service service_id={$service_id} selected_image={$selected_raw} final_url={$selected}"
    );

    return [
        'image' => $selected,
        'name' => (string)($service['name'] ?? ''),
        'category' => (string)($service['category'] ?? ''),
    ];
}

if (!function_exists('pf_payment_default_service_image_url')) {
    function pf_payment_default_service_image_url(): string {
        $base_path = function_exists('pf_app_base_path') ? pf_app_base_path() : '';
        return rtrim((string)$base_path, '/') . '/public/assets/images/services/default.png';
    }
}

if (!function_exists('pf_payment_is_default_service_image')) {
    function pf_payment_is_default_service_image(?string $url): bool {
        $url = trim((string)$url);
        if ($url === '') {
            return true;
        }
        return strcasecmp($url, pf_payment_default_service_image_url()) === 0;
    }
}

if (!function_exists('pf_payment_resolve_service_preview_image')) {
    function pf_payment_resolve_service_preview_image(array $order, array $item, array $custom, int $resolvedServiceId = 0): string {
        $default_img = pf_payment_default_service_image_url();

        if ($resolvedServiceId > 0) {
            $payload = pf_payment_service_image_payload($resolvedServiceId, (int)($order['order_id'] ?? 0));
            $image = trim((string)($payload['image'] ?? ''));
            if ($image !== '' && !pf_payment_is_default_service_image($image)) {
                return $image;
            }
        }

        $nameHints = array_values(array_unique(array_filter([
            trim((string)($custom['service_type'] ?? '')),
            trim((string)($item['product_name'] ?? '')),
            trim((string)($order['first_job_title'] ?? '')),
            trim((string)($order['first_job_service_type'] ?? '')),
        ], static fn($value) => trim((string)$value) !== '')));

        foreach ($nameHints as $hint) {
            if (function_exists('printflow_notification_service_image_from_name')) {
                $from_name = trim((string)printflow_notification_service_image_from_name((string)$hint));
                if ($from_name !== '' && !pf_payment_is_default_service_image($from_name)) {
                    return $from_name;
                }
            }

            if (function_exists('get_service_image_url')) {
                $fallback = trim((string)get_service_image_url((string)$hint));
                if ($fallback !== '' && !pf_payment_is_default_service_image($fallback)) {
                    return $fallback;
                }
            }
        }

        if (function_exists('printflow_order_list_thumbnail_url')) {
            $order_like = $order;
            $order_like['first_job_title'] = $order['first_job_title'] ?? '';
            $order_like['first_job_service_type'] = $order['first_job_service_type'] ?? '';
            $thumb = trim((string)printflow_order_list_thumbnail_url($order_like, (string)($item['product_name'] ?? '')));
            if ($thumb !== '' && !pf_payment_is_default_service_image($thumb)) {
                return $thumb;
            }
        }

        return '';
    }
}

if (!function_exists('printflow_resolve_order_service_catalog_image_url')) {
    function printflow_resolve_order_service_catalog_image_url(array $item, string $displayName = ''): string {
        foreach (['service_image', 'catalog_service_image'] as $field) {
            $url = trim((string)($item[$field] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }

        $service_id = (int)($item['service_id'] ?? 0);
        if ($service_id <= 0) {
            $custom = printflow_decode_modal_customization_payload($item['customization_data'] ?? '');
            if (is_array($custom)) {
                $service_id = (int)($custom['service_id'] ?? 0);
            }
        }

        if ($service_id > 0) {
            $payload = pf_payment_service_image_payload($service_id, (int)($item['order_id'] ?? 0));
            return (string)($payload['image'] ?? '');
        }

        return '';
    }
}

// 1. First check regular orders
$order_result = db_query("
    SELECT * FROM orders 
    WHERE order_id = ? AND customer_id = ?
", 'ii', [$order_id, $customer_id]);

if (!empty($order_result)) {
    $order = $order_result[0];
    $items = [];
    $latest_payment_review = db_query("
        SELECT payment_status, payment_proof_status, payment_rejection_reason
        FROM job_orders
        WHERE order_id = ? AND customer_id = ?
        ORDER BY id DESC
        LIMIT 1
    ", 'ii', [$order_id, $customer_id]);
    $latest_payment_review = !empty($latest_payment_review) ? $latest_payment_review[0] : null;
    
    // Get order items
    $has_product_image = !empty(db_query("SHOW COLUMNS FROM products LIKE 'product_image'"));
    $has_photo_path = !empty(db_query("SHOW COLUMNS FROM products LIKE 'photo_path'"));
    $product_image_select = "'' AS product_image";
    if ($has_product_image && $has_photo_path) {
        $product_image_select = "COALESCE(p.photo_path, p.product_image) AS product_image";
    } elseif ($has_product_image) {
        $product_image_select = "p.product_image AS product_image";
    } elseif ($has_photo_path) {
        $product_image_select = "p.photo_path AS product_image";
    }

    $items = db_query("
        SELECT oi.*,
               p.name AS product_name,
               p.category,
               {$product_image_select}
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.product_id
        WHERE oi.order_id = ?
    ", 'i', [$order_id]);

    foreach ($items as &$item) {
        $custom = printflow_decode_modal_customization_payload($item['customization_data'] ?? '');
        if (!is_array($custom)) {
            $custom = [];
        }

        $identity = printflow_resolve_order_line_identity($order, $item, $custom);
        $looksServiceLike = $identity['item_type'] === 'service';
        $resolvedServiceId = (int)$identity['service_id'];

        if ($resolvedServiceId > 0 && $looksServiceLike) {
            $serviceMedia = pf_payment_service_image_payload($resolvedServiceId, $order_id);
            $item['service_id'] = $resolvedServiceId;
            $item['type'] = 'Service';
            $item['source_page'] = 'services';

            if (empty($custom['service_id'])) {
                $custom['service_id'] = $resolvedServiceId;
            }
            if (empty($custom['source_page'])) {
                $custom['source_page'] = 'services';
            }

            $resolvedServiceName = trim((string)($serviceMedia['name'] ?? $identity['display_name'] ?? ''));
            if ($resolvedServiceName === '' && function_exists('customer_orders_resolve_service_name_by_id')) {
                $resolvedServiceName = trim((string)customer_orders_resolve_service_name_by_id($resolvedServiceId));
            }
            if ($resolvedServiceName !== '') {
                $custom['service_type'] = $resolvedServiceName;
                $item['product_name'] = $resolvedServiceName;
            }

            if (!empty($serviceMedia['category'])) {
                $item['category'] = $serviceMedia['category'];
            }
            $servicePreviewImage = trim((string)($serviceMedia['image'] ?? ''));
            if ($servicePreviewImage === '' || pf_payment_is_default_service_image($servicePreviewImage)) {
                $servicePreviewImage = pf_payment_resolve_service_preview_image($order, $item, $custom, $resolvedServiceId);
            }
            if ($servicePreviewImage !== '') {
                $item['service_image'] = $servicePreviewImage;
                $item['catalog_service_image'] = $servicePreviewImage;
            }

            // Service lines must not inherit a similarly-numbered product thumbnail.
            $item['product_image'] = '';
            $item['customization_data'] = printflow_encode_customization_payload($custom);
        } elseif ($looksServiceLike) {
            $item['type'] = 'Service';
            $item['source_page'] = 'services';
            $item['product_name'] = (string)$identity['display_name'];
            if (trim((string)($custom['service_type'] ?? '')) === '') {
                $custom['service_type'] = (string)$identity['display_name'];
            }
            $servicePreviewImage = pf_payment_resolve_service_preview_image($order, $item, $custom, 0);
            if ($servicePreviewImage !== '') {
                $item['service_image'] = $servicePreviewImage;
                $item['catalog_service_image'] = $servicePreviewImage;
            }
            $item['product_image'] = '';
            $item['customization_data'] = printflow_encode_customization_payload($custom);
        }
    }
    unset($item);
    
    // The staff-approved order total is the payment authority. Item prices are
    // display mirrors only and must not override the immutable amount charged.
    $calculated_total = 0;
    foreach ($items as $item) {
        $calculated_total += (float)$item['unit_price'] * (int)$item['quantity'];
    }

    $total_amount = (float)($order['total_amount'] ?? 0);
    if ($total_amount <= 0) {
        $total_amount = $calculated_total;
    }

    // If items have zero unit_price but the order has a staff-set total_amount,
    // distribute that total across items in-memory so the item cards display correctly.
    // This handles existing orders where price was set before the order_items sync fix.
    if ($total_amount > 0 && !empty($items)
        && ($calculated_total <= 0 || abs($calculated_total - $total_amount) > 0.009)) {
        $_total_qty = array_sum(array_column($items, 'quantity'));
        if ($_total_qty > 0) {
            $_remaining = $total_amount;
            $_count     = count($items);
            foreach ($items as $_idx => &$_item) {
                $_is_last   = ($_idx === $_count - 1);
                $_item_tot  = $_is_last ? $_remaining : round($total_amount * $_item['quantity'] / $_total_qty, 2);
                $_item['unit_price'] = ($_item['quantity'] > 0) ? round($_item_tot / $_item['quantity'], 4) : 0;
                $_item['approved_total'] = $_item_tot;
                $_item['price_is_final'] = true;
                $_remaining -= $_item_tot;
            }
            unset($_item);
        }
    }
    $payment_status = $order['payment_status']; // 'Paid', 'Unpaid'
    $order_status = $order['status'];
    $payment_proof_status = (string)($latest_payment_review['payment_proof_status'] ?? '');
    $payment_rejection_reason = trim((string)($latest_payment_review['payment_rejection_reason'] ?? ''));
    $is_rejected_payment = (strcasecmp($order_status, 'Rejected') === 0) || (strcasecmp($payment_proof_status, 'REJECTED') === 0);
    $is_paid_ui = !$is_rejected_payment && strcasecmp((string)$payment_status, 'Paid') === 0;
    $is_verifying_payment = !$is_rejected_payment && (
        in_array($order_status, ['Downpayment Submitted', 'To Verify'], true) ||
        strcasecmp($payment_proof_status, 'SUBMITTED') === 0
    );
    $show_payment_form = !$is_paid_ui && !$is_verifying_payment && !in_array($order_status, ['Cancelled'], true);
    
} else {
    // 2. Fallback to job orders
    $job_result = db_query("
        SELECT * FROM job_orders 
        WHERE id = ? AND customer_id = ?
    ", 'ii', [$order_id, $customer_id]);
    
    if (empty($job_result)) {
        die('<div style="text-align:center; padding: 50px; font-family: sans-serif;">
                <h2 style="color: #e11d48;">Order Not Found</h2>
                <p>The requested order was not found or you do not have permission to view it.</p>
                <a href="orders.php" style="color: #2563eb; text-decoration: none; font-weight: bold;">Back to My Orders</a>
             </div>');
    }
    
    $order = $job_result[0];
    $is_job_order = true;
    $total_amount = (float)$order['estimated_total'];
    $payment_status = $order['payment_status']; // 'PAID', 'UNPAID', 'PARTIAL'
    $order_status = $order['status'];
    $payment_proof_status = (string)($order['payment_proof_status'] ?? '');
    $payment_rejection_reason = trim((string)($order['payment_rejection_reason'] ?? ''));
    
    // Normalize status names for consistent UI
    if ($payment_status === 'PAID') $payment_status = 'Paid';
    if ($payment_status === 'UNPAID') $payment_status = 'Unpaid';
    
    $is_rejected_payment = strcasecmp($payment_proof_status, 'REJECTED') === 0 || strcasecmp($order_status, 'REJECTED') === 0;
    $is_paid_ui = !$is_rejected_payment && $order['payment_status'] === 'PAID';
    $is_verifying_payment = !$is_rejected_payment && $order['payment_proof_status'] === 'SUBMITTED';
    $show_payment_form = !$is_paid_ui && !$is_verifying_payment && $order_status !== 'CANCELLED';
}

if (!isset($items) || !is_array($items)) {
    $items = [];
}

$payment_rejection_reason = $payment_rejection_reason ?? '';
$payment_proof_status = $payment_proof_status ?? '';
$is_rejected_payment = $is_rejected_payment ?? false;
$is_paid_ui = $is_paid_ui ?? false;
$is_verifying_payment = $is_verifying_payment ?? false;
$payment_verification_summary = payment_verification_customer_summary(
    $customer_id,
    $is_job_order ? (int)($order['order_id'] ?? 0) : $order_id,
    $is_job_order ? $order_id : 0
);
$manual_payment_enabled = printflow_manual_online_payment_enabled();
$paymongo_online_enabled = printflow_paymongo_online_payment_enabled();
$paymongo_subject_type = $is_job_order ? 'job_order' : 'order';
$paymongo_payment = printflow_provider_payment_for_customer(
    $customer_id,
    $paymongo_subject_type,
    $order_id
);
if (!empty($paymongo_payment['id'])
    && (($paymongo_payment['status'] ?? '') === 'paid'
        || ($paymongo_online_enabled && ($paymongo_payment['status'] ?? '') === 'awaiting_payment'))
    && printflow_provider_payment_claim_reconciliation((int)$paymongo_payment['id'], 5)) {
    // Provider GET verification is the return-page fallback. No browser
    // parameter can mark the payment paid.
    printflow_provider_payment_reconcile($paymongo_payment);
    $paymongo_payment = printflow_provider_payment_for_customer(
        $customer_id,
        $paymongo_subject_type,
        $order_id
    );
}

if (($paymongo_payment['status'] ?? '') === 'paid') {
    $is_paid_ui = true;
    $show_payment_form = false;
}

// Re-read state after reconciliation so the first returned page already shows
// Paid / Awaiting Production, without waiting for a webhook or another refresh.
if (!$is_job_order) {
    $freshOrder = db_query(
        'SELECT * FROM orders WHERE order_id = ? AND customer_id = ? LIMIT 1',
        'ii',
        [$order_id, $customer_id]
    ) ?: [];
    if (!empty($freshOrder)) {
        $order = $freshOrder[0];
        $payment_status = (string)($order['payment_status'] ?? $payment_status);
        $order_status = (string)($order['status'] ?? $order_status);
        $total_amount = (float)($order['total_amount'] ?? $total_amount);
    }
} else {
    $freshOrder = db_query(
        'SELECT * FROM job_orders WHERE id = ? AND customer_id = ? LIMIT 1',
        'ii',
        [$order_id, $customer_id]
    ) ?: [];
    if (!empty($freshOrder)) {
        $order = $freshOrder[0];
        $payment_status = strcasecmp((string)($order['payment_status'] ?? ''), 'PAID') === 0 ? 'Paid' : 'Unpaid';
        $order_status = (string)($order['status'] ?? $order_status);
        $total_amount = (float)($order['estimated_total'] ?? $total_amount);
    }
}

$financial_snapshot = printflow_order_financial_snapshot(
    $order,
    (($paymongo_payment['status'] ?? '') === 'paid' || $paymongo_online_enabled) ? $paymongo_payment : []
);
$paymongo_public = !empty($paymongo_payment)
    ? printflow_provider_payment_public($paymongo_payment)
    : [];
$paymongo_mode = in_array((string)($paymongo_payment['mode'] ?? ''), ['test', 'live'], true)
    ? (string)$paymongo_payment['mode']
    : printflow_paymongo_mode();
$paymongo_available = $paymongo_online_enabled
    && in_array($paymongo_mode, ['test', 'live'], true)
    && printflow_paymongo_secret_key_for_mode($paymongo_mode) !== '';
$paymongo_direct_methods = $paymongo_available
    ? printflow_paymongo_enabled_methods($paymongo_mode)
    : [];
$paymongo_qrph_available = in_array('qrph', $paymongo_direct_methods, true);
$customer_paymongo_csrf = generate_csrf_token();
if (!$is_job_order && !empty($items) && !empty($financial_snapshot['price_is_final'])) {
    $remainingDisplayAmount = $financial_snapshot['amount_due_centavos'] / 100;
    $totalQty = max(1, array_sum(array_map(static fn($line): int => max(1, (int)($line['quantity'] ?? 1)), $items)));
    $lastIndex = array_key_last($items);
    foreach ($items as $index => &$line) {
        $lineTotal = $index === $lastIndex
            ? $remainingDisplayAmount
            : round(($financial_snapshot['amount_due_centavos'] / 100) * max(1, (int)$line['quantity']) / $totalQty, 2);
        $line['approved_total'] = max(0, $lineTotal);
        $line['price_is_final'] = true;
        $line['unit_price'] = max(0, $lineTotal) / max(1, (int)$line['quantity']);
        $remainingDisplayAmount -= $lineTotal;
    }
    unset($line);
}

if ($restore_cart_requested) {
    $restore_entry = $_SESSION['pending_payment_cart_restore'][(string)$order_id] ?? null;
    $restore_items = is_array($restore_entry) ? ($restore_entry['items'] ?? null) : null;

    if (is_array($restore_items) && !$is_paid_ui && !$is_verifying_payment) {
        if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }

        foreach ($restore_items as $cart_key => $cart_item) {
            if (!is_array($cart_item)) {
                continue;
            }
            $cart_item['selected'] = true;
            $_SESSION['cart'][(string)$cart_key] = $cart_item;
        }

        $_SESSION['last_order_item_key'] = implode(',', array_keys($restore_items));
        unset($_SESSION['pending_payment_cart_restore'][(string)$order_id]);
        sync_cart_to_db($customer_id);
    }

    header('Location: cart.php');
    exit;
}

$page_title = "Payment - Order #{$order_id}";
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';

if (!function_exists('pf_payment_qr_url')) {
    function pf_payment_qr_url($file): string {
        $file = trim((string)$file);
        if ($file === '') {
            return '';
        }

        $base_path = function_exists('pf_app_base_path')
            ? rtrim((string)pf_app_base_path(), '/')
            : (defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '');

        $file = str_replace('\\', '/', $file);
        if (preg_match('#^https?://#i', $file)) {
            $parts = parse_url($file);
            if (empty($parts['path'])) {
                return $file;
            }
            $file = $parts['path'];
        }

        $marker = '/public/assets/uploads/qr/';
        $pos = strpos($file, $marker);
        if ($pos !== false) {
            $file = substr($file, $pos + strlen($marker));
        }

        $file = basename($file);
        return ($base_path !== '' ? $base_path : '') . '/public/assets/uploads/qr/' . rawurlencode($file);
    }
}
?>

<style>
    /* === PAYMENT PAGE — WIDE TWO-COLUMN LAYOUT === */
    .payment-container {
        width: min(1100px, calc(100vw - 2rem)) !important;
        max-width: 1100px !important;
        margin: 0 auto !important;
        padding: 2rem 0 4rem !important;
    }
    .payment-layout {
        display: grid;
        grid-template-columns: 1fr 420px;
        gap: 1.5rem;
        align-items: start;
    }
    @media (max-width: 900px) {
        .payment-layout { grid-template-columns: 1fr; }
        .payment-sidebar { order: -1; }
    }
    .payment-card {
        background: #ffffff !important;
        border: 1px solid #e5e7eb !important;
        border-radius: 4px !important;
        box-shadow: 0 1px 1px 0 rgba(0,0,0,.05);
        overflow: hidden;
        margin-bottom: 1.25rem;
        backdrop-filter: none;
    }
    /* Fix all dark section titles → white */
    .payment-section-title {
        font-size: 0.95rem;
        font-weight: 800;
        color: #eaf6fb !important;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 8px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    /* Fix input label → visible light */
    .input-label {
        display: block;
        font-size: 0.82rem;
        font-weight: 700;
        color: #9fc4d4 !important;
        margin-bottom: 0.6rem;
        letter-spacing: 0.02em;
        text-transform: uppercase;
    }
    .amount-badge {
        background: linear-gradient(135deg, rgba(83,197,224,0.12), rgba(50,161,196,0.05));
        border: none;
        color: #eaf6fb;
        padding: 1.5rem 1.25rem;
        border-radius: 0;
        text-align: center;
        margin-bottom: 1.5rem;
    }
    .amount-label {
        font-size: 0.75rem;
        font-weight: 700;
        color: #9fc4d4;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        margin-bottom: 0.5rem;
    }
    .amount-value {
        font-size: 2.5rem;
        font-weight: 900;
        color: #53c5e0;
        letter-spacing: -0.02em;
    }
    .pm-tab-btn {
        flex: 1;
        padding: 12px;
        border-radius: 0;
        border: none;
        background: rgba(0,28,36,0.7);
        color: #9fc4d4;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.25s;
        text-align: center;
        font-size: 0.875rem;
    }
    .pm-tab-btn.active {
        background: #53c5e0;
        color: #001820;
        box-shadow: 0 2px 8px rgba(83,197,224,0.35);
    }
    .input-group { margin-bottom: 1.5rem; }
    .custom-input {
        width: 100%;
        padding: 12px 16px;
        background: rgba(0,49,61,0.6);
        border: none;
        border-radius: 0;
        font-weight: 600;
        color: #e0f2fe;
        transition: all 0.25s;
        font-size: 1rem;
    }
    .custom-input:focus {
        outline: none;
        background: rgba(0,49,61,0.8);
        box-shadow: 0 0 0 3px rgba(83,197,224,0.12);
    }
    .dropzone {
        border: 2px dashed rgba(83,197,224,0.35);
        border-radius: 0;
        padding: 2rem 1.25rem;
        text-align: center;
        cursor: pointer;
        transition: all 0.25s;
        background: rgba(0,28,36,0.5);
    }
    .dropzone:hover {
        border-color: #53c5e0;
        background: rgba(83,197,224,0.06);
    }
    /* Fix dark dropzone text → white */
    .dropzone .dz-title { font-weight: 700; color: #eaf6fb !important; font-size: 0.9rem; }
    .dropzone .dz-sub   { font-size: 0.78rem; color: #9fc4d4 !important; }
    /* Fix Items heading */
    .items-heading { font-size: 0.88rem; font-weight: 800; color: #eaf6fb !important; }
    /* Show More btn */
    .show-more-btn {
        width: 100%;
        padding: 0.65rem;
        background: rgba(83,197,224,0.08);
        border: none;
        border-radius: 0;
        color: #53c5e0 !important;
        font-weight: 700;
        font-size: 0.875rem;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        margin-bottom: 1.25rem;
    }
    .show-more-btn:hover { background: rgba(83,197,224,0.15); }
    .show-more-btn svg   { transition: transform 0.3s; }
    .show-more-btn.expanded svg { transform: rotate(180deg); }
    .items-hidden { display: none; }
    .payment-page-shell {
        background: #ffffff;
        color: #0f172a;
    }
    .payment-page-shell .payment-topbar a { color: #374151 !important; }
    .payment-page-shell .payment-topbar a:hover { color: #111827 !important; }
    .payment-page-shell .payment-topbar h1 { color: #111827 !important; }
    .payment-page-shell .payment-card,
    .payment-page-shell .payment-card [style*="background: linear-gradient"],
    .payment-page-shell .payment-card [style*="background: rgba"],
    .payment-page-shell .payment-card [style*="background:#0a2530"],
    .payment-page-shell .payment-card [style*="background: #0a2530"] {
        background: #ffffff !important;
        box-shadow: none !important;
    }
    .payment-page-shell .payment-section-title,
    .payment-page-shell .items-heading,
    .payment-page-shell .dropzone .dz-title,
    .payment-page-shell .payment-card h2,
    .payment-page-shell .payment-card h3,
    .payment-page-shell .payment-card h4,
    .payment-page-shell .payment-card [style*="color: #eaf6fb"],
    .payment-page-shell .payment-card [style*="color:#eaf6fb"],
    .payment-page-shell .payment-card [style*="color: #ffffff"],
    .payment-page-shell .payment-card [style*="color:#ffffff"] {
        color: #111827 !important;
    }
    .payment-page-shell .input-label,
    .payment-page-shell .dropzone .dz-sub,
    .payment-page-shell .payment-card [style*="color: #9fc4d4"],
    .payment-page-shell .payment-card [style*="color:#9fc4d4"],
    .payment-page-shell .payment-card [style*="color: #64748b"],
    .payment-page-shell .payment-card [style*="color:#64748b"] {
        color: #64748b !important;
    }
    .payment-page-shell .payment-card [style*="color: #53c5e0"],
    .payment-page-shell .payment-card [style*="color:#53c5e0"] {
        color: #00232b !important;
    }
    .payment-page-shell .pm-tab-btn {
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        color: #374151;
        border-radius: 4px;
    }
    .payment-page-shell .pm-tab-btn.active {
        background: #00232b;
        border-color: #00232b;
        color: #ffffff;
        box-shadow: 0 2px 8px rgba(0,35,43,0.18);
    }
    .payment-page-shell .dropzone {
        background: #f8fafc;
        border-color: #cbd5e1;
        border-radius: 4px;
    }
    .payment-page-shell .dropzone:hover {
        background: #f0f9ff;
        border-color: #53c5e0;
    }
    .payment-page-shell .show-more-btn {
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 4px;
        color: #00232b !important;
    }
    .paymongo-card { padding:1.4rem;margin-bottom:1.25rem;border:1px solid #dbe5e8;border-radius:14px;background:#fff;box-shadow:0 10px 28px rgba(0,35,43,.08);color:#172b32; }
    .paymongo-eyebrow { color:#0f766e;font-size:.7rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase; }
    .paymongo-title { margin:.35rem 0 0;color:#102f38;font-size:1.08rem;font-weight:850; }
    .paymongo-amount-wrap { margin:1.15rem 0;padding:1rem;border-radius:10px;background:#f1f8f8; }
    .paymongo-label { color:#64748b;font-size:.72rem;font-weight:750;letter-spacing:.04em;text-transform:uppercase; }
    .paymongo-amount { margin-top:.15rem;color:#082f3a;font-size:1.8rem;font-weight:900;letter-spacing:-.025em; }
    .paymongo-options { display:grid;grid-template-columns:1fr 1fr;gap:.75rem; }
    .paymongo-option { position:relative;min-height:116px;padding:.9rem;border:1px solid #cbdde1;border-radius:10px;background:#fff;color:#17343d;text-align:left;cursor:pointer;transition:border-color .18s,background-color .18s,box-shadow .18s,transform .18s; }
    .paymongo-option:hover { border-color:#12818a;box-shadow:0 5px 16px rgba(15,118,110,.1);transform:translateY(-1px); }
    .paymongo-option:disabled { opacity:.58;cursor:wait;transform:none; }
    .paymongo-option.is-selected { border-color:#075e68;background:#eaf8f7;box-shadow:0 0 0 2px rgba(15,118,110,.13); }
    .paymongo-option strong { display:block;color:#082f3a;font-size:.84rem;letter-spacing:.035em; }
    .paymongo-option span { display:block;margin-top:.45rem;color:#5b6f76;font-size:.76rem;line-height:1.45; }
    .paymongo-recommended { display:inline-flex !important;width:auto;margin:0 0 .55rem !important;padding:.18rem .45rem;border-radius:999px;background:#dff5f2;color:#0f766e !important;font-size:.62rem !important;font-weight:800;text-transform:uppercase; }
    .paymongo-qr-card { margin-top:1rem;padding:1.15rem;border:1px solid #dbe5e8;border-radius:12px;background:#fbfefe;text-align:center; }
    .paymongo-qr-card img { width:min(248px,100%);padding:8px;border:1px solid #e2e8f0;border-radius:8px; }
    .paymongo-state { margin-top:.85rem;padding:.7rem .8rem;border-radius:8px;background:#f1f5f9;color:#43565d;font-size:.8rem;line-height:1.45;text-align:center; }
    .paymongo-actions { display:flex;justify-content:center;flex-wrap:wrap;gap:.65rem;margin-top:.85rem; }
    .paymongo-action { display:inline-flex;min-height:42px;align-items:center;justify-content:center;width:auto;padding:.65rem 1rem;border-radius:9px;font-size:.8rem;font-weight:800;text-decoration:none; }
    .paymongo-action-primary { border:1px solid #063b47;background:#063b47;color:#fff; }
    .paymongo-action-secondary { border:1px solid #a9c7cc;background:#fff;color:#0a5962; }
    .payment-confirmed-card { padding:1.5rem;color:#172b32;text-align:center; }
    .payment-confirmed-card .paid-total { margin:.65rem 0 1.15rem;color:#08756d;font-size:1.65rem;font-weight:900; }
    .payment-detail-grid { display:grid;grid-template-columns:1fr 1fr;gap:.7rem;text-align:left; }
    .payment-detail { min-width:0;padding:.8rem;border:1px solid #e2e8f0;border-radius:9px;background:#f8fafc; }
    .payment-detail-value { display:block;margin-top:.18rem;color:#142f38;font-size:.9rem;font-weight:800;overflow-wrap:anywhere; }
    .payment-confirmed-actions { display:flex;justify-content:center;align-items:center;flex-wrap:wrap;gap:.65rem;margin-top:1.15rem; }
    .payment-status-badge { display:inline-flex;min-height:42px;align-items:center;padding:.6rem 1rem;border-radius:9px;background:#e8f6f1;color:#136b55;font-size:.8rem;font-weight:800; }
    /* Compact specs in item card */
    .order-spec-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
        gap: 0.6rem;
    }
    @media (max-width: 640px) {
        h1 { font-size: 1rem !important; }
        .payment-card { margin-bottom: 0.5rem !important; }
        .payment-container { width: calc(100vw - 1.25rem) !important; padding: 1.25rem 0 2.5rem !important; }
        .payment-layout { gap: 1rem; }
        .payment-topbar { flex-direction: column; gap: 0.75rem; align-items: flex-start !important; }
        .payment-topbar h1 { position: static !important; transform: none !important; }
        .pm-tab-btn { font-size: 0.78rem; padding: 10px; }
        #pm-details-container { padding: 1.1rem !important; margin-bottom: 1.25rem !important; }
        #pm-details-container img { width: 140px !important; height: 140px !important; }
        .dropzone { padding: 1.25rem 1rem; }
        .dropzone .dz-title { font-size: 0.85rem; }
        .dropzone .dz-sub { font-size: 0.72rem; }
        #submitBtn { font-size: 0.82rem; padding: 0.7rem; }
        #submitError { font-size: 0.75rem; }
        .order-item-header { flex-direction: column !important; align-items: stretch !important; }
        .order-item-image { width: 100% !important; height: auto !important; aspect-ratio: 1 / 1; }
        .order-item-image img { width: 100% !important; height: 100% !important; object-fit: cover; }
        .paymongo-options, .payment-detail-grid { grid-template-columns:1fr; }
        .paymongo-card, .payment-confirmed-card { padding:1.05rem; }
        .paymongo-action { flex:1 1 auto; }
    }
</style>

<div class="min-h-screen payment-page-shell">
    <div class="payment-container">
            
            <div class="payment-topbar" style="display: flex; align-items: center; justify-content: space-between; position: relative; margin-bottom: 2rem;">
                <?php 
                $back_url = 'orders.php';
                if (!$is_job_order) {
                    $back_url = 'payment.php?order_id=' . $order_id . '&restore_cart=1';
                }
                ?>
                <a href="<?php echo $back_url; ?>" style="text-decoration: none; display: flex; align-items: center; gap: 4px; color: #9fc4d4; font-weight: 600; transition: color 0.2s;" onmouseover="this.style.color='#53c5e0'" onmouseout="this.style.color='#9fc4d4'">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Back
                </a>
                <h1 class="text-2xl font-bold" style="margin: 0; position: absolute; left: 50%; transform: translateX(-50%); color: #eaf6fb;">Complete Payment</h1>
            </div>
            
            <!-- TWO COLUMN LAYOUT -->
            <div class="payment-layout">

                <!-- LEFT: Order Summary -->
                <div class="payment-main">
                <div class="payment-card p-6">
                <!-- Grand Total -->
                <div style="background: linear-gradient(135deg, #0f3340, #0a2530); border: none; border-radius: 0; padding: 1.25rem; margin-bottom: 1.5rem; box-shadow: 0 4px 15px rgba(0,0,0,0.25); text-align: center;">
                    <span style="font-size: 0.78rem; font-weight: 700; color: #9fc4d4; text-transform: uppercase; letter-spacing: 0.1em; display: block; margin-bottom: 0.4rem;">Order Total Amount</span>
                    <span style="font-size: 2.25rem; font-weight: 900; color: #53c5e0; letter-spacing: -0.01em;">₱ <?php echo number_format($total_amount, 2); ?></span>
                </div>

                <div style="margin-bottom: 1.5rem;">
                    <?php if (!$is_job_order): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                            <h3 class="items-heading">Items (<?php echo count($items); ?>)</h3>
                        </div>
                        <?php 
                        $item_index = 0;
                        foreach ($items as $item): 
                            $item_index++;
                            $is_hidden = ($item_index > 3);
                        ?>
                            <div class="<?php echo $is_hidden ? 'items-hidden' : ''; ?>" style="margin-bottom: 0.75rem; border-bottom: 1px solid rgba(83,197,224,0.12); padding-bottom: 0.75rem; <?php echo ($item_index === count($items)) ? 'border-bottom: none;' : ''; ?>">
                                <?php render_order_item_clean($item, false, true); ?>
                            </div>
                        <?php endforeach; ?>

                        <?php if (count($items) > 3): ?>
                        <button type="button" class="show-more-btn" onclick="toggleItems(this)" style="margin-bottom: 1rem;">
                            <span class="show-more-text">Show All <?php echo count($items); ?> Items</span>
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <?php endif; ?>
                    <?php else: ?>
                            <!-- Job Order item style -->
                            <!-- Job Order item style (Matches the new dark renderer) -->
                            <div style="background: #0a2530; padding: 0; overflow: hidden; border: none; border-radius: 0; margin-bottom: 1.25rem; box-shadow: 0 10px 25px rgba(0,0,0,0.3);">
                                <div style="padding: 1.25rem; display: flex; gap: 1.25rem; align-items: flex-start; border-bottom: 1px solid rgba(83, 197, 224, 0.15); background: rgba(255,255,255,0.02);">
                                    <div style="width: 130px; height: 130px; border-radius: 0; overflow: hidden; background: rgba(0,0,0,0.35); border: none; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: inset 0 2px 10px rgba(0,0,0,0.2);">
                                        <?php if (!empty($order['artwork_path'])): ?>
                                            <img src="<?php echo (defined('BASE_PATH') ? BASE_PATH : ''); ?>/<?php echo htmlspecialchars($order['artwork_path']); ?>" style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease-in-out;" onmouseover="this.style.transform='scale(1.08)'" onmouseout="this.style.transform='scale(1)'">
                                        <?php else: ?>
                                            <span style="font-size: 2.2rem; color: rgba(255,255,255,0.15);">🛠️</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="flex: 1; min-width: 0; display: flex; flex-direction: column;">
                                        <h3 style="font-size: 0.95rem; line-height: 1.3rem; font-weight: 600; color: #ffffff !important; margin: 0 0 0.3rem 0;"><?php echo htmlspecialchars($order['job_title']); ?></h3>
                                        <div style="display: inline-flex; font-size: 0.72rem; font-weight: 700; color: #53c5e0; text-transform: uppercase; letter-spacing: 0.08em; padding: 3px 10px; border-radius: 0; background: rgba(83, 197, 224, 0.12); border: none; margin-bottom: 1.25rem; align-self: flex-start;">
                                            <?php echo htmlspecialchars($order['service_type']); ?>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-top: auto;">
                                            <div style="flex: 1; min-width: 80px;">
                                                <div style="font-size: 0.68rem; color: #9fc4d4; font-weight: 700; text-transform: uppercase; margin-bottom: 2px;">Quantity</div>
                                                <div style="font-size: 1rem; color: #eaf6fb; font-weight: 700;"><?php echo $order['quantity']; ?></div>
                                            </div>
                                            <div style="flex: 1; min-width: 100px;">
                                                <div style="font-size: 0.68rem; color: #53c5e0; font-weight: 700; text-transform: uppercase; margin-bottom: 2px;">Estimated Total</div>
                                                <div style="font-size: 1rem; color: #53c5e0; font-weight: 800;"><?php echo format_currency($total_amount); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div style="padding: 1.25rem; background: transparent;">
                                    <h4 style="font-size: 0.85rem; font-weight: 800; color: #eaf6fb; margin-bottom: 1rem; border-bottom: 1px solid rgba(83, 197, 224, 0.12); padding-bottom: 0.5rem; display: flex; align-items: center; gap: 8px;">Order Specifications</h4>
                                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 0.85rem;">
                                        <div style="background: rgba(255, 255, 255, 0.04); border: none; padding: 0.75rem 0.85rem; border-radius: 0;">
                                            <div style="font-size: 0.65rem; color: #9fc4d4; font-weight: 600; text-transform: uppercase; margin-bottom: 2px;">Size</div>
                                            <div style="font-size: 0.95rem; font-weight: 700; color: #eaf6fb;"><?php echo htmlspecialchars($order['width_ft'] . ' x ' . $order['height_ft']); ?> ft</div>
                                        </div>
                                        <?php if (!empty($order['notes'])): ?>
                                            <div style="grid-column: 1 / -1; margin-top: 0.75rem; padding: 1.15rem; background: rgba(83, 197, 224, 0.08); border: none; border-radius: 0;">
                                                <div style="font-size: 0.75rem; font-weight: 800; color: #53c5e0; text-transform: uppercase; margin-bottom: 6px;">📝 Special Instructions & Notes</div>
                                                <div style="font-size: 0.95rem; color: #eaf6fb; line-height: 1.6; font-weight: 600;"><?php echo nl2br(htmlspecialchars($order['notes'])); ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                    <?php endif; ?>
                </div>
                </div><!-- end .payment-card -->
                </div><!-- end .payment-main -->

                <!-- RIGHT: Payment Sidebar -->
                <div class="payment-sidebar">
                <div id="payment-sidebar-card" class="payment-card p-6">

                <!-- Divider between order info top and payment form -->

                <!-- Payment Section -->
                <?php if ($is_paid_ui): ?>
                    <div class="payment-confirmed-card">
                        <?php if (($paymongo_payment['status'] ?? '') === 'paid' && ($paymongo_payment['mode'] ?? '') === 'test'): ?>
                            <div style="display:inline-flex;align-items:center;padding:4px 9px;margin-bottom:12px;background:#fef3c7;color:#92400e;font-size:11px;font-weight:800;text-transform:uppercase;">PayMongo Test Mode</div>
                        <?php endif; ?>
                        <div class="paymongo-eyebrow">Payment Confirmed</div>
                        <?php if (($paymongo_payment['status'] ?? '') === 'paid'): ?>
                            <div class="paid-total"><?php echo format_currency(((int)$paymongo_public['paid_amount_centavos']) / 100); ?> PAID</div>
                            <div class="payment-detail-grid">
                                <div class="payment-detail"><span class="paymongo-label">Payment Method</span><strong class="payment-detail-value"><?php echo htmlspecialchars((string)$paymongo_public['payment_method_label']); ?></strong></div>
                                <div class="payment-detail"><span class="paymongo-label">Reference</span><strong class="payment-detail-value"><?php echo htmlspecialchars((string)($paymongo_public['reference_number'] ?: $paymongo_public['payment_reference'] ?: 'Not recorded')); ?></strong></div>
                                <div class="payment-detail"><span class="paymongo-label">Paid On</span><strong class="payment-detail-value"><?php echo htmlspecialchars(!empty($paymongo_public['provider_paid_at']) ? format_datetime((string)$paymongo_public['provider_paid_at']) : 'Not recorded'); ?></strong></div>
                                <div class="payment-detail"><span class="paymongo-label">Order Status</span><strong class="payment-detail-value">Awaiting Production</strong></div>
                            </div>
                            <p style="color:#52666d;font-size:.82rem;line-height:1.55;margin:1rem auto 0;max-width:34rem;">Payment has been received. Production will begin after staff confirmation.</p>
                            <div class="payment-confirmed-actions"><span class="payment-status-badge">Awaiting Production</span><a href="<?php echo !$is_job_order ? 'orders.php?highlight=' . $order_id : 'services.php'; ?>" class="paymongo-action paymongo-action-secondary">View Order Details</a></div>
                        <?php else: ?>
                            <div style="font-size:1.45rem;font-weight:900;color:#eaf6fb;margin:10px 0;">Amount Paid: <?php echo format_currency($total_amount); ?></div>
                            <p style="color:#9fc4d4;font-size:.875rem;margin-bottom:6px;">Payment method: <?php echo htmlspecialchars((string)($order['payment_method'] ?? 'GCash')); ?></p>
                            <p style="color:#9fc4d4;font-size:.875rem;"><strong style="color:#eaf6fb;">Status: Payment Approved.</strong><br>Your order is proceeding through production.</p>
                        <?php endif; ?>
                        <?php if (($paymongo_payment['status'] ?? '') !== 'paid'): ?><a href="<?php echo !$is_job_order ? 'orders.php?highlight=' . $order_id : 'services.php'; ?>" class="paymongo-action paymongo-action-secondary" style="margin-top:1rem;">View Order Details</a><?php endif; ?>
                    </div>
                <?php elseif (!$show_payment_form): ?>
                    <div style="text-align: center; padding: 2rem;">
                        <div style="width: 80px; height: 80px; margin: 0 auto 1.5rem; background: linear-gradient(135deg, #0f3340, #0a2530); border: 2px solid #53c5e0; display: flex; align-items: center; justify-content: center; position: relative;">
                            <svg style="width: 48px; height: 48px; color: #53c5e0;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <h3 style="font-weight: 800; color: #eaf6fb; margin-bottom: 0.5rem;"><?php echo htmlspecialchars((string)($payment_verification_summary['customer_label'] ?? 'Under Review')); ?></h3>
                        <p style="color: #9fc4d4; font-size: 0.875rem;"><?php echo htmlspecialchars((string)($payment_verification_summary['customer_message'] ?? 'Payment proof submitted. Your payment is pending staff verification.')); ?></p>
                        <a href="<?php echo !$is_job_order ? 'orders.php?highlight=' . $order_id : 'services.php'; ?>" class="btn-primary w-full mt-6 text-center block" style="text-decoration: none;">Track Order Status</a>
                    </div>
                <?php else: ?>
                    <?php
                    $payment_submission_token = bin2hex(random_bytes(24));
                    ?>
                    <?php if ($paymongo_available): ?>
                        <div id="paymongo-test-payment" class="paymongo-card">
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;">
                                <div><div class="paymongo-eyebrow">Payment Method</div><h2 class="paymongo-title">Pay securely with PayMongo</h2></div>
                                <?php if ($paymongo_mode === 'test'): ?><span style="padding:3px 8px;background:#fef3c7;color:#92400e;font-size:10px;font-weight:800;text-transform:uppercase;">Test Mode</span><?php endif; ?>
                            </div>
                            <div class="paymongo-amount-wrap">
                                <div class="paymongo-label">Amount Due</div>
                                <div class="paymongo-amount"><?php echo format_currency($total_amount); ?></div>
                            </div>
                            <div id="paymongo-method-actions" class="paymongo-options" role="radiogroup" aria-label="PayMongo payment method">
                                <?php if ($paymongo_qrph_available): ?>
                                    <button id="paymongo-create-qrph" type="button" class="paymongo-option" role="radio" aria-checked="false"><span class="paymongo-recommended">Recommended</span><strong>QR PH</strong><span>Scan a secure QR using a supported banking or e-wallet app.</span></button>
                                <?php endif; ?>
                                <button id="paymongo-create-link" type="button" class="paymongo-option" role="radio" aria-checked="false"><strong>SECURE CHECKOUT</strong><span>Continue to PayMongo's hosted secure checkout.</span></button>
                            </div>
                            <div id="paymongo-qr-panel" class="paymongo-qr-card" style="display:none;">
                                <div class="paymongo-eyebrow">QR Ph Payment</div><div class="paymongo-amount" style="font-size:1.45rem;margin:.3rem 0 .5rem;"><?php echo format_currency($total_amount); ?></div>
                                <div style="font-size:.8rem;color:#52666d;line-height:1.5;margin-bottom:.8rem;">Scan this QR using a supported banking or e-wallet application.</div>
                                <img id="paymongo-qr-image" alt="PayMongo QR Ph payment code">
                                <div style="margin-top:.75rem;font-size:.82rem;font-weight:800;color:#9a6700;">Waiting for payment</div>
                                <div id="paymongo-qr-countdown" style="font-size:.82rem;font-weight:800;color:#0f766e;margin-top:.25rem;min-height:20px;"></div>
                                <div style="margin-top:.5rem;font-size:.72rem;color:#64748b;overflow-wrap:anywhere;">Order Reference: <strong style="color:#223b43;">#<?php echo (int)$order_id; ?></strong></div>
                            </div>
                            <div class="paymongo-actions"><button id="paymongo-pay-now" type="button" class="paymongo-action paymongo-action-secondary" style="display:none;">Continue to Secure Checkout</button><button id="paymongo-retry" type="button" class="paymongo-action paymongo-action-primary" style="display:none;">Generate New QR</button></div>
                            <div id="paymongo-payment-state" class="paymongo-state" role="status" aria-live="polite">Choose a payment method to continue.</div>
                        </div>
                        <?php if ($manual_payment_enabled): ?>
                        <div style="display:flex;align-items:center;gap:10px;margin:0 0 20px;color:#9fc4d4;font-size:12px;">
                            <span style="height:1px;background:#31515c;flex:1;"></span>
                            Having trouble with online payment? Use manual payment proof instead.
                            <span style="height:1px;background:#31515c;flex:1;"></span>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($paymongo_online_enabled && !$paymongo_available): ?>
                        <div style="padding:14px;margin-bottom:20px;border:1px solid #f59e0b;background:#2b2110;color:#fde68a;font-size:13px;line-height:1.55;">
                            PayMongo payment is temporarily unavailable. Please contact the shop before sending payment.
                        </div>
                    <?php endif; ?>
                    <?php if ($manual_payment_enabled): ?>
                    <form id="paymentForm" enctype="multipart/form-data">
                        <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                        <input type="hidden" name="is_job" value="<?php echo $is_job_order ? '1' : '0'; ?>">
                        <input type="hidden" name="submission_token" value="<?php echo htmlspecialchars($payment_submission_token, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo csrf_field(); ?>

                        <?php if ($is_rejected_payment): ?>
                            <div style="background: linear-gradient(135deg, rgba(239, 68, 68, 0.16), rgba(220, 38, 38, 0.08)); border-left: 4px solid #ef4444; padding: 1rem 1.25rem; margin-bottom: 1.5rem; border-radius: 0;">
                                <div style="font-weight: 800; color: #fecaca; font-size: 0.875rem; margin-bottom: 0.4rem; text-transform: uppercase; letter-spacing: 0.05em;">Previous Payment Rejected</div>
                                <div style="color: #fee2e2; font-size: 0.9rem; line-height: 1.6; font-weight: 600;">
                                    <?php echo htmlspecialchars($payment_rejection_reason !== '' ? $payment_rejection_reason : 'Please upload a clearer or corrected proof of payment to continue.'); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <h2 class="payment-section-title" style="margin-bottom: 1rem; font-size: 1rem;">1. Payment Method — GCash</h2>
                        
                        <div style="background:rgba(83,197,224,.12);border-left:4px solid #53c5e0;padding:1rem 1.25rem;margin-bottom:1.25rem;">
                            <div style="font-size:.72rem;font-weight:800;color:#9fc4d4;text-transform:uppercase;letter-spacing:.06em;">Amount Due</div>
                            <div style="font-size:1.6rem;font-weight:900;color:#eaf6fb;margin-top:.2rem;"><?php echo format_currency($total_amount); ?></div>
                            <div style="font-size:.8rem;color:#9fc4d4;margin-top:.35rem;">Pay this exact amount using the GCash account below, then upload a screenshot of the completed transaction.</div>
                        </div>

                        <!-- Important Note - Moved above QR code -->
                        <div style="background: linear-gradient(135deg, rgba(251, 191, 36, 0.15), rgba(245, 158, 11, 0.08)); border-left: 4px solid #fbbf24; padding: 1rem 1.25rem; margin-bottom: 1.5rem; border-radius: 0;">
                            <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                                <div style="font-size: 1.5rem; line-height: 1; flex-shrink: 0;">⚠️</div>
                                <div>
                                    <div style="font-weight: 800; color: #fbbf24; font-size: 0.875rem; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em;">Important Reminder</div>
                                    <div style="color: #eaf6fb; font-size: 0.875rem; line-height: 1.6; font-weight: 600;">
                                        <strong style="color: #fbbf24;">Take a screenshot</strong> of your payment transaction <strong style="color: #fbbf24;">before closing</strong> the payment app. You'll need to upload it here as proof of payment.
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php 
                        $qr_dir = __DIR__ . '/../public/assets/uploads/qr/';
                        $payment_cfg_path = $qr_dir . 'payment_methods.json';
                        $payment_methods = printflow_load_runtime_config('payment_methods', $payment_cfg_path);
                        $all_enabled = array_filter($payment_methods ?: [], function($m) { return !empty($m['enabled']); });

                        // Only keep GCash in the UI to remove PayMaya/Maya options here
                        $enabled_methods = array_values(array_filter($all_enabled, function($m){
                            $prov = strval($m['provider'] ?? '');
                            return stripos($prov, 'gcash') !== false;
                        }));

                        // Determine if this is a product order (no customization) or service order
                        $is_product_order = true;
                        if (!$is_job_order && !empty($items)) {
                            foreach ($items as $item) {
                                $custom_data = json_decode($item['customization_data'] ?? '{}', true);
                                if (!empty($custom_data) && count($custom_data) > 0) {
                                    $is_product_order = false;
                                    break;
                                }
                            }
                        } elseif ($is_job_order) {
                            $is_product_order = false;
                        }
                        ?>

                        <?php if (empty($enabled_methods)): ?>
                            <div style="background: #fff1f2; border: none; border-radius: 0; padding: 1rem; color: #be123c; font-size: 0.875rem; font-weight: 600; margin-bottom: 1.5rem;">
                                Online payment is currently unavailable. Please contact the shop.
                            </div>
                        <?php else: ?>
                            <div style="display: flex; gap: 8px; margin-bottom: 1.5rem;">
                                <?php $first = true; foreach ($enabled_methods as $index => $pm): ?>
                                    <button type="button" onclick="selectPM(<?php echo $index; ?>)" id="btn-pm-<?php echo $index; ?>" data-provider="<?php echo htmlspecialchars((string)$pm['provider'], ENT_QUOTES, 'UTF-8'); ?>" class="pm-tab-btn <?php echo $first ? 'active' : ''; ?>">
                                        <?php echo htmlspecialchars($pm['provider']); ?>
                                    </button>
                                <?php $first = false; endforeach; ?>
                            </div>

                            <div id="pm-details-container" style="background: rgba(0,28,36,0.7); border: none; border-radius: 0; padding: 1.75rem; margin-bottom: 2.25rem; text-align: center; backdrop-filter: blur(8px);">
                                <?php $first = true; foreach ($enabled_methods as $index => $pm): ?>
                                    <div id="pm-info-<?php echo $index; ?>" style="display: <?php echo $first ? 'block' : 'none'; ?>;">
                                        <?php $qr_url = pf_payment_qr_url($pm['file'] ?? ''); ?>
                                        <?php if ($qr_url !== ''): ?>
                                            <img src="<?php echo htmlspecialchars($qr_url, ENT_QUOTES, 'UTF-8'); ?>" style="width: 170px; height: 170px; object-fit: contain; margin: 0 auto 1.25rem; display: block; border-radius: 0; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08);" alt="<?php echo htmlspecialchars(($pm['provider'] ?? 'Payment') . ' QR', ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php endif; ?>
                                        <div style="font-weight: 800; color: #eaf6fb; font-size: 1.05rem; letter-spacing: 0.01em;"><?php echo htmlspecialchars($pm['provider']); ?></div>
                                        <div style="color: #9fc4d4; font-size: 0.875rem; font-weight: 600; margin-top: 6px;"><?php echo htmlspecialchars($pm['label']); ?></div>
                                    </div>
                                <?php $first = false; endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Simplified Flow: Always Full Payment -->
                        <input type="hidden" name="amount" value="<?php echo number_format($total_amount, 2, '.', ''); ?>">
                        <input type="hidden" name="payment_choice" value="full">
                        <input type="hidden" name="selected_payment_method" id="selectedPaymentMethod" value="<?php echo htmlspecialchars((string)($enabled_methods[0]['provider'] ?? 'GCash'), ENT_QUOTES, 'UTF-8'); ?>">

                        <h2 class="payment-section-title" style="margin-bottom: 1rem; font-size: 1rem; color: #eaf6fb;">2. Upload Reference Receipt</h2>
                        
                        <div class="input-group">
                            <input type="file" name="payment_proof" id="proofInput" style="display: none;" accept="image/jpeg,image/png,image/webp" required>
                            <div id="dropzone" class="dropzone" onclick="document.getElementById('proofInput').click()">
                                <div id="placeholder" style="display: block;">
                                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">📸</div>
                                    <div class="dz-title">Click to upload receipt</div>
                                    <div class="dz-sub">JPG, PNG or WEBP image, up to 10 MB</div>
                                </div>
                                <div id="preview" style="display: none; align-items: center; justify-content: center; flex-direction: column; width: 100%; overflow: hidden;">
                                    <img id="previewImg" src="" style="max-height: 120px; border-radius: 8px; margin-bottom: 10px; max-width: 100%; object-fit: contain;">
                                    <p id="fileName" style="font-size: 0.8125rem; font-weight: 700; color: #eaf6fb; max-width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding: 0 4px;"></p>
                                </div>
                            </div>
                        </div>

                        <button type="submit" id="submitBtn" class="shopee-btn-primary" data-methods-disabled="<?php echo empty($enabled_methods) ? '1' : '0'; ?>" style="width: 100%; padding: 0.75rem; white-space: nowrap; text-decoration: none; text-align: center; display: block; font-weight: 700; font-size: 0.9rem; border-radius: 0; border: none; background: #53c5e0 !important; color: #ffffff !important; text-transform: uppercase; letter-spacing: 0.02em; cursor: pointer; box-shadow: 0 4px 12px rgba(83, 197,224,0.3); transition: all 0.2s;" <?php echo empty($enabled_methods) ? 'disabled aria-disabled="true"' : ''; ?> onmouseover="this.style.background='#32a1c4'; this.style.color='#ffffff'" onmouseout="this.style.background='#53c5e0'; this.style.color='#ffffff'">
                            Submit Payment Proof
                        </button>
                        <div id="submitError" style="display:none; margin-top:0.6rem; font-size:0.8rem; font-weight:700; color:#b91c1c;">Please upload your reference receipt before submitting.</div>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
                </div><!-- end payment-card sidebar -->
                </div><!-- end payment-sidebar -->

            </div><!-- end payment-layout -->

    </div>
</div>

<script>
    const paymongoStatusUrl = 'api_paymongo_status.php?subject_type=<?php echo rawurlencode($paymongo_subject_type); ?>&subject_id=<?php echo (int)$order_id; ?>';
    const paymongoCreateUrl = 'api_paymongo_status.php';
    const paymongoCsrfToken = <?php echo json_encode($customer_paymongo_csrf); ?>;
    const paymongoSubjectType = <?php echo json_encode($paymongo_subject_type); ?>;
    const paymongoSubjectId = <?php echo (int)$order_id; ?>;
    const paymongoState = document.getElementById('paymongo-payment-state');
    const paymongoPayNow = document.getElementById('paymongo-pay-now');
    const paymongoQrPanel = document.getElementById('paymongo-qr-panel');
    const paymongoQrImage = document.getElementById('paymongo-qr-image');
    const paymongoQrCountdown = document.getElementById('paymongo-qr-countdown');
    const paymongoQrButton = document.getElementById('paymongo-create-qrph');
    const paymongoLinkButton = document.getElementById('paymongo-create-link');
    const paymongoRetryButton = document.getElementById('paymongo-retry');
    const paymentSidebarCard = document.getElementById('payment-sidebar-card');
    if (paymongoState) {
        let paymongoPollTimer = null;
        let paymongoCountdownTimer = null;
        let paymentCreateInFlight = false;
        let paymongoCurrentPayment = <?php echo json_encode($paymongo_public, JSON_UNESCAPED_SLASHES); ?>;
        let selectedPayMongoMethod = paymongoCurrentPayment?.payment_flow === 'payment_link'
            ? 'payment_link'
            : (paymongoCurrentPayment?.payment_flow === 'payment_intent' ? 'qrph' : null);
        const stopPayMongoTimers = () => {
            if (paymongoPollTimer) window.clearTimeout(paymongoPollTimer);
            if (paymongoCountdownTimer) window.clearInterval(paymongoCountdownTimer);
            paymongoPollTimer = null;
            paymongoCountdownTimer = null;
        };
        const setPayMongoButtonsBusy = (busy) => {
            paymentCreateInFlight = busy;
            [paymongoQrButton, paymongoLinkButton, paymongoPayNow, paymongoRetryButton].forEach((button) => {
                if (button) button.disabled = busy;
            });
            if (paymongoPayNow) {
                paymongoPayNow.textContent = busy && selectedPayMongoMethod === 'payment_link'
                    ? 'Creating secure checkout...'
                    : 'Continue to Secure Checkout';
            }
        };
        const parsePayMongoJson = async (response) => {
            const contentType = response.headers.get('content-type') || '';
            if (!contentType.includes('application/json')) {
                throw new Error('The payment service returned an invalid response. Please try again.');
            }
            return response.json();
        };
        const setSelectedPayMongoMethod = (method) => {
            selectedPayMongoMethod = ['qrph', 'payment_link'].includes(method) ? method : null;
            const qrSelected = selectedPayMongoMethod === 'qrph';
            const linkSelected = selectedPayMongoMethod === 'payment_link';
            document.querySelectorAll('#paymongo-method-actions .paymongo-option').forEach((card) => {
                card.classList.remove('is-selected');
                card.setAttribute('aria-checked', 'false');
            });
            const selectedCard = qrSelected ? paymongoQrButton : (linkSelected ? paymongoLinkButton : null);
            if (selectedCard) {
                selectedCard.classList.add('is-selected');
                selectedCard.setAttribute('aria-checked', 'true');
            }
            if (paymongoPayNow) paymongoPayNow.style.display = linkSelected ? 'inline-flex' : 'none';
            if (paymongoQrPanel && !qrSelected) paymongoQrPanel.style.display = 'none';
            paymongoState.textContent = qrSelected
                ? 'Scan a secure QR using a supported banking or e-wallet app.'
                : (linkSelected
                    ? 'You\'ll continue to PayMongo\'s secure hosted checkout.'
                    : 'Choose a payment method to continue.');
        };
        const renderPayMongoConfirmed = (payment) => {
            stopPayMongoTimers();
            if (!paymentSidebarCard) return;
            paymentSidebarCard.replaceChildren();
            const panel = document.createElement('div');
            panel.className = 'payment-confirmed-card';

            if (payment.test_mode) {
                const badge = document.createElement('div');
                badge.textContent = 'PayMongo Test Mode';
                badge.style.cssText = 'display:inline-flex;align-items:center;padding:4px 9px;margin-bottom:12px;background:#fef3c7;color:#92400e;font-size:11px;font-weight:800;text-transform:uppercase;';
                panel.appendChild(badge);
            }

            const heading = document.createElement('div');
            heading.textContent = 'Payment Confirmed';
            heading.className = 'paymongo-eyebrow';
            panel.appendChild(heading);

            const amount = document.createElement('div');
            amount.textContent = '₱' + (Number(payment.paid_amount_centavos || payment.amount || 0) / 100).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' PAID';
            amount.className = 'paid-total';
            panel.appendChild(amount);

            const details = document.createElement('div');
            details.className = 'payment-detail-grid';
            const reference = payment.reference_number || payment.payment_reference || '';
            const detailValues = [
                ['Payment Method', payment.payment_method_label || 'QR Ph'],
                ['Reference', reference || 'Not recorded'],
                ['Paid On', payment.provider_paid_at || payment.paid_at || 'Not recorded'],
                ['Order Status', 'Awaiting Production']
            ];
            detailValues.forEach(([label, value]) => {
                const detail = document.createElement('div');
                detail.className = 'payment-detail';
                const labelElement = document.createElement('span');
                labelElement.className = 'paymongo-label';
                labelElement.textContent = label;
                const valueElement = document.createElement('strong');
                valueElement.className = 'payment-detail-value';
                valueElement.textContent = value;
                detail.append(labelElement, valueElement);
                details.appendChild(detail);
            });
            panel.appendChild(details);

            const next = document.createElement('p');
            next.textContent = 'Payment has been received. Production will begin after staff confirmation.';
            next.style.cssText = 'color:#52666d;font-size:.82rem;line-height:1.55;margin:1rem auto 0;max-width:34rem;';
            panel.appendChild(next);

            const actions = document.createElement('div');
            actions.className = 'payment-confirmed-actions';
            const statusBadge = document.createElement('span');
            statusBadge.className = 'payment-status-badge';
            statusBadge.textContent = 'Awaiting Production';
            actions.appendChild(statusBadge);

            const orderLink = document.createElement('a');
            orderLink.href = <?php echo json_encode(!$is_job_order ? 'orders.php?highlight=' . $order_id : 'services.php'); ?>;
            orderLink.textContent = 'View Order Details';
            orderLink.className = 'paymongo-action paymongo-action-secondary';
            actions.appendChild(orderLink);
            panel.appendChild(actions);
            paymentSidebarCard.appendChild(panel);
        };
        const startQrCountdown = (payment) => {
            if (paymongoCountdownTimer) window.clearInterval(paymongoCountdownTimer);
            if (!paymongoQrCountdown || !payment.qr_expires_at_epoch) return;
            const render = () => {
                const remaining = Math.max(0, Number(payment.qr_expires_at_epoch) - Math.floor(Date.now() / 1000));
                const minutes = String(Math.floor(remaining / 60)).padStart(2, '0');
                const seconds = String(remaining % 60).padStart(2, '0');
                paymongoQrCountdown.textContent = remaining > 0
                    ? `QR expires in ${minutes}:${seconds}`
                    : 'QR code expired';
                if (remaining <= 0 && paymongoCountdownTimer) {
                    window.clearInterval(paymongoCountdownTimer);
                    paymongoCountdownTimer = null;
                    pollPayMongo();
                }
            };
            render();
            paymongoCountdownTimer = window.setInterval(render, 1000);
        };
        const renderPayMongoPayment = (payment) => {
            paymongoCurrentPayment = payment || null;
            if (!payment) {
                paymongoState.textContent = selectedPayMongoMethod === 'qrph'
                    ? 'Scan a secure QR using a supported banking or e-wallet app.'
                    : (selectedPayMongoMethod === 'payment_link'
                        ? 'You\'ll continue to PayMongo\'s secure hosted checkout.'
                        : 'Choose a payment method to continue.');
                if (paymongoQrPanel) paymongoQrPanel.style.display = 'none';
                if (paymongoPayNow) paymongoPayNow.style.display = selectedPayMongoMethod === 'payment_link' ? 'inline-flex' : 'none';
                if (paymongoRetryButton) paymongoRetryButton.style.display = 'none';
                return false;
            }
            const status = String(payment.status || '').toLowerCase();
            if (status === 'paid') {
                renderPayMongoConfirmed(payment);
                return true;
            }
            const isQr = payment.payment_flow === 'payment_intent' && payment.payment_method === 'qrph';
            const hasQr = selectedPayMongoMethod === 'qrph' && isQr && Boolean(payment.qr_image_url);
            if (paymongoQrPanel) paymongoQrPanel.style.display = hasQr ? 'block' : 'none';
            if (paymongoQrImage && hasQr) paymongoQrImage.src = payment.qr_image_url;
            if (paymongoPayNow) {
                paymongoPayNow.style.display = selectedPayMongoMethod === 'payment_link' ? 'inline-flex' : 'none';
            }
            if (paymongoRetryButton) {
                paymongoRetryButton.style.display = selectedPayMongoMethod === 'qrph'
                    && isQr && ['failed', 'expired', 'cancelled'].includes(status)
                    ? 'block'
                    : 'none';
            }
            if (selectedPayMongoMethod === 'payment_link') {
                paymongoState.textContent = payment.payment_flow === 'payment_link' && payment.checkout_url
                    ? 'Secure checkout is ready. Continue to PayMongo to complete payment.'
                    : 'You\'ll continue to PayMongo\'s secure hosted checkout.';
            } else if (payment.payment_flow === 'payment_link') {
                paymongoState.textContent = 'Generate a QR to switch from Secure Checkout to QR PH.';
            } else if (status === 'failed') {
                paymongoState.textContent = 'Payment was not completed. You may generate a new QR and try again.';
            } else if (status === 'expired') {
                paymongoState.textContent = 'QR code expired. Generate a new QR code to continue.';
            } else if (status === 'generating') {
                paymongoState.textContent = 'Preparing your PayMongo payment...';
            } else {
                paymongoState.textContent = isQr
                    ? 'Waiting for QR PH payment confirmation.'
                    : 'Scan a secure QR using a supported banking or e-wallet app.';
            }
            if (hasQr) startQrCountdown(payment);
            return ['paid', 'failed', 'expired', 'cancelled'].includes(status);
        };
        const schedulePayMongoPoll = () => {
            if (paymongoPollTimer) window.clearTimeout(paymongoPollTimer);
            const status = String(paymongoCurrentPayment?.status || '').toLowerCase();
            if (selectedPayMongoMethod !== 'qrph'
                || paymongoCurrentPayment?.payment_flow !== 'payment_intent'
                || !['generating', 'awaiting_payment'].includes(status)) {
                paymongoPollTimer = null;
                return;
            }
            paymongoPollTimer = window.setTimeout(pollPayMongo, 5000);
        };
        const normalizePayMongoPayment = (data) => {
            const payment = data?.payment && typeof data.payment === 'object'
                ? {...data.payment}
                : {};
            payment.payment_flow = data?.payment_flow || payment.payment_flow || '';
            payment.payment_method = data?.payment_method || payment.payment_method || '';
            payment.status = data?.status || payment.status || '';
            payment.qr_image_url = data?.qr_image_url || payment.qr_image_url || '';
            payment.qr_expires_at = data?.qr_expires_at || payment.qr_expires_at || null;
            payment.qr_expires_at_epoch = data?.qr_expires_at_epoch || payment.qr_expires_at_epoch || null;
            payment.checkout_url = data?.checkout_url || payment.checkout_url || '';
            return payment;
        };
        const pollPayMongo = async () => {
            try {
                const response = await fetch(paymongoStatusUrl, { cache: 'no-store' });
                const data = await parsePayMongoJson(response);
                if (!response.ok) {
                    paymongoState.textContent = data.message || `Payment status is temporarily unavailable (${response.status}).`;
                    return;
                }
                if (data.success) {
                    const terminal = renderPayMongoPayment(normalizePayMongoPayment(data));
                    if (terminal) return;
                }
                if (data.success && data.confirming) {
                    paymongoState.textContent = 'Payment received by PayMongo. Confirming your payment…';
                }
            } catch (error) {
                // The next poll retries; no credential or provider response is logged.
            }
            schedulePayMongoPoll();
        };
        const createPayMongoPayment = async (action) => {
            if (paymentCreateInFlight) return null;
            const isQrAction = ['create_qrph', 'retry_qrph'].includes(action);
            setPayMongoButtonsBusy(true);
            paymongoState.textContent = isQrAction
                ? 'Generating secure QR...'
                : 'Preparing secure checkout...';
            try {
                const response = await fetch(paymongoCreateUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        action,
                        subject_type: paymongoSubjectType,
                        subject_id: paymongoSubjectId,
                        csrf_token: paymongoCsrfToken
                    })
                });
                const data = await parsePayMongoJson(response);
                if (!response.ok || !data.success) {
                    throw new Error(data.message || (action === 'create_link'
                        ? 'We couldn\'t create Secure Checkout right now. Please try again.'
                        : 'We couldn\'t generate a QR right now. Please try again.'));
                }
                const payment = normalizePayMongoPayment(data);
                if (!payment.payment_flow
                    || (isQrAction && payment.status === 'awaiting_payment' && !payment.qr_image_url)
                    || (action === 'create_link' && !payment.checkout_url)) {
                    throw new Error(action === 'create_link'
                        ? 'We couldn\'t create Secure Checkout right now. Please try again.'
                        : 'We couldn\'t generate a QR right now. Please try again.');
                }
                stopPayMongoTimers();
                const terminal = renderPayMongoPayment(payment);
                if (!terminal) schedulePayMongoPoll();
                return payment;
            } catch (error) {
                paymongoState.textContent = error.message || 'The payment could not be prepared. Please try again.';
                return null;
            } finally {
                setPayMongoButtonsBusy(false);
            }
        };
        const continueToSecureCheckout = async () => {
            if (paymentCreateInFlight || selectedPayMongoMethod !== 'payment_link') return;
            const payment = await createPayMongoPayment('create_link');
            if (payment?.payment_flow === 'payment_link' && payment.checkout_url) {
                window.location.assign(payment.checkout_url);
            }
        };
        if (paymongoQrButton) paymongoQrButton.addEventListener('click', () => {
            stopPayMongoTimers();
            setSelectedPayMongoMethod('qrph');
            createPayMongoPayment('retry_qrph');
        });
        if (paymongoLinkButton) paymongoLinkButton.addEventListener('click', () => {
            stopPayMongoTimers();
            setSelectedPayMongoMethod('payment_link');
            renderPayMongoPayment(paymongoCurrentPayment);
        });
        if (paymongoPayNow) paymongoPayNow.addEventListener('click', continueToSecureCheckout);
        if (paymongoRetryButton) paymongoRetryButton.addEventListener('click', () => {
            stopPayMongoTimers();
            setSelectedPayMongoMethod('qrph');
            createPayMongoPayment('create_qrph');
        });
        window.addEventListener('pagehide', stopPayMongoTimers, {once: true});
        setSelectedPayMongoMethod(selectedPayMongoMethod);
        const initialTerminal = renderPayMongoPayment(paymongoCurrentPayment);
        if (!initialTerminal) schedulePayMongoPoll();
    }

    function toggleItems(btn) {
        const hiddenItems = document.querySelectorAll('.items-hidden');
        const isExpanded = btn.classList.contains('expanded');
        const textSpan = btn.querySelector('.show-more-text');
        const totalItems = <?php echo count($items); ?>;
        
        if (isExpanded) {
            // Collapse
            hiddenItems.forEach(item => item.style.display = 'none');
            btn.classList.remove('expanded');
            textSpan.textContent = 'Show All ' + totalItems + ' Items';
        } else {
            // Expand
            hiddenItems.forEach(item => item.style.display = 'block');
            btn.classList.add('expanded');
            textSpan.textContent = 'Show Less';
        }
    }

    function selectPM(idx) {
        document.querySelectorAll('.pm-tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('btn-pm-' + idx).classList.add('active');
        
        document.querySelectorAll('[id^="pm-info-"]').forEach(i => i.style.display = 'none');
        document.getElementById('pm-info-' + idx).style.display = 'block';
        const methodInput = document.getElementById('selectedPaymentMethod');
        const methodButton = document.getElementById('btn-pm-' + idx);
        if (methodInput && methodButton) methodInput.value = methodButton.dataset.provider || 'GCash';
    }

    const proofInput = document.getElementById('proofInput');
    const placeholder = document.getElementById('placeholder');
    const preview = document.getElementById('preview');
    const previewImg = document.getElementById('previewImg');
    const fileName = document.getElementById('fileName');
    let paymentSubmissionInFlight = false;

    function showPaymentFeedback(message, type = 'info') {
        const safeMessage = String(message || 'Something went wrong. Please try again.');
        const sharedFeedback = [window.showNotification, window.displayToast]
            .find((candidate) => typeof candidate === 'function');
        if (sharedFeedback) {
            sharedFeedback.call(window, safeMessage, type);
            return;
        }

        let toast = document.getElementById('payment-feedback-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'payment-feedback-toast';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            toast.style.cssText = 'position:fixed;right:20px;bottom:20px;z-index:100000;max-width:min(360px,calc(100vw - 40px));padding:12px 16px;border-radius:6px;color:#fff;font:600 14px/1.45 system-ui,sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.24);';
            document.body.appendChild(toast);
        }
        toast.textContent = safeMessage;
        toast.style.background = type === 'error' ? '#b91c1c' : (type === 'success' ? '#047857' : '#0a2530');
        toast.hidden = false;
        window.clearTimeout(toast._paymentHideTimer);
        toast._paymentHideTimer = window.setTimeout(() => { toast.hidden = true; }, 6000);
    }

    // Compatibility for any older inline branch that still calls showToast.
    function showToast(message, type = 'error') {
        showPaymentFeedback(message, type);
    }

    function resetPaymentSubmitButton() {
        paymentSubmissionInFlight = false;
        const button = document.getElementById('submitBtn');
        if (!button) return;
        button.textContent = 'Submit Payment Proof';
        button.classList.remove('is-uploading');
        updateSubmitState();
    }

    function updateSubmitState() {
        const btn = document.getElementById('submitBtn');
        if (!btn) return;
        const errorEl = document.getElementById('submitError');
        if (btn.dataset.methodsDisabled === '1') {
            btn.disabled = true;
            btn.style.opacity = '0.5';
            btn.style.cursor = 'not-allowed';
            return;
        }
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
        if (errorEl) errorEl.style.display = 'none';
    }

    if (proofInput) {
        proofInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                const validTypes = ['image/jpeg', 'image/png', 'image/webp'];
                if (!validTypes.includes(file.type) || file.size > 10 * 1024 * 1024) {
                    e.target.value = '';
                    showPaymentFeedback('Please choose a JPG, PNG, or WEBP receipt image up to 10 MB.', 'error');
                    updateSubmitState();
                    return;
                }
                fileName.textContent = file.name;
                placeholder.style.display = 'none';
                preview.style.display = 'flex';
                const reader = new FileReader();
                reader.onload = (event) => {
                    previewImg.src = event.target.result;
                    previewImg.style.display = 'block';
                    previewImg.style.borderRadius = '0';
                };
                reader.readAsDataURL(file);
            }
            updateSubmitState();
        });
    }
    updateSubmitState();

    const paymentForm = document.getElementById('paymentForm');
    if (paymentForm) {
        paymentForm.addEventListener('submit', function(e) {
            e.preventDefault();
            if (paymentSubmissionInFlight) return;
            if (!proofInput || !proofInput.files || proofInput.files.length === 0) {
                const errorEl = document.getElementById('submitError');
                if (errorEl) errorEl.style.display = 'block';
                return;
            }
            const btn = document.getElementById('submitBtn');
            paymentSubmissionInFlight = true;
            btn.disabled = true;
            btn.classList.add('is-uploading');
            btn.innerHTML = '<span style="display:flex; align-items:center; justify-content:center; gap:8px;">Uploading...</span>';

            const formData = new FormData(this);
            
            // Use XHR for more reliable file upload and progress on mobile browsers
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'api_submit_payment.php', true);
            xhr.timeout = 60000;

            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    var percent = Math.round((e.loaded / e.total) * 100);
                    btn.textContent = percent >= 100 ? 'Processing payment...' : 'Uploading... ' + percent + '%';
                }
            };

            xhr.onload = function() {
                try {
                    let data;
                    try {
                        data = JSON.parse(xhr.responseText || '');
                    } catch (parseError) {
                        console.error('Invalid payment submission response:', xhr.responseText);
                        throw new Error('The server returned an invalid response. Please try again.');
                    }

                    const submissionId = data.payment_submission_id || data.submission_id;
                    if (xhr.status >= 200 && xhr.status < 300 && data.success && data.record_created && submissionId) {
                        const successMessage = data.message || 'Payment proof submitted successfully and sent for staff verification.';
                        if (typeof window.showSuccessModal === 'function') {
                            window.showSuccessModal(
                                'Receipt Submitted',
                                successMessage,
                                'orders.php?highlight=<?php echo $order_id; ?>',
                                'services.php',
                                'View Order',
                                'Back to Services',
                                'services.php',
                                4000
                            );
                        } else {
                            showPaymentFeedback(successMessage, 'success');
                        }
                        return;
                    }

                    const step = data && data.step ? ` [${data.step}]` : '';
                    const message = data && data.message
                        ? data.message
                        : `Payment submission failed with HTTP ${xhr.status}.`;
                    throw new Error(message + step);
                } catch (error) {
                    console.error('Payment submission error:', error, {
                        status: xhr.status,
                        response: xhr.responseText
                    });
                    showPaymentFeedback(error.message, 'error');
                } finally {
                    resetPaymentSubmitButton();
                }
            };

            xhr.onerror = function() {
                console.error('Network error while submitting payment proof.');
                showPaymentFeedback('Network error. Please try again.', 'error');
                resetPaymentSubmitButton();
            };

            xhr.ontimeout = function() {
                console.error('Payment submission request timed out.');
                showPaymentFeedback('The upload timed out. Please try again.', 'error');
                resetPaymentSubmitButton();
            };

            xhr.onabort = function() {
                showPaymentFeedback('Payment submission was cancelled.', 'error');
                resetPaymentSubmitButton();
            };

            xhr.send(formData);
        });
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
