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
require_once __DIR__ . '/../includes/payment_verification.php';
require_once __DIR__ . '/../includes/provider_payments.php';

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
        $params[] = 'Rejected';
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

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(419);
    echo json_encode([
        'success' => false,
        'error' => 'Your session expired. Please refresh and try again.',
        'csrf_token' => generate_csrf_token(),
    ]);
    exit;
}

$order_id = (int)($_POST['order_id'] ?? 0);
$action = $_POST['action'] ?? ''; // 'Approve' or 'Reject'
$submission_id = (int)($_POST['submission_id'] ?? 0);
$submission_notes = mb_substr(trim((string)($_POST['staff_notes'] ?? '')), 0, 5000);

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
$submission_id = $submission_id > 0 ? $submission_id : payment_verification_latest_submission_id($order_id, 0);
$submission = $submission_id > 0 ? payment_verification_get_submission($submission_id) : null;
if (!$submission) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'No payment proof submission is available for this order.']);
    exit;
}
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
if ($submission && (int)($submission['order_id'] ?? 0) !== $order_id) {
    echo json_encode(['success' => false, 'error' => 'Payment submission does not belong to this order.']);
    exit;
}
if ($submission && in_array((string)$submission['verification_status'], ['Approved', 'Rejected'], true)) {
    $expectedDecision = $action === 'Approve' ? 'Approved' : 'Rejected';
    if ((string)$submission['verification_status'] === $expectedDecision) {
        echo json_encode(['success' => true, 'already_processed' => true]);
    } else {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'This payment submission has already been finalized.']);
    }
    exit;
}
if ($action === 'Approve') {
    $providerPayment = printflow_provider_payment_find('order', $order_id, 'online');
    if (($providerPayment['status'] ?? '') === 'paid') {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'This order is already paid through PayMongo.']);
        exit;
    }
}

$staff_id = get_user_id();
$new_status = '';
$payment_status = $order['payment_status'];
$success = false;
$error_message = '';
$sync_warning = '';
$transaction_started = false;

$lock_submission_for_decision = static function () use ($submission_id, $order_id, $action): void {
    global $conn;
    $rows = db_query(
        'SELECT id, order_id, verification_status FROM payment_submissions WHERE id = ? LIMIT 1 FOR UPDATE',
        'i',
        [$submission_id]
    ) ?: [];
    if (empty($rows) || (int)($rows[0]['order_id'] ?? 0) !== $order_id) {
        throw new RuntimeException('The payment proof submission no longer belongs to this order.');
    }

    $current = (string)($rows[0]['verification_status'] ?? '');
    if (!in_array($current, ['Approved', 'Rejected'], true)) {
        return;
    }

    $expected = $action === 'Approve' ? 'Approved' : 'Rejected';
    $conn->rollback();
    if ($current === $expected) {
        echo json_encode(['success' => true, 'already_processed' => true]);
    } else {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'This payment submission has already been finalized.']);
    }
    exit;
};

$lock_order_for_decision = static function () use ($order_id, $staffBranchId): array {
    $rows = db_query('SELECT * FROM orders WHERE order_id = ? LIMIT 1 FOR UPDATE', 'i', [$order_id]) ?: [];
    if (empty($rows)) {
        throw new RuntimeException('The order no longer exists.');
    }
    $lockedOrder = $rows[0];
    $lockedBranchId = (int)($lockedOrder['branch_id'] ?? 0);
    if ($staffBranchId !== null) {
        $branchMatches = ($lockedBranchId > 0 && $lockedBranchId === (int)$staffBranchId)
            || printflow_order_in_branch($order_id, (int)$staffBranchId);
        if (!$branchMatches) {
            throw new RuntimeException('The order is outside the staff branch scope.');
        }
    }

    if (strcasecmp(trim((string)($lockedOrder['payment_status'] ?? '')), 'Paid') === 0) {
        throw new RuntimeException('This order is already paid.');
    }
    $lockedStatus = strtoupper(trim((string)($lockedOrder['status'] ?? '')));
    if (in_array($lockedStatus, ['CANCELLED', 'COMPLETED', 'RATED'], true)) {
        throw new RuntimeException('This order can no longer accept a payment decision.');
    }
    return $lockedOrder;
};

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

        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Could not start the payment approval transaction.');
        }
        $transaction_started = true;
        try {
            // Provider callbacks lock the PayMongo ledger before the order.
            // Preserve that lock ordering so a late historical callback cannot
            // race a manual approval into a second paid transition.
            if (printflow_provider_payments_ready()) {
                $providerRows = db_query(
                    "SELECT id, status FROM provider_payments
                     WHERE provider = 'paymongo' AND subject_type = 'order'
                       AND subject_id = ? AND channel = 'online'
                     ORDER BY CASE WHEN status = 'paid' THEN 0 ELSE 1 END, id DESC
                     LIMIT 1 FOR UPDATE",
                    'i',
                    [$order_id]
                ) ?: [];
                if (($providerRows[0]['status'] ?? '') === 'paid') {
                    throw new RuntimeException('This order is already paid through PayMongo.');
                }
            }
            $lock_submission_for_decision();
            $order = $lock_order_for_decision();
            $orderBranchId = (int)($order['branch_id'] ?? 0);
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

            if (!payment_verification_mark_order_decision(
                $submission_id,
                $order_id,
                0,
                'Approved',
                $staff_id,
                '',
                $submission_notes
            )) {
                throw new Exception('Payment submission was already finalized.');
            }

            $approved_amount = payment_verification_expected_amount($order_id);
            $msg = 'Your payment of ' . format_currency($approved_amount) . ' has been verified and approved.';
            if ($isPlainProductOrder) {
                $msg .= ' Your order is now ready for pickup.';
            }
            
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

            if (!$conn->commit()) {
                throw new RuntimeException('Could not commit the payment approval.');
            }
            $transaction_started = false;
        } catch (Throwable $e) {
            if ($transaction_started || ($conn->in_transaction ?? false)) {
                $conn->rollback();
                $transaction_started = false;
            }
            throw $e;
        }
    } else {
        // Payment proof rejected — explicit status so lists/modals/customer flows recognize it (not "still verifying").
        // Prepare linked rows before our transaction because the repair helper
        // may use its own transaction internally.
        require_once __DIR__ . '/../includes/JobOrderService.php';
        JobOrderService::ensureJobsForStoreOrder($order_id);
        global $conn;
        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Could not start the payment rejection transaction.');
        }
        $transaction_started = true;
        $lock_submission_for_decision();
        $order = $lock_order_for_decision();

        $new_status = 'Rejected';
        $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 1000);
        if ($reason === '') {
            $reason = 'Payment proof rejected by staff. Please upload a clearer or corrected receipt.';
        }
        
        // Get product name for better message context
        $product_name = 'your order';
        $items = db_query("SELECT service_type FROM order_items WHERE order_id = ? LIMIT 1", "i", [$order_id]);
        if (!empty($items)) {
            $product_name = $items[0]['service_type'];
        }
        
        // Persist Rejected status + markers; uploaded proof stays in DB/on disk until customer replaces it.
        $success = printflow_staff_apply_orders_payment_proof_rejection($order_id, $new_status, $reason);
        if ($success && db_table_has_column('orders', 'payment_status')) {
            $payment_status = 'Rejected';
        }
        
        if ($success) {
            if (!payment_verification_mark_order_decision(
                $submission_id,
                $order_id,
                0,
                'Rejected',
                $staff_id,
                $reason,
                $submission_notes
            )) {
                throw new RuntimeException('Payment submission was already finalized.');
            }
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

            // Mirror the rejected payment state into linked production rows.
            $jobsRejected = db_execute(
                "UPDATE job_orders SET payment_proof_status = 'REJECTED', status = 'TO_PAY',
                 payment_status = 'UNPAID', amount_paid = 0,
                 payment_rejection_reason = ?,
                 payment_submitted_amount = 0,
                 payment_proof_uploaded_at = NULL
                 WHERE order_id = ? AND status NOT IN ('COMPLETED','CANCELLED')",
                'si',
                [$reason, $order_id]
            );
            if (!$jobsRejected) {
                throw new RuntimeException('Linked production payment state could not be rejected.');
            }

            // Proof file intentionally retained — staff can reopen the rejected proof; clearing DB paths caused 403/404 churn.
        }
        if (!$success) {
            throw new RuntimeException('The rejected payment state could not be saved.');
        }
        if (!$conn->commit()) {
            throw new RuntimeException('Could not commit the payment rejection.');
        }
        $transaction_started = false;
    }
} catch (Throwable $e) {
    if ($transaction_started && isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
        $transaction_started = false;
    }
    $success = false;
    payment_verification_log('store_payment_review_failed', [
        'order_id' => $order_id,
        'staff_id' => $staff_id,
        'action' => $action,
        'reason' => $e->getMessage(),
    ]);
    $error_message = 'The payment review could not be saved. Please try again.';
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
