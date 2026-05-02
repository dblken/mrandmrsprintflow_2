<?php
/**
 * Transparent Stickers - Service Order Form
 * PrintFlow - Service-Based Ordering
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_order_helper.php';

require_role('Customer');
require_once __DIR__ . '/../includes/require_customer_profile_complete.php';
$customer_id = get_user_id();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $branch_id = (int)($_POST['branch_id'] ?? 1);
    $surface_application = trim($_POST['surface_application'] ?? '');
    $surface_other = trim($_POST['surface_other'] ?? '');
    $dimensions = trim($_POST['dimensions'] ?? '');
    $layout = trim($_POST['layout'] ?? '');
    $lamination = trim($_POST['lamination'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 1);
    $needed_date = trim($_POST['needed_date'] ?? '');
    $additional_notes = trim($_POST['additional_notes'] ?? '');

    $surface_display = ($surface_application === 'Others' && $surface_other) ? $surface_other : $surface_application;

    if (empty($surface_display) || empty($dimensions) || empty($layout) || empty($lamination) || $quantity < 1 || empty($needed_date)) {
        $error = 'Please fill in Branch, Surface, Dimensions, Layout, Lamination, Quantity, and Needed Date.';
    } elseif ((function_exists('mb_strlen') ? mb_strlen($additional_notes) : strlen($additional_notes)) > 500) {
        $error = 'Notes must not exceed 500 characters.';
    } elseif ($surface_application === 'Others' && empty($surface_other)) {
        $error = 'Please specify your surface type when Others is selected.';
    } elseif (!isset($_FILES['design_file']) || $_FILES['design_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please upload your design file.';
    } else {
        $valid = service_order_validate_file($_FILES['design_file']);
        if (!$valid['ok']) {
            $error = $valid['error'];
        } else {
            $item_key = 'trans_' . time() . '_' . rand(100, 999);
            $original_name = $_FILES['design_file']['name'];
            $mime = $valid['mime'];
            $ext = pathinfo($original_name, PATHINFO_EXTENSION);
            $new_name = uniqid('tmp_') . '.' . $ext;
            $tmp_dest = service_order_temp_dir() . DIRECTORY_SEPARATOR . $new_name;

            if (move_uploaded_file($_FILES['design_file']['tmp_name'], $tmp_dest)) {
                $_SESSION['cart'][$item_key] = [
                    'type' => 'Service',
                    'source_page' => 'services',
                    'name' => 'Transparent Sticker Printing',
                    'price' => 0, // Calculated at checkout or review
                    'quantity' => $quantity,
                    'category' => 'Sticker Printing',
                    'branch_id' => $branch_id,
                    'design_tmp_path' => $tmp_dest,
                    'design_name' => $original_name,
                    'design_mime' => $mime,
                    'customization' => [
                        'surface_application' => $surface_display,
                        'dimensions' => $dimensions,
                        'layout' => $layout,
                        'lamination' => $lamination,
                        'needed_date' => $needed_date,
                        'additional_notes' => $additional_notes
                    ]
                ];

                if (($_POST['action'] ?? '') === 'buy_now' || isset($_POST['buy_now'])) {
                    redirect(BASE_URL . '/customer/order_review.php?item=' . urlencode($item_key));
                } else {
                    redirect(BASE_URL . '/customer/cart.php');
                }
            } else {
                $error = 'Failed to process uploaded file.';
            }
        }
    }
}

$page_title = 'Order Transparent Stickers - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';

$branches = db_query("SELECT id, branch_name FROM branches WHERE status = 'Active'");
$_svc = db_query("SELECT hero_image FROM services WHERE customer_link LIKE '%order_transparent%' LIMIT 1");
$display_img = (!empty($_svc) && !empty($_svc[0]['hero_image'])) ? $_svc[0]['hero_image'] : '';
if ($display_img !== '' && strpos($display_img, 'http') === false && $display_img[0] !== '/') { $display_img = '/' . ltrim($display_img, '/'); }
?>

<div class="min-h-screen py-8">
    <div class="shopee-layout-container">
        <!-- Breadcrumb -->
        <div class="text-sm text-gray-500 mb-6 flex items-center gap-2">
            <a href="services.php" class="hover:text-blue-600">Services</a>
            <span>/</span>
            <span class="font-semibold text-gray-900">Transparent Stickers</span>
        </div>

        <div class="shopee-card">
            <!-- Left Side: Image -->
            <div class="shopee-image-section">
                <div class="sticky-image-container">
                    <div class="shopee-main-image-wrap">
                        <img src="<?php echo htmlspecialchars($display_img ?: 'https://placehold.co/600x600/f8fafc/0f172a?text=Transparent+Stickers'); ?>" alt="Transparent Stickers" class="shopee-main-image" onerror="this.src='https://placehold.co/600x600/f8fafc/0f172a?text=Transparent+Stickers'">
                    </div>
                </div>
            </div>

            <!-- Right Side: Form -->
            <div class="shopee-form-section">
                <h1 class="text-2xl font-bold text-gray-900 mb-2">Transparent Stickers</h1>
                
                <?php
                $stats = service_order_get_page_stats('order_transparent');
                $raw_avg = (float)($stats['avg_rating'] ?? 0);
                $review_count = (int)($stats['review_count'] ?? 0);
                $sold_count = (int)($stats['sold_count'] ?? 0);
                $sold_display = $sold_count >= 1000 ? number_format($sold_count / 1000, 1) . 'k' : $sold_count;
                
                $_s_name = 'PrintFlow Service';
                $_s_row = db_query("SELECT name FROM services WHERE customer_link LIKE '%order_transparent%' LIMIT 1");
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

                <?php if ($error): ?><div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <form method="POST" enctype="multipart/form-data" id="transForm" novalidate>
                    <?php echo csrf_field(); ?>

                    <div class="shopee-form-row">
                        <label class="shopee-form-label">Branch *</label>
                        <select name="branch_id" class="input-field shopee-form-field" required>
                            <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="shopee-form-row">
                        <label class="shopee-form-label pt-2">Application *</label>
                        <div class="shopee-form-field">
                            <div class="shopee-opt-group">
                                <label class="shopee-opt-btn"><input type="radio" name="surface_application" value="Glass (Window/Door/Storefront)" required style="display:none;" onchange="transUpdateOpt(this)"> <span>Glass</span></label>
                                <label class="shopee-opt-btn"><input type="radio" name="surface_application" value="Plastic / Acrylic" required style="display:none;" onchange="transUpdateOpt(this)"> <span>Plastic/Acrylic</span></label>
                                <label class="shopee-opt-btn"><input type="radio" name="surface_application" value="Metal" required style="display:none;" onchange="transUpdateOpt(this)"> <span>Metal</span></label>
                                <label class="shopee-opt-btn"><input type="radio" name="surface_application" value="Smooth Painted Wall" required style="display:none;" onchange="transUpdateOpt(this)"> <span>Painted Wall</span></label>
                                <label class="shopee-opt-btn"><input type="radio" name="surface_application" value="Mirror" required style="display:none;" onchange="transUpdateOpt(this)"> <span>Mirror</span></label>
                                <label class="shopee-opt-btn"><input type="radio" name="surface_application" value="Others" required style="display:none;" onchange="transUpdateOpt(this)"> <span>Others</span></label>
                            </div>
                            <div id="surface-other-wrap" style="display: none; margin-top: 0.75rem;">
                                <input type="text" name="surface_other" id="surface_other" class="input-field" placeholder="Specify surface type">
                            </div>
                        </div>
                    </div>

                    <div class="shopee-form-row">
                        <label class="shopee-form-label pt-2">Dimensions *</label>
                        <div class="shopee-form-field">
                            <div class="shopee-opt-group">
                                <button type="button" class="shopee-opt-btn shopee-dim-btn" data-dim="2x2" onclick="selectDimPreset('2x2', event)">2×2 ft</button>
                                <button type="button" class="shopee-opt-btn shopee-dim-btn" data-dim="3x3" onclick="selectDimPreset('3x3', event)">3×3 ft</button>
                                <button type="button" class="shopee-opt-btn shopee-dim-btn" data-dim="4x4" onclick="selectDimPreset('4x4', event)">4×4 ft</button>
                                <button type="button" class="shopee-opt-btn shopee-dim-btn" data-dim="2x3" onclick="selectDimPreset('2x3', event)">2×3 ft</button>
                                <button type="button" class="shopee-opt-btn shopee-dim-btn" data-dim="3x4" onclick="selectDimPreset('3x4', event)">3×4 ft</button>
                                <button type="button" class="shopee-opt-btn shopee-dim-btn" data-dim="4x6" onclick="selectDimPreset('4x6', event)">4×6 ft</button>
                                <button type="button" class="shopee-opt-btn shopee-dim-btn" id="dim-others-btn" onclick="selectDimOthers(event)">Others</button>
                            </div>
                            <input type="hidden" name="dimensions" id="dimensions_hidden">
                            <div id="dim-others-inputs" class="shopee-dim-custom-row" style="display: none; margin-top: 1rem;">
                                <div class="flex-1">
                                    <label class="dim-label">Width (ft)</label>
                                    <input type="text" inputmode="numeric" id="custom_width" class="input-field" placeholder="0">
                                </div>
                                <div class="pt-6 font-bold text-gray-400">×</div>
                                <div class="flex-1">
                                    <label class="dim-label">Height (ft)</label>
                                    <input type="text" inputmode="numeric" id="custom_height" class="input-field" placeholder="0">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="shopee-form-row">
                        <label class="shopee-form-label pt-2">Layout *</label>
                        <div class="shopee-opt-group shopee-form-field">
                            <label class="shopee-opt-btn"><input type="radio" name="layout" value="With Layout" required style="display:none;" onchange="transUpdateOpt(this)"> <span>With Layout</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="layout" value="Without Layout" required style="display:none;" onchange="transUpdateOpt(this)"> <span>Without Layout</span></label>
                        </div>
                    </div>

                    <div class="shopee-form-row">
                        <label class="shopee-form-label pt-2">Lamination *</label>
                        <div class="shopee-opt-group shopee-form-field">
                            <label class="shopee-opt-btn"><input type="radio" name="lamination" value="With Laminate" required style="display:none;" onchange="transUpdateOpt(this)"> <span>With Laminate</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="lamination" value="Without Laminate" required style="display:none;" onchange="transUpdateOpt(this)"> <span>Without Laminate</span></label>
                        </div>
                    </div>

                    <div class="shopee-form-row">
                        <label class="shopee-form-label">Upload Design *</label>
                        <input type="file" id="design_file" name="design_file" accept=".jpg,.jpeg,.png,.pdf" class="input-field shopee-form-field" required>
                    </div>

                    <div class="shopee-form-row pt-4 border-t border-gray-50">
                        <label class="shopee-form-label pt-2">Order Details *</label>
                        <div class="shopee-form-field">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="dim-label">Needed Date</label>
                                    <input type="date" name="needed_date" id="needed_date" class="input-field" value="<?php echo htmlspecialchars($_POST['needed_date'] ?? ''); ?>" required min="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div>
                                    <label class="dim-label">Quantity</label>
                                    <div class="shopee-qty-control">
                                        <button type="button" class="shopee-qty-btn" onclick="transDecreaseQty()">−</button>
                                        <input type="number" id="quantity-input" name="quantity" class="shopee-qty-input" min="1" max="999" value="<?php echo (int)($_POST['quantity'] ?? ($_GET['qty'] ?? 1)); ?>">
                                        <button type="button" class="shopee-qty-btn" onclick="transIncreaseQty()">+</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="shopee-form-row">
                        <label class="shopee-form-label">Notes</label>
                        <textarea name="additional_notes" rows="3" class="input-field shopee-form-field" placeholder="Any special instructions..." maxlength="500"><?php echo htmlspecialchars($_POST['additional_notes'] ?? ''); ?></textarea>
                    </div>

                    <div class="shopee-form-row pt-8">
                        <div style="width: 130px;"></div>
                        <div class="flex gap-4 flex-1">
                            <a href="<?php echo BASE_URL; ?>/customer/services.php" class="shopee-btn-outline" style="flex:1;">Back</a>
                            <button type="submit" name="action" value="add_to_cart" class="shopee-btn-outline" style="width:2.75rem;height:2.75rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;padding:0;" title="Add to Cart"><svg style="width:1.25rem;height:1.25rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg></button>
                            <button type="submit" name="action" value="buy_now" class="shopee-btn-primary" style="flex:1.5;">Buy Now</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
.dim-label { font-size: 0.70rem; color: #94a3b8; font-weight: 600; margin-bottom: 4px; display: block; text-transform: uppercase; }
.shopee-dim-custom-row { display: flex; align-items: center; gap: 8px; }
</style>
</style>

<script>
function transUpdateOpt(input) {
    const name = input.name;
    document.querySelectorAll(`input[name="${name}"]`).forEach(function(r) {
        const wrap = r.closest('.shopee-opt-btn');
        if (wrap) wrap.classList.toggle('active', r.checked);
    });
    if (name === 'surface_application') {
        document.getElementById('surface-other-wrap').style.display = input.value === 'Others' ? 'block' : 'none';
    }
}

let dimensionMode = 'preset';

function transIncreaseQty() { const i = document.getElementById('quantity-input'); i.value = Math.min(999, (parseInt(i.value) || 1) + 1); }
function transDecreaseQty() { const i = document.getElementById('quantity-input'); if (parseInt(i.value) > 1) i.value = parseInt(i.value) - 1; }

function syncDimensions() {
    const h = document.getElementById('dimensions_hidden');
    if (dimensionMode === 'preset') {
        const btn = document.querySelector('.shopee-dim-btn.active');
        h.value = btn ? btn.dataset.dim : '';
    } else {
        const w = document.getElementById('custom_width').value.trim();
        const g = document.getElementById('custom_height').value.trim();
        h.value = (w && g) ? w + 'x' + g + ' ft' : '';
    }
}

function selectDimPreset(dim, e) {
    e.preventDefault(); dimensionMode = 'preset';
    document.querySelectorAll('.shopee-dim-btn').forEach(b => b.classList.remove('active'));
    e.target.closest('.shopee-dim-btn').classList.add('active');
    document.getElementById('dim-others-inputs').style.display = 'none';
    syncDimensions();
}

function selectDimOthers(e) {
    e.preventDefault(); dimensionMode = 'others';
    document.querySelectorAll('.shopee-dim-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('dim-others-btn').classList.add('active');
    document.getElementById('dim-others-inputs').style.display = 'flex';
    syncDimensions();
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('#transForm .shopee-opt-btn').forEach(function(w) {
        if (w.querySelector('input:checked')) w.classList.add('active');
    });
});

document.getElementById('transForm').addEventListener('submit', function(e) {
    syncDimensions();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
