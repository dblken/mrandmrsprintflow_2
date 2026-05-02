<?php
/**
 * T-Shirt Printing - Service Order Form
 * PrintFlow - Service-Based Ordering
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_order_helper.php';

require_role('Customer');
require_once __DIR__ . '/../includes/require_customer_profile_complete.php';
$customer_id = get_user_id();

$error = '';

// Print placement options with image mapping (placement name -> image filename)
// One image per unique print type; Left/Right Chest combined to avoid duplicate image
$placement_options = [
    'Front Center Print' => 'Front Center Print.webp',
    'Back Upper Print' => 'Back Upper Print.webp',
    'Left/Right Chest Print' => 'Left Right Chest Print.webp',
    'Bottom Hem Print' => 'Buttom Hem Print.webp',
    'Sleeve Print' => 'Sleeve Print.webp',
    'Long Sleeve Arm Print' => 'Long Sleeve Arm Print.webp',
];

$img_base = (defined('BASE_URL') ? BASE_URL : '/printflow') . '/public/assets/images/tshirt_replacement/';
$edit_item_key = trim((string)($_GET['edit_item'] ?? $_POST['edit_item'] ?? ''));
$is_edit_mode = false;
$edit_existing_item = null;

if ($edit_item_key !== '' && isset($_SESSION['cart'][$edit_item_key]) && is_array($_SESSION['cart'][$edit_item_key])) {
    $candidate = $_SESSION['cart'][$edit_item_key];
    $cat_name = strtolower(((string)($candidate['category'] ?? '')) . ' ' . ((string)($candidate['name'] ?? '')));
    if (strpos($cat_name, 't-shirt') !== false || strpos($cat_name, 'shirt') !== false) {
        $is_edit_mode = true;
        $edit_existing_item = $candidate;
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $cust = (array)($candidate['customization'] ?? []);
            $saved_type = (string)($cust['shirt_type'] ?? '');
            $saved_color = (string)($cust['shirt_color'] ?? '');
            $saved_size = (string)($cust['size'] ?? '');
            $known_types = ['Crew Neck', 'V-Neck', 'Polo', 'Raglan', 'Long Sleeve'];
            $known_colors = ['Black', 'White', 'Red', 'Blue', 'Navy', 'Grey'];
            $known_sizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'];
            $_POST['branch_id'] = (string)($candidate['branch_id'] ?? '');
            $_POST['shirt_source'] = (string)($cust['shirt_source'] ?? '');
            $_POST['shirt_type'] = in_array($saved_type, $known_types, true) ? $saved_type : 'Others';
            $_POST['shirt_type_other'] = in_array($saved_type, $known_types, true) ? '' : $saved_type;
            $_POST['shirt_color'] = in_array($saved_color, $known_colors, true) ? $saved_color : 'Other';
            $_POST['color_other'] = in_array($saved_color, $known_colors, true) ? '' : $saved_color;
            $_POST['sizes'] = in_array($saved_size, $known_sizes, true) ? $saved_size : 'Others';
            $_POST['sizes_other'] = in_array($saved_size, $known_sizes, true) ? '' : $saved_size;
            $_POST['print_placement'] = (string)($cust['print_placement'] ?? '');
            $_POST['lamination'] = (string)($cust['lamination'] ?? '');
            $_POST['quantity'] = (string)($candidate['quantity'] ?? ($cust['quantity'] ?? 1));
            $_POST['needed_date'] = (string)($cust['needed_date'] ?? '');
            $_POST['notes'] = (string)($cust['notes'] ?? '');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $branch_id = (int)($_POST['branch_id'] ?? 0);
    $shirt_source = trim($_POST['shirt_source'] ?? '');
    $shirt_type = trim($_POST['shirt_type'] ?? '');
    $shirt_type_other = trim($_POST['shirt_type_other'] ?? '');
    $shirt_color = trim($_POST['shirt_color'] ?? '');
    $color_other = trim($_POST['color_other'] ?? '');
    $sizes = trim($_POST['sizes'] ?? '');
    $sizes_other = trim($_POST['sizes_other'] ?? '');
    $print_placement = trim($_POST['print_placement'] ?? '');
    $lamination = trim($_POST['lamination'] ?? '');
    $design_type = trim($_POST['design_type'] ?? '');
    $print_color = trim($_POST['print_color'] ?? '');
    $text_content = trim($_POST['text_content'] ?? '');
    $font_style = trim($_POST['font_style'] ?? '');
    $font_size = trim($_POST['font_size'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 1);
    $needed_date = trim($_POST['needed_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $color_display = ($shirt_color === 'Other') ? $color_other : $shirt_color;
    $shirt_type_display = ($shirt_type === 'Others') ? $shirt_type_other : $shirt_type;

    $shop_provides = ($shirt_source === 'Shop provides shirt');
    $is_text_only = ($design_type === 'Text Only');
    $is_logo_only = ($design_type === 'Logo Only');
    $proceed = false;
    
    if ($branch_id <= 0) {
        $error = 'Please select a branch.';
    } elseif (empty($shirt_source)) {
        $error = 'Please select whether the shop or customer will provide the shirt.';
    } elseif (empty($design_type)) {
        $error = 'Please select a design type.';
    } elseif ($is_text_only && (empty($text_content) || empty($print_color))) {
        $error = 'Please provide text content and print color for text designs.';
    } elseif ((function_exists('mb_strlen') ? mb_strlen($notes) : strlen($notes)) > 500) {
        $error = 'Notes must not exceed 500 characters.';
    } elseif ($shop_provides) {
        if (empty($shirt_type_display) || empty($color_display) || empty($size) || empty($print_placement) || empty($lamination) || empty($needed_date) || $quantity < 1) {
            $error = 'Please fill in required fields: Shirt Type, Color, Sizes, Print Placement, Lamination, Needed Date, and Quantity.';
        } elseif ($shirt_type === 'Others' && empty($shirt_type_other)) {
            $error = 'Please enter your custom shirt type.';
        } elseif ($sizes === 'Others' && empty($sizes_other)) {
            $error = 'Please enter your custom size.';
        } elseif ($shirt_color === 'Other' && empty($color_other)) {
            $error = 'Please enter your custom shirt color.';
        } else {
            $proceed = true;
        }
    } else {
        if (empty($shirt_type_display) || empty($color_display) || empty($print_placement) || empty($lamination) || empty($needed_date) || $quantity < 1) {
            $error = 'Please fill in required fields: Shirt Type, Color, Print Placement, Lamination, Needed Date, and Quantity.';
        } elseif ($shirt_color === 'Other' && empty($color_other)) {
            $error = 'Please enter your custom shirt color.';
        } elseif ($shirt_type === 'Others' && empty($shirt_type_other)) {
            $error = 'Please enter your custom shirt type.';
        } else {
            $proceed = true;
        }
    }

    $has_new_upload = isset($_FILES['design_file']) && ($_FILES['design_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    if ($proceed && $is_logo_only && !$has_new_upload && !$is_edit_mode) {
        $error = 'Please upload your design file for logo printing.';
        $proceed = false;
    }

    if ($proceed) {
        $tmp_dest = '';
        $original_name = '';
        $mime = '';
        if ($has_new_upload) {
            $valid = service_order_validate_file($_FILES['design_file']);
            if (!$valid['ok']) {
                $error = $valid['error'];
                $proceed = false;
            } else {
                $original_name = $_FILES['design_file']['name'];
                $mime = $valid['mime'];
                $ext = pathinfo($original_name, PATHINFO_EXTENSION);
                $new_name = uniqid('tmp_') . '.' . $ext;
                $tmp_dest = service_order_temp_dir() . DIRECTORY_SEPARATOR . $new_name;
                if (!move_uploaded_file($_FILES['design_file']['tmp_name'], $tmp_dest)) {
                    $error = 'Failed to process uploaded file.';
                    $proceed = false;
                }
            }
        } elseif ($is_edit_mode && is_array($edit_existing_item)) {
            $tmp_dest = (string)($edit_existing_item['design_tmp_path'] ?? '');
            $original_name = (string)($edit_existing_item['design_name'] ?? '');
            $mime = (string)($edit_existing_item['design_mime'] ?? '');
        }

        if ($proceed) {
            $item_key = ($is_edit_mode && $edit_item_key !== '' && isset($_SESSION['cart'][$edit_item_key])) ? $edit_item_key : ('tshirt_' . time() . '_' . rand(100, 999));
            $_SESSION['cart'][$item_key] = [
                    'type' => 'Service',
                    'source_page' => 'services',
                    'name' => 'T-Shirt Printing',
                    'price' => 350.00,
                    'quantity' => $quantity,
                    'category' => 'T-Shirts',
                    'branch_id' => $branch_id,
                    'design_tmp_path' => $tmp_dest,
                    'design_name' => $original_name,
                    'design_mime' => $mime,
                    'customization' => [
                        'shirt_source' => $shirt_source,
                        'design_type' => $design_type,
                        'print_color' => $print_color,
                        'text_content' => $text_content,
                        'font_style' => $font_style,
                        'font_size' => $font_size,
                        'shirt_type' => $shirt_type_display,
                        'shirt_color' => $color_display,
                        'size' => $size ?? '',
                        'print_placement' => $print_placement,
                        'lamination' => $lamination,
                        'quantity' => $quantity,
                        'needed_date' => $needed_date,
                        'notes' => $notes
                    ]
            ];
            
            if (($_POST['action'] ?? '') === 'buy_now' || isset($_POST['buy_now'])) {
                redirect("order_review.php?item=" . urlencode($item_key));
            } else {
                redirect("cart.php");
            }
        }
    }
}

$page_title = 'Order T-Shirt - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';

$branches = db_query("SELECT id, branch_name FROM branches WHERE status = 'Active'");
$_svc = db_query("SELECT hero_image FROM services WHERE customer_link LIKE '%order_tshirt%' LIMIT 1");
$display_img = (!empty($_svc) && !empty($_svc[0]['hero_image'])) ? $_svc[0]['hero_image'] : '';
if ($display_img !== '' && strpos($display_img, 'http') === false && $display_img[0] !== '/') { $display_img = '/' . ltrim($display_img, '/'); }
?>

<div class="min-h-screen py-8">
    <div class="shopee-layout-container">
        <!-- Breadcrumb -->
        <div class="text-sm text-gray-500 mb-6 flex items-center gap-2">
            <a href="services.php" class="hover:text-blue-600">Services</a>
            <span>/</span>
            <span class="font-semibold text-gray-900">T-Shirt Printing</span>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="shopee-card">
            <!-- Left Side: Image -->
            <div class="shopee-image-section">
                <div class="sticky-image-container">
                    <div class="shopee-main-image-wrap">
                        <img src="<?php echo htmlspecialchars($display_img ?: 'https://placehold.co/600x600/f8fafc/0f172a?text=T-Shirt'); ?>" alt="T-Shirt Printing" class="shopee-main-image" onerror="this.src='https://placehold.co/600x600/f8fafc/0f172a?text=T-Shirt'">
                    </div>
                    
                    <div class="mt-6 p-4 bg-blue-50 border border-blue-100 rounded-lg">
                        <h4 class="text-xs font-bold text-blue-800 uppercase mb-2">Service Note</h4>
                        <p class="text-xs text-blue-700 leading-relaxed">
                            Please choose whether the shirt will be provided by the shop or by the customer. It is recommended that customers provide their own shirt so they can ensure the correct size and preferred quality for their use.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Right Side: Form -->
            <div class="shopee-form-section">
                <h1 class="text-2xl font-bold text-gray-900 mb-2">T-Shirt Printing</h1>
                
                <?php
                $stats = service_order_get_page_stats('order_tshirt');
                $raw_avg = (float)($stats['avg_rating'] ?? 0);
                $review_count = (int)($stats['review_count'] ?? 0);
                $sold_count = (int)($stats['sold_count'] ?? 0);
                $sold_display = $sold_count >= 1000 ? number_format($sold_count / 1000, 1) . 'k' : $sold_count;
                
                $_s_name = 'PrintFlow Service';
                $_s_row = db_query("SELECT name FROM services WHERE customer_link LIKE '%order_tshirt%' LIMIT 1");
                if(!empty($_s_row)) { $_s_name = $_s_row[0]['name']; }
                ?>
                <div class="flex items-center gap-4 mb-6 pb-6 border-b border-gray-100">
                    <div class="flex items-center gap-1">
                        <?php for($i=1; $i<=5; $i++): ?>
                            <svg class="w-4 h-4" style="fill: <?php echo ($i <= round($raw_avg)) ? '#FBBF24' : '#E2E8F0'; ?>;" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <?php endfor; ?>
                        
                        <?php if ($review_count > 0): ?>
                            <a href="reviews.php?service_id=<?php echo $stats['service_id']; ?>" class="text-sm text-gray-500 hover:text-blue-500 hover:underline ml-1 cursor-pointer">(<?php echo number_format($review_count); ?> Reviews)</a>
                        <?php endif; ?>
                    </div>
                    <div class="h-4 w-px bg-gray-200"></div>
                    <div class="text-sm text-gray-500"><?php echo $sold_display; ?> Sold</div>
                </div>

                <form action="" method="POST" enctype="multipart/form-data" id="tshirtForm" novalidate>
                    <?php echo csrf_field(); ?>
                    <?php if ($is_edit_mode): ?>
                        <input type="hidden" name="edit_item" value="<?php echo htmlspecialchars($edit_item_key); ?>">
                    <?php endif; ?>

                <div class="shopee-form-row">
                    <label class="shopee-form-label">Branch *</label>
                    <select name="branch_id" class="input-field shopee-form-field" required>
                        <?php foreach($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo ((string)($b['id']) === (string)($_POST['branch_id'] ?? '')) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="shopee-form-row">
                    <label class="shopee-form-label">Source *</label>
                    <div class="shopee-opt-group shopee-form-field">
                        <label class="shopee-opt-btn"><input type="radio" name="shirt_source" value="Shop will provide the shirt" style="display:none;" required> <span>Shop provides shirt</span></label>
                        <label class="shopee-opt-btn"><input type="radio" name="shirt_source" value="Customer will provide the shirt" style="display:none;" required> <span>Customer provides</span></label>
                    </div>
                </div>

                <div class="shopee-form-row">
                    <label class="shopee-form-label">Design *</label>
                    <div class="shopee-opt-group shopee-form-field">
                        <label class="shopee-opt-btn"><input type="radio" name="design_type" value="Logo Only" style="display:none;" required> <span>Logo Only</span></label>
                        <label class="shopee-opt-btn"><input type="radio" name="design_type" value="Text Only" style="display:none;" required> <span>Text Only</span></label>
                    </div>
                </div>

                <div class="shopee-form-row" id="upload-section">
                    <label class="shopee-form-label">Design *</label>
                    <div class="shopee-form-field">
                        <input type="file" name="design_file" id="design_file" accept=".jpg,.jpeg,.png,.pdf" class="input-field">
                        <?php if ($is_edit_mode): ?>
                            <p class="text-xs text-blue-500 mt-1 italic">Keep empty to retain current design.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="text-design-section" style="display: none;">
                    <div class="shopee-form-row">
                        <label class="shopee-form-label">Text *</label>
                        <input type="text" name="text_content" id="text_content" class="input-field shopee-form-field" placeholder="Content to be printed">
                    </div>
                    <div class="shopee-form-row">
                        <label class="shopee-form-label">Print Color *</label>
                        <input type="text" name="print_color" id="print_color" class="input-field shopee-form-field" placeholder="e.g. White, Gold">
                    </div>
                    <div class="shopee-form-row">
                        <label class="shopee-form-label">Style/Size</label>
                        <div class="flex gap-4 shopee-form-field">
                            <input type="text" name="font_style" id="font_style" class="input-field flex-1" placeholder="Font name">
                            <input type="text" name="font_size" id="font_size" class="input-field flex-1" placeholder="Size (e.g. 2in)">
                        </div>
                    </div>
                </div>

                <div class="shopee-form-row" id="shirt-type-section">
                    <label class="shopee-form-label">Shirt Type *</label>
                    <div class="shopee-form-field">
                        <div class="shopee-opt-group">
                            <label class="shopee-opt-btn"><input type="radio" name="shirt_type" value="Crew Neck" style="display:none;"> <span>Crew Neck</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="shirt_type" value="V-Neck" style="display:none;"> <span>V-Neck</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="shirt_type" value="Polo" style="display:none;"> <span>Polo</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="shirt_type" value="Raglan" style="display:none;"> <span>Raglan</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="shirt_type" value="Long Sleeve" style="display:none;"> <span>Long Sleeve</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="shirt_type" value="Others" style="display:none;"> <span>Others</span></label>
                        </div>
                        <div id="shirt-type-other-wrap" style="display: none; margin-top: 1rem;">
                            <input type="text" name="shirt_type_other" id="shirt_type_other" class="input-field" placeholder="Custom shirt type">
                        </div>
                    </div>
                </div>

                <div class="shopee-form-row">
                    <label class="shopee-form-label">Shirt Color *</label>
                    <div class="shopee-form-field">
                        <div class="shopee-opt-group">
                            <label class="shopee-opt-btn"><input type="radio" name="shirt_color" value="Black" style="display:none;" required> <span>Black</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="shirt_color" value="White" style="display:none;" required> <span>White</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="shirt_color" value="Red" style="display:none;" required> <span>Red</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="shirt_color" value="Blue" style="display:none;" required> <span>Blue</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="shirt_color" value="Navy" style="display:none;" required> <span>Navy</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="shirt_color" value="Grey" style="display:none;" required> <span>Grey</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="shirt_color" value="Other" style="display:none;" required> <span>Others</span></label>
                        </div>
                        <div id="color-other-wrap" style="display: none; margin-top: 1rem;">
                            <input type="text" name="color_other" id="color_other" class="input-field" placeholder="Custom color">
                        </div>
                    </div>
                </div>

                <div class="shopee-form-row" id="size-section" style="display: none;">
                    <label class="shopee-form-label">Shirt Size *</label>
                    <div class="shopee-form-field">
                        <div class="shopee-opt-group">
                            <label class="shopee-opt-btn"><input type="radio" name="sizes" value="XS" style="display:none;"> <span>XS</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="sizes" value="S" style="display:none;"> <span>S</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="sizes" value="M" style="display:none;"> <span>M</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="sizes" value="L" style="display:none;"> <span>L</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="sizes" value="XL" style="display:none;"> <span>XL</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="sizes" value="XXL" style="display:none;"> <span>XXL</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="sizes" value="XXXL" style="display:none;"> <span>XXXL</span></label>
                        </div>
                    </div>
                </div>

                <div class="shopee-form-row">
                    <label class="shopee-form-label">Placement *</label>
                    <div class="shopee-form-field">
                        <div class="placement-grid">
                            <?php foreach ($placement_options as $name => $img_file): 
                                $img_url = $img_base . rawurlencode($img_file);
                            ?>
                            <label class="placement-card" data-img="<?php echo htmlspecialchars($img_url); ?>" data-name="<?php echo htmlspecialchars($name); ?>">
                                <input type="radio" name="print_placement" value="<?php echo htmlspecialchars($name); ?>" style="display:none;" required>
                                <div class="placement-img-wrap">
                                    <img src="<?php echo $img_url; ?>" alt="<?php echo htmlspecialchars($name); ?>" onerror="this.src='https://placehold.co/100x100?text=Placement'">
                                </div>
                                <span class="placement-label"><?php echo htmlspecialchars($name); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- 6. Removed from here -->

                <div class="shopee-form-row">
                    <label class="shopee-form-label">Laminate *</label>
                    <div class="shopee-opt-group shopee-form-field">
                        <label class="shopee-opt-btn"><input type="radio" name="lamination" value="With Laminate" style="display:none;" required> <span>With Laminate</span></label>
                        <label class="shopee-opt-btn"><input type="radio" name="lamination" value="Without Laminate" style="display:none;"> <span>Without Laminate</span></label>
                    </div>
                </div>

                <div class="shopee-form-row">
                    <label class="shopee-form-label">Order Detail *</label>
                    <div class="shopee-form-field">
                        <div class="need-qty-row">
                            <div class="flex-1">
                                <label class="dim-label">Needed Date</label>
                                <input type="date" name="needed_date" id="needed_date" class="input-field" value="<?php echo htmlspecialchars($_POST['needed_date'] ?? ''); ?>" required min="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="flex-1">
                                <label class="dim-label">Quantity</label>
                                <div class="shopee-qty-control">
                                    <button type="button" onclick="tshirtDecreaseQty()" class="shopee-qty-btn">−</button>
                                    <input type="number" id="quantity-input" name="quantity" class="shopee-qty-input" min="1" value="<?php echo (int)($_POST['quantity'] ?? ($_GET['qty'] ?? 1)); ?>">
                                    <button type="button" onclick="tshirtIncreaseQty()" class="shopee-qty-btn">+</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="shopee-form-row pt-4">
                    <label class="shopee-form-label">Notes</label>
                    <textarea name="notes" rows="3" class="input-field shopee-form-field" placeholder="Any special instructions..." maxlength="500"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                </div>

                <div class="shopee-form-row pt-8">
                    <div style="width: 130px;"></div>
                    <div class="flex gap-4 flex-1">
                        <?php if ($is_edit_mode): ?>
                            <a href="cart.php" class="shopee-btn-outline" style="flex: 1;">Cancel</a>
                            <button type="submit" name="action" value="save_changes" class="shopee-btn-primary" style="flex: 1.5;">Save Changes</button>
                        <?php else: ?>
                            <a href="<?php echo BASE_URL; ?>/customer/services.php" class="shopee-btn-outline" style="flex: 1;">Back</a>
                            <button type="submit" name="action" value="add_to_cart" class="shopee-btn-outline" style="width:2.75rem;height:2.75rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;padding:0;" title="Add to Cart"><svg style="width:1.25rem;height:1.25rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg></button>
                            <button type="submit" name="action" value="buy_now" id="buyNowBtn" class="shopee-btn-primary" style="flex: 1.5;">Buy Now</button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
</div>

<style>
/* Service Specific Tweaks */
select, input, textarea, .input-field { border: 1px solid #53c5e0 !important; outline: none !important; box-shadow: none !important; border-radius: 0 !important; }
.dim-label { font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 4px; display: block; text-transform: uppercase; }
.need-qty-row { display: flex; gap: 16px; width: 100%; }

.placement-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; }
.placement-card { display: flex; flex-direction: column; align-items: center; cursor: pointer; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.5rem; background: #fff; transition: all 0.2s ease; }
.placement-card:hover { border-color: #0a2530; background: #f8fafc; }
.placement-card:has(input:checked) { border-color: #0a2530; background: #f0f9ff; box-shadow: 0 0 0 1px #0a2530; }
.placement-img-wrap { width: 100%; aspect-ratio: 1; border-radius: 6px; overflow: hidden; background: #f3f4f6; position: relative; }
.placement-img-wrap img { width: 100%; height: 100%; object-fit: cover; }
.placement-label { font-size: 0.65rem; font-weight: 600; text-align: center; margin-top: 0.4rem; line-height: 1.2; color: #475569; text-transform: uppercase; }

@media (max-width: 640px) {
    .need-qty-row { flex-direction: column; }
    .placement-grid { grid-template-columns: repeat(2, 1fr); }
}

.tshirt-btn-secondary:hover {
    background: rgba(83, 197, 224, 0.14) !important;
    border-color: rgba(83, 197, 224, 0.52) !important;
}
.field-error {
    margin-top: .4rem;
    font-size: .75rem;
    color: #fca5a5;
    line-height: 1.3;
    display: block;
    width: 100%;
}
#tshirtForm .mb-4.is-invalid {
    border-color: rgba(239, 68, 68, 0.35) !important;
    box-shadow: none !important;
}
#tshirtForm .mb-4.is-invalid .input-field,
#tshirtForm .mb-4.is-invalid .qty-control {
    border-color: rgba(239, 68, 68, 0.55) !important;
}
@media (max-width: 640px) {
    #tshirtForm .opt-btn-group { grid-template-columns: 1fr; }
    .option-grid-3x2, .option-grid-3x3 { grid-template-columns: repeat(2, 1fr); }
    .option-grid-color .opt-btn-others { grid-column: 1 / -1; justify-self: center; }
    .placement-grid { grid-template-columns: repeat(2, 1fr); }
    #tshirtForm .mb-4 {
        padding: .85rem;
    }
    .tshirt-actions-row {
        flex-direction: column;
        align-items: stretch;
    }
    .tshirt-btn {
        width: 100%;
    }
}
@media (max-width: 480px) {
    .lam-qty-row { flex-direction: column; align-items: stretch; }
    .lam-qty-qty { width: 100%; }
    .qty-control-shopee { width: 100%; }
    .lam-options { grid-template-columns: 1fr; }
    .need-qty-row { flex-direction: column; align-items: stretch; }
    .need-qty-qty { width: 100%; }
}
</style>

<script>
const TSHIRT_IS_EDIT_MODE = <?php echo $is_edit_mode ? 'true' : 'false'; ?>;
const TSHIRT_PREFILL = <?php echo json_encode([
    'branch_id' => (string)($_POST['branch_id'] ?? ''),
    'shirt_source' => (string)($_POST['shirt_source'] ?? ''),
    'design_type' => (string)($_POST['design_type'] ?? ''),
    'print_color' => (string)($_POST['print_color'] ?? ''),
    'text_content' => (string)($_POST['text_content'] ?? ''),
    'font_style' => (string)($_POST['font_style'] ?? ''),
    'font_size' => (string)($_POST['font_size'] ?? ''),
    'shirt_type' => (string)($_POST['shirt_type'] ?? ''),
    'shirt_type_other' => (string)($_POST['shirt_type_other'] ?? ''),
    'shirt_color' => (string)($_POST['shirt_color'] ?? ''),
    'color_other' => (string)($_POST['color_other'] ?? ''),
    'sizes' => (string)($_POST['sizes'] ?? ''),
    'sizes_other' => (string)($_POST['sizes_other'] ?? ''),
    'print_placement' => (string)($_POST['print_placement'] ?? ''),
    'lamination' => (string)($_POST['lamination'] ?? ''),
], JSON_UNESCAPED_UNICODE); ?>;

function tshirtSetRadio(name, value) {
    if (!value) return;
    const input = Array.from(document.querySelectorAll('input[name="' + name + '"]')).find(function(el) {
        return el.value === value;
    });
    if (input) {
        input.checked = true;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function tshirtIncreaseQty() {
    const i = document.getElementById('quantity-input');
    i.value = Math.min(999, (parseInt(i.value) || 1) + 1);
    checkFormValid();
}
function tshirtDecreaseQty() {
    const i = document.getElementById('quantity-input');
    const v = parseInt(i.value) || 1;
    if (v > 1) { i.value = v - 1; }
    checkFormValid();
}

function clearFieldError(container) {
    if (!container) return;
    const err = container.querySelector('.field-error');
    container.classList.remove('is-invalid');
    if (err) {
        err.textContent = '';
        err.style.display = 'none';
    }
}

function setFieldError(container, message) {
    if (!container) return;
    let err = container.querySelector('.field-error');
    if (!err) {
        err = document.createElement('div');
        err.className = 'field-error';
        container.appendChild(err);
    }
    if (message) {
        container.classList.add('is-invalid');
        err.textContent = message;
        err.style.display = 'block';
    } else {
        container.classList.remove('is-invalid');
        err.textContent = '';
        err.style.display = 'none';
    }
}

function checkFormValid() {
    const showErrors = window.__tshirtValidationTriggered === true;

    const branch = document.querySelector('select[name="branch_id"]');
    const shirtSource = document.querySelector('input[name="shirt_source"]:checked');
    const designType = document.querySelector('input[name="design_type"]:checked');
    const shirtType = document.querySelector('input[name="shirt_type"]:checked');
    const shirtColor = document.querySelector('input[name="shirt_color"]:checked');
    const placement = document.querySelector('input[name="print_placement"]:checked');
    const lamination = document.querySelector('input[name="lamination"]:checked');
    const qty = parseInt(document.getElementById('quantity-input').value) || 0;
    const file = document.getElementById('design_file');
    const neededDate = document.getElementById('needed_date');

    const textContent = document.getElementById('text_content');
    const printColor = document.getElementById('print_color');

    const cBranch = branch?.closest('.mb-4');
    const cShirtSource = document.querySelector('input[name="shirt_source"]')?.closest('.mb-4');
    const cDesignType = document.querySelector('input[name="design_type"]')?.closest('.mb-4');
    const cShirtType = document.querySelector('input[name="shirt_type"]')?.closest('.mb-4');
    const cShirtColor = document.querySelector('input[name="shirt_color"]')?.closest('.mb-4');
    const cPlacement = document.querySelector('input[name="print_placement"]')?.closest('.mb-4');
    const cFile = file?.closest('.mb-4');
    const cLamination = document.querySelector('input[name="lamination"]')?.closest('.lam-qty-lam');
    const cDate = neededDate?.closest('.mb-4');
    const cTextContent = textContent?.closest('.mb-4');
    const cPrintColor = printColor?.closest('.mb-4');

    const shopProvides = shirtSource && shirtSource.value === 'Shop provides shirt';
    const isTextOnly = designType && designType.value === 'Text Only';
    const isLogoOnly = designType && designType.value === 'Logo Only';
    const hasFile = !!(file && file.files && file.files.length > 0);

    let ok = !!shirtSource && !!designType && !!shirtType && !!shirtColor && !!placement && !!lamination && qty >= 1 && neededDate?.value.trim() !== '';
    
    // Conditional validation for Logo Only
    if (isLogoOnly && !TSHIRT_IS_EDIT_MODE) {
        ok = ok && hasFile;
    }
    
    // Conditional validation for Text Only
    if (isTextOnly) {
        ok = ok && !!textContent.value.trim() && !!printColor.value.trim();
    }

    if (showErrors) {
        setFieldError(cBranch, '');
        setFieldError(cShirtSource, !shirtSource ? 'Please select shirt source' : '');
        setFieldError(cDesignType, !designType ? 'Please select design type' : '');
        setFieldError(cShirtType, !shirtType ? 'Please select shirt type' : '');
        setFieldError(cShirtColor, !shirtColor ? 'Please select shirt color' : '');
        setFieldError(cPlacement, !placement ? 'Please select placement' : '');
        setFieldError(cFile, (isLogoOnly && !TSHIRT_IS_EDIT_MODE && !hasFile) ? 'Please upload your design for logo printing' : '');
        setFieldError(cLamination, !lamination ? 'Please select lamination' : '');
        setFieldError(cDate, !neededDate.value.trim() ? 'Please select a date' : '');
        
        if (isTextOnly) {
            setFieldError(cTextContent, !textContent.value.trim() ? 'Text content is required for text designs' : '');
            setFieldError(cPrintColor, !printColor.value.trim() ? 'Print color is required for text designs' : '');
        } else {
            clearFieldError(cTextContent);
            clearFieldError(cPrintColor);
        }
    } else {
        [cBranch, cShirtSource, cDesignType, cShirtType, cShirtColor, cPlacement, cFile, cLamination, cDate, cTextContent, cPrintColor].forEach(clearFieldError);
    }

    return ok;
}

// ── Event Listeners ──
document.getElementById('tshirtForm').addEventListener('change', checkFormValid);
document.getElementById('tshirtForm').addEventListener('input', checkFormValid);
document.getElementById('design_file').addEventListener('change', checkFormValid);
document.getElementById('quantity-input').addEventListener('input', checkFormValid);
document.getElementById('needed_date').addEventListener('change', checkFormValid);

document.querySelectorAll('input[name="design_type"]').forEach(r => {
    r.addEventListener('change', function() {
        const isLogo = this.value === 'Logo Only';
        const isText = this.value === 'Text Only';
        const uploadLabel = document.getElementById('upload-label');
        const fileInput = document.getElementById('design_file');
        const textSection = document.getElementById('text-design-section');
        
        // Toggle Upload Section Label/Requirement
        if (isLogo) {
            uploadLabel.textContent = 'Upload Design * (Required for logo printing)';
            if (!TSHIRT_IS_EDIT_MODE) fileInput.required = true;
        } else {
            uploadLabel.textContent = 'Upload Design (Optional - for reference only)';
            fileInput.required = false;
        }

        // Toggle Text Section
        textSection.style.display = isText ? 'block' : 'none';
        
        checkFormValid();
    });
});

document.querySelectorAll('input[name="shirt_source"]').forEach(r => {
    r.addEventListener('change', function() {
        const shopProvides = this.value === 'Shop provides shirt';
        document.getElementById('shop-provides-note').style.display = shopProvides ? 'block' : 'none';
        document.getElementById('size-section').style.display = shopProvides ? 'block' : 'none';
        document.querySelectorAll('input[name="sizes"]').forEach(s => { 
            s.required = shopProvides;
            if (!shopProvides) s.checked = false; 
        });
        document.getElementById('size-required-mark').textContent = shopProvides ? '*' : '';
    });
});

document.querySelectorAll('input[name="sizes"]').forEach(r => {
    r.addEventListener('change', function() {
        document.getElementById('sizes-other-wrap').style.display = this.value === 'Others' ? 'block' : 'none';
    });
});

document.querySelectorAll('input[name="shirt_type"]').forEach(r => {
    r.addEventListener('change', function() {
        document.getElementById('shirt-type-other-wrap').style.display = this.value === 'Others' ? 'block' : 'none';
    });
});

document.querySelectorAll('input[name="shirt_color"]').forEach(r => {
    r.addEventListener('change', function() {
        document.getElementById('color-other-wrap').style.display = this.value === 'Other' ? 'block' : 'none';
    });
});

// Form validation is handled by the global order_validation.js
// No manual submit intercept needed for basic validation

// Prefill values
if (TSHIRT_PREFILL.text_content) document.getElementById('text_content').value = TSHIRT_PREFILL.text_content;
if (TSHIRT_PREFILL.print_color) document.getElementById('print_color').value = TSHIRT_PREFILL.print_color;
if (TSHIRT_PREFILL.font_style) document.getElementById('font_style').value = TSHIRT_PREFILL.font_style;
if (TSHIRT_PREFILL.font_size) document.getElementById('font_size').value = TSHIRT_PREFILL.font_size;
if (TSHIRT_PREFILL.shirt_type_other) document.getElementById('shirt_type_other').value = TSHIRT_PREFILL.shirt_type_other;
if (TSHIRT_PREFILL.color_other) document.getElementById('color_other').value = TSHIRT_PREFILL.color_other;
if (TSHIRT_PREFILL.sizes_other) document.getElementById('sizes_other').value = TSHIRT_PREFILL.sizes_other;

tshirtSetRadio('branch_id', TSHIRT_PREFILL.branch_id);
tshirtSetRadio('shirt_source', TSHIRT_PREFILL.shirt_source);
tshirtSetRadio('design_type', TSHIRT_PREFILL.design_type);
tshirtSetRadio('shirt_type', TSHIRT_PREFILL.shirt_type);
tshirtSetRadio('shirt_color', TSHIRT_PREFILL.shirt_color);
tshirtSetRadio('sizes', TSHIRT_PREFILL.sizes);
tshirtSetRadio('print_placement', TSHIRT_PREFILL.print_placement);
tshirtSetRadio('lamination', TSHIRT_PREFILL.lamination);

checkFormValid();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
