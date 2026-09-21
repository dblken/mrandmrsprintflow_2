<?php
/**
 * Per-branch stock for catalog products (products table stays canonical for admins).
 * Staff POS / manager UI use branch rows only. If a branch has no stock row yet,
 * that branch should see zero stock rather than inheriting another branch's quantity.
 */

if (!defined('PRODUCT_BRANCH_STOCK_LOADED')) {
    define('PRODUCT_BRANCH_STOCK_LOADED', true);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/InventoryManager.php';

function printflow_generate_product_inventory_ref_id(int $branchId = 0): int {
    static $sequence = 0;
    $sequence = ($sequence + 1) % 10;

    $branchPart = max(0, min(99, $branchId));
    $timePart = (int)date('His');

    return (int)(($branchPart * 10000000) + ($timePart * 10) + $sequence);
}

/**
 * The main branch (Cabuyao) keeps its canonical stock in products.stock_quantity.
 */
function printflow_product_branch_uses_base_stock(int $branchId): bool {
    static $cache = [];
    if ($branchId <= 0) {
        return false;
    }
    if (array_key_exists($branchId, $cache)) {
        return $cache[$branchId];
    }

    if (function_exists('printflow_get_default_admin_branch_id') && $branchId === (int)printflow_get_default_admin_branch_id()) {
        return $cache[$branchId] = true;
    }

    $row = db_query('SELECT branch_name FROM branches WHERE id = ? LIMIT 1', 'i', [$branchId]);
    $name = strtolower(trim((string)($row[0]['branch_name'] ?? '')));
    return $cache[$branchId] = ($name !== '' && strpos($name, 'cabuyao') !== false);
}

/**
 * Create product_branch_stock if missing (idempotent).
 */
function printflow_ensure_product_branch_stock_table(): void {
    static $done = false;
    if ($done) {
        return;
    }
    global $conn;
    if (printflow_db_in_transaction($conn)) {
        // Never run implicit-commit DDL inside checkout/completion transactions.
        // Existing installations must already have this canonical stock table.
        $done = true;
        return;
    }
    $sql = "CREATE TABLE IF NOT EXISTS `product_branch_stock` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `product_id` INT NOT NULL,
        `branch_id` INT NOT NULL,
        `stock_quantity` INT NOT NULL DEFAULT 0,
        `low_stock_level` INT NOT NULL DEFAULT 10,
        `critical_level` INT NOT NULL DEFAULT 0,
        `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_product_branch` (`product_id`, `branch_id`),
        KEY `idx_branch` (`branch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    @$conn->query($sql);
    $done = true;
}

/**
 * Update branch stock quantity without changing threshold policy.
 */
function printflow_product_branch_stock_set_quantity(int $productId, int $branchId, int $stockQty): bool {
    printflow_ensure_product_branch_stock_table();
    if ($productId <= 0 || $branchId <= 0) {
        return false;
    }
    $existing = db_query(
        'SELECT low_stock_level, critical_level FROM product_branch_stock WHERE product_id = ? AND branch_id = ? LIMIT 1',
        'ii',
        [$productId, $branchId]
    );
    if (!empty($existing)) {
        return db_execute(
            'UPDATE product_branch_stock SET stock_quantity = ? WHERE product_id = ? AND branch_id = ?',
            'iii',
            [$stockQty, $productId, $branchId]
        ) !== false;
    }
    $p = db_query(
        'SELECT COALESCE(low_stock_level, 10) AS low_stock_level, COALESCE(critical_level, 0) AS critical_level FROM products WHERE product_id = ? LIMIT 1',
        'i',
        [$productId]
    );
    $low = (int)($p[0]['low_stock_level'] ?? 10);
    $critical = (int)($p[0]['critical_level'] ?? 0);
    return printflow_product_branch_stock_upsert($productId, $branchId, $stockQty, $low, $critical);
}

/**
 * Upsert branch-level stock and thresholds.
 */
function printflow_product_branch_stock_upsert(int $productId, int $branchId, int $stockQty, int $lowLevel, ?int $criticalLevel = null): bool {
    printflow_ensure_product_branch_stock_table();
    if ($productId <= 0 || $branchId <= 0) {
        return false;
    }
    if ($criticalLevel === null) {
        $existing = db_query(
            'SELECT critical_level FROM product_branch_stock WHERE product_id = ? AND branch_id = ? LIMIT 1',
            'ii',
            [$productId, $branchId]
        );
        if (!empty($existing)) {
            $criticalLevel = (int)($existing[0]['critical_level'] ?? 0);
        } else {
            $p = db_query('SELECT COALESCE(critical_level, 0) AS critical_level FROM products WHERE product_id = ? LIMIT 1', 'i', [$productId]);
            $criticalLevel = (int)($p[0]['critical_level'] ?? 0);
        }
    }
    $res = db_execute(
        'INSERT INTO product_branch_stock (product_id, branch_id, stock_quantity, low_stock_level, critical_level)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE stock_quantity = VALUES(stock_quantity), low_stock_level = VALUES(low_stock_level), critical_level = VALUES(critical_level)',
        'iiiii',
        [$productId, $branchId, $stockQty, $lowLevel, $criticalLevel]
    );
    return $res !== false;
}

/**
 * Ensure product-aware audit columns exist in inventory_transactions.
 * Product stock movements share the ledger table, but must never rely on
 * inv_items IDs because product IDs and material IDs are different domains.
 */
function printflow_ensure_product_inventory_transaction_schema(): void {
    static $ddlDone = false;
    global $conn;
    if (!$ddlDone && printflow_db_in_transaction($conn)) {
        // DDL would implicitly commit the caller's stock movement. Queries below
        // fail closed if a required migration has not yet been applied.
        return;
    }
    if (!$ddlDone) {
        InventoryManager::ensureBranchScopedSchema();

        try {
            $productCols = db_query("SHOW COLUMNS FROM inventory_transactions LIKE 'product_id'") ?: [];
            if (empty($productCols)) {
                db_execute("ALTER TABLE inventory_transactions ADD COLUMN product_id INT NULL AFTER item_id");
            }
        } catch (Throwable $e) {
            // Allow page logic to continue even if the schema is already being
            // changed by another request.
        }

        try {
            $transactionCols = db_query("SHOW COLUMNS FROM inventory_transactions LIKE 'transaction_id'") ?: [];
            if (empty($transactionCols)) {
                db_execute("ALTER TABLE inventory_transactions ADD COLUMN transaction_id VARCHAR(50) NULL AFTER item_id");
            }
            $transactionIdx = db_query("SHOW INDEX FROM inventory_transactions WHERE Key_name = 'idx_unique_txn'") ?: [];
            if (empty($transactionIdx)) {
                db_execute("ALTER TABLE inventory_transactions ADD UNIQUE INDEX idx_unique_txn (transaction_id)");
            }
        } catch (Throwable $e) {
            // The atomic order-line helper verifies this capability before use.
        }

        try {
            $productIdx = db_query("SHOW INDEX FROM inventory_transactions WHERE Key_name = 'idx_inv_tx_product_date'") ?: [];
            if (empty($productIdx)) {
                db_execute("ALTER TABLE inventory_transactions ADD INDEX idx_inv_tx_product_date (product_id, transaction_date)");
            }
        } catch (Throwable $e) {
            // Non-fatal. Reads can still work without this index.
        }

        try {
            $itemCols = db_query("SHOW COLUMNS FROM inventory_transactions LIKE 'item_id'") ?: [];
            if (!empty($itemCols) && strtoupper((string)($itemCols[0]['Null'] ?? '')) !== 'YES') {
                db_execute("ALTER TABLE inventory_transactions MODIFY item_id INT NULL");
            }
        } catch (Throwable $e) {
            // Non-fatal: product audit writer has a fallback for schemas that still require item_id.
        }

        $ddlDone = true;
    }
}

/**
 * Older catalog ledger rows skipped branch_id while still updating stock — they disappear on branch-filtered ledger views (e.g. only product #40).
 * Runs at most once per request; skips when nothing is orphaned (COUNT probe).
 */
function printflow_inventory_transactions_item_id_allows_null(): bool {
    static $allowsNull = null;
    if ($allowsNull !== null) {
        return $allowsNull;
    }
    try {
        $cols = db_query("SHOW COLUMNS FROM inventory_transactions LIKE 'item_id'") ?: [];
        $allowsNull = !empty($cols) && strtoupper((string)($cols[0]['Null'] ?? '')) === 'YES';
    } catch (Throwable $e) {
        $allowsNull = false;
    }
    return $allowsNull;
}

function printflow_product_inventory_placeholder_item_id(int $productId): int {
    if ($productId > 0) {
        $sameId = db_query('SELECT id FROM inv_items WHERE id = ? LIMIT 1', 'i', [$productId]) ?: [];
        if (!empty($sameId)) {
            return (int)$sameId[0]['id'];
        }
    }
    $row = db_query('SELECT id FROM inv_items ORDER BY id ASC LIMIT 1') ?: [];
    return (int)($row[0]['id'] ?? max(1, $productId));
}

function printflow_repair_orphan_catalog_inventory_branches(): void {
    static $ranThisRequest = false;
    if ($ranThisRequest) {
        return;
    }
    $ranThisRequest = true;

    if (!function_exists('db_table_has_column')
        || !db_table_has_column('inventory_transactions', 'product_id')
        || !db_table_has_column('inventory_transactions', 'branch_id')) {
        return;
    }

    try {
        $cntRow = db_query(
            "SELECT COUNT(*) AS c FROM inventory_transactions t
             WHERE (t.branch_id IS NULL OR t.branch_id <= 0)
               AND (
                   (t.product_id IS NOT NULL AND t.product_id > 0)
                   OR UPPER(TRIM(COALESCE(t.ref_type, ''))) = 'ORDER'
               )"
        ) ?: [];
        if (((int)($cntRow[0]['c'] ?? 0)) < 1) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }

    $mainId = (int)(function_exists('printflow_get_default_admin_branch_id') ? printflow_get_default_admin_branch_id() : 0);
    if ($mainId <= 0) {
        $mainId = 1;
    }

    try {
        db_execute(
            "UPDATE inventory_transactions t
             INNER JOIN orders o ON UPPER(TRIM(COALESCE(t.ref_type, ''))) = 'ORDER'
               AND CAST(t.ref_id AS UNSIGNED) = o.order_id
             SET t.branch_id = CASE
                WHEN COALESCE(NULLIF(o.branch_id, 0), 0) > 0 THEN o.branch_id
                ELSE ?
             END
             WHERE (t.branch_id IS NULL OR t.branch_id <= 0)",
            'i',
            [$mainId]
        );
    } catch (Throwable $e) {
        error_log('printflow repair catalog inventory branches (ORDER join): ' . $e->getMessage());
    }

    try {
        db_execute(
            'UPDATE inventory_transactions
             SET branch_id = ?
             WHERE product_id IS NOT NULL AND product_id > 0
               AND (branch_id IS NULL OR branch_id <= 0)',
            'i',
            [$mainId]
        );
    } catch (Throwable $e) {
        error_log('printflow repair catalog inventory branches (fallback product_id): ' . $e->getMessage());
    }

    try {
        db_execute(
            'UPDATE inventory_transactions
             SET branch_id = ?
             WHERE (branch_id IS NULL OR branch_id <= 0)
               AND COALESCE(product_id, 0) = 0
               AND item_id IS NOT NULL AND item_id > 0
               AND UPPER(TRIM(COALESCE(ref_type, \'\'))) = \'ORDER_PRODUCT\'',
            'i',
            [$mainId]
        );
    } catch (Throwable $e) {
        error_log('printflow repair catalog inventory branches (ORDER_PRODUCT legacy): ' . $e->getMessage());
    }
}

/**
 * Record a product stock movement into the shared inventory ledger safely.
 */
function printflow_record_product_inventory_transaction(
    int $productId,
    string $direction,
    float $quantity,
    string $refType = 'PRODUCT',
    ?int $refId = null,
    string $notes = '',
    ?int $userId = null,
    ?string $date = null,
    ?int $branchId = null,
    ?string $transactionId = null
) {
    if (!printflow_db_in_transaction($conn)) {
        printflow_ensure_product_inventory_transaction_schema();
    }

    if ($productId <= 0 || $quantity <= 0) {
        return false;
    }

    $direction = strtoupper(trim($direction));
    if (!in_array($direction, ['IN', 'OUT'], true)) {
        return false;
    }

    $date = $date ?: date('Y-m-d');
    $userId = $userId ?: (int)($_SESSION['user_id'] ?? 0);
    $qty = abs((float)$quantity);
    $normalizedRefType = strtoupper(trim($refType ?: 'PRODUCT'));
    $hasProductIdColumn = db_table_has_column('inventory_transactions', 'product_id');
    $hasTransactionIdColumn = db_table_has_column('inventory_transactions', 'transaction_id');

    if (($branchId === null || $branchId <= 0) && $normalizedRefType === 'ORDER' && $refId !== null && $refId > 0) {
        $orderBranch = db_query(
            'SELECT branch_id FROM orders WHERE order_id = ? LIMIT 1',
            'i',
            [$refId]
        );
        $branchId = (int)($orderBranch[0]['branch_id'] ?? 0);
    }

    if ($branchId === null || $branchId <= 0) {
        if (function_exists('printflow_branch_filter_for_user')) {
            $lockedBranchId = printflow_branch_filter_for_user();
            if ($lockedBranchId !== null && (int)$lockedBranchId > 0) {
                $branchId = (int)$lockedBranchId;
            }
        }
    }

    if ($branchId === null || $branchId <= 0) {
        $selectedBranchId = $_SESSION['selected_branch_id'] ?? null;
        if ($selectedBranchId !== null && $selectedBranchId !== 'all' && (int)$selectedBranchId > 0) {
            $branchId = (int)$selectedBranchId;
        }
    }

    if ($branchId === null || $branchId <= 0) {
        $sessionBranchId = (int)($_SESSION['branch_id'] ?? 0);
        if ($sessionBranchId > 0) {
            $branchId = $sessionBranchId;
        }
    }

    if ($branchId === null || $branchId <= 0) {
        if (function_exists('printflow_get_default_admin_branch_id')) {
            $branchId = (int)printflow_get_default_admin_branch_id();
        } else {
            $branchId = 1;
        }
    }
    // Never omit branch_id: NULL branch hid catalog movements on branch-filtered ledger views (Products / POS).
    $branchId = max(1, (int)$branchId);
    $productTransactionId = trim((string)$transactionId);
    if ($productTransactionId === '') {
        $productTransactionId = 'PRD-' . date('YmdHis') . '-' . $branchId . '-' . $productId . '-' . random_int(1000, 9999);
    }
    $productTransactionId = substr($productTransactionId, 0, 50);

    $storedRefType = $normalizedRefType;
    $storedRefId = $refId;

    if (in_array($normalizedRefType, ['PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT'], true)) {
        $storedRefId = printflow_generate_product_inventory_ref_id($branchId);
    }

    if (!$hasProductIdColumn) {
        if ($normalizedRefType === 'ORDER') {
            $storedRefType = 'ORDER_PRODUCT';
            $storedRefId = $productId;
        } elseif ($storedRefId === null || $storedRefId <= 0) {
            $storedRefId = $productId;
        }
    }

    $fields = [
        'transaction_id'   => ['type' => 's', 'val' => $productTransactionId],
        'direction'        => ['type' => 's', 'val' => $direction],
        'quantity'         => ['type' => 's', 'val' => (string)$qty],
        'uom'              => ['type' => 's', 'val' => 'pcs'],
        'ref_type'         => ['type' => 's', 'val' => $storedRefType],
        'notes'            => ['type' => 's', 'val' => $notes],
        'transaction_date' => ['type' => 's', 'val' => $date],
    ];

    if ($hasProductIdColumn) {
        $fields['product_id'] = ['type' => 'i', 'val' => $productId];
        if (!printflow_inventory_transactions_item_id_allows_null()) {
            $fields = ['item_id' => ['type' => 'i', 'val' => printflow_product_inventory_placeholder_item_id($productId)]] + $fields;
        }
    } else {
        $fields = ['item_id' => ['type' => 'i', 'val' => $productId]] + $fields;
    }

    $fields['branch_id'] = ['type' => 'i', 'val' => $branchId];
    if ($storedRefId !== null) {
        $fields['ref_id'] = ['type' => 'i', 'val' => $storedRefId];
    }
    if ($userId > 0) {
        $fields['created_by'] = ['type' => 'i', 'val' => $userId];
    }

    if (!$hasTransactionIdColumn) {
        unset($fields['transaction_id']);
    }

    $cols = array_keys($fields);
    $placeholders = array_fill(0, count($fields), '?');
    $types = implode('', array_column($fields, 'type'));
    $values = array_column($fields, 'val');

    $result = db_execute(
        "INSERT INTO inventory_transactions (" . implode(', ', $cols) . ")
         VALUES (" . implode(', ', $placeholders) . ")",
        $types,
        $values
    );

    if ($result !== false) {
        return $result;
    }

    // Compatibility fallback for older/live ledger schemas that still expect
    // product rows to use item_id = product_id, as in the older ledger fetch.
    $legacyFields = [
        'item_id'          => ['type' => 'i', 'val' => printflow_product_inventory_placeholder_item_id($productId)],
        'transaction_id'   => ['type' => 's', 'val' => $productTransactionId],
        'direction'        => ['type' => 's', 'val' => $direction],
        'quantity'         => ['type' => 's', 'val' => (string)$qty],
        'uom'              => ['type' => 's', 'val' => 'pcs'],
        'ref_type'         => ['type' => 's', 'val' => $normalizedRefType === 'ORDER' ? 'ORDER' : $storedRefType],
        'notes'            => ['type' => 's', 'val' => $notes],
        'transaction_date' => ['type' => 's', 'val' => $date],
    ];

    $legacyFields['branch_id'] = ['type' => 'i', 'val' => $branchId];
    if ($storedRefId !== null) {
        $legacyFields['ref_id'] = ['type' => 'i', 'val' => $storedRefId];
    } elseif ($refId !== null) {
        $legacyFields['ref_id'] = ['type' => 'i', 'val' => $refId];
    }
    if ($userId > 0) {
        $legacyFields['created_by'] = ['type' => 'i', 'val' => $userId];
    }

    if (!$hasTransactionIdColumn) {
        unset($legacyFields['transaction_id']);
    }

    $legacyCols = array_keys($legacyFields);
    $legacyPlaceholders = array_fill(0, count($legacyFields), '?');
    $legacyTypes = implode('', array_column($legacyFields, 'type'));
    $legacyValues = array_column($legacyFields, 'val');

    $legacyResult = db_execute(
        "INSERT INTO inventory_transactions (" . implode(', ', $legacyCols) . ")
         VALUES (" . implode(', ', $legacyPlaceholders) . ")",
        $legacyTypes,
        $legacyValues
    );

    if ($legacyResult === false) {
        error_log('Product inventory ledger insert failed for product #' . $productId . ' (' . $normalizedRefType . ')');
    }

    return $legacyResult;
}

/**
 * Atomically deduct one ready-made order line and write its matching ledger row.
 *
 * The deterministic transaction_id is the backend idempotency key. Locking the
 * order line prevents concurrent completion handlers from both passing the
 * ledger check before either one commits.
 *
 * @return array{applied:bool,already_applied:bool,product_id:int,quantity:int,branch_id:int,option:?array}
 */
function printflow_apply_product_order_item_inventory(
    int $orderItemId,
    int $branchId = 0,
    int $actorId = 0,
    string $sourceLabel = 'Order'
): array {
    global $conn;

    if ($orderItemId <= 0) {
        throw new InvalidArgumentException('A valid order item is required for inventory deduction.');
    }

    printflow_ensure_product_inventory_transaction_schema();
    require_once __DIR__ . '/product_option_stock.php';

    if (!db_table_has_column('inventory_transactions', 'transaction_id')
        || !db_table_has_column('inventory_transactions', 'product_id')) {
        throw new RuntimeException('The product inventory ledger migration is unavailable.');
    }

    $startedTransaction = !printflow_db_in_transaction($conn);
    if ($startedTransaction && !$conn->begin_transaction()) {
        throw new RuntimeException('Unable to start product inventory transaction.');
    }

    try {
        $rows = db_query(
            "SELECT oi.order_item_id, oi.order_id, oi.product_id, oi.quantity, oi.customization_data,
                    o.branch_id AS order_branch_id, o.status AS order_status, p.name AS product_name
             FROM order_items oi
             INNER JOIN orders o ON o.order_id = oi.order_id
             INNER JOIN products p ON p.product_id = oi.product_id
             WHERE oi.order_item_id = ?
             LIMIT 1
             FOR UPDATE",
            'i',
            [$orderItemId]
        ) ?: [];
        if ($rows === []) {
            throw new RuntimeException('The product order item no longer exists.');
        }

        $item = $rows[0];
        $orderId = (int)$item['order_id'];
        $productId = (int)$item['product_id'];
        $quantity = (int)$item['quantity'];
        $orderBranchId = (int)($item['order_branch_id'] ?? 0);
        if ($productId <= 0 || $quantity <= 0) {
            throw new RuntimeException('The product order item has invalid inventory values.');
        }
        if (strcasecmp(trim((string)($item['order_status'] ?? '')), 'Cancelled') === 0) {
            throw new RuntimeException('Cancelled orders cannot consume inventory.');
        }
        if ($orderBranchId > 0) {
            if ($branchId > 0 && $branchId !== $orderBranchId) {
                throw new RuntimeException('The requested inventory branch does not match the order branch.');
            }
            $branchId = $orderBranchId;
        }
        if ($branchId <= 0) {
            throw new RuntimeException('The order does not identify an inventory branch.');
        }

        $transactionId = substr("ORDER-{$orderId}-ITEM-{$orderItemId}-OUT", 0, 50);
        $existing = db_query(
            'SELECT id FROM inventory_transactions WHERE transaction_id = ? LIMIT 1',
            's',
            [$transactionId]
        ) ?: [];
        if ($existing !== []) {
            if ($startedTransaction) $conn->commit();
            return [
                'applied' => false,
                'already_applied' => true,
                'product_id' => $productId,
                'quantity' => $quantity,
                'branch_id' => $branchId,
                'option' => null,
            ];
        }

        $customization = json_decode((string)($item['customization_data'] ?? ''), true);
        $customization = is_array($customization) ? $customization : [];
        $optionResult = printflow_product_option_stock_deduct($productId, $branchId, $customization, $quantity);
        if (!empty($optionResult['handled'])) {
            if (empty($optionResult['success'])) {
                throw new RuntimeException((string)($optionResult['message'] ?? 'Selected option stock is insufficient.'));
            }
        } elseif (!printflow_product_deduct_stock_for_branch($productId, $branchId, $quantity)) {
            throw new RuntimeException('Insufficient product stock at the order branch.');
        }

        $orderRef = function_exists('printflow_get_order_inventory_reference')
            ? printflow_get_order_inventory_reference($orderId)
            : [];
        $orderLabel = $orderRef['label'] ?? ('Order #' . $orderId);
        $productName = trim((string)($item['product_name'] ?? '')) ?: ('Product #' . $productId);
        $optionNote = !empty($optionResult['handled'])
            ? ' (' . (string)$optionResult['field_label'] . ': ' . (string)$optionResult['option_value'] . ')'
            : '';
        $notes = trim($sourceLabel) . ": {$orderLabel} - {$productName}{$optionNote}";

        $ledgerResult = printflow_record_product_inventory_transaction(
            $productId,
            'OUT',
            (float)$quantity,
            'ORDER',
            $orderId,
            $notes,
            $actorId,
            date('Y-m-d'),
            $branchId,
            $transactionId
        );
        if ($ledgerResult === false) {
            throw new RuntimeException('Product stock changed but its ledger transaction could not be recorded.');
        }

        if ($startedTransaction) $conn->commit();
        return [
            'applied' => true,
            'already_applied' => false,
            'product_id' => $productId,
            'quantity' => $quantity,
            'branch_id' => $branchId,
            'option' => !empty($optionResult['handled']) ? $optionResult : null,
        ];
    } catch (Throwable $e) {
        if ($startedTransaction && printflow_db_in_transaction($conn)) {
            $conn->rollback();
        }
        throw $e;
    }
}

/**
 * Effective quantity + low threshold for a product at a branch.
 *
 * @return array{0:int,1:int} [stock_quantity, low_stock_level]
 */
function printflow_product_effective_stock(int $productId, int $branchId): array {
    printflow_ensure_product_branch_stock_table();
    if ($productId <= 0) {
        return [0, 10];
    }

    if ($branchId > 0) {
        if (printflow_product_branch_uses_base_stock($branchId)) {
            $p = db_query(
                'SELECT stock_quantity, COALESCE(low_stock_level, 10) AS low_stock_level FROM products WHERE product_id = ? LIMIT 1',
                'i',
                [$productId]
            );
            if (empty($p)) {
                return [0, 10];
            }
            return [(int)($p[0]['stock_quantity'] ?? 0), (int)$p[0]['low_stock_level']];
        }

        $pbs = db_query(
            'SELECT stock_quantity, low_stock_level FROM product_branch_stock WHERE product_id = ? AND branch_id = ? LIMIT 1',
            'ii',
            [$productId, $branchId]
        );
        if (!empty($pbs)) {
            return [(int)$pbs[0]['stock_quantity'], (int)$pbs[0]['low_stock_level']];
        }

        $p = db_query(
            'SELECT COALESCE(low_stock_level, 10) AS low_stock_level FROM products WHERE product_id = ? LIMIT 1',
            'i',
            [$productId]
        );
        if (empty($p)) {
            return [0, 10];
        }

        // Non-main branches must only see their own branch row.
        // If no branch stock row exists yet, treat the branch as zero stock
        // instead of inheriting the Cabuyao/base product quantity.
        return [0, (int)$p[0]['low_stock_level']];
    }

    $p = db_query(
        'SELECT stock_quantity, COALESCE(low_stock_level, 10) AS low_stock_level FROM products WHERE product_id = ? LIMIT 1',
        'i',
        [$productId]
    );
    if (empty($p)) {
        return [0, 10];
    }
    return [(int)$p[0]['stock_quantity'], (int)$p[0]['low_stock_level']];
}

/**
 * Deduct sold quantity at branch.
 * Branch-scoped users may only deduct from their own branch row.
 * Global/admin flows without a branch continue using products.stock_quantity.
 */
function printflow_product_deduct_stock_for_branch(int $productId, int $branchId, int $qty): bool {
    printflow_ensure_product_branch_stock_table();
    if ($productId <= 0 || $qty <= 0) {
        return false;
    }
    if ($branchId > 0) {
        if (printflow_product_branch_uses_base_stock($branchId)) {
            $p = db_query('SELECT stock_quantity FROM products WHERE product_id = ? LIMIT 1', 'i', [$productId]);
            if (empty($p) || (int)$p[0]['stock_quantity'] < $qty) {
                return false;
            }
            $affected = db_execute_affected_rows(
                'UPDATE products SET stock_quantity = stock_quantity - ? WHERE product_id = ? AND stock_quantity >= ?',
                'iii',
                [$qty, $productId, $qty]
            );
            return $affected === 1;
        }

        $row = db_query(
            'SELECT stock_quantity FROM product_branch_stock WHERE product_id = ? AND branch_id = ? LIMIT 1',
            'ii',
            [$productId, $branchId]
        );
        if (!empty($row)) {
            $cur = (int)$row[0]['stock_quantity'];
            if ($cur < $qty) {
                return false;
            }
            $affected = db_execute_affected_rows(
                'UPDATE product_branch_stock SET stock_quantity = stock_quantity - ? WHERE product_id = ? AND branch_id = ? AND stock_quantity >= ?',
                'iiii',
                [$qty, $productId, $branchId, $qty]
            );
            return $affected === 1;
        }

        return false;
    }

    $p = db_query('SELECT stock_quantity FROM products WHERE product_id = ? LIMIT 1', 'i', [$productId]);
    if (empty($p) || (int)$p[0]['stock_quantity'] < $qty) {
        return false;
    }
    $affected = db_execute_affected_rows(
        'UPDATE products SET stock_quantity = stock_quantity - ? WHERE product_id = ? AND stock_quantity >= ?',
        'iii',
        [$qty, $productId, $qty]
    );
    return $affected === 1;
}
