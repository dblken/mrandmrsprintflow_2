<?php
/**
 * Admin-only historical ready-made transaction entry.
 *
 * This intentionally handles product sales only. Custom/service orders remain
 * on their existing workflows until a separate historical design is approved.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/product_branch_stock.php';
require_once __DIR__ . '/../includes/product_option_stock.php';
require_once __DIR__ . '/../includes/pos_customer_helpers.php';

require_role('Admin');

if (!isset($base_path)) {
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

global $conn;
$current_user = get_logged_in_user();
$page_title = 'Historical Transaction Entry - Admin';
$error = '';
$success = '';
$receipt = null;

/** Existing POS guest resolver expects this function from the POS endpoint. */
if (!function_exists('pos_get_walkin_customer_id')) {
    function pos_get_walkin_customer_id(): int
    {
        global $conn;
        $rows = db_query("SELECT customer_id FROM customers WHERE email = 'walkin@pos.local' LIMIT 1") ?: [];
        if ($rows !== []) {
            return (int)$rows[0]['customer_id'];
        }

        if (!db_execute(
            "INSERT INTO customers (first_name, last_name, email, contact_number, password_hash, status)
             VALUES ('Walk-in', 'Guest', 'walkin@pos.local', NULL, '', 'Active')"
        )) {
            throw new RuntimeException('The shared walk-in customer could not be prepared.');
        }

        return (int)$conn->insert_id;
    }
}

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

function historical_table_ready(): bool
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

function historical_insert_order(array $data, array $items, string $businessAt, string $reason, string $token): int
{
    global $conn;
    $guest = printflow_pos_resolve_checkout_customer_context([
        'customer_id' => 'guest',
        'guest_display_name' => $data['guest_name'] ?? '',
        'customer_email' => $data['guest_email'] ?? '',
    ]);
    $customerId = (int)$guest['customer_id'];
    $total = 0.0;
    foreach ($items as $item) {
        $total += (float)$item['unit_price'] * (int)$item['quantity'];
    }
    $total = round($total, 2);
    $operatorId = (int)get_user_id();
    $auditNote = '[Historical transaction] Entered by user #' . $operatorId . ' on ' . date('Y-m-d H:i:s')
        . '. Business date: ' . $businessAt . '. Reason: ' . trim($reason);

    // Leave updated_at out so the database keeps the real processing timestamp.
    $columns = ['customer_id', 'pos_guest_display_name', 'pos_guest_email', 'branch_id', 'reference_id', 'total_amount', 'status', 'payment_status', 'payment_method', 'payment_reference', 'order_date', 'notes'];
    $values = [$customerId, $guest['pos_guest_display_name'], $guest['pos_guest_email'], (int)$data['branch_id'], 'HIST-' . $token, $total, 'Completed', 'Paid', 'Cash', 'HIST-' . $token, $businessAt, $auditNote];
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
        $values[] = 'product';
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

$branches = db_query("SELECT id, branch_name FROM branches WHERE status != 'Archived' ORDER BY branch_name ASC") ?: [];
$products = historical_products();
$productsById = [];
foreach ($products as $product) {
    $productsById[(int)$product['product_id']] = $product;
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
        if (!historical_table_ready()) {
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
        $items = historical_items_from_post($_POST, $branchId, $productsById);
        $operatorId = (int)get_user_id();

        $conn->begin_transaction();
        try {
            $orderId = historical_insert_order([
                'branch_id' => $branchId,
                'guest_name' => $guestName,
                'guest_email' => $guestEmail,
            ], $items, $businessAt, $reason, $postedToken);
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
        log_activity($operatorId, 'Historical transaction entered', 'Order #' . $orderId . ' | business date ' . $businessAt . ' | reason: ' . $reason);
        $success = 'Historical transaction saved as Order #' . $orderId . '. Current branch stock was deducted.';
        $receipt = ['order_id' => $orderId, 'business_at' => $businessAt];
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
        @media (max-width: 760px) { .history-shell { padding: 16px; } .history-grid { grid-template-columns: 1fr; } .history-field.full { grid-column: auto; } .history-row { grid-template-columns: 1fr 1fr; } .history-row .remove-row { grid-column: 2; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../includes/' . (($current_user['role'] ?? '') === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>
    <main class="main-content">
        <div class="history-shell">
            <h1 style="font-size:28px;font-weight:800;color:#0f172a;margin:0 0 18px;">Historical Transaction Entry</h1>
            <div class="history-panel">
                <div class="history-notice">Use this page only for real historical business transactions. Stock will be deducted from current inventory.</div>
                <div class="history-warning"><strong>Historical entries affect current stock.</strong> Enter transactions from oldest to newest.</div>
                <?php if ($error !== ''): ?><div class="history-message history-error"><?php echo historical_h($error); ?></div><?php endif; ?>
                <?php if ($success !== ''): ?><div class="history-message history-success"><?php echo historical_h($success); ?><?php if ($receipt): ?> <a href="<?php echo historical_h($base_path); ?>/admin/orders_management.php?order_id=<?php echo (int)$receipt['order_id']; ?>" style="text-decoration:underline;">View order</a><?php endif; ?></div><?php endif; ?>
                <?php if ($products === []): ?><div class="history-message history-error">No ready-made products are available, or the product type schema is not ready. No transaction can be entered.</div><?php endif; ?>

                <form method="post" id="historicalTransactionForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="submission_token" value="<?php echo historical_h($submissionToken); ?>">
                    <div class="history-grid">
                        <div class="history-field"><label for="branch_id">Branch *</label><select id="branch_id" name="branch_id" required><option value="">Select branch</option><?php foreach ($branches as $branch): ?><option value="<?php echo (int)$branch['id']; ?>" <?php echo ((string)($_POST['branch_id'] ?? '') === (string)$branch['id']) ? 'selected' : ''; ?>><?php echo historical_h((string)$branch['branch_name']); ?></option><?php endforeach; ?></select></div>
                        <div class="history-field"><label for="historical_date">Historical Date *</label><input id="historical_date" name="historical_date" type="date" value="<?php echo historical_old($_POST, 'historical_date', $defaultDate); ?>" required></div>
                        <div class="history-field"><label for="historical_time">Historical Time *</label><input id="historical_time" name="historical_time" type="time" value="<?php echo historical_old($_POST, 'historical_time', $defaultTime); ?>" required></div>
                        <div class="history-field"><label for="guest_name">Walk-in Customer Name *</label><input id="guest_name" name="guest_name" value="<?php echo historical_old($_POST, 'guest_name'); ?>" maxlength="120" required></div>
                        <div class="history-field"><label for="guest_email">Walk-in Email (optional)</label><input id="guest_email" name="guest_email" type="email" value="<?php echo historical_old($_POST, 'guest_email'); ?>" maxlength="254"></div>
                        <div class="history-field"><label>Payment</label><input value="Cash - Paid" readonly aria-readonly="true"></div>
                        <div class="history-field full"><label for="reason">Reason / Note *</label><textarea id="reason" name="reason" maxlength="1000" required><?php echo historical_old($_POST, 'reason'); ?></textarea></div>
                    </div>

                    <div class="history-products">
                        <h2 style="font-size:18px;font-weight:800;color:#0f172a;margin:0 0 12px;">Ready-made Products</h2>
                        <div id="productRows"></div>
                        <button type="button" class="history-add" id="addProduct">+ Add product</button>
                        <div class="history-total">Total: <span id="grandTotal">₱0.00</span></div>
                    </div>
                    <div class="history-actions"><button class="history-submit" type="submit" <?php echo $products === [] ? 'disabled' : ''; ?>>Review and Save Historical Sale</button></div>
                </form>
            </div>
        </div>
    </main>
    <script>
        const products = <?php echo json_encode(array_values(array_map(static function (array $product): array {
            return ['id' => (int)$product['product_id'], 'name' => (string)$product['name'], 'price' => (float)$product['price']];
        }, $products)), JSON_UNESCAPED_SLASHES); ?>;
        const branchOptions = <?php echo json_encode(array_values(array_map(static fn(array $branch): array => ['id' => (int)$branch['id'], 'name' => (string)$branch['branch_name']], $branches)), JSON_UNESCAPED_SLASHES); ?>;
        const rowRoot = document.getElementById('productRows');
        const totalEl = document.getElementById('grandTotal');
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
        document.getElementById('addProduct').addEventListener('click', addRow);
        document.getElementById('historicalTransactionForm').addEventListener('submit', event => { if (!confirm('Save this historical cash sale and deduct the selected quantities from current branch stock?')) event.preventDefault(); });
        addRow();
    </script>
</body>
</html>
