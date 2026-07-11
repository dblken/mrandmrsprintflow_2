<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/barcode.php';

if (!has_role(['Admin', 'Manager', 'Staff'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unauthorized';
    exit;
}

$sku = printflow_barcode_clean_value((string)($_GET['sku'] ?? ''));
if ($sku === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'SKU is required';
    exit;
}

try {
    $svg = printflow_barcode_svg($sku, 2, 72);
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    echo $svg;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Barcode generation failed. Install dependencies with composer install, or enable PHP gd and rerun composer install.';
}
