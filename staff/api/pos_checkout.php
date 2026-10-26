<?php
/**
 * API: POS Checkout Process
 * Path: staff/api/pos_checkout.php
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/product_branch_stock.php';
require_once __DIR__ . '/../../includes/product_option_stock.php';
require_once __DIR__ . '/../../includes/order_items_persistence.php';
require_once __DIR__ . '/../../includes/JobOrderService.php';
require_once __DIR__ . '/../../includes/runtime_config.php';
require_once __DIR__ . '/../../includes/provider_payments.php';
require_once __DIR__ . '/../../includes/pos_receipt.php';
require_once __DIR__ . '/../../includes/pos_receipt_printer.php';
require_once __DIR__ . '/../../includes/pos_draft_lifecycle.php';
require_once __DIR__ . '/../../includes/service_order_helper.php';
require_once __DIR__ . '/../../includes/pos_customer_helpers.php';
require_once __DIR__ . '/../../includes/pos_set_price_helpers.php';
require_once __DIR__ . '/../../includes/order_item_design_files.php';
require_once __DIR__ . '/../../includes/pos_custom_transaction_datetime.php';
require_once __DIR__ . '/../../includes/pos_quantity_validation.php';
require_once __DIR__ . '/../../includes/pos_service_stock_validation.php';
require_once __DIR__ . '/../../includes/pos_branch_scope.php';

function pos_checkout_parse_positive_quantity($value): int {
    try {
        return printflow_pos_parse_cart_quantity($value);
    } catch (InvalidArgumentException $error) {
        throw new RuntimeException($error->getMessage());
    }
}

function pos_checkout_validate_assigned_service_materials(array $items, array $pendingOrders): ?string {
    foreach ($items as $item) {
        if (!is_array($item) || !pos_payload_item_is_service($item)) {
            continue;
        }
        $pendingOrderId = (int)($item['pending_order_id'] ?? 0);
        $productId = (int)($item['id'] ?? 0);
        $sessionPendingOrderId = (int)($pendingOrders[$productId] ?? 0);
        if ($pendingOrderId <= 0) {
            $pendingOrderId = $sessionPendingOrderId;
        }
        if ($pendingOrderId <= 0) {
            continue;
        }
        if ($productId <= 0 || $sessionPendingOrderId !== $pendingOrderId) {
            return 'The assigned service materials could not be verified for this POS item. Open Set Price again and retry.';
        }
        $pendingItemId = (int)($item['pending_order_item_id'] ?? 0);
        $jobSql = 'SELECT id FROM job_orders WHERE order_id = ?';
        $jobTypes = 'i';
        $jobParams = [$pendingOrderId];
        if ($pendingItemId > 0 && db_table_has_column('job_orders', 'order_item_id')) {
            $jobSql .= ' AND order_item_id = ?';
            $jobTypes .= 'i';
            $jobParams[] = $pendingItemId;
        }
        $jobs = db_query($jobSql . ' ORDER BY id ASC LIMIT 1', $jobTypes, $jobParams) ?: [];
        $jobId = (int)($jobs[0]['id'] ?? 0);
        if ($jobId <= 0) {
            continue;
        }
        $assignments = JobOrderService::getScopedMaterialsPublic($jobId, false, false);
        if ($assignments === []) {
            continue;
        }
        $qty = pos_checkout_parse_positive_quantity($item['qty'] ?? null);
        try {
            $issues = JobOrderService::validatePendingMaterialStockPublic($jobId, $qty);
        } catch (RuntimeException $stockError) {
            return $stockError->getMessage();
        }
        if ($issues !== []) {
            $issue = $issues[0];
            $uom = trim((string)($issue['uom'] ?? ''));
            $required = rtrim(rtrim(number_format((float)$issue['required'], 3, '.', ''), '0'), '.');
            $available = rtrim(rtrim(number_format((float)$issue['available'], 3, '.', ''), '0'), '.');
            return (string)$issue['name'] . ' has only ' . $available . ($uom !== '' ? ' ' . $uom : '') . ' available; ' . $required . ($uom !== '' ? ' ' . $uom : '') . ' required.';
        }
    }
    return null;
}

function pos_payload_item_is_service(array $item): bool {
    if (!empty($item['is_service'])) {
        return true;
    }

    $customization = $item['customization'] ?? null;
    if (is_array($customization)) {
        if (!empty($customization['service_id']) || !empty($customization['service_type'])) {
            return true;
        }
    }

    return false;
}

function pos_table_has_column(string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $rows = db_query(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1",
        'ss',
        [$table, $column]
    ) ?: [];

    return $cache[$key] = !empty($rows);
}

function pos_is_shared_walkin_placeholder_email(string $email): bool {
    return strtolower(trim($email)) === 'walkin@pos.local';
}

function pos_resolve_checkout_customer_id(array $data): int {
    return printflow_pos_resolve_checkout_customer_context($data)['customer_id'];
}

function pos_checkout_guest_contact_insert_sql(?string $guestDisplayName, ?string $guestEmail): array {
    printflow_pos_ensure_orders_guest_display_name_column();
    $guestDisplayName = printflow_pos_sanitize_guest_display_name($guestDisplayName ?? '');
    $guestEmail = printflow_pos_normalize_customer_email($guestEmail ?? '');
    $columns = [];
    $values = [];
    $params = [];
    $types = '';
    if ($guestDisplayName !== '' && pos_table_has_column('orders', 'pos_guest_display_name')) {
        $columns[] = 'pos_guest_display_name';
        $values[] = '?';
        $params[] = $guestDisplayName;
        $types .= 's';
    }
    if ($guestEmail !== '') {
        printflow_pos_ensure_orders_guest_email_column();
        if (!pos_table_has_column('orders', 'pos_guest_email')) {
            throw new RuntimeException('Walk-in email storage is not ready. Apply the POS guest email migration and retry.', 500);
        }
        $columns[] = 'pos_guest_email';
        $values[] = '?';
        $params[] = $guestEmail;
        $types .= 's';
    }
    if ($columns === []) return ['', '', [], ''];
    return [', ' . implode(', ', $columns), ', ' . implode(', ', $values), $params, $types];
}

function pos_checkout_order_insert_types(string $guestTypes, string $amountPaidTypes, string $priceFinalTypes): string {
    $core = ($guestTypes !== '' ? 'i' . $guestTypes : 'i') . 'iidssssss';

    return $core . $amountPaidTypes . $priceFinalTypes;
}

function pos_checkout_draft_order_insert_types(string $guestTypes): string {
    return ($guestTypes !== '' ? 'i' . $guestTypes : 'i') . 'ii';
}

function pos_checkout_safe_db_failure_message(string $fallback): string {
    if (!function_exists('printflow_db_errors')) {
        return $fallback;
    }
    $errors = printflow_db_errors();
    if (!is_array($errors) || $errors === []) {
        return $fallback;
    }
    $last = end($errors);
    if (!is_array($last)) {
        return $fallback;
    }
    $stage = trim((string)($last['stage'] ?? ''));
    $errText = trim((string)($last['error'] ?? ''));
    if ($errText === '') {
        return $fallback;
    }
    error_log('[pos_checkout] db failure' . ($stage !== '' ? (' stage=' . $stage) : '') . ': ' . $errText);

    return $fallback;
}

function pos_prepare_order_for_paymongo_checkout(
    int $orderId,
    int $staffId,
    string $paymentMethod,
    string $paymentStatus = 'Awaiting Payment',
    string $orderStatus = 'Pending'
): array {
    if ($orderId <= 0) {
        return ['ok' => false, 'message' => 'The POS order was not found.'];
    }

    $rows = db_query(
        "SELECT order_id, total_amount, status, payment_status
         FROM orders
         WHERE order_id = ?
         LIMIT 1",
        'i',
        [$orderId]
    ) ?: [];
    if (empty($rows)) {
        return ['ok' => false, 'message' => 'The POS order was not found.'];
    }

    $order = $rows[0];
    $totalAmount = (float)($order['total_amount'] ?? 0);
    if ($totalAmount <= 0) {
        return ['ok' => false, 'message' => 'The POS order total must be greater than zero.'];
    }

    $normalizedStatus = strtoupper(str_replace(' ', '_', trim((string)($order['status'] ?? ''))));
    if (in_array($normalizedStatus, ['CANCELLED', 'REJECTED', 'COMPLETED'], true)) {
        return ['ok' => false, 'message' => 'This POS order can no longer be paid.'];
    }

    $setParts = [];
    $types = '';
    $params = [];

    if (db_table_has_column('orders', 'payment_status')) {
        $setParts[] = 'payment_status = ?';
        $types .= 's';
        $params[] = $paymentStatus;
    }

    if (db_table_has_column('orders', 'payment_method')) {
        $setParts[] = 'payment_method = ?';
        $types .= 's';
        $params[] = $paymentMethod;
    }

    if (db_table_has_column('orders', 'status')) {
        $setParts[] = 'status = ?';
        $types .= 's';
        $params[] = $orderStatus;
    }

    if (db_table_has_column('orders', 'price_finalized_at')) {
        $setParts[] = 'price_finalized_at = COALESCE(price_finalized_at, NOW())';
    }

    if (db_table_has_column('orders', 'price_finalized_by')) {
        $setParts[] = 'price_finalized_by = COALESCE(price_finalized_by, ?)';
        $types .= 'i';
        $params[] = $staffId;
    }

    if ($setParts !== []) {
        $setParts[] = 'updated_at = NOW()';
        if (!db_execute(
            'UPDATE orders SET ' . implode(', ', $setParts) . ' WHERE order_id = ?',
            $types . 'i',
            array_merge($params, [$orderId])
        )) {
            return ['ok' => false, 'message' => 'The POS order could not be prepared for PayMongo checkout.'];
        }
    }

    return ['ok' => true];
}

function pos_order_uses_walkin_placeholder(array $order): bool {
    return pos_is_shared_walkin_placeholder_email((string)($order['email'] ?? ''));
}

function pos_ensure_customizations_table(): void {
    db_execute(
        "CREATE TABLE IF NOT EXISTS `customizations` (
          `customization_id` INT AUTO_INCREMENT PRIMARY KEY,
          `order_id` INT NOT NULL,
          `order_item_id` INT DEFAULT NULL,
          `customer_id` INT NOT NULL,
          `service_type` VARCHAR(100) NOT NULL,
          `customization_details` LONGTEXT NULL,
          `status` VARCHAR(50) NOT NULL DEFAULT 'Pending Review',
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY `idx_order` (`order_id`),
          KEY `idx_customer` (`customer_id`),
          KEY `idx_status` (`status`),
          KEY `idx_order_item` (`order_item_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function pos_get_service_placeholder_product_id(): int {
    $existing = db_query("SELECT product_id FROM products WHERE sku = 'POS-SERVICE' LIMIT 1") ?: [];
    if (!empty($existing)) {
        return (int)$existing[0]['product_id'];
    }

    global $conn;
    $created = db_execute(
        "INSERT INTO products (sku, name, category, description, price, stock_quantity, status)
         VALUES ('POS-SERVICE', 'POS Service Item', 'Service', 'Placeholder product for POS service-only order items.', 0, 0, 'Activated')"
    );

    if ($created) {
        return (int)$conn->insert_id;
    }

    $existing = db_query("SELECT product_id FROM products WHERE sku = 'POS-SERVICE' LIMIT 1") ?: [];
    if (!empty($existing)) {
        return (int)$existing[0]['product_id'];
    }

    $fallback = db_query("SELECT product_id FROM products ORDER BY product_id ASC LIMIT 1") ?: [];
    if (!empty($fallback)) {
        error_log('PrintFlow POS checkout: using first available product as service placeholder fallback.');
        return (int)$fallback[0]['product_id'];
    }

    return 0;
}

/**
 * @return array<int,array{price:float,name:string}>
 */
function pos_prefetch_products_by_ids(array $productIds): array {
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn(int $id): bool => $id > 0)));
    if ($productIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $rows = db_query(
        "SELECT product_id, price, name FROM products WHERE product_id IN ({$placeholders})",
        str_repeat('i', count($productIds)),
        $productIds
    ) ?: [];

    $map = [];
    foreach ($rows as $row) {
        $productId = (int)($row['product_id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }
        $map[$productId] = [
            'price' => (float)($row['price'] ?? 0),
            'name' => (string)($row['name'] ?? 'Product'),
        ];
    }

    return $map;
}

function pos_customization_has_persisted_media_path(array $customization, string $prefix): bool {
    $pathKeys = $prefix === 'reference_upload'
        ? ['reference_upload_path', 'reference_file']
        : ['design_upload_path', 'design_file', 'design_tmp_path'];
    foreach ($pathKeys as $pathKey) {
        if (trim((string)($customization[$pathKey] ?? '')) !== '') {
            return true;
        }
    }

    if ($prefix === 'design_upload' && !empty($customization['design_upload_files']) && is_array($customization['design_upload_files'])) {
        foreach ($customization['design_upload_files'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (trim((string)($entry['path'] ?? ($entry['storage_path'] ?? ''))) !== '') {
                return true;
            }
        }
    }

    return false;
}

/**
 * @return array{
 *   csrf_token:string,
 *   pos_checkout_orders:array,
 *   pos_paymongo_checkouts:array,
 *   pos_pending_orders:array,
 *   user_id:int,
 *   branch_id:int,
 *   user_name:string
 * }
 */
function pos_checkout_capture_session_context(): array {
    return [
        'csrf_token' => (string)($_SESSION['csrf_token'] ?? ''),
        'pos_checkout_orders' => is_array($_SESSION['pos_checkout_orders'] ?? null)
            ? $_SESSION['pos_checkout_orders']
            : [],
        'pos_paymongo_checkouts' => is_array($_SESSION['pos_paymongo_checkouts'] ?? null)
            ? $_SESSION['pos_paymongo_checkouts']
            : [],
        'pos_pending_orders' => is_array($_SESSION['pos_pending_orders'] ?? null)
            ? $_SESSION['pos_pending_orders']
            : [],
        'pos_cart' => is_array($_SESSION['pos_cart'] ?? null) ? array_values($_SESSION['pos_cart']) : [],
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'branch_id' => (int)($_SESSION['branch_id'] ?? 0),
        'user_name' => trim((string)($_SESSION['user_name'] ?? 'Staff')),
    ];
}

function pos_checkout_pair_tarpaulin_cart_lines(array &$items, array $sessionCart, int $branchId): ?string
{
    $result = printflow_tarpaulin_pair_cart_lines($items, $sessionCart, $branchId);
    if (empty($result['ok'])) return (string)($result['error'] ?? 'Tarpaulin checkout cart lines could not be verified.');
    $items = (array)$result['items'];
    return null;
}

function pos_checkout_pair_design_fee_lines(array &$items, array $sessionCart): ?string
{
    foreach ($items as $index => $item) {
        if (!is_array($item) || !pos_payload_item_is_service($item)) continue;
        $custom = is_array($item['customization'] ?? null) ? $item['customization'] : [];
        $lineId = trim((string)($custom['pos_design_line_id'] ?? ''));
        if ($lineId === '') continue;
        $matches = [];
        foreach ($sessionCart as $sessionItem) {
            if (!is_array($sessionItem) || !pos_payload_item_is_service($sessionItem)) continue;
            $sessionCustom = is_array($sessionItem['customization'] ?? null) ? $sessionItem['customization'] : [];
            if (hash_equals((string)($sessionCustom['pos_design_line_id'] ?? ''), $lineId)) $matches[] = $sessionItem;
        }
        if (count($matches) !== 1) return 'A service cart line could not be matched to its server-side pricing. Refresh the POS cart and try again.';
        $sessionItem = $matches[0];
        $clientQty = pos_checkout_parse_positive_quantity($item['qty'] ?? null);
        $sessionQty = pos_checkout_parse_positive_quantity($sessionItem['qty'] ?? null);
        if ($clientQty !== $sessionQty || (int)($item['id'] ?? 0) !== (int)($sessionItem['product_id'] ?? 0)) {
            return 'A service quantity or identity changed. Refresh the POS cart and try again.';
        }
        $items[$index]['price'] = (float)($sessionItem['price'] ?? 0);
        $items[$index]['price_set'] = !empty($sessionItem['price_set']);
        $items[$index]['line_total_override'] = $sessionItem['line_total_override'] ?? null;
        $items[$index]['customization'] = is_array($sessionItem['customization'] ?? null) ? $sessionItem['customization'] : [];
    }
    return null;
}

function pos_checkout_contains_tarpaulin(array $items): bool
{
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $customization = is_array($item['customization'] ?? null) ? $item['customization'] : [];
        if (((int)($item['id'] ?? $item['product_id'] ?? 0) === 40 || (int)($customization['service_id'] ?? 0) === 40)
            && (!empty($item['is_service']) || !empty($customization['service_id']))) return true;
    }
    return false;
}

function pos_checkout_verify_csrf(string $token, array $sessionContext): bool {
    $expected = (string)($sessionContext['csrf_token'] ?? '');
    return $expected !== '' && hash_equals($expected, (string)$token);
}

function pos_checkout_selected_transaction_datetime(array $data): string {
    return printflow_pos_selected_transaction_datetime($data);
}

function pos_checkout_align_pos_order_timestamps(array $orderIds, string $selectedTransactionAt): void {
    $selectedTransactionAt = trim($selectedTransactionAt);
    if ($selectedTransactionAt === '') {
        return;
    }
    foreach ($orderIds as $orderId) {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            continue;
        }
        // created_at is the actual creation time. Consumers resolve the
        // business timestamp through customizations.order_id -> orders.
        if (getenv('PRINTFLOW_POS_DATETIME_DEBUG') === '1') {
            $saved = db_query('SELECT order_id, order_date FROM orders WHERE order_id = ?', 'i', [$orderId]);
            error_log('[PrintFlow POS datetime] saved_order ' . json_encode([
                'expected_transaction_date' => $selectedTransactionAt,
                'saved' => $saved,
                'customizations' => db_query('SELECT customization_id, order_id, created_at FROM customizations WHERE order_id = ?', 'i', [$orderId]),
            ]));
        }
    }
}

function pos_checkout_reopen_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        SessionManager::start();
    }
}

function pos_checkout_log_stage(string $stage, array $meta = []): void {
    $parts = ['[POS CHECKOUT]', 'stage=' . $stage];
    foreach ($meta as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $parts[] = $key . '=' . (string)$value;
        }
    }
    error_log(implode(' ', $parts));
}

function pos_checkout_persist_session_state(
    string $checkoutToken,
    int $orderId,
    array $posPendingOrdersSnapshot,
    ?array $receipt = null,
    bool $isPayMongo = false
): void {
    if ($orderId <= 0 || $checkoutToken === '') {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        SessionManager::start();
    }

    if ($posPendingOrdersSnapshot !== ($_SESSION['pos_pending_orders'] ?? [])) {
        $_SESSION['pos_pending_orders'] = $posPendingOrdersSnapshot;
    }
    $_SESSION['pos_checkout_orders'][$checkoutToken] = $orderId;
    if (count($_SESSION['pos_checkout_orders']) > 20) {
        $_SESSION['pos_checkout_orders'] = array_slice($_SESSION['pos_checkout_orders'], -20, null, true);
    }
    if ($isPayMongo) {
        $_SESSION['pos_paymongo_checkouts'] = [];
        $_SESSION['pos_paymongo_checkouts'][$checkoutToken] = $orderId;
    }
    if ($receipt !== null) {
        $_SESSION['pos_receipt_snapshots'][$orderId] = $receipt;
    }

    session_write_close();
}

function pos_find_pending_service_link(int $customerId, int $branchId, int $productId, string $serviceName): array {
    if ($customerId <= 0 || $branchId <= 0) {
        return ['order_id' => 0, 'customization_id' => 0];
    }

    $normalizedServiceName = trim($serviceName);
    $rows = db_query(
        "SELECT
            o.order_id,
            cust.customization_id,
            cust.service_type
         FROM orders o
         JOIN customizations cust ON cust.order_id = o.order_id
         LEFT JOIN order_items oi ON oi.order_id = o.order_id
         WHERE o.customer_id = ?
           AND o.branch_id = ?
           AND LOWER(TRIM(COALESCE(o.order_source, ''))) IN ('pos', 'pos_draft')
           AND o.payment_status = 'Unpaid'
           AND o.status IN ('Draft', 'Approved', 'Pending', 'Pending Review', 'Pending Approval')
           AND (
                o.reference_id = ?
                OR LOWER(TRIM(COALESCE(cust.service_type, ''))) = LOWER(TRIM(?))
                OR oi.customization_data LIKE '%\"source\":\"POS\"%'
                OR oi.customization_data LIKE '%\"source\": \"POS\"%'
           )
         ORDER BY cust.updated_at DESC, cust.customization_id DESC
         LIMIT 1",
        'iiis',
        [$customerId, $branchId, $productId, $normalizedServiceName]
    ) ?: [];

    if (empty($rows)) {
        return ['order_id' => 0, 'customization_id' => 0];
    }

    return [
        'order_id' => (int)($rows[0]['order_id'] ?? 0),
        'customization_id' => (int)($rows[0]['customization_id'] ?? 0),
    ];
}

function pos_normalize_media_upload_keys(array &$customization): void {
    foreach ($customization as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        $norm = strtolower(preg_replace('/[^a-z0-9]/', '', $key));
        if (str_contains($norm, 'upload') && str_contains($norm, 'design')) {
            if (empty($customization['design_upload']) && is_scalar($value) && trim((string)$value) !== '') {
                $customization['design_upload'] = trim((string)$value);
            }
            $dataKey = $key . '_data';
            if (!empty($customization[$dataKey]) && empty($customization['design_upload_data'])) {
                $customization['design_upload_data'] = $customization[$dataKey];
            }
            $mimeKey = $key . '_mime';
            if (!empty($customization[$mimeKey]) && empty($customization['design_upload_mime'])) {
                $customization['design_upload_mime'] = $customization[$mimeKey];
            }
            $nameKey = $key . '_name';
            if (!empty($customization[$nameKey]) && empty($customization['design_upload_name'])) {
                $customization['design_upload_name'] = $customization[$nameKey];
            }
        }
        if (str_contains($norm, 'upload') && str_contains($norm, 'reference')) {
            if (empty($customization['reference_upload']) && is_scalar($value) && trim((string)$value) !== '') {
                $customization['reference_upload'] = trim((string)$value);
            }
            $dataKey = $key . '_data';
            if (!empty($customization[$dataKey]) && empty($customization['reference_upload_data'])) {
                $customization['reference_upload_data'] = $customization[$dataKey];
            }
        }
    }
}

function pos_extract_inline_media_payload(array &$customization, string $prefix): array {
    $data = trim((string)($customization[$prefix . '_data'] ?? ''));
    $name = trim((string)($customization[$prefix . '_name'] ?? $customization[$prefix] ?? ''));
    $mime = trim((string)($customization[$prefix . '_mime'] ?? ''));

    $payload = [
        'blob' => null,
        'mime' => $mime,
        'name' => $name !== '' ? $name : ($prefix === 'reference_upload' ? 'reference_upload' : 'design_upload'),
    ];

    if ($data === '') {
        // During POS checkout, persisted upload paths are authoritative. Re-reading
        // every design file into memory for each cart line is extremely slow for
        // large mixed carts and is not required when the media is already stored.
        if (pos_customization_has_persisted_media_path($customization, $prefix)) {
            return $payload;
        }

        return $payload;
    }

    $decoded = null;
    if (preg_match('#^data:([^;]+);base64,(.+)$#s', $data, $matches)) {
        $decoded = base64_decode($matches[2], true);
        if ($mime === '') {
            $payload['mime'] = trim((string)$matches[1]);
        }
    } elseif (preg_match('#^[A-Za-z0-9+/=\r\n]+$#', $data)) {
        $decoded = base64_decode(preg_replace('/\s+/', '', $data), true);
    }

    if ($decoded === false || $decoded === '') {
        return $payload;
    }

    $payload['blob'] = $decoded;
    if ($payload['mime'] === '') {
        $payload['mime'] = 'application/octet-stream';
    }

    unset(
        $customization[$prefix . '_data'],
        $customization[$prefix . '_name'],
        $customization[$prefix . '_mime']
    );

    return $payload;
}

/**
 * @return array{design_path:?string,design_name:?string,design_blob_saved:bool,reference_path:?string,reference_name:?string}
 */
function pos_store_order_item_inline_media(int $orderItemId, array $designPayload, array $referencePayload = []): array {
    $result = [
        'design_path'        => null,
        'design_name'        => null,
        'design_blob_saved'  => false,
        'reference_path'     => null,
        'reference_name'     => null,
    ];

    if ($orderItemId <= 0) {
        return $result;
    }

    printflow_ensure_order_items_columns();

    $uploadDir = dirname(__DIR__, 2) . '/uploads/orders';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0775, true);
    }

    global $conn;

    if (!empty($designPayload['blob'])) {
        $webPath = printflow_persist_order_item_design_media(
            $orderItemId,
            (string)$designPayload['blob'],
            (string)($designPayload['mime'] ?? 'application/octet-stream'),
            (string)($designPayload['name'] ?? 'design_upload')
        );
        if ($webPath !== null) {
            $result['design_path'] = $webPath;
            $result['design_name'] = (string)($designPayload['name'] ?? 'design_upload');
            $result['design_blob_saved'] = true;
        }
    }

    if (!empty($referencePayload['blob'])) {
        $refName = (string)($referencePayload['name'] ?? 'reference_upload');
        $result['reference_name'] = $refName;
        if (is_dir($uploadDir) && is_writable($uploadDir)) {
            $baseName = trim($refName);
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $baseName);
            if ($safeName === '' || $safeName === null) {
                $safeName = 'reference_upload';
            }
            $targetName = time() . '_ref_' . $orderItemId . '_' . $safeName;
            $targetPath = $uploadDir . '/' . $targetName;
            if (@file_put_contents($targetPath, $referencePayload['blob']) !== false) {
                $storedPath = '/uploads/orders/' . $targetName;
                $result['reference_path'] = $storedPath;
                db_execute(
                    "UPDATE order_items SET reference_image_file = ? WHERE order_item_id = ?",
                    'si',
                    [$storedPath, $orderItemId]
                );
            }
        }
    }

    return $result;
}

function pos_apply_stored_media_to_customization(array &$customization, array $stored): void {
    if (!empty($stored['design_path'])) {
        $path = function_exists('printflow_normalize_order_upload_web_path')
            ? printflow_normalize_order_upload_web_path((string)$stored['design_path'])
            : (string)$stored['design_path'];
        $customization['design_upload_path'] = $path;
        $customization['design_file'] = $path;
        $name = trim((string)($stored['design_name'] ?? ''));
        if ($name !== '') {
            $customization['design_upload'] = $name;
            $customization['design_upload_name'] = $name;
        }
    }

    if (!empty($stored['reference_path'])) {
        $path = function_exists('printflow_normalize_order_upload_web_path')
            ? printflow_normalize_order_upload_web_path((string)$stored['reference_path'])
            : (string)$stored['reference_path'];
        $customization['reference_upload_path'] = $path;
        $customization['reference_file'] = $path;
        $name = trim((string)($stored['reference_name'] ?? ''));
        if ($name !== '') {
            $customization['reference_upload'] = $name;
            $customization['reference_upload_name'] = $name;
        }
    }
}

function pos_sync_order_item_customization_json(int $orderItemId, int $orderId, int $customizationId, array $customization): void {
    if ($orderItemId <= 0) {
        return;
    }

    $customization = printflow_customization_normalize_storage($customization);
    $json = json_encode($customization ?: new stdClass());
    db_execute(
        'UPDATE order_items SET customization_data = ? WHERE order_item_id = ?',
        'si',
        [$json, $orderItemId]
    );

    if ($customizationId > 0) {
        db_execute(
            'UPDATE customizations SET customization_details = ?, updated_at = NOW() WHERE customization_id = ?',
            'si',
            [$json, $customizationId]
        );
        return;
    }

    if ($orderId > 0) {
        db_execute(
            'UPDATE customizations SET customization_details = ?, updated_at = NOW() WHERE order_id = ? AND order_item_id = ?',
            'sii',
            [$json, $orderId, $orderItemId]
        );
    }
}

function pos_copy_order_item_media_if_missing(int $sourceOrderItemId, int $targetOrderItemId): void {
    if ($sourceOrderItemId <= 0 || $targetOrderItemId <= 0 || $sourceOrderItemId === $targetOrderItemId) {
        return;
    }

    $sourceRows = db_query(
        "SELECT design_image, design_image_mime, design_image_name, design_file, reference_image_file
         FROM order_items
         WHERE order_item_id = ?
         LIMIT 1",
        'i',
        [$sourceOrderItemId]
    ) ?: [];
    if (empty($sourceRows)) {
        return;
    }

    $targetRows = db_query(
        "SELECT
            IFNULL(LENGTH(design_image), 0) AS design_image_bytes,
            TRIM(COALESCE(design_file, '')) AS design_file,
            TRIM(COALESCE(reference_image_file, '')) AS reference_image_file
         FROM order_items
         WHERE order_item_id = ?
         LIMIT 1",
        'i',
        [$targetOrderItemId]
    ) ?: [];
    if (empty($targetRows)) {
        return;
    }

    $source = $sourceRows[0];
    $target = $targetRows[0];
    $targetHasDesign = (int)($target['design_image_bytes'] ?? 0) > 0 || trim((string)($target['design_file'] ?? '')) !== '';
    $targetHasReference = trim((string)($target['reference_image_file'] ?? '')) !== '';

    if (!$targetHasDesign) {
        $sourceHasDesign = !empty($source['design_image']) || trim((string)($source['design_file'] ?? '')) !== '';
        if ($sourceHasDesign) {
            db_execute(
                "UPDATE order_items
                 SET design_image = ?, design_image_mime = ?, design_image_name = ?, design_file = ?
                 WHERE order_item_id = ?",
                'ssssi',
                [
                    $source['design_image'],
                    (string)($source['design_image_mime'] ?? ''),
                    (string)($source['design_image_name'] ?? ''),
                    (string)($source['design_file'] ?? ''),
                    $targetOrderItemId
                ]
            );
        }
    }

    if (!$targetHasReference && trim((string)($source['reference_image_file'] ?? '')) !== '') {
        db_execute(
            "UPDATE order_items
             SET reference_image_file = ?
             WHERE order_item_id = ?",
            'si',
            [(string)$source['reference_image_file'], $targetOrderItemId]
        );
    }
}

function pos_migrate_pending_assignments_to_order(int $sourceOrderId, int $targetOrderId): void {
    if ($sourceOrderId <= 0 || $targetOrderId <= 0 || $sourceOrderId === $targetOrderId) {
        return;
    }

    $sourceJobRows = db_query(
        "SELECT id FROM job_orders WHERE order_id = ? ORDER BY id ASC",
        'i',
        [$sourceOrderId]
    ) ?: [];
    $sourceJobIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $sourceJobRows)));

    $useStdOrderId = pos_table_has_column('job_order_materials', 'std_order_id')
        && pos_table_has_column('job_order_ink_usage', 'std_order_id');

    if (empty($sourceJobIds) && !$useStdOrderId) {
        return;
    }
    if (!empty($sourceJobIds)) {
        $sourceJobPlaceholders = implode(',', array_fill(0, count($sourceJobIds), '?'));
        db_execute(
            "UPDATE job_orders
             SET order_id = ?
             WHERE id IN ($sourceJobPlaceholders)",
            'i' . str_repeat('i', count($sourceJobIds)),
            array_merge([$targetOrderId], $sourceJobIds)
        );
    }
    if ($useStdOrderId) {
        db_execute(
            "UPDATE job_order_materials
             SET std_order_id = ?
             WHERE std_order_id = ?
               AND (job_order_id IS NULL OR job_order_id = 0)",
            'ii',
            [$targetOrderId, $sourceOrderId]
        );
        db_execute(
            "UPDATE job_order_ink_usage
             SET std_order_id = ?
             WHERE std_order_id = ?
               AND (job_order_id IS NULL OR job_order_id = 0)",
            'ii',
            [$targetOrderId, $sourceOrderId]
        );
    }

    error_log(sprintf(
        'PrintFlow POS material migration: re-linked %d source jobs from pending order %d to final order %d',
        count($sourceJobIds),
        $sourceOrderId,
        $targetOrderId
    ));
}

/**
 * Copy pending POS material assignments to the final sale order and move the
 * linked production jobs into the live POS status without deducting inventory
 * yet. Final deduction must happen only when the POS order is marked Completed.
 */
function pos_finalize_inventory_after_checkout(int $finalOrderId, string $targetStatus, int $pendingOrderId = 0): void {
    if ($finalOrderId <= 0 || $targetStatus === '') {
        return;
    }

    if ($pendingOrderId > 0) {
        pos_migrate_pending_assignments_to_order($pendingOrderId, $finalOrderId);
    }

    JobOrderService::ensureJobsForStoreOrder($finalOrderId);
    $jobRows = db_query(
        "SELECT id
         FROM job_orders
         WHERE order_id = ?
           AND status NOT IN ('COMPLETED', 'CANCELLED')
         ORDER BY id ASC",
        'i',
        [$finalOrderId]
    ) ?: [];
    if (empty($jobRows)) {
        throw new Exception('No linked production job was available for POS inventory processing.');
    }

    $jobIds = array_values(array_filter(array_map(static function (array $row): int {
        return (int)($row['id'] ?? 0);
    }, $jobRows)));

    if (!empty($jobIds)) {
        $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
        db_execute(
            "UPDATE job_orders
             SET status = ?, updated_at = NOW()
             WHERE id IN ($placeholders)",
            's' . str_repeat('i', count($jobIds)),
            array_merge([$targetStatus], $jobIds)
        );
    }

    error_log(sprintf(
        'PrintFlow POS finalization: linked %d active jobs to order %d with status %s; inventory deduction deferred until completion',
        count($jobIds),
        $finalOrderId,
        $targetStatus
    ));
}

/**
 * POS customization rows must resolve to real job_orders, but the order insert
 * transaction must commit first before jobs are finalized.
 */
function pos_sync_customization_jobs_after_commit(int $orderId, string $targetStatus): void {
    if ($orderId <= 0 || $targetStatus === '') {
        return;
    }

    $jobs = db_query(
        "SELECT id FROM job_orders WHERE order_id = ? AND status NOT IN ('COMPLETED', 'CANCELLED') ORDER BY id ASC",
        'i',
        [$orderId]
    ) ?: [];

    $normalizedTargetStatus = strtoupper(trim($targetStatus));
    if ($normalizedTargetStatus === 'PENDING') {
        foreach ($jobs as $job) {
            $jobId = (int)($job['id'] ?? 0);
            if ($jobId > 0) {
                db_execute("UPDATE job_orders SET status = 'PENDING', updated_at = NOW() WHERE id = ?", 'i', [$jobId]);
            }
        }
        return;
    }

    // POS service requirement: production status and material deduction are one
    // authoritative operation. A deduction failure aborts checkout.
    if (in_array($normalizedTargetStatus, ['IN_PRODUCTION', 'PROCESSING', 'PRINTING'], true)) {
        foreach ($jobs as $job) {
            $jobId = (int)($job['id'] ?? 0);
            if ($jobId <= 0) {
                continue;
            }
            // Use store-facing "Processing" so order/customization/job statuses stay
            // aligned to the same production phase and deduction triggers immediately.
            JobOrderService::updateStatus($jobId, 'Processing', null, '', true);
        }
        return;
    }

    foreach ($jobs as $job) {
        JobOrderService::updateStatus((int)$job['id'], $targetStatus);
    }
}

function pos_extract_order_item_display_name(array $item): string {
    $fallback = trim((string)($item['product_name'] ?? 'Item'));
    $customization = [];

    if (!empty($item['customization_data'])) {
        $decoded = json_decode((string)$item['customization_data'], true);
        if (is_array($decoded)) {
            $customization = $decoded;
        }
    }

    $serviceType = trim((string)($customization['service_type'] ?? ''));
    $productType = trim((string)($customization['product_type'] ?? ''));
    $baseName = $serviceType !== '' ? $serviceType : ($productType !== '' ? $productType : $fallback);

    return $baseName !== '' ? $baseName : 'Item';
}

/**
 * Recompute service line prices from specifications; reject tampered or missing pricing.
 */
function pos_checkout_validate_service_item_prices(array &$items): ?string
{
    foreach ($items as $itemIndex => $item) {
        if (!pos_payload_item_is_service((array)$item)) {
            continue;
        }

        $itemRow = (array)$item;
        $customization = is_array($itemRow['customization'] ?? null) ? $itemRow['customization'] : [];
        $serviceId = (int)($customization['service_id'] ?? $itemRow['id'] ?? 0);
        try {
            $qty = pos_checkout_parse_positive_quantity($itemRow['qty'] ?? null);
        } catch (RuntimeException $quantityError) {
            return $quantityError->getMessage();
        }
        $cartUnit = (float)($itemRow['price'] ?? 0);

        $requiresSetPrice = pos_cart_item_requires_pos_set_price([
            'product_id' => $serviceId,
            'name' => (string)($itemRow['name'] ?? ''),
            'customization' => $customization,
            'is_service' => true,
            'price_set' => !empty($itemRow['price_set']),
        ]);

        if ($requiresSetPrice) {
            if (empty($itemRow['price_set'])) {
                $label = trim((string)($itemRow['name'] ?? 'Sintraboard item'));
                return 'Set Price and materials for "' . $label . '" before completing this sale.';
            }
            try { printflow_pos_parse_final_price($itemRow['price'] ?? null); }
            catch (InvalidArgumentException $priceError) { return $priceError->getMessage(); }
            $items[$itemIndex]['line_total_override'] = (float)($itemRow['line_total_override'] ?? $itemRow['price']);
            continue;
        }

        $calc = printflow_calculate_service_unit_price($serviceId, $customization);
        if (!$calc['ok']) {
            return (string)($calc['message'] ?? 'Service price could not be validated.');
        }

        $linePricing = printflow_calculate_service_line_pricing($serviceId, $customization, $qty);
        if (empty($linePricing['ok'])) return (string)($linePricing['message'] ?? 'Service line price could not be validated.');
        $expectedUnit = (float)$linePricing['unit_price'];
        if ($cartUnit <= 0) {
            return 'A service item has no valid price. Remove it and add it again with complete specifications.';
        }
        if (abs($cartUnit - $expectedUnit) > 0.02) {
            $label = trim((string)($itemRow['name'] ?? 'Service'));
            return 'Cart price for "' . $label . '" does not match the selected options. Refresh the page and try again.';
        }
        if (abs((float)($customization['design_fee'] ?? 0) - (float)$linePricing['design_fee']) > 0.001) {
            $label = trim((string)($itemRow['name'] ?? 'Service'));
            return 'Design fee for "' . $label . '" changed. Reopen the service options and try again.';
        }
        $customization['design_fee'] = number_format((float)$linePricing['design_fee'], 2, '.', '');
        $customization['calculated_estimated_price'] = number_format((float)$linePricing['line_total'], 2, '.', '');
        $items[$itemIndex]['customization'] = $customization;

    }

    return null;
}

function pos_checkout_group_total(array $groupItems, array $products_cache): float
{
    $total = 0.0;
    foreach ($groupItems as $item) {
        $product_id = (int)($item['id'] ?? 0);
        $qty = pos_checkout_parse_positive_quantity($item['qty'] ?? null);
        $is_service = pos_payload_item_is_service((array)$item);
        $p = $products_cache[$product_id] ?? ['price' => (float)($item['price'] ?? 0)];
        $price = $is_service
            ? (float)($item['price'] ?? ($p['price'] ?? 0))
            : (float)($p['price'] ?? 0);
        $customization = is_array($item['customization'] ?? null) ? $item['customization'] : [];
        $total += isset($item['line_total_override'])
            ? max(0.0, (float)$item['line_total_override'])
            : ($price * $qty) + ($is_service ? max(0.0, (float)($customization['design_fee'] ?? 0)) : 0.0);
    }

    return round($total, 2);
}

function pos_checkout_linked_order_ids(int $primaryOrderId): array
{
    if ($primaryOrderId <= 0) {
        return [];
    }

    $rows = db_query(
        'SELECT payment_reference FROM orders WHERE order_id = ? LIMIT 1',
        'i',
        [$primaryOrderId]
    ) ?: [];
    $reference = trim((string)($rows[0]['payment_reference'] ?? ''));
    if ($reference === '' || !str_starts_with($reference, 'POSBUNDLE-')) {
        return [$primaryOrderId];
    }

    $linkedRows = db_query(
        "SELECT order_id FROM orders WHERE payment_reference = ? AND order_source = 'pos' ORDER BY order_id ASC",
        's',
        [$reference]
    ) ?: [];
    $linked = array_values(array_filter(array_map(static function (array $row): int {
        return (int)($row['order_id'] ?? 0);
    }, $linkedRows)));

    return $linked !== [] ? $linked : [$primaryOrderId];
}

function pos_build_receipt_payload(int $orderId, float $amountTendered = 0.0, array $linkedOrderIds = []): array {
    if ($linkedOrderIds === []) {
        $linkedOrderIds = pos_checkout_linked_order_ids($orderId);
    }
    return printflow_pos_build_receipt($orderId, $amountTendered, $linkedOrderIds);
    /* Legacy implementation retained below temporarily for caller compatibility. */
    $orderRows = db_query(
        "SELECT o.*, c.first_name, c.last_name, c.email, c.contact_number,
                b.branch_name, b.address AS branch_address, b.contact_number AS branch_contact,
                d.code AS discount_code, d.description AS discount_description, d.discount_percent
         FROM orders o
         LEFT JOIN customers c ON c.customer_id = o.customer_id
         LEFT JOIN branches b ON b.id = o.branch_id
         LEFT JOIN discounts d ON d.discount_id = o.discount_id
         WHERE o.order_id = ?
         LIMIT 1",
        'i',
        [$orderId]
    ) ?: [];

    if (empty($orderRows)) {
        return [];
    }

    $order = $orderRows[0];
    $itemRows = db_query(
        "SELECT oi.quantity, oi.unit_price, oi.customization_data, p.name AS product_name
         FROM order_items oi
         LEFT JOIN products p ON p.product_id = oi.product_id
         WHERE oi.order_id = ?
         ORDER BY oi.order_item_id ASC",
        'i',
        [$orderId]
    ) ?: [];

    $receiptItems = [];
    $subtotal = 0.0;
    foreach ($itemRows as $itemRow) {
        $quantity = (int)($itemRow['quantity'] ?? 0);
        $unitPrice = (float)($itemRow['unit_price'] ?? 0);
        $lineTotal = $quantity * $unitPrice;
        $customization = json_decode((string)($itemRow['customization_data'] ?? ''), true);
        $customization = printflow_customization_display_specs(is_array($customization) ? $customization : [], [
            'include_service' => false, 'include_design' => true, 'include_notes' => true, 'include_quantity' => false
        ]);
        $subtotal += $lineTotal;
        $receiptItems[] = [
            'name' => pos_extract_order_item_display_name($itemRow),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'customization' => $customization,
        ];
    }

    $storedTotal = (float)($order['total_amount'] ?? 0);
    $discountPercent = (float)($order['discount_percent'] ?? 0);
    $discountAmount = max(0, $subtotal - $storedTotal);
    if ($discountAmount <= 0 && $discountPercent > 0 && $subtotal > 0) {
        $discountAmount = round($subtotal * ($discountPercent / 100), 2);
    }
    $total = $storedTotal > 0 ? $storedTotal : max(0, $subtotal - $discountAmount);
    $changeAmount = max(0, $amountTendered - $total);

    $shopCfg = printflow_load_runtime_config('shop', dirname(__DIR__, 2) . '/public/assets/uploads/shop_config.json');
    $shopName = trim((string)($shopCfg['name'] ?? 'Mr. and Mrs. Print'));
    $shopLogo = trim((string)($shopCfg['logo'] ?? ''));
    $logoUrl = $shopLogo !== ''
        ? rtrim((string)(defined('BASE_PATH') ? BASE_PATH : '/printflow'), '/') . '/public/assets/uploads/' . rawurlencode(basename($shopLogo))
        : '';

    $isWalkinPlaceholder = pos_order_uses_walkin_placeholder($order);
    $customerName = trim(((string)($order['first_name'] ?? '')) . ' ' . ((string)($order['last_name'] ?? '')));
    if ($isWalkinPlaceholder || $customerName === '') {
        $customerName = 'Walk-in Guest';
    }
    $customerEmail = $isWalkinPlaceholder ? '' : (string)($order['email'] ?? '');
    $customerPhone = $isWalkinPlaceholder ? '' : (string)($order['contact_number'] ?? '');

    $receiptDateTime = (string)($order['order_date'] ?? date('Y-m-d H:i:s'));
    return [
        'receipt_number' => 'POS-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT),
        'order_id' => $orderId,
        'qr_payload' => printflow_receipt_qr_payload($orderId),
        'date_time' => $receiptDateTime,
        'date_time_display' => date('M j, Y h:i A', strtotime($receiptDateTime) ?: time()),
        'cashier' => trim((string)($_SESSION['user_name'] ?? 'Staff')) ?: 'Staff',
        'company' => [
            'name' => $shopName,
            'logo_url' => $logoUrl,
            'branch_name' => (string)($order['branch_name'] ?? 'Main Branch'),
            'address' => (string)($order['branch_address'] ?? ''),
            'contact' => (string)($order['branch_contact'] ?? ''),
        ],
        'customer' => [
            'name' => $customerName,
            'email' => $customerEmail,
            'phone' => $customerPhone,
        ],
        'items' => $receiptItems,
        'subtotal' => round($subtotal, 2),
        'discount' => [
            'code' => (string)($order['discount_code'] ?? ''),
            'description' => (string)($order['discount_description'] ?? ''),
            'percent' => $discountPercent,
            'amount' => round($discountAmount, 2),
        ],
        'total' => round($total, 2),
        'payment' => [
            'method' => (string)($order['payment_method'] ?? ''),
            'reference' => (string)($order['payment_reference'] ?? ''),
            'amount_paid' => round($amountTendered > 0 ? $amountTendered : $total, 2),
            'change' => round($changeAmount, 2),
            'balance' => round(max(0, $total - ($amountTendered > 0 ? $amountTendered : $total)), 2),
            'status' => (string)($order['payment_status'] ?? ''),
        ],
    ];
}
// Require staff or admin role
if (!has_role(['Admin', 'Staff'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

header('Content-Type: application/json');

$sessionContext = pos_checkout_capture_session_context();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
pos_checkout_log_stage('session_released');

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data.']);
    exit;
}

pos_checkout_log_stage('payload_ready', [
    'action' => (string)($data['action'] ?? 'walkin_checkout'),
    'items' => is_array($data['items'] ?? null) ? count($data['items']) : 0,
]);

// Void an unfinalized POS Set Price draft (cart removal / explicit cancel).
if (isset($data['action']) && $data['action'] === 'void_pos_draft') {
    $orderId = (int)($data['order_id'] ?? 0);
    $voidResult = pos_void_unfinalized_draft($orderId);
    echo json_encode($voidResult);
    exit;
}

// Handle create_pending_customization action
if (isset($data['action']) && $data['action'] === 'create_pending_customization') {
    pos_checkout_reopen_session();
    printflow_ensure_order_items_columns();
    pos_ensure_customizations_table();

    $transaction_open = false;

    try {
        $checkoutCustomer = printflow_pos_resolve_checkout_customer_context($data);
        $customer_id = $checkoutCustomer['customer_id'];
        $posGuestDisplayName = $checkoutCustomer['pos_guest_display_name'];
        $posGuestEmail = $checkoutCustomer['pos_guest_email'];
    } catch (RuntimeException $customerError) {
        $code = (int)$customerError->getCode();
        if ($code >= 400 && $code < 600) {
            http_response_code($code);
        }
        echo json_encode(['success' => false, 'message' => $customerError->getMessage()]);
        exit;
    }
    
    $item = $data['item'];
    $product_id = (int)$item['id'];
    $name = $item['name'] ?? 'Service';
    try {
        $qty = pos_checkout_parse_positive_quantity($item['qty'] ?? null);
    } catch (RuntimeException $quantityError) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $quantityError->getMessage()]);
        exit;
    }
    $customization = $item['customization'] ?? [];
    if (!is_array($customization)) {
        $customization = [];
    }

    $tarpaulinBranchId = 0;
    if ($product_id === 40) {
        try {
            $tarpaulinBranchId = printflow_pos_authorized_branch_id();
            $cartLines = array_values((array)($_SESSION['pos_cart'] ?? []));
            $validation = printflow_pos_validate_service_stock($cartLines, $tarpaulinBranchId);
            if (empty($validation['ok'])) throw new RuntimeException('Tarpaulin stock could not be verified. Return to the POS cart and validate again.', 409);
            $targetId = trim((string)($item['pos_cart_line_id'] ?? ''));
            $matches = [];
            foreach ($cartLines as $cartIndex => $cartLine) {
                if ((int)($cartLine['product_id'] ?? 0) === 40 && hash_equals((string)($cartLine['pos_cart_line_id'] ?? ''), $targetId)) $matches[] = $cartIndex;
            }
            if ($targetId === '' || count($matches) !== 1) throw new RuntimeException('The Tarpaulin cart line could not be uniquely verified.', 409);
            $targetIndex = $matches[0];
            $cartLine = $cartLines[$targetIndex];
            $cartCustomization = is_array($cartLine['customization'] ?? null) ? $cartLine['customization'] : [];
            if ((int)($cartLine['qty'] ?? 0) !== $qty
                || abs((float)($cartCustomization['width'] ?? 0) - (float)($customization['width'] ?? 0)) >= 0.000001
                || abs((float)($cartCustomization['height'] ?? 0) - (float)($customization['height'] ?? 0)) >= 0.000001) {
                throw new RuntimeException('Tarpaulin dimensions or quantity no longer match the validated cart line.', 409);
            }
            $selection = $validation['tarpaulin_selections'][$targetIndex] ?? null;
            if (!is_array($selection)) throw new RuntimeException('The server-validated Tarpaulin roll selection is missing.', 409);
            $customization['pos_tarpaulin_selection'] = $selection;
        } catch (Throwable $tarpaulinError) {
            http_response_code((int)$tarpaulinError->getCode() === 409 ? 409 : 400);
            echo json_encode(['success' => false, 'message' => $tarpaulinError->getMessage()]);
            exit;
        }
    }
    
    // Mark as POS source
    $customization['source'] = 'POS';
    pos_normalize_media_upload_keys($customization);
    $draftDesignPayload = pos_extract_inline_media_payload($customization, 'design_upload');
    $draftReferencePayload = pos_extract_inline_media_payload($customization, 'reference_upload');
    
    try {
        global $conn;
        $conn->begin_transaction();
        $transaction_open = true;
        
        $branch_id = $product_id === 40 ? $tarpaulinBranchId : (int)($_SESSION['branch_id'] ?? 1);
        if ($branch_id < 1) $branch_id = 1;

        $pending_item_product_id = pos_get_service_placeholder_product_id();
        if ($pending_item_product_id <= 0) {
            $pending_item_product_id = $product_id;
        }

        // Create a hidden draft POS order so customizations still satisfies table
        // constraints, but keep it out of normal staff lists until checkout finalizes.
        [$guestCol, $guestVal, $guestParams, $guestTypes] = pos_checkout_guest_contact_insert_sql($posGuestDisplayName, $posGuestEmail);
        $order_result = db_execute(
            "INSERT INTO orders (customer_id{$guestCol}, branch_id, reference_id, total_amount, status, payment_status, payment_method, order_date, updated_at, order_type, order_source)
             VALUES (?{$guestVal}, ?, ?, 0, 'Draft', 'Unpaid', 'Cash', NOW(), NOW(), 'custom', 'pos_draft')",
            pos_checkout_draft_order_insert_types($guestTypes),
            array_merge([$customer_id], $guestParams, [$branch_id, $product_id])
        );
        if (!$order_result) {
            $conn->rollback();
            echo json_encode([
                'success' => false,
                'stage' => 'draft_order_insert',
                'message' => pos_checkout_safe_db_failure_message('Failed to create draft order.'),
            ]);
            exit;
        }

        $order_id = $conn->insert_id;
        if (!isset($_SESSION['pos_pending_orders'])) {
            $_SESSION['pos_pending_orders'] = [];
        }
        $_SESSION['pos_pending_orders'][$product_id] = $order_id;

        $posEstimatedTotal = printflow_pos_customization_estimated_total($customization, $qty);
        if ($posEstimatedTotal > 0 && function_exists('db_table_has_column') && db_table_has_column('orders', 'estimated_price')) {
            db_execute(
                'UPDATE orders SET estimated_price = ? WHERE order_id = ?',
                'di',
                [$posEstimatedTotal, $order_id]
            );
        }

        $customization_json = json_encode($customization ?: new stdClass());
        $item_result = db_execute(
            "INSERT INTO order_items (order_id, product_id, quantity, unit_price, customization_data) VALUES (?, ?, ?, 0, ?)",
            'iiis',
            [$order_id, $pending_item_product_id, $qty, $customization_json]
        );
        if (!$item_result) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to create draft order item.']);
            exit;
        }

        $order_item_id = $conn->insert_id;
        $storedMedia = pos_store_order_item_inline_media($order_item_id, $draftDesignPayload, $draftReferencePayload);
        pos_apply_stored_media_to_customization($customization, $storedMedia);
        if (!empty($draftDesignPayload['blob']) && function_exists('printflow_embed_design_backup_in_customization')) {
            $customization = printflow_embed_design_backup_in_customization(
                $customization,
                (string)$draftDesignPayload['blob'],
                (string)($draftDesignPayload['mime'] ?? ''),
                (string)($draftDesignPayload['name'] ?? 'design_upload')
            );
        }
        printflow_sync_job_orders_artwork_for_order_item((int)$order_item_id);

        $details_json = json_encode($customization ?: new stdClass());
        $customization_result = db_execute(
            "INSERT INTO customizations (order_id, order_item_id, customer_id, service_type, customization_details, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'Approved', NOW(), NOW())",
            'iiiss',
            [$order_id, $order_item_id, $customer_id, $name, $details_json]
        );
        
        if (!$customization_result) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to create customization entry.']);
            exit;
        }
        
        $customization_id = $conn->insert_id;
        pos_sync_order_item_customization_json($order_item_id, $order_id, $customization_id, $customization);
        if (function_exists('printflow_order_item_design_files_sync_from_customization')) {
            $syncedDesignFiles = printflow_order_item_design_files_sync_from_customization(
                (int)$order_item_id,
                (int)$order_id,
                (int)$customer_id,
                (int)$customization_id,
                is_array($customization) ? $customization : []
            );
            if (
                $syncedDesignFiles !== []
                && function_exists('printflow_customization_apply_synced_design_files')
            ) {
                $customization = printflow_customization_apply_synced_design_files($customization, $syncedDesignFiles);
                pos_sync_order_item_customization_json($order_item_id, $order_id, $customization_id, $customization);
            }
        }

        $conn->commit();
        $transaction_open = false;
        echo json_encode([
            'success' => true,
            'customization_id' => $customization_id,
            'order_id' => $order_id,
            'order_item_id' => $order_item_id,
            'design_saved' => !empty($storedMedia['design_path']) || !empty($storedMedia['design_blob_saved']),
        ]);
        exit;
        
    } catch (Exception $e) {
        if ($transaction_open && isset($conn)) {
            $conn->rollback();
        }
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

if (empty($data['items'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data. Cart is empty.']);
    exit;
}

printflow_ensure_order_items_columns();
pos_ensure_customizations_table();

if (!pos_checkout_verify_csrf((string)($data['csrf_token'] ?? ''), $sessionContext)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}
try {
    $selectedTransactionAt = pos_checkout_selected_transaction_datetime($data);
} catch (RuntimeException $datetimeError) {
    $code = (int)$datetimeError->getCode();
    http_response_code($code >= 400 && $code < 600 ? $code : 400);
    echo json_encode(['success' => false, 'message' => $datetimeError->getMessage(), 'stage' => 'transaction_datetime']);
    exit;
}
pos_checkout_log_stage('transaction_datetime', [
    'selected' => $selectedTransactionAt,
    'inventory_transaction_date' => $selectedTransactionAt,
    'use_custom_transaction_datetime' => printflow_pos_custom_datetime_enabled($data) ? 1 : 0,
]);
$payment_method = sanitize($data['payment_method'] ?? 'Cash');
$reference_number = sanitize($data['reference_number'] ?? '');
$amount_tendered = (float)($data['amount_tendered'] ?? 0);
$items = $data['items'];

if (!is_array($items) || $items === []) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'At least one cart item is required.']);
    exit;
}
foreach ($items as $itemIndex => $item) {
    try {
        if (!is_array($item)) throw new RuntimeException('Invalid cart item.');
        $items[$itemIndex]['qty'] = pos_checkout_parse_positive_quantity($item['qty'] ?? null);
    } catch (RuntimeException $quantityError) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $quantityError->getMessage()]);
        exit;
    }
}

$pricingSessionCart = array_values((array)($sessionContext['pos_cart'] ?? []));
$designLinePairingError = pos_checkout_pair_design_fee_lines($items, $pricingSessionCart);
if ($designLinePairingError !== null) {
    http_response_code(409);
    echo json_encode(['success' => false, 'stage' => 'service_pricing_validation', 'message' => $designLinePairingError]);
    exit;
}

$unconfiguredPriceError = printflow_checkout_unconfigured_price_message(is_array($items) ? $items : []);
if ($unconfiguredPriceError !== null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $unconfiguredPriceError]);
    exit;
}

$servicePriceError = pos_checkout_validate_service_item_prices($items);
if ($servicePriceError !== null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $servicePriceError]);
    exit;
}
foreach ($items as $item) {
    if (!is_array($item) || !pos_payload_item_is_service($item)) continue;
    $custom = is_array($item['customization'] ?? null) ? $item['customization'] : [];
    $fee = max(0.0, (float)($custom['design_fee'] ?? 0));
    if ($fee > 0 && !printflow_order_items_has_design_fee_column()) {
        http_response_code(503);
        echo json_encode(['success' => false, 'stage' => 'schema', 'message' => 'Checkout is temporarily unavailable until the approved design-fee database migration is applied.']);
        exit;
    }
    if (!empty($item['price_set']) && !printflow_order_items_has_line_total_override_column()) {
        http_response_code(503);
        echo json_encode(['success' => false, 'stage' => 'schema', 'message' => 'Checkout is temporarily unavailable until the approved exact line-total migration is applied.']);
        exit;
    }
}

$pm_lc = preg_replace('/\s+/', ' ', strtolower(trim($payment_method)));
if ($pm_lc === 'paymongo qr ph') {
    $pm_lc = 'paymongo qrph';
}
$allowedPosPaymentMethods = [
    'cash' => 'Cash',
    'paymongo qrph' => 'PayMongo QRPh',
];
if (!isset($allowedPosPaymentMethods[$pm_lc])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unsupported POS payment method.']);
    exit;
}
$payment_method = $allowedPosPaymentMethods[$pm_lc];
$isPayMongoQrph = $pm_lc === 'paymongo qrph';
$isPayMongo = $isPayMongoQrph;
if ($amount_tendered > 1000000) {
    echo json_encode(['success' => false, 'message' => 'Amount paid exceeds maximum limit of ₱1,000,000.']);
    exit;
}

$checkoutToken = strtolower(trim((string)($data['checkout_token'] ?? '')));
if ($isPayMongoQrph
    && !in_array('qrph', printflow_paymongo_enabled_methods(printflow_paymongo_mode()), true)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Dynamic QR Ph is not enabled for this payment environment.']);
    exit;
}
if (!preg_match('/^[a-f0-9]{32,64}$/', $checkoutToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid POS checkout token is required.']);
    exit;
}
$checkoutLockName = 'pos_checkout_' . substr(hash('sha256', session_id() . ':' . $checkoutToken), 0, 48);
$checkoutLockRows = db_query('SELECT GET_LOCK(?, 15) AS acquired', 's', [$checkoutLockName]) ?: [];
if ((int)($checkoutLockRows[0]['acquired'] ?? 0) !== 1) {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'This checkout is already being processed. Please wait and refresh the cart.']);
    exit;
}
register_shutdown_function(static function () use ($checkoutLockName): void {
    db_query('SELECT RELEASE_LOCK(?) AS released', 's', [$checkoutLockName]);
});
// Refresh session idempotency state after waiting for another request with this token.
pos_checkout_reopen_session();
$sessionContext = pos_checkout_capture_session_context();
session_write_close();
if (!empty($sessionContext['pos_checkout_orders'][$checkoutToken])) {
    $existingOrderId = (int)$sessionContext['pos_checkout_orders'][$checkoutToken];
    $existingOrder = db_query(
        "SELECT order_id, payment_status
         FROM orders
         WHERE order_id = ? AND order_source = 'pos'
         LIMIT 1",
        'i',
        [$existingOrderId]
    ) ?: [];
    if ($existingOrder !== []) {
        if ($isPayMongo) {
            pos_checkout_reopen_session();
            $_SESSION['pos_paymongo_checkouts'][$checkoutToken] = $existingOrderId;
            session_write_close();
        } else {
            $receipt = pos_build_receipt_payload($existingOrderId, $amount_tendered);
            pos_checkout_persist_session_state(
                $checkoutToken,
                $existingOrderId,
                $sessionContext['pos_pending_orders'],
                $receipt,
                false
            );
            echo json_encode([
                'success' => true,
                'duplicate_request' => true,
                'order_id' => $existingOrderId,
                'message' => 'Sale was already completed successfully.',
                'warning' => '',
                'receipt' => $receipt,
                'print_job' => null,
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }
    } else {
        pos_checkout_reopen_session();
        unset($_SESSION['pos_checkout_orders'][$checkoutToken]);
        session_write_close();
    }
}
if ($isPayMongo && !empty($sessionContext['pos_paymongo_checkouts'][$checkoutToken])) {
    $existingOrderId = (int)$sessionContext['pos_paymongo_checkouts'][$checkoutToken];
    if (pos_checkout_contains_tarpaulin($items) || pos_checkout_contains_tarpaulin((array)($sessionContext['pos_cart'] ?? []))) {
        try {
            $tarpaulinBranchId = printflow_pos_authorized_branch_id();
            $tarpaulinCart = array_values((array)($sessionContext['pos_cart'] ?? []));
            $pairingError = pos_checkout_pair_tarpaulin_cart_lines($items, $tarpaulinCart, $tarpaulinBranchId);
            if ($pairingError !== null) throw new RuntimeException($pairingError);
            $retryValidation = printflow_pos_validate_service_stock($items, $tarpaulinBranchId);
            if (empty($retryValidation['ok'])) throw new RuntimeException('Tarpaulin stock changed or could not be verified. Revalidate the cart before retrying QR Ph.');
        } catch (Throwable $tarpaulinRetryError) {
            http_response_code(409);
            echo json_encode(['success' => false, 'stage' => 'service_material_stock_validation', 'message' => $tarpaulinRetryError->getMessage()]);
            exit;
        }
    }
    $preparedOrder = pos_prepare_order_for_paymongo_checkout(
        $existingOrderId,
        (int)$sessionContext['user_id'],
        $payment_method,
        'Awaiting Payment',
        'Pending'
    );
    if (!$preparedOrder['ok']) {
        pos_checkout_reopen_session();
        unset($_SESSION['pos_paymongo_checkouts'][$checkoutToken]);
        session_write_close();
    } else {
        $existingResult = printflow_provider_payment_create_qrph(
            'order',
            $existingOrderId,
            'pos',
            (int)$sessionContext['user_id']
        );
        http_response_code(!empty($existingResult['ok']) ? 200 : (int)($existingResult['http_status'] ?? 409));
        echo json_encode([
            'success' => !empty($existingResult['ok']),
            'order_id' => $existingOrderId,
            'payment_pending' => true,
            'payment' => $existingResult['payment'] ?? null,
            'message' => $existingResult['message'] ?? 'Complete the PayMongo payment to continue.',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

try {
    $checkoutCustomer = printflow_pos_resolve_checkout_customer_context($data);
    $customer_id = $checkoutCustomer['customer_id'];
    $posGuestDisplayName = $checkoutCustomer['pos_guest_display_name'];
    $posGuestEmail = $checkoutCustomer['pos_guest_email'];
} catch (RuntimeException $customerError) {
    $code = (int)$customerError->getCode();
    if ($code >= 400 && $code < 600) {
        http_response_code($code);
    }
    echo json_encode(['success' => false, 'message' => $customerError->getMessage()]);
    exit;
}

$pos_pending_orders_snapshot = $sessionContext['pos_pending_orders'];
$checkout_actor_user_id = (int)$sessionContext['user_id'];
$checkout_branch_id = (int)$sessionContext['branch_id'];
if ($checkout_branch_id < 1) {
    $checkout_branch_id = 1;
}
$sessionCart = array_values((array)($sessionContext['pos_cart'] ?? []));
$designLinePairingError = pos_checkout_pair_design_fee_lines($items, $sessionCart);
if ($designLinePairingError !== null) {
    http_response_code(409);
    echo json_encode(['success' => false, 'stage' => 'service_pricing_validation', 'message' => $designLinePairingError]);
    exit;
}
$unconfiguredPriceError = printflow_checkout_unconfigured_price_message($items);
if ($unconfiguredPriceError !== null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $unconfiguredPriceError]);
    exit;
}
$servicePriceError = pos_checkout_validate_service_item_prices($items);
if ($servicePriceError !== null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $servicePriceError]);
    exit;
}
foreach ($items as $item) {
    if (!is_array($item) || !pos_payload_item_is_service($item)) continue;
    $custom = is_array($item['customization'] ?? null) ? $item['customization'] : [];
    if (max(0.0, (float)($custom['design_fee'] ?? 0)) > 0 && !printflow_order_items_has_design_fee_column()) {
        http_response_code(503);
        echo json_encode(['success' => false, 'stage' => 'schema', 'message' => 'Checkout is temporarily unavailable until the approved design-fee database migration is applied.']);
        exit;
    }
    if (!empty($item['price_set']) && !printflow_order_items_has_line_total_override_column()) {
        http_response_code(503);
        echo json_encode(['success' => false, 'stage' => 'schema', 'message' => 'Checkout is temporarily unavailable until the approved exact line-total migration is applied.']);
        exit;
    }
}
if (pos_checkout_contains_tarpaulin($items) || pos_checkout_contains_tarpaulin($sessionCart)) {
    try {
        $checkout_branch_id = printflow_pos_authorized_branch_id();
    } catch (Throwable $branchError) {
        http_response_code(409);
        echo json_encode(['success' => false, 'stage' => 'service_material_stock_validation', 'message' => $branchError->getMessage()]);
        exit;
    }
    $pairingError = pos_checkout_pair_tarpaulin_cart_lines($items, $sessionCart, $checkout_branch_id);
    if ($pairingError !== null) {
        http_response_code(409);
        echo json_encode(['success' => false, 'stage' => 'service_material_stock_validation', 'message' => $pairingError]);
        exit;
    }
}
$pos_branch_id = $checkout_branch_id;

$serviceStockValidation = printflow_pos_validate_service_stock($items, $checkout_branch_id);
if (empty($serviceStockValidation['ok'])) {
    $issue = $serviceStockValidation['issues'][0] ?? [];
    if (trim((string)($issue['message'] ?? '')) !== '') {
        http_response_code(409);
        echo json_encode(['success' => false, 'stage' => 'service_material_stock_validation', 'message' => (string)$issue['message']]);
        exit;
    }
    $uom = trim((string)($issue['uom'] ?? ''));
    $format = static fn($value): string => rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
    $message = 'Insufficient ' . (string)($issue['name'] ?? 'service material') . ' stock. Available: '
        . $format($issue['available'] ?? 0) . ($uom !== '' ? ' ' . $uom : '')
        . '. Required: ' . $format($issue['required'] ?? 0) . ($uom !== '' ? ' ' . $uom : '') . '.';
    http_response_code(409);
    echo json_encode(['success' => false, 'stage' => 'service_material_stock_validation', 'message' => $message]);
    exit;
}
foreach ((array)($serviceStockValidation['tarpaulin_selections'] ?? []) as $lineIndex => $selection) {
    if (!is_array($selection) || !isset($items[$lineIndex])) continue;
    $customization = is_array($items[$lineIndex]['customization'] ?? null) ? $items[$lineIndex]['customization'] : [];
    $customization['pos_tarpaulin_selection'] = $selection;
    $items[$lineIndex]['customization'] = $customization;
}

$serviceMaterialError = pos_checkout_validate_assigned_service_materials($items, $pos_pending_orders_snapshot);
if ($serviceMaterialError !== null) {
    http_response_code(409);
    echo json_encode(['success' => false, 'stage' => 'service_material_stock_validation', 'message' => $serviceMaterialError]);
    exit;
}
$checkoutStaffId = $checkout_actor_user_id;

printflow_ensure_product_branch_stock_table();
printflow_ensure_product_inventory_transaction_schema();

$productIdsToPrefetch = [];
foreach ($items as $item) {
    $productIdsToPrefetch[] = (int)($item['id'] ?? 0);
}
$products_cache = pos_prefetch_products_by_ids($productIdsToPrefetch);
$ordersHasAmountPaid = pos_table_has_column('orders', 'amount_paid');
$ordersHasPriceFinalized = db_table_has_column('orders', 'price_finalized_at')
    && db_table_has_column('orders', 'price_finalized_by');

// Calculate total and verify stock
$total_amount = 0;
$actual_product_ids = [];
$baseStockDemand = [];
$optionStockDemand = [];
foreach ($items as $itemIndex => $item) {
    $product_id = (int)$item['id'];
    $qty = pos_checkout_parse_positive_quantity($item['qty'] ?? null);
    $is_service_item = pos_payload_item_is_service((array)$item);

    if (!isset($products_cache[$product_id])) {
        if ($is_service_item) {
            // Service IDs may not exist in products table — use name/price from payload
            $products_cache[$product_id] = ['name' => $item['name'] ?? 'Service', 'price' => (float)($item['price'] ?? 0)];
            $custom = is_array($item['customization'] ?? null) ? $item['customization'] : [];
            $total_amount += isset($item['line_total_override'])
                ? max(0.0, (float)$item['line_total_override'])
                : ((float)($item['price'] ?? 0) * $qty) + max(0.0, (float)($custom['design_fee'] ?? 0));
            continue;
        }
        echo json_encode(['success' => false, 'message' => 'Product not found: ' . $product_id]);
        exit;
    }
    $p = $products_cache[$product_id];
    $actual_product_ids[$product_id] = true;
    $productName = (string)($item['name'] ?? $p['name'] ?? ('Product #' . $product_id));

    // Skip stock check for services
    if (!$is_service_item) {
        $customization = $item['customization'] ?? [];
        $customization = is_array($customization) ? $customization : [];
        $preparedOptionStock = printflow_product_option_stock_prepare_cart_customization(
            $product_id,
            $pos_branch_id,
            $customization,
            $qty,
            $productName
        );
        if (!$preparedOptionStock['ok']) {
            echo json_encode([
                'success' => false,
                'stage' => 'stock_option_validation',
                'product_id' => $product_id,
                'message' => (string)($preparedOptionStock['message'] ?? 'Selected option is out of stock.'),
            ]);
            exit;
        }
        $items[$itemIndex]['customization'] = $preparedOptionStock['customization'];
        $usesOptionStock = printflow_product_option_stock_has_rows($product_id, $pos_branch_id);
        if ($usesOptionStock) {
            $selection = printflow_product_option_stock_find_selected_value($product_id, $customization);
            if ($selection === null) {
                echo json_encode(['success' => false, 'stage' => 'stock_option_validation', 'product_id' => $product_id, 'message' => 'A valid stock option must be selected.']);
                exit;
            }
            $optionKey = $product_id . ':' . strtolower((string)$selection['option_value']);
            if (!isset($optionStockDemand[$optionKey])) {
                $optionStockDemand[$optionKey] = [
                    'product_id' => $product_id,
                    'qty' => 0,
                    'customization' => $customization,
                    'name' => $productName,
                ];
            }
            $optionStockDemand[$optionKey]['qty'] += $qty;
        } else {
            $baseStockDemand[$product_id] = ($baseStockDemand[$product_id] ?? 0) + $qty;
        }
    }

    $price = $is_service_item
        ? (float)($item['price'] ?? $p['price'])
        : (float)$p['price'];
    $custom = is_array($item['customization'] ?? null) ? $item['customization'] : [];
    $total_amount += isset($item['line_total_override'])
        ? max(0.0, (float)$item['line_total_override'])
        : ($price * $qty) + ($is_service_item ? max(0.0, (float)($custom['design_fee'] ?? 0)) : 0.0);
}
foreach ($baseStockDemand as $productId => $requiredQty) {
    [$effStock] = printflow_product_effective_stock((int)$productId, $pos_branch_id);
    if ($effStock < $requiredQty) {
        $productName = (string)($products_cache[$productId]['name'] ?? ('Product #' . $productId));
        echo json_encode(['success' => false, 'stage' => 'stock_validation', 'message' => 'Only ' . (int)$effStock . ' items are available for ' . $productName . '.']);
        exit;
    }
}
foreach ($optionStockDemand as $demand) {
    $stock = printflow_product_option_stock_validate(
        (int)$demand['product_id'],
        $pos_branch_id,
        (array)$demand['customization'],
        (int)$demand['qty']
    );
    if (empty($stock['ok'])) {
        $available = (int)($stock['available'] ?? 0);
        echo json_encode([
            'success' => false,
            'stage' => 'stock_option_validation',
            'product_id' => (int)$demand['product_id'],
            'message' => 'Only ' . $available . ' items are available for ' . (string)$demand['name'] . '.',
        ]);
        exit;
    }
}
if (!$isPayMongo && $amount_tendered < $total_amount) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Amount paid must be at least ₱' . number_format($total_amount, 2) . '.',
    ]);
    exit;
}

$checkout_started_at = microtime(true);
$checkout_stage = 'transaction_start';
$checkout_committed = false;
pos_checkout_log_stage('transaction_start', ['items' => count($items)]);
try {
    global $conn;
    $post_commit_job_sync = [];
    $transaction_open = false;
    $service_placeholder_product_id = 0;
    $conn->begin_transaction();
    $transaction_open = true;

    // Fixed-product walk-in sales complete immediately; service/custom POS items keep their own workflow.
    $branch_id = $checkout_branch_id;

    $productItems = [];
    $serviceItems = [];
    foreach ($items as $item) {
        if (pos_payload_item_is_service((array)$item)) {
            $serviceItems[] = $item;
        } else {
            $productItems[] = $item;
        }
    }
    $orderGroups = [];
    if ($productItems !== []) {
        $orderGroups[] = ['type' => 'product', 'items' => $productItems];
    }
    if ($serviceItems !== []) {
        $orderGroups[] = ['type' => 'custom', 'items' => $serviceItems];
    }
    $isMixedCart = count($orderGroups) > 1;
    $posBundleReference = $isMixedCart ? ('POSBUNDLE-' . $checkoutToken) : $reference_number;
    $initial_payment_status = $isPayMongo ? 'Awaiting Payment' : 'Paid';
    $linkedOrderIds = [];
    $primaryOrderId = null;
    $order_id = null;

    foreach ($orderGroups as $group) {
        $groupItems = $group['items'];
        $order_type = (string)$group['type'];
        $has_service = ($order_type === 'custom');
        $groupTotal = pos_checkout_group_total($groupItems, $products_cache);
        if ($isPayMongo && $isMixedCart && $order_type === 'product') {
            $groupTotal = round($total_amount, 2);
        }
        $order_status = $isPayMongo ? 'Pending' : ($has_service ? 'Pending' : 'Completed');
        $reference_id = $groupItems[0]['id'] ?? null;

        $priceFinalColumns = '';
        $priceFinalValues = '';
        $priceFinalTypes = '';
        $priceFinalParams = [];
        $amountPaidColumns = '';
        $amountPaidValues = '';
        $amountPaidTypes = '';
        $amountPaidParams = [];
        if (!$isPayMongo && $ordersHasAmountPaid) {
            if (!$isMixedCart || $order_type === 'product') {
                $amountPaidColumns = ', amount_paid';
                $amountPaidValues = ', ?';
                $amountPaidTypes = 'd';
                $amountPaidParams[] = $amount_tendered;
            }
        }
        if ($ordersHasPriceFinalized) {
            $priceFinalColumns = ', price_finalized_at, price_finalized_by';
            $priceFinalValues = ', NOW(), ?';
            $priceFinalTypes = 'i';
            $priceFinalParams[] = $checkoutStaffId;
        }

        [$guestCol, $guestVal, $guestParams, $guestTypes] = pos_checkout_guest_contact_insert_sql($posGuestDisplayName, $posGuestEmail);
        $order_result = db_execute(
            "INSERT INTO orders (customer_id{$guestCol}, branch_id, reference_id, total_amount, status, payment_status, payment_method, payment_reference, order_date, updated_at, order_type, order_source{$amountPaidColumns}{$priceFinalColumns})
             VALUES (?{$guestVal}, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'pos'{$amountPaidValues}{$priceFinalValues})",
            pos_checkout_order_insert_types($guestTypes, $amountPaidTypes, $priceFinalTypes),
            array_merge(
                [$customer_id],
                $guestParams,
                [$branch_id, $reference_id, $groupTotal, $order_status, $initial_payment_status, $payment_method, $posBundleReference, $selectedTransactionAt, $order_type],
                $amountPaidParams,
                $priceFinalParams
            )
        );

        if (!$order_result) {
            $conn->rollback();
            $checkout_stage = 'order_insert';
            echo json_encode([
                'success' => false,
                'stage' => 'order_insert',
                'message' => pos_checkout_safe_db_failure_message('Failed to create order. No sale was saved.'),
            ]);
            exit;
        }

        $order_id = (int)$conn->insert_id;
        pos_checkout_log_stage('order_inserted', [
            'order_id' => $order_id,
            'order_date' => $selectedTransactionAt,
        ]);
        $linkedOrderIds[] = $order_id;
        if ($primaryOrderId === null || $order_type === 'product') {
            $primaryOrderId = $order_id;
        }
        $checkout_stage = 'order_created';

        // Insert order items and update stock for this category-specific order.
        foreach ($groupItems as $item) {
        $product_id = (int)$item['id'];
        $qty = pos_checkout_parse_positive_quantity($item['qty'] ?? null);
        $p = $products_cache[$product_id] ?? null;
        $prod_name = $p['name'] ?? 'Product';

        // Use the name from the cart item if provided (for services)
        $name = $item['name'] ?? $prod_name;
        
        // Detect if this specific item is a service or customized product
        $is_service = pos_payload_item_is_service((array)$item);
        $price = $is_service
            ? (float)($item['price'] ?? ($p['price'] ?? 0))
            : (float)($p['price'] ?? 0);
        if ($is_service && isset($item['line_total_override'])) {
            $price = round(max(0.0, (float)$item['line_total_override']) / max(1, $qty), 2);
        }
        
        $custom_details = $item['customization'] ?? [];
        if (!is_array($custom_details)) $custom_details = [];
        if ($is_service && empty($custom_details['service_id'])) {
            $custom_details['service_id'] = $product_id;
        }
        pos_normalize_media_upload_keys($custom_details);
        if (
            function_exists('printflow_customization_layout_is_without')
            && printflow_customization_layout_is_without($custom_details)
        ) {
            $custom_details = printflow_customization_clear_ephemeral_design_media($custom_details);
        } elseif (function_exists('printflow_service_design_upload_is_active')) {
            require_once __DIR__ . '/../../includes/service_field_config_helper.php';
            $serviceIdForFields = (int)($custom_details['service_id'] ?? $product_id);
            if ($is_service && $serviceIdForFields > 0 && function_exists('get_service_field_config')) {
                $fieldConfigs = get_service_field_config($serviceIdForFields) ?: [];
                if ($fieldConfigs !== []) {
                    $fieldValues = $custom_details;
                    printflow_apply_layout_canonical_to_customization($fieldValues, $fieldConfigs);
                    if (!printflow_service_design_upload_is_active($fieldConfigs, $fieldValues)) {
                        $custom_details = printflow_customization_clear_ephemeral_design_media($custom_details);
                    }
                }
            }
        }
        $hasPersistedDesignPath = pos_customization_has_persisted_media_path($custom_details, 'design_upload');
        $hasPersistedReferencePath = pos_customization_has_persisted_media_path($custom_details, 'reference_upload');
        $designPayload = $hasPersistedDesignPath
            ? ['blob' => null, 'mime' => '', 'name' => 'design_upload']
            : pos_extract_inline_media_payload($custom_details, 'design_upload');
        $referencePayload = $hasPersistedReferencePath
            ? ['blob' => null, 'mime' => '', 'name' => 'reference_upload']
            : pos_extract_inline_media_payload($custom_details, 'reference_upload');

        // Service catalog IDs can overlap product IDs. Only keep product_id for
        // product-backed custom items, not services selected from the Services tab.
        $is_catalog_service = $is_service && (int)($custom_details['service_id'] ?? 0) === $product_id;
        $is_actual_product = !$is_catalog_service && isset($actual_product_ids[$product_id]);
        $order_item_product_id = $is_actual_product ? $product_id : 0;
        if ($order_item_product_id <= 0) {
            if ($service_placeholder_product_id <= 0) {
                $service_placeholder_product_id = pos_get_service_placeholder_product_id();
            }
            $order_item_product_id = $service_placeholder_product_id;
            if ($order_item_product_id <= 0 && isset($actual_product_ids[$product_id])) {
                // Last fallback: use existing product IDs when this service came from products.
                $order_item_product_id = $product_id;
            }
            if ($order_item_product_id <= 0) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Failed to prepare service order item support.']);
                exit;
            }
        }
        
        // Store the service name in customization for proper display
        if ($is_service && $name) {
            $custom_details['service_type'] = $name;
        }
        $custom_details = printflow_customization_normalize_storage($custom_details);
        $custom_details['pos_business_datetime'] = $selectedTransactionAt;

        $customization_json = json_encode($custom_details ?: new stdClass());

        $item_result = db_execute(
            "INSERT INTO order_items (order_id, product_id, quantity, unit_price, customization_data) VALUES (?, ?, ?, ?, ?)",
            'iiids',
            [$order_id, $order_item_product_id, $qty, $price, $customization_json]
        );

        if (!$item_result) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to add order items.']);
            exit;
        }
        
        $order_item_id = $conn->insert_id;
        $designFee = $is_service ? max(0.0, (float)($custom_details['design_fee'] ?? 0)) : 0.0;
        $lineTotalOverride = !empty($item['price_set'])
            ? max(0.0, (float)($item['line_total_override'] ?? $item['price'] ?? 0))
            : null;
        if ($designFee > 0) {
            $savedFee = db_execute('UPDATE order_items SET design_fee = ? WHERE order_item_id = ?', 'di', [$designFee, (int)$order_item_id]);
            if (!$savedFee) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Could not persist the verified design fee. No checkout was completed.']);
                exit;
            }
        }
        if ($lineTotalOverride !== null) {
            $savedOverride = db_execute('UPDATE order_items SET line_total_override = ? WHERE order_item_id = ?', 'di', [$lineTotalOverride, (int)$order_item_id]);
            if (!$savedOverride) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Could not persist the exact Set Price line total. No checkout was completed.']);
                exit;
            }
        }
        $checkout_stage = 'items_inserting';
        $storedMedia = pos_store_order_item_inline_media($order_item_id, $designPayload, $referencePayload);
        pos_apply_stored_media_to_customization($custom_details, $storedMedia);
        if (
            !$hasPersistedDesignPath
            && !empty($designPayload['blob'])
            && function_exists('printflow_embed_design_backup_in_customization')
        ) {
            $custom_details = printflow_embed_design_backup_in_customization(
                $custom_details,
                (string)$designPayload['blob'],
                (string)($designPayload['mime'] ?? ''),
                (string)($designPayload['name'] ?? 'design_upload')
            );
        }
        $custom_details = printflow_customization_normalize_storage($custom_details);
        if ($hasPersistedDesignPath || !empty($storedMedia['design_path'])) {
            printflow_sync_job_orders_artwork_for_order_item((int)$order_item_id);
        }
        $customization_json = json_encode($custom_details ?: new stdClass());
        db_execute(
            'UPDATE order_items SET customization_data = ? WHERE order_item_id = ?',
            'si',
            [$customization_json, $order_item_id]
        );

        if (function_exists('printflow_order_item_design_files_sync_from_customization')) {
            $syncedDesignFiles = printflow_order_item_design_files_sync_from_customization(
                (int)$order_item_id,
                (int)$order_id,
                (int)$customer_id,
                0,
                is_array($custom_details) ? $custom_details : []
            );
            if (
                $syncedDesignFiles !== []
                && function_exists('printflow_customization_apply_synced_design_files')
            ) {
                $custom_details = printflow_customization_apply_synced_design_files($custom_details, $syncedDesignFiles);
                $custom_details = printflow_customization_normalize_storage($custom_details);
                db_execute(
                    'UPDATE order_items SET customization_data = ? WHERE order_item_id = ?',
                    'si',
                    [json_encode($custom_details ?: new stdClass()), $order_item_id]
                );
            }
        }

        // Always create or re-link customization entry for service items
        if ($is_service) {
            $details = $custom_details;
            $details['source'] = 'POS'; // Mark as POS purchase
            $details_json = json_encode($details ?: new stdClass());
            $pendingOrderId = (int)($item['pending_order_id'] ?? 0);
            if ($pendingOrderId <= 0 && isset($pos_pending_orders_snapshot[$product_id])) {
                $pendingOrderId = (int)$pos_pending_orders_snapshot[$product_id];
            }
            $pendingCustomizationId = (int)($item['pending_customization_id'] ?? 0);
            $pendingOrderItemId = 0;
            if ($pendingOrderId <= 0 && $pendingCustomizationId <= 0) {
                $pendingLink = pos_find_pending_service_link($customer_id, $branch_id, $product_id, $name);
                $pendingOrderId = (int)($pendingLink['order_id'] ?? 0);
                if ($pendingCustomizationId <= 0) {
                    $pendingCustomizationId = (int)($pendingLink['customization_id'] ?? 0);
                }
            }
            if ($pendingCustomizationId <= 0 && $pendingOrderId > 0) {
                $pendingCustomizationRows = db_query(
                    "SELECT customization_id, order_item_id
                     FROM customizations
                     WHERE order_id = ?
                     ORDER BY customization_id ASC
                     LIMIT 1",
                    'i',
                    [$pendingOrderId]
                ) ?: [];
                if (!empty($pendingCustomizationRows)) {
                    $pendingCustomizationId = (int)($pendingCustomizationRows[0]['customization_id'] ?? 0);
                    $pendingOrderItemId = (int)($pendingCustomizationRows[0]['order_item_id'] ?? 0);
                }
            }

            $customization_result = false;
            if ($pendingCustomizationId > 0) {
                $customizationExists = db_query(
                    "SELECT customization_id, order_item_id
                     FROM customizations
                     WHERE customization_id = ?
                     LIMIT 1",
                    'i',
                    [$pendingCustomizationId]
                ) ?: [];

                if (!empty($customizationExists)) {
                    $pendingOrderItemId = (int)($customizationExists[0]['order_item_id'] ?? $pendingOrderItemId);
                    if ($pendingOrderItemId > 0) {
                        pos_copy_order_item_media_if_missing($pendingOrderItemId, $order_item_id);
                        if (function_exists('printflow_order_item_design_files_migrate_order_item')) {
                            printflow_order_item_design_files_migrate_order_item(
                                $pendingOrderItemId,
                                (int)$order_item_id,
                                (int)$order_id,
                                $pendingCustomizationId
                            );
                        }
                    }
                    $customizationStatus = $isPayMongo ? 'Awaiting Payment' : 'In Production';
                    $customization_result = db_execute(
                        "UPDATE customizations
                         SET order_id = ?, order_item_id = ?, customer_id = ?, service_type = ?, customization_details = ?, status = ?, updated_at = NOW()
                         WHERE customization_id = ?",
                        'iiisssi',
                        [$order_id, $order_item_id, $customer_id, $name, $details_json, $customizationStatus, $pendingCustomizationId]
                    );
                    if ($customization_result) {
                        $last_customization_id = $pendingCustomizationId;
                    }
                }
            }

            if (!$customization_result && $pendingOrderId > 0) {
                if (!$customization_result) {
                    $existingCustomizationRows = db_query(
                        "SELECT customization_id, order_item_id
                         FROM customizations
                         WHERE order_id = ?
                         ORDER BY customization_id ASC",
                        'i',
                        [$pendingOrderId]
                    ) ?: [];

                    if (!empty($existingCustomizationRows)) {
                        $customizationIds = array_values(array_filter(array_map(static function (array $row): int {
                            return (int)($row['customization_id'] ?? 0);
                        }, $existingCustomizationRows)));
                        if (!empty($customizationIds)) {
                            $firstPendingRow = $existingCustomizationRows[0] ?? [];
                            $pendingOrderItemId = (int)($firstPendingRow['order_item_id'] ?? 0);
                            if ($pendingOrderItemId > 0) {
                                pos_copy_order_item_media_if_missing($pendingOrderItemId, $order_item_id);
                                if (function_exists('printflow_order_item_design_files_migrate_order_item')) {
                                    printflow_order_item_design_files_migrate_order_item(
                                        $pendingOrderItemId,
                                        (int)$order_item_id,
                                        (int)$order_id,
                                        (int)($firstPendingRow['customization_id'] ?? 0)
                                    );
                                }
                            }
                            $placeholders = implode(',', array_fill(0, count($customizationIds), '?'));
                            $customizationStatus = $isPayMongo ? 'Awaiting Payment' : 'In Production';
                            $customization_result = db_execute(
                                "UPDATE customizations
                                 SET order_id = ?, order_item_id = ?, customer_id = ?, service_type = ?, customization_details = ?, status = ?, updated_at = NOW()
                                 WHERE customization_id IN ($placeholders)",
                                'iiisss' . str_repeat('i', count($customizationIds)),
                                array_merge([$order_id, $order_item_id, $customer_id, $name, $details_json, $customizationStatus], $customizationIds)
                            );
                            $last_customization_id = $customizationIds[0] ?? null;
                        }
                    }
                }
            }

            if (!$customization_result) {
                $customizationStatus = $isPayMongo ? 'Awaiting Payment' : 'In Production';
                $customization_result = db_execute(
                    "INSERT INTO customizations (order_id, order_item_id, customer_id, service_type, customization_details, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    'iiisssss',
                    [$order_id, $order_item_id, $customer_id, $name, $details_json, $customizationStatus, $selectedTransactionAt, $selectedTransactionAt]
                );
                if ($customization_result) {
                    $last_customization_id = $conn->insert_id;
                }
            }

            if (!$customization_result) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Failed to create customization entry.']);
                exit;
            }
            if ($pendingOrderId <= 0) {
                error_log(sprintf(
                    'PrintFlow POS checkout warning: missing pending_order_id for service item product_id=%d on final order #%d',
                    $product_id,
                    $order_id
                ));
            }
            $post_commit_job_sync[] = [
                'order_id' => (int)$order_id,
                'status' => 'PENDING',
                'pending_order_id' => $pendingOrderId
            ];
            
            // Update any existing pending customization orders for this item to mark as paid
            if ($pendingOrderId > 0) {
                $pendingPriceFinalSql = '';
                $pendingPriceFinalTypes = '';
                $pendingPriceFinalParams = [];
                if ($ordersHasPriceFinalized) {
                    $pendingPriceFinalSql = ',
                         price_finalized_at = COALESCE(price_finalized_at, NOW()),
                         price_finalized_by = COALESCE(price_finalized_by, ?)';
                    $pendingPriceFinalTypes = 'i';
                    $pendingPriceFinalParams[] = $checkoutStaffId;
                }
                db_execute(
                    "UPDATE orders
                     SET payment_status = 'Unpaid',
                         payment_method = ?,
                         status = 'Cancelled',
                         order_source = 'pos_merged',
                         order_date = ?,
                         updated_at = NOW()
                         {$pendingPriceFinalSql}
                     WHERE order_id = ?",
                    's' . $pendingPriceFinalTypes . 'i',
                    array_merge(
                        [$payment_method, $selectedTransactionAt],
                        $pendingPriceFinalParams,
                        [$pendingOrderId]
                    )
                );
                // Hide the temporary POS source order after its customization is re-linked.
                unset($pos_pending_orders_snapshot[$product_id]);
            }
        }

        // Ready-made product lines deduct immediately at POS checkout. Service/custom
        // items continue through the job/material completion flow.
        $current_user_id = $checkout_actor_user_id;
        if (!$isPayMongo && !$is_service && $is_actual_product) {
            try {
                pos_checkout_log_stage('inventory_deduction_started', [
                    'order_id' => $order_id,
                    'order_item_id' => $order_item_id,
                    'product_id' => $product_id,
                    'quantity' => $qty,
                    'branch_id' => $branch_id,
                    'transaction_date' => $selectedTransactionAt,
                ]);
                $inventoryResult = printflow_apply_product_order_item_inventory(
                    (int)$order_item_id,
                    (int)$branch_id,
                    $current_user_id,
                    'POS sale',
                    $selectedTransactionAt
                );
                pos_checkout_log_stage('inventory_deduction_finished', [
                    'order_id' => $order_id,
                    'order_item_id' => $order_item_id,
                    'product_id' => $product_id,
                    'quantity' => $qty,
                    'branch_id' => $branch_id,
                    'transaction_date' => $selectedTransactionAt,
                    'applied' => !empty($inventoryResult['applied']) ? 1 : 0,
                    'already_applied' => !empty($inventoryResult['already_applied']) ? 1 : 0,
                ]);
                $checkout_stage = 'inventory_deducted';
            } catch (Throwable $inventoryError) {
                pos_checkout_log_stage('inventory_deduction_failed', [
                    'order_id' => $order_id,
                    'order_item_id' => $order_item_id,
                    'product_id' => $product_id,
                    'quantity' => $qty,
                    'branch_id' => $branch_id,
                    'transaction_date' => $selectedTransactionAt,
                    'error' => $inventoryError->getMessage(),
                ]);
                throw new RuntimeException('Failed to deduct stock for ' . $prod_name . ': ' . $inventoryError->getMessage(), 409, $inventoryError);
            }
        }
        }

        if ($order_type === 'custom' && !empty($order_id)) {
            try {
                db_execute(
                    "UPDATE job_orders SET status = 'PENDING', updated_at = NOW() WHERE order_id = ? AND status NOT IN ('COMPLETED', 'CANCELLED')",
                    'i',
                    [(int)$order_id]
                );
            } catch (Throwable $forceSyncError) {
                error_log('PrintFlow POS forced pending sync warning for order #' . (int)$order_id . ': ' . $forceSyncError->getMessage());
            }
        }
    }

    $order_id = $primaryOrderId;

    $checkout_stage = 'commit';
    pos_checkout_log_stage('commit', ['order_id' => (int)($order_id ?? 0)]);
    if (!$conn->commit()) {
        throw new RuntimeException('Could not commit the POS sale.');
    }
    $transaction_open = false;
    $checkout_committed = true;
    $checkout_stage = 'committed';
    if ($checkout_branch_id > 0) {
        require_once __DIR__ . '/../../includes/staff_dashboard_cache.php';
        printflow_staff_dashboard_cache_invalidate_branch((int)$checkout_branch_id);
    }
    pos_checkout_align_pos_order_timestamps($linkedOrderIds, $selectedTransactionAt);
    $sync_warning = '';
    foreach ($post_commit_job_sync as $syncMeta) {
        if (!is_array($syncMeta)) {
            continue;
        }
        $syncOrderId = (int)($syncMeta['order_id'] ?? 0);
        $syncStatus = (string)($syncMeta['status'] ?? '');
        $pendingOrderId = (int)($syncMeta['pending_order_id'] ?? 0);
        try {
            if ($pendingOrderId > 0) {
                pos_migrate_pending_assignments_to_order($pendingOrderId, $syncOrderId);
            }
            pos_sync_customization_jobs_after_commit($syncOrderId, $syncStatus);
        } catch (Throwable $syncError) {
            $sync_warning = 'Sale completed, but production sync needs follow-up.';
            error_log('PrintFlow POS checkout sync warning for order #' . $syncOrderId . ': ' . $syncError->getMessage());
        }
    }

    if ($isPayMongo) {
        $providerResult = printflow_provider_payment_create_qrph(
            'order',
            (int)$order_id,
            'pos',
            $checkoutStaffId
        );
        pos_checkout_persist_session_state(
            $checkoutToken,
            (int)$order_id,
            $pos_pending_orders_snapshot,
            null,
            true
        );
        if (empty($providerResult['ok'])) {
            http_response_code((int)($providerResult['http_status'] ?? 502));
            echo json_encode([
                'success' => false,
                'order_id' => (int)$order_id,
                'payment_pending' => true,
                'message' => $providerResult['message'] ?? 'The order was saved, but its PayMongo payment could not be created. Retry checkout to reuse this order.',
            ]);
            exit;
        }
        echo json_encode([
            'success' => true,
            'order_id' => (int)$order_id,
            'customization_id' => $last_customization_id ?? null,
            'payment_pending' => true,
            'message' => 'Order saved. Complete the PayMongo payment before issuing a receipt.',
            'payment' => $providerResult['payment'] ?? null,
            'receipt' => null,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $receipt = pos_build_receipt_payload((int)$order_id, (float)$amount_tendered, $linkedOrderIds);
    pos_checkout_log_stage('receipt_built', [
        'order_id' => (int)$order_id,
        'order_date' => $selectedTransactionAt,
        'receipt_date' => (string)($receipt['date_time'] ?? ''),
    ]);
    pos_checkout_persist_session_state(
        $checkoutToken,
        (int)$order_id,
        $pos_pending_orders_snapshot,
        $receipt,
        false
    );
    echo json_encode([
        'success' => true,
        'order_id' => $order_id,
        // This is the one canonical POS transaction timestamp.  Receipt data
        // is built from the saved order_date using the same value.
        'order_date' => $selectedTransactionAt,
        'transaction_date' => $selectedTransactionAt,
        'customization_id' => $last_customization_id ?? null,
        'message' => 'Sale completed successfully.',
        'warning' => $sync_warning,
        'receipt' => $receipt,
        'print_job' => null
    ]);

} catch (Throwable $e) {
    if ($transaction_open && isset($conn)) {
        $conn->rollback();
        $transaction_open = false;
    }
    error_log(sprintf(
        'PrintFlow POS checkout failed stage=%s elapsed_ms=%d error=%s',
        (string)$checkout_stage,
        (int)round((microtime(true) - $checkout_started_at) * 1000),
        $e->getMessage()
    ));
    if (!empty($order_id) && $checkout_committed) {
        error_log('PrintFlow POS checkout post-commit sync failed for order #' . (int)$order_id . ': ' . $e->getMessage());
        try {
            $receipt = pos_build_receipt_payload((int)$order_id, (float)$amount_tendered, $linkedOrderIds);
            pos_checkout_persist_session_state(
                $checkoutToken,
                (int)$order_id,
                $pos_pending_orders_snapshot,
                $receipt,
                $isPayMongo
            );
            echo json_encode([
                'success' => true,
                'order_id' => (int)$order_id,
                'order_date' => $selectedTransactionAt,
                'transaction_date' => $selectedTransactionAt,
                'customization_id' => $last_customization_id ?? null,
                'message' => 'Sale completed successfully.',
                'warning' => 'Production sync needs follow-up.',
                'receipt' => $receipt,
                'print_job' => null
            ]);
        } catch (Throwable $receiptError) {
            error_log('PrintFlow POS checkout receipt recovery failed for order #' . (int)$order_id . ': ' . $receiptError->getMessage());
            pos_checkout_persist_session_state(
                $checkoutToken,
                (int)$order_id,
                $pos_pending_orders_snapshot,
                null,
                $isPayMongo
            );
            echo json_encode([
                'success' => true,
                'order_id' => (int)$order_id,
                'customization_id' => $last_customization_id ?? null,
                'message' => 'Sale completed successfully.',
                'warning' => 'Receipt details could not be loaded. Order #' . (int)$order_id . ' was saved.',
                'receipt' => null,
                'print_job' => null
            ]);
        }
    } else {
        $isInventoryFailure = (int)$e->getCode() === 409
            && str_starts_with($e->getMessage(), 'Failed to deduct stock for ');
        http_response_code($isInventoryFailure ? 409 : 500);
        echo json_encode([
            'success' => false,
            'stage' => (string)$checkout_stage,
            'message' => $isInventoryFailure
                ? $e->getMessage()
                : 'Unable to complete the sale. No order or stock movement was saved.',
        ]);
    }
}




