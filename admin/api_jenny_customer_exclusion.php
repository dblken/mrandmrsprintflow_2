<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/json_endpoint.php';
printflow_json_endpoint_bootstrap();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_archive.php';

const PF_JENNY_EXCLUSION_CUSTOMER_ID = 25;
const PF_JENNY_EXCLUSION_VERSION = 'jenny-customer-exclusion-20261012-v2';

function pf_jenny_archive_reference(?string $reason): ?string
{
    if (!is_string($reason) || !preg_match('/Archive reference: (JNY-25-[A-Z0-9-]+)/', $reason, $match)) return null;
    return $match[1];
}

function pf_jenny_archive_managed_reason(?string $reason): bool
{
    return pf_jenny_archive_reference($reason) !== null
        || in_array($reason, [
            'Admin-authorized reversible customer-specific archive/hide for Jenny Ferr account 25.',
            'Admin-authorized restoration of Jenny Ferr customer-specific archive/hide.',
        ], true);
}

if (!is_admin() || (int)($_SESSION['user_id'] ?? 0) < 1) {
    printflow_json_response(['success' => false, 'error' => 'Admin authorization is required.'], 403);
}
if (!printflow_customer_exclusion_schema_ready()) {
    printflow_json_response(['success' => false, 'error' => 'Customer exclusion migration is not ready.'], 503);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$customerId = PF_JENNY_EXCLUSION_CUSTOMER_ID;
if ($method === 'GET') {
    if (isset($_GET['search'])) {
        $term = trim((string)$_GET['search']);
        $like = '%' . $term . '%';
        $customers = db_query(
            "SELECT customer_id, first_name, last_name, email FROM customers
             WHERE customer_id = ? AND (? = '' OR CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) LIKE ? OR COALESCE(email,'') LIKE ? OR CAST(customer_id AS CHAR) LIKE ?)
             LIMIT 1",
            'issss',
            [$customerId, $term, $like, $like, $like]
        ) ?: [];
        printflow_json_response([
            'success' => true,
            'version' => PF_JENNY_EXCLUSION_VERSION,
            'customers' => array_map(static fn(array $row): array => [
                'customer_id' => (int)$row['customer_id'],
                'name' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
                'email' => (string)($row['email'] ?? ''),
            ], $customers),
        ]);
    }
    if (($_GET['list'] ?? '') === '1') {
        $rows = db_query(
            "SELECT ce.customer_id, c.first_name, c.last_name, ce.status, ce.reason, ce.created_at AS archived_at,
                    ce.created_by AS archived_by, COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))), ''), CONCAT('User #', ce.created_by)) AS archived_by_name,
                    (SELECT COUNT(*) FROM orders o WHERE o.customer_id = ce.customer_id) AS total_orders
             FROM customer_exclusion ce
             JOIN customers c ON c.customer_id = ce.customer_id
             LEFT JOIN users u ON u.user_id = ce.created_by
             WHERE ce.entity_type = 'CUSTOMER'
             ORDER BY ce.created_at DESC, ce.customer_id ASC"
        ) ?: [];
        foreach ($rows as &$row) {
            $row['archive_reference_id'] = pf_jenny_archive_reference((string)($row['reason'] ?? ''));
            $row['archive_managed'] = pf_jenny_archive_managed_reason((string)($row['reason'] ?? ''));
        }
        unset($row);
        printflow_json_response(['success' => true, 'version' => PF_JENNY_EXCLUSION_VERSION, 'archives' => $rows]);
    }
    $customer = db_query('SELECT customer_id FROM customers WHERE customer_id = ? LIMIT 1', 'i', [$customerId]) ?: [];
    if (!$customer) printflow_json_response(['success' => false, 'error' => 'Target customer record is unavailable.'], 404);
    $customerRows = db_query("SELECT status, reason, created_at, updated_at, created_by FROM customer_exclusion WHERE customer_id = ? AND entity_type = 'CUSTOMER' AND record_id = ? LIMIT 1", 'ii', [$customerId, $customerId]) ?: [];
    $orderCount = db_query('SELECT COUNT(*) AS n FROM orders WHERE customer_id = ?', 'i', [$customerId]) ?: [];
    $customerName = db_query('SELECT customer_id, first_name, last_name, email FROM customers WHERE customer_id = ? LIMIT 1', 'i', [$customerId]) ?: [];
    $legacyArchiveJoin = printflow_order_archive_tables_ready()
        ? " LEFT JOIN printflow_order_archive_map am ON am.order_id = o.order_id "
        : '';
    $legacyArchivePredicate = printflow_order_archive_tables_ready() ? " OR MAX(am.status) = 'archived'" : '';
    $orders = db_query(
        "SELECT o.order_id, GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR '-') AS order_sku, o.order_date, o.status,
                CASE WHEN ? = 'active' OR ce.status = 'active' {$legacyArchivePredicate} THEN 1 ELSE 0 END AS is_hidden,
                CASE WHEN ? = 'active' OR ce.status = 'active' {$legacyArchivePredicate} THEN 'Archived' ELSE 'Visible' END AS archive_status
         FROM orders o
         LEFT JOIN customer_exclusion ce ON ce.customer_id = o.customer_id AND ce.entity_type = 'ORDER' AND ce.record_id = o.order_id
         {$legacyArchiveJoin}
         LEFT JOIN order_items oi ON oi.order_id = o.order_id
         LEFT JOIN products p ON p.product_id = oi.product_id
         WHERE o.customer_id = ?
         GROUP BY o.order_id, o.order_date, o.status, ce.status
         ORDER BY o.order_date DESC, o.order_id DESC",
        'ssi',
        [(string)($customerRows[0]['status'] ?? ''), (string)($customerRows[0]['status'] ?? ''), $customerId]
    ) ?: [];
    foreach ($orders as &$order) $order['order_code'] = function_exists('printflow_format_order_code')
        ? printflow_format_order_code($order['order_id'] ?? 0, $order['order_sku'] ?? '')
        : ((string)($order['order_sku'] ?? '') !== '' ? (string)$order['order_sku'] . '-' . (int)$order['order_id'] : 'ORD-' . (int)$order['order_id']);
    unset($order);
    $archiveReference = pf_jenny_archive_reference((string)($customerRows[0]['reason'] ?? ''));
    $archiveManaged = $customerRows === [] || pf_jenny_archive_managed_reason((string)($customerRows[0]['reason'] ?? ''));
    $ownedOrderIds = array_map(static fn(array $row): int => (int)$row['order_id'], $orders);
    $previewToken = bin2hex(random_bytes(32));
    $_SESSION['pf_jenny_archive_preview'] = [
        'token_hash' => hash('sha256', $previewToken),
        'scope_hash' => hash('sha256', (string)($customerRows[0]['status'] ?? 'inactive') . ':' . implode(',', $ownedOrderIds)),
    ];
    printflow_json_response([
        'success' => true,
        'version' => PF_JENNY_EXCLUSION_VERSION,
        'customer_id' => $customerId,
        'customer' => $customerName[0] ?? null,
        'orders_currently_owned' => (int)($orderCount[0]['n'] ?? 0),
        'orders' => $orders,
        'exclusion' => $customerRows[0] ?? null,
        'archive_reference_id' => $archiveReference,
        'archive_managed' => $archiveManaged,
        'preview_token' => $previewToken,
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
$preview = $_SESSION['pf_jenny_archive_preview'] ?? null;
$submittedPreviewToken = (string)($payload['preview_token'] ?? '');
if (!is_array($preview) || $submittedPreviewToken === '' || !hash_equals((string)($preview['token_hash'] ?? ''), hash('sha256', $submittedPreviewToken))) {
    printflow_json_response(['success' => false, 'error' => 'The live archive preview expired. Refresh it before continuing.'], 409);
}
$status = $action === 'activate' ? 'active' : 'inactive';
$actorId = (int)($_SESSION['user_id'] ?? 0);
$stage = 'validate_target';
$transactionStarted = false;
try {
    if (!$conn->begin_transaction()) throw new RuntimeException('Database transaction could not be started.');
    $transactionStarted = true;
    $customer = db_query('SELECT customer_id, first_name, last_name FROM customers WHERE customer_id = ? LIMIT 1 FOR UPDATE', 'i', [$customerId]) ?: [];
    if (!$customer) throw new RuntimeException('Target customer record is unavailable.');
    $ownedOrders = db_query('SELECT order_id FROM orders WHERE customer_id = ? ORDER BY order_id', 'i', [$customerId]) ?: [];
    $stage = 'upsert_customer_exclusion';
    $existingCustomerExclusion = db_query("SELECT status, reason FROM customer_exclusion WHERE customer_id = ? AND entity_type = 'CUSTOMER' AND record_id = ? LIMIT 1 FOR UPDATE", 'ii', [$customerId, $customerId]) ?: [];
    $wasActive = (string)($existingCustomerExclusion[0]['status'] ?? '') === 'active';
    $existingReason = (string)($existingCustomerExclusion[0]['reason'] ?? '');
    if ($existingCustomerExclusion !== [] && !pf_jenny_archive_managed_reason($existingReason)) throw new RuntimeException('A separate customer exclusion already exists; this archive will not overwrite or restore it.');
    $liveScopeHash = hash('sha256', (string)($existingCustomerExclusion[0]['status'] ?? 'inactive') . ':' . implode(',', array_map(static fn(array $row): int => (int)$row['order_id'], $ownedOrders)));
    if (!hash_equals((string)($preview['scope_hash'] ?? ''), $liveScopeHash)) throw new RuntimeException('The customer order scope changed after preview. Refresh and review it again.');
    $archiveReference = pf_jenny_archive_reference($existingReason);
    if ($action === 'activate' && !$archiveReference) $archiveReference = 'JNY-25-' . gmdate('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $reason = $action === 'activate'
        ? 'Archive reference: ' . $archiveReference . '; Admin-authorized reversible archive for Jenny Ferr account 25.'
        : $existingReason;
    if ($action === 'restore' && $archiveReference !== null && !db_execute("UPDATE customer_exclusion SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE customer_id = ? AND entity_type = 'ORDER' AND reason LIKE ?", 'is', [$customerId, 'Archive reference: ' . $archiveReference . ';%'])) {
        throw new RuntimeException('Existing order exclusions could not be restored.');
    }
    if ($action === 'restore' && $archiveReference === null && $existingReason === 'Admin-authorized reversible customer-specific archive/hide for Jenny Ferr account 25.' && !db_execute('UPDATE customer_exclusion SET status = \'inactive\', updated_at = CURRENT_TIMESTAMP WHERE customer_id = ? AND entity_type = \'ORDER\' AND reason = ?', 'is', [$customerId, $existingReason])) {
        throw new RuntimeException('Existing legacy Jenny order exclusions could not be restored.');
    }
    if ($action === 'activate') {
        if (!$wasActive && !printflow_customer_exclusion_set($customerId, 'CUSTOMER', $customerId, $actorId, $reason, null, '', 'active')) {
            throw new RuntimeException('Customer exclusion could not be saved.');
        }
    } elseif (!db_execute("UPDATE customer_exclusion SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE customer_id = ? AND entity_type = 'CUSTOMER' AND record_id = ?", 'ii', [$customerId, $customerId])) {
        throw new RuntimeException('Customer exclusion could not be restored.');
    }
    $orderExclusionsChanged = 0;
    foreach ($ownedOrders as $ownedOrder) {
        $orderId = (int)($ownedOrder['order_id'] ?? 0);
        if ($orderId <= 0) throw new RuntimeException('An invalid owned-order relationship was returned.');
        if ($action === 'activate') {
            $existingOrderExclusion = db_query("SELECT status, reason FROM customer_exclusion WHERE customer_id = ? AND entity_type = 'ORDER' AND record_id = ? LIMIT 1 FOR UPDATE", 'ii', [$customerId, $orderId]) ?: [];
            if (!$existingOrderExclusion) {
                if (!printflow_customer_exclusion_set($customerId, 'ORDER', $orderId, $actorId, $reason, $orderId, '', 'active')) throw new RuntimeException('An order exclusion could not be saved.');
                $orderExclusionsChanged++;
            } else {
                $existingReference = pf_jenny_archive_reference((string)($existingOrderExclusion[0]['reason'] ?? ''));
                $belongsToThisArchive = ($archiveReference !== null && $existingReference === $archiveReference)
                    || ($archiveReference === null && $existingReference === null && (string)($existingOrderExclusion[0]['reason'] ?? '') === 'Admin-authorized reversible customer-specific archive/hide for Jenny Ferr account 25.');
                if ($belongsToThisArchive && (string)$existingOrderExclusion[0]['status'] !== 'active') {
                    if (!db_execute("UPDATE customer_exclusion SET status = 'active', reason = ?, updated_at = CURRENT_TIMESTAMP WHERE customer_id = ? AND entity_type = 'ORDER' AND record_id = ?", 'sii', [$reason, $customerId, $orderId])) throw new RuntimeException('A prior Jenny order exclusion could not be reactivated.');
                    $orderExclusionsChanged++;
                }
            }
        }
    }
    $stage = 'verify_customer_exclusion';
    $verify = db_query("SELECT status FROM customer_exclusion WHERE customer_id = ? AND entity_type = 'CUSTOMER' AND record_id = ? LIMIT 1", 'ii', [$customerId, $customerId]) ?: [];
    if (($verify[0]['status'] ?? null) !== $status) throw new RuntimeException('Customer exclusion verification failed.');
    if (!$conn->commit()) throw new RuntimeException('Database transaction commit failed.');
    $transactionStarted = false;
    unset($_SESSION['pf_jenny_archive_preview']);
    error_log('[customer_exclusion_admin] ' . json_encode(['stage' => 'committed', 'customer_id' => $customerId, 'actor_id' => $actorId, 'action' => $action]));
    printflow_json_response(['success' => true, 'version' => PF_JENNY_EXCLUSION_VERSION, 'customer_id' => $customerId, 'customer_name' => trim((string)$customer[0]['first_name'] . ' ' . (string)$customer[0]['last_name']), 'status' => $status, 'action' => $action, 'archive_reference_id' => $archiveReference, 'orders_hidden' => $action === 'activate' ? count($ownedOrders) : 0, 'order_exclusion_rows' => $orderExclusionsChanged, 'owned_orders_checked' => count($ownedOrders), 'data_records_deleted_or_modified' => false, 'message' => $status === 'active' ? 'Jenny’s customer-owned records are now hidden by the active customer and order exclusions.' : 'Jenny’s customer-specific exclusions are inactive; records are visible again.', 'csrf_token' => generate_csrf_token()]);
} catch (Throwable $e) {
    if ($transactionStarted) $conn->rollback();
    error_log('[customer_exclusion_admin] ' . json_encode(['stage' => $stage, 'customer_id' => $customerId, 'actor_id' => $actorId, 'error_type' => get_class($e), 'error_code' => (int)$e->getCode()]));
    printflow_json_response(['success' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'The exclusion change failed and was rolled back.', 'stage' => $stage], 500);
}
