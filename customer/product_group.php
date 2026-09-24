<?php
/**
 * Customer product group picker — selects a real product_id for cart/order.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/product_catalog_groups.php';

require_role('Customer');

printflow_ensure_product_catalog_groups_schema();

$groupId = (int) ($_GET['group_id'] ?? 0);
$group = printflow_catalog_group_get($groupId, true);
if (!$group) {
    redirect(pf_app_base_path() . '/customer/products.php');
}

$members = printflow_catalog_group_members($groupId, true);
if (empty($members)) {
    redirect(pf_app_base_path() . '/customer/products.php');
}

$base_path = pf_app_base_path();
$default_product_img = $base_path . '/public/assets/images/services/default.png';
$cover = printflow_catalog_group_cover_url($group, $base_path, $default_product_img);
if ($cover === $default_product_img) {
    $cover = printflow_catalog_group_fallback_cover_from_members($groupId, $base_path, $default_product_img);
}

$options = [];
foreach ($members as $m) {
    $pid = (int) ($m['product_id'] ?? 0);
    $rawImg = trim((string) ($m['photo_path'] ?? $m['product_image'] ?? ''));
    $img = $rawImg !== '' ? pf_normalize_service_image_path($rawImg, $base_path, $default_product_img) : $default_product_img;
    $options[] = [
        'product_id' => $pid,
        'name' => (string) ($m['name'] ?? ''),
        'price' => (float) ($m['price'] ?? 0),
        'stock_quantity' => (int) ($m['stock_quantity'] ?? 0),
        'category' => (string) ($m['category'] ?? ''),
        'image_url' => $img,
    ];
}

$selectedId = (int) ($_GET['product_id'] ?? ($options[0]['product_id'] ?? 0));
if (!printflow_catalog_validate_member_in_group($groupId, $selectedId)) {
    $selectedId = (int) ($options[0]['product_id'] ?? 0);
}

$page_title = htmlspecialchars($group['name']) . ' - Products';
$use_customer_css = true;
$pf_catalog_nav_page = true;
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .pf-group-page { max-width: 1100px; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
    .pf-group-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.1fr); gap: 1.25rem; }
    @media (max-width: 900px) { .pf-group-layout { grid-template-columns: 1fr; } }
    .pf-group-hero-img {
        width: 100%; aspect-ratio: 4/3; object-fit: contain; background: #f1f5f9;
        border-radius: 16px; border: 1px solid rgba(126,164,184,0.24);
    }
    .pf-group-option {
        display: flex; gap: 10px; align-items: center; padding: 10px; border-radius: 12px;
        border: 1px solid rgba(126,164,184,0.24); cursor: pointer; background: rgba(255,255,255,0.78);
        transition: border-color .2s, box-shadow .2s;
    }
    .pf-group-option.is-active { border-color: rgba(15,52,65,0.45); box-shadow: 0 8px 24px rgba(13,45,60,0.12); }
    .pf-group-option img { width: 56px; height: 56px; object-fit: contain; border-radius: 8px; background: #f8fafc; flex-shrink: 0; }
    .pf-group-options { display: flex; flex-direction: column; gap: 8px; max-height: 360px; overflow-y: auto; }
    .pf-group-back { color: #0f3441; font-weight: 600; text-decoration: none; font-size: 0.875rem; }
    .shopee-btn { display: inline-flex; align-items: center; justify-content: center; padding: 0.55rem 1rem; border-radius: 10px; font-size: 0.8125rem; font-weight: 700; border: 1px solid transparent; cursor: pointer; text-decoration: none; }
    .shopee-btn-cart { background: rgba(255,255,255,0.85); color: #0f3441; border-color: rgba(126,164,184,0.24); }
    .shopee-btn-buy { background: linear-gradient(135deg, #123746 0%, #0f4958 100%); color: #fff; }
</style>

<div class="pf-group-page">
    <a class="pf-group-back" href="products.php">&larr; Back to products</a>
    <h1 class="text-2xl font-bold text-gray-800" style="margin:1rem 0 0.25rem;"><?php echo htmlspecialchars($group['name']); ?></h1>
    <p style="color:#64748b;font-size:0.875rem;margin:0 0 1.25rem;">Choose an option, then order or add to cart.</p>

    <div class="pf-group-layout">
        <div>
            <img id="pf-group-main-image" class="pf-group-hero-img" src="<?php echo htmlspecialchars($cover); ?>" alt="">
            <div style="margin-top:1rem;">
                <div style="font-size:0.75rem;font-weight:700;color:#0f3441;text-transform:uppercase;letter-spacing:.06em;">Selected</div>
                <div id="pf-group-selected-name" style="font-size:1.125rem;font-weight:700;color:#173042;margin-top:4px;">—</div>
                <div id="pf-group-selected-price" style="font-size:1.5rem;font-weight:800;margin-top:6px;">—</div>
                <div id="pf-group-selected-stock" style="font-size:0.8125rem;color:#64748b;margin-top:4px;">—</div>
            </div>
            <div style="display:flex;gap:10px;margin-top:1rem;flex-wrap:wrap;">
                <button type="button" id="pf-group-add-cart" class="shopee-btn shopee-btn-cart" style="flex:1;min-width:120px;">Add to cart</button>
                <a id="pf-group-order-now" href="#" class="shopee-btn shopee-btn-buy" style="flex:1;text-align:center;min-width:120px;">Order Now</a>
            </div>
        </div>
        <div>
            <div style="font-size:0.875rem;font-weight:700;color:#173042;margin-bottom:10px;">Available options</div>
            <div class="pf-group-options" id="pf-group-options">
                <?php foreach ($options as $opt): ?>
                    <div class="pf-group-option<?php echo (int)$opt['product_id'] === $selectedId ? ' is-active' : ''; ?>"
                         data-product-id="<?php echo (int)$opt['product_id']; ?>"
                         data-name="<?php echo htmlspecialchars($opt['name'], ENT_QUOTES); ?>"
                         data-price="<?php echo htmlspecialchars(number_format($opt['price'], 2, '.', ''), ENT_QUOTES); ?>"
                         data-stock="<?php echo (int)$opt['stock_quantity']; ?>"
                         data-image="<?php echo htmlspecialchars($opt['image_url'], ENT_QUOTES); ?>">
                        <img src="<?php echo htmlspecialchars($opt['image_url']); ?>" alt="">
                        <div style="min-width:0;">
                            <div style="font-weight:700;font-size:0.875rem;color:#173042;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($opt['name']); ?></div>
                            <div style="font-size:0.8125rem;color:#64748b;"><?php echo format_currency($opt['price']); ?> · <?php echo (int)$opt['stock_quantity']; ?> in stock</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars($base_path); ?>/public/assets/js/add_to_cart_fx.js"></script>
<script>
var PF_CSRF_TOKEN = '<?php echo generate_csrf_token(); ?>';
var PF_GROUP_ID = <?php echo (int)$groupId; ?>;

function pfFormatMoney(n) {
    var v = parseFloat(n);
    if (isNaN(v)) v = 0;
    return '₱' + v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function pfSelectGroupOption(el) {
    if (!el) return;
    document.querySelectorAll('.pf-group-option').forEach(function (row) { row.classList.remove('is-active'); });
    el.classList.add('is-active');
    var pid = el.getAttribute('data-product-id');
    var name = el.getAttribute('data-name') || '';
    var price = el.getAttribute('data-price') || '0';
    var stock = parseInt(el.getAttribute('data-stock') || '0', 10);
    var img = el.getAttribute('data-image') || '';
    document.getElementById('pf-group-main-image').src = img;
    document.getElementById('pf-group-selected-name').textContent = name;
    document.getElementById('pf-group-selected-price').textContent = pfFormatMoney(price);
    document.getElementById('pf-group-selected-stock').textContent = stock > 0 ? (stock + ' in stock') : 'Out of stock';
    document.getElementById('pf-group-order-now').href = 'order_create.php?product_id=' + encodeURIComponent(pid) + '&buy_now=1';
    document.getElementById('pf-group-add-cart').disabled = stock <= 0;
}

document.getElementById('pf-group-options').addEventListener('click', function (e) {
    var row = e.target.closest('.pf-group-option');
    if (row) pfSelectGroupOption(row);
});

document.getElementById('pf-group-add-cart').addEventListener('click', async function () {
    var active = document.querySelector('.pf-group-option.is-active');
    if (!active) return;
    var productId = parseInt(active.getAttribute('data-product-id') || '0', 10);
    if (!productId) return;
    try {
        const response = await fetch('api_cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'add',
                product_id: productId,
                quantity: 1,
                csrf_token: PF_CSRF_TOKEN,
                catalog_group_id: PF_GROUP_ID
            })
        });
        const data = await response.json();
        if (!data.success) {
            alert(data.message || 'Could not add to cart.');
            return;
        }
        if (window.updateCartBadge) updateCartBadge(data.cart_count);
        alert('Added to cart.');
    } catch (err) {
        alert('Network error. Please try again.');
    }
});

(function () {
    var initial = document.querySelector('.pf-group-option.is-active') || document.querySelector('.pf-group-option');
    pfSelectGroupOption(initial);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
