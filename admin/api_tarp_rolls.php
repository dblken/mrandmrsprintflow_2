<?php
/**
 * Tarpaulin Rolls API
 */
require_once __DIR__ . '/../includes/api_header.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/TarpaulinService.php';

require_role(['Admin', 'Staff']);

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list_available':
        $width = (int)($_GET['width'] ?? 0);
        if (!$width) {
            echo json_encode(['success' => false, 'error' => 'Width required']);
            break;
        }
        $rolls = TarpaulinService::getAvailableRolls($width);
        echo json_encode(['success' => true, 'rolls' => $rolls]);
        break;

    case 'list_all':
        $rolls = db_query("SELECT r.*, i.name as item_name FROM inv_rolls r JOIN inv_items i ON r.item_id = i.id ORDER BY r.received_at DESC");
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
        
        $item_id = (int)$input['item_id'];
        $roll_code = sanitize($input['roll_code']);
        $width = (int)$input['width_ft'];
        $length = (float)$input['total_length_ft'];
        // Note: unit_cost/supplier columns are optional but supported in Unified inv_rolls
        $cost = (float)($input['unit_cost'] ?? 0);
        $supplier = sanitize($input['supplier'] ?? '');

        try {
            global $conn;
            $stmt = $conn->prepare("INSERT INTO inv_rolls (item_id, roll_code, width_ft, total_length_ft, remaining_length_ft) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("isidd", $item_id, $roll_code, $width, $length, $length);
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Roll added']);
            } else {
                echo json_encode(['success' => false, 'error' => $conn->error]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
?>
