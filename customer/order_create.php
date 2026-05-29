<?php
/**
 * Fixed Product Order Page
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_order_helper.php';
require_once __DIR__ . '/../includes/product_branch_stock.php';
require_once __DIR__ . '/../includes/product_field_config_helper.php';

require_role('Customer');
require_once __DIR__ . '/../includes/require_customer_profile_complete.php';
require_once __DIR__ . '/../includes/require_id_verified.php';

function order_create_optional_query($sql, $types = '', $params = []) {
    try {
        return db_query($sql, $types, $params) ?: [];
    } catch (Throwable $e) {
        error_log('order_create optional query failed: ' . $e->getMessage());
        return [];
    }
}

function order_create_optional_execute($sql, $types = '', $params = []) {
    try {
        return db_execute($sql, $types, $params);
    } catch (Throwable $e) {
        error_log('order_create optional execute failed: ' . $e->getMessage());
        return false;
    }
}

function printflow_product_option_price_map(array $options): array
{
    $map = [];
    foreach ($options as $option) {
        if (is_array($option)) {
            $value = trim((string)($option['value'] ?? ''));
            $price = (float)($option['price'] ?? 0);
        } else {
            $value = trim((string)$option);
            $price = 0.0;
        }
        if ($value !== '') {
            $map[$value] = max(0, $price);
        }
    }
    return $map;
}

function printflow_render_product_custom_field(string $field_key, array $config, array $existing_data = []): string
{
    if (empty($config['visible'])) {
        return '';
    }

    $label = trim((string)($config['label'] ?? $field_key));
    $type = trim((string)($config['type'] ?? 'textarea'));
    $required = !empty($config['required']);
    $required_mark = $required ? ' *' : '';
    $required_attr = $required ? 'required' : '';
    $saved = $existing_data['customization'][$label] ?? $existing_data['customization'][$field_key] ?? '';
    $saved = is_string($saved) ? $saved : '';
    $name = htmlspecialchars($field_key, ENT_QUOTES, 'UTF-8');
    $html = '<div class="shopee-form-row">';
    $html .= '<div class="shopee-form-label">' . htmlspecialchars($label) . $required_mark . '</div>';
    $html .= '<div class="shopee-form-field">';

    if ($type === 'select') {
        $html .= '<select name="' . $name . '" class="shopee-opt-btn pricing-field" ' . $required_attr . ' style="width: 220px; cursor: pointer;">';
        $html .= '<option value="">Select ' . htmlspecialchars($label) . '</option>';
        foreach ((array)($config['options'] ?? []) as $option) {
            $value = is_array($option) ? (string)($option['value'] ?? '') : (string)$option;
            $price = is_array($option) ? (float)($option['price'] ?? 0) : 0.0;
            if ($value === '') {
                continue;
            }
            $selected = $saved === $value ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" data-price="' . htmlspecialchars((string)$price, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($value) . '</option>';
        }
        $html .= '</select>';
    } elseif ($type === 'radio') {
        $othersEnabled = !array_key_exists('allow_others', $config) || !empty($config['allow_others']);
        $html .= '<div class="shopee-opt-group">';
        foreach ((array)($config['options'] ?? []) as $option) {
            $value = is_array($option) ? (string)($option['value'] ?? '') : (string)$option;
            $price = is_array($option) ? (float)($option['price'] ?? 0) : 0.0;
            if ($value === '') {
                continue;
            }
            $checked = $saved === $value ? ' checked' : '';
            $active = $saved === $value ? ' active' : '';
            $html .= '<label class="shopee-opt-btn' . $active . '">';
            $html .= '<input type="radio" name="' . $name . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" data-price="' . htmlspecialchars((string)$price, ENT_QUOTES, 'UTF-8') . '" style="display:none;" class="pricing-field"' . $checked . ' ' . $required_attr . '>';
            $html .= '<span>' . htmlspecialchars($value) . '</span></label>';
        }
        if ($othersEnabled) {
            $checked = ($saved !== '' && !isset(printflow_product_option_price_map((array)($config['options'] ?? []))[$saved])) ? ' checked' : '';
            $active = $checked !== '' ? ' active' : '';
            $html .= '<label class="shopee-opt-btn' . $active . '">';
            $html .= '<input type="radio" name="' . $name . '" value="__other__" style="display:none;" class="pricing-field"' . $checked . ' ' . $required_attr . '><span>Others</span></label>';
        }
        $html .= '</div>';
        if ($othersEnabled) {
            $otherValue = ($saved !== '' && !isset(printflow_product_option_price_map((array)($config['options'] ?? []))[$saved])) ? $saved : '';
            $display = $otherValue !== '' ? 'block' : 'none';
            $html .= '<div id="other-wrap-' . $name . '" style="display:' . $display . ';margin-top:12px;">';
            $html .= '<input type="text" name="' . $name . '_other" value="' . htmlspecialchars($otherValue, ENT_QUOTES, 'UTF-8') . '" class="field-input" placeholder="Please specify..." style="max-width:400px;">';
            $html .= '</div>';
        }
    } elseif ($type === 'dimension') {
        $valueMap = printflow_product_option_price_map((array)($config['options'] ?? []));
        $savedBase = preg_replace('/\s+(ft|in|cm)$/i', '', $saved);
        $savedIsPreset = isset($valueMap[(string)$savedBase]);
        $html .= '<div class="shopee-opt-group">';
        foreach ((array)($config['options'] ?? []) as $option) {
            $value = is_array($option) ? (string)($option['value'] ?? '') : (string)$option;
            $price = is_array($option) ? (float)($option['price'] ?? 0) : 0.0;
            if ($value === '') {
                continue;
            }
            $active = $savedBase === $value ? ' active' : '';
            $html .= '<button type="button" class="shopee-opt-btn pricing-dimension' . $active . '" data-target="' . $name . '" data-value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" data-price="' . htmlspecialchars((string)$price, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($value) . '</button>';
        }
        if (!array_key_exists('allow_others', $config) || !empty($config['allow_others'])) {
            $active = (!$savedIsPreset && $savedBase !== '') ? ' active' : '';
            $html .= '<button type="button" class="shopee-opt-btn pricing-dimension-other' . $active . '" data-target="' . $name . '">Others</button>';
        }
        $html .= '</div>';
        $html .= '<input type="hidden" name="' . $name . '" id="hidden-' . $name . '" value="' . htmlspecialchars($savedBase, ENT_QUOTES, 'UTF-8') . '" ' . $required_attr . '>';
        if (!array_key_exists('allow_others', $config) || !empty($config['allow_others'])) {
            $parts = preg_split('/[xX×]/', (string)$savedBase);
            $showCustom = !$savedIsPreset && $savedBase !== '';
            $html .= '<div id="custom-dim-' . $name . '" style="display:' . ($showCustom ? 'flex' : 'none') . ';gap:12px;max-width:320px;margin-top:12px;">';
            $html .= '<input type="text" id="width-' . $name . '" class="field-input dim-width" placeholder="Width" value="' . htmlspecialchars(trim((string)($parts[0] ?? '')), ENT_QUOTES, 'UTF-8') . '">';
            $html .= '<input type="text" id="height-' . $name . '" class="field-input dim-height" placeholder="Height" value="' . htmlspecialchars(trim((string)($parts[1] ?? '')), ENT_QUOTES, 'UTF-8') . '">';
            $html .= '</div>';
        }
    } elseif ($type === 'file') {
        $html .= '<input type="file" name="' . $name . '" class="field-input" ' . $required_attr . ' style="max-width:420px;">';
        if ($saved !== '') {
            $html .= '<div style="font-size:12px;color:#64748b;margin-top:8px;">Current file: ' . htmlspecialchars($saved) . '</div>';
        }
    } else {
        $html .= '<textarea name="' . $name . '" class="field-input" rows="4" ' . $required_attr . ' placeholder="Enter ' . htmlspecialchars($label) . '">' . htmlspecialchars($saved) . '</textarea>';
    }

    $html .= '</div></div>';
    return $html;
}

$product_id = (int)($_GET['product_id'] ?? 0);
$edit_item_key = $_GET['edit_item'] ?? '';

// Load existing cart data if editing
$existing_data = [];
if ($edit_item_key && isset($_SESSION['cart'][$edit_item_key])) {
    $existing_data = $_SESSION['cart'][$edit_item_key];
}

if ($product_id < 1) { header('Location: products.php'); exit; }

$product = db_query(
    "SELECT * FROM products WHERE product_id = ? AND status = 'Activated'",
    'i', [$product_id]
);
if (empty($product)) { header('Location: products.php'); exit; }
$product = $product[0];
$product_field_configs = get_product_field_config($product_id);
$main_branch_id = function_exists('printflow_get_default_admin_branch_id')
    ? (int)printflow_get_default_admin_branch_id()
    : 1;

$error = '';
$branches = order_create_optional_query("SELECT id, branch_name FROM branches WHERE status = 'Active'") ?: [];
$branch_stock_map = [];
foreach ($branches as $branch_row) {
    $bid = (int)($branch_row['id'] ?? 0);
    if ($bid <= 0) {
        continue;
    }
    [$branch_stock_qty] = printflow_product_effective_stock($product_id, $bid);
    $branch_stock_map[$bid] = [
        'stock' => (int)$branch_stock_qty,
        'name' => (string)($branch_row['branch_name'] ?? ('Branch #' . $bid)),
    ];
}
$main_branch_stock = $branch_stock_map[$main_branch_id]['stock'] ?? 0;
$initial_branch_id = (int)($existing_data['branch_id'] ?? $_POST['branch_id'] ?? 0);
$initial_stock_branch_id = $initial_branch_id > 0 ? $initial_branch_id : $main_branch_id;
$initial_stock_qty = $branch_stock_map[$initial_stock_branch_id]['stock'] ?? $main_branch_stock;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $branch_id  = (int)($_POST['branch_id'] ?? 0);
    $quantity   = max(1, min(999, (int)($_POST['quantity'] ?? 1)));
    $needed_date = trim($_POST['needed_date'] ?? '');
    $notes      = trim($_POST['notes'] ?? '');
    [$branch_stock_qty] = printflow_product_effective_stock($product_id, $branch_id);

    $customization = [];
    $uploaded_files = [];
    $price_delta = 0.0;

    foreach ($product_field_configs as $field_key => $config) {
        if (empty($config['visible'])) {
            continue;
        }

        $label = trim((string)($config['label'] ?? $field_key));
        $type = trim((string)($config['type'] ?? 'textarea'));
        $required_field = !empty($config['required']);
        $value = '';

        if ($type === 'file') {
            $file = $_FILES[$field_key] ?? null;
            if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $valid = service_order_validate_file($file);
                if (!$valid['ok']) {
                    $error = $valid['error'];
                    break;
                }
                $tmpDir = service_order_temp_dir();
                $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
                $target = $tmpDir . DIRECTORY_SEPARATOR . 'product_field_' . $product_id . '_' . $field_key . '_' . uniqid('', true) . '.' . $ext;
                if (!move_uploaded_file($file['tmp_name'], $target)) {
                    $error = 'Failed to keep uploaded file for checkout.';
                    break;
                }
                $value = trim((string)$file['name']);
                $uploaded_files[] = [
                    'field_key' => $field_key,
                    'label' => $label,
                    'tmp_path' => $target,
                    'name' => $value,
                    'mime' => (string)$valid['mime'],
                ];
            } elseif ($required_field) {
                $error = $label . ' is required.';
                break;
            }
        } elseif ($type === 'radio') {
            $raw = trim((string)($_POST[$field_key] ?? ''));
            if ($raw === '__other__') {
                $raw = trim((string)($_POST[$field_key . '_other'] ?? ''));
            }
            $value = $raw;
            $priceMap = printflow_product_option_price_map((array)($config['options'] ?? []));
            if ($value !== '' && isset($priceMap[$value])) {
                $price_delta += (float)$priceMap[$value];
            }
        } elseif ($type === 'select') {
            $value = trim((string)($_POST[$field_key] ?? ''));
            $priceMap = printflow_product_option_price_map((array)($config['options'] ?? []));
            if ($value !== '' && isset($priceMap[$value])) {
                $price_delta += (float)$priceMap[$value];
            }
        } elseif ($type === 'dimension') {
            $value = trim((string)($_POST[$field_key] ?? ''));
            $priceMap = printflow_product_option_price_map((array)($config['options'] ?? []));
            if ($value !== '' && isset($priceMap[$value])) {
                $price_delta += (float)$priceMap[$value];
            }
            if ($value !== '' && !empty($config['unit'])) {
                $value .= ' ' . trim((string)$config['unit']);
            }
        } else {
            $value = trim((string)($_POST[$field_key] ?? ''));
        }

        if ($required_field && $value === '') {
            $error = $label . ' is required.';
            break;
        }
        if ($value !== '') {
            $customization[$label] = $value;
        }
    }

    if (false) {
        $error = 'Please select a branch.';
    } elseif ($quantity > (int)$branch_stock_qty) {
        $error = 'Quantity exceeds available stock.';
    } elseif ((int)$branch_stock_qty <= 0) {
        $error = 'This product is currently out of stock.';
    } else {
        $item_key = 'product_' . $product_id . '_' . time() . '_' . rand(100, 999);

        if (empty($error)) {
            $_SESSION['cart'][$item_key] = [
                'type'            => 'Product',
                'source_page'     => 'products',
                'product_id'      => $product_id,
                'name'            => $product['name'],
                'price'           => (float)$product['price'] + $price_delta,
                'quantity'        => $quantity,
                'category'        => $product['category'],
                'branch_id'       => $branch_id,
                'design_tmp_path' => null,
                'design_name'     => null,
                'design_mime'     => null,
                'uploaded_files'  => $uploaded_files,
                'customization'   => $customization,
            ];

            if (($_POST['action'] ?? '') === 'buy_now') {
                redirect('order_review.php?item=' . urlencode($item_key));
            } else {
                redirect('cart.php');
            }
        }
    }
}

// Product image
$display_img = $product['photo_path'] ?: $product['product_image'] ?: '';
if ($display_img && strpos($display_img, 'http') === false && $display_img[0] !== '/') {
    $display_img = '/' . ltrim($display_img, '/');
}
if (!$display_img) {
    $display_img = 'https://placehold.co/600x600/f8fafc/0f172a?text=' . urlencode($product['name']);
}

// Ratings
if (function_exists('ensure_ratings_table_exists')) {
    try {
        ensure_ratings_table_exists();
    } catch (Throwable $e) {
        error_log('order_create ratings schema ensure failed: ' . $e->getMessage());
    }
}

// Ensure review_helpful table exists
order_create_optional_execute("CREATE TABLE IF NOT EXISTS review_helpful (
    id INT AUTO_INCREMENT PRIMARY KEY,
    review_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_review_user (review_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$review_helpful_columns = array_flip(array_column(order_create_optional_query("SHOW COLUMNS FROM review_helpful") ?: [], 'Field'));
if (!isset($review_helpful_columns['customer_id'])) {
    order_create_optional_execute("ALTER TABLE review_helpful ADD COLUMN customer_id INT NULL AFTER user_id");
    $review_helpful_columns['customer_id'] = true;
}
if (!isset($review_helpful_columns['user_type'])) {
    order_create_optional_execute("ALTER TABLE review_helpful ADD COLUMN user_type VARCHAR(20) NULL AFTER customer_id");
    $review_helpful_columns['user_type'] = true;
}

$current_user_id = get_user_id();
$current_user_type = (string)($_SESSION['user_type'] ?? '');
$current_customer_id = $current_user_type === 'Customer' ? $current_user_id : 0;
$review_user_voted_sql = "(SELECT COUNT(*)
        FROM review_helpful rh
        WHERE rh.review_id = r.id
          AND rh.user_id = ?
          AND COALESCE(rh.user_type, ?) = ?)";
$review_user_voted_types = 'iss';
$review_user_voted_params = [$current_user_id, $current_user_type !== '' ? $current_user_type : 'Customer', $current_user_type !== '' ? $current_user_type : 'Customer'];
if ($current_customer_id > 0 && isset($review_helpful_columns['customer_id'], $review_helpful_columns['user_type'])) {
    $review_user_voted_sql = "(SELECT COUNT(*)
            FROM review_helpful rh
            WHERE rh.review_id = r.id
              AND (
                    (rh.customer_id = ? AND COALESCE(rh.user_type, 'Customer') = 'Customer')
                    OR (rh.customer_id IS NULL AND rh.user_id = ? AND COALESCE(rh.user_type, 'Customer') = 'Customer')
              ))";
    $review_user_voted_types = 'ii';
    $review_user_voted_params = [$current_customer_id, $current_user_id];
}
$review_columns = array_flip(array_column(order_create_optional_query("SHOW COLUMNS FROM reviews") ?: [], 'Field'));
$review_customer_expr = isset($review_columns['customer_id']) ? 'r.customer_id' : (isset($review_columns['user_id']) ? 'r.user_id' : '0');
$review_comment_expr = isset($review_columns['comment']) ? 'r.comment' : (isset($review_columns['message']) ? 'r.message' : "''");
$review_service_expr = isset($review_columns['service_type']) ? 'r.service_type' : "''";
$review_video_expr = isset($review_columns['video_path']) ? 'r.video_path' : "''";
$review_created_expr = isset($review_columns['created_at']) ? 'r.created_at' : 'NOW()';
$review_order_expr = isset($review_columns['order_id']) ? 'r.order_id' : 'NULL';
$review_ref_expr = isset($review_columns['reference_id']) ? 'r.reference_id' : 'NULL';
$review_type_expr = isset($review_columns['review_type']) ? 'r.review_type' : "''";

$review_where_parts = [];
$review_params = [];
$review_types = '';
if (isset($review_columns['reference_id']) && isset($review_columns['review_type'])) {
    $review_where_parts[] = "({$review_type_expr} = 'product' AND {$review_ref_expr} = ?)";
    $review_params[] = $product_id;
    $review_types .= 'i';
}
if (isset($review_columns['order_id'])) {
    $review_where_parts[] = "EXISTS (
        SELECT 1
        FROM order_items oi
        WHERE oi.order_id = {$review_order_expr}
          AND oi.product_id = ?
    )";
    $review_params[] = $product_id;
    $review_types .= 'i';
}
$review_where_parts[] = "{$review_service_expr} COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci";
$review_params[] = $product['name'];
$review_types .= 's';
$review_where = '(' . implode(' OR ', $review_where_parts) . ')';

$reviews = order_create_optional_query(
    "SELECT
     r.id,
     {$review_order_expr} AS order_id,
     {$review_customer_expr} AS user_id,
     {$review_service_expr} AS service_type,
     r.rating,
     {$review_comment_expr} AS comment,
     {$review_video_expr} AS video_path,
     {$review_created_expr} AS created_at,
     c.first_name,
     c.last_name,
     c.profile_picture,
     (SELECT COUNT(*) FROM review_helpful WHERE review_id = r.id) as helpful_count,
     {$review_user_voted_sql} as user_voted
     FROM reviews r
     LEFT JOIN customers c ON {$review_customer_expr} = c.customer_id
     WHERE {$review_where}
     ORDER BY {$review_created_expr} DESC",
    $review_user_voted_types . $review_types,
    array_merge($review_user_voted_params, $review_params)
) ?: [];

$total_reviews = count($reviews);
$avg_rating = $total_reviews > 0 ? array_sum(array_column($reviews, 'rating')) / $total_reviews : 0;
$rating_counts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$with_comments = 0; $with_media = 0;
foreach ($reviews as $idx => $r) {
    $rt = (int)$r['rating'];
    if ($rt >= 1 && $rt <= 5) $rating_counts[$rt]++;
    if (!empty(trim($r['comment'] ?? ''))) $with_comments++;
    
    // Fetch all images for this review
    $r_imgs = order_create_optional_query("SELECT image_path FROM review_images WHERE review_id = ?", "i", [$r['id']]) ?: [];
    
    // Fetch all replies for this review
    $r_replies = order_create_optional_query("
        SELECT rr.reply_message, rr.created_at, u.first_name, u.last_name
        FROM review_replies rr
        INNER JOIN users u ON u.user_id = rr.staff_id
        WHERE rr.review_id = ?
        ORDER BY rr.created_at ASC
    ", 'i', [$r['id']]) ?: [];

    $reviews[$idx]['images'] = $r_imgs;
    $reviews[$idx]['replies'] = $r_replies;
    $reviews[$idx]['has_video'] = !empty($r['video_path']);
    
    if (!empty($r_imgs) || !empty($r['video_path'])) $with_media++;
}

$sold_count = printflow_product_units_sold((int)$product_id);
$sold_display = $sold_count >= 1000 ? number_format($sold_count / 1000, 1) . 'k' : $sold_count;

$page_title = 'Order ' . $product['name'] . ' - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="min-h-screen py-8 bg-white">
    <div class="shopee-layout-container">
        <div class="text-sm text-gray-500 mb-6 flex items-center gap-2">
            <a href="products.php" class="hover:text-blue-600">Products</a>
            <span>/</span>
            <span class="font-semibold text-gray-900"><?php echo htmlspecialchars($product['name']); ?></span>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="shopee-card">
            <div class="shopee-image-section">
                <div class="sticky top-24">
                    <div class="shopee-main-image-wrap">
                        <button type="button" class="poc-media-trigger" data-media-type="image" data-media-src="<?php echo htmlspecialchars($display_img); ?>" aria-label="View product image">
                            <img src="<?php echo htmlspecialchars($display_img); ?>"
                                 alt="<?php echo htmlspecialchars($product['name']); ?>"
                                 class="shopee-main-image"
                                 onerror="this.src='https://placehold.co/600x600/f8fafc/0f172a?text=Product'">
                        </button>
                    </div>
                    <?php if (!empty($product['description'])): ?>
                        <div style="margin-top:20px;padding:16px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;">
                            <h3 style="font-size:14px;font-weight:700;color:#374151;margin-bottom:8px;text-transform:uppercase;letter-spacing:0.5px;">Description</h3>
                            <p style="font-size:14px;line-height:1.6;color:#64748b;white-space:pre-wrap;"><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="shopee-form-section">
                <h1 class="text-2xl font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($product['name']); ?></h1>

                <div class="flex items-center gap-4 mb-6 pb-6 border-b border-gray-100">
                    <div class="flex items-center gap-1">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <svg class="w-4 h-4" style="fill:<?php echo ($i <= round($avg_rating)) ? '#FBBF24' : '#E2E8F0'; ?>;" viewBox="0 0 20 20">
                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                            </svg>
                        <?php endfor; ?>
                        <?php if ($total_reviews > 0): ?>
                            <span class="text-sm text-gray-500 ml-1">(<?php echo number_format($total_reviews); ?> Reviews)</span>
                        <?php endif; ?>
                    </div>
                    <div class="h-4 w-px bg-gray-200"></div>
                    <div class="text-sm text-gray-500"><?php echo $sold_display; ?> Sold</div>
                    <div class="h-4 w-px bg-gray-200"></div>
                    <div class="text-lg font-bold text-gray-900"><?php echo format_currency($product['price']); ?></div>
                </div>

                <form action="" method="POST" enctype="multipart/form-data" id="productOrderForm" data-pf-skip-validation="true" novalidate>
                    <?php echo csrf_field(); ?>

                    <div class="shopee-form-row">
                        <div class="shopee-form-label">Branch *</div>
                        <div class="shopee-form-field">
                            <select name="branch_id" id="poc-branch-select" class="shopee-opt-btn" required style="width: 175px; cursor: pointer;">
                                <?php 
                                $saved_branch = $existing_data['branch_id'] ?? ($_POST['branch_id'] ?? '');
                                foreach ($branches as $b): 
                                    $selected = ($saved_branch == $b['id']) ? ' selected' : '';
                                ?>
                                    <option value="<?php echo $b['id']; ?>"<?php echo $selected; ?>><?php echo htmlspecialchars($b['branch_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div id="poc-stock-display" style="font-size: 0.8rem; color: #475569; margin-top: 0.5rem; font-weight: 600;">
                                Available stock:
                                <span id="poc-stock-count"><?php echo number_format($initial_stock_qty); ?></span>
                                <span id="poc-stock-branch" style="color: #64748b;">
                                    (<?php echo htmlspecialchars($branch_stock_map[$initial_stock_branch_id]['name'] ?? 'Cabuyao Branch'); ?>)
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="shopee-form-row">
                        <div class="shopee-form-label">Quantity *</div>
                        <div class="shopee-form-field">
                            <div class="shopee-opt-group">
                                <div class="quantity-container shopee-opt-btn" style="display: inline-flex; justify-content: space-between; gap: 1rem; width: 175px; cursor: default;">
                                    <button type="button" id="poc-qty-minus" style="background: none; border: none; color: #6b7280; font-size: 1.125rem; font-weight: 600; cursor: pointer; padding: 0; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center;" onclick="const i=document.getElementById('poc-qty');if(parseInt(i.value)>1){i.value=parseInt(i.value)-1;hideStockWarning();}">&minus;</button>
                                    <input type="number" id="poc-qty" name="quantity" class="qty-input-field" style="border: none; text-align: center; width: 60px; font-size: 0.875rem; font-weight: 500; color: #374151; background: transparent; outline: none; -moz-appearance: textfield;" min="1" max="<?php echo (int)$initial_stock_qty; ?>" value="<?php echo (int)($existing_data['quantity'] ?? $_POST['quantity'] ?? $_GET['qty'] ?? 1); ?>" onwheel="return false;">
                                    <button type="button" id="poc-qty-plus" style="background: none; border: none; color: #6b7280; font-size: 1.125rem; font-weight: 600; cursor: pointer; padding: 0; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center;" onclick="window.pfIncreaseQty && window.pfIncreaseQty()">+</button>
                                </div>
                            </div>
                            <div id="stock-warning" style="display: none; font-size: 0.75rem; color: #dc2626; margin-top: 0.5rem; font-weight: 600;"></div>
                        </div>
                    </div>

                    <?php foreach ($product_field_configs as $field_key => $config): ?>
                        <?php echo printflow_render_product_custom_field((string)$field_key, $config, $existing_data); ?>
                    <?php endforeach; ?>

                    <div class="shopee-form-row pt-8">
                        <div style="width: 130px;"></div>
                        <div class="flex gap-4 flex-1">
                            <a href="products.php" class="shopee-btn-outline" style="flex: 1; min-width: 0;">Back</a>
                            <?php if ((int)$initial_stock_qty > 0): ?>
                                <button type="submit" name="action" value="add_to_cart" id="poc-add-cart-btn" class="shopee-btn-outline" style="flex: 1.2; min-width: 140px; display: flex; align-items: center; justify-content: center; gap: 0.5rem; white-space: nowrap; padding: 0.5rem 1.25rem;" title="Add to Cart">
                                    <svg style="width: 1.125rem; height: 1.125rem; flex-shrink: 0;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                    </svg>
                                    <span>Add to Cart</span>
                                </button>
                                <button type="submit" name="action" value="buy_now" id="poc-buy-now-btn" class="shopee-btn-primary" style="flex: 1; min-width: 0; white-space: nowrap; display: flex; align-items: center; justify-content: center; padding: 0.5rem 1.25rem;">
                                    <span>Order Now</span>
                                </button>
                            <?php else: ?>
                                <button type="button" disabled id="poc-add-cart-btn" class="shopee-btn-outline" style="flex: 1.2; min-width: 140px; opacity: 0.5; cursor: not-allowed; padding: 0.5rem 1.25rem;">Out of Stock</button>
                                <button type="button" disabled id="poc-buy-now-btn" class="shopee-btn-primary" style="flex: 1; min-width: 0; opacity: 0.5; cursor: not-allowed; padding: 0.5rem 1.25rem;">Out of Stock</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Product Ratings Section -->
        <?php
        $reviews_per_page = 10;
        $poc_page = max(1, (int)($_GET['rpage'] ?? 1));
        $poc_total_pages = $total_reviews > 0 ? (int)ceil($total_reviews / $reviews_per_page) : 1;
        $poc_page = min($poc_page, $poc_total_pages);
        $poc_offset = ($poc_page - 1) * $reviews_per_page;
        $reviews_paged = array_slice($reviews, $poc_offset, $reviews_per_page);
        ?>
        <div style="margin-top:24px;padding:1.5rem 2rem;background:#fff;border:1px solid #e5e7eb;border-radius:4px;">
            <h2 class="poc-section-title">Product Ratings</h2>

            <?php if ($total_reviews > 0): ?>
            <!-- Rating summary box -->
            <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem;">
                <div style="display: flex; gap: 2rem; align-items: center; flex-wrap: wrap;">
                    <div style="text-align: center;">
                        <div style="font-size: 3rem; font-weight: 700; color: #f97316; line-height: 1;"><?php echo number_format($avg_rating, 1); ?></div>
                        <div style="font-size: 0.875rem; color: #6b7280; margin-top: 0.25rem;">out of 5</div>
                        <div style="display: flex; gap: 2px; margin-top: 0.5rem; justify-content: center;">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <svg width="22" height="22" fill="<?php echo ($i <= round($avg_rating)) ? '#f97316' : '#d1d5db'; ?>" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                </svg>
                            <?php endfor; ?>
                        </div>
                    </div>
                    
                    <div style="flex: 1; min-width: 300px;">
                        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                            <button class="poc-filter-btn active" data-filter="all" style="padding: 0.5rem 1rem; border: 1px solid #e5e7eb; border-radius: 6px; background: white; cursor: pointer; font-size: 0.875rem; transition: all 0.2s;">All</button>
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <button class="poc-filter-btn" data-filter="<?php echo $i; ?>" style="padding: 0.5rem 1rem; border: 1px solid #e5e7eb; border-radius: 6px; background: white; cursor: pointer; font-size: 0.875rem; transition: all 0.2s;"><?php echo $i; ?> Star (<?php echo $rating_counts[$i]; ?>)</button>
                            <?php endfor; ?>
                            <button class="poc-filter-btn" data-filter="comments" style="padding: 0.5rem 1rem; border: 1px solid #e5e7eb; border-radius: 6px; background: white; cursor: pointer; font-size: 0.875rem; transition: all 0.2s;">With Comments (<?php echo $with_comments; ?>)</button>
                            <button class="poc-filter-btn" data-filter="media" style="padding: 0.5rem 1rem; border: 1px solid #e5e7eb; border-radius: 6px; background: white; cursor: pointer; font-size: 0.875rem; transition: all 0.2s;">With Media (<?php echo $with_media; ?>)</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Review list -->
            <div id="poc-reviews-container">
                <?php foreach ($reviews_paged as $review):
                    $reviewer_name = htmlspecialchars(trim(($review['first_name'] ?? '') . ' ' . ($review['last_name'] ?? '')));
                    $profile_pic = !empty($review['profile_picture'])
                        ? get_profile_image($review['profile_picture'])
                        : '';
                    $profile_pic_fallback = rtrim(defined('BASE_PATH') ? BASE_PATH : '', '/') . '/public/assets/uploads/profiles/default.png';
                    $rating      = (int)$review['rating'];
                    $comment     = htmlspecialchars($review['comment'] ?? '');
                    $variation   = htmlspecialchars($review['variation'] ?? '');
                    $has_comment = !empty(trim($review['comment'] ?? ''));
                    $rev_imgs    = $review['images'] ?? [];
                    $has_video   = !empty($review['video_path']);
                    $has_media   = !empty($rev_imgs) || $has_video;
                ?>
                <div id="review-<?php echo $review['id']; ?>" class="poc-review-item" data-rating="<?php echo $rating; ?>" data-has-comment="<?php echo $has_comment ? '1' : '0'; ?>" data-has-media="<?php echo $has_media ? '1' : '0'; ?>" style="padding: 1.5rem; border-bottom: 1px solid #e5e7eb;">
                    <div style="display: flex; gap: 1rem;">
                        <div style="flex-shrink: 0;">
                            <?php if ($profile_pic): ?>
                                <img src="<?php echo htmlspecialchars($profile_pic); ?>" alt="<?php echo $reviewer_name; ?>" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($profile_pic_fallback); ?>';" style="width: 48px; height: 48px; border-radius: 50%; object-fit: cover;">
                            <?php else: ?>
                                <div style="width: 48px; height: 48px; border-radius: 50%; background: #e5e7eb; display: flex; align-items: center; justify-content: center; font-weight: 600; color: #6b7280;">
                                    <?php echo strtoupper(substr($reviewer_name, 0, 1) ?: '?'); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div style="flex: 1;">
                            <div style="font-weight: 600; color: #1f2937; margin-bottom: 0.25rem;"><?php echo $reviewer_name; ?></div>
                            <div style="display: flex; gap: 2px; margin-bottom: 0.5rem;">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <svg width="16" height="16" fill="<?php echo ($i <= $rating) ? '#f97316' : '#d1d5db'; ?>" viewBox="0 0 20 20">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                    </svg>
                                <?php endfor; ?>
                            </div>
                            <div style="font-size: 0.875rem; color: #6b7280; margin-bottom: 0.5rem;">
                                <?php echo date('Y-m-d H:i', strtotime($review['created_at'])); ?>
                                <?php if ($variation): ?> | Variation: <?php echo $variation; ?><?php endif; ?>
                            </div>
                            <?php if ($has_comment): ?>
                                <div style="color: #374151; line-height: 1.6; margin-bottom: 0.75rem;"><?php echo nl2br($comment); ?></div>
                            <?php endif; ?>
                            
                            <?php if(!empty($rev_imgs)): ?>
                                <div style="margin-bottom: 0.75rem; display: flex; flex-wrap: wrap; gap: 0.5rem;">
                                    <?php foreach($rev_imgs as $img): 
                                        $ipath = $img['image_path'];
                                        if (!preg_match('/\.(jpg|jpeg|png|webp|gif|svg)$/i', (string)$ipath)) continue;
                                        if (strpos($ipath, 'http') === false && (!isset($ipath[0]) || $ipath[0] !== '/')) $ipath = '/printflow/' . $ipath;
                                    ?>
                                        <button type="button" class="poc-media-trigger" data-media-type="image" data-media-src="<?php echo htmlspecialchars($ipath); ?>" aria-label="View review image">
                                            <img src="<?php echo htmlspecialchars($ipath); ?>" alt="Review image" class="poc-media-thumb" style="max-width: 200px; border-radius: 8px; border: 1px solid #e5e7eb;">
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if($has_video && preg_match('/\.(mp4|webm|ogg|mov)$/i', (string)$review['video_path'])): 
                                $vpath = $review['video_path'];
                                if (strpos($vpath, 'http') === false && (!isset($vpath[0]) || $vpath[0] !== '/')) $vpath = '/printflow/' . $vpath;
                            ?>
                                <div style="margin-bottom:0.75rem;">
                                    <div class="poc-video-thumb">
                                        <video src="<?php echo htmlspecialchars($vpath); ?>" controls playsinline preload="metadata" class="poc-video-preview"></video>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($review['replies'])): ?>
                                <div style="margin-top: 1rem; padding: 1rem; background: #f9fafb; border-left: 3px solid #e5e7eb; border-radius: 6px;">
                                    <div style="font-size: 0.75rem; font-weight: 700; color: #374151; text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Staff Response</div>
                                    <?php foreach ($review['replies'] as $reply): ?>
                                        <div style="margin-bottom: 0.5rem;">
                                            <div style="color: #374151; font-size: 0.875rem; line-height: 1.5;"><?php echo nl2br(htmlspecialchars($reply['reply_message'])); ?></div>
                                            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">
                                                <?php echo htmlspecialchars($reply['first_name'] . ' ' . $reply['last_name']); ?> • <?php echo date('Y-m-d', strtotime($reply['created_at'])); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div style="display:flex;align-items:center;gap:8px;margin-top:8px;">
                                <button type="button" onclick="markHelpful(<?php echo $review['id']; ?>, this)" class="helpful-btn<?php echo $review['user_voted'] ? ' voted' : ''; ?>" <?php echo $review['user_voted'] ? 'data-voted="1"' : ''; ?>>
                                    <svg width="15" height="15" fill="currentColor" viewBox="0 0 20 20"><path d="M2 10.5a1.5 1.5 0 113 0v6a1.5 1.5 0 01-3 0v-6zM6 10.333v5.43a2 2 0 001.106 1.79l.05.025A4 4 0 008.943 18h5.416a2 2 0 001.962-1.608l1.2-6A2 2 0 0015.56 8H12V4a2 2 0 00-2-2 1 1 0 00-1 1v.667a4 4 0 01-.8 2.4L6.8 7.933a4 4 0 00-.8 2.4z"/></svg>
                                    <span class="helpful-label"><?php echo $review['user_voted'] ? (int)$review['helpful_count'] : 'Helpful'; ?></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php echo render_pagination($poc_page, $poc_total_pages, ['product_id' => $product_id], 'rpage'); ?>

            <?php else: ?>
            <div class="poc-empty">
                <svg width="56" height="56" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                <p style="font-size:1rem;font-weight:600;margin:0.75rem 0 0.25rem;">No Reviews Yet</p>
                <p style="font-size:0.875rem;color:#9ca3af;">Be the first to review this product!</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Media Modal -->
<div id="pocMediaModal" class="poc-media-modal" aria-hidden="true">
    <div class="poc-media-modal-inner" role="dialog" aria-modal="true" aria-label="Media viewer">
        <button type="button" id="pocMediaClose" class="poc-media-close" aria-label="Close media viewer">&times;</button>
        <img id="pocMediaImg" class="poc-media-full" alt="Media preview" hidden>
        <video id="pocMediaVideo" class="poc-media-full" controls playsinline hidden>
            <source id="pocMediaVideoSource" src="" type="video/mp4">
        </video>
    </div>
</div>

<style>
.dim-label { font-size:0.7rem;color:#94a3b8;font-weight:600;margin-bottom:4px;display:block;text-transform:uppercase; }
.need-qty-row { display:flex;gap:16px;width:100%; }
@media (max-width:640px) { .need-qty-row { flex-direction:column; } }
.shopee-opt-btn { display: inline-flex; align-items: center; justify-content: center; padding: 0.5rem 1rem; border: 2px solid #e5e7eb; border-radius: 0.5rem; background: white; cursor: pointer; transition: all 0.2s; font-size: 0.875rem; font-weight: 500; color: #374151; min-height: 2.5rem; }
.quantity-container:hover { border-color: #e5e7eb !important; background: white !important; }
.qty-input-field::-webkit-outer-spin-button, .qty-input-field::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.qty-input-field[type=number] { -moz-appearance: textfield; appearance: textfield; }
.shopee-form-field { flex: 1; position: relative; display: flex !important; flex-direction: column !important; min-width: 0; gap: 4px; }
.field-error { display: flex !important; align-items: center; gap: 0.375rem; color: #ef4444; font-size: 0.875rem; margin-top: 0.5rem; width: 100% !important; flex-basis: 100% !important; order: 999; }
.field-invalid { border-color: #ef4444 !important; }
.field-error::before { content: '⚠'; font-size: 1rem; flex-shrink: 0; }

/* Ratings section */
.poc-section-title { font-size:1.1rem;font-weight:700;color:#111827;margin:0 0 0.75rem; }

.poc-filter-btn.active {
    background: #0a2530 !important;
    color: white !important;
    border-color: #0a2530 !important;
}

.poc-filter-btn:hover {
    border-color: #0a2530;
    background: #f0f4f5;
}

/* Review items */
.poc-review-item { border-bottom:1px solid #f3f4f6;padding:1.25rem 0; }
.poc-review-item:last-child { border-bottom:none; }

.poc-empty { text-align:center;padding:3rem 1rem;color:#6b7280; }
.helpful-btn { display:inline-flex;align-items:center;gap:5px;padding:4px 0;border:none;background:transparent;color:#9ca3af;font-size:0.82rem;font-weight:400;cursor:pointer;transition:color 0.2s; }
.helpful-btn:hover { color:#6b7280; }
.helpful-btn.voted { color:#f97316; }
.helpful-btn.voted svg { fill:#f97316; }

.poc-media-trigger { border: none; background: none; padding: 0; cursor: pointer; }
.poc-media-thumb { display: block; }
.poc-video-thumb { position: relative; display: inline-block; max-width: 240px; border-radius: 8px; border: 1px solid #e5e7eb; overflow: hidden; background: #0f172a; }
.poc-video-preview { display: block; width: 100%; height: auto; }

.poc-media-modal { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.85); display: none; align-items: center; justify-content: center; padding: 1.5rem; z-index: 100000; }
.poc-media-modal.is-open { display: flex; }
.poc-media-modal-inner { position: relative; max-width: 90vw; max-height: 90vh; }
.poc-media-full { max-width: 90vw; max-height: 90vh; border-radius: 8px; box-shadow: 0 20px 60px rgba(0,0,0,0.5); background: #0b1220; }
.poc-media-close { position: absolute; top: -12px; right: -12px; width: 36px; height: 36px; border-radius: 999px; border: none; background: #111827; color: #fff; font-size: 1.5rem; line-height: 1; cursor: pointer; box-shadow: 0 10px 30px rgba(0,0,0,0.35); }
</style>

<script>
window.pfProductBranchStocks = <?php echo json_encode($branch_stock_map, JSON_UNESCAPED_SLASHES); ?>;
window.pfDefaultStockBranchId = <?php echo (int)$main_branch_id; ?>;

function showStockWarning(max) {
    const warning = document.getElementById('stock-warning');
    if (warning) {
        warning.textContent = 'Maximum stock available: ' + max;
        warning.style.display = 'block';
    }
}

function hideStockWarning() {
    const warning = document.getElementById('stock-warning');
    if (warning) {
        warning.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const branchStocks = window.pfProductBranchStocks || {};
    const defaultBranchId = String(window.pfDefaultStockBranchId || '');
    const branchSelect = document.getElementById('poc-branch-select');
    const qtyInput = document.getElementById('poc-qty');
    const stockCount = document.getElementById('poc-stock-count');
    const stockBranch = document.getElementById('poc-stock-branch');
    const addToCartBtn = document.getElementById('poc-add-cart-btn');
    const buyNowBtn = document.getElementById('poc-buy-now-btn');

    const setButtonState = (button, enabled) => {
        if (!button) return;
        button.disabled = !enabled;
        button.style.opacity = enabled ? '1' : '0.5';
        button.style.cursor = enabled ? 'pointer' : 'not-allowed';
        const label = button.querySelector('span') || button;
        if (button.id === 'poc-buy-now-btn') {
            label.textContent = enabled ? 'Order Now' : 'Out of Stock';
        } else if (button.id === 'poc-add-cart-btn') {
            const span = button.querySelector('span');
            if (span) span.textContent = enabled ? 'Add to Cart' : 'Out of Stock';
            else button.textContent = enabled ? 'Add to Cart' : 'Out of Stock';
        }
    };

    const currentStockForBranch = () => {
        const selectedBranchId = String((branchSelect && branchSelect.value) ? branchSelect.value : '');
        if (selectedBranchId && branchStocks[selectedBranchId]) {
            return branchStocks[selectedBranchId];
        }
        if (selectedBranchId) {
            const selectedName = branchSelect?.options?.[branchSelect.selectedIndex]?.text || 'Selected Branch';
            return { stock: 0, name: selectedName };
        }
        return branchStocks[defaultBranchId] || { stock: 0, name: 'Cabuyao Branch' };
    };

    const syncQtyWithStock = () => {
        if (!qtyInput) return;
        const stockInfo = currentStockForBranch();
        const max = Math.max(0, parseInt(stockInfo.stock || 0, 10));
        qtyInput.max = max;
        if (max <= 0) {
            qtyInput.value = 1;
        } else if ((parseInt(qtyInput.value || '1', 10) || 1) > max) {
            qtyInput.value = max;
            showStockWarning(max);
        } else {
            hideStockWarning();
        }
        if (stockCount) stockCount.textContent = max;
        if (stockBranch) stockBranch.textContent = '(' + (stockInfo.name || 'Selected Branch') + ')';
        setButtonState(addToCartBtn, max > 0);
        setButtonState(buyNowBtn, max > 0);
    };

    window.pfIncreaseQty = function() {
        if (!qtyInput) return;
        const max = Math.max(0, parseInt(qtyInput.max || '0', 10));
        const current = parseInt(qtyInput.value || '1', 10) || 1;
        if (max > 0 && current < max) {
            qtyInput.value = current + 1;
            hideStockWarning();
        } else {
            showStockWarning(max);
        }
    };

    if (qtyInput) {
        qtyInput.addEventListener('input', function() {
            const max = Math.max(0, parseInt(this.max || '0', 10));
            const current = parseInt(this.value || '1', 10) || 1;
            if (max > 0 && current > max) {
                this.value = max;
                showStockWarning(max);
            } else {
                hideStockWarning();
            }
        });
    }

    if (branchSelect) {
        branchSelect.addEventListener('change', syncQtyWithStock);
    }

    syncQtyWithStock();

    document.querySelectorAll('.shopee-opt-btn input[type="radio"]').forEach(input => {
        input.addEventListener('change', function() {
            const wrap = this.closest('.shopee-opt-group');
            wrap?.querySelectorAll('.shopee-opt-btn').forEach(btn => btn.classList.remove('active'));
            this.closest('.shopee-opt-btn')?.classList.add('active');

            const name = this.getAttribute('name');
            const otherWrap = document.getElementById('other-wrap-' + name);
            if (otherWrap) {
                otherWrap.style.display = this.value === '__other__' ? 'block' : 'none';
            }
        });
    });

    document.querySelectorAll('.pricing-dimension').forEach(button => {
        button.addEventListener('click', function() {
            const target = this.dataset.target;
            document.querySelectorAll(`.pricing-dimension[data-target="${target}"]`).forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll(`.pricing-dimension-other[data-target="${target}"]`).forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            const hidden = document.getElementById('hidden-' + target);
            if (hidden) {
                hidden.value = this.dataset.value || '';
            }
            const customWrap = document.getElementById('custom-dim-' + target);
            if (customWrap) {
                customWrap.style.display = 'none';
            }
        });
    });

    document.querySelectorAll('.pricing-dimension-other').forEach(button => {
        button.addEventListener('click', function() {
            const target = this.dataset.target;
            document.querySelectorAll(`.pricing-dimension[data-target="${target}"]`).forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll(`.pricing-dimension-other[data-target="${target}"]`).forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            const customWrap = document.getElementById('custom-dim-' + target);
            if (customWrap) {
                customWrap.style.display = 'flex';
            }
            const sync = () => {
                const width = document.getElementById('width-' + target)?.value.trim() || '';
                const height = document.getElementById('height-' + target)?.value.trim() || '';
                const hidden = document.getElementById('hidden-' + target);
                if (hidden) {
                    hidden.value = width && height ? `${width}×${height}` : '';
                }
            };
            document.getElementById('width-' + target)?.addEventListener('input', sync);
            document.getElementById('height-' + target)?.addEventListener('input', sync);
            sync();
        });
    });

    const form = document.getElementById('productOrderForm');
    if (form) {
        const removeFieldError = (field) => {
            const container = field.closest('.shopee-form-field') || field.parentNode;
            container?.querySelectorAll('.field-error').forEach(el => el.remove());
            field.classList.remove('field-invalid');
        };

        const fieldHasValue = (field) => {
            if (!field) return true;
            if (field.type === 'checkbox' || field.type === 'radio') return field.checked;
            if (field.type === 'file') return field.files && field.files.length > 0;
            return String(field.value || '').trim() !== '';
        };

        form.addEventListener('submit', function(e) {
            document.querySelectorAll('.field-error').forEach(el => el.remove());
            let hasError = false, firstErrorField = null;

            const setError = (field, message) => {
                removeFieldError(field);
                const errorSpan = document.createElement('span');
                errorSpan.className = 'field-error';
                errorSpan.textContent = message;
                const container = field.closest('.shopee-form-field') || field.parentNode;
                container.appendChild(errorSpan);
                field.classList.add('field-invalid');
                if (!firstErrorField) firstErrorField = field;
                hasError = true;
            };

            form.querySelectorAll('input[type="radio"][required]').forEach(field => {
                if (!form.querySelector(`input[name="${field.name}"]:checked`)) {
                    setError(field, 'Please select an option.');
                }
            });

            if (hasError) {
                e.preventDefault();
                if (firstErrorField) firstErrorField.closest('.shopee-form-row')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        form.querySelectorAll('[required]').forEach(field => {
            const clearIfValid = () => {
                if (fieldHasValue(field)) removeFieldError(field);
            };
            field.addEventListener('input', clearIfValid);
            field.addEventListener('change', clearIfValid);
        });
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const filterBtns = document.querySelectorAll('.poc-filter-btn');
    const reviewItems = document.querySelectorAll('.poc-review-item');

    filterBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            filterBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            const filter = this.dataset.filter;
            reviewItems.forEach(item => {
                const show = filter === 'all'
                    || (filter === 'comments' && item.dataset.hasComment === '1')
                    || (filter === 'media'    && item.dataset.hasMedia   === '1')
                    || item.dataset.rating === filter;
                item.style.display = show ? '' : 'none';
            });
        });
    });
});

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('pocMediaModal');
    const modalImg = document.getElementById('pocMediaImg');
    const modalVideo = document.getElementById('pocMediaVideo');
    const modalVideoSource = document.getElementById('pocMediaVideoSource');
    const closeBtn = document.getElementById('pocMediaClose');

    if (!modal || !modalImg || !modalVideo || !modalVideoSource || !closeBtn) return;

    const openMedia = (type, src) => {
        if (!src) return;
        if (type === 'video') {
            modalImg.hidden = true;
            modalVideo.hidden = false;
            modalVideoSource.src = src;
            modalVideo.load();
        } else {
            modalVideo.hidden = true;
            modalImg.hidden = false;
            modalImg.src = src;
        }
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    };

    const closeMedia = () => {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        modalImg.src = '';
        modalVideo.pause();
        modalVideoSource.src = '';
        modalVideo.load();
        document.body.style.overflow = '';
    };

    document.querySelectorAll('.poc-media-trigger').forEach(btn => {
        btn.addEventListener('click', function() {
            openMedia(this.dataset.mediaType, this.dataset.mediaSrc);
        });
    });

    closeBtn.addEventListener('click', closeMedia);
    modal.addEventListener('click', function(e) {
        if (e.target === modal) closeMedia();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) {
            closeMedia();
        }
    });
});

async function markHelpful(reviewId, btn) {
    const basePath = <?php echo json_encode(rtrim(defined('BASE_PATH') ? BASE_PATH : '', '/')); ?>;
    const label = btn.querySelector('.helpful-label');
    const originalLabel = btn.dataset.defaultLabel || 'Helpful';
    const nextAction = btn.dataset.voted === '1' ? 'unlike' : 'like';
    btn.disabled = true;
    try {
        const res = await fetch(basePath + '/public/api/review_helpful.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'review_id=' + encodeURIComponent(reviewId) + '&action=' + encodeURIComponent(nextAction)
        });
        const data = await res.json().catch(() => ({success: false, error: 'Invalid server response'}));
        if (data.success) {
            if (data.voted) {
                btn.dataset.voted = '1';
                btn.classList.add('voted');
                label.textContent = data.count;
            } else {
                delete btn.dataset.voted;
                btn.classList.remove('voted');
                label.textContent = originalLabel;
            }
        } else {
            console.error('Helpful vote failed:', data.error || res.statusText);
        }
    } catch(e) {
        console.error('Helpful vote failed:', e);
    } finally {
        btn.disabled = false;
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
