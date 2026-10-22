<?php
// Load config for environment detection
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/../includes/shop_config.php';

$base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
$logo_version = rawurlencode(printflow_logo_version());
$png_logo_src = $base_path . '/public/app-icon.php?v=' . $logo_version;
header('Content-Type: application/json');

$manifest_icons = [
    [
        'src' => $png_logo_src,
        'sizes' => '512x512',
        'type' => 'image/png',
        'purpose' => 'any maskable'
    ],
];

$icon_sizes = ['72x72', '96x96', '128x128', '144x144', '152x152', '192x192', '384x384', '512x512'];
foreach ($icon_sizes as $size) {
    $manifest_icons[] = [
        'src' => $png_logo_src,
        'sizes' => $size,
        'type' => 'image/png',
        'purpose' => 'any maskable'
    ];
}

$manifest = [
    'name' => 'PrintFlow - Printing Shop',
    'short_name' => 'PrintFlow',
    'description' => 'Pickup-only printing shop for tarpaulins, t-shirts, stickers, and custom designs',
    'start_url' => $base_path . '/',
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => '#4F46E5',
    'orientation' => 'portrait-primary',
    'icons' => $manifest_icons,
    'categories' => ['business', 'productivity'],
    'screenshots' => []
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
