<?php
/**
 * Staff: Get order data formatted for customizations modal
 * Returns order + items in currentJo-compatible format
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/order_ui_helper.php';
require_once __DIR__ . '/../includes/JobOrderService.php';

header('Content-Type: application/json');

if (!is_logged_in() || !in_array(get_user_type(), ['Staff', 'Admin', 'Manager'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$order_id = (int)($_GET['id'] ?? 0);
if (!$order_id) {
    echo json_encode(['success' => false, 'error' => 'Order ID required']);
    exit;
}

printflow_assert_order_branch_access($order_id);

$order_row = db_query("
    SELECT o.*, c.first_name, c.last_name, c.customer_type, c.contact_number, c.email,
           COALESCE(c.transaction_count, 0) as transaction_count,
           CONCAT(c.first_name, ' ', c.last_name) as customer_full_name,
           COALESCE(c.contact_number, c.email, '') as customer_contact
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.customer_id
    WHERE o.order_id = ?
", 'i', [$order_id]);

if (empty($order_row)) {
    echo json_encode(['success' => false, 'error' => 'Order not found']);
    exit;
}

$o = $order_row[0];
$status_map = [
    'Pending' => 'PENDING', 'Pending Review' => 'PENDING', 'Pending Approval' => 'PENDING',
    'For Revision' => 'PENDING', 'Design Approved' => 'APPROVED', 'Approved' => 'APPROVED',
    'Pending Verification' => 'PENDING', 'Downpayment Submitted' => 'PENDING',
    'To Pay' => 'TO_PAY',
    'Paid – In Process' => 'IN_PRODUCTION',
    'Paid - In Process' => 'IN_PRODUCTION',
    'Processing' => 'IN_PRODUCTION', 'In Production' => 'IN_PRODUCTION', 'Printing' => 'IN_PRODUCTION',
    'Ready for Pickup' => 'TO_RECEIVE', 'Completed' => 'COMPLETED', 'Cancelled' => 'CANCELLED'
];
$db_status = $o['status'] ?? '';
$mapped_status = $status_map[$db_status] ?? $db_status;

$items = db_query("
    SELECT oi.*, p.name as product_name, p.category
    FROM order_items oi
    LEFT JOIN products p ON oi.product_id = p.product_id
    WHERE oi.order_id = ?
", 'i', [$order_id]) ?: [];

$items_out = [];
$first_custom = [];
$total_qty = 0;
$width_ft = '1';
$height_ft = '1';

foreach ($items as $item) {
    $custom = printflow_decode_modal_customization_payload((string)($item['customization_data'] ?? ''));
    if (empty($first_custom)) $first_custom = $custom;
    $total_qty += (int)$item['quantity'];
    if (!empty($custom['width']) && !empty($custom['height'])) {
        $width_ft = (string)$custom['width'];
        $height_ft = (string)$custom['height'];
    } elseif (!empty($custom['dimensions'])) {
        $d = $custom['dimensions'];
        if (is_string($d) && preg_match('/^(\d+)\s*[x×]\s*(\d+)$/i', $d, $m)) {
            $width_ft = $m[1];
            $height_ft = $m[2];
        } else {
            $width_ft = (string)$d;
            $height_ft = '';
        }
    }
    $name = printflow_resolve_order_item_name($item['product_name'] ?? 'Custom Order', $custom, 'Custom Order');
    $designOpenUrl = (!empty($item['design_image']) || !empty($item['design_file']))
        ? BASE_PATH . '/public/serve_design.php?type=order_item&id=' . (int)$item['order_item_id']
        : null;
    $referenceOpenUrl = !empty($item['reference_image_file'])
        ? BASE_PATH . '/public/serve_design.php?type=order_item&id=' . (int)$item['order_item_id'] . '&field=reference'
        : null;
    $designIsImage = function_exists('printflow_order_item_has_previewable_design')
        ? printflow_order_item_has_previewable_design($item)
        : false;
    $referenceIsImage = function_exists('printflow_media_path_looks_like_image')
        ? printflow_media_path_looks_like_image((string)($item['reference_image_file'] ?? ''))
        : false;
    $designName = trim((string)($item['design_image_name'] ?? ''));
    if ($designName === '' && !empty($item['design_file'])) {
        $designPath = parse_url((string)$item['design_file'], PHP_URL_PATH);
        $designName = basename(is_string($designPath) && $designPath !== '' ? $designPath : (string)$item['design_file']);
    }
    $referenceName = '';
    if (!empty($item['reference_image_file'])) {
        $referencePath = parse_url((string)$item['reference_image_file'], PHP_URL_PATH);
        $referenceName = basename(is_string($referencePath) && $referencePath !== '' ? $referencePath : (string)$item['reference_image_file']);
    }
    $items_out[] = [
        'order_item_id'   => $item['order_item_id'],
        'product_name'    => $name,
        'quantity'        => (int)$item['quantity'],
        'customization'   => $custom,
        'design_url'      => $designIsImage ? $designOpenUrl : null,
        'design_open_url' => $designOpenUrl,
        'design_is_image' => $designIsImage,
        'design_name'     => $designName !== '' ? $designName : null,
        'reference_url'   => $referenceIsImage ? $referenceOpenUrl : null,
        'reference_open_url' => $referenceOpenUrl,
        'reference_is_image' => $referenceIsImage,
        'reference_name'  => $referenceName !== '' ? $referenceName : null,
    ];
}

$service_name = printflow_resolve_order_item_name($items_out[0]['product_name'] ?? 'Standard Order', $first_custom, 'Standard Order');
$transaction_count = (int)($o['transaction_count'] ?? 0);

$linked_job_id = JobOrderService::ensureJobsForStoreOrder($order_id);

$materials = [];
if ($linked_job_id) {
    $material_sql = "
        SELECT m.*, i.name as item_name, i.track_by_roll, i.category_id, r.roll_code
        FROM job_order_materials m
        JOIN inv_items i ON m.item_id = i.id
        LEFT JOIN inv_rolls r ON m.roll_id = r.id
        WHERE m.job_order_id = ?
    ";
    $material_params = [$linked_job_id];
    $material_types = 'i';
    if (db_table_has_column('job_order_materials', 'std_order_id')) {
        $material_sql .= " OR (m.std_order_id = ? AND (m.job_order_id IS NULL OR m.job_order_id = 0))";
        $material_params[] = $order_id;
        $material_types .= 'i';
    }
    $materials = db_query($material_sql, $material_types, $material_params) ?: [];
}

// Parse JSON metadata for each material
foreach ($materials as &$m) {
    $m['metadata'] = $m['metadata'] ? json_decode($m['metadata'], true) : null;
}
unset($m);

$ink_usage = [];
if ($linked_job_id) {
    $ink_sql = "
        SELECT u.*, i.name as item_name
        FROM job_order_ink_usage u
        JOIN inv_items i ON u.item_id = i.id
        WHERE u.job_order_id = ?
    ";
    $ink_params = [$linked_job_id];
    $ink_types = 'i';
    if (db_table_has_column('job_order_ink_usage', 'std_order_id')) {
        $ink_sql .= " OR (u.std_order_id = ? AND (u.job_order_id IS NULL OR u.job_order_id = 0))";
        $ink_params[] = $order_id;
        $ink_types .= 'i';
    }
    $ink_usage = db_query($ink_sql, $ink_types, $ink_params) ?: [];
}

$data = [
    'id'                   => $o['order_id'],
    'order_id'             => $o['order_id'],
    'order_type'           => 'ORDER',
    'customer_full_name'   => $o['customer_full_name'] ?? trim(($o['first_name'] ?? '') . ' ' . ($o['last_name'] ?? '')),
    'customer_contact'     => $o['customer_contact'] ?? '',
    'customer_type'        => $transaction_count < 3 ? 'NEW' : 'REGULAR',
    'service_type'         => $service_name,
    'job_title'            => implode(', ', array_map(function($i) { return $i['product_name'] . ' - ' . $i['quantity'] . 'pcs'; }, $items_out)),
    'width_ft'             => $width_ft,
    'height_ft'            => $height_ft,
    'quantity'             => $total_qty,
    'status'               => $mapped_status,
    'estimated_total'      => (float)($o['estimated_price'] ?? 0),
    'estimated_price'      => (float)($o['estimated_price'] ?? 0),
    'final_price'          => (float)($o['total_amount'] ?? 0),
    'amount_paid'          => (($o['payment_status'] ?? '') === 'Paid') ? (float)($o['total_amount'] ?? 0) : (float)($o['amount_paid'] ?? 0),
    'notes'                => $o['notes'] ?? '',
    'payment_proof_status' => 'PAID',
    'payment_status'       => 'NO',
    'readiness'            => 'READY',
    'items'                => $items_out,
    'materials'            => $materials,
    'ink_usage'            => $ink_usage,
];

echo json_encode(['success' => true, 'data' => $data]);
