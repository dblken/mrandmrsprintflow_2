<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/json_endpoint.php';
printflow_json_endpoint_bootstrap();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_archive.php';
require_once __DIR__ . '/../includes/customer_exclusion_archive_batch.php';

const PF_JENNY_EXCLUSION_CUSTOMER_ID = 25;
const PF_JENNY_EXCLUSION_VERSION = 'jenny-customer-exclusion-20261012-v3';

final class PFJennyArchiveConflict extends RuntimeException {}

function pf_jenny_archive_query(string $sql, string $types = '', array $params = [], string $stage = 'query'): array
{
    $before = count(printflow_db_errors());
    $rows = db_query($sql, $types, $params);
    $errors = printflow_db_errors();
    if (count($errors) > $before) {
        $last = $errors[count($errors) - 1] ?? [];
        $code = (int)($last['errno'] ?? 0);
        throw new RuntimeException($stage . ' query failed (database code ' . $code . ').', $code);
    }
    return $rows ?: [];
}

function pf_jenny_archive_assert_write($result, string $stage, int $errorCountBefore): void
{
    if ($result !== false) return;
    $errors = printflow_db_errors();
    $last = count($errors) > $errorCountBefore ? ($errors[count($errors) - 1] ?? []) : [];
    $code = (int)($last['errno'] ?? 0);
    throw new RuntimeException($stage . ' write failed (database code ' . $code . ').', $code);
}

function pf_jenny_archive_execute(string $sql, string $types, array $params, string $stage): void
{
    $errorCountBefore = count(printflow_db_errors());
    pf_jenny_archive_assert_write(db_execute($sql, $types, $params), $stage, $errorCountBefore);
}

function pf_jenny_archive_set_order(int $customerId, int $orderId, int $actorId, string $reason): void
{
    $errorCountBefore = count(printflow_db_errors());
    pf_jenny_archive_assert_write(
        printflow_customer_exclusion_set($customerId, 'ORDER', $orderId, $actorId, $reason, $orderId, '', 'active'),
        'create_order_exclusion',
        $errorCountBefore
    );
}

function pf_jenny_archive_scope_hash(string $customerStatus, array $orders): string
{
    usort($orders, static fn(array $left, array $right): int => (int)($left['order_id'] ?? 0) <=> (int)($right['order_id'] ?? 0));
    $scope = [];
    foreach ($orders as $order) {
        $scope[] = [
            (int)($order['order_id'] ?? 0),
            strtolower((string)($order['order_exclusion_status'] ?? '')),
            strtolower((string)($order['archive_map_status'] ?? '')),
        ];
    }
    return hash('sha256', json_encode([strtolower($customerStatus), $scope], JSON_THROW_ON_ERROR));
}

function pf_jenny_archive_order_rows(int $customerId, bool $includeOrderCode = false): array
{
    $archiveReady = printflow_order_archive_tables_ready();
    $archiveStatus = $archiveReady
        ? "(SELECT CASE WHEN EXISTS (SELECT 1 FROM printflow_order_archive_map am WHERE am.order_id = o.order_id AND am.status = 'archived') THEN 'archived' ELSE '' END)"
        : "''";
    $orderCode = $includeOrderCode
        ? ", (SELECT GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR '-')
             FROM order_items oi JOIN products p ON p.product_id = oi.product_id WHERE oi.order_id = o.order_id) AS order_sku"
        : '';
    return pf_jenny_archive_query(
        "SELECT o.order_id{$orderCode}, o.order_date, o.status,
                (SELECT ce_order.status FROM customer_exclusion ce_order
                 WHERE ce_order.customer_id = o.customer_id AND ce_order.entity_type = 'ORDER'
                   AND ce_order.record_id = o.order_id LIMIT 1) AS order_exclusion_status,
                {$archiveStatus} AS archive_map_status
         FROM orders o WHERE o.customer_id = ? ORDER BY o.order_date DESC, o.order_id DESC",
        'i', [$customerId], 'read_customer_orders'
    ) ?: [];
}

function pf_jenny_archive_foreign_order_exclusion_conflicts(int $customerId, array $orderIds): array
{
    if ($orderIds === []) return [];
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $types = 'i' . str_repeat('i', count($orderIds));
    return pf_jenny_archive_query(
        "SELECT customer_id, record_id FROM customer_exclusion
         WHERE entity_type = 'ORDER' AND customer_id <> ? AND record_id IN ({$placeholders}) LIMIT 5",
        $types, array_merge([$customerId], $orderIds), 'check_foreign_order_exclusions'
    ) ?: [];
}

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
    $readStage = 'read_request';
    try {
    if (isset($_GET['search'])) {
        $readStage = 'search_target_customer';
        $term = trim((string)$_GET['search']);
        $like = '%' . $term . '%';
        $customers = pf_jenny_archive_query(
            "SELECT customer_id, first_name, last_name, email FROM customers
             WHERE customer_id = ? AND (? = '' OR CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) LIKE ? OR COALESCE(email,'') LIKE ? OR CAST(customer_id AS CHAR) LIKE ?)
             LIMIT 1",
            'issss',
            [$customerId, $term, $like, $like, $like], 'search_target_customer'
        );
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
        $readStage = 'list_customer_archives';
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
    $readStage = 'validate_target_customer';
    $customer = pf_jenny_archive_query('SELECT customer_id FROM customers WHERE customer_id = ? LIMIT 1', 'i', [$customerId], 'validate_target_customer');
    if (!$customer) printflow_json_response(['success' => false, 'error' => 'Target customer record is unavailable.'], 404);
    $readStage = 'read_customer_exclusion';
    $customerRows = pf_jenny_archive_query("SELECT status, reason, created_at, updated_at, created_by FROM customer_exclusion WHERE customer_id = ? AND entity_type = 'CUSTOMER' AND record_id = ? LIMIT 1", 'ii', [$customerId, $customerId], 'read_customer_exclusion');
    $readStage = 'count_owned_orders';
    $orderCount = pf_jenny_archive_query('SELECT COUNT(*) AS n FROM orders WHERE customer_id = ?', 'i', [$customerId], 'count_owned_orders');
    $readStage = 'read_customer_identity';
    $customerName = pf_jenny_archive_query('SELECT customer_id, first_name, last_name, email FROM customers WHERE customer_id = ? LIMIT 1', 'i', [$customerId], 'read_customer_identity');
    $customerIsHidden = strtolower((string)($customerRows[0]['status'] ?? '')) === 'active';
    $readStage = 'read_customer_orders';
    $orders = pf_jenny_archive_order_rows($customerId, true);
    $readStage = 'classify_customer_orders';
    foreach ($orders as &$order) {
        $order['is_hidden'] = printflow_customer_archive_order_is_hidden($order, $customerIsHidden) ? 1 : 0;
        $order['archive_status'] = $order['is_hidden'] ? 'Archived' : 'Visible';
        $order['order_code'] = function_exists('printflow_format_order_code')
            ? printflow_format_order_code($order['order_id'] ?? 0, $order['order_sku'] ?? '')
            : ((string)($order['order_sku'] ?? '') !== '' ? (string)$order['order_sku'] . '-' . (int)$order['order_id'] : 'ORD-' . (int)$order['order_id']);
    }
    unset($order);
    $archiveReference = pf_jenny_archive_reference((string)($customerRows[0]['reason'] ?? ''));
    $archiveManaged = $customerRows === [] || pf_jenny_archive_managed_reason((string)($customerRows[0]['reason'] ?? ''));
    $ownedOrderIds = array_map(static fn(array $row): int => (int)$row['order_id'], $orders);
    $previewToken = bin2hex(random_bytes(32));
    $_SESSION['pf_jenny_archive_preview'] = [
        'token_hash' => hash('sha256', $previewToken),
        'scope_hash' => pf_jenny_archive_scope_hash((string)($customerRows[0]['status'] ?? 'inactive'), $orders),
    ];
    $readStage = 'calculate_archive_counts';
    $archiveCounts = printflow_customer_archive_batch_counts($orders, $customerIsHidden);
    $readStage = 'return_customer_preview';
    printflow_json_response([
        'success' => true,
        'version' => PF_JENNY_EXCLUSION_VERSION,
        'customer_id' => $customerId,
        'customer' => $customerName[0] ?? null,
        'orders_currently_owned' => (int)($orderCount[0]['n'] ?? 0),
        'archive_counts' => $archiveCounts,
        'orders' => $orders,
        'exclusion' => $customerRows[0] ?? null,
        'archive_reference_id' => $archiveReference,
        'archive_managed' => $archiveManaged,
        'preview_token' => $previewToken,
        'csrf_token' => generate_csrf_token(),
        'data_mutated' => false,
        'data_records_deleted_or_modified' => false,
    ]);
    } catch (Throwable $e) {
        $errors = function_exists('printflow_db_errors') ? printflow_db_errors() : [];
        $lastDbError = is_array($errors) ? end($errors) : null;
        $dbCode = (int)$e->getCode();
        if ($dbCode === 0 && is_array($lastDbError)) $dbCode = (int)($lastDbError['errno'] ?? 0);
        error_log('[customer_exclusion_admin] ' . json_encode([
            'stage' => $readStage,
            'customer_id' => $customerId,
            'error_type' => get_class($e),
            'error_code' => $dbCode,
        ]));
        printflow_json_response([
            'success' => false,
            'error' => 'The read-only archive preview failed.',
            'stage' => $readStage,
            'table' => match ($readStage) {
                'validate_target_customer', 'read_customer_identity' => 'customers',
                'read_customer_exclusion' => 'customer_exclusion',
                'count_owned_orders', 'read_customer_orders', 'classify_customer_orders' => 'orders',
                'calculate_archive_counts', 'return_customer_preview' => 'application',
                default => 'customer_exclusion',
            },
            'error_type' => get_class($e),
            'database_error_code' => $dbCode,
        ], 500);
    }
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
    $stage = 'lock_target_customer';
    $customer = pf_jenny_archive_query('SELECT customer_id, first_name, last_name FROM customers WHERE customer_id = ? LIMIT 1 FOR UPDATE', 'i', [$customerId], 'lock_target_customer');
    if (!$customer) throw new PFJennyArchiveConflict('Target customer record is unavailable.');
    $stage = 'lock_owned_orders';
    $ownedOrders = pf_jenny_archive_query('SELECT order_id FROM orders WHERE customer_id = ? ORDER BY order_id FOR UPDATE', 'i', [$customerId], 'lock_owned_orders');
    $ownedOrderIds = array_values(array_unique(array_filter(array_map(static fn(array $row): int => (int)($row['order_id'] ?? 0), $ownedOrders), static fn(int $id): bool => $id > 0)));
    if (count($ownedOrderIds) !== count($ownedOrders)) throw new PFJennyArchiveConflict('An invalid current order ownership relationship was returned.');
    $stage = 'check_foreign_order_exclusions';
    $foreignOrderConflicts = pf_jenny_archive_foreign_order_exclusion_conflicts($customerId, $ownedOrderIds);
    if ($foreignOrderConflicts !== []) throw new RuntimeException('An order exclusion references Jenny’s order under a different customer owner. No records were changed.', 409);

    $orderExclusionsById = [];
    $legacyArchiveById = [];
    if ($ownedOrderIds !== []) {
        $idPlaceholders = implode(',', array_fill(0, count($ownedOrderIds), '?'));
        $idTypes = str_repeat('i', count($ownedOrderIds));
        $stage = 'lock_order_exclusions';
        $orderExclusionRows = pf_jenny_archive_query(
            "SELECT record_id, status, reason FROM customer_exclusion
             WHERE customer_id = ? AND entity_type = 'ORDER' AND record_id IN ({$idPlaceholders}) FOR UPDATE",
            'i' . $idTypes, array_merge([$customerId], $ownedOrderIds), 'lock_order_exclusions'
        );
        foreach ($orderExclusionRows as $row) $orderExclusionsById[(int)$row['record_id']] = $row;
        if (printflow_order_archive_tables_ready()) {
            $stage = 'read_existing_archive_maps';
            $legacyArchiveRows = pf_jenny_archive_query(
                "SELECT order_id, MAX(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) AS has_archived FROM printflow_order_archive_map
                 WHERE order_id IN ({$idPlaceholders}) GROUP BY order_id",
                $idTypes, $ownedOrderIds, 'read_existing_archive_maps'
            );
            foreach ($legacyArchiveRows as $row) $legacyArchiveById[(int)$row['order_id']] = (int)$row['has_archived'] === 1 ? 'archived' : '';
        }
    }
    $stage = 'lock_customer_exclusion';
    $existingCustomerExclusion = pf_jenny_archive_query("SELECT status, reason FROM customer_exclusion WHERE customer_id = ? AND entity_type = 'CUSTOMER' AND record_id = ? LIMIT 1 FOR UPDATE", 'ii', [$customerId, $customerId], 'lock_customer_exclusion');
    $wasActive = (string)($existingCustomerExclusion[0]['status'] ?? '') === 'active';
    $existingReason = (string)($existingCustomerExclusion[0]['reason'] ?? '');
    if ($existingCustomerExclusion !== [] && !pf_jenny_archive_managed_reason($existingReason)) throw new PFJennyArchiveConflict('A separate customer exclusion already exists; this archive will not overwrite or restore it.');
    $customerWasActive = strtolower((string)($existingCustomerExclusion[0]['status'] ?? '')) === 'active';
    $scopeRows = [];
    foreach ($ownedOrders as $ownedOrder) {
        $orderId = (int)$ownedOrder['order_id'];
        $scopeRows[] = [
            'order_id' => $orderId,
            'order_exclusion_status' => (string)($orderExclusionsById[$orderId]['status'] ?? ''),
            'archive_map_status' => (string)($legacyArchiveById[$orderId] ?? ''),
        ];
    }
    $liveScopeHash = pf_jenny_archive_scope_hash((string)($existingCustomerExclusion[0]['status'] ?? 'inactive'), $scopeRows);
    if (!hash_equals((string)($preview['scope_hash'] ?? ''), $liveScopeHash)) throw new PFJennyArchiveConflict('The customer order scope changed after preview. Refresh and review it again.');
    $beforeCounts = printflow_customer_archive_batch_counts($scopeRows, $customerWasActive);
    $archiveReference = pf_jenny_archive_reference($existingReason);
    if ($action === 'activate' && !$archiveReference) $archiveReference = 'JNY-25-' . gmdate('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $reason = $action === 'activate'
        ? 'Archive reference: ' . $archiveReference . '; Admin-authorized reversible archive for Jenny Ferr account 25.'
        : $existingReason;
    $orderExclusionsChanged = 0;
    if ($action === 'restore') {
        foreach ($orderExclusionsById as $orderExclusion) {
            if (strtolower((string)($orderExclusion['status'] ?? '')) !== 'active') continue;
            $rowReference = pf_jenny_archive_reference((string)($orderExclusion['reason'] ?? ''));
            $isOwnedByThisArchive = ($archiveReference !== null && $rowReference === $archiveReference)
                || ($archiveReference === null && (string)($orderExclusion['reason'] ?? '') === 'Admin-authorized reversible customer-specific archive/hide for Jenny Ferr account 25.');
            if ($isOwnedByThisArchive) $orderExclusionsChanged++;
        }
    }
    if ($action === 'restore' && $archiveReference !== null) {
        $stage = 'restore_order_exclusions';
        pf_jenny_archive_execute("UPDATE customer_exclusion SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE customer_id = ? AND entity_type = 'ORDER' AND reason LIKE ?", 'is', [$customerId, 'Archive reference: ' . $archiveReference . ';%'], $stage);
    }
    if ($action === 'restore' && $archiveReference === null && $existingReason === 'Admin-authorized reversible customer-specific archive/hide for Jenny Ferr account 25.') {
        $stage = 'restore_legacy_order_exclusions';
        pf_jenny_archive_execute('UPDATE customer_exclusion SET status = \'inactive\', updated_at = CURRENT_TIMESTAMP WHERE customer_id = ? AND entity_type = \'ORDER\' AND reason = ?', 'is', [$customerId, $existingReason], $stage);
    }
    if ($action === 'activate') {
        if (!$wasActive) {
            $stage = 'activate_customer_exclusion';
            $errorCountBefore = count(printflow_db_errors());
            pf_jenny_archive_assert_write(printflow_customer_exclusion_set($customerId, 'CUSTOMER', $customerId, $actorId, $reason, null, '', 'active'), $stage, $errorCountBefore);
        }
    } else {
        $stage = 'restore_customer_exclusion';
        pf_jenny_archive_execute("UPDATE customer_exclusion SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE customer_id = ? AND entity_type = 'CUSTOMER' AND record_id = ?", 'ii', [$customerId, $customerId], $stage);
    }
    foreach ($scopeRows as $orderRow) {
        $orderId = (int)$orderRow['order_id'];
        $existingOrderExclusion = $orderExclusionsById[$orderId] ?? null;
        $hiddenBefore = printflow_customer_archive_order_is_hidden($orderRow, $customerWasActive);
        if ($action === 'activate' && !$hiddenBefore) {
            if ($existingOrderExclusion !== null) {
                $existingReference = pf_jenny_archive_reference((string)($existingOrderExclusion['reason'] ?? ''));
                $belongsToThisArchive = ($archiveReference !== null && $existingReference === $archiveReference)
                    || ($existingReference === null && (string)($existingOrderExclusion['reason'] ?? '') === 'Admin-authorized reversible customer-specific archive/hide for Jenny Ferr account 25.');
                if (!$belongsToThisArchive) throw new PFJennyArchiveConflict('A visible order has an unrelated customer exclusion row. No records were changed.');
                if (strtolower((string)$existingOrderExclusion['status']) !== 'active') {
                    $stage = 'reactivate_order_exclusion';
                    pf_jenny_archive_execute("UPDATE customer_exclusion SET status = 'active', reason = ?, updated_at = CURRENT_TIMESTAMP WHERE customer_id = ? AND entity_type = 'ORDER' AND record_id = ?", 'sii', [$reason, $customerId, $orderId], $stage);
                    $orderExclusionsChanged++;
                }
            } else {
                $stage = 'create_order_exclusion';
                pf_jenny_archive_set_order($customerId, $orderId, $actorId, $reason);
                $orderExclusionsChanged++;
            }
        }
    }
    $stage = 'verify_customer_exclusion';
    $verify = pf_jenny_archive_query("SELECT status FROM customer_exclusion WHERE customer_id = ? AND entity_type = 'CUSTOMER' AND record_id = ? LIMIT 1", 'ii', [$customerId, $customerId], 'verify_customer_exclusion');
    if (($verify[0]['status'] ?? null) !== $status) throw new RuntimeException('Customer exclusion verification failed.');
    $verifiedOrders = pf_jenny_archive_order_rows($customerId, false);
    $customerHiddenAfter = strtolower((string)($verify[0]['status'] ?? '')) === 'active';
    $remainingVisible = 0;
    foreach ($verifiedOrders as $verifiedOrder) {
        if (!printflow_customer_archive_order_is_hidden($verifiedOrder, $customerHiddenAfter)) $remainingVisible++;
    }
    if ($action === 'activate' && $remainingVisible !== 0) throw new RuntimeException('Post-write verification found one or more Jenny orders still visible. The transaction was rolled back.');
    if (!$conn->commit()) throw new RuntimeException('Database transaction commit failed.');
    $transactionStarted = false;
    unset($_SESSION['pf_jenny_archive_preview']);
    error_log('[customer_exclusion_admin] ' . json_encode(['stage' => 'committed', 'customer_id' => $customerId, 'actor_id' => $actorId, 'action' => $action]));
    $newlyArchived = $action === 'activate' ? (int)$beforeCounts['newly_archived_count'] : 0;
    $alreadyArchived = (int)$beforeCounts['already_archived_count'];
    printflow_json_response([
        'success' => true,
        'version' => PF_JENNY_EXCLUSION_VERSION,
        'customer_id' => $customerId,
        'customer_name' => trim((string)$customer[0]['first_name'] . ' ' . (string)$customer[0]['last_name']),
        'status' => $status,
        'action' => $action,
        'archive_reference_id' => $archiveReference,
        'total_orders_found' => count($ownedOrders),
        'already_archived_count' => $alreadyArchived,
        'newly_archived_count' => $newlyArchived,
        'orders_hidden' => $action === 'activate' ? count($ownedOrders) : 0,
        'order_exclusion_rows_added_or_activated' => $action === 'activate' ? $orderExclusionsChanged : 0,
        'order_exclusion_rows_deactivated' => $action === 'restore' ? $orderExclusionsChanged : 0,
        'owned_orders_checked' => count($ownedOrders),
        'remaining_visible_count' => $remainingVisible,
        'failed_count' => 0,
        'failed_reason' => null,
        'data_records_deleted_or_modified' => false,
        'message' => $status === 'active' ? 'Jenny’s customer-owned records are now hidden by the active customer and order exclusions.' : 'Jenny’s customer-specific exclusions are inactive; records are visible again.',
        'csrf_token' => generate_csrf_token(),
    ]);
} catch (Throwable $e) {
    $rollbackSucceeded = null;
    if ($transactionStarted) {
        try { $rollbackSucceeded = (bool)$conn->rollback(); } catch (Throwable $rollbackError) { $rollbackSucceeded = false; }
    }
    error_log('[customer_exclusion_admin] ' . json_encode(['stage' => $stage, 'customer_id' => $customerId, 'actor_id' => $actorId, 'error_type' => get_class($e), 'error_code' => (int)$e->getCode()]));
    $isConflict = $e instanceof PFJennyArchiveConflict;
    $failureReason = $e->getMessage();
    if ($rollbackSucceeded === false) $failureReason .= ' Rollback failed; database state requires Admin review.';
    printflow_json_response([
        'success' => false,
        'error' => $e instanceof RuntimeException ? $failureReason : ($rollbackSucceeded === false ? $failureReason : 'The exclusion change failed and was rolled back.'),
        'stage' => $stage,
        'total_orders_found' => isset($ownedOrders) ? count($ownedOrders) : 0,
        'already_archived_count' => isset($beforeCounts) ? (int)$beforeCounts['already_archived_count'] : 0,
        'newly_archived_count' => 0,
        'remaining_visible_count' => isset($ownedOrders) ? count($ownedOrders) : 0,
        'failed_count' => 1,
        'failed_reason' => $failureReason,
        'rollback_succeeded' => $rollbackSucceeded,
    ], $isConflict ? 409 : 500);
}
