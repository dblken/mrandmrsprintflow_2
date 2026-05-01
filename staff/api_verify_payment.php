<?php
/**
 * API: Verify Payment Proof (Staff)
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/db.php';

require_role(['Admin', 'Staff', 'Manager']);

$staffBranchId = null;
if (is_staff() || is_manager()) {
    $staffBranchId = printflow_branch_filter_for_user() ?? (int)($_SESSION['branch_id'] ?? 1);
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$order_id = (int)($_POST['order_id'] ?? 0);
$action = $_POST['action'] ?? ''; // 'Approve' or 'Reject'

if (!$order_id || !in_array($action, ['Approve', 'Reject'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Get order details then validate branch with the same rules used by the staff listing.
$order_result = db_query("SELECT * FROM orders WHERE order_id = ?", 'i', [$order_id]);
if (empty($order_result)) {
    echo json_encode(['success' => false, 'error' => 'Order not found']);
    exit;
}
$order = $order_result[0];
$orderBranchId = (int)($order['branch_id'] ?? 0);
if ($staffBranchId !== null) {
    $branchMatches =
        ($orderBranchId > 0 && $orderBranchId === (int)$staffBranchId) ||
        printflow_order_in_branch($order_id, (int)$staffBranchId);
    if (!$branchMatches) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

$staff_id = get_user_id();
$new_status = '';
$payment_status = $order['payment_status'];
$success = false;
$error_message = '';

try {
    if ($action === 'Approve') {
        require_once __DIR__ . '/../includes/JobOrderService.php';
        global $conn;
        $orderType = strtolower(trim((string)($order['order_type'] ?? '')));
        if ($orderType !== 'product') {
            JobOrderService::ensureJobsForStoreOrder($order_id);
        }
        $jobs = db_query(
            "SELECT id FROM job_orders WHERE order_id = ? AND status NOT IN ('COMPLETED', 'CANCELLED')",
            'i',
            [$order_id]
        ) ?: [];
        $hasProductionJobs = !empty($jobs);
        $isPlainProductOrder = (($order['order_type'] ?? '') === 'product') && !$hasProductionJobs;
        if (!$isPlainProductOrder && !$hasProductionJobs) {
            throw new Exception('Cannot verify payment: no linked production job found for this service order.');
        }
        $new_status = $isPlainProductOrder ? 'Ready for Pickup' : 'Processing';
        $payment_status = 'Paid';
        
        // Get product name for better message context
        $product_name = 'your order';
        $items = db_query("SELECT service_type FROM order_items WHERE order_id = ? LIMIT 1", "i", [$order_id]);
        if (!empty($items)) {
            $product_name = $items[0]['service_type'];
        }

        $conn->begin_transaction();
        try {
            // Update order
            if ($staffBranchId !== null) {
                $sql = "UPDATE orders SET status = ?, payment_status = ? WHERE order_id = ? AND branch_id = ?";
                $success = db_execute($sql, 'ssii', [$new_status, $payment_status, $order_id, $staffBranchId]);
            } else {
                $sql = "UPDATE orders SET status = ?, payment_status = ? WHERE order_id = ?";
                $success = db_execute($sql, 'ssi', [$new_status, $payment_status, $order_id]);
            }

            if (!$success) {
                throw new Exception('Database update failed');
            }

            $msg = $isPlainProductOrder 
                ? "Your payment has been approved, and your order is now ready for pickup!" 
                : "Your payment has been approved. We will now proceed with processing your order.";
            
            if (!empty($order['customer_id'])) {
                create_notification((int)$order['customer_id'], 'Customer', $msg, 'Order', false, false, $order_id);
            }
            
            // Send order update chat message (ONLY if not handled by JobOrderService below)
            if (!$hasProductionJobs || $isPlainProductOrder) {
                require_once __DIR__ . '/../includes/order_chat_system.php';
                if ($isPlainProductOrder) {
                    // Fixed product order: send specific pickup message from staff
                    $pickup_msg = "Your payment has been approved. Your order is now ready for pickup.";
                    db_execute(
                        "INSERT INTO order_messages (order_id, sender, sender_id, message, message_type, read_receipt) VALUES (?, 'Staff', ?, ?, 'order_update', 0)",
                        'iis', [$order_id, $staff_id, $pickup_msg]
                    );
                } else {
                    $meta = [
                        'order_id' => $order_id,
                        'product_name' => $product_name,
                        'order_status' => $new_status,
                        'payment_status' => $payment_status,
                        'step' => 'payment_verified'
                    ];
                    printflow_send_order_update($order_id, 'payment_verified', 'view_status', '', '', $meta);
                }
            }

            
            $log_desc = $isPlainProductOrder 
                ? "Approved payment for Order #{$order_id}, moved to Ready for Pickup" 
                : "Approved payment for Order #{$order_id}, moved to In Production";
            log_activity($staff_id, 'Payment Approved', $log_desc);
            
            // Update linked job_orders and trigger inventory deduction via JobOrderService
            if ($hasProductionJobs) {
                $notified = false;
                foreach ($jobs as $job) {
                    if ($orderBranchId > 0) {
                        db_execute(
                            "UPDATE job_orders SET branch_id = ? WHERE id = ?",
                            'ii',
                            [$orderBranchId, (int)$job['id']]
                        );
                    }
                    // Update payment fields first
                    db_execute(
                        "UPDATE job_orders SET payment_proof_status = 'VERIFIED', payment_status = 'PAID', amount_paid = estimated_total WHERE id = ?",
                        'i',
                        [$job['id']]
                    );
                    if ($isPlainProductOrder) {
                        // Move product jobs straight to READY_TO_COLLECT
                        db_execute("UPDATE job_orders SET status = 'READY_TO_COLLECT' WHERE id = ?", 'i', [$job['id']]);
                    } else {
                        // Move service jobs to IN_PRODUCTION (triggers inventory deduction)
                        JobOrderService::updateStatus($job['id'], 'IN_PRODUCTION', null, '', $notified);
                        $notified = true;
                    }
                }
            }

            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->in_transaction ?? false) {
                $conn->rollback();
            }
            throw $e;
        }
    } else {
        // Rejected - move back to To Pay or Pending
        $new_status = 'To Pay';
        $reason = $_POST['reason'] ?? 'Payment proof rejected by staff.';
        
        // Get product name for better message context
        $product_name = 'your order';
        $items = db_query("SELECT service_type FROM order_items WHERE order_id = ? LIMIT 1", "i", [$order_id]);
        if (!empty($items)) {
            $product_name = $items[0]['service_type'];
        }
        
        // Clear proof so they can re-upload
        if ($staffBranchId !== null) {
            $sql = "UPDATE orders SET status = ?, payment_proof = NULL, rejection_reason = ? WHERE order_id = ? AND branch_id = ?";
            $success = db_execute($sql, 'ssii', [$new_status, $reason, $order_id, $staffBranchId]);
        } else {
            $sql = "UPDATE orders SET status = ?, payment_proof = NULL, rejection_reason = ? WHERE order_id = ?";
            $success = db_execute($sql, 'ssi', [$new_status, $reason, $order_id]);
        }
        
        if ($success) {
            $msg = "Your payment proof was rejected. Reason: " . $reason . ". Please resubmit your payment proof.";
            if (!empty($order['customer_id'])) {
                create_notification((int)$order['customer_id'], 'Customer', $msg, 'Order', false, false, $order_id);
            }
            
            // Send order update chat message for rejection
            require_once __DIR__ . '/../includes/order_chat_system.php';
            if (($order['order_type'] ?? '') === 'product') {
                // Fixed product order: use specific rejection message from staff
                $prod_reject_msg = "Your payment has been rejected. Reason: {$reason}. Please resubmit your payment based on the feedback provided.";
                db_execute(
                    "INSERT INTO order_messages (order_id, sender, sender_id, message, message_type, read_receipt) VALUES (?, 'Staff', ?, ?, 'order_update', 0)",
                    'iis', [$order_id, $staff_id, $prod_reject_msg]
                );
            } else {
                $meta = [
                    'order_id' => $order_id,
                    'product_name' => $product_name,
                    'order_status' => $new_status,
                    'payment_status' => 'Rejected',
                    'reason' => $reason,
                    'step' => 'payment_rejected'
                ];
                printflow_send_order_update($order_id, 'payment_rejected', 'retry_payment', '', '', $meta);
            }

            
            log_activity($staff_id, 'Payment Rejected', "Rejected payment for Order #{$order_id}. Reason: {$reason}");
            db_execute(
                "UPDATE job_orders SET payment_proof_status = 'REJECTED', status = 'TO_PAY',
                 payment_rejection_reason = ?,
                 payment_proof_path = NULL, payment_submitted_amount = 0, payment_proof_uploaded_at = NULL
                 WHERE order_id = ? AND status NOT IN ('COMPLETED','CANCELLED')",
                'si',
                [$reason, $order_id]
            );

            // Delete the file if it exists to save space (optional, but cleaner)
            $proof = $order['payment_proof'] ?? '';
            if ($proof !== '') {
                $rel = ltrim(str_replace('\\', '/', $proof), '/');
                if (strpos($rel, 'printflow/') === 0) {
                    $rel = substr($rel, strlen('printflow/'));
                }
                $abs = __DIR__ . '/../' . $rel;
                if (is_file($abs)) {
                    @unlink($abs);
                }
            }
        }
    }
} catch (Exception $e) {
    $success = false;
    $error_message = 'Database error: ' . $e->getMessage();
}

if ($success) {
    echo json_encode([
        'success' => true,
        'new_status' => $new_status,
        'payment_status' => $payment_status
    ]);
} else {
    echo json_encode(['success' => false, 'error' => $error_message ?: 'Database update failed']);
}
