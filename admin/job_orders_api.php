<?php
/**
 * Job Orders API
 * Admin/Staff CRUD for job orders and material assignment.
 */

// Prevent any output before JSON
ob_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/JobOrderService.php';
require_once __DIR__ . '/../includes/service_order_helper.php';

if (!is_logged_in()) {
    jo_api_json_response(['success' => false, 'error' => 'Unauthorized'], 401);
}
if (!has_role(['Admin', 'Manager', 'Staff', 'Customer'])) {
    jo_api_json_response(['success' => false, 'error' => 'Forbidden'], 403);
}

// Clear any buffered output from includes
ob_end_clean();

// Start fresh buffer for JSON output only
ob_start();

header('Content-Type: application/json');

function jo_api_json_response(array $payload, int $statusCode = 200): never {
    http_response_code($statusCode);
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        http_response_code(500);
        $json = '{"success":false,"error":"Failed to encode JSON response."}';
    }
    echo $json;
    exit;
}

/** Staff / Manager only see/manage job orders for their assigned branch. */
$joStaffBranch = null;
if (is_staff() || get_user_type() === 'Manager') {
    $joStaffBranch = printflow_branch_filter_for_user() ?? (int)($_SESSION['branch_id'] ?? 1);
    if ($joStaffBranch < 1) {
        $joStaffBranch = 1;
    }
}

/**
 * Ensure a job_orders row is visible to the current staff/manager branch (Admin/Customer: no-op).
 */
function jo_api_require_staff_branch(?int $staffBranch, int $jobId): void {
    if ($staffBranch === null || $jobId <= 0) {
        return;
    }
    $row = db_query(
        'SELECT COALESCE(jo.branch_id, o.branch_id) AS b, jo.order_id FROM job_orders jo LEFT JOIN orders o ON o.order_id = jo.order_id WHERE jo.id = ? LIMIT 1',
        'i',
        [$jobId]
    );
    $b = (int)($row[0]['b'] ?? 0);
    $orderId = (int)($row[0]['order_id'] ?? 0);
    $branchMatches =
        ($b > 0 && $b === $staffBranch) ||
        ($orderId > 0 && printflow_order_in_branch($orderId, $staffBranch));
    if (!$branchMatches) {
        throw new Exception('Unauthorized');
    }
    if ($b <= 0 && $orderId > 0) {
        db_execute(
            'UPDATE job_orders SET branch_id = ? WHERE id = ? AND (branch_id IS NULL OR branch_id = 0)',
            'ii',
            [$staffBranch, $jobId]
        );
    }
}

function jo_api_require_staff_order_branch(?int $staffBranch, int $orderId): void {
    if ($staffBranch === null || $orderId <= 0) {
        return;
    }
    if (!printflow_order_in_branch($orderId, $staffBranch)) {
        throw new Exception('Unauthorized');
    }
}

function jo_api_normalize_customer_type($customerType, $transactionCount = null): string {
    $raw = strtoupper(trim((string)$customerType));
    if ($raw === 'REGULAR' || $raw === 'RETURNING') {
        return 'REGULAR';
    }
    if ($raw === 'NEW') {
        return 'NEW';
    }
    if ($raw !== '') {
        return $raw;
    }
    return ((int)$transactionCount >= 5) ? 'REGULAR' : 'NEW';
}

function jo_api_resolve_order_source(?int $orderId, $currentSource = null): string {
    $source = strtolower(trim((string)$currentSource));
    if (in_array($source, ['pos', 'walk-in'], true)) {
        return $source;
    }

    if (($source === '' || $source === 'customer') && !empty($orderId)) {
        $posCheck = db_query(
            "SELECT 1 FROM customizations WHERE order_id = ? AND customization_details LIKE '%\"source\":\"POS\"%' LIMIT 1",
            'i',
            [$orderId]
        );
        if (!empty($posCheck)) {
            db_execute(
                "UPDATE orders SET order_source = 'pos' WHERE order_id = ? AND (order_source IS NULL OR order_source = 'customer' OR order_source = '')",
                'i',
                [$orderId]
            );
            return 'pos';
        }
    }

    return $source !== '' ? $source : 'customer';
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$serviceOnly = in_array(strtolower((string)($_GET['service_only'] ?? $_POST['service_only'] ?? '')), ['1', 'true', 'yes'], true);

try {
    switch ($action) {
        case 'list_orders':
            $status = sanitize($_GET['status'] ?? '');
            $sql = "SELECT jo.*, c.first_name, c.last_name, c.customer_type, c.transaction_count,
                           c.profile_picture AS customer_profile_picture,
                           TRIM(CONCAT_WS(', ', NULLIF(TRIM(c.street_address), ''), NULLIF(TRIM(c.barangay), ''), NULLIF(TRIM(c.city), ''))) AS customer_address,
                           COALESCE(NULLIF(TRIM(c.contact_number), ''), NULLIF(TRIM(c.email), '')) AS customer_contact,
                           o.order_source
                    FROM job_orders jo 
                    LEFT JOIN orders o ON o.order_id = jo.order_id
                    LEFT JOIN customers c ON jo.customer_id = c.customer_id 
                    WHERE 1=1";
            $params = []; $types = '';
            if ($status) {
                $sql .= " AND jo.status = ?";
                $params[] = $status; $types .= 's';
            }
            if (isset($_GET['customer_id'])) {
                $sql .= " AND jo.customer_id = ?";
                $params[] = (int)$_GET['customer_id']; $types .= 'i';
            }
            
            
            if ($joStaffBranch !== null) {
                $sql .= " AND COALESCE(jo.branch_id, (SELECT o2.branch_id FROM orders o2 WHERE o2.order_id = jo.order_id LIMIT 1)) = ?";
                $params[] = $joStaffBranch;
                $types .= 'i';
            }
            if ($serviceOnly) {
                $sql .= " AND (
                    jo.order_id IS NULL
                    OR EXISTS (
                        SELECT 1
                        FROM orders o_scope
                        JOIN order_items oi_scope ON oi_scope.order_id = o_scope.order_id
                        LEFT JOIN products p_scope ON p_scope.product_id = oi_scope.product_id
                        WHERE o_scope.order_id = jo.order_id
                          AND o_scope.order_type = 'custom'
                          AND (
                              COALESCE(LOWER(TRIM(p_scope.product_type)), 'custom') <> 'fixed'
                              OR LOWER(TRIM(p_scope.category)) LIKE '%service%'
                          )
                    )
                )";
            }
            
            // Pagination
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per_page = isset($_GET['customer_id'])
                ? 10
                : min(500, max(1, (int)($_GET['per_page'] ?? 250)));
            $offset = ($page - 1) * $per_page;
            
            // Get total count (match main FROM job_orders only, not subqueries in SELECT)
            $count_sql = preg_replace(
                '/^SELECT\s+[\s\S]*?\sFROM\s+job_orders\s+jo\s+/i',
                'SELECT COUNT(*) as total FROM job_orders jo ',
                $sql,
                1
            );
            $total_count = db_query($count_sql, $types ?: null, $params ?: null)[0]['total'] ?? 0;
            
            $sql .= " ORDER BY jo.priority = 'HIGH' DESC, jo.due_date ASC, jo.created_at DESC LIMIT ? OFFSET ?";
            $params[] = $per_page; $params[] = $offset; $types .= 'ii';
            $orders = db_query($sql, $types ?: null, $params ?: null) ?: [];
            
            if (!empty($orders)) {
                $orderIds = array_column($orders, 'id');
                $ids_str = implode(',', array_map('intval', $orderIds));

                // 1. Batch Fetch ALL Materials for these jobs
                $materials = db_query("SELECT m.*, i.track_by_roll FROM job_order_materials m JOIN inv_items i ON m.item_id = i.id WHERE m.job_order_id IN ($ids_str)") ?: [];
                $materialsByJob = [];
                $item_ids_needed = [];
                foreach ($materials as $m) {
                    $materialsByJob[$m['job_order_id']][] = $m;
                    $item_ids_needed[] = $m['item_id'];
                    $meta = json_decode($m['metadata'] ?? '{}', true);
                    if (!empty($meta['lamination_item_id'])) $item_ids_needed[] = $meta['lamination_item_id'];
                }

                // 2. Batch Fetch ALL Inks for these jobs
                $inks = db_query("SELECT * FROM job_order_ink_usage WHERE job_order_id IN ($ids_str)") ?: [];
                $inksByJob = [];
                foreach ($inks as $ink) {
                    $inksByJob[$ink['job_order_id']][] = $ink;
                    $item_ids_needed[] = $ink['item_id'];
                }

                // 3. Batch Fetch SOH for all items needed
                $stockMap = [];
                if (!empty($item_ids_needed)) {
                    $unique_items = array_unique($item_ids_needed);
                    $items_str = implode(',', array_map('intval', $unique_items));
                    $branchFilterSql = '';
                    $branchFilterTypes = '';
                    $branchFilterParams = [];
                    if ($joStaffBranch !== null) {
                        $branchFilterSql = " AND branch_id = ?";
                        $branchFilterTypes = 'i';
                        $branchFilterParams = [$joStaffBranch];
                    }
                    
                    // From rolls
                    $rollStocks = db_query(
                        "SELECT item_id, SUM(remaining_length_ft) as soh FROM inv_rolls WHERE item_id IN ($items_str) AND status = 'OPEN'{$branchFilterSql} GROUP BY item_id",
                        $branchFilterTypes ?: null,
                        $branchFilterParams ?: null
                    ) ?: [];
                    foreach ($rollStocks as $rs) $stockMap[$rs['item_id']] = (float)$rs['soh'];
                    
                    // From transactions (for non-roll items)
                    $transStocks = db_query(
                        "SELECT item_id, SUM(IF(direction='IN', quantity, -quantity)) as soh FROM inventory_transactions WHERE item_id IN ($items_str){$branchFilterSql} GROUP BY item_id",
                        $branchFilterTypes ?: null,
                        $branchFilterParams ?: null
                    ) ?: [];
                    foreach ($transStocks as $ts) {
                        if (!isset($stockMap[$ts['item_id']])) $stockMap[$ts['item_id']] = (float)$ts['soh'];
                    }
                }

                // 4. Enrich orders using the pre-fetched data
                $visibleOrders = [];
                foreach ($orders as $jo) {
                    $jo['order_type'] = 'JOB';
                    $jo['order_source'] = jo_api_resolve_order_source(
                        (int)($jo['order_id'] ?? 0),
                        $jo['order_source'] ?? null
                    );
                    if (!empty($jo['order_id'])) {
                        $payload = JobOrderService::getStoreOrderItemsPayload((int)$jo['order_id'], $serviceOnly);
                        if ($serviceOnly && empty($payload['items'])) {
                            continue;
                        }
                        if (!empty($payload['service_type'])) {
                            $jo['service_type'] = $payload['service_type'];
                            $jo['job_title'] = $payload['service_type'];
                        }
                        $jo['order_code'] = printflow_get_order_inventory_reference((int)$jo['order_id'])['code'] ?? '';
                    } else {
                        $jo['order_code'] = printflow_get_job_inventory_reference((int)($jo['id'] ?? 0))['code'] ?? '';
                    }
                    $jobMats = $materialsByJob[$jo['id']] ?? [];
                    $jobInks = $inksByJob[$jo['id']] ?? [];
                    
                    // Calculate readiness
                    $readiness = 'READY';
                    $total_cost = 0;
                    
                    foreach ($jobMats as $m) {
                        $qty_needed = ($m['track_by_roll'] == 1) ? (float)($m['computed_required_length_ft'] ?: 0) : (float)$m['quantity'];
                        $itemStock = $stockMap[$m['item_id']] ?? 0;
                        
                        if ($itemStock <= 0) $readiness = 'MISSING';
                        elseif ($itemStock < $qty_needed && $readiness !== 'MISSING') $readiness = 'LOW';
                        
                        $total_cost += $qty_needed * (float)$m['unit_cost_at_assignment'];

                        // Check lamination
                        $meta = json_decode($m['metadata'] ?? '{}', true);
                        if (!empty($meta['lamination_item_id']) && !empty($meta['lamination_length_ft'])) {
                            $lamStock = $stockMap[$meta['lamination_item_id']] ?? 0;
                            if ($lamStock <= 0) $readiness = 'MISSING';
                            elseif ($lamStock < (float)$meta['lamination_length_ft'] && $readiness !== 'MISSING') $readiness = 'LOW';
                        }
                    }
                    
                    foreach ($jobInks as $ink) {
                        $inkStock = $stockMap[$ink['item_id']] ?? 0;
                        if ($inkStock < (float)$ink['quantity_used']) $readiness = 'MISSING';
                    }

                    $jo['readiness'] = $readiness;
                    $jo['estimated_cost'] = $total_cost;
                    $visibleOrders[] = $jo;
                }
                $orders = $visibleOrders;
            }
            
            $response = ['success' => true, 'data' => $orders];
            if (isset($_GET['customer_id'])) {
                $response['pagination'] = [
                    'current_page' => $page,
                    'total_pages' => max(1, ceil($total_count / $per_page)),
                    'total_items' => $total_count,
                    'per_page' => $per_page
                ];
            }
            
            jo_api_json_response($response);
            break;

        case 'resolve_job_for_order':
            if (!in_array(get_user_type() ?? '', ['Admin', 'Staff', 'Manager'], true)) {
                throw new Exception('Unauthorized');
            }
            $orderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
            if (!$orderId) {
                throw new Exception('order_id required');
            }
            if ($joStaffBranch !== null && !printflow_order_in_branch($orderId, $joStaffBranch)) {
                throw new Exception('Unauthorized');
            }
            $row = db_query('SELECT id FROM job_orders WHERE order_id = ? ORDER BY id ASC LIMIT 1', 'i', [$orderId]);
            $jobId = $row[0]['id'] ?? null;
            // Checkout sometimes leaves orders without job_orders if job creation failed — backfill from order_items (same rules as checkout)
            if ($jobId === null) {
                $created = JobOrderService::ensureJobsForStoreOrder($orderId);
                $jobId = $created !== null ? $created : null;
            }
            jo_api_json_response(['success' => true, 'job_id' => $jobId !== null ? (int)$jobId : null]);
            break;

        case 'list_pending_orders':
            // Fetch regular product orders with pending status for staff customization dashboard
            $sql = "SELECT 
                        o.order_id as id,
                        o.order_id,
                        o.customer_id,
                        c.first_name,
                        c.last_name,
                        c.profile_picture AS customer_profile_picture,
                        c.customer_type,
                        c.transaction_count,
                        CONCAT(c.first_name, ' ', c.last_name) as customer_full_name,
                        TRIM(CONCAT_WS(', ', NULLIF(TRIM(c.street_address), ''), NULLIF(TRIM(c.barangay), ''), NULLIF(TRIM(c.city), ''))) as customer_contact,
                        'ORDER' as order_type,
                        COALESCE(MAX(p.category), 'Custom Order') as service_type,
                        GROUP_CONCAT(DISTINCT CONCAT(p.name, ' - ', oi.quantity, 'pcs') SEPARATOR ', ') as job_title,
                        '1' as width_ft,
                        '1' as height_ft,
                        SUM(oi.quantity) as quantity,
                        CASE 
                            WHEN o.status IN ('Pending', 'Pending Review', 'Pending Approval', 'For Revision') THEN 'PENDING'
                            WHEN o.status IN ('Design Approved', 'Approved') THEN 'APPROVED'
                            WHEN o.status IN ('Pending Verification', 'Downpayment Submitted', 'To Verify') THEN 'VERIFY_PAY'
                            WHEN o.status IN ('To Pay') THEN 'TO_PAY'
                            WHEN o.status IN ('Paid – In Process', 'Paid - In Process', 'Processing', 'In Production', 'Printing') THEN 'IN_PRODUCTION'
                            WHEN o.status = 'Ready for Pickup' THEN 'TO_RECEIVE'
                            WHEN o.status = 'Completed' THEN 'COMPLETED'
                            WHEN o.status = 'Rejected' THEN 'REJECTED'
                            WHEN o.status = 'Cancelled' THEN 'CANCELLED'
                            ELSE o.status
                        END as status,
                        CASE 
                            WHEN o.status IN ('Pending Verification', 'Downpayment Submitted', 'To Verify') THEN 'SUBMITTED'
                            WHEN o.status IN ('Completed', 'Ready for Pickup', 'Processing', 'In Production', 'Printing', 'Paid – In Process', 'Paid - In Process') THEN 'VERIFIED'
                            ELSE 'NONE'
                        END as payment_proof_status,
                        'NO' as payment_status,
                        '' as materials,
                        o.order_date as created_at,
                        o.updated_at,
                        o.order_date,
                        NULL as due_date,
                        NULL as priority,
                        o.total_amount as estimated_total,
                        (SELECT MIN(jo.id) FROM job_orders jo WHERE jo.order_id = o.order_id) AS job_order_id,
                        o.payment_proof as payment_proof_path,
                        o.downpayment_amount as payment_submitted_amount,
                        COALESCE(o.order_source, 'customer') as order_source
                    FROM orders o
                    LEFT JOIN order_items oi ON o.order_id = oi.order_id
                    LEFT JOIN products p ON oi.product_id = p.product_id
                    LEFT JOIN customers c ON o.customer_id = c.customer_id
                    WHERE (o.order_type IS NULL OR o.order_type = 'product' OR o.order_type = 'custom')
                    AND COALESCE(o.order_source, '') <> 'pos_merged'
                    AND o.status IN (
                        'Pending', 'Pending Review', 'Pending Approval', 'For Revision',
                        'Approved', 'Design Approved',
                        'To Pay', 'Downpayment Submitted', 'Pending Verification', 'To Verify',
                        'Processing', 'In Production', 'Printing', 'Paid – In Process', 'Paid - In Process', 'Ready for Pickup',
                        'Completed', 'Rejected', 'Cancelled'
                    )"
                    . ($joStaffBranch !== null ? " AND o.branch_id = ?" : "") . "
                    GROUP BY o.order_id
                    ORDER BY o.order_date DESC
                    LIMIT 50";

            $pending_orders = $joStaffBranch !== null
                ? (db_query($sql, 'i', [$joStaffBranch]) ?: [])
                : (db_query($sql) ?: []);
            
            $visiblePendingOrders = [];
            foreach ($pending_orders as $order) {
                $order['readiness'] = 'READY';
                $order['estimated_cost'] = 0;
                $order['order_code'] = printflow_get_order_inventory_reference((int)($order['order_id'] ?? 0))['code'] ?? '';
                
                // Fallback: detect legacy POS orders missing order_source
                if (empty($order['order_source']) || $order['order_source'] === 'customer') {
                    $pos_check = db_query(
                        "SELECT 1 FROM customizations WHERE order_id = ? AND customization_details LIKE '%\"source\":\"POS\"%' LIMIT 1",
                        'i', [$order['order_id']]
                    );
                    if (!empty($pos_check)) {
                        $order['order_source'] = 'pos';
                        // Backfill the DB so future loads are instant
                        db_execute("UPDATE orders SET order_source = 'pos' WHERE order_id = ? AND (order_source IS NULL OR order_source = 'customer')", 'i', [$order['order_id']]);
                    }
                }
                
                // Fetch dynamic correct names based on ordered items customizations
                $payload = JobOrderService::getStoreOrderItemsPayload($order['order_id'], $serviceOnly);
                if ($serviceOnly && empty($payload['items'])) {
                    continue;
                }
                if (!empty($payload['service_type']) && $payload['service_type'] !== 'Custom Order') {
                    $order['service_type'] = $payload['service_type'];
                }
                $order['width_ft'] = $payload['width_ft'];
                $order['height_ft'] = $payload['height_ft'];
                
                $title_parts = [];
                foreach ($payload['items'] as $it) {
                    $title_parts[] = $it['product_name'] . ' - ' . $it['quantity'] . 'pcs';
                }
                if (!empty($title_parts)) {
                    $order['job_title'] = implode(', ', array_unique($title_parts));
                }
                $visiblePendingOrders[] = $order;
            }
            $pending_orders = $visiblePendingOrders;

            // Customizations from POS (customizations table)
            $custom_sql = "SELECT 
                    cust.customization_id AS id,
                    cust.order_id,
                    (SELECT MIN(jo.id) FROM job_orders jo WHERE jo.order_id = cust.order_id) AS job_order_id,
                    cust.customization_details,
                    cust.customer_id,
                    c.first_name,
                    c.last_name,
                    c.profile_picture AS customer_profile_picture,
                    c.customer_type,
                    c.transaction_count,
                    TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) AS customer_full_name,
                    TRIM(CONCAT_WS(', ', NULLIF(TRIM(c.street_address), ''), NULLIF(TRIM(c.barangay), ''), NULLIF(TRIM(c.city), ''))) AS customer_contact,
                    'CUSTOMIZATION' AS order_type,
                    cust.service_type AS service_type,
                    cust.service_type AS job_title,
                    '1' AS width_ft,
                    '1' AS height_ft,
                    1 AS quantity,
                    CASE 
                        WHEN cust.status IN ('Pending Review', 'Pending', 'Pending Approval', 'For Revision') THEN 'PENDING'
                        WHEN cust.status = 'Approved' THEN 'APPROVED'
                        WHEN cust.status = 'To Pay' THEN 'TO_PAY'
                        WHEN cust.status IN ('Pending Verification', 'Downpayment Submitted', 'To Verify') THEN 'VERIFY_PAY'
                        WHEN cust.status IN ('Processing', 'In Production') THEN 'IN_PRODUCTION'
                        WHEN cust.status IN ('Ready for Pickup', 'Ready For Pickup') THEN 'TO_RECEIVE'
                        WHEN cust.status = 'Completed' THEN 'COMPLETED'
                        WHEN cust.status = 'Rejected' THEN 'REJECTED'
                        WHEN cust.status = 'Cancelled' THEN 'CANCELLED'
                        ELSE 'PENDING'
                    END AS status,
                    'PAID' AS payment_proof_status,
                    'NO' AS payment_status,
                    '' AS materials,
                    cust.created_at AS created_at,
                    cust.updated_at AS updated_at,
                    cust.created_at AS order_date,
                    NULL AS due_date,
                    NULL AS priority,
                    0 AS estimated_total,
                    'pos' AS order_source
                FROM customizations cust
                LEFT JOIN customers c ON cust.customer_id = c.customer_id
                LEFT JOIN orders o ON cust.order_id = o.order_id
                WHERE cust.status IN ('Pending Review', 'Pending', 'Pending Approval', 'For Revision', 'Approved', 'To Pay', 'Pending Verification', 'Downpayment Submitted', 'To Verify', 'Processing', 'In Production', 'Ready for Pickup', 'Ready For Pickup', 'Completed', 'Rejected', 'Cancelled')
                AND COALESCE(o.order_source, '') <> 'pos_merged'"
                . ($joStaffBranch !== null ? " AND o.branch_id = ?" : "") . "
                ORDER BY cust.created_at DESC
                LIMIT 50";

            $custom_orders = $joStaffBranch !== null
                ? (db_query($custom_sql, 'i', [$joStaffBranch]) ?: [])
                : (db_query($custom_sql) ?: []);
            foreach ($custom_orders as &$co) {
                $summary = printflow_customization_summary($co['customization_details'] ?? [], $co['service_type'] ?? 'Custom Service');
                $co['service_type'] = $summary['service_type'];
                $co['job_title'] = $summary['job_title'];
                $co['width_ft'] = $summary['width_ft'];
                $co['height_ft'] = $summary['height_ft'];
                $co['quantity'] = $summary['quantity'];
                $co['readiness'] = 'READY';
                $co['estimated_cost'] = 0;
                if (!empty($co['order_id'])) {
                    $co['order_code'] = printflow_get_order_inventory_reference((int)$co['order_id'])['code'] ?? '';
                } else {
                    $co['order_code'] = printflow_format_customization_code((int)($co['id'] ?? 0));
                }
            }
            unset($co);

            // Service purchases (service_orders) — same dashboard shape; order_type SERVICE
            service_order_ensure_tables();
            $svc_sql = "SELECT 
                    so.id AS id,
                    so.id AS order_id,
                    so.customer_id,
                    c.first_name,
                    c.last_name,
                    c.profile_picture AS customer_profile_picture,
                    c.customer_type,
                    c.transaction_count,
                    TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) AS customer_full_name,
                    TRIM(CONCAT_WS(', ', NULLIF(TRIM(c.street_address), ''), NULLIF(TRIM(c.barangay), ''), NULLIF(TRIM(c.city), ''))) AS customer_contact,
                    'SERVICE' AS order_type,
                    so.service_name AS service_type,
                    so.service_name AS job_title,
                    '1' AS width_ft,
                    '1' AS height_ft,
                    1 AS quantity,
                    CASE 
                        WHEN so.status IN ('Pending Review', 'Pending', 'Pending Approval', 'For Revision') THEN 'PENDING'
                        WHEN so.status = 'Approved' THEN 'APPROVED'
                        WHEN so.status = 'Processing' THEN 'IN_PRODUCTION'
                        WHEN so.status IN ('Ready for Pickup', 'Ready For Pickup') THEN 'TO_RECEIVE'
                        WHEN so.status = 'Completed' THEN 'COMPLETED'
                        WHEN so.status IN ('Rejected', 'Cancelled') THEN 'CANCELLED'
                        ELSE 'PENDING'
                    END AS status,
                    'PAID' AS payment_proof_status,
                    'NO' AS payment_status,
                    '' AS materials,
                    so.created_at AS created_at,
                    so.updated_at AS updated_at,
                    so.created_at AS order_date,
                    NULL AS due_date,
                    NULL AS priority,
                    so.total_price AS estimated_total
                FROM service_orders so
                LEFT JOIN customers c ON so.customer_id = c.customer_id
                WHERE so.status IN (
                    'Pending Review', 'Pending', 'Pending Approval', 'For Revision',
                    'Approved', 'Processing', 'Ready for Pickup', 'Ready For Pickup',
                    'Completed', 'Rejected', 'Cancelled'
                )
                ORDER BY so.created_at DESC
                LIMIT 50";

            $svc_orders = db_query($svc_sql) ?: [];
            foreach ($svc_orders as &$so) {
                $so['readiness'] = 'READY';
                $so['estimated_cost'] = 0;
                $so['order_code'] = 'SRV-' . str_pad((string)((int)($so['id'] ?? 0)), 5, '0', STR_PAD_LEFT);
            }
            unset($so);

            $merged = array_merge($pending_orders, $custom_orders, $svc_orders);
            usort($merged, function ($a, $b) {
                $ta = strtotime($a['updated_at'] ?? $a['created_at'] ?? $a['order_date'] ?? 'now');
                $tb = strtotime($b['updated_at'] ?? $b['created_at'] ?? $b['order_date'] ?? 'now');
                return $tb <=> $ta;
            });

            jo_api_json_response(['success' => true, 'data' => $merged]);
            break;

        case 'list_machines':
            $machines = db_query("SELECT * FROM machines WHERE status = 'ACTIVE'") ?: [];
            jo_api_json_response(['success' => true, 'data' => $machines]);
            break;

        case 'get_order':
            $id = (int)($_GET['id'] ?? 0);
            jo_api_require_staff_branch($joStaffBranch, $id);
            $order = JobOrderService::getOrder($id);
            if (!$order) throw new Exception("Order not found.");
            $order['readiness'] = JobOrderService::getMaterialReadiness($id);
            $order['order_code'] = printflow_get_job_inventory_reference($id)['code'] ?? printflow_format_job_code($id);
            jo_api_json_response(['success' => true, 'data' => $order]);
            break;

        case 'update_customization':
            if (!in_array($_SESSION['user_type'] ?? '', ['Admin', 'Staff', 'Manager'])) {
                throw new Exception('Unauthorized');
            }
            $cust_id = (int)($_POST['id'] ?? 0);
            $raw_status = sanitize($_POST['status'] ?? '');
            $price = isset($_POST['price']) ? (float)$_POST['price'] : null;
            if (!$cust_id || !$raw_status) throw new Exception('ID and status required.');

            // Normalize frontend enum values to DB-stored strings
            $status_to_db = [
                'PENDING'       => 'Pending',
                'APPROVED'      => 'Approved',
                'TO_PAY'        => 'To Pay',
                'VERIFY_PAY'    => 'Pending Verification',
                'IN_PRODUCTION' => 'Processing',
                'TO_RECEIVE'    => 'Ready for Pickup',
                'COMPLETED'     => 'Completed',
                'REJECTED'      => 'Rejected',
                'CANCELLED'     => 'Cancelled',
                'FOR REVISION'  => 'For Revision',
                'For Revision'  => 'For Revision',
            ];
            $new_status = $status_to_db[$raw_status] ?? $raw_status;
            $rejection_reason = sanitize($_POST['reason'] ?? '');

            // Check if payment proof already exists for this customization's order
            $order_check = db_query(
                "SELECT o.order_id, o.payment_proof_path, o.downpayment_amount 
                 FROM orders o 
                 JOIN customizations c ON o.order_id = c.order_id 
                 WHERE c.customization_id = ? LIMIT 1",
                'i', [$cust_id]
            );

            $has_payment_proof = !empty($order_check) && !empty($order_check[0]['payment_proof_path']);
            $payment_amount    = !empty($order_check) ? (float)($order_check[0]['downpayment_amount'] ?? 0) : 0;
            $linked_order_id   = !empty($order_check) ? (int)$order_check[0]['order_id'] : null;

            // If TO_PAY but proof already uploaded, skip straight to verification
            if ($new_status === 'To Pay' && $has_payment_proof && $payment_amount > 0) {
                $new_status = 'Pending Verification';
            }

            $linked_job_ids = [];
            if ($linked_order_id) {
                JobOrderService::ensureJobsForStoreOrder($linked_order_id);
                $linked_jobs = db_query(
                    "SELECT id FROM job_orders WHERE order_id = ? AND status NOT IN ('COMPLETED', 'CANCELLED') ORDER BY id ASC",
                    'i',
                    [$linked_order_id]
                ) ?: [];
                $linked_job_ids = array_map(static fn(array $row): int => (int)$row['id'], $linked_jobs);
            }

            $job_status_sync = [
                'Approved'              => 'APPROVED',
                'To Pay'                => 'TO_PAY',
                'Pending Verification'  => 'VERIFY_PAY',
                'Processing'            => 'IN_PRODUCTION',
                'Ready for Pickup'      => 'TO_RECEIVE',
                'Completed'             => 'COMPLETED',
                'Cancelled'             => 'CANCELLED',
            ];

            if (!empty($linked_job_ids) && isset($job_status_sync[$new_status])) {
                $targetJobStatus = $job_status_sync[$new_status];
                foreach ($linked_job_ids as $jobId) {
                    if ($price !== null) {
                        db_execute(
                            "UPDATE job_orders SET estimated_total = ?, required_payment = ? WHERE id = ?",
                            'ddi',
                            [$price, $price, $jobId]
                        );
                    }
                    JobOrderService::updateStatus($jobId, $targetJobStatus);
                }
            } elseif ($new_status === 'Processing' && empty($linked_job_ids)) {
                throw new Exception('Cannot move customization to Processing without a linked production job.');
            }

            db_execute('UPDATE customizations SET status = ?, updated_at = NOW() WHERE customization_id = ?', 'si', [$new_status, $cust_id]);
            if ($new_status === 'Rejected' && !empty($rejection_reason)) {
                db_execute('UPDATE customizations SET rejection_reason = ? WHERE customization_id = ?', 'si', [$rejection_reason, $cust_id]);
            }
            if ($new_status === 'For Revision' && !empty($rejection_reason)) {
                db_execute('UPDATE customizations SET rejection_reason = ? WHERE customization_id = ?', 'si', [$rejection_reason, $cust_id]);
            }

            if ($price !== null && $linked_order_id) {
                db_execute('UPDATE orders SET total_amount = ? WHERE order_id = ?', 'di', [$price, $linked_order_id]);
                // Also update the order_items unit_price so POS cart reflects the correct price
                db_execute('UPDATE order_items SET unit_price = ? WHERE order_id = ? LIMIT 1', 'di', [$price, $linked_order_id]);
            }

            // Sync orders.status for key transitions
            if ($linked_order_id) {
                $order_status_sync = [
                    'To Pay'               => 'To Pay',
                    'Pending Verification' => 'Pending Verification',
                    'Processing'           => 'Processing',
                    'Ready for Pickup'     => 'Ready for Pickup',
                    'Completed'            => 'Completed',
                    'Rejected'             => 'Rejected',
                    'Cancelled'            => 'Cancelled',
                    'For Revision'         => 'For Revision',
                ];
                if (isset($order_status_sync[$new_status])) {
                    if (($new_status === 'Rejected' || $new_status === 'For Revision') && $rejection_reason !== '') {
                        db_execute(
                            'UPDATE orders SET status = ?, rejection_reason = ?, design_status = ? WHERE order_id = ?',
                            'sssi',
                            [$order_status_sync[$new_status], $rejection_reason, ($new_status === 'For Revision' ? 'Revision Requested' : 'Rejected'), $linked_order_id]
                        );
                    } else {
                        db_execute('UPDATE orders SET status = ? WHERE order_id = ?', 'si', [$order_status_sync[$new_status], $linked_order_id]);
                    }
                }
            }

            // ── Automated chat update card ───────────────────────────────────────
            if ($linked_order_id) {
                $chat_step_map = [
                    'Approved'          => 'approved',
                    'To Pay'            => 'send_to_payment',
                    'Processing'        => 'in_production',
                    'Ready for Pickup'  => 'ready_to_pickup',
                    'Completed'         => 'completed',
                    'For Revision'      => 'for_revision',
                ];
                if (isset($chat_step_map[$new_status])) {
                    require_once __DIR__ . '/../includes/functions.php';
                    $additional_meta = [];
                    if ($new_status === 'For Revision' && !empty($rejection_reason)) {
                        $additional_meta['reason'] = $rejection_reason;
                    }
                    printflow_send_order_update($linked_order_id, $chat_step_map[$new_status], 'view_status', '', '', $additional_meta);
                }
            }

            jo_api_json_response(['success' => true]);
            break;

        case 'get_customization':
            // Get customization entry details (from POS services)
            if (!in_array($_SESSION['user_type'] ?? '', ['Admin', 'Staff', 'Manager'])) {
                throw new Exception("Unauthorized");
            }
            $cust_id = (int)($_GET['id'] ?? 0);
            if (!$cust_id) throw new Exception("Customization ID required.");

            $cust_row = db_query("
                SELECT cust.*,
                       c.first_name, c.last_name, c.customer_type, c.contact_number, c.email, c.transaction_count,
                       c.profile_picture AS customer_profile_picture,
                       CONCAT(c.first_name, ' ', c.last_name) AS customer_full_name,
                       COALESCE(NULLIF(TRIM(c.contact_number), ''), NULLIF(TRIM(c.email), '')) AS customer_contact,
                       TRIM(CONCAT_WS(', ', NULLIF(TRIM(c.street_address), ''), NULLIF(TRIM(c.barangay), ''), NULLIF(TRIM(c.city), ''))) AS customer_address,
                       o.total_amount AS order_total,
                       COALESCE(NULLIF(o.payment_proof_path,''), NULLIF(o.payment_proof,''), NULLIF(jo.payment_proof_path,'')) AS payment_proof_path,
                       COALESCE(jo.payment_submitted_amount, o.downpayment_amount, 0) AS downpayment_amount,
                       o.order_source
                FROM customizations cust
                LEFT JOIN customers c ON cust.customer_id = c.customer_id
                LEFT JOIN orders o ON cust.order_id = o.order_id
                LEFT JOIN job_orders jo ON jo.order_id = o.order_id AND jo.status NOT IN ('CANCELLED')
                WHERE cust.customization_id = ?
                ORDER BY jo.id ASC
                LIMIT 1
            ", 'i', [$cust_id]);

            if (empty($cust_row)) throw new Exception("Customization not found.");
            $cust = $cust_row[0];

            // Parse customization details
            $details = printflow_decode_modal_customization_payload((string)($cust['customization_details'] ?? ''));
            $design_name = '';
            $reference_name = '';
            foreach ($details as $detail_key => $detail_value) {
                if (is_array($detail_value) || $detail_value === null || $detail_value === '') {
                    continue;
                }
                $normalized_key = strtolower(trim((string)$detail_key));
                if ($design_name === '' && (
                    $normalized_key === 'design_upload' ||
                    $normalized_key === 'design_file' ||
                    $normalized_key === 'design_upload_path' ||
                    (strpos($normalized_key, 'upload design') !== false) ||
                    (strpos($normalized_key, 'design upload') !== false)
                )) {
                    $design_name = (string)$detail_value;
                    continue;
                }
                if ($reference_name === '' && (
                    $normalized_key === 'reference_upload' ||
                    $normalized_key === 'reference_file' ||
                    $normalized_key === 'reference_upload_path' ||
                    (strpos($normalized_key, 'reference upload') !== false)
                )) {
                    $reference_name = (string)$detail_value;
                }
            }

            // Map DB status → frontend enum
            $status_map = [
                'Pending Review'        => 'PENDING',
                'Pending'               => 'PENDING',
                'Pending Approval'      => 'PENDING',
                'For Revision'          => 'PENDING',
                'Approved'              => 'APPROVED',
                'To Pay'                => 'TO_PAY',
                'Pending Verification'  => 'VERIFY_PAY',
                'Downpayment Submitted' => 'VERIFY_PAY',
                'To Verify'             => 'VERIFY_PAY',
                'Processing'            => 'IN_PRODUCTION',
                'In Production'         => 'IN_PRODUCTION',
                'Ready for Pickup'      => 'TO_RECEIVE',
                'Ready For Pickup'      => 'TO_RECEIVE',
                'Completed'             => 'COMPLETED',
                'Cancelled'             => 'CANCELLED',
                'Rejected'              => 'REJECTED',
            ];
            $mapped_status = $status_map[$cust['status'] ?? ''] ?? 'PENDING';

            if (!empty($cust['order_id']) && in_array($mapped_status, ['IN_PRODUCTION', 'TO_RECEIVE', 'COMPLETED'], true)) {
                try {
                    JobOrderService::ensureStoreOrderProductionDeductions((int)$cust['order_id']);
                } catch (Throwable $syncErr) {
                    error_log(sprintf(
                        'PrintFlow customization deduction sync failed for order %d: %s',
                        (int)$cust['order_id'],
                        $syncErr->getMessage()
                    ));
                }
            }
            if ($mapped_status === 'IN_PRODUCTION') {
                try {
                    $linkedJobForDeduction = db_query(
                        "SELECT id
                         FROM job_orders
                         WHERE order_id = ?
                           AND status NOT IN ('COMPLETED', 'CANCELLED')
                         ORDER BY id ASC
                         LIMIT 1",
                        'i',
                        [(int)($cust['order_id'] ?? 0)]
                    ) ?: [];
                    $linkedJobId = (int)($linkedJobForDeduction[0]['id'] ?? 0);
                    if ($linkedJobId > 0) {
                        JobOrderService::ensureProductionDeductionsForJob($linkedJobId);
                    }
                } catch (Throwable $syncErr) {
                    error_log(sprintf(
                        'PrintFlow customization job-level deduction sync failed for order %d: %s',
                        (int)($cust['order_id'] ?? 0),
                        $syncErr->getMessage()
                    ));
                }
            }

            // Determine payment proof status
            $payment_proof_status = 'NONE';
            $payment_proof_url = null;
            if (!empty($cust['payment_proof_path'])) {
                $payment_proof_status = in_array($mapped_status, ['IN_PRODUCTION', 'TO_RECEIVE', 'COMPLETED'])
                    ? 'VERIFIED' : 'SUBMITTED';
                
                // Resolve path to URL
                $raw_path = $cust['payment_proof_path'];
                if (!preg_match('#^https?://#i', $raw_path)) {
                    $bp = defined('BASE_PATH') ? rtrim(BASE_PATH, '/') : '/printflow';
                    if (strpos($raw_path, 'uploads/') !== false) {
                        $payment_proof_url = $bp . '/' . substr($raw_path, strpos($raw_path, 'uploads/'));
                    } else {
                        $payment_proof_url = $bp . '/public/serve_design.php?type=order_payment&id=' . (int)$cust['order_id'];
                    }
                } else {
                    $payment_proof_url = $raw_path;
                }
            }

            // Use order total if set (price was already approved)
            $estimated_total = (float)($cust['order_estimated_price'] ?? $cust['order_total'] ?? 0);
            $final_price = (float)($cust['order_total'] ?? 0);
            $linked_job_id = 0;
            $linked_job = null;
            $linked_job_materials = [];
            $linked_job_ink_usage = [];
            if (!empty($cust['order_id'])) {
                JobOrderService::ensureJobsForStoreOrder((int)$cust['order_id']);
                $linked_job_rows = db_query(
                    "SELECT id FROM job_orders WHERE order_id = ? ORDER BY id ASC",
                    'i',
                    [(int)$cust['order_id']]
                ) ?: [];
                $linked_job_id = (int)($linked_job_rows[0]['id'] ?? 0);
                if ($linked_job_id > 0) {
                    $linked_job = JobOrderService::getOrder($linked_job_id);
                }
            }

            $items = [[
                'order_item_id' => $cust['order_item_id'] ?? null,
                'product_name'  => $details['service_type'] ?? ($cust['service_type'] ?? 'Service'),
                'quantity'      => 1,
                'customization' => $details,
                'design_name'   => $design_name,
                'design_url'    => $design_name ? (defined('BASE_PATH') ? BASE_PATH : '/printflow') . '/public/serve_design.php?type=order_item&id=' . (int)($cust['order_item_id'] ?? 0) : null,
                'reference_name'=> $reference_name,
                'reference_url' => $reference_name ? (defined('BASE_PATH') ? BASE_PATH : '/printflow') . '/public/serve_design.php?type=order_item&id=' . (int)($cust['order_item_id'] ?? 0) . '&field=reference' : null,
            ]];

            $summary = printflow_customization_summary($details, $cust['service_type'] ?? 'Service');
            $items[0]['product_name'] = $summary['job_title'];
            $items[0]['quantity'] = $summary['quantity'];

            if (!empty($cust['order_id'])) {
                $storeLinePayload = JobOrderService::getStoreOrderItemsPayload((int)$cust['order_id'], false, true);
                if (!empty($storeLinePayload['items'])) {
                    $items = $storeLinePayload['items'];
                }
                if (!empty($storeLinePayload['service_type'])) {
                    $summary['service_type'] = $storeLinePayload['service_type'];
                    $summary['job_title'] = $storeLinePayload['service_type'];
                }
                if (!empty($storeLinePayload['width_ft'])) {
                    $summary['width_ft'] = $storeLinePayload['width_ft'];
                }
                if (!empty($storeLinePayload['height_ft'])) {
                    $summary['height_ft'] = $storeLinePayload['height_ft'];
                }
                if (isset($storeLinePayload['line_qty']) && (int)$storeLinePayload['line_qty'] > 0) {
                    $summary['quantity'] = (int)$storeLinePayload['line_qty'];
                }

                $linked_job_candidates = $linked_job_rows ?? [];

                if (!empty($linked_job_candidates)) {
                    $targetService = strtoupper(trim((string)($summary['service_type'] ?? '')));
                    $targetTitle = strtoupper(trim((string)($summary['job_title'] ?? '')));
                    $targetQty = (int)($summary['quantity'] ?? 0);
                    $targetWidth = (string)($summary['width_ft'] ?? '');
                    $targetHeight = (string)($summary['height_ft'] ?? '');

                    $bestJob = null;
                    $bestScore = -1;
                    foreach ($linked_job_candidates as $linked_job_row) {
                        $candidateId = (int)($linked_job_row['id'] ?? 0);
                        if ($candidateId <= 0) {
                            continue;
                        }
                        $candidateJob = JobOrderService::getOrder($candidateId);
                        if (!$candidateJob) {
                            continue;
                        }

                        $score = 0;
                        $candidateService = strtoupper(trim((string)($candidateJob['service_type'] ?? '')));
                        $candidateTitle = strtoupper(trim((string)($candidateJob['job_title'] ?? '')));
                        if ($targetService !== '' && $candidateService === $targetService) {
                            $score += 6;
                        } elseif ($targetService !== '' && $candidateService !== '' && (
                            strpos($candidateService, $targetService) !== false ||
                            strpos($targetService, $candidateService) !== false
                        )) {
                            $score += 4;
                        }

                        if ($targetTitle !== '' && $candidateTitle === $targetTitle) {
                            $score += 5;
                        } elseif ($targetTitle !== '' && $candidateTitle !== '' && (
                            strpos($candidateTitle, $targetTitle) !== false ||
                            strpos($targetTitle, $candidateTitle) !== false
                        )) {
                            $score += 3;
                        }

                        if ($targetQty > 0 && (int)($candidateJob['quantity'] ?? 0) === $targetQty) {
                            $score += 2;
                        }
                        if ($targetWidth !== '' && $targetHeight !== '' &&
                            (string)($candidateJob['width_ft'] ?? '') === $targetWidth &&
                            (string)($candidateJob['height_ft'] ?? '') === $targetHeight) {
                            $score += 2;
                        }

                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $bestJob = $candidateJob;
                        }
                    }

                    if ($bestJob) {
                        $linked_job = $bestJob;
                        $linked_job_id = (int)($bestJob['id'] ?? 0);
                    }
                }
            }

            $linked_job_ids = array_values(array_filter(array_map(static function ($row) {
                return (int)($row['id'] ?? 0);
            }, $linked_job_rows ?? [])));

            $materialClauses = [];
            $materialParams = [];
            $materialTypes = '';
            if (!empty($linked_job_ids)) {
                $jobIdPlaceholders = implode(',', array_fill(0, count($linked_job_ids), '?'));
                $materialClauses[] = "m.job_order_id IN ($jobIdPlaceholders)";
                $materialParams = array_merge($materialParams, $linked_job_ids);
                $materialTypes .= str_repeat('i', count($linked_job_ids));
            }
            if (!empty($cust['order_id']) && db_table_has_column('job_order_materials', 'std_order_id')) {
                $materialClauses[] = "(m.std_order_id = ? AND (m.job_order_id IS NULL OR m.job_order_id = 0))";
                $materialParams[] = (int)$cust['order_id'];
                $materialTypes .= 'i';
            }
            if (!empty($materialClauses)) {
                $linked_job_materials = db_query(
                    "SELECT m.*, i.name as item_name, i.track_by_roll, i.category_id, r.roll_code,
                            (SELECT SUM(IF(direction='IN', quantity, -quantity)) FROM inventory_transactions WHERE item_id = m.item_id) as total_stock
                     FROM job_order_materials m
                     JOIN inv_items i ON m.item_id = i.id
                     LEFT JOIN inv_rolls r ON m.roll_id = r.id
                     WHERE " . implode(' OR ', $materialClauses),
                    $materialTypes,
                    $materialParams
                ) ?: [];
                foreach ($linked_job_materials as &$material) {
                    $material['metadata'] = $material['metadata'] ? json_decode($material['metadata'], true) : null;
                }
                unset($material);
            }

            $inkClauses = [];
            $inkParams = [];
            $inkTypes = '';
            if (!empty($linked_job_ids)) {
                $jobIdPlaceholders = implode(',', array_fill(0, count($linked_job_ids), '?'));
                $inkClauses[] = "u.job_order_id IN ($jobIdPlaceholders)";
                $inkParams = array_merge($inkParams, $linked_job_ids);
                $inkTypes .= str_repeat('i', count($linked_job_ids));
            }
            if (!empty($cust['order_id']) && db_table_has_column('job_order_ink_usage', 'std_order_id')) {
                $inkClauses[] = "(u.std_order_id = ? AND (u.job_order_id IS NULL OR u.job_order_id = 0))";
                $inkParams[] = (int)$cust['order_id'];
                $inkTypes .= 'i';
            }
            if (!empty($inkClauses)) {
                $linked_job_ink_usage = db_query(
                    "SELECT u.*, i.name as item_name
                     FROM job_order_ink_usage u
                     JOIN inv_items i ON u.item_id = i.id
                     WHERE " . implode(' OR ', $inkClauses),
                    $inkTypes,
                    $inkParams
                ) ?: [];
            }

            $resolvedOrderSource = jo_api_resolve_order_source(
                (int)($cust['order_id'] ?? 0),
                $cust['order_source'] ?? null
            );

            if (in_array($resolvedOrderSource, ['pos', 'walk-in'], true) && $mapped_status === 'APPROVED') {
                $autoAssignedIds = [];
                $filteredMaterials = [];
                foreach ($linked_job_materials as $material) {
                    $metadata = $material['metadata'] ?? null;
                    if (is_string($metadata)) {
                        $metadata = json_decode($metadata, true);
                    }
                    $manualAssignment = is_array($metadata) && !empty($metadata['manual_assignment']);
                    $deductedAt = trim((string)($material['deducted_at'] ?? ''));
                    if (!$manualAssignment && $deductedAt === '') {
                        $autoAssignedIds[] = (int)($material['id'] ?? 0);
                        continue;
                    }
                    $material['metadata'] = $metadata;
                    $filteredMaterials[] = $material;
                }
                $linked_job_materials = $filteredMaterials;

                $autoAssignedIds = array_values(array_filter($autoAssignedIds));
                if (!empty($autoAssignedIds)) {
                    $placeholders = implode(',', array_fill(0, count($autoAssignedIds), '?'));
                    db_execute(
                        "DELETE FROM job_order_materials WHERE id IN ($placeholders) AND deducted_at IS NULL",
                        str_repeat('i', count($autoAssignedIds)),
                        $autoAssignedIds
                    );
                    error_log(sprintf(
                        'PrintFlow POS customization cleanup: removed %d pre-approval material rows from order %d',
                        count($autoAssignedIds),
                        (int)($cust['order_id'] ?? 0)
                    ));
                }
            }

            $data = [
                'id'                       => $cust['customization_id'],
                'order_id'                 => $cust['order_id'],
                'order_type'               => 'CUSTOMIZATION',
                'order_code'               => !empty($cust['order_id'])
                    ? (printflow_get_order_inventory_reference((int)$cust['order_id'])['code'] ?? '')
                    : printflow_format_customization_code((int)$cust['customization_id']),
                'customer_full_name'       => $cust['customer_full_name'] ?? '',
                'customer_profile_picture' => $cust['customer_profile_picture'] ?? '',
                'customer_contact'         => $cust['customer_contact'] ?? '',
                'customer_address'         => $cust['customer_address'] ?? '',
                'customer_type'            => jo_api_normalize_customer_type($cust['customer_type'] ?? '', $cust['transaction_count'] ?? 0),
                'transaction_count'        => (int)($cust['transaction_count'] ?? 0),
                'service_type'             => $summary['service_type'],
                'job_title'                => $summary['job_title'],
                'width_ft'                 => $summary['width_ft'],
                'height_ft'                => $summary['height_ft'],
                'quantity'                 => $summary['quantity'],
                'status'                   => $mapped_status,
                'estimated_total'          => $estimated_total,
                'estimated_price'          => $estimated_total,
                'final_price'              => $final_price,
                'amount_paid'              => 0,
                'job_order_id'             => $linked_job_id > 0 ? $linked_job_id : null,
                'notes'                    => '',
                'store_order_notes'        => '',
                'payment_proof_status'     => $payment_proof_status,
                'payment_proof_path'       => $payment_proof_url,
                'payment_submitted_amount' => (float)($cust['downpayment_amount'] ?? 0),
                'payment_status'           => 'NO',
                'readiness'                => $linked_job['readiness'] ?? 'READY',
                'order_source'             => $resolvedOrderSource,
                'items'                    => $items,
                'materials'                => $linked_job_materials,
                'ink_usage'                => $linked_job_ink_usage,
                'customization_details'    => $details,
            ];

            jo_api_json_response(['success' => true, 'data' => $data]);
            break;

        case 'get_regular_order':
            // Full order details for regular (orders table) - includes items + customization_data
            if (!in_array($_SESSION['user_type'] ?? '', ['Admin', 'Staff', 'Manager'])) {
                throw new Exception("Unauthorized");
            }
            $order_id = (int)($_GET['id'] ?? 0);
            if (!$order_id) throw new Exception("Order ID required.");
            $order_row = db_query("
                SELECT o.*, c.first_name, c.last_name, c.customer_type, c.transaction_count, c.contact_number, c.email,
                       c.profile_picture AS customer_profile_picture,
                       CONCAT(c.first_name, ' ', c.last_name) as customer_full_name,
                       COALESCE(NULLIF(TRIM(c.contact_number), ''), NULLIF(TRIM(c.email), '')) as customer_contact,
                       TRIM(CONCAT_WS(', ', NULLIF(TRIM(c.street_address), ''), NULLIF(TRIM(c.barangay), ''), NULLIF(TRIM(c.city), ''))) AS customer_address
                FROM orders o
                LEFT JOIN customers c ON o.customer_id = c.customer_id
                WHERE o.order_id = ?
            ", 'i', [$order_id]);
            if (empty($order_row)) throw new Exception("Order not found.");
            $o = $order_row[0];
            if ($joStaffBranch !== null) {
                $orderBranchId = (int)($o['branch_id'] ?? 0);
                $branchMatches =
                    ($orderBranchId > 0 && $orderBranchId === $joStaffBranch) ||
                    printflow_order_in_branch($order_id, $joStaffBranch);
                if (!$branchMatches) {
                    throw new Exception("Unauthorized");
                }
            }
            $status_map = [
                'Pending' => 'PENDING', 'Pending Review' => 'PENDING', 'Pending Approval' => 'PENDING',
                'For Revision' => 'PENDING', 'Design Approved' => 'APPROVED', 'Approved' => 'APPROVED',
                'Pending Verification' => 'VERIFY_PAY', 'Downpayment Submitted' => 'VERIFY_PAY', 'To Verify' => 'VERIFY_PAY',
                'To Pay' => 'TO_PAY',
                'Paid – In Process' => 'IN_PRODUCTION',
                'Paid - In Process' => 'IN_PRODUCTION',
                'Processing' => 'IN_PRODUCTION', 'In Production' => 'IN_PRODUCTION', 'Printing' => 'IN_PRODUCTION',
                'Ready for Pickup' => 'TO_RECEIVE', 'Completed' => 'COMPLETED', 'Cancelled' => 'CANCELLED'
            ];
            $db_status = $o['status'] ?? '';
            $mapped_status = $status_map[$db_status] ?? $db_status;

            if ($order_id > 0 && in_array($mapped_status, ['IN_PRODUCTION', 'TO_RECEIVE', 'COMPLETED'], true)) {
                try {
                    JobOrderService::ensureStoreOrderProductionDeductions($order_id);
                } catch (Throwable $syncErr) {
                    error_log(sprintf(
                        'PrintFlow order deduction sync failed for order %d: %s',
                        $order_id,
                        $syncErr->getMessage()
                    ));
                }
            }
            if ($order_id > 0 && $mapped_status === 'IN_PRODUCTION') {
                try {
                    $linkedJobRows = db_query(
                        "SELECT id
                         FROM job_orders
                         WHERE order_id = ?
                           AND status NOT IN ('COMPLETED', 'CANCELLED')
                         ORDER BY id ASC
                         LIMIT 1",
                        'i',
                        [$order_id]
                    ) ?: [];
                    $linkedJobId = (int)($linkedJobRows[0]['id'] ?? 0);
                    if ($linkedJobId > 0) {
                        JobOrderService::ensureProductionDeductionsForJob($linkedJobId);
                    }
                } catch (Throwable $syncErr) {
                    error_log(sprintf(
                        'PrintFlow regular-order job-level deduction sync failed for order %d: %s',
                        $order_id,
                        $syncErr->getMessage()
                    ));
                }
            }
            
            // Map payment proof status for staff dashboard
            $payment_proof_status = 'NONE';
            $payment_proof_url = null;
            $raw_pp = $o['payment_proof_path'] ?? $o['payment_proof'] ?? null;
            if ($raw_pp) {
                if (in_array($db_status, ['Pending Verification', 'Downpayment Submitted', 'To Verify'], true)) {
                    $payment_proof_status = 'SUBMITTED';
                } elseif (in_array($db_status, ['Completed', 'Ready for Pickup', 'Processing', 'In Production', 'Printing', 'Paid – In Process', 'Paid - In Process'], true)) {
                    $payment_proof_status = 'VERIFIED';
                }

                if (!preg_match('#^https?://#i', $raw_pp)) {
                    $bp = defined('BASE_PATH') ? rtrim(BASE_PATH, '/') : '/printflow';
                    if (strpos($raw_pp, 'uploads/') !== false) {
                        $payment_proof_url = $bp . '/' . substr($raw_pp, strpos($raw_pp, 'uploads/'));
                    } else {
                        $payment_proof_url = $bp . '/public/serve_design.php?type=order_payment&id=' . (int)$o['order_id'];
                    }
                } else {
                    $payment_proof_url = $raw_pp;
                }
            }

            $payload = JobOrderService::getStoreOrderItemsPayload($order_id, $serviceOnly, true);
            $items_out = $payload['items'];
            $width_ft = $payload['width_ft'];
            $height_ft = $payload['height_ft'];
            $total_qty = (int)($payload['line_qty'] ?? 0);
            $service_name = $payload['service_type'] ?: 'Custom Order';
            $linked_job_id = 0;
            $linked_job = null;
            $linked_job_materials = [];
            $linked_job_ink_usage = [];
            JobOrderService::ensureJobsForStoreOrder($order_id);
            $linked_job_rows = db_query(
                "SELECT id FROM job_orders WHERE order_id = ? ORDER BY id ASC",
                'i',
                [$order_id]
            ) ?: [];
            $linked_job_id = (int)($linked_job_rows[0]['id'] ?? 0);
            if ($linked_job_id > 0) {
                $linked_job = JobOrderService::getOrder($linked_job_id);
            }
            $linked_job_ids = array_values(array_filter(array_map(static function ($row) {
                return (int)($row['id'] ?? 0);
            }, $linked_job_rows)));

            $materialClauses = [];
            $materialParams = [];
            $materialTypes = '';
            if (!empty($linked_job_ids)) {
                $jobIdPlaceholders = implode(',', array_fill(0, count($linked_job_ids), '?'));
                $materialClauses[] = "m.job_order_id IN ($jobIdPlaceholders)";
                $materialParams = array_merge($materialParams, $linked_job_ids);
                $materialTypes .= str_repeat('i', count($linked_job_ids));
            }
            if (db_table_has_column('job_order_materials', 'std_order_id')) {
                $materialClauses[] = "(m.std_order_id = ? AND (m.job_order_id IS NULL OR m.job_order_id = 0))";
                $materialParams[] = $order_id;
                $materialTypes .= 'i';
            }
            if (!empty($materialClauses)) {
                $linked_job_materials = db_query(
                    "SELECT m.*, i.name as item_name, i.track_by_roll, i.category_id, r.roll_code,
                            (SELECT SUM(IF(direction='IN', quantity, -quantity)) FROM inventory_transactions WHERE item_id = m.item_id) as total_stock
                     FROM job_order_materials m
                     JOIN inv_items i ON m.item_id = i.id
                     LEFT JOIN inv_rolls r ON m.roll_id = r.id
                     WHERE " . implode(' OR ', $materialClauses),
                    $materialTypes,
                    $materialParams
                ) ?: [];
                foreach ($linked_job_materials as &$material) {
                    $material['metadata'] = $material['metadata'] ? json_decode($material['metadata'], true) : null;
                }
                unset($material);
            }

            $inkClauses = [];
            $inkParams = [];
            $inkTypes = '';
            if (!empty($linked_job_ids)) {
                $jobIdPlaceholders = implode(',', array_fill(0, count($linked_job_ids), '?'));
                $inkClauses[] = "u.job_order_id IN ($jobIdPlaceholders)";
                $inkParams = array_merge($inkParams, $linked_job_ids);
                $inkTypes .= str_repeat('i', count($linked_job_ids));
            }
            if (db_table_has_column('job_order_ink_usage', 'std_order_id')) {
                $inkClauses[] = "(u.std_order_id = ? AND (u.job_order_id IS NULL OR u.job_order_id = 0))";
                $inkParams[] = $order_id;
                $inkTypes .= 'i';
            }
            if (!empty($inkClauses)) {
                $linked_job_ink_usage = db_query(
                    "SELECT u.*, i.name as item_name
                     FROM job_order_ink_usage u
                     JOIN inv_items i ON u.item_id = i.id
                     WHERE " . implode(' OR ', $inkClauses),
                    $inkTypes,
                    $inkParams
                ) ?: [];
            }
            $data = [
                'id'                   => $o['order_id'],
                'order_id'             => $o['order_id'],
                'order_type'           => 'ORDER',
                'order_code'           => printflow_get_order_inventory_reference($order_id)['code'] ?? printflow_format_order_code($order_id, ''),
                'customer_full_name'   => $o['customer_full_name'] ?? trim(($o['first_name'] ?? '') . ' ' . ($o['last_name'] ?? '')),
                'customer_profile_picture' => $o['customer_profile_picture'] ?? '',
                'customer_contact'     => $o['customer_contact'] ?? '',
                'customer_type'        => jo_api_normalize_customer_type($o['customer_type'] ?? '', $o['transaction_count'] ?? 0),
                'transaction_count'    => (int)($o['transaction_count'] ?? 0),
                'service_type'         => $service_name,
                'job_title'            => implode(', ', array_map(function($i) { return $i['product_name'] . ' - ' . $i['quantity'] . 'pcs'; }, $items_out)),
                'width_ft'             => $width_ft,
                'height_ft'            => $height_ft,
                'quantity'             => $total_qty,
                'status'               => $mapped_status,
                'estimated_total'      => (float)($o['total_amount'] ?? 0),
                'estimated_price'      => (float)($o['total_amount'] ?? 0),
                'final_price'          => (float)($o['total_amount'] ?? 0),
                'amount_paid'          => (($o['payment_status'] ?? '') === 'Paid') ? (float)($o['total_amount'] ?? 0) : (float)($o['amount_paid'] ?? 0),
                'job_order_id'         => $linked_job_id > 0 ? $linked_job_id : null,
                'notes'                => $o['notes'] ?? '',
                'store_order_notes'    => $o['notes'] ?? '',
                'revision_reason'      => $o['revision_reason'] ?? '',
                'customer_address'     => $o['customer_address'] ?? '',
                'payment_proof_status' => $payment_proof_status,
                'payment_proof_path'   => $payment_proof_url,
                'payment_submitted_amount' => (float)($o['downpayment_amount'] ?? 0),
                'payment_proof_uploaded_at' => $o['payment_submitted_at'] ?? null,
                'payment_status'       => 'NO',
                'readiness'            => $linked_job['readiness'] ?? 'READY',
                'order_source'         => jo_api_resolve_order_source($order_id, $o['order_source'] ?? null),
                'items'                => $items_out,
                'materials'            => $linked_job_materials,
                'ink_usage'            => $linked_job_ink_usage,
            ];
            jo_api_json_response(['success' => true, 'data' => $data]);
            break;

        case 'update_status':
            $id = (int)($_POST['id'] ?? 0);
            $status = sanitize($_POST['status'] ?? '');
            $machineId = isset($_POST['machine_id']) ? (int)$_POST['machine_id'] : null;
            $reason = sanitize($_POST['reason'] ?? '');
            if (!$id || !$status) throw new Exception("ID and status required.");
            jo_api_require_staff_branch($joStaffBranch, $id);
            
            if ($status === 'For Revision' && $reason !== '') {
                db_execute("UPDATE job_orders SET notes = CONCAT(IFNULL(notes, ''), '\n[REVISION REQUEST] ', ?) WHERE id = ?", 'si', [$reason, $id]);
            }

            
            $res = JobOrderService::updateStatus($id, $status, $machineId, $reason);
            jo_api_json_response(['success' => $res]);
            break;

        case 'update_order_price':
            $order_id = (int)($_POST['order_id'] ?? 0);
            $price = (float)($_POST['price'] ?? 0);
            if (!$order_id) throw new Exception("Order ID required.");
            if ($joStaffBranch !== null && !printflow_order_in_branch($order_id, $joStaffBranch)) {
                throw new Exception("Unauthorized");
            }
            $orderMetaRows = db_query(
                "SELECT order_source, order_type, status
                 FROM orders
                 WHERE order_id = ?
                 LIMIT 1",
                'i',
                [$order_id]
            ) ?: [];
            $orderMeta = $orderMetaRows[0] ?? [];

            $sql = "UPDATE orders SET total_amount = ? WHERE order_id = ?";
            $res = db_execute($sql, 'di', [$price, $order_id]);
            db_execute('UPDATE order_items SET unit_price = ? WHERE order_id = ? LIMIT 1', 'di', [$price, $order_id]);

            $linkedCustomizationRows = db_query(
                "SELECT customization_id
                 FROM customizations
                 WHERE order_id = ?",
                'i',
                [$order_id]
            ) ?: [];

            $isPosCustomizationOrder =
                !empty($linkedCustomizationRows) &&
                strtolower(trim((string)($orderMeta['order_source'] ?? ''))) === 'pos';

            if ($isPosCustomizationOrder) {
                db_execute(
                    "UPDATE customizations
                     SET status = 'Approved', updated_at = NOW()
                     WHERE order_id = ?
                       AND status NOT IN ('Processing', 'In Production', 'Ready for Pickup', 'Ready For Pickup', 'Completed', 'Rejected', 'Cancelled')",
                    'i',
                    [$order_id]
                );

                db_execute(
                    "UPDATE orders
                     SET status = 'Approved'
                     WHERE order_id = ?
                       AND status NOT IN ('Processing', 'In Production', 'Printing', 'Ready for Pickup', 'Completed', 'Rejected', 'Cancelled')",
                    'i',
                    [$order_id]
                );

                JobOrderService::ensureJobsForStoreOrder($order_id);
                $linkedJobs = db_query(
                    "SELECT id, status
                     FROM job_orders
                     WHERE order_id = ?
                       AND status NOT IN ('COMPLETED', 'CANCELLED')
                     ORDER BY id ASC",
                    'i',
                    [$order_id]
                ) ?: [];

                foreach ($linkedJobs as $jobRow) {
                    $jobId = (int)($jobRow['id'] ?? 0);
                    if ($jobId <= 0) {
                        continue;
                    }

                    db_execute(
                        "UPDATE job_orders SET estimated_total = ?, required_payment = ? WHERE id = ?",
                        'ddi',
                        [$price, $price, $jobId]
                    );

                    $jobStatus = strtoupper(trim((string)($jobRow['status'] ?? '')));
                    if (!in_array($jobStatus, ['IN_PRODUCTION', 'TO_RECEIVE', 'COMPLETED', 'CANCELLED'], true)) {
                        JobOrderService::updateStatus($jobId, 'APPROVED');
                    }
                }
            }

            jo_api_json_response(['success' => $res]);
            break;

        case 'create_order':
            $service = sanitize($_POST['service_type'] ?? '');
            if (!$service) throw new Exception("Service type required.");
            
            $width = (float)($_POST['width_ft'] ?? 0);
            $height = (float)($_POST['height_ft'] ?? 0);
            $qty = (int)($_POST['quantity'] ?? 1);
            $notes = sanitize($_POST['notes'] ?? '');
            
            $orderMaterials = [];

            $orderId = JobOrderService::createOrder([
                'customer_id'     => ($_SESSION['user_type'] === 'Customer') ? $_SESSION['user_id'] : ($_POST['customer_id'] ?? null),
                'customer_name'   => sanitize($_POST['customer_name'] ?? ''),
                'service_type'    => $service,
                'width_ft'        => $width,
                'height_ft'       => $height,
                'quantity'        => $qty,
                'total_sqft'      => $width * $height * $qty,
                'price_per_sqft'  => null, // Staff will fill
                'price_per_piece' => null,
                'estimated_total' => null,
                'notes'           => $notes,
                'artwork_path'    => null,
                'created_by'      => ($_SESSION['user_type'] !== 'Customer') ? $_SESSION['user_id'] : null
            ], $orderMaterials);
            
            if ($joStaffBranch !== null && $orderId) {
                db_execute('UPDATE job_orders SET branch_id = ? WHERE id = ?', 'ii', [$joStaffBranch, (int)$orderId]);
            }
            
            jo_api_json_response(['success' => true, 'id' => $orderId]);
            break;

        case 'assign_roll':
            $jomId = (int)($_POST['jom_id'] ?? 0);
            $rollId = (int)($_POST['roll_id'] ?? 0);
            if (!$jomId || !$rollId) throw new Exception("Incomplete assignment data.");
            $jomRow = db_query('SELECT job_order_id FROM job_order_materials WHERE id = ?', 'i', [$jomId]);
            jo_api_require_staff_branch($joStaffBranch, (int)($jomRow[0]['job_order_id'] ?? 0));
            
            $res = JobOrderService::assignRoll($jomId, $rollId);
            jo_api_json_response(['success' => $res]);
            break;

        case 'set_price':
            $id = (int)($_POST['id'] ?? 0);
            $price = (float)($_POST['price'] ?? 0);
            if (!$id) throw new Exception("ID required.");
            jo_api_require_staff_branch($joStaffBranch, $id);
            // Setting the price also means updating the required payment to match exactly
            $res = db_execute("UPDATE job_orders SET estimated_total = ?, required_payment = ? WHERE id = ?", 'ddi', [$price, $price, $id]);
            // Also update the orders table so the customer sees the correct price
            $job = db_query("SELECT order_id FROM job_orders WHERE id = ?", 'i', [$id]);
            if (!empty($job) && !empty($job[0]['order_id'])) {
                db_execute("UPDATE orders SET total_amount = ? WHERE order_id = ?", 'di', [$price, $job[0]['order_id']]);
            }
            jo_api_json_response(['success' => (bool)$res]);
            break;

        case 'add_material':
            $orderId = (int)($_POST['order_id'] ?? 0);
            $orderType = isset($_POST['order_type']) ? sanitize($_POST['order_type']) : null;
            $itemId = (int)($_POST['item_id'] ?? 0);
            $qty = (float)($_POST['quantity'] ?? 1);
            $uom = sanitize($_POST['uom'] ?? 'pcs');
            $rollId = !empty($_POST['roll_id']) ? (int)$_POST['roll_id'] : null;
            $notes = sanitize($_POST['notes'] ?? '');
            $metadata = isset($_POST['metadata']) ? json_decode($_POST['metadata'], true) : null;
            
            if (!$orderId || !$itemId) throw new Exception("Incomplete material data.");
            if ($qty < 1) throw new Exception("Material quantity must be at least 1.");
            if (strtoupper((string)$orderType) === 'ORDER') {
                jo_api_require_staff_order_branch($joStaffBranch, $orderId);
            } else {
                jo_api_require_staff_branch($joStaffBranch, $orderId);
            }
            $res = JobOrderService::addMaterial($orderId, $itemId, $qty, $uom, $rollId, $notes, $metadata, $orderType);
            jo_api_json_response(['success' => true, 'id' => $res]);
            break;

        case 'save_ink_usage':
            $orderId = (int)($_POST['order_id'] ?? 0);
            $orderType = isset($_POST['order_type']) ? sanitize($_POST['order_type']) : null;
            $inkData = isset($_POST['ink_data']) ? json_decode($_POST['ink_data'], true) : [];
            
            if (!$orderId) throw new Exception("Order ID required.");
            if (strtoupper((string)$orderType) === 'ORDER') {
                jo_api_require_staff_order_branch($joStaffBranch, $orderId);
            } else {
                jo_api_require_staff_branch($joStaffBranch, $orderId);
            }
            $res = JobOrderService::saveInkUsage($orderId, $inkData, $orderType);
            jo_api_json_response(['success' => true]);
            break;

        case 'preview_impact':
            $itemId = (int)($_GET['item_id'] ?? 0);
            $rollId = isset($_GET['roll_id']) ? (int)$_GET['roll_id'] : null;
            $qty = (float)($_GET['quantity'] ?? 0);
            $height = (float)($_GET['height'] ?? 0);
            
            $res = JobOrderService::previewImpact($itemId, $rollId, $qty, $height);
            jo_api_json_response(['success' => true, 'data' => $res]);
            break;

        case 'remove_material':
            $jomId = (int)($_POST['id'] ?? 0);
            if (!$jomId) throw new Exception("ID required.");
            throw new Exception('Assigned materials cannot be removed once they have been set.');
            break;

        default:
            throw new Exception("Unknown action: $action");
    }
} catch (Throwable $e) {
    ob_clean(); // Clear any partial output
    http_response_code(400);
    jo_api_json_response(['success' => false, 'error' => $e->getMessage()], 400);
}

// Flush clean JSON output
ob_end_flush();
