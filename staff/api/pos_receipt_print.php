<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/pos_receipt_printer.php';
require_once __DIR__ . '/../../includes/pos_receipt.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!is_logged_in() || !in_array((string)get_user_type(), ['Admin', 'Manager', 'Staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input) || !verify_csrf_token((string)($input['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

$orderId = (int)($input['order_id'] ?? 0);
$action = strtolower(trim((string)($input['action'] ?? 'print')));
$isReprint = $action === 'reprint';
$rows = $orderId > 0 ? (db_query(
    "SELECT order_id, branch_id, status, payment_status, order_source
     FROM orders WHERE order_id = ? LIMIT 1",
    'i',
    [$orderId]
) ?: []) : [];

if (empty($rows)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Completed POS receipt not found.']);
    exit;
}

$order = $rows[0];
$source = strtolower(trim((string)($order['order_source'] ?? '')));
$staffBranch = (int)($_SESSION['branch_id'] ?? 0);
$authorizedBranch = get_user_type() === 'Admin'
    || $staffBranch <= 0
    || (int)$order['branch_id'] === $staffBranch;
$isPosOrder = str_starts_with($source, 'pos') || $source === 'walk-in';
$isPaid = strcasecmp((string)$order['payment_status'], 'Paid') === 0;
$isCompleted = strcasecmp((string)$order['status'], 'Completed') === 0;

if (!$authorizedBranch || !$isPosOrder || !$isPaid || ($isReprint && !$isCompleted)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'This receipt is not available for printing.']);
    exit;
}

$receipt = [];
if (!$isReprint) {
    $snapshot = $_SESSION['pos_receipt_snapshots'][$orderId] ?? null;
    if (is_array($snapshot) && (int)($snapshot['order_id'] ?? 0) === $orderId) {
        $receipt = $snapshot;
    }
}
if (empty($receipt)) {
    $receipt = printflow_pos_load_original_printed_receipt($orderId);
}
if (empty($receipt)) {
    $receipt = printflow_pos_build_receipt($orderId);
}
if (empty($receipt)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Finalized receipt data is unavailable.']);
    exit;
}

$receipt['company']['name'] = 'PrintFlow';
if ($isReprint) {
    $receipt['reprint'] = true;
}
$jobType = $isReprint ? 'pos_receipt_reprint' : 'pos_receipt';
$deliveryKey = $isReprint ? bin2hex(random_bytes(16)) : '';
$printJob = printflow_receipt_enqueue_order_print_safe(
    $orderId,
    $receipt,
    (int)$order['branch_id'],
    $jobType,
    $deliveryKey
);

echo json_encode([
    'success' => !empty($printJob['ok']),
    'message' => !empty($printJob['ok'])
        ? ($isReprint ? 'Receipt reprint queued.' : 'Receipt print queued.')
        : ($printJob['message'] ?? 'Receipt printing failed.'),
    'receipt' => $receipt,
    'print_job' => $printJob,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
