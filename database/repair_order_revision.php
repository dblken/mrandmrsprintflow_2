<?php
/**
 * Controlled, idempotent legacy revision repair.
 *
 * Usage (deployment host): php database/repair_order_revision.php [order_id]
 * Defaults to the reported SNB-0005-11240 numeric order ID, 11240.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/revision_workflow.php';

$orderId = isset($argv[1]) ? (int)$argv[1] : 11240;
if ($orderId <= 0) {
    fwrite(STDERR, "Provide a valid numeric order ID.\n");
    exit(1);
}
if (!printflow_revision_ensure_schema()) {
    fwrite(STDERR, "Revision request storage is unavailable.\n");
    exit(1);
}

$orders = db_query(
    'SELECT order_id, customer_id, status, design_status, revision_reason FROM orders WHERE order_id = ? LIMIT 1',
    'i',
    [$orderId]
) ?: [];
if (empty($orders)) {
    fwrite(STDERR, "Order #{$orderId} was not found.\n");
    exit(1);
}

$beforeRows = db_query(
    'SELECT revision_request_id, permitted_fields, request_status, active_flag
     FROM order_revision_requests WHERE order_id = ? ORDER BY revision_request_id DESC LIMIT 1',
    'i',
    [$orderId]
) ?: [];
$before = $beforeRows[0] ?? null;

$active = printflow_revision_get_active_or_legacy($orderId, (int)$orders[0]['customer_id']);
$afterRows = db_query(
    'SELECT revision_request_id, reason_code, revision_reason, staff_instruction,
            permitted_fields, request_status, active_flag, requested_at
     FROM order_revision_requests WHERE order_id = ? ORDER BY revision_request_id DESC LIMIT 1',
    'i',
    [$orderId]
) ?: [];

$result = [
    'order_id' => $orderId,
    'order_status' => (string)$orders[0]['status'],
    'design_status' => (string)$orders[0]['design_status'],
    'before' => $before,
    'after' => $afterRows[0] ?? null,
    'active_permissions' => is_array($active['permitted_fields_array'] ?? null)
        ? $active['permitted_fields_array']
        : [],
    'repaired' => !empty($active['permitted_fields_array']),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['repaired'] ? 0 : 2);
