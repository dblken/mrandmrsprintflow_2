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

$groupReviews = printflow_catalog_products_reviews_list($memberRows);
$productStatsMap = printflow_catalog_product_card_stats_map($memberRows);

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
    .pf-group-layout { display: grid; grid-template-columns: minmax(0, 1fr) max-content; gap: 1.25rem; align-items: start; }
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
    .pf-group-stats .rating-text { margin-left: 4px; font-weight: 600; font-size: 0.75rem; color: var(--shopee-muted); }
    .pf-group-option {
        display: inline-flex; gap: 6px; align-items: center; padding: 4px 8px 4px 4px; border-radius: 8px;
        border: 1px solid var(--shopee-border); cursor: pointer; background: rgba(255,255,255,0.78);
        transition: border-color .2s, box-shadow .2s; min-height: 0; width: auto; max-width: 100%;
    }
    .pf-group-option.is-active { border-color: rgba(15,52,65,0.45); box-shadow: 0 4px 12px rgba(13,45,60,0.08); }
    .pf-group-option img { width: 40px; height: 40px; object-fit: contain; border-radius: 6px; background: #f8fafc; flex-shrink: 0; }
    .pf-group-option-text { flex: 0 1 auto; line-height: 1.25; white-space: nowrap; }
    .pf-group-options-col { max-width: 100%; }
    .pf-group-options { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; max-height: 260px; overflow-y: auto; width: max-content; max-width: min(100%, 360px); }
    @media (max-width: 900px) {
        .pf-group-options { width: 100%; max-width: 100%; }
        .pf-group-option { width: 100%; display: flex; }
        .pf-group-option-text { white-space: normal; }
    }
    .pf-group-back { color: #0f3441; font-weight: 600; text-decoration: none; font-size: 0.875rem; }
    .shopee-footer {
        padding: 8px 0 0; border-top: 1px solid rgba(126, 164, 184, 0.16);
        display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 8px;
        margin-top: 12px; width: 100%;
    }
    .shopee-btn {
        padding: 0.5rem 0.75rem; border-radius: 12px; font-size: 0.6rem; font-weight: 700;
        text-align: center; text-transform: uppercase; border: 1px solid transparent; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center; text-decoration: none;
        letter-spacing: 0.05em; white-space: nowrap; line-height: 1; flex: 0 0 auto;
    }
    .shopee-btn-cart { background: rgba(255,255,255,0.85); color: #0f3441; border-color: var(--shopee-border); width: 42px; height: 42px; padding: 0; }
    .shopee-btn-buy { background: linear-gradient(135deg, #123746 0%, #0f4958 100%); color: #fff; min-width: 118px; height: 42px; }
    .shopee-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    @media (max-width: 480px) {
        .shopee-footer { display: flex; }
        .shopee-btn-buy { flex: 1; min-width: 0; }
    }
    .pf-group-reviews { margin-top: 1.5rem; padding: 1.25rem 1.5rem; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; }
    .pf-group-reviews h2 { font-size: 1.125rem; font-weight: 700; color: #111827; margin: 0 0 1rem; }
    .pf-review-item { padding: 0.75rem 0; border-bottom: 1px solid #f3f4f6; }
    .pf-review-item:last-child { border-bottom: none; }
    .pf-review-product { font-size: 0.6875rem; font-weight: 700; color: #477089; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 4px; }
    .pf-staff-reply { margin-top: 0.75rem; padding: 0.75rem; background: #f9fafb; border-left: 3px solid #e5e7eb; border-radius: 6px; }
    .pf-staff-reply-label { font-size: 0.75rem; font-weight: 700; color: #374151; text-transform: uppercase; margin-bottom: 0.35rem; letter-spacing: 0.05em; }
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
                    <div class="rating-stars" id="pf-group-stats-stars"></div>
                    <span id="pf-group-stats-sold">— sold</span>
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
        <div class="pf-group-options-col">
            <div style="font-size:0.875rem;font-weight:700;color:#173042;margin-bottom:8px;">Available options</div>
            <div class="pf-group-options" id="pf-group-options">
                <?php foreach ($options as $opt):
                    $pid = (int) $opt['product_id'];
                    $ps = $productStatsMap[$pid] ?? ['avg_rating' => 0.0, 'review_count' => 0, 'sold_count' => 0];
                    ?>
                    <div class="pf-group-option<?php echo $pid === $selectedId ? ' is-active' : ''; ?>"
                         data-product-id="<?php echo $pid; ?>"
                         data-name="<?php echo htmlspecialchars($opt['name'], ENT_QUOTES); ?>"
                         data-price="<?php echo htmlspecialchars(number_format($opt['price'], 2, '.', ''), ENT_QUOTES); ?>"
                         data-stock="<?php echo (int)$opt['stock_quantity']; ?>"
                         data-image="<?php echo htmlspecialchars($opt['image_url'], ENT_QUOTES); ?>"
                         data-avg-rating="<?php echo htmlspecialchars(number_format((float) $ps['avg_rating'], 2, '.', ''), ENT_QUOTES); ?>"
                         data-review-count="<?php echo (int) ($ps['review_count'] ?? 0); ?>"
                         data-sold-count="<?php echo (int) ($ps['sold_count'] ?? 0); ?>">
                        <img src="<?php echo htmlspecialchars($opt['image_url']); ?>" alt="">
                        <div class="pf-group-option-text">
                            <div style="font-weight:700;font-size:0.78rem;color:#173042;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($opt['name']); ?></div>
                            <div style="font-size:0.68rem;color:#64748b;margin-top:1px;"><?php echo format_currency($opt['price']); ?> · <?php echo (int)$opt['stock_quantity']; ?> in stock</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <section class="pf-group-reviews" aria-labelledby="pf-group-reviews-heading">
        <h2 id="pf-group-reviews-heading">Product ratings</h2>
        <?php if (empty($groupReviews)): ?>
            <p style="font-size:0.875rem;color:#6b7280;margin:0;">No reviews yet for products in this group.</p>
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
                <?php if (!empty($review['replies'])): ?>
                    <div class="pf-staff-reply">
                        <div class="pf-staff-reply-label">Staff response</div>
                        <?php foreach ($review['replies'] as $reply): ?>
                            <div style="margin-bottom:0.35rem;">
                                <div style="color:#374151;font-size:0.875rem;line-height:1.5;"><?php echo nl2br(htmlspecialchars($reply['reply_message'] ?? '')); ?></div>
                                <div style="font-size:0.75rem;color:#6b7280;margin-top:0.25rem;">
                                    <?php echo htmlspecialchars(trim(($reply['first_name'] ?? '') . ' ' . ($reply['last_name'] ?? ''))); ?>
                                    <?php if (!empty($reply['created_at'])): ?>
                                        · <?php echo htmlspecialchars(date('Y-m-d', strtotime((string) $reply['created_at']))); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
</div>

<script src="<?php echo htmlspecialchars($base_path); ?>/public/assets/js/add_to_cart_fx.js"></script>
<script>
var PF_CSRF_TOKEN = '<?php echo generate_csrf_token(); ?>';
var PF_GROUP_ID = <?php echo (int)$groupId; ?>;

function pfFormatSold(count) {
    var n = parseInt(count || '0', 10);
    if (isNaN(n) || n < 0) n = 0;
    return n >= 1000 ? (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k' : String(n);
}

function pfRenderStatsStars(avgRating, reviewCount) {
    var wrap = document.getElementById('pf-group-stats-stars');
    if (!wrap) return;
    var avg = parseFloat(avgRating);
    if (isNaN(avg)) avg = 0;
    var rc = parseInt(reviewCount || '0', 10);
    var html = '';
    for (var i = 1; i <= 5; i++) {
        var fill = i <= Math.round(avg) ? '#ffca11' : '#e5e7eb';
        html += '<svg style="width:14px;height:14px;" fill="' + fill + '" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>';
    }
    if (rc > 0) {
        html += '<span class="rating-text">' + avg.toFixed(1) + ' (' + rc + ')</span>';
    }
    wrap.innerHTML = html;
}

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
    var avgRating = el.getAttribute('data-avg-rating') || '0';
    var reviewCount = el.getAttribute('data-review-count') || '0';
    var soldCount = el.getAttribute('data-sold-count') || '0';
    document.getElementById('pf-group-main-image').src = img;
    document.getElementById('pf-group-selected-name').textContent = name;
    document.getElementById('pf-group-selected-price').textContent = pfFormatMoney(price);
    document.getElementById('pf-group-selected-stock').textContent = stock > 0 ? (stock + ' in stock') : 'Out of stock';
    pfRenderStatsStars(avgRating, reviewCount);
    document.getElementById('pf-group-stats-sold').textContent = pfFormatSold(soldCount) + ' sold';
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
