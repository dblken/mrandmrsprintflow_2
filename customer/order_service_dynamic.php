<?php
/**
 * Dynamic Service Order Page
 * Renders form based on admin field configuration
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_order_helper.php';
require_once __DIR__ . '/../includes/service_field_renderer.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$base_path = pf_app_base_path();
$default_service_img = $base_path . '/public/assets/images/services/default.png';

function pf_normalize_service_media_path($path, $base_path, $default_img) {
    $path = trim((string)$path);
    if ($path === '') {
        return $default_img;
    }
    $path = str_replace('\\', '/', $path);
    if (preg_match('#^https?://#i', $path)) {
        $parts = parse_url($path);
        if (!empty($parts['path'])) {
            $path = $parts['path'];
        } else {
            return $path;
        }
    }
    if (preg_match('#^[A-Za-z]:/#', $path)) {
        $path = preg_replace('#^[A-Za-z]:#', '', $path);
    }
    $public_pos = strpos($path, '/public/');
    if ($public_pos !== false) {
        $path = substr($path, $public_pos);
    }
    $uploads_pos = strpos($path, '/uploads/');
    if ($uploads_pos !== false && $uploads_pos < $public_pos) {
        $path = substr($path, $uploads_pos);
    }
    if ($base_path === '' && strpos($path, '/printflow/') === 0) {
        $path = substr($path, strlen('/printflow'));
    }
    if ($path !== '' && $path[0] !== '/') {
        $path = '/' . ltrim($path, '/');
    }
    if ($base_path !== '' && strpos($path, $base_path . '/') !== 0) {
        $path = $base_path . $path;
    }
    return $path;
}

function pf_service_video_candidates($path, $base_path) {
    $raw = trim((string)$path);
    if ($raw === '') {
        return [];
    }

    $normalized = pf_normalize_service_media_path($raw, $base_path, '');
    $variants = [];
    $basename = basename(str_replace('\\', '/', $raw));

    foreach ([$raw, $normalized] as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            continue;
        }

        $candidate = str_replace('\\', '/', $candidate);
        $variants[] = $candidate;

        if (strpos($candidate, '/public/') !== false) {
            $variants[] = str_replace('/public/', '/', $candidate);
        } elseif (strpos($candidate, '/assets/videos/services/') !== false) {
            $variants[] = preg_replace('#/assets/videos/services/#', '/public/assets/videos/services/', $candidate, 1);
        }

        if ($base_path !== '' && strpos($candidate, $base_path . '/public/') === 0) {
            $variants[] = $base_path . substr($candidate, strlen($base_path . '/public'));
        }
    }

    if ($basename !== '' && $basename !== '.' && $basename !== '/') {
        $variants[] = '/public/assets/videos/services/' . $basename;
        $variants[] = '/assets/videos/services/' . $basename;
        if ($base_path !== '') {
            $variants[] = $base_path . '/public/assets/videos/services/' . $basename;
            $variants[] = $base_path . '/assets/videos/services/' . $basename;
        }
    }

    $clean = [];
    foreach ($variants as $variant) {
        $variant = trim((string)$variant);
        if ($variant === '') {
            continue;
        }

        if (preg_match('#^https?://#i', $variant)) {
            $parts = parse_url($variant);
            if (!empty($parts['path'])) {
                $variant = $parts['path'];
            }
        }

        if ($variant !== '' && $variant[0] !== '/' && !preg_match('#^https?://#i', $variant)) {
            $variant = '/' . ltrim($variant, '/');
        }

        if ($base_path !== '' && strpos($variant, 'http') !== 0 && strpos($variant, $base_path . '/') !== 0) {
            $variant = $base_path . $variant;
        }

        $clean[$variant] = true;
    }

    return array_keys($clean);
}

function pf_video_mime_type($src) {
    $ext = strtolower(pathinfo(parse_url((string)$src, PHP_URL_PATH) ?? (string)$src, PATHINFO_EXTENSION));
    return match ($ext) {
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'ogv' => 'video/ogg',
        'avi' => 'video/x-msvideo',
        default => 'video/mp4',
    };
}

function pf_render_video_sources($sources) {
    $html = '';
    foreach ($sources as $src) {
        $html .= '<source src="' . htmlspecialchars($src) . '" type="' . htmlspecialchars(pf_video_mime_type($src)) . '">' . "\n";
    }
    return $html;
}

function pf_review_helpful_columns(): array {
    global $conn;

    $conn->query("CREATE TABLE IF NOT EXISTS review_helpful (
        id INT AUTO_INCREMENT PRIMARY KEY,
        review_id INT NOT NULL,
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_review_user (review_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $columns = array_flip(array_column(db_query("SHOW COLUMNS FROM review_helpful") ?: [], 'Field'));
    if (!isset($columns['customer_id'])) {
        db_execute("ALTER TABLE review_helpful ADD COLUMN customer_id INT NULL AFTER user_id");
        $columns['customer_id'] = true;
    }
    if (!isset($columns['user_type'])) {
        db_execute("ALTER TABLE review_helpful ADD COLUMN user_type VARCHAR(20) NULL AFTER customer_id");
        $columns['user_type'] = true;
    }

    return $columns;
}

function pf_normalize_review_media_path($path, $base_path, $folder = '') {
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }

    $path = str_replace('\\', '/', $path);
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    if (preg_match('#^[A-Za-z]:/#', $path)) {
        $path = preg_replace('#^[A-Za-z]:#', '', $path);
    }

    $printflowPos = strpos($path, '/printflow/');
    if ($printflowPos !== false) {
        $path = substr($path, $printflowPos + strlen('/printflow'));
    }

    $uploadsPos = strpos($path, '/uploads/');
    if ($uploadsPos !== false) {
        $path = substr($path, $uploadsPos);
    } elseif (strpos($path, 'uploads/') === 0) {
        $path = '/' . $path;
    } elseif (strpos($path, '/') === false && $folder !== '') {
        $path = '/uploads/' . trim($folder, '/') . '/' . $path;
    } elseif ($path[0] !== '/') {
        $path = '/' . ltrim($path, '/');
    }

    if ($base_path === '' && strpos($path, '/printflow/') === 0) {
        $path = substr($path, strlen('/printflow'));
    }

    if ($base_path !== '' && strpos($path, $base_path . '/') !== 0 && strpos($path, 'http') !== 0) {
        $path = $base_path . $path;
    }

    return $path;
}

function pf_review_video_candidates($path, $base_path, $review_id = 0) {
    $sources = [];
    $base = rtrim((string)$base_path, '/');
    if ((int)$review_id > 0) {
        $sources[] = ($base !== '' ? $base : '') . '/public/serve_review_video.php?review_id=' . (int)$review_id;
    }
    foreach (pf_review_video_direct_candidates((string)$path, (string)$base_path) as $candidate) {
        if (!in_array($candidate, $sources, true)) {
            $sources[] = $candidate;
        }
    }
    return $sources;
}

require_role('Customer');
require_once __DIR__ . '/../includes/require_customer_profile_complete.php';
require_once __DIR__ . '/../includes/require_id_verified.php';
$customer_id = get_user_id();

function pf_customer_absolute_url(string $path, array $query = []): string {
    $base = rtrim((string)(defined('BASE_URL') ? BASE_URL : (function_exists('pf_app_base_path') ? pf_app_base_path() : '')), '/');
    $path = '/' . ltrim($path, '/');
    $relative = $base . $path;
    if (!empty($query)) {
        $relative .= '?' . http_build_query($query);
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return $relative;
    }

    $is_https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    return ($is_https ? 'https://' : 'http://') . $host . $relative;
}

function pf_post_redirect(string $path, array $query = []): void {
    header('Location: ' . pf_customer_absolute_url($path, $query), true, 303);
    exit;
}

$service_id = (int)($_GET['service_id'] ?? 0);
$edit_item_key = $_GET['edit_item'] ?? '';
$error = '';

// Load existing cart data if editing
$existing_data = [];
if ($edit_item_key && isset($_SESSION['cart'][$edit_item_key])) {
    $existing_data = $_SESSION['cart'][$edit_item_key];
}

if ($service_id < 1) {
    header('Location: services.php');
    exit;
}

$service = db_query("SELECT * FROM services WHERE service_id = ? AND status = 'Activated'", 'i', [$service_id]);
if (empty($service)) {
    header('Location: services.php');
    exit;
}
$service = $service[0];

// Check if service has field configuration
if (!service_has_field_config($service_id)) {
    // Fallback to hardcoded page if exists
    if (!empty($service['customer_link']) && file_exists(__DIR__ . '/' . $service['customer_link'])) {
        header('Location: ' . $service['customer_link']);
        exit;
    }
    // If no customer_link and no field config, show error instead of redirecting
    $error = 'This service is not yet configured. Please contact administrator.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    // Get field configurations to validate dynamically
    $field_configs = get_service_field_config($service_id);
    
    // Extract values from POST based on field configuration
    $branch_id = (int)($_POST['branch_id'] ?? 0);
    $quantity_field_key = 'quantity';
    foreach ($field_configs as $qk => $qc) {
        if (!empty($qc['visible']) && ($qc['type'] ?? '') === 'quantity') {
            $quantity_field_key = $qk;
            break;
        }
    }
    $quantity = max(1, min(999, (int)($_POST[$quantity_field_key] ?? $_POST['quantity'] ?? 1)));
    $has_design_field = false;
    
    // Validate branch
    if ($branch_id < 1) {
        $error = 'Please select a branch for pickup.';
    }
    
    // Validate all required fields dynamically
    if (empty($error)) {
        foreach ($field_configs as $key => $config) {
            if (!$config['visible']) continue;
            
            // --- Conditional Logic Check ---
            if (!empty($config['parent_field_key']) && !empty($config['parent_value'])) {
                $parent_key = $config['parent_field_key'];
                $trigger_value = $config['parent_value'];
                
                // Get the value of the parent field from POST
                // For radio/select, it's just $_POST[$parent_key]
                $parent_submitted_value = $_POST[$parent_key] ?? null;
                
                // Special case for 'branch' if it were possible, but here it's custom fields
                if ($parent_key === 'branch') {
                    $parent_submitted_value = $_POST['branch_id'] ?? null;
                }
                
                // If parent condition not met, skip this field (it was hidden)
                if ($parent_submitted_value != $trigger_value) {
                    continue;
                }
            }
            // --- End Conditional Logic Check ---
            
            if ($config['type'] === 'date') {
                $date_val = trim((string)($_POST[$key] ?? ''));
                if ($config['required'] && $date_val === '') {
                    $error = 'Please select ' . strtolower($config['label'] ?: 'a date') . '.';
                    break;
                }
            } elseif ($config['type'] === 'textarea') {
                $note_val = trim((string)($_POST[$key] ?? ''));
                if ($config['required'] && $note_val === '') {
                    $error = 'Please provide ' . strtolower($config['label'] ?: 'notes') . '.';
                    break;
                }
            } elseif ($config['type'] === 'quantity') {
                $qv = max(0, min(999, (int)($_POST[$key] ?? 0)));
                if ($config['required'] && $qv < 1) {
                    $error = 'Please enter a valid quantity.';
                    break;
                }
            } elseif ($config['type'] === 'file') {
                $has_design_field = true;
                if ($config['required'] && (!isset($_FILES['design_file']) || $_FILES['design_file']['error'] !== UPLOAD_ERR_OK)) {
                    $error = 'Please upload your design.';
                    break;
                }
            } elseif ($config['type'] === 'radio' || $config['type'] === 'select') {
                // Skip branch validation as it's already validated above
                if ($key === 'branch') continue;
                
                if ($config['required'] && empty($_POST[$key])) {
                    $error = 'Please select ' . strtolower($config['label']) . '.';
                    break;
                }
                
                // If "Others" is selected, check specify input
                if (($_POST[$key] ?? '') === 'Others' && empty(trim($_POST[$key . '_other'] ?? ''))) {
                    $error = 'Please specify ' . strtolower($config['label']) . '.';
                    break;
                }
            } elseif ($config['type'] === 'dimension') {
                $width = trim($_POST[$key . '_width'] ?? $_POST['width'] ?? '');
                $height = trim($_POST[$key . '_height'] ?? $_POST['height'] ?? '');
                if ($config['required'] && (empty($width) || empty($height))) {
                    $error = 'Please select dimensions.';
                    break;
                }
                if (!empty($width) && (!is_numeric($width) || $width <= 0)) {
                    $error = 'Invalid width dimension.';
                    break;
                }
                if (!empty($height) && (!is_numeric($height) || $height <= 0)) {
                    $error = 'Invalid height dimension.';
                    break;
                }
            } elseif (in_array($config['type'], ['text', 'number'])) {
                if ($config['required'] && empty(trim($_POST[$key] ?? ''))) {
                    $error = 'Please provide ' . strtolower($config['label']) . '.';
                    break;
                }
            }
        }
    }
    
    if (empty($error)) {
        // Validate and process file upload if design field exists
        $design_tmp_path = null;
        $design_name = null;
        $design_mime = null;
        
        if ($has_design_field && isset($_FILES['design_file']) && $_FILES['design_file']['error'] === UPLOAD_ERR_OK) {
            $valid = service_order_validate_file($_FILES['design_file']);
            if (!$valid['ok']) {
                $error = $valid['error'];
            } else {
                $item_key = 'service_' . $service_id . '_' . time() . '_' . rand(100, 999);
                $original_name = $_FILES['design_file']['name'];
                $mime = $valid['mime'];
                $ext = pathinfo($original_name, PATHINFO_EXTENSION);
                $new_name = uniqid('tmp_') . '.' . $ext;
                $tmp_dest = service_order_temp_dir() . DIRECTORY_SEPARATOR . $new_name;

                if (move_uploaded_file($_FILES['design_file']['tmp_name'], $tmp_dest)) {
                    $design_tmp_path = $tmp_dest;
                    $design_name = $original_name;
                    $design_mime = $mime;
                } else {
                    $error = 'Failed to process uploaded file.';
                }
            }
        } elseif (!$has_design_field) {
            // No design field configured, create item key without file
            $item_key = 'service_' . $service_id . '_' . time() . '_' . rand(100, 999);
        }
        
        if (empty($error)) {
            // Collect all custom fields dynamically
            $customization = [];
            $spec_label = static function (array $cfg, string $fieldKey): string {
                $l = trim((string)($cfg['label'] ?? ''));
                if ($l !== '') {
                    return $l;
                }
                $fk = trim($fieldKey);
                return $fk !== '' ? $fk : 'Specification';
            };
            foreach ($field_configs as $key => $config) {
                if (!$config['visible']) continue;
                
                // Check conditional logic again for data collection
                if (!empty($config['parent_field_key']) && !empty($config['parent_value'])) {
                    $parent_key = $config['parent_field_key'];
                    $trigger_value = $config['parent_value'];
                    $parent_submitted_value = $_POST[$parent_key] ?? null;
                    if ($parent_key === 'branch') $parent_submitted_value = $_POST['branch_id'] ?? null;
                    
                    if ($parent_submitted_value != $trigger_value) {
                        continue;
                    }
                }
                
                if ($key === 'branch') {
                    continue;
                }
                if ($key === $quantity_field_key && ($config['type'] ?? '') === 'quantity') {
                    $customization[$spec_label($config, $key)] = (string)$quantity;
                    continue;
                }

                if ($config['type'] === 'date') {
                    $dv = trim((string)($_POST[$key] ?? ''));
                    if ($dv !== '') {
                        $customization[$spec_label($config, $key)] = $dv;
                        if ($key === 'needed_date') {
                            $customization['needed_date'] = $dv;
                        }
                    }
                    continue;
                }
                if ($config['type'] === 'file') {
                    if ($design_name !== null && $design_name !== '') {
                        $customization[$spec_label($config, $key)] = $design_name;
                    }
                    continue;
                }

                if ($config['type'] === 'dimension') {
                    $width = trim($_POST[$key . '_width'] ?? $_POST['width'] ?? '');
                    $height = trim($_POST[$key . '_height'] ?? $_POST['height'] ?? '');
                    if ($width !== '' && $height !== '') {
                        $customization[$spec_label($config, $key)] = $width . '×' . $height . ' ' . ($config['unit'] ?? 'ft');
                    }
                } else {
                    if (!isset($_POST[$key])) {
                        continue;
                    }
                    $val = $_POST[$key];
                    if ($val === '' || $val === null) {
                        continue;
                    }
                    if (is_array($val)) {
                        $parts = [];
                        foreach ($val as $vx) {
                            if ($vx === '' || $vx === null) {
                                continue;
                            }
                            $parts[] = is_scalar($vx) ? (string)$vx : (string)json_encode($vx, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                        }
                        if ($parts === []) {
                            continue;
                        }
                        $val = implode(', ', $parts);
                    }
                    if ($val === 'Others' && !empty($_POST[$key . '_other'])) {
                        $val = $_POST[$key . '_other'];
                        $customization[$spec_label($config, $key) . ' (Other)'] = $_POST[$key . '_other'];
                    }
                    $customization[$spec_label($config, $key)] = $val;
                    if (($config['type'] ?? '') === 'textarea' && $key === 'notes') {
                        $customization['notes'] = is_string($val) ? $val : (string)$val;
                    }
                }
            }
            printflow_merge_nested_service_fields_into_customization($field_configs, $customization, $_POST, $_FILES);
            if ($branch_id > 0) {
                $branch_row = db_query('SELECT branch_name FROM branches WHERE id = ? LIMIT 1', 'i', [$branch_id]);
                if (!empty($branch_row) && trim((string)($branch_row[0]['branch_name'] ?? '')) !== '') {
                    $branchLabel = 'Branch';
                    foreach ($field_configs as $bk => $bc) {
                        if ($bk === 'branch' && !empty($bc['visible'])) {
                            $branchLabel = $spec_label($bc, $bk);
                            break;
                        }
                    }
                    $customization[$branchLabel] = $branch_row[0]['branch_name'];
                }
            }
            if (empty($customization['source_page'])) {
                $customization['source_page'] = 'services';
            }
            $customization['service_id'] = $service_id;
            if (empty($customization['service_type'])) {
                $customization['service_type'] = $service['name'];
            }

            // Calculate estimated price dynamically based on selected options
            $base_price = (float)($service['base_price'] ?? 0);
            $options_total = 0;

            foreach ($field_configs as $key => $config) {
                if (!$config['visible']) continue;
                if (!in_array($config['type'], ['radio', 'select', 'dimension'])) continue;
                
                $selected_value = $_POST[$key] ?? '';
                
                // Handle dimension fields
                if ($config['type'] === 'dimension') {
                    $width = trim($_POST[$key . '_width'] ?? $_POST['width'] ?? '');
                    $height = trim($_POST[$key . '_height'] ?? $_POST['height'] ?? '');
                    if (!empty($width) && !empty($height)) {
                        $selected_value = $width . '×' . $height;
                    }
                }
                
                if (empty($selected_value) || $selected_value === 'Others') continue;
                
                if (!empty($config['options']) && is_array($config['options'])) {
                    foreach ($config['options'] as $option) {
                        $opt_value = is_array($option) ? ($option['value'] ?? '') : $option;
                        $opt_price = is_array($option) ? ($option['price'] ?? 0) : 0;
                        
                        if ($opt_value === $selected_value) {
                            $options_total += (float)$opt_price;
                            break;
                        }
                    }
                }
            }

            $options_total += printflow_nested_service_options_price_total($_POST, $field_configs);

            $unit_price = $base_price + $options_total;
            $estimated_price = $unit_price * $quantity;
            $posted_unit_price = isset($_POST['calculated_unit_price']) ? (float)$_POST['calculated_unit_price'] : null;
            $posted_estimated_price = isset($_POST['calculated_estimated_price']) ? (float)$_POST['calculated_estimated_price'] : null;
            if ($posted_unit_price !== null && $posted_unit_price >= 0) {
                $unit_price = $posted_unit_price;
            }
            if ($posted_estimated_price !== null && $posted_estimated_price >= 0) {
                $estimated_price = $posted_estimated_price;
            }
            
            $_SESSION['cart'][$item_key] = [
                'type' => 'Service',
                'source_page' => 'services',
                'service_id' => $service_id,
                'product_id' => $service_id,
                'name' => $service['name'],
                'price' => $unit_price,  // FIXED: Store unit price per item, not total
                'estimated_price' => $estimated_price,
                'quantity' => $quantity,
                'category' => $service['category'],
                'branch_id' => $branch_id,
                'design_tmp_path' => $design_tmp_path,
                'design_name' => $design_name,
                'design_mime' => $design_mime,
                'customization' => $customization
            ];
            
            if (($_POST['action'] ?? '') === 'inquire_now' || isset($_POST['inquire_now'])) {
                pf_post_redirect('/customer/order_review.php', ['item' => $item_key]);
            } else {
                // Add to cart action
                pf_post_redirect('/customer/cart.php');
            }
        }
    }
}

$page_title = 'Order ' . $service['name'] . ' - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';

$review_helpful_columns = pf_review_helpful_columns();

$branches = db_query("SELECT id, branch_name FROM branches WHERE status = 'Active'");

// Get all display images
$display_images = [];
if (!empty($service['display_image'])) {
    $images = explode(',', $service['display_image']);
    foreach ($images as $img) {
        $img = trim($img);
        if ($img !== '') {
            $img = pf_normalize_service_media_path($img, $base_path, $default_service_img);
            $display_images[] = ['type' => 'image', 'src' => $img];
        }
    }
}

// Include video if present
$display_video = '';
$display_video_sources = [];
if (!empty($service['video_url'])) {
    $vid = trim($service['video_url']);
    if ($vid !== '') {
        $vid = pf_normalize_service_media_path($vid, $base_path, $default_service_img);
        $display_video = $vid;
        $display_video_sources = pf_service_video_candidates($service['video_url'], $base_path);
        if (empty($display_video_sources)) {
            $display_video_sources = [$vid];
        }
        $display_images[] = ['type' => 'video', 'src' => $vid];
    }
}

// Fallback to hero_image if no display images
if (empty($display_images) && !empty($service['hero_image'])) {
    $img = pf_normalize_service_media_path($service['hero_image'], $base_path, $default_service_img);
    $display_images[] = ['type' => 'image', 'src' => $img];
}

// Use first item or placeholder
$display_img = !empty($display_images) ? $display_images[0]['src'] : 'https://placehold.co/600x600/f8fafc/0f172a?text=' . urlencode($service['name']);
$display_img_type = !empty($display_images) ? $display_images[0]['type'] : 'image';
$display_video_poster = !empty($service['hero_image'])
    ? pf_normalize_service_media_path($service['hero_image'], $base_path, $default_service_img)
    : (!empty($display_images) && $display_images[0]['type'] === 'image' ? $display_images[0]['src'] : $default_service_img);

$stats = service_order_get_page_stats($service['customer_link'] ?? '', (int)($service['service_id'] ?? 0));
$avg_rating = number_format((float)($stats['avg_rating'] ?? 0), 1);
$review_count = (int)($stats['review_count'] ?? 0);
$sold_count = (int)($stats['sold_count'] ?? 0);
$sold_display = $sold_count >= 1000 ? number_format($sold_count / 1000, 1) . 'k' : $sold_count;
?>

<div class="min-h-screen py-8 bg-white">
    <div class="shopee-layout-container">
        <div class="text-sm text-gray-500 mb-6 flex items-center gap-2">
            <a href="services.php" class="hover:text-blue-600">Services</a>
            <span>/</span>
            <span class="font-semibold text-gray-900 text-ellipsis-single" title="<?php echo htmlspecialchars($service['name']); ?>"><?php echo htmlspecialchars($service['name']); ?></span>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6" id="error-message">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="shopee-card">
            <div class="shopee-image-section">
                <div class="sticky-image-container" style="position: sticky; top: 80px;">
                    <div class="shopee-main-image-wrap" style="position:relative;">
                        <?php if (count($display_images) > 1): ?>
                            <!-- Media Carousel -->
                            <div id="image-carousel" data-current-index="0" style="position:relative;width:100%;height:500px;overflow:hidden;border-radius:0;background:#f9fafb;isolation:isolate;pointer-events:auto;">
                                <?php foreach ($display_images as $index => $media): ?>
                                    <?php if ($media['type'] === 'video'): ?>
                                        <div class="carousel-item service-media-video-wrap" data-index="<?php echo $index; ?>" style="position:absolute;top:0;left:<?php echo $index === 0 ? '0' : '100%'; ?>;width:100%;height:100%;transition:left 0.4s ease-in-out;pointer-events:auto;">
                                            <video id="carousel-video-<?php echo $index; ?>"
                                                   poster="<?php echo htmlspecialchars($display_video_poster); ?>"
                                                   class="service-media-video"
                                                   controls
                                                   controlsList="nodownload"
                                                   disablePictureInPicture
                                                   preload="metadata"
                                                   autoplay
                                                   muted
                                                   loop
                                                   playsinline
                                                   webkit-playsinline="true">
                                                <?php echo pf_render_video_sources($display_video_sources); ?>
                                            </video>
                                            <div style="position:absolute;top:10px;right:10px;background:rgba(0,0,0,0.6);color:white;font-size:10px;font-weight:700;padding:3px 8px;border-radius:99px;letter-spacing:0.05em;">VIDEO</div>
                                        </div>
                                    <?php else: ?>
                                        <img src="<?php echo htmlspecialchars($media['src']); ?>"
                                             alt="<?php echo htmlspecialchars($service['name']); ?>"
                                             class="carousel-image"
                                             data-index="<?php echo $index; ?>"
                                             style="position:absolute;top:0;left:<?php echo $index === 0 ? '0' : '100%'; ?>;width:100%;height:100%;object-fit:cover;transition:left 0.4s ease-in-out;pointer-events:none;">
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                
                                <script>
                                window.pfInlineCarouselStep = function(direction) {
                                    var carousel = document.getElementById('image-carousel');
                                    if (!carousel) return false;

                                    var items = Array.prototype.slice.call(carousel.querySelectorAll('.carousel-image, .carousel-item')).sort(function(a, b) {
                                        return (parseInt(a.getAttribute('data-index'), 10) || 0) - (parseInt(b.getAttribute('data-index'), 10) || 0);
                                    });
                                    if (!items.length) return false;

                                    var current = parseInt(carousel.getAttribute('data-current-index'), 10);
                                    if (isNaN(current)) {
                                        var counter = document.getElementById('current-image');
                                        current = counter ? ((parseInt(counter.textContent, 10) || 1) - 1) : 0;
                                    }

                                    var delta = parseInt(direction, 10) || 0;
                                    var next = Math.max(0, Math.min(items.length - 1, current + delta));
                                    if (next === current) return false;

                                    var oldItem = items[current];
                                    var newItem = items[next];
                                    if (newItem) newItem.style.left = delta > 0 ? '100%' : '-100%';
                                    if (newItem) newItem.offsetHeight;
                                    if (oldItem) oldItem.style.left = delta > 0 ? '-100%' : '100%';
                                    if (newItem) newItem.style.left = '0';

                                    carousel.setAttribute('data-current-index', String(next));
                                    window.currentImageIndex = next;
                                    window.__pfServiceCarouselState = { serviceId: <?php echo (int)$service_id; ?>, imageIndex: next };

                                    var currentLabel = document.getElementById('current-image');
                                    if (currentLabel) currentLabel.textContent = next + 1;

                                    var prevBtn = document.getElementById('carousel-prev');
                                    var nextBtn = document.getElementById('carousel-next');
                                    if (prevBtn) prevBtn.style.display = next === 0 ? 'none' : 'flex';
                                    if (nextBtn) nextBtn.style.display = next === items.length - 1 ? 'none' : 'flex';

                                    items.forEach(function(item, i) {
                                        var video = item.querySelector ? item.querySelector('video') : null;
                                        if (!video) return;
                                        if (i === next) video.play().catch(function() {});
                                        else {
                                            video.pause();
                                            try { video.currentTime = 0; } catch (e) {}
                                        }
                                    });

                                    window.setTimeout(function() {
                                        items.forEach(function(item, i) {
                                            item.style.left = ((i - next) * 100) + '%';
                                        });
                                    }, 430);

                                    return false;
                                };
                                </script>

                                <!-- Navigation Arrows -->
                                <button type="button" id="carousel-prev" data-carousel-dir="-1" onclick="return window.pfInlineCarouselStep(-1)" class="carousel-arrow carousel-prev" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.85);color:#374151;border:none;border-radius:50%;width:32px;height:32px;cursor:pointer;display:none;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,0.15);z-index:100;transition:all 0.2s;pointer-events:auto;">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                                </button>
                                <button type="button" id="carousel-next" data-carousel-dir="1" onclick="return window.pfInlineCarouselStep(1)" class="carousel-arrow carousel-next" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.85);color:#374151;border:none;border-radius:50%;width:32px;height:32px;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(0,0,0,0.15);z-index:100;transition:all 0.2s;pointer-events:auto;">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                </button>
                                
                                <!-- Image Counter -->
                                <div style="position:absolute;bottom:32px;right:16px;background:rgba(0,0,0,0.65);color:white;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:600;z-index:10;">
                                    <span id="current-image">1</span> / <?php echo count($display_images); ?>
                                </div>
                            </div>

                            <?php
                            // Find video index for the shared mute button
                            $video_index_in_carousel = -1;
                            foreach ($display_images as $vi => $vm) {
                                if ($vm['type'] === 'video') { $video_index_in_carousel = $vi; break; }
                            }
                            ?>
                            <?php if ($video_index_in_carousel >= 0): ?>
                            <!-- Shared mute button — outside overflow:hidden carousel -->
                            <button type="button" id="shared-mute-btn"
                                onclick="toggleMute(<?php echo $video_index_in_carousel; ?>)"
                                title="Toggle sound"
                                style="position:absolute;top:12px;left:12px;background:rgba(0,0,0,0.65);color:white;border:none;border-radius:50%;width:38px;height:38px;cursor:pointer;display:<?php echo $video_index_in_carousel === 0 ? 'flex' : 'none'; ?>;align-items:center;justify-content:center;z-index:30;box-shadow:0 2px 8px rgba(0,0,0,0.4);transition:background 0.2s;padding:0;"
                                onmouseover="this.style.background='rgba(0,0,0,0.9)'" onmouseout="this.style.background='rgba(0,0,0,0.65)'">
                                <svg id="shared-mute-icon" width="20" height="20" fill="white" viewBox="0 0 24 24">
                                    <path d="M16.5 12A4.5 4.5 0 0014 7.97v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51A8.796 8.796 0 0021 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06A8.99 8.99 0 0017.73 18l2 2.01L21 18.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/>
                                </svg>
                            </button>
                            <?php endif; ?>
                            <!-- Thumbnail Navigation (hidden) -->
                            <div style="display:none;gap:8px;margin-top:12px;overflow-x:auto;padding:4px;">
                                <?php foreach ($display_images as $index => $media): ?>
                                    <?php if ($media['type'] === 'video'): ?>
                                        <div class="carousel-thumbnail"
                                            data-index="<?php echo $index; ?>"
                                            style="width:70px;height:70px;border-radius:10px;cursor:pointer;border:2px solid <?php echo $index === 0 ? '#0d9488' : '#e5e7eb'; ?>;transition:all 0.2s;flex-shrink:0;background:#111;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;">
                                            <video src="<?php echo htmlspecialchars($media['src']); ?>" style="width:100%;height:100%;object-fit:cover;pointer-events:none;"></video>
                                            <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.4);">
                                                <svg width="20" height="20" fill="white" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <img src="<?php echo htmlspecialchars($media['src']); ?>"
                                            alt="Thumbnail <?php echo $index + 1; ?>"
                                            class="carousel-thumbnail"
                                            data-index="<?php echo $index; ?>"
                                            style="width:70px;height:70px;object-fit:cover;border-radius:10px;cursor:pointer;border:2px solid <?php echo $index === 0 ? '#0d9488' : '#e5e7eb'; ?>;transition:all 0.2s;flex-shrink:0;box-shadow:0 4px 10px rgba(0,0,0,0.2);">
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <!-- Single Media -->
                            <div class="single-media-frame<?php echo $display_img_type === 'video' ? ' service-media-video-wrap' : ''; ?>" style="width:100%;height:500px;border-radius:8px;background:<?php echo $display_img_type === 'video' ? '#0b1220' : '#f9fafb'; ?>;display:flex;align-items:center;justify-content:center;overflow:hidden;border:1px solid #e5e7eb;position:relative;">
                                <?php if ($display_img_type === 'video'): ?>
                                    <video id="single-video"
                                           poster="<?php echo htmlspecialchars($display_video_poster); ?>"
                                           class="service-media-video"
                                           controls
                                           controlsList="nodownload"
                                           disablePictureInPicture
                                           preload="metadata"
                                           autoplay
                                           muted
                                           loop
                                           playsinline
                                           webkit-playsinline="true">
                                        <?php echo pf_render_video_sources($display_video_sources); ?>
                                    </video>
                                    <button type="button" onclick="toggleSingleMute()" title="Toggle sound"
                                        style="position:absolute;top:12px;left:12px;background:rgba(0,0,0,0.6);color:white;border:none;border-radius:50%;width:36px;height:36px;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:20;padding:0;"
                                        onmouseover="this.style.background='rgba(0,0,0,0.85)'" onmouseout="this.style.background='rgba(0,0,0,0.6)'">
                                        <svg id="single-mute-icon" width="18" height="18" fill="white" viewBox="0 0 24 24">
                                            <path d="M16.5 12A4.5 4.5 0 0014 7.97v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51A8.796 8.796 0 0021 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06A8.99 8.99 0 0017.73 18l2 2.01L21 18.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/>
                                        </svg>
                                    </button>
                                    <div style="position:absolute;top:10px;right:10px;background:rgba(0,0,0,0.6);color:white;font-size:10px;font-weight:700;padding:3px 8px;border-radius:99px;letter-spacing:0.05em;">VIDEO</div>
                                <?php else: ?>
                                    <img src="<?php echo htmlspecialchars($display_img); ?>"
                                         alt="<?php echo htmlspecialchars($service['name']); ?>"
                                         style="width:100%;height:100%;object-fit:cover;">
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Service Description -->
                    <?php if (!empty($service['description'])): ?>
                        <div style="margin-top:20px;padding:16px;background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;">
                            <h3 style="font-size:14px;font-weight:700;color:#374151;margin-bottom:8px;text-transform:uppercase;letter-spacing:0.5px;">Description</h3>
                            <p class="service-copy-safe" style="font-size:14px;line-height:1.6;color:#64748b;white-space:pre-wrap;text-align:justify;"><?php echo nl2br(htmlspecialchars($service['description'])); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="shopee-form-section">
                <h1 class="text-2xl font-bold text-gray-900 mb-2 service-name-safe" title="<?php echo htmlspecialchars($service['name']); ?>"><?php echo htmlspecialchars($service['name']); ?></h1>
                
                <div class="flex items-center gap-4 mb-6 pb-6 border-b border-gray-100">
                    <div class="flex items-center gap-1">
                        <?php for($i=1; $i<=5; $i++): ?>
                            <svg class="w-4 h-4" style="fill: <?php echo ($i <= round((float)($stats['avg_rating'] ?? 0))) ? '#FBBF24' : '#E2E8F0'; ?>;" viewBox="0 0 20 20">
                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                            </svg>
                        <?php endfor; ?>
                        <?php if ($review_count > 0): ?>
                            <a href="reviews.php?service_id=<?php echo $service_id; ?>" class="text-sm text-gray-500 hover:text-blue-500 hover:underline ml-1">
                                (<?php echo number_format($review_count); ?> Reviews)
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="h-4 w-px bg-gray-200"></div>
                    <div class="text-sm text-gray-500"><?php echo $sold_display; ?> Sold</div>
                </div>

                <form action="" method="POST" enctype="multipart/form-data" id="serviceForm" data-pf-skip-validation="true" target="_top" novalidate>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="calculated_unit_price" id="calculated-unit-price" value="0">
                    <input type="hidden" name="calculated_estimated_price" id="calculated-estimated-price" value="0">
                    
                    <!-- Estimated Price Display -->
                    <div id="estimated-price-display" style="position:sticky;top:80px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;z-index:10;">
                        <div style="border-top:1px solid #e5e7eb;padding-top:1rem;display:flex;justify-content:space-between;align-items:center;">
                            <span style="font-size:1.125rem;color:#0a2530;font-weight:800;">Estimated Price:</span>
                            <span id="estimated-total" style="font-size:1.5rem;color:#0a2530;font-weight:900;">₱0</span>
                        </div>
                        <div style="margin-top:0.5rem;font-size:0.875rem;color:#64748b;text-align:right;font-weight:500;">
                            Quantity: <span id="qty-display">1</span> | Final price will be confirmed by staff
                        </div>
                    </div>
                    
                    <?php echo render_service_fields($service_id, $branches, $existing_data); ?>
                    
                    <div class="shopee-form-row pt-8 service-action-row">
                        <div style="width: 130px;"></div>
                        <div class="service-action-buttons">
                            <a href="<?php echo BASE_URL; ?>/customer/services.php" class="shopee-btn-outline" style="min-width: 100px;">Back</a>
                            <button type="submit" name="action" value="add_to_cart" class="shopee-btn-outline" style="min-width: 140px; display: flex; align-items: center; justify-content: center; gap: 0.5rem; white-space: nowrap; padding: 0.5rem 1.25rem;" title="Add to Cart">
                                <svg style="width: 1.125rem; height: 1.125rem; flex-shrink: 0; margin-right: 0.5rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                </svg>
                                <span>Add to Cart</span>
                            </button>
                            <button type="submit" name="action" value="inquire_now" class="shopee-btn-primary" style="min-width: 160px; display: flex; align-items: center; justify-content: center; padding: 0.5rem 1.25rem;">
                                <svg style="width: 1.125rem; height: 1.125rem; flex-shrink: 0; margin-right: 0.5rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
                                </svg>
                                <span>Inquire Now</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Product Ratings Section -->
        <?php
        $review_schema = printflow_review_schema();
        $review_aliases = printflow_service_name_aliases((string)$service['name']);
        $current_user_id = (int)get_user_id();
        $current_user_type = (string)($_SESSION['user_type'] ?? '');
        $current_customer_id = $current_user_type === 'Customer' ? $current_user_id : 0;
        $review_user_voted_sql = "(SELECT COUNT(*)
                FROM review_helpful rh
                WHERE rh.review_id = r.id
                  AND rh.user_id = ?
                  AND COALESCE(rh.user_type, ?) = ?)";
        $review_user_voted_types = 'iss';
        $review_user_voted_params = [
            $current_user_id,
            $current_user_type !== '' ? $current_user_type : 'Customer',
            $current_user_type !== '' ? $current_user_type : 'Customer'
        ];
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
        $review_cols = array_flip(array_column(db_query("SHOW COLUMNS FROM reviews") ?: [], 'Field'));
        $review_message_expr = isset($review_cols['comment']) ? 'r.comment' : (isset($review_cols['message']) ? 'r.message' : "''");
        $review_user_expr = isset($review_cols['user_id']) ? 'r.user_id' : (isset($review_cols['customer_id']) ? 'r.customer_id' : '0');
        $review_service_expr = !empty($review_schema['service_col']) ? 'r.' . $review_schema['service_col'] : "''";
        $review_created_expr = !empty($review_schema['created_col'])
            ? "CASE
                    WHEN r.{$review_schema['created_col']} IS NULL OR r.{$review_schema['created_col']} IN ('0000-00-00 00:00:00', '0000-00-00')
                    THEN COALESCE(o.updated_at, o.order_date)
                    ELSE r.{$review_schema['created_col']}
               END"
            : 'COALESCE(o.updated_at, o.order_date)';
        $review_video_expr = isset($review_cols['video_path']) ? 'r.video_path' : "''";
        $review_helpful_sql = '(SELECT COUNT(*) FROM review_helpful WHERE review_id = r.id) as helpful_count,';
        $review_voted_sql = $review_user_voted_sql . ' as user_voted';

        $reviews = [];
        if (!empty($review_aliases)) {
            $review_where_parts = [];
            $review_types = '';
            $review_params = [];

            if (!empty($review_schema['service_col'])) {
                $service_placeholders = implode(',', array_fill(0, count($review_aliases), '?'));
                $review_where_parts[] = "{$review_service_expr} COLLATE utf8mb4_unicode_ci IN ($service_placeholders)";
                $review_types .= str_repeat('s', count($review_aliases));
                array_push($review_params, ...$review_aliases);
            }

            $order_match_parts = [];
            $order_name_placeholders = implode(',', array_fill(0, count($review_aliases), '?'));
            $order_match_parts[] = "p.name COLLATE utf8mb4_unicode_ci IN ($order_name_placeholders)";
            $review_types .= str_repeat('s', count($review_aliases));
            array_push($review_params, ...$review_aliases);

            foreach ($review_aliases as $alias) {
                $order_match_parts[] = "oi.customization_data COLLATE utf8mb4_unicode_ci LIKE ?";
                $review_types .= 's';
                $review_params[] = '%' . $alias . '%';
            }

            $review_where_parts[] = "EXISTS (
                SELECT 1
                FROM order_items oi
                LEFT JOIN products p ON p.product_id = oi.product_id
                WHERE oi.order_id = r.order_id
                  AND (" . implode(' OR ', $order_match_parts) . ")
            )";

            $review_sql = "SELECT DISTINCT
                 r.id,
                 {$review_user_expr} AS user_id,
                 {$review_service_expr} AS service_type,
                 r.rating,
                 {$review_message_expr} AS comment,
                 {$review_video_expr} AS video_path,
                 {$review_created_expr} AS created_at,
                 c.first_name,
                 c.last_name,
                 c.profile_picture,
                 {$review_helpful_sql}
                 {$review_voted_sql}
                 FROM reviews r
                 LEFT JOIN orders o ON o.order_id = r.order_id
                 LEFT JOIN customers c ON {$review_user_expr} = c.customer_id
                 WHERE " . implode(' OR ', $review_where_parts) . "
                 ORDER BY {$review_created_expr} DESC";

            $review_types = $review_user_voted_types . $review_types;
            array_unshift($review_params, ...$review_user_voted_params);

            $reviews = db_query($review_sql, $review_types, $review_params) ?: [];
        }

        $total_reviews = count($reviews);
        $avg_rating = $total_reviews > 0 ? array_sum(array_column($reviews, 'rating')) / $total_reviews : 0;
        $rating_counts = [5=>0,4=>0,3=>0,2=>0,1=>0];
        $with_comments = 0; $with_media = 0;
        foreach ($reviews as $idx => $r) {
            $rt = (int)$r['rating'];
            if ($rt >= 1 && $rt <= 5) $rating_counts[$rt]++;
            if (!empty(trim($r['comment'] ?? ''))) $with_comments++;
            
            // Fetch all images for this review
            $r_imgs = db_query("SELECT image_path FROM review_images WHERE review_id = ?", "i", [$r['id']]) ?: [];
            
            // Fetch all replies for this review
            $r_replies = db_query("
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
            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;">
                <div style="display:flex;gap:2rem;align-items:center;flex-wrap:wrap;">
                    <div style="text-align:center;">
                        <div style="font-size:3rem;font-weight:700;color:#f97316;line-height:1;"><?php echo number_format($avg_rating,1); ?></div>
                        <div style="font-size:0.875rem;color:#6b7280;margin-top:0.25rem;">out of 5</div>
                        <div style="display:flex;gap:2px;margin-top:0.5rem;justify-content:center;">
                            <?php for($i=1;$i<=5;$i++): ?>
                                <svg width="22" height="22" fill="<?php echo ($i<=round($avg_rating))?'#f97316':'#d1d5db'; ?>" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <div style="flex:1;min-width:300px;">
                        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:1rem;">
                            <button class="poc-filter-btn active" data-filter="all" style="padding:0.5rem 1rem;border:1px solid #e5e7eb;border-radius:6px;background:white;cursor:pointer;font-size:0.875rem;transition:all 0.2s;">All</button>
                            <?php for($i=5;$i>=1;$i--): ?>
                                <button class="poc-filter-btn" data-filter="<?php echo $i; ?>" style="padding:0.5rem 1rem;border:1px solid #e5e7eb;border-radius:6px;background:white;cursor:pointer;font-size:0.875rem;transition:all 0.2s;"><?php echo $i; ?> Star (<?php echo $rating_counts[$i]; ?>)</button>
                            <?php endfor; ?>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
                            <button class="poc-filter-btn" data-filter="comments" style="padding:0.5rem 1rem;border:1px solid #e5e7eb;border-radius:6px;background:white;cursor:pointer;font-size:0.875rem;transition:all 0.2s;">With Comments (<?php echo $with_comments; ?>)</button>
                            <button class="poc-filter-btn" data-filter="media" style="padding:0.5rem 1rem;border:1px solid #e5e7eb;border-radius:6px;background:white;cursor:pointer;font-size:0.875rem;transition:all 0.2s;">With Media (<?php echo $with_media; ?>)</button>
                        </div>
                    </div>
                </div>
            </div>
            <div id="poc-reviews-container">
                <?php foreach ($reviews_paged as $review):
                    $reviewer_name = htmlspecialchars(trim(($review['first_name']??'').' '.($review['last_name']??'')));
                    $profile_pic = !empty($review['profile_picture']) ? get_profile_image($review['profile_picture']) : '';
                    $rating = (int)$review['rating'];
                    $comment = htmlspecialchars($review['comment'] ?? '');
                    $has_comment = !empty(trim($review['comment'] ?? ''));
                    $rev_imgs = $review['images'] ?? [];
                    $has_video = !empty($review['video_path']);
                    $has_media = !empty($rev_imgs) || $has_video;
                    $created_raw = trim((string)($review['created_at'] ?? ''));
                    $created_ts = $created_raw !== '' ? strtotime($created_raw) : false;
                    $created_label = ($created_ts !== false && $created_ts >= strtotime('2000-01-01 00:00:00'))
                        ? date('M j, Y g:i A', $created_ts)
                        : '';
                ?>
                <div id="review-<?php echo $review['id']; ?>" class="poc-review-item" data-rating="<?php echo $rating; ?>" data-has-comment="<?php echo $has_comment?'1':'0'; ?>" data-has-media="<?php echo $has_media?'1':'0'; ?>" style="padding:1.5rem;border-bottom:1px solid #e5e7eb;">
                    <div style="display:flex;gap:1rem;">
                        <div style="flex-shrink:0;">
                            <?php if($profile_pic): ?>
                                <img src="<?php echo $profile_pic; ?>" alt="<?php echo $reviewer_name; ?>" style="width:48px;height:48px;border-radius:50%;object-fit:cover;">
                            <?php else: ?>
                                <div style="width:48px;height:48px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-weight:600;color:#6b7280;"><?php echo strtoupper(substr($reviewer_name,0,1)?:'?'); ?></div>
                            <?php endif; ?>
                        </div>
                        <div style="flex:1;">
                            <div style="font-weight:600;color:#1f2937;margin-bottom:0.25rem;"><?php echo $reviewer_name; ?></div>
                            <div style="display:flex;gap:2px;margin-bottom:0.5rem;">
                                <?php for($i=1;$i<=5;$i++): ?>
                                    <svg width="16" height="16" fill="<?php echo ($i<=$rating)?'#f97316':'#d1d5db'; ?>" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                <?php endfor; ?>
                            </div>
                            <?php if ($created_label !== ''): ?>
                                <div style="font-size:0.875rem;color:#6b7280;margin-bottom:0.5rem;"><?php echo htmlspecialchars($created_label); ?></div>
                            <?php endif; ?>
                            <?php if($has_comment): ?><div style="color:#374151;line-height:1.6;margin-bottom:0.75rem;font-size:0.95rem;overflow-wrap:anywhere;word-break:break-word;"><?php echo nl2br($comment); ?></div><?php endif; ?>
                             
                             <?php if(!empty($rev_imgs)): ?>
                                <div style="display:flex; overflow-x:auto; gap:12px; margin-bottom:1rem; padding-bottom:10px; scrollbar-width: thin;">
                                    <?php foreach($rev_imgs as $imgIndex => $img): 
                                        $ipath = pf_normalize_service_media_path((string)($img['image_path'] ?? ''), $base_path, '');
                                    ?>
                                        <div style="flex: 0 0 140px; aspect-ratio:1; border-radius:12px; overflow:hidden; border:1px solid #e5e7eb; background: #f9fafb;">
                                            <img src="<?php echo htmlspecialchars($ipath); ?>" alt="Review image" style="width:100%; height:100%; object-fit:cover; cursor:pointer;" onclick="openReviewImageGallery(<?php echo (int)$review['id']; ?>, <?php echo (int)$imgIndex; ?>)" data-review-id="<?php echo (int)$review['id']; ?>" data-image-index="<?php echo (int)$imgIndex; ?>" class="review-image-item" onerror="this.closest('div').style.display='none'">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if($has_video): 
                                $video_sources = pf_review_video_candidates((string)($review['video_path'] ?? ''), $base_path, (int)$review['id']);
                                $vpath = $video_sources[0] ?? '';
                            ?>
                                <?php if ($vpath !== ''): ?>
                                    <div style="margin-bottom:0.75rem;">
                                        <button type="button" class="poc-media-trigger" data-media-type="video" data-media-src="<?php echo htmlspecialchars($vpath); ?>" aria-label="Play review video">
                                            <div class="poc-video-thumb">
                                                <video
                                                    controls
                                                    controlsList="nodownload"
                                                    disablePictureInPicture
                                                    playsinline
                                                    preload="metadata"
                                                    class="poc-video-preview"
                                                    oncontextmenu="return false"
                                                    onclick="event.stopPropagation();">
                                                    <?php echo pf_render_video_sources($video_sources); ?>
                                                </video>
                                            </div>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div style="margin-bottom:0.75rem;color:#6b7280;font-size:0.9rem;">Video not available</div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if (!empty($review['replies'])): ?>
                                <div style="margin-top: 1rem; padding: 1rem; background: #f9fafb; border-left: 3px solid #e5e7eb; border-radius: 6px;">
                                    <div style="font-size: 0.75rem; font-weight: 700; color: #374151; text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Staff Response</div>
                                    <?php foreach ($review['replies'] as $reply): ?>
                                        <div style="margin-bottom: 0.75rem; last-child: margin-bottom: 0;">
                                            <div style="color: #374151; font-size: 0.9rem; line-height: 1.5; overflow-wrap: anywhere; word-break: break-word;"><?php echo nl2br(htmlspecialchars($reply['reply_message'])); ?></div>
                                            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">
                                                <?php echo htmlspecialchars($reply['first_name'] . ' ' . $reply['last_name']); ?> &bull; <?php echo date('Y-m-d', strtotime($reply['created_at'])); ?>
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
            <?php echo render_pagination($poc_page, $poc_total_pages, ['service_id' => $service_id], 'rpage'); ?>
            <?php else: ?>
            <div class="poc-empty" style="text-align:center;padding:3rem 1rem;color:#6b7280;">
                <svg width="56" height="56" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                <p style="font-size:1rem;font-weight:600;margin:0.75rem 0 0.25rem;">No Reviews Yet</p>
                <p style="font-size:0.875rem;color:#9ca3af;">Be the first to review this product!</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="pocMediaModal" class="poc-media-modal" aria-hidden="true">
    <div class="poc-media-modal-inner" role="dialog" aria-modal="true" aria-label="Media viewer">
        <button type="button" id="pocMediaClose" class="poc-media-close" aria-label="Close media viewer">&times;</button>
        <button type="button" id="pocMediaPrev" class="poc-media-nav" aria-label="Previous image" hidden>
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </button>
        <img id="pocMediaImg" class="poc-media-full" alt="Media preview" hidden>
        <video id="pocMediaVideo" class="poc-media-full" controls controlsList="nodownload" disablePictureInPicture playsinline hidden oncontextmenu="return false">
            <source id="pocMediaVideoSource" src="" type="video/mp4">
        </video>
        <button type="button" id="pocMediaNext" class="poc-media-nav" aria-label="Next image" hidden>
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </button>
    </div>
</div>

<style>
.poc-section-title { font-size:1.1rem;font-weight:700;color:#111827;margin:0 0 0.75rem; }
.poc-filter-btn.active { background:#0a2530 !important;color:white !important;border-color:#0a2530 !important; }
.poc-filter-btn:hover { border-color:#0a2530;background:#f0f4f5; }
.poc-review-item { border-bottom:1px solid #f3f4f6;padding:1.25rem 0; color: #1f2937; }
.poc-review-item:last-child { border-bottom:none; }
.poc-empty { text-align:center;padding:3rem 1rem;color:#6b7280; }
.helpful-btn { display:inline-flex;align-items:center;gap:5px;padding:4px 0;border:none;background:transparent;color:#9ca3af;font-size:0.82rem;font-weight:400;cursor:pointer;transition:color 0.2s; }
.helpful-btn:hover { color:#6b7280; }
.helpful-btn.voted { color:#f97316; }
.helpful-btn.voted svg { fill:#f97316; }
.poc-media-trigger { border:none; background:none; padding:0; cursor:pointer; }
.poc-media-thumb { display:block; }
.poc-video-thumb { position:relative; display:inline-block; max-width:240px; border-radius:8px; border:1px solid #e5e7eb; overflow:hidden; background:#0f172a; }
.poc-video-preview { display:block; width:100%; height:auto; background:#0f172a; }
.poc-media-modal { position:fixed; inset:0; background:rgba(0,0,0,0.85); display:none; align-items:center; justify-content:center; padding:1.5rem; z-index:100000; }
.poc-media-modal.is-open { display:flex; }
.poc-media-modal-inner { position:relative; max-width:90vw; max-height:90vh; }
.poc-media-full { max-width:90vw; max-height:90vh; border-radius:8px; box-shadow:0 20px 60px rgba(0,0,0,0.5); background:#0b1220; }
.poc-media-close { position:absolute; top:-12px; right:-12px; width:36px; height:36px; border-radius:999px; border:none; background:#111827; color:#fff; font-size:1.5rem; line-height:1; cursor:pointer; box-shadow:0 10px 30px rgba(0,0,0,0.35); }
.poc-media-nav { position:absolute; top:50%; transform:translateY(-50%); width:40px; height:40px; border:none; border-radius:999px; background:rgba(2,6,23,0.76); color:#fff; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; z-index:5; }
#pocMediaPrev { left:-52px; }
#pocMediaNext { right:-52px; }

.service-media-video-wrap { background: #0b1220; display: flex; align-items: center; justify-content: center; padding: 0; box-sizing: border-box; }
.service-media-video { width: 100%; height: 100%; display: block; object-fit: cover; background: #0b1220; border-radius: 0; }
.carousel-arrow:hover { background: rgba(0,0,0,0.75) !important; color: white !important; transform: translateY(-50%) scale(1.15) !important; }
.carousel-thumbnail:hover { border-color: #0d9488 !important; opacity: 0.8; }
.shopee-opt-group { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: flex-start; flex-direction: row; }
.shopee-opt-group .field-error { flex-basis: 100%; width: 100%; }
.field-price-display { animation: slideIn 0.3s ease-out; }
@keyframes slideIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
.shopee-opt-btn { display: inline-flex; align-items: center; justify-content: center; padding: 0.5rem 1rem; border: 2px solid #e5e7eb; border-radius: 0.5rem; background: white; cursor: pointer; transition: all 0.2s; font-size: 0.875rem; font-weight: 500; color: #374151; min-height: 2.5rem; text-align:center; white-space:normal; overflow-wrap:anywhere; word-break:break-word; }
.shopee-opt-btn:hover { border-color: #0d9488; background: #f0fdfa; }
.shopee-opt-btn.active { border-color: #0d9488; background: #0d9488; color: white; }
.quantity-container:hover { border-color: #e5e7eb !important; background: white !important; }
textarea.shopee-opt-btn:hover, textarea.shopee-opt-btn:focus { border-color: #e5e7eb !important; background: white !important; outline: none; }
.notes-textarea { font-size: 0.875rem; font-weight: 500; color: #374151; resize: none !important; overflow-y: auto !important; min-height: 100px !important; max-height: 100px !important; scrollbar-width: thin; scrollbar-color: #cbd5e1 #f1f5f9; }
.notes-textarea::placeholder { font-size: 0.875rem; font-weight: 500; color: #9ca3af; }
.text-ellipsis-single { display:inline-block; max-width:100%; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:bottom; }
.service-name-safe { overflow-wrap:anywhere; word-break:break-word; }
.service-copy-safe { overflow-wrap:anywhere; word-break:break-word; }
textarea.notes-textarea { resize: none !important; }
textarea.notes-textarea::-webkit-resizer { display: none !important; }
.notes-textarea::-webkit-scrollbar { width: 8px; }
.notes-textarea::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
.notes-textarea::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
.notes-textarea::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
.qty-input-field::-webkit-outer-spin-button, .qty-input-field::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.qty-input-field[type=number] { -moz-appearance: textfield; appearance: textfield; }
.dim-label { font-size:0.7rem;color:#94a3b8;font-weight:600;margin-bottom:4px;display:block;text-transform:uppercase; }
.shopee-form-row { display: flex; gap: 1rem; margin-bottom: 1.5rem; align-items: flex-start; position: relative; flex-wrap: wrap; }
.shopee-form-label { min-width: 130px; padding-top: 0.5rem; font-size: 0.875rem; font-weight: 600; color: #374151; flex-shrink: 0; overflow-wrap:anywhere; word-break:break-word; }
.shopee-form-field { flex: 1; position: relative; display: flex !important; flex-direction: column !important; min-width: 0; gap: 4px; }
.service-action-row { align-items: center; margin-bottom: 0; }
.service-action-row > div:first-child { width: 130px; flex: 0 0 130px; }
.service-action-buttons { flex: 1; display: flex; justify-content: flex-start; align-items: center; gap: 0.75rem; flex-wrap: nowrap; min-width: 0; }
.service-action-buttons > a,
.service-action-buttons > button { flex: 0 0 auto; }
.service-action-buttons > button[name="action"][value="inquire_now"] { flex: 1 1 auto; }
@media (max-width: 760px) {
    .service-action-row > div:first-child { display: none; }
    .service-action-buttons { justify-content: stretch; flex-wrap: wrap; }
    .service-action-buttons > a,
    .service-action-buttons > button { flex: 1 1 100%; width: 100%; }
}
@media (max-width: 640px) {
    .sticky-image-container,
    #estimated-price-display { position: static !important; top: auto !important; }
    .single-media-frame,
    #image-carousel { height: min(72vw, 320px) !important; max-height: 320px !important; }
    .service-media-video-wrap { padding: 0; }
    .shopee-card { padding: 1rem !important; gap: 1rem !important; }
    .shopee-form-section { padding-right: 0 !important; }
    .shopee-form-field > .input-field,
    .shopee-form-field > .shopee-opt-btn,
    .shopee-form-field > .shopee-opt-group,
    .shopee-form-field select,
    .shopee-form-field textarea,
    .shopee-form-field input[type="date"],
    .shopee-form-field input[type="text"],
    .shopee-form-field input[type="number"] { width: 100% !important; max-width: 100% !important; }
    .shopee-opt-group { width: 100%; }
    .poc-video-thumb { max-width: 100%; }
}
.field-error { display: flex !important; align-items: center; gap: 0.375rem; color: #ef4444; font-size: 0.875rem; margin-top: 0.5rem; padding-left: 0; width: 100% !important; min-width: 100% !important; flex-basis: 100% !important; order: 999; clear: both; }
.field-error::before { content: '⚠'; font-size: 1rem; flex-shrink: 0; }
.field-invalid { border-color: #ef4444 !important; }
.input-field, .shopee-opt-group, .shopee-qty-control { margin-top: 0; }
</style>

<script>
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
    const prevBtn = document.getElementById('pocMediaPrev');
    const nextBtn = document.getElementById('pocMediaNext');
    let modalGallery = [];
    let modalGalleryIndex = 0;

    if (!modal || !modalImg || !modalVideo || !modalVideoSource || !closeBtn || !prevBtn || !nextBtn) return;

    const renderGalleryNav = () => {
        const show = modalGallery.length > 1 && !modalImg.hidden;
        prevBtn.hidden = !show;
        nextBtn.hidden = !show;
    };

    const renderGalleryImage = () => {
        const src = modalGallery[modalGalleryIndex] || '';
        if (!src) return;
        modalVideo.hidden = true;
        modalImg.hidden = false;
        modalImg.src = src;
        renderGalleryNav();
    };

    const openMedia = (type, src) => {
        if (!src) return;
        modalGallery = [];
        modalGalleryIndex = 0;
        if (type === 'video') {
            modalImg.hidden = true;
            modalVideo.hidden = false;
            modalVideoSource.src = src;
            modalVideo.load();
            modalVideo.play().catch(() => {});
            renderGalleryNav();
        } else {
            modalVideo.hidden = true;
            modalImg.hidden = false;
            modalImg.src = src;
            renderGalleryNav();
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
        modalGallery = [];
        modalGalleryIndex = 0;
        prevBtn.hidden = true;
        nextBtn.hidden = true;
        document.body.style.overflow = '';
    };

    window.openReviewImageGallery = (reviewId, startIndex) => {
        const imgs = Array.from(document.querySelectorAll('.review-image-item[data-review-id="' + Number(reviewId) + '"]'));
        modalGallery = imgs.map((img) => img.getAttribute('src')).filter(Boolean);
        if (!modalGallery.length) return;
        modalGalleryIndex = Math.max(0, Math.min(Number(startIndex) || 0, modalGallery.length - 1));
        renderGalleryImage();
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    };

    prevBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        if (!modalGallery.length) return;
        modalGalleryIndex = (modalGalleryIndex - 1 + modalGallery.length) % modalGallery.length;
        renderGalleryImage();
    });

    nextBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        if (!modalGallery.length) return;
        modalGalleryIndex = (modalGalleryIndex + 1) % modalGallery.length;
        renderGalleryImage();
    });

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
    const basePath = <?php echo json_encode(rtrim((string)$base_path, '/')); ?>;
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

<?php echo get_service_field_scripts(); ?>

<script>
var currentImageIndex = 0;
var carouselServiceId = <?php echo (int)$service_id; ?>;
var totalImages = <?php echo count($display_images); ?>;
var isAnimating = false;

function getAllCarouselItems() {
    const all = Array.from(document.querySelectorAll('.carousel-image, .carousel-item'));
    all.sort((a, b) => parseInt(a.dataset.index) - parseInt(b.dataset.index));
    return all;
}

function updateArrowVisibility() {
    const prevBtn = document.getElementById('carousel-prev');
    const nextBtn = document.getElementById('carousel-next');
    if (prevBtn) prevBtn.style.display = currentImageIndex === 0 ? 'none' : 'flex';
    if (nextBtn) nextBtn.style.display = currentImageIndex === totalImages - 1 ? 'none' : 'flex';
}

function updateThumbnailBorder() {
    document.querySelectorAll('.carousel-thumbnail').forEach(thumb => {
        const active = parseInt(thumb.dataset.index) === currentImageIndex;
        thumb.style.borderColor = active ? '#53c5e0' : 'rgba(83,197,224,0.15)';
    });
}

function updateCarouselVideos(items) {
    let hasVideo = false;
    items.forEach((item, i) => {
        if (!item.classList.contains('carousel-item')) return;
        const vid = item.querySelector('video');
        if (!vid) return;
        if (i === currentImageIndex) {
            vid.play().catch(() => {});
            hasVideo = true;
            const sharedIcon = document.getElementById('shared-mute-icon');
            if (sharedIcon && typeof MUTE_PATH !== 'undefined') {
                sharedIcon.innerHTML = vid.muted ? MUTE_PATH : UNMUTE_PATH;
            }
        } else {
            vid.pause();
            try { vid.currentTime = 0; } catch (e) {}
        }
    });

    const sharedMuteBtn = document.getElementById('shared-mute-btn');
    if (sharedMuteBtn) {
        sharedMuteBtn.style.display = hasVideo ? 'flex' : 'none';
        if (hasVideo) sharedMuteBtn.setAttribute('onclick', 'toggleMute(' + currentImageIndex + ')');
    }
}

function setCarouselState(index) {
    const items = getAllCarouselItems();
    if (items.length === 0) return;
    if (items.length !== totalImages) totalImages = items.length;

    currentImageIndex = Math.max(0, Math.min(parseInt(index, 10) || 0, totalImages - 1));
    window.__pfServiceCarouselState = {
        serviceId: carouselServiceId,
        imageIndex: currentImageIndex
    };

    const carousel = document.getElementById('image-carousel');
    if (carousel) carousel.dataset.currentIndex = currentImageIndex;

    items.forEach((item, i) => {
        item.style.left = ((i - currentImageIndex) * 100) + '%';
    });

    const counter = document.getElementById('current-image');
    if (counter) counter.textContent = currentImageIndex + 1;
    updateCarouselVideos(items);
    updateArrowVisibility();
    updateThumbnailBorder();
}

function changeImage(direction) {
    if (isAnimating) return false;

    const delta = parseInt(direction, 10) || 0;
    const newIndex = currentImageIndex + delta;
    if (delta === 0 || newIndex < 0 || newIndex >= totalImages) return false;

    const items = getAllCarouselItems();
    if (!items.length) return false;
    isAnimating = true;

    const oldIndex = currentImageIndex;
    const oldItem = items[oldIndex];
    const newItem = items[newIndex];
    currentImageIndex = newIndex;

    if (newItem) newItem.style.left = delta > 0 ? '100%' : '-100%';
    if (newItem) newItem.offsetHeight;
    if (oldItem) oldItem.style.left = delta > 0 ? '-100%' : '100%';
    if (newItem) newItem.style.left = '0';

    const carousel = document.getElementById('image-carousel');
    if (carousel) carousel.dataset.currentIndex = currentImageIndex;
    window.__pfServiceCarouselState = {
        serviceId: carouselServiceId,
        imageIndex: currentImageIndex
    };

    const counter = document.getElementById('current-image');
    if (counter) counter.textContent = currentImageIndex + 1;
    updateCarouselVideos(items);
    updateArrowVisibility();
    updateThumbnailBorder();

    setTimeout(() => {
        isAnimating = false;
        getAllCarouselItems().forEach((item, i) => {
            item.style.left = ((i - currentImageIndex) * 100) + '%';
        });
    }, 420);
    return false;
}

function goToImage(index) {
    if (isAnimating) return false;
    index = parseInt(index, 10) || 0;
    if (index === currentImageIndex || index < 0 || index >= totalImages) return false;
    const direction = index > currentImageIndex ? 1 : -1;
    currentImageIndex = index - direction;
    return changeImage(direction);
}

var MUTE_PATH = '<path d="M16.5 12A4.5 4.5 0 0014 7.97v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51A8.796 8.796 0 0021 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06A8.99 8.99 0 0017.73 18l2 2.01L21 18.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/>';
var UNMUTE_PATH = '<path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM16.5 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/>';

function toggleMute(index) {
    const vid = document.getElementById('carousel-video-' + index);
    const sharedIcon = document.getElementById('shared-mute-icon');
    if (!vid) return;
    vid.muted = !vid.muted;
    if (sharedIcon) sharedIcon.innerHTML = vid.muted ? MUTE_PATH : UNMUTE_PATH;
}

function toggleSingleMute() {
    const vid = document.getElementById('single-video');
    const icon = document.getElementById('single-mute-icon');
    if (!vid) return;
    vid.muted = !vid.muted;
    if (icon) icon.innerHTML = vid.muted ? MUTE_PATH : UNMUTE_PATH;
}

document.addEventListener('DOMContentLoaded', function() {
    if (window.__pfServiceCarouselState && window.__pfServiceCarouselState.serviceId === carouselServiceId) {
        currentImageIndex = parseInt(window.__pfServiceCarouselState.imageIndex || 0, 10) || 0;
    } else {
        currentImageIndex = 0;
    }
    setCarouselState(currentImageIndex);
});

window.changeImage = changeImage;
window.goToImage = goToImage;
window.toggleMute = toggleMute;
window.toggleSingleMute = toggleSingleMute;

function bindCarouselControls() {
    document.querySelectorAll('.carousel-thumbnail[data-index]').forEach(thumb => {
        if (thumb.dataset.pfCarouselBound === '1') return;
        thumb.dataset.pfCarouselBound = '1';
        thumb.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            goToImage(parseInt(this.dataset.index, 10) || 0);
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindCarouselControls);
} else {
    bindCarouselControls();
}
document.addEventListener('turbo:load', bindCarouselControls);

document.addEventListener('keydown', function(e) {
    if (totalImages > 1) {
        if (e.key === 'ArrowLeft') changeImage(-1);
        if (e.key === 'ArrowRight') changeImage(1);
    }
});
</script>

<script>
// Estimated Price Calculation System - Global scope
window.calculateEstimatedPrice = window.calculateEstimatedPrice || null;

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('serviceForm');
    if (!form) return;
    
    // Get base price from PHP
    const basePrice = <?php echo (float)($service['base_price'] ?? 0); ?>;
    
    window.calculateEstimatedPrice = function() {
        let optionsTotal = 0;
        
        // Calculate price from radio buttons
        const checkedRadios = form.querySelectorAll('input[type="radio"].pricing-field:checked');
        checkedRadios.forEach(radio => {
            const price = parseFloat(radio.getAttribute('data-price') || 0);
            optionsTotal += price;
        });
        
        // Calculate price from select dropdowns
        const selects = form.querySelectorAll('select.pricing-field');
        selects.forEach(select => {
            const selectedOption = select.options[select.selectedIndex];
            if (selectedOption && selectedOption.value) {
                const price = parseFloat(selectedOption.getAttribute('data-price') || 0);
                optionsTotal += price;
            }
        });
        
        // Calculate price from dimension buttons
        const activeDimensionBtn = form.querySelector('button.shopee-opt-btn.pricing-field.active[data-price]');
        if (activeDimensionBtn) {
            const price = parseFloat(activeDimensionBtn.getAttribute('data-price') || 0);
            optionsTotal += price;
        }

        // Nested fields under radio/select options (only when container is visible)
        form.querySelectorAll('.nested-fields-container').forEach(function(container) {
            var cs = window.getComputedStyle(container);
            if (cs.display === 'none' || cs.visibility === 'hidden') return;
            if (!container.offsetParent) return;

            container.querySelectorAll('select').forEach(function(sel) {
                var opt = sel.options[sel.selectedIndex];
                if (opt && opt.value) {
                    optionsTotal += parseFloat(opt.getAttribute('data-price') || '0') || 0;
                }
            });
            container.querySelectorAll('input[type="radio"]:checked').forEach(function(radio) {
                optionsTotal += parseFloat(radio.getAttribute('data-price') || '0') || 0;
            });
            container.querySelectorAll('.shopee-opt-group').forEach(function(grp) {
                var btn = grp.querySelector('button.shopee-opt-btn.active[data-price]');
                if (btn) {
                    optionsTotal += parseFloat(btn.getAttribute('data-price') || '0') || 0;
                }
            });
        });
        
        // Get quantity (field name comes from admin service_field_configs)
        const qtyInput = form.querySelector('.pf-service-quantity-input');
        const quantity = parseInt(qtyInput?.value || 1);
        
        // Calculate totals
        const unitPrice = basePrice + optionsTotal;
        const estimatedTotal = unitPrice * quantity;
        
        // Update display
        const estimatedTotalEl = document.getElementById('estimated-total');
        const qtyDisplayEl = document.getElementById('qty-display');
        const unitPriceInputEl = document.getElementById('calculated-unit-price');
        const estimatedPriceInputEl = document.getElementById('calculated-estimated-price');
        
        if (estimatedTotalEl) {
            estimatedTotalEl.textContent = '₱' + estimatedTotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
        
        if (qtyDisplayEl) {
            qtyDisplayEl.textContent = quantity;
        }
        
        if (unitPriceInputEl) {
            unitPriceInputEl.value = unitPrice.toFixed(2);
        }
        
        if (estimatedPriceInputEl) {
            estimatedPriceInputEl.value = estimatedTotal.toFixed(2);
        }
    };
    
    // Listen to all form changes
    form.addEventListener('change', window.calculateEstimatedPrice);
    form.addEventListener('input', function(e) {
        if (e.target.classList && e.target.classList.contains('pf-service-quantity-input')) {
            window.calculateEstimatedPrice();
        }
    });
    
    // Initial calculation
    window.calculateEstimatedPrice();
});
</script>

<script>
// Final Validation Script Update - v4.0.0
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('serviceForm');
    
    if (form) {
        const getRowValueState = (row) => {
            let rowHasValue = false;
            let hasControls = false;

            row.querySelectorAll('select').forEach(select => {
                hasControls = true;
                if (select.value && select.value !== '') rowHasValue = true;
            });

            const radios = row.querySelectorAll('input[type="radio"]');
            if (radios.length > 0) {
                hasControls = true;
                if (row.querySelector('input[type="radio"]:checked')) rowHasValue = true;
            }

            const widthHidden = row.querySelector('[data-dimension-role="width"], #width_hidden');
            const heightHidden = row.querySelector('[data-dimension-role="height"], #height_hidden');
            const activeDimensionButton = row.querySelector('[data-dimension-choice="1"].active[data-width][data-height]');
            const nestedDimension = row.querySelector('input[id^="nested-hidden-"]');
            if (activeDimensionButton) {
                hasControls = true;
                if (widthHidden) widthHidden.value = activeDimensionButton.dataset.width || '';
                if (heightHidden) heightHidden.value = activeDimensionButton.dataset.height || '';
                if (activeDimensionButton.dataset.width && activeDimensionButton.dataset.height) rowHasValue = true;
            }
            if (widthHidden && heightHidden) {
                hasControls = true;
                if (widthHidden.value && heightHidden.value) rowHasValue = true;
            }
            if (nestedDimension) {
                hasControls = true;
                if (String(nestedDimension.value || '').trim() !== '') rowHasValue = true;
            }

            row.querySelectorAll('input[type="date"]').forEach(date => {
                hasControls = true;
                if (date.value) rowHasValue = true;
            });

            row.querySelectorAll('input[type="file"]').forEach(file => {
                hasControls = true;
                if (file.files && file.files.length > 0) rowHasValue = true;
            });

            row.querySelectorAll('input[type="text"], input[type="number"], textarea').forEach(input => {
                if (input.type === 'hidden' || input.dataset.dimensionRole || input.id === 'width_hidden' || input.id === 'height_hidden') return;
                hasControls = true;
                if (input.value && input.value.trim() !== '') rowHasValue = true;
            });

            return { hasControls, rowHasValue };
        };

        const removeRowErrors = (row) => {
            if (!row) return;
            row.querySelectorAll('.field-error').forEach(el => el.remove());
            row.querySelectorAll('.field-invalid').forEach(el => el.classList.remove('field-invalid'));
        };

        const clearRowErrorIfSatisfied = (row) => {
            if (!row || row.offsetParent === null) return;
            const labelEl = row.querySelector('.shopee-form-label');
            if (!labelEl || !labelEl.innerText.includes('*')) return;
            if (getRowValueState(row).rowHasValue) removeRowErrors(row);
        };

        form.addEventListener('submit', function(e) {
            // Clear all previous field errors
            document.querySelectorAll('.field-error').forEach(el => el.remove());
            form.querySelectorAll('.field-invalid').forEach(el => el.classList.remove('field-invalid'));
            
            let hasError = false;
            let firstErrorField = null;
            
            const setError = (field, message) => {
                showFieldError(field, message);
                if (!firstErrorField) firstErrorField = field;
                hasError = true;
            };

            // Process every form row to check for required fields (*)
            const rows = form.querySelectorAll('.shopee-form-row');
            rows.forEach(row => {
                if (row.offsetParent === null) return;

                const labelEl = row.querySelector('.shopee-form-label');
                if (!labelEl) return;

                const labelText = labelEl.innerText.trim();
                const isRequired = labelText.includes('*');
                if (!isRequired) return;

                // Extract a clean field name for the error message
                const fieldName = labelText.replace('*', '').replace('id', '').replace('ID', '').trim();
                const { hasControls, rowHasValue } = getRowValueState(row);

                // Final check: If row is required (*) but has NO value detected in ANY control
                if (hasControls && !rowHasValue) {
                    const firstControl = row.querySelector('select, label, input:not([type="hidden"]), textarea') || row;
                    const finalMessage = fieldName.includes('Branch') ? 'Please select a branch for pickup.' : `${fieldName} is required.`;
                    setError(firstControl, finalMessage);
                }
            });
            
            if (hasError) {
                e.preventDefault();
                // Scroll to first error
                if (firstErrorField) {
                    const errorRow = firstErrorField.closest('.shopee-form-row') || firstErrorField;
                    errorRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                // Hide top error message if exists
                const topError = document.getElementById('error-message');
                if (topError) topError.style.display = 'none';
            }
        });

        ['input', 'change', 'click'].forEach(eventName => {
            form.addEventListener(eventName, function(e) {
                const row = e.target.closest('.shopee-form-row');
                if (!row) return;
                setTimeout(() => clearRowErrorIfSatisfied(row), 0);
            });
        });

        document.addEventListener('click', function(e) {
            const dimensionButton = e.target.closest('[data-dimension-choice="1"], [data-dimension-others="1"]');
            if (!dimensionButton || !form.contains(dimensionButton)) return;
            const row = dimensionButton.closest('.shopee-form-row');
            setTimeout(() => clearRowErrorIfSatisfied(row), 0);
        }, true);
    }
    
    function showFieldError(element, message) {
        if (!element) return;
        
        // Find the top-most field container for this row to ensure it appears at the absolute bottom
        const row = element.closest('.shopee-form-row');
        const container = row ? row.querySelector('.shopee-form-field') : element.closest('.shopee-form-field');
        if (row) {
            row.querySelectorAll('.field-error').forEach(el => el.remove());
            row.querySelectorAll('select, input, textarea, .shopee-opt-btn').forEach(el => el.classList.remove('field-invalid'));
        }

        const errorSpan = document.createElement('span');
        errorSpan.className = 'field-error';
        errorSpan.textContent = message;
        element.classList.add('field-invalid');
        
        if (container) {
            // Force it to the absolute end of the flex-column container
            container.appendChild(errorSpan);
        } else {
            // Absolute fallback
            element.parentNode.insertBefore(errorSpan, element.nextSibling);
        }
    }
});

</script>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>
