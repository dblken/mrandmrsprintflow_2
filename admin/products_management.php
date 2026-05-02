<?php
/**
 * Admin Products Management Page
 * PrintFlow - Printing Shop PWA  
 * Full CRUD for products
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/branch_ui.php';
require_once __DIR__ . '/../includes/product_branch_stock.php';

require_role(['Admin', 'Manager']);
// Ensure $base_path is defined
if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

$current_user = get_logged_in_user();
$is_manager = (get_user_type() === 'Manager');
$branchCtx = init_branch_context(true);
$selectedStockBranchId = (int)($branchCtx['selected_branch_id'] ?? 0);
$mgr_branch_id = $is_manager ? $selectedStockBranchId : 0;
$product_stock_branch_id = $selectedStockBranchId;
$product_stock_uses_base = printflow_product_branch_uses_base_stock($product_stock_branch_id);
printflow_ensure_product_branch_stock_table();

$error = '';
$success = '';

function printflow_products_page_redirect_with_flash(string $message, string $type = 'success'): void {
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        session_start();
    }

    if ($type === 'error') {
        $_SESSION['pf_products_flash_error'] = $message;
        unset($_SESSION['pf_products_flash_success']);
    } else {
        $_SESSION['pf_products_flash_success'] = $message;
        unset($_SESSION['pf_products_flash_error']);
    }

    $params = $_GET ?? [];
    unset($params['ajax'], $params['get_archived'], $params['get_next_sku']);
    $target = strtok($_SERVER['REQUEST_URI'] ?? 'products_management.php', '?');
    $qs = http_build_query($params);
    if ($qs !== '') {
        $target .= '?' . $qs;
    }
    header('Location: ' . $target);
    exit;
}

if (isset($_SESSION['pf_products_flash_success'])) {
    $success = (string)$_SESSION['pf_products_flash_success'];
    unset($_SESSION['pf_products_flash_success']);
}
if (isset($_SESSION['pf_products_flash_error'])) {
    $error = (string)$_SESSION['pf_products_flash_error'];
    unset($_SESSION['pf_products_flash_error']);
}

/**
 * Categories shown in add/edit product modal (General excluded; legacy General rows use JS legacy option).
 */
function printflow_product_modal_categories(): array {
    return ['T-Shirt', 'Stickers', 'Sintraboard', 'Signage', 'Merchandise'];
}

/**
 * Categories accepted on POST — modal list plus General for legacy/recover updates.
 */
function printflow_allowed_product_categories(): array {
    return array_merge(printflow_product_modal_categories(), ['General']);
}

function printflow_product_category_valid_for_create(string $category): bool {
    foreach (printflow_product_modal_categories() as $a) {
        if (strcasecmp(trim($category), $a) === 0) {
            return true;
        }
    }
    return false;
}

function printflow_product_category_is_allowed(string $category): bool {
    $category = trim($category);
    if ($category === '' || strcasecmp($category, 'system') === 0) {
        return false;
    }
    foreach (printflow_allowed_product_categories() as $a) {
        if (strcasecmp($category, $a) === 0) {
            return true;
        }
    }
    return false;
}

function printflow_canonical_product_category(string $category): ?string {
    foreach (printflow_allowed_product_categories() as $a) {
        if (strcasecmp(trim($category), $a) === 0) {
            return $a;
        }
    }
    return null;
}

/**
 * Handle product photo upload
 * @param array $file The $_FILES array element
 * @param int|null $product_id For updating existing photo
 * @return string|null Path to uploaded file or null
 */
function handle_product_photo_upload($file, $product_id = null) {
    if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null; // No file uploaded
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error: ' . $file['error']);
    }
    
    // Check file size (5MB max)
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception('File size must be less than 5MB');
    }
    
    // Check MIME type
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) {
        throw new Exception('Invalid file type. Only JPG, PNG, and GIF are allowed');
    }
    
    // Create uploads directory if it doesn't exist
    $upload_dir = __DIR__ . '/../uploads/products';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Generate unique filename
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'product_' . time() . '_' . uniqid() . '.' . $ext;
    $target_path = $upload_dir . '/' . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        throw new Exception('Failed to upload file to server');
    }
    
    // Use the actual $base_path variable value, not PHP code
    $base = defined('BASE_PATH') ? BASE_PATH : (defined('BASE_URL') ? BASE_URL : '/printflow');
    return $base . '/uploads/products/' . $filename;
}

function printflow_is_system_deleted_product(array $product): bool {
    $sku = strtolower(trim((string)($product['sku'] ?? '')));
    $name = strtolower(trim((string)($product['name'] ?? '')));

    if ($sku !== '' && strpos($sku, 'sys-deleted') !== false) {
        return true;
    }

    if ($name !== '' && (
        strpos($name, '[system] deleted product') !== false ||
        $name === 'deleted product'
    )) {
        return true;
    }

    return false;
}

function printflow_restore_is_valid_name(string $name): bool {
    $candidate = strtolower(trim($name));
    if ($candidate === '' || strlen($candidate) < 2) {
        return false;
    }

    $blocked = [
        '[system] deleted product',
        'deleted product',
        'product',
        'order item',
        'pos service item',
    ];

    return !in_array($candidate, $blocked, true);
}

function printflow_restore_is_valid_sku(string $sku): bool {
    $candidate = strtolower(trim($sku));
    if ($candidate === '') {
        return false;
    }
    if (strpos($candidate, 'sys-deleted') !== false) {
        return false;
    }
    if (strpos($candidate, 'pos-service') !== false || strpos($candidate, 'pos_service') !== false) {
        return false;
    }
    return true;
}

function printflow_restore_extract_name_from_note(string $notes): string {
    $text = trim($notes);
    if ($text === '') {
        return '';
    }

    $patterns = [
        '/\badd stock for\s+(.+?):\s*[-+]?\d+/i',
        '/\bstock update for\s+(.+?):\s*[-+]?\d+/i',
        '/\bbranch stock update for\s+(.+?):\s*[-+]?\d+/i',
        '/\border\s*#\d+\s*completed\s*-\s*(.+)$/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $candidate = trim((string)($m[1] ?? ''));
            $candidate = preg_replace('/\s+/', ' ', $candidate ?? '');
            if (printflow_restore_is_valid_name((string)$candidate)) {
                return (string)$candidate;
            }
        }
    }

    return '';
}

function printflow_restore_guess_name_from_sku(string $sku): string {
    $value = preg_replace('/[^A-Za-z0-9]+/', ' ', trim($sku));
    if ($value === null || $value === '') {
        return '';
    }
    $value = preg_replace('/\s+\d+\s*$/', '', $value);
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $value = ucwords(strtolower($value));
    return printflow_restore_is_valid_name($value) ? $value : '';
}

function printflow_restore_build_payload(int $productId): array {
    $payload = [
        'name' => '',
        'sku' => '',
        'category' => '',
        'price' => 0.0,
        'description' => '',
        'low_stock_level' => 10,
    ];

    $currentRows = db_query(
        "SELECT name, sku, category, price, description, low_stock_level
         FROM products
         WHERE product_id = ?
         LIMIT 1",
        'i',
        [$productId]
    ) ?: [];
    $current = $currentRows[0] ?? [];

    $existingName = trim((string)($current['name'] ?? ''));
    $existingSku = trim((string)($current['sku'] ?? ''));
    $existingCategory = trim((string)($current['category'] ?? ''));
    $existingDescription = trim((string)($current['description'] ?? ''));
    $existingPrice = (float)($current['price'] ?? 0);
    $existingLowStock = (int)($current['low_stock_level'] ?? 10);

    if (printflow_restore_is_valid_name($existingName)) {
        $payload['name'] = $existingName;
    }
    if (printflow_restore_is_valid_sku($existingSku)) {
        $payload['sku'] = $existingSku;
    }
    if ($existingCategory !== '' && strtolower($existingCategory) !== 'system') {
        $payload['category'] = $existingCategory;
    }
    if ($existingDescription !== '' && stripos($existingDescription, 'deleted') === false) {
        $payload['description'] = $existingDescription;
    }
    if ($existingPrice > 0) {
        $payload['price'] = $existingPrice;
    }
    if ($existingLowStock > 0) {
        $payload['low_stock_level'] = $existingLowStock;
    }

    $skuRows = db_query(
        "SELECT sku, unit_price
         FROM order_items
         WHERE product_id = ? AND sku IS NOT NULL AND sku <> ''
         ORDER BY order_item_id DESC
         LIMIT 100",
        'i',
        [$productId]
    ) ?: [];

    foreach ($skuRows as $row) {
        $candidateSku = trim((string)($row['sku'] ?? ''));
        if ($payload['sku'] === '' && printflow_restore_is_valid_sku($candidateSku)) {
            $payload['sku'] = $candidateSku;
        }
        if ($payload['price'] <= 0) {
            $candidatePrice = (float)($row['unit_price'] ?? 0);
            if ($candidatePrice > 0) {
                $payload['price'] = $candidatePrice;
            }
        }
        if ($payload['sku'] !== '' && $payload['price'] > 0) {
            break;
        }
    }

    $noteRows = db_query(
        "SELECT notes
         FROM inventory_transactions
         WHERE product_id = ? AND notes IS NOT NULL AND notes <> ''
         ORDER BY transaction_date DESC
         LIMIT 120",
        'i',
        [$productId]
    ) ?: [];

    foreach ($noteRows as $noteRow) {
        $candidateName = printflow_restore_extract_name_from_note((string)($noteRow['notes'] ?? ''));
        if ($candidateName !== '') {
            $payload['name'] = $candidateName;
            break;
        }
    }

    if ($payload['name'] === '' && $payload['sku'] !== '') {
        $payload['name'] = printflow_restore_guess_name_from_sku($payload['sku']);
    }

    if ($payload['category'] === '') {
        $skuUpper = strtoupper($payload['sku']);
        if (strpos($skuUpper, 'TARP') !== false) {
            $payload['category'] = 'Signage';
        } elseif (strpos($skuUpper, 'TSH') !== false || strpos($skuUpper, 'TSHIRT') !== false || strpos($skuUpper, 'SHIRT') !== false) {
            $payload['category'] = 'T-Shirt';
        } elseif (strpos($skuUpper, 'STICK') !== false) {
            $payload['category'] = 'Stickers';
        } elseif (strpos($skuUpper, 'SNB') !== false || strpos($skuUpper, 'SINTRA') !== false) {
            $payload['category'] = 'Sintraboard';
        } elseif (strpos($skuUpper, 'SGN') !== false || strpos($skuUpper, 'SIGN') !== false) {
            $payload['category'] = 'Signage';
        } elseif (strpos($skuUpper, 'MER') !== false) {
            $payload['category'] = 'Merchandise';
        } elseif (strpos($skuUpper, 'PRT') !== false || strpos($skuUpper, 'PRINT') !== false) {
            $payload['category'] = 'Merchandise';
        } else {
            $payload['category'] = 'General';
        }
    }

    if ($payload['description'] === '') {
        $payload['description'] = 'Restored from historical product records.';
    }

    return $payload;
}

// Handle product creation/update/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    if (isset($_POST['create_product'])) {
        if ($is_manager) {
            $error = 'Only administrators can add new products.';
        } else {
        $name = preg_replace('/\s+/', ' ', trim($_POST['name'] ?? ''));
        $sku = trim($_POST['sku'] ?? '');
        $category = sanitize($_POST['category'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $stock_quantity = (int)($_POST['stock_quantity'] ?? 0);
        $low_stock_level = (int)($_POST['low_stock_level'] ?? 10);
        if ($low_stock_level < 1) $low_stock_level = 10;
        $statusRaw = trim((string)($_POST['status'] ?? ''));
        $status = ($statusRaw === 'Deactivated') ? 'Deactivated' : 'Activated';

        // Server-side validation
        if (strlen($name) < 2) {
            $error = $name ? 'Product name must be at least 2 characters.' : 'Product name is required.';
        } elseif (strlen($name) > 100) {
            $error = 'Product name must not exceed 100 characters.';
        } elseif (preg_match('/^\d+$/', $name)) {
            $error = 'Product name cannot contain only numbers.';
        } elseif (strlen($description) > 500) {
            $error = 'Description must not exceed 500 characters.';
        } elseif ($price < 1.00 || $price > 1000000) {
            $error = $price <= 0 ? 'Price is required and must be greater than 0.' : 'Price must be between ₱1.00 and ₱1,000,000.00.';
        } elseif ($stock_quantity < 0) {
            $error = 'Quantity must be a non-negative whole number.';
        } elseif ($low_stock_level < 0) {
            $error = 'Low stock level must be a non-negative whole number.';
        } elseif ($low_stock_level > $stock_quantity) {
            $error = 'Low stock level cannot exceed quantity.';
        } elseif (empty($category) || $category === '-- Select Category --') {
            $error = 'Please select a category.';
        } elseif (!printflow_product_category_valid_for_create($category)) {
            $error = 'Invalid category.';
        } elseif (empty($_FILES['photo']['name']) || ($_FILES['photo']['error'] ?? 0) === UPLOAD_ERR_NO_FILE) {
            $error = 'Product photo is required.';
        } else {
            // Allow empty SKU - treat as NULL
            $sku_val = $sku !== '' ? trim($sku) : null;
            if ($sku_val !== null && $sku_val !== '') {
                // Check for duplicate SKU
                $exists = db_query("SELECT product_id FROM products WHERE sku = ?", 's', [$sku_val]);
                if (!empty($exists)) {
                    $error = "A product with SKU '" . htmlspecialchars($sku_val) . "' already exists.";
                }
            }

            if (!$error) {
                try {
                    $category = printflow_canonical_product_category($category);
                    // Handle photo upload
                    $photo_path = handle_product_photo_upload($_FILES['photo'] ?? null);
                    
                    $result = db_execute(
                        "INSERT INTO products (name, sku, category, description, price, stock_quantity, low_stock_level, status, photo_path, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                        'ssssdiiis',
                        [$name, $sku_val, $category, $description, $price, $product_stock_uses_base ? $stock_quantity : 0, $low_stock_level, $status, $photo_path]
                    );

                    if ($result) {
                        if (!$product_stock_uses_base) {
                            printflow_product_branch_stock_upsert((int)$result, $product_stock_branch_id, $stock_quantity, $low_stock_level);
                        }
                        if ($stock_quantity > 0) {
                            printflow_record_product_inventory_transaction(
                                (int)$result,
                                'IN',
                                (float)$stock_quantity,
                                'PRODUCT_CREATE',
                                (int)$result,
                                'Initial stock from Products Management creation',
                                (int)(get_user_id() ?? 0),
                                date('Y-m-d'),
                                $product_stock_branch_id
                            );
                        }
                        $success = "Product '$name' created successfully!";
                    } else {
                        global $conn;
                        $error = "Failed to create product. DB error: " . $conn->error;
                    }
                } catch (Exception $e) {
                    $error = "Upload error: " . $e->getMessage();
                }
            }
        }
        }
    } elseif (isset($_POST['add_product_stock'])) {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $add_stock_quantity = (int)($_POST['add_stock_quantity'] ?? 0);
        $low_stock_level = (int)($_POST['low_stock_level'] ?? 10);
        if ($low_stock_level < 1) {
            $low_stock_level = 10;
        }

        if ($product_id < 1) {
            $error = 'Invalid product.';
        } elseif ($add_stock_quantity <= 0) {
            $error = 'Add stock quantity must be greater than 0.';
        } elseif ($add_stock_quantity > 99999) {
            $error = 'Add stock quantity must not exceed 5 digits.';
        } elseif ($is_manager && $mgr_branch_id < 1) {
            $error = 'No branch is assigned to your account.';
        } else {
            $existingProduct = db_query(
                "SELECT product_id, name, stock_quantity FROM products WHERE product_id = ? AND status != 'Archived' LIMIT 1",
                'i',
                [$product_id]
            );

            if ($existingProduct === false) {
                $error = 'Database error while verifying product.';
            } elseif (count($existingProduct) === 0) {
                $error = 'Product not found.';
            } else {
                $productName = (string)($existingProduct[0]['name'] ?? ('Product #' . $product_id));
                $oldStockQuantity = $product_stock_uses_base
                    ? (int)($existingProduct[0]['stock_quantity'] ?? 0)
                    : (int)(printflow_product_effective_stock($product_id, $product_stock_branch_id)[0] ?? 0);
                $newStockQuantity = $oldStockQuantity + $add_stock_quantity;

                if ($low_stock_level > $newStockQuantity) {
                    $error = 'Low stock level cannot exceed the updated quantity.';
                } else {
                    $result = false;
                    if ($product_stock_uses_base) {
                        $result = db_execute(
                            "UPDATE products SET stock_quantity = ?, low_stock_level = ?, updated_at = NOW() WHERE product_id = ?",
                            'iii',
                            [$newStockQuantity, $low_stock_level, $product_id]
                        );
                    } else {
                        $result = printflow_product_branch_stock_upsert(
                            $product_id,
                            $product_stock_branch_id,
                            $newStockQuantity,
                            $low_stock_level
                        );
                    }

                    if ($result) {
                        printflow_record_product_inventory_transaction(
                            $product_id,
                            'IN',
                            (float)$add_stock_quantity,
                            'PRODUCT_ADJUSTMENT',
                            $product_id,
                            "Products Management add stock for {$productName}: {$oldStockQuantity} -> {$newStockQuantity}",
                            (int)(get_user_id() ?? 0),
                            date('Y-m-d'),
                            $product_stock_branch_id
                        );
                        $success = 'Stock added successfully.';
                    } else {
                        global $conn;
                        $dberr = isset($conn) ? $conn->error : '';
                        $error = 'Failed to add stock.' . ($dberr !== '' ? ' (' . htmlspecialchars($dberr, ENT_QUOTES, 'UTF-8') . ')' : '');
                    }
                }
            }
        }
    } elseif (isset($_POST['update_product'])) {
        if ($is_manager) {
            $product_id = (int)($_POST['product_id'] ?? 0);
            $stock_quantity = (int)($_POST['stock_quantity'] ?? 0);
            $low_stock_level = (int)($_POST['low_stock_level'] ?? 10);
            if ($low_stock_level < 1) {
                $low_stock_level = 10;
            }
            if ($product_id < 1) {
                $error = 'Invalid product.';
            } elseif ($mgr_branch_id < 1) {
                $error = 'No branch is assigned to your account.';
            } elseif ($stock_quantity < 0) {
                $error = 'Quantity must be a non-negative whole number.';
            } elseif ($low_stock_level > $stock_quantity) {
                $error = 'Low stock level cannot exceed quantity.';
            } else {
                $existingProduct = db_query("SELECT product_id, name FROM products WHERE product_id = ? AND status != 'Archived' LIMIT 1", 'i', [$product_id]);
                $existingStock = printflow_product_effective_stock($product_id, $mgr_branch_id);
                $oldStockQuantity = (int)($existingStock[0] ?? 0);
                if ($existingProduct === false) {
                    $error = 'Database error while verifying product.';
                } elseif (count($existingProduct) === 0) {
                    $error = 'Product not found.';
                } elseif (printflow_product_branch_stock_upsert($product_id, $mgr_branch_id, $stock_quantity, $low_stock_level)) {
                    $delta = $stock_quantity - $oldStockQuantity;
                    if ($delta !== 0) {
                        $direction = $delta > 0 ? 'IN' : 'OUT';
                        $qtyMoved = abs($delta);
                        $productName = (string)($existingProduct[0]['name'] ?? ('Product #' . $product_id));
                        printflow_record_product_inventory_transaction(
                            $product_id,
                            $direction,
                            (float)$qtyMoved,
                            'PRODUCT_ADJUSTMENT',
                            $product_id,
                            "Products Management branch stock update for {$productName}: {$oldStockQuantity} -> {$stock_quantity}",
                            (int)(get_user_id() ?? 0),
                            date('Y-m-d'),
                            $mgr_branch_id
                        );
                    }
                    $success = 'Stock for your branch was updated successfully.';
                } else {
                    global $conn;
                    $dberr = isset($conn) ? $conn->error : '';
                    $error = 'Failed to update branch stock.' . ($dberr !== '' ? ' (' . htmlspecialchars($dberr, ENT_QUOTES, 'UTF-8') . ')' : '');
                }
            }
        } else {
        $product_id = (int)$_POST['product_id'];
        $name = preg_replace('/\s+/', ' ', trim($_POST['name'] ?? ''));
        $sku = trim($_POST['sku'] ?? '');
        $category = sanitize($_POST['category'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $stock_quantity = (int)($_POST['stock_quantity'] ?? 0);
        $low_stock_level = (int)($_POST['low_stock_level'] ?? 10);
        $statusRaw = trim((string)($_POST['status'] ?? ''));
        $status = ($statusRaw === 'Deactivated') ? 'Deactivated' : 'Activated';

        $sku_val = $sku !== '' ? trim($sku) : null;

        // Server-side validation for update (photo optional)
        if (strlen($name) < 2) {
            $error = $name ? 'Product name must be at least 2 characters.' : 'Product name is required.';
        } elseif (strlen($name) > 100) {
            $error = 'Product name must not exceed 100 characters.';
        } elseif (preg_match('/^\d+$/', $name)) {
            $error = 'Product name cannot contain only numbers.';
        } elseif (strlen($description) > 500) {
            $error = 'Description must not exceed 500 characters.';
        } elseif ($price < 1.00 || $price > 1000000) {
            $error = $price <= 0 ? 'Price is required and must be greater than 0.' : 'Price must be between ₱1.00 and ₱1,000,000.00.';
        } elseif ($stock_quantity < 0 || $stock_quantity != floor($stock_quantity)) {
            $error = 'Quantity must be a non-negative whole number.';
        } elseif ($low_stock_level < 0 || $low_stock_level != floor($low_stock_level)) {
            $error = 'Low stock level must be a non-negative whole number.';
        } elseif ($low_stock_level > $stock_quantity) {
            $error = 'Low stock level cannot exceed quantity.';
        } elseif (empty($category) || $category === '-- Select Category --') {
            $error = 'Please select a category.';
        } elseif (!printflow_product_category_is_allowed($category)) {
            $error = 'Invalid category.';
        } else {
            if ($sku_val !== null && $sku_val !== '') {
                $exists = db_query("SELECT product_id FROM products WHERE sku = ? AND product_id != ?", 'si', [$sku_val, $product_id]);
                if (!empty($exists)) {
                    $error = "A product with SKU '" . htmlspecialchars($sku_val) . "' already exists.";
                }
            }
        }

        if (!$error) {
        try {
            $category = printflow_canonical_product_category($category);
            $existingProduct = db_query(
                "SELECT product_id, name, stock_quantity FROM products WHERE product_id = ? LIMIT 1",
                'i',
                [$product_id]
            );
            $oldProduct = $existingProduct[0] ?? null;
            $oldStockQuantity = $product_stock_uses_base
                ? (int)($oldProduct['stock_quantity'] ?? 0)
                : (int)(printflow_product_effective_stock($product_id, $product_stock_branch_id)[0] ?? 0);

            // Handle photo upload (only if a new file is provided)
            $photo_path = handle_product_photo_upload($_FILES['photo'] ?? null);
            
            if ($photo_path) {
                if ($product_stock_uses_base) {
                    $result = db_execute(
                        "UPDATE products SET name = ?, sku = ?, category = ?, description = ?, price = ?, stock_quantity = ?, low_stock_level = ?, status = ?, photo_path = ?, updated_at = NOW() WHERE product_id = ?",
                        'ssssdiiisi',
                        [$name, $sku_val, $category, $description, $price, $stock_quantity, $low_stock_level, $status, $photo_path, $product_id]
                    );
                } else {
                    $result = db_execute(
                        "UPDATE products SET name = ?, sku = ?, category = ?, description = ?, price = ?, status = ?, photo_path = ?, updated_at = NOW() WHERE product_id = ?",
                        'ssssdssi',
                        [$name, $sku_val, $category, $description, $price, $status, $photo_path, $product_id]
                    );
                }
            } else {
                if ($product_stock_uses_base) {
                    $result = db_execute(
                        "UPDATE products SET name = ?, sku = ?, category = ?, description = ?, price = ?, stock_quantity = ?, low_stock_level = ?, status = ?, updated_at = NOW() WHERE product_id = ?",
                        'ssssdiisi',
                        [$name, $sku_val, $category, $description, $price, $stock_quantity, $low_stock_level, $status, $product_id]
                    );
                } else {
                    $result = db_execute(
                        "UPDATE products SET name = ?, sku = ?, category = ?, description = ?, price = ?, status = ?, updated_at = NOW() WHERE product_id = ?",
                        'ssssdsi',
                        [$name, $sku_val, $category, $description, $price, $status, $product_id]
                    );
                }
            }

            if ($result) {
                if (!$product_stock_uses_base) {
                    printflow_product_branch_stock_upsert($product_id, $product_stock_branch_id, $stock_quantity, $low_stock_level);
                }
                if ($oldProduct) {
                    $delta = $stock_quantity - $oldStockQuantity;
                    if ($delta !== 0) {
                        $direction = $delta > 0 ? 'IN' : 'OUT';
                        $qtyMoved = abs($delta);
                        printflow_record_product_inventory_transaction(
                            $product_id,
                            $direction,
                            (float)$qtyMoved,
                            'PRODUCT_ADJUSTMENT',
                            $product_id,
                            "Products Management stock update for {$name}: {$oldStockQuantity} -> {$stock_quantity}",
                            (int)(get_user_id() ?? 0),
                            date('Y-m-d'),
                            $product_stock_branch_id
                        );
                    }
                }
                $success = "Product '$name' updated successfully!";
            } else {
                global $conn;
                $error = "Failed to update product. DB error: " . $conn->error;
            }
        } catch (Exception $e) {
            $error = "Upload error: " . $e->getMessage();
        }
        }
    }
} elseif (isset($_POST['archive_product'])) {
    if ($is_manager) {
        $error = 'Only administrators can archive products.';
    } else {
    $product_id = (int)$_POST['product_id'];
    db_execute("UPDATE products SET status = 'Archived', updated_at = NOW() WHERE product_id = ?", 'i', [$product_id]);
    $success = 'Product archived successfully!';
    }
} elseif (isset($_POST['restore_product'])) {
    if ($is_manager) {
        $error = 'Only administrators can restore products.';
    } else {
    $product_id = (int)$_POST['product_id'];
    db_execute("UPDATE products SET status = 'Activated', updated_at = NOW() WHERE product_id = ?", 'i', [$product_id]);
    $success = 'Product restored successfully!';
    }
} elseif (isset($_POST['recover_system_deleted_product'])) {
    if ($is_manager) {
        $error = 'Only administrators can recover deleted product placeholders.';
    } else {
    $product_id = (int)($_POST['product_id'] ?? 0);
    if ($product_id < 1) {
        $error = 'Invalid product.';
    } else {
        $productRows = db_query(
            "SELECT product_id, name, sku, category, description, price, low_stock_level
             FROM products
             WHERE product_id = ?
             LIMIT 1",
            'i',
            [$product_id]
        ) ?: [];

        if (empty($productRows)) {
            $error = 'Product not found.';
        } else {
            $currentProduct = $productRows[0];
            if (!printflow_is_system_deleted_product($currentProduct)) {
                $error = 'This product is not marked as a system-deleted placeholder.';
            } else {
                $payload = printflow_restore_build_payload($product_id);

                $targetName = trim((string)($payload['name'] ?? ''));
                $targetSku = trim((string)($payload['sku'] ?? ''));
                $targetCategory = trim((string)($payload['category'] ?? 'General'));
                $targetDescription = trim((string)($payload['description'] ?? 'Restored from historical product records.'));
                $targetPrice = (float)($payload['price'] ?? 0);
                $targetLowStock = (int)($payload['low_stock_level'] ?? 10);

                if (!printflow_restore_is_valid_name($targetName)) {
                    $targetName = 'Restored Product #' . $product_id;
                }
                if ($targetSku !== '' && !printflow_restore_is_valid_sku($targetSku)) {
                    $targetSku = '';
                }
                if ($targetSku !== '') {
                    $skuConflict = db_query(
                        "SELECT product_id FROM products WHERE sku = ? AND product_id != ? LIMIT 1",
                        'si',
                        [$targetSku, $product_id]
                    ) ?: [];
                    if (!empty($skuConflict)) {
                        $targetSku = 'RESTORED-' . $product_id;
                    }
                }
                if ($targetCategory === '' || strtolower($targetCategory) === 'system') {
                    $targetCategory = 'General';
                }
                if (!printflow_product_category_is_allowed($targetCategory)) {
                    $targetCategory = 'General';
                }
                $targetCategory = printflow_canonical_product_category($targetCategory) ?? 'General';
                if ($targetPrice < 0) {
                    $targetPrice = 0;
                }
                if ($targetLowStock < 1) {
                    $targetLowStock = 10;
                }

                $updated = db_execute(
                    "UPDATE products
                     SET name = ?, sku = ?, category = ?, description = ?, price = ?, low_stock_level = ?, status = 'Activated', updated_at = NOW()
                     WHERE product_id = ?",
                    'ssssdii',
                    [$targetName, ($targetSku !== '' ? $targetSku : null), $targetCategory, $targetDescription, $targetPrice, $targetLowStock, $product_id]
                );

                if ($updated) {
                    $success = "Product #{$product_id} was restored. Please review details and update if needed.";
                } else {
                    global $conn;
                    $error = 'Failed to recover product placeholder.' . (!empty($conn->error) ? ' (' . $conn->error . ')' : '');
                }
            }
        }
    }
    }
} elseif (isset($_POST['delete_product'])) {
    if ($is_manager) {
        $error = 'Only administrators can change product status or delete products.';
    } else {
    $product_id = (int)$_POST['product_id'];
    $current = db_query("SELECT status FROM products WHERE product_id = ?", 'i', [$product_id]);
    $status = $current[0]['status'] ?? '';
    
    if ($status === 'Archived') {
        $error = 'Archived products cannot be permanently deleted.';
    } else {
        $new_status = ($status === 'Activated') ? 'Deactivated' : 'Activated';
        db_execute("UPDATE products SET status = ?, updated_at = NOW() WHERE product_id = ?", 'si', [$new_status, $product_id]);
        $success = 'Product ' . strtolower($new_status) . ' successfully!';
    }
    }
}
}

// Handle AJAX for Archive Storage Modal
if (isset($_GET['get_archived'])) {
    header('Content-Type: application/json');
    if ($is_manager) {
        echo json_encode(['success' => false, 'html' => '<p style="padding:24px;text-align:center;color:#9ca3af;">Archived items are only available to administrators.</p>']);
        exit;
    }
    $archived = db_query("SELECT * FROM products WHERE status = 'Archived' ORDER BY updated_at DESC");
    
    $html = '<table class="orders-table" style="width:100%;">';
    $html .= '<thead><tr><th>SKU</th><th>Name</th><th>Category</th><th style="text-align:right;">Actions</th></tr></thead>';
    $html .= '<tbody>';
    
    if (empty($archived)) {
        $html .= '<tr><td colspan="4" style="padding:40px;text-align:center;color:#9ca3af;">No archived products found.</td></tr>';
    } else {
        foreach ($archived as $p) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($p['sku'] ?? '—') . '</td>';
            $html .= '<td style="font-weight:500; max-width:300px; word-break: break-word; overflow-wrap: anywhere;">' . htmlspecialchars($p['name']) . '</td>';
            $html .= '<td>' . htmlspecialchars($p['category'] ?? '—') . '</td>';
            $html .= '<td style="text-align:right;white-space:nowrap;">';
            $html .= '<form method="POST" class="inline product-status-form" data-pf-skip-guard style="display:inline-block;margin-right:4px;" data-action="Restore" data-product-name="' . htmlspecialchars($p['name'], ENT_QUOTES) . '" onsubmit="showProductStatusModal(event, this);return false;">';
            $html .= csrf_field();
            $html .= '<input type="hidden" name="product_id" value="' . $p['product_id'] . '">';
            $html .= '<button type="submit" name="restore_product" class="btn-action teal">Restore</button></form>';
            $html .= '</td></tr>';
        }
    }
    $html .= '</tbody></table>';
    
    echo json_encode(['success' => true, 'html' => $html]);
    exit;
}

// Handle AJAX for next SKU generation
if (isset($_GET['get_next_sku'])) {
    header('Content-Type: application/json');
    $prefix = trim($_GET['get_next_sku'] ?? '');
    
    if (empty($prefix)) {
        echo json_encode(['success' => false, 'error' => 'Invalid prefix']);
        exit;
    }
    
    // Find the highest number for this prefix (with hyphen format: TARP-0003)
    $result = db_query(
        "SELECT sku FROM products WHERE sku LIKE ? ORDER BY sku DESC LIMIT 1",
        's',
        [$prefix . '-%']
    );
    
    $nextNumber = 1;
    if (!empty($result) && isset($result[0]['sku'])) {
        $lastSku = $result[0]['sku'];
        // Extract number from SKU (e.g., TARP-0003 -> 3)
        $numberPart = preg_replace('/^[A-Z]+-/', '', $lastSku);
        if (is_numeric($numberPart)) {
            $nextNumber = intval($numberPart) + 1;
        }
    }
    
    // Format with hyphen and leading zeros (4 digits): TARP-0004
    $newSku = $prefix . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    
    echo json_encode(['success' => true, 'sku' => $newSku]);
    exit;
}

// Get all products
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 10;
$search        = trim($_GET['search'] ?? '');
$cat_filter    = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';
$stock_filter  = $_GET['stock_status'] ?? '';
$sort_by       = $_GET['sort'] ?? 'newest';
$date_from     = $_GET['date_from'] ?? '';
$date_to       = $_GET['date_to'] ?? '';

$product_filter_category_map = [];
$categories_raw_pf = db_query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND TRIM(category) != '' AND status != 'Archived' ORDER BY category ASC") ?: [];
foreach ($categories_raw_pf as $crow) {
    $c = trim((string)($crow['category'] ?? ''));
    if ($c === '' || strcasecmp($c, 'system') === 0) {
        continue;
    }
    $lk = strtolower($c);
    if (!isset($product_filter_category_map[$lk])) {
        $product_filter_category_map[$lk] = $c;
    }
}
if ($cat_filter !== '') {
    $lkf = strtolower($cat_filter);
    if (!isset($product_filter_category_map[$lkf])) {
        $cat_filter = '';
    } else {
        $cat_filter = $product_filter_category_map[$lkf];
    }
}

$branchJoin = '';
$stockExpr  = 'p.stock_quantity';
$lowExpr    = 'COALESCE(p.low_stock_level, 10)';
$sqlParams  = [];
$sqlTypes   = '';

if ($product_stock_branch_id > 0 && !$product_stock_uses_base) {
    $branchJoin = ' LEFT JOIN product_branch_stock pbs ON pbs.product_id = p.product_id AND pbs.branch_id = ? ';
    $stockExpr  = 'COALESCE(pbs.stock_quantity, 0)';
    $lowExpr    = 'COALESCE(pbs.low_stock_level, p.low_stock_level, 10)';
    $sqlParams[] = $product_stock_branch_id;
    $sqlTypes   .= 'i';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($success !== '') {
        printflow_products_page_redirect_with_flash($success, 'success');
    }
    if ($error !== '') {
        printflow_products_page_redirect_with_flash($error, 'error');
    }
}

$sql    = "SELECT p.*, {$stockExpr} AS eff_stock_qty, {$lowExpr} AS eff_low_stock FROM products p {$branchJoin} WHERE p.status != 'Archived'";
$params = $sqlParams;
$types  = $sqlTypes;

if ($search) {
    $like = '%'.$search.'%';
    $sql .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
    $params = array_merge($params, [$like, $like]);
    $types .= 'ss';
}
if ($cat_filter) {
    $sql .= " AND p.category = ?";
    $params[] = $cat_filter;
    $types .= 's';
}
if ($status_filter) {
    $sql .= " AND p.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if ($stock_filter === 'out_of_stock') {
    $sql .= " AND ({$stockExpr}) <= 0";
} elseif ($stock_filter === 'low_stock') {
    $sql .= " AND ({$stockExpr}) > 0 AND ({$stockExpr}) <= ({$lowExpr})";
} elseif ($stock_filter === 'in_stock') {
    $sql .= " AND ({$stockExpr}) > ({$lowExpr})";
}
if (!empty($date_from)) {
    $sql .= " AND DATE(p.created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}
if (!empty($date_to)) {
    $sql .= " AND DATE(p.created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$count_sql = preg_replace('/SELECT p\.\*.*FROM/s', 'SELECT COUNT(*) as total FROM', $sql);
$total_products = db_query($count_sql, $types ?: null, $params ?: null)[0]['total'] ?? 0;
$total_pages = max(1, ceil($total_products / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$order_clause = match($sort_by) {
    'oldest' => "p.created_at ASC",
    'az'     => "p.name ASC",
    'za'     => "p.name DESC",
    default  => "p.created_at DESC"
};
$sql .= " ORDER BY $order_clause LIMIT $per_page OFFSET $offset";
$products = db_query($sql, $types ?: null, $params ?: null) ?: [];

foreach ($products as &$pfProduct) {
    if ($product_stock_branch_id > 0 && !$product_stock_uses_base) {
        $pfProduct['stock_quantity'] = (int)($pfProduct['eff_stock_qty'] ?? $pfProduct['stock_quantity']);
        $pfProduct['low_stock_level'] = (int)($pfProduct['eff_low_stock'] ?? $pfProduct['low_stock_level'] ?? 10);
    }
    unset($pfProduct['eff_stock_qty'], $pfProduct['eff_low_stock']);
}
unset($pfProduct);

$page_title = 'Products Management - Admin';

// Summary stats
$stat_total     = db_query("SELECT COUNT(*) as c FROM products WHERE status != 'Archived'")[0]['c'] ?? 0;
$stat_active    = db_query("SELECT COUNT(*) as c FROM products WHERE status='Activated'")[0]['c'] ?? 0;
$stat_inactive  = db_query("SELECT COUNT(*) as c FROM products WHERE status='Deactivated'")[0]['c'] ?? 0;
if ($product_stock_branch_id > 0 && !$product_stock_uses_base) {
    $stat_low_stock = db_query(
        "SELECT COUNT(*) as c FROM products p
         LEFT JOIN product_branch_stock pbs ON pbs.product_id = p.product_id AND pbs.branch_id = ?
         WHERE p.status != 'Archived'
         AND COALESCE(pbs.stock_quantity, 0) <= COALESCE(pbs.low_stock_level, p.low_stock_level, 10)",
        'i',
        [$product_stock_branch_id]
    )[0]['c'] ?? 0;
} else {
    $stat_low_stock = db_query("SELECT COUNT(*) as c FROM products WHERE status != 'Archived' AND stock_quantity <= COALESCE(low_stock_level, 10)")[0]['c'] ?? 0;
}

// Filter dropdown: categories that exist on at least one non-archived product
$categories = [];
foreach ($product_filter_category_map as $c) {
    $categories[] = ['category' => $c];
}
usort($categories, static function ($a, $b) {
    return strcasecmp($a['category'], $b['category']);
});

// AJAX response
if (isset($_GET['ajax'])) {
    ob_start();
    ?>
    <table class="orders-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>SKU</th>
                <th>Name</th>
                <th>Category</th>
                <th>Price</th>
                <th><?php echo $is_manager ? 'Branch qty' : 'Quantity'; ?></th>
                <th>Stock Status</th>
                <th>Status</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody id="productsTableBody">
            <?php if (empty($products)): ?>
                <tr><td colspan="9" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">No products found.</td></tr>
            <?php else: ?>
                <?php foreach ($products as $product): ?>
                    <?php
                    $low = (int)($product['low_stock_level'] ?? 10);
                    $stockStatus = get_stock_status($product['stock_quantity'], $low);
                    $isLowOrOut = in_array($stockStatus, ['Low Stock','Out of Stock']);
                    $isSystemDeletedProduct = printflow_is_system_deleted_product($product);
                    $stockBadge = match($stockStatus) {
                        'In Stock' => 'background:#dcfce7;color:#166534;',
                        'Low Stock' => 'background:#fef9c3;color:#854d0e;',
                        'Out of Stock' => 'background:#fee2e2;color:#991b1b;',
                        default => 'background:#f3f4f6;color:#374151;'
                    };
                    ?>
                    <tr class="<?php echo $isLowOrOut ? 'low-stock-row' : ''; ?>" onclick="openViewModal(<?php echo htmlspecialchars(json_encode($product), ENT_QUOTES); ?>)">
                        <td style="color:#1f2937;"><?php echo $product['product_id']; ?></td>
                        <td><?php echo htmlspecialchars($product['sku'] ?? '—'); ?></td>
                        <td style="font-weight:500;color:#1f2937;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($product['name']); ?></td>
                        <td><?php echo htmlspecialchars($product['category'] ?? '—'); ?></td>
                        <td style="font-weight:600;color:#1f2937;white-space:nowrap;">₱<?php echo number_format($product['price'], 2); ?></td>
                        <td>
                            <span style="font-weight:<?php echo $isLowOrOut ? 'bold' : '400'; ?>;color:<?php echo $stockStatus === 'Out of Stock' ? '#dc2626' : ($stockStatus === 'Low Stock' ? '#b45309' : '#374151'); ?>;"><?php echo $product['stock_quantity']; ?></span>
                        </td>
                        <td>
                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;<?php echo $stockBadge; ?>"><?php echo $stockStatus; ?></span>
                        </td>
                        <td>
                            <?php $sc = match($product['status']) { 'Activated' => 'background:#dcfce7;color:#166534;', 'Deactivated' => 'background:#fee2e2;color:#991b1b;', default => 'background:#fef9c3;color:#854d0e;' }; ?>
                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;<?php echo $sc; ?>"><?php echo $product['status']; ?></span>
                        </td>
                        <td style="text-align:right;white-space:nowrap;" onclick="event.stopPropagation();">
                            <button class="btn-action blue" onclick='openProductModal("edit", <?php echo htmlspecialchars(json_encode($product), ENT_QUOTES); ?>)'><?php echo $is_manager ? 'Stock' : 'Edit'; ?></button>
                            <?php if (!$is_manager && $isSystemDeletedProduct): ?>
                                <form method="POST" class="inline product-status-form" data-pf-skip-guard data-action="Auto Recover" data-product-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>" onsubmit="showProductStatusModal(event, this);return false;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                    <button type="submit" name="recover_system_deleted_product" class="btn-action teal">Auto Recover</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!$is_manager): ?>
                            <?php if ($product['status'] !== 'Archived'): ?>
                                <form method="POST" class="inline product-status-form" data-pf-skip-guard data-action="<?php echo $product['status'] === 'Activated' ? 'Deactivate' : 'Activate'; ?>" data-product-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>" onsubmit="showProductStatusModal(event, this);return false;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                    <button type="submit" name="delete_product" class="btn-action <?php echo $product['status'] === 'Activated' ? 'red' : 'teal'; ?>">
                                        <?php echo $product['status'] === 'Activated' ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                                <?php if ($product['status'] === 'Deactivated'): ?>
                                    <form method="POST" class="inline product-status-form" data-pf-skip-guard data-action="Archive" data-product-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>" onsubmit="showProductStatusModal(event, this);return false;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                        <button type="submit" name="archive_product" class="btn-action gray">Archive</button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <form method="POST" class="inline product-status-form" data-pf-skip-guard data-action="Restore" data-product-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>" onsubmit="showProductStatusModal(event, this);return false;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                    <button type="submit" name="restore_product" class="btn-action teal">Restore</button>
                                </form>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
    $table_html = ob_get_clean();
    ob_start();
    $pp = array_filter(['search'=>$search,'category'=>$cat_filter,'status'=>$status_filter,'stock_status'=>$stock_filter,'sort'=>$sort_by,'date_from'=>$date_from,'date_to'=>$date_to], function($v) { return $v !== null && $v !== ''; });
    echo render_pagination($page, $total_pages, $pp);
    $pagination_html = ob_get_clean();
    echo json_encode([
        'success'    => true,
        'table'      => $table_html,
        'pagination' => $pagination_html,
        'count'      => number_format($total_products),
        'badge'      => count(array_filter([$search,$cat_filter,$status_filter,$stock_filter,$date_from,$date_to], function($v) { return $v !== null && $v !== ''; }))
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo $base_path; ?>/public/assets/css/output.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 12px;
            min-width: 80px;
            border: 1px solid transparent;
            background: transparent;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
        }
        .btn-action.teal { color: #14b8a6; border-color: #14b8a6; }
        .btn-action.teal:hover { background: #14b8a6; color: white; }
        .btn-action.blue { color: #3b82f6; border-color: #3b82f6; }
        .btn-action.blue:hover { background: #3b82f6; color: white; }
        .btn-action.red { color: #ef4444; border-color: #ef4444; }
        .btn-action.red:hover { background: #ef4444; color: white; }
        .btn-action.gray { color: #6b7280; border-color: #d1d5db; }
        .btn-action.gray:hover { background: #6b7280; color: white; }

        /* KPI Row */
        .kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px; }
        @media(max-width:900px) { .kpi-row { grid-template-columns:repeat(2,1fr); } }
        .kpi-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; position:relative; overflow:hidden; }
        .kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
        .kpi-card.indigo::before { background:linear-gradient(90deg,#6366f1,#818cf8); }
        .kpi-card.emerald::before { background:linear-gradient(90deg,#059669,#34d399); }
        .kpi-card.rose::before { background:linear-gradient(90deg,#e11d48,#fb7185); }
        .kpi-card.amber::before { background:linear-gradient(90deg,#f59e0b,#fbbf24); }
        .kpi-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#9ca3af; margin-bottom:6px; }
        .kpi-sub { font-size:12px; color:#6b7280; margin-top:4px; }



        [x-cloak] { display: none !important; }

        /* ── Toolbar Buttons (Sort / Filter) ─── */
        .toolbar-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border: 1px solid #e5e7eb;
            background: #fff;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .toolbar-btn:hover { border-color: #9ca3af; background: #f9fafb; }
        .toolbar-btn.active { border-color: #0d9488; color: #0d9488; background: #f0fdfa; }
        .toolbar-btn svg { flex-shrink: 0; }

        /* Sort Dropdown */
        .sort-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            width: 180px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            z-index: 200;
            padding: 6px;
        }
        .sort-option {
            padding: 9px 12px;
            font-size: 13px;
            color: #4b5563;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .sort-option:hover { background: #f9fafb; color: #111827; }
        .sort-option.selected { background: #f0fdfa; color: #0d9488; font-weight: 600; }
        .sort-option svg.check { color: #0d9488; }

        /* Filter Panel */
        .filter-panel {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            width: 320px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            z-index: 200;
            overflow: hidden;
        }
        .filter-panel-header {
            padding: 14px 18px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
            font-weight: 700;
            color: #111827;
        }
        .filter-section {
            padding: 14px 18px;
            border-bottom: 1px solid #f3f4f6;
        }
        .filter-section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .filter-section-label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
        }
        .filter-reset-link {
            font-size: 12px;
            font-weight: 600;
            color: #0d9488;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
        }
        .filter-reset-link:hover { text-decoration: underline; }
        .filter-input {
            width: 100%;
            height: 34px;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            font-size: 13px;
            padding: 0 10px;
            color: #1f2937;
            box-sizing: border-box;
            transition: border-color 0.15s;
        }
        .filter-input:focus { outline: none; border-color: #0d9488; }
        .filter-date-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .filter-date-label { font-size: 11px; color: #6b7280; margin-bottom: 4px; }
        .filter-select {
            width: 100%;
            height: 34px;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            font-size: 13px;
            padding: 0 10px;
            color: #1f2937;
            background: #fff;
            box-sizing: border-box;
            cursor: pointer;
        }
        .filter-select:focus { outline: none; border-color: #0d9488; }
        .filter-search-wrap { position: relative; }
        .filter-search-input {
            width: 100%;
            height: 34px;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            font-size: 13px;
            padding: 0 12px;
            color: #1f2937;
            box-sizing: border-box;
        }
        .filter-search-input:focus { outline: none; border-color: #0d9488; }
        .filter-actions {
            display: flex;
            gap: 8px;
            padding: 14px 18px;
            border-top: 1px solid #f3f4f6;
        }
        .filter-btn-reset {
            flex: 1;
            height: 36px;
            border: 1px solid #e5e7eb;
            background: #fff;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
        }
        .filter-btn-reset:hover { background: #f9fafb; }
        .filter-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            background: #0d9488;
            color: #fff;
            border-radius: 50%;
            font-size: 10px;
            font-weight: 700;
        }
        #product-modal-overlay,
        #view-product-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        #product-modal-overlay.active,
        #view-product-modal-overlay.active {
            display: flex;
        }
        #product-modal { max-width: 580px; }
        #view-product-modal { max-width: 800px; }
        #product-modal,
        #view-product-modal {
            background: white;
            border-radius: 12px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.25);
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
        }
        #product-modal .modal-header,
        #view-product-modal .modal-header {
            padding: 18px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        #product-modal .modal-body,
        #view-product-modal .modal-body {
            padding: 18px 20px 20px;
        }
        #product-modal .form-row,
        #view-product-modal .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 12px;
        }
        #product-modal .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 14px;
            margin-bottom: 12px;
        }
        #product-modal .form-group,
        #view-product-modal .form-group {
            margin-bottom: 12px;
        }
        #product-modal .form-group:last-child,
        #view-product-modal .form-group:last-child { margin-bottom: 0; }
        #product-modal .form-row .form-group,
        #product-modal .form-row-3 .form-group,
        #view-product-modal .form-row .form-group { margin-bottom: 0; }
        #product-modal .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 4px;
            color: #374151;
        }
        #product-modal .form-group input,
        #product-modal .form-group select,
        #product-modal .form-group textarea {
            width: 100%;
            padding: 8px 11px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
            transition: border-color 0.2s;
        }
        #product-modal .form-group input:focus,
        #product-modal .form-group select:focus,
        #product-modal .form-group textarea:focus {
            outline: none;
            border-color: #0d9488;
            box-shadow: 0 0 0 2px rgba(13,148,136,0.15);
        }
        #product-modal .form-group small {
            display: block;
            color: #6b7280;
            font-size: 11px;
            margin-top: 3px;
            line-height: 1.3;
        }
        #product-modal .form-group.has-error input,
        #product-modal .form-group.has-error select,
        #product-modal .form-group.has-error textarea { border-color: #ef4444 !important; }
        #product-modal .form-group.has-success input,
        #product-modal .form-group.has-success select,
        #product-modal .form-group.has-success textarea { border-color: #22c55e !important; }
        #product-modal .field-error { display: block; font-size: 12px; color: #ef4444; margin-top: 4px; min-height: 18px; }
        #product-modal .form-group.has-error .field-error { display: block; }
        /* Validation states */
        #product-modal .form-group.has-error input,
        #product-modal .form-group.has-error select,
        #product-modal .form-group.has-error textarea { border-color: #ef4444; }
        #product-modal .form-group.has-success input,
        #product-modal .form-group.has-success select,
        #product-modal .form-group.has-success textarea { border-color: #22c55e; }
        #product-modal .field-error { display: block; color: #ef4444; font-size: 12px; margin-top: 4px; }
        #product-modal .field-success { display: block; color: #22c55e; font-size: 12px; margin-top: 4px; }
        /* Form validation states */
        #product-modal .form-group.has-error input,
        #product-modal .form-group.has-error select,
        #product-modal .form-group.has-error textarea { border-color: #ef4444 !important; }
        #product-modal .form-group.has-success input,
        #product-modal .form-group.has-success select,
        #product-modal .form-group.has-success textarea { border-color: #22c55e !important; }
        #product-modal .field-error { display: block; font-size: 12px; color: #dc2626; margin-top: 4px; }
        #product-modal .field-success { display: inline-block; font-size: 12px; color: #16a34a; margin-left: 6px; }
        #product-modal .btn-save:disabled { opacity: 0.6; cursor: not-allowed; }
        /* Validation states */
        #product-modal .form-group.has-error input,
        #product-modal .form-group.has-error select,
        #product-modal .form-group.has-error textarea { border-color: #ef4444 !important; }
        #product-modal .form-group.has-success input,
        #product-modal .form-group.has-success select,
        #product-modal .form-group.has-success textarea { border-color: #10b981 !important; }
        #product-modal .field-error { display: block; color: #ef4444; font-size: 12px; margin-top: 4px; }
        #product-modal .field-success { display: block; color: #10b981; font-size: 11px; margin-top: 4px; }
        #product-modal .field-error { display:block; font-size:12px; color:#dc2626; margin-top:4px; }
        #product-modal .field-success { display:block; font-size:12px; color:#16a34a; margin-top:4px; }
        #product-modal .form-group.has-error input,
        #product-modal .form-group.has-error select,
        #product-modal .form-group.has-error textarea { border-color:#dc2626; }
        #product-modal .form-group.has-success input,
        #product-modal .form-group.has-success select { border-color:#16a34a; }
        #product-modal .form-group.has-error input,
        #product-modal .form-group.has-error select,
        #product-modal .form-group.has-error textarea {
            border-color: #ef4444;
            box-shadow: 0 0 0 2px rgba(239,68,68,0.15);
        }
        #product-modal .form-group.has-success input,
        #product-modal .form-group.has-success select,
        #product-modal .form-group.has-success textarea {
            border-color: #10b981;
        }
        #product-modal .field-error {
            display: block;
            color: #dc2626;
            font-size: 12px;
            margin-top: 4px;
            line-height: 1.3;
        }
        #product-modal .field-success {
            color: #059669;
            font-size: 11px;
            margin-top: 2px;
        }
        /* Validation states */
        #product-modal .form-group.has-error input,
        #product-modal .form-group.has-error select,
        #product-modal .form-group.has-error textarea { border-color: #ef4444; }
        #product-modal .form-group.has-success input,
        #product-modal .form-group.has-success select,
        #product-modal .form-group.has-success textarea { border-color: #22c55e; }
        #product-modal .field-error { display: block; font-size: 12px; color: #dc2626; margin-top: 4px; }
        #product-modal .field-success { display: block; font-size: 11px; color: #16a34a; margin-top: 4px; }
        /* Validation states */
        #product-modal .form-group.has-error input,
        #product-modal .form-group.has-error select,
        #product-modal .form-group.has-error textarea {
            border-color: #ef4444 !important;
            box-shadow: 0 0 0 2px rgba(239,68,68,0.15);
        }
        #product-modal .form-group.has-success input,
        #product-modal .form-group.has-success select,
        #product-modal .form-group.has-success textarea {
            border-color: #22c55e !important;
        }
        #product-modal .field-error {
            display: block;
            color: #dc2626;
            font-size: 12px;
            margin-top: 4px;
        }
        #product-modal .field-success {
            color: #16a34a;
            font-size: 12px;
            margin-top: 4px;
        }
        /* Validation states */
        #product-modal .form-group.has-error input,
        #product-modal .form-group.has-error select,
        #product-modal .form-group.has-error textarea { border-color: #ef4444 !important; }
        #product-modal .form-group.has-success input,
        #product-modal .form-group.has-success select,
        #product-modal .form-group.has-success textarea { border-color: #22c55e !important; }
        .field-error { display: block; font-size: 12px; color: #ef4444; margin-top: 4px; }
        .field-success { display: block; font-size: 11px; color: #22c55e; margin-top: 4px; }
        /* Validation states */
        #product-modal .form-group.has-error input,
        #product-modal .form-group.has-error select,
        #product-modal .form-group.has-error textarea { border-color: #ef4444 !important; box-shadow: 0 0 0 2px rgba(239,68,68,0.15); }
        #product-modal .form-group.has-success input,
        #product-modal .form-group.has-success select,
        #product-modal .form-group.has-success textarea { border-color: #10b981 !important; }
        #product-modal .field-error { display: block; color: #dc2626; font-size: 12px; margin-top: 4px; }
        #product-modal .field-success { display: block; color: #059669; font-size: 11px; margin-top: 4px; }
        #product-modal .btn-save:disabled { opacity: 0.6; cursor: not-allowed; }
        #product-modal .modal-footer,
        #view-product-modal .modal-footer {
            display: flex;
            gap: 10px;
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid #e5e7eb;
        }
        #product-modal .modal-footer button,
        #view-product-modal .modal-footer button {
            flex: 1;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        #product-modal .btn-cancel,
        #view-product-modal .btn-cancel {
            background: #f3f4f6;
            color: #374151;
        }
        #product-modal .btn-cancel:hover,
        #view-product-modal .btn-cancel:hover { background: #e5e7eb; color: #111827; }
        #product-modal .btn-save {
            background: #0d9488;
            color: white;
        }
        #product-modal .btn-save:hover { background: #0f766e; }
        #product-modal .btn-save:hover { background: #374151; }
        #close-modal-btn,
        #close-view-modal-btn {
            background: transparent;
            border: none;
            cursor: pointer;
            color: #6b7280;
            padding: 4px;
            line-height: 1;
        }
        #close-modal-btn:hover,
        #close-view-modal-btn:hover { color: #374151; }

        /* View Modal Specifics */
        .view-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
        }
        .view-value-box {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 10px 14px;
            color: #374151;
            font-size: 13px;
            min-height: 38px;
            display: block;
            word-break: break-all;
            overflow-wrap: break-word;
            box-sizing: border-box;
        }
        @media (max-width: 600px) {
            #product-modal .form-row { grid-template-columns: 1fr; }
            #product-modal .form-row-3 { grid-template-columns: 1fr; }
        }

        /* Orders-style table */
        .orders-table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: auto; }
        .orders-table th { padding: 12px 16px; font-size: 13px; font-weight: 600; color: #6b7280; text-align: left; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
        .orders-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; color: #374151; }
        .orders-table tbody tr { cursor: pointer; transition: background 0.1s; }
        .orders-table tbody tr:hover { background: #f9fafb; }
        .orders-table tbody tr:last-child td { border-bottom: none; }

        /* Low stock row highlight */
        .low-stock-row td { background-color: #fff5f5 !important; color: #374151 !important; }
        .low-stock-row:hover td { background-color: #fee2e2 !important; }

        /* Add Product blue text */
        .toolbar-btn.btn-add-product { color: #3b82f6; border-color: #3b82f6; }
        .toolbar-btn.btn-add-product:hover { background: #3b82f6; color: #fff; border-color: #3b82f6; }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . ($current_user['role'] === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>

    <!-- Main Content -->
    <div class="main-content">
        <header>
            <h1 class="page-title">Products Management</h1>
        </header>

        <script>
            // ── Alpine.js Data Components ───────────────────────────
            function filterPanel() {
                return {
                    sortOpen: false,
                    filterOpen: false,
                    activeSort: '<?php echo $sort_by; ?>',
                    hasActiveFilters: <?php echo count(array_filter([$search,$cat_filter,$status_filter,$stock_filter,$date_from,$date_to], function($v) { return $v !== null && $v !== ''; })) > 0 ? 'true' : 'false'; ?>,
                };
            }

            function productsPage() {
                return {
                    ...filterPanel(),
                    init() {
                        // Global event listeners (idempotent init handled by printflowInitProductsPage)
                    }
                };
            }
            window.filterPanel = filterPanel;
            window.productsPage = productsPage;

            // ── Filter & Sort JS ─────────────────────────────────────────
            /* var: Turbo re-runs this script; let would conflict across visits. */
            var activeSort = '<?php echo $sort_by; ?>';
            var searchDebounceTimer = null;

            function buildFilterURL(page = 1) {
                const params = new URLSearchParams();
                params.set('page', page);
                const df = document.getElementById('fp_date_from')?.value; if (df) params.set('date_from', df);
                const dt = document.getElementById('fp_date_to')?.value;   if (dt) params.set('date_to', dt);
                const cat = document.getElementById('fp_category')?.value; if (cat) params.set('category', cat);
                const st = document.getElementById('fp_status')?.value;   if (st) params.set('status', st);
                const stock = document.getElementById('fp_stock_status')?.value; if (stock) params.set('stock_status', stock);
                const s = document.getElementById('fp_search')?.value;     if (s) params.set('search', s);
                if (activeSort !== 'newest') params.set('sort', activeSort);
                return '?' + params.toString();
            }

            async function fetchUpdatedTable(page = 1) {
                const url = buildFilterURL(page) + '&ajax=1';
                try {
                    const r = await fetch(url);
                    const data = await r.json();
                    if (data.success) {
                        const wrap = document.getElementById('productsTableContainer');
                        if (wrap) {
                            wrap.innerHTML = data.table + '<div id="productsPagination">' + data.pagination + '</div>';
                            if (typeof Alpine !== 'undefined' && typeof Alpine.initTree === 'function') {
                                try { Alpine.initTree(wrap); } catch (e) { console.error(e); }
                            }
                        }
                        const cont = document.getElementById('filterBadgeContainer');
                        if (cont) cont.innerHTML = data.badge > 0 ? '<span class="filter-badge">' + data.badge + '</span>' : '';
                        
                        // Update hasActiveFilters in Alpine
                        const root = document.querySelector('[x-data*="productsPage"]');
                        if (root && root._x_dataStack) root._x_dataStack[0].hasActiveFilters = (data.badge > 0);

                        history.replaceState(null, '', buildFilterURL(page));
                    }
                } catch (e) { console.error(e); }
            }

            function applyFilters(reset = false) {
                if (reset) {
                    ['fp_date_from','fp_date_to','fp_category','fp_status','fp_stock_status','fp_search'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) el.value = '';
                    });
                    activeSort = 'newest';
                }
                fetchUpdatedTable(1);
            }

            function resetFilterField(fields) {
                fields.forEach(f => {
                    const map = { date_from:'fp_date_from', date_to:'fp_date_to', category:'fp_category', status:'fp_status', stock_status:'fp_stock_status', search:'fp_search' };
                    const el = document.getElementById(map[f] || 'fp_' + f);
                    if (el) el.value = '';
                });
                fetchUpdatedTable(1);
            }

            function applySortFilter(sortKey) {
                activeSort = sortKey;
                fetchUpdatedTable(1);
                const alpineEl = document.querySelector('[x-data*="productsPage"]');
                if (alpineEl && alpineEl._x_dataStack) {
                    alpineEl._x_dataStack[0].activeSort = sortKey;
                    alpineEl._x_dataStack[0].sortOpen   = false;
                }
            }
        </script>

        <main x-data="productsPage()" x-init="init()">
            <?php if ($success): ?>
                <div style="background:#f0fdf4; border:1px solid #86efac; color:#166534; padding:12px 16px; border-radius:8px; margin-bottom:16px;">
                    ✓ <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div style="background:#fef2f2; border:1px solid #fca5a5; color:#dc2626; padding:12px 16px; border-radius:8px; margin-bottom:16px;">
                    ✗ <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <?php if ($is_manager): ?>
                <p style="font-size:13px;color:#4b5563;margin:0 0 16px;padding:12px 14px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;">
                    You can update <strong>stock quantity</strong> and <strong>low-stock alert level</strong> for <strong>your branch only</strong>. Product name, price, photo, and status are controlled by an administrator.
                </p>
            <?php endif; ?>

            <!-- KPI Summary Cards -->
            <div class="kpi-row">
                <div class="kpi-card indigo">
                    <div class="kpi-label">Total Products</div>
                    <div class="kpi-value"><?php echo $stat_total; ?></div>
                    <div class="kpi-sub">All products</div>
                </div>
                <div class="kpi-card emerald">
                    <div class="kpi-label">Active</div>
                    <div class="kpi-value"><?php echo $stat_active; ?></div>
                    <div class="kpi-sub">Visible to customers</div>
                </div>
                <div class="kpi-card rose">
                    <div class="kpi-label">Inactive</div>
                    <div class="kpi-value"><?php echo $stat_inactive; ?></div>
                    <div class="kpi-sub">Deactivated</div>
                </div>
                <div class="kpi-card amber">
                    <div class="kpi-label">Low Stock</div>
                    <div class="kpi-value"><?php echo $stat_low_stock; ?></div>
                    <div class="kpi-sub">At or below threshold</div>
                </div>
            </div>

            <div class="card">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px;">
                    <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;" id="productsListHeader">Products List</h3>

                    <div style="display:flex; align-items:center; gap:8px;">
                        <?php if (!$is_manager): ?>
                        <button class="toolbar-btn" type="button" onclick="openProductModal('create')" style="height:38px; border-color:#3b82f6; color:#3b82f6;">Add Item</button>
                        <button class="toolbar-btn" type="button" onclick="window.openArchiveModal()" style="height:38px; border-color:#6b7280; color:#6b7280; display:flex; align-items:center; gap:6px;">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                            </svg>
                            Archived Items
                        </button>
                        <?php endif; ?>

                        <!-- Sort Button -->
                        <div style="position:relative;">
                            <button type="button" class="toolbar-btn" :class="{ active: sortOpen || (activeSort !== 'newest') }" @click="sortOpen = !sortOpen; filterOpen = false" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="9" y1="18" x2="15" y2="18"/>
                                </svg>
                                Sort by
                            </button>
                            <div class="sort-dropdown" x-show="sortOpen" x-cloak @click.outside="sortOpen = false">
                                <?php
                                $sorts = [
                                    'newest' => 'Newest to Oldest',
                                    'oldest' => 'Oldest to Newest',
                                    'az'     => 'A → Z',
                                    'za'     => 'Z → A',
                                ];
                                foreach ($sorts as $key => $label): ?>
                                <div class="sort-option" 
                                     :class="{ 'selected': activeSort === '<?php echo $key; ?>' }"
                                     onclick="applySortFilter('<?php echo $key; ?>')">
                                    <?php echo htmlspecialchars($label); ?>
                                    <svg x-show="activeSort === '<?php echo $key; ?>'" class="check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Filter Button -->
                        <div style="position:relative;">
                            <button type="button" class="toolbar-btn" :class="{active: filterOpen || hasActiveFilters}" @click="filterOpen = !filterOpen; sortOpen = false" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                                </svg>
                                Filter
                                <span id="filterBadgeContainer">
                                    <?php $afc = count(array_filter([$search,$cat_filter,$status_filter,$stock_filter,$date_from,$date_to], function($v) { return $v !== null && $v !== ''; })); if ($afc > 0): ?>
                                    <span class="filter-badge"><?php echo $afc; ?></span>
                                    <?php endif; ?>
                                </span>
                            </button>

                            <!-- Filter Panel -->
                            <div class="filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false">
                                <div class="filter-panel-header">Filter</div>

                                <!-- Date Range -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Date range</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['date_from','date_to'])">Reset</button>
                                    </div>
                                    <div class="filter-date-row">
                                        <div>
                                            <div class="filter-date-label">From:</div>
                                            <input type="date" id="fp_date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from); ?>">
                                        </div>
                                        <div>
                                            <div class="filter-date-label">To:</div>
                                            <input type="date" id="fp_date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to); ?>">
                                        </div>
                                    </div>
                                </div>

                                <!-- Category -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Category</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['category'])">Reset</button>
                                    </div>
                                    <select id="fp_category" class="filter-select">
                                        <option value="">All categories</option>
                                        <?php foreach($categories as $cat): ?>
                                            <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo $cat_filter===$cat['category']?'selected':''; ?>><?php echo htmlspecialchars($cat['category']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Status -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Status</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['status'])">Reset</button>
                                    </div>
                                    <select id="fp_status" class="filter-select">
                                        <option value="">All statuses</option>
                                        <option value="Activated" <?php echo $status_filter==='Activated'?'selected':''; ?>>Activated</option>
                                        <option value="Deactivated" <?php echo $status_filter==='Deactivated'?'selected':''; ?>>Deactivated</option>
                                    </select>
                                </div>

                                <!-- Stock Status -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Stock Status</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['stock_status'])">Reset</button>
                                    </div>
                                    <select id="fp_stock_status" class="filter-select">
                                        <option value="">All stock</option>
                                        <option value="in_stock" <?php echo $stock_filter==='in_stock'?'selected':''; ?>>In Stock</option>
                                        <option value="low_stock" <?php echo $stock_filter==='low_stock'?'selected':''; ?>>Low Stock</option>
                                        <option value="out_of_stock" <?php echo $stock_filter==='out_of_stock'?'selected':''; ?>>Out of Stock</option>
                                    </select>
                                </div>

                                <!-- Keyword Search -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Keyword search</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['search'])">Reset</button>
                                    </div>
                                    <div class="filter-search-wrap">
                                        <input type="text" id="fp_search" class="filter-search-input" placeholder="Search by name or SKU..." value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div class="filter-actions">
                                    <button class="filter-btn-reset" style="width:100%;" onclick="applyFilters(true)">Reset all filters</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="productsTableContainer">
                <div class="overflow-x-auto">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>SKU</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th><?php echo $is_manager ? 'Branch qty' : 'Quantity'; ?></th>
                                <th>Stock Status</th>
                                <th>Status</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="productsTableBody">
                            <?php if (empty($products)): ?>
                                <tr id="emptyProductsRow">
                                    <td colspan="9" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">
                                        <?php echo $search ? 'No products found matching "' . htmlspecialchars($search) . '"' : 'No products yet.'; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($products as $product): ?>
                                    <?php
                                    $low = (int)($product['low_stock_level'] ?? 10);
                                    $stockStatus = get_stock_status($product['stock_quantity'], $low);
                                    $isLowOrOut = in_array($stockStatus, ['Low Stock','Out of Stock']);
                                    $isSystemDeletedProduct = printflow_is_system_deleted_product($product);
                                    $stockBadge = match($stockStatus) {
                                        'In Stock' => 'background:#dcfce7;color:#166534;',
                                        'Low Stock' => 'background:#fef9c3;color:#854d0e;',
                                        'Out of Stock' => 'background:#fee2e2;color:#991b1b;',
                                        default => 'background:#f3f4f6;color:#374151;'
                                    };
                                    ?>
                                    <tr class="<?php echo $isLowOrOut ? 'low-stock-row' : ''; ?>" onclick="openViewModal(<?php echo htmlspecialchars(json_encode($product), ENT_QUOTES); ?>)">
                                        <td style="color:#1f2937;"><?php echo $product['product_id']; ?></td>
                                        <td><?php echo htmlspecialchars($product['sku'] ?? '—'); ?></td>
                                        <td style="font-weight:500;color:#1f2937;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($product['name']); ?></td>
                                        <td><?php echo htmlspecialchars($product['category'] ?? '—'); ?></td>
                                        <td style="font-weight:600;color:#1f2937;white-space:nowrap;">₱<?php echo number_format($product['price'], 2); ?></td>
                                        <td>
                                            <span style="font-weight:<?php echo $isLowOrOut ? 'bold' : '400'; ?>;color:<?php echo $stockStatus === 'Out of Stock' ? '#dc2626' : ($stockStatus === 'Low Stock' ? '#b45309' : '#374151'); ?>;"><?php echo $product['stock_quantity']; ?></span>
                                        </td>
                                        <td>
                                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;<?php echo $stockBadge; ?>"><?php echo $stockStatus; ?></span>
                                        </td>
                                        <td>
                                            <?php
                                                $sc = match($product['status']) {
                                                    'Activated'   => 'background:#dcfce7;color:#166534;',
                                                    'Deactivated' => 'background:#fee2e2;color:#991b1b;',
                                                    'Archived'    => 'background:#f3f4f6;color:#374151;',
                                                    default       => 'background:#fef9c3;color:#854d0e;'
                                                };
                                            ?>
                                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;<?php echo $sc; ?>">
                                                <?php echo $product['status']; ?>
                                            </span>
                                        </td>
                                        <td style="text-align:right;white-space:nowrap;" onclick="event.stopPropagation();">
                                            <?php if ($product['status'] !== 'Archived'): ?>
                                            <button class="btn-action teal"
                                                onclick='openProductModal("stock", <?php echo htmlspecialchars(json_encode($product), ENT_QUOTES); ?>)'>Add Stock</button>
                                            <?php endif; ?>
                                            <button class="btn-action blue"
                                                onclick='openProductModal("edit", <?php echo htmlspecialchars(json_encode($product), ENT_QUOTES); ?>)'><?php echo $is_manager ? 'Stock' : 'Edit'; ?></button>
                                            <?php if (!$is_manager && $isSystemDeletedProduct): ?>
                                                <form method="POST" class="inline product-status-form" data-pf-skip-guard data-action="Auto Recover" data-product-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>" onsubmit="showProductStatusModal(event, this);return false;">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                                    <button type="submit" name="recover_system_deleted_product" class="btn-action teal">Auto Recover</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (!$is_manager): ?>
                                            <?php if ($product['status'] !== 'Archived'): ?>
                                                <form method="POST" class="inline product-status-form" data-pf-skip-guard data-action="<?php echo $product['status'] === 'Activated' ? 'Deactivate' : 'Activate'; ?>" data-product-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>" onsubmit="showProductStatusModal(event, this);return false;">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                                    <button type="submit" name="delete_product" class="btn-action <?php echo $product['status'] === 'Activated' ? 'red' : 'teal'; ?>">
                                                        <?php echo $product['status'] === 'Activated' ? 'Deactivate' : 'Activate'; ?>
                                                    </button>
                                                </form>
                                                <?php if ($product['status'] === 'Deactivated'): ?>
                                                    <form method="POST" class="inline product-status-form" data-pf-skip-guard data-action="Archive" data-product-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>" onsubmit="showProductStatusModal(event, this);return false;">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                                        <button type="submit" name="archive_product" class="btn-action gray">Archive</button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <form method="POST" class="inline product-status-form" data-pf-skip-guard data-action="Restore" data-product-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>" onsubmit="showProductStatusModal(event, this);return false;">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                                    <button type="submit" name="restore_product" class="btn-action teal">Restore</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="productsPagination">
                    <?php
                    $pagination_params = array_filter(['search'=>$search,'category'=>$cat_filter,'status'=>$status_filter,'stock_status'=>$stock_filter,'sort'=>$sort_by,'date_from'=>$date_from,'date_to'=>$date_to], function($v) { return $v !== null && $v !== ''; });
                    echo render_pagination($page, $total_pages, $pagination_params);
                    ?>
                </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Product Status Confirmation Modal -->
<div id="productStatusConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:10100;align-items:center;justify-content:center;padding:16px;flex-direction:column;pointer-events:auto;">
    <div style="background:white;border-radius:16px;padding:26px;max-width:420px;width:100%;box-shadow:0 25px 50px rgba(0,0,0,0.25);text-align:center;">
        <div id="productStatusConfirmIcon" style="width:48px;height:48px;background:#f3f4f6;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;color:#6b7280;">
            <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        </div>
        <h3 id="productStatusConfirmTitle" style="font-size:18px;font-weight:700;color:#1f2937;margin:0 0 8px;">Confirm Action</h3>
        <p id="productStatusConfirmText" style="font-size:14px;color:#4b5563;margin:0 0 16px;line-height:1.5;word-break:break-word;overflow-wrap:anywhere;">Are you sure you want to proceed?</p>
        
        <div id="productStatusInfoBox" style="font-size:12px;color:#6b7280;background:#f9fafb;padding:12px;border-radius:10px;margin-bottom:24px;text-align:left;border:1px solid #e5e7eb;line-height:1.5;">
            <div style="font-weight:700;margin-bottom:4px;color:#374151;display:flex;align-items:center;gap:5px;">
                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                What happens next?
            </div>
            <div id="productStatusInfoText">Action details will appear here.</div>
        </div>

        <div style="display:flex;gap:12px;justify-content:center;">
            <button type="button" id="productStatusConfirmCancel" style="flex:1;padding:12px 16px;border:1px solid #e5e7eb;background:white;border-radius:10px;font-size:14px;font-weight:600;color:#4b5563;cursor:pointer;transition:all 0.2s;">Cancel</button>
            <button type="button" id="productStatusConfirmOk" style="flex:1;padding:12px 16px;border:none;background:#3b82f6;border-radius:10px;font-size:14px;font-weight:600;color:white;cursor:pointer;transition:all 0.2s;">Confirm</button>
        </div>
    </div>
</div>

<!-- Add/Edit Product Modal -->
<div id="product-modal-overlay" onclick="handleOverlayClick(event)">
    <div id="product-modal">
        <div class="modal-header">
            <h3 id="modal-title" style="font-size:18px; font-weight:700; margin:0;">Add New Product</h3>
            <button id="close-modal-btn" onclick="closeProductModal()" style="background:transparent;border:none;cursor:pointer;color:#6b7280;">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <form method="POST" id="product-form" action="" enctype="multipart/form-data" novalidate data-turbo="false">
                <?php echo csrf_field(); ?>
                <?php /* Managers never create products: always POST update_product so server runs branch-stock handler even if JS fails after form.reset() */ ?>
                <input type="hidden" id="modal-mode-input" name="<?php echo $is_manager ? 'update_product' : 'create_product'; ?>" value="1">
                <input type="hidden" id="modal-product-id" name="product_id" value="">

                <div id="pf-stock-only" style="display:none;">
                    <div class="form-group" style="margin-bottom:18px;">
                        <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Product name</label>
                        <div id="stock-modal-product-name" style="padding:12px 14px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;font-weight:600;color:#111827;font-size:15px;">—</div>
                    </div>
                    <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                        <div class="form-group">
                            <label for="stock-modal-current">Current stock</label>
                            <input type="number" id="stock-modal-current" value="" disabled>
                            <small>
                                <?php echo $is_manager ? 'Current quantity for your assigned branch.' : 'Current quantity for the selected stock scope.'; ?>
                            </small>
                        </div>
                        <div class="form-group" id="fg-add-stock">
                            <label for="stock-modal-add-qty">Add stock quantity <span style="color:#dc2626">*</span></label>
                            <input type="number" id="stock-modal-add-qty" min="1" max="99999" value="" step="1" disabled autocomplete="off" inputmode="numeric" placeholder="0" oninput="if (this.value.length > 5) this.value = this.value.slice(0, 5);">
                            <span id="err-add-stock" class="field-error"></span>
                        </div>
                    </div>
                    <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                        <div class="form-group" id="fg-stock-only-low">
                            <label for="stock-modal-low-level">Low stock level <span style="color:#dc2626">*</span></label>
                            <input type="number" id="stock-modal-low-level" min="0" value="10" step="1" disabled autocomplete="off" placeholder="0" maxlength="5">
                            <small>Warn when stock falls below this. Must be less than or equal to resulting stock.</small>
                            <span id="err-stock-only-low" class="field-error"></span>
                        </div>
                        <div class="form-group">
                            <label for="stock-modal-result">Resulting stock</label>
                            <input type="number" id="stock-modal-result" value="" disabled>
                            <small>This updates automatically as you enter the added stock quantity.</small>
                        </div>
                    </div>
                </div>

                <?php if ($is_manager): ?>
                <div id="pf-manager-only" style="display:none;">
                    <div class="form-group" style="margin-bottom:18px;">
                        <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;">Product name</label>
                        <div id="manager-modal-product-name" style="padding:12px 14px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;font-weight:600;color:#111827;font-size:15px;">—</div>
                    </div>
                    <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                        <div class="form-group" id="fg-stock-mgr">
                            <label for="modal-stock-mgr">Quantity <span style="color:#dc2626">*</span> <span style="font-weight:500;color:#6b7280;">(your branch)</span></label>
                            <input type="number" id="modal-stock-mgr" name="stock_quantity" min="0" value="" step="1" disabled autocomplete="off" placeholder="0" maxlength="5">
                            <span id="err-stock-mgr" class="field-error"></span>
                        </div>
                        <div class="form-group" id="fg-low-stock-mgr">
                            <label for="modal-low-mgr">Low stock level <span style="color:#dc2626">*</span> <span style="font-weight:500;color:#6b7280;">(your branch)</span></label>
                            <input type="number" id="modal-low-mgr" name="low_stock_level" min="0" value="10" step="1" disabled autocomplete="off" placeholder="0" maxlength="5">
                            <small style="display:block;margin-top:4px;color:#6b7280;">Warn when stock falls below this. Must be ≤ quantity.</small>
                            <span id="err-low-mgr" class="field-error"></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div id="pf-admin-only">
                <div class="form-row">
                    <div class="form-group" id="fg-name">
                        <label for="modal-name">Product Name <span style="color:red">*</span></label>
                        <input type="text" id="modal-name" name="name" placeholder="e.g. Custom Tarpaulin" maxlength="100">
                        <span id="err-name" class="field-error"></span>
                    </div>
                    <div class="form-group">
                        <label for="modal-sku">SKU</label>
                        <input type="text" id="modal-sku" name="sku" placeholder="Auto-generated" readonly style="background-color:#f3f4f6; cursor:not-allowed;">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group" id="fg-category">
                        <label for="modal-category">Category <span style="color:red">*</span></label>
                        <select id="modal-category" name="category">
                            <option value="">-- Select Category --</option>
                            <?php foreach (printflow_product_modal_categories() as $pf_prod_cat): ?>
                            <option value="<?php echo htmlspecialchars($pf_prod_cat); ?>"><?php echo htmlspecialchars($pf_prod_cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span id="err-category" class="field-error"></span>
                    </div>
                    <div class="form-group" id="fg-price">
                        <label for="modal-price">Price (₱) <span style="color:red">*</span></label>
                        <input type="number" id="modal-price" name="price" step="0.01" min="0.01" placeholder="0.00">
                        <span id="err-price" class="field-error"></span>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group" id="fg-description" style="grid-column:1/-1;">
                        <label for="modal-description">Description</label>
                        <textarea id="modal-description" name="description" rows="2" maxlength="500" placeholder="Optional description (max 500 chars)..." style="resize:vertical;overflow-y:auto;max-height:120px;"></textarea>
                        <span id="err-description" class="field-error"></span>
                    </div>
                </div>

                <div class="form-group" id="fg-photo">
                    <label for="modal-photo">Product Photo <span style="color:red">*</span></label>
                    <div style="display:flex; align-items:flex-start; gap:12px;">
                        <div id="photo-preview" style="width:110px; height:82px; flex-shrink:0; border:2px dashed #d1d5db; border-radius:8px; background:#f9fafb; display:flex; align-items:center; justify-content:center; overflow:hidden;">
                            <img id="photo-preview-img" src="" alt="" style="width:100%; height:100%; object-fit:cover; display:none;">
                            <span id="photo-preview-text" style="color:#9ca3af; font-size:11px; text-align:center; padding:4px;">No photo</span>
                        </div>
                        <div style="flex:1; min-width:0;">
                            <input type="file" id="modal-photo" name="photo" accept="image/jpeg,image/jpg,image/png,image/gif" style="padding:6px 8px; border:1px solid #e5e7eb; border-radius:6px; cursor:pointer; font-size:12px;">
                            <small>JPG, PNG, GIF only. Max 5MB.</small>
                        </div>
                    </div>
                    <span id="err-photo" class="field-error"></span>
                </div>

                <div class="form-row-3">
                    <div class="form-group" id="fg-stock">
                        <label for="modal-stock">Quantity <span style="color:#dc2626">*</span></label>
                        <input type="number" id="modal-stock"<?php echo $is_manager ? '' : ' name="stock_quantity"'; ?> min="0" value="" step="1" placeholder="0" maxlength="5">
                        <span id="err-stock" class="field-error"></span>
                    </div>
                    <div class="form-group" id="fg-low-stock">
                        <label for="modal-low-stock">Low Stock Level <span style="color:#dc2626">*</span></label>
                        <input type="number" id="modal-low-stock"<?php echo $is_manager ? '' : ' name="low_stock_level"'; ?> min="0" value="10" step="1" placeholder="0" maxlength="5">
                        <small>Warn when stock falls below this. Must be ≤ Quantity.</small>
                        <span id="err-low-stock" class="field-error"></span>
                    </div>
                    <div class="form-group">
                        <label for="modal-status">Status</label>
                        <select id="modal-status" name="status">
                            <option value="Activated">Activated</option>
                            <option value="Deactivated">Deactivated</option>
                        </select>
                    </div>
                </div>
                </div><!-- #pf-admin-only -->

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeProductModal()">Cancel</button>
                    <?php /* Managers: separate id so shared product-form-validation.js never sets disabled on this button */ ?>
                    <button type="submit" id="<?php echo $is_manager ? 'modal-submit-products-mgr' : 'modal-submit-btn'; ?>" class="btn-save">Create Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Product Modal -->
<div id="view-product-modal-overlay" onclick="handleViewOverlayClick(event)">
    <div id="view-product-modal">
        <div class="modal-header">
            <h3 style="font-size:18px; font-weight:700; margin:0;">
                Product Details
            </h3>
            <button onclick="closeViewModal()" style="background:transparent;border:none;cursor:pointer;color:#6b7280;">
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="modal-body" style="padding:24px;">
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:24px; min-width:0;">
                <!-- Left Side: Details -->
                <div style="display:flex; flex-direction:column; gap:16px; min-width:0;">
                    <div>
                        <label class="view-label">Product Name</label>
                        <div id="view-product-name" class="view-value-box">-</div>
                    </div>
                    
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                        <div>
                            <label class="view-label">SKU</label>
                            <div id="view-product-sku" class="view-value-box">-</div>
                        </div>
                        <div>
                            <label class="view-label">Category</label>
                            <div id="view-product-category" class="view-value-box">-</div>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                        <div>
                            <label class="view-label">Price</label>
                            <div id="view-product-price" class="view-value-box">-</div>
                        </div>
                        <div>
                            <label class="view-label">Stock Status</label>
                            <div id="view-product-stock-status" style="height:38px; display:flex; align-items:center; justify-content:center; border-radius:8px; font-size:12px; font-weight:700;">-</div>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                        <div>
                            <label class="view-label">Current Quantity</label>
                            <div id="view-product-stock" class="view-value-box" style="font-weight:700;">-</div>
                        </div>
                        <div>
                            <label class="view-label">Low Stock Warning</label>
                            <div id="view-product-low-stock" class="view-value-box">-</div>
                        </div>
                    </div>

                    <div>
                        <label class="view-label">Product Visibility</label>
                        <div id="view-product-status" style="height:38px; display:flex; align-items:center; justify-content:center; border-radius:8px; font-size:12px; font-weight:700;">-</div>
                    </div>
                </div>

                <!-- Right Side: Photo & Description -->
                <div style="display:flex; flex-direction:column; gap:16px; min-width:0;">
                    <div>
                        <label class="view-label">Product Photo</label>
                        <div style="width:100%; height:200px; border-radius:12px; border:1px solid #e5e7eb; background:#f9fafb; overflow:hidden; display:flex; align-items:center; justify-content:center;">
                            <img id="view-product-photo-img" src="" alt="" style="width:100%; height:100%; object-fit:cover; display:none;">
                            <span id="view-product-photo-text" style="color:#9ca3af; font-size:12px; text-align:center; padding:20px;">No photo available</span>
                        </div>
                    </div>
                    <div>
                        <label class="view-label">Description</label>
                        <div id="view-product-description" class="view-value-box" style="min-height:80px; font-size:13px; line-height:1.5; white-space:pre-wrap;">-</div>
                    </div>
                </div>
            </div>

            <div style="padding:16px 0 0;border-top:1px solid #f3f4f6;margin-top:24px;display:flex;justify-content:flex-end;">
                <button type="button" onclick="closeViewModal()" class="btn-secondary">Close</button>
            </div>
        </div>
    </div>
</div>



<!-- Archived Items Modal (z-index above status modal 10000, logout 9999, pf-fg portal 10030+) -->
<div id="archive-storage-overlay" role="dialog" aria-modal="true" aria-labelledby="archive-storage-title" onclick="if (event.target === this) window.closeArchiveModal()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:10090;align-items:center;justify-content:center;padding:16px;pointer-events:auto;">
    <div id="archive-storage-modal" onclick="event.stopPropagation()" style="background:white;border-radius:16px;width:100%;max-width:900px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 25px 50px rgba(0,0,0,0.25);pointer-events:auto;">
        <div class="modal-header" style="padding:20px 24px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <h3 id="archive-storage-title" style="font-size:18px;font-weight:700;color:#1f2937;margin:0;">Archived Items</h3>
                <p style="font-size:12px;color:#6b7280;margin:0;">Manage archived products here.</p>
            </div>
            <button type="button" onclick="window.closeArchiveModal()" style="background:none;border:none;cursor:pointer;color:#9ca3af;padding:4px;line-height:1;">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="modal-body" style="padding:0;overflow-y:auto;flex:1;">
            <div id="archived-products-container" style="min-height:200px;padding:24px;">
                <!-- Content loaded via AJAX -->
                <div style="padding:60px;text-align:center;color:#9ca3af;">
                    <div class="spinner" style="margin:0 auto 12px;"></div>
                    <p>Loading archived products...</p>
                </div>
            </div>
            <div style="padding:16px 24px 24px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;">
                <button type="button" class="btn-secondary" onclick="window.closeArchiveModal()">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
window.PF_PRODUCTS_IS_MANAGER = <?php echo $is_manager ? 'true' : 'false'; ?>;
window.PF_PRODUCT_CATEGORY_ALLOWLIST = <?php echo json_encode(printflow_product_modal_categories(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function pfGetProductModalSubmitBtn() {
    return document.getElementById('modal-submit-products-mgr') || document.getElementById('modal-submit-btn');
}

function pfStockOnlyModalSetActive(active, product) {
    var stockOnly = document.getElementById('pf-stock-only');
    var mgr = document.getElementById('pf-manager-only');
    var adm = document.getElementById('pf-admin-only');
    if (!stockOnly || !adm) return;

    var addQty = document.getElementById('stock-modal-add-qty');
    var lowLevel = document.getElementById('stock-modal-low-level');
    var currentStock = document.getElementById('stock-modal-current');
    var resultStock = document.getElementById('stock-modal-result');
    var productName = document.getElementById('stock-modal-product-name');

    function toggleSection(sectionEl, disabled) {
        if (!sectionEl) return;
        sectionEl.querySelectorAll('input, select, textarea, button').forEach(function(el) {
            if (el.type === 'hidden') return;
            if (disabled) {
                el.setAttribute('data-pf-prev-disabled', el.disabled ? '1' : '0');
                el.disabled = true;
            } else {
                var prev = el.getAttribute('data-pf-prev-disabled');
                el.disabled = (prev === '1');
                el.removeAttribute('data-pf-prev-disabled');
            }
        });
    }

    if (active) {
        stockOnly.style.display = 'block';
        if (mgr) mgr.style.display = 'none';
        adm.style.display = 'none';
        toggleSection(mgr, true);
        toggleSection(adm, true);

        if (addQty) {
            addQty.disabled = false;
            addQty.name = 'add_stock_quantity';
        }
        if (lowLevel) {
            lowLevel.disabled = false;
            lowLevel.name = 'low_stock_level';
        }
        if (productName) productName.textContent = product && product.name ? product.name : '—';
        var qty = product && product.stock_quantity != null ? parseInt(product.stock_quantity, 10) || 0 : 0;
        var low = product && product.low_stock_level != null ? parseInt(product.low_stock_level, 10) || 10 : 10;
        if (currentStock) currentStock.value = String(qty);
        if (resultStock) resultStock.value = String(qty);
        if (addQty) addQty.value = '';
        if (lowLevel) lowLevel.value = String(low);
    } else {
        stockOnly.style.display = 'none';
        if (mgr) mgr.style.display = 'none';
        adm.style.display = window.PF_PRODUCTS_IS_MANAGER ? 'none' : 'block';
        if (addQty) {
            addQty.disabled = true;
            addQty.removeAttribute('name');
            addQty.value = '';
        }
        if (lowLevel) {
            lowLevel.disabled = true;
            lowLevel.removeAttribute('name');
            lowLevel.value = '10';
        }
        if (currentStock) currentStock.value = '';
        if (resultStock) resultStock.value = '';
        if (productName) productName.textContent = '—';
        toggleSection(mgr, false);
        toggleSection(adm, false);
    }
}

function pfSyncStockOnlyResult() {
    var currentStock = document.getElementById('stock-modal-current');
    var addQty = document.getElementById('stock-modal-add-qty');
    var resultStock = document.getElementById('stock-modal-result');
    if (!currentStock || !addQty || !resultStock) return;
    var current = parseInt(currentStock.value || '0', 10);
    var add = parseInt(addQty.value || '0', 10);
    if (isNaN(current)) current = 0;
    if (isNaN(add) || add < 0) add = 0;
    resultStock.value = String(current + add);
}

function pfSubmitStockOnlyForm() {
    var form = document.getElementById('product-form');
    var addQty = document.getElementById('stock-modal-add-qty');
    var lowLevel = document.getElementById('stock-modal-low-level');
    var currentStock = document.getElementById('stock-modal-current');
    if (!form || !addQty || !lowLevel || !currentStock) return;

    var add = parseInt(addQty.value || '0', 10);
    var low = parseInt(lowLevel.value || '0', 10);
    var current = parseInt(currentStock.value || '0', 10);
    if (isNaN(current)) current = 0;

    var errAdd = document.getElementById('err-add-stock');
    var errLow = document.getElementById('err-stock-only-low');
    if (errAdd) errAdd.textContent = '';
    if (errLow) errLow.textContent = '';

    if (isNaN(add) || add < 1) {
        if (errAdd) errAdd.textContent = 'Add stock quantity must be greater than 0.';
        addQty.focus();
        return;
    }
    if (isNaN(low) || low < 0) {
        if (errLow) errLow.textContent = 'Low stock level must be a non-negative whole number.';
        lowLevel.focus();
        return;
    }
    if (low > (current + add)) {
        if (errLow) errLow.textContent = 'Low stock level cannot exceed the updated quantity.';
        lowLevel.focus();
        return;
    }

    HTMLFormElement.prototype.submit.call(form);
}

function pfEnsureStockOnlySubmitEnabled() {
    var modeInput = document.getElementById('modal-mode-input');
    var submitBtn = pfGetProductModalSubmitBtn();
    if (!modeInput || !submitBtn) return;
    if (modeInput.name !== 'add_product_stock') return;
    submitBtn.disabled = false;
    submitBtn.removeAttribute('disabled');
    submitBtn.style.opacity = '1';
    submitBtn.style.cursor = 'pointer';
}

/** Manager: show only product name + branch quantity + low stock; hide full catalog form and avoid duplicate POST names. */
function pfManagerModalSetActive(active, product) {
    if (!window.PF_PRODUCTS_IS_MANAGER) return;
    var mgr = document.getElementById('pf-manager-only');
    var adm = document.getElementById('pf-admin-only');
    if (!mgr || !adm) return;
    var stockAdm = document.getElementById('modal-stock');
    var lowAdm = document.getElementById('modal-low-stock');
    var sm = document.getElementById('modal-stock-mgr');
    var lm = document.getElementById('modal-low-mgr');
    if (active) {
        mgr.style.display = 'block';
        adm.style.display = 'none';
        adm.querySelectorAll('input, select, textarea').forEach(function(el) {
            if (el.type === 'hidden') return;
            el.setAttribute('data-pf-prev-disabled', el.disabled ? '1' : '0');
            el.disabled = true;
        });
        if (stockAdm) stockAdm.removeAttribute('name');
        if (lowAdm) lowAdm.removeAttribute('name');
        if (sm) { sm.disabled = false; }
        if (lm) { lm.disabled = false; }
        var nm = document.getElementById('manager-modal-product-name');
        if (product && nm) nm.textContent = product.name || '—';
        if (product && sm) sm.value = product.stock_quantity != null ? String(product.stock_quantity) : '0';
        if (product && lm) lm.value = product.low_stock_level != null ? String(product.low_stock_level) : '10';
    } else {
        mgr.style.display = 'none';
        adm.style.display = 'block';
        adm.querySelectorAll('input, select, textarea').forEach(function(el) {
            if (el.type === 'hidden') return;
            var p = el.getAttribute('data-pf-prev-disabled');
            el.disabled = (p === '1');
            el.removeAttribute('data-pf-prev-disabled');
        });
        if (!window.PF_PRODUCTS_IS_MANAGER) {
            if (stockAdm) stockAdm.setAttribute('name', 'stock_quantity');
            if (lowAdm) lowAdm.setAttribute('name', 'low_stock_level');
        }
        if (sm) { sm.disabled = true; }
        if (lm) { lm.disabled = true; }
    }
}

var _productStatusForm = null;
var _productStatusButtonName = null;

function pfProductStockStatusLabel(qty, low) {
    qty = parseInt(qty, 10) || 0;
    low = parseInt(low, 10) || 10;
    if (qty <= 0) return 'Out of Stock';
    if (qty <= low) return 'Low Stock';
    return 'In Stock';
}
function pfStockBadgeStyle(label) {
    if (label === 'In Stock') return 'background:#dcfce7;color:#166534;';
    if (label === 'Low Stock') return 'background:#fef9c3;color:#854d0e;';
    if (label === 'Out of Stock') return 'background:#fee2e2;color:#991b1b;';
    return 'background:#f3f4f6;color:#374151;';
}
function pfVisibilityStatusStyle(st) {
    if (st === 'Activated') return 'background:#dcfce7;color:#166534;';
    if (st === 'Deactivated') return 'background:#fee2e2;color:#991b1b;';
    if (st === 'Archived') return 'background:#f3f4f6;color:#374151;';
    return 'background:#fef9c3;color:#854d0e;';
}

window.openProductModal = function openProductModal(mode, product) {
    if (window.PF_PRODUCTS_IS_MANAGER && mode === 'create') {
        return;
    }
    var overlay = document.getElementById('product-modal-overlay');
    var form = document.getElementById('product-form');
    if (!overlay || !form) {
        console.warn('openProductModal: product modal markup not in DOM.');
        return;
    }
    pfStockOnlyModalSetActive(false);
    pfManagerModalSetActive(false);
    var pti = document.getElementById('modal-photo');
    if (pti) pti.disabled = false;
    form.reset();
    var catSelPfInit = document.getElementById('modal-category');
    if (catSelPfInit) catSelPfInit.querySelectorAll('option[data-pf-legacy-cat]').forEach(function(o) { o.remove(); });
    var title = document.getElementById('modal-title');
    var modeInput = document.getElementById('modal-mode-input');
    var submitBtn = pfGetProductModalSubmitBtn();
    var previewImg = document.getElementById('photo-preview-img');
    var previewText = document.getElementById('photo-preview-text');
    var photoInput = document.getElementById('modal-photo');

    if (mode === 'stock' && product) {
        if (title) title.textContent = 'Add Stock';
        if (modeInput) { modeInput.name = 'add_product_stock'; modeInput.value = '1'; }
        if (submitBtn) {
            submitBtn.textContent = 'Add Stock';
            submitBtn.type = 'button';
            submitBtn.onclick = pfSubmitStockOnlyForm;
        }
        var pidStockEl = document.getElementById('modal-product-id');
        if (pidStockEl) pidStockEl.value = product.product_id != null ? String(product.product_id) : '';
        pfStockOnlyModalSetActive(true, product);
    } else if (mode === 'edit' && product) {
        if (title) title.textContent = window.PF_PRODUCTS_IS_MANAGER ? 'Branch stock' : 'Edit Product';
        if (modeInput) { modeInput.name = 'update_product'; modeInput.value = '1'; }
        if (submitBtn) {
            submitBtn.textContent = window.PF_PRODUCTS_IS_MANAGER ? 'Save branch stock' : 'Save Changes';
            submitBtn.type = 'submit';
            submitBtn.onclick = null;
        }
        var pidEl = document.getElementById('modal-product-id');
        if (pidEl) pidEl.value = product.product_id != null ? String(product.product_id) : '';
        if (window.PF_PRODUCTS_IS_MANAGER) {
            pfManagerModalSetActive(true, product);
        } else {
            var nameEl = document.getElementById('modal-name');
            if (nameEl) nameEl.value = product.name || '';
            var skuEl = document.getElementById('modal-sku');
            if (skuEl) skuEl.value = product.sku || '';
            var catEl = document.getElementById('modal-category');
            if (catEl) {
                catEl.querySelectorAll('option[data-pf-legacy-cat]').forEach(function(o) { o.remove(); });
                var catVal = product.category || '';
                var allow = window.PF_PRODUCT_CATEGORY_ALLOWLIST || [];
                var catNorm = String(catVal).toLowerCase();
                var allowedPick = allow.some(function(a) { return String(a).toLowerCase() === catNorm; });
                if (catVal && !allowedPick) {
                    var o = document.createElement('option');
                    o.value = catVal;
                    o.textContent = catVal + ' (legacy — pick an allowed category to save)';
                    o.setAttribute('data-pf-legacy-cat', '1');
                    catEl.appendChild(o);
                }
                catEl.value = catVal;
            }
            var priceEl = document.getElementById('modal-price');
            if (priceEl) priceEl.value = product.price != null ? String(product.price) : '';
            var descEl = document.getElementById('modal-description');
            if (descEl) descEl.value = product.description || '';
            var stockEl = document.getElementById('modal-stock');
            if (stockEl) stockEl.value = product.stock_quantity != null ? String(product.stock_quantity) : '0';
            var lowEl = document.getElementById('modal-low-stock');
            if (lowEl) lowEl.value = product.low_stock_level != null ? String(product.low_stock_level) : '10';
            var stEl = document.getElementById('modal-status');
            if (stEl) stEl.value = (product.status === 'Deactivated') ? 'Deactivated' : 'Activated';
            if (photoInput) photoInput.value = '';
            if (product.photo_path && previewImg && previewText) {
                previewImg.src = product.photo_path;
                previewImg.style.display = 'block';
                previewText.style.display = 'none';
            } else {
                if (previewImg) { previewImg.removeAttribute('src'); previewImg.style.display = 'none'; }
                if (previewText) previewText.style.display = 'block';
            }
        }
    } else {
        if (title) title.textContent = 'Add New Product';
        if (modeInput) { modeInput.name = 'create_product'; modeInput.value = '1'; }
        if (submitBtn) {
            submitBtn.textContent = 'Create Product';
            submitBtn.type = 'submit';
            submitBtn.onclick = null;
        }
        pfStockOnlyModalSetActive(false);
        pfManagerModalSetActive(false);
        var pidEl2 = document.getElementById('modal-product-id');
        if (pidEl2) pidEl2.value = '';
        if (photoInput) photoInput.value = '';
        if (previewImg) { previewImg.removeAttribute('src'); previewImg.style.display = 'none'; }
        if (previewText) previewText.style.display = 'block';
    }
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.removeAttribute('disabled');
    }
    if (typeof window.printflowProductFormValidationRun === 'function') {
        window.printflowProductFormValidationRun();
    }
    if (mode === 'stock' && product) {
        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                pfEnsureStockOnlySubmitEnabled();
            });
        });
    }
    try {
        document.dispatchEvent(new CustomEvent('pf-product-modal-shown'));
    } catch (e) { /* ignore */ }
    if ((window.PF_PRODUCTS_IS_MANAGER && mode === 'edit' && product && submitBtn) || (mode === 'stock' && product && submitBtn)) {
        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                var b = pfGetProductModalSubmitBtn();
                if (b && document.getElementById('product-modal-overlay')?.classList.contains('active')) {
                    b.disabled = false;
                    b.removeAttribute('disabled');
                }
            });
        });
    }
    var focusEl = (mode === 'stock' && product)
        ? document.getElementById('stock-modal-add-qty')
        : (window.PF_PRODUCTS_IS_MANAGER && mode === 'edit' && product)
        ? document.getElementById('modal-stock-mgr')
        : document.getElementById('modal-name');
    if (focusEl) setTimeout(function() { focusEl.focus(); }, 10);
};

function closeProductModal() {
    pfStockOnlyModalSetActive(false);
    pfManagerModalSetActive(false);
    var pti = document.getElementById('modal-photo');
    if (pti) pti.disabled = false;
    var overlay = document.getElementById('product-modal-overlay');
    if (overlay) overlay.classList.remove('active');
    if (document.getElementById('archive-storage-overlay')?.style.display !== 'flex' &&
        document.getElementById('productStatusConfirmModal')?.style.display !== 'flex') {
        document.body.style.overflow = '';
    }
    var submitBtn = pfGetProductModalSubmitBtn();
    var modeInput = document.getElementById('modal-mode-input');
    if (submitBtn && modeInput) {
        submitBtn.disabled = false;
        submitBtn.removeAttribute('disabled');
        if (modeInput.name === 'update_product') {
            submitBtn.textContent = window.PF_PRODUCTS_IS_MANAGER ? 'Save branch stock' : 'Save Changes';
            submitBtn.type = 'submit';
            submitBtn.onclick = null;
        } else if (modeInput.name === 'add_product_stock') {
            submitBtn.textContent = 'Add Stock';
            submitBtn.type = 'button';
            submitBtn.onclick = pfSubmitStockOnlyForm;
        } else {
            submitBtn.textContent = 'Create Product';
            submitBtn.type = 'submit';
            submitBtn.onclick = null;
        }
    }
}

function handleOverlayClick(e) {
    if (e.target.id === 'product-modal-overlay') closeProductModal();
}

document.addEventListener('DOMContentLoaded', function() {
    var addQty = document.getElementById('stock-modal-add-qty');
    if (addQty && !addQty.dataset.pfStockPreviewBound) {
        addQty.dataset.pfStockPreviewBound = '1';
        addQty.addEventListener('input', function() {
            pfSyncStockOnlyResult();
            pfEnsureStockOnlySubmitEnabled();
        });
        addQty.addEventListener('change', function() {
            pfSyncStockOnlyResult();
            pfEnsureStockOnlySubmitEnabled();
        });
    }
    var lowLevel = document.getElementById('stock-modal-low-level');
    if (lowLevel && !lowLevel.dataset.pfStockPreviewBound) {
        lowLevel.dataset.pfStockPreviewBound = '1';
        lowLevel.addEventListener('input', pfEnsureStockOnlySubmitEnabled);
        lowLevel.addEventListener('change', pfEnsureStockOnlySubmitEnabled);
    }
});

function openViewModal(product) {
    if (!product) return;
    var stockLabel = pfProductStockStatusLabel(product.stock_quantity, product.low_stock_level);
    var nameEl = document.getElementById('view-product-name');
    if (nameEl) nameEl.textContent = product.name || '—';
    var skuEl = document.getElementById('view-product-sku');
    if (skuEl) skuEl.textContent = product.sku || '—';
    var catEl = document.getElementById('view-product-category');
    if (catEl) catEl.textContent = product.category || '—';
    var priceEl = document.getElementById('view-product-price');
    if (priceEl) {
        var p = parseFloat(product.price);
        priceEl.textContent = isNaN(p) ? '—' : '₱' + p.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    var ssEl = document.getElementById('view-product-stock-status');
    if (ssEl) {
        ssEl.textContent = stockLabel;
        ssEl.setAttribute('style', 'height:38px;display:flex;align-items:center;justify-content:center;border-radius:8px;font-size:12px;font-weight:700;' + pfStockBadgeStyle(stockLabel));
    }
    var sqEl = document.getElementById('view-product-stock');
    if (sqEl) sqEl.textContent = product.stock_quantity != null ? String(product.stock_quantity) : '—';
    var lowEl = document.getElementById('view-product-low-stock');
    if (lowEl) lowEl.textContent = product.low_stock_level != null ? String(product.low_stock_level) : '—';
    var visEl = document.getElementById('view-product-status');
    var st = product.status || '';
    if (visEl) {
        visEl.textContent = st || '—';
        visEl.setAttribute('style', 'height:38px;display:flex;align-items:center;justify-content:center;border-radius:8px;font-size:12px;font-weight:700;' + pfVisibilityStatusStyle(st));
    }
    var img = document.getElementById('view-product-photo-img');
    var imgTxt = document.getElementById('view-product-photo-text');
    if (product.photo_path && img && imgTxt) {
        img.src = product.photo_path;
        img.style.display = 'block';
        imgTxt.style.display = 'none';
    } else {
        if (img) { img.removeAttribute('src'); img.style.display = 'none'; }
        if (imgTxt) imgTxt.style.display = 'block';
    }
    var descEl = document.getElementById('view-product-description');
    if (descEl) descEl.textContent = (product.description && String(product.description).trim()) ? product.description : '—';
    var vOverlay = document.getElementById('view-product-modal-overlay');
    if (vOverlay) {
        vOverlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeViewModal() {
    var vOverlay = document.getElementById('view-product-modal-overlay');
    if (vOverlay) vOverlay.classList.remove('active');
    if (document.getElementById('product-modal-overlay')?.classList.contains('active')) return;
    if (document.getElementById('archive-storage-overlay')?.style.display === 'flex') return;
    if (document.getElementById('productStatusConfirmModal')?.style.display === 'flex') return;
    document.body.style.overflow = '';
}

function handleViewOverlayClick(e) {
    if (e.target.id === 'view-product-modal-overlay') closeViewModal();
}

function showProductStatusModal(event, form) {
    if (event) event.preventDefault();
    var action = form.getAttribute('data-action') || 'proceed';
    var prodName = form.getAttribute('data-product-name') || 'this product';
    _productStatusForm = form;
    var btn = form.querySelector('button[type="submit"]');
    _productStatusButtonName = btn ? btn.getAttribute('name') : null;

    if (typeof window.closeArchiveModal === 'function') window.closeArchiveModal();
    closeProductModal();
    closeViewModal();

    var titleEl = document.getElementById('productStatusConfirmTitle');
    var textEl = document.getElementById('productStatusConfirmText');
    var infoEl = document.getElementById('productStatusInfoText');
    if (titleEl) titleEl.textContent = 'Confirm ' + action;
    if (textEl) {
        textEl.innerHTML = 'Are you sure you want to <strong>' + action.toLowerCase() + '</strong> <strong style="color:#111827;">' + String(prodName).replace(/</g, '&lt;') + '</strong>?';
    }
    var msg = '';
    if (action === 'Deactivate') msg = 'This product will be hidden from customers until activated again.';
    else if (action === 'Activate') msg = 'This product will be visible to customers again.';
    else if (action === 'Archive') msg = 'The product moves to archive storage; you can restore it later.';
    else if (action === 'Restore') msg = 'The product returns to the main list as Active.';
    else if (action === 'Delete Permanently') msg = 'This cannot be undone.';
    if (infoEl) infoEl.textContent = msg;

    var m = document.getElementById('productStatusConfirmModal');
    if (m) {
        m.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeProductStatusModal() {
    var m = document.getElementById('productStatusConfirmModal');
    if (m) m.style.display = 'none';
    document.body.style.overflow = '';
    _productStatusForm = null;
    _productStatusButtonName = null;
}

document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    var archiveOv = document.getElementById('archive-storage-overlay');
    if (archiveOv && archiveOv.style.display === 'flex') {
        if (typeof window.closeArchiveModal === 'function') window.closeArchiveModal();
        return;
    }
    var statusM = document.getElementById('productStatusConfirmModal');
    if (statusM && statusM.style.display === 'flex') {
        closeProductStatusModal();
        return;
    }
    closeProductModal();
    closeViewModal();
});

// Archive Modal Functions (on window for Turbo + inline onclick)
window.openArchiveModal = function openArchiveModal() {
    var el = document.getElementById('archive-storage-overlay');
    if (!el) return;
    el.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    fetchArchivedProducts();
};

window.closeArchiveModal = function closeArchiveModal() {
    var el = document.getElementById('archive-storage-overlay');
    if (el) el.style.display = 'none';
    document.body.style.overflow = '';
};

function fetchArchivedProducts() {
    const container = document.getElementById('archived-products-container');
    fetch('?get_archived=1')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                container.innerHTML = data.html;
            } else {
                container.innerHTML = '<div style="padding:40px;text-align:center;color:#ef4444;">Failed to load archived products.</div>';
            }
        })
        .catch(err => {
            console.error(err);
            container.innerHTML = '<div style="padding:40px;text-align:center;color:#ef4444;">Error loading archived products.</div>';
        });
}

function printflowInitProductsPage() {
    /* Alpine tree: Alpine.start / turbo-init initTree(.main-content). Never initTree nested #productsTableContainer or filterPanel here. */
    // Real-time filters (idempotent)
    ['fp_date_from','fp_date_to','fp_category','fp_status','fp_stock_status'].forEach(id => {
        const el = document.getElementById(id);
        if (el && !el._pf_bound) {
            el._pf_bound = true;
            el.addEventListener('change', () => fetchUpdatedTable());
        }
    });

    const searchInput = document.getElementById('fp_search');
    if (searchInput && !searchInput._pf_bound) {
        searchInput._pf_bound = true;
        searchInput.addEventListener('input', () => {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => fetchUpdatedTable(), 500);
        });
    }

    // Auto-generate SKU based on category (idempotent)
    var categorySelect = document.getElementById('modal-category');
    if (categorySelect && !categorySelect._pf_sku_bound) {
        categorySelect._pf_sku_bound = true;
        categorySelect.addEventListener('change', async function() {
            var skuInput = document.getElementById('modal-sku');
            var modeInput = document.getElementById('modal-mode-input');
            if (!skuInput || !modeInput) return;
            
            // Only auto-generate for create mode
            if (modeInput.name !== 'create_product') return;
            
            var category = this.value;
            if (!category || category === '-- Select Category --') {
                skuInput.value = '';
                return;
            }
            
            // Generate SKU prefix from category
            var prefix = '';
            switch(category) {
                case 'T-Shirt': prefix = 'TSH'; break;
                case 'Stickers': prefix = 'STK'; break;
                case 'Sintraboard': prefix = 'SNB'; break;
                case 'Signage': prefix = 'SGN'; break;
                case 'Merchandise': prefix = 'MER'; break;
                case 'General': prefix = 'GEN'; break;
                default: prefix = 'PROD'; break;
            }
            
            // Fetch next available number from server
            try {
                const response = await fetch('?get_next_sku=' + encodeURIComponent(prefix));
                const data = await response.json();
                if (data.success && data.sku) {
                    skuInput.value = data.sku;
                } else {
                    // Fallback to prefix + 0001
                    skuInput.value = prefix + '0001';
                }
            } catch (err) {
                console.error('Failed to generate SKU:', err);
                skuInput.value = prefix + '0001';
            }
        });
    }

    // Description textarea: limit newlines to 5 (idempotent)
    var descTextarea = document.getElementById('modal-description');
    if (descTextarea && !descTextarea._pf_newline_bound) {
        descTextarea._pf_newline_bound = true;
        descTextarea.addEventListener('input', function(e) {
            var text = this.value;
            var newlineCount = (text.match(/\n/g) || []).length;
            if (newlineCount > 5) {
                // Remove excess newlines
                var lines = text.split('\n');
                this.value = lines.slice(0, 6).join('\n'); // 6 lines = 5 newlines
                // Move cursor to end
                this.setSelectionRange(this.value.length, this.value.length);
            }
        });
        descTextarea.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                var text = this.value;
                var newlineCount = (text.match(/\n/g) || []).length;
                if (newlineCount >= 5) {
                    e.preventDefault();
                    return false;
                }
            }
        });
    }

    // Photo preview (idempotent)
    var photoInput = document.getElementById('modal-photo');
    if (photoInput && !photoInput._pf_bound) {
        photoInput._pf_bound = true;
        photoInput.addEventListener('change', function(e) {
            var file = e.target.files[0];
            if (file) {
                if (file.size > 5 * 1024 * 1024) { alert('File size must be less than 5MB'); this.value = ''; return; }
                var allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!allowedTypes.includes(file.type)) { alert('Please select a valid image file (JPG, PNG, or GIF)'); this.value = ''; return; }
                var reader = new FileReader();
                reader.onload = function(event) {
                    var previewImg = document.getElementById('photo-preview-img');
                    var previewText = document.getElementById('photo-preview-text');
                    previewImg.src = event.target.result;
                    previewImg.style.display = 'block';
                    previewText.style.display = 'none';
                };
                reader.readAsDataURL(file);
            }
        });
    }

    // Status modal buttons (idempotent)
    var cancelBtn = document.getElementById('productStatusConfirmCancel');
    var okBtn = document.getElementById('productStatusConfirmOk');
    var modal = document.getElementById('productStatusConfirmModal');
    if (cancelBtn && !cancelBtn._pf_bound) {
        cancelBtn._pf_bound = true;
        cancelBtn.addEventListener('click', closeProductStatusModal);
    }
    if (okBtn && !okBtn._pf_bound) {
        okBtn._pf_bound = true;
        okBtn.addEventListener('click', function() {
            if (_productStatusForm) {
                if (_productStatusButtonName) {
                    var hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = _productStatusButtonName;
                    hiddenInput.value = '1';
                    _productStatusForm.appendChild(hiddenInput);
                }
                _productStatusForm.submit();
            }
            closeProductStatusModal();
        });
    }
    if (modal && !modal._pf_bound) {
        modal._pf_bound = true;
        modal.addEventListener('click', function(e) { if (e.target === modal) closeProductStatusModal(); });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', printflowInitProductsPage);
} else {
    printflowInitProductsPage();
}
document.addEventListener('printflow:page-init', printflowInitProductsPage);
</script>
<script src="<?php echo $base_path; ?>/public/assets/js/product-form-validation.js"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
