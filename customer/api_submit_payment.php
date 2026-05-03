<?php
/**
 * API: Submit Payment Proof
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/JobOrderService.php';

require_role('Customer');

header('Content-Type: application/json');

try {

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$order_id = (int)($_POST['order_id'] ?? 0);
$is_job = (bool)($_POST['is_job'] ?? false);
$customer_id = get_user_id();
$payment_choice = $_POST['payment_choice'] ?? 'full';
if (!in_array($payment_choice, ['full', 'half'], true)) {
    $payment_choice = 'full';
}

// 1. Validate order in correct table
if (!$is_job) {
    $order_result = db_query("SELECT * FROM orders WHERE order_id = ? AND customer_id = ?", 'ii', [$order_id, $customer_id]);
    if (empty($order_result)) {
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }
    $order = $order_result[0];
    $total_to_pay = (float)$order['total_amount'];
} else {
    $order_result = db_query("SELECT * FROM job_orders WHERE id = ? AND customer_id = ?", 'ii', [$order_id, $customer_id]);
    if (empty($order_result)) {
        echo json_encode(['success' => false, 'message' => 'Job Order not found']);
        exit;
    }
    $order = $order_result[0];
    $total_to_pay = (float)$order['estimated_total'];
}

// Validate amount and proof
$amount = (float)($_POST['amount'] ?? 0);
$min_required = ($payment_choice === 'half') ? $total_to_pay * 0.5 : $total_to_pay;

if ($amount < $min_required - 0.01) {
    echo json_encode(['success' => false, 'message' => 'Amount must be at least ' . ($payment_choice === 'half' ? '50%' : '100%') . ' of the total (' . format_currency($min_required) . ')']);
    exit;
}

if (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Please upload a proof of payment.']);
    exit;
}

// Accept common mobile formats (including HEIC/HEIF) and PDFs; allow up to 25MB uploads
$upload = upload_file($_FILES['payment_proof'], ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'heic', 'heif'], 'payments', null, 25 * 1024 * 1024);
if (!$upload['success']) {
    echo json_encode(['success' => false, 'message' => 'Upload failed: ' . $upload['error']]);
    exit;
}

$file_path = $upload['file_path'];
$payment_type = ($payment_choice === 'half') ? '50_percent' : 'full_payment';

if (!$is_job) {
    // 3. Update regular order
    $sql = "UPDATE orders SET 
            status = 'To Verify', 
            payment_type = ?,
            downpayment_amount = ?, 
            payment_proof = ?, 
            payment_submitted_at = NOW() 
            WHERE order_id = ?";
    $update_success = db_execute($sql, 'sdsi', [$payment_type, $amount, $file_path, $order_id]);
    $type_label = "Order";
    if ($update_success) {
        db_execute(
            "DELETE FROM order_messages WHERE order_id = ? AND message_type IN ('pay_reject','staff_pay_rejected')",
            'i',
            [$order_id]
        );
        if (db_table_has_column('orders', 'payment_proof_needs_resubmit')) {
            db_execute(
                'UPDATE orders SET payment_proof_needs_resubmit = 0 WHERE order_id = ?',
                'i',
                [$order_id]
            );
        }
    }
} else {
    // 4. Update job order
    $sql = "UPDATE job_orders SET 
            status = 'VERIFY_PAY',
            payment_proof_status = 'SUBMITTED', 
            payment_proof_path = ?, 
            payment_submitted_amount = ?, 
            payment_proof_uploaded_at = NOW() 
            WHERE id = ?";
    $update_success = db_execute($sql, 'sdi', [$file_path, $amount, $order_id]);
    $type_label = "Job Order";
}

if ($update_success) {
    if (!$is_job) {
        // Fixed product orders: customer message MUST be inserted first
        // so it appears before the system "We have received your payment" message.
        $order_type_row = db_query("SELECT order_type FROM orders WHERE order_id = ?", 'i', [$order_id]);
        if (!empty($order_type_row) && ($order_type_row[0]['order_type'] ?? '') === 'product') {
            $prod_pay_msg = "A new product order has been paid. Please verify the payment and process the order.";
            db_execute(
                "INSERT INTO order_messages (order_id, sender, sender_id, message, message_type, read_receipt) VALUES (?, 'Customer', ?, ?, 'text', 0)",
                'iis', [$order_id, $customer_id, $prod_pay_msg]
            );
        }

        // System confirmation message comes AFTER the customer message
        printflow_send_order_update($order_id, 'payment_submitted');
    }


    // Keep linked production jobs in sync so staff Customizations → TO_VERIFY tab shows this row
    // (store payment only touched `orders`; merged list often hides ORDER when any JOB exists).
    if (!$is_job) {
        JobOrderService::ensureJobsForStoreOrder($order_id);
        db_execute(
            "UPDATE job_orders SET
                status = 'VERIFY_PAY',
                payment_proof_status = 'SUBMITTED',
                payment_submitted_amount = ?,
                payment_proof_path = ?,
                payment_proof_uploaded_at = NOW()
             WHERE order_id = ?
               AND status NOT IN ('COMPLETED','CANCELLED')",
            'dsi',
            [$amount, $file_path, $order_id]
        );
    }

    // Notify staff
    $customer_name_row = db_query("SELECT first_name, last_name FROM customers WHERE customer_id = ?", 'i', [$customer_id]);
    $customer_display_name = !empty($customer_name_row) ? trim($customer_name_row[0]['first_name'] . ' ' . $customer_name_row[0]['last_name']) : "Customer";
    
    // Determine Service Name
    $service_name = "Order";
    if (!$is_job) {
        $first_item = db_query("SELECT customization_data FROM order_items WHERE order_id = ? LIMIT 1", 'i', [$order_id]);
        $custom_data = !empty($first_item[0]['customization_data']) ? json_decode($first_item[0]['customization_data'], true) : [];
        $service_name = get_service_name_from_customization($custom_data, 'Product Order');
    } else {
        $service_name = normalize_service_name($order['service_type'] ?? 'Custom Job');
    }

    // Determine if it was a resubmission
    $is_resubmission = false;
    if (!$is_job) {
        // If regular order status implies we backtracked from a previous verification
        if (in_array($order['status'], ['To Pay', 'For Revision']) && !empty($order['payment_submitted_at'])) {
            $is_resubmission = true;
        }
    } else {
        // For job orders, we explicitly track REJECTED status
        if (($order['payment_proof_status'] ?? '') === 'REJECTED') {
            $is_resubmission = true;
        }
    }

    $action_verb = $is_resubmission ? "resubmitted" : "submitted";
    $staff_msg = "{$customer_display_name} {$action_verb} payment for {$service_name}";
    
    // Notify shop users using each user's actual role so push subscriptions match.
    notify_shop_users($staff_msg, 'Order', false, false, $order_id, ['Staff', 'Admin', 'Manager']);
    
    // Log activity (if staff logged in, otherwise skip)
    log_activity($customer_id, 'Payment Submitted', "Submitted proof for {$type_label} #{$order_id}");

    // Clear the cart items now that payment is submitted
    if (!empty($_SESSION['last_order_item_key'])) {
        $item_keys = explode(',', $_SESSION['last_order_item_key']);
        foreach ($item_keys as $key) {
            $key = trim($key);
            if (isset($_SESSION['cart'][$key])) {
                unset($_SESSION['cart'][$key]);
            }
        }
        unset($_SESSION['last_order_item_key']);
        sync_cart_to_db($customer_id);
    }

    echo json_encode([
        'success' => true, 
        'message' => 'Payment submitted successfully. Waiting for staff verification.',
        'file_path' => $file_path
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database update failed. Please try again.']);
}

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
