<?php
/**
 * Customer Order Payment Page
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_ui_helper.php';
require_once __DIR__ . '/../includes/runtime_config.php';

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

        $resolvedServiceId = function_exists('printflow_resolve_service_catalog_service_id_for_order_line')
            ? printflow_resolve_service_catalog_service_id_for_order_line($custom, $order, $item)
            : 0;

        if ($resolvedServiceId > 0) {
            $item['service_id'] = $resolvedServiceId;

            if (empty($custom['service_id'])) {
                $custom['service_id'] = $resolvedServiceId;
            }

            if (trim((string)($custom['service_type'] ?? '')) === '' && function_exists('customer_orders_resolve_service_name_by_id')) {
                $resolvedServiceName = trim((string)customer_orders_resolve_service_name_by_id($resolvedServiceId));
                if ($resolvedServiceName !== '') {
                    $custom['service_type'] = $resolvedServiceName;
                    if (trim((string)($item['product_name'] ?? '')) === '' || customer_orders_is_generic_item_name((string)($item['product_name'] ?? ''))) {
                        $item['product_name'] = $resolvedServiceName;
                    }
                }
            }

            if (function_exists('printflow_service_catalog_image_from_id')) {
                $serviceImage = trim((string)printflow_service_catalog_image_from_id($resolvedServiceId));
                if ($serviceImage !== '') {
                    $item['service_image'] = $serviceImage;
                    $item['catalog_service_image'] = $serviceImage;
                }
            }

            // Service lines must not inherit a similarly-numbered product thumbnail.
            $item['product_image'] = '';
            $item['customization_data'] = printflow_encode_customization_payload($custom);
        }
    }
    unset($item);
    
    // Dynamically calculate total from items to ensure accuracy
    $calculated_total = 0;
    foreach ($items as $item) {
        $calculated_total += (float)$item['unit_price'] * (int)$item['quantity'];
    }

    $total_amount = ($calculated_total > 0) ? $calculated_total : (float)$order['total_amount'];

    // If items have zero unit_price but the order has a staff-set total_amount,
    // distribute that total across items in-memory so the item cards display correctly.
    // This handles existing orders where price was set before the order_items sync fix.
    if ($calculated_total <= 0 && $total_amount > 0 && !empty($items)) {
        $_total_qty = array_sum(array_column($items, 'quantity'));
        if ($_total_qty > 0) {
            $_remaining = $total_amount;
            $_count     = count($items);
            foreach ($items as $_idx => &$_item) {
                $_is_last   = ($_idx === $_count - 1);
                $_item_tot  = $_is_last ? $_remaining : round($total_amount * $_item['quantity'] / $_total_qty, 2);
                $_item['unit_price'] = ($_item['quantity'] > 0) ? round($_item_tot / $_item['quantity'], 4) : 0;
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
                <div class="payment-card p-6">

                <!-- Divider between order info top and payment form -->

                <!-- Payment Section -->
                <?php if ($is_paid_ui): ?>
                    <div style="text-align: center; padding: 2rem;">
                        <div style="width: 80px; height: 80px; margin: 0 auto 1.5rem; background: linear-gradient(135deg, #059669, #047857); display: flex; align-items: center; justify-content: center; position: relative;">
                            <svg style="width: 48px; height: 48px; color: #fff;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                        <h3 style="font-weight: 800; color: #059669; margin-bottom: 0.5rem;">Payment Completed</h3>
                        <p style="color: #64748b; font-size: 0.875rem;">This order has already been fully paid.</p>
                        <a href="<?php echo !$is_job_order ? 'orders.php?highlight=' . $order_id : 'services.php'; ?>" class="btn-primary w-full mt-6 text-center block" style="text-decoration: none;">View Order Details</a>
                    </div>
                <?php elseif (!$show_payment_form): ?>
                    <div style="text-align: center; padding: 2rem;">
                        <div style="width: 80px; height: 80px; margin: 0 auto 1.5rem; background: linear-gradient(135deg, #0f3340, #0a2530); border: 2px solid #53c5e0; display: flex; align-items: center; justify-content: center; position: relative;">
                            <svg style="width: 48px; height: 48px; color: #53c5e0;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <h3 style="font-weight: 800; color: #eaf6fb; margin-bottom: 0.5rem;">Payment Verifying</h3>
                        <p style="color: #9fc4d4; font-size: 0.875rem;">Your payment proof is currently under review by our staff.</p>
                        <a href="<?php echo !$is_job_order ? 'orders.php?highlight=' . $order_id : 'services.php'; ?>" class="btn-primary w-full mt-6 text-center block" style="text-decoration: none;">Track Order Status</a>
                    </div>
                <?php else: ?>
                    <form id="paymentForm" enctype="multipart/form-data">
                        <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                        <input type="hidden" name="is_job" value="<?php echo $is_job_order ? '1' : '0'; ?>">
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

                        // Fallback: if no GCash entry is found, keep the first enabled method
                        if (empty($enabled_methods)) {
                            $enabled_methods = array_values($all_enabled);
                            if (!empty($enabled_methods)) {
                                $enabled_methods = [ $enabled_methods[0] ];
                            }
                        }

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
                                    <button type="button" onclick="selectPM(<?php echo $index; ?>)" id="btn-pm-<?php echo $index; ?>" class="pm-tab-btn <?php echo $first ? 'active' : ''; ?>">
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
                        <input type="hidden" name="amount" value="<?php echo number_format($order['total_amount'], 2, '.', ''); ?>">
                        <input type="hidden" name="payment_choice" value="full">

                        <h2 class="payment-section-title" style="margin-bottom: 1rem; font-size: 1rem; color: #eaf6fb;">2. Upload Reference Receipt</h2>
                        
                        <div class="input-group">
                            <input type="file" name="payment_proof" id="proofInput" style="display: none;" accept="image/*,application/pdf" required>
                            <div id="dropzone" class="dropzone" onclick="document.getElementById('proofInput').click()">
                                <div id="placeholder" style="display: block;">
                                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">📸</div>
                                    <div class="dz-title">Click to upload receipt</div>
                                    <div class="dz-sub">JPG, PNG or PDF</div>
                                </div>
                                <div id="preview" style="display: none; align-items: center; justify-content: center; flex-direction: column; width: 100%; overflow: hidden;">
                                    <img id="previewImg" src="" style="max-height: 120px; border-radius: 8px; margin-bottom: 10px; max-width: 100%; object-fit: contain;">
                                    <p id="fileName" style="font-size: 0.8125rem; font-weight: 700; color: #eaf6fb; max-width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding: 0 4px;"></p>
                                </div>
                            </div>
                        </div>

                        <button type="submit" id="submitBtn" class="shopee-btn-primary" data-methods-disabled="<?php echo empty($enabled_methods) ? '1' : '0'; ?>" style="width: 100%; padding: 0.75rem; white-space: nowrap; text-decoration: none; text-align: center; display: block; font-weight: 700; font-size: 0.9rem; border-radius: 0; border: none; background: #53c5e0 !important; color: #ffffff !important; text-transform: uppercase; letter-spacing: 0.02em; cursor: pointer; box-shadow: 0 4px 12px rgba(83, 197, 224, 0.3); transition: all 0.2s;" <?php echo empty($enabled_methods) ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : ''; ?> onmouseover="this.style.background='#32a1c4'; this.style.color='#ffffff'" onmouseout="this.style.background='#53c5e0'; this.style.color='#ffffff'">
                            Submit Payment Proof
                        </button>
                        <div id="submitError" style="display:none; margin-top:0.6rem; font-size:0.8rem; font-weight:700; color:#b91c1c;">Please upload your reference receipt before submitting.</div>
                    </form>
                <?php endif; ?>
                </div><!-- end payment-card sidebar -->
                </div><!-- end payment-sidebar -->

            </div><!-- end payment-layout -->

    </div>
</div>

<script>
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
    }

    const proofInput = document.getElementById('proofInput');
    const placeholder = document.getElementById('placeholder');
    const preview = document.getElementById('preview');
    const previewImg = document.getElementById('previewImg');
    const fileName = document.getElementById('fileName');

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
                fileName.textContent = file.name;
                const reader = new FileReader();
                reader.onload = (e) => {
                    previewImg.src = e.target.result;
                    previewImg.style.borderRadius = '0';
                    placeholder.style.display = 'none';
                    preview.style.display = 'flex';
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
            if (!proofInput || !proofInput.files || proofInput.files.length === 0) {
                const errorEl = document.getElementById('submitError');
                if (errorEl) errorEl.style.display = 'block';
                return;
            }
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.innerHTML = '<span style="display:flex; align-items:center; justify-content:center; gap:8px;">Uploading...</span>';

            const formData = new FormData(this);
            
            // Use XHR for more reliable file upload and progress on mobile browsers
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'api_submit_payment.php', true);
            xhr.timeout = 120000; // 2 minutes

            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    var percent = Math.round((e.loaded / e.total) * 100);
                    btn.innerHTML = 'Uploading... ' + percent + '%';
                }
            };

            xhr.onload = function() {
                try {
                    var data = JSON.parse(xhr.responseText || '{}');
                } catch (err) {
                    console.error('Invalid JSON response', xhr.responseText);
                    showToast('Server error. Please try again.');
                    btn.disabled = false;
                    btn.textContent = 'Submit Payment Proof';
                    return;
                }

                if (xhr.status >= 200 && xhr.status < 300 && data.success) {
                    showSuccessModal(
                        'Payment Success',
                        'Your payment proof has been submitted and is now under review. We\'ll notify you once verified!',
                        'orders.php?highlight=<?php echo $order_id; ?>',
                        'services.php',
                        'View Order',
                        'Back to Services',
                        'services.php',
                        4000
                    );
                } else {
                    showToast('Error: ' + (data.message || 'Upload failed'));
                    btn.disabled = false;
                    btn.textContent = 'Submit Payment Proof';
                }
            };

            xhr.onerror = function() {
                console.error('Upload error');
                showToast('Network error during upload. Please try again.');
                btn.disabled = false;
                btn.textContent = 'Submit Payment Proof';
            };

            xhr.ontimeout = function() {
                showToast('Upload timed out. Try a smaller file or use Wi‑Fi.');
                btn.disabled = false;
                btn.textContent = 'Submit Payment Proof';
            };

            xhr.send(formData);
        });
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
