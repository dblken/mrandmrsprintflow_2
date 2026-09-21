<?php
/**
 * Variant Functions
 * PrintFlow - Printing Shop PWA
 * 
 * Functions for managing product variants and BOM-based material deduction.
 */

require_once __DIR__ . '/db.php';

/**
 * Get all variants for a product.
 */
function get_variants_by_product(int $product_id): array {
    return db_query(
        "SELECT pv.*,
                (SELECT COUNT(*) FROM variant_materials vm WHERE vm.variant_id = pv.variant_id) AS material_count
         FROM product_variants pv
         WHERE pv.product_id = ?
         ORDER BY pv.created_at DESC",
        'i', [$product_id]
    ) ?: [];
}

/**
 * Get a single variant by ID.
 */
function get_variant(int $variant_id): ?array {
    $rows = db_query("SELECT * FROM product_variants WHERE variant_id = ?", 'i', [$variant_id]);
    return (!empty($rows)) ? $rows[0] : null;
}

/**
 * Get materials assigned to a variant.
 */
function get_variant_materials(int $variant_id): array {
    return db_query(
        "SELECT vm.*, m.material_name, m.unit, m.current_stock
         FROM variant_materials vm
         JOIN materials m ON m.material_id = vm.material_id
         WHERE vm.variant_id = ?
         ORDER BY m.material_name",
        'i', [$variant_id]
    ) ?: [];
}

/**
 * Save (overwrite) the BOM for a variant.
 * Deletes existing rows then re-inserts supplied ones.
 *
 * @param int   $variant_id
 * @param array $materials  Each item: ['material_id' => int, 'quantity_required' => float]
 * @return bool
 */
function save_variant_materials(int $variant_id, array $materials): bool {
    global $conn;

    $conn->begin_transaction();
    try {
        // Delete existing BOM rows
        db_execute("DELETE FROM variant_materials WHERE variant_id = ?", 'i', [$variant_id]);

        // Insert new rows
        foreach ($materials as $row) {
            $mid = (int) ($row['material_id'] ?? 0);
            $qty = (float) ($row['quantity_required'] ?? 0);
            if ($mid > 0 && $qty > 0) {
                db_execute(
                    "INSERT INTO variant_materials (variant_id, material_id, quantity_required) VALUES (?, ?, ?)",
                    'iid', [$variant_id, $mid, $qty]
                );
            }
        }

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("save_variant_materials failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Deduct materials based on variant BOM for a specific order and branch.
 *
 * Steps:
 *  1. Fetch branch_id from order
 *  2. Fetch order_items with a non-null variant_id.
 *  3. For each item, compute required quantities (BOM qty × order qty).
 *  4. Validate stock sufficiency for ALL materials at the branch.
 *  5. BEGIN TRANSACTION → deduct stock → log → COMMIT.
 *     On any error → ROLLBACK.
 *
 * @param int $order_id
 * @return array ['success' => bool, 'message' => string, 'errors' => array]
 */
function deduct_materials_by_variant(int $order_id): array {
    global $conn;

    // 1. Get branch_id for the order
    $order_row = db_query("SELECT branch_id FROM orders WHERE order_id = ?", 'i', [$order_id]);
    if (empty($order_row)) {
        return ['success' => false, 'message' => "Order #{$order_id} not found.", 'errors' => []];
    }
    $branch_id = (int)$order_row[0]['branch_id'];

    // 2. Fetch variant-linked items
    $items = db_query(
        "SELECT order_item_id, variant_id, quantity AS order_qty 
         FROM order_items WHERE order_id = ? AND variant_id IS NOT NULL", 
        'i', [$order_id]
    );

    if (empty($items)) {
        return ['success' => false, 'message' => "No variant-linked items found.", 'errors' => []];
    }

    // 3. Aggregate all material requirements
    $requirements = [];
    foreach ($items as $item) {
        $bom = get_variant_materials((int) $item['variant_id']);
        foreach ($bom as $mat) {
            $mid = (int) $mat['material_id'];
            $needed = (float) $mat['quantity_required'] * (int) $item['order_qty'];
            $requirements[$mid] = ($requirements[$mid] ?? 0) + $needed;
        }
    }

    if (empty($requirements)) return ['success' => false, 'message' => "No BOM materials needed.", 'errors' => []];

    // 4. Validate sufficient stock specifically AT THE ASSIGNED BRANCH
    $errors = [];
    foreach ($requirements as $mid => $total_needed) {
        // We use LEFT JOIN so we can still grab the material name even if no inventory record exists in branch_inventory
        $row = db_query(
            "SELECT m.material_name, IFNULL(bi.stock_quantity, 0) as stock_quantity 
             FROM materials m 
             LEFT JOIN branch_inventory bi ON bi.material_id = m.material_id AND bi.branch_id = ?
             WHERE m.material_id = ?
             FOR UPDATE",
            'ii', [$branch_id, $mid]
        );
        
        if (empty($row)) continue;

        $stock = (float) $row[0]['stock_quantity'];
        if ($stock < $total_needed) {
            $errors[] = "Insufficient stock at branch for \"{$row[0]['material_name']}\": need {$total_needed}, have {$stock}.";
        }
    }

    if (!empty($errors)) return ['success' => false, 'message' => "Stock validation failed.", 'errors' => $errors];

    // 5. Deduct stock safely inside transaction
    $startedTransaction = !printflow_db_in_transaction($conn);
    if ($startedTransaction) {
        $conn->begin_transaction();
    }
    try {
        foreach ($requirements as $mid => $total_needed) {
            $affected = db_execute_affected_rows(
                "UPDATE branch_inventory 
                 SET stock_quantity = stock_quantity - ?, last_updated = CURRENT_TIMESTAMP 
                 WHERE material_id = ? AND branch_id = ? AND stock_quantity >= ?",
                'diid', [$total_needed, $mid, $branch_id, $total_needed]
            );
            
            // Validate the row was successfully updated
            if ($affected !== 1) {
                throw new Exception("Material deduction failed for item #{$mid} at physical branch #{$branch_id}.");
            }
        }

        // 6. Push to Material Logs
        foreach ($items as $item) {
            $bom = get_variant_materials((int) $item['variant_id']);
            foreach ($bom as $mat) {
                $deducted = (float) $mat['quantity_required'] * (int) $item['order_qty'];
                if (db_execute(
                    "INSERT INTO material_usage_logs (order_id, order_item_id, variant_id, material_id, quantity_deducted) 
                     VALUES (?, ?, ?, ?, ?)",
                    'iiiid', [$order_id, (int)$item['order_item_id'], (int)$item['variant_id'], $mat['material_id'], $deducted]
                ) === false) {
                    throw new RuntimeException('Failed to record the variant material movement.');
                }
            }
        }

        if ($startedTransaction) $conn->commit();
        return ['success' => true, 'message' => "Materials deducted securely across Branch #{$branch_id}.", 'errors' => []];
    } catch (Throwable $e) {
        if ($startedTransaction && printflow_db_in_transaction($conn)) $conn->rollback();
        return ['success' => false, 'message' => "Deduction transaction error.", 'errors' => [$e->getMessage()]];
    }
}
