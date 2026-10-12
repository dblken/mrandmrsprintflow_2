<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/json_endpoint.php';
printflow_json_endpoint_bootstrap();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

const PF_JENNY_EXCLUSION_CUSTOMER_ID = 25;
const PF_JENNY_EXCLUSION_VERSION = 'jenny-customer-exclusion-20261012-v1';

if (!is_admin() || (int)($_SESSION['user_id'] ?? 0) < 1) {
    printflow_json_response(['success' => false, 'error' => 'Admin authorization is required.'], 403);
}
if (!printflow_customer_exclusion_schema_ready()) {
    printflow_json_response(['success' => false, 'error' => 'Customer exclusion migration is not ready.'], 503);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$customerId = PF_JENNY_EXCLUSION_CUSTOMER_ID;
if ($method === 'GET') {
    $customer = db_query('SELECT customer_id FROM customers WHERE customer_id = ? LIMIT 1', 'i', [$customerId]) ?: [];
    if (!$customer) printflow_json_response(['success' => false, 'error' => 'Target customer record is unavailable.'], 404);
    $rows = db_query("SELECT status, reason, created_at, updated_at FROM customer_exclusion WHERE customer_id = ? AND entity_type = 'CUSTOMER' AND record_id = ? LIMIT 1", 'ii', [$customerId, $customerId]) ?: [];
    $orderCount = db_query('SELECT COUNT(*) AS n FROM orders WHERE customer_id = ?', 'i', [$customerId]) ?: [];
    printflow_json_response([
        'success' => true,
        'version' => PF_JENNY_EXCLUSION_VERSION,
        'customer_id' => $customerId,
        'orders_currently_owned' => (int)($orderCount[0]['n'] ?? 0),
        'exclusion' => $rows[0] ?? null,
        'csrf_token' => generate_csrf_token(),
        'data_mutated' => false,
        'data_records_deleted_or_modified' => false,
    ]);
}
if ($method !== 'POST') printflow_json_response(['success' => false, 'error' => 'Method not allowed.'], 405);

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) $payload = $_POST;
if (!verify_csrf_token((string)($payload['csrf_token'] ?? ''))) {
    printflow_json_response(['success' => false, 'error' => 'CSRF validation failed.'], 403);
}
$action = (string)($payload['action'] ?? '');
if (!in_array($action, ['activate', 'restore'], true)) {
    printflow_json_response(['success' => false, 'error' => 'Unsupported exclusion action.'], 400);
}
$status = $action === 'activate' ? 'active' : 'inactive';
$actorId = (int)($_SESSION['user_id'] ?? 0);
$stage = 'validate_target';
$transactionStarted = false;
try {
    if (!$conn->begin_transaction()) throw new RuntimeException('Database transaction could not be started.');
    $transactionStarted = true;
    $customer = db_query('SELECT customer_id FROM customers WHERE customer_id = ? LIMIT 1 FOR UPDATE', 'i', [$customerId]) ?: [];
    if (!$customer) throw new RuntimeException('Target customer record is unavailable.');
    $ownedOrders = db_query('SELECT order_id FROM orders WHERE customer_id = ? ORDER BY order_id', 'i', [$customerId]) ?: [];
    $stage = 'upsert_customer_exclusion';
    $reason = $action === 'activate'
        ? 'Admin-authorized reversible customer-specific archive/hide for Jenny Ferr account 25.'
        : 'Admin-authorized restoration of Jenny Ferr customer-specific archive/hide.';
    if ($action === 'restore' && !db_execute("UPDATE customer_exclusion SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE customer_id = ? AND entity_type = 'ORDER'", 'i', [$customerId])) {
        throw new RuntimeException('Existing order exclusions could not be restored.');
    }
    if (!printflow_customer_exclusion_set($customerId, 'CUSTOMER', $customerId, $actorId, $reason, null, '', $status)) {
        throw new RuntimeException('Customer exclusion could not be saved.');
    }
    $orderExclusionsChanged = 0;
    foreach ($ownedOrders as $ownedOrder) {
        $orderId = (int)($ownedOrder['order_id'] ?? 0);
        if ($orderId <= 0) throw new RuntimeException('An invalid owned-order relationship was returned.');
        if (!printflow_customer_exclusion_set($customerId, 'ORDER', $orderId, $actorId, $reason, $orderId, '', $status)) {
            throw new RuntimeException('An order exclusion could not be saved.');
        }
        $orderExclusionsChanged++;
    }
    $stage = 'verify_customer_exclusion';
    $verify = db_query("SELECT status FROM customer_exclusion WHERE customer_id = ? AND entity_type = 'CUSTOMER' AND record_id = ? LIMIT 1", 'ii', [$customerId, $customerId]) ?: [];
    if (($verify[0]['status'] ?? null) !== $status) throw new RuntimeException('Customer exclusion verification failed.');
    if (!$conn->commit()) throw new RuntimeException('Database transaction commit failed.');
    $transactionStarted = false;
    error_log('[customer_exclusion_admin] ' . json_encode(['stage' => 'committed', 'customer_id' => $customerId, 'actor_id' => $actorId, 'action' => $action]));
    printflow_json_response(['success' => true, 'version' => PF_JENNY_EXCLUSION_VERSION, 'customer_id' => $customerId, 'status' => $status, 'action' => $action, 'customer_exclusion_rows' => 1, 'order_exclusion_rows' => $orderExclusionsChanged, 'owned_orders_checked' => count($ownedOrders), 'data_records_deleted_or_modified' => false, 'message' => $status === 'active' ? 'Jenny’s customer-owned records are now hidden by the active customer and order exclusions.' : 'Jenny’s customer-specific exclusions are inactive; records are visible again.', 'csrf_token' => generate_csrf_token()]);
} catch (Throwable $e) {
    if ($transactionStarted) $conn->rollback();
    error_log('[customer_exclusion_admin] ' . json_encode(['stage' => $stage, 'customer_id' => $customerId, 'actor_id' => $actorId, 'error_type' => get_class($e), 'error_code' => (int)$e->getCode()]));
    printflow_json_response(['success' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'The exclusion change failed and was rolled back.', 'stage' => $stage], 500);
}
