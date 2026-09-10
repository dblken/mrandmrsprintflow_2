<?php
/**
 * POS walk-in draft lifecycle: void unfinalized Set Price drafts when cart items are removed.
 * Preserves audit history — soft-cancel only, never hard-delete paid/completed orders.
 */

function pos_customization_json_marked_as_pos(?string $json): bool
{
    $json = trim((string)$json);
    if ($json === '') {
        return false;
    }

    return str_contains($json, '"source":"POS"')
        || str_contains($json, '"source": "POS"');
}

function pos_order_has_unfinalized_pos_marker(int $orderId): bool
{
    if ($orderId <= 0) {
        return false;
    }

    $rows = db_query(
        "SELECT
            LOWER(TRIM(COALESCE(o.order_source, ''))) AS order_source,
            LOWER(TRIM(COALESCE(o.status, ''))) AS status,
            LOWER(TRIM(COALESCE(o.payment_status, ''))) AS payment_status,
            EXISTS(
                SELECT 1
                FROM customizations cust
                WHERE cust.order_id = o.order_id
                  AND (
                      cust.customization_details LIKE '%\"source\":\"POS\"%'
                      OR cust.customization_details LIKE '%\"source\": \"POS\"%'
                  )
            ) AS has_pos_customization,
            EXISTS(
                SELECT 1
                FROM order_items oi
                WHERE oi.order_id = o.order_id
                  AND (
                      oi.customization_data LIKE '%\"source\":\"POS\"%'
                      OR oi.customization_data LIKE '%\"source\": \"POS\"%'
                  )
            ) AS has_pos_order_item
         FROM orders o
         WHERE o.order_id = ?
         LIMIT 1",
        'i',
        [$orderId]
    ) ?: [];

    if ($rows === []) {
        return false;
    }

    $row = $rows[0];
    $payment = strtolower(trim((string)($row['payment_status'] ?? '')));
    if (in_array($payment, ['paid', 'partially paid', 'partial'], true)) {
        return false;
    }

    $source = strtolower(trim((string)($row['order_source'] ?? '')));
    $status = strtolower(trim(str_replace(['–', '—'], '-', (string)($row['status'] ?? ''))));
    $hasPosMarker = !empty($row['has_pos_customization']) || !empty($row['has_pos_order_item']);

    if ($source === 'pos_draft') {
        return true;
    }

    if ($hasPosMarker
        && in_array($payment, ['unpaid', ''], true)
        && in_array($status, ['draft', 'approved', 'pending', 'pending review', 'pending approval', 'for revision'], true)) {
        return true;
    }

    return false;
}

function pos_order_is_voidable_unfinalized_draft(array $order): bool
{
    $orderId = (int)($order['order_id'] ?? 0);
    $source = strtolower(trim((string)($order['order_source'] ?? '')));
    if ($source === 'pos_merged') {
        return false;
    }

    $payment = strtolower(trim((string)($order['payment_status'] ?? '')));
    if (in_array($payment, ['paid', 'partially paid', 'partial'], true)) {
        return false;
    }

    $status = strtolower(trim(str_replace(['–', '—'], '-', (string)($order['status'] ?? ''))));
    if (in_array($status, [
        'completed', 'cancelled', 'processing', 'in production', 'printing',
        'ready for pickup', 'paid - in process', 'paid-in process',
    ], true)) {
        return false;
    }

    if ($source === 'pos_draft') {
        return true;
    }

    if ($orderId > 0 && pos_order_has_unfinalized_pos_marker($orderId)) {
        return true;
    }

    if (!in_array($source, ['pos', 'walk-in'], true)) {
        return false;
    }

    return in_array($status, ['draft', 'approved', 'pending', 'pending review', 'pending approval', 'for revision'], true);
}

/**
 * @return array{success:bool,message?:string,order_id?:int}
 */
function pos_void_unfinalized_draft(int $orderId): array
{
    if ($orderId <= 0) {
        return ['success' => false, 'message' => 'Invalid order ID.'];
    }

    $rows = db_query(
        'SELECT order_id, order_source, payment_status, status, total_amount FROM orders WHERE order_id = ? LIMIT 1',
        'i',
        [$orderId]
    ) ?: [];
    if ($rows === []) {
        return ['success' => false, 'message' => 'Order not found.'];
    }

    $order = $rows[0];
    if (!pos_order_is_voidable_unfinalized_draft($order)) {
        return ['success' => false, 'message' => 'This order cannot be voided because it is finalized or paid.'];
    }

    global $conn;
    $started = !($conn->in_transaction ?? false);
    if ($started && !$conn->begin_transaction()) {
        return ['success' => false, 'message' => 'Unable to start void transaction.'];
    }

    try {
        db_execute(
            "UPDATE orders
             SET status = 'Cancelled',
                 updated_at = NOW()
             WHERE order_id = ?
               AND status NOT IN ('Completed', 'Cancelled')",
            'i',
            [$orderId]
        );

        if (function_exists('db_table_has_column') && db_table_has_column('customizations', 'customization_id')) {
            db_execute(
                "UPDATE customizations
                 SET status = 'Cancelled', updated_at = NOW()
                 WHERE order_id = ?
                   AND status NOT IN ('Completed', 'Cancelled', 'Processing', 'In Production')",
                'i',
                [$orderId]
            );
        }

        if (function_exists('db_table_has_column') && db_table_has_column('job_orders', 'id')) {
            db_execute(
                "UPDATE job_orders
                 SET status = 'CANCELLED', updated_at = NOW()
                 WHERE order_id = ?
                   AND status NOT IN ('COMPLETED', 'CANCELLED', 'Completed', 'Cancelled')",
                'i',
                [$orderId]
            );
        }

        if ($started) {
            $conn->commit();
        }

        if (isset($_SESSION['pos_pending_orders']) && is_array($_SESSION['pos_pending_orders'])) {
            foreach ($_SESSION['pos_pending_orders'] as $productId => $pendingOrderId) {
                if ((int)$pendingOrderId === $orderId) {
                    unset($_SESSION['pos_pending_orders'][$productId]);
                }
            }
        }

        return ['success' => true, 'order_id' => $orderId];
    } catch (Throwable $e) {
        if ($started && ($conn->in_transaction ?? false)) {
            $conn->rollback();
        }
        error_log('PrintFlow pos_void_unfinalized_draft: order ' . $orderId . ' — ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to void draft order.'];
    }
}

/**
 * @param array<string,mixed> $cartItem
 * @return int[]
 */
function pos_cart_item_draft_order_ids(array $cartItem): array
{
    $ids = [];
    $pendingOrderId = (int)($cartItem['pending_order_id'] ?? 0);
    if ($pendingOrderId > 0) {
        $ids[] = $pendingOrderId;
    }

    $pendingCustomizationId = (int)($cartItem['pending_customization_id'] ?? 0);
    if ($pendingCustomizationId > 0) {
        $rows = db_query(
            'SELECT order_id FROM customizations WHERE customization_id = ? LIMIT 1',
            'i',
            [$pendingCustomizationId]
        ) ?: [];
        $linkedOrderId = (int)($rows[0]['order_id'] ?? 0);
        if ($linkedOrderId > 0) {
            $ids[] = $linkedOrderId;
        }
    }

    return array_values(array_unique(array_filter($ids)));
}

/**
 * @param int[] $orderIds
 */
function pos_void_unfinalized_drafts(array $orderIds): void
{
    foreach (array_unique(array_filter(array_map('intval', $orderIds))) as $orderId) {
        if ($orderId > 0) {
            pos_void_unfinalized_draft($orderId);
        }
    }
}
