<?php
/**
 * Inventory Rolls API
 * Roll-specific management.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/RollService.php';
require_once __DIR__ . '/../includes/branch_context.php';

require_role(['Admin', 'Manager', 'Staff']);
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function printflow_inventory_rolls_require_csrf(): void {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        echo json_encode(['success' => false, 'error' => 'Invalid session token.']);
        exit;
    }
}

try {
    switch ($action) {
        case 'list':
        case 'list_rolls':
            $itemId = (int)($_GET['item_id'] ?? 0);
            $status = sanitize($_GET['status'] ?? '');
            $branchId = null;
            if (is_staff() || get_user_type() === 'Manager') {
                $branchId = printflow_branch_filter_for_user() ?? (int)($_SESSION['branch_id'] ?? 0);
            }

            if ($itemId > 0) {
                $rolls = RollService::getAvailableRolls($itemId, $branchId);
                if ($status !== '' && strtoupper($status) !== 'OPEN') {
                    $rolls = array_values(array_filter($rolls, function ($r) use ($status) {
                        return (string)($r['status'] ?? '') === $status;
                    }));
                }
            } else {
                $where = ['1=1'];
                $types = '';
                $params = [];

                if ($status !== '') {
                    $where[] = 'r.status = ?';
                    $types .= 's';
                    $params[] = $status;
                }
                if ($branchId !== null && $branchId > 0) {
                    $where[] = 'r.branch_id = ?';
                    $types .= 'i';
                    $params[] = $branchId;
                }

                $sql = "SELECT r.*, i.name AS item_name
                        FROM inv_rolls r
                        JOIN inv_items i ON i.id = r.item_id
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY r.status ASC, r.received_at ASC";

                $rolls = $types ? db_query($sql, $types, $params) : db_query($sql);
            }
            echo json_encode(['success' => true, 'data' => $rolls ?: []]);
            break;

        case 'add_roll':
            printflow_inventory_rolls_require_csrf();
            $itemId = (int)($_POST['item_id'] ?? 0);
            $len = (float)($_POST['total_length'] ?? 0);
            $code = sanitize($_POST['roll_code'] ?? '');
            $supplier = sanitize($_POST['supplier'] ?? '');
            
            if (!$itemId || $len <= 0) throw new Exception("Invalid item or length.");
            
            $rollId = RollService::createRoll($itemId, $len, $code, $supplier);
            echo json_encode(['success' => true, 'roll_id' => $rollId]);
            break;

        case 'void_roll':
            printflow_inventory_rolls_require_csrf();
            $rollId = (int)($_POST['roll_id'] ?? 0);
            $notes = sanitize($_POST['notes'] ?? '');
            if (!$rollId) throw new Exception("Roll ID required.");
            
            $res = RollService::voidRoll($rollId, $notes);
            echo json_encode(['success' => $res]);
            break;

        default:
            throw new Exception("Unknown action: $action");
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
