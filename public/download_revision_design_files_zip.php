<?php
/**
 * Download all replacement design files for one revision request (authorized).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/revision_workflow.php';
require_once __DIR__ . '/../includes/order_item_design_files.php';
require_once __DIR__ . '/../includes/branch_context.php';

$user_id = get_user_id();
$is_staff = is_staff() || is_admin() || is_manager();
$revisionRequestId = max(0, (int)($_GET['revision_request_id'] ?? 0));
$orderItemId = max(0, (int)($_GET['order_item_id'] ?? 0));

if ($revisionRequestId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid revision request.';
    exit;
}

$rows = db_query(
    'SELECT order_id, customer_id FROM order_revision_requests WHERE revision_request_id = ? LIMIT 1',
    'i',
    [$revisionRequestId]
) ?: [];
if (empty($rows[0])) {
    http_response_code(404);
    echo 'Revision request not found.';
    exit;
}
$orderId = (int)($rows[0]['order_id'] ?? 0);
$customerId = (int)($rows[0]['customer_id'] ?? 0);
if (!$is_staff && $customerId !== (int)$user_id) {
    http_response_code(403);
    echo 'Unauthorized.';
    exit;
}
if ($is_staff && $orderId > 0) {
    printflow_assert_order_branch_access($orderId);
}

$apiFiles = printflow_revision_design_files_list_for_request($revisionRequestId, $orderItemId);
if ($apiFiles === []) {
    http_response_code(404);
    echo 'No replacement design files found.';
    exit;
}

if (!class_exists('ZipArchive')) {
    http_response_code(503);
    echo 'ZIP download is not available on this server.';
    exit;
}

$zip = new ZipArchive();
$tmp = tempnam(sys_get_temp_dir(), 'pf_rev_design_zip_');
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
    echo 'Files are unavailable.';
    exit;
}

$zipFilename = 'revision-' . $revisionRequestId . '-designs.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
header('Content-Length: ' . (string)filesize($zipPath));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
readfile($zipPath);
@unlink($zipPath);
exit;
