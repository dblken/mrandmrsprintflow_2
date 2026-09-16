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
$detail = $read('customer/order_service_dynamic.php');
$footer = $read('includes/footer.php');
$functions = $read('includes/functions.php');
$auth = $read('includes/auth.php');
$catalogPerf = $read('includes/customer_catalog_perf.php');
$orderReview = $read('customer/order_review.php');
$catalogNavJs = $read('public/assets/js/customer-catalog-nav.js');

$assert(str_contains($optimizer, 'pf_catalog_image_tag'), 'image optimizer exposes catalog image helper');
$assert(str_contains($optimizer, '-pf'), 'derivatives keep originals and use separate WebP filenames');
$assert(str_contains($services, 'pf_catalog_image_tag'), 'services cards use optimized responsive images');
$assert(str_contains($services, "define('PF_CUSTOMER_CATALOG_NAV', true)"), 'services page opts into catalog navigation cache policy');
$assert(str_contains($services, 'printflow_catalog_service_card_stats_map'), 'services listing uses shared review stats helper');
$assert(str_contains($services, 'service_ids_with_field_config'), 'services listing batches field-config lookups');
$assert(str_contains($products, "define('PF_CUSTOMER_CATALOG_NAV', true)"), 'products page opts into catalog navigation cache policy');
$assert(str_contains($products, 'pf_catalog_image_tag'), 'products cards use optimized responsive images');
$assert(str_contains($auth, 'PF_CUSTOMER_CATALOG_NAV'), 'auth uses softer cache headers on catalog pages');
$assert(str_contains($catalogPerf, 'printflow_catalog_service_card_stats_map'), 'catalog service stats are batched');
$assert(str_contains($catalogPerf, 'printflow_catalog_product_card_stats_map'), 'catalog product stats are batched');
$assert(str_contains($products, 'printflow_catalog_product_card_stats_map'), 'products listing uses batched card stats helper');
$assert(!str_contains($products, 'as avg_rating'), 'products listing no longer uses correlated avg_rating subquery');
$assert(!str_contains($services, 'printflow_get_service_review_stats'), 'services listing no longer calls per-card review stats');
$assert(!str_contains($services, 'printflow_service_units_sold'), 'services listing no longer calls per-card sold stats');
$assert(str_contains($orderReview, 'review-order-entry--service .review-total-value'), 'order review allows full To Be Discussed text');
$assert(str_contains($catalogNavJs, 'pf-catalog-nav-page'), 'catalog nav script only runs on catalog pages');
$assert(str_contains($products, 'pf_normalize_service_image_path'), 'products use normalized image paths');
$assert(str_contains($detail, 'printflow_attach_review_media'), 'service detail batches review media queries');
$assert(str_contains($detail, 'LIMIT ? OFFSET ?'), 'service detail paginates reviews in SQL');
$assert(str_contains($detail, 'pf-lazy-carousel'), 'service detail carousel lazy-loads off-screen images');
$assert(str_contains($footer, 'filemtime(__DIR__ . \'/../public/assets/js/pwa.js\')'), 'pwa.js uses filemtime cache busting');
$assert(str_contains($functions, 'printflow_attach_review_media'), 'shared review media batch helper exists');

echo "Customer catalog performance regression test passed.\n";
