<?php
/**
 * API: Verify Payment Proof (Staff)
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ensure_orders_status_schema.php';

require_role(['Admin', 'Staff', 'Manager']);

/**
 * Ensure orders row can record "staff rejected payment proof, customer must resubmit".
 * Scoped column so tabs do not rely on fragile order_messages/message_type quirks.
 */
function printflow_ensure_orders_payment_proof_needs_resubmit_column(): bool {
    if (db_table_has_column('orders', 'payment_proof_needs_resubmit')) {
        return true;
    }
    global $conn;
    if (!($conn instanceof mysqli)) {
        return false;
    }
    $ok = (bool)$conn->query(
        'ALTER TABLE `orders` ADD COLUMN `payment_proof_needs_resubmit` TINYINT(1) NOT NULL DEFAULT 0'
    );
    db_table_has_column('orders', 'payment_proof_needs_resubmit', true);
    return $ok;
}

/**
 * Clear payment submission on orders after rejection. Builds SET clauses from columns that exist
 * so missing migrations (e.g. rejection_reason) do not fail the whole update.
 *
 * Branch was already enforced above; updating by order_id avoids AND branch_id = ? skipping rows
 * when branch_id is NULL/0 while access is granted via listings.
 */
function printflow_staff_apply_orders_payment_proof_rejection(int $orderId, string $newStatus, string $reason): bool {
    printflow_ensure_orders_payment_proof_needs_resubmit_column();

    $sets = ['status = ?'];
    $types = 's';
    $params = [$newStatus];

    // Keep the last uploaded proof path on file + in DB so staff/customer can audit and api_view_proof authorizes reliably.

    $reasonCol = null;
    if (db_table_has_column('orders', 'rejection_reason')) {
        $reasonCol = 'rejection_reason';
    } elseif (db_table_has_column('orders', 'payment_rejection_reason')) {
        $reasonCol = 'payment_rejection_reason';
    }
    if ($reasonCol !== null) {
        $sets[] = "`{$reasonCol}` = ?";
        $types .= 's';
        $params[] = $reason;
    }

    if (db_table_has_column('orders', 'payment_status')) {
        $sets[] = 'payment_status = ?';
        $types .= 's';
        $params[] = 'Unpaid';
    }
    if (db_table_has_column('orders', 'payment_proof_needs_resubmit')) {
        $sets[] = 'payment_proof_needs_resubmit = 1';
    }

    $sql = 'UPDATE orders SET ' . implode(', ', $sets) . ' WHERE order_id = ?';
    $types .= 'i';
    $params[] = $orderId;

    return (bool)db_execute($sql, $types, $params);
}

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
$sync_warning = '';

// ENUM/VARCHAR widen so statuses like Rejected save reliably (noop if already migrated).
printflow_ensure_orders_status_schema();

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
        // Ready-made product orders must always skip production workflow and go straight to pickup,
        // even if legacy/misclassified job_orders rows already exist.
        $isPlainProductOrder = (($order['order_type'] ?? '') === 'product');
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
                        // Move service jobs to IN_PRODUCTION. Keep payment success even if
                        // inventory deduction sync fails and needs manual follow-up.
                        try {
                            JobOrderService::updateStatus($job['id'], 'IN_PRODUCTION', null, '', $notified);
                        } catch (Throwable $statusSyncError) {
                            db_execute(
                                "UPDATE job_orders SET status = 'IN_PRODUCTION', updated_at = NOW() WHERE id = ?",
                                'i',
                                [$job['id']]
                            );
                            $sync_warning = 'Payment approved, but inventory deduction needs follow-up.';
                            error_log('PrintFlow staff verify payment warning for job #' . (int)$job['id'] . ': ' . $statusSyncError->getMessage());
                        }
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
        // Payment proof rejected — explicit status so lists/modals/customer flows recognize it (not "still verifying").
        $new_status = 'Rejected';
        $reason = $_POST['reason'] ?? 'Payment proof rejected by staff.';
        
        // Get product name for better message context
        $product_name = 'your order';
        $items = db_query("SELECT service_type FROM order_items WHERE order_id = ? LIMIT 1", "i", [$order_id]);
        if (!empty($items)) {
            $product_name = $items[0]['service_type'];
        }
        
        // Persist Rejected status + markers; uploaded proof stays in DB/on disk until customer replaces it.
        $success = printflow_staff_apply_orders_payment_proof_rejection($order_id, $new_status, $reason);
        if ($success && db_table_has_column('orders', 'payment_status')) {
            $payment_status = 'Unpaid';
        }
        
        if ($success) {
            $msg = "Your payment proof was rejected. Reason: " . $reason . ". Please resubmit your payment proof.";
            if (!empty($order['customer_id'])) {
                create_notification((int)$order['customer_id'], 'Customer', $msg, 'Order', false, false, $order_id);
            }
            
            // Send order update chat message for rejection
            require_once __DIR__ . '/../includes/order_chat_system.php';
            if (($order['order_type'] ?? '') === 'product') {
                // Fixed product order: use specific rejection message from staff (marker type drives staff Rejected tab)
                $prod_reject_msg = "Your payment has been rejected. Reason: {$reason}. Please resubmit your payment based on the feedback provided.";
                // Keep short VARCHAR(10) safe; duplicates legacy staff_pay_rejected in SQL predicates.
                db_execute(
                    "INSERT INTO order_messages (order_id, sender, sender_id, message, message_type, read_receipt) VALUES (?, 'Staff', ?, ?, 'pay_reject', 0)",
                    'iis',
                    [$order_id, $staff_id, $prod_reject_msg]
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

            // Mirror checkout flow: guarantees at least one job row so payment flags persist (fixes empty Rejected tab).
            require_once __DIR__ . '/../includes/JobOrderService.php';
            JobOrderService::ensureJobsForStoreOrder($order_id);

            db_execute(
                "UPDATE job_orders SET payment_proof_status = 'REJECTED', status = 'TO_PAY',
                 payment_rejection_reason = ?,
                 payment_submitted_amount = 0,
                 payment_proof_uploaded_at = NULL
                 WHERE order_id = ? AND status NOT IN ('COMPLETED','CANCELLED')",
                'si',
                [$reason, $order_id]
            );

            // Proof file intentionally retained — staff can reopen the rejected proof; clearing DB paths caused 403/404 churn.
        }
    }
} catch (Exception $e) {
    $success = false;
    $error_message = 'Database error: ' . $e->getMessage();
}

if ($success) {
    if (db_table_has_column('orders', 'payment_proof_needs_resubmit')) {
        if ($action === 'Approve') {
            db_execute(
                'UPDATE orders SET payment_proof_needs_resubmit = 0 WHERE order_id = ?',
                'i',
                [$order_id]
            );
        }
    }
    if ($action === 'Approve') {
        db_execute(
            "DELETE FROM order_messages WHERE order_id = ? AND message_type IN ('pay_reject','staff_pay_rejected')",
            'i',
            [$order_id]
        );
    }
    echo json_encode([
        'success' => true,
        'new_status' => $new_status,
        'payment_status' => $payment_status,
        'warning' => $sync_warning
    ]);
} else {
    echo json_encode(['success' => false, 'error' => $error_message ?: 'Database update failed']);
}
