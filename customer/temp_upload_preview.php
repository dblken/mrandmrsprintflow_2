<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_ui_helper.php';

require_role('Customer');

$item_key = trim((string)($_GET['item'] ?? ''));
$field = trim((string)($_GET['field'] ?? 'design'));

if ($item_key === '' || !isset($_SESSION['cart'][$item_key]) || !is_array($_SESSION['cart'][$item_key])) {
    http_response_code(404);
    exit;
}

$item = $_SESSION['cart'][$item_key];
$resolved = pf_order_ui_cart_temp_disk_path($item, $field === 'reference' ? 'reference' : 'design');
if ($resolved === null) {
    http_response_code(404);
    exit;
}

$path = $resolved['path'];
$mime = $resolved['mime'];
if ($mime === '' && function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $mime = (string)finfo_file($finfo, $path);
        finfo_close($finfo);
    }
}

if (stripos($mime, 'image/') !== 0) {
    http_response_code(415);
    exit;
}

$filename = basename((string)($resolved['name'] !== '' ? $resolved['name'] : 'preview'));

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');

readfile($path);
