<?php
/**
 * Admin-only historical ready-made transaction entry.
 *
 * Phase 1/2 historical entry: ready-made product sales and custom/service
 * orders. Legacy service_orders are intentionally not used here.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/product_branch_stock.php';
require_once __DIR__ . '/../includes/product_option_stock.php';
require_once __DIR__ . '/../includes/pos_customer_helpers.php';
require_once __DIR__ . '/../includes/JobOrderService.php';
require_once __DIR__ . '/../includes/historical_admin_tools_guard.php';

require_role('Admin');
printflow_block_historical_admin_tool();

if (!isset($base_path)) {
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

global $conn;
$current_user = get_logged_in_user();
$page_title = 'Historical Transaction Entry - Admin';
$error = '';
$success = '';
$receipt = null;

function historical_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function historical_old(array $source, string $key, string $default = ''): string
{
    $value = $source[$key] ?? $default;
    return historical_h(is_scalar($value) ? (string)$value : $default);
}

function historical_redirect_token(): string
{
    return bin2hex(random_bytes(24));
}

function historical_validate_datetime(string $date, string $time): string
{
    $date = trim($date);
    $time = trim($time);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        throw new RuntimeException('Enter a valid historical date and time.');
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $time . ':00');
    $errors = DateTimeImmutable::getLastErrors();
    if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new RuntimeException('Enter a real calendar date and time.');
    }

    $now = new DateTimeImmutable('now');
    if ($parsed > $now) {
        throw new RuntimeException('Historical transactions cannot use a future date or time.');
    }

    return $parsed->format('Y-m-d H:i:s');
}

function historical_table_ready(string $transactionType = 'ready'): bool
{
    $required = [
        ['orders', 'customer_id'],
        ['orders', 'branch_id'],
        ['orders', 'reference_id'],
        ['orders', 'total_amount'],
        ['orders', 'status'],
        ['orders', 'payment_status'],
        ['orders', 'payment_method'],
        ['orders', 'payment_reference'],
        ['orders', 'order_date'],
        ['orders', 'updated_at'],
        ['orders', 'notes'],
        ['orders', 'pos_guest_display_name'],
        ['orders', 'pos_guest_email'],
        ['order_items', 'customization_data'],
        ['inventory_transactions', 'transaction_date'],
        ['inventory_transactions', 'transaction_id'],
    ];
    foreach ($required as [$table, $column]) {
        if (!db_table_has_column($table, $column)) {
            return false;
        }
    }
    if ($transactionType === 'custom') {
        $customRequired = [
            ['customizations', 'order_id'],
            ['customizations', 'order_item_id'],
            ['customizations', 'customer_id'],
            ['customizations', 'service_type'],
            ['customizations', 'customization_details'],
            ['customizations', 'status'],
            ['customizations', 'created_at'],
            ['customizations', 'updated_at'],
            ['job_orders', 'order_id'],
            ['job_orders', 'order_item_id'],
            ['job_orders', 'customer_id'],
            ['job_orders', 'branch_id'],
            ['job_orders', 'job_title'],
            ['job_orders', 'service_type'],
            ['job_orders', 'quantity'],
            ['job_orders', 'estimated_total'],
            ['job_orders', 'required_payment'],
            ['job_orders', 'notes'],
            ['job_orders', 'due_date'],
            ['job_orders', 'priority'],
            ['job_orders', 'artwork_path'],
            ['job_orders', 'payment_status'],
            ['job_orders', 'created_by'],
        ];
        foreach ($customRequired as [$table, $column]) {
            if (!db_table_has_column($table, $column)) {
                return false;
            }
        }
    }
    return true;
}

function historical_products(): array
{
    if (!db_table_has_column('products', 'product_type')) {
        return [];
    }

    return db_query(
        "SELECT product_id, name, price, product_type, status
         FROM products
         WHERE LOWER(TRIM(product_type)) = 'fixed'
           AND LOWER(TRIM(COALESCE(status, ''))) NOT IN ('archived', 'inactive')
         ORDER BY name ASC",
    ) ?: [];
}

function historical_services(): array
{
    if (!db_table_has_column('services', 'service_id')) {
        return [];
    }

    return db_query(
        "SELECT service_id, name, category, COALESCE(price, 0) AS base_price
         FROM services
         WHERE status = 'Activated'
         ORDER BY name ASC",
    ) ?: [];
}

function historical_service_type(array $service): string
{
    $serviceType = JobOrderService::inferServiceTypeFromProduct(
        (string)($service['category'] ?? ''),
        (string)($service['name'] ?? '')
    );
    if ($serviceType === '') {
        throw new RuntimeException('This service has no supported production service type configured.');
    }
    return $serviceType;
}

function historical_service_placeholder_product_id(): int
{
    $rows = db_query("SELECT product_id FROM products WHERE sku = 'POS-SERVICE' LIMIT 1") ?: [];
    $productId = (int)($rows[0]['product_id'] ?? 0);
    if ($productId <= 0) {
        throw new RuntimeException('The existing POS service placeholder product is missing. Prepare the normal POS service setup before using historical custom orders.');
    }
    return $productId;
}

function historical_custom_item_from_post(array $post, array $servicesById): array
{
    $serviceId = (int)($post['custom_service_id'] ?? 0);
    if ($serviceId <= 0 || !isset($servicesById[$serviceId])) {
        throw new RuntimeException('Select a valid active custom/service item.');
    }

    $quantityRaw = trim((string)($post['custom_quantity'] ?? ''));
    if ($quantityRaw === '' || !preg_match('/^[1-9]\d*$/', $quantityRaw)) {
        throw new RuntimeException('Custom/service quantity must be a whole number of at least 1.');
    }
    $quantity = (int)$quantityRaw;
    if ($quantity > 2147483647) {
        throw new RuntimeException('Custom/service quantity is too large.');
    }

    $priceRaw = trim((string)($post['custom_price'] ?? ''));
    if ($priceRaw === '' || !is_numeric($priceRaw) || (float)$priceRaw < 0) {
        throw new RuntimeException('Enter a valid final custom/service price.');
    }
    $specifications = trim((string)($post['custom_specifications'] ?? ''));
    if ($specifications === '') {
        throw new RuntimeException('Enter the required specifications or options for the custom/service order.');
    }
    if (strlen($specifications) > 4000) {
        throw new RuntimeException('Specifications must be 4,000 characters or fewer.');
    }

    $service = $servicesById[$serviceId];
    $dimensions = trim((string)($post['custom_dimensions'] ?? ''));
    if ($dimensions !== '' && strlen($dimensions) > 80) {
        throw new RuntimeException('Dimensions must be 80 characters or fewer.');
    }
    $customization = [
        'source' => 'HISTORICAL_POS',
        'source_page' => 'historical_transactions',
        'form_type' => 'historical',
        'service_id' => $serviceId,
        'service_type' => (string)$service['name'],
        'product_type' => (string)$service['name'],
        'specifications' => $specifications,
    ];
    if ($dimensions !== '') {
        $customization['dimensions'] = $dimensions;
    }

    return [
        'product_id' => historical_service_placeholder_product_id(),
        'service_id' => $serviceId,
        'service_name' => (string)$service['name'],
        'service_type' => historical_service_type($service),
        'quantity' => $quantity,
        'unit_price' => round((float)$priceRaw, 2),
        'customization' => $customization,
        'specifications' => $specifications,
    ];
}

function historical_product_options(int $productId, int $branchId): array
{
    if ($productId <= 0 || $branchId <= 0 || !printflow_product_option_stock_has_rows($productId, $branchId)) {
        return [];
    }
    return printflow_product_option_stock_map($productId, $branchId);
}

function historical_items_from_post(array $post, int $branchId, array $productsById): array
{
    $productIds = $post['product_id'] ?? [];
    $variants = $post['variant'] ?? [];
    $quantities = $post['quantity'] ?? [];
    $prices = $post['unit_price'] ?? [];
    if (!is_array($productIds) || !is_array($quantities) || !is_array($prices)) {
        throw new RuntimeException('Add at least one ready-made product.');
    }

    $items = [];
    foreach ($productIds as $index => $rawProductId) {
        $productId = (int)$rawProductId;
        if ($productId <= 0) {
            continue;
        }
        if (!isset($productsById[$productId])) {
            throw new RuntimeException('One selected product is not a ready-made product.');
        }

        $quantityRaw = trim((string)($quantities[$index] ?? ''));
        if ($quantityRaw === '' || !preg_match('/^[1-9]\d*$/', $quantityRaw)) {
            throw new RuntimeException('Each quantity must be a whole number of at least 1.');
        }
        $quantity = (int)$quantityRaw;
        if ($quantity > 2147483647) {
            throw new RuntimeException('Quantity is too large.');
        }

        $priceRaw = trim((string)($prices[$index] ?? ''));
        if ($priceRaw === '' || !is_numeric($priceRaw) || (float)$priceRaw < 0) {
            throw new RuntimeException('Each unit price must be zero or greater.');
        }
        $unitPrice = round((float)$priceRaw, 2);
        $variant = trim((string)($variants[$index] ?? ''));

        $customization = [];
        if ($variant !== '') {
            $field = printflow_product_resolve_stock_field_config($productId);
            if ($field !== null) {
                $customization[(string)$field['field_key']] = $variant;
                $customization[(string)$field['field_label']] = $variant;
            } else {
                $customization['variant'] = $variant;
            }
        }

        $prepared = printflow_product_option_stock_prepare_cart_customization(
            $productId,
            $branchId,
            $customization,
            $quantity,
            (string)$productsById[$productId]['name']
        );
        if (empty($prepared['ok'])) {
            throw new RuntimeException((string)($prepared['message'] ?? 'The selected product option is unavailable.'));
        }

        if (empty($prepared['customization']) && $variant !== '') {
            $prepared['customization'] = ['variant' => $variant];
        }

        if (printflow_product_option_stock_has_rows($productId, $branchId)
            && empty($prepared['customization'])) {
            throw new RuntimeException('Select a valid variant or size for ' . $productsById[$productId]['name'] . '.');
        }

        [$available] = printflow_product_effective_stock($productId, $branchId);
        if (!printflow_product_option_stock_has_rows($productId, $branchId) && $available < $quantity) {
            throw new RuntimeException('Insufficient current stock for ' . $productsById[$productId]['name'] . '. Available: ' . $available . '.');
        }

        $items[] = [
            'product_id' => $productId,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'customization' => is_array($prepared['customization'] ?? null) ? $prepared['customization'] : [],
        ];
    }

    if ($items === []) {
        throw new RuntimeException('Add at least one ready-made product.');
    }
    return $items;
}

function historical_insert_parent_order(array $data, float $total, string $businessAt, string $reason, string $token, string $orderType): array
{
    global $conn;
    $guest = printflow_pos_resolve_checkout_customer_context([
        'customer_id' => 'guest',
        'guest_display_name' => $data['guest_name'] ?? '',
        'customer_email' => $data['guest_email'] ?? '',
    ]);
    $customerId = (int)$guest['customer_id'];
    $operatorId = (int)get_user_id();
    $auditNote = '[Historical transaction] Entered by user #' . $operatorId . ' on ' . date('Y-m-d H:i:s')
        . '. Business date: ' . $businessAt . '. Reason: ' . trim($reason);

    $orderStatus = $orderType === 'custom' ? 'Pending' : 'Completed';
    $columns = ['customer_id', 'pos_guest_display_name', 'pos_guest_email', 'branch_id', 'reference_id', 'total_amount', 'status', 'payment_status', 'payment_method', 'payment_reference', 'order_date', 'notes'];
    $values = [$customerId, $guest['pos_guest_display_name'], $guest['pos_guest_email'], (int)$data['branch_id'], 'HIST-' . $token, round($total, 2), $orderStatus, 'Paid', 'Cash', 'HIST-' . $token, $businessAt, $auditNote];
    $types = 'issisdssssss';
    $placeholders = array_fill(0, count($columns), '?');

    if (db_table_has_column('orders', 'amount_paid')) {
        $columns[] = 'amount_paid';
        $placeholders[] = '?';
        $values[] = $total;
        $types .= 'd';
    }
    if (db_table_has_column('orders', 'order_type')) {
        $columns[] = 'order_type';
        $placeholders[] = '?';
        $values[] = $orderType;
        $types .= 's';
    }
    if (db_table_has_column('orders', 'order_source')) {
        $columns[] = 'order_source';
        $placeholders[] = '?';
        $values[] = 'pos';
        $types .= 's';
    }

    $sql = 'INSERT INTO orders (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    if (!db_execute($sql, $types, $values)) {
        throw new RuntimeException('The historical order could not be created.');
    }
    $orderId = (int)$conn->insert_id;
    if ($orderId <= 0) {
        throw new RuntimeException('The historical order ID was not returned.');
    }
    if (!db_execute('UPDATE orders SET updated_at = NOW() WHERE order_id = ?', 'i', [$orderId])) {
        throw new RuntimeException('The historical order timestamp could not be recorded.');
    }

    return ['order_id' => $orderId, 'customer_id' => $customerId];
}

function historical_insert_order(array $data, array $items, string $businessAt, string $reason, string $token): int
{
    global $conn;
    $total = 0.0;
    foreach ($items as $item) {
        $total += (float)$item['unit_price'] * (int)$item['quantity'];
    }
    $parent = historical_insert_parent_order($data, round($total, 2), $businessAt, $reason, $token, 'product');
    $orderId = (int)$parent['order_id'];
    $operatorId = (int)get_user_id();

    foreach ($items as $item) {
        $customization = $item['customization'];
        $customization['source'] = 'HISTORICAL_POS';
        $customizationJson = json_encode($customization ?: new stdClass(), JSON_UNESCAPED_SLASHES);
        if ($customizationJson === false) {
            throw new RuntimeException('A product variant could not be saved.');
        }
        $itemResult = db_execute(
            'INSERT INTO order_items (order_id, product_id, quantity, unit_price, customization_data) VALUES (?, ?, ?, ?, ?)',
            'iiids',
            [$orderId, (int)$item['product_id'], (int)$item['quantity'], (float)$item['unit_price'], $customizationJson]
        );
        if (!$itemResult) {
            throw new RuntimeException('A historical order item could not be created.');
        }
        $orderItemId = (int)$conn->insert_id;
        printflow_apply_product_order_item_inventory(
            $orderItemId,
            (int)$data['branch_id'],
            $operatorId,
            'Historical POS sale',
            $businessAt
        );
    }

    return $orderId;
}

function historical_insert_custom_order(array $data, array $item, string $businessAt, string $reason, string $token): array
{
    global $conn;
    $total = round((float)$item['unit_price'] * (int)$item['quantity'], 2);
    $parent = historical_insert_parent_order($data, $total, $businessAt, $reason, $token, 'custom');
    $orderId = (int)$parent['order_id'];
    $customerId = (int)$parent['customer_id'];
    $customization = is_array($item['customization'] ?? null) ? $item['customization'] : [];
    $customizationJson = json_encode($customization, JSON_UNESCAPED_SLASHES);
    if ($customizationJson === false) {
        throw new RuntimeException('The custom/service specifications could not be saved.');
    }

    $itemResult = db_execute(
        'INSERT INTO order_items (order_id, product_id, quantity, unit_price, customization_data) VALUES (?, ?, ?, ?, ?)',
        'iiids',
        [$orderId, (int)$item['product_id'], (int)$item['quantity'], (float)$item['unit_price'], $customizationJson]
    );
    if (!$itemResult) {
        throw new RuntimeException('The historical custom/service order item could not be created.');
    }
    $orderItemId = (int)$conn->insert_id;
    if ($orderItemId <= 0) {
        throw new RuntimeException('The historical custom/service order item ID was not returned.');
    }

    $customizationResult = db_execute(
        'INSERT INTO customizations (order_id, order_item_id, customer_id, service_type, customization_details, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
        'iiisss',
        [$orderId, $orderItemId, $customerId, (string)$item['service_name'], $customizationJson, 'In Production']
    );
    if (!$customizationResult) {
        throw new RuntimeException('The historical customization record could not be created.');
    }

    $widthFt = 0.0;
    $heightFt = 0.0;
    $dimensions = trim((string)($customization['dimensions'] ?? ''));
    if ($dimensions !== '' && preg_match('/^\s*(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)\s*$/u', $dimensions, $dimensionMatch)) {
        $widthFt = (float)$dimensionMatch[1];
        $heightFt = (float)$dimensionMatch[2];
    }

    // Reuse the existing job factory. Passing no materials deliberately keeps
    // assignment and material/ink deductions in the normal production flow.
    $jobId = JobOrderService::createOrder([
        'order_id' => $orderId,
        'order_item_id' => $orderItemId,
        'customer_id' => $customerId,
        'branch_id' => (int)$data['branch_id'],
        'job_title' => (string)$item['service_name'],
        'service_type' => (string)$item['service_type'],
        'width_ft' => $widthFt,
        'height_ft' => $heightFt,
        'quantity' => (int)$item['quantity'],
        'total_sqft' => $widthFt * $heightFt * (int)$item['quantity'],
        'price_per_sqft' => null,
        'price_per_piece' => null,
        'estimated_total' => $total,
        'notes' => trim($reason),
        'due_date' => null,
        'priority' => 'NORMAL',
        'artwork_path' => null,
        'created_by' => (int)get_user_id(),
    ], []);
    if ((int)$jobId <= 0) {
        throw new RuntimeException('The linked historical production job could not be created.');
    }

    return ['order_id' => $orderId, 'job_id' => (int)$jobId, 'business_at' => $businessAt];
}

$branches = db_query("SELECT id, branch_name FROM branches WHERE status != 'Archived' ORDER BY branch_name ASC") ?: [];
$products = historical_products();
$services = historical_services();
$productsById = [];
foreach ($products as $product) {
    $productsById[(int)$product['product_id']] = $product;
}
$servicesById = [];
foreach ($services as $service) {
    $servicesById[(int)$service['service_id']] = $service;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = trim((string)($_POST['submission_token'] ?? ''));
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid security token. Refresh the page and try again.');
        }
        if (!preg_match('/^[a-f0-9]{48}$/', $postedToken)) {
            throw new RuntimeException('Invalid submission token. Refresh the page and try again.');
        }
        $transactionType = (string)($_POST['transaction_type'] ?? 'ready');
        if (!in_array($transactionType, ['ready', 'custom'], true)) {
            throw new RuntimeException('Select a valid historical transaction type.');
        }
        if (!historical_table_ready($transactionType)) {
            throw new RuntimeException('Required POS guest/order/inventory fields are missing. Apply the existing POS schema migration before using historical entry.');
        }
        if (isset($_SESSION['historical_transaction_tokens'][$postedToken])) {
            $existingId = (int)$_SESSION['historical_transaction_tokens'][$postedToken];
            throw new RuntimeException('This submission was already processed as Order #' . $existingId . '.');
        }

        $branchId = (int)($_POST['branch_id'] ?? 0);
        $branchRows = db_query("SELECT id FROM branches WHERE id = ? AND status != 'Archived' LIMIT 1", 'i', [$branchId]) ?: [];
        if ($branchRows === []) {
            throw new RuntimeException('Select a valid active branch.');
        }
        $guestName = printflow_pos_sanitize_guest_display_name($_POST['guest_name'] ?? '');
        if ($guestName === '') {
            throw new RuntimeException('Enter the walk-in customer name.');
        }
        $guestEmail = printflow_pos_normalize_customer_email($_POST['guest_email'] ?? '');
        if ($guestEmail !== '' && !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid walk-in email address.');
        }
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($reason === '') {
            throw new RuntimeException('Enter a reason for the historical entry.');
        }
        if (strlen($reason) > 1000) {
            throw new RuntimeException('The reason must be 1,000 characters or fewer.');
        }
        $businessAt = historical_validate_datetime((string)($_POST['historical_date'] ?? ''), (string)($_POST['historical_time'] ?? ''));
        $operatorId = (int)get_user_id();

        $conn->begin_transaction();
        try {
            $customerData = [
                'branch_id' => $branchId,
                'guest_name' => $guestName,
                'guest_email' => $guestEmail,
            ];
            if ($transactionType === 'custom') {
                $customItem = historical_custom_item_from_post($_POST, $servicesById);
                $customReceipt = historical_insert_custom_order($customerData, $customItem, $businessAt, $reason, $postedToken);
                $orderId = (int)$customReceipt['order_id'];
                $receipt = ['order_id' => $orderId, 'job_id' => (int)$customReceipt['job_id'], 'business_at' => $businessAt];
            } else {
                $items = historical_items_from_post($_POST, $branchId, $productsById);
                $orderId = historical_insert_order($customerData, $items, $businessAt, $reason, $postedToken);
                $receipt = ['order_id' => $orderId, 'business_at' => $businessAt];
            }
            if (!$conn->commit()) {
                throw new RuntimeException('The historical transaction could not be committed.');
            }
        } catch (Throwable $transactionError) {
            if (printflow_db_in_transaction($conn)) {
                $conn->rollback();
            }
            throw $transactionError;
        }

        if (!isset($_SESSION['historical_transaction_tokens']) || !is_array($_SESSION['historical_transaction_tokens'])) {
            $_SESSION['historical_transaction_tokens'] = [];
        }
        $_SESSION['historical_transaction_tokens'][$postedToken] = $orderId;
        $_SESSION['historical_transaction_tokens'] = array_slice($_SESSION['historical_transaction_tokens'], -50, null, true);
        $jobAudit = isset($receipt['job_id']) ? ' | job #' . (int)$receipt['job_id'] : '';
        log_activity($operatorId, 'Historical transaction entered', 'Order #' . $orderId . $jobAudit . ' | business date ' . $businessAt . ' | reason: ' . $reason);
        $success = $transactionType === 'custom'
            ? 'Historical custom/service transaction saved as Order #' . $orderId . '. Materials and ink remain on the normal production workflow.'
            : 'Historical transaction saved as Order #' . $orderId . '. Current branch stock was deducted.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$defaultDate = date('Y-m-d');
$defaultTime = date('H:i');
$submissionToken = historical_redirect_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo historical_h($page_title); ?></title>
    <link rel="stylesheet" href="<?php echo historical_h($base_path); ?>/public/assets/css/output.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        .history-shell { max-width: 1120px; margin: 0 auto; padding: 28px; }
        .history-panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; box-shadow: 0 8px 24px rgba(15, 23, 42, .06); }
        .history-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .history-field { display: flex; flex-direction: column; gap: 7px; }
        .history-field.full { grid-column: 1 / -1; }
        .history-field label { font-weight: 700; color: #334155; font-size: 13px; }
        .history-field input, .history-field select, .history-field textarea { width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; color: #0f172a; background: #fff; }
        .history-field textarea { min-height: 90px; resize: vertical; }
        .history-notice { background: #eff6ff; border-left: 4px solid #2563eb; color: #1e3a8a; padding: 14px 16px; margin-bottom: 20px; border-radius: 4px; }
        .history-warning { background: #fff7ed; border-left: 4px solid #ea580c; color: #9a3412; padding: 14px 16px; margin: 16px 0 22px; border-radius: 4px; }
        .history-message { padding: 12px 14px; margin-bottom: 18px; border-radius: 6px; font-weight: 600; }
        .history-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .history-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .history-products { border-top: 1px solid #e2e8f0; margin-top: 22px; padding-top: 20px; }
        .history-row { display: grid; grid-template-columns: 2fr 1.25fr .75fr 1fr 38px; gap: 10px; align-items: end; margin-bottom: 10px; }
        .history-row .remove-row { height: 40px; border: 1px solid #fecaca; background: #fff1f2; color: #be123c; border-radius: 6px; cursor: pointer; font-weight: 700; }
        .history-total { text-align: right; font-weight: 800; color: #0f172a; font-size: 18px; margin: 16px 0; }
        .history-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; }
        .history-actions button, .history-add { border: 0; border-radius: 6px; padding: 11px 17px; font-weight: 700; cursor: pointer; }
        .history-add { background: #e0f2fe; color: #075985; }
        .history-submit { background: #0f766e; color: #fff; }
        .history-submit:hover { background: #115e59; }
        .history-mode { border-top: 1px solid #e2e8f0; margin-top: 22px; padding-top: 20px; }
        .history-custom-note { background: #f8fafc; border: 1px solid #cbd5e1; color: #334155; padding: 12px 14px; border-radius: 6px; margin-bottom: 16px; }
        @media (max-width: 760px) { .history-shell { padding: 16px; } .history-grid { grid-template-columns: 1fr; } .history-field.full { grid-column: auto; } .history-row { grid-template-columns: 1fr 1fr; } .history-row .remove-row { grid-column: 2; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/' . (($current_user['role'] ?? '') === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>
    <main class="main-content">
        <div class="history-shell">
            <h1 style="font-size:28px;font-weight:800;color:#0f172a;margin:0 0 18px;">Historical Transaction Entry</h1>
            <div class="history-panel">
                <div class="history-notice">Use this page only for real historical business transactions. Ready-made stock is deducted from current inventory; custom/service materials follow the normal production workflow.</div>
                <div class="history-warning"><strong>Historical ready-made entries affect current stock.</strong> Enter transactions from oldest to newest.</div>
                <?php if ($error !== ''): ?><div class="history-message history-error"><?php echo historical_h($error); ?></div><?php endif; ?>
                <?php if ($success !== ''): ?><div class="history-message history-success"><?php echo historical_h($success); ?><?php if ($receipt): ?> <a href="<?php echo historical_h($base_path); ?>/admin/orders_management.php?order_id=<?php echo (int)$receipt['order_id']; ?>" style="text-decoration:underline;">View order</a><?php endif; ?></div><?php endif; ?>
                <?php if ($products === []): ?><div class="history-message history-error">No ready-made products are available, or the product type schema is not ready. No transaction can be entered.</div><?php endif; ?>

                <form method="post" id="historicalTransactionForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="submission_token" value="<?php echo historical_h($submissionToken); ?>">
                    <div class="history-grid">
                        <div class="history-field full"><label for="transaction_type">Transaction Type *</label><select id="transaction_type" name="transaction_type" required><option value="ready" <?php echo $postedTransactionType === 'ready' ? 'selected' : ''; ?>>Ready-Made Product</option><option value="custom" <?php echo $postedTransactionType === 'custom' ? 'selected' : ''; ?>>Custom/Service Order</option></select></div>
                        <div class="history-field"><label for="branch_id">Branch *</label><select id="branch_id" name="branch_id" required><option value="">Select branch</option><?php foreach ($branches as $branch): ?><option value="<?php echo (int)$branch['id']; ?>" <?php echo ((string)($_POST['branch_id'] ?? '') === (string)$branch['id']) ? 'selected' : ''; ?>><?php echo historical_h((string)$branch['branch_name']); ?></option><?php endforeach; ?></select></div>
                        <div class="history-field"><label for="historical_date">Historical Date *</label><input id="historical_date" name="historical_date" type="date" value="<?php echo historical_old($_POST, 'historical_date', $defaultDate); ?>" required></div>
                        <div class="history-field"><label for="historical_time">Historical Time *</label><input id="historical_time" name="historical_time" type="time" value="<?php echo historical_old($_POST, 'historical_time', $defaultTime); ?>" required></div>
                        <div class="history-field"><label for="guest_name">Walk-in Customer Name *</label><input id="guest_name" name="guest_name" value="<?php echo historical_old($_POST, 'guest_name'); ?>" maxlength="120" required></div>
                        <div class="history-field"><label for="guest_email">Walk-in Email (optional)</label><input id="guest_email" name="guest_email" type="email" value="<?php echo historical_old($_POST, 'guest_email'); ?>" maxlength="254"></div>
                        <div class="history-field"><label>Payment</label><input value="Cash - Paid" readonly aria-readonly="true"></div>
                        <div class="history-field full"><label for="reason">Reason / Note *</label><textarea id="reason" name="reason" maxlength="1000" required><?php echo historical_old($_POST, 'reason'); ?></textarea></div>
                    </div>

                    <div class="history-products history-mode" id="readyFields">
                        <h2 style="font-size:18px;font-weight:800;color:#0f172a;margin:0 0 12px;">Ready-made Products</h2>
                        <div id="productRows"></div>
                        <button type="button" class="history-add" id="addProduct">+ Add product</button>
                        <div class="history-total">Total: <span id="grandTotal">₱0.00</span></div>
                    </div>
                    <div class="history-mode" id="customFields" hidden>
                        <h2 style="font-size:18px;font-weight:800;color:#0f172a;margin:0 0 12px;">Custom/Service Order</h2>
                        <div class="history-custom-note">The order will create a pending production job. Materials and ink will remain on the normal production workflow and will not be deducted at historical-entry time.</div>
                        <div class="history-grid">
                            <div class="history-field full"><label for="custom_service_id">Custom/Service Item *</label><select id="custom_service_id" name="custom_service_id"><option value="">Select custom/service item</option><?php foreach ($services as $service): ?><option value="<?php echo (int)$service['service_id']; ?>" data-price="<?php echo historical_h(number_format((float)$service['base_price'], 2, '.', '')); ?>" <?php echo ((string)($_POST['custom_service_id'] ?? '') === (string)$service['service_id']) ? 'selected' : ''; ?>><?php echo historical_h((string)$service['name']); ?></option><?php endforeach; ?></select></div>
                            <div class="history-field"><label for="custom_quantity">Quantity *</label><input id="custom_quantity" name="custom_quantity" type="number" min="1" step="1" value="<?php echo historical_old($_POST, 'custom_quantity', '1'); ?>"></div>
                            <div class="history-field"><label for="custom_price">Final / Actual Price *</label><input id="custom_price" name="custom_price" type="number" min="0" step="0.01" value="<?php echo historical_old($_POST, 'custom_price', '0.00'); ?>"></div>
                            <div class="history-field"><label for="custom_dimensions">Dimensions (optional)</label><input id="custom_dimensions" name="custom_dimensions" value="<?php echo historical_old($_POST, 'custom_dimensions'); ?>" maxlength="80" placeholder="Example: 2x3"></div>
                            <div class="history-field full"><label for="custom_specifications">Required Specifications / Options *</label><textarea id="custom_specifications" name="custom_specifications" maxlength="4000" placeholder="Record the historical specifications, size, material, color, or other options."><?php echo historical_old($_POST, 'custom_specifications'); ?></textarea></div>
                        </div>
                        <div class="history-total">Total: <span id="customTotal">0.00</span></div>
                    </div>
                    <div class="history-actions"><button class="history-submit" type="submit" <?php echo ($products === [] && $services === []) ? 'disabled' : ''; ?>>Review and Save Historical Sale</button></div>
                </form>
            </div>
        </div>
    </main>
    <script>
        const products = <?php echo json_encode(array_values(array_map(static function (array $product): array {
            return ['id' => (int)$product['product_id'], 'name' => (string)$product['name'], 'price' => (float)$product['price']];
        }, $products)), JSON_UNESCAPED_SLASHES); ?>;
        const services = <?php echo json_encode(array_values(array_map(static function (array $service): array {
            return ['id' => (int)$service['service_id'], 'price' => (float)$service['base_price']];
        }, $services)), JSON_UNESCAPED_SLASHES); ?>;
        const rowRoot = document.getElementById('productRows');
        const totalEl = document.getElementById('grandTotal');
        const customTotalEl = document.getElementById('customTotal');
        const transactionTypeEl = document.getElementById('transaction_type');
        const readyFields = document.getElementById('readyFields');
        const customFields = document.getElementById('customFields');
        let rowCount = 0;
        const money = value => '₱' + Number(value || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        function productOptions() { return '<option value="">Select product</option>' + products.map(p => `<option value="${p.id}" data-price="${p.price}">${escapeHtml(p.name)}</option>`).join(''); }
        function escapeHtml(value) { return String(value).replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char])); }
        function addRow() {
            rowCount += 1;
            const row = document.createElement('div');
            row.className = 'history-row';
            row.innerHTML = `<div class="history-field"><label>Product *</label><select name="product_id[]" class="product-select" required>${productOptions()}</select></div><div class="history-field"><label>Variant / size</label><input name="variant[]" placeholder="Optional"></div><div class="history-field"><label>Quantity *</label><input name="quantity[]" type="number" min="1" step="1" value="1" required></div><div class="history-field"><label>Unit price *</label><input name="unit_price[]" type="number" min="0" step="0.01" value="0.00" required></div><button type="button" class="remove-row" title="Remove product">×</button>`;
            row.querySelector('.product-select').addEventListener('change', event => { const selected = event.target.selectedOptions[0]; row.querySelector('[name="unit_price[]"]').value = selected?.dataset.price || '0.00'; updateTotal(); });
            row.querySelectorAll('input').forEach(input => input.addEventListener('input', updateTotal));
            row.querySelector('.remove-row').addEventListener('click', () => { row.remove(); updateTotal(); });
            rowRoot.appendChild(row);
        }
        function updateTotal() { let total = 0; rowRoot.querySelectorAll('.history-row').forEach(row => { total += Math.max(0, Number(row.querySelector('[name="quantity[]"]').value || 0)) * Math.max(0, Number(row.querySelector('[name="unit_price[]"]').value || 0)); }); totalEl.textContent = money(total); }
        function updateCustomTotal() { const quantity = Math.max(0, Number(document.getElementById('custom_quantity').value || 0)); const price = Math.max(0, Number(document.getElementById('custom_price').value || 0)); customTotalEl.textContent = money(quantity * price); }
        function updateCustomPrice() { const selected = document.getElementById('custom_service_id').selectedOptions[0]; const price = selected?.dataset.price; if (price !== undefined && document.getElementById('custom_price').value === '0.00') document.getElementById('custom_price').value = price; updateCustomTotal(); }
        function setTransactionType() {
            const isCustom = transactionTypeEl.value === 'custom';
            readyFields.hidden = isCustom;
            customFields.hidden = !isCustom;
            readyFields.querySelectorAll('input, select, button').forEach(field => { field.disabled = isCustom; });
            customFields.querySelectorAll('input, select, textarea').forEach(field => { field.disabled = !isCustom; });
            document.getElementById('custom_service_id').required = isCustom;
            document.getElementById('custom_specifications').required = isCustom;
            updateCustomTotal();
        }
        document.getElementById('addProduct').addEventListener('click', addRow);
        transactionTypeEl.addEventListener('change', setTransactionType);
        document.getElementById('custom_service_id').addEventListener('change', updateCustomPrice);
        document.getElementById('custom_quantity').addEventListener('input', updateCustomTotal);
        document.getElementById('custom_price').addEventListener('input', updateCustomTotal);
        document.getElementById('historicalTransactionForm').addEventListener('submit', event => { const message = transactionTypeEl.value === 'custom' ? 'Save this historical cash custom/service order and create a pending production job? Materials and ink will not be deducted yet.' : 'Save this historical cash sale and deduct the selected quantities from current branch stock?'; if (!confirm(message)) event.preventDefault(); });
        addRow();
        setTransactionType();
    </script>
</body>
</html>
