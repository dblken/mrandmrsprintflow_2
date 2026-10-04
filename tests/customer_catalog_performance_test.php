<?php

$read = static function (string $relativePath): string {
    $path = __DIR__ . '/../' . ltrim($relativePath, '/');
    if (!is_file($path)) {
        throw new RuntimeException('Missing file: ' . $relativePath);
    }
    return (string)file_get_contents($path);
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

$optimizer = $read('includes/image_optimizer.php');
$services = $read('customer/services.php');
$products = $read('customer/products.php');
$productGroups = $read('includes/product_catalog_groups.php');
$detail = $read('customer/order_service_dynamic.php');
$footer = $read('includes/footer.php');
$navHeader = $read('includes/nav-header.php');
$functions = $read('includes/functions.php');
$auth = $read('includes/auth.php');
$catalogPerf = $read('includes/customer_catalog_perf.php');
$orderReview = $read('customer/order_review.php');
$catalogNavJs = $read('public/assets/js/customer-catalog-nav.js');
$orderCreate = $read('customer/order_create.php');
$servicesDetail = $read('customer/order_service_dynamic.php');

$assert(str_contains($optimizer, 'pf_catalog_image_tag'), 'image optimizer exposes catalog image helper');
$assert(str_contains($optimizer, '-pf'), 'derivatives keep originals and use separate WebP filenames');
$assert(str_contains($services, 'pf_catalog_image_tag'), 'services cards use optimized responsive images');
$assert(str_contains($services, "define('PF_CUSTOMER_CATALOG_NAV', true)"), 'services page opts into catalog navigation cache policy');
$assert(str_contains($services, 'printflow_catalog_service_card_stats_map'), 'services listing uses shared review stats helper');
$assert(!str_contains($services, 'service_ids_with_field_config') && !str_contains($services, 'init_service_field_config'), 'services listing avoids field-config lookups and writes for unrelated cards');
$assert(str_contains($products, "define('PF_CUSTOMER_CATALOG_NAV', true)"), 'products page opts into catalog navigation cache policy');
$assert(str_contains($products, 'pf_catalog_image_tag'), 'products cards use optimized responsive images');
$assert(str_contains($auth, 'PF_CUSTOMER_CATALOG_NAV'), 'auth uses softer cache headers on catalog pages');
$assert(str_contains($navHeader, 'setTimeout(pollCart, 30000)'), 'cart badge background polling is limited to a lower-frequency refresh');
$assert(str_contains($navHeader, 'if (cartPollFlight) return cartPollFlight;'), 'cart badge refresh prevents duplicate in-flight requests');
$assert(str_contains($navHeader, "document.addEventListener('visibilitychange', refreshCartOnReturn)"), 'cart badge refreshes when a hidden page becomes visible');
$assert(str_contains($catalogPerf, 'printflow_catalog_service_card_stats_map'), 'catalog service stats are batched');
$assert(str_contains($catalogPerf, 'printflow_catalog_product_card_stats_map'), 'catalog product stats are batched');
$assert(!str_contains($catalogPerf, 'INNER JOIN (" . implode(\' UNION ALL \', $serviceIdRows)'), 'service sold stats do not cross-join every order item to every service ID');
$assert(!str_contains($catalogPerf, 'INNER JOIN reviews r ON (" . implode(\' OR \', $reviewWhere)'), 'service review stats do not use the alias cross-product with correlated order-item lookups');
$assert(str_contains($catalogPerf, 'SELECT oi.order_item_id, oi.quantity, o.reference_id, o.order_type, oi.customization_data'), 'service sold stats scan eligible order rows once');
$assert(str_contains($catalogPerf, "' FROM reviews r WHERE ' . implode(' OR ', \$reviewWhere)"), 'service card ratings filter candidate reviews in SQL');
$assert(!str_contains($catalogPerf, "SELECT ' . implode(', ', \$reviewSelect) . ' FROM reviews'"), 'service card ratings do not load the full review table');
$assert(str_contains($productGroups, 'SELECT p.product_id, p.name, p.category, p.price'), 'product cards select only fields used by the listing');
$assert(str_contains($productGroups, 'ORDER BY p.name COLLATE utf8mb4_unicode_ci ASC, p.product_id ASC LIMIT ?'), 'product catalog candidates are sorted and bounded in SQL');
$assert(str_contains($productGroups, 'SELECT COUNT(*) AS total'), 'product catalog totals use a count query instead of loading every product row');
$assert(str_contains($services, 'SELECT s.service_id, s.name, s.category, s.display_image, s.hero_image'), 'services listing selects only catalog-card columns');
$assert(str_contains($products, 'printflow_catalog_product_card_stats_map'), 'products listing uses batched card stats helper');
$assert(!str_contains($products, 'as avg_rating'), 'products listing no longer uses correlated avg_rating subquery');
$assert(!str_contains($services, 'printflow_get_service_review_stats'), 'services listing no longer calls per-card review stats');
$assert(!str_contains($services, 'printflow_service_units_sold'), 'services listing no longer calls per-card sold stats');
$assert(str_contains($orderReview, 'review-order-entry--service .review-total-value'), 'order review allows full To Be Discussed text');
$assert(str_contains($catalogNavJs, 'pf-catalog-nav-page'), 'catalog nav script only runs on catalog pages');
$assert(str_contains($catalogNavJs, 'pf-nav-skeleton-grid') && str_contains($catalogNavJs, 'pf-nav-skeleton-detail'), 'catalog navigation provides listing and detail skeleton layouts');
$assert(str_contains($catalogNavJs, "main.setAttribute('aria-busy', 'true')"), 'navigation skeleton exposes accessible busy state');
$assert(str_contains($catalogNavJs, 'pf-nav-skeleton-retry'), 'slow page navigation exposes a retry action');
$assert(str_contains($catalogNavJs, "event.key !== 'Enter' && event.key !== ' '"), 'keyboard card navigation also starts the skeleton state');
$assert(str_contains($detail, '$pf_catalog_nav_page = true;') && str_contains($orderCreate, '$pf_catalog_nav_page = true;'), 'service and product order pages enable catalog transition skeletons');
$assert(str_contains($detail, 'init_service_field_config($service_id'), 'legacy service form config initializes only after that service is selected');
$assert(str_contains($servicesDetail, 'COALESCE(price, 0) AS base_price'), 'service order page maps the schema price column to its base-price view');
$assert(str_contains($servicesDetail, "db_table_has_column('services', 'video_url')") && str_contains($servicesDetail, 'NULL AS video_url'), 'service order page tolerates deployments without the optional video column');
$assert(str_contains($servicesDetail, 'WHERE service_id = ? AND status = \'Activated\'') && !str_contains($servicesDetail, 'SELECT * FROM services'), 'service order page keeps the selected active service lookup narrow');
$assert(str_contains($servicesDetail, '$has_service_field_config = service_has_field_config($service_id);') && str_contains($servicesDetail, 'if (!$has_service_field_config)'), 'service order page reuses field-config presence for configured services');
$assert(str_contains($servicesDetail, '(string)($service[\'name\'] ?? \'\')') && str_contains($servicesDetail, 'service_order_get_page_stats('), 'service order page passes its already-loaded service name to page stats');
$assert(str_contains($read('includes/service_order_helper.php'), 'printflow_service_units_sold($s_id, $s_name)'), 'service page stats reuse selected service metadata for sold counts');
$assert(str_contains($navHeader, '@media (min-width: 768px) and (max-width: 1023px)') && str_contains($navHeader, 'body[data-user-type="Customer"] #main-header .pf-header-mid'), 'customer Services and Products navigation remains visible across the tablet breakpoint');
$assert(str_contains($navHeader, 'body[data-user-type="Customer"] #main-header .pf-burger-btn') && str_contains($navHeader, 'display: none !important;'), 'tablet customer header avoids a duplicate burger while retaining mobile navigation');
$assert(str_contains($orderCreate, 'SELECT product_id, name, price, category, photo_path, product_image, description') && !str_contains($orderCreate, 'SELECT * FROM products'), 'product order page selects only fields used by the selected product');
$assert(str_contains($products, 'pf_normalize_service_image_path'), 'products use normalized image paths');
$assert(str_contains($detail, 'printflow_attach_review_media'), 'service detail batches review media queries');
$assert(str_contains($detail, 'LIMIT ? OFFSET ?'), 'service detail paginates reviews in SQL');
$assert(str_contains($detail, '$total_reviews = $review_count;') && !str_contains($detail, 'COUNT(DISTINCT r.id) AS total_reviews'), 'service detail reuses the already-computed review count for pagination');
$assert(!str_contains($detail, 'AVG(r.rating) AS avg_rating'), 'service detail avoids recomputing the review average');
$assert(str_contains($detail, 'pf-lazy-carousel'), 'service detail carousel lazy-loads off-screen images');
$assert(str_contains($footer, 'filemtime(__DIR__ . \'/../public/assets/js/pwa.js\')'), 'pwa.js uses filemtime cache busting');
$assert(str_contains($functions, 'printflow_attach_review_media'), 'shared review media batch helper exists');

echo "Customer catalog performance regression test passed.\n";
