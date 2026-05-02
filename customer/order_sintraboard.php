<?php
/**
 * Sintraboard & Standees - Service Order Form
 * PrintFlow - Clean flow: Product Type → Dimensions → Options → Upload → Needed Date + Quantity → Notes
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_order_helper.php';
require_role('Customer');
require_once __DIR__ . '/../includes/require_customer_profile_complete.php';
$customer_id = get_user_id();
$error = '';

// Common Sintraboard dimension presets (inches)
$dimension_presets = [
    '8 x 10'  => ['w' => 8,  'h' => 10],
    '12 x 18' => ['w' => 12, 'h' => 18],
    '18 x 24' => ['w' => 18, 'h' => 24],
    '24 x 36' => ['w' => 24, 'h' => 36],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $branch_id_raw = trim($_POST['branch_id'] ?? '');
    $branch_id = $branch_id_raw === '' ? 0 : (int)$branch_id_raw;
    $sintra_type = trim($_POST['sintra_type'] ?? '');
    $dimensions = trim($_POST['dimensions'] ?? '');
    $unit = trim($_POST['unit'] ?? '');
    $thickness = trim($_POST['thickness'] ?? '');
    $lamination = trim($_POST['lamination'] ?? '');
    $layout = trim($_POST['layout'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $needed_date = trim($_POST['needed_date'] ?? '');
    $quantity = (int)($_POST['quantity'] ?? 1);
    $quantity = max(1, min(999, $quantity));

    $valid_types = ['Flat Type', '2D Type (with Frame)', 'Standee (Back Stand Support)'];
    if ($branch_id < 1) {
        $error = 'Please select a branch.';
    } elseif (empty($sintra_type) || !in_array($sintra_type, $valid_types, true)) {
        $error = 'Please select a Sintraboard Type.';
    } elseif ($dimensions === '') {
        $error = 'Please specify dimensions.';
    } elseif ($unit === '' || !in_array($unit, ['in', 'ft'], true)) {
        $error = 'Please select a unit.';
    } elseif ($lamination === '' || !in_array($lamination, ['With Lamination', 'Without Lamination'], true)) {
        $error = 'Please select lamination.';
    } elseif ($layout === '' || !in_array($layout, ['With Layout', 'Without Layout'], true)) {
        $error = 'Please select layout.';
    } elseif ($thickness === '' || !in_array($thickness, ['3mm', '5mm', '10mm'], true)) {
        $error = 'Please select thickness.';
    } elseif ($needed_date === '') {
        $error = 'Please select a needed date.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $needed_date)) {
        $error = 'Please enter a valid needed date.';
    } elseif (strtotime($needed_date . ' 00:00:00') < strtotime('today')) {
        $error = 'Needed date cannot be in the past.';
    } elseif (!isset($_FILES['design_file']) || $_FILES['design_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please upload your design.';
    } else {
        $valid = service_order_validate_file($_FILES['design_file']);
        if (!$valid['ok']) {
            $error = $valid['error'];
        } else {
            $width = '';
            $height = '';
            if (preg_match('/^(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)$/i', $dimensions, $m)) {
                $width = $m[1];
                $height = $m[2];
            } else {
                $parts = preg_split('/[\s,]+/', $dimensions, 2);
                if (count($parts) >= 2) {
                    $width = trim($parts[0]);
                    $height = trim($parts[1]);
                }
            }

            if ($width === '' || $height === '') {
                $error = 'Please enter valid dimensions (e.g. 12 x 18).';
            } else {
                $tmp_dir = service_order_temp_dir();
                $ext = pathinfo($_FILES['design_file']['name'], PATHINFO_EXTENSION);
                $tmp_filename = uniqid('sintra_') . '.' . $ext;
                $tmp_path = $tmp_dir . DIRECTORY_SEPARATOR . $tmp_filename;
                file_put_contents($tmp_path, file_get_contents($_FILES['design_file']['tmp_name']));

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES['design_file']['tmp_name']);
                finfo_close($finfo);

                if (!isset($_SESSION['cart'])) {
                    $_SESSION['cart'] = [];
                }

                $product_id = ($sintra_type === 'Flat Type') ? 51 : 54;
                $product_name = $sintra_type;
                $sintra_price = ($sintra_type === 'Flat Type') ? 150.00 : 800.00;

                $item_key = $product_id . '_' . time();
                $_SESSION['cart'][$item_key] = [
                    'product_id'       => $product_id,
                    'source_page'      => 'services',
                    'branch_id'        => $branch_id,
                    'name'             => $product_name,
                    'category'         => 'Sintraboard Standees',
                    'price'            => $sintra_price,
                    'quantity'         => $quantity,
                    'image'            => '📦',
                    'customization'    => [
                        'Sintra_Type' => $sintra_type,
                        'Dimensions'  => $dimensions,
                        'Unit'        => $unit,
                        'Width'       => $width,
                        'Height'      => $height,
                        'Thickness'   => $thickness,
                        'Lamination'  => $lamination,
                        'Layout'      => $layout,
                        'needed_date' => $needed_date,
                        'notes'       => $notes,
                    ],
                    'design_notes'     => $notes,
                    'design_tmp_path'  => $tmp_path,
                    'design_mime'     => $mime,
                    'design_name'     => $_FILES['design_file']['name'],
                    'reference_tmp_path' => null,
                    'reference_mime'  => null,
                    'reference_name'  => null,
                ];

                if (($_POST['action'] ?? '') === 'buy_now' || isset($_POST['buy_now'])) {
                    redirect(BASE_URL . '/customer/order_review.php?item=' . urlencode($item_key));
                } else {
                    redirect(BASE_URL . '/customer/cart.php');
                }
            }
        }
    }
}

$page_title = 'Order Sintraboard Standees - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';

$branches = db_query("SELECT id, branch_name FROM branches WHERE status = 'Active'");
$_svc = db_query("SELECT hero_image FROM services WHERE customer_link LIKE '%order_sintraboard%' LIMIT 1");
$display_img = (!empty($_svc) && !empty($_svc[0]['hero_image'])) ? $_svc[0]['hero_image'] : '';
if ($display_img !== '' && strpos($display_img, 'http') === false && $display_img[0] !== '/') { $display_img = '/' . ltrim($display_img, '/'); }
$qty_default = (int)($_GET['qty'] ?? 1);
$qty_default = max(1, min(999, $qty_default));
$sel_type = $_POST['sintra_type'] ?? $_GET['sintra_type'] ?? '';
$sel_unit = $_POST['unit'] ?? '';
$sel_lamination = $_POST['lamination'] ?? '';
$sel_layout = $_POST['layout'] ?? '';
$sel_thickness = $_POST['thickness'] ?? '';
$other_w = '';
$other_h = '';
if (!empty($_POST['dimensions']) && preg_match('/^(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)$/i', trim((string)($_POST['dimensions'] ?? '')), $sintra_dim_m)) {
    $other_w = $sintra_dim_m[1];
    $other_h = $sintra_dim_m[2];
}
?>
<div class="min-h-screen py-8">
    <div class="shopee-layout-container">
        <!-- Breadcrumb -->
        <div class="text-sm text-gray-500 mb-6 flex items-center gap-2">
            <a href="services.php" class="hover:text-blue-600">Services</a>
            <span>/</span>
            <span class="font-semibold text-gray-900">Sintraboard & Standees</span>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="shopee-card">
            <!-- Left Side: Image -->
            <div class="shopee-image-section">
                <div class="sticky-image-container">
                    <div class="shopee-main-image-wrap">
                        <img src="<?php echo htmlspecialchars($display_img ?: 'https://placehold.co/600x600/f8fafc/0f172a?text=Sintraboard'); ?>" alt="Sintraboard & Standees" class="shopee-main-image" onerror="this.src='https://placehold.co/600x600/f8fafc/0f172a?text=Sintraboard'">
                    </div>
                </div>
            </div>

            <!-- Right Side: Form -->
            <div class="shopee-form-section">
                <h1 class="text-2xl font-bold text-gray-900 mb-2">Sintraboard & Standees</h1>
                
                <?php
                $stats = service_order_get_page_stats('order_sintraboard');
                $raw_avg = (float)($stats['avg_rating'] ?? 0);
                $review_count = (int)($stats['review_count'] ?? 0);
                $sold_count = (int)($stats['sold_count'] ?? 0);
                $sold_display = $sold_count >= 1000 ? number_format($sold_count / 1000, 1) . 'k' : $sold_count;
                
                $_s_name = 'PrintFlow Service';
                $_s_row = db_query("SELECT name FROM services WHERE customer_link LIKE '%order_sintraboard%' LIMIT 1");
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

                <form method="POST" enctype="multipart/form-data" id="sintraForm" novalidate>
                    <?php echo csrf_field(); ?>

                <div class="shopee-form-row">
                    <label class="shopee-form-label">Branch *</label>
                    <select name="branch_id" id="sintra_branch_id" class="input-field shopee-form-field" required>
                        <?php $branch_post = $_POST['branch_id'] ?? ''; ?>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo ((string)($b['id']) === (string)$branch_post) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="shopee-form-row">
                    <label class="shopee-form-label">Type *</label>
                    <div class="shopee-opt-group shopee-form-field">
                        <?php
                        $types = ['Flat Type', '2D Type (with Frame)', 'Standee (Back Stand Support)'];
                        $type_info = [
                            'Flat Type' => 'A standard flat sintraboard panel ideal for wall signs, labels, and simple display boards.',
                            '2D Type (with Frame)' => 'A framed 2D board with cleaner edge presentation, great for more premium display use.',
                            'Standee (Back Stand Support)' => 'A freestanding board with rear support so it can stand on floors or counters without wall mounting.',
                        ];
                        foreach ($types as $t):
                            $checked = ($sel_type === $t) ? 'checked' : '';
                        ?>
                        <label class="shopee-opt-btn sintra-type-row" data-info-title="<?php echo htmlspecialchars($t); ?>" data-info-body="<?php echo htmlspecialchars($type_info[$t] ?? ''); ?>">
                            <input type="radio" name="sintra_type" value="<?php echo htmlspecialchars($t); ?>" style="display:none;" <?php echo $checked; ?> onchange="sintraUpdateOptionVisuals(this)">
                            <span><?php echo htmlspecialchars($t); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="shopee-form-row">
                    <label class="shopee-form-label">Dimensions *</label>
                    <div class="shopee-form-field">
                        <div class="shopee-opt-group mb-3">
                            <?php foreach ($dimension_presets as $label => $d): ?>
                            <label class="shopee-opt-btn sintra-dim-btn" data-w="<?php echo $d['w']; ?>" data-h="<?php echo $d['h']; ?>">
                                <input type="radio" name="dimension_preset" value="<?php echo htmlspecialchars($label); ?>" style="display:none;" onchange="sintraSelectDimension('<?php echo htmlspecialchars($label); ?>', <?php echo $d['w']; ?>, <?php echo $d['h']; ?>)">
                                <span><?php echo htmlspecialchars($label); ?> in</span>
                            </label>
                            <?php endforeach; ?>
                            <label class="shopee-opt-btn sintra-dim-btn" data-others="1">
                                <input type="radio" name="dimension_preset" value="Others" style="display:none;" onchange="sintraSelectDimensionOthers()">
                                <span>Others</span>
                            </label>
                        </div>
                        <input type="hidden" name="dimensions" id="sintra_dimensions" value="<?php echo htmlspecialchars($_POST['dimensions'] ?? ''); ?>">
                        
                        <div id="sintraDimOthersWrap" class="shopee-form-row" style="display: none; border-top: 1px dashed #eee; padding-top: 1rem; margin-top: 1rem;">
                            <div class="flex-1">
                                <label class="dim-label">Width <span class="sintra-dim-others-unit"></span></label>
                                <input type="text" id="sintra_dim_other_w" class="input-field" inputmode="decimal" placeholder="e.g. 10" value="<?php echo htmlspecialchars($other_w); ?>" oninput="sintraSyncDimOthers()">
                            </div>
                            <div class="dim-sep" style="height:44px; display:flex; align-items:center;">×</div>
                            <div class="flex-1">
                                <label class="dim-label">Height <span class="sintra-dim-others-unit"></span></label>
                                <input type="text" id="sintra_dim_other_h" class="input-field" inputmode="decimal" placeholder="e.g. 12" value="<?php echo htmlspecialchars($other_h); ?>" oninput="sintraSyncDimOthers()">
                            </div>
                        </div>

                        <div class="mt-4">
                            <label class="dim-label mb-2">Select Unit</label>
                            <div class="shopee-opt-group">
                                <label class="shopee-opt-btn">
                                    <input type="radio" name="unit" value="in" required <?php echo $sel_unit === 'in' ? 'checked' : ''; ?> style="display:none;" onchange="sintraUpdateOptionVisuals(this)">
                                    <span>Inches (in)</span>
                                </label>
                                <label class="shopee-opt-btn">
                                    <input type="radio" name="unit" value="ft" <?php echo $sel_unit === 'ft' ? 'checked' : ''; ?> style="display:none;" onchange="sintraUpdateOptionVisuals(this)">
                                    <span>Feet (ft)</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="shopee-form-row">
                    <label class="shopee-form-label">Laminate *</label>
                    <div class="shopee-opt-group shopee-form-field">
                        <label class="shopee-opt-btn">
                            <input type="radio" name="lamination" value="With Lamination" required <?php echo $sel_lamination === 'With Lamination' ? 'checked' : ''; ?> style="display:none;" onchange="sintraUpdateOptionVisuals(this)">
                            <span>With Lamination</span>
                        </label>
                        <label class="shopee-opt-btn">
                            <input type="radio" name="lamination" value="Without Lamination" <?php echo $sel_lamination === 'Without Lamination' ? 'checked' : ''; ?> style="display:none;" onchange="sintraUpdateOptionVisuals(this)">
                            <span>Without Lamination</span>
                        </label>
                    </div>
                </div>

                <div class="shopee-form-row">
                    <label class="shopee-form-label">Layout *</label>
                    <div class="shopee-opt-group shopee-form-field">
                        <label class="shopee-opt-btn">
                            <input type="radio" name="layout" value="With Layout" required <?php echo $sel_layout === 'With Layout' ? 'checked' : ''; ?> style="display:none;" onchange="sintraUpdateOptionVisuals(this)">
                            <span>With Layout</span>
                        </label>
                        <label class="shopee-opt-btn">
                            <input type="radio" name="layout" value="Without Layout" <?php echo $sel_layout === 'Without Layout' ? 'checked' : ''; ?> style="display:none;" onchange="sintraUpdateOptionVisuals(this)">
                            <span>Without Layout</span>
                        </label>
                    </div>
                </div>

                <div class="shopee-form-row">
                    <label class="shopee-form-label">Thickness *</label>
                    <div class="shopee-opt-group shopee-form-field">
                        <?php foreach (['3mm', '5mm', '10mm'] as $ti => $th): ?>
                        <label class="shopee-opt-btn">
                            <input type="radio" name="thickness" value="<?php echo htmlspecialchars($th); ?>" style="display:none;" <?php echo $ti === 0 ? 'required' : ''; ?> <?php echo $sel_thickness === $th ? 'checked' : ''; ?> onchange="sintraUpdateOptionVisuals(this)">
                            <span><?php echo htmlspecialchars($th); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="shopee-form-row">
                    <label class="shopee-form-label">Design *</label>
                    <input type="file" name="design_file" id="sintra_design_file" accept=".jpg,.jpeg,.png,.pdf" class="input-field shopee-form-field" required>
                </div>

                <div class="shopee-form-row" id="sintra-need-qty-card">
                    <label class="shopee-form-label">Order Detail *</label>
                    <div class="shopee-form-field">
                        <div class="need-qty-row">
                            <div class="flex-1">
                                <label class="dim-label">Needed Date</label>
                                <input type="date" name="needed_date" id="sintra_needed_date" class="input-field" value="<?php echo htmlspecialchars($_POST['needed_date'] ?? ''); ?>" required min="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="flex-1">
                                <label class="dim-label">Quantity</label>
                                <div class="shopee-qty-control">
                                    <button type="button" onclick="sintraQtyDown()" class="shopee-qty-btn">−</button>
                                    <input type="number" name="quantity" id="sintra_quantity" class="shopee-qty-input" min="1" max="999" value="<?php echo (int)($_POST['quantity'] ?? $qty_default); ?>" oninput="sintraQtyClamp()">
                                    <button type="button" onclick="sintraQtyUp()" class="shopee-qty-btn">+</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="shopee-form-row pt-4">
                    <label class="shopee-form-label">Notes</label>
                    <textarea name="notes" rows="3" class="input-field shopee-form-field" placeholder="Any special requests?" maxlength="500"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                </div>

                <div class="shopee-form-row pt-8">
                    <div style="width: 130px;"></div>
                    <div class="flex gap-4 flex-1">
                        <a href="<?php echo BASE_URL; ?>/customer/services.php" class="shopee-btn-outline" style="flex: 1;">Back</a>
                        <button type="submit" name="action" value="add_to_cart" class="shopee-btn-outline" style="width:2.75rem;height:2.75rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;padding:0;" title="Add to Cart"><svg style="width:1.25rem;height:1.25rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg></button>
                        <button type="submit" name="action" value="buy_now" class="shopee-btn-primary" style="flex: 1.5;">Buy Now</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
</div>


<div id="sintraTypeInfoModal" class="sintra-info-modal" style="display:none;">
    <div class="sintra-info-modal-backdrop" onclick="sintraCloseInfoModal()"></div>
    <div class="sintra-info-modal-card" role="dialog" aria-modal="true" aria-labelledby="sintraInfoTitle">
        <button type="button" class="sintra-info-modal-close" onclick="sintraCloseInfoModal()" aria-label="Close">×</button>
        <h3 id="sintraInfoTitle" class="sintra-info-modal-title"></h3>
        <p id="sintraInfoBody" class="sintra-info-modal-body"></p>
    </div>
</div>

<script>
function sintraOpenInfoModal(title, body) {
    var modal = document.getElementById('sintraTypeInfoModal');
    var titleEl = document.getElementById('sintraInfoTitle');
    var bodyEl = document.getElementById('sintraInfoBody');
    if (!modal || !titleEl || !bodyEl) return;
    titleEl.textContent = title || 'Sintraboard Type';
    bodyEl.textContent = body || '';
    modal.style.display = 'flex';
}

function sintraCloseInfoModal() {
    var modal = document.getElementById('sintraTypeInfoModal');
    if (modal) modal.style.display = 'none';
}

function sintraClearFieldError(container) {
    if (!container) return;
    var err = container.querySelector('.field-error');
    container.classList.remove('is-invalid');
    if (err) {
        err.textContent = '';
        err.style.display = 'none';
    }
}

function sintraSetFieldError(container, message) {
    if (!container) return;
    var err = container.querySelector('.field-error');
    if (message) {
        if (!err) { 
            err = document.createElement('div'); 
            err.className = 'field-error'; 
            container.appendChild(err); 
        }
        container.classList.add('is-invalid');
        err.innerHTML = `<span class="refl-error-icon">⚠</span> ${message}`;
        err.style.display = 'flex';
    } else {
        container.classList.remove('is-invalid');
        if (err) { err.textContent = ''; err.style.display = 'none'; }
    }
}

function sintraCheckFormValid() {
    var showErrors = window.__sintraValidationTriggered === true;
    var form = document.getElementById('sintraForm');
    if (!form) return true;

    var branch = form.querySelector('select[name="branch_id"]');
    var sintraType = form.querySelector('input[name="sintra_type"]:checked');
    var dimEl = document.getElementById('sintra_dimensions');
    var dim = (dimEl && dimEl.value.trim()) || '';
    var othersWrap = document.getElementById('sintraDimOthersWrap');
    var othersOpen = othersWrap && othersWrap.style.display !== 'none';
    var owIn = document.getElementById('sintra_dim_other_w');
    var ohIn = document.getElementById('sintra_dim_other_h');
    var wv = (owIn && owIn.value.trim()) || '';
    var hv = (ohIn && ohIn.value.trim()) || '';
    var unit = form.querySelector('input[name="unit"]:checked');
    var lamination = form.querySelector('input[name="lamination"]:checked');
    var layout = form.querySelector('input[name="layout"]:checked');
    var thickness = form.querySelector('input[name="thickness"]:checked');
    var file = document.getElementById('sintra_design_file');
    var neededDate = document.getElementById('sintra_needed_date');
    var qtyInput = document.getElementById('sintra_quantity');
    var qty = qtyInput ? (parseInt(qtyInput.value, 10) || 0) : 0;

    var cBranch = branch && branch.closest('.mb-4');
    var cType = form.querySelector('input[name="sintra_type"]') && form.querySelector('input[name="sintra_type"]').closest('.mb-4');
    var cDim = dimEl && dimEl.closest('.mb-4');
    var cLam = form.querySelector('input[name="lamination"]') && form.querySelector('input[name="lamination"]').closest('.mb-4');
    var cLayout = form.querySelector('input[name="layout"]') && form.querySelector('input[name="layout"]').closest('.mb-4');
    var cThick = form.querySelector('input[name="thickness"]') && form.querySelector('input[name="thickness"]').closest('.mb-4');
    var cFile = file && file.closest('.mb-4');
    var cNeedQty = document.getElementById('sintra-need-qty-card');

    var ok = !!(sintraType && dim && (!othersOpen || (wv && hv)) && unit && lamination && layout && thickness && file && file.files && file.files.length > 0 && neededDate && neededDate.value.trim() !== '' && qty >= 1);

    if (showErrors) {
        sintraSetFieldError(cBranch, '');
        sintraSetFieldError(cType, !sintraType ? 'This field is required' : '');
        var dimMsg = '';
        if (!dim) dimMsg = 'This field is required';
        else if (othersOpen && (!wv || !hv)) dimMsg = 'This field is required';
        else if (!unit) dimMsg = 'This field is required';
        sintraSetFieldError(cDim, dimMsg);
        sintraSetFieldError(cLam, !lamination ? 'This field is required' : '');
        sintraSetFieldError(cLayout, !layout ? 'This field is required' : '');
        sintraSetFieldError(cThick, !thickness ? 'This field is required' : '');
        sintraSetFieldError(cFile, !(file && file.files && file.files.length > 0) ? 'This field is required' : '');
        sintraSetFieldError(cNeedQty, (!neededDate || !neededDate.value.trim() || qty < 1) ? 'This field is required' : '');
    } else {
        [cBranch, cType, cDim, cLam, cLayout, cThick, cFile, cNeedQty].forEach(sintraClearFieldError);
    }

    return ok;
}

function sintraUpdateOthersUnitLabels() {
    var u = document.querySelector('#sintraForm input[name="unit"]:checked');
    var t = '';
    if (u && u.value === 'ft') t = '(FT)';
    else if (u && u.value === 'in') t = '(IN)';
    document.querySelectorAll('#sintraDimOthersWrap .sintra-dim-others-unit').forEach(function(el) {
        el.textContent = t;
    });
}

function sintraUpdateOptionVisuals(input) {
    const name = input.name;
    document.querySelectorAll('#sintraForm input[name="' + name + '"]').forEach(function(r) {
        const wrap = r.closest('.shopee-opt-btn');
        if (wrap) { wrap.classList.remove('active'); if (r.checked) wrap.classList.add('active'); }
    });
    if (input && input.name === 'unit') sintraUpdateOthersUnitLabels();
    sintraCheckFormValid();
}

function sintraSelectDimension(label, w, h) {
    document.getElementById('sintra_dimensions').value = w + ' x ' + h;
    document.getElementById('sintraDimOthersWrap').style.display = 'none';
    var ow = document.getElementById('sintra_dim_other_w');
    var oh = document.getElementById('sintra_dim_other_h');
    if (ow) ow.value = '';
    if (oh) oh.value = '';
    document.querySelectorAll('.sintra-dim-btn').forEach(function(b) { b.classList.remove('active'); });
    var btn = document.querySelector('.sintra-dim-btn[data-w="' + w + '"][data-h="' + h + '"]');
    if (btn) btn.classList.add('active');
    sintraCheckFormValid();
}

function sintraSelectDimensionOthers() {
    document.getElementById('sintraDimOthersWrap').style.display = 'block';
    document.getElementById('sintra_dimensions').value = '';
    document.querySelectorAll('.sintra-dim-btn').forEach(function(b) { b.classList.remove('active'); });
    var others = document.querySelector('.sintra-dim-btn[data-others="1"]');
    if (others) others.classList.add('active');
    sintraCheckFormValid();
}

function sintraSyncDimOthers() {
    var w = document.getElementById('sintra_dim_other_w');
    var h = document.getElementById('sintra_dim_other_h');
    var wv = w ? w.value.trim() : '';
    var hv = h ? h.value.trim() : '';
    document.getElementById('sintra_dimensions').value = (wv && hv) ? (wv + ' x ' + hv) : '';
    sintraCheckFormValid();
}

function sintraQtyUp() {
    var q = document.getElementById('sintra_quantity');
    q.value = Math.min(999, (parseInt(q.value, 10) || 1) + 1);
    sintraCheckFormValid();
}
function sintraQtyDown() {
    var q = document.getElementById('sintra_quantity');
    q.value = Math.max(1, (parseInt(q.value, 10) || 1) - 1);
    sintraCheckFormValid();
}
function sintraQtyClamp() {
    var q = document.getElementById('sintra_quantity');
    var v = parseInt(q.value, 10) || 1;
    q.value = Math.max(1, Math.min(999, v));
    sintraCheckFormValid();
}

document.getElementById('sintra_design_file').addEventListener('change', function(e) {
    sintraCheckFormValid();
});

document.getElementById('sintraForm').addEventListener('invalid', function(e) {
    e.preventDefault();
}, true);

document.getElementById('sintraForm').addEventListener('change', sintraCheckFormValid);
document.getElementById('sintraForm').addEventListener('input', sintraCheckFormValid);

document.getElementById('sintraForm').addEventListener('submit', function(e) {
    window.__sintraValidationTriggered = true;
    if (!sintraCheckFormValid()) {
        e.preventDefault();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('#sintraForm .opt-btn-wrap').forEach(function(w) {
        if (w.querySelector('input:checked')) w.classList.add('active');
    });
    document.querySelectorAll('#sintraForm .sintra-type-row').forEach(function(row) {
        row.addEventListener('click', function() {
            sintraOpenInfoModal(row.getAttribute('data-info-title') || '', row.getAttribute('data-info-body') || '');
        });
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') sintraCloseInfoModal();
    });
    var dimVal = document.getElementById('sintra_dimensions').value.trim();
    if (dimVal) {
        var matched = false;
        document.querySelectorAll('.sintra-dim-btn[data-w]').forEach(function(btn) {
            var w = btn.getAttribute('data-w');
            var h = btn.getAttribute('data-h');
            if (dimVal === w + ' x ' + h) {
                btn.classList.add('active');
                var inp = btn.querySelector('input[type="radio"]');
                if (inp) inp.checked = true;
                matched = true;
            }
        });
        if (!matched && dimVal) {
            var others = document.querySelector('.sintra-dim-btn[data-others="1"]');
            if (others) {
                others.classList.add('active');
                var or = others.querySelector('input[type="radio"]');
                if (or) or.checked = true;
            }
            document.getElementById('sintraDimOthersWrap').style.display = 'block';
            var m = dimVal.match(/^(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)$/i);
            var ow = document.getElementById('sintra_dim_other_w');
            var oh = document.getElementById('sintra_dim_other_h');
            if (m && ow && oh) {
                ow.value = m[1];
                oh.value = m[2];
            } else if (ow && oh) {
                ow.value = dimVal;
                oh.value = '';
            }
        }
    }
    sintraUpdateOthersUnitLabels();
    sintraCheckFormValid();
});
</script>

<style>
/* Service Specific Tweaks */
.dim-label { font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 4px; display: block; text-transform: uppercase; }
.field-error {
    font-size: 0.75rem;
    color: #f59e0b;
    margin-top: 8px;
    display: none;
    align-items: center;
    gap: 6px;
    font-weight: 600;
    width: 100%;
    flex-basis: 100%;
}
.refl-error-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 15px;
    height: 15px;
    background: #f59e0b;
    color: #fff;
    border-radius: 50%;
    font-size: 11px;
    font-weight: 900;
    flex-shrink: 0;
}
.need-qty-row { display: flex; gap: 16px; width: 100%; }
.dim-sep { height: 44px; display: flex; align-items: center; color: #cbd5e1; font-weight: bold; }

@media (max-width: 640px) {
    .need-qty-row { flex-direction: column; }
}
#sintra_quantity::-webkit-outer-spin-button,
#sintra_quantity::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
#sintra_quantity { -moz-appearance: textfield; appearance: textfield; }

.sintra-info-modal { position: fixed; inset: 0; z-index: 100000; display:none; align-items: center; justify-content: center; padding: 1rem; }
.sintra-info-modal[style*="flex"] { display: flex !important; }
.sintra-info-modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.4); }
.sintra-info-modal-card { position: relative; width: 100%; max-width: 520px; background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 1.5rem; box-shadow: 0 18px 40px rgba(0,0,0,0.12); }
.sintra-info-modal-close { position: absolute; top: 0.75rem; right: 0.75rem; width: 30px; height: 30px; border-radius: 999px; border: 1px solid #e2e8f0; background: #f8fafc; color: #64748b; font-size: 1.1rem; cursor: pointer; display:flex; align-items:center; justify-content:center; }
.sintra-info-modal-close:hover { background: #f1f5f9; }
.sintra-info-modal-title { margin: 0 2rem 0.6rem 0; color: #0f172a; font-size: 1.05rem; font-weight: 800; }
.sintra-info-modal-body { margin: 0; color: #475569; line-height: 1.6; font-size: 0.92rem; }

@media (max-width: 640px) {
    .tshirt-actions-row { flex-direction: column; align-items: stretch; }
}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

