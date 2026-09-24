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
$groupReviews = printflow_attach_review_media($groupReviews);

$reviewIds = [];
foreach ($groupReviews as $gr) {
    $rid = (int) ($gr['id'] ?? 0);
    if ($rid > 0) {
        $reviewIds[$rid] = $rid;
    }
}
$reviewIds = array_values($reviewIds);

if ($reviewIds !== []) {
    $reviewCols = array_flip(array_column(db_query('SHOW COLUMNS FROM reviews') ?: [], 'Field'));
    if (isset($reviewCols['video_path'])) {
        $ph = implode(',', array_fill(0, count($reviewIds), '?'));
        $videoRows = db_query(
            "SELECT id, video_path FROM reviews WHERE id IN ($ph)",
            str_repeat('i', count($reviewIds)),
            $reviewIds
        ) ?: [];
        $videoById = [];
        foreach ($videoRows as $vr) {
            $videoById[(int) ($vr['id'] ?? 0)] = (string) ($vr['video_path'] ?? '');
        }
        foreach ($groupReviews as $idx => $gr) {
            $rid = (int) ($gr['id'] ?? 0);
            if ($rid > 0 && isset($videoById[$rid])) {
                $groupReviews[$idx]['video_path'] = $videoById[$rid];
                $groupReviews[$idx]['has_video'] = $videoById[$rid] !== '';
            }
        }
    }

    $helpfulByReview = [];
    $votedSet = [];
    $helpfulTable = db_query("SHOW TABLES LIKE 'review_helpful'") ?: [];
    if ($helpfulTable !== []) {
        $ph = implode(',', array_fill(0, count($reviewIds), '?'));
        $types = str_repeat('i', count($reviewIds));
        $helpfulRows = db_query(
            "SELECT review_id, COUNT(*) AS cnt FROM review_helpful WHERE review_id IN ($ph) GROUP BY review_id",
            $types,
            $reviewIds
        ) ?: [];
        foreach ($helpfulRows as $hr) {
            $helpfulByReview[(int) ($hr['review_id'] ?? 0)] = (int) ($hr['cnt'] ?? 0);
        }

        $current_user_id = (int) (get_user_id() ?? 0);
        if ($current_user_id > 0) {
            $helpfulCols = array_flip(array_column(db_query('SHOW COLUMNS FROM review_helpful') ?: [], 'Field'));
            if (isset($helpfulCols['customer_id'])) {
                $votedRows = db_query(
                    "SELECT review_id FROM review_helpful
                     WHERE review_id IN ($ph)
                       AND customer_id = ?
                       AND COALESCE(user_type, 'Customer') = 'Customer'",
                    $types . 'i',
                    array_merge($reviewIds, [$current_user_id])
                ) ?: [];
            } else {
                $votedRows = db_query(
                    "SELECT review_id FROM review_helpful WHERE review_id IN ($ph) AND user_id = ?",
                    $types . 'i',
                    array_merge($reviewIds, [$current_user_id])
                ) ?: [];
            }
            foreach ($votedRows as $vr) {
                $votedSet[(int) ($vr['review_id'] ?? 0)] = true;
            }
        }
    }

    foreach ($groupReviews as $idx => $gr) {
        $rid = (int) ($gr['id'] ?? 0);
        $groupReviews[$idx]['helpful_count'] = (int) ($helpfulByReview[$rid] ?? 0);
        $groupReviews[$idx]['user_voted'] = !empty($votedSet[$rid]);
    }
}

$rating_counts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$with_comments = 0;
$with_media = 0;
$rating_sum = 0;
$total_reviews = count($groupReviews);
foreach ($groupReviews as $gr) {
    $rt = (int) ($gr['rating'] ?? 0);
    if ($rt >= 1 && $rt <= 5) {
        $rating_counts[$rt]++;
        $rating_sum += $rt;
    }
    if (trim((string) ($gr['comment'] ?? '')) !== '') {
        $with_comments++;
    }
    $hasVid = !empty($gr['video_path']);
    $hasImgs = !empty($gr['images']);
    if ($hasVid || $hasImgs) {
        $with_media++;
    }
}
$avg_rating = $total_reviews > 0 ? $rating_sum / $total_reviews : 0.0;

$base_path = pf_app_base_path();

$reviews_per_page = 10;
$poc_page = max(1, (int) ($_GET['rpage'] ?? 1));
$poc_total_pages = $total_reviews > 0 ? (int) ceil($total_reviews / $reviews_per_page) : 1;
$poc_page = min($poc_page, $poc_total_pages);
$poc_offset = ($poc_page - 1) * $reviews_per_page;
$reviews_paged = array_slice($groupReviews, $poc_offset, $reviews_per_page);

$profile_pic_fallback = rtrim($base_path, '/') . '/public/assets/uploads/profiles/default.png';

$productStatsMap = printflow_catalog_product_card_stats_map($memberRows);
$groupStats = printflow_catalog_products_aggregate_stats($memberRows);

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
        --pf-group-content-max: 880px;
    }
    .pf-group-page {
        max-width: var(--pf-group-content-max);
        width: 100%;
        margin: 0 auto;
        padding: 1.5rem 1.25rem 3rem;
        box-sizing: border-box;
    }
    .pf-group-selection {
        border: 1px solid var(--shopee-border);
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.88);
        box-shadow: 0 22px 50px rgba(13, 45, 60, 0.1);
        overflow: hidden;
        height: fit-content;
        width: 100%;
    }
    .pf-group-selection-inner {
        display: grid;
        grid-template-columns: minmax(0, 0.44fr) minmax(0, 0.56fr);
        align-items: stretch;
    }
    @media (max-width: 900px) {
        .pf-group-selection-inner {
            grid-template-columns: 1fr;
            grid-template-rows: auto auto;
        }
        .pf-group-selection-options {
            border-left: none;
            border-top: 1px solid rgba(126, 164, 184, 0.18);
            height: auto;
            min-height: 0;
        }
        .pf-group-selection-main {
            height: auto;
            min-height: 0;
        }
        .pf-group-selection .pf-group-options-actions {
            margin-top: 12px;
        }
    }
    .pf-group-selection-main {
        min-width: 0;
        display: flex;
        flex-direction: column;
        align-items: stretch;
        height: 100%;
        min-height: 100%;
    }
    .pf-group-selection-options {
        min-width: 0;
        display: flex;
        flex-direction: column;
        padding: 14px 16px 14px;
        border-left: 1px solid rgba(126, 164, 184, 0.18);
        background: rgba(248, 250, 252, 0.55);
        height: 100%;
        min-height: 100%;
        box-sizing: border-box;
        gap: 0;
    }
    .pf-group-options-heading {
        font-size: 0.58rem;
        font-weight: 700;
        color: #477089;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        margin: 0 0 10px;
    }
    .pf-group-hero-img {
        display: block;
        max-width: 100%;
        max-height: 100%;
        width: auto;
        height: auto;
        object-fit: contain;
        background: transparent;
        border: none;
    }
    .pf-group-img-wrap {
        align-self: center;
        width: min(100%, 240px);
        max-width: 240px;
        aspect-ratio: 3 / 4;
        max-height: min(360px, 52vh);
        min-height: 0;
        overflow: hidden;
        background: #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: center;
        border: none;
        border-bottom: 1px solid rgba(126, 164, 184, 0.14);
        box-sizing: border-box;
        padding: 8px;
        margin: 0 auto;
    }
    .pf-group-detail-body {
        padding: 12px 16px 14px; flex: 0 0 auto; display: flex; flex-direction: column; min-width: 0; gap: 0;
    }
    .pf-group-selected-label {
        font-size: 0.58rem; font-weight: 700; color: #477089; text-transform: uppercase; letter-spacing: 0.08em; line-height: 1.2;
    }
    .pf-group-selected-name {
        font-size: 0.95rem; font-weight: 700; color: var(--shopee-text); margin-top: 4px;
        overflow-wrap: anywhere; word-break: break-word; line-height: 1.3;
    }
    .pf-group-selected-price { font-size: 1.125rem; font-weight: 800; color: #0f3441; margin-top: 4px; line-height: 1.2; }
    .pf-group-selected-stock { font-size: 0.75rem; color: #64748b; margin-top: 4px; font-weight: 600; line-height: 1.2; }
    .pf-group-stats {
        display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px;
        font-size: 0.75rem; color: var(--shopee-muted); margin: 8px 0 10px; padding-bottom: 10px;
        border-bottom: 1px solid rgba(126,164,184,0.16);
    }
    .pf-group-stats .rating-stars { display: flex; align-items: center; gap: 2px; flex-wrap: wrap; }
    .pf-group-stats .rating-stars svg { width: 14px; height: 14px; }
    .pf-group-stats .rating-stars svg.pf-star-on { fill: #ffca11 !important; }
    .pf-group-stats .rating-stars svg.pf-star-off { fill: #e5e7eb !important; }
    .pf-group-stats .rating-text { margin-left: 4px; font-weight: 600; font-size: 0.75rem; color: var(--shopee-muted); }
    .pf-group-option {
        display: flex; gap: 8px; align-items: center; padding: 8px 10px 8px 8px; border-radius: 10px;
        border: 1px solid var(--shopee-border); cursor: pointer; background: rgba(255, 255, 255, 0.92);
        transition: border-color 0.2s, box-shadow 0.2s, background 0.2s; min-height: 0;
        width: 100%; max-width: 100%; box-sizing: border-box;
    }
    .pf-group-option:hover { border-color: rgba(15, 52, 65, 0.28); background: #fff; }
    .pf-group-option.is-active {
        border-color: rgba(15, 52, 65, 0.5);
        background: rgba(255, 255, 255, 1);
        box-shadow: 0 0 0 1px rgba(15, 52, 65, 0.12), 0 4px 14px rgba(13, 45, 60, 0.08);
    }
    .pf-group-option img { width: 44px; height: 44px; object-fit: contain; border-radius: 8px; background: #f8fafc; flex-shrink: 0; border: 1px solid rgba(126, 164, 184, 0.12); }
    .pf-group-option-text { flex: 1; min-width: 0; line-height: 1.3; }
    .pf-group-option-name { font-weight: 700; font-size: 0.78rem; color: #173042; overflow-wrap: anywhere; word-break: break-word; }
    .pf-group-option-meta { font-size: 0.68rem; color: #64748b; margin-top: 2px; }
    .pf-group-options {
        display: flex;
        flex-direction: column;
        gap: 6px;
        width: 100%;
        min-width: 0;
        flex: 0 0 auto;
        height: auto;
        max-height: none;
        overflow-y: visible;
    }
    .pf-group-options:has(.pf-group-option:nth-child(5)) {
        max-height: min(280px, 45vh);
        overflow-y: auto;
    }
    .pf-group-back { color: #0f3441; font-weight: 600; text-decoration: none; font-size: 0.875rem; }
    .pf-group-selection .shopee-footer {
        padding: 10px 0 0;
        border-top: 1px solid rgba(126, 164, 184, 0.16);
        display: flex;
        flex-wrap: nowrap;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        width: 100%;
        flex: 0 0 auto;
        box-sizing: border-box;
    }
    .pf-group-selection .pf-group-options-actions {
        margin-top: auto;
        padding-top: 12px;
    }
    .pf-group-selection .shopee-btn {
        padding: 0.5rem 0.75rem; border-radius: 12px; font-size: 0.6rem; font-weight: 700;
        text-align: center; text-transform: uppercase; border: 1px solid transparent; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center; text-decoration: none;
        letter-spacing: 0.05em; white-space: nowrap; line-height: 1; flex: 0 0 auto;
    }
    .pf-group-selection .shopee-btn-cart {
        background: rgba(255,255,255,0.85); color: #0f3441; border-color: var(--shopee-border);
        width: 42px; height: 42px; padding: 0;
    }
    .pf-group-selection .shopee-btn-cart svg { width: 1.25rem; height: 1.25rem; }
    .pf-group-selection .shopee-btn-buy {
        background: linear-gradient(135deg, #123746 0%, #0f4958 100%); color: #fff;
        min-width: 118px; height: 42px;
    }
    .pf-group-selection .shopee-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    @media (max-width: 480px) {
        .pf-group-selection .shopee-footer { display: flex; }
        .pf-group-selection .shopee-btn-buy { flex: 1; min-width: 0; }
        .pf-group-selection .shopee-btn-cart { width: 42px; height: 42px; }
        .pf-group-selection .shopee-btn-buy { height: 42px; min-height: 42px; }
        .pf-group-img-wrap {
            width: min(100%, 220px);
            max-width: 220px;
            max-height: min(320px, 48vh);
        }
    }
    .pf-group-reviews {
        margin-top: 1.25rem;
        padding: 1.5rem 2rem;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 4px;
        width: 100%;
        box-sizing: border-box;
    }
    .poc-section-title { font-size: 1.1rem; font-weight: 700; color: #111827; margin: 0 0 0.75rem; }
    .poc-filter-btn.active { background: #0a2530 !important; color: white !important; border-color: #0a2530 !important; }
    .poc-filter-btn:hover { border-color: #0a2530; background: #f0f4f5; }
    .poc-review-item { border-bottom: 1px solid #f3f4f6; padding: 1.25rem 0; color: #1f2937; }
    .poc-review-item:last-child { border-bottom: none; }
    .poc-empty { text-align: center; padding: 3rem 1rem; color: #6b7280; }
    .helpful-btn { display: inline-flex; align-items: center; gap: 5px; padding: 4px 0; border: none; background: transparent; color: #9ca3af; font-size: 0.82rem; font-weight: 400; cursor: pointer; transition: color 0.2s; }
    .helpful-btn:hover { color: #6b7280; }
    .helpful-btn.voted { color: #f97316; }
    .helpful-btn.voted svg { fill: #f97316; }
    .poc-media-trigger { border: none; background: none; padding: 0; cursor: pointer; }
    .poc-media-thumb { display: block; max-width: 100%; height: auto; }
    .poc-video-thumb { position: relative; display: inline-block; max-width: 240px; border-radius: 8px; border: 1px solid #e5e7eb; overflow: hidden; background: #0f172a; }
    .poc-video-preview { display: block; width: 100%; height: auto; background: #0f172a; }
    .poc-media-modal { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.85); display: none; align-items: center; justify-content: center; padding: 1.5rem; z-index: 100000; }
    .poc-media-modal.is-open { display: flex; }
    .poc-media-modal-inner { position: relative; max-width: 90vw; max-height: 90vh; }
    .poc-media-full { max-width: 90vw; max-height: 90vh; border-radius: 8px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5); background: #0b1220; }
    .poc-media-close { position: absolute; top: -12px; right: -12px; width: 36px; height: 36px; border-radius: 999px; border: none; background: #111827; color: #fff; font-size: 1.5rem; line-height: 1; cursor: pointer; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35); }
    .pf-review-product-label { font-size: 0.75rem; font-weight: 600; color: #477089; margin-bottom: 0.35rem; overflow-wrap: anywhere; word-break: break-word; }
    @media (max-width: 640px) {
        .pf-group-reviews { padding: 1.25rem 1rem; }
    }
</style>

<div class="pf-group-page">
    <a class="pf-group-back" href="products.php">&larr; Back to products</a>
    <h1 class="text-2xl font-bold text-gray-800" style="margin:1rem 0 0.25rem;"><?php echo htmlspecialchars($group['name']); ?></h1>
    <p style="color:#64748b;font-size:0.875rem;margin:0 0 1.25rem;">Choose an option, then order or add to cart.</p>

    <div class="pf-group-selection" role="region" aria-label="Product selection">
        <div class="pf-group-selection-inner">
            <div class="pf-group-selection-main">
                <div class="pf-group-img-wrap">
                    <img id="pf-group-main-image" class="pf-group-hero-img" src="<?php echo htmlspecialchars($cover); ?>" alt="">
                </div>
                <div class="pf-group-detail-body">
                    <div class="pf-group-selected-label">Selected option</div>
                    <div id="pf-group-selected-name" class="pf-group-selected-name">—</div>

                    <div class="pf-group-stats">
                        <div class="rating-stars" id="pf-group-stats-stars">
                            <?php
                            $selPs = $productStatsMap[$selectedId] ?? ['avg_rating' => 0.0, 'review_count' => 0, 'sold_count' => 0];
                            $starAvg = (float) ($selPs['avg_rating'] ?? 0);
                            $starRc = (int) ($selPs['review_count'] ?? 0);
                            if ($starRc < 1 && (int) ($groupStats['review_count'] ?? 0) > 0) {
                                $starAvg = (float) ($groupStats['avg_rating'] ?? 0);
                                $starRc = (int) ($groupStats['review_count'] ?? 0);
                            }
                            $starRounded = (int) round($starAvg);
                            for ($si = 1; $si <= 5; $si++):
                                $starClass = $si <= $starRounded ? 'pf-star-on' : 'pf-star-off';
                                ?>
                                <svg class="<?php echo $starClass; ?>" viewBox="0 0 20 20" aria-hidden="true"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>
                            <?php endfor;
                            if ($starRc > 0): ?>
                                <span class="rating-text"><?php echo number_format($starAvg, 1); ?> (<?php echo (int) $starRc; ?>)</span>
                            <?php endif; ?>
                        </div>
                        <span id="pf-group-stats-sold">— sold</span>
                    </div>

                    <div id="pf-group-selected-price" class="pf-group-selected-price">—</div>
                    <div id="pf-group-selected-stock" class="pf-group-selected-stock">—</div>
                </div>
            </div>
            <div class="pf-group-selection-options">
                <h2 class="pf-group-options-heading">Available options</h2>
                <div class="pf-group-options" id="pf-group-options">
                    <?php foreach ($options as $opt):
                        $pid = (int) $opt['product_id'];
                        $ps = $productStatsMap[$pid] ?? ['avg_rating' => 0.0, 'review_count' => 0, 'sold_count' => 0];
                        $stockQty = (int) $opt['stock_quantity'];
                        $stockLabel = $stockQty > 0 ? ($stockQty . ' in stock') : 'Out of stock';
                        ?>
                        <div class="pf-group-option<?php echo $pid === $selectedId ? ' is-active' : ''; ?>"
                             data-product-id="<?php echo $pid; ?>"
                             data-name="<?php echo htmlspecialchars($opt['name'], ENT_QUOTES); ?>"
                             data-price="<?php echo htmlspecialchars(number_format($opt['price'], 2, '.', ''), ENT_QUOTES); ?>"
                             data-stock="<?php echo $stockQty; ?>"
                             data-image="<?php echo htmlspecialchars($opt['image_url'], ENT_QUOTES); ?>"
                             data-avg-rating="<?php echo htmlspecialchars(number_format((float) $ps['avg_rating'], 2, '.', ''), ENT_QUOTES); ?>"
                             data-review-count="<?php echo (int) ($ps['review_count'] ?? 0); ?>"
                             data-sold-count="<?php echo (int) ($ps['sold_count'] ?? 0); ?>">
                            <img src="<?php echo htmlspecialchars($opt['image_url']); ?>" alt="">
                            <div class="pf-group-option-text">
                                <div class="pf-group-option-name"><?php echo htmlspecialchars($opt['name']); ?></div>
                                <div class="pf-group-option-meta"><?php echo format_currency($opt['price']); ?> · <?php echo htmlspecialchars($stockLabel); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="shopee-footer pf-group-options-actions">
                    <button type="button" id="pf-group-add-cart" class="shopee-btn shopee-btn-cart" title="Add to Cart">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    </button>
                    <a id="pf-group-order-now" href="#" class="shopee-btn shopee-btn-buy">Order Now</a>
                </div>
            </div>
        </div>
    </div>

    <section class="pf-group-reviews" aria-labelledby="pf-group-reviews-heading">
        <h2 id="pf-group-reviews-heading" class="poc-section-title">Product Ratings</h2>

        <?php if ($total_reviews > 0): ?>
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;">
            <div style="display:flex;gap:2rem;align-items:center;flex-wrap:wrap;">
                <div style="text-align:center;">
                    <div style="font-size:3rem;font-weight:700;color:#f97316;line-height:1;"><?php echo number_format($avg_rating, 1); ?></div>
                    <div style="font-size:0.875rem;color:#6b7280;margin-top:0.25rem;">out of 5</div>
                    <div style="display:flex;gap:2px;margin-top:0.5rem;justify-content:center;">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <svg width="22" height="22" fill="<?php echo ($i <= round($avg_rating)) ? '#f97316' : '#d1d5db'; ?>" viewBox="0 0 20 20" aria-hidden="true">
                                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                            </svg>
                        <?php endfor; ?>
                    </div>
                </div>
                <div style="flex:1;min-width:260px;">
                    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.5rem;">
                        <button type="button" class="poc-filter-btn active" data-filter="all" style="padding:0.5rem 1rem;border:1px solid #e5e7eb;border-radius:6px;background:white;cursor:pointer;font-size:0.875rem;transition:all 0.2s;">All</button>
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <button type="button" class="poc-filter-btn" data-filter="<?php echo $i; ?>" style="padding:0.5rem 1rem;border:1px solid #e5e7eb;border-radius:6px;background:white;cursor:pointer;font-size:0.875rem;transition:all 0.2s;"><?php echo $i; ?> Star (<?php echo (int) $rating_counts[$i]; ?>)</button>
                        <?php endfor; ?>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
                        <button type="button" class="poc-filter-btn" data-filter="comments" style="padding:0.5rem 1rem;border:1px solid #e5e7eb;border-radius:6px;background:white;cursor:pointer;font-size:0.875rem;transition:all 0.2s;">With Comments (<?php echo (int) $with_comments; ?>)</button>
                        <button type="button" class="poc-filter-btn" data-filter="media" style="padding:0.5rem 1rem;border:1px solid #e5e7eb;border-radius:6px;background:white;cursor:pointer;font-size:0.875rem;transition:all 0.2s;">With Media (<?php echo (int) $with_media; ?>)</button>
                    </div>
                </div>
            </div>
        </div>

        <div id="poc-reviews-container">
            <?php foreach ($reviews_paged as $review):
                $reviewer_name = htmlspecialchars(trim(($review['first_name'] ?? '') . ' ' . ($review['last_name'] ?? '')));
                $profile_pic = !empty($review['profile_picture'])
                    ? get_profile_image($review['profile_picture'])
                    : '';
                $rating = (int) ($review['rating'] ?? 0);
                $comment = htmlspecialchars($review['comment'] ?? '');
                $has_comment = trim((string) ($review['comment'] ?? '')) !== '';
                $rev_imgs = $review['images'] ?? [];
                $has_video = !empty($review['video_path']);
                $has_media = !empty($rev_imgs) || $has_video;
                $product_label = trim((string) ($review['product_name'] ?? ''));
                ?>
            <div id="review-<?php echo (int) $review['id']; ?>" class="poc-review-item" data-rating="<?php echo $rating; ?>" data-has-comment="<?php echo $has_comment ? '1' : '0'; ?>" data-has-media="<?php echo $has_media ? '1' : '0'; ?>" style="padding:1.5rem 0;border-bottom:1px solid #e5e7eb;">
                <div style="display:flex;gap:1rem;align-items:flex-start;">
                    <div style="flex-shrink:0;">
                        <?php if ($profile_pic): ?>
                            <img src="<?php echo htmlspecialchars($profile_pic); ?>" alt="<?php echo $reviewer_name; ?>" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($profile_pic_fallback); ?>';" style="width:48px;height:48px;border-radius:50%;object-fit:cover;">
                        <?php else: ?>
                            <div style="width:48px;height:48px;border-radius:50%;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-weight:600;color:#6b7280;">
                                <?php echo strtoupper(substr($reviewer_name, 0, 1) ?: '?'); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <?php if ($product_label !== ''): ?>
                            <div class="pf-review-product-label"><?php echo htmlspecialchars($product_label); ?></div>
                        <?php endif; ?>
                        <div style="font-weight:600;color:#1f2937;margin-bottom:0.25rem;overflow-wrap:anywhere;word-break:break-word;"><?php echo $reviewer_name !== '' ? $reviewer_name : 'Customer'; ?></div>
                        <div style="display:flex;gap:2px;margin-bottom:0.5rem;flex-wrap:wrap;">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <svg width="16" height="16" fill="<?php echo ($i <= $rating) ? '#f97316' : '#d1d5db'; ?>" viewBox="0 0 20 20" aria-hidden="true">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                </svg>
                            <?php endfor; ?>
                        </div>
                        <?php if (!empty($review['created_at'])): ?>
                            <div style="font-size:0.875rem;color:#6b7280;margin-bottom:0.5rem;"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string) $review['created_at']))); ?></div>
                        <?php endif; ?>
                        <?php if ($has_comment): ?>
                            <div style="color:#374151;line-height:1.6;margin-bottom:0.75rem;overflow-wrap:anywhere;word-break:break-word;"><?php echo nl2br($comment); ?></div>
                        <?php endif; ?>

                        <?php if (!empty($rev_imgs)): ?>
                            <div style="margin-bottom:0.75rem;display:flex;flex-wrap:wrap;gap:0.5rem;">
                                <?php foreach ($rev_imgs as $img):
                                    $ipath = (string) ($img['image_path'] ?? '');
                                    if ($ipath === '' || !preg_match('/\.(jpg|jpeg|png|webp|gif|svg)$/i', $ipath)) {
                                        continue;
                                    }
                                    if (strpos($ipath, 'http') === false && (!isset($ipath[0]) || $ipath[0] !== '/')) {
                                        $ipath = rtrim($base_path, '/') . '/' . ltrim($ipath, '/');
                                    }
                                    ?>
                                    <button type="button" class="poc-media-trigger" data-media-type="image" data-media-src="<?php echo htmlspecialchars($ipath); ?>" aria-label="View review image">
                                        <img src="<?php echo htmlspecialchars($ipath); ?>" alt="Review image" class="poc-media-thumb" style="max-width:200px;border-radius:8px;border:1px solid #e5e7eb;">
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($has_video && preg_match('/\.(mp4|webm|ogg|mov)$/i', (string) $review['video_path'])):
                            $vpath = (string) $review['video_path'];
                            if (strpos($vpath, 'http') === false && (!isset($vpath[0]) || $vpath[0] !== '/')) {
                                $vpath = rtrim($base_path, '/') . '/' . ltrim($vpath, '/');
                            }
                            ?>
                            <div style="margin-bottom:0.75rem;">
                                <button type="button" class="poc-media-trigger" data-media-type="video" data-media-src="<?php echo htmlspecialchars($vpath); ?>" aria-label="Play review video">
                                    <div class="poc-video-thumb">
                                        <video src="<?php echo htmlspecialchars($vpath); ?>" controls playsinline preload="metadata" class="poc-video-preview" onclick="event.stopPropagation();"></video>
                                    </div>
                                </button>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($review['replies'])): ?>
                            <div style="margin-top:1rem;padding:1rem;background:#f9fafb;border-left:3px solid #e5e7eb;border-radius:6px;">
                                <div style="font-size:0.75rem;font-weight:700;color:#374151;text-transform:uppercase;margin-bottom:0.5rem;letter-spacing:0.05em;">Staff Response</div>
                                <?php foreach ($review['replies'] as $reply): ?>
                                    <div style="margin-bottom:0.5rem;">
                                        <div style="color:#374151;font-size:0.875rem;line-height:1.5;overflow-wrap:anywhere;word-break:break-word;"><?php echo nl2br(htmlspecialchars($reply['reply_message'] ?? '')); ?></div>
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

                        <div style="display:flex;align-items:center;gap:8px;margin-top:8px;">
                            <button type="button" onclick="markHelpful(<?php echo (int) $review['id']; ?>, this)" class="helpful-btn<?php echo !empty($review['user_voted']) ? ' voted' : ''; ?>" data-default-label="Helpful"<?php echo !empty($review['user_voted']) ? ' data-voted="1"' : ''; ?>>
                                <svg width="15" height="15" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path d="M2 10.5a1.5 1.5 0 113 0v6a1.5 1.5 0 01-3 0v-6zM6 10.333v5.43a2 2 0 001.106 1.79l.05.025A4 4 0 008.943 18h5.416a2 2 0 001.962-1.608l1.2-6A2 2 0 0015.56 8H12V4a2 2 0 00-2-2 1 1 0 00-1 1v.667a4 4 0 01-.8 2.4L6.8 7.933a4 4 0 00-.8 2.4z"/></svg>
                                <span class="helpful-label"><?php echo !empty($review['user_voted']) ? (int) ($review['helpful_count'] ?? 0) : 'Helpful'; ?></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php
        $pagination_params = ['group_id' => $groupId];
        if ($selectedId > 0) {
            $pagination_params['product_id'] = $selectedId;
        }
        echo render_pagination($poc_page, $poc_total_pages, $pagination_params, 'rpage');
        ?>

        <?php else: ?>
        <div class="poc-empty">
            <svg width="56" height="56" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
            <p style="font-size:1rem;font-weight:600;margin:0.75rem 0 0.25rem;">No Reviews Yet</p>
            <p style="font-size:0.875rem;color:#9ca3af;">Be the first to review products in this group!</p>
        </div>
        <?php endif; ?>
    </section>
</div>

<div id="pocMediaModal" class="poc-media-modal" aria-hidden="true">
    <div class="poc-media-modal-inner" role="dialog" aria-modal="true" aria-label="Media viewer">
        <button type="button" id="pocMediaClose" class="poc-media-close" aria-label="Close media viewer">&times;</button>
        <img id="pocMediaImg" class="poc-media-full" alt="Media preview" hidden>
        <video id="pocMediaVideo" class="poc-media-full" controls playsinline hidden>
            <source id="pocMediaVideoSource" src="" type="video/mp4">
        </video>
    </div>
</div>

<script src="<?php echo htmlspecialchars($base_path); ?>/public/assets/js/add_to_cart_fx.js"></script>
<script>
var PF_CSRF_TOKEN = '<?php echo generate_csrf_token(); ?>';
var PF_GROUP_ID = <?php echo (int)$groupId; ?>;
var PF_GROUP_STATS = <?php echo json_encode([
    'avg_rating' => (float) ($groupStats['avg_rating'] ?? 0),
    'review_count' => (int) ($groupStats['review_count'] ?? 0),
], JSON_UNESCAPED_UNICODE); ?>;

function pfFormatSold(count) {
    var n = parseInt(count || '0', 10);
    if (isNaN(n) || n < 0) n = 0;
    return n >= 1000 ? (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k' : String(n);
}

function pfRenderStatsStars(avgRating, reviewCount) {
    var wrap = document.getElementById('pf-group-stats-stars');
    if (!wrap) return;
    var productAvg = parseFloat(avgRating);
    if (isNaN(productAvg)) productAvg = 0;
    var productRc = parseInt(reviewCount || '0', 10);
    if (isNaN(productRc) || productRc < 0) productRc = 0;

    var avg = productAvg;
    var rc = productRc;
    if (productRc < 1 && PF_GROUP_STATS && parseInt(PF_GROUP_STATS.review_count || '0', 10) > 0) {
        avg = parseFloat(PF_GROUP_STATS.avg_rating);
        if (isNaN(avg)) avg = 0;
        rc = parseInt(PF_GROUP_STATS.review_count, 10);
    }

    var rounded = Math.round(avg);
    if (rounded < 0) rounded = 0;
    if (rounded > 5) rounded = 5;

    var html = '';
    for (var i = 1; i <= 5; i++) {
        var cls = i <= rounded ? 'pf-star-on' : 'pf-star-off';
        html += '<svg class="' + cls + '" viewBox="0 0 20 20" aria-hidden="true"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path></svg>';
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

document.addEventListener('DOMContentLoaded', function () {
    var filterBtns = document.querySelectorAll('.pf-group-reviews .poc-filter-btn');
    var reviewItems = document.querySelectorAll('.pf-group-reviews .poc-review-item');
    filterBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            filterBtns.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            var filter = btn.getAttribute('data-filter');
            reviewItems.forEach(function (item) {
                var show = filter === 'all'
                    || (filter === 'comments' && item.getAttribute('data-has-comment') === '1')
                    || (filter === 'media' && item.getAttribute('data-has-media') === '1')
                    || item.getAttribute('data-rating') === filter;
                item.style.display = show ? '' : 'none';
            });
        });
    });
});

document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('pocMediaModal');
    var modalImg = document.getElementById('pocMediaImg');
    var modalVideo = document.getElementById('pocMediaVideo');
    var modalVideoSource = document.getElementById('pocMediaVideoSource');
    var closeBtn = document.getElementById('pocMediaClose');
    if (!modal || !modalImg || !modalVideo || !modalVideoSource || !closeBtn) return;

    var openMedia = function (type, src) {
        if (!src) return;
        if (type === 'video') {
            modalImg.hidden = true;
            modalVideo.hidden = false;
            modalVideoSource.src = src;
            modalVideo.load();
        } else {
            modalVideo.hidden = true;
            modalImg.hidden = false;
            modalImg.src = src;
        }
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    };

    var closeMedia = function () {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        modalImg.src = '';
        modalVideo.pause();
        modalVideoSource.src = '';
        modalVideo.load();
        document.body.style.overflow = '';
    };

    document.querySelectorAll('.pf-group-reviews .poc-media-trigger').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openMedia(btn.getAttribute('data-media-type'), btn.getAttribute('data-media-src'));
        });
    });

    closeBtn.addEventListener('click', closeMedia);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeMedia();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) closeMedia();
    });
});

async function markHelpful(reviewId, btn) {
    var basePath = <?php echo json_encode(rtrim($base_path, '/'), JSON_UNESCAPED_SLASHES); ?>;
    var label = btn.querySelector('.helpful-label');
    var originalLabel = btn.getAttribute('data-default-label') || 'Helpful';
    var nextAction = btn.getAttribute('data-voted') === '1' ? 'unlike' : 'like';
    btn.disabled = true;
    try {
        var res = await fetch(basePath + '/public/api/review_helpful.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'review_id=' + encodeURIComponent(reviewId) + '&action=' + encodeURIComponent(nextAction)
        });
        var data = await res.json().catch(function () { return { success: false, error: 'Invalid server response' }; });
        if (data.success) {
            if (data.voted) {
                btn.setAttribute('data-voted', '1');
                btn.classList.add('voted');
                label.textContent = data.count;
            } else {
                btn.removeAttribute('data-voted');
                btn.classList.remove('voted');
                label.textContent = originalLabel;
            }
        }
    } catch (e) {
        console.error('Helpful vote failed:', e);
    } finally {
        btn.disabled = false;
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
