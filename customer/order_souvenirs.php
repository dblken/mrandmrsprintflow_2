<?php
/**
 * Souvenirs - Service Order Form
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_order_helper.php';
require_role('Customer');
require_once __DIR__ . '/../includes/require_customer_profile_complete.php';
$customer_id = get_user_id();

$page_title = 'Order Souvenirs - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';

$branches = db_query("SELECT id, branch_name FROM branches WHERE status = 'Active'");
$_svc = db_query("SELECT hero_image FROM services WHERE customer_link LIKE '%order_souvenirs%' LIMIT 1");
$display_img = (!empty($_svc) && !empty($_svc[0]['hero_image'])) ? $_svc[0]['hero_image'] : '';
if ($display_img !== '' && strpos($display_img, 'http') === false && $display_img[0] !== '/') { $display_img = '/' . ltrim($display_img, '/'); }
$souvenir_type_options = ['Mug', 'Keychain', 'Tote Bag', 'Pen', 'Tumbler', 'T-Shirt'];
?>
<div class="min-h-screen py-8">
    <div class="shopee-layout-container">
        <!-- Breadcrumb -->
        <div class="text-sm text-gray-500 mb-6 flex items-center gap-2">
            <a href="services.php" class="hover:text-blue-600">Services</a>
            <span>/</span>
            <span class="font-semibold text-gray-900">Souvenirs</span>
        </div>

        <div class="shopee-card">
            <!-- Left Side: Image -->
            <div class="shopee-image-section">
                <div class="sticky top-24">
                    <div class="shopee-main-image-wrap">
                        <img src="<?php echo htmlspecialchars($display_img ?: 'https://placehold.co/600x600/f8fafc/0f172a?text=Souvenirs'); ?>" alt="Souvenirs" class="shopee-main-image" onerror="this.src='https://placehold.co/600x600/f8fafc/0f172a?text=Souvenirs'">
                    </div>
                </div>
            </div>

            <!-- Right Side: Form -->
            <div class="shopee-form-section">
                <h1 class="text-2xl font-bold text-gray-900 mb-2">Souvenirs</h1>
                
                <?php
                $stats = service_order_get_page_stats('order_souvenirs');
                $raw_avg = (float)($stats['avg_rating'] ?? 0);
                $review_count = (int)($stats['review_count'] ?? 0);
                $sold_count = (int)($stats['sold_count'] ?? 0);
                $sold_display = $sold_count >= 1000 ? number_format($sold_count / 1000, 1) . 'k' : $sold_count;
                
                $_s_name = 'PrintFlow Service';
                $_s_row = db_query("SELECT name FROM services WHERE customer_link LIKE '%order_souvenirs%' LIMIT 1");
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

                <form id="souvenirForm" method="POST" enctype="multipart/form-data" novalidate>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="service_type" value="Souvenirs">

                    <div class="shopee-form-row" id="card-branch-souvenir">
                        <label class="shopee-form-label">Branch *</label>
                        <select name="branch_id" id="souvenir_branch_id" class="input-field shopee-form-field" required>
                            <?php foreach($branches as $b): ?>
                                <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="shopee-form-row" id="souvenir-type-section">
                        <label class="shopee-form-label pt-2">Type *</label>
                        <div class="shopee-form-field">
                            <div class="shopee-opt-group">
                                <?php foreach ($souvenir_type_options as $st): ?>
                                <label class="shopee-opt-btn"><input type="radio" name="souvenir_type" value="<?php echo htmlspecialchars($st); ?>" required style="display:none;" onchange="souvenirUpdateOpt(this)"> <span><?php echo htmlspecialchars($st); ?></span></label>
                                <?php endforeach; ?>
                                <label class="shopee-opt-btn"><input type="radio" name="souvenir_type" value="Others" style="display:none;" onchange="souvenirUpdateOpt(this)"> <span>Others</span></label>
                            </div>
                            <div id="souvenir-type-other-wrap" style="display: none; margin-top: 0.75rem;">
                                <input type="text" name="souvenir_type_other" id="souvenir_type_other" class="input-field" placeholder="Specify other type">
                            </div>
                        </div>
                    </div>

                    <div class="shopee-form-row" id="card-custom-print-souvenir">
                        <label class="shopee-form-label pt-2">Custom Print? *</label>
                        <div class="shopee-opt-group shopee-form-field">
                            <label class="shopee-opt-btn"><input type="radio" name="custom_print" value="No" required style="display:none;" onchange="toggleDesignUpload(); souvenirUpdateOpt(this)"> <span>No</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="custom_print" value="Yes" required style="display:none;" onchange="toggleDesignUpload(); souvenirUpdateOpt(this)"> <span>Yes (I have a design)</span></label>
                        </div>
                    </div>

                    <div class="shopee-form-row" id="card-upload-souvenir">
                        <label class="shopee-form-label">
                            Design <span id="upload-asterisk" class="text-red-500" style="display: none;">*</span>
                            <span id="upload-hint" class="block text-xs font-normal text-gray-400">(Optional)</span>
                        </label>
                        <input type="file" name="design_file" id="design_file" accept=".jpg,.jpeg,.png,.pdf" class="input-field shopee-form-field">
                    </div>

                    <div class="shopee-form-row" id="card-lamination-souvenir">
                        <label class="shopee-form-label pt-2">Lamination *</label>
                        <div class="shopee-opt-group shopee-form-field">
                            <label class="shopee-opt-btn"><input type="radio" name="lamination" value="With Lamination" required style="display:none;" onchange="souvenirUpdateOpt(this)"> <span>With Lamination</span></label>
                            <label class="shopee-opt-btn"><input type="radio" name="lamination" value="Without Lamination" required style="display:none;" onchange="souvenirUpdateOpt(this)"> <span>Without Lamination</span></label>
                        </div>
                    </div>

                    <div class="shopee-form-row pt-4 border-t border-gray-50" id="card-date-qty-souvenir">
                        <label class="shopee-form-label pt-2">Order Details *</label>
                        <div class="shopee-form-field">
                            <div class="grid grid-cols-2 gap-4">
                                <div id="souvenir-wrap-date">
                                    <label class="dim-label">Needed Date</label>
                                    <input type="date" name="needed_date" id="needed_date" class="input-field" required min="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div id="souvenir-wrap-qty">
                                    <label class="dim-label">Quantity</label>
                                    <div class="shopee-qty-control">
                                        <button type="button" class="shopee-qty-btn" onclick="souvenirQtyDown()">−</button>
                                        <input type="number" id="souvenir-qty" name="quantity" class="shopee-qty-input" min="1" max="999" required value="<?php echo max(1, (int)($_GET['qty'] ?? 1)); ?>" oninput="souvenirQtyClamp()">
                                        <button type="button" class="shopee-qty-btn" onclick="souvenirQtyUp()">+</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="shopee-form-row">
                        <label class="shopee-form-label">Notes</label>
                        <textarea name="notes" rows="3" class="input-field shopee-form-field" placeholder="e.g., preferred colors, text to print, placement..."></textarea>
                    </div>

                    <div class="shopee-form-row pt-8">
                        <div style="width: 130px;"></div>
                        <div class="flex gap-4 flex-1">
                            <a href="<?php echo BASE_URL; ?>/customer/services.php" class="shopee-btn-outline" style="flex:1;">Back</a>
                            <button type="button" onclick="submitSouvenirOrder('add_to_cart')" class="shopee-btn-outline" style="width:2.75rem;height:2.75rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;padding:0;" title="Add to Cart"><svg style="width:1.25rem;height:1.25rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg></button>
                            <button type="button" onclick="submitSouvenirOrder('buy_now')" class="shopee-btn-primary" style="flex:1.5;">Buy Now</button>
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
        // Fall through to same-window navigation.
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

function souvenirUpdateOpt(input) {
    const name = input.name;
    document.querySelectorAll('input[name="' + name + '"]').forEach(function(r) {
        const wrap = r.closest('.shopee-opt-btn');
        if (wrap) { wrap.classList.remove('active'); if (r.checked) wrap.classList.add('active'); }
    });
    if (input.name === 'souvenir_type') {
        toggleSouvenirTypeOther();
    }
}

function toggleSouvenirTypeOther() {
    var checked = document.querySelector('#souvenirForm input[name="souvenir_type"]:checked');
    var wrap = document.getElementById('souvenir-type-other-wrap');
    var inp = document.getElementById('souvenir_type_other');
    var show = checked && checked.value === 'Others';
    if (wrap) wrap.style.display = show ? 'block' : 'none';
    if (!show && inp) inp.value = '';
    if (window.__souvenirValidationTriggered) checkSouvenirFormValid();
}

function clearSouvenirFieldError(container) {
    if (!container) return;
    var err = container.querySelector('.field-error');
    container.classList.remove('is-invalid');
    if (err) { err.textContent = ''; err.style.display = 'none'; }
}

function setSouvenirFieldError(container, message) {
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

function checkSouvenirFormValid() {
    var show = window.__souvenirValidationTriggered === true;
    var branchSel = document.getElementById('souvenir_branch_id');
    var typeEl = document.querySelector('#souvenirForm input[name="souvenir_type"]:checked');
    var typeOtherInp = document.getElementById('souvenir_type_other');
    var typeOtherVal = (typeOtherInp && typeOtherInp.value) ? typeOtherInp.value.trim() : '';
    var typeOtherLen = typeOtherVal.length;
    var nd = document.getElementById('needed_date');
    var qtyEl = document.getElementById('souvenir-qty');
    var qty = parseInt(qtyEl && qtyEl.value, 10) || 0;
    var file = document.getElementById('design_file');
    var customEl = document.querySelector('input[name="custom_print"]:checked');
    var lamEl = document.querySelector('input[name="lamination"]:checked');
    var customYes = customEl && customEl.value === 'Yes';

    var okBranch = true;
    var okType = !!typeEl && (typeEl.value !== 'Others' || (typeOtherLen >= 1 && typeOtherLen <= 50));
    var okCustom = !!customEl;
    var okLam = !!lamEl;
    var okDate = false;
    if (nd && nd.value) {
        var d = new Date(nd.value + 'T12:00:00');
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        if (d >= today) okDate = true;
    }
    var okQty = qty >= 1 && qty <= 999;
    var okFile = !customYes || (file && file.files && file.files.length > 0);

    var cBranch = document.getElementById('card-branch-souvenir');
    var cType = document.getElementById('souvenir-type-section');
    var cCustom = document.getElementById('card-custom-print-souvenir');
    var cLam = document.getElementById('card-lamination-souvenir');
    var cUpload = document.getElementById('card-upload-souvenir');
    var cDate = document.getElementById('souvenir-wrap-date');
    var cQty = document.getElementById('souvenir-wrap-qty');

    var reqMsg = 'This field is required';
    if (show) {
        setSouvenirFieldError(cBranch, !okBranch ? reqMsg : '');
        setSouvenirFieldError(cType, !okType ? reqMsg : '');
        setSouvenirFieldError(cCustom, !okCustom ? reqMsg : '');
        setSouvenirFieldError(cLam, !okLam ? reqMsg : '');
        setSouvenirFieldError(cUpload, !okFile ? reqMsg : '');
        setSouvenirFieldError(cDate, !okDate ? reqMsg : '');
        setSouvenirFieldError(cQty, !okQty ? reqMsg : '');
    } else {
        [cBranch, cType, cCustom, cLam, cUpload, cDate, cQty].forEach(clearSouvenirFieldError);
    }
    return okBranch && okType && okCustom && okLam && okDate && okQty && okFile;
}

function toggleDesignUpload() {
    const checked = document.querySelector('input[name="custom_print"]:checked');
    const isYes = checked && checked.value === 'Yes';
    const hint = document.getElementById('upload-hint');
    const fileInput = document.getElementById('design_file');
    const shell = document.getElementById('souvenir-upload-shell');
    const asterisk = document.getElementById('upload-asterisk');

    if (isYes) {
        hint.textContent = '(Required)';
        hint.style.color = '#ef4444';
        hint.style.fontWeight = '700';
        fileInput.required = true;
        if (asterisk) { asterisk.style.display = 'inline'; }
        if (shell) {
            shell.classList.add('souvenir-upload-shell--required');
        }
    } else {
        hint.textContent = '(Optional)';
        hint.style.color = '#9ca3af';
        hint.style.fontWeight = 'normal';
        fileInput.required = false;
        if (asterisk) { asterisk.style.display = 'none'; }
        if (shell) {
            shell.classList.remove('souvenir-upload-shell--required');
        }
    }
    if (window.__souvenirValidationTriggered) checkSouvenirFormValid();
}

function souvenirQtyClamp() {
    const input = document.getElementById('souvenir-qty');
    if (!input) return;
    let v = parseInt(input.value, 10);
    if (!v || v < 1) v = 1;
    if (v > 999) v = 999;
    input.value = v;
    if (window.__souvenirValidationTriggered) checkSouvenirFormValid();
}

function souvenirQtyUp() {
    const input = document.getElementById('souvenir-qty');
    if (!input) return;
    let v = parseInt(input.value, 10) || 1;
    input.value = Math.min(v + 1, 999);
    if (window.__souvenirValidationTriggered) checkSouvenirFormValid();
}

function souvenirQtyDown() {
    const input = document.getElementById('souvenir-qty');
    if (!input) return;
    let v = parseInt(input.value, 10) || 1;
    input.value = Math.max(v - 1, 1);
    if (window.__souvenirValidationTriggered) checkSouvenirFormValid();
}

function submitSouvenirOrder(action) {
    const form = document.getElementById('souvenirForm');
    form.dataset.action = action;
    if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
    } else {
        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('#souvenirForm .shopee-opt-btn').forEach(function(w) {
        if (w.querySelector('input:checked')) w.classList.add('active');
    });
    toggleSouvenirTypeOther();
    toggleDesignUpload();
    checkSouvenirFormValid();
});

var souvenirFormEl = document.getElementById('souvenirForm');
if (souvenirFormEl) {
    souvenirFormEl.addEventListener('invalid', function(e) { e.preventDefault(); }, true);
    souvenirFormEl.addEventListener('change', function(e) {
        var t = e.target;
        if (t && t.type === 'radio') {
            if (t.name === 'custom_print') {
                toggleDesignUpload();
            }
            souvenirUpdateOpt(t);
        }
        if (window.__souvenirValidationTriggered) checkSouvenirFormValid();
    });
    souvenirFormEl.addEventListener('input', function() {
        if (window.__souvenirValidationTriggered) checkSouvenirFormValid();
    });
    souvenirFormEl.addEventListener('submit', function(e) {
        e.preventDefault();
        window.__souvenirValidationTriggered = true;
        if (!checkSouvenirFormValid()) {
            return;
        }
        const action = this.dataset.action || 'add_to_cart';
        const buttons = this.querySelectorAll('button');
        buttons.forEach(btn => btn.disabled = true);

        const formData = new FormData(this);
        formData.append('action', action);

        fetch(pfBuildCustomerUrl('/customer/api_add_to_cart_souvenirs.php'), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(pfParseJsonResponse)
        .then(data => {
            if (!data || data.success !== true) {
                alert((data && data.message) ? data.message : 'Unable to continue. Please try again.');
                buttons.forEach(btn => btn.disabled = false);
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
            alert(err && err.message ? err.message : 'An error occurred. Please try again.');
            console.error(err);
            buttons.forEach(btn => btn.disabled = false);
        });
    });
}
var souvenirFileEl = document.getElementById('design_file');
if (souvenirFileEl) {
    souvenirFileEl.addEventListener('change', function() {
        if (window.__souvenirValidationTriggered) checkSouvenirFormValid();
    });
}
var souvenirTypeOtherEl = document.getElementById('souvenir_type_other');
if (souvenirTypeOtherEl) {
    souvenirTypeOtherEl.addEventListener('input', function() {
        if (this.value.length > 50) this.value = this.value.slice(0, 50);
        if (window.__souvenirValidationTriggered) checkSouvenirFormValid();
    });
}
</script>

<style>
.dim-label { font-size: 0.70rem; color: #94a3b8; font-weight: 600; margin-bottom: 4px; display: block; text-transform: uppercase; }
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
</style>
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
