<?php
/**
 * Inventory API — AJAX Endpoint
 * PrintFlow - Dynamic Inventory Module
 * Handles all AJAX requests for inventory operations
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/InventoryManager.php';
require_once __DIR__ . '/../includes/branch_context.php';

require_role(['Admin', 'Manager']);

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$user = get_logged_in_user();
$branchCtx = init_branch_context(false);
$selectedBranchId = $branchCtx['selected_branch_id'] ?? InventoryManager::getCurrentBranchId();
$branchId = ($selectedBranchId === 'all') ? 0 : (int)$selectedBranchId;
if (($user['role'] ?? '') === 'Manager') {
    $managerAssignedBranchId = (int)($user['branch_id'] ?? ($_SESSION['branch_id'] ?? 0));
    if ($managerAssignedBranchId <= 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No branch is assigned to your account.']);
        exit;
    }
    $branchId = $managerAssignedBranchId;
    $_SESSION['selected_branch_id'] = $managerAssignedBranchId;
    $_GET['branch_id'] = $managerAssignedBranchId;
}

try {
    switch ($action) {

        // ─── Categories ───────────────────────────────────
        case 'get_categories':
            $cats = db_query("SELECT id as category_id, name as category_name FROM inv_categories ORDER BY name ASC");
            echo json_encode(['success' => true, 'data' => $cats ?: []]);
            break;

        case 'create_category':
            $name = sanitize($_POST['category_name'] ?? '');
            if (empty($name)) throw new Exception('Category name is required');
            $id = db_execute("INSERT INTO inv_categories (name) VALUES (?)", 's', [$name]);
            echo json_encode(['success' => true, 'category_id' => $id]);
            break;

        case 'delete_category':
            $id = (int)($_POST['category_id'] ?? 0);
            if (!$id) throw new Exception('Invalid category');
            db_execute("DELETE FROM inv_categories WHERE id = ?", 'i', [$id]);
            echo json_encode(['success' => true]);
            break;

        // ─── Materials ────────────────────────────────────
        case 'get_materials':
            $cat_id = (int)($_GET['category_id'] ?? 0);
            $sql = "SELECT id as material_id, name as material_name, unit_of_measure as unit FROM inv_items";
            $params = [];
            $types = '';
            if ($cat_id) {
                $sql .= " WHERE category_id = ?";
                $params[] = $cat_id;
                $types = 'i';
            }
            $sql .= " ORDER BY name ASC";
            $mats = db_query($sql, $types, $params);
            echo json_encode(['success' => true, 'data' => $mats ?: []]);
            break;

        case 'create_material':
            $cat_id = (int)($_POST['category_id'] ?? 0);
            $name = sanitize($_POST['material_name'] ?? '');
            $opening = (float)($_POST['opening_stock'] ?? 0);
            $unit = sanitize($_POST['unit'] ?? 'ft');
            if (!$cat_id || empty($name)) throw new Exception('Category and material name are required');
            $id = db_execute(
                "INSERT INTO materials (category_id, material_name, opening_stock, current_stock, unit) VALUES (?, ?, ?, ?, ?)",
                'isdds', [$cat_id, $name, $opening, $opening, $unit]
            );
            echo json_encode(['success' => true, 'material_id' => $id]);
            break;

        case 'update_material':
            $mid = (int)($_POST['material_id'] ?? 0);
            $name = sanitize($_POST['material_name'] ?? '');
            $opening = (float)($_POST['opening_stock'] ?? 0);
            $unit = sanitize($_POST['unit'] ?? 'ft');
            if (!$mid) throw new Exception('Invalid material');
            db_execute(
                "UPDATE materials SET material_name = ?, opening_stock = ?, unit = ? WHERE material_id = ?",
                'sdsi', [$name, $opening, $unit, $mid]
            );
            echo json_encode(['success' => true]);
            break;

        case 'delete_material':
            $mid = (int)($_POST['material_id'] ?? 0);
            if (!$mid) throw new Exception('Invalid material');
            db_execute("DELETE FROM materials WHERE material_id = ?", 'i', [$mid]);
            echo json_encode(['success' => true]);
            break;

        // ─── Monthly Data ─────────────────────────────────
        case 'get_monthly_data':
            $cat_id = (int)($_GET['category_id'] ?? 0);
            $month  = (int)($_GET['month'] ?? date('n'));
            $year   = (int)($_GET['year'] ?? date('Y'));
            if (!$cat_id) throw new Exception('Category required');

            // Get materials in this category
            $materials = db_query(
                "SELECT id as material_id, name as material_name, unit_of_measure as unit FROM inv_items WHERE category_id = ? ORDER BY name ASC",
                'i', [$cat_id]
            );

            $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);

            $result = [];
            foreach ($materials ?: [] as $mat) {
                $mid = $mat['material_id'];

                // Get all movements for this material in this month
                $movements = db_query(
                    "SELECT DAY(transaction_date) as day_num, SUM(IF(direction='IN', quantity, -quantity)) as quantity_change 
                     FROM inventory_transactions 
                     WHERE item_id = ? AND MONTH(transaction_date) = ? AND YEAR(transaction_date) = ?
                     GROUP BY day_num
                     ORDER BY day_num ASC",
                    'iii', [$mid, $month, $year]
                );

                // Build day map
                $day_data = [];
                $total_change = 0;
                foreach ($movements ?: [] as $mv) {
                    $day_data[(int)$mv['day_num']] = (float)$mv['quantity_change'];
                    $total_change += (float)$mv['quantity_change'];
                }

                $result[] = [
                    'material_id'   => $mid,
                    'material_name' => $mat['material_name'],
                    'opening_stock' => 0, // Simplified for now since opening is a txn
                    'unit'          => $mat['unit'],
                    'days'          => $day_data,
                    'total_stock'   => InventoryManager::getStockOnHand($mid)
                ];
            }

            echo json_encode([
                'success'       => true,
                'days_in_month' => $days_in_month,
                'month'         => $month,
                'year'          => $year,
                'materials'     => $result
            ]);
            break;

        // ─── Save Movement (Upsert) ──────────────────────
        case 'save_movement':
            if ($branchId <= 0) {
                throw new Exception('Please select a specific branch before saving an inventory movement.');
            }
            $mid   = (int)($_POST['material_id'] ?? 0);
            $date  = $_POST['movement_date'] ?? '';
            $qty   = (float)($_POST['quantity_change'] ?? 0);
            $notes = sanitize($_POST['notes'] ?? 'Manual entry');

            if (!$mid || empty($date)) throw new Exception('Material ID and date required');

            // Validate date format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new Exception('Invalid date format');

            if ($qty == 0) {
                // Not strictly supported by ledger to just 'delete' a movement, 
                // but we can delete the manual adjustment for that date
                db_execute(
                    "DELETE FROM inventory_transactions WHERE item_id = ? AND transaction_date = ? AND ref_type = 'adjustment_manual' AND branch_id = ?",
                    'isi', [$mid, $date, $branchId]
                );
            } else {
                // Record via InventoryManager
                // Mapping: negative quantity in UI means usage (OUT), positive means IN
                $direction = $qty > 0 ? 'IN' : 'OUT';
                $abs_qty = abs($qty);
                
                // Idempotency: try to update existing manual adjustment for that day or insert new
                $existing = db_query("SELECT id FROM inventory_transactions WHERE item_id = ? AND transaction_date = ? AND ref_type = 'adjustment_manual' AND branch_id = ?", 'isi', [$mid, $date, $branchId]);
                
                if (!empty($existing)) {
                    db_execute("UPDATE inventory_transactions SET quantity = ?, direction = ?, notes = ? WHERE id = ?", 'dssi', [$abs_qty, $direction, $notes, $existing[0]['id']]);
                } else {
                    InventoryManager::recordTransaction($mid, $direction, $abs_qty, null, 'adjustment_manual', null, null, $notes, null, $date, $branchId);
                }
            }

            $new_stock = InventoryManager::getStockOnHand($mid, $branchId);
            echo json_encode(['success' => true, 'new_total' => $new_stock]);
            break;

        default:
            throw new Exception('Unknown action: ' . $action);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
