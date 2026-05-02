<?php
/**
 * Decals / Stickers - Service Order Form
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
    $shape = trim($_POST['shape'] ?? 'Custom'); // Shape hidden for now; default
    $w_in = trim((string)($_POST['width_in'] ?? ''));
    $h_in = trim((string)($_POST['height_in'] ?? ''));
    $size = ($w_in !== '' && $h_in !== '') ? ($w_in . 'x' . $h_in) : '';
    $finish = trim($_POST['finish'] ?? '');
    $laminate_option = trim($_POST['laminate_option'] ?? '');
    $layout = trim($_POST['layout'] ?? '');
    if (!in_array($finish, ['Glossy', 'Matte'], true)) {
        $finish = 'Glossy';
    }
    if (!in_array($laminate_option, ['With Laminate', 'Without Laminate'], true)) {
        $laminate_option = 'Without Laminate';
    }
    if (!in_array($layout, ['With Layout', 'Without Layout'], true)) {
        $layout = '';
    }
    $needed_date = trim($_POST['needed_date'] ?? '');
    $quantity = max(1, min(999, (int)($_POST['quantity'] ?? 1)));
    $notes = trim($_POST['notes'] ?? '');

    if ($w_in === '' || $h_in === '' || empty($needed_date) || $quantity < 1 || empty($layout)) {
        $error = 'Please fill in all required fields including layout.';
    } elseif ((function_exists('mb_strlen') ? mb_strlen($notes) : strlen($notes)) > 500) {
        $error = 'Notes must not exceed 500 characters.';
    } elseif (!isset($_FILES['design_file']) || $_FILES['design_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please upload your design.';
    } else {
        $valid = service_order_validate_file($_FILES['design_file']);
        if (!$valid['ok']) {
            $error = $valid['error'];
        } else {
            $item_key = 'stickers_' . time() . '_' . rand(100, 999);
            
            $original_name = $_FILES['design_file']['name'];
            $mime = $valid['mime'];
            $ext = pathinfo($original_name, PATHINFO_EXTENSION);
            $new_name = uniqid('tmp_') . '.' . $ext;
            $tmp_dest = service_order_temp_dir() . DIRECTORY_SEPARATOR . $new_name;

            if (move_uploaded_file($_FILES['design_file']['tmp_name'], $tmp_dest)) {
                $_SESSION['cart'][$item_key] = [
                    'type' => 'Service',
                    'source_page' => 'services',
                    'name' => 'Decals / Stickers',
                    'price' => 50.00, // Base price per cut/set
                    'quantity' => $quantity,
                    'category' => 'Decals & Stickers',
                    'branch_id' => $branch_id,
                    'design_tmp_path' => $tmp_dest,
                    'design_name' => $original_name,
                    'design_mime' => $mime,
                    'customization' => [
                        'shape' => $shape,
                        'size' => $size,
                        'finish' => $finish,
                        'laminate_option' => $laminate_option,
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

$page_title = 'Order Stickers - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';

$branches = db_query("SELECT id, branch_name FROM branches WHERE status = 'Active'");
$_svc = db_query("SELECT hero_image FROM services WHERE customer_link LIKE '%order_stickers%' AND customer_link NOT LIKE '%order_glass_stickers%' AND customer_link NOT LIKE '%order_transparent%' LIMIT 1");
$display_img = (!empty($_svc) && !empty($_svc[0]['hero_image'])) ? $_svc[0]['hero_image'] : '';
if ($display_img !== '' && strpos($display_img, 'http') === false && $display_img[0] !== '/') { $display_img = '/' . ltrim($display_img, '/'); }
$stickers_finish_val = $_POST['finish'] ?? '';
if ($stickers_finish_val !== '' && !in_array($stickers_finish_val, ['Glossy', 'Matte'], true)) {
    $stickers_finish_val = 'Glossy';
}
$stickers_lam_val = $_POST['laminate_option'] ?? '';
if ($stickers_lam_val !== '' && !in_array($stickers_lam_val, ['With Laminate', 'Without Laminate'], true)) {
    $stickers_lam_val = 'Without Laminate';
}
?>

<div class="min-h-screen py-8">
    <div class="shopee-layout-container">
        <!-- Breadcrumb -->
        <div class="text-sm text-gray-500 mb-6 flex items-center gap-2">
            <a href="services.php" class="hover:text-blue-600">Services</a>
            <span>/</span>
            <span class="font-semibold text-gray-900">Stickers</span>
        </div>

        <?php if ($error): ?>
            <div class="stickers-form-error mb-6"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="shopee-card">
            <!-- Left Side: Image -->
            <div class="shopee-image-section">
                <div class="sticky-image-container">
                    <div class="shopee-main-image-wrap">
                        <img src="<?php echo htmlspecialchars($display_img ?: 'https://placehold.co/600x600/f8fafc/0f172a?text=Stickers'); ?>" alt="Stickers" class="shopee-main-image" onerror="this.src='https://placehold.co/600x600/f8fafc/0f172a?text=Stickers'">
                    </div>
                </div>
            </div>

            <!-- Right Side: Form -->
            <div class="shopee-form-section">
                <h1 class="text-2xl font-bold text-gray-900 mb-2">Decals / Stickers</h1>
                
                <?php
                $stats = service_order_get_page_stats('order_stickers');
                $raw_avg = (float)($stats['avg_rating'] ?? 0);
                $review_count = (int)($stats['review_count'] ?? 0);
                $sold_count = (int)($stats['sold_count'] ?? 0);
                $sold_display = $sold_count >= 1000 ? number_format($sold_count / 1000, 1) . 'k' : $sold_count;
                
                $_s_name = 'PrintFlow Service';
                $_s_row = db_query("SELECT name FROM services WHERE customer_link LIKE '%order_stickers%' LIMIT 1");
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

                <form action="" method="POST" enctype="multipart/form-data" id="stickersForm" novalidate>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="shape" value="Custom">
                <input type="hidden" name="shape" value="Custom">
                
                <div class="shopee-form-row" id="card-branch-stickers">
                    <label class="shopee-form-label">Branch *</label>
                    <select name="branch_id" id="stickers_branch_id" class="input-field shopee-form-field" required>
                        <?php foreach($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['branch_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="shopee-form-row" id="card-dim-stickers">
                    <label class="shopee-form-label">Dimensions (in) *</label>
                    <div class="shopee-form-field">
                        <div class="stickers-dim-row">
                            <div>
                                <label class="stickers-dim-field-label">Width</label>
                                <input type="text" name="width_in" id="stickers_width_in" class="input-field" inputmode="decimal" required placeholder="e.g. 10" value="<?php echo htmlspecialchars($_POST['width_in'] ?? ''); ?>">
                            </div>
                            <div class="stickers-dim-sep">×</div>
                            <div>
                                <label class="stickers-dim-field-label">Height</label>
                                <input type="text" name="height_in" id="stickers_height_in" class="input-field" inputmode="decimal" required placeholder="e.g. 12" value="<?php echo htmlspecialchars($_POST['height_in'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="shopee-form-row" id="card-finish-stickers">
                    <label class="shopee-form-label">Finish *</label>
                    <div class="shopee-opt-group shopee-form-field">
                        <label class="shopee-opt-btn"><input type="radio" name="finish" value="Glossy" style="display:none;" required <?php echo $stickers_finish_val === 'Glossy' ? 'checked' : ''; ?>> <span>Glossy</span></label>
                        <label class="shopee-opt-btn"><input type="radio" name="finish" value="Matte" style="display:none;" <?php echo $stickers_finish_val === 'Matte' ? 'checked' : ''; ?>> <span>Matte</span></label>
                    </div>
                </div>

                <div class="shopee-form-row" id="card-laminate-stickers">
                    <label class="shopee-form-label">Laminate *</label>
                    <div class="shopee-opt-group shopee-form-field">
                        <label class="shopee-opt-btn"><input type="radio" name="laminate_option" value="With Laminate" style="display:none;" required <?php echo $stickers_lam_val === 'With Laminate' ? 'checked' : ''; ?>> <span>With Laminate</span></label>
                        <label class="shopee-opt-btn"><input type="radio" name="laminate_option" value="Without Laminate" style="display:none;" <?php echo $stickers_lam_val === 'Without Laminate' ? 'checked' : ''; ?>> <span>Without Laminate</span></label>
                    </div>
                </div>

                <div class="shopee-form-row" id="card-layout-stickers">
                    <label class="shopee-form-label">Layout *</label>
                    <div class="shopee-opt-group shopee-form-field">
                        <label class="shopee-opt-btn"><input type="radio" name="layout" value="With Layout" style="display:none;" required> <span>With Layout</span></label>
                        <label class="shopee-opt-btn"><input type="radio" name="layout" value="Without Layout" style="display:none;"> <span>Without Layout</span></label>
                    </div>
                </div>

                <div class="shopee-form-row" id="card-date-qty-stickers">
                    <label class="shopee-form-label">Scheduling *</label>
                    <div class="shopee-form-field">
                        <div class="need-qty-row">
                            <div class="need-qty-date">
                                <label class="stickers-dim-field-label">Needed Date</label>
                                <input type="date" name="needed_date" id="stickers_needed_date" class="input-field" required min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($_POST['needed_date'] ?? ''); ?>">
                            </div>
                            <div class="need-qty-qty">
                                <label class="stickers-dim-field-label">Quantity</label>
                                <div class="shopee-qty-control">
                                    <button type="button" class="shopee-qty-btn" onclick="stickerQtyDown()">−</button>
                                    <input type="number" id="sticker-qty" name="quantity" class="shopee-qty-input" min="1" max="999" required value="<?php echo (int)($_POST['quantity'] ?? ($_GET['qty'] ?? 1)); ?>" oninput="stickerQtyClamp()">
                                    <button type="button" class="shopee-qty-btn" onclick="stickerQtyUp()">+</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="shopee-form-row" id="card-upload-stickers">
                    <label class="shopee-form-label">Design *</label>
                    <input type="file" name="design_file" id="stickers_design_file" accept=".jpg,.jpeg,.png,.pdf" class="input-field shopee-form-field" required>
                </div>

                <div class="shopee-form-row" id="card-notes-stickers">
                    <label class="shopee-form-label">Notes</label>
                    <textarea name="notes" rows="3" class="input-field shopee-form-field" placeholder="Any special instructions..." maxlength="500"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                </div>

                <div class="shopee-form-row pt-6">
                    <div style="width: 130px;"></div> <!-- Spacer for label alignment -->
                    <div class="flex gap-4 flex-1">
                        <a href="<?php echo BASE_URL; ?>/customer/services.php" class="shopee-btn-outline" style="flex: 1;">Back</a>
                        <button type="submit" name="action" value="add_to_cart" class="shopee-btn-outline" style="width:2.75rem;height:2.75rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;padding:0;" title="Add to Cart"><svg style="width:1.25rem;height:1.25rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg></button>
                        <button type="submit" name="action" value="buy_now" id="stickersBuyNowBtn" class="shopee-btn-primary" style="flex: 1.5;">Buy Now</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
</div>

<style>
/* Service Specific Tweaks */
.stickers-dim-row { display: flex; align-items: flex-end; gap: 8px; width: 100%; }
.stickers-dim-row > div { flex: 1; }
.stickers-dim-field-label { font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 4px; display: block; text-transform: uppercase; }
.stickers-dim-sep { height: 40px; display: flex; align-items: center; color: #cbd5e1; font-weight: bold; }

.need-qty-row { display: flex; gap: 16px; width: 100%; }
.need-qty-date { flex: 1.5; }
.need-qty-qty { flex: 1; }

.stickers-form-error {
    background: #fff5f5;
    border: 1px solid #feb2b2;
    color: #c53030;
    padding: 12px;
    border-radius: 2px;
    font-size: 0.875rem;
}

/* Validation styling override for row based layout */
#stickersForm .shopee-form-row.is-invalid {
    background: #fffafa;
}
.field-error {
    font-size: 0.75rem;
    color: #e53e3e;
    margin-top: 4px;
    display: block;
}

@media (max-width: 640px) {
    .need-qty-row { flex-direction: column; }
    .stickers-dim-row { flex-direction: column; align-items: stretch; }
    .stickers-dim-sep { display: none; }
}
</style>
<div id="finishInfoModal" class="stickers-finish-modal" style="display:none; position:fixed; inset:0; z-index:99999; align-items:center; justify-content:center; padding:1rem;">
    <div onclick="closeFinishInfo()" style="position:absolute; inset:0; background:rgba(2,12,18,0.72);"></div>
    <div class="stickers-finish-modal-inner" style="position:relative; background:#0a2530; border:1px solid rgba(83,197,224,0.28); border-radius:14px; width:min(920px, 96vw); max-height:90vh; overflow:auto; padding:1rem 1rem 1.25rem;">
        <button type="button" onclick="closeFinishInfo()" class="stickers-modal-close" style="position:absolute; top:8px; right:8px; border:1px solid rgba(83,197,224,0.28); background:rgba(15,53,68,0.95); border-radius:999px; width:30px; height:30px; cursor:pointer; color:#d8edf5;">×</button>
        <h3 style="font-size:1.1rem; font-weight:800; margin:0 0 0.8rem 0; color:#eaf6fb;">Finish Type Guide</h3>
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:0.9rem;">
            <div style="border:1px solid rgba(83,197,224,0.24); border-radius:12px; padding:0.8rem; background:rgba(13,43,56,0.6);">
                <div style="font-weight:800; margin-bottom:0.5rem; color:#eaf6fb;">Matte Finish</div>
                <img src="<?php echo (defined('BASE_PATH') ? BASE_PATH : ''); ?>/public/images/products/product_21.jpg" alt="Matte sample" style="width:100%; height:160px; object-fit:cover; border-radius:10px; margin-bottom:0.6rem;">
                <ul style="margin:0; padding-left:1.05rem; font-size:0.86rem; color:#c2deea; line-height:1.5;">
                    <li>Non-shiny surface</li><li>Smooth and elegant appearance</li><li>Reduces glare and reflections</li><li>Ideal for minimalist, professional, or premium designs</li><li>Easier to read under strong lighting</li>
                </ul>
            </div>
            <div style="border:1px solid rgba(83,197,224,0.24); border-radius:12px; padding:0.8rem; background:rgba(13,43,56,0.6);">
                <div style="font-weight:800; margin-bottom:0.5rem; color:#eaf6fb;">Glossy Finish</div>
                <img src="<?php echo (defined('BASE_PATH') ? BASE_PATH : ''); ?>/public/images/products/product_26.jpg" alt="Glossy sample" style="width:100%; height:160px; object-fit:cover; border-radius:10px; margin-bottom:0.6rem;">
                <ul style="margin:0; padding-left:1.05rem; font-size:0.86rem; color:#c2deea; line-height:1.5;">
                    <li>Shiny and reflective surface</li><li>Colors appear more vibrant and bright</li><li>Eye-catching and smooth texture</li><li>Best for colorful designs, logos, and photos</li><li>Reflects light and gives a polished look</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function openFinishInfo() {
    document.getElementById('finishInfoModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeFinishInfo() {
    document.getElementById('finishInfoModal').style.display = 'none';
    document.body.style.overflow = '';
}
['stickers_width_in', 'stickers_height_in'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) {
        el.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9.]/g, '').replace(/(\..*)\./g, '$1').slice(0, 8);
            if (window.__stickersValidationTriggered) checkStickersFormValid();
        });
    }
});

function clearStickersFieldError(container) {
    if (!container) return;
    var err = container.querySelector('.field-error');
    container.classList.remove('is-invalid');
    if (err) { err.textContent = ''; err.style.display = 'none'; }
}
function setStickersFieldError(container, message) {
    if (!container) return;
    var err = container.querySelector('.field-error');
    if (message) {
        if (!err) { err = document.createElement('div'); err.className = 'field-error'; container.appendChild(err); }
        container.classList.add('is-invalid');
        err.textContent = message;
        err.style.display = 'block';
    } else {
        container.classList.remove('is-invalid');
        if (err) { err.textContent = ''; err.style.display = 'none'; }
    }
}
function checkStickersFormValid() {
    var show = window.__stickersValidationTriggered === true;
    var branch = document.getElementById('stickers_branch_id');
    var w = document.getElementById('stickers_width_in');
    var h = document.getElementById('stickers_height_in');
    var finish = document.querySelector('input[name="finish"]:checked');
    var lam = document.querySelector('input[name="laminate_option"]:checked');
    var nd = document.getElementById('stickers_needed_date');
    var qty = parseInt(document.getElementById('sticker-qty').value, 10) || 0;
    var file = document.getElementById('stickers_design_file');
    var notesEl = document.querySelector('#stickersForm textarea[name="notes"]');
    var notesLen = (notesEl && notesEl.value) ? notesEl.value.length : 0;

    var cBranch = document.getElementById('card-branch-stickers');
    var cDim = document.getElementById('card-dim-stickers');
    var cFinish = document.getElementById('card-finish-stickers');
    var cLam = document.getElementById('card-laminate-stickers');
    var cDateQty = document.getElementById('card-date-qty-stickers');
    var cUpload = document.getElementById('card-upload-stickers');
    var cNotes = document.getElementById('card-notes-stickers');

    var okBranch = true;
    var okDim = w && h && w.value.trim() !== '' && h.value.trim() !== '';
    var okFinish = !!finish;
    var okLam = !!lam;
    var okLayout = !!document.querySelector('input[name="layout"]:checked');
    var okDateQty = nd && nd.value.trim() !== '' && qty >= 1;
    var okFile = file && file.files && file.files.length > 0;
    var okNotes = notesLen <= 500;

    var cLayout = document.getElementById('card-layout-stickers');

    if (show) {
        setStickersFieldError(cBranch, !okBranch ? 'This field is required' : '');
        setStickersFieldError(cDim, !okDim ? 'This field is required' : '');
        setStickersFieldError(cFinish, !okFinish ? 'This field is required' : '');
        setStickersFieldError(cLam, !okLam ? 'This field is required' : '');
        setStickersFieldError(cLayout, !okLayout ? 'This field is required' : '');
        setStickersFieldError(cDateQty, !okDateQty ? 'This field is required' : '');
        setStickersFieldError(cUpload, !okFile ? 'This field is required' : '');
        setStickersFieldError(cNotes, !okNotes ? 'Notes must not exceed 500 characters' : '');
    } else {
        [cBranch, cDim, cFinish, cLam, cLayout, cDateQty, cUpload, cNotes].forEach(clearStickersFieldError);
    }
    return okBranch && okDim && okFinish && okLam && okLayout && okDateQty && okFile && okNotes;
}

var stickersFormEl = document.getElementById('stickersForm');
if (stickersFormEl) {
// Form validation is handled by the global order_validation.js
// No manual submit intercept needed for basic validation
    stickersFormEl.addEventListener('change', checkStickersFormValid);
    stickersFormEl.addEventListener('input', checkStickersFormValid);
    stickersFormEl.addEventListener('invalid', function(e) { e.preventDefault(); }, true);
}
var stickersFileEl = document.getElementById('stickers_design_file');
if (stickersFileEl) stickersFileEl.addEventListener('change', checkStickersFormValid);
var sq = document.getElementById('sticker-qty');
if (sq) sq.addEventListener('input', function() { if (window.__stickersValidationTriggered) checkStickersFormValid(); });
function stickerQtyClamp() {
    const input = document.getElementById('sticker-qty');
    let v = parseInt(input.value, 10);
    if (!v || v < 1) v = 1;
    if (v > 999) v = 999;
    input.value = v;
    if (window.__stickersValidationTriggered) checkStickersFormValid();
}
function stickerQtyUp() {
    const input = document.getElementById('sticker-qty');
    let v = parseInt(input.value, 10) || 1;
    input.value = Math.min(v + 1, 999);
    if (window.__stickersValidationTriggered) checkStickersFormValid();
}
function stickerQtyDown() {
    const input = document.getElementById('sticker-qty');
    let v = parseInt(input.value, 10) || 1;
    input.value = Math.max(v - 1, 1);
    if (window.__stickersValidationTriggered) checkStickersFormValid();
}
checkStickersFormValid();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
