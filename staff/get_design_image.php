<?php
/**
 * Serve design image from BLOB or persisted file path.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/design_resolver.php';
require_once __DIR__ . '/../includes/branch_context.php';

require_role(['Admin', 'Staff', 'Customer']);

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    die('ID required');
}

$res = db_query("
    SELECT oi.order_item_id, oi.order_id, oi.design_file, oi.design_image_name, oi.design_image_mime,
           IFNULL(LENGTH(oi.design_image), 0) AS design_image_bytes,
           oi.customization_data, o.customer_id, o.branch_id
    FROM order_items oi
    LEFT JOIN orders o ON o.order_id = oi.order_id
    WHERE oi.order_item_id = ?
", 'i', [$id]);
if (!$res) {
    header('Content-Type: image/png');
    readfile(__DIR__ . '/../public/assets/images/services/default.png');
    exit;
}

$row = $res[0];
$userType = get_user_type();
$userId = get_user_id();

if ($userType === 'Customer') {
    if ((int)($row['customer_id'] ?? 0) !== (int)$userId) {
        http_response_code(403);
        exit('Forbidden');
    }
} else {
    $branchId = printflow_branch_filter_for_user();
    if ($branchId !== null && (int)($row['branch_id'] ?? 0) !== (int)$branchId) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$design = getOrderDesignImage($row, ['debug' => true]);

if (!empty($design['disk_path']) && is_file($design['disk_path'])) {
    $mime = mime_content_type($design['disk_path']) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    readfile($design['disk_path']);
    exit;
}

if (!empty($row['design_image_bytes'])) {
    $blobRows = db_query(
        'SELECT design_image FROM order_items WHERE order_item_id = ? LIMIT 1',
        'i',
        [$id]
    ) ?: [];
    if (!empty($blobRows[0]['design_image'])) {
        header('Content-Type: ' . ($row['design_image_mime'] ?: 'image/png'));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo $blobRows[0]['design_image'];
        exit;
    }
}

header('Content-Type: text/plain; charset=UTF-8');
http_response_code(404);
echo 'Missing file: ' . ($design['missing_path'] ?? $design['stored_path'] ?? '(unknown)');
