<?php
declare(strict_types=1);

/**
 * Admin-only POS walk-in service/customization demo seed import & batch rollback.
 * Does not use POS checkout, PayMongo, or inventory deduction workflows.
 */

const DEMO_SEED_BATCH_TABLE = 'demo_seed_batches';
const DEMO_SEED_ROW_TABLE = 'demo_seed_rows';
const DEMO_SEED_DATE_MIN = '2026-09-07 00:00:00';
const DEMO_SEED_DATE_MAX = '2026-10-01 09:30:00';
const DEMO_SEED_PENDING_MIN = '2026-09-30 00:00:00';
const DEMO_SEED_MAX_PENDING = 10;
const DEMO_SEED_MAX_UPLOAD_BYTES = 2 * 1024 * 1024;
const DEMO_SEED_SESSION_KEY = 'demo_seed_validated_preview';

function demo_seed_last_db_error(): string
{
    global $conn;
    if (function_exists('printflow_db_errors')) {
        $logged = printflow_db_errors();
        if ($logged !== []) {
            $last = $logged[count($logged) - 1];
            $parts = array_filter([
                trim((string)($last['error'] ?? '')),
                isset($last['errno']) && (int)$last['errno'] !== 0 ? ('errno=' . (int)$last['errno']) : '',
                trim((string)($last['sqlstate'] ?? '')),
                trim((string)($last['stage'] ?? '')),
            ]);
            $msg = implode(' ', $parts);
            if ($msg !== '' && $msg !== '00000') {
                return $msg;
            }
            $errOnly = trim((string)($last['error'] ?? ''));
            if ($errOnly !== '') {
                return $errOnly;
            }
            if (isset($last['errno']) && (int)$last['errno'] !== 0) {
                return 'errno=' . (int)$last['errno'] . (isset($last['sqlstate']) ? (' sqlstate=' . trim((string)$last['sqlstate'])) : '');
            }
        }
    }
    $parts = array_filter([
        trim((string)($conn->error ?? '')),
        (int)($conn->errno ?? 0) !== 0 ? ('errno=' . (int)$conn->errno) : '',
        trim((string)($conn->sqlstate ?? '')),
    ]);
    $msg = implode(' ', $parts);
    return ($msg !== '' && $msg !== '00000') ? $msg : '';
}

/**
 * @param array<string,mixed> $context
 */
function demo_seed_fail(string $step, string $message, array $context = []): never
{
    $seedRowKey = trim((string)($context['seed_row_key'] ?? ''));
    $prefix = $seedRowKey !== '' ? ('[' . $seedRowKey . '] ') : '';
    $detail = demo_seed_last_db_error();
    $full = $prefix . $step . ': ' . $message;
    if ($detail !== '') {
        $full .= ' (' . $detail . ')';
    }
    throw new RuntimeException($full);
}

/** @return list<string> */
function demo_seed_required_csv_columns(): array
{
    return [
        'seed_row_key',
        'seed_batch_id',
        'order_datetime',
        'order_status',
        'customization_status',
        'job_status',
        'service_display_name',
        'job_service_type_enum',
        'service_catalog_id',
        'unit_price',
        'quantity',
        'payment_method',
        'payment_status',
        'amount_paid',
        'customer_first_name',
        'customer_last_name',
        'customer_email',
        'customer_phone',
        'customer_street',
        'customer_barangay',
        'customer_city',
        'customer_province',
        'customer_postal',
        'spec_summary',
        'staff_user_id',
        'branch_id',
    ];
}

/** @return list<string> */
function demo_seed_job_service_enum_values(): array
{
    return [
        'Tarpaulin Printing',
        'T-shirt Printing',
        'Decals/Stickers (Print/Cut)',
        'Glass Stickers / Wall / Frosted Stickers',
        'Transparent Stickers',
        'Layouts',
        'Reflectorized (Subdivision Stickers/Signages)',
        'Stickers on Sintraboard',
        'Sintraboard Standees',
        'Souvenirs',
    ];
}

function demo_seed_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function demo_seed_table_exists(string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($safe === '') {
        return false;
    }
    $rows = db_query(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
        's',
        [$safe]
    ) ?: [];
    return $cache[$table] = !empty($rows);
}

function demo_seed_begin_transaction(): void
{
    global $conn;
    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Unable to start database transaction.');
    }
}

function demo_seed_commit_transaction(): void
{
    global $conn;
    if (!$conn->commit()) {
        throw new RuntimeException('Unable to commit database transaction.');
    }
}

function demo_seed_rollback_transaction(): void
{
    global $conn;
    if (!$conn->rollback()) {
        error_log('demo_seed_data rollback warning: ' . ($conn->error ?? ''));
    }
}

/**
 * @param list<mixed> $params
 */
function demo_seed_require_execute(string $sql, string $types = '', array $params = [], string $step = 'database_write', array $context = []): void
{
    if (db_execute($sql, $types, $params) === false) {
        demo_seed_fail($step, 'Database write failed.', $context);
    }
}

function demo_seed_require_insert_id(string $sql, string $types = '', array $params = [], string $step = 'insert', array $context = []): int
{
    global $conn;
    $result = db_execute($sql, $types, $params);
    if ($result === false) {
        demo_seed_fail($step, 'Insert failed.', $context);
    }
    if (is_int($result) && $result > 0) {
        return $result;
    }
    $id = (int)($conn->insert_id ?? 0);
    if ($id > 0) {
        return $id;
    }
    if ($result === true) {
        demo_seed_fail(
            $step,
            'Insert reported success but no insert_id was returned (possible duplicate key or schema trigger).',
            $context
        );
    }
    demo_seed_fail($step, 'Insert did not return a new row id.', $context);
}

/**
 * @return array{
 *   rows:list<array<string,mixed>>,
 *   order_ids:list<int>,
 *   order_item_ids:list<int>,
 *   customization_ids:list<int>,
 *   job_order_ids:list<int>,
 *   customer_ids:list<int>
 * }
 */
function demo_seed_registry_snapshot(string $batchId): array
{
    $rows = db_query(
        'SELECT * FROM ' . DEMO_SEED_ROW_TABLE . ' WHERE batch_id = ? ORDER BY id ASC',
        's',
        [$batchId]
    ) ?: [];

    $unique = static function (array $values): array {
        return array_values(array_unique(array_filter(array_map('intval', $values))));
    };

    return [
        'rows' => $rows,
        'order_ids' => $unique(array_column($rows, 'order_id')),
        'order_item_ids' => $unique(array_column($rows, 'order_item_id')),
        'customization_ids' => $unique(array_column($rows, 'customization_id')),
        'job_order_ids' => $unique(array_column($rows, 'job_order_id')),
        'customer_ids' => $unique(array_column($rows, 'customer_id')),
    ];
}

/**
 * @param array{rows:list<array<string,mixed>>} $snap
 * @return list<array{seed_row_key:string,missing:list<string>}>
 */
function demo_seed_registry_gap_report(array $snap): array
{
    $gaps = [];
    foreach ($snap['rows'] as $row) {
        $missing = [];
        foreach (['order_id', 'order_item_id', 'customization_id', 'job_order_id', 'customer_id'] as $col) {
            if ((int)($row[$col] ?? 0) <= 0) {
                $missing[] = $col;
            }
        }
        if ($missing !== []) {
            $gaps[] = [
                'seed_row_key' => (string)($row['seed_row_key'] ?? ''),
                'missing' => $missing,
            ];
        }
    }
    return $gaps;
}

/** @param list<int> $ids */
function demo_seed_delete_ids_in(string $table, string $column, array $ids): void
{
    if ($ids === [] || !demo_seed_table_exists($table) || !db_table_has_column($table, $column)) {
        return;
    }
    $csv = implode(',', array_map('intval', $ids));
    demo_seed_require_execute("DELETE FROM {$table} WHERE {$column} IN ({$csv})");
}

/** @param list<int> $orderIds */
function demo_seed_delete_for_order_ids(string $table, array $orderIds): void
{
    if ($orderIds === [] || !demo_seed_table_exists($table) || !db_table_has_column($table, 'order_id')) {
        return;
    }
    $csv = implode(',', array_map('intval', $orderIds));
    demo_seed_require_execute("DELETE FROM {$table} WHERE order_id IN ({$csv})");
}

/**
 * Remove demo batch data. Uses demo_seed_rows IDs first, then order_id-scoped cleanup for broken imports.
 * Never touches inventory_transactions.
 *
 * @return array<string,mixed>
 */
function demo_seed_purge_batch_data(string $batchId, array $snap): array
{
    $orderIds = $snap['order_ids'];
    $deleted = [
        'orders' => 0,
        'order_items' => 0,
        'customizations' => 0,
        'job_orders' => 0,
        'customers' => 0,
    ];

    if ($orderIds === []) {
        return $deleted;
    }

    $jobIdsOnOrders = demo_seed_collect_job_ids($orderIds);
    if ($jobIdsOnOrders !== []) {
        demo_seed_delete_ids_in('job_order_ink_usage', 'job_order_id', $jobIdsOnOrders);
        demo_seed_delete_ids_in('job_order_materials', 'job_order_id', $jobIdsOnOrders);
        demo_seed_delete_ids_in('job_order_files', 'job_order_id', $jobIdsOnOrders);
    }

    demo_seed_delete_ids_in('job_orders', 'id', $snap['job_order_ids']);
    demo_seed_delete_ids_in('customizations', 'customization_id', $snap['customization_ids']);
    demo_seed_delete_ids_in('order_items', 'order_item_id', $snap['order_item_ids']);

    demo_seed_delete_for_order_ids('job_orders', $orderIds);
    demo_seed_delete_for_order_ids('customizations', $orderIds);
    demo_seed_delete_for_order_ids('order_items', $orderIds);

    foreach (['order_status_history', 'order_messages', 'order_notes', 'order_designs'] as $table) {
        demo_seed_delete_for_order_ids($table, $orderIds);
    }
    demo_seed_delete_for_order_ids('payment_submissions', $orderIds);
    demo_seed_delete_for_order_ids('provider_payments', $orderIds);
    if (demo_seed_table_exists('change_item_requests')) {
        demo_seed_delete_for_order_ids('change_item_requests', $orderIds);
    }

    demo_seed_delete_ids_in('orders', 'order_id', $orderIds);
    $deleted['orders'] = count($orderIds);

    foreach ($snap['customer_ids'] as $customerId) {
        if (demo_seed_customer_safe_to_delete($batchId, $customerId)) {
            demo_seed_require_execute('DELETE FROM customers WHERE customer_id = ?', 'i', [$customerId]);
            $deleted['customers']++;
        }
    }

    return $deleted;
}

/** @param list<int> $ids */
function demo_seed_count_rows_by_pk(string $table, string $pkColumn, array $ids): int
{
    if ($ids === [] || !demo_seed_table_exists($table) || !db_table_has_column($table, $pkColumn)) {
        return 0;
    }
    $csv = implode(',', array_map('intval', $ids));
    return (int)(db_query("SELECT COUNT(*) AS c FROM {$table} WHERE {$pkColumn} IN ({$csv})")[0]['c'] ?? 0);
}

/**
 * @return array{ok:bool,expected_rows:int,checks:array<string,mixed>,errors:list<string>}
 */
function demo_seed_verify_batch_integrity(string $batchId, int $expectedRows): array
{
    $snap = demo_seed_registry_snapshot($batchId);
    $registryCount = count($snap['rows']);
    $errors = [];

    $checks = [
        'registry_rows' => $registryCount,
        'order_ids' => count($snap['order_ids']),
        'order_item_ids' => count($snap['order_item_ids']),
        'customization_ids' => count($snap['customization_ids']),
        'job_order_ids' => count($snap['job_order_ids']),
        'customer_ids' => count(array_filter($snap['customer_ids'], static fn(int $id): bool => $id > 0)),
        'orders_in_db' => demo_seed_count_rows_by_pk('orders', 'order_id', $snap['order_ids']),
        'order_items_in_db' => demo_seed_count_rows_by_pk('order_items', 'order_item_id', $snap['order_item_ids']),
        'customizations_in_db' => demo_seed_count_rows_by_pk('customizations', 'customization_id', $snap['customization_ids']),
        'job_orders_in_db' => demo_seed_count_rows_by_pk('job_orders', 'id', $snap['job_order_ids']),
        'customers_in_db' => demo_seed_count_rows_by_pk('customers', 'customer_id', $snap['customer_ids']),
        'provider_payments' => demo_seed_count_in('provider_payments', 'order_id', $snap['order_ids']),
        'payment_submissions' => demo_seed_count_in('payment_submissions', 'order_id', $snap['order_ids']),
        'inventory_transactions' => 0,
        'job_order_materials' => demo_seed_count_rows_by_pk('job_order_materials', 'job_order_id', $snap['job_order_ids']),
        'job_order_ink_usage' => demo_seed_count_rows_by_pk('job_order_ink_usage', 'job_order_id', $snap['job_order_ids']),
    ];

    if ($registryCount !== $expectedRows) {
        $errors[] = 'Registry row count mismatch (expected ' . $expectedRows . ', got ' . $registryCount . ').';
    }
    foreach (['order_ids', 'order_item_ids', 'customization_ids', 'job_order_ids', 'customer_ids'] as $key) {
        if (count($snap[$key]) !== $expectedRows) {
            $errors[] = 'Expected ' . $expectedRows . ' ' . $key . ', got ' . count($snap[$key]) . '.';
        }
    }
    if (($checks['orders_in_db'] ?? 0) !== $expectedRows) {
        $errors[] = 'orders table row count mismatch.';
    }
    if (($checks['order_items_in_db'] ?? 0) !== $expectedRows) {
        $errors[] = 'order_items table row count mismatch.';
    }
    if (($checks['customizations_in_db'] ?? 0) !== $expectedRows) {
        $errors[] = 'customizations table row count mismatch.';
    }
    if (($checks['job_orders_in_db'] ?? 0) !== $expectedRows) {
        $errors[] = 'job_orders table row count mismatch.';
    }
    if (($checks['customers_in_db'] ?? 0) !== $expectedRows) {
        $errors[] = 'customers table row count mismatch.';
    }
    if (($checks['provider_payments'] ?? 0) !== 0 || ($checks['payment_submissions'] ?? 0) !== 0) {
        $errors[] = 'Demo import must not create payment provider/submission rows.';
    }
    if (($checks['job_order_materials'] ?? 0) !== 0 || ($checks['job_order_ink_usage'] ?? 0) !== 0) {
        $errors[] = 'Demo import must not create job material/ink rows.';
    }

    foreach ($snap['rows'] as $row) {
        foreach (['order_id', 'order_item_id', 'customization_id', 'job_order_id', 'customer_id'] as $col) {
            if ((int)($row[$col] ?? 0) <= 0) {
                $errors[] = 'Registry row ' . ($row['seed_row_key'] ?? '') . ' is missing ' . $col . '.';
            }
        }
    }

    return [
        'ok' => $errors === [],
        'expected_rows' => $expectedRows,
        'checks' => $checks,
        'errors' => $errors,
    ];
}

/**
 * @return array{width?:string,height?:string}
 */
function demo_seed_parse_spec_dimensions(string $specSummary, string $serviceDisplayName): array
{
    $spec = trim($specSummary);
    if ($spec === '') {
        return [];
    }
    $service = strtolower($serviceDisplayName);
    if (!str_contains($service, 'tarp')) {
        return [];
    }
    if (!preg_match('/(\d+(?:\.\d+)?)\s*(?:ft|feet|\')?\s*[x×]\s*(\d+(?:\.\d+)?)/i', $spec, $match)) {
        return [];
    }
    return [
        'width' => (string)$match[1],
        'height' => (string)$match[2],
    ];
}

/**
 * @param array<string,string> $customer
 * @return array<string,mixed>
 */
function demo_seed_build_pos_customization_payload(
    array $row,
    string $batchId,
    int $catalogId,
    array $customer,
    string $orderAt
): array {
    $specSummary = trim((string)($row['spec_summary'] ?? ''));
    $serviceName = (string)($row['service_display_name'] ?? '');
    $payload = [
        'service_type' => $serviceName,
        'service_id' => $catalogId,
        'source' => 'POS',
        'source_page' => 'pos',
        'spec_summary' => $specSummary,
        'quantity' => 1,
        'needed_date' => substr($orderAt, 0, 10),
        'customer_first_name' => (string)$customer['first_name'],
        'customer_last_name' => (string)$customer['last_name'],
        'customer_email' => (string)$customer['email'],
        'customer_phone' => (string)$customer['phone'],
        '_seed_batch_id' => $batchId,
    ];
    foreach (demo_seed_parse_spec_dimensions($specSummary, $serviceName) as $key => $value) {
        $payload[$key] = $value;
    }
    return $payload;
}

/**
 * @return array<string,mixed>
 */
function demo_seed_trace_visibility_checks(
    ?array $order,
    ?array $customization,
    ?array $jobOrder,
    ?array $customer,
    ?int $staffBranchId
): array {
    $reasons = [];
    $orderId = (int)($order['order_id'] ?? 0);
    $branchId = (int)($order['branch_id'] ?? $jobOrder['branch_id'] ?? 0);
    $orderSource = strtolower(trim((string)($order['order_source'] ?? '')));
    $email = strtolower(trim((string)($customer['email'] ?? '')));

    if ($staffBranchId !== null && $branchId > 0 && $branchId !== $staffBranchId) {
        $reasons[] = 'branch_filter: order branch_id ' . $branchId . ' != staff branch ' . $staffBranchId;
    }
    if ($orderSource === 'pos_draft' || $orderSource === 'pos_merged') {
        $reasons[] = 'order_source excluded: ' . $orderSource;
    }
    $isPos = in_array($orderSource, ['pos', 'walk-in'], true)
        || $email === 'walkin@pos.local'
        || str_contains((string)($customization['customization_details'] ?? ''), '"source":"POS"');
    if (!$isPos) {
        $reasons[] = 'pos_staff_view: order_source is not POS/walk-in and no POS customization marker';
    }
    $orderStatus = trim((string)($order['status'] ?? ''));
    $customStatus = trim((string)($customization['status'] ?? ''));
    if ($orderId <= 0) {
        $reasons[] = 'missing order_id link';
    }

    return [
        'staff_pos_list_eligible' => $reasons === [],
        'staff_pos_count_eligible' => $reasons === [],
        'exclusion_reasons' => $reasons,
        'resolved_order_source' => $isPos ? 'pos' : ($orderSource !== '' ? $orderSource : 'customer'),
        'order_status' => $orderStatus,
        'customization_status' => $customStatus,
        'job_order_status' => (string)($jobOrder['status'] ?? ''),
        'branch_id' => $branchId,
    ];
}

/**
 * @return array<string,mixed>
 */
function demo_seed_trace_seed_row(string $batchId, string $seedRowKey): array
{
    demo_seed_ensure_tables();
    $seedRowKey = trim($seedRowKey);
    $registry = db_query(
        'SELECT * FROM ' . DEMO_SEED_ROW_TABLE . ' WHERE batch_id = ? AND seed_row_key = ? LIMIT 1',
        'ss',
        [$batchId, $seedRowKey]
    ) ?: [];
    if ($registry === []) {
        return ['found' => false, 'message' => 'No demo_seed_rows entry for this batch/key.'];
    }
    $reg = $registry[0];
    $orderId = (int)($reg['order_id'] ?? 0);
    $customerId = (int)($reg['customer_id'] ?? 0);
    $orderItemId = (int)($reg['order_item_id'] ?? 0);
    $customizationId = (int)($reg['customization_id'] ?? 0);
    $jobOrderId = (int)($reg['job_order_id'] ?? 0);

    $customer = $customerId > 0
        ? (db_query('SELECT * FROM customers WHERE customer_id = ? LIMIT 1', 'i', [$customerId])[0] ?? null)
        : null;
    $order = $orderId > 0
        ? (db_query('SELECT * FROM orders WHERE order_id = ? LIMIT 1', 'i', [$orderId])[0] ?? null)
        : null;
    $orderItem = $orderItemId > 0
        ? (db_query('SELECT * FROM order_items WHERE order_item_id = ? LIMIT 1', 'i', [$orderItemId])[0] ?? null)
        : null;
    $customization = $customizationId > 0
        ? (db_query('SELECT * FROM customizations WHERE customization_id = ? LIMIT 1', 'i', [$customizationId])[0] ?? null)
        : null;
    $jobOrder = $jobOrderId > 0
        ? (db_query('SELECT * FROM job_orders WHERE id = ? LIMIT 1', 'i', [$jobOrderId])[0] ?? null)
        : null;

    $customerEmail = strtolower(trim((string)($customer['email'] ?? '')));
    $displayName = trim((string)($customer['first_name'] ?? '') . ' ' . (string)($customer['last_name'] ?? ''));
    $walkInGuest = $customerEmail === 'walkin@pos.local';
    $staffBranch = function_exists('printflow_branch_filter_for_user') ? printflow_branch_filter_for_user() : null;

    $customDetails = [];
    if (!empty($customization['customization_details'])) {
        $decoded = json_decode((string)$customization['customization_details'], true);
        $customDetails = is_array($decoded) ? $decoded : [];
    }

    return [
        'found' => true,
        'registry' => $reg,
        'ids' => [
            'seed_row_key' => $seedRowKey,
            'customer_id' => $customerId,
            'order_id' => $orderId,
            'order_item_id' => $orderItemId,
            'customization_id' => $customizationId,
            'job_order_id' => $jobOrderId,
        ],
        'customer' => $customer ? [
            'customer_id' => $customerId,
            'first_name' => (string)($customer['first_name'] ?? ''),
            'last_name' => (string)($customer['last_name'] ?? ''),
            'email' => (string)($customer['email'] ?? ''),
            'contact_number' => (string)($customer['contact_number'] ?? ''),
        ] : null,
        'order' => $order,
        'order_item' => $orderItem,
        'customization' => $customization,
        'job_order' => $jobOrder,
        'dates' => [
            'orders.order_date' => (string)($order['order_date'] ?? ''),
            'orders.created_at' => (string)($order['created_at'] ?? ''),
            'customizations.created_at' => (string)($customization['created_at'] ?? ''),
            'customizations.updated_at' => (string)($customization['updated_at'] ?? ''),
            'customizations.needed_date' => (string)($customization['needed_date'] ?? ''),
            'payload.needed_date' => (string)($customDetails['needed_date'] ?? ''),
            'job_orders.created_at' => (string)($jobOrder['created_at'] ?? ''),
            'job_orders.updated_at' => (string)($jobOrder['updated_at'] ?? ''),
            'job_orders.due_date' => (string)($jobOrder['due_date'] ?? ''),
        ],
        'visibility' => demo_seed_trace_visibility_checks(
            is_array($order) ? $order : null,
            is_array($customization) ? $customization : null,
            is_array($jobOrder) ? $jobOrder : null,
            is_array($customer) ? $customer : null,
            $staffBranch !== null ? (int)$staffBranch : null
        ),
        'display' => [
            'customer_display_name' => $displayName,
            'shows_walk_in_guest' => $walkInGuest || strcasecmp($displayName, 'Walk-in Guest') === 0,
            'walk_in_reason' => $walkInGuest ? 'customer.email is walkin@pos.local' : ($displayName === '' ? 'customer name empty' : null),
            'spec_summary' => (string)($customDetails['spec_summary'] ?? ''),
        ],
    ];
}

function demo_seed_ensure_tables(): void
{
    $sql1 = 'CREATE TABLE IF NOT EXISTS ' . DEMO_SEED_BATCH_TABLE . " (
        id BIGINT NOT NULL AUTO_INCREMENT,
        batch_id VARCHAR(80) NOT NULL,
        label VARCHAR(160) NULL,
        source_file_name VARCHAR(255) NULL,
        imported_by INT NULL,
        imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        rolled_back_by INT NULL,
        rolled_back_at DATETIME NULL DEFAULT NULL,
        total_orders INT NOT NULL DEFAULT 0,
        total_sales DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        completed_count INT NOT NULL DEFAULT 0,
        pending_count INT NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        PRIMARY KEY (id),
        UNIQUE KEY uq_demo_seed_batch_id (batch_id),
        KEY idx_demo_seed_status (status, rolled_back_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $sql2 = 'CREATE TABLE IF NOT EXISTS ' . DEMO_SEED_ROW_TABLE . " (
        id BIGINT NOT NULL AUTO_INCREMENT,
        batch_id VARCHAR(80) NOT NULL,
        seed_row_key VARCHAR(80) NOT NULL,
        order_id INT NOT NULL,
        order_item_id INT NULL,
        customization_id INT NULL,
        job_order_id INT NULL,
        customer_id INT NOT NULL,
        customer_created TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_demo_seed_rows_batch (batch_id),
        KEY idx_demo_seed_rows_order (order_id),
        KEY idx_demo_seed_rows_customer (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!db_execute($sql1) || !db_execute($sql2)) {
        throw new RuntimeException('Could not create or verify demo seed maintenance tables.');
    }
}

function demo_seed_active_batch(): ?array
{
    if (!demo_seed_table_exists(DEMO_SEED_BATCH_TABLE)) {
        return null;
    }
    $rows = db_query(
        "SELECT b.*, CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) AS imported_by_name
         FROM " . DEMO_SEED_BATCH_TABLE . " b
         LEFT JOIN users u ON u.user_id = b.imported_by
         WHERE b.status = 'active' AND b.rolled_back_at IS NULL
         ORDER BY b.imported_at DESC
         LIMIT 1"
    ) ?: [];
    return $rows[0] ?? null;
}

function demo_seed_pos_service_placeholder_product_id(): int
{
    $existing = db_query("SELECT product_id FROM products WHERE sku = 'POS-SERVICE' LIMIT 1") ?: [];
    if (!empty($existing)) {
        return (int)$existing[0]['product_id'];
    }
    global $conn;
    if (db_execute(
        "INSERT INTO products (sku, name, category, description, price, stock_quantity, status)
         VALUES ('POS-SERVICE', 'POS Service Item', 'Service', 'Placeholder product for POS service-only order items.', 0, 0, 'Activated')"
    )) {
        return (int)$conn->insert_id;
    }
    $fallback = db_query('SELECT product_id FROM products ORDER BY product_id ASC LIMIT 1') ?: [];
    return (int)($fallback[0]['product_id'] ?? 0);
}

function demo_seed_cabuyao_branch_id(): int
{
    if (function_exists('printflow_get_default_admin_branch_id')) {
        return (int)printflow_get_default_admin_branch_id();
    }
    return 1;
}

function demo_seed_branch_is_cabuyao(int $branchId): bool
{
    if ($branchId <= 0) {
        return false;
    }
    $expected = demo_seed_cabuyao_branch_id();
    if ($branchId === $expected) {
        return true;
    }
    $rows = db_query(
        "SELECT id FROM branches
         WHERE id = ?
           AND status != 'Archived'
           AND (
               LOWER(branch_name) LIKE '%cabuyao%'
               OR LOWER(COALESCE(city, '')) LIKE '%cabuyao%'
               OR LOWER(COALESCE(address, '')) LIKE '%cabuyao%'
           )
         LIMIT 1",
        'i',
        [$branchId]
    ) ?: [];
    return !empty($rows);
}

function demo_seed_staff_user_valid(int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $rows = db_query(
        "SELECT user_id FROM users WHERE user_id = ? AND role IN ('Admin', 'Staff') AND status = 'Activated' LIMIT 1",
        'i',
        [$userId]
    ) ?: [];
    return !empty($rows);
}

function demo_seed_contains_banned_visible_text(string $text): bool
{
    return (bool)preg_match('/\b(demo|test|fake|sample|dummy)\b/i', $text);
}

function demo_seed_contains_provider_like_text(string $text): bool
{
    $lower = strtolower($text);
    $needles = [
        'paymongo', 'payment_intent', 'provider_reference', 'provider_payment',
        'checkout_session', 'source_id', 'gcash', 'maya', 'qrph',
    ];
    foreach ($needles as $needle) {
        if (str_contains($lower, $needle)) {
            return true;
        }
    }
    return false;
}

function demo_seed_is_raffle_service(string $serviceName, string $enumName): bool
{
    $blob = strtolower($serviceName . ' ' . $enumName);
    return str_contains($blob, 'raffle');
}

/**
 * @return list<array{service_id:int,name:string}>
 */
function demo_seed_list_activated_services(): array
{
    return db_query(
        "SELECT service_id, name
         FROM services
         WHERE LOWER(TRIM(COALESCE(status, ''))) = 'activated'
         ORDER BY name ASC"
    ) ?: [];
}

function demo_seed_get_branch_row(int $branchId): ?array
{
    if ($branchId <= 0) {
        return null;
    }
    $rows = db_query(
        "SELECT id, branch_name, city FROM branches WHERE id = ? LIMIT 1",
        'i',
        [$branchId]
    ) ?: [];
    return $rows[0] ?? null;
}

function demo_seed_get_staff_row(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $rows = db_query(
        "SELECT user_id, first_name, last_name, role
         FROM users
         WHERE user_id = ? AND role IN ('Admin', 'Staff') AND status = 'Activated'
         LIMIT 1",
        'i',
        [$userId]
    ) ?: [];
    return $rows[0] ?? null;
}

/**
 * @return list<array{service_id:int,name:string}>
 */
function demo_seed_find_service_catalog_matches(string $displayName): array
{
    $displayName = trim($displayName);
    if ($displayName === '') {
        return [];
    }

    $catalog = demo_seed_list_activated_services();
    $exact = [];
    foreach ($catalog as $row) {
        if (strcasecmp(trim((string)$row['name']), $displayName) === 0) {
            $exact[] = [
                'service_id' => (int)$row['service_id'],
                'name' => (string)$row['name'],
            ];
        }
    }
    if ($exact !== []) {
        return $exact;
    }

    $resolvedId = 0;
    if (function_exists('printflow_resolve_active_service_catalog_id')) {
        $resolvedId = printflow_resolve_active_service_catalog_id($displayName);
    } elseif (function_exists('printflow_resolve_service_catalog_service_id')) {
        $resolvedId = printflow_resolve_service_catalog_service_id($displayName);
    }
    if ($resolvedId > 0) {
        foreach ($catalog as $row) {
            if ((int)$row['service_id'] === $resolvedId) {
                return [[
                    'service_id' => $resolvedId,
                    'name' => (string)$row['name'],
                ]];
            }
        }
    }

    $needle = strtolower($displayName);
    $aliasNeedles = [
        'tarpaulin' => 'tarpaulin',
        't-shirt' => 't-shirt',
        'stickers decals' => 'decals/stickers',
        'poster printing' => 'poster',
        'sintraboard standees' => 'standee',
        'mugs' => 'mug',
        'mug printing' => 'mug',
    ];
    foreach ($aliasNeedles as $alias => $fragment) {
        if (str_contains($needle, $alias) || str_contains($alias, $needle)) {
            foreach ($catalog as $row) {
                if (str_contains(strtolower((string)$row['name']), $fragment)) {
                    return [[
                        'service_id' => (int)$row['service_id'],
                        'name' => (string)$row['name'],
                    ]];
                }
            }
        }
    }

    $partial = [];
    foreach ($catalog as $row) {
        $name = strtolower(trim((string)$row['name']));
        if ($name === '' || strlen($needle) < 4) {
            continue;
        }
        if (str_contains($name, $needle) || str_contains($needle, $name)) {
            $partial[] = [
                'service_id' => (int)$row['service_id'],
                'name' => (string)$row['name'],
            ];
        }
    }
    return $partial;
}

/**
 * @return array{ok:bool,service_id?:int,service_name?:string,method?:string,error?:string,candidates?:list<array{service_id:int,name:string}>,available?:list<string>}
 */
function demo_seed_resolve_service_catalog(int $csvCatalogId, string $displayName): array
{
    $displayName = trim($displayName);
    if ($csvCatalogId > 0) {
        $byId = db_query(
            "SELECT service_id, name FROM services
             WHERE service_id = ? AND LOWER(TRIM(COALESCE(status, ''))) <> 'archived'
             LIMIT 1",
            'i',
            [$csvCatalogId]
        ) ?: [];
        if ($byId !== []) {
            return [
                'ok' => true,
                'service_id' => (int)$byId[0]['service_id'],
                'service_name' => (string)$byId[0]['name'],
                'method' => 'csv_service_id',
            ];
        }
    }

    $matches = demo_seed_find_service_catalog_matches($displayName);
    if (count($matches) === 1) {
        return [
            'ok' => true,
            'service_id' => (int)$matches[0]['service_id'],
            'service_name' => (string)$matches[0]['name'],
            'method' => $csvCatalogId > 0 ? 'display_name_after_invalid_csv_id' : 'display_name',
        ];
    }
    if (count($matches) > 1) {
        return [
            'ok' => false,
            'error' => 'Multiple catalog services match "' . $displayName . '". Use a more specific service_display_name or a valid service_catalog_id.',
            'candidates' => $matches,
        ];
    }

    $available = array_map(static fn(array $r): string => (string)$r['name'], demo_seed_list_activated_services());
    return [
        'ok' => false,
        'error' => 'No activated catalog service matches "' . $displayName . '".',
        'available' => $available,
    ];
}

/**
 * @return array{ok:bool,enum?:string,method?:string,error?:string,available?:list<string>}
 */
function demo_seed_resolve_job_service_enum(string $displayName, string $csvEnum = ''): array
{
    $enums = demo_seed_job_service_enum_values();
    $csvEnum = trim($csvEnum);
    if ($csvEnum !== '') {
        $matched = demo_seed_match_job_enum($csvEnum);
        foreach ($enums as $enum) {
            if (strcasecmp($enum, $matched) === 0) {
                return ['ok' => true, 'enum' => $enum, 'method' => 'csv_enum'];
            }
        }
    }

    if (!class_exists('JobOrderService')) {
        require_once __DIR__ . '/JobOrderService.php';
    }
    $inferred = JobOrderService::inferServiceTypeFromProduct('', $displayName);
    foreach ($enums as $enum) {
        if (strcasecmp($enum, $inferred) === 0) {
            return ['ok' => true, 'enum' => $enum, 'method' => 'inferred_from_display_name'];
        }
    }

    $lower = strtolower($displayName);
    $aliasMap = [
        'poster' => 'Layouts',
        'mug' => 'Souvenirs',
        'address plate' => 'Reflectorized (Subdivision Stickers/Signages)',
        'sintra' => 'Stickers on Sintraboard',
        'standee' => 'Sintraboard Standees',
        't-shirt' => 'T-shirt Printing',
        'tshirt' => 'T-shirt Printing',
        'sticker' => 'Decals/Stickers (Print/Cut)',
        'decal' => 'Decals/Stickers (Print/Cut)',
        'tarp' => 'Tarpaulin Printing',
    ];
    foreach ($aliasMap as $needle => $enum) {
        if (str_contains($lower, $needle)) {
            return ['ok' => true, 'enum' => $enum, 'method' => 'alias_from_display_name'];
        }
    }

    return [
        'ok' => false,
        'error' => 'Could not map job service type for "' . $displayName . '".',
        'available' => $enums,
    ];
}

/**
 * @return array{ok:bool,branch_id?:int,branch_name?:string,method?:string,error?:string}
 */
function demo_seed_resolve_branch_id(int $csvBranchId): array
{
    $defaultId = demo_seed_cabuyao_branch_id();
    if ($csvBranchId > 0 && demo_seed_branch_is_cabuyao($csvBranchId)) {
        $row = demo_seed_get_branch_row($csvBranchId);
        return [
            'ok' => true,
            'branch_id' => $csvBranchId,
            'branch_name' => (string)($row['branch_name'] ?? ('Branch #' . $csvBranchId)),
            'method' => 'csv_branch_id',
        ];
    }
    if ($csvBranchId > 0) {
        return ['ok' => false, 'error' => 'branch_id must be the Cabuyao branch (default id ' . $defaultId . ').'];
    }
    $row = demo_seed_get_branch_row($defaultId);
    return [
        'ok' => true,
        'branch_id' => $defaultId,
        'branch_name' => (string)($row['branch_name'] ?? ('Branch #' . $defaultId)),
        'method' => 'default_cabuyao',
    ];
}

/**
 * @return array{ok:bool,user_id?:int,user_label?:string,method?:string,error?:string}
 */
function demo_seed_resolve_staff_user_id(int $csvStaffId, int $fallbackStaffId): array
{
    if ($csvStaffId > 0 && demo_seed_staff_user_valid($csvStaffId)) {
        $row = demo_seed_get_staff_row($csvStaffId);
        return [
            'ok' => true,
            'user_id' => $csvStaffId,
            'user_label' => trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')) . ' (' . (string)($row['role'] ?? '') . ')',
            'method' => 'csv_staff_user_id',
        ];
    }
    if ($fallbackStaffId > 0 && demo_seed_staff_user_valid($fallbackStaffId)) {
        $row = demo_seed_get_staff_row($fallbackStaffId);
        return [
            'ok' => true,
            'user_id' => $fallbackStaffId,
            'user_label' => trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')) . ' (' . (string)($row['role'] ?? '') . ')',
            'method' => $csvStaffId > 0 ? 'logged_in_after_invalid_csv_id' : 'logged_in_default',
        ];
    }
    return ['ok' => false, 'error' => 'Could not resolve an Admin/Staff user for import (check staff_user_id or log in as Admin/Staff).'];
}

function demo_seed_resolver_reference(int $fallbackStaffId): array
{
    $branchId = demo_seed_cabuyao_branch_id();
    $branch = demo_seed_get_branch_row($branchId);
    $staff = demo_seed_resolve_staff_user_id(0, $fallbackStaffId);
    return [
        'service_names' => demo_seed_list_activated_services(),
        'job_service_types' => demo_seed_job_service_enum_values(),
        'default_branch' => [
            'branch_id' => $branchId,
            'branch_name' => (string)($branch['branch_name'] ?? ''),
            'city' => (string)($branch['city'] ?? ''),
        ],
        'default_staff' => $staff,
    ];
}

/**
 * @return array{rows:list<array<string,string>>,errors:list<array{row:int,key?:string,message:string}>}
 */
function demo_seed_parse_csv_content(string $content): array
{
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
    $lines = preg_split('/\R/', trim($content));
    if ($lines === false || $lines === []) {
        return ['rows' => [], 'errors' => [['row' => 0, 'message' => 'CSV file is empty.']]];
    }

    $header = str_getcsv(array_shift($lines));
    $header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);
    $required = demo_seed_required_csv_columns();
    $errors = [];
    foreach ($required as $col) {
        if (!in_array($col, $header, true)) {
            $errors[] = ['row' => 0, 'message' => 'Missing required column: ' . $col];
        }
    }
    foreach ($header as $col) {
        if ($col !== '' && demo_seed_contains_provider_like_text($col)) {
            $errors[] = ['row' => 0, 'message' => 'Disallowed provider-like column: ' . $col];
        }
    }
    if ($errors !== []) {
        return ['rows' => [], 'errors' => $errors];
    }

    $rows = [];
    $lineNo = 1;
    foreach ($lines as $line) {
        $lineNo++;
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line);
        if (count($cells) < count($header)) {
            $cells = array_pad($cells, count($header), '');
        }
        $assoc = [];
        foreach ($header as $i => $col) {
            $assoc[$col] = trim((string)($cells[$i] ?? ''));
        }
        $assoc['_csv_line'] = (string)$lineNo;
        $rows[] = $assoc;
    }

    return ['rows' => $rows, 'errors' => $errors];
}

/**
 * @param list<array<string,string>> $rows
 * @return array{valid:bool,summary:array<string,mixed>,row_errors:list<array<string,mixed>>,normalized_rows:list<array<string,mixed>>,row_resolutions:list<array<string,mixed>>}
 */
function demo_seed_validate_rows(array $rows, array $options = []): array
{
    $fallbackStaffId = (int)($options['fallback_staff_user_id'] ?? 0);
    $skipActiveBatchCheck = (bool)($options['skip_active_batch_check'] ?? false);
    $skipCustomerEmailDbCheck = (bool)($options['skip_customer_email_db_check'] ?? false);
    $rowErrors = [];
    $normalized = [];
    $rowResolutions = [];
    $pendingCount = 0;
    $completedCount = 0;
    $totalSales = 0.0;
    $services = [];
    $emailsInBatch = [];
    $batchIds = [];
    $minTs = null;
    $maxTs = null;
    $branchId = null;
    $branchName = null;
    $staffId = null;
    $staffLabel = null;

    $minDt = new DateTimeImmutable(DEMO_SEED_DATE_MIN);
    $maxDt = new DateTimeImmutable(DEMO_SEED_DATE_MAX);
    $pendingMinDt = new DateTimeImmutable(DEMO_SEED_PENDING_MIN);

    $allowedOrderStatus = ['completed', 'pending'];
    $allowedCustomizationStatus = ['completed', 'in production', 'pending'];
    $allowedJobStatus = ['completed', 'pending', 'in_production'];

    foreach ($rows as $index => $row) {
        $line = (int)($row['_csv_line'] ?? ($index + 2));
        $key = trim((string)($row['seed_row_key'] ?? ''));
        $errorsForRow = [];

        if ($key === '') {
            $errorsForRow[] = 'seed_row_key is required.';
        }

        $batchId = trim((string)($row['seed_batch_id'] ?? ''));
        if ($batchId === '') {
            $errorsForRow[] = 'seed_batch_id is required.';
        } else {
            $batchIds[$batchId] = true;
        }

        $orderAtRaw = trim((string)($row['order_datetime'] ?? ''));
        $orderAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $orderAtRaw);
        $fmtErrors = DateTimeImmutable::getLastErrors();
        if (!$orderAt || ($fmtErrors !== false && ($fmtErrors['warning_count'] > 0 || $fmtErrors['error_count'] > 0))
            || $orderAt->format('Y-m-d H:i:s') !== $orderAtRaw) {
            $errorsForRow[] = 'order_datetime must be Y-m-d H:i:s between ' . DEMO_SEED_DATE_MIN . ' and ' . DEMO_SEED_DATE_MAX . '.';
        } elseif ($orderAt < $minDt || $orderAt > $maxDt) {
            $errorsForRow[] = 'order_datetime is outside the allowed meeting window.';
        } else {
            if ($minTs === null || $orderAt < $minTs) {
                $minTs = $orderAt;
            }
            if ($maxTs === null || $orderAt > $maxTs) {
                $maxTs = $orderAt;
            }
        }

        $orderStatus = strtolower(trim((string)($row['order_status'] ?? '')));
        if (!in_array($orderStatus, $allowedOrderStatus, true)) {
            $errorsForRow[] = 'order_status must be Completed or Pending.';
        } elseif ($orderStatus === 'pending') {
            if (!$orderAt || $orderAt < $pendingMinDt) {
                $errorsForRow[] = 'Pending orders are only allowed from 2026-09-30 onward.';
            }
            $pendingCount++;
        } else {
            $completedCount++;
        }

        $customStatus = strtolower(trim((string)($row['customization_status'] ?? '')));
        if (!in_array($customStatus, $allowedCustomizationStatus, true)) {
            $errorsForRow[] = 'customization_status must be Completed, In Production, or Pending.';
        }

        $jobStatus = strtoupper(trim((string)($row['job_status'] ?? '')));
        $jobStatusNorm = str_replace(' ', '_', $jobStatus);
        if (!in_array(strtolower($jobStatusNorm), $allowedJobStatus, true)) {
            $errorsForRow[] = 'job_status must be COMPLETED, PENDING, or IN_PRODUCTION.';
        }

        $serviceName = trim((string)($row['service_display_name'] ?? ''));
        $jobEnumCsv = trim((string)($row['job_service_type_enum'] ?? ''));
        if ($serviceName === '') {
            $errorsForRow[] = 'service_display_name is required.';
        }
        if ($serviceName !== '' && demo_seed_is_raffle_service($serviceName, $jobEnumCsv)) {
            $errorsForRow[] = 'Raffle ticketing services are not allowed.';
        }

        $qty = (int)($row['quantity'] ?? 0);
        if ($qty !== 1) {
            $errorsForRow[] = 'quantity must be exactly 1.';
        }

        $unitPrice = round((float)($row['unit_price'] ?? 0), 2);
        $amountPaid = round((float)($row['amount_paid'] ?? 0), 2);
        if ($unitPrice <= 0 || $amountPaid <= 0) {
            $errorsForRow[] = 'unit_price and amount_paid must be positive.';
        } elseif (abs($unitPrice - $amountPaid) > 0.009) {
            $errorsForRow[] = 'unit_price must match amount_paid for single-quantity rows.';
        }

        $paymentMethod = trim((string)($row['payment_method'] ?? ''));
        if (strcasecmp($paymentMethod, 'Cash') !== 0) {
            $errorsForRow[] = 'payment_method must be Cash only.';
        }
        $paymentStatus = trim((string)($row['payment_status'] ?? ''));
        if (strcasecmp($paymentStatus, 'Paid') !== 0) {
            $errorsForRow[] = 'payment_status must be Paid.';
        }

        foreach ($row as $col => $val) {
            if ($col === 'seed_batch_id' || str_starts_with($col, '_')) {
                continue;
            }
            if (is_string($val) && demo_seed_contains_provider_like_text($val)) {
                $errorsForRow[] = 'Provider-like value is not allowed in column ' . $col . '.';
            }
        }

        $visibleFields = [
            'customer_first_name', 'customer_last_name', 'customer_email', 'customer_phone',
            'customer_street', 'customer_barangay', 'customer_city', 'customer_province',
            'customer_postal', 'spec_summary', 'service_display_name',
        ];
        foreach ($visibleFields as $field) {
            $val = trim((string)($row[$field] ?? ''));
            if ($val === '') {
                $errorsForRow[] = $field . ' is required.';
            } elseif (demo_seed_contains_banned_visible_text($val)) {
                $errorsForRow[] = $field . ' contains disallowed placeholder wording.';
            }
        }

        $email = strtolower(trim((string)($row['customer_email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorsForRow[] = 'customer_email is invalid.';
        } elseif (isset($emailsInBatch[$email])) {
            $errorsForRow[] = 'customer_email must be unique within the CSV batch.';
        } else {
            $emailsInBatch[$email] = true;
            if (!$skipCustomerEmailDbCheck) {
                $existing = db_query('SELECT customer_id FROM customers WHERE LOWER(email) = ? LIMIT 1', 's', [$email]) ?: [];
                if (!empty($existing)) {
                    $errorsForRow[] = 'customer_email already exists in the live database; use unique meeting emails.';
                }
            }
        }

        $csvCatalogId = (int)($row['service_catalog_id'] ?? 0);
        $csvBranchId = (int)($row['branch_id'] ?? 0);
        $csvStaffId = (int)($row['staff_user_id'] ?? 0);

        $catalogRes = $serviceName !== ''
            ? demo_seed_resolve_service_catalog($csvCatalogId, $serviceName)
            : ['ok' => false, 'error' => 'service_display_name is required.'];
        $branchRes = demo_seed_resolve_branch_id($csvBranchId);
        $staffRes = demo_seed_resolve_staff_user_id($csvStaffId, $fallbackStaffId);
        $enumRes = $serviceName !== ''
            ? demo_seed_resolve_job_service_enum($serviceName, $jobEnumCsv)
            : ['ok' => false, 'error' => 'service_display_name is required.'];

        if (!$catalogRes['ok']) {
            $msg = (string)($catalogRes['error'] ?? 'Could not resolve service catalog.');
            if (!empty($catalogRes['candidates'])) {
                $names = array_map(static fn(array $c): string => (string)$c['name'] . ' (id ' . (int)$c['service_id'] . ')', $catalogRes['candidates']);
                $msg .= ' Possible matches: ' . implode('; ', $names) . '.';
            } elseif (!empty($catalogRes['available'])) {
                $msg .= ' Available services: ' . implode('; ', $catalogRes['available']) . '.';
            }
            $errorsForRow[] = $msg;
        }
        if (!$branchRes['ok']) {
            $errorsForRow[] = (string)($branchRes['error'] ?? 'Could not resolve branch.');
        }
        if (!$staffRes['ok']) {
            $errorsForRow[] = (string)($staffRes['error'] ?? 'Could not resolve staff user.');
        }
        if (!$enumRes['ok']) {
            $msg = (string)($enumRes['error'] ?? 'Could not resolve job service type.');
            if (!empty($enumRes['available'])) {
                $msg .= ' Valid job_orders.service_type values: ' . implode('; ', $enumRes['available']) . '.';
            }
            $errorsForRow[] = $msg;
        }

        if ($branchRes['ok'] ?? false) {
            $resolvedBranchId = (int)$branchRes['branch_id'];
            if ($branchId === null) {
                $branchId = $resolvedBranchId;
                $branchName = (string)($branchRes['branch_name'] ?? '');
            } elseif ($branchId !== $resolvedBranchId) {
                $errorsForRow[] = 'Resolved branch_id must be consistent across all rows.';
            }
        }

        if ($staffRes['ok'] ?? false) {
            $resolvedStaffId = (int)$staffRes['user_id'];
            if ($staffId === null) {
                $staffId = $resolvedStaffId;
                $staffLabel = (string)($staffRes['user_label'] ?? '');
            } elseif ($staffId !== $resolvedStaffId) {
                $errorsForRow[] = 'Resolved staff user must be consistent across all rows (leave staff_user_id blank to use the logged-in user).';
            }
        }

        $resolution = [
            'row' => $line,
            'seed_row_key' => $key,
            'service_catalog_id_csv' => $csvCatalogId > 0 ? (string)$csvCatalogId : '',
            'service_catalog_id_resolved' => $catalogRes['ok'] ? (int)$catalogRes['service_id'] : null,
            'service_name_resolved' => $catalogRes['ok'] ? (string)$catalogRes['service_name'] : null,
            'service_resolve_method' => $catalogRes['ok'] ? (string)($catalogRes['method'] ?? '') : null,
            'job_service_type_enum_csv' => $jobEnumCsv,
            'job_service_type_enum_resolved' => $enumRes['ok'] ? (string)$enumRes['enum'] : null,
            'job_enum_resolve_method' => $enumRes['ok'] ? (string)($enumRes['method'] ?? '') : null,
            'branch_id_csv' => $csvBranchId > 0 ? (string)$csvBranchId : '',
            'branch_id_resolved' => $branchRes['ok'] ? (int)$branchRes['branch_id'] : null,
            'branch_name_resolved' => $branchRes['ok'] ? (string)($branchRes['branch_name'] ?? '') : null,
            'branch_resolve_method' => $branchRes['ok'] ? (string)($branchRes['method'] ?? '') : null,
            'staff_user_id_csv' => $csvStaffId > 0 ? (string)$csvStaffId : '',
            'staff_user_id_resolved' => $staffRes['ok'] ? (int)$staffRes['user_id'] : null,
            'staff_user_resolved_label' => $staffRes['ok'] ? (string)($staffRes['user_label'] ?? '') : null,
            'staff_resolve_method' => $staffRes['ok'] ? (string)($staffRes['method'] ?? '') : null,
        ];
        $rowResolutions[] = $resolution;

        if ($errorsForRow !== []) {
            foreach ($errorsForRow as $msg) {
                $rowErrors[] = ['row' => $line, 'seed_row_key' => $key, 'message' => $msg];
            }
            continue;
        }

        $services[$serviceName] = ($services[$serviceName] ?? 0) + 1;
        $totalSales += $amountPaid;

        $resolvedCatalogId = (int)$catalogRes['service_id'];
        $resolvedEnum = (string)$enumRes['enum'];
        $resolvedBranchId = (int)$branchRes['branch_id'];
        $resolvedStaffId = (int)$staffRes['user_id'];

        $normalized[] = [
            'seed_row_key' => $key,
            'seed_batch_id' => $batchId,
            'order_datetime' => $orderAtRaw,
            'order_status' => $orderStatus === 'completed' ? 'Completed' : 'Pending',
            'customization_status' => demo_seed_title_case_status((string)($row['customization_status'] ?? '')),
            'job_status' => demo_seed_normalize_job_status($jobStatusNorm),
            'service_display_name' => $serviceName,
            'job_service_type_enum' => $resolvedEnum,
            'service_catalog_id' => $resolvedCatalogId,
            'service_catalog_name' => (string)$catalogRes['service_name'],
            'unit_price' => $unitPrice,
            'quantity' => 1,
            'amount_paid' => $amountPaid,
            'customer' => [
                'first_name' => trim((string)$row['customer_first_name']),
                'last_name' => trim((string)$row['customer_last_name']),
                'email' => $email,
                'phone' => trim((string)$row['customer_phone']),
                'street' => trim((string)$row['customer_street']),
                'barangay' => trim((string)$row['customer_barangay']),
                'city' => trim((string)$row['customer_city']),
                'province' => trim((string)$row['customer_province']),
                'postal' => trim((string)$row['customer_postal']),
            ],
            'spec_summary' => trim((string)$row['spec_summary']),
            'staff_user_id' => $resolvedStaffId,
            'branch_id' => $resolvedBranchId,
            'resolution' => $resolution,
        ];
    }

    if (count($batchIds) > 1) {
        $rowErrors[] = ['row' => 0, 'message' => 'All rows must share the same seed_batch_id.'];
    }
    if ($pendingCount > DEMO_SEED_MAX_PENDING) {
        $rowErrors[] = ['row' => 0, 'message' => 'Pending order count exceeds maximum of ' . DEMO_SEED_MAX_PENDING . '.'];
    }

    $active = demo_seed_active_batch();
    if (!$skipActiveBatchCheck && $active !== null) {
        $rowErrors[] = [
            'row' => 0,
            'message' => 'An active demo batch already exists (' . ($active['batch_id'] ?? '') . '). Delete it before importing a new one.',
        ];
    }

    $valid = $rowErrors === [] && $normalized !== [];

    return [
        'valid' => $valid,
        'summary' => [
            'total_rows' => count($rows),
            'valid_rows' => count($normalized),
            'date_from' => $minTs ? $minTs->format('Y-m-d H:i:s') : null,
            'date_to' => $maxTs ? $maxTs->format('Y-m-d H:i:s') : null,
            'completed_count' => $completedCount,
            'pending_count' => $pendingCount,
            'total_sales' => round($totalSales, 2),
            'services_breakdown' => $services,
            'branch_id' => $branchId,
            'branch_name' => $branchName,
            'staff_user_id' => $staffId,
            'staff_user_label' => $staffLabel,
            'seed_batch_id' => count($batchIds) === 1 ? array_key_first($batchIds) : null,
            'resolution_row_count' => count($rowResolutions),
        ],
        'row_errors' => $rowErrors,
        'normalized_rows' => $normalized,
        'row_resolutions' => $rowResolutions,
    ];
}

function demo_seed_title_case_status(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'in production') {
        return 'In Production';
    }
    if ($status === 'pending') {
        return 'Pending Review';
    }
    return 'Completed';
}

function demo_seed_normalize_job_status(string $status): string
{
    $status = strtoupper(str_replace(' ', '_', trim($status)));
    return match ($status) {
        'IN_PRODUCTION' => 'IN_PRODUCTION',
        'PENDING' => 'PENDING',
        default => 'COMPLETED',
    };
}

function demo_seed_match_job_enum(string $input): string
{
    foreach (demo_seed_job_service_enum_values() as $enum) {
        if (strcasecmp($enum, $input) === 0) {
            return $enum;
        }
    }
    return trim($input);
}

function demo_seed_store_preview(array $validation, string $fileName): string
{
    $token = bin2hex(random_bytes(16));
    $_SESSION[DEMO_SEED_SESSION_KEY] = [
        'token' => $token,
        'file_name' => $fileName,
        'summary' => $validation['summary'],
        'normalized_rows' => $validation['normalized_rows'],
        'row_resolutions' => $validation['row_resolutions'] ?? [],
        'created_at' => time(),
    ];
    return $token;
}

function demo_seed_get_preview(?string $token): ?array
{
    $preview = $_SESSION[DEMO_SEED_SESSION_KEY] ?? null;
    if (!is_array($preview)) {
        return null;
    }
    if ($token !== null && (string)($preview['token'] ?? '') !== $token) {
        return null;
    }
    if ((int)($preview['created_at'] ?? 0) < time() - 7200) {
        unset($_SESSION[DEMO_SEED_SESSION_KEY]);
        return null;
    }
    return $preview;
}

function demo_seed_clear_preview(): void
{
    unset($_SESSION[DEMO_SEED_SESSION_KEY]);
}

function demo_seed_import_preflight(string $batchId): void
{
    $active = demo_seed_active_batch();
    if ($active !== null) {
        throw new RuntimeException(
            'An active demo batch already exists (' . ($active['batch_id'] ?? '') . '). '
            . 'Delete it under “Delete Demo Data” before importing again.'
        );
    }

    $prior = db_query(
        'SELECT batch_id, rolled_back_at FROM ' . DEMO_SEED_BATCH_TABLE . ' WHERE batch_id = ? LIMIT 1',
        's',
        [$batchId]
    ) ?: [];
    if ($prior !== [] && empty($prior[0]['rolled_back_at'])) {
        throw new RuntimeException(
            'Batch id "' . $batchId . '" is already registered and not rolled back. Delete or roll back that batch first.'
        );
    }
}

function demo_seed_clear_prior_batch_metadata(string $batchId): void
{
    demo_seed_require_execute(
        'DELETE FROM ' . DEMO_SEED_ROW_TABLE . ' WHERE batch_id = ?',
        's',
        [$batchId],
        'clear_prior_registry'
    );
    demo_seed_require_execute(
        'DELETE FROM ' . DEMO_SEED_BATCH_TABLE . ' WHERE batch_id = ?',
        's',
        [$batchId],
        'clear_prior_batch'
    );
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function demo_seed_import_row_debug_context(array $row): array
{
    $resolution = is_array($row['resolution'] ?? null) ? $row['resolution'] : [];
    return [
        'seed_row_key' => (string)($row['seed_row_key'] ?? ''),
        'service_catalog_id' => (int)($row['service_catalog_id'] ?? 0),
        'service_catalog_name' => (string)($row['service_catalog_name'] ?? ''),
        'job_service_type_enum' => (string)($row['job_service_type_enum'] ?? ''),
        'staff_user_id' => (int)($row['staff_user_id'] ?? 0),
        'branch_id' => (int)($row['branch_id'] ?? 0),
        'order_datetime' => (string)($row['order_datetime'] ?? ''),
        'customer_email' => (string)($row['customer']['email'] ?? ''),
        'resolution' => $resolution,
    ];
}

function demo_seed_set_last_import_debug(?array $debug): void
{
    $GLOBALS['demo_seed_last_import_debug'] = $debug;
}

/** @return array<string,mixed>|null */
function demo_seed_get_last_import_debug(): ?array
{
    $debug = $GLOBALS['demo_seed_last_import_debug'] ?? null;
    return is_array($debug) ? $debug : null;
}

/**
 * @param list<string> $columns
 * @param list<mixed> $params
 * @param array<string,mixed> $context
 * @param array<string,mixed> $resolvedValues
 * @return array<string,mixed>
 */
function demo_seed_bind_param_debug(
    string $step,
    array $columns,
    string $types,
    array $params,
    array $resolvedValues = []
): array {
    $placeholderCount = count($columns);
    return [
        $step . '_columns' => $columns,
        $step . '_placeholder_count' => $placeholderCount,
        $step . '_bind_types' => $types,
        $step . '_bind_types_length' => strlen($types),
        $step . '_bind_param_count' => count($params),
        $step . '_values' => $resolvedValues,
    ];
}

/**
 * @param list<string> $columns
 * @param list<mixed> $params
 * @param array<string,mixed> $context
 */
function demo_seed_require_bind_match(
    string $step,
    array $columns,
    string $types,
    array $params,
    array $context = []
): void {
    $n = count($columns);
    $typeLen = strlen($types);
    $paramCount = count($params);
    if ($n === $typeLen && $n === $paramCount) {
        return;
    }
    $bindDebug = demo_seed_bind_param_debug($step, $columns, $types, $params);
    demo_seed_fail(
        $step,
        sprintf(
            'bind_param mismatch: placeholders=%d bind_types_length=%d bind_param_count=%d',
            $n,
            $typeLen,
            $paramCount
        ),
        array_merge($context, ['bind_debug' => $bindDebug])
    );
}

/**
 * @param array<string,mixed> $row
 */
function demo_seed_insert_job_order_for_row(
    array $row,
    int $orderId,
    int $orderItemId,
    int $customerId,
    int $branchId,
    int $staffId,
    float $amountPaid,
    string $orderAt,
    string $updatedAt,
    string $seedRowKey
): int {
    $customer = (array)($row['customer'] ?? []);
    $jobTitle = (string)$row['service_display_name'] . ' — ' . (string)$row['spec_summary'];
    if (strlen($jobTitle) > 150) {
        $jobTitle = substr($jobTitle, 0, 147) . '...';
    }
    $customerName = trim((string)$customer['first_name'] . ' ' . (string)$customer['last_name']);

    $spec = [
        ['order_id', 'i', $orderId],
        ['order_item_id', 'i', $orderItemId],
        ['customer_id', 'i', $customerId],
        ['branch_id', 'i', $branchId],
        ['job_title', 's', $jobTitle],
        ['customer_name', 's', $customerName],
        ['service_type', 's', (string)$row['job_service_type_enum']],
        ['quantity', 'i', 1],
        ['estimated_total', 'd', $amountPaid],
        ['amount_paid', 'd', $amountPaid],
        ['required_payment', 'd', $amountPaid],
        ['payment_status', 's', 'PAID'],
        ['payment_method', 's', 'Cash'],
        ['status', 's', (string)$row['job_status']],
        ['created_by', 'i', $staffId],
        ['created_at', 's', $orderAt],
        ['updated_at', 's', $updatedAt],
        ['due_date', 's', substr($orderAt, 0, 10)],
    ];

    $cols = [];
    $types = '';
    $params = [];
    foreach ($spec as [$col, $type, $value]) {
        if (!db_table_has_column('job_orders', $col)) {
            continue;
        }
        $cols[] = $col;
        $types .= $type;
        $params[] = $value;
    }
    if ($cols === []) {
        demo_seed_fail('job_order_insert', 'No writable job_orders columns found.', ['seed_row_key' => $seedRowKey]);
    }

    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    demo_seed_require_bind_match(
        'job_order_insert',
        $cols,
        $types,
        $params,
        ['seed_row_key' => $seedRowKey]
    );
    return demo_seed_require_insert_id(
        'INSERT INTO job_orders (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')',
        $types,
        $params,
        'job_order_insert',
        ['seed_row_key' => $seedRowKey]
    );
}

/**
 * @return array{seed_row_key:?string,step:?string,message:string,debug?:array<string,mixed>}
 */
function demo_seed_parse_import_failure_message(string $message): array
{
    $seedRowKey = null;
    $step = null;
    if (preg_match('/^\[([^\]]+)\]\s*([^:]+):\s*(.+)$/s', $message, $m)) {
        $seedRowKey = $m[1];
        $step = trim($m[2]);
        $message = trim($m[3]);
    }
    return [
        'seed_row_key' => $seedRowKey,
        'step' => $step,
        'message' => $message,
    ];
}

/**
 * @param list<array<string,mixed>> $rows
 */
function demo_seed_import_rows(array $rows, int $adminId, string $sourceFileName, string $batchId): array
{
    demo_seed_ensure_tables();
    if ($rows === []) {
        throw new RuntimeException('No validated rows to import.');
    }

    $placeholderProductId = demo_seed_pos_service_placeholder_product_id();
    if ($placeholderProductId <= 0) {
        throw new RuntimeException('POS service placeholder product is unavailable.');
    }

    demo_seed_import_preflight($batchId);
    demo_seed_clear_prior_batch_metadata($batchId);

    $completed = 0;
    $pending = 0;
    $totalSales = 0.0;
    $integrity = ['ok' => true, 'checks' => [], 'errors' => []];
    $lastDebug = null;

    demo_seed_begin_transaction();
    try {
        demo_seed_require_execute(
            'INSERT INTO ' . DEMO_SEED_BATCH_TABLE . ' (batch_id, label, source_file_name, imported_by, imported_at, total_orders, total_sales, completed_count, pending_count, status)
             VALUES (?, ?, ?, ?, NOW(), 0, 0, 0, 0, ?)',
            'sssis',
            [$batchId, 'Meeting demo POS services', $sourceFileName, $adminId, 'active'],
            'demo_seed_batches_insert'
        );

        foreach ($rows as $row) {
            $lastDebug = demo_seed_import_row_debug_context($row);
            demo_seed_set_last_import_debug($lastDebug);
            try {
                $rowResult = demo_seed_import_single_row($row, $batchId, $adminId, $placeholderProductId);
                $lastDebug = array_merge($lastDebug ?? [], [
                    'customer_insert' => (string)($rowResult['customer_insert'] ?? ''),
                    'customer_id' => (int)($rowResult['customer_id'] ?? 0),
                    'order_insert' => 'success',
                    'order_item_insert' => 'success',
                    'customization_insert' => 'success',
                    'job_order_insert' => 'success',
                    'demo_seed_rows_insert' => 'success',
                ]);
                demo_seed_set_last_import_debug($lastDebug);
            } catch (RuntimeException $e) {
                throw $e;
            }
            if (($row['order_status'] ?? '') === 'Completed') {
                $completed++;
            } else {
                $pending++;
            }
            $totalSales += (float)($row['amount_paid'] ?? 0);
        }

        demo_seed_require_execute(
            'UPDATE ' . DEMO_SEED_BATCH_TABLE . ' SET total_orders = ?, total_sales = ?, completed_count = ?, pending_count = ? WHERE batch_id = ?',
            'idiis',
            [count($rows), round($totalSales, 2), $completed, $pending, $batchId],
            'demo_seed_batches_update'
        );

        $integrity = demo_seed_verify_batch_integrity($batchId, count($rows));
        if (!$integrity['ok']) {
            throw new RuntimeException(
                'Demo import failed integrity checks: ' . implode(' ', $integrity['errors'])
            );
        }

        demo_seed_commit_transaction();
    } catch (Throwable $e) {
        demo_seed_rollback_transaction();
        if ($lastDebug !== null && !str_contains($e->getMessage(), 'seed_row_key')) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
        throw $e;
    }

    demo_seed_clear_preview();
    log_activity($adminId, 'Import Demo Seed Batch', 'Imported demo batch ' . $batchId . ' with ' . count($rows) . ' POS service orders.');

    return [
        'batch_id' => $batchId,
        'imported_orders' => count($rows),
        'total_sales' => round($totalSales, 2),
        'completed_count' => $completed,
        'pending_count' => $pending,
        'integrity' => $integrity,
    ];
}

/**
 * @param array<string,mixed> $row
 * @return array<string,int>
 */
function demo_seed_import_single_row(array $row, string $batchId, int $adminId, int $placeholderProductId): array
{
    $seedRowKey = (string)($row['seed_row_key'] ?? '');
    $ctx = ['seed_row_key' => $seedRowKey];

    $orderAt = (string)$row['order_datetime'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $orderAt)) {
        demo_seed_fail('order_datetime', 'Must be Y-m-d H:i:s.', $ctx);
    }
    $updatedAt = $orderAt;

    $customer = (array)($row['customer'] ?? []);
    $passwordHash = password_hash(bin2hex(random_bytes(12)), PASSWORD_BCRYPT);
    $customerPayload = [
        'first_name' => (string)$customer['first_name'],
        'last_name' => (string)$customer['last_name'],
        'email' => (string)$customer['email'],
        'phone' => (string)$customer['phone'],
        'password_hash' => $passwordHash,
    ];
    $customerResult = demo_seed_resolve_or_insert_customer($customerPayload, $customer, $orderAt, $ctx);
    $customerId = (int)$customerResult['customer_id'];
    $customerCreated = (int)$customerResult['customer_created'];

    $importDebug = demo_seed_get_last_import_debug() ?? [];
    $importDebug['customer_insert'] = (string)$customerResult['method'];
    $importDebug['customer_id'] = $customerId;
    demo_seed_set_last_import_debug($importDebug);

    $branchId = (int)$row['branch_id'];
    $amountPaid = (float)$row['amount_paid'];
    $catalogId = (int)$row['service_catalog_id'];
    $staffId = (int)$row['staff_user_id'];

    $orderCols = ['customer_id', 'branch_id', 'reference_id', 'total_amount', 'status', 'payment_status', 'payment_method', 'order_date', 'updated_at', 'order_type', 'order_source'];
    $orderTypes = 'iiidsssssss';
    $orderParams = [
        $customerId,
        $branchId,
        $catalogId,
        $amountPaid,
        (string)$row['order_status'],
        (string)($row['payment_status'] ?? 'Paid'),
        (string)($row['payment_method'] ?? 'Cash'),
        $orderAt,
        $updatedAt,
        'custom',
        'pos',
    ];

    if (db_table_has_column('orders', 'created_at')) {
        $orderCols[] = 'created_at';
        $orderTypes .= 's';
        $orderParams[] = $orderAt;
    }
    if (db_table_has_column('orders', 'amount_paid')) {
        $orderCols[] = 'amount_paid';
        $orderTypes .= 'd';
        $orderParams[] = $amountPaid;
    }
    if (db_table_has_column('orders', 'price_finalized_at')) {
        $orderCols[] = 'price_finalized_at';
        $orderTypes .= 's';
        $orderParams[] = $orderAt;
    }
    if (db_table_has_column('orders', 'price_finalized_by')) {
        $orderCols[] = 'price_finalized_by';
        $orderTypes .= 'i';
        $orderParams[] = $staffId;
    }
    if (db_table_has_column('orders', 'payment_type')) {
        $orderCols[] = 'payment_type';
        $orderTypes .= 's';
        $orderParams[] = 'full_payment';
    }

    $placeholders = implode(', ', array_fill(0, count($orderCols), '?'));
    $orderResolved = [
        'customer_id' => $customerId,
        'branch_id' => $branchId,
        'reference_id' => $catalogId,
        'total_amount' => $amountPaid,
        'status' => (string)$row['order_status'],
        'payment_status' => (string)($row['payment_status'] ?? 'Paid'),
        'payment_method' => (string)($row['payment_method'] ?? 'Cash'),
        'order_date' => $orderAt,
        'updated_at' => $updatedAt,
        'order_type' => 'custom',
        'order_source' => 'pos',
    ];
    if (in_array('created_at', $orderCols, true)) {
        $orderResolved['created_at'] = $orderAt;
    }
    if (in_array('amount_paid', $orderCols, true)) {
        $orderResolved['amount_paid'] = $amountPaid;
    }
    if (in_array('price_finalized_at', $orderCols, true)) {
        $orderResolved['price_finalized_at'] = $orderAt;
    }
    if (in_array('price_finalized_by', $orderCols, true)) {
        $orderResolved['price_finalized_by'] = $staffId;
    }
    if (in_array('payment_type', $orderCols, true)) {
        $orderResolved['payment_type'] = 'full_payment';
    }
    $importDebug = array_merge(
        $importDebug,
        demo_seed_bind_param_debug('order_insert', $orderCols, $orderTypes, $orderParams, $orderResolved)
    );
    demo_seed_set_last_import_debug($importDebug);
    demo_seed_require_bind_match('order_insert', $orderCols, $orderTypes, $orderParams, $ctx);

    $orderId = demo_seed_require_insert_id(
        'INSERT INTO orders (' . implode(', ', $orderCols) . ') VALUES (' . $placeholders . ')',
        $orderTypes,
        $orderParams,
        'order_insert',
        $ctx
    );

    $customizationData = demo_seed_build_pos_customization_payload($row, $batchId, $catalogId, $customer, $orderAt);
    $customizationJson = json_encode($customizationData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $oiCols = ['order_id', 'product_id', 'quantity', 'unit_price', 'customization_data'];
    $oiTypes = 'iiids';
    $oiParams = [$orderId, $placeholderProductId, 1, (float)$row['unit_price'], $customizationJson];
    if (db_table_has_column('order_items', 'item_type')) {
        $oiCols[] = 'item_type';
        $oiTypes .= 's';
        $oiParams[] = 'service';
    }
    if (db_table_has_column('order_items', 'specifications')) {
        $oiCols[] = 'specifications';
        $oiTypes .= 's';
        $oiParams[] = $customizationJson;
    }
    if (db_table_has_column('order_items', 'created_at')) {
        $oiCols[] = 'created_at';
        $oiTypes .= 's';
        $oiParams[] = $orderAt;
    }
    if (db_table_has_column('order_items', 'updated_at')) {
        $oiCols[] = 'updated_at';
        $oiTypes .= 's';
        $oiParams[] = $updatedAt;
    }
    $oiPlaceholders = implode(', ', array_fill(0, count($oiCols), '?'));
    $orderItemId = demo_seed_require_insert_id(
        'INSERT INTO order_items (' . implode(', ', $oiCols) . ') VALUES (' . $oiPlaceholders . ')',
        $oiTypes,
        $oiParams,
        'order_item_insert',
        $ctx
    );

    $details = $customizationData;
    $details['notes'] = (string)$row['spec_summary'];
    $customCols = ['order_id', 'order_item_id', 'customer_id', 'service_type', 'customization_details', 'status', 'created_at', 'updated_at'];
    $customTypes = 'iiisssss';
    $customParams = [
        $orderId,
        $orderItemId,
        $customerId,
        (string)$row['service_display_name'],
        json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        (string)$row['customization_status'],
        $orderAt,
        $updatedAt,
    ];
    if (db_table_has_column('customizations', 'needed_date')) {
        $customCols[] = 'needed_date';
        $customTypes .= 's';
        $customParams[] = substr($orderAt, 0, 10);
    }
    $customPlaceholders = implode(', ', array_fill(0, count($customCols), '?'));
    $customizationId = demo_seed_require_insert_id(
        'INSERT INTO customizations (' . implode(', ', $customCols) . ') VALUES (' . $customPlaceholders . ')',
        $customTypes,
        $customParams,
        'customization_insert',
        $ctx
    );

    $jobOrderId = demo_seed_insert_job_order_for_row(
        $row,
        $orderId,
        $orderItemId,
        $customerId,
        $branchId,
        $staffId,
        $amountPaid,
        $orderAt,
        $updatedAt,
        $seedRowKey
    );

    demo_seed_optional_history($orderId, (string)$row['order_status'], $orderAt);

    demo_seed_require_execute(
        'INSERT INTO ' . DEMO_SEED_ROW_TABLE . ' (batch_id, seed_row_key, order_id, order_item_id, customization_id, job_order_id, customer_id, customer_created)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        'ssiiiiii',
        [$batchId, $seedRowKey, $orderId, $orderItemId, $customizationId, $jobOrderId, $customerId, $customerCreated],
        'demo_seed_rows_insert',
        $ctx
    );

    return [
        'order_id' => $orderId,
        'order_item_id' => $orderItemId,
        'customization_id' => $customizationId,
        'job_order_id' => $jobOrderId,
        'customer_id' => $customerId,
        'customer_insert' => (string)$customerResult['method'],
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function demo_seed_customers_show_columns(): array
{
    static $cache = null;
    if ($cache === null) {
        if (!demo_seed_table_exists('customers')) {
            $cache = [];
        } else {
            $cache = db_query('SHOW COLUMNS FROM customers') ?: [];
        }
    }
    return $cache;
}

function demo_seed_customers_has_column(string $name): bool
{
    foreach (demo_seed_customers_show_columns() as $col) {
        if (strcasecmp((string)($col['Field'] ?? ''), $name) === 0) {
            return true;
        }
    }
    return false;
}

function demo_seed_customer_status_value(): string
{
    foreach (demo_seed_customers_show_columns() as $col) {
        if (strcasecmp((string)($col['Field'] ?? ''), 'status') !== 0) {
            continue;
        }
        $type = strtolower((string)($col['Type'] ?? ''));
        if (str_contains($type, "'active'") && !str_contains($type, 'activated')) {
            return 'Active';
        }
        break;
    }
    return 'Activated';
}

function demo_seed_update_customer_from_csv(int $customerId, array $customerPayload, array $address): void
{
    $map = [
        'first_name' => (string)($customerPayload['first_name'] ?? ''),
        'last_name' => (string)($customerPayload['last_name'] ?? ''),
        'contact_number' => (string)($customerPayload['phone'] ?? ''),
        'street_address' => (string)($address['street'] ?? ''),
        'barangay' => (string)($address['barangay'] ?? ''),
        'city' => (string)($address['city'] ?? ''),
        'province' => (string)($address['province'] ?? ''),
        'postal_code' => (string)($address['postal'] ?? ''),
    ];
    $sets = [];
    $types = '';
    $params = [];
    foreach ($map as $col => $val) {
        if (!demo_seed_customers_has_column($col)) {
            continue;
        }
        $sets[] = $col . ' = ?';
        $types .= 's';
        $params[] = $val;
    }
    if ($sets === []) {
        return;
    }
    $types .= 'i';
    $params[] = $customerId;
    db_execute('UPDATE customers SET ' . implode(', ', $sets) . ' WHERE customer_id = ?', $types, $params);
}

/**
 * @return array{customer_id:int,method:string,customer_created:int}
 */
function demo_seed_resolve_or_insert_customer(array $customerPayload, array $address, string $createdAt, array $context = []): array
{
    $email = strtolower(trim((string)($customerPayload['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        demo_seed_fail('customer_insert', 'Invalid customer email.', $context);
    }
    if ($email === 'walkin@pos.local') {
        demo_seed_fail('customer_insert', 'Cannot attach demo import to walk-in guest account.', $context);
    }

    $existing = db_query(
        'SELECT customer_id FROM customers WHERE LOWER(TRIM(email)) = ? LIMIT 1',
        's',
        [$email]
    ) ?: [];
    if ($existing !== []) {
        $customerId = (int)$existing[0]['customer_id'];
        demo_seed_update_customer_from_csv($customerId, $customerPayload, $address);
        return [
            'customer_id' => $customerId,
            'method' => 'reused_existing',
            'customer_created' => 0,
        ];
    }

    return [
        'customer_id' => demo_seed_insert_new_customer_row($customerPayload, $address, $createdAt, $context),
        'method' => 'inserted',
        'customer_created' => 1,
    ];
}

function demo_seed_insert_new_customer_row(array $customerPayload, array $address, string $createdAt, array $context = []): int
{
    if (function_exists('printflow_ensure_customers_auth_provider_column')) {
        printflow_ensure_customers_auth_provider_column();
    }

    $email = strtolower(trim((string)($customerPayload['email'] ?? '')));
    $passwordHash = (string)($customerPayload['password_hash'] ?? '');
    if ($passwordHash === '') {
        $passwordHash = password_hash(bin2hex(random_bytes(12)), PASSWORD_BCRYPT);
    }

    $addressLine = trim(implode(', ', array_filter([
        (string)($address['street'] ?? ''),
        (string)($address['barangay'] ?? ''),
        (string)($address['city'] ?? ''),
    ])));

    $candidates = [
        'first_name' => [(string)($customerPayload['first_name'] ?? ''), 's'],
        'middle_name' => ['', 's'],
        'last_name' => [(string)($customerPayload['last_name'] ?? ''), 's'],
        'email' => [$email, 's'],
        'contact_number' => [(string)($customerPayload['phone'] ?? ''), 's'],
        'password_hash' => [$passwordHash, 's'],
        'status' => [demo_seed_customer_status_value(), 's'],
        'street_address' => [(string)($address['street'] ?? ''), 's'],
        'barangay' => [(string)($address['barangay'] ?? ''), 's'],
        'city' => [(string)($address['city'] ?? ''), 's'],
        'province' => [(string)($address['province'] ?? ''), 's'],
        'postal_code' => [(string)($address['postal'] ?? ''), 's'],
        'address' => [$addressLine, 's'],
        'auth_provider' => ['local', 's'],
        'created_by_system' => [1, 'i'],
        'email_verified' => [1, 'i'],
        'is_profile_complete' => [1, 'i'],
        'id_status' => ['None', 's'],
        'created_at' => [$createdAt, 's'],
        'updated_at' => [$createdAt, 's'],
        'terms_accepted_at' => [$createdAt, 's'],
        'terms_version' => ['demo_seed', 's'],
    ];

    $cols = [];
    $types = '';
    $params = [];
    foreach ($candidates as $col => [$val, $type]) {
        if (!demo_seed_customers_has_column($col)) {
            continue;
        }
        $cols[] = $col;
        $types .= $type;
        $params[] = $val;
    }

    if (!in_array('first_name', $cols, true) || !in_array('last_name', $cols, true) || !in_array('email', $cols, true)) {
        demo_seed_fail('customer_insert', 'customers table is missing first_name, last_name, or email.', $context);
    }
    if (!in_array('password_hash', $cols, true)) {
        demo_seed_fail('customer_insert', 'customers.password_hash is required for new demo customers.', $context);
    }

    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $sql = 'INSERT INTO customers (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')';

    if (!function_exists('printflow_run_guarded_account_insert')) {
        return demo_seed_require_insert_id($sql, $types, $params, 'customer_insert', $context);
    }

    $insertId = printflow_run_guarded_account_insert(static function () use ($sql, $types, $params, $context): int {
        global $conn;
        $result = db_execute($sql, $types, $params);
        if ($result === false) {
            demo_seed_fail('customer_insert', 'Insert failed.', $context);
        }
        if (is_int($result) && $result > 0) {
            return $result;
        }
        $id = (int)($conn->insert_id ?? 0);
        if ($id > 0) {
            return $id;
        }
        if ($result === true) {
            demo_seed_fail(
                'customer_insert',
                'Insert reported success but no insert_id was returned (possible duplicate key or schema trigger).',
                $context
            );
        }
        demo_seed_fail('customer_insert', 'Insert did not return a new row id.', $context);
    });

    return (int)$insertId;
}

/**
 * @param array<string,string> $customerPayload
 * @param array<string,string> $address
 */
function demo_seed_insert_customer_return_id(array $customerPayload, array $address, string $createdAt, array $context = []): int
{
    $result = demo_seed_resolve_or_insert_customer($customerPayload, $address, $createdAt, $context);
    return (int)$result['customer_id'];
}

/** @deprecated Use demo_seed_insert_customer_return_id() */
function demo_seed_insert_customer(array $customerPayload, array $address): void
{
    demo_seed_insert_customer_return_id($customerPayload, $address, date('Y-m-d H:i:s'));
}

function demo_seed_optional_history(int $orderId, string $orderStatus, string $orderAt): void
{
    if (!demo_seed_table_exists('order_status_history') || !db_table_has_column('order_status_history', 'order_id')) {
        return;
    }
    $payload = ['order_id' => $orderId];
    if (db_table_has_column('order_status_history', 'old_status')
        && db_table_has_column('order_status_history', 'new_status')
        && db_table_has_column('order_status_history', 'changed_by')) {
        $payload['old_status'] = 'Pending';
        $payload['new_status'] = $orderStatus;
        $payload['changed_by'] = 'Admin';
    } elseif (db_table_has_column('order_status_history', 'status')) {
        $payload['status'] = $orderStatus;
    } else {
        return;
    }
    if (db_table_has_column('order_status_history', 'changed_at')) {
        $payload['changed_at'] = $orderAt;
    } elseif (db_table_has_column('order_status_history', 'created_at')) {
        $payload['created_at'] = $orderAt;
    }

    $cols = array_keys($payload);
    $types = '';
    $bindValues = [];
    foreach ($payload as $column => $value) {
        $types .= $column === 'order_id' ? 'i' : 's';
        $bindValues[] = $value;
    }
    db_execute(
        'INSERT INTO order_status_history (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
        $types,
        $bindValues
    );
}

function demo_seed_delete_preview(?string $batchId = null): array
{
    demo_seed_ensure_tables();
    $batch = $batchId
        ? (db_query('SELECT * FROM ' . DEMO_SEED_BATCH_TABLE . ' WHERE batch_id = ? AND rolled_back_at IS NULL LIMIT 1', 's', [$batchId]) ?: [])[0] ?? null
        : demo_seed_active_batch();
    if (!$batch) {
        return ['batch_id' => null, 'counts' => [], 'integrity' => null, 'registry_gaps' => []];
    }
    $batchId = (string)$batch['batch_id'];
    $snap = demo_seed_registry_snapshot($batchId);
    $expected = (int)($batch['total_orders'] ?? count($snap['rows']));
    $gaps = demo_seed_registry_gap_report($snap);
    $integrity = demo_seed_verify_batch_integrity($batchId, $expected > 0 ? $expected : count($snap['rows']));

    $registeredCustomers = count(array_filter(
        $snap['customer_ids'],
        static fn(int $id): bool => $id > 0
    ));

    $counts = [
        'registry_rows' => count($snap['rows']),
        'orders' => count($snap['order_ids']),
        'order_items' => count($snap['order_item_ids']),
        'customizations' => count($snap['customization_ids']),
        'job_orders' => count($snap['job_order_ids']),
        'customers' => demo_seed_count_deletable_customers($batchId, $snap['customer_ids']),
        'customers_registered' => $registeredCustomers,
        'job_order_materials' => demo_seed_count_rows_by_pk('job_order_materials', 'job_order_id', $snap['job_order_ids']),
        'job_order_ink_usage' => demo_seed_count_rows_by_pk('job_order_ink_usage', 'job_order_id', $snap['job_order_ids']),
        'job_order_files' => demo_seed_count_rows_by_pk('job_order_files', 'job_order_id', $snap['job_order_ids']),
        'order_status_history' => demo_seed_count_in('order_status_history', 'order_id', $snap['order_ids']),
        'order_messages' => demo_seed_count_in('order_messages', 'order_id', $snap['order_ids']),
        'order_notes' => demo_seed_count_in('order_notes', 'order_id', $snap['order_ids']),
        'order_designs' => demo_seed_count_in('order_designs', 'order_id', $snap['order_ids']),
        'payment_submissions' => demo_seed_count_in('payment_submissions', 'order_id', $snap['order_ids']),
        'provider_payments' => demo_seed_count_in('provider_payments', 'order_id', $snap['order_ids']),
        'inventory_transactions' => 0,
    ];

    return [
        'batch_id' => $batchId,
        'batch' => $batch,
        'counts' => $counts,
        'integrity' => $integrity,
        'registry_gaps' => $gaps,
        'recovery_mode' => !($integrity['ok'] ?? false),
        'delete_scope' => [
            'batch_id' => $batchId,
            'order_ids' => $snap['order_ids'],
            'uses_registry_ids' => true,
            'order_scoped_cleanup' => !($integrity['ok'] ?? false),
            'inventory_transactions' => 'never (demo import does not register inventory)',
        ],
    ];
}

/** @param list<int> $ids */
function demo_seed_count_in(string $table, string $column, array $ids): int
{
    if ($ids === [] || !demo_seed_table_exists($table) || !db_table_has_column($table, $column)) {
        return 0;
    }
    $csv = implode(',', array_map('intval', $ids));
    return (int)(db_query("SELECT COUNT(*) AS c FROM {$table} WHERE {$column} IN ({$csv})")[0]['c'] ?? 0);
}

/** @param list<int> $orderIds */
function demo_seed_count_job_children(string $table, array $orderIds): int
{
    if ($orderIds === [] || !demo_seed_table_exists($table) || !demo_seed_table_exists('job_orders')) {
        return 0;
    }
    $csv = implode(',', array_map('intval', $orderIds));
    return (int)(db_query(
        "SELECT COUNT(*) AS c FROM {$table} t INNER JOIN job_orders jo ON jo.id = t.job_order_id WHERE jo.order_id IN ({$csv})"
    )[0]['c'] ?? 0);
}

/** @deprecated Demo import never registers inventory; preview always reports 0. */
function demo_seed_count_inventory_for_orders(array $orderIds): int
{
    return 0;
}

/** @param list<int> $customerIds */
function demo_seed_count_deletable_customers(string $batchId, array $customerIds): int
{
    if ($customerIds === []) {
        return 0;
    }
    $count = 0;
    foreach ($customerIds as $customerId) {
        if ($customerId <= 0) {
            continue;
        }
        $inBatch = db_query(
            'SELECT 1 FROM ' . DEMO_SEED_ROW_TABLE . ' WHERE batch_id = ? AND customer_id = ? LIMIT 1',
            'si',
            [$batchId, $customerId]
        ) ?: [];
        if ($inBatch === []) {
            continue;
        }
        $otherOrders = db_query(
            'SELECT 1 FROM orders o WHERE o.customer_id = ?
             AND NOT EXISTS (SELECT 1 FROM ' . DEMO_SEED_ROW_TABLE . ' r WHERE r.order_id = o.order_id AND r.batch_id = ?)
             LIMIT 1',
            'is',
            [$customerId, $batchId]
        ) ?: [];
        if ($otherOrders === []) {
            $count++;
        }
    }
    return $count;
}

function demo_seed_delete_batch(string $batchId, int $adminId): array
{
    demo_seed_ensure_tables();
    $preview = demo_seed_delete_preview($batchId);
    if (empty($preview['batch_id'])) {
        throw new RuntimeException('No active demo batch was found to delete.');
    }

    $batchId = (string)$preview['batch_id'];
    $snap = demo_seed_registry_snapshot($batchId);

    demo_seed_begin_transaction();
    try {
        $purged = demo_seed_purge_batch_data($batchId, $snap);

        if ($snap['order_ids'] === [] && count($snap['rows']) > 0) {
            demo_seed_require_execute('DELETE FROM ' . DEMO_SEED_ROW_TABLE . ' WHERE batch_id = ?', 's', [$batchId]);
            demo_seed_require_execute(
                'UPDATE ' . DEMO_SEED_BATCH_TABLE . ' SET status = ?, rolled_back_at = NOW(), rolled_back_by = ? WHERE batch_id = ?',
                'sis',
                ['rolled_back', $adminId, $batchId]
            );
            demo_seed_commit_transaction();
            log_activity($adminId, 'Delete Demo Seed Batch', 'Removed orphan demo registry for batch ' . $batchId . ' (no order ids).');
            return [
                'batch_id' => $batchId,
                'deleted_orders' => 0,
                'deleted_customers' => 0,
                'counts' => $preview['counts'],
                'registry_gaps' => $preview['registry_gaps'] ?? [],
                'recovery_mode' => true,
            ];
        }

        if ($snap['order_ids'] === []) {
            throw new RuntimeException('Batch registry has no order ids to delete.');
        }

        demo_seed_require_execute('DELETE FROM ' . DEMO_SEED_ROW_TABLE . ' WHERE batch_id = ?', 's', [$batchId]);
        demo_seed_require_execute(
            'UPDATE ' . DEMO_SEED_BATCH_TABLE . ' SET status = ?, rolled_back_at = NOW(), rolled_back_by = ? WHERE batch_id = ?',
            'sis',
            ['rolled_back', $adminId, $batchId]
        );

        demo_seed_commit_transaction();
    } catch (Throwable $e) {
        demo_seed_rollback_transaction();
        throw new RuntimeException('Demo batch delete failed: ' . $e->getMessage(), 0, $e);
    }

    $message = 'Deleted demo batch ' . $batchId . ' (' . count($snap['order_ids']) . ' orders, ' . ($purged['customers'] ?? 0) . ' customers).';
    log_activity($adminId, 'Delete Demo Seed Batch', $message);

    return [
        'batch_id' => $batchId,
        'deleted_orders' => count($snap['order_ids']),
        'deleted_customers' => (int)($purged['customers'] ?? 0),
        'counts' => $preview['counts'],
        'integrity' => $preview['integrity'] ?? null,
        'registry_gaps' => $preview['registry_gaps'] ?? [],
        'recovery_mode' => (bool)($preview['recovery_mode'] ?? false),
    ];
}

/** @param list<int> $orderIds */
function demo_seed_collect_job_ids(array $orderIds): array
{
    if ($orderIds === [] || !demo_seed_table_exists('job_orders')) {
        return [];
    }
    $csv = implode(',', array_map('intval', $orderIds));
    $rows = db_query("SELECT id FROM job_orders WHERE order_id IN ({$csv})") ?: [];
    return array_values(array_filter(array_map(static fn($r) => (int)($r['id'] ?? 0), $rows)));
}

function demo_seed_customer_safe_to_delete(string $batchId, int $customerId): bool
{
    if ($customerId <= 0) {
        return false;
    }
    $inBatch = db_query(
        'SELECT 1 FROM ' . DEMO_SEED_ROW_TABLE . ' WHERE batch_id = ? AND customer_id = ? LIMIT 1',
        'si',
        [$batchId, $customerId]
    ) ?: [];
    if ($inBatch === []) {
        return false;
    }
    $other = db_query(
        'SELECT 1 FROM orders o WHERE o.customer_id = ?
         AND NOT EXISTS (SELECT 1 FROM ' . DEMO_SEED_ROW_TABLE . ' r WHERE r.order_id = o.order_id AND r.batch_id = ?)
         LIMIT 1',
        'is',
        [$customerId, $batchId]
    ) ?: [];
    return $other === [];
}

function demo_seed_validate_uploaded_file(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'CSV upload failed.';
    }
    if ((int)($file['size'] ?? 0) > DEMO_SEED_MAX_UPLOAD_BYTES) {
        return 'CSV file exceeds the 2 MB upload limit.';
    }
    $name = (string)($file['name'] ?? '');
    if (!preg_match('/\.csv$/i', $name)) {
        return 'Only .csv files are allowed.';
    }
    $mime = (string)($file['type'] ?? '');
    if ($mime !== '' && !in_array($mime, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'], true)) {
        return 'Invalid CSV MIME type.';
    }
    return null;
}
