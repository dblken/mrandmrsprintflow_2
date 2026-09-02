<?php
/**
 * Order Details API for Chat Modal
 * Used by both Customer (own orders) and Staff (any order)
 */
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/branch_context.php';
require_once __DIR__ . '/../../../includes/ensure_chat_schema.php';
require_once __DIR__ . '/../../../includes/chat_http.php';

// Prevent accidental output (notices, etc.) from breaking JSON
ob_start();

header('Content-Type: application/json');

if (!is_logged_in()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$order_id = (int)($_GET['order_id'] ?? $_GET['id'] ?? 0);
$user_id = get_user_id();
$user_type = get_user_type();

if (!$order_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
    exit;
}

// Access control: Customer = own orders only; Staff/Manager = assigned branch only; Admin = any order.
printflow_chat_authorize_order($order_id);
$order_result = db_query("SELECT * FROM orders WHERE order_id = ?", 'i', [$order_id]);

if (empty($order_result)) {
    echo json_encode(['success' => false, 'error' => 'Order not found']);
    exit;
}
$order = $order_result[0];

function pf_chat_order_public_url(?string $path): string {
    $path = trim((string)$path);
    if ($path === '' || preg_match('#^(https?:|data:)#i', $path)) {
        return $path;
    }

    $path = str_replace('<?php echo $base_path; ?>', '', $path);
    $path = preg_replace('#/+#', '/', $path);
    $base = rtrim(defined('BASE_PATH') ? BASE_PATH : (defined('AUTH_REDIRECT_BASE') ? AUTH_REDIRECT_BASE : '/printflow'), '/');

    if ($base === '' && strpos($path, '/printflow/') === 0) {
        $path = substr($path, strlen('/printflow'));
    }
    if ($base !== '' && strpos($path, $base . '/') === 0) {
        return $path;
    }
    if ($path !== '' && $path[0] === '/') {
        return $base . $path;
    }

    return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
}

function pf_chat_order_product_image_url(?string $raw, int $product_id = 0): ?string {
    $raw = trim((string)$raw);
    $base = rtrim(defined('BASE_PATH') ? BASE_PATH : (defined('AUTH_REDIRECT_BASE') ? AUTH_REDIRECT_BASE : '/printflow'), '/');

    $resolve_existing = static function (string $relative) use ($base): ?string {
        $relative = '/' . ltrim($relative, '/');
        $full = dirname(__DIR__, 3) . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($full)) {
            return ($base === '' ? '' : $base) . $relative;
        }
        return null;
    };

    if ($raw !== '') {
        if (preg_match('#^https?://#i', $raw)) {
            return $raw;
        }

        $clean = '/' . ltrim($raw, '/');
        if ($base !== '' && strncmp($clean, $base . '/', strlen($base) + 1) === 0) {
            $clean = substr($clean, strlen($base));
            $clean = '/' . ltrim((string)$clean, '/');
        }

        foreach ([
            $clean,
            '/uploads/products/' . basename($clean),
            '/public/assets/uploads/products/' . basename($clean),
            '/public/images/products/' . basename($clean),
        ] as $candidate) {
            $resolved = $resolve_existing($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }
    }

    if ($product_id > 0) {
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $resolved = $resolve_existing('/public/images/products/product_' . $product_id . '.' . $ext);
            if ($resolved !== null) {
                return $resolved;
            }
        }
    }

    return null;
}

// Get customer info (join from customers table)
$customer = [];
$customer_id = (int)($order['customer_id'] ?? 0);
if ($customer_id) {
    $addr_cols = array_column(db_query("SHOW COLUMNS FROM customers") ?: [], 'Field');
    $addr_sel = '';
    if (in_array('address', $addr_cols)) {
        $addr_sel = ', c.address';
    } elseif (count(array_intersect($addr_cols, ['street_address', 'barangay', 'city', 'province'])) === 4) {
        $addr_sel = ", CONCAT_WS(', ', NULLIF(TRIM(c.street_address),''), NULLIF(TRIM(c.barangay),''), NULLIF(TRIM(c.city),''), NULLIF(TRIM(c.province),'')) as address";
    }
    $cust_result = db_query("
        SELECT c.first_name, c.middle_name, c.last_name, c.email, c.contact_number, c.profile_picture
        $addr_sel
        FROM customers c WHERE c.customer_id = ?
    ", 'i', [$customer_id]);
    if (!empty($cust_result)) {
        $c = $cust_result[0];
        $customer = [
            'full_name' => trim(($c['first_name'] ?? '') . ' ' . ($c['middle_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
            'contact_number' => $c['contact_number'] ?? '',
            'email' => $c['email'] ?? '',
            'profile_picture' => !empty($c['profile_picture'])
                ? pf_chat_order_public_url('/public/assets/uploads/profiles/' . ltrim((string)$c['profile_picture'], '/'))
                : null,
        ];
        if (isset($c['address']) && trim((string)$c['address']) !== '') {
            $customer['address'] = trim($c['address']);
        }
    }
}

// Get items with product info
$order_item_cols = array_column(db_query("SHOW COLUMNS FROM order_items") ?: [], 'Field');
$has_product_image = !empty(db_query("SHOW COLUMNS FROM products LIKE 'product_image'"));
$has_photo_path = !empty(db_query("SHOW COLUMNS FROM products LIKE 'photo_path'"));
$product_image_select = "'' AS product_image";
if ($has_product_image && $has_photo_path) {
    $product_image_select = "COALESCE(p.photo_path, p.product_image) AS product_image";
} elseif ($has_product_image) {
    $product_image_select = "p.product_image AS product_image";
} elseif ($has_photo_path) {
    $product_image_select = "p.photo_path AS product_image";
}

$items = db_query("
    SELECT oi.*, p.name as product_name, p.category, {$product_image_select}
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.product_id
    WHERE oi.order_id = ?
", 'i', [$order_id]);

$price_pending_statuses = ['Pending', 'Pending Review', 'Pending Approval', 'For Revision', 'Approved'];
$show_price = !in_array($order['status'], $price_pending_statuses, true);

$items_out = [];
foreach ($items ?: [] as $item) {
    $custom_data = in_array('customization_data', $order_item_cols, true)
        ? (json_decode($item['customization_data'] ?? '{}', true) ?: [])
        : [];
    unset($custom_data['design_upload'], $custom_data['reference_upload']);

    $product_name = printflow_resolve_order_item_name($item['product_name'] ?? 'Order Item', $custom_data, 'Order Item');

    $design_url = null;
    $ref_url = null;
    if ((in_array('design_image', $order_item_cols, true) && !empty($item['design_image']))
        || (in_array('design_file', $order_item_cols, true) && !empty($item['design_file']))) {
        $design_url = pf_chat_order_public_url('/public/serve_design.php?type=order_item&id=' . (int)$item['order_item_id']);
    } elseif (!empty($item['product_image'])) {
        $design_url = pf_chat_order_product_image_url((string)$item['product_image'], (int)($item['product_id'] ?? 0));
    }
    if (in_array('reference_image_file', $order_item_cols, true) && !empty($item['reference_image_file'])) {
        $ref_url = pf_chat_order_public_url('/public/serve_design.php?type=order_item&id=' . (int)$item['order_item_id'] . '&field=reference');
    }

    $items_out[] = [
        'order_item_id'     => (int)$item['order_item_id'],
        'product_name'      => $product_name,
        'category'          => $item['category'] ?? '',
        'quantity'          => (int)$item['quantity'],
        'unit_price'        => $show_price ? format_currency($item['unit_price']) : null,
        'subtotal'          => $show_price ? format_currency($item['quantity'] * $item['unit_price']) : null,
        'customization'     => $custom_data,
        'design_url'        => $design_url,
        'reference_url'     => $ref_url,
    ];
}

// Clear accidental output before sending JSON
ob_end_clean();
echo json_encode([
    'success'  => true,
    'customer' => $customer,
    'order' => [
        'order_id'       => (int)$order['order_id'],
        'manage_url'     => ($user_type === 'Customer') ? null : printflow_staff_order_management_url((int)$order['order_id'], true),
        'order_date'     => format_datetime($order['order_date']),
        'status'         => $order['status'],
        'payment_status' => $order['payment_status'] ?? '',
        'total_amount'   => $show_price ? format_currency($order['total_amount']) : null,
        'notes'          => $order['notes'] ?? '',
        'revision_reason'=> $order['revision_reason'] ?? '',
    ],
    'items' => $items_out,
]);
