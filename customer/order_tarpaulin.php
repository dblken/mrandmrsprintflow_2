<?php
/**
 * Tarpaulin Printing - Service Order Form
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_order_helper.php';

require_role('Customer');
require_once __DIR__ . '/../includes/require_customer_profile_complete.php';
require_once __DIR__ . '/../includes/require_id_verified.php';
$customer_id = get_user_id();

$error = '';

// Pricelist image - check both possible locations
$base_path = __DIR__ . '/../public/';
$pricelist_url = null;
foreach (['images/tarp price range/', 'assets/images/tarp price range/'] as $subpath) {
    foreach (['.webp', '.jpg', '.jpeg', '.png'] as $ext) {
        if (file_exists($base_path . $subpath . 'pricelist' . $ext)) {
            $pricelist_url = (defined('BASE_URL') ? BASE_URL : '/printflow') . '/public/' . $subpath . 'pricelist' . $ext;
            break 2;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $branch_id = (int)($_POST['branch_id'] ?? 1);
    $width = trim($_POST['width'] ?? '');
    $height = trim($_POST['height'] ?? '');
    $unit = trim($_POST['unit'] ?? 'ft');
    $lamination = trim($_POST['lamination'] ?? '');
    $finish = trim($_POST['finish'] ?? '');
    $with_eyelets = trim($_POST['with_eyelets'] ?? '');
    $layout = trim($_POST['layout'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 1);
    $needed_date = trim($_POST['needed_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (empty($width) || empty($height) || $quantity < 1) {
        $error = 'Please fill in Dimensions (Width, Height) and Quantity.';
    } elseif ((function_exists('mb_strlen') ? mb_strlen($notes) : strlen($notes)) > 500) {
        $error = 'Notes must not exceed 500 characters.';
    } elseif (empty($needed_date)) {
        $error = 'Please select when you need the order.';
    } elseif (!isset($_FILES['design_file']) || $_FILES['design_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please upload your design.';
    } else {
        $valid = service_order_validate_file($_FILES['design_file']);
        if (!$valid['ok']) {
            $error = $valid['error'];
        } else {
            $item_key = 'tarp_' . time() . '_' . rand(100, 999);
            $original_name = $_FILES['design_file']['name'];
            $mime = $valid['mime'];
            $ext = pathinfo($original_name, PATHINFO_EXTENSION);
            $new_name = uniqid('tmp_') . '.' . $ext;
            $tmp_dest = service_order_temp_dir() . DIRECTORY_SEPARATOR . $new_name;

            if (move_uploaded_file($_FILES['design_file']['tmp_name'], $tmp_dest)) {
                $w = (float)$width;
                $h = (float)$height;
                $area = $w * $h;
                if ($unit === 'in') {
                    $area = $area / 144;
                }
                $price_per_sqft = 20.00;
                $unit_price = $area * $price_per_sqft;  // Price per piece
                
                $_SESSION['cart'][$item_key] = [
                    'type' => 'Service',
                    'source_page' => 'services',
                    'name' => 'Tarpaulin Printing',
                    'price' => $unit_price,  // Unit price, not total
                    'quantity' => $quantity,
                    'category' => 'Tarpaulin',
                    'branch_id' => $branch_id,
                    'design_tmp_path' => $tmp_dest,
                    'design_name' => $original_name,
                    'design_mime' => $mime,
                    'customization' => [
                        'width' => $width,
                        'height' => $height,
                        'unit' => $unit,
                        'lamination' => $lamination,
                        'finish' => $finish,
                        'with_eyelets' => $with_eyelets,
                        'layout' => $layout,
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

$page_title = 'Order Tarpaulin - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';

$branches = db_query("SELECT id, branch_name FROM branches WHERE status = 'Active'");
$_svc = db_query("SELECT hero_image FROM services WHERE customer_link LIKE '%order_tarpaulin%' LIMIT 1");
$display_img = (!empty($_svc) && !empty($_svc[0]['hero_image'])) ? $_svc[0]['hero_image'] : '';
if ($display_img !== '' && strpos($display_img, 'http') === false && $display_img[0] !== '/') { $display_img = '/' . ltrim($display_img, '/'); }

$stats = service_order_get_page_stats('order_tarpaulin');

$avg_rating = number_format((float)($stats['avg_rating'] ?? 0), 1);
$review_count = (int)($stats['review_count'] ?? 0);
$sold_count = (int)($stats['sold_count'] ?? 0);

// Format large sold numbers
$sold_display = $sold_count;
if ($sold_count >= 1000) {
    $sold_display = number_format($sold_count / 1000, 1) . 'k';
}
?>

<div class="min-h-screen py-8">
    <div class="shopee-layout-container">
        <!-- Breadcrumb -->
        <div class="text-sm text-gray-500 mb-6 flex items-center gap-2">
            <a href="services.php" class="hover:text-blue-600">Services</a>
            <span>/</span>
            <span class="font-semibold text-gray-900">Tarpaulin</span>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="shopee-card">
            <!-- Left Side: Image -->
            <div class="shopee-image-section">
                <div class="sticky-image-container">
                    <div class="shopee-main-image-wrap">
                        <img src="<?php echo htmlspecialchars($display_img ?: 'https://placehold.co/600x600/f8fafc/0f172a?text=Tarpaulin'); ?>" alt="Tarpaulin" class="shopee-main-image" onerror="this.src='https://placehold.co/600x600/f8fafc/0f172a?text=Tarpaulin'">
                    </div>
                </div>
            </div>

            <!-- Right Side: Form -->
            <div class="shopee-form-section">
                <div class="flex items-start justify-between mb-2">
                    <h1 class="text-2xl font-bold text-gray-900 mr-2 flex-1">Tarpaulin Printing</h1>
                    <button type="button" onclick="openPricelistModal()" style="border: 1px solid #0a2530; color: #0a2530; background: rgba(10, 37, 48, 0.05); padding: 0.35rem 0.75rem; border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer; white-space: nowrap; transition: all 0.2s;" onmouseover="this.style.background='rgba(10, 37, 48, 0.12)'" onmouseout="this.style.background='rgba(10, 37, 48, 0.05)'">
                        View Price List
                    </button>
                </div>
                
                <div class="flex items-center gap-4 mb-6 pb-6 border-b border-gray-100">
                    <div class="flex items-center gap-1">
                        <?php 
                        $raw_avg = (float)($stats['avg_rating'] ?? 0);
                        for($i=1; $i<=5; $i++): 
                        ?>
                            <svg class="w-4 h-4" style="fill: <?php echo ($i <= round($raw_avg)) ? '#FBBF24' : '#E2E8F0'; ?>;" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <?php endfor; ?>
                        
                        <?php if ($review_count > 0): ?>
                            <a href="reviews.php?service_id=<?php echo $stats['service_id']; ?>" class="text-sm text-gray-500 hover:text-blue-500 hover:underline ml-1 cursor-pointer">(<?php echo number_format($review_count); ?> Reviews)</a>
                        <?php endif; ?>
                    </div>
                    <div class="h-4 w-px bg-gray-200"></div>
                    <div class="text-sm text-gray-500"><?php echo $sold_display; ?> Sold</div>
                </div>

                <form action="" method="POST" enctype="multipart/form-data" id="tarpForm" novalidate>
                    <?php echo csrf_field(); ?>

                
                <div class="shopee-form-row">
                    <label class="shopee-form-label">Branch *</label>
                    <select name="branch_id" class="input-field shopee-form-field" required>
                        <?php foreach($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['branch_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="shopee-form-row" id="card-dimensions">
                    <label class="shopee-form-label">Size (ft) *</label>
                    <div class="shopee-form-field">
                        <div class="shopee-opt-group mb-3">
                            <button type="button" class="shopee-opt-btn" data-width="3" data-height="4" onclick="selectDimension(3, 4, event)">3×4</button>
                            <button type="button" class="shopee-opt-btn" data-width="4" data-height="6" onclick="selectDimension(4, 6, event)">4×6</button>
                            <button type="button" class="shopee-opt-btn" data-width="5" data-height="8" onclick="selectDimension(5, 8, event)">5×8</button>
                            <button type="button" class="shopee-opt-btn" data-width="6" data-height="8" onclick="selectDimension(6, 8, event)">6×8</button>
                            <button type="button" class="shopee-opt-btn" id="dim-others-btn" onclick="selectDimensionOthers(event)">Others</button>
                        </div>
                        
                        <div id="dim-others-inputs" class="shopee-form-row" style="display: none; border-top: 1px dashed #eee; padding-top: 1rem; margin-top: 1rem;">
                            <div class="flex-1">
                                <label class="dim-label">Width</label>
                                <input type="text" inputmode="numeric" id="custom_width" class="input-field" placeholder="ft">
                            </div>
                            <div class="dim-sep">×</div>
                            <div class="flex-1">
                                <label class="dim-label">Height</label>
                                <input type="text" inputmode="numeric" id="custom_height" class="input-field" placeholder="ft">
                            </div>
                        </div>

                    </div>
                    <input type="hidden" name="width" id="width_hidden">
                    <input type="hidden" name="height" id="height_hidden">
                    <input type="hidden" name="unit" value="ft">
                </div>

                <div class="shopee-form-row">
                    <label class="shopee-form-label">Finish *</label>
                    <div class="shopee-opt-group shopee-form-field">
                        <label class="shopee-opt-btn"><input type="radio" name="finish" value="Matte" style="display:none;" required> <span>Matte</span></label>
                        <label class="shopee-opt-btn"><input type="radio" name="finish" value="Glossy" style="display:none;" required> <span>Glossy</span></label>
                    </div>
                </div>

                <div class="shopee-form-row">
                    <label class="shopee-form-label">Laminate *</label>
                    <div class="shopee-opt-group shopee-form-field">
                        <label class="shopee-opt-btn"><input type="radio" name="lamination" value="With Laminate" style="display:none;" required> <span>With Laminate</span></label>
                        <label class="shopee-opt-btn"><input type="radio" name="lamination" value="Without Laminate" style="display:none;" required> <span>Without Laminate</span></label>
                    </div>
                </div>

                <div class="shopee-form-row">
                    <label class="shopee-form-label">Eyelets *</label>
                    <div class="shopee-opt-group shopee-form-field">
                        <label class="shopee-opt-btn"><input type="radio" name="with_eyelets" value="Yes" style="display:none;" required> <span>Yes</span></label>
                        <label class="shopee-opt-btn"><input type="radio" name="with_eyelets" value="No" style="display:none;" required> <span>No</span></label>
                    </div>
                </div>

                <div class="shopee-form-row" id="card-upload">
                    <label class="shopee-form-label">Design *</label>
                    <input type="file" name="design_file" id="design_file" accept=".jpg,.jpeg,.png,.pdf" class="input-field shopee-form-field" required>
                </div>

                <div class="shopee-form-row" id="card-layout">
                    <label class="shopee-form-label">Layout *</label>
                    <div class="shopee-opt-group shopee-form-field">
                        <label class="shopee-opt-btn"><input type="radio" name="layout" value="With Layout" style="display:none;" required> <span>With Layout</span></label>
                        <label class="shopee-opt-btn"><input type="radio" name="layout" value="Without Layout" style="display:none;" required> <span>Without Layout</span></label>
                    </div>
                </div>

                <div class="shopee-form-row" id="card-date-qty">
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
                                    <button type="button" class="shopee-qty-btn" onclick="decreaseQty()">−</button>
                                    <input type="number" id="quantity-input" name="quantity" class="shopee-qty-input" min="1" value="<?php echo (int)($_POST['quantity'] ?? ($_GET['qty'] ?? 1)); ?>">
                                    <button type="button" class="shopee-qty-btn" onclick="increaseQty()">+</button>
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
                        <a href="<?php echo BASE_URL; ?>/customer/services.php" class="shopee-btn-outline" style="flex: 1;">Back</a>
                        <button type="submit" name="action" value="add_to_cart" class="shopee-btn-outline" style="width:2.75rem;height:2.75rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;padding:0;" title="Add to Cart"><svg style="width:1.25rem;height:1.25rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg></button>
                        <button type="submit" name="action" value="buy_now" id="buyNowBtn" class="shopee-btn-primary" style="flex: 1.5;">Buy Now</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
</div>



<!-- Pricelist Modal -->
<div id="pricelist-modal" style="display: none; position: fixed; inset: 0; z-index: 99999; align-items: center; justify-content: center; padding: 1.5rem; background: rgba(0,0,0,0.5);">
    <div onclick="closePricelistModal()" style="position: absolute; inset: 0;"></div>
    <div style="position: relative; background: #0a2530; border: 1px solid rgba(83, 197, 224, 0.28); border-radius: 1rem; max-width: 72vw; max-height: 90vh; overflow: auto; box-shadow: 0 25px 50px rgba(0,0,0,0.45);">
        <button onclick="closePricelistModal()" style="position: absolute; top: 0.75rem; right: 0.75rem; z-index: 10; width: 36px; height: 36px; border: 1px solid rgba(83, 197, 224, 0.28); background: rgba(15, 53, 68, 0.95); border-radius: 50%; cursor: pointer; font-size: 1.25rem; line-height: 1; color: #d8edf5;">×</button>
        <?php if ($pricelist_url): ?>
        <img src="<?php echo htmlspecialchars($pricelist_url); ?>" alt="Tarpaulin Pricelist" style="width: 100%; max-width: 640px; display: block;">
        <?php else: ?>
        <div style="padding: 3rem; text-align: center; color: #6b7280;">Pricelist image not found.</div>
        <?php endif; ?>
    </div>
</div>

<style>
/* Service Specific Tweaks */
.dim-sep { height: 40px; display: flex; align-items: center; color: #cbd5e1; font-weight: bold; }
.dim-label { font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 4px; display: block; text-transform: uppercase; }
.need-qty-row { display: flex; gap: 16px; width: 100%; }

@media (max-width: 640px) {
    .need-qty-row { flex-direction: column; }
}
</style>

<script>
let dimensionMode = 'preset';

function openPricelistModal() { document.getElementById('pricelist-modal').style.display = 'flex'; document.body.style.overflow = 'hidden'; }
function closePricelistModal() { document.getElementById('pricelist-modal').style.display = 'none'; document.body.style.overflow = ''; }

function syncDimensionToHidden() {
    const wh = document.getElementById('width_hidden');
    const hh = document.getElementById('height_hidden');
    if (dimensionMode === 'preset') {
        const btn = document.querySelector('.shopee-opt-btn.active');
        wh.value = btn ? btn.dataset.width : '';
        hh.value = btn ? btn.dataset.height : '';
    } else {
        wh.value = document.getElementById('custom_width').value;
        hh.value = document.getElementById('custom_height').value;
    }
}

function selectDimension(w, h, e) {
    e.preventDefault();
    dimensionMode = 'preset';
    document.querySelectorAll('.shopee-opt-btn').forEach(b => b.classList.remove('active'));
    e.target.closest('.shopee-opt-btn').classList.add('active');
    document.getElementById('dim-others-inputs').style.display = 'none';
    syncDimensionToHidden();
}

function selectDimensionOthers(e) {
    e.preventDefault();
    dimensionMode = 'others';
    document.querySelectorAll('.shopee-opt-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('dim-others-btn').classList.add('active');
    document.getElementById('dim-others-inputs').style.display = 'flex';
    syncDimensionToHidden();
}

function increaseQty() { const i = document.getElementById('quantity-input'); i.value = Math.min(999, (parseInt(i.value) || 1) + 1); }
function decreaseQty() { const i = document.getElementById('quantity-input'); if (parseInt(i.value) > 1) i.value = parseInt(i.value) - 1; }

document.getElementById('tarpForm').addEventListener('submit', function(e) {
    syncDimensionToHidden();
});

var finishIcon = document.getElementById('finish-info-icon');
var finishTooltip = document.getElementById('finish-tooltip');
if (finishIcon && finishTooltip) {
    finishIcon.addEventListener('mouseenter', () => finishTooltip.classList.add('visible'));
    finishIcon.addEventListener('mouseleave', () => finishTooltip.classList.remove('visible'));
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
