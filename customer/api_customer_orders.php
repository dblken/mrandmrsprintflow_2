<?php
/**
 * api_customer_orders.php
 * Real-time polling API for the customer orders page.
 * Returns lightweight order list (status + price visibility controlled).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_archive.php';
require_role('Customer');

header('Content-Type: application/json');

$customer_id = get_user_id();

// Statuses where price must NOT be shown to customer
$HIDDEN_PRICE_STATUSES = ['Pending', 'Pending Approval', 'Pending Review', 'For Revision', 'Approved'];

try {
    $orders = db_query(
        "SELECT o.order_id, o.status, o.order_type, o.total_amount, o.order_date, o.updated_at, o.cancelled_at,
                (SELECT COALESCE(p.name, 'Order Item')
                 FROM order_items oi
                 LEFT JOIN products p ON oi.product_id = p.product_id
                 WHERE oi.order_id = o.order_id
                 ORDER BY oi.order_item_id ASC LIMIT 1) as display_name,
                (SELECT oi.customization_data
                 FROM order_items oi
                 WHERE oi.order_id = o.order_id
                 ORDER BY oi.order_item_id ASC LIMIT 1) as first_item_customization
         FROM orders o
         WHERE o.customer_id = ? AND " . printflow_order_archive_scope_sql('o') . "
         ORDER BY o.order_date DESC
         LIMIT 100",
        'i',
        [$customer_id]
    ) ?: [];

    $result = [];
    foreach ($orders as $o) {
        // Derive display name for custom orders
        $display_name = $o['display_name'] ?? 'Order';
        if (in_array(strtolower(trim((string)$display_name)), ['custom order', 'customer order', 'service order', 'order item']) && !empty($o['first_item_customization'])) {
            $cj = json_decode($o['first_item_customization'], true);
            if (!empty($cj['service_type'])) $display_name = $cj['service_type'];
        }
        $display_name = normalize_service_name($display_name, 'Order');

        // Price visibility control
        $show_price = !in_array($o['status'], $HIDDEN_PRICE_STATUSES);
        $timestampMeta = printflow_customer_order_timestamp_meta($o);

        $result[] = [
            'order_id'     => (int)$o['order_id'],
            'status'       => $o['status'],
            'order_type'   => (string)($o['order_type'] ?? ''),
            'display_name' => $display_name,
            'total_amount' => $show_price ? (float)($o['total_amount'] ?? 0) : null,
            'price_hidden' => !$show_price,
            'order_date'   => $o['order_date'],
            'updated_at'   => $o['updated_at'],
            'display_timestamp' => $timestampMeta['datetime'],
            'display_timestamp_label' => $timestampMeta['label'],
            'display_timestamp_text' => $timestampMeta['text'],
            '_display_ts' => !empty($timestampMeta['datetime']) ? (strtotime((string)$timestampMeta['datetime']) ?: 0) : 0,
        ];
    }

    usort($result, static function (array $a, array $b): int {
        $ta = (int)($a['_display_ts'] ?? 0);
        $tb = (int)($b['_display_ts'] ?? 0);
        if ($ta === $tb) {
            return (int)($b['order_id'] ?? 0) <=> (int)($a['order_id'] ?? 0);
        }
        return $tb <=> $ta;
    });

    // Also return notification count so the bell can update
    $notif_count = db_query(
        "SELECT COUNT(*) as cnt FROM notifications n WHERE customer_id = ? AND is_read = 0 AND " . printflow_order_archive_notification_exclusion_sql('n'),
        'i',
        [$customer_id]
    );

    echo json_encode([
        'success'            => true,
        'orders'             => $result,
        'unread_notif_count' => (int)($notif_count[0]['cnt'] ?? 0),
        'server_time'        => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
