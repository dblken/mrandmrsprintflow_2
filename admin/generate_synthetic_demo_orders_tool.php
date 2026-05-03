<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

require_role('Admin');

const PF_SDO_TOOL_VERSION = '2026-05-03 v2';
const PF_SDO_BATCH_TABLE = 'maintenance_synthetic_demo_order_batches';
const PF_SDO_ROW_TABLE = 'maintenance_synthetic_demo_order_rows';
const PF_SDO_DEFAULT_ORDER_COUNT = 120;
const PF_SDO_MAX_ORDER_COUNT = 1000;
const PF_SDO_MARKER = 'synthetic_demo_order';

function pf_sdo_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pf_sdo_table_exists(string $table): bool
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
        "SELECT 1
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         LIMIT 1",
        's',
        [$safe]
    ) ?: [];

    $cache[$table] = !empty($rows);
    return $cache[$table];
}

function pf_sdo_table_columns(string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!pf_sdo_table_exists($table)) {
        return [];
    }

    $rows = db_query("SHOW COLUMNS FROM `" . preg_replace('/[^a-zA-Z0-9_]/', '', $table) . "`") ?: [];
    $columns = [];
    foreach ($rows as $row) {
        $field = (string)($row['Field'] ?? '');
        if ($field !== '') {
            $columns[] = $field;
        }
    }

    $cache[$table] = $columns;
    return $columns;
}

function pf_sdo_table_has_column(string $table, string $column): bool
{
    return in_array($column, pf_sdo_table_columns($table), true);
}

function pf_sdo_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function pf_sdo_quote_sql_value($value): string
{
    global $conn;

    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    return "'" . $conn->real_escape_string((string)$value) . "'";
}

function pf_sdo_insert_row(string $table, array $data): int
{
    global $conn;

    $columns = [];
    $values = [];
    foreach ($data as $column => $value) {
        $columns[] = pf_sdo_quote_identifier($column);
        $values[] = pf_sdo_quote_sql_value($value);
    }

    $sql = "INSERT INTO " . pf_sdo_quote_identifier($table)
        . " (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")";

    if (!$conn->query($sql)) {
        throw new RuntimeException("Insert failed for {$table}: " . $conn->error);
    }

    return (int)$conn->insert_id;
}

function pf_sdo_exec_raw(string $sql): void
{
    global $conn;

    if (!$conn->query($sql)) {
        throw new RuntimeException('SQL failed: ' . $conn->error);
    }
}

function pf_sdo_exec_raw_affected(string $sql): int
{
    global $conn;

    if (!$conn->query($sql)) {
        throw new RuntimeException('SQL failed: ' . $conn->error);
    }

    return (int)$conn->affected_rows;
}

function pf_sdo_begin_transaction(): void
{
    global $conn;
    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Could not start transaction: ' . $conn->error);
    }
}

function pf_sdo_commit_transaction(): void
{
    global $conn;
    if (!$conn->commit()) {
        throw new RuntimeException('Could not commit transaction: ' . $conn->error);
    }
}

function pf_sdo_rollback_transaction(): void
{
    global $conn;
    if (!$conn->rollback()) {
        error_log('generate_synthetic_demo_orders_tool rollback warning: ' . $conn->error);
    }
}

function pf_sdo_ensure_tables(): void
{
    $sql1 = "CREATE TABLE IF NOT EXISTS " . PF_SDO_BATCH_TABLE . " (
        id BIGINT NOT NULL AUTO_INCREMENT,
        batch_id VARCHAR(80) NOT NULL,
        campaign_key VARCHAR(160) NOT NULL,
        requested_count INT NOT NULL,
        inserted_orders INT NOT NULL DEFAULT 0,
        inserted_items INT NOT NULL DEFAULT 0,
        date_from DATETIME NOT NULL,
        date_to DATETIME NOT NULL,
        note VARCHAR(255) NOT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        rolled_back_by INT NULL,
        rolled_back_at DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_batch_id (batch_id),
        KEY idx_campaign_key (campaign_key),
        KEY idx_rollback_state (rolled_back_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $sql2 = "CREATE TABLE IF NOT EXISTS " . PF_SDO_ROW_TABLE . " (
        id BIGINT NOT NULL AUTO_INCREMENT,
        batch_id VARCHAR(80) NOT NULL,
        order_id INT NOT NULL,
        order_item_id INT NULL,
        customer_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(12,2) NOT NULL,
        order_total DECIMAL(12,2) NOT NULL,
        order_date DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_batch_id (batch_id),
        KEY idx_order_id (order_id),
        KEY idx_customer_id (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!db_execute($sql1) || !db_execute($sql2)) {
        throw new RuntimeException('Could not create or verify synthetic demo order maintenance tables.');
    }
}

function pf_sdo_parse_positive_int($value, int $default, int $min, int $max): int
{
    $parsed = (int)$value;
    if ($parsed < $min) {
        return $default;
    }
    return min($max, $parsed);
}

function pf_sdo_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now');
}

function pf_sdo_date_window(): array
{
    $to = pf_sdo_now();
    $from = $to->sub(new DateInterval('P3M'));
    return [$from, $to];
}

function pf_sdo_scalar(string $sql, string $types = '', array $params = [])
{
    $rows = db_query($sql, $types, $params) ?: [];
    if (empty($rows)) {
        return null;
    }

    $row = $rows[0];
    if (!is_array($row) || $row === []) {
        return null;
    }

    $firstKey = array_key_first($row);
    return $firstKey !== null ? ($row[$firstKey] ?? null) : null;
}

function pf_sdo_campaign_key(int $orderCount, DateTimeImmutable $from, DateTimeImmutable $to): string
{
    return implode('|', [
        PF_SDO_MARKER,
        'v2',
        'count:' . $orderCount,
        'from:' . $from->format('Y-m-d'),
        'to:' . $to->format('Y-m-d'),
    ]);
}

function pf_sdo_staff_overlap_emails(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    if (!pf_sdo_table_exists('users')) {
        $cache = [];
        return $cache;
    }

    $rows = db_query(
        "SELECT LOWER(TRIM(email)) AS email
         FROM users
         WHERE role IN ('Admin', 'Manager', 'Staff')"
    ) ?: [];

    $emails = [];
    foreach ($rows as $row) {
        $email = trim((string)($row['email'] ?? ''));
        if ($email !== '') {
            $emails[$email] = true;
        }
    }

    $cache = array_keys($emails);
    return $cache;
}

function pf_sdo_is_active_customer_status(string $status): bool
{
    $normalized = strtolower(trim($status));
    return $normalized === '' || in_array($normalized, ['activated', 'active'], true);
}

function pf_sdo_customer_pool_details(): array
{
    $sql = "SELECT customer_id, first_name, last_name, email";
    if (pf_sdo_table_has_column('customers', 'status')) {
        $sql .= ", status";
    }
    $sql .= "
            FROM customers
            WHERE customer_id > 0";

    $rows = db_query($sql) ?: [];
    $baseEligible = [];
    $overlapEligible = [];
    $staffEmails = array_fill_keys(pf_sdo_staff_overlap_emails(), true);
    $excludedWalkIn = 0;
    $excludedStatus = 0;
    $excludedOverlap = 0;

    foreach ($rows as $row) {
        $email = strtolower(trim((string)($row['email'] ?? '')));
        $name = strtolower(trim((string)(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))));
        $status = (string)($row['status'] ?? '');
        if (!pf_sdo_is_active_customer_status($status)) {
            $excludedStatus++;
            continue;
        }
        if ($email === 'walkin@pos.local' || str_ends_with($email, '@phone.local')) {
            $excludedWalkIn++;
            continue;
        }
        if (str_contains($name, 'walk in') || str_contains($name, 'walk-in')) {
            $excludedWalkIn++;
            continue;
        }

        $baseEligible[] = $row;

        if ($email !== '' && isset($staffEmails[$email])) {
            $excludedOverlap++;
            continue;
        }

        $overlapEligible[] = $row;
    }

    $usingFallback = ($overlapEligible === [] && $baseEligible !== []);

    return [
        'customers' => $usingFallback ? $baseEligible : $overlapEligible,
        'base_eligible_count' => count($baseEligible),
        'eligible_count' => $usingFallback ? count($baseEligible) : count($overlapEligible),
        'excluded_walk_in' => $excludedWalkIn,
        'excluded_status' => $excludedStatus,
        'excluded_overlap' => $usingFallback ? 0 : $excludedOverlap,
        'overlap_filter_fallback' => $usingFallback,
    ];
}

function pf_sdo_random_existing_customers(): array
{
    $pool = pf_sdo_customer_pool_details();
    return $pool['customers'] ?? [];
}

function pf_sdo_branch_ids(): array
{
    if (!pf_sdo_table_exists('branches')) {
        return [1];
    }

    $rows = db_query("SELECT id FROM branches ORDER BY id ASC") ?: [];
    $ids = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $rows), static fn(int $id): bool => $id > 0));
    return $ids === [] ? [1] : $ids;
}

function pf_sdo_demo_products(): array
{
    $sql = "SELECT product_id, name, price";
    if (pf_sdo_table_has_column('products', 'sku')) {
        $sql .= ", sku";
    }
    $sql .= "
            FROM products
            WHERE COALESCE(price, 0) > 0";
    if (pf_sdo_table_has_column('products', 'status')) {
        $sql .= " AND status = 'Activated'";
    }
    $sql .= " ORDER BY product_id ASC";

    $rows = db_query($sql) ?: [];
    $products = [];
    foreach ($rows as $row) {
        $price = (float)($row['price'] ?? 0);
        if ($price <= 0) {
            continue;
        }
        $products[] = [
            'product_id' => (int)($row['product_id'] ?? 0),
            'name' => (string)($row['name'] ?? 'Product'),
            'price' => round($price, 2),
            'sku' => trim((string)($row['sku'] ?? '')),
        ];
    }

    return $products;
}

function pf_sdo_product_combos(array $products): array
{
    $common = [];
    $premium = [];

    foreach ($products as $product) {
        $price = (float)$product['price'];
        for ($qty = 1; $qty <= 5; $qty++) {
            $total = round($price * $qty, 2);
            if ($total < 200 || $total > 1000) {
                continue;
            }

            $combo = [
                'product_id' => (int)$product['product_id'],
                'product_name' => (string)$product['name'],
                'unit_price' => $price,
                'quantity' => $qty,
                'total' => $total,
                'sku' => trim((string)($product['sku'] ?? '')),
            ];

            if ($total <= 900) {
                $common[] = $combo;
            } else {
                $premium[] = $combo;
            }
        }
    }

    return ['common' => $common, 'premium' => $premium];
}

function pf_sdo_random_array_item(array $items): array
{
    if ($items === []) {
        throw new RuntimeException('No random item candidates are available.');
    }
    return $items[array_rand($items)];
}

function pf_sdo_random_order_status(): array
{
    $roll = random_int(1, 100);
    if ($roll <= 48) {
        return ['status' => 'Completed', 'payment_status' => 'Paid', 'design_status' => 'Approved'];
    }
    if ($roll <= 68) {
        return ['status' => 'Ready for Pickup', 'payment_status' => 'Paid', 'design_status' => 'Approved'];
    }
    if ($roll <= 82) {
        return ['status' => 'Processing', 'payment_status' => 'Paid', 'design_status' => 'Approved'];
    }
    if ($roll <= 92) {
        return ['status' => 'Pending', 'payment_status' => 'Unpaid', 'design_status' => 'Pending'];
    }
    return ['status' => 'To Pay', 'payment_status' => 'Unpaid', 'design_status' => 'Pending'];
}

function pf_sdo_random_order_datetime(DateTimeImmutable $from, DateTimeImmutable $to): DateTimeImmutable
{
    $startTs = $from->getTimestamp();
    $endTs = $to->getTimestamp();
    $randomTs = random_int($startTs, $endTs);
    $date = (new DateTimeImmutable('@' . $randomTs))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $hour = random_int(8, 19);
    $minute = random_int(0, 59);
    $second = random_int(0, 59);
    return $date->setTime($hour, $minute, $second);
}

function pf_sdo_payment_method_map(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    if (!pf_sdo_table_exists('payment_methods')
        || !pf_sdo_table_has_column('payment_methods', 'payment_method_id')
        || !pf_sdo_table_has_column('payment_methods', 'name')) {
        $cache = [];
        return $cache;
    }

    $sql = "SELECT payment_method_id, name
            FROM payment_methods";
    if (pf_sdo_table_has_column('payment_methods', 'status')) {
        $sql .= " WHERE status = 'Activated'";
    }

    $rows = db_query($sql) ?: [];
    $map = [];
    foreach ($rows as $row) {
        $name = strtolower(trim((string)($row['name'] ?? '')));
        $id = (int)($row['payment_method_id'] ?? 0);
        if ($name !== '' && $id > 0) {
            $map[$name] = $id;
        }
    }

    $cache = $map;
    return $cache;
}

function pf_sdo_optional_history_payload(
    int $orderId,
    array $statusInfo,
    string $batchNote,
    DateTimeImmutable $orderDate
): ?array {
    if (!pf_sdo_table_exists('order_status_history')
        || !pf_sdo_table_has_column('order_status_history', 'order_id')) {
        return null;
    }

    $payload = ['order_id' => $orderId];
    $hasLegacyShape = pf_sdo_table_has_column('order_status_history', 'old_status')
        && pf_sdo_table_has_column('order_status_history', 'new_status')
        && pf_sdo_table_has_column('order_status_history', 'changed_by');

    if ($hasLegacyShape) {
        $payload['old_status'] = 'Pending';
        $payload['new_status'] = $statusInfo['status'];
        $payload['changed_by'] = 'Admin';
    } elseif (pf_sdo_table_has_column('order_status_history', 'status')) {
        $payload['status'] = $statusInfo['status'];
    } else {
        return null;
    }

    if (pf_sdo_table_has_column('order_status_history', 'notes')) {
        $payload['notes'] = $batchNote;
    }
    if (pf_sdo_table_has_column('order_status_history', 'created_at')) {
        $payload['created_at'] = $orderDate->format('Y-m-d H:i:s');
    } elseif (pf_sdo_table_has_column('order_status_history', 'changed_at')) {
        $payload['changed_at'] = $orderDate->format('Y-m-d H:i:s');
    }

    return $payload;
}

function pf_sdo_existing_active_batch(string $campaignKey): ?array
{
    pf_sdo_ensure_tables();

    $rows = db_query(
        "SELECT batch_id, inserted_orders, inserted_items, created_at
         FROM " . PF_SDO_BATCH_TABLE . "
         WHERE campaign_key = ?
           AND rolled_back_at IS NULL
         ORDER BY created_at DESC
         LIMIT 1",
        's',
        [$campaignKey]
    ) ?: [];

    return $rows[0] ?? null;
}

function pf_sdo_latest_active_batch(): ?array
{
    pf_sdo_ensure_tables();

    $rows = db_query(
        "SELECT batch_id, campaign_key, inserted_orders, inserted_items, created_at
         FROM " . PF_SDO_BATCH_TABLE . "
         WHERE rolled_back_at IS NULL
         ORDER BY created_at DESC
         LIMIT 1"
    ) ?: [];

    return $rows[0] ?? null;
}

function pf_sdo_recent_batches(): array
{
    pf_sdo_ensure_tables();

    return db_query(
        "SELECT batch_id, campaign_key, requested_count, inserted_orders, inserted_items, created_at, rolled_back_at
         FROM " . PF_SDO_BATCH_TABLE . "
         ORDER BY created_at DESC
         LIMIT 8"
    ) ?: [];
}

function pf_sdo_order_insert_payload(
    array $customer,
    array $combo,
    array $statusInfo,
    int $branchId,
    DateTimeImmutable $orderDate,
    string $batchId,
    string $campaignKey,
    int $index
): array {
    $payload = [
        'customer_id' => (int)$customer['customer_id'],
        'total_amount' => (float)$combo['total'],
    ];

    $note = PF_SDO_MARKER
        . ' batch=' . $batchId
        . ' campaign=' . $campaignKey
        . ' product=' . (int)$combo['product_id']
        . ' qty=' . (int)$combo['quantity'];

    if (pf_sdo_table_has_column('orders', 'order_date')) {
        $payload['order_date'] = $orderDate->format('Y-m-d H:i:s');
    }
    if (pf_sdo_table_has_column('orders', 'updated_at')) {
        $payload['updated_at'] = $orderDate->format('Y-m-d H:i:s');
    }
    if (pf_sdo_table_has_column('orders', 'downpayment_amount')) {
        $payload['downpayment_amount'] = 0.00;
    }
    if (pf_sdo_table_has_column('orders', 'status')) {
        $payload['status'] = $statusInfo['status'];
    }
    if (pf_sdo_table_has_column('orders', 'payment_status')) {
        $payload['payment_status'] = $statusInfo['payment_status'];
    }
    if (pf_sdo_table_has_column('orders', 'notes')) {
        $payload['notes'] = $note;
    }
    if (pf_sdo_table_has_column('orders', 'branch_id')) {
        $payload['branch_id'] = $branchId;
    }
    if (pf_sdo_table_has_column('orders', 'design_status')) {
        $payload['design_status'] = $statusInfo['design_status'];
    }
    if (pf_sdo_table_has_column('orders', 'estimated_completion')) {
        $payload['estimated_completion'] = $orderDate->modify('+' . random_int(2, 10) . ' days')->format('Y-m-d');
    }
    if (pf_sdo_table_has_column('orders', 'payment_type')) {
        $payload['payment_type'] = 'full_payment';
    }
    if (pf_sdo_table_has_column('orders', 'reference_id')) {
        $payload['reference_id'] = (int)$combo['product_id'];
    }
    if (pf_sdo_table_has_column('orders', 'order_type')) {
        $payload['order_type'] = 'product';
    }
    if (pf_sdo_table_has_column('orders', 'order_source')) {
        $payload['order_source'] = 'customer';
    }
    if (pf_sdo_table_has_column('orders', 'payment_method')) {
        $methods = ['Cash', 'GCash', 'Maya'];
        $payload['payment_method'] = $statusInfo['payment_status'] === 'Paid'
            ? $methods[array_rand($methods)]
            : null;
    }
    if (pf_sdo_table_has_column('orders', 'payment_method_id')) {
        $methodMap = pf_sdo_payment_method_map();
        $methodKey = strtolower(trim((string)($payload['payment_method'] ?? '')));
        $payload['payment_method_id'] = $statusInfo['payment_status'] === 'Paid' && isset($methodMap[$methodKey])
            ? (int)$methodMap[$methodKey]
            : null;
    }
    if (pf_sdo_table_has_column('orders', 'payment_reference')) {
        $payload['payment_reference'] = ($payload['payment_method'] ?? null) && ($payload['payment_method'] ?? '') !== 'Cash'
            ? 'DEMO-' . strtoupper(substr($batchId, -6)) . '-' . str_pad((string)$index, 4, '0', STR_PAD_LEFT)
            : null;
    }

    return $payload;
}

function pf_sdo_order_item_insert_payload(
    int $orderId,
    array $combo,
    string $batchId,
    string $campaignKey,
    DateTimeImmutable $orderDate
): array {
    $payload = [
        'order_id' => $orderId,
        'product_id' => (int)$combo['product_id'],
        'quantity' => (int)$combo['quantity'],
        'unit_price' => (float)$combo['unit_price'],
    ];

    if (pf_sdo_table_has_column('order_items', 'customization_data')) {
        $payload['customization_data'] = json_encode([
            'marker' => PF_SDO_MARKER,
            'batch_id' => $batchId,
            'campaign_key' => $campaignKey,
            'generated_at' => $orderDate->format('c'),
            'demo' => true,
        ], JSON_UNESCAPED_SLASHES);
    }
    if (pf_sdo_table_has_column('order_items', 'sku') && trim((string)($combo['sku'] ?? '')) !== '') {
        $payload['sku'] = trim((string)$combo['sku']);
    }

    return $payload;
}

function pf_sdo_generate_orders(int $orderCount): array
{
    pf_sdo_ensure_tables();

    [$from, $to] = pf_sdo_date_window();
    $campaignKey = pf_sdo_campaign_key($orderCount, $from, $to);
    $existingBatch = pf_sdo_existing_active_batch($campaignKey);
    if ($existingBatch) {
        return [
            'success' => true,
            'message' => 'A matching synthetic demo batch already exists and was left unchanged.',
            'inserted_orders' => (int)($existingBatch['inserted_orders'] ?? 0),
            'inserted_items' => (int)($existingBatch['inserted_items'] ?? 0),
            'batch_id' => (string)($existingBatch['batch_id'] ?? ''),
            'verification' => [],
        ];
    }

    $customers = pf_sdo_random_existing_customers();
    if ($customers === []) {
        throw new RuntimeException('No eligible customer accounts were found.');
    }

    $branches = pf_sdo_branch_ids();
    $products = pf_sdo_demo_products();
    if ($products === []) {
        throw new RuntimeException('No activated products with prices were found.');
    }

    $combos = pf_sdo_product_combos($products);
    if ($combos['common'] === []) {
        throw new RuntimeException('No valid product/quantity combinations were found between PHP 200 and PHP 900.');
    }

    $adminId = (int)get_user_id();
    $batchId = 'sdo_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $batchNote = PF_SDO_MARKER . ' synthetic demo product orders';
    $ordersBefore = (int)(pf_sdo_scalar("SELECT COUNT(*) AS c FROM orders") ?? 0);
    $customersBefore = (int)(pf_sdo_scalar("SELECT COUNT(*) AS c FROM customers") ?? 0);

    pf_sdo_begin_transaction();

    try {
        pf_sdo_insert_row(PF_SDO_BATCH_TABLE, [
            'batch_id' => $batchId,
            'campaign_key' => $campaignKey,
            'requested_count' => $orderCount,
            'inserted_orders' => 0,
            'inserted_items' => 0,
            'date_from' => $from->format('Y-m-d H:i:s'),
            'date_to' => $to->format('Y-m-d H:i:s'),
            'note' => $batchNote,
            'created_by' => $adminId,
        ]);

        $insertedOrderIds = [];
        $insertedOrders = 0;
        $insertedItems = 0;

        for ($i = 1; $i <= $orderCount; $i++) {
            $usePremium = $combos['premium'] !== [] && random_int(1, 100) <= 15;
            $combo = $usePremium ? pf_sdo_random_array_item($combos['premium']) : pf_sdo_random_array_item($combos['common']);
            $customer = pf_sdo_random_array_item($customers);
            $branchId = $branches[array_rand($branches)];
            $statusInfo = pf_sdo_random_order_status();
            $orderDate = pf_sdo_random_order_datetime($from, $to);

            $orderPayload = pf_sdo_order_insert_payload($customer, $combo, $statusInfo, $branchId, $orderDate, $batchId, $campaignKey, $i);
            $orderId = pf_sdo_insert_row('orders', $orderPayload);

            $itemPayload = pf_sdo_order_item_insert_payload($orderId, $combo, $batchId, $campaignKey, $orderDate);
            $orderItemId = pf_sdo_insert_row('order_items', $itemPayload);

            $historyPayload = pf_sdo_optional_history_payload($orderId, $statusInfo, $batchNote, $orderDate);
            if ($historyPayload !== null) {
                try {
                    pf_sdo_insert_row('order_status_history', $historyPayload);
                } catch (Throwable $historyError) {
                    error_log('generate_synthetic_demo_orders_tool history insert skipped: ' . $historyError->getMessage());
                }
            }

            pf_sdo_insert_row(PF_SDO_ROW_TABLE, [
                'batch_id' => $batchId,
                'order_id' => $orderId,
                'order_item_id' => $orderItemId,
                'customer_id' => (int)$customer['customer_id'],
                'product_id' => (int)$combo['product_id'],
                'quantity' => (int)$combo['quantity'],
                'unit_price' => (float)$combo['unit_price'],
                'order_total' => (float)$combo['total'],
                'order_date' => $orderDate->format('Y-m-d H:i:s'),
            ]);

            $insertedOrderIds[] = $orderId;
            $insertedOrders++;
            $insertedItems++;
        }

        pf_sdo_exec_raw(
            "UPDATE " . PF_SDO_BATCH_TABLE . "
             SET inserted_orders = " . (int)$insertedOrders . ",
                 inserted_items = " . (int)$insertedItems . "
             WHERE batch_id = " . pf_sdo_quote_sql_value($batchId)
        );

        $idCsv = implode(', ', array_map('intval', $insertedOrderIds));
        $verification = [];

        $verification['orders_in_window'] = (int)((db_query(
            "SELECT COUNT(*) AS c
             FROM orders
             WHERE order_id IN ({$idCsv})
               AND order_date >= " . pf_sdo_quote_sql_value($from->format('Y-m-d H:i:s')) . "
               AND order_date <= " . pf_sdo_quote_sql_value($to->format('Y-m-d H:i:s'))
        )[0]['c'] ?? 0));

        $verification['orders_in_total_range'] = (int)((db_query(
            "SELECT COUNT(*) AS c
             FROM orders
             WHERE order_id IN ({$idCsv})
               AND total_amount >= 200
               AND total_amount <= 1000"
        )[0]['c'] ?? 0));

        $verification['valid_customers'] = (int)((db_query(
            "SELECT COUNT(*) AS c
             FROM orders o
             INNER JOIN customers c ON c.customer_id = o.customer_id
             WHERE o.order_id IN ({$idCsv})"
        )[0]['c'] ?? 0));

        $verification['order_items_present'] = (int)((db_query(
            "SELECT COUNT(DISTINCT order_id) AS c
             FROM order_items
             WHERE order_id IN ({$idCsv})"
        )[0]['c'] ?? 0));

        $rangeRow = (db_query(
            "SELECT MIN(order_date) AS min_order_date,
                    MAX(order_date) AS max_order_date,
                    MIN(total_amount) AS min_total_amount,
                    MAX(total_amount) AS max_total_amount,
                    COUNT(DISTINCT customer_id) AS distinct_customers,
                    SUM(CASE WHEN payment_status = 'Paid' THEN 1 ELSE 0 END) AS paid_orders
             FROM orders
             WHERE order_id IN ({$idCsv})"
        )[0] ?? []);

        $verification['date_min'] = (string)($rangeRow['min_order_date'] ?? '');
        $verification['date_max'] = (string)($rangeRow['max_order_date'] ?? '');
        $verification['total_min'] = number_format((float)($rangeRow['min_total_amount'] ?? 0), 2);
        $verification['total_max'] = number_format((float)($rangeRow['max_total_amount'] ?? 0), 2);
        $verification['distinct_customers'] = (int)($rangeRow['distinct_customers'] ?? 0);
        $verification['paid_orders'] = (int)($rangeRow['paid_orders'] ?? 0);

        $ordersAfter = (int)(pf_sdo_scalar("SELECT COUNT(*) AS c FROM orders") ?? 0);
        $customersAfter = (int)(pf_sdo_scalar("SELECT COUNT(*) AS c FROM customers") ?? 0);
        $verification['orders_before'] = $ordersBefore;
        $verification['orders_after'] = $ordersAfter;
        $verification['customers_before'] = $customersBefore;
        $verification['customers_after'] = $customersAfter;
        $verification['rollback_plan'] = 'Use Rollback Latest Synthetic Batch for ' . $batchId . '.';

        if ($verification['orders_in_window'] !== $insertedOrders) {
            throw new RuntimeException('Verification failed: some synthetic orders are outside the 3-month window.');
        }
        if ($verification['orders_in_total_range'] !== $insertedOrders) {
            throw new RuntimeException('Verification failed: some synthetic orders are outside the PHP 200 to PHP 1,000 total range.');
        }
        if ($verification['valid_customers'] !== $insertedOrders) {
            throw new RuntimeException('Verification failed: some synthetic orders were not linked to valid customers.');
        }
        if ($verification['order_items_present'] !== $insertedOrders) {
            throw new RuntimeException('Verification failed: some synthetic orders are missing order_items.');
        }
        if ($ordersAfter !== ($ordersBefore + $insertedOrders)) {
            throw new RuntimeException('Verification failed: total orders count did not increase by the inserted batch size.');
        }
        if ($customersAfter !== $customersBefore) {
            throw new RuntimeException('Verification failed: customer row count changed unexpectedly.');
        }

        pf_sdo_commit_transaction();

        log_activity(
            $adminId,
            'Generate Synthetic Demo Orders',
            'Inserted synthetic demo order batch ' . $batchId . ' with ' . $insertedOrders . ' orders for reporting tests.'
        );

        return [
            'success' => true,
            'message' => 'Inserted synthetic/demo product orders successfully.',
            'inserted_orders' => $insertedOrders,
            'inserted_items' => $insertedItems,
            'batch_id' => $batchId,
            'verification' => $verification,
        ];
    } catch (Throwable $e) {
        pf_sdo_rollback_transaction();
        throw $e;
    }
}

function pf_sdo_rollback_latest_batch(): array
{
    $batch = pf_sdo_latest_active_batch();
    if (!$batch) {
        return [
            'success' => false,
            'message' => 'No active synthetic demo batch is available for rollback.',
            'batch_id' => null,
            'deleted_orders' => 0,
        ];
    }

    $batchId = (string)$batch['batch_id'];
    $adminId = (int)get_user_id();

    $rows = db_query(
        "SELECT order_id
         FROM " . PF_SDO_ROW_TABLE . "
         WHERE batch_id = ?
         ORDER BY order_id DESC",
        's',
        [$batchId]
    ) ?: [];

    $orderIds = array_values(array_unique(array_filter(array_map(static fn(array $row): int => (int)($row['order_id'] ?? 0), $rows), static fn(int $id): bool => $id > 0)));
    if ($orderIds === []) {
        return [
            'success' => false,
            'message' => 'The latest synthetic batch has no logged orders to roll back.',
            'batch_id' => $batchId,
            'deleted_orders' => 0,
        ];
    }

    $orderIdCsv = implode(', ', $orderIds);

    pf_sdo_begin_transaction();

    try {
        if (pf_sdo_table_exists('order_status_history') && pf_sdo_table_has_column('order_status_history', 'order_id')) {
            pf_sdo_exec_raw_affected("DELETE FROM order_status_history WHERE order_id IN ({$orderIdCsv})");
        }

        if (pf_sdo_table_exists('order_items')) {
            pf_sdo_exec_raw_affected("DELETE FROM order_items WHERE order_id IN ({$orderIdCsv})");
        }

        $deletedOrders = pf_sdo_exec_raw_affected("DELETE FROM orders WHERE order_id IN ({$orderIdCsv})");

        pf_sdo_exec_raw(
            "UPDATE " . PF_SDO_BATCH_TABLE . "
             SET rolled_back_at = NOW(),
                 rolled_back_by = " . (int)$adminId . "
             WHERE batch_id = " . pf_sdo_quote_sql_value($batchId)
        );

        pf_sdo_commit_transaction();

        log_activity(
            $adminId,
            'Rollback Synthetic Demo Orders',
            'Rolled back synthetic demo order batch ' . $batchId . '.'
        );

        return [
            'success' => true,
            'message' => 'Rolled back the latest synthetic demo batch successfully.',
            'batch_id' => $batchId,
            'deleted_orders' => $deletedOrders,
        ];
    } catch (Throwable $e) {
        pf_sdo_rollback_transaction();
        throw $e;
    }
}

function pf_sdo_preview_summary(): array
{
    $customerPool = pf_sdo_customer_pool_details();
    $customers = $customerPool['customers'] ?? [];
    $products = pf_sdo_demo_products();
    [$from, $to] = pf_sdo_date_window();
    $totalCustomers = (int)(pf_sdo_scalar("SELECT COUNT(*) AS c FROM customers") ?? 0);

    return [
        'total_customers' => $totalCustomers,
        'eligible_customers' => count($customers),
        'excluded_customers' => max(0, $totalCustomers - count($customers)),
        'base_eligible_customers' => (int)($customerPool['base_eligible_count'] ?? count($customers)),
        'excluded_walk_in' => (int)($customerPool['excluded_walk_in'] ?? 0),
        'excluded_status' => (int)($customerPool['excluded_status'] ?? 0),
        'excluded_overlap' => (int)($customerPool['excluded_overlap'] ?? 0),
        'overlap_filter_fallback' => !empty($customerPool['overlap_filter_fallback']),
        'eligible_products' => count($products),
        'date_from' => $from->format('Y-m-d'),
        'date_to' => $to->format('Y-m-d'),
    ];
}

$requestedOrderCount = pf_sdo_parse_positive_int($_POST['order_count'] ?? PF_SDO_DEFAULT_ORDER_COUNT, PF_SDO_DEFAULT_ORDER_COUNT, 1, PF_SDO_MAX_ORDER_COUNT);
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $result = [
            'success' => false,
            'message' => 'Invalid CSRF token.',
            'mode' => 'error',
        ];
    } elseif (isset($_POST['generate_synthetic_demo_orders'])) {
        try {
            $result = pf_sdo_generate_orders($requestedOrderCount);
            $result['mode'] = 'generate';
        } catch (Throwable $e) {
            $result = [
                'success' => false,
                'message' => 'Generation failed: ' . $e->getMessage(),
                'mode' => 'generate',
            ];
        }
    } elseif (isset($_POST['rollback_synthetic_demo_orders'])) {
        try {
            $result = pf_sdo_rollback_latest_batch();
            $result['mode'] = 'rollback';
        } catch (Throwable $e) {
            $result = [
                'success' => false,
                'message' => 'Rollback failed: ' . $e->getMessage(),
                'mode' => 'rollback',
            ];
        }
    }
}

$preview = pf_sdo_preview_summary();
$activeBatch = pf_sdo_latest_active_batch();
$recentBatches = pf_sdo_recent_batches();

$page_title = 'Generate Synthetic Demo Orders Tool';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-5xl mx-auto">
        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-200">
                <h1 class="text-2xl font-bold text-gray-900">Generate Synthetic Demo Orders Tool</h1>
                <p class="text-sm text-gray-600 mt-2">
                    Temporary admin maintenance page for inserting synthetic/demo product orders for recent reporting, sales trend, and dashboard testing.
                </p>
                <p class="text-xs text-gray-500 mt-2">
                    Tool version: <span class="font-mono"><?php echo pf_sdo_h(PF_SDO_TOOL_VERSION); ?></span>
                </p>
            </div>

            <div class="p-6 space-y-6">
                <?php if ($result !== null): ?>
                    <div class="<?php echo !empty($result['success']) ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800'; ?> border rounded-xl px-4 py-3">
                        <div class="font-semibold"><?php echo pf_sdo_h($result['message'] ?? 'Action completed.'); ?></div>
                        <?php if (!empty($result['batch_id'])): ?>
                            <div class="text-sm mt-2">Batch: <span class="font-mono"><?php echo pf_sdo_h($result['batch_id']); ?></span></div>
                        <?php endif; ?>
                        <?php if (isset($result['inserted_orders'])): ?>
                            <div class="text-sm mt-2">Inserted orders: <?php echo (int)$result['inserted_orders']; ?></div>
                        <?php endif; ?>
                        <?php if (isset($result['inserted_items'])): ?>
                            <div class="text-sm">Inserted order items: <?php echo (int)$result['inserted_items']; ?></div>
                        <?php endif; ?>
                        <?php if (isset($result['deleted_orders'])): ?>
                            <div class="text-sm mt-2">Deleted orders: <?php echo (int)$result['deleted_orders']; ?></div>
                        <?php endif; ?>
                        <?php if (!empty($result['verification']) && is_array($result['verification'])): ?>
                            <div class="text-sm mt-2">
                                <?php foreach ($result['verification'] as $label => $count): ?>
                                    <div><?php echo pf_sdo_h($label); ?>: <?php echo pf_sdo_h(is_scalar($count) ? (string)$count : json_encode($count)); ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-4">
                    <div class="font-semibold text-amber-900">Safety Notes</div>
                    <p class="text-sm text-amber-800 mt-1">
                        This tool inserts synthetic/demo product orders only. It does not modify existing orders, customers, products, payments, or inventory. Every generated row is marked with <code><?php echo pf_sdo_h(PF_SDO_MARKER); ?></code>, logged in a batch table, and inserted inside a transaction.
                    </p>
                    <?php if (!empty($preview['overlap_filter_fallback'])): ?>
                        <p class="text-sm text-amber-800 mt-2">
                            Customer email overlap with shop-user accounts was detected across the whole pool, so the tool automatically fell back to normal activated customer rows instead of excluding everyone.
                        </p>
                    <?php endif; ?>
                </div>

                <div class="grid md:grid-cols-3 gap-6">
                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                        <h2 class="text-lg font-semibold text-gray-900 mb-3">Preview</h2>
                        <div class="space-y-1 text-sm text-gray-700">
                            <div><strong>Total customers:</strong> <?php echo (int)($preview['total_customers'] ?? 0); ?></div>
                            <div><strong>Eligible customers:</strong> <?php echo (int)$preview['eligible_customers']; ?></div>
                            <div><strong>Base eligible before overlap filter:</strong> <?php echo (int)($preview['base_eligible_customers'] ?? 0); ?></div>
                            <div><strong>Excluded non-customer/staff-overlap rows:</strong> <?php echo (int)($preview['excluded_customers'] ?? 0); ?></div>
                            <div><strong>Excluded walk-in placeholders:</strong> <?php echo (int)($preview['excluded_walk_in'] ?? 0); ?></div>
                            <div><strong>Excluded inactive customers:</strong> <?php echo (int)($preview['excluded_status'] ?? 0); ?></div>
                            <div><strong>Excluded email-overlap rows:</strong> <?php echo (int)($preview['excluded_overlap'] ?? 0); ?></div>
                            <div><strong>Eligible products:</strong> <?php echo (int)$preview['eligible_products']; ?></div>
                            <div><strong>Date window:</strong> <?php echo pf_sdo_h($preview['date_from']); ?> to <?php echo pf_sdo_h($preview['date_to']); ?></div>
                            <div><strong>Total range:</strong> PHP 200 to PHP 1,000</div>
                        </div>
                    </div>

                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                        <h2 class="text-lg font-semibold text-gray-900 mb-3">Latest Active Batch</h2>
                        <?php if ($activeBatch): ?>
                            <div class="space-y-1 text-sm text-gray-700">
                                <div><strong>Batch:</strong> <span class="font-mono"><?php echo pf_sdo_h($activeBatch['batch_id'] ?? ''); ?></span></div>
                                <div><strong>Orders:</strong> <?php echo (int)($activeBatch['inserted_orders'] ?? 0); ?></div>
                                <div><strong>Items:</strong> <?php echo (int)($activeBatch['inserted_items'] ?? 0); ?></div>
                                <div><strong>Created:</strong> <?php echo pf_sdo_h($activeBatch['created_at'] ?? ''); ?></div>
                            </div>
                        <?php else: ?>
                            <div class="text-sm text-gray-600">No active synthetic demo batch is pending rollback.</div>
                        <?php endif; ?>
                    </div>

                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                        <h2 class="text-lg font-semibold text-gray-900 mb-3">Default Plan</h2>
                        <div class="space-y-1 text-sm text-gray-700">
                            <div><strong>Order type:</strong> Product orders only</div>
                            <div><strong>Related rows:</strong> `order_items` and status history when available</div>
                            <div><strong>Marker:</strong> <code><?php echo pf_sdo_h(PF_SDO_MARKER); ?></code></div>
                            <div><strong>Rollback:</strong> Latest synthetic batch only</div>
                        </div>
                    </div>
                </div>

                <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                    <h2 class="text-lg font-semibold text-gray-900 mb-3">Generate Demo Orders</h2>
                    <form method="post" class="flex flex-wrap gap-3 items-end" onsubmit="return confirm('Generate synthetic/demo product orders for the last 3 months? This will insert new testing data only.');">
                        <?php echo csrf_field(); ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Order Count</label>
                            <input
                                type="number"
                                name="order_count"
                                min="1"
                                max="<?php echo (int)PF_SDO_MAX_ORDER_COUNT; ?>"
                                value="<?php echo (int)$requestedOrderCount; ?>"
                                class="w-40 rounded-lg border border-gray-300 px-3 py-2 text-sm"
                            >
                        </div>
                        <input type="hidden" name="generate_synthetic_demo_orders" value="1">
                        <button
                            type="submit"
                            class="inline-flex items-center px-5 py-3 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-semibold transition"
                            <?php echo (($preview['eligible_customers'] ?? 0) <= 0 || ($preview['eligible_products'] ?? 0) <= 0) ? 'disabled style="opacity:.6;cursor:not-allowed;"' : ''; ?>
                        >
                            Generate Synthetic Demo Orders
                        </button>
                    </form>
                    <p class="text-xs text-gray-500 mt-3">
                        Idempotency note: the tool skips insertion if the same window and order count already produced an unrolled synthetic batch.
                    </p>
                </div>

                <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                    <h2 class="text-lg font-semibold text-gray-900 mb-3">Rollback</h2>
                    <form method="post" onsubmit="return confirm('Rollback the latest active synthetic demo order batch?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="rollback_synthetic_demo_orders" value="1">
                        <button
                            type="submit"
                            class="inline-flex items-center px-5 py-3 rounded-xl bg-slate-700 hover:bg-slate-800 text-white font-semibold transition"
                            <?php echo !$activeBatch ? 'disabled style="opacity:.6;cursor:not-allowed;"' : ''; ?>
                        >
                            Rollback Latest Synthetic Batch
                        </button>
                    </form>
                    <p class="text-xs text-gray-500 mt-3">
                        Rollback deletes the `orders`, `order_items`, and synthetic `order_status_history` rows created by the latest active synthetic batch and marks that batch as rolled back.
                    </p>
                </div>

                <div class="bg-gray-50 border border-gray-200 rounded-xl p-4">
                    <h2 class="text-lg font-semibold text-gray-900 mb-3">Recent Batches</h2>
                    <?php if ($recentBatches === []): ?>
                        <div class="text-sm text-gray-600">No synthetic demo batches have been recorded yet.</div>
                    <?php else: ?>
                        <div class="space-y-2 text-sm text-gray-700">
                            <?php foreach ($recentBatches as $batch): ?>
                                <div class="border border-gray-200 rounded-lg px-3 py-2 bg-white">
                                    <div class="font-mono text-xs text-gray-900"><?php echo pf_sdo_h($batch['batch_id'] ?? ''); ?></div>
                                    <div>Campaign: <?php echo pf_sdo_h($batch['campaign_key'] ?? ''); ?></div>
                                    <div>Requested: <?php echo (int)($batch['requested_count'] ?? 0); ?></div>
                                    <div>Inserted orders: <?php echo (int)($batch['inserted_orders'] ?? 0); ?></div>
                                    <div>Inserted items: <?php echo (int)($batch['inserted_items'] ?? 0); ?></div>
                                    <div>Created: <?php echo pf_sdo_h($batch['created_at'] ?? ''); ?></div>
                                    <div>Rolled back: <?php echo pf_sdo_h($batch['rolled_back_at'] ?? 'Not yet'); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
