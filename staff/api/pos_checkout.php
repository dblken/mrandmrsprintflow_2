<?php
/**
 * API: POS Checkout Process
 * Path: staff/api/pos_checkout.php
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/product_branch_stock.php';
require_once __DIR__ . '/../../includes/JobOrderService.php';

function pos_table_has_column(string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $rows = db_query(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1",
        'ss',
        [$table, $column]
    ) ?: [];

    return $cache[$key] = !empty($rows);
}

function pos_get_service_placeholder_product_id(): int {
    $existing = db_query("SELECT product_id FROM products WHERE sku = 'POS-SERVICE' LIMIT 1") ?: [];
    if (!empty($existing)) {
        return (int)$existing[0]['product_id'];
    }

    global $conn;
    $created = db_execute(
        "INSERT INTO products (sku, name, category, description, price, stock_quantity, status)
         VALUES ('POS-SERVICE', 'POS Service Item', 'Service', 'Placeholder product for POS service-only order items.', 0, 0, 'Activated')"
    );

    if ($created) {
        return (int)$conn->insert_id;
    }

    $existing = db_query("SELECT product_id FROM products WHERE sku = 'POS-SERVICE' LIMIT 1") ?: [];
    if (!empty($existing)) {
        return (int)$existing[0]['product_id'];
    }

    $fallback = db_query("SELECT product_id FROM products ORDER BY product_id ASC LIMIT 1") ?: [];
    if (!empty($fallback)) {
        error_log('PrintFlow POS checkout: using first available product as service placeholder fallback.');
        return (int)$fallback[0]['product_id'];
    }

    return 0;
}

function pos_migrate_pending_assignments_to_order(int $sourceOrderId, int $targetOrderId): void {
    if ($sourceOrderId <= 0 || $targetOrderId <= 0 || $sourceOrderId === $targetOrderId) {
        return;
    }

    $sourceJobRows = db_query(
        "SELECT id FROM job_orders WHERE order_id = ? ORDER BY id ASC",
        'i',
        [$sourceOrderId]
    ) ?: [];
    $sourceJobIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $sourceJobRows)));

    $useStdOrderId = pos_table_has_column('job_order_materials', 'std_order_id')
        && pos_table_has_column('job_order_ink_usage', 'std_order_id');

    if (empty($sourceJobIds) && !$useStdOrderId) {
        return;
    }
    if (!empty($sourceJobIds)) {
        $sourceJobPlaceholders = implode(',', array_fill(0, count($sourceJobIds), '?'));
        db_execute(
            "UPDATE job_orders
             SET order_id = ?
             WHERE id IN ($sourceJobPlaceholders)",
            'i' . str_repeat('i', count($sourceJobIds)),
            array_merge([$targetOrderId], $sourceJobIds)
        );
    }
    if ($useStdOrderId) {
        db_execute(
            "UPDATE job_order_materials
             SET std_order_id = ?
             WHERE std_order_id = ?
               AND (job_order_id IS NULL OR job_order_id = 0)",
            'ii',
            [$targetOrderId, $sourceOrderId]
        );
        db_execute(
            "UPDATE job_order_ink_usage
             SET std_order_id = ?
             WHERE std_order_id = ?
               AND (job_order_id IS NULL OR job_order_id = 0)",
            'ii',
            [$targetOrderId, $sourceOrderId]
        );
    }

    error_log(sprintf(
        'PrintFlow POS material migration: re-linked %d source jobs from pending order %d to final order %d',
        count($sourceJobIds),
        $sourceOrderId,
        $targetOrderId
    ));
}

/**
 * Copy pending POS material assignments to the final sale order and move the
 * linked production jobs into the live POS status without deducting inventory
 * yet. Final deduction must happen only when the POS order is marked Completed.
 */
function pos_finalize_inventory_after_checkout(int $finalOrderId, string $targetStatus, int $pendingOrderId = 0): void {
    if ($finalOrderId <= 0 || $targetStatus === '') {
        return;
    }

    if ($pendingOrderId > 0) {
        pos_migrate_pending_assignments_to_order($pendingOrderId, $finalOrderId);
    }

    JobOrderService::ensureJobsForStoreOrder($finalOrderId);
    $jobRows = db_query(
        "SELECT id
         FROM job_orders
         WHERE order_id = ?
           AND status NOT IN ('COMPLETED', 'CANCELLED')
         ORDER BY id ASC",
        'i',
        [$finalOrderId]
    ) ?: [];
    if (empty($jobRows)) {
        throw new Exception('No linked production job was available for POS inventory processing.');
    }

    $jobIds = array_values(array_filter(array_map(static function (array $row): int {
        return (int)($row['id'] ?? 0);
    }, $jobRows)));

    if (!empty($jobIds)) {
        $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
        db_execute(
            "UPDATE job_orders
             SET status = ?, updated_at = NOW()
             WHERE id IN ($placeholders)",
            's' . str_repeat('i', count($jobIds)),
            array_merge([$targetStatus], $jobIds)
        );
    }

    error_log(sprintf(
        'PrintFlow POS finalization: linked %d active jobs to order %d with status %s; inventory deduction deferred until completion',
        count($jobIds),
        $finalOrderId,
        $targetStatus
    ));
}

/**
 * POS customization rows must resolve to real job_orders, but the order insert
 * transaction must commit first before jobs are finalized.
 */
function pos_sync_customization_jobs_after_commit(int $orderId, string $targetStatus): void {
    if ($orderId <= 0 || $targetStatus === '') {
        return;
    }

    $jobs = db_query(
        "SELECT id FROM job_orders WHERE order_id = ? AND status NOT IN ('COMPLETED', 'CANCELLED') ORDER BY id ASC",
        'i',
        [$orderId]
    ) ?: [];

    foreach ($jobs as $job) {
        JobOrderService::updateStatus((int)$job['id'], $targetStatus);
    }
}

// Require staff or admin role
if (!has_role(['Admin', 'Staff'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

header('Content-Type: application/json');

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data.']);
    exit;
}

// Handle create_pending_customization action
if (isset($data['action']) && $data['action'] === 'create_pending_customization') {
    $customer_id = $data['customer_id'] === 'guest' ? null : (int)$data['customer_id'];
    $post_commit_order_id = 0;
    $transaction_open = false;
    
    if ($customer_id === null) {
        global $conn;
        $res = db_query("SELECT customer_id FROM customers WHERE email='walkin@pos.local' LIMIT 1");
        if (!empty($res)) {
            $customer_id = (int)$res[0]['customer_id'];
        } else {
            db_execute("INSERT INTO customers (first_name, last_name, email, password_hash, status) VALUES ('Walk-in', 'Guest', 'walkin@pos.local', '', 'Active')");
            $customer_id = $conn->insert_id;
        }
    }
    
    $item = $data['item'];
    $product_id = (int)$item['id'];
    $name = $item['name'] ?? 'Service';
    $qty = (int)($item['qty'] ?? 1);
    $customization = $item['customization'] ?? [];
    
    // Mark as POS source
    $customization['source'] = 'POS';
    
    try {
        global $conn;
        $conn->begin_transaction();
        $transaction_open = true;
        
        $branch_id = (int)($_SESSION['branch_id'] ?? 1);
        if ($branch_id < 1) $branch_id = 1;
        
        // Create order with status 'Approved' (skipped initial Pending verification for POS)
        // Will be updated to 'Paid' when checkout completes
        $order_result = db_execute(
            "INSERT INTO orders (customer_id, branch_id, reference_id, total_amount, status, payment_status, payment_method, order_date, updated_at, order_type, order_source) 
             VALUES (?, ?, ?, 0, 'Approved', 'Unpaid', 'Cash', NOW(), NOW(), 'custom', 'pos')",
            'iii',
            [$customer_id, $branch_id, $product_id]
        );
        
        if (!$order_result) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to create order.']);
            exit;
        }
        
        $order_id = $conn->insert_id;
        
        // Store order_id in session for later update during checkout
        if (!isset($_SESSION['pos_pending_orders'])) {
            $_SESSION['pos_pending_orders'] = [];
        }
        $_SESSION['pos_pending_orders'][$product_id] = $order_id;
        
        // Create order item with price = 0
        $pending_item_product_id = pos_get_service_placeholder_product_id();
        if ($pending_item_product_id <= 0) {
            // Last fallback: use service ID when placeholder setup is unavailable.
            $pending_item_product_id = $product_id;
        }
        $customization_json = json_encode($customization ?: new stdClass());
        $item_result = db_execute(
            "INSERT INTO order_items (order_id, product_id, quantity, unit_price, customization_data) VALUES (?, ?, ?, 0, ?)",
            'iiis',
            [$order_id, $pending_item_product_id, $qty, $customization_json]
        );
        
        if (!$item_result) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to add order item.']);
            exit;
        }
        
        $order_item_id = $conn->insert_id;
        
        // Create customization entry with status 'Approved'
        $details_json = json_encode($customization ?: new stdClass());
        $customization_result = db_execute(
            "INSERT INTO customizations (order_id, order_item_id, customer_id, service_type, customization_details, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'Approved', NOW(), NOW())",
            'iiiss',
            [$order_id, $order_item_id, $customer_id, $name, $details_json]
        );
        
        if (!$customization_result) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to create customization entry.']);
            exit;
        }
        
        $customization_id = $conn->insert_id;

        $conn->commit();
        $transaction_open = false;
        $post_commit_order_id = $order_id;
        pos_sync_customization_jobs_after_commit($post_commit_order_id, 'APPROVED');
        echo json_encode(['success' => true, 'customization_id' => $customization_id, 'order_id' => $order_id]);
        exit;
        
    } catch (Exception $e) {
        if ($transaction_open && isset($conn)) {
            $conn->rollback();
        }
        if ($post_commit_order_id > 0) {
            error_log('PrintFlow POS customization sync failed for order #' . $post_commit_order_id . ': ' . $e->getMessage());
            echo json_encode([
                'success' => true,
                'customization_id' => $customization_id ?? null,
                'order_id' => $post_commit_order_id,
                'warning' => 'Customization was created, but production sync needs follow-up.'
            ]);
            exit;
        }
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

if (empty($data['items'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data. Cart is empty.']);
    exit;
}

$customer_id = $data['customer_id'] === 'guest' ? null : (int)$data['customer_id'];

if ($customer_id === null) {
    global $conn;
    $res = db_query("SELECT customer_id FROM customers WHERE email='walkin@pos.local' LIMIT 1");
    if (!empty($res)) {
        $customer_id = (int)$res[0]['customer_id'];
    } else {
        db_execute("INSERT INTO customers (first_name, last_name, email, password_hash, status) VALUES ('Walk-in', 'Guest', 'walkin@pos.local', '', 'Active')");
        $customer_id = $conn->insert_id;
    }
}
$payment_method = sanitize($data['payment_method'] ?? 'Cash');
$reference_number = sanitize($data['reference_number'] ?? '');
$amount_tendered = (float)($data['amount_tendered'] ?? 0);
$items = $data['items'];

if ($payment_method !== 'Cash' && empty($reference_number)) {
    echo json_encode(['success' => false, 'message' => "Reference number is required for $payment_method."]);
    exit;
}
if ($amount_tendered > 1000000) {
    echo json_encode(['success' => false, 'message' => 'Amount paid exceeds maximum limit of ₱1,000,000.']);
    exit;
}

printflow_ensure_product_branch_stock_table();
$pos_branch_id = (int)($_SESSION['branch_id'] ?? 0);

// Calculate total and verify stock
$total_amount = 0;
$products_cache = [];
$actual_product_ids = [];
foreach ($items as $item) {
    $product_id = (int)$item['id'];
    $qty = (int)$item['qty'];
    $is_service_item = isset($item['is_service']) && $item['is_service'];

    $product = db_query("SELECT price, name FROM products WHERE product_id = ?", 'i', [$product_id]);
    if (!$product) {
        if ($is_service_item) {
            // Service IDs may not exist in products table — use name/price from payload
            $products_cache[$product_id] = ['name' => $item['name'] ?? 'Service', 'price' => (float)($item['price'] ?? 0)];
            $total_amount += (float)($item['price'] ?? 0) * $qty;
            continue;
        }
        echo json_encode(['success' => false, 'message' => 'Product not found: ' . $product_id]);
        exit;
    }
    $p = $product[0];
    $products_cache[$product_id] = $p;
    $actual_product_ids[$product_id] = true;

    // Skip stock check for services
    if (!$is_service_item) {
        [$effStock] = printflow_product_effective_stock($product_id, $pos_branch_id);
        if ($effStock < $qty) {
            echo json_encode(['success' => false, 'message' => 'Insufficient stock for ' . $p['name']]);
            exit;
        }
    }

    $price = (float)($item['price'] ?? $p['price']);
    $total_amount += $price * $qty;
}

try {
    global $conn;
    $post_commit_job_sync = [];
    $transaction_open = false;
    $service_placeholder_product_id = 0;
    $conn->begin_transaction();
    $transaction_open = true;

    // Create Order
    // For POS walk-ins, we use status 'In Production' and payment_status 'Paid', skipping 'To Pay' and 'To Verify'
    $branch_id = (int)($_SESSION['branch_id'] ?? 1);
    if ($branch_id < 1) $branch_id = 1;

    // Determine order_type based on cart content
    $has_service = false;
    foreach ($items as $item) {
        if (isset($item['is_service']) && $item['is_service']) {
            $has_service = true;
            break;
        }
    }
    $order_type = $has_service ? 'custom' : 'product';
    $reference_id = $items[0]['id'] ?? null;

    $order_result = db_execute(
        "INSERT INTO orders (customer_id, branch_id, reference_id, total_amount, status, payment_status, payment_method, payment_reference, order_date, updated_at, order_type, order_source) 
         VALUES (?, ?, ?, ?, 'In Production', 'Paid', ?, ?, NOW(), NOW(), ?, 'pos')",
        'iiidsss',
        [$customer_id, $branch_id, $reference_id, $total_amount, $payment_method, $reference_number, $order_type]
    );

    if (!$order_result) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Failed to create order.']);
        exit;
    }

    $order_id = $conn->insert_id;

    // Insert Order Items and Update Stock
    foreach ($items as $item) {
        $product_id = (int)$item['id'];
        $qty = (int)$item['qty'];
        $price = (float)$item['price'];
        $p = $products_cache[$product_id] ?? null;
        $prod_name = $p['name'] ?? 'Product';

        // Use the name from the cart item if provided (for services)
        $name = $item['name'] ?? $prod_name;
        
        // Detect if this specific item is a service or customized product
        $is_service = (isset($item['is_service']) && $item['is_service']);
        
        $custom_details = $item['customization'] ?? [];
        if (!is_array($custom_details)) $custom_details = [];

        // Service catalog IDs can overlap product IDs. Only keep product_id for
        // product-backed custom items, not services selected from the Services tab.
        $is_catalog_service = $is_service && (int)($custom_details['service_id'] ?? 0) === $product_id;
        $is_actual_product = !$is_catalog_service && isset($actual_product_ids[$product_id]);
        $order_item_product_id = $is_actual_product ? $product_id : 0;
        if ($order_item_product_id <= 0) {
            if ($service_placeholder_product_id <= 0) {
                $service_placeholder_product_id = pos_get_service_placeholder_product_id();
            }
            $order_item_product_id = $service_placeholder_product_id;
            if ($order_item_product_id <= 0 && isset($actual_product_ids[$product_id])) {
                // Last fallback: use existing product IDs when this service came from products.
                $order_item_product_id = $product_id;
            }
            if ($order_item_product_id <= 0) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Failed to prepare service order item support.']);
                exit;
            }
        }
        
        // Store the service name in customization for proper display
        if ($is_service && $name) {
            $custom_details['service_type'] = $name;
        }
        
        $customization_json = json_encode($custom_details ?: new stdClass());

        $item_result = db_execute(
            "INSERT INTO order_items (order_id, product_id, quantity, unit_price, customization_data) VALUES (?, ?, ?, ?, ?)",
            'iiids',
            [$order_id, $order_item_product_id, $qty, $price, $customization_json]
        );

        if (!$item_result) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to add order items.']);
            exit;
        }
        
        $order_item_id = $conn->insert_id;

        // Always create or re-link customization entry for service items
        if ($is_service) {
            $details = $custom_details;
            $details['source'] = 'POS'; // Mark as POS purchase
            $details_json = json_encode($details ?: new stdClass());
            $pendingOrderId = (int)($item['pending_order_id'] ?? 0);
            if ($pendingOrderId <= 0 && isset($_SESSION['pos_pending_orders'][$product_id])) {
                $pendingOrderId = (int)$_SESSION['pos_pending_orders'][$product_id];
            }
            $pendingCustomizationId = (int)($item['pending_customization_id'] ?? 0);

            $customization_result = false;
            if ($pendingOrderId > 0) {
                if ($pendingCustomizationId > 0) {
                    $customizationExists = db_query(
                        "SELECT customization_id
                         FROM customizations
                         WHERE customization_id = ?
                         LIMIT 1",
                        'i',
                        [$pendingCustomizationId]
                    ) ?: [];

                    if (!empty($customizationExists)) {
                        $customization_result = db_execute(
                            "UPDATE customizations
                             SET order_id = ?, order_item_id = ?, customer_id = ?, service_type = ?, customization_details = ?, status = 'In Production', updated_at = NOW()
                             WHERE customization_id = ?",
                            'iiissi',
                            [$order_id, $order_item_id, $customer_id, $name, $details_json, $pendingCustomizationId]
                        );
                        if ($customization_result) {
                            $last_customization_id = $pendingCustomizationId;
                        }
                    }
                }

                if (!$customization_result) {
                    $existingCustomizationRows = db_query(
                        "SELECT customization_id
                         FROM customizations
                         WHERE order_id = ?
                         ORDER BY customization_id ASC",
                        'i',
                        [$pendingOrderId]
                    ) ?: [];

                    if (!empty($existingCustomizationRows)) {
                        $customizationIds = array_values(array_filter(array_map(static function (array $row): int {
                            return (int)($row['customization_id'] ?? 0);
                        }, $existingCustomizationRows)));
                        if (!empty($customizationIds)) {
                            $placeholders = implode(',', array_fill(0, count($customizationIds), '?'));
                            $customization_result = db_execute(
                                "UPDATE customizations
                                 SET order_id = ?, order_item_id = ?, customer_id = ?, service_type = ?, customization_details = ?, status = 'In Production', updated_at = NOW()
                                 WHERE customization_id IN ($placeholders)",
                                'iiiss' . str_repeat('i', count($customizationIds)),
                                array_merge([$order_id, $order_item_id, $customer_id, $name, $details_json], $customizationIds)
                            );
                            $last_customization_id = $customizationIds[0] ?? null;
                        }
                    }
                }
            }

            if (!$customization_result) {
                $customization_result = db_execute(
                    "INSERT INTO customizations (order_id, order_item_id, customer_id, service_type, customization_details, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'In Production', NOW(), NOW())",
                    'iiiss',
                    [$order_id, $order_item_id, $customer_id, $name, $details_json]
                );
                if ($customization_result) {
                    $last_customization_id = $conn->insert_id;
                }
            }

            if (!$customization_result) {
                $conn->rollback();
                echo json_encode(['success' => false, 'message' => 'Failed to create customization entry.']);
                exit;
            }
            if ($pendingOrderId <= 0) {
                error_log(sprintf(
                    'PrintFlow POS checkout warning: missing pending_order_id for service item product_id=%d on final order #%d',
                    $product_id,
                    $order_id
                ));
            }
            $post_commit_job_sync[$order_id] = [
                'status' => 'IN_PRODUCTION',
                'pending_order_id' => $pendingOrderId
            ];
            
            // Update any existing pending customization orders for this item to mark as paid
            if ($pendingOrderId > 0) {
                db_execute(
                    "UPDATE orders
                     SET payment_status = 'Paid',
                         payment_method = ?,
                         total_amount = ?,
                         status = 'In Production',
                         order_source = 'pos_merged',
                         updated_at = NOW()
                     WHERE order_id = ?",
                    'sdi',
                    [$payment_method, $price * $qty, $pendingOrderId]
                );
                // Hide the temporary POS source order after its customization is re-linked.
                unset($_SESSION['pos_pending_orders'][$product_id]);
            }
        }

        // Deduct stock is removed here as per user request to deduct only when status is COMPLETED
    }

    $conn->commit();
    $transaction_open = false;
    $sync_warning = '';
    foreach ($post_commit_job_sync as $sync_order_id => $syncMeta) {
        $syncOrderId = (int)$sync_order_id;
        $syncStatus = is_array($syncMeta) ? (string)($syncMeta['status'] ?? '') : (string)$syncMeta;
        $pendingOrderId = is_array($syncMeta) ? (int)($syncMeta['pending_order_id'] ?? 0) : 0;
        try {
            if ($pendingOrderId > 0) {
                pos_migrate_pending_assignments_to_order($pendingOrderId, $syncOrderId);
            }
            pos_sync_customization_jobs_after_commit($syncOrderId, $syncStatus);
        } catch (Throwable $syncError) {
            $sync_warning = 'Sale completed, but production sync needs follow-up.';
            error_log('PrintFlow POS checkout sync warning for order #' . $syncOrderId . ': ' . $syncError->getMessage());
        }
    }
    echo json_encode([
        'success' => true,
        'order_id' => $order_id,
        'customization_id' => $last_customization_id ?? null,
        'message' => 'Sale completed successfully.',
        'warning' => $sync_warning
    ]);

} catch (Exception $e) {
    if ($transaction_open && isset($conn)) {
        $conn->rollback();
    }
    if (!empty($order_id) && !$transaction_open) {
        error_log('PrintFlow POS checkout post-commit sync failed for order #' . (int)$order_id . ': ' . $e->getMessage());
        echo json_encode([
            'success' => true,
            'order_id' => (int)$order_id,
            'customization_id' => $last_customization_id ?? null,
            'message' => 'Sale completed successfully.',
            'warning' => 'Production sync needs follow-up.'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
