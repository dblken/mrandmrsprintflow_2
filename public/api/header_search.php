<?php
/**
 * Header real-time search suggestions.
 * UI helper endpoint only; does not alter existing business logic/routes.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

$base_path = rtrim(defined('BASE_PATH') ? BASE_PATH : '/printflow', '/');
$q = trim((string)($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'query' => $q, 'results' => []]);
    exit;
}

$needle = '%' . $q . '%';
$results = [];

// Products
$products = db_query(
    "SELECT product_id, name, category
     FROM products
     WHERE status = 'Activated'
       AND (name LIKE ? OR description LIKE ?)
     ORDER BY name ASC
     LIMIT 6",
    'ss',
    [$needle, $needle]
) ?: [];

foreach ($products as $p) {
    $results[] = [
        'type' => 'product',
        'title' => $p['name'],
        'meta' => $p['category'] ?: 'Product',
        'url' => $base_path . '/' . (is_customer() ? 'customer/products.php' : 'products/') . '?search=' . rawurlencode($q),
    ];
}

// Services
$services = db_query(
    "SELECT service_id, name, category
     FROM services
     WHERE status = 'Activated'
       AND visible_to_customer = 1
       AND (name LIKE ? OR category LIKE ? OR description LIKE ?)
     ORDER BY name ASC
     LIMIT 6",
    'sss',
    [$needle, $needle, $needle]
) ?: [];

foreach ($services as $s) {
    $results[] = [
        'type' => 'service',
        'title' => $s['name'],
        'meta' => $s['category'] ?: 'Service',
        'url' => $base_path . '/' . (is_customer() ? 'customer/services.php' : 'public/services.php'),
    ];
}

// Customer orders (only when logged in as customer)
if (is_logged_in() && is_customer()) {
    $orders = db_query(
        "SELECT o.order_id, o.status,
                (SELECT COALESCE(p.name, 'Order Item')
                 FROM order_items oi
                 LEFT JOIN products p ON oi.product_id = p.product_id
                 WHERE oi.order_id = o.order_id
                 ORDER BY oi.order_item_id ASC
                 LIMIT 1) AS display_name
         FROM orders o
         WHERE o.customer_id = ?
           AND " . printflow_order_archive_scope_sql('o') . "
           AND (
                CAST(o.order_id AS CHAR) LIKE ?
                OR o.status LIKE ?
                OR EXISTS (
                    SELECT 1
                    FROM order_items oi2
                    LEFT JOIN products p2 ON oi2.product_id = p2.product_id
                    WHERE oi2.order_id = o.order_id
                      AND p2.name LIKE ?
                )
           )
         ORDER BY o.order_date DESC
         LIMIT 6",
        'isss',
        [get_user_id(), $needle, $needle, $needle]
    ) ?: [];

    foreach ($orders as $o) {
        $results[] = [
            'type' => 'order',
            'title' => 'Order #' . (int)$o['order_id'] . ' - ' . ($o['display_name'] ?: 'Order'),
            'meta' => $o['status'] ?: 'Order',
            'url' => $base_path . '/customer/chat.php?order_id=' . (int)$o['order_id'],
        ];
    }
}

echo json_encode([
    'success' => true,
    'query' => $q,
    'results' => array_slice($results, 0, 12),
]);

