<?php
/**
 * Download all design files for one order item as a ZIP (authorized).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/order_item_design_files.php';
require_once __DIR__ . '/../includes/branch_context.php';

$user_id = get_user_id();
$is_staff = is_staff() || is_admin() || is_manager();
$orderItemId = max(0, (int)($_GET['order_item_id'] ?? 0));

if ($orderItemId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid order item.';
    exit;
}

if (!printflow_order_item_design_file_assert_order_item_access($orderItemId, (int)$user_id, $is_staff)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unauthorized.';
    exit;
}

if (!class_exists('ZipArchive')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ZIP download is not available on this server.';
    exit;
}

$apiFiles = printflow_order_item_design_files_list_for_item($orderItemId, false);
if ($apiFiles === []) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'No design files found for this item.';
    exit;
}

$zip = new ZipArchive();
$tmp = tempnam(sys_get_temp_dir(), 'pf_design_zip_');
if ($tmp === false) {
    http_response_code(500);
    echo 'Could not create archive.';
    exit;
}
@unlink($tmp);
$zipPath = $tmp . '.zip';

if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo 'Could not create archive.';
    exit;
}

$usedNames = [];
$seenFileIds = [];
$seenPhysical = [];
$added = 0;
foreach ($apiFiles as $file) {
    if (!is_array($file)) {
        continue;
    }
    $fileId = (int)($file['file_id'] ?? 0);
    if ($fileId > 0) {
        if (isset($seenFileIds[$fileId])) {
            continue;
        }
        $seenFileIds[$fileId] = true;
    }
    if (function_exists('printflow_order_item_design_file_physical_identity_key')) {
        $physicalKey = printflow_order_item_design_file_physical_identity_key($file);
        if ($physicalKey !== '' && isset($seenPhysical[$physicalKey])) {
            continue;
        }
        if ($physicalKey !== '') {
            $seenPhysical[$physicalKey] = true;
        }
    }
    $displayName = trim((string)($file['display_name'] ?? 'design'));
    $displayName = preg_replace('/[\x00-\x1F\x7F"\\\\]+/u', '_', basename($displayName)) ?: 'design';
    $storagePath = trim((string)($file['storage_path'] ?? ''));
    $disk = ($storagePath !== '' && function_exists('printflow_resolve_order_upload_disk_path'))
        ? printflow_resolve_order_upload_disk_path($storagePath)
        : null;
    if ($disk === null || !is_file($disk) || !is_readable($disk)) {
        continue;
    }
    $entryName = sprintf('%02d-%s', (int)($file['upload_sort'] ?? $added), $displayName);
    if (isset($usedNames[$entryName])) {
        $entryName = sprintf('%02d-file-%d-%s', (int)($file['upload_sort'] ?? $added), $fileId, $displayName);
    }
    $usedNames[$entryName] = true;
    if ($zip->addFile($disk, $entryName)) {
        $added++;
    }
}

$zip->close();

if ($added === 0) {
    @unlink($zipPath);
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Files are unavailable.';
    exit;
}

$zipFilename = 'order-item-' . $orderItemId . '-designs.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
header('Content-Length: ' . (string)filesize($zipPath));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
readfile($zipPath);
@unlink($zipPath);
exit;
