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
    $sql = "CREATE TABLE IF NOT EXISTS `product_branch_stock` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `product_id` INT NOT NULL,
        `branch_id` INT NOT NULL,
        `stock_quantity` INT NOT NULL DEFAULT 0,
        `low_stock_level` INT NOT NULL DEFAULT 10,
        `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_product_branch` (`product_id`, `branch_id`),
        KEY `idx_branch` (`branch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    @$conn->query($sql);
    $done = true;
}

/**
 * Ensure product-aware audit columns exist in inventory_transactions.
 * Product stock movements share the ledger table, but must never rely on
 * inv_items IDs because product IDs and material IDs are different domains.
 */
function printflow_ensure_product_inventory_transaction_schema(): void {
    static $ddlDone = false;
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
            $productIdx = db_query("SHOW INDEX FROM inventory_transactions WHERE Key_name = 'idx_inv_tx_product_date'") ?: [];
            if (empty($productIdx)) {
                db_execute("ALTER TABLE inventory_transactions ADD INDEX idx_inv_tx_product_date (product_id, transaction_date)");
            }
        } catch (Throwable $e) {
            // Non-fatal. Reads can still work without this index.
        }

        $ddlDone = true;
    }

    printflow_repair_orphan_catalog_inventory_branches();
}

/**
 * Older catalog ledger rows skipped branch_id while still updating stock — they disappear on branch-filtered ledger views (e.g. only product #40).
 * Runs at most once per request; skips when nothing is orphaned (COUNT probe).
 */
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
    ?int $branchId = null
) {
    printflow_ensure_product_inventory_transaction_schema();

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

    if (($branchId === null || $branchId <= 0) && $normalizedRefType === 'ORDER' && $refId !== null && $refId > 0) {
        $orderBranch = db_query(
            'SELECT branch_id FROM orders WHERE order_id = ? LIMIT 1',
            'i',
            [$refId]
        );
        $branchId = (int)($orderBranch[0]['branch_id'] ?? 0);
    }

    if ($branchId === null || $branchId <= 0) {
        if (function_exists('printflow_get_default_admin_branch_id')) {
            $branchId = (int)printflow_get_default_admin_branch_id();
        } else {
            $branchId = (int)($_SESSION['branch_id'] ?? 0);
        }
    }
    // Never omit branch_id: NULL branch hid catalog movements on branch-filtered ledger views (Products / POS).
    $branchId = max(1, (int)$branchId);

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
        'item_id'          => ['type' => 'i', 'val' => 0],
        'direction'        => ['type' => 's', 'val' => $direction],
        'quantity'         => ['type' => 's', 'val' => (string)$qty],
        'uom'              => ['type' => 's', 'val' => 'pcs'],
        'ref_type'         => ['type' => 's', 'val' => $storedRefType],
        'notes'            => ['type' => 's', 'val' => $notes],
        'transaction_date' => ['type' => 's', 'val' => $date],
    ];

    if ($hasProductIdColumn) {
        $fields['product_id'] = ['type' => 'i', 'val' => $productId];
    }

    $fields['branch_id'] = ['type' => 'i', 'val' => $branchId];
    if ($storedRefId !== null) {
        $fields['ref_id'] = ['type' => 'i', 'val' => $storedRefId];
    }
    if ($userId > 0) {
        $fields['created_by'] = ['type' => 'i', 'val' => $userId];
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
        'item_id'          => ['type' => 'i', 'val' => $productId],
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
 * Upsert branch-level stock (managers).
 */
function printflow_product_branch_stock_upsert(int $productId, int $branchId, int $stockQty, int $lowLevel): bool {
    printflow_ensure_product_branch_stock_table();
    if ($productId <= 0 || $branchId <= 0) {
        return false;
    }
    $res = db_execute(
        'INSERT INTO product_branch_stock (product_id, branch_id, stock_quantity, low_stock_level)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE stock_quantity = VALUES(stock_quantity), low_stock_level = VALUES(low_stock_level)',
        'iiii',
        [$productId, $branchId, $stockQty, $lowLevel]
    );
    return $res !== false;
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
            $u = db_execute(
                'UPDATE products SET stock_quantity = stock_quantity - ? WHERE product_id = ? AND stock_quantity >= ?',
                'iii',
                [$qty, $productId, $qty]
            );
            return $u !== false;
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
            $u = db_execute(
                'UPDATE product_branch_stock SET stock_quantity = stock_quantity - ? WHERE product_id = ? AND branch_id = ? AND stock_quantity >= ?',
                'iiii',
                [$qty, $productId, $branchId, $qty]
            );
            return $u !== false;
        }

        return false;
    }

    $p = db_query('SELECT stock_quantity FROM products WHERE product_id = ? LIMIT 1', 'i', [$productId]);
    if (empty($p) || (int)$p[0]['stock_quantity'] < $qty) {
        return false;
    }
    $u = db_execute(
        'UPDATE products SET stock_quantity = stock_quantity - ? WHERE product_id = ? AND stock_quantity >= ?',
        'iii',
        [$qty, $productId, $qty]
    );
    return $u !== false;
}
