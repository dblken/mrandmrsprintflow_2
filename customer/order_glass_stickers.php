<?php
/**
 * Glass & Wall Sticker Printing - Service Order Form
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_order_helper.php';
require_once __DIR__ . '/../includes/service_field_config_helper.php';

require_role('Customer');
require_once __DIR__ . '/../includes/require_customer_profile_complete.php';
$customer_id = get_user_id();

// Get service ID and field configuration
$service_info = db_query("SELECT service_id FROM services WHERE customer_link LIKE '%order_glass_stickers%' LIMIT 1");
$service_id = $service_info[0]['service_id'] ?? 0;
$field_configs = $service_id > 0 ? get_service_field_config($service_id) : [];

// Helper function to get field label
function get_field_label($key, $default, $configs) {
    return (!empty($configs[$key]) && $configs[$key]['visible']) ? $configs[$key]['label'] : $default;
}

// Helper function to get field options
function get_field_options($key, $default, $configs) {
    return (!empty($configs[$key]) && $configs[$key]['visible'] && !empty($configs[$key]['options'])) ? $configs[$key]['options'] : $default;
}

// Helper function to check if field is visible
function is_field_visible($key, $configs) {
    return empty($configs) || empty($configs[$key]) || $configs[$key]['visible'];
}

$error = '';
$addr_api = (defined('BASE_URL') ? BASE_URL : '/printflow') . '/public/api_address_public.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $branch_id = (int)($_POST['branch_id'] ?? 1);
    $width = trim($_POST['width'] ?? '');
    $height = trim($_POST['height'] ?? '');
    $unit = trim($_POST['unit'] ?? 'ft');
    $surface_type = trim($_POST['surface_type'] ?? '');
    $surface_other = trim($_POST['surface_type_other'] ?? '');
    $lamination = trim($_POST['lamination'] ?? '');
    $installation = trim($_POST['installation'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 1);
    $needed_date = trim($_POST['needed_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    $province = trim($_POST['install_province'] ?? '');
    $city = trim($_POST['install_city'] ?? '');
    $barangay = trim($_POST['install_barangay'] ?? '');
    $street = trim($_POST['install_street'] ?? '');

    $surface_display = ($surface_type === 'Others' && $surface_other) ? $surface_other : $surface_type;

    if (empty($width) || empty($height) || $quantity < 1 || empty($needed_date) || empty($surface_type) || empty($lamination) || empty($installation)) {
        $error = 'Please fill in all required fields marked with *.';
    } elseif ($installation === 'With Installation' && (empty($province) || empty($city) || empty($barangay) || empty($street))) {
        $error = 'Please complete the installation address.';
    } elseif (!isset($_FILES['design_file']) || $_FILES['design_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please upload your design.';
    } else {
        $valid = service_order_validate_file($_FILES['design_file']);
        if (!$valid['ok']) {
            $error = $valid['error'];
        } else {
            $item_key = 'glass_' . time() . '_' . rand(100, 999);
            $original_name = $_FILES['design_file']['name'];
            $mime = $valid['mime'];
            $ext = pathinfo($original_name, PATHINFO_EXTENSION);
            $new_name = uniqid('tmp_') . '.' . $ext;
            $tmp_dest = service_order_temp_dir() . DIRECTORY_SEPARATOR . $new_name;

            if (move_uploaded_file($_FILES['design_file']['tmp_name'], $tmp_dest)) {
                $w = (float)$width;
                $h = (float)$height;
                $area = $w * $h;
                if ($unit === 'in') $area = $area / 144;
                
                $price_per_sqft = 45.00;
                $unit_price = $area * $price_per_sqft;  // Price per piece
                $installation_fee = ($installation === 'With Installation') ? (500 + ($area * 15)) : 0;
                $installation_fee_per_unit = $installation_fee / $quantity;  // Divide installation fee by quantity

                $_SESSION['cart'][$item_key] = [
                    'type' => 'Service',
                    'source_page' => 'services',
                    'name' => 'Glass & Wall Sticker Printing',
                    'price' => $unit_price + $installation_fee_per_unit,  // Unit price, not total
                    'quantity' => $quantity,
                    'category' => 'Glass & Wall Sticker Printing',
                    'branch_id' => $branch_id,
                    'design_tmp_path' => $tmp_dest,
                    'design_name' => $original_name,
                    'design_mime' => $mime,
                    'customization' => [
                        'width' => $width,
                        'height' => $height,
                        'unit' => $unit,
                        'surface_type' => $surface_display,
                        'lamination' => $lamination,
                        'installation' => $installation,
                        'installation_fee' => $installation_fee,
                        'install_province' => $province,
                        'install_city' => $city,
                        'install_barangay' => $barangay,
                        'install_street' => $street,
                        'needed_date' => $needed_date,
                        'notes' => $notes
                    ]
                ];
                
                if (($_POST['action'] ?? '') === 'buy_now' || isset($_POST['buy_now'])) {
                    redirect("order_review.php?item=" . urlencode($item_key));
                } else {
                    redirect("cart.php");
                }
            } else {
                $error = 'Failed to process uploaded file.';
            }
        }
    }
}

$page_title = 'Order Glass & Wall Sticker - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';

$branches = db_query("SELECT id, branch_name FROM branches WHERE status = 'Active'");

// Fetch actual service image
$service_info = db_query("SELECT hero_image FROM services WHERE customer_link LIKE '%order_glass_stickers%' LIMIT 1");
$display_img = (!empty($service_info) && !empty($service_info[0]['hero_image'])) ? $service_info[0]['hero_image'] : '/printflow/public/assets/images/services/default.png';
if ($display_img !== '' && strpos($display_img, 'http') === false && $display_img[0] !== '/') {
    $display_img = '/' . ltrim($display_img, '/');
}
?>

<div class="min-h-screen py-8">
    <div class="container mx-auto px-4" style="max-width: 1200px;">
        <!-- Breadcrumb-style title -->
        <div class="text-sm text-gray-500 mb-6 flex items-center gap-2">
            <a href="services.php" class="hover:text-blue-600">Services</a>
            <span>/</span>
            <span class="font-semibold text-gray-900"><?php echo $page_title; ?></span>
        </div>

        <?php if ($error): ?>
            <div class="glass-form-error mb-6"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="shopee-card">
            <div class="shopee-image-section">
                <div class="sticky-image-container">
                    <div class="shopee-main-image-wrap">
                        <img src="<?php echo htmlspecialchars($display_img); ?>" alt="Service Image" class="shopee-main-image" onerror="this.src='https://placehold.co/600x600/f8fafc/0f172a?text=Glass+Printing'">
                    </div>
                </div>
            </div>

            <!-- Right Side: Form Section -->
            <div class="shopee-form-section">
                <h1 class="text-2xl font-bold text-gray-900 mb-2">Glass & Wall Sticker Printing</h1>
                <?php
                $stats = service_order_get_page_stats('order_glass_stickers');
                $raw_avg = (float)($stats['avg_rating'] ?? 0);
                $review_count = (int)($stats['review_count'] ?? 0);
                $sold_count = (int)($stats['sold_count'] ?? 0);
                $sold_display = $sold_count >= 1000 ? number_format($sold_count / 1000, 1) . 'k' : $sold_count;
                
                $_s_name = 'PrintFlow Service';
                $_s_row = db_query("SELECT name FROM services WHERE customer_link LIKE '%order_glass_stickers%' LIMIT 1");
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

                <form action="" method="POST" enctype="multipart/form-data" id="glassForm" novalidate>
                    <?php echo csrf_field(); ?>

                <!-- Branch -->
                <?php if (is_field_visible('branch', $field_configs)): ?>
                <div class="mb-4" id="card-branch">
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(get_field_label('branch', 'Branch', $field_configs)); ?> *</label>
                    <select name="branch_id" class="input-field" required>
                        <?php foreach($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['branch_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Dimensions -->
                <?php if (is_field_visible('dimensions', $field_configs)): ?>
                <?php $dim_options = get_field_options('dimensions', ['2×3', '4×6', '6×8'], $field_configs); ?>
                <div class="shopee-form-row">
                    <label class="shopee-form-label pt-2"><?php echo htmlspecialchars(get_field_label('dimensions', 'Dimensions (ft)', $field_configs)); ?> * <span class="text-xs text-gray-400 font-normal">(All values are in feet)</span></label>
                    <div class="shopee-form-field">
                        <div class="shopee-opt-group">
                            <?php foreach ($dim_options as $dim): ?>
                                <?php if ($dim === 'Others'): ?>
                                    <button type="button" class="shopee-opt-btn" id="dim-others-btn" onclick="selectDimensionOthers(event)"><?php echo htmlspecialchars($dim); ?></button>
                                <?php else: ?>
                                    <?php 
                                    $parts = explode('×', $dim);
                                    if (count($parts) === 2):
                                        $w = trim($parts[0]);
                                        $h = trim($parts[1]);
                                    ?>
                                    <button type="button" class="shopee-opt-btn" data-width="<?php echo htmlspecialchars($w); ?>" data-height="<?php echo htmlspecialchars($h); ?>" onclick="selectDimension(<?php echo htmlspecialchars($w); ?>, <?php echo htmlspecialchars($h); ?>, event)"><?php echo htmlspecialchars($dim); ?></button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <input type="hidden" name="width" id="width_hidden">
                        <input type="hidden" name="height" id="height_hidden">
                        <input type="hidden" name="unit" value="ft">
                        
                        <div id="dim-others-inputs" style="display: none; margin-top: 1rem;">
                            <div class="flex gap-4 items-center">
                                <div class="flex-1">
                                    <label class="text-xs font-bold text-gray-400 mb-1 block">WIDTH (FT)</label>
                                    <input type="number" step="0.01" id="custom_width" class="input-field" placeholder="e.g. 5">
                                </div>
                                <div class="text-gray-400 font-bold mt-4">×</div>
                                <div class="flex-1">
                                    <label class="text-xs font-bold text-gray-400 mb-1 block">HEIGHT (FT)</label>
                                    <input type="number" step="0.01" id="custom_height" class="input-field" placeholder="e.g. 10">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Surface Type -->
                <?php if (is_field_visible('surface_type', $field_configs)): ?>
                <?php $surface_options = get_field_options('surface_type', ['Glass (Window/Door/Storefront)', 'Wall (Painted/Concrete)', 'Frosted Glass', 'Mirror', 'Acrylic/Panel', 'Others'], $field_configs); ?>
                <div class="shopee-form-row">
                    <label class="shopee-form-label pt-2"><?php echo htmlspecialchars(get_field_label('surface_type', 'Surface Type', $field_configs)); ?> *</label>
                    <div class="shopee-form-field">
                        <div class="shopee-opt-group" style="display:grid; grid-template-columns: repeat(3, minmax(0, 1fr));">
                            <?php foreach ($surface_options as $surf): ?>
                                <label class="shopee-opt-btn"><input type="radio" name="surface_type" value="<?php echo htmlspecialchars($surf); ?>" required style="display:none;" onchange="updateOpt(this); toggleSurfaceOther();"> <span><?php echo htmlspecialchars($surf); ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        
                        <div id="surface-other-wrap" style="display: none; margin-top: 0.75rem;">
                            <input type="text" name="surface_type_other" id="surface_type_other" class="input-field" placeholder="Specify surface type...">
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Lamination -->
                <?php if (is_field_visible('lamination', $field_configs)): ?>
                <?php $lam_options = get_field_options('lamination', ['With Laminate', 'Without Laminate'], $field_configs); ?>
                <div class="shopee-form-row">
                    <label class="shopee-form-label pt-2"><?php echo htmlspecialchars(get_field_label('lamination', 'Lamination', $field_configs)); ?> *</label>
                    <div class="shopee-opt-group shopee-form-field">
                        <?php foreach ($lam_options as $lam): ?>
                            <label class="shopee-opt-btn"><input type="radio" name="lamination" value="<?php echo htmlspecialchars($lam); ?>" required style="display:none;" onchange="updateOpt(this)"> <span><?php echo htmlspecialchars($lam); ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Installation -->
                <?php if (is_field_visible('installation', $field_configs)): ?>
                <?php $inst_options = get_field_options('installation', ['With Installation', 'Without Installation'], $field_configs); ?>
                <div class="shopee-form-row">
                    <label class="shopee-form-label pt-2"><?php echo htmlspecialchars(get_field_label('installation', 'Installation', $field_configs)); ?> *</label>
                    <div class="shopee-opt-group shopee-form-field">
                        <?php foreach ($inst_options as $inst): ?>
                            <label class="shopee-opt-btn"><input type="radio" name="installation" value="<?php echo htmlspecialchars($inst); ?>" required style="display:none;" onchange="updateOpt(this); toggleInstallationAddress(<?php echo $inst === 'With Installation' ? 'true' : 'false'; ?>)"> <span><?php echo htmlspecialchars($inst); ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Installation Address (Conditional) -->
                <div id="install-address-section" style="display: none; margin-bottom: 1rem; padding: 1.25rem; border-radius: 12px;" class="bg-gray-50 border border-gray-200">
                    <div class="address-notice mb-4 text-xs">
                        <strong>Installation fee varies based on distance.</strong> A base fee is applied; final amount may be adjusted after location confirmation.
                    </div>
                    <label class="block text-sm font-medium text-gray-700 mb-3">Installation Address *</label>
                    <div class="space-y-4 pr-4">
                        <div class="address-field">
                            <label class="text-xs font-bold text-gray-400 mb-1 block">Province</label>
                            <select name="install_province" id="install_province" class="input-field shopee-form-field">
                                <option value="">— Select Province —</option>
                            </select>
                        </div>
                        <div class="address-field">
                            <label class="text-xs font-bold text-gray-400 mb-1 block">City / Municipality</label>
                            <select name="install_city" id="install_city" class="input-field shopee-form-field" disabled>
                                <option value="">— Select City / Municipality —</option>
                            </select>
                        </div>
                        <div class="address-field">
                            <label class="text-xs font-bold text-gray-400 mb-1 block">Barangay</label>
                            <select name="install_barangay" id="install_barangay" class="input-field shopee-form-field" disabled>
                                <option value="">— Select Barangay —</option>
                            </select>
                        </div>
                        <div class="address-field">
                            <label class="text-xs font-bold text-gray-400 mb-1 block">Street / Purok</label>
                            <input type="text" name="install_street" id="install_street" class="input-field shopee-form-field" placeholder="Street name, Purok, etc.">
                        </div>
                    </div>
                </div>

                <!-- Needed Date + Quantity -->
                <div class="shopee-form-row">
                    <div class="w-[130px] flex-shrink-0">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Needed Date *</label>
                        <input type="date" name="needed_date" id="needed_date" class="input-field shopee-form-field mt-1" required min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="ml-8 w-[130px] flex-shrink-0">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Quantity *</label>
                        <div class="qty-control group-focus-within mt-1">
                            <button type="button" class="qty-btn" onclick="decreaseQty()">−</button>
                            <input type="number" id="quantity-input" name="quantity" min="1" max="999" value="1" oninput="clampQty()">
                            <button type="button" class="qty-btn" onclick="increaseQty()">+</button>
                        </div>
                    </div>
                </div>

                <!-- File Upload -->
                <div class="shopee-form-row">
                    <label class="shopee-form-label">Upload Design * <br><span class="text-xs text-gray-400 font-normal">(JPG, PNG, PDF)</span></label>
                    <input type="file" name="design_file" id="design_file" accept=".jpg,.jpeg,.png,.pdf" class="input-field shopee-form-field" required>
                </div>

                <!-- Notes -->
                <div class="shopee-form-row">
                    <label class="shopee-form-label">Notes</label>
                    <textarea name="notes" rows="3" class="input-field shopee-form-field" placeholder="Any special instructions..." maxlength="500"></textarea>
                </div>

                <!-- Action Buttons -->
                <div class="shopee-form-row pt-8">
                    <div style="width: 130px;"></div>
                    <div class="flex gap-4 flex-1">
                        <a href="<?php echo BASE_URL; ?>/customer/services.php" class="shopee-btn-outline" style="flex: 1; text-align: center; line-height: 2.2;">Back</a>
                        <button type="submit" name="action" value="add_to_cart" class="shopee-btn-outline" style="width:2.75rem;height:2.75rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;padding:0;" title="Add to Cart"><svg style="width:1.25rem;height:1.25rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg></button>
                        <button type="submit" name="action" value="buy_now" class="shopee-btn-primary" style="flex: 1.5;">Buy Now</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
/* Override specifics for this form */
#install-address-section {

    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 20px;
    margin-bottom: 24px;
    border-radius: 4px;
    flex-direction: column !important;
    align-items: stretch !important;
}

.address-notice {
    background: #fffbeb;
    border: 1px solid #fef3c7;
    color: #92400e;
    padding: 12px;
    border-radius: 4px;
    font-size: 0.85rem;
    margin-bottom: 16px;
}

.address-field label {
    display: block;
    font-size: 0.75rem;
    color: #64748b;
    font-weight: 600;
    margin-bottom: 4px;
}

@media (max-width: 640px) {
    .need-qty-row { flex-direction: column; gap: 16px; }
}
</style>

<script>
const ADDR_API = '<?php echo $addr_api; ?>';
let dimensionMode = 'preset';

function updateOpt(input) {
    const name = input.name;
    document.querySelectorAll(`input[name="${name}"]`).forEach(function(r) {
        const wrap = r.closest('.shopee-opt-btn');
        if (wrap) wrap.classList.toggle('active', r.checked);
    });
}

function selectDimension(w, h, e) {
    e.preventDefault();
    dimensionMode = 'preset';
    document.querySelectorAll('.shopee-opt-group .shopee-opt-btn').forEach(b => {
        if(b.hasAttribute('data-width') || b.id === 'dim-others-btn') {
            b.classList.remove('active');
        }
    });
    e.target.closest('.shopee-opt-btn').classList.add('active');
    document.getElementById('dim-others-inputs').style.display = 'none';
    document.getElementById('width_hidden').value = w;
    document.getElementById('height_hidden').value = h;
}

function selectDimensionOthers(e) {
    e.preventDefault();
    dimensionMode = 'others';
    document.querySelectorAll('.shopee-opt-group .shopee-opt-btn').forEach(b => {
        if(b.hasAttribute('data-width') || b.id === 'dim-others-btn') {
            b.classList.remove('active');
        }
    });
    document.getElementById('dim-others-btn').classList.add('active');
    document.getElementById('dim-others-inputs').style.display = 'block';
    syncOthersDimensions();
}

function syncOthersDimensions() {
    document.getElementById('width_hidden').value = document.getElementById('custom_width').value;
    document.getElementById('height_hidden').value = document.getElementById('custom_height').value;
}

document.getElementById('custom_width').addEventListener('input', syncOthersDimensions);
document.getElementById('custom_height').addEventListener('input', syncOthersDimensions);

function toggleSurfaceOther() {
    const r = document.querySelector('input[name="surface_type"]:checked');
    document.getElementById('surface-other-wrap').style.display = (r && r.value === 'Others') ? 'block' : 'none';
}

function toggleInstallationAddress(show) {
    const sec = document.getElementById('install-address-section');
    sec.style.display = show ? 'block' : 'none';
    const fields = sec.querySelectorAll('.input-field');
    fields.forEach(f => {
        if (f.id !== 'install_province') f.disabled = !show;
        if (show) f.setAttribute('required', '');
        else f.removeAttribute('required');
    });
    if (show && document.getElementById('install_province').options.length <= 1) {
        loadProvinces();
    }
}

async function loadProvinces() {
    const p = document.getElementById('install_province');
    try {
        const r = await fetch(ADDR_API + '?address_action=provinces');
        const d = await r.json();
        if (d.success && d.data) {
            p.innerHTML = '<option value="">— Select Province —</option>' + d.data.map(i => `<option value="${i.code}">${i.name}</option>`).join('');
        }
    } catch(e) { console.error(e); }
}

document.getElementById('install_province').addEventListener('change', async function() {
    const city = document.getElementById('install_city');
    city.innerHTML = '<option value="">— Select City / Municipality —</option>';
    city.disabled = true;
    if (!this.value) return;
    try {
        const r = await fetch(ADDR_API + '?address_action=cities&province_code=' + this.value);
        const d = await r.json();
        if (d.success && d.data) {
            city.innerHTML = '<option value="">— Select City / Municipality —</option>' + d.data.map(i => `<option value="${i.code}">${i.name}</option>`).join('');
            city.disabled = false;
        }
    } catch(e) { console.error(e); }
});

document.getElementById('install_city').addEventListener('change', async function() {
    const b = document.getElementById('install_barangay');
    b.innerHTML = '<option value="">— Select Barangay —</option>';
    b.disabled = true;
    if (!this.value) return;
    try {
        const r = await fetch(ADDR_API + '?address_action=barangays&city_code=' + this.value);
        const d = await r.json();
        if (d.success && d.data) {
            b.innerHTML = '<option value="">— Select Barangay —</option>' + d.data.map(i => `<option value="${i.code}">${i.name}</option>`).join('');
            b.disabled = false;
        }
    } catch(e) { console.error(e); }
});

function decreaseQty() { const i = document.getElementById('quantity-input'); if (parseInt(i.value) > 1) i.value = parseInt(i.value) - 1; }
function increaseQty() { const i = document.getElementById('quantity-input'); i.value = Math.min(999, (parseInt(i.value) || 1) + 1); }
function clampQty() { const i = document.getElementById('quantity-input'); let v = parseInt(i.value) || 1; i.value = Math.min(999, Math.max(1, v)); }

document.getElementById('glassForm').addEventListener('submit', function(e) {
    if (dimensionMode === 'others') {
        syncOthersDimensions();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
