<?php
/**
 * Customer Order API
 * Handles customer-side order submission and profile data.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/JobOrderService.php';
require_once __DIR__ . '/../includes/service_material_rule_assignments.php';

require_role(['Customer', 'Staff', 'Admin', 'Manager']); 
header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_customer_info':
            $customerId = ($_SESSION['user_type'] === 'Customer') ? $_SESSION['user_id'] : (int)($_GET['customer_id'] ?? 0);
            if (!$customerId) throw new Exception("Customer ID required.");
            
            $customer = db_query("SELECT customer_id, first_name, last_name, email, phone, address, customer_type, transaction_count FROM customers WHERE customer_id = ?", 'i', [$customerId]);
            if (!$customer) throw new Exception("Customer not found.");
            
            echo json_encode(['success' => true, 'data' => $customer[0]]);
            break;

        case 'create_order':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Invalid request method.");
            if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
                http_response_code(419);
                throw new Exception('Your session expired. Please refresh and try again.');
            }
            
            // 1. Validate Basic Info
            $service = sanitize($_POST['service_type'] ?? '');
            if (!$service) throw new Exception("Service type is required.");
            
            $customerId = ($_SESSION['user_type'] === 'Customer') ? $_SESSION['user_id'] : (int)($_POST['customer_id'] ?? 0);
            if (!$customerId) throw new Exception("Customer context is missing.");

            $branchId = (int)($_POST['branch_id'] ?? 1);
            $width = (float)($_POST['width_ft'] ?? 0);
            $height = (float)($_POST['height_ft'] ?? 0);
            $qty = (int)($_POST['quantity'] ?? 1);
            $notes = sanitize($_POST['notes'] ?? '');
            $jobTitle = sanitize($_POST['job_title'] ?? $service);

            // 2. Map Service to Materials (using existing logic from admin API)
            require_once __DIR__ . '/../includes/ServiceAvailabilityChecker.php';
            $materialRuleRows = db_query(
                "SELECT item_id, rule_type FROM service_material_rules WHERE service_type = ?",
                's', [$service]
            );
            if ($materialRuleRows === false) throw new RuntimeException('Service material rules could not be loaded. No order was created.');
            $hasRequiredRules = false;
            $requiredRuleCount = 0;
            foreach ($materialRuleRows as $rule) {
                $ruleType = strtoupper(trim((string)($rule['rule_type'] ?? '')));
                if ($ruleType === 'REQUIRED') { $hasRequiredRules = true; $requiredRuleCount++; }
                elseif ($ruleType !== 'OPTIONAL') throw new RuntimeException('Service material configuration contains an unsupported rule type. No order was created.');
            }
            $orderMaterials = [];
            if ($hasRequiredRules) {
                $hasMultiplier = function_exists('db_table_has_column') && db_table_has_column('service_material_rules', 'qty_multiplier', true);
                $hasApproval = function_exists('db_table_has_column') && db_table_has_column('service_material_rules', 'qty_multiplier_approved', true);
                $hasApprover = function_exists('db_table_has_column') && db_table_has_column('service_material_rules', 'qty_multiplier_approved_by', true);
                $hasApprovalTimestamp = function_exists('db_table_has_column') && db_table_has_column('service_material_rules', 'qty_multiplier_approved_at', true);
                printflow_pos_assert_service_stock_multiplier_schema(
                    $hasMultiplier,
                    $hasApproval,
                    $hasApprover,
                    $hasApprovalTimestamp
                );
                $requiredRules = db_query(
                    "SELECT r.item_id, r.rule_type, r.qty_multiplier, r.qty_multiplier_approved,
                            r.qty_multiplier_approved_by, r.qty_multiplier_approved_at, i.status AS item_status
                     FROM service_material_rules r
                     LEFT JOIN inv_items i ON i.id = r.item_id
                     WHERE r.service_type = ? AND UPPER(TRIM(r.rule_type)) = 'REQUIRED'",
                    's', [$service]
                );
                if ($requiredRules === false) throw new RuntimeException('Required service material configuration could not be loaded. No order was created.');
                if (count($requiredRules) !== $requiredRuleCount) throw new RuntimeException('Required service material configuration changed during validation. Refresh and retry; no order was created.');
                $orderMaterials = printflow_build_approved_service_material_assignments($requiredRules, $qty, $height, $service);
            }

            // 3. Create Job Order
            $orderId = JobOrderService::createOrder([
                'order_id'        => null, // Auto-increment in JobOrderService
                'branch_id'       => $branchId,
                'customer_id'     => $customerId,
                'job_title'       => $jobTitle,
                'service_type'    => $service,
                'width_ft'        => $width,
                'height_ft'       => $height,
                'quantity'        => $qty,
                'total_sqft'      => $width * $height * $qty,
                'price_per_sqft'  => null,
                'price_per_piece' => null,
                'estimated_total' => (float)($_POST['estimated_total'] ?? 0),
                'notes'           => $notes,
                'artwork_path'    => null, // We'll use the new table instead
                'created_by'      => ($_SESSION['user_type'] !== 'Customer') ? $_SESSION['user_id'] : null,
                'due_date'        => sanitize($_POST['due_date'] ?? null),
                'priority'       => sanitize($_POST['priority'] ?? 'NORMAL')
            ], $orderMaterials);

            // 4. Handle File Uploads
            if (!empty($_FILES['artworks']['name'][0])) {
                $uploadDir = __DIR__ . '/../uploads/artworks/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

                foreach ($_FILES['artworks']['tmp_name'] as $key => $tmpName) {
                    if ($_FILES['artworks']['error'][$key] === UPLOAD_ERR_OK) {
                        $originalName = $_FILES['artworks']['name'][$key];
                        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
                        $newName = 'JO_' . $orderId . '_' . uniqid() . '.' . $ext;
                        $targetPath = $uploadDir . $newName;

                        if (move_uploaded_file($tmpName, $targetPath)) {
                            db_execute(
                                "INSERT INTO job_order_files (job_order_id, file_path, file_name, file_type) VALUES (?, ?, ?, ?)",
                                'isss', [$orderId, 'uploads/artworks/' . $newName, $originalName, $_FILES['artworks']['type'][$key]]
                            );
                        }
                    }
                }
            }

            // Notify admin and staff of new job order
            notify_shop_users("New Custom Job #{$orderId} submitted.", 'Job Order', false, false, $orderId);

            echo json_encode(['success' => true, 'id' => $orderId]);
            break;

        default:
            throw new Exception("Action '$action' not supported.");
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
