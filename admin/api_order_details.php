<?php
/**
 * Admin Order Details API
 * PrintFlow - Printing Shop PWA
 * Returns order details as JSON for the modal view
 */

require_once __DIR__ . '/../includes/api_header.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';

function pf_order_details_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function pf_order_details_clean_notes($notes): string
{
    $raw = trim((string)$notes);
    if ($raw === '') {
        return '';
    }

    $lines = preg_split('/\R/u', $raw) ?: [$raw];
    $lines = array_values(array_filter($lines, static function ($line): bool {
        return stripos((string)$line, 'synthetic_demo_order') === false;
    }));

    return trim(implode("\n", $lines));
}

if (!is_logged_in()) {
    pf_order_details_json(['success' => false, 'error' => 'Authentication required'], 401);
}

if (!in_array(get_user_type(), ['Admin', 'Manager'], true)) {
    pf_order_details_json(['success' => false, 'error' => 'Unauthorized'], 403);
}

if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

try {
    if (isset($_GET['customer_id'])) {
        $cust_id = (int) $_GET['customer_id'];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per_page = 10;
        $offset = ($page - 1) * $per_page;

        $mgrBranch = printflow_branch_filter_for_user();
        if (get_user_type() !== 'Admin' && $mgrBranch) {
            $total = db_query(
                "SELECT COUNT(*) as c FROM orders WHERE customer_id = ? AND branch_id = ?",
                'ii',
                [$cust_id, $mgrBranch]
            )[0]['c'] ?? 0;
            $orders = db_query(
                "SELECT o.order_id, o.total_amount, o.status, o.order_date,
                        GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR '-') as order_sku
                 FROM orders o
                 LEFT JOIN order_items oi ON o.order_id = oi.order_id
                 LEFT JOIN products p ON oi.product_id = p.product_id
                 WHERE o.customer_id = ? AND o.branch_id = ?
                 GROUP BY o.order_id
                 ORDER BY o.order_date DESC LIMIT ? OFFSET ?",
                'iiii',
                [$cust_id, $mgrBranch, $per_page, $offset]
            ) ?: [];
        } else {
            $total = db_query(
                "SELECT COUNT(*) as c FROM orders WHERE customer_id = ?",
                'i',
                [$cust_id]
            )[0]['c'] ?? 0;
            $orders = db_query(
                "SELECT o.order_id, o.total_amount, o.status, o.order_date,
                        GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR '-') as order_sku
                 FROM orders o
                 LEFT JOIN order_items oi ON o.order_id = oi.order_id
                 LEFT JOIN products p ON oi.product_id = p.product_id
                 WHERE o.customer_id = ?
                 GROUP BY o.order_id
                 ORDER BY o.order_date DESC LIMIT ? OFFSET ?",
                'iii',
                [$cust_id, $per_page, $offset]
            ) ?: [];
        }

        $total_pages = max(1, (int) ceil($total / $per_page));

        foreach ($orders as &$order) {
            $order['order_code'] = $order['order_sku']
                ? htmlspecialchars($order['order_sku']) . '-' . $order['order_id']
                : 'ORD-' . $order['order_id'];
        }
        unset($order);

        pf_order_details_json([
            'success' => true,
            'data' => $orders,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $total_pages,
                'total_items' => $total,
                'per_page' => $per_page
            ]
        ]);
    }

    $order_id = (int) ($_GET['id'] ?? 0);
    if ($order_id <= 0) {
        pf_order_details_json(['success' => false, 'error' => 'Invalid order ID'], 400);
    }

    $order_result = db_query(
        "SELECT o.*,
                CONCAT(c.first_name, ' ', c.last_name) as customer_name,
                c.email as customer_email,
                c.contact_number as customer_phone,
                c.first_name as cust_first,
                c.profile_picture,
                b.branch_name,
                GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR '-') as order_sku
         FROM orders o
         LEFT JOIN customers c ON o.customer_id = c.customer_id
         LEFT JOIN branches b ON o.branch_id = b.id
         LEFT JOIN order_items oi ON o.order_id = oi.order_id
         LEFT JOIN products p ON oi.product_id = p.product_id
         WHERE o.order_id = ?
         GROUP BY o.order_id",
        'i',
        [$order_id]
    );

    if (empty($order_result)) {
        pf_order_details_json(['success' => false, 'error' => 'Order not found'], 404);
    }

    printflow_assert_order_branch_access($order_id);
    $order = $order_result[0];

    $items = db_query(
        "SELECT oi.*, p.name as product_name, p.sku, p.category, pv.variant_name
         FROM order_items oi
         LEFT JOIN products p ON oi.product_id = p.product_id
         LEFT JOIN product_variants pv ON pv.variant_id = oi.variant_id
         WHERE oi.order_id = ?",
        'i',
        [$order_id]
    );

    $formatted_items = [];
    foreach ($items as $item) {
        $tarp_details = null;
        $category = strtoupper((string) ($item['category'] ?? ''));

        if ($category === 'TARPAULIN' || $category === 'TARPAULIN (FT)') {
            $tarp_rows = db_query(
                "SELECT otd.*, r.roll_code, r.width_ft as roll_width
                 FROM order_tarp_details otd
                 LEFT JOIN inv_rolls r ON otd.roll_id = r.id
                 WHERE otd.order_item_id = ?",
                'i',
                [(int) $item['order_item_id']]
            );
            $tarp_details = $tarp_rows[0] ?? null;
        }

        $quantity = (int) ($item['quantity'] ?? 0);
        $unit_price = (float) ($item['unit_price'] ?? 0);

        $formatted_items[] = [
            'order_item_id' => (int) ($item['order_item_id'] ?? 0),
            'product_name' => $item['product_name'] ?? 'Unknown Product',
            'variant_name' => $item['variant_name'] ?? '',
            'sku' => $item['sku'] ?? '-',
            'category' => $item['category'] ?? '-',
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'subtotal' => $quantity * $unit_price,
            'unit_price_formatted' => format_currency($unit_price),
            'subtotal_formatted' => format_currency($quantity * $unit_price),
            'tarp_details' => $tarp_details
        ];
    }

    pf_order_details_json([
        'success' => true,
        'order' => [
            'order_id' => (int) $order['order_id'],
            'order_code' => $order['order_sku']
                ? htmlspecialchars($order['order_sku']) . '-' . $order['order_id']
                : 'ORD-' . $order['order_id'],
            'customer_name' => $order['customer_name'] ?? 'N/A',
            'customer_email' => $order['customer_email'] ?? 'N/A',
            'customer_phone' => $order['customer_phone'] ?? 'N/A',
            'customer_initial' => strtoupper(substr((string) ($order['cust_first'] ?? 'C'), 0, 1)),
            'customer_picture' => !empty($order['profile_picture'])
                ? $base_path . '/public/assets/uploads/profiles/' . $order['profile_picture']
                : '',
            'order_date' => format_datetime($order['order_date']),
            'total_amount' => format_currency($order['total_amount']),
            'status' => $order['status'] ?? '',
            'branch_name' => $order['branch_name'] ?? 'Main Branch',
            'payment_status' => $order['payment_status'] ?? '',
            'payment_reference' => $order['payment_reference'] ?? '',
            'notes' => pf_order_details_clean_notes($order['notes'] ?? ''),
        ],
        'items' => $formatted_items
    ]);
} catch (Throwable $e) {
    error_log('admin/api_order_details.php: ' . $e->getMessage());
    pf_order_details_json(['success' => false, 'error' => 'Failed to load order details'], 500);
}
