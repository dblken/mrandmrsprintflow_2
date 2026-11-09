<?php

/** Final customer receipts are available only after confirmed payment and production progress. */
function printflow_customer_receipt_is_available(string $orderStatus, string $paymentStatus): bool {
    if (strcasecmp(trim($paymentStatus), 'Paid') !== 0) return false;
    $status = str_replace(['–', '—', '_'], ['-', '-', ' '], $orderStatus);
    $status = strtolower(trim((string)preg_replace('/\s+/', ' ', $status)));
    return in_array($status, [
        'processing', 'in production', 'printing', 'paid - in process', 'paid-in-process',
        'approved design', 'ready for pickup', 'to receive', 'completed', 'to rate', 'rated'
    ], true);
}

/**
 * Ready-made customer receipts require a persisted order line and a verified
 * successful payment state. The order's Paid mirror is accepted because the
 * payment page also treats that server-side state as paid; an unverified
 * payment submission by itself is never sufficient.
 */
/** Confirmed payment receipt for online custom/service orders (same payment guards as ready-made). */
function printflow_customer_service_paid_receipt_is_available(
    array $order,
    array $providerPayment,
    array $orderItems,
    bool $paymentReceived
): bool {
    if (!$paymentReceived) {
        return false;
    }
    return printflow_customer_ready_made_receipt_is_available($order, $providerPayment, $orderItems);
}

function printflow_customer_ready_made_receipt_is_available(array $order, array $providerPayment, array $orderItems): bool {
    $orderId = (int)($order['order_id'] ?? 0);
    if ($orderId <= 0 || $orderItems === []) {
        return false;
    }

    $hasPersistedOrderItem = false;
    foreach ($orderItems as $item) {
        if (is_array($item) && (int)($item['order_item_id'] ?? 0) > 0) {
            $hasPersistedOrderItem = true;
            break;
        }
    }
    if (!$hasPersistedOrderItem) {
        return false;
    }

    $blockedOrderStates = [
        'cancelled', 'canceled', 'expired', 'failed', 'rejected', 'declined',
        'abandoned', 'unpaid', 'awaiting payment', 'pending payment'
    ];
    $blockedPaymentStates = ['cancelled', 'canceled', 'expired', 'failed', 'rejected', 'declined', 'abandoned'];
    $normalizeStatus = static function ($value): string {
        $value = strtolower(trim((string)$value));
        $value = str_replace(['–', '—', '_'], ['-', '-', ' '], $value);
        return (string)preg_replace('/\s+/', ' ', $value);
    };
    if (in_array($normalizeStatus($order['status'] ?? ''), $blockedOrderStates, true)
        || in_array($normalizeStatus($order['payment_status'] ?? ''), $blockedPaymentStates, true)) {
        return false;
    }

    $providerPaid = strtolower(trim((string)($providerPayment['status'] ?? ''))) === 'paid'
        && (int)($providerPayment['id'] ?? 0) > 0
        && strtolower(trim((string)($providerPayment['provider'] ?? ''))) === 'paymongo'
        && strtolower(trim((string)($providerPayment['channel'] ?? ''))) === 'online'
        && (string)($providerPayment['subject_type'] ?? '') === 'order'
        && (int)($providerPayment['subject_id'] ?? 0) === $orderId
        && (int)($providerPayment['customer_id'] ?? 0) === (int)($order['customer_id'] ?? 0);
    $orderMarkedPaid = strcasecmp(trim((string)($order['payment_status'] ?? '')), 'Paid') === 0;

    return $providerPaid || $orderMarkedPaid;
}
