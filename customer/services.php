<?php
/**
 * Customer Services
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/customer_service_catalog.php';
require_once __DIR__ . '/../includes/service_field_config_helper.php';

require_role('Customer');

$base_path = pf_app_base_path();
$default_service_img = $base_path . '/public/assets/images/services/default.png';

function pf_normalize_service_image_path($path, $base_path, $default_img) {
    $path = trim((string)$path);
    if ($path === '') {
        return $default_img;
    }
    $path = str_replace('\\', '/', $path);
    // Absolute URLs must be used as-is so images load from CDNs or absolute hosts.
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    if (preg_match('#^[A-Za-z]:/#', $path)) {
        $path = preg_replace('#^[A-Za-z]:#', '', $path);
    }
    $public_pos = strpos($path, '/public/');
    if ($public_pos !== false) {
        $path = substr($path, $public_pos);
    }
    $uploads_pos = strpos($path, '/uploads/');
    if ($uploads_pos !== false && ($public_pos === false || $uploads_pos < $public_pos)) {
        $path = substr($path, $uploads_pos);
    }
    if ($base_path === '' && strpos($path, '/printflow/') === 0) {
        $path = substr($path, strlen('/printflow'));
    }
    if ($path !== '' && $path[0] !== '/') {
        $path = '/' . ltrim($path, '/');
    }
    if ($base_path !== '' && strpos($path, $base_path . '/') !== 0) {
        $path = $base_path . $path;
    }
    return $path;
}

function pf_service_local_asset_exists($urlPath, $base_path): bool {
    $urlPath = trim((string)$urlPath);
    if ($urlPath === '' || preg_match('#^https?://#i', $urlPath)) {
        return true;
    }

    $clean = str_replace('\\', '/', $urlPath);
    $clean = strtok($clean, '?#');
    if (!is_string($clean) || $clean === '') {
        return false;
    }

    $base = rtrim((string)$base_path, '/');
    if ($base !== '' && strpos($clean, $base . '/') === 0) {
        $clean = substr($clean, strlen($base));
    }
    if ($clean === false) {
        return false;
    }
    $clean = '/' . ltrim((string)$clean, '/');
    $appRoot = realpath(__DIR__ . '/..');
    if ($appRoot === false) {
        return false;
    }

    $candidates = [__DIR__ . '/..' . $clean];
    // Some deployments store web assets under /public (e.g. /public/uploads/...).
    if (strpos($clean, '/uploads/') === 0 || strpos($clean, '/public/') === 0) {
        $candidates[] = $appRoot . '/public' . (strpos($clean, '/public/') === 0 ? substr($clean, strlen('/public')) : $clean);
    }

    foreach ($candidates as $full) {
        $local = realpath($full);
        if ($local !== false && strpos($local, $appRoot) === 0 && is_file($local)) {
            return true;
        }
        if (is_file($full) && strpos(realpath(dirname($full)) ?: '', $appRoot) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * First image (non-video) from display_image CSV, else first media, then hero.
 */
function pf_service_card_primary_image(string $display_csv, string $hero, string $base_path, string $default_img): string {
    $candidates = [];
    if (trim($display_csv) !== '') {
        foreach (array_filter(array_map('trim', explode(',', $display_csv))) as $p) {
            if ($p !== '') {
                $candidates[] = $p;
            }
        }
    }
    if (trim($hero) !== '') {
        $candidates[] = trim($hero);
    }

    $pick = '';
    foreach ($candidates as $raw) {
        if (!pf_service_media_is_video($raw)) {
            $pick = $raw;
            break;
        }
    }
    if ($pick === '' && $candidates !== []) {
        $pick = $candidates[0];
    }
    if ($pick === '') {
        return $default_img;
    }
    return pf_normalize_service_image_path($pick, $base_path, $default_img);
}

function pf_service_media_is_video($path) {
    return printflow_is_video_media_path((string) $path);
}

// Fetch services from DB
$visible_rows = db_query(
    'SELECT s.*, 
    (SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi INNER JOIN orders o ON o.order_id = oi.order_id WHERE o.status != \'Cancelled\' AND (
        (LOWER(TRIM(COALESCE(o.order_type, \'\'))) = \'custom\' AND o.reference_id = s.service_id)
        OR oi.customization_data LIKE CONCAT(\'%"service_id":\', s.service_id, \'%\')
        OR oi.customization_data LIKE CONCAT(\'%"service_id": \', s.service_id, \'%\')
        OR oi.customization_data LIKE CONCAT(\'%"service_id":"\', s.service_id, \'"%\')
        OR (oi.customization_data LIKE \'%"service_type"%\' AND oi.customization_data LIKE CONCAT(\'%\', CONVERT(s.name USING utf8mb4) COLLATE utf8mb4_unicode_ci, \'%\'))
    )) as sold_count,
    (SELECT AVG(rating) FROM reviews r WHERE r.service_type COLLATE utf8mb4_unicode_ci = s.name COLLATE utf8mb4_unicode_ci) as avg_rating,
    (SELECT COUNT(*) FROM reviews r WHERE r.service_type COLLATE utf8mb4_unicode_ci = s.name COLLATE utf8mb4_unicode_ci) as review_count
    FROM services s WHERE s.status = \'Activated\' ORDER BY name ASC',
    '',
    []
) ?: [];

$core_services = [];
foreach ($visible_rows as $row) {
    $sid = (int)($row['service_id'] ?? 0);
    if ($sid < 1) {
        continue;
    }

    // Every catalog tile must hit order_service_dynamic.php so order_items.customization_data always carries
    // service_id + the same field labels as admin service_field_configs (legacy customer_link flows omit these).
    if (!service_has_field_config($sid)) {
        $cl = trim((string)($row['customer_link'] ?? ''));
        if ($cl !== '') {
            $cl = basename(str_replace('\\', '/', $cl));
        }
        init_service_field_config($sid, $cl !== '' ? $cl : null);
    }

    $img = pf_service_card_primary_image(
        (string)($row['display_image'] ?? ''),
        (string)($row['hero_image'] ?? ''),
        $base_path,
        $default_service_img
    );

    $core_services[] = [
        'id' => $sid,
        'name' => $row['name'],
        'category' => $row['category'] ?? '',
        'img' => $img,
        'display_image_raw' => (string)($row['display_image'] ?? ''),
        'hero_image_raw' => (string)($row['hero_image'] ?? ''),
        'link' => 'order_service_dynamic.php?service_id=' . $sid,
        'modal_text' => $row['customer_modal_text'] ?: printflow_default_customer_service_modal_text(),
        'sold_count' => (int)$row['sold_count'],
        'avg_rating' => (float)$row['avg_rating'],
        'review_count' => (int)$row['review_count']
    ];
}

foreach ($core_services as &$service_row) {
    $review_stats = printflow_get_service_review_stats($service_row['name']);
    $service_row['avg_rating'] = (float)($review_stats['avg_rating'] ?? 0);
    $service_row['review_count'] = (int)($review_stats['review_count'] ?? 0);
}
unset($service_row);

$csrf_token = generate_csrf_token();
$page_title = 'Services - PrintFlow';
$use_customer_css = true;
require_once __DIR__ . '/../includes/header.php';

// Reusable card template function
function render_service_card($srv) {
    global $base_path, $default_service_img;
    $img = pf_normalize_service_image_path($srv['img'], $base_path, $default_service_img);
    $is_video = pf_service_media_is_video($img);
    
    $json_name = htmlspecialchars(json_encode($srv['name']), ENT_QUOTES, 'UTF-8');
    $json_category = htmlspecialchars(json_encode($srv['category']), ENT_QUOTES, 'UTF-8');
    $json_img = htmlspecialchars(json_encode($img), ENT_QUOTES, 'UTF-8');
    $json_link = htmlspecialchars(json_encode($srv['link']), ENT_QUOTES, 'UTF-8');
    $json_modal_text = htmlspecialchars(json_encode($srv['modal_text']), ENT_QUOTES, 'UTF-8');
    
    $display_images = [];
    $raw_csv = trim((string)($srv['display_image_raw'] ?? ''));
    if ($raw_csv !== '') {
        foreach (explode(',', $raw_csv) as $imgPath) {
            $imgPath = trim($imgPath);
            if ($imgPath !== '') {
                $normalized = pf_normalize_service_image_path($imgPath, $base_path, $default_service_img);
                if ($normalized !== '') {
                    $display_images[] = $normalized;
                }
            }
        }
    }
    if (empty($display_images) && trim((string)($srv['hero_image_raw'] ?? '')) !== '') {
        $h = pf_normalize_service_image_path(trim($srv['hero_image_raw']), $base_path, $default_service_img);
        if ($h !== '' && $h !== $default_service_img) {
            $display_images[] = $h;
        }
    }
    if (empty($display_images)) {
        $display_images[] = $img;
    }
    $json_images = htmlspecialchars(json_encode($display_images), ENT_QUOTES, 'UTF-8');
    
    $ravg = $srv['avg_rating'];
    $rcount = $srv['review_count'];
    $sold = $srv['sold_count'];
    $display_sold = $sold;
    ?>
    <div class="shopee-card" onclick="window.location.href=<?php echo $json_link; ?>;" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();window.location.href=<?php echo $json_link; ?>;}" role="link" tabindex="0" aria-label="Order <?php echo htmlspecialchars($srv['name']); ?>">
        <?php if ($is_video): ?>
            <video
                src="<?php echo htmlspecialchars($img); ?>"
                class="shopee-img"
                muted
                playsinline
                autoplay
                loop
                preload="auto"
                oncanplay="this.play().catch(function(){});this.style.opacity='1';"
                style="background:#f8fafc;opacity:0;"
            ></video>
        <?php else: ?>
            <img src="<?php echo htmlspecialchars($img); ?>" alt="<?php echo htmlspecialchars($srv['name']); ?>" class="shopee-img" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($default_service_img); ?>';">
        <?php endif; ?>
        <div class="shopee-body">
            <span class="shopee-category"><?php echo htmlspecialchars($srv['category']); ?></span>
            <h3 class="shopee-name"><?php echo htmlspecialchars($srv['name']); ?></h3>
            
            <div class="rating-stars">
                <?php for($i=1; $i<=5; $i++): ?>
                    <svg style="width: 14px; height: 14px;" fill="<?php echo ($i <= round($ravg)) ? '#ffca11' : '#e5e7eb'; ?>" viewBox="0 0 20 20">
                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
                    </svg>
                <?php endfor; ?>
                <span class="rating-text"><?php echo $rcount > 0 ? "($rcount)" : ''; ?></span>
                <span class="shopee-sold"><?php echo $display_sold; ?> sold</span>
            </div>
        </div>
        <div class="shopee-footer" onclick="event.stopPropagation();">
            <a href="<?php echo htmlspecialchars($srv['link']); ?>" class="shopee-btn shopee-btn-buy">Order Now</a>
        </div>
    </div>
    <?php
}
?>

<style>
    :root {
        --shopee-orange: #0f3441;
        --shopee-bg: #ffffff;
        --shopee-card-bg: rgba(255, 255, 255, 0.78);
        --shopee-text: #173042;
        --shopee-muted: #688092;
        --shopee-border: rgba(126, 164, 184, 0.24);
        --shopee-glass-shadow: 0 22px 50px rgba(13, 45, 60, 0.12);
        --shopee-glass-shadow-hover: 0 28px 65px rgba(13, 45, 60, 0.2);
        --shopee-glow: linear-gradient(135deg, rgba(129, 212, 250, 0.3), rgba(255, 255, 255, 0.08) 45%, rgba(139, 226, 216, 0.18));
    }

    .ct-product-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 18px;
    }

    .shopee-card {
        background: var(--shopee-card-bg);
        border: 1px solid var(--shopee-border);
        border-radius: 22px;
        transition: transform 0.28s ease, box-shadow 0.28s ease, border-color 0.28s ease;
        cursor: pointer;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        min-height: 100%;
        position: relative;
        box-shadow: var(--shopee-glass-shadow);
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
    }

    .shopee-card::before {
        content: "";
        position: absolute;
        inset: 0;
        background: var(--shopee-glow);
        opacity: 0.85;
        pointer-events: none;
    }

    .shopee-card::after {
        content: "";
        position: absolute;
        inset: 1px;
        border-radius: 21px;
        border: 1px solid rgba(255, 255, 255, 0.45);
        pointer-events: none;
    }

    .shopee-card:hover {
        transform: translateY(-8px);
        box-shadow: var(--shopee-glass-shadow-hover);
        border-color: rgba(93, 158, 188, 0.42);
    }

    .shopee-card > * {
        position: relative;
        z-index: 1;
    }

    .shopee-img {
        width: calc(100% - 24px);
        margin: 12px 12px 0;
        aspect-ratio: 1.05;
        object-fit: cover;
        border-radius: 16px;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.45), 0 14px 30px rgba(16, 53, 71, 0.12);
        background: linear-gradient(180deg, rgba(240, 248, 252, 0.9), rgba(225, 236, 243, 0.9));
    }

    .shopee-body {
        padding: 12px 14px 0;
        flex-grow: 1;
        display: flex;
        flex-direction: column;
    }

    .shopee-name {
        font-size: 0.92rem;
        line-height: 1.25rem;
        min-height: 2.5rem;
        overflow: hidden;
        text-overflow: ellipsis;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        color: var(--shopee-text);
        margin-bottom: 8px;
        font-weight: 700;
        letter-spacing: -0.02em;
    }

    .shopee-category {
        font-size: 0.68rem;
        color: #477089;
        margin-bottom: 8px;
        display: inline-flex;
        align-items: center;
        width: fit-content;
        padding: 0.32rem 0.68rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.6);
        border: 1px solid rgba(126, 164, 184, 0.18);
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-weight: 700;
    }

    .shopee-price-row {
        margin-top: auto;
        margin-bottom: 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .shopee-price {
        color: var(--shopee-orange);
        font-weight: 600;
        font-size: 1.1rem;
    }

    .shopee-sold {
        margin-left: auto;
        font-size: 0.72rem;
        color: var(--shopee-muted);
        padding: 0.22rem 0.55rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.55);
        border: 1px solid rgba(126, 164, 184, 0.16);
    }

    .shopee-footer {
        padding: 10px 14px 14px;
        border-top: 1px solid rgba(126, 164, 184, 0.16);
        display: flex;
        gap: 8px;
    }

    .shopee-btn {
        flex: 1;
        padding: 0.82rem 0.9rem;
        border-radius: 14px;
        font-size: 0.78rem;
        font-weight: 700;
        text-align: center;
        text-transform: uppercase;
        transition: transform 0.22s ease, box-shadow 0.22s ease, background 0.22s ease, opacity 0.22s ease;
        border: 1px solid transparent;
        display: flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        cursor: pointer;
        letter-spacing: 0.05em;
    }

    .shopee-btn-cart {
        background: rgba(10, 37, 48, 0.08);
        color: var(--shopee-orange);
    }

    .shopee-btn-buy {
        background: linear-gradient(135deg, #123746 0%, #0f4958 100%);
        color: #fff;
        width: 100%;
        box-shadow: 0 12px 24px rgba(15, 58, 73, 0.22);
    }

    .shopee-btn:hover {
        opacity: 1;
        transform: translateY(-2px);
        box-shadow: 0 16px 32px rgba(15, 58, 73, 0.28);
    }

    .rating-stars {
        color: #ffca11;
        font-size: 0.74rem;
        display: flex;
        align-items: center;
        gap: 4px;
        margin-bottom: 2px;
        padding-top: 0;
    }

    .rating-text {
        font-size: 0.72rem;
        color: var(--shopee-muted);
        margin-left: 6px;
        font-weight: 600;
    }

    @media (max-width: 1023px) and (min-width: 641px) {
        .ct-product-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
    }

    @media (max-width: 640px) {
        .ct-product-grid {
            grid-template-columns: 1fr;
            gap: 18px;
        }

        .shopee-card {
            border-radius: 22px;
        }

        .shopee-img {
            width: calc(100% - 22px);
            margin: 11px 11px 0;
            aspect-ratio: 1.32;
            border-radius: 16px;
        }

        .shopee-body {
            padding: 14px 15px 0;
        }

        .shopee-name {
            font-size: 1.02rem;
            line-height: 1.38rem;
            min-height: 2.76rem;
        }

        .shopee-footer {
            padding: 12px 15px 15px;
        }

        .shopee-btn {
            min-height: 46px;
        }
    }
    
    #service-modal-content {
        background: rgba(0,28,36,0.97) !important;
        border: 1px solid rgba(83,197,224,0.28) !important;
        box-shadow: 0 25px 50px rgba(0,0,0,0.6) !important;
    }
    #modal-name { color: #eaf6fb !important; }
    #modal-intro-text { color: #b9d4df !important; }
    #modal-cart-section {
        background: rgba(0,18,24,0.97) !important;
        border-top-color: rgba(83,197,224,0.24) !important;
    }
</style>

<div class="min-h-screen py-8">
    <div class="container mx-auto px-4" style="max-width:1100px;">

        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
            <h1 class="text-2xl font-bold text-gray-800">Available Services</h1>
        </div>
        
        <?php if (empty($core_services)): ?>
            <div class="ct-empty" style="padding:4rem;text-align:center;color:#6b7280; background: rgba(15, 23, 42, 0.5); border-radius: 1rem;">
                <div style="font-size: 4rem; margin-bottom: 1rem;">🏢</div>
                <p>No services are available at the moment.</p>
            </div>
        <?php else: ?>
        <div class="ct-product-grid mb-12">
            <?php foreach ($core_services as $srv): ?>
                <?php render_service_card($srv); ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- Service Detail Modal -->
<div id="service-modal" style="display: none; position: fixed; inset: 0; align-items: center; justify-content: center; z-index: 9999999; padding: 1.5rem; transition: opacity 0.2s ease;">
    <!-- Backdrop -->
    <div onclick="closeServiceModal()" style="position: absolute; inset: 0; background-color: rgba(0, 0, 0, 0.45);"></div>
    
    <div id="service-modal-content" style="position: relative; background: rgba(10, 37, 48, 0.96); border: none; border-radius: 0; width: 620px; max-width: 100%; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); transform: translateY(20px); transition: all 0.3s ease;">
        
        <style>
            #service-modal-scroll-body::-webkit-scrollbar { width: 6px; }
            #service-modal-scroll-body::-webkit-scrollbar-track { background: transparent; }
            #service-modal-scroll-body::-webkit-scrollbar-thumb { background: rgba(83, 197, 224, 0.7); border-radius: 10px; }
            .modal-action-row { display: flex; align-items: stretch; gap: 1rem; }
            .modal-qty-block { display: flex; align-items: center; border: none; border-radius: 0; height: 48px; flex-shrink: 0; background: rgba(12, 43, 56, 0.92); }
            .modal-qty-btn { width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; background: transparent; border: none; cursor: pointer; color: #e8f4f8; font-weight: 700; transition: all 0.2s; }
            .modal-action-buttons { display: grid; grid-template-columns: 1fr; gap: 0.75rem; flex: 1; }
            .modal-action-btn { height: 52px; display: flex; align-items: center; justify-content: center; gap: 8px; font-weight: 800; border-radius: 0; border: none; cursor: pointer; transition: all 0.3s; font-size: 0.9rem; text-transform: uppercase; background: var(--shopee-orange); color: #001c24; box-shadow: 0 0 15px rgba(83, 197, 224, 0.25); letter-spacing: 0.05em; }
            .modal-action-btn:hover { background: #7adcf5; box-shadow: 0 0 25px rgba(83, 197, 224, 0.5); transform: translateY(-2px); }
        </style>
        
        <button onclick="closeServiceModal()" style="position: absolute; top: 1rem; right: 1rem; z-index: 100; padding: 0.5rem; background: #ffffff; border: none; border-radius: 0; cursor: pointer; box-shadow: 0 4px 10px rgba(0,0,0,0.1); display: flex; align-items: center; justify-content: center;">
            <svg style="width: 1.5rem; height: 1.5rem; color: #1e293b;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>

        <div id="service-modal-scroll-body" style="overflow-y: auto; flex: 1; display: flex; flex-direction: column;">
            <div style="width: 100%; height: 280px; position: relative; flex-shrink: 0;">
                <div id="modal-image-carousel" style="position:relative;width:100%;height:100%;overflow:hidden;">
                    <!-- Images will be inserted here dynamically -->
                </div>
                
                <!-- Navigation Arrows -->
                <button type="button" id="modal-carousel-prev" onclick="changeModalImage(-1);event.stopPropagation();" style="display:none;position:absolute;left:12px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.9);color:#374151;border:none;border-radius:0;width:36px;height:36px;cursor:pointer;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,0.2);z-index:10;transition:all 0.2s;">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <button type="button" id="modal-carousel-next" onclick="changeModalImage(1);event.stopPropagation();" style="display:flex;position:absolute;right:12px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,0.9);color:#374151;border:none;border-radius:0;width:36px;height:36px;cursor:pointer;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,0.2);z-index:10;transition:all 0.2s;">
                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
                
                <!-- Image Counter -->
                <div id="modal-image-counter" style="display:none;position:absolute;bottom:16px;right:16px;background:rgba(0,0,0,0.7);color:white;padding:4px 10px;border-radius:0;font-size:11px;font-weight:600;z-index:10;">
                    <span id="modal-current-image">1</span> / <span id="modal-total-images">1</span>
                </div>
                
                <div style="position: absolute; top: 1.25rem; left: 1.25rem; z-index:11;">
                    <span id="modal-category" style="padding: 0.35rem 0.85rem; background: #ffffff; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; border-radius: 0; color: #4F46E5; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">CATEGORY</span>
                </div>
            </div>

            <div style="padding: 1.5rem 2rem; display: flex; flex-direction: column;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.75rem;">
                    <h2 id="modal-name" style="font-size: 1.5rem; font-weight: 800; color: #eaf6fb; margin: 0;">Service Name</h2>
                    <div id="modal-rating-pill" style="display: flex; align-items: center; gap: 6px; background: rgba(245, 158, 11, 0.1); border: none; padding: 4px 10px; border-radius: 0;">
                        <span style="color: #f59e0b; font-size: 14px;">★</span>
                        <span id="modal-rating-val" style="color: #f59e0b; font-size: 0.85rem; font-weight: 800;">0.0</span>
                    </div>
                </div>
                <p id="modal-intro-text" style="color: #b9d4df; margin: 0 0 1.25rem; line-height: 1.6; font-size: 0.9rem;"></p>
            </div>
            <div id="modal-ratings-section" style="padding: 0 2rem 1.5rem;"></div>
        </div>

        <div id="modal-cart-section" style="padding: 1.25rem 2rem; background: rgba(8, 30, 39, 0.95); border-top: 1px solid rgba(83, 197, 224, 0.24);">
            <div class="modal-action-row">
                <div class="modal-action-buttons">
                    <button type="button" onclick="buyNowService()" class="modal-action-btn">
                        Proceed to Customization
                        <svg style="width: 1.2rem; height: 1.2rem;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"></path></svg>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var basePath = <?php echo json_encode($base_path); ?>;
var currentModalData = {};
var modalImages = [];
var currentModalImageIndex = 0;

function isVideoMedia(path) {
    return /\.(mp4|webm|mov|m4v)(?:[\?#].*)?$/i.test(String(path || ''));
}

function openServiceModal(id, name, category, images, link, is_service, price, stock, modalIntro, avgRating, reviewCount) {
    document.getElementById('modal-name').textContent = name;
    document.getElementById('modal-category').textContent = category;
    document.getElementById('modal-intro-text').textContent = modalIntro;
    
    // Handle images array
    modalImages = Array.isArray(images) ? images : [images];
    currentModalImageIndex = 0;
    
    // Setup carousel
    const carousel = document.getElementById('modal-image-carousel');
    carousel.innerHTML = '';
    
    modalImages.forEach((img, index) => {
        let mediaEl;
        if (isVideoMedia(img)) {
            mediaEl = document.createElement('video');
            mediaEl.src = img;
            mediaEl.muted = true;
            mediaEl.playsInline = true;
            mediaEl.autoplay = true;
            mediaEl.loop = true;
            mediaEl.preload = 'auto';
            mediaEl.style.background = '#f8fafc';
            mediaEl.style.opacity = '0';
            mediaEl.oncanplay = function() {
                this.play().catch(function(){});
                this.style.opacity = '1';
            };
        } else {
            mediaEl = document.createElement('img');
            mediaEl.src = img;
            mediaEl.alt = name;
        }
        mediaEl.style.cssText = 'position:absolute;top:0;left:' + (index === 0 ? '0' : '100%') + ';width:100%;height:100%;object-fit:cover;transition:left 0.4s ease-in-out;';
        mediaEl.className = 'modal-carousel-image';
        mediaEl.dataset.index = index;
        carousel.appendChild(mediaEl);
    });
    
    // Show/hide navigation
    const prevBtn = document.getElementById('modal-carousel-prev');
    const nextBtn = document.getElementById('modal-carousel-next');
    const counter = document.getElementById('modal-image-counter');
    
    if (modalImages.length > 1) {
        prevBtn.style.display = 'none'; // Hide on first image
        nextBtn.style.display = 'flex';
        counter.style.display = 'block';
        document.getElementById('modal-total-images').textContent = modalImages.length;
        document.getElementById('modal-current-image').textContent = '1';
    } else {
        prevBtn.style.display = 'none';
        nextBtn.style.display = 'none';
        counter.style.display = 'none';
    }
    
    const ratingVal = parseFloat(avgRating || 0).toFixed(1);
    document.getElementById('modal-rating-val').textContent = ratingVal;
    document.getElementById('modal-reviews-link').href = 'reviews.php?service_id=' + id;
    
    currentModalData = { id, name, link };
    
    // Load reviews
    loadModalReviews(id);
    
    const modal = document.getElementById('service-modal');
    const content = document.getElementById('service-modal-content');
    
    modal.style.display = 'flex';
    void modal.offsetWidth;
    modal.style.opacity = '1';
    modal.style.pointerEvents = 'auto';
    content.style.transform = 'translateY(0)';
    document.body.style.overflow = 'hidden';
}

function changeModalImage(direction) {
    const newIndex = currentModalImageIndex + direction;
    
    if (newIndex < 0 || newIndex >= modalImages.length) return;
    
    const images = document.querySelectorAll('.modal-carousel-image');
    const oldImg = images[currentModalImageIndex];
    const newImg = images[newIndex];
    
    // Position new image
    if (direction > 0) {
        newImg.style.left = '100%';
    } else {
        newImg.style.left = '-100%';
    }
    
    // Force reflow
    newImg.offsetHeight;
    
    // Animate
    if (direction > 0) {
        oldImg.style.left = '-100%';
    } else {
        oldImg.style.left = '100%';
    }
    newImg.style.left = '0';
    
    currentModalImageIndex = newIndex;
    
    // Update counter
    document.getElementById('modal-current-image').textContent = currentModalImageIndex + 1;
    
    // Update arrow visibility
    const prevBtn = document.getElementById('modal-carousel-prev');
    const nextBtn = document.getElementById('modal-carousel-next');
    prevBtn.style.display = currentModalImageIndex === 0 ? 'none' : 'flex';
    nextBtn.style.display = currentModalImageIndex === modalImages.length - 1 ? 'none' : 'flex';
}

function closeServiceModal() {
    const modal = document.getElementById('service-modal');
    const content = document.getElementById('service-modal-content');
    modal.style.opacity = '0';
    modal.style.pointerEvents = 'none';
    content.style.transform = 'translateY(20px)';
    setTimeout(() => {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }, 300);
}

function loadModalReviews(serviceId) {
    const container = document.getElementById('modal-ratings-section');
    container.innerHTML = '';

    fetch(basePath + '/public/api/modal_reviews.php?service_id=' + encodeURIComponent(serviceId))
        .then(r => r.json())
        .then(data => {
            const reviews = data.reviews || [];
            const avg = parseFloat(data.avg || 0);
            const count = parseInt(data.count || 0);

            const starSvg = (w, filled) =>
                `<svg width="${w}" height="${w}" fill="${filled ? '#ef4444' : '#d1d5db'}" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>`;

            let html = `<div style="background:rgba(0,28,36,0.95);border:1px solid rgba(83,197,224,0.16);border-radius:8px;padding:1.5rem;">`;
            html += `<h2 style="font-size:1.1rem;font-weight:700;color:#eaf6fb;margin:0 0 0.75rem;">Product Ratings</h2>`;

            if (reviews.length === 0) {
                html += `<div style="text-align:center;padding:3rem 1rem;color:#6b7280;">`;
                html += `<svg width="56" height="56" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>`;
                html += `<p style="font-size:1rem;font-weight:600;margin:0.75rem 0 0.25rem;">No Reviews Yet</p>`;
                html += `<p style="font-size:0.875rem;color:#9ca3af;">Be the first to review this product!</p>`;
                html += `</div>`;
            } else {
                html += `<div style="background:rgba(83,197,224,0.05);border:1px solid rgba(83,197,224,0.18);border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;">`;
                html += `<div style="display:flex;gap:2rem;align-items:center;flex-wrap:wrap;">`;
                html += `<div style="text-align:center;"><div style="font-size:3rem;font-weight:700;color:#ef4444;line-height:1;">${avg.toFixed(1)}</div>`;
                html += `<div style="font-size:0.875rem;color:#6b7280;margin-top:0.25rem;">out of 5</div>`;
                html += `<div style="display:flex;gap:2px;margin-top:0.5rem;justify-content:center;">${[1,2,3,4,5].map(i => starSvg(22, i <= Math.round(avg))).join('')}</div></div>`;
                html += `</div></div>`;

                reviews.forEach(rv => {
                    const name = ((rv.first_name || '') + ' ' + (rv.last_name || '')).trim();
                    const initial = (name[0] || '?').toUpperCase();
                    const rating = parseInt(rv.rating);
                    const date = rv.created_at ? rv.created_at.substring(0, 16) : '';
                    const msg = rv.message ? rv.message.replace(/</g,'&lt;').replace(/>/g,'&gt;') : '';

                    html += `<div style="padding:1.5rem;border-bottom:1px solid rgba(83,197,224,0.1);">`;
                    html += `<div style="display:flex;gap:1rem;">`;
                    html += `<div style="flex-shrink:0;"><div style="width:48px;height:48px;border-radius:50%;background:rgba(83,197,224,0.15);display:flex;align-items:center;justify-content:center;font-weight:600;color:#e0f2fe;">${initial}</div></div>`;
                    html += `<div style="flex:1;"><div style="font-weight:600;color:#eaf6fb;margin-bottom:0.25rem;">${name}</div>`;
                    html += `<div style="display:flex;gap:2px;margin-bottom:0.5rem;">${[1,2,3,4,5].map(i => starSvg(16, i <= rating)).join('')}</div>`;
                    html += `<div style="font-size:0.875rem;color:#6b7280;margin-bottom:0.5rem;">${date}</div>`;
                    
                    if (msg) html += `<div style="color:#b9d4df;line-height:1.6;margin-bottom:0.75rem;">${msg}</div>`;
                    
                    // Photos
                    if (rv.images && rv.images.length > 0) {
                        html += `<div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap:8px; margin-bottom:0.75rem;">`;
                        rv.images.forEach(img => {
                            html += `<div style="aspect-ratio:1; border-radius:6px; overflow:hidden; border:1px solid rgba(83,197,224,0.12);">`;
                            html += `<img src="${img.image_path}" style="width:100%; height:100%; object-fit:cover; cursor:pointer;" onclick="window.open(this.src, '_blank')">`;
                            html += `</div>`;
                        });
                        html += `</div>`;
                    }
                    
                    // Video
                    if (rv.video_path) {
                        html += `<div style="margin-bottom:0.75rem; max-width:260px;">`;
                        html += `<div style="position:relative; width:100%; aspect-ratio:16/9; border-radius:8px; overflow:hidden; border:1px solid rgba(83,197,224,0.2);">`;
                        html += `<video src="${rv.video_path}#t=1" controls playsinline preload="metadata" style="width:100%; height:100%; object-fit:cover;background:#f8fafc;" onloadedmetadata="try{this.currentTime=1;}catch(e){}"></video>`;
                        html += `</div></div>`;
                    }

                    html += `</div></div></div>`;
                });

                if (count > reviews.length) {
                    html += `<div style="text-align:center;padding:1rem;"><a href="reviews.php?service_id=${serviceId}" style="font-size:0.875rem;color:#3b82f6;font-weight:600;text-decoration:none;">See all ${count} reviews →</a></div>`;
                }
            }

            html += `</div>`;
            container.innerHTML = html;
        })
        .catch(() => { container.innerHTML = ''; });
}


function buyNowService() {
    if (currentModalData.link) {
        window.location.href = currentModalData.link;
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
