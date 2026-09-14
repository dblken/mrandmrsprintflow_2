<?php
/**
 * Pre-generate WebP catalog derivatives for existing product/service images.
 * Safe to run multiple times; originals are never modified.
 *
 * Usage: php tools/pf_warm_catalog_images.php
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/image_optimizer.php';

$paths = [];

$serviceRows = db_query("SELECT display_image, hero_image FROM services WHERE status = 'Activated'") ?: [];
foreach ($serviceRows as $row) {
    foreach ([(string)($row['display_image'] ?? ''), (string)($row['hero_image'] ?? '')] as $csv) {
        foreach (array_filter(array_map('trim', explode(',', $csv))) as $path) {
            $paths[$path] = $path;
        }
    }
}

$productRows = db_query("SELECT photo_path, product_image FROM products WHERE status = 'Activated'") ?: [];
foreach ($productRows as $row) {
    foreach ([(string)($row['photo_path'] ?? ''), (string)($row['product_image'] ?? '')] as $path) {
        if (trim($path) !== '') {
            $paths[$path] = $path;
        }
    }
}

$generated = 0;
$skipped = 0;

foreach ($paths as $url) {
    $local = pf_image_optimizer_local_from_url($url);
    if ($local === null || !pf_image_optimizer_is_raster_image($local)) {
        $skipped++;
        continue;
    }
    pf_image_optimizer_generate_all($local);
    $generated++;
}

echo "Catalog image warm-up complete.\n";
echo "Processed: {$generated}\n";
echo "Skipped: {$skipped}\n";
