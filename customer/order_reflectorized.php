<?php
/**
 * Reflectorized (Subdivision Stickers / Signages) - Service Order Form
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_order_helper.php';

require_role('Customer');
require_once __DIR__ . '/../includes/require_customer_profile_complete.php';
$customer_id = get_user_id();

$page_title = 'Order Reflectorized Signage - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';

$branches = db_query("SELECT id, branch_name FROM branches WHERE status = 'Active'");
$_svc = db_query("SELECT hero_image FROM services WHERE customer_link LIKE '%order_reflectorized%' LIMIT 1");
$display_img = (!empty($_svc) && !empty($_svc[0]['hero_image'])) ? $_svc[0]['hero_image'] : '';
if ($display_img !== '' && strpos($display_img, 'http') === false && $display_img[0] !== '/') { $display_img = '/' . ltrim($display_img, '/'); }

// Standard reflectorized sizes (inches)
$dimension_presets = [
    '6 x 12' => ['w' => 6, 'h' => 12],
    '9 x 12' => ['w' => 9, 'h' => 12],
    '12 x 18' => ['w' => 12, 'h' => 18],
    '18 x 24' => ['w' => 18, 'h' => 24],
    '24 x 36' => ['w' => 24, 'h' => 36],
];
?>

<div class="min-h-screen py-8">
    <div class="shopee-layout-container">
        <!-- Breadcrumb -->
        <div class="text-sm text-gray-500 mb-6 flex items-center gap-2">
            <a href="services.php" class="hover:text-blue-600">Services</a>
            <span>/</span>
            <span class="font-semibold text-gray-900">Reflectorized Signage</span>
        </div>

        <div class="shopee-card">
            <!-- Left Side: Image -->
            <div class="shopee-image-section">
                <div class="sticky-image-container">
                    <div class="shopee-main-image-wrap">
                        <img src="<?php echo htmlspecialchars($display_img ?: 'https://placehold.co/600x600/f8fafc/0f172a?text=Reflectorized'); ?>" alt="Reflectorized Signage" class="shopee-main-image" onerror="this.src='https://placehold.co/600x600/f8fafc/0f172a?text=Reflectorized'">
                    </div>
                </div>
            </div>

            <!-- Right Side: Form -->
            <div class="shopee-form-section">
                <h1 class="text-2xl font-bold text-gray-900 mb-2">Reflectorized Signage</h1>
                
                <?php
                $stats = service_order_get_page_stats('order_reflectorized');
                $raw_avg = (float)($stats['avg_rating'] ?? 0);
                $review_count = (int)($stats['review_count'] ?? 0);
                $sold_count = (int)($stats['sold_count'] ?? 0);
                $sold_display = $sold_count >= 1000 ? number_format($sold_count / 1000, 1) . 'k' : $sold_count;
                
                $_s_name = 'PrintFlow Service';
                $_s_row = db_query("SELECT name FROM services WHERE customer_link LIKE '%order_reflectorized%' LIMIT 1");
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

                <form id="reflectorizedForm" enctype="multipart/form-data" novalidate>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="service_type" value="Reflectorized Signage">

                    <div class="shopee-form-row">
                        <label class="shopee-form-label">Branch *</label>
                        <select name="branch_id" class="input-field shopee-form-field" required>
                            <?php foreach($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Product Type -->
                    <div class="shopee-form-row">
                        <label class="shopee-form-label">Product Type *</label>
                        <select name="product_type" id="refl_product_type" class="input-field shopee-form-field" required onchange="reflToggleProductFields()">
                            <option value="" selected disabled>Select Product Category</option>
                            <option value="Subdivision / Gate Pass (Vehicle Sticker)">Subdivision / Gate Pass (Vehicle Sticker)</option>
                            <option value="Plate Number / Temporary Plate">Plate Number / Temporary Plate</option>
                            <option value="Custom Reflectorized Sign">Custom Reflectorized Sign</option>
                        </select>
                    </div>

                    <!-- Temporary Plate Fields -->
                    <div class="refl-expand refl-tempPlateFields" style="display: none;">
                        <div class="shopee-form-row align-top">
                            <label class="shopee-form-label pt-3">Material *</label>
                            <div class="shopee-opt-group shopee-form-field">
                                <label class="shopee-opt-btn"><input type="radio" name="temp_plate_material" value="Acrylic" style="display:none;" onchange="reflUpdateOptionVisuals(this)"> <span>Acrylic</span></label>
                                <label class="shopee-opt-btn"><input type="radio" name="temp_plate_material" value="Aluminum Sheet" style="display:none;" onchange="reflUpdateOptionVisuals(this)"> <span>Aluminum Sheet</span></label>
                                <label class="shopee-opt-btn" style="grid-column: span 2;"><input type="radio" name="temp_plate_material" value="Aluminum Coated (Steel Plate)" style="display:none;" onchange="reflUpdateOptionVisuals(this)"> <span>Aluminum Coated (Steel Plate)</span></label>
                            </div>
                        </div>
                        
                        <div class="shopee-form-row">
                            <label class="shopee-form-label">Plate Info *</label>
                            <div class="shopee-form-field">
                                <div class="grid grid-cols-2 gap-4">
                                    <div><label class="dim-label">Plate Number</label><input type="text" name="temp_plate_number" id="temp_plate_number" class="input-field" placeholder="Must match OR/CR"></div>
                                    <div><label class="dim-label">Label</label><input type="text" name="temp_plate_text" class="input-field bg-gray-50" value="TEMPORARY PLATE" readonly></div>
                                    <div><label class="dim-label">MV File No.</label><input type="text" name="mv_file_number" class="input-field" placeholder="Optional"></div>
                                    <div><label class="dim-label">Dealer Name</label><input type="text" name="dealer_name" class="input-field" placeholder="Optional"></div>
                                </div>
                            </div>
                        </div>

                        <div class="shopee-form-row">
                            <label class="shopee-form-label">Order Details *</label>
                            <div class="shopee-form-field">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="dim-label">Needed Date</label>
                                        <input type="date" id="needed_date_temp" class="input-field" min="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    <div>
                                        <label class="dim-label">Quantity</label>
                                        <div class="shopee-qty-control">
                                            <button type="button" onclick="reflQtyDownTemp()" class="shopee-qty-btn">−</button>
                                            <input type="number" id="quantity_temp" class="shopee-qty-input" min="1" value="<?php echo (int)($_GET['qty'] ?? 1); ?>" oninput="reflQtyClampTemp()">
                                            <button type="button" onclick="reflQtyUpTemp()" class="shopee-qty-btn">+</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Gate Pass Fields -->
                    <div class="refl-expand refl-gatePassFields" style="display: none;">
                        <div class="shopee-form-row">
                            <label class="shopee-form-label">Subdivision *</label>
                            <input type="text" name="gate_pass_subdivision" id="gate_pass_subdivision" class="input-field shopee-form-field" placeholder="e.g. GREEN VALLEY SUBDIVISION">
                        </div>
                        <div class="shopee-form-row">
                            <label class="shopee-form-label">Gate Pass *</label>
                            <div class="shopee-form-field grid grid-cols-2 gap-4">
                                <div><label class="dim-label">Pass Number</label><input type="text" name="gate_pass_number" id="gate_pass_number" class="input-field" placeholder="e.g. GP-0215"></div>
                                <div><label class="dim-label">Plate Number</label><input type="text" name="gate_pass_plate" id="gate_pass_plate" class="input-field" placeholder="e.g. ABC 1234"></div>
                                <div><label class="dim-label">Validity Year</label><input type="text" name="gate_pass_year" id="gate_pass_year" class="input-field" placeholder="e.g. 2026"></div>
                                <div><label class="dim-label">Vehicle Type</label><select name="gate_pass_vehicle_type" class="input-field"><option value="">Select</option><option value="Car">Car</option><option value="Motorcycle">Motorcycle</option></select></div>
                            </div>
                        </div>
                        
                        <div class="shopee-form-row">
                            <label class="shopee-form-label">Size (IN) *</label>
                            <div class="shopee-form-field flex items-center gap-3">
                                <div class="flex-1"><label class="dim-label">Width</label><input type="text" id="dimensions_gatepass_w" class="input-field" placeholder="e.g. 10"></div>
                                <div class="pt-5 font-bold text-gray-300">×</div>
                                <div class="flex-1"><label class="dim-label">Height</label><input type="text" id="dimensions_gatepass_h" class="input-field" placeholder="e.g. 12"></div>
                            </div>
                        </div>

                        <div class="shopee-form-row">
                            <label class="shopee-form-label">Logo/File *</label>
                            <input type="file" name="gate_pass_logo" id="gate_pass_logo" accept=".jpg,.jpeg,.png,.pdf" class="input-field shopee-form-field">
                        </div>

                        <div class="shopee-form-row">
                            <label class="shopee-form-label">Order Details *</label>
                            <div class="shopee-form-field grid grid-cols-2 gap-4">
                                <div>
                                    <label class="dim-label">Needed Date</label>
                                    <input type="date" id="needed_date" class="input-field" min="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div>
                                    <label class="dim-label">Quantity</label>
                                    <div class="shopee-qty-control">
                                        <button type="button" onclick="reflQtyDown()" class="shopee-qty-btn">−</button>
                                        <input type="number" id="quantity" class="shopee-qty-input" min="1" value="<?php echo (int)($_GET['qty'] ?? 1); ?>" oninput="reflQtyClamp()">
                                        <button type="button" onclick="reflQtyUp()" class="shopee-qty-btn">+</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="reflNotesSection" class="shopee-form-row pt-4" style="display:none;">
                        <label class="shopee-form-label">Notes</label>
                        <textarea id="notes_shared" rows="2" class="input-field shopee-form-field" placeholder="Any special requests?"></textarea>
                    </div>

                    <!-- Custom Reflectorized Sign Section -->
                    <div id="reflCustomSection" class="refl-expand" style="display: none;">
                        <div class="shopee-form-row">
                            <label class="shopee-form-label pt-2">Dimensions *</label>
                            <div class="shopee-form-field">
                                <div class="shopee-opt-group mb-3">
                                    <?php foreach($dimension_presets as $label => $d): ?>
                                    <label class="shopee-opt-btn refl-dim-btn" data-w="<?php echo $d['w']; ?>" data-h="<?php echo $d['h']; ?>">
                                        <input type="radio" name="dimension_preset" value="<?php echo $label; ?>" style="display:none;" onchange="reflSelectDimension('<?php echo $label; ?>', <?php echo $d['w']; ?>, <?php echo $d['h']; ?>)">
                                        <span><?php echo $label; ?> in</span>
                                    </label>
                                    <?php endforeach; ?>
                                    <label class="shopee-opt-btn refl-dim-btn" data-others="1">
                                        <input type="radio" name="dimension_preset" value="Others" style="display:none;" onchange="reflSelectDimensionOthers()">
                                        <span>Others</span>
                                    </label>
                                </div>
                                <input type="hidden" id="reflDimensionsHidden">
                                <div id="reflDimOthersWrap" class="flex items-center gap-3 border-t border-dashed border-gray-100 pt-4 mt-4" style="display: none;">
                                    <div class="flex-1 text-center"><label class="dim-label">Width</label><input type="text" id="reflDimOthersW" class="input-field" placeholder="e.g. 10" oninput="reflSyncDimOthers()"></div>
                                    <div class="pt-5 font-bold text-gray-300">×</div>
                                    <div class="flex-1 text-center"><label class="dim-label">Height</label><input type="text" id="reflDimOthersH" class="input-field" placeholder="e.g. 14" oninput="reflSyncDimOthers()"></div>
                                </div>
                            </div>
                        </div>

                        <div class="shopee-form-row">
                            <label class="shopee-form-label pt-2">Laminate *</label>
                            <div class="shopee-opt-group shopee-form-field">
                                <label class="shopee-opt-btn"><input type="radio" name="laminate_option" value="With Laminate" style="display:none;" onchange="reflUpdateOptionVisuals(this)"> <span>With Lamination</span></label>
                                <label class="shopee-opt-btn"><input type="radio" name="laminate_option" value="Without Laminate" style="display:none;" onchange="reflUpdateOptionVisuals(this)"> <span>Without Lamination</span></label>
                            </div>
                        </div>

                        <div class="shopee-form-row">
                            <label class="shopee-form-label pt-2">Layout *</label>
                            <div class="shopee-opt-group shopee-form-field">
                                <label class="shopee-opt-btn"><input type="radio" name="layout" value="With Layout" style="display:none;" onchange="reflUpdateOptionVisuals(this)"> <span>With Layout</span></label>
                                <label class="shopee-opt-btn"><input type="radio" name="layout" value="Without Layout" style="display:none;" onchange="reflUpdateOptionVisuals(this)"> <span>Without Layout</span></label>
                            </div>
                        </div>

                        <div class="shopee-form-row">
                            <label class="shopee-form-label pt-2">Brand *</label>
                            <div class="shopee-opt-group shopee-form-field">
                                <label class="shopee-opt-btn"><input type="radio" name="material_type" value="Kiwalite (Japan Brand)" style="display:none;" onchange="reflUpdateOptionVisuals(this)"> <span>Kiwalite</span></label>
                                <label class="shopee-opt-btn"><input type="radio" name="material_type" value="3M Brand" style="display:none;" onchange="reflUpdateOptionVisuals(this)"> <span>3M Brand</span></label>
                            </div>
                        </div>

                        <div class="shopee-form-row">
                            <label class="shopee-form-label">Design *</label>
                            <input type="file" name="signage_logo" id="signage_logo" accept=".jpg,.jpeg,.png,.pdf" class="input-field shopee-form-field">
                        </div>

                        <div class="shopee-form-row">
                            <label class="shopee-form-label">Order Details *</label>
                            <div class="shopee-form-field grid grid-cols-2 gap-4">
                                <div>
                                    <label class="dim-label">Needed Date</label>
                                    <input type="date" id="needed_date_custom" class="input-field" min="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div>
                                    <label class="dim-label">Quantity</label>
                                    <div class="shopee-qty-control">
                                        <button type="button" onclick="reflQtyDownCustom()" class="shopee-qty-btn">−</button>
                                        <input type="number" id="quantity_custom" class="shopee-qty-input" min="1" value="<?php echo (int)($_GET['qty'] ?? 1); ?>" oninput="reflQtyClampCustom()">
                                        <button type="button" onclick="reflQtyUpCustom()" class="shopee-qty-btn">+</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="shopee-form-row">
                            <label class="shopee-form-label">Notes</label>
                            <textarea id="notes_custom" rows="2" class="input-field shopee-form-field" placeholder="Any special requests?"></textarea>
                        </div>
                    </div>

                    <input type="hidden" name="quantity_gatepass" id="quantity_gatepass">
                    <input type="hidden" name="quantity_signage" id="quantity_signage">
                    <input type="hidden" name="quantity" id="quantity_hidden">
                    <input type="hidden" name="needed_date" id="needed_date_hidden">
                    <input type="hidden" name="other_instructions" id="notes_hidden">
                    <input type="hidden" name="dimensions" id="dimensions_submit">
                    <input type="hidden" name="unit" id="unit_submit" value="in">

                    <div class="shopee-form-row pt-8">
                        <div style="width: 130px;"></div>
                        <div class="flex gap-4 flex-1">
                            <a href="<?php echo BASE_URL; ?>/customer/services.php" class="shopee-btn-outline" style="flex:1; text-align: center; line-height: 2.2;">Back</a>
                            <button type="button" onclick="reflSubmitOrder('add_to_cart')" class="shopee-btn-outline" style="width:2.75rem;height:2.75rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;padding:0;" title="Add to Cart"><svg style="width:1.25rem;height:1.25rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg></button>
                            <button type="button" onclick="reflSubmitOrder('buy_now')" class="shopee-btn-primary" style="flex:1.5;">Buy Now</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function pfBuildCustomerUrl(path) {
    var base = <?php echo json_encode(rtrim((string)BASE_URL, '/')); ?>;
    path = '/' + String(path || '').replace(/^\/+/, '');
    return window.location.origin + base + path;
}

function pfSafeCustomerRedirect(targetUrl) {
    var url;
    try {
        url = new URL(targetUrl, window.location.origin);
    } catch (err) {
        throw new Error('Invalid redirect URL.');
    }

    if (window.location.protocol === 'https:' && url.protocol !== 'https:') {
        url.protocol = 'https:';
    }
    if (url.origin !== window.location.origin) {
        throw new Error('Blocked unsafe redirect target.');
    }

    try {
        if (window.top && window.top !== window.self) {
            window.top.location.assign(url.toString());
            return;
        }
    } catch (err) {
        // Fall back to same-window navigation.
    }

    window.location.assign(url.toString());
}

async function pfParseJsonResponse(response) {
    if (!response || !response.ok) {
        throw new Error('Request failed' + (response ? ' (' + response.status + ')' : '.'));
    }
    var contentType = String(response.headers.get('content-type') || '').toLowerCase();
    if (contentType.indexOf('application/json') === -1) {
        throw new Error('Unexpected server response.');
    }
    return response.json();
}

let reflLastSelectedType = '';
window.__reflValidationTriggered = false;

function reflUpdateOptionVisuals(input) {
    const name = input.name;
    document.querySelectorAll(`input[name="${name}"]`).forEach(r => {
        const wrap = r.closest('.shopee-opt-btn');
        if (wrap) wrap.classList.toggle('active', r.checked);
    });
    if (window.__reflValidationTriggered) reflCheckFormValid();
}

function reflSelectDimension(label, w, h) {
    document.getElementById('reflDimensionsHidden').value = label;
    document.getElementById('reflDimOthersWrap').style.display = 'none';
    document.querySelectorAll('#reflCustomSection .shopee-opt-btn').forEach(b => b.classList.remove('active'));
    const btn = document.querySelector('#reflCustomSection .refl-dim-btn[data-w="' + w + '"][data-h="' + h + '"]');
    if (btn) btn.classList.add('active');
    if (window.__reflValidationTriggered) reflCheckFormValid();
}

function reflSelectDimensionOthers() {
    document.getElementById('reflDimOthersWrap').style.display = 'flex';
    document.querySelectorAll('#reflCustomSection .shopee-opt-btn').forEach(b => b.classList.remove('active'));
    document.querySelector('#reflCustomSection .refl-dim-btn[data-others="1"]')?.classList.add('active');
    reflSyncDimOthers();
}

function reflSyncDimOthers() {
    const w = (document.getElementById('reflDimOthersW')?.value || '').trim();
    const h = (document.getElementById('reflDimOthersH')?.value || '').trim();
    document.getElementById('reflDimensionsHidden').value = (w && h) ? (w + ' x ' + h) : '';
    if (window.__reflValidationTriggered) reflCheckFormValid();
}

function reflClearFieldError(container) {
    if (!container) return;
    container.classList.remove('is-invalid');
    const err = container.querySelector('.field-error');
    if (err) { err.textContent = ''; err.style.display = 'none'; }
}

function reflSetFieldError(container, message) {
    if (!container) return;
    let err = container.querySelector('.field-error');
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

function reflCheckFormValid() {
    const show = window.__reflValidationTriggered === true;
    const form = document.getElementById('reflectorizedForm');
    const branch = form.querySelector('select[name="branch_id"]');
    const type = document.getElementById('refl_product_type').value;

    const cBranch = branch.closest('.shopee-form-row');
    const cType = document.getElementById('refl_product_type').closest('.shopee-form-row');

    let ok = !!type;
    if (show) {
        reflSetFieldError(cBranch, '');
        reflSetFieldError(cType, !type ? 'This field is required' : '');
    }

    // Reset section error states
    reflClearFieldError(document.querySelector('.refl-tempPlateFields'));
    reflClearFieldError(document.querySelector('.refl-gatePassFields'));
    reflClearFieldError(document.getElementById('reflCustomSection'));

    if (type === 'Plate Number / Temporary Plate') {
        const mat = document.querySelector('input[name="temp_plate_material"]:checked');
        const plate = document.getElementById('temp_plate_number').value.trim();
        const nd = document.getElementById('needed_date_temp').value;
        const cFields = document.querySelector('.refl-tempPlateFields');
        const okTemp = !!mat && !!plate && !!nd;
        if (show && !okTemp) {
            let msg = 'Please complete all required fields';
            if (!mat) msg = 'Please select a material';
            else if (!plate) msg = 'Please enter the plate number';
            else if (!nd) msg = 'Please select a needed date';
            reflSetFieldError(cFields, msg);
        }
        ok = ok && okTemp;
    } else if (type === 'Subdivision / Gate Pass (Vehicle Sticker)') {
        const sub = document.getElementById('gate_pass_subdivision').value.trim();
        const num = document.getElementById('gate_pass_number').value.trim();
        const gplate = document.getElementById('gate_pass_plate').value.trim();
        const year = document.getElementById('gate_pass_year').value.trim();
        const w = document.getElementById('dimensions_gatepass_w').value.trim();
        const h = document.getElementById('dimensions_gatepass_h').value.trim();
        const file = document.getElementById('gate_pass_logo').files.length > 0;
        const nd = document.getElementById('needed_date').value;
        const cFields = document.querySelector('.refl-gatePassFields');
        const okGate = !!sub && !!num && !!gplate && !!year && !!w && !!h && file && !!nd;
        if (show && !okGate) {
            let msg = 'Please complete all required fields';
            if (!sub) msg = 'Please enter subdivision name';
            else if (!num) msg = 'Please enter gate pass number';
            else if (!gplate) msg = 'Please enter plate number';
            else if (!year) msg = 'Please enter year/validity';
            else if (!w || !h) msg = 'Please enter dimensions';
            else if (!file) msg = 'Please upload a design file';
            else if (!nd) msg = 'Please select a needed date';
            reflSetFieldError(cFields, msg);
        }
        ok = ok && okGate;
    } else if (type === 'Custom Reflectorized Sign') {
        const dim = document.getElementById('reflDimensionsHidden').value.trim();
        const lam = document.querySelector('input[name="laminate_option"]:checked');
        const layout = document.querySelector('input[name="layout"]:checked');
        const mat = document.querySelector('input[name="material_type"]:checked');
        const file = document.getElementById('signage_logo').files.length > 0;
        const nd = document.getElementById('needed_date_custom').value;
        const cFields = document.getElementById('reflCustomSection');
        const okCustom = !!dim && !!lam && !!layout && !!mat && file && !!nd;
        if (show && !okCustom) {
            let msg = 'Please complete all required fields';
            if (!dim) msg = 'Please select or enter dimensions';
            else if (!lam) msg = 'Please select a lamination option';
            else if (!layout) msg = 'Please select a layout option';
            else if (!mat) msg = 'Please select a material brand';
            else if (!file) msg = 'Please upload a design file';
            else if (!nd) msg = 'Please select a needed date';
            reflSetFieldError(cFields, msg);
        }
        ok = ok && okCustom;
    }

    return ok;
}

function reflToggleProductFields() {
    const type = document.getElementById('refl_product_type').value;
    document.querySelectorAll('.refl-expand').forEach(el => el.style.display = 'none');
    document.getElementById('reflNotesSection').style.display = 'none';

    // Reset required attributes first
    document.querySelectorAll('.refl-form input, .refl-form select, .refl-form textarea').forEach(el => {
        if (el.name !== 'branch_id' && el.name !== 'product_type') {
            el.removeAttribute('required');
        }
    });

    if (type === 'Plate Number / Temporary Plate') {
        document.querySelector('.refl-tempPlateFields').style.display = 'block';
        document.getElementById('reflNotesSection').style.display = 'block';
        document.getElementById('temp_plate_number').setAttribute('required', 'required');
        document.getElementById('needed_date_temp').setAttribute('required', 'required');
    } else if (type === 'Subdivision / Gate Pass (Vehicle Sticker)') {
        document.querySelector('.refl-gatePassFields').style.display = 'block';
        document.getElementById('reflNotesSection').style.display = 'block';
        document.getElementById('gate_pass_subdivision').setAttribute('required', 'required');
        document.getElementById('gate_pass_number').setAttribute('required', 'required');
        document.getElementById('gate_pass_plate').setAttribute('required', 'required');
        document.getElementById('gate_pass_year').setAttribute('required', 'required');
        document.getElementById('dimensions_gatepass_w').setAttribute('required', 'required');
        document.getElementById('dimensions_gatepass_h').setAttribute('required', 'required');
        document.getElementById('gate_pass_logo').setAttribute('required', 'required');
        document.getElementById('needed_date').setAttribute('required', 'required');
    } else if (type === 'Custom Reflectorized Sign') {
        document.getElementById('reflCustomSection').style.display = 'block';
        document.getElementById('signage_logo').setAttribute('required', 'required');
        document.getElementById('needed_date_custom').setAttribute('required', 'required');
    }
    if (window.__reflValidationTriggered) reflCheckFormValid();
}

function reflQtyUp() { const i = document.getElementById('quantity'); i.value = Math.min(999, (parseInt(i.value) || 1) + 1); }
function reflQtyDown() { const i = document.getElementById('quantity'); if (parseInt(i.value) > 1) i.value = parseInt(i.value) - 1; }
function reflQtyUpTemp() { const i = document.getElementById('quantity_temp'); i.value = Math.min(999, (parseInt(i.value) || 1) + 1); }
function reflQtyDownTemp() { const i = document.getElementById('quantity_temp'); if (parseInt(i.value) > 1) i.value = parseInt(i.value) - 1; }
function reflQtyUpCustom() { const i = document.getElementById('quantity_custom'); i.value = Math.min(999, (parseInt(i.value) || 1) + 1); }
function reflQtyDownCustom() { const i = document.getElementById('quantity_custom'); if (parseInt(i.value) > 1) i.value = parseInt(i.value) - 1; }

function reflQtyClamp() { const i = document.getElementById('quantity'); let v = parseInt(i.value) || 1; i.value = Math.min(999, Math.max(1, v)); }
function reflQtyClampTemp() { const i = document.getElementById('quantity_temp'); let v = parseInt(i.value) || 1; i.value = Math.min(999, Math.max(1, v)); }
function reflQtyClampCustom() { const i = document.getElementById('quantity_custom'); let v = parseInt(i.value) || 1; i.value = Math.min(999, Math.max(1, v)); }

function reflSubmitOrder(action) {
    window.__reflValidationTriggered = true;
    if (!reflCheckFormValid()) return;

    const form = document.getElementById('reflectorizedForm');
    const type = document.getElementById('refl_product_type').value;

    if (type === 'Plate Number / Temporary Plate') {
        document.getElementById('quantity_hidden').value = document.getElementById('quantity_temp').value;
        document.getElementById('needed_date_hidden').value = document.getElementById('needed_date_temp').value;
        document.getElementById('notes_hidden').value = document.getElementById('notes_shared').value;
        document.getElementById('dimensions_submit').value = 'Standard';
    } else if (type === 'Subdivision / Gate Pass (Vehicle Sticker)') {
        document.getElementById('quantity_hidden').value = document.getElementById('quantity').value;
        document.getElementById('needed_date_hidden').value = document.getElementById('needed_date').value;
        document.getElementById('notes_hidden').value = document.getElementById('notes_shared').value;
        document.getElementById('dimensions_submit').value = document.getElementById('dimensions_gatepass_w').value + ' x ' + document.getElementById('dimensions_gatepass_h').value;
    } else if (type === 'Custom Reflectorized Sign') {
        document.getElementById('quantity_hidden').value = document.getElementById('quantity_custom').value;
        document.getElementById('needed_date_hidden').value = document.getElementById('needed_date_custom').value;
        document.getElementById('notes_hidden').value = document.getElementById('notes_custom').value;
        document.getElementById('dimensions_submit').value = document.getElementById('reflDimensionsHidden').value;
    }

    const formData = new FormData(form);
    formData.append('action', action);

    const buttons = form.querySelectorAll('button');
    buttons.forEach(b => b.disabled = true);

    fetch(pfBuildCustomerUrl('/customer/api_add_to_cart_reflectorized.php'), {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(pfParseJsonResponse)
    .then(data => {
        if (!data || data.success !== true) {
            alert('Error: ' + ((data && data.message) ? data.message : 'Unable to continue.'));
            buttons.forEach(b => b.disabled = false);
            return;
        }

        if (action === 'buy_now' && !data.item_key) {
            throw new Error('Missing order review item key.');
        }

        var nextUrl = action === 'buy_now'
            ? (data.redirect_url || pfBuildCustomerUrl('/customer/order_review.php?item=' + encodeURIComponent(data.item_key || '')))
            : (data.cart_url || pfBuildCustomerUrl('/customer/cart.php'));

        pfSafeCustomerRedirect(nextUrl);
    })
    .catch(err => {
        console.error(err);
        alert(err && err.message ? err.message : 'An unexpected error occurred.');
        buttons.forEach(b => b.disabled = false);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    reflToggleProductFields();
});
</script>

<style>
.dim-label { font-size: 0.7rem; color: #94a3b8; font-weight: 600; margin-bottom: 4px; display: block; text-transform: uppercase; }
.refl-expand { display: flex; flex-direction: column; gap: 0rem; }
.shopee-form-row.align-top { align-items: flex-start; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
