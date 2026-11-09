<?php
/**
 * Tarpaulin Rolls API
 */
require_once __DIR__ . '/../includes/api_header.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/TarpaulinService.php';
require_once __DIR__ . '/../includes/RollService.php';
require_once __DIR__ . '/../includes/roll_width.php';
require_once __DIR__ . '/../includes/branch_context.php';

require_role(['Admin', 'Staff']);

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list_available':
        $width = (int)($_GET['width'] ?? 0);
        $orderId = (int)($_GET['order_id'] ?? 0);
        if (!$width || $orderId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Order and width are required']);
            break;
        }
        printflow_assert_order_branch_access($orderId);
        $orderRows = db_query('SELECT branch_id FROM orders WHERE order_id = ? LIMIT 1', 'i', [$orderId]) ?: [];
        $orderBranchId = (int)($orderRows[0]['branch_id'] ?? 0);
        if ($orderBranchId <= 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'The order branch is unavailable; no branch-wide roll list was returned.']);
            break;
        }
        $rolls = TarpaulinService::getAvailableRolls($width, $orderBranchId);
        echo json_encode(['success' => true, 'rolls' => $rolls]);
        break;

    case 'list_all':
        if (get_user_type() === 'Admin') {
            $rolls = db_query("SELECT r.*, i.name as item_name FROM inv_rolls r JOIN inv_items i ON r.item_id = i.id ORDER BY r.received_at DESC");
        } else {
            $branchId = (int)(printflow_branch_filter_for_user() ?? ($_SESSION['branch_id'] ?? 0));
            if ($branchId <= 0) {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'Your assigned branch could not be verified; no unscoped roll list was returned.']);
                break;
            }
            $rolls = db_query(
                'SELECT r.*, i.name AS item_name FROM inv_rolls r JOIN inv_items i ON r.item_id = i.id WHERE r.branch_id = ? ORDER BY r.received_at DESC',
                'i',
                [$branchId]
            );
        }
        echo json_encode(['success' => true, 'rolls' => $rolls]);
        break;

    case 'add_roll':
        require_role(['Admin', 'Manager']);
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            echo json_encode(['success' => false, 'error' => 'Invalid data']);
            break;
        }
        if (!verify_csrf_token($input['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            break;
        }
        
        $item_id = (int)($input['item_id'] ?? 0);
        $roll_code = sanitize($input['roll_code'] ?? '');
        $length = (float)($input['total_length_ft'] ?? 0);
        // Note: unit_cost/supplier columns are optional but supported in Unified inv_rolls
        $cost = (float)($input['unit_cost'] ?? 0);
        $supplier = sanitize($input['supplier'] ?? '');

        try {
            $width = printflow_roll_width_to_feet($input['roll_width'] ?? $input['width_ft'] ?? '', (string)($input['roll_width_unit'] ?? (isset($input['width_ft']) ? 'ft' : '')));
            if ($item_id <= 0 || $width <= 0 || !is_finite($length) || $length <= 0) throw new InvalidArgumentException('Valid material, width, and length are required.');
            $rollId = RollService::createRoll($item_id, $length, $roll_code, $supplier, $width);
            echo json_encode(['success' => true, 'message' => 'Roll added', 'roll_id' => (int)$rollId]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
?>
