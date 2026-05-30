<?php
/**
 * Product stock threshold helpers (reorder = low_stock_level, critical = critical_level).
 */

require_once __DIR__ . '/inventory_stock_status.php';

function printflow_ensure_products_threshold_schema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $cols = db_query("SHOW COLUMNS FROM products LIKE 'critical_level'") ?: [];
        if (empty($cols)) {
            db_execute(
                "ALTER TABLE products ADD COLUMN critical_level INT NOT NULL DEFAULT 0 AFTER low_stock_level"
            );
            db_execute(
                "UPDATE products
                 SET critical_level = GREATEST(0, CEIL(low_stock_level * 0.25))
                 WHERE low_stock_level > 0"
            );
        }
    } catch (Throwable $e) {
        error_log('printflow_ensure_products_threshold_schema products: ' . $e->getMessage());
    }

    printflow_ensure_product_branch_stock_table();

    try {
        $pbsCols = db_query("SHOW COLUMNS FROM product_branch_stock LIKE 'critical_level'") ?: [];
        if (empty($pbsCols)) {
            db_execute(
                "ALTER TABLE product_branch_stock ADD COLUMN critical_level INT NOT NULL DEFAULT 0 AFTER low_stock_level"
            );
            db_execute(
                "UPDATE product_branch_stock
                 SET critical_level = GREATEST(0, CEIL(low_stock_level * 0.25))
                 WHERE low_stock_level > 0"
            );
        }
    } catch (Throwable $e) {
        error_log('printflow_ensure_products_threshold_schema pbs: ' . $e->getMessage());
    }
}

function printflow_product_stored_reorder_level(array $product): int {
    return max(0, (int)($product['low_stock_level'] ?? 0));
}

function printflow_product_stored_critical_level(array $product): int {
    return max(0, (int)($product['critical_level'] ?? 0));
}

/** @return array{reorder:int,critical:int} */
function printflow_apply_suggested_product_thresholds(int $productId, int $referenceQuantity, int $branchId = 0): array {
    printflow_ensure_products_threshold_schema();
    $thresholds = printflow_thresholds_for_quantity((float)$referenceQuantity);
    $reorder = (int)$thresholds['reorder'];
    $critical = (int)$thresholds['critical'];

    if ($branchId > 0 && !printflow_product_branch_uses_base_stock($branchId)) {
        db_execute(
            'UPDATE product_branch_stock SET low_stock_level = ?, critical_level = ? WHERE product_id = ? AND branch_id = ?',
            'iiii',
            [$reorder, $critical, $productId, $branchId]
        );
    } else {
        db_execute(
            'UPDATE products SET low_stock_level = ?, critical_level = ? WHERE product_id = ?',
            'iii',
            [$reorder, $critical, $productId]
        );
    }

    return ['reorder' => $reorder, 'critical' => $critical];
}

function printflow_product_resolve_stock_status(int $quantity, int $reorderLevel, int $criticalLevel): array {
    return printflow_resolve_stock_status((float)$quantity, (float)$reorderLevel, (float)$criticalLevel, false);
}
