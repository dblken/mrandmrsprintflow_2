<?php
/**
 * Process Dynamic Service Order Form Submission
 * Safely handles form data from admin-configured dynamic forms
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dynamic_form_helpers.php';

require_role('Customer');
require_once __DIR__ . '/../includes/require_customer_profile_complete.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: products.php");
    exit;
}

// CSRF Check
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $_SESSION['error'] = "Invalid session. Please refresh and try again.";
    header("Location: products.php");
    exit;
}

$product_id = (int)($_POST['product_id'] ?? 0);
$config_id = (int)($_POST['config_id'] ?? 0);
$quantity = (int)($_POST['quantity'] ?? 1);
$branch_id = (int)($_POST['branch_id'] ?? 1);
$action = $_POST['action'] ?? 'add_to_cart';

// Validate product
$product = db_query("SELECT * FROM products WHERE product_id = ? AND status = 'Activated'", 'i', [$product_id]);
if (empty($product)) {
    $_SESSION['error'] = "Product not found.";
    header("Location: products.php");
    exit;
}
$product = $product[0];

// Validate dynamic form config
$config = db_query("SELECT * FROM service_form_configs WHERE config_id = ? AND product_id = ? AND is_active = 1", 'ii', [$config_id, $product_id]);
if (empty($config)) {
    $_SESSION['error'] = "Form configuration not found.";
    header("Location: products.php");
    exit;
}
$config = $config[0];

// Check customer restrictions
$customer_id = get_user_id();
if (false) {
    $_SESSION['error'] = "Account restricted. Cannot place order.";
    header("Location: order_dynamic.php?product_id=" . $product_id);
    exit;
}

// Collect all form data
$form_data = [];
$fields = get_form_fields($config_id);

foreach ($fields as $field) {
    $field_name = $field['field_name'];
    
    if ($field['field_type'] === 'file') {
        // Handle file uploads
        if (isset($_FILES[$field_name]) && $_FILES[$field_name]['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES[$field_name]['tmp_name'];
            $file_name = $_FILES[$field_name]['name'];
            $file_size = $_FILES[$field_name]['size'];
            
            // Validate file size (10MB max)
            if ($file_size > 10 * 1024 * 1024) {
                $_SESSION['error'] = "File too large. Maximum size is 10MB.";
                header("Location: order_dynamic.php?product_id=" . $product_id);
                exit;
            }
            
            // Store temp file
            $tmp_path = tempnam(sys_get_temp_dir(), 'pf_dynamic_');
            $data = file_get_contents($file_tmp);
            file_put_contents($tmp_path, $data);
            
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file_tmp);
            finfo_close($finfo);
            
            $form_data[$field_name] = [
                'type' => 'file',
                'tmp_path' => $tmp_path,
                'mime' => $mime,
                'name' => $file_name
            ];
        } elseif ($field['is_required']) {
            $_SESSION['error'] = "Please upload required file: " . $field['field_label'];
            header("Location: order_dynamic.php?product_id=" . $product_id);
            exit;
        }
    } elseif ($field['field_type'] === 'checkbox') {
        // Handle checkbox arrays
        $form_data[$field_name] = $_POST[$field_name] ?? [];
    } else {
        // Handle regular fields
        $value = $_POST[$field_name] ?? '';
        if ($field['is_required'] && empty($value)) {
            $_SESSION['error'] = "Please fill required field: " . $field['field_label'];
            header("Location: order_dynamic.php?product_id=" . $product_id);
            exit;
        }
        $form_data[$field_name] = sanitize($value);
    }
}

// Initialize cart if needed
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Create unique cart item key
$item_key = $product_id . '_dynamic_' . time();

// Prepare cart item
$cart_item = [
    'product_id' => $product_id,
    'name' => $product['name'],
    'category' => $product['category'] ?? 'Service',
    'source_page' => 'dynamic_form',
    'branch_id' => $branch_id,
    'price' => $product['base_price'],
    'quantity' => $quantity,
    'image' => '📦',
    'customization' => [
        'Branch_ID' => $branch_id,
        'form_type' => 'dynamic',
        'config_id' => $config_id
    ],
    'dynamic_form_data' => $form_data
];

// Handle file uploads in form data
foreach ($form_data as $key => $value) {
    if (is_array($value) && isset($value['type']) && $value['type'] === 'file') {
        $cart_item['design_tmp_path'] = $value['tmp_path'];
        $cart_item['design_mime'] = $value['mime'];
        $cart_item['design_name'] = $value['name'];
    }
}

// Add to cart
$_SESSION['cart'][$item_key] = $cart_item;

// Redirect based on action
if ($action === 'buy_now') {
    // For service orders, create order directly instead of going to review page
    // This restores the original behavior
    
    // Check customer restrictions again
    if (false) {
        $_SESSION['error'] = "Account restricted. Cannot place order.";
        header("Location: order_dynamic.php?product_id=" . $product_id);
        exit;
    }
    
    global $conn;
    
    // Get customer info
    $customer = db_query("SELECT * FROM customers WHERE customer_id = ?", 'i', [$customer_id])[0] ?? [];
    
    // Server-side idempotency guard to prevent duplicate order creation
    // from repeated buy-now submits.
    $guard_form_snapshot = $form_data;
    foreach ($guard_form_snapshot as $k => $v) {
        if (is_array($v) && isset($v['type']) && $v['type'] === 'file') {
            unset($guard_form_snapshot[$k]['tmp_path']);
        }
    }
    ksort($guard_form_snapshot);
    $guard_payload = [
        'customer_id' => (int)$customer_id,
        'product_id' => (int)$product_id,
        'config_id' => (int)$config_id,
        'quantity' => (int)$quantity,
        'branch_id' => (int)$branch_id,
        'form_data' => $guard_form_snapshot,
    ];
    $guard_fingerprint = hash('sha256', json_encode($guard_payload));
    $guard_now = time();
    $guard_window_secs = 30;
    $last_guard = $_SESSION['dynamic_submit_guard'] ?? null;
    if (
        is_array($last_guard)
        && ($last_guard['fingerprint'] ?? '') === $guard_fingerprint
        && ($guard_now - (int)($last_guard['ts'] ?? 0)) <= $guard_window_secs
    ) {
        if (!empty($last_guard['order_id'])) {
            $_SESSION['order_success'] = "Order #{$last_guard['order_id']} was already placed successfully.";
            header("Location: orders.php");
            exit;
        }
        $_SESSION['error'] = "Order submission is already in progress. Please wait a moment.";
        header("Location: order_dynamic.php?product_id=" . $product_id);
        exit;
    }
    $_SESSION['dynamic_submit_guard'] = [
        'fingerprint' => $guard_fingerprint,
        'ts' => $guard_now,
        'order_id' => null,
    ];

    // Create order
    $order_sql = "INSERT INTO orders (customer_id, branch_id, reference_id, order_date, total_amount, downpayment_amount, status, payment_status, payment_type, notes, order_type)
                  VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)";
    $order_id = db_execute($order_sql, 'iiiddsssss', [
        $customer_id,
        $branch_id,
        $product_id,
        0, // total_amount
        0, // downpayment_amount
        'To Pay', // status
        'Unpaid', // payment_status
        'full_payment', // payment_type
        null, // notes
        'custom' // order_type
    ]);
    
    if ($order_id) {
        $_SESSION['dynamic_submit_guard']['order_id'] = (int)$order_id;
        // Prepare customization data
        $custom_data = [
            'Branch_ID' => $branch_id,
            'form_type' => 'dynamic',
            'config_id' => $config_id,
            'source_page' => 'dynamic_form',
            'product_type' => $product['name']
        ];
        
        // Add all form fields to customization
        foreach ($form_data as $key => $value) {
            if (is_array($value) && isset($value['type']) && $value['type'] === 'file') {
                // Skip file data in JSON, will be stored separately
                continue;
            }
            $custom_data[$key] = $value;
        }
        
        $custom_json = printflow_encode_customization_payload($custom_data);
        $hasSpecificationsColumn = function_exists('printflow_ensure_order_items_specifications_column')
            ? printflow_ensure_order_items_specifications_column()
            : false;
        
        // Handle file uploads
        $upload_dir = __DIR__ . '/../uploads/orders';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        
        $design_file_path = null;
        $design_binary = null;
        $design_mime = null;
        $design_name = null;
        
        foreach ($form_data as $key => $value) {
            if (is_array($value) && isset($value['type']) && $value['type'] === 'file') {
                if (file_exists($value['tmp_path'])) {
                    $design_binary = file_get_contents($value['tmp_path']);
                    $ext = strtolower(pathinfo($value['name'], PATHINFO_EXTENSION));
                    $new_name = uniqid('design_') . '_' . time() . '.' . $ext;
                    if (copy($value['tmp_path'], $upload_dir . '/' . $new_name)) {
                        $design_file_path = (defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '') . '/uploads/orders/' . $new_name;
                    }
                    $design_mime = $value['mime'];
                    $design_name = $value['name'];
                    @unlink($value['tmp_path']);
                }
                break; // Only handle first file
            }
        }
        
        // Insert order item
        $unit_price = 0; // Will be set by staff
        
        $order_item_id = 0;
        if ($design_binary) {
            $order_item_id = printflow_order_items_insert_line(
                (int)$order_id,
                (int)$product_id,
                (int)$quantity,
                (float)$unit_price,
                $custom_json,
                $design_binary,
                $design_mime,
                $design_name,
                $design_file_path,
                null
            );
        } else {
            $order_item_id = printflow_order_items_insert_line(
                (int)$order_id,
                (int)$product_id,
                (int)$quantity,
                (float)$unit_price,
                $custom_json,
                null,
                null,
                null,
                $design_file_path,
                null
            );
        }

        if ($order_item_id > 0) {
            db_execute(
                "INSERT INTO customizations (order_id, order_item_id, customer_id, service_type, customization_details, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 'Pending Review', NOW(), NOW())",
                'iiiss',
                [
                    $order_id,
                    $order_item_id,
                    $customer_id,
                    (string)($custom_data['service_type'] ?? ($product['name'] ?? 'Service')),
                    $custom_json
                ]
            );
        }
        
        // Clear cart item
        unset($_SESSION['cart'][$item_key]);
        sync_cart_to_db($customer_id);
        
        // Create notification for staff
        $customer_name = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
        if (empty($customer_name)) $customer_name = 'Customer';
        
        notify_staff_new_order((int)$order_id, $customer_name, $customer_id);
        printflow_send_order_update((int)$order_id, 'inquiry');
        
        // Log activity
        log_activity($customer_id, 'Order Placed', "Customer placed order #$order_id");
        
        // Set success message and redirect
        $_SESSION['order_success'] = "Order #$order_id placed successfully! Our team will review and price your order shortly.";
        header("Location: orders.php");
        exit;
    } else {
        unset($_SESSION['dynamic_submit_guard']);
        $_SESSION['error'] = "Failed to place order. Please try again.";
        header("Location: order_dynamic.php?product_id=" . $product_id);
        exit;
    }
} else {
    header("Location: cart.php");
}
exit;
