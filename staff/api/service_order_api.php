<?php
/**
 * Staff API: service order detail (JSON) + approve / reject / update status.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/service_order_helper.php';
require_once __DIR__ . '/../../includes/branch_context.php';
require_once __DIR__ . '/../../includes/service_order_staff_modal_data.php';

if (!is_logged_in() || (!is_staff() && !is_admin())) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

service_order_ensure_tables();
$staffBranchId = printflow_branch_filter_for_user();
$serviceOrderBranchSql = '';
$serviceOrderBranchTypes = '';
$serviceOrderBranchParams = [];
if ($staffBranchId !== null) {
    $serviceOrderBranchSql = ' AND (branch_id = ? OR branch_id IS NULL)';
    $serviceOrderBranchTypes = 'i';
    $serviceOrderBranchParams = [(int)$staffBranchId];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && ($_GET['action'] ?? '') === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    $data = service_order_staff_modal_data($id);
    if ($data === null) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

if ($method !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($input)) {
    $input = $_POST;
}

if (!verify_csrf_token($input['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$order_id = (int)($input['order_id'] ?? $input['id'] ?? 0);
if ($order_id < 1) {
    echo json_encode(['success' => false, 'error' => 'Invalid order']);
    exit;
}

$order_row = db_query(
    'SELECT * FROM service_orders WHERE id = ?' . $serviceOrderBranchSql,
    'i' . $serviceOrderBranchTypes,
    array_merge([$order_id], $serviceOrderBranchParams)
);
if (empty($order_row)) {
    echo json_encode(['success' => false, 'error' => 'Order not found']);
    exit;
}
$order_row = $order_row[0];

$op = $input['op'] ?? '';
if ($op === 'update_status' && (string)($input['status'] ?? '') === 'Processing') {
    $op = 'approve';
}

if ($op === 'approve') {
    require_once __DIR__ . '/../../includes/InventoryManager.php';
    require_once __DIR__ . '/../../includes/RollService.php';

    global $conn;
    $transactionStarted = !printflow_db_in_transaction($conn);
    try {
        if ($transactionStarted && !$conn->begin_transaction()) {
            throw new RuntimeException('Unable to start the service inventory transaction.');
        }
        $lockedOrderRows = db_query("SELECT id, status, branch_id FROM service_orders WHERE id = ? FOR UPDATE", 'i', [$order_id]) ?: [];
        if ($lockedOrderRows === []) throw new RuntimeException('The service order no longer exists.');
        $lockedOrder = $lockedOrderRows[0];
        $branchId = (int)($lockedOrder['branch_id'] ?? 0);
        if ($branchId <= 0) {
            throw new RuntimeException('The service order does not identify an inventory branch.');
        }

        // Get materials assigned to this service order
        $materials = db_query(
            "SELECT * FROM job_order_materials
             WHERE std_order_id = ?
               AND (deducted_at IS NULL OR deducted_at = '' OR deducted_at = '0000-00-00 00:00:00')
             FOR UPDATE",
            'i',
            [$order_id]
        );
        
        if ($materials) {
            foreach ($materials as $m) {
                db_query('SELECT id FROM inv_items WHERE id = ? FOR UPDATE', 'i', [(int)$m['item_id']]);
                $item = InventoryManager::getItem($m['item_id']);
                if (!$item) throw new RuntimeException('An assigned service material no longer exists.');

                if ($item['track_by_roll']) {
                    $lengthNeeded = (float)($m['computed_required_length_ft'] ?: $m['quantity']);

                    if ($lengthNeeded <= 0) {
                        db_execute("UPDATE job_order_materials SET deducted_at = NOW() WHERE id = ?", 'i', [$m['id']]);
                        continue;
                    }

                    try {
                        RollService::deductFIFO(
                            $m['item_id'],
                            $lengthNeeded,
                            'SERVICE_ORDER',
                            $order_id,
                            "Deducted for Service Order #{$order_id}",
                            $branchId
                        );
                    } catch (Exception $e) {
                        throw new Exception(
                            "Cannot process Service Order #{$order_id}: Roll stock depleted for '{$item['name']}'. " .
                            "Please receive new stock before approving. (" . $e->getMessage() . ")"
                        );
                    }
                    
                    // Handle lamination if present
                    $metadata = is_string($m['metadata']) ? json_decode($m['metadata'], true) : $m['metadata'];
                    if (is_array($metadata) && isset($metadata['lamination_item_id']) && !empty($metadata['lamination_length_ft'])) {
                        $lamItem = InventoryManager::getItem($metadata['lamination_item_id']);
                        if ($lamItem) {
                            db_query('SELECT id FROM inv_items WHERE id = ? FOR UPDATE', 'i', [(int)$lamItem['id']]);
                            try {
                                if ($lamItem['track_by_roll']) {
                                    RollService::deductFIFO(
                                        $lamItem['id'],
                                        $metadata['lamination_length_ft'],
                                        'SERVICE_ORDER',
                                        $order_id,
                                        "Lamination deducted for Service Order #{$order_id}",
                                        $branchId
                                    );
                                } else {
                                    InventoryManager::issueStock(
                                        $lamItem['id'], 
                                        $metadata['lamination_length_ft'], 
                                        $lamItem['unit_of_measure'], 
                                        'SERVICE_ORDER', 
                                        $order_id, 
                                        "Lamination deducted for Service Order #{$order_id}",
                                        false,
                                        false,
                                        $branchId
                                    );
                                }
                            } catch (Exception $e) {
                                throw new Exception(
                                    "Cannot process Service Order #{$order_id}: Lamination stock depleted for '{$lamItem['name']}'. " .
                                    "Please receive new stock before approving. (" . $e->getMessage() . ")"
                                );
                            }
                        }
                    }

                    db_execute("UPDATE job_order_materials SET deducted_at = NOW() WHERE id = ?", 'i', [$m['id']]);
                } else {
                    // Non-roll deduction
                    InventoryManager::issueStock(
                        $m['item_id'], 
                        $m['quantity'], 
                        $m['uom'], 
                        'SERVICE_ORDER', 
                        $order_id, 
                        "Deducted for Service Order #{$order_id}",
                        false,
                        false,
                        $branchId
                    );
                    db_execute("UPDATE job_order_materials SET deducted_at = NOW() WHERE id = ?", 'i', [$m['id']]);
                }
            }
        }

        // Process Ink Deductions
        if (!db_table_has_column('job_order_ink_usage', 'deducted_at')) {
            $legacyInks = db_query("SELECT id FROM job_order_ink_usage WHERE std_order_id = ? LIMIT 1", 'i', [$order_id]) ?: [];
            if ($legacyInks !== []) {
                throw new RuntimeException('The ink-usage idempotency migration is required before inventory can be deducted.');
            }
            $inks = [];
        } else {
            $inks = db_query(
                "SELECT * FROM job_order_ink_usage
                 WHERE std_order_id = ?
                   AND (deducted_at IS NULL OR deducted_at = '' OR deducted_at = '0000-00-00 00:00:00')
                 FOR UPDATE",
                'i',
                [$order_id]
            ) ?: [];
        }
        if ($inks) {
            foreach ($inks as $ink) {
                db_query('SELECT id FROM inv_items WHERE id = ? FOR UPDATE', 'i', [(int)$ink['item_id']]);
                $inkItem = InventoryManager::getItem($ink['item_id']);
                if (!$inkItem) throw new RuntimeException('An assigned ink inventory item no longer exists.');

                InventoryManager::issueStock(
                    $ink['item_id'],
                    $ink['quantity_used'],
                    $inkItem['unit_of_measure'] ?? 'bottle',
                    'SERVICE_ORDER',
                    $order_id,
                    "{$ink['ink_color']} ink used for Service Order #{$order_id}",
                    false,
                    false,
                    $branchId
                );
                if (db_execute(
                    "UPDATE job_order_ink_usage SET deducted_at = NOW()
                     WHERE id = ?
                       AND (deducted_at IS NULL OR deducted_at = '' OR deducted_at = '0000-00-00 00:00:00')",
                    'i',
                    [(int)$ink['id']]
                ) === false) {
                    throw new RuntimeException('Failed to finalize the ink deduction.');
                }
            }
        }

        if (!db_execute("UPDATE service_orders SET status = 'Processing' WHERE id = ?", 'i', [$order_id])) {
            throw new RuntimeException('Failed to move the service order into production.');
        }
        if ($transactionStarted) $conn->commit();
    } catch (Throwable $e) {
        if ($transactionStarted && printflow_db_in_transaction($conn)) $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
    
    if (function_exists('create_notification')) {
        create_notification(
            (int)$order_row['customer_id'],
            'Customer',
            "Your service order #{$order_id} has been approved and is now in production. Materials have been allocated.",
            'Order',
            true,
            false
        );
    }
} elseif ($op === 'reject') {
    db_execute("UPDATE service_orders SET status = 'Rejected' WHERE id = ?", 'i', [$order_id]);
    if (function_exists('create_notification')) {
        create_notification(
            (int)$order_row['customer_id'],
            'Customer',
            "Your service order #{$order_id} has been rejected.",
            'Order',
            true,
            false
        );
    }
} elseif ($op === 'update_status') {
    $new_status = (string)($input['status'] ?? '');
    if (!in_array($new_status, ['Pending', 'Pending Review', 'Approved', 'Processing', 'Completed', 'Rejected'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid status']);
        exit;
    }
    if ($new_status === 'Completed' && strcasecmp(trim((string)($order_row['status'] ?? '')), 'Processing') !== 0) {
        echo json_encode(['success' => false, 'error' => 'Move the service order to Processing so inventory is deducted before completion.']);
        exit;
    }
    db_execute('UPDATE service_orders SET status = ? WHERE id = ?', 'si', [$new_status, $order_id]);
} else {
    echo json_encode(['success' => false, 'error' => 'Unknown operation']);
    exit;
}

$data = service_order_staff_modal_data($order_id);
echo json_encode(['success' => true, 'data' => $data]);
exit;
