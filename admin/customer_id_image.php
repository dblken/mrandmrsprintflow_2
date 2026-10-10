<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';

require_role(['Admin', 'Manager']);

$customerId = (int)($_GET['id'] ?? 0);
if ($customerId <= 0) {
    http_response_code(404);
    exit;
}

$viewerBranch = printflow_branch_filter_for_user();
if (get_user_type() !== 'Admin' && $viewerBranch) {
    [$custWhere, $custTypes, $custParams] = branch_customers_belong_where_sql((int)$viewerBranch, 'c');
    $allowed = db_query(
        "SELECT c.customer_id FROM customers c WHERE c.customer_id = ?" . $custWhere . " LIMIT 1",
        'i' . $custTypes,
        array_merge([$customerId], $custParams)
    );
    if (empty($allowed)) {
        http_response_code(404);
        exit;
    }
}

$rows = db_query(
    'SELECT id_image FROM customers WHERE customer_id = ? LIMIT 1',
    'i',
    [$customerId]
);
$storedName = trim((string)($rows[0]['id_image'] ?? ''));
$filename = basename(str_replace('\\', '/', $storedName));
$imagePath = __DIR__ . '/../uploads/ids/' . $filename;

if ($filename === '' || !is_file($imagePath)) {
    http_response_code(404);
    exit;
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($imagePath);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($imagePath));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: private, no-store, max-age=0');
readfile($imagePath);