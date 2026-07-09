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

if (!is_logged_in() || !in_array(get_user_type(), ['Staff', 'Admin', 'Manager'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$order_id = (int)($_GET['id'] ?? 0);
$includeAssignments = !empty($_GET['include_assignments']) && $_GET['include_assignments'] !== '0';
$ensureJob = !empty($_GET['ensure_job']) && $_GET['ensure_job'] !== '0';
if ($order_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Order ID required']);
    exit;
}

printflow_assert_order_branch_access($order_id);

$order_row = db_query(
    "SELECT o.*, c.first_name, c.last_name, c.customer_type, c.contact_number, c.email,
            COALESCE(c.transaction_count, 0) AS transaction_count,
            CONCAT(c.first_name, ' ', c.last_name) AS customer_full_name,
            COALESCE(c.contact_number, c.email, '') AS customer_contact
     FROM orders o
     LEFT JOIN customers c ON o.customer_id = c.customer_id
     WHERE o.order_id = ?",
    'i',
    [$order_id]
);

if (empty($order_row)) {
    echo json_encode(['success' => false, 'error' => 'Order not found']);
    exit;
}

$o = $order_row[0];
$resolvedOrderSource = strtolower(trim((string)($o['order_source'] ?? 'customer')));
$isPosSource = in_array($resolvedOrderSource, ['pos', 'walk-in'], true);
if (get_user_type() === 'Staff') {
    $staffAccessRole = printflow_get_staff_access_role();
    if (($staffAccessRole === 'pos' && !$isPosSource) || ($staffAccessRole === 'online' && $isPosSource)) {
        echo json_encode(['success' => false, 'error' => 'You do not have access to this order.']);
        exit;
    }
}
$status_map = [
    'Pending' => 'PENDING',
    'Pending Review' => 'PENDING',
    'Pending Approval' => 'PENDING',
    'For Revision' => 'PENDING',
    'Design Approved' => 'APPROVED',
    'Approved' => 'APPROVED',
    'Pending Verification' => 'PENDING',
    'Downpayment Submitted' => 'PENDING',
    'To Pay' => 'TO_PAY',
    'Paid – In Process' => 'IN_PRODUCTION',
    'Paid - In Process' => 'IN_PRODUCTION',
    'Processing' => 'IN_PRODUCTION',
    'In Production' => 'IN_PRODUCTION',
    'Printing' => 'IN_PRODUCTION',
    'Ready for Pickup' => 'TO_RECEIVE',
    'Completed' => 'COMPLETED',
    'Cancelled' => 'CANCELLED',
];
$db_status = (string)($o['status'] ?? '');
$mapped_status = $status_map[$db_status] ?? $db_status;

$payload = JobOrderService::getStoreOrderItemsPayload($order_id, false, true);
$items_out = array_values($payload['items'] ?? []);
$first_custom = [];
$total_qty = 0;
foreach ($items_out as $item) {
    if ($first_custom === [] && !empty($item['customization']) && is_array($item['customization'])) {
        $first_custom = $item['customization'];
    }
    $total_qty += max(1, (int)($item['quantity'] ?? 0));
}

$width_ft = trim((string)($payload['width_ft'] ?? '1'));
$height_ft = trim((string)($payload['height_ft'] ?? '1'));
$service_name = trim((string)($payload['service_type'] ?? ''));
if ($service_name === '') {
    $service_name = printflow_resolve_order_item_name($items_out[0]['product_name'] ?? 'Standard Order', $first_custom, 'Standard Order');
}

$linked_job_id = 0;
$linkedJobRows = db_query(
    "SELECT id FROM job_orders WHERE order_id = ? ORDER BY id ASC LIMIT 1",
    'i',
    [$order_id]
) ?: [];
$linked_job_id = (int)($linkedJobRows[0]['id'] ?? 0);
if ($linked_job_id <= 0 && $ensureJob && strtolower(trim((string)($o['order_type'] ?? ''))) === 'custom') {
    $linked_job_id = (int)(JobOrderService::ensureJobsForStoreOrder($order_id) ?? 0);
}

$materials = [];
if ($includeAssignments && $linked_job_id) {
    $material_sql = "
        SELECT m.*, i.name AS item_name, i.track_by_roll, i.category_id, r.roll_code
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

foreach ($materials as &$m) {
    $m['metadata'] = $m['metadata'] ? json_decode($m['metadata'], true) : null;
}
unset($m);

$ink_usage = [];
if ($includeAssignments && $linked_job_id) {
    $ink_sql = "
        SELECT u.*, i.name AS item_name
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
    'id' => $o['order_id'],
    'order_id' => $o['order_id'],
    'job_order_id' => $linked_job_id ?: null,
    'order_type' => 'ORDER',
    'customer_full_name' => $o['customer_full_name'] ?? trim(($o['first_name'] ?? '') . ' ' . ($o['last_name'] ?? '')),
    'customer_contact' => $o['customer_contact'] ?? '',
    'customer_type' => ((int)($o['transaction_count'] ?? 0) < 3 ? 'NEW' : 'REGULAR'),
    'service_type' => $service_name,
    'job_title' => implode(', ', array_map(static function ($i) {
        return (string)($i['product_name'] ?? 'Order Item') . ' - ' . max(1, (int)($i['quantity'] ?? 0)) . 'pcs';
    }, $items_out)),
    'width_ft' => $width_ft,
    'height_ft' => $height_ft,
    'quantity' => $total_qty,
    'status' => $mapped_status,
    'estimated_total' => (float)($o['estimated_price'] ?? 0),
    'estimated_price' => (float)($o['estimated_price'] ?? 0),
    'final_price' => (float)($o['total_amount'] ?? 0),
    'amount_paid' => (($o['payment_status'] ?? '') === 'Paid')
        ? (float)($o['total_amount'] ?? 0)
        : (float)($o['amount_paid'] ?? 0),
    'notes' => $o['notes'] ?? '',
    'payment_proof_status' => 'PAID',
    'payment_status' => 'NO',
    'readiness' => 'READY',
    'items' => $items_out,
    'materials' => $materials,
    'ink_usage' => $ink_usage,
];

echo json_encode(['success' => true, 'data' => $data]);
