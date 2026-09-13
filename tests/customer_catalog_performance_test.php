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

$assert(str_contains($optimizer, 'pf_catalog_image_tag'), 'image optimizer exposes catalog image helper');
$assert(str_contains($optimizer, '-pf'), 'derivatives keep originals and use separate WebP filenames');
$assert(str_contains($services, 'pf_catalog_image_tag'), 'services cards use optimized responsive images');
$assert(!str_contains($services, 'printflow_get_service_review_stats($service_row'), 'services page avoids duplicate review stats queries');
$assert(str_contains($products, 'pf_catalog_image_tag'), 'products cards use optimized responsive images');
$assert(str_contains($products, 'pf_normalize_service_image_path'), 'products use normalized image paths');
$assert(str_contains($detail, 'printflow_attach_review_media'), 'service detail batches review media queries');
$assert(str_contains($detail, 'LIMIT ? OFFSET ?'), 'service detail paginates reviews in SQL');
$assert(str_contains($detail, 'pf-lazy-carousel'), 'service detail carousel lazy-loads off-screen images');
$assert(str_contains($footer, 'filemtime(__DIR__ . \'/../public/assets/js/pwa.js\')'), 'pwa.js uses filemtime cache busting');
$assert(str_contains($functions, 'printflow_attach_review_media'), 'shared review media batch helper exists');

echo "Customer catalog performance regression test passed.\n";
