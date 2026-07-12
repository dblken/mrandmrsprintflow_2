<?php
/**
 * API: Submit Payment Proof
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/JobOrderService.php';
require_once __DIR__ . '/../includes/payment_verification.php';

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
$selected_payment_method = payment_verification_normalize_method((string)($_POST['selected_payment_method'] ?? 'GCash'));
if ($selected_payment_method === '') {
    $selected_payment_method = 'GCash';
}
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

// Store receipts in the protected payment directory after server-side MIME validation.
$upload = payment_verification_store_receipt($_FILES['payment_proof']);
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
            payment_method = ?,
            payment_submitted_amount = ?, 
            payment_proof_uploaded_at = NOW() 
            WHERE id = ?";
    $update_success = db_execute($sql, 'ssdi', [$file_path, $selected_payment_method, $amount, $order_id]);
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
                payment_method = ?,
                payment_proof_uploaded_at = NOW()
             WHERE order_id = ?
               AND status NOT IN ('COMPLETED','CANCELLED')",
            'dssi',
            [$amount, $file_path, $selected_payment_method, $order_id]
        );
    }

    // OCR is advisory and stored separately so existing payment/order fields remain untouched.
    $submission_id = payment_verification_create_submission([
        'order_id' => $is_job ? (int)($order['order_id'] ?? 0) : $order_id,
        'job_order_id' => $is_job ? $order_id : 0,
        'customer_id' => $customer_id,
        'receipt_file' => $file_path,
        'receipt_thumbnail' => (string)($upload['thumbnail_path'] ?? ''),
        'receipt_original_name' => (string)($upload['original_name'] ?? ''),
        'receipt_mime' => (string)($upload['mime'] ?? ''),
        'receipt_size' => (int)($upload['size'] ?? 0),
        'receipt_sha256' => (string)($upload['sha256'] ?? ''),
        'selected_payment_method' => $selected_payment_method,
        'submitted_amount' => $amount,
    ]);
    if ($submission_id <= 0) {
        error_log("Payment submission audit row could not be created for order #{$order_id}.");
    }

    payment_verification_notify_reviewers(
        $is_job ? (int)($order['order_id'] ?? 0) : $order_id,
        $is_job ? $order_id : 0
    );
    
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
        'message' => 'Payment proof submitted. Your payment is pending staff verification.',
        'file_path' => $file_path,
        'submission_id' => $submission_id ?? 0,
        'ocr_status' => ($submission_id ?? 0) > 0 ? 'Pending' : 'Unavailable'
    ]);
} else {
    if (empty($update_success)) {
        if (!empty($upload['local_path']) && is_file($upload['local_path'])) {
            @unlink($upload['local_path']);
        }
        if (!empty($upload['thumbnail_path'])) {
            $thumbnailLocal = payment_verification_local_file((string)$upload['thumbnail_path']);
            if ($thumbnailLocal) @unlink($thumbnailLocal);
        }
    }
    echo json_encode(['success' => false, 'message' => 'Database update failed. Please try again.']);
}

} catch (Throwable $e) {
    if (empty($update_success)) {
        if (!empty($upload['local_path']) && is_file($upload['local_path'])) {
            @unlink($upload['local_path']);
        }
        if (!empty($upload['thumbnail_path'])) {
            $thumbnailLocal = payment_verification_local_file((string)$upload['thumbnail_path']);
            if ($thumbnailLocal) @unlink($thumbnailLocal);
        }
    }
    error_log('Payment proof submission failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'The payment proof could not be submitted. Please try again.']);
}
