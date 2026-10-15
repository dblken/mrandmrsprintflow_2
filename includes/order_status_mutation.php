<?php

declare(strict_types=1);

/**
 * Shared staff order-status mutation (used by single-order and bulk completion endpoints).
 */

require_once __DIR__ . '/product_branch_stock.php';
require_once __DIR__ . '/product_option_stock.php';
require_once __DIR__ . '/InventoryManager.php';
require_once __DIR__ . '/JobOrderService.php';
require_once __DIR__ . '/provider_payments.php';
require_once __DIR__ . '/change_item_workflow.php';
require_once __DIR__ . '/order_completion_payment.php';
require_once __DIR__ . '/order_completion_override.php';

if (!function_exists('printflow_staff_mutate_order_status')) {
    /**
     * @param array{
     *   cancel_reason?: string,
     *   change_item_id?: int,
     *   complete_change_item?: bool,
     *   manage_transaction?: bool,
     * } $options
     * @return array<string, mixed>
     */
    function printflow_staff_mutate_order_status(
        int $orderId,
        string $newStatus,
        string $expectedStatus,
        array $options = []
    ): array {
        $cancelReason = trim((string)($options['cancel_reason'] ?? ''));
        $changeItemId = max(0, (int)($options['change_item_id'] ?? 0));
        $completeChangeItem = !empty($options['complete_change_item']) || $changeItemId > 0;
        $allowPendingCompletionOverride = !empty($options['allow_pending_completion_override']);
        $confirmUnpaidCompletion = !empty($options['confirm_unpaid_completion']);
        $manageTransaction = !array_key_exists('manage_transaction', $options)
            || (bool)$options['manage_transaction'];

        global $conn;
        $transactionStarted = $manageTransaction && !printflow_db_in_transaction($conn);

        try {
            if ($transactionStarted && !printflow_db_transaction_depth_bump($conn)) {
                throw new RuntimeException('Unable to start order update transaction.');
            }

            $amountPaidSelect = db_table_has_column('orders', 'amount_paid') ? ', amount_paid' : '';
            $rows = db_query(
                'SELECT status, payment_status, total_amount, branch_id, customer_id, order_type, order_source, reference_id, order_date'
                . $amountPaidSelect
                . ' FROM orders WHERE order_id = ? LIMIT 1 FOR UPDATE',
                'i',
                [$orderId]
            ) ?: [];
            if ($rows === []) {
                if ($transactionStarted) {
                    printflow_db_transaction_depth_pop($conn, false);
                }
                return ['success' => false, 'error' => 'Order not found.', '_http_status' => 404];
            }

            $order = $rows[0];
            $oldStatus = trim((string)($order['status'] ?? ''));
            $actorRole = (string)get_user_type();
            $actorId = (int)get_user_id();
            if ($confirmUnpaidCompletion && !$allowPendingCompletionOverride) {
                if ($transactionStarted) printflow_db_transaction_depth_pop($conn, false);
                return ['success' => false, 'error' => 'The unpaid completion confirmation is invalid.', '_http_status' => 422];
            }
            if ($allowPendingCompletionOverride) {
                $staffCanUseCustomizations = $actorRole !== 'Staff'
                    || (function_exists('printflow_staff_can_access_module') && printflow_staff_can_access_module('customizations'));
                if (!in_array($actorRole, ['Admin', 'Manager', 'Staff'], true) || !$staffCanUseCustomizations) {
                    if ($transactionStarted) printflow_db_transaction_depth_pop($conn, false);
                    return ['success' => false, 'error' => 'You are not authorized to complete orders from Customizations.', '_http_status' => 403];
                }
                if (!printflow_order_completion_override_date_in_scope((string)($order['order_date'] ?? ''))) {
                    if ($transactionStarted) printflow_db_transaction_depth_pop($conn, false);
                    return ['success' => false, 'error' => 'The completion override is limited to orders dated September 1 through October 5, 2026.', '_http_status' => 409];
                }
                if (!printflow_order_completion_override_pending_status($oldStatus)
                    && printflow_workflow_status_key($oldStatus) !== 'COMPLETED') {
                    if ($transactionStarted) printflow_db_transaction_depth_pop($conn, false);
                    return ['success' => false, 'error' => 'Only pending orders in the selected date range can use this completion override.', '_http_status' => 409];
                }
            }
            $canonicalStatus = static function (string $status): string {
                return printflow_workflow_status_key($status);
            };

            $unpaidOverrideApplied = false;
            if ($newStatus === 'Completed') {
                $activeChangeItem = printflow_change_item_get_active($orderId);
                $changeItemInRework = $activeChangeItem !== null
                    && in_array(
                        printflow_change_item_normalize_status((string)($activeChangeItem['request_status'] ?? '')),
                        ['IN_REWORK', 'APPROVED'],
                        true
                    );
                $parentAlreadyCompleted = $canonicalStatus($oldStatus) === 'COMPLETED';
                if ($completeChangeItem || ($changeItemInRework && $parentAlreadyCompleted)) {
                    if (getenv('PRINTFLOW_NOTIFICATION_DEBUG') === '1') {
                        error_log('[order-status][change_item] user_id=' . (int)get_user_id()
                            . ' role=' . (string)get_user_type()
                            . ' order_id=' . $orderId
                            . ' change_item_id=' . $changeItemId
                            . ' expected_status=' . $expectedStatus);
                    }
                    try {
                        $changeResult = printflow_change_item_complete_staff_action(
                            $orderId,
                            $changeItemId,
                            $expectedStatus
                        );
                        if ($transactionStarted) {
                            printflow_db_transaction_depth_pop($conn, true);
                        }
                        $changeResult['_http_status'] = ($changeResult['success'] ?? false) ? 200 : 422;
                        return $changeResult;
                    } catch (InvalidArgumentException $validationError) {
                        if ($transactionStarted) {
                            printflow_db_transaction_depth_pop($conn, false);
                        }
                        return ['success' => false, 'error' => $validationError->getMessage(), '_http_status' => 422];
                    } catch (RuntimeException $runtimeError) {
                        if ($transactionStarted) {
                            printflow_db_transaction_depth_pop($conn, false);
                        }
                        $message = $runtimeError->getMessage();
                        if (str_starts_with($message, 'CHANGE_ITEM_STATUS_CONFLICT:')) {
                            return [
                                'success' => false,
                                'error' => 'This Change Item was updated by another staff member. Please refresh and review the latest status.',
                                'current_status' => substr($message, strlen('CHANGE_ITEM_STATUS_CONFLICT:')),
                                'change_item_conflict' => true,
                                '_http_status' => 409,
                            ];
                        }
                        throw $runtimeError;
                    }
                }
            }

            $requestingCompletedEarly = strcasecmp($newStatus, 'Completed') === 0;
            $alreadyCompletedEarly = $canonicalStatus($oldStatus) === 'COMPLETED';
            $statusCompatible = printflow_workflow_status_compatible($expectedStatus, $oldStatus);
            if (!$statusCompatible && $requestingCompletedEarly && !$alreadyCompletedEarly) {
                $orderSourceEarly = strtolower(trim((string)($order['order_source'] ?? '')));
                $expectedKey = printflow_workflow_status_key($expectedStatus);
                $actualKey = printflow_workflow_status_key($oldStatus);
                if (in_array($orderSourceEarly, ['pos', 'walk-in'], true)
                    && !in_array($actualKey, ['COMPLETED', 'CANCELLED', 'REJECTED'], true)
                    && ($expectedKey === 'PENDING' || $expectedKey === 'IN_PRODUCTION' || $expectedKey === 'APPROVED')) {
                    $statusCompatible = true;
                }
            }
            if (!$statusCompatible) {
                if (!($requestingCompletedEarly && $alreadyCompletedEarly)) {
                    if ($transactionStarted) {
                        printflow_db_transaction_depth_pop($conn, false);
                    }
                    return [
                        'success' => false,
                        'error' => 'This order was updated by another staff member. Please refresh and review the latest status.',
                        'current_status' => $oldStatus,
                        '_http_status' => 409,
                    ];
                }
            }
            $customerId = (int)($order['customer_id'] ?? 0);
            $orderType = strtolower(trim((string)($order['order_type'] ?? '')));
            $isProductOrder = $orderType === 'product';
            $isServiceOrder = $orderType === 'custom';
            $isReadyMadeProductOrder = $isProductOrder && printflow_is_ready_made_product_order($order);

            if ($isReadyMadeProductOrder && $newStatus !== 'Completed') {
                if ($transactionStarted) {
                    printflow_db_transaction_depth_pop($conn, false);
                }
                return [
                    'success' => false,
                    'error' => 'Ready-made online products can only be completed after verified payment and customer claim.',
                    '_http_status' => 409,
                ];
            }

            if (strcasecmp($oldStatus, $newStatus) === 0) {
                if ($transactionStarted) {
                    printflow_db_transaction_depth_pop($conn, true);
                }
                return [
                    'success' => true,
                    'changed' => false,
                    'already_completed' => strcasecmp($newStatus, 'Completed') === 0,
                    'order_id' => $orderId,
                    'status' => $newStatus,
                    'message' => "Order #{$orderId} is already {$newStatus}.",
                    '_http_status' => 200,
                ];
            }

            if ($newStatus === 'Completed') {
                if ((float)($order['total_amount'] ?? 0) <= 0) {
                    if ($transactionStarted) {
                        printflow_db_transaction_depth_pop($conn, false);
                    }
                    return [
                        'success' => false,
                        'error' => 'Set a valid price before completing this order.',
                        '_http_status' => 409,
                    ];
                }
                $orderSource = strtolower(trim((string)($order['order_source'] ?? '')));
                if ($orderSource === 'pos_merged') {
                    if ($transactionStarted) {
                        printflow_db_transaction_depth_pop($conn, false);
                    }
                    return [
                        'success' => false,
                        'error' => 'This is an archived POS draft shell. Refresh the list and complete the active POS order instead.',
                        '_http_status' => 409,
                    ];
                }
                $paymentGate = printflow_order_staff_completion_payment_gate($orderId, $order);
                if (!$paymentGate['allowed'] && $allowPendingCompletionOverride && !$confirmUnpaidCompletion) {
                    if ($transactionStarted) {
                        printflow_db_transaction_depth_pop($conn, false);
                    }
                    return [
                        'success' => false,
                        'error' => 'This order is unpaid. Confirm that it may be marked Completed without recording or changing a payment.',
                        'payment_status' => (string)($paymentGate['payment_status'] ?? 'Unpaid'),
                        'requires_unpaid_confirmation' => true,
                        '_http_status' => 409,
                    ];
                }
                if (!$paymentGate['allowed'] && !$allowPendingCompletionOverride) {
                    if ($transactionStarted) {
                        printflow_db_transaction_depth_pop($conn, false);
                    }
                    return [
                        'success' => false,
                        'error' => (string)($paymentGate['reason'] ?? 'Cannot mark as Completed: payment must be Paid.'),
                        'payment_status' => (string)($paymentGate['payment_status'] ?? 'Unpaid'),
                        '_http_status' => 409,
                    ];
                }
                if (!$paymentGate['allowed']) {
                    if (!$confirmUnpaidCompletion) {
                        if ($transactionStarted) printflow_db_transaction_depth_pop($conn, false);
                        return ['success' => false, 'error' => 'Explicit confirmation is required for unpaid completion.', 'requires_unpaid_confirmation' => true, '_http_status' => 409];
                    }
                    $recordedPaymentStatus = strtoupper(trim((string)($paymentGate['payment_status'] ?? $order['payment_status'] ?? '')));
                    if (!in_array($recordedPaymentStatus, ['', 'UNPAID'], true)
                        || (float)($order['amount_paid'] ?? 0) > 0.0001) {
                        if ($transactionStarted) printflow_db_transaction_depth_pop($conn, false);
                        return [
                            'success' => false,
                            'error' => 'This order has a payment submission or partial payment that must be reviewed before completion.',
                            'payment_status' => $recordedPaymentStatus !== '' ? $recordedPaymentStatus : 'Unpaid',
                            '_http_status' => 409,
                        ];
                    }
                    $unpaidOverrideApplied = true;
                }
                if (!empty($paymentGate['sync_payment_status'])) {
                    db_execute(
                        "UPDATE orders SET payment_status = ?, updated_at = NOW() WHERE order_id = ?",
                        'si',
                        [(string)$paymentGate['sync_payment_status'], $orderId]
                    );
                    $order['payment_status'] = (string)$paymentGate['sync_payment_status'];
                }
                if ($isReadyMadeProductOrder) {
                    $providerPayment = printflow_provider_payment_find('order', $orderId, 'online', printflow_paymongo_mode());
                    if (($providerPayment['status'] ?? '') !== 'paid') {
                        if ($transactionStarted) {
                            printflow_db_transaction_depth_pop($conn, false);
                        }
                        return [
                            'success' => false,
                            'error' => 'Cannot mark as Completed: PayMongo has not verified this payment as paid.',
                            '_http_status' => 409,
                        ];
                    }
                }
            }

            $updatedJobs = [];
            if ($isServiceOrder && $newStatus === 'Completed') {
                try {
                    $updatedJobs = JobOrderService::syncStoreOrderToStatus($orderId, 'COMPLETED', null, '', false, $unpaidOverrideApplied);
                } catch (Throwable $jobCompletionError) {
                    if ($transactionStarted) {
                        printflow_db_transaction_depth_pop($conn, false);
                    }
                    return printflow_staff_mutate_order_status_job_error_response($jobCompletionError);
                }
                if ($updatedJobs === []) {
                    $ok = db_execute("UPDATE orders SET status = 'Completed', updated_at = NOW() WHERE order_id = ?", 'i', [$orderId]);
                    if (!$ok) {
                        throw new RuntimeException('Failed to persist the completed order status.');
                    }
                }
                if (function_exists('db_table_has_column') && db_table_has_column('customizations', 'customization_id')) {
                    $customizationSql = db_table_has_column('customizations', 'updated_at')
                        ? "UPDATE customizations SET status = 'Completed', updated_at = NOW()
                           WHERE order_id = ?
                             AND status NOT IN ('Completed', 'Cancelled', 'Rejected')"
                        : "UPDATE customizations SET status = 'Completed'
                           WHERE order_id = ?
                             AND status NOT IN ('Completed', 'Cancelled', 'Rejected')";
                    if (!db_execute($customizationSql, 'i', [$orderId])) {
                        throw new RuntimeException('Failed to update linked customization status.');
                    }
                }
            } elseif ($isServiceOrder && $newStatus === 'Cancelled') {
                $updatedJobs = JobOrderService::syncStoreOrderToStatus($orderId, 'CANCELLED', null, $cancelReason);
                $ok = db_execute(
                    "UPDATE orders SET status = 'Cancelled', cancelled_by = 'Staff', cancel_reason = ?, cancelled_at = NOW(), updated_at = NOW() WHERE order_id = ?",
                    'si',
                    [$cancelReason, $orderId]
                );
                if (!$ok) {
                    throw new RuntimeException('Failed to persist the cancelled order status.');
                }
            } else {
                $updateSql = 'UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ?';
                $updateTypes = 'si';
                $updateParams = [$newStatus, $orderId];
                if ($newStatus === 'Cancelled') {
                    $updateSql = "UPDATE orders SET status = ?, cancel_reason = ?, cancelled_by = 'Staff', cancelled_at = NOW(), updated_at = NOW() WHERE order_id = ?";
                    $updateTypes = 'ssi';
                    $updateParams = [$newStatus, $cancelReason, $orderId];
                }
                if (!db_execute($updateSql, $updateTypes, $updateParams)) {
                    throw new RuntimeException('Failed to update order status.');
                }

                if ($newStatus === 'Completed') {
                    $branchId = (int)($order['branch_id'] ?? 0);
                    $items = db_query(
                        'SELECT oi.order_item_id, oi.product_id, oi.quantity
                         FROM order_items oi
                         WHERE oi.order_id = ?',
                        'i',
                        [$orderId]
                    ) ?: [];

                    foreach ($items as $item) {
                        $productId = (int)($item['product_id'] ?? 0);
                        $quantity = (int)($item['quantity'] ?? 0);
                        if ($productId <= 0 || $quantity <= 0) {
                            continue;
                        }

                        printflow_apply_product_order_item_inventory(
                            (int)$item['order_item_id'],
                            $branchId,
                            (int)($_SESSION['user_id'] ?? 0),
                            'Online product completion'
                        );
                    }
                }

                $notification = get_order_status_notification_payload($orderId, $newStatus);
                if ($customerId > 0) {
                    create_notification($customerId, 'Customer', $notification['message'], $notification['type'], false, false, $orderId);
                }
                add_order_system_message($orderId, $notification['message']);

                $chatSteps = [
                    'Approved' => 'approved', 'To Pay' => 'send_to_payment',
                    'Processing' => 'in_production', 'In Production' => 'in_production',
                    'Ready for Pickup' => 'ready_to_pickup', 'Completed' => 'completed',
                ];
                $chatStep = $chatSteps[$newStatus] ?? null;
                if ($chatStep !== null) {
                    if ($isProductOrder && $chatStep === 'completed') {
                        db_execute(
                            "INSERT INTO order_messages (order_id, sender, sender_id, message, message_type, read_receipt) VALUES (?, 'Staff', ?, ?, 'order_update', 0)",
                            'iis',
                            [$orderId, (int)($_SESSION['user_id'] ?? 0), 'Order Completed. Your order has been successfully picked up. We hope you are satisfied with our service! Feel free to share your feedback to help us improve even more.']
                        );
                    } else {
                        printflow_send_order_update($orderId, $chatStep);
                        if ($chatStep === 'completed') {
                            printflow_send_order_update($orderId, 'rate');
                        }
                    }
                }
            }

            if ($unpaidOverrideApplied) {
                $auditDetails = json_encode([
                    'order_id' => $orderId,
                    'previous_status' => $oldStatus,
                    'payment_status' => (string)($order['payment_status'] ?? 'Unpaid'),
                    'actor_user_id' => $actorId,
                    'actor_role' => $actorRole,
                    'confirmed_unpaid_completion' => true,
                    'order_business_date' => (string)($order['order_date'] ?? ''),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if ($actorId <= 0 || !db_execute(
                    "INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (?, 'Unpaid Order Completion Override', ?, NOW())",
                    'is',
                    [$actorId, $auditDetails]
                )) {
                    throw new RuntimeException('The unpaid completion audit entry could not be recorded.');
                }
            }

            if ($transactionStarted) {
                printflow_db_transaction_depth_pop($conn, true);
            }
            $branchIdForCache = (int)($order['branch_id'] ?? 0);
            if ($branchIdForCache > 0 && is_file(__DIR__ . '/staff_dashboard_cache.php')) {
                require_once __DIR__ . '/staff_dashboard_cache.php';
                printflow_staff_dashboard_cache_invalidate_branch($branchIdForCache);
            }
            return [
                'success' => true,
                'changed' => true,
                'already_completed' => false,
                'order_id' => $orderId,
                'status' => $newStatus,
                'job_ids' => $updatedJobs,
                'message' => "Order #{$orderId} marked as {$newStatus}.",
                '_http_status' => 200,
            ];
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                printflow_db_transaction_depth_pop($conn, false);
            }
            return printflow_staff_mutate_order_status_job_error_response($exception, $orderId, $newStatus);
        }
    }
}

if (!function_exists('printflow_staff_mutate_order_status_job_error_response')) {
    /**
     * @return array<string, mixed>
     */
    function printflow_staff_mutate_order_status_job_error_response(
        Throwable $exception,
        int $orderId = 0,
        string $newStatus = ''
    ): array {
        $reference = printflow_json_error_reference();
        $message = trim($exception->getMessage());
        if ($message === '') {
            $message = 'Unexpected ' . get_class($exception) . ' during order status update.';
        }
        error_log(sprintf(
            '[order-status][%s] order=%d target=%s class=%s message=%s',
            $reference,
            $orderId,
            preg_replace('/[^A-Za-z ]/', '', $newStatus),
            get_class($exception),
            $message
        ));
        if (getenv('PRINTFLOW_MATERIAL_DEDUCTION_DEBUG') === '1') {
            error_log('[order-status][' . $reference . '] rolled back: ' . $message);
        }

        $httpStatus = 500;
        $clientMessage = 'The order status could not be updated.';
        if ($message !== '') {
            $businessHints = [
                'Cannot mark as Completed',
                'Cannot complete Job',
                'Roll stock depleted',
                'Insufficient roll',
                'Insufficient stock',
                'FIFO deduction',
                'payment must be Paid',
                'Set a valid price',
                'Order not found',
                'inventory branch',
                'assigned inventory',
                'deduction was not completed',
                'Failed to persist',
                'Failed to update linked customization',
                'updated by another staff member',
                'Reconcile before completing',
                'Material assignment',
                'Lamination stock depleted',
            ];
            foreach ($businessHints as $hint) {
                if (stripos($message, $hint) !== false) {
                    $httpStatus = 409;
                    $clientMessage = $message;
                    break;
                }
            }
            if ($exception instanceof InvalidArgumentException) {
                $httpStatus = 422;
                $clientMessage = $message;
            } elseif ($httpStatus === 500) {
                $clientMessage = $message;
                if (preg_match(
                    '/cannot complete|insufficient|reconcile|deduction|roll stock|inventory|failed to (persist|update|finalize)/i',
                    $message
                ) === 1) {
                    $httpStatus = 409;
                }
            }
        }

        return [
            'success' => false,
            'error' => $clientMessage,
            'error_reference' => $reference,
            'error_class' => $message !== '' ? get_class($exception) : null,
            '_http_status' => $httpStatus,
        ];
    }
}
