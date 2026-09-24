<?php
/**
 * Customer product group picker — selects a real product_id for cart/order.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/customer_catalog_perf.php';
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

$memberRows = [];
foreach ($members as $m) {
    $memberRows[] = [
        'product_id' => (int) ($m['product_id'] ?? 0),
        'name' => (string) ($m['name'] ?? ''),
    ];
}

$groupStats = printflow_catalog_products_aggregate_stats($memberRows);
$avg_rating = (float) ($groupStats['avg_rating'] ?? 0);
$review_count = (int) ($groupStats['review_count'] ?? 0);
$sold_count = (int) ($groupStats['sold_count'] ?? 0);
$sold_display = $sold_count >= 1000 ? number_format($sold_count / 1000, 1) . 'k' : (string) $sold_count;

$groupReviews = printflow_catalog_products_reviews_list($memberRows);

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
    :root {
        --shopee-text: #173042;
        --shopee-muted: #688092;
        --shopee-border: rgba(126, 164, 184, 0.24);
    }
    .pf-group-page { max-width: 1100px; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
    .pf-group-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 1.25rem; align-items: start; }
    @media (max-width: 900px) { .pf-group-layout { grid-template-columns: 1fr; } }
    .pf-group-hero-img {
        width: 100%; height: 210px; max-height: 220px; object-fit: contain; background: #f1f5f9;
        border-radius: 0; border: none;
    }
    .pf-group-img-wrap {
        width: 100%; height: 210px; max-height: 220px; overflow: hidden; background: #f1f5f9;
        display: flex; align-items: center; justify-content: center;
        border: 1px solid var(--shopee-border); border-radius: 16px 16px 0 0;
    }
    .pf-group-detail-card {
        border: 1px solid var(--shopee-border); border-radius: 16px; background: rgba(255,255,255,0.78);
        box-shadow: 0 22px 50px rgba(13, 45, 60, 0.12); overflow: hidden;
    }
    .pf-group-detail-body { padding: 12px 14px 14px; }
    .pf-group-stats {
        display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px;
        font-size: 0.75rem; color: var(--shopee-muted); margin: 8px 0 10px; padding-bottom: 10px;
        border-bottom: 1px solid rgba(126,164,184,0.16);
    }
    .pf-group-stats .rating-stars { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; }
    .pf-group-stats .rating-text { margin-left: 4px; font-weight: 600; }
    .pf-group-option {
        display: flex; gap: 8px; align-items: center; padding: 6px 8px; border-radius: 10px;
        border: 1px solid var(--shopee-border); cursor: pointer; background: rgba(255,255,255,0.78);
        transition: border-color .2s, box-shadow .2s; min-height: 0;
    }
    .pf-group-option.is-active { border-color: rgba(15,52,65,0.45); box-shadow: 0 6px 18px rgba(13,45,60,0.1); }
    .pf-group-option img { width: 44px; height: 44px; object-fit: contain; border-radius: 8px; background: #f8fafc; flex-shrink: 0; }
    .pf-group-options { display: flex; flex-direction: column; gap: 6px; max-height: 280px; overflow-y: auto; }
    .pf-group-back { color: #0f3441; font-weight: 600; text-decoration: none; font-size: 0.875rem; }
    .shopee-footer { padding: 5px 0 0; border-top: 1px solid rgba(126, 164, 184, 0.16); display: flex; gap: 4px; margin-top: 12px; }
    .shopee-btn {
        flex: 1; padding: 0.5rem 0.54rem; border-radius: 12px; font-size: 0.6rem; font-weight: 700;
        text-align: center; text-transform: uppercase; border: 1px solid transparent; cursor: pointer;
        display: flex; align-items: center; justify-content: center; text-decoration: none;
        letter-spacing: 0.05em; white-space: nowrap; line-height: 1;
    }
    .shopee-btn-cart { background: rgba(255,255,255,0.85); color: #0f3441; border-color: var(--shopee-border); }
    .shopee-btn-buy { background: linear-gradient(135deg, #123746 0%, #0f4958 100%); color: #fff; flex: 1.2; }
    .shopee-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .pf-group-reviews { margin-top: 1.5rem; padding: 1.25rem 1.5rem; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; }
    .pf-group-reviews h2 { font-size: 1.125rem; font-weight: 700; color: #111827; margin: 0 0 1rem; }
    .pf-review-item { padding: 1rem 0; border-bottom: 1px solid #f3f4f6; }
    .pf-review-item:last-child { border-bottom: none; }
    .pf-review-product { font-size: 0.6875rem; font-weight: 700; color: #477089; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 4px; }
</style>

<div class="pf-group-page">
    <a class="pf-group-back" href="products.php">&larr; Back to products</a>
    <h1 class="text-2xl font-bold text-gray-800" style="margin:1rem 0 0.25rem;"><?php echo htmlspecialchars($group['name']); ?></h1>
    <p style="color:#64748b;font-size:0.875rem;margin:0 0 1.25rem;">Choose an option, then order or add to cart.</p>

    <div class="pf-group-layout">
        <div class="pf-group-detail-card">
            <div class="pf-group-img-wrap">
                <img id="pf-group-main-image" class="pf-group-hero-img" src="<?php echo htmlspecialchars($cover); ?>" alt="">
            </div>
            <div class="pf-group-detail-body">
                <div style="font-size:0.58rem;font-weight:700;color:#477089;text-transform:uppercase;letter-spacing:.08em;">Selected option</div>
                <div id="pf-group-selected-name" style="font-size:0.95rem;font-weight:700;color:var(--shopee-text);margin-top:4px;">—</div>

                <div class="pf-group-stats">
                    <div class="rating-stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <svg style="width: 14px; height: 14px;" fill="<?php echo ($i <= round($avg_rating)) ? '#ffca11' : '#e5e7eb'; ?>" viewBox="0 0 20 20">
                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                            </svg>
                        <?php endfor; ?>
                        <?php if ($review_count > 0): ?>
                            <span class="rating-text"><?php echo number_format($avg_rating, 1); ?> (<?php echo (int) $review_count; ?> reviews)</span>
                        <?php endif; ?>
                    </div>
                    <span><?php echo htmlspecialchars($sold_display); ?> sold</span>
                </div>

                <div id="pf-group-selected-price" style="font-size:1.125rem;font-weight:800;color:#0f3441;">—</div>
                <div id="pf-group-selected-stock" style="font-size:0.75rem;color:#64748b;margin-top:4px;font-weight:600;">—</div>

                <div class="shopee-footer">
                    <button type="button" id="pf-group-add-cart" class="shopee-btn shopee-btn-cart" title="Add to Cart">
                        <svg style="width: 1.25rem; height: 1.25rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    </button>
                    <a id="pf-group-order-now" href="#" class="shopee-btn shopee-btn-buy">Order Now</a>
                </div>
            </div>
        </div>
        <div>
            <div style="font-size:0.875rem;font-weight:700;color:#173042;margin-bottom:8px;">Available options</div>
            <div class="pf-group-options" id="pf-group-options">
                <?php foreach ($options as $opt): ?>
                    <div class="pf-group-option<?php echo (int)$opt['product_id'] === $selectedId ? ' is-active' : ''; ?>"
                         data-product-id="<?php echo (int)$opt['product_id']; ?>"
                         data-name="<?php echo htmlspecialchars($opt['name'], ENT_QUOTES); ?>"
                         data-price="<?php echo htmlspecialchars(number_format($opt['price'], 2, '.', ''), ENT_QUOTES); ?>"
                         data-stock="<?php echo (int)$opt['stock_quantity']; ?>"
                         data-image="<?php echo htmlspecialchars($opt['image_url'], ENT_QUOTES); ?>">
                        <img src="<?php echo htmlspecialchars($opt['image_url']); ?>" alt="">
                        <div style="min-width:0;flex:1;">
                            <div style="font-weight:700;font-size:0.8125rem;color:#173042;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($opt['name']); ?></div>
                            <div style="font-size:0.72rem;color:#64748b;margin-top:2px;"><?php echo format_currency($opt['price']); ?> · <?php echo (int)$opt['stock_quantity']; ?> in stock</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <section class="pf-group-reviews" aria-labelledby="pf-group-reviews-heading">
        <h2 id="pf-group-reviews-heading">Product ratings</h2>
        <?php if ($review_count > 0): ?>
            <p style="font-size:0.875rem;color:#6b7280;margin:-0.5rem 0 1rem;">
                <?php echo number_format($avg_rating, 1); ?> out of 5 · <?php echo (int) $review_count; ?> review<?php echo $review_count === 1 ? '' : 's'; ?> · <?php echo htmlspecialchars($sold_display); ?> sold
            </p>
        <?php else: ?>
            <p style="font-size:0.875rem;color:#6b7280;margin:-0.5rem 0 1rem;">No reviews yet for products in this group.</p>
        <?php endif; ?>
        <?php foreach ($groupReviews as $review):
            $reviewer = trim((string) (($review['first_name'] ?? '') . ' ' . ($review['last_name'] ?? '')));
            $rating = (int) ($review['rating'] ?? 0);
            $comment = trim((string) ($review['comment'] ?? ''));
            ?>
            <article class="pf-review-item">
                <div class="pf-review-product"><?php echo htmlspecialchars($review['product_name'] ?? ''); ?></div>
                <div style="display:flex;align-items:center;gap:4px;margin-bottom:4px;">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <svg width="14" height="14" fill="<?php echo $i <= $rating ? '#ffca11' : '#e5e7eb'; ?>" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    <?php endfor; ?>
                    <?php if ($reviewer !== ''): ?>
                        <span style="font-size:0.8125rem;color:#374151;margin-left:6px;"><?php echo htmlspecialchars($reviewer); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ($comment !== ''): ?>
                    <p style="margin:0;font-size:0.875rem;color:#4b5563;line-height:1.5;"><?php echo nl2br(htmlspecialchars($comment)); ?></p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
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
    var cartBtn = document.getElementById('pf-group-add-cart');
    cartBtn.disabled = stock <= 0;
    cartBtn.setAttribute('data-product-id', pid);
}

document.getElementById('pf-group-options').addEventListener('click', function (e) {
    var row = e.target.closest('.pf-group-option');
    if (row) pfSelectGroupOption(row);
});

function showToast(msg, isError) {
    var toast = document.getElementById('shopee-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'shopee-toast';
        document.body.appendChild(toast);
    }
    toast.textContent = msg;
    toast.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);padding:12px 20px;border-radius:8px;font-size:14px;font-weight:600;z-index:9999;color:#fff;background:' + (isError ? '#dc2626' : '#0f3441') + ';';
    toast.style.display = 'block';
    setTimeout(function () { toast.style.display = 'none'; }, 2800);
}

document.getElementById('pf-group-add-cart').addEventListener('click', async function (ev) {
    var btn = ev.currentTarget;
    if (btn.disabled) return;
    var productId = parseInt(btn.getAttribute('data-product-id') || '0', 10);
    if (!productId) return;
    var lockKey = 'product-' + String(productId);
    if (window.PFAddToCartFx && PFAddToCartFx.isPending(lockKey)) return;

    var runAdd = async function () {
        var response = await fetch('api_cart.php', {
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
        var data = await response.json();
        if (!data.success) {
            showToast(data.message || 'Could not add to cart.', true);
            return;
        }
        var applyCartCount = function () {
            if (window.updateCartBadge) updateCartBadge(data.cart_count);
        };
        if (window.PFAddToCartFx) {
            await new Promise(function (resolve) {
                PFAddToCartFx.run(btn, {
                    message: 'Added to cart ✓',
                    onComplete: function () { applyCartCount(); resolve(true); }
                });
            });
        } else {
            applyCartCount();
            showToast('Added to cart!');
        }
    };

    try {
        if (window.PFAddToCartFx) {
            await PFAddToCartFx.withLock(lockKey, btn, runAdd);
        } else {
            await runAdd();
        }
    } catch (err) {
        showToast('Network error. Please try again.', true);
    }
});

(function () {
    var initial = document.querySelector('.pf-group-option.is-active') || document.querySelector('.pf-group-option');
    pfSelectGroupOption(initial);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
