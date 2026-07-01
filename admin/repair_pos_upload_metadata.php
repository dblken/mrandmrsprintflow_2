<?php
/**
 * Backfill POS upload metadata for older orders so the staff customization
 * modal can render uploaded designs and references reliably.
 *
 * Run from CLI:
 *   php admin/repair_pos_upload_metadata.php
 *
 * Or from the browser as an Admin.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_items_persistence.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli && !has_role(['Admin'])) {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}

printflow_ensure_order_items_columns();

function pf_fix_meta_json(?string $json, ?string $path, ?string $name, ?string $mime, string $prefix): ?string {
    $data = [];
    if (trim((string)$json) !== '') {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    if ($path !== null && $path !== '') {
        $data[$prefix . '_path'] = $path;
        $data[$prefix . '_file'] = $path;
    }
    if ($name !== null && $name !== '') {
        $data[$prefix . '_name'] = $name;
        $data[$prefix] = $data[$prefix] ?? $name;
    }
    if ($mime !== null && $mime !== '') {
        $data[$prefix . '_mime'] = $mime;
    }

    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function pf_extension_for_mime(string $mime, string $fallbackName = ''): string {
    $mime = strtolower(trim($mime));
    if ($mime === '') {
        $ext = strtolower(pathinfo($fallbackName, PATHINFO_EXTENSION));
        return $ext !== '' ? $ext : 'jpg';
    }
    return match (true) {
        str_starts_with($mime, 'image/jpeg') => 'jpg',
        str_starts_with($mime, 'image/png') => 'png',
        str_starts_with($mime, 'image/gif') => 'gif',
        str_starts_with($mime, 'image/webp') => 'webp',
        str_starts_with($mime, 'image/bmp') => 'bmp',
        str_starts_with($mime, 'image/svg') => 'svg',
        str_contains($mime, 'pdf') => 'pdf',
        default => 'bin',
    };
}

$rows = db_query(
    "SELECT oi.order_item_id, oi.order_id, oi.design_image, oi.design_image_mime, oi.design_image_name, oi.design_file, oi.reference_image_file, oi.customization_data, c.customization_details
     FROM order_items oi
     LEFT JOIN customizations c ON c.order_id = oi.order_id AND c.order_item_id = oi.order_item_id
     INNER JOIN orders o ON o.order_id = oi.order_id
     WHERE LOWER(TRIM(COALESCE(o.order_source, ''))) IN ('pos', 'pos_draft')
       AND (
            IFNULL(LENGTH(oi.design_image), 0) > 0
            OR TRIM(COALESCE(oi.design_file, '')) <> ''
            OR TRIM(COALESCE(oi.reference_image_file, '')) <> ''
       )
     ORDER BY oi.order_item_id ASC"
) ?: [];

$stats = [
    'scanned' => count($rows),
    'updated_order_items' => 0,
    'updated_customizations' => 0,
    'wrote_files' => 0,
    'normalised_paths' => 0,
];

foreach ($rows as $row) {
    $orderItemId = (int)($row['order_item_id'] ?? 0);
    if ($orderItemId <= 0) {
        continue;
    }

    $designFile = trim((string)($row['design_file'] ?? ''));
    $designName = trim((string)($row['design_image_name'] ?? ''));
    $designMime = trim((string)($row['design_image_mime'] ?? ''));
    $referenceFile = trim((string)($row['reference_image_file'] ?? ''));
    $designBlob = $row['design_image'] ?? null;

    $newDesignFile = $designFile !== '' ? printflow_normalize_order_upload_web_path($designFile) : '';
    if ($newDesignFile !== '' && $newDesignFile !== $designFile) {
        $stats['normalised_paths']++;
        $designFile = $newDesignFile;
    }

    if ($designFile === '' && is_string($designBlob) && $designBlob !== '') {
        $safeName = $designName !== '' ? $designName : ('design_' . $orderItemId);
        $ext = pf_extension_for_mime($designMime, $safeName);
        if (pathinfo($safeName, PATHINFO_EXTENSION) === '') {
            $safeName .= '.' . $ext;
        }
        $targetWebPath = printflow_write_order_upload_file($designBlob, $safeName, $orderItemId, 'design');
        if ($targetWebPath !== null) {
            $designFile = $targetWebPath;
            $stats['wrote_files']++;
        }
    }

    if ($referenceFile !== '') {
        $referenceFile = printflow_normalize_order_upload_web_path($referenceFile);
    }

    $customizationData = pf_fix_meta_json(
        (string)($row['customization_data'] ?? ''),
        $designFile !== '' ? $designFile : null,
        $designName !== '' ? $designName : null,
        $designMime !== '' ? $designMime : null,
        'design_upload'
    );

    $customizationDetails = pf_fix_meta_json(
        (string)($row['customization_details'] ?? ''),
        $designFile !== '' ? $designFile : null,
        $designName !== '' ? $designName : null,
        $designMime !== '' ? $designMime : null,
        'design_upload'
    );

    if ($referenceFile !== '') {
        $customizationData = pf_fix_meta_json($customizationData, $referenceFile, basename($referenceFile), null, 'reference_upload');
        $customizationDetails = pf_fix_meta_json($customizationDetails, $referenceFile, basename($referenceFile), null, 'reference_upload');
    }

    $updated = false;
    if ($designFile !== '' || $referenceFile !== '') {
        db_execute(
            "UPDATE order_items
             SET design_file = ?,
                 design_image_name = COALESCE(NULLIF(?, ''), design_image_name),
                 design_image_mime = COALESCE(NULLIF(?, ''), design_image_mime),
                 reference_image_file = COALESCE(NULLIF(?, ''), reference_image_file),
                 customization_data = COALESCE(?, customization_data)
             WHERE order_item_id = ?",
            'sssssi',
            [
                $designFile !== '' ? $designFile : (string)($row['design_file'] ?? ''),
                $designName,
                $designMime,
                $referenceFile !== '' ? $referenceFile : null,
                $customizationData,
                $orderItemId,
            ]
        );
        $stats['updated_order_items']++;
        $updated = true;
    }

    if ($customizationDetails !== null) {
        db_execute(
            "UPDATE customizations
             SET customization_details = COALESCE(?, customization_details), updated_at = NOW()
             WHERE order_id = ? AND order_item_id = ?",
            'sii',
            [$customizationDetails, (int)($row['order_id'] ?? 0), $orderItemId]
        );
        $stats['updated_customizations']++;
        $updated = true;
    }

    if ($updated && $isCli) {
        echo "Fixed order_item #{$orderItemId}\n";
    }
}

$output = [
    'success' => true,
    'stats' => $stats,
];

if ($isCli) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    header('Content-Type: application/json');
    echo json_encode($output, JSON_UNESCAPED_SLASHES);
}
