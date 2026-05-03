<?php
/**
 * Inventory Transactions API
 * Handles recording and fetching stock movements
 */
ob_start(); // Prevent accidental output from corrupting JSON
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/InventoryManager.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/product_branch_stock.php';

require_role(['Admin', 'Manager']);
ob_end_clean();
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$user = get_logged_in_user();
$branchCtx = init_branch_context(false);
$selectedBranchId = $branchCtx['selected_branch_id'] ?? InventoryManager::getCurrentBranchId();
$branchId = ($selectedBranchId === 'all') ? 0 : (int)$selectedBranchId;
$inventory_branch_read_only = (($user['role'] ?? '') === 'Admin') && $branchId > 0 && !InventoryManager::isMainBranch($branchId);

try {
    switch ($action) {
        case 'get_transactions':
            $item_id = (int)($_GET['item_id'] ?? 0);
            $type = sanitize($_GET['type'] ?? '');
            $start_date = sanitize($_GET['start_date'] ?? '');
            $end_date = sanitize($_GET['end_date'] ?? '');
            $sort = sanitize($_GET['sort'] ?? 'transaction_date');
            $dir  = strtoupper(sanitize($_GET['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
            
            $sort_map = [
                'id' => 't.id',
                'transaction_date' => 't.transaction_date',
                'item_name' => 'item_name',
                'direction' => 't.direction',
                'quantity' => 't.quantity'
            ];
            $orderBy = $sort_map[$sort] ?? 't.transaction_date';

            printflow_ensure_product_inventory_transaction_schema();
            $hasProductIdColumn = db_table_has_column('inventory_transactions', 'product_id');
            $productNameExpr = $hasProductIdColumn
                ? "NULLIF(TRIM(p_direct.name), '')"
                : "NULL";
            $legacyProductNameExpr = "NULLIF(TRIM(p_item.name), '')";
            $productKindExpr = $hasProductIdColumn
                ? "(t.product_id IS NOT NULL AND t.product_id > 0)"
                : "0";
            $legacyProductKindExpr = "(UPPER(t.ref_type) IN ('ORDER', 'PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT') AND p_item.product_id IS NOT NULL)";
            $productLikeExpr = "({$productKindExpr} OR {$legacyProductKindExpr} OR UPPER(t.ref_type) IN ('PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT', 'ORDER_PRODUCT', 'ORDER'))";
            $itemNameSql = "COALESCE({$productNameExpr}, {$legacyProductNameExpr}, NULLIF(TRIM(p_ref.name), ''), i.name, CASE WHEN {$productKindExpr} OR {$legacyProductKindExpr} OR UPPER(t.ref_type) IN ('PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT', 'ORDER_PRODUCT') THEN CONCAT('Product #', COALESCE(t.ref_id, t.item_id)) ELSE CONCAT('Item #', t.item_id) END)";

            $sql = "SELECT t.*, {$itemNameSql} as item_name, CASE
                           WHEN {$productKindExpr} OR {$legacyProductKindExpr} OR UPPER(t.ref_type) IN ('PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT', 'ORDER_PRODUCT')
                               THEN 'pcs'
                           ELSE COALESCE(NULLIF(TRIM(i.unit_of_measure), ''), NULLIF(TRIM(t.uom), ''), 'pcs')
                       END as unit, 
                           CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
                           r.roll_code as roll_code
                    FROM inventory_transactions t
                    LEFT JOIN inv_items i ON t.item_id = i.id
                    " . ($hasProductIdColumn ? "LEFT JOIN products p_direct ON t.product_id = p_direct.product_id" : "") . "
                    LEFT JOIN products p_item ON UPPER(t.ref_type) IN ('ORDER', 'PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT') AND t.item_id = p_item.product_id
                    LEFT JOIN products p_ref ON UPPER(t.ref_type) IN ('PRODUCT_CREATE', 'PRODUCT_ADJUSTMENT', 'ORDER_PRODUCT') AND t.ref_id = p_ref.product_id
                    LEFT JOIN users u ON t.created_by = u.user_id
                    LEFT JOIN inv_rolls r ON t.roll_id = r.id
                    WHERE 1=1";
            $params = [];
            $types = '';
            if ($branchId > 0) {
                if (InventoryManager::isMainBranch($branchId)) {
                    $sql .= " AND (t.branch_id = ? OR t.branch_id IS NULL)";
                } else {
                    $sql .= " AND t.branch_id = ?";
                }
                $types .= 'i';
                $params[] = $branchId;
            }
            
            $search     = sanitize($_GET['search'] ?? '');
            
            if ($item_id) {
                $sql .= " AND t.item_id = ?";
                $params[] = $item_id;
                $types .= 'i';
            }
            if ($type) {
                // If type is IN or OUT filter by direction
                if (in_array(strtoupper($type), ['IN', 'OUT'])) {
                    $sql .= " AND t.direction = ?";
                } else {
                    $sql .= " AND t.ref_type = ?";
                }
                $params[] = $type;
                $types .= 's';
            }
            if ($search) {
                $st = '%' . $search . '%';
                $sql .= " AND ({$itemNameSql} LIKE ? OR t.notes LIKE ? OR CAST(t.ref_id AS CHAR) LIKE ?)";
                $params[] = $st; $params[] = $st; $params[] = $st;
                $types .= 'sss';
            }
            if ($start_date && $end_date) {
                $sql .= " AND t.transaction_date BETWEEN ? AND ?";
                $params[] = $start_date;
                $params[] = $end_date;
                $types .= 'ss';
            }
            
            $sql .= " ORDER BY $orderBy $dir, t.id DESC LIMIT 500";
            
            $transactions = db_query($sql, $types ?: null, $params ?: null) ?: [];
            
            echo json_encode(['success' => true, 'data' => $transactions]);
            break;

        case 'record_transaction':
            if ($inventory_branch_read_only) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'This branch is view-only. Stock changes are not allowed here.']);
                exit;
            }
            // Extract POST data (form sends: item_id, transaction_type, transaction_date, quantity, reference_type, reference_id, notes)
            $item_id = (int)($_POST['item_id'] ?? 0);
            $type = sanitize($_POST['transaction_type'] ?? '');
            $quantity = (float)($_POST['quantity'] ?? 0);
            $ref_type = sanitize($_POST['reference_type'] ?? '');
            $ref_id_raw = trim($_POST['reference_id'] ?? '');
            $notes = sanitize($_POST['notes'] ?? '');
            $transaction_date = sanitize($_POST['transaction_date'] ?? '') ?: date('Y-m-d');
            $ref_id = (is_numeric($ref_id_raw) && $ref_id_raw !== '') ? (int)$ref_id_raw : null;
            if ($ref_id === null && $ref_id_raw !== '') {
                $notes = trim($notes . ($notes ? ' | ' : '') . 'Ref: ' . $ref_id_raw);
            }

            // Optional: unit cost used for weighted-average updates on IN transactions
            $unit_cost_new = null;
            if (array_key_exists('unit_cost', $_POST)) {
                $unit_cost_raw = trim((string)($_POST['unit_cost'] ?? ''));
                if ($unit_cost_raw !== '') $unit_cost_new = (float)$unit_cost_raw;
            }

            $errors = [];
            if (!$item_id) $errors['item_id'] = "Item is required.";
            if (empty($type)) $errors['transaction_type'] = "Transaction type is required.";
            if ($quantity <= 0) $errors['quantity'] = "Quantity must be greater than zero.";
            if ($unit_cost_new !== null && $unit_cost_new <= 0) $errors['unit_cost'] = "Unit cost must be greater than zero.";

            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => implode(' ', $errors), 'errors' => $errors]);
                exit;
            }

            // Use Ref Category for ref_type when provided, else derive from transaction_type
            $refType = $ref_type ?: $type;

            // Mapping old types to direction
            $inTypes = ['opening_balance', 'purchase', 'return', 'transfer_in', 'adjustment_up'];
            $direction = in_array($type, $inTypes) ? 'IN' : 'OUT';

            if ($direction === 'IN') {
                $rollData = null;
                if (!empty($_POST['roll_code']) || !empty($_POST['width_ft'])) {
                    $rollData = [
                        'roll_code' => sanitize($_POST['roll_code'] ?? ''),
                        'width_ft'  => (float)($_POST['width_ft'] ?? 0)
                    ];
                }

                // Weighted-average cost update (optional; only runs when unit_cost is provided)
                $q_old = null;
                $c_old = null;
                if ($unit_cost_new !== null && $unit_cost_new > 0) {
                    $q_old = InventoryManager::getStockOnHand($item_id, $branchId);
                    $itemForCost = InventoryManager::getItem($item_id);
                    $c_old = (float)($itemForCost['unit_cost'] ?? 0);
                    $notes = trim($notes . ($notes ? ' | ' : '') . 'Unit cost used: ' . number_format($unit_cost_new, 2, '.', ''));
                }

                // For IN transactions, use receiveStock to handle roll tracking logic
                $success = InventoryManager::receiveStock($item_id, $quantity, $_POST['uom'] ?? null, $rollData, $refType, $ref_id, $notes, $transaction_date, $branchId);
                $transactionId = 0; 
                $fifoResult = null;

                if ($success && $unit_cost_new !== null && $unit_cost_new > 0) {
                    $q_total = ($q_old ?? 0) + $quantity;
                    if ($q_total > 0) {
                        $c_updated = ((($q_old ?? 0) * ($c_old ?? 0)) + ($quantity * $unit_cost_new)) / $q_total;
                    } else {
                        // First stock entry safeguard
                        $c_updated = $unit_cost_new;
                    }
                    db_execute("UPDATE inv_items SET unit_cost = ? WHERE id = ?", 'di', [$c_updated, (int)$item_id]);
                }
            } else {
                // For OUT transactions, use issueStock which handles FIFO for roll items
                $result = InventoryManager::issueStock(
                    $item_id,
                    $quantity,
                    $_POST['uom'] ?? null,
                    $refType ?: 'ADJUSTMENT',
                    $ref_id,
                    $notes,
                    false,
                    false,
                    $branchId
                );
                
                // issueStock returns an array of roll deductions for roll items, or a transaction ID for non-roll
                if (is_array($result)) {
                    $transactionId = 0;
                    $fifoResult = $result;
                } else {
                    $transactionId = $result;
                    $fifoResult = null;
                }
            }
            
            $response = ['success' => true, 'transaction_id' => $transactionId];
            if ($fifoResult) {
                $response['fifo_deductions'] = $fifoResult;
            }
            echo json_encode($response);
            break;

        case 'get_current_stock':
            $item_id = (int)($_GET['item_id'] ?? 0);
            if (!$item_id) throw new Exception("Item ID required");
            $soh = InventoryManager::getStockOnHand($item_id, $branchId);
            echo json_encode(['success' => true, 'soh' => $soh]);
            break;

        case 'get_history':
            $item_id = (int)($_GET['item_id'] ?? 0);
            if (!$item_id) throw new Exception("Item ID required");
            
            // Get last 30 days of data
            $days = [];
            for ($i = 29; $i >= 0; $i--) {
                $days[] = date('Y-m-d', strtotime("-$i days"));
            }
            
            $start = $days[0];
            $end = $days[29];
            
            // 1. Get initial stock BEFORE the 30-day window
            [$histBranchSql, $histBranchTypes, $histBranchParams] = InventoryManager::branchClause('branch_id', $branchId);
            $preBalance = db_query(
                "SELECT SUM(IF(direction='IN', quantity, -quantity)) as balance
                 FROM inventory_transactions
                 WHERE item_id = ? AND transaction_date < ?{$histBranchSql}",
                'is' . $histBranchTypes,
                array_merge([$item_id, $start], $histBranchParams)
            );
            $runningBalance = (float)$preBalance[0]['balance'];
            
            // 2. Get all movements in the 30-day window
            $movements = db_query(
                "SELECT transaction_date as t_date, SUM(IF(direction='IN', quantity, -quantity)) as daily_total 
                 FROM inventory_transactions 
                 WHERE item_id = ? AND transaction_date BETWEEN ? AND ?{$histBranchSql}
                 GROUP BY t_date ORDER BY t_date ASC",
                'iss' . $histBranchTypes, array_merge([$item_id, $start, $end], $histBranchParams)
            );
            
            $movementMap = [];
            foreach ($movements as $m) {
                $movementMap[$m['t_date']] = (float)$m['daily_total'];
            }
            
            // 3. Build the daily trend
            $history = [];
            foreach ($days as $date) {
                $change = $movementMap[$date] ?? 0;
                $runningBalance += $change;
                $history[] = [
                    'date' => $date,
                    'stock' => $runningBalance,
                    'change' => $change
                ];
            }
            
            echo json_encode(['success' => true, 'data' => $history]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . ($action ?: '(empty)')]);
            break;
    }
} catch (Throwable $e) {
    ob_clean();
    if (headers_sent() === false) {
        http_response_code(400);
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
