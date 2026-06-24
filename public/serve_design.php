<?php
/**
 * Serve Design Image / File
 * PrintFlow - Printing Shop PWA
 * Serves design images from either the database (BLOB) or the filesystem.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Base directories used by older and newer upload code.
$htdocs_root = realpath(__DIR__ . '/../../');
$printflow_root = realpath(__DIR__ . '/..');
$default_fallback_image = __DIR__ . '/assets/uploads/profiles/default.png';

function pf_serve_design_no_cache_headers(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
}

function pf_serve_design_emit_blob(string $blob, string $mime = 'application/octet-stream', string $filename = 'design'): void {
    pf_serve_design_no_cache_headers();
    header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . basename($filename) . '"');
    echo $blob;
    exit;
}

function pf_serve_design_emit_file(string $path, string $mime = '', string $filename = ''): void {
    pf_serve_design_no_cache_headers();
    $resolvedMime = $mime !== '' ? $mime : (mime_content_type($path) ?: 'application/octet-stream');
    header('Content-Type: ' . $resolvedMime);
    header('Content-Disposition: inline; filename="' . basename($filename !== '' ? $filename : $path) . '"');
    readfile($path);
    exit;
}

function pf_serve_design_emit_fallback(string $reason = ''): void {
    global $default_fallback_image;

    if (is_file($default_fallback_image)) {
        pf_serve_design_emit_file($default_fallback_image, 'image/png', 'default.png');
    }

    http_response_code(200);
    pf_serve_design_no_cache_headers();
    header('Content-Type: image/svg+xml; charset=UTF-8');
    $label = $reason !== '' ? htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') : 'Image unavailable';
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="240" viewBox="0 0 320 240">'
        . '<rect width="320" height="240" rx="16" fill="#f8fafc"/>'
        . '<rect x="24" y="24" width="272" height="192" rx="12" fill="#e2e8f0"/>'
        . '<text x="160" y="118" text-anchor="middle" font-family="Arial, sans-serif" font-size="16" fill="#475569">'
        . $label
        . '</text>'
        . '</svg>';
    exit;
}

function pf_serve_design_file_candidates(?string $storedPath): array {
    global $htdocs_root, $printflow_root;

    $path = trim((string)$storedPath);
    if ($path === '') {
        return [];
    }

    $path = str_replace('\\', '/', $path);
    $pathOnly = parse_url($path, PHP_URL_PATH);
    if (is_string($pathOnly) && $pathOnly !== '') {
        $path = $pathOnly;
    }

    $basePath = '';
    if (defined('BASE_PATH')) {
        $basePath = trim((string)BASE_PATH);
    } elseif (function_exists('pf_app_base_path')) {
        $basePath = trim((string)pf_app_base_path());
    }
    $basePath = '/' . trim($basePath, '/');
    if ($basePath === '/') {
        $basePath = '';
    }

    $variants = [$path];
    if ($basePath !== '' && substr($path, 0, strlen($basePath . '/')) === $basePath . '/') {
        $variants[] = substr($path, strlen($basePath));
    }
    if (substr($path, 0, strlen('/printflow/')) === '/printflow/') {
        $variants[] = substr($path, strlen('/printflow'));
    }
    if (preg_match('#/uploads/#', $path, $m, PREG_OFFSET_CAPTURE)) {
        $variants[] = substr($path, $m[0][1]);
    }
    if (preg_match('#/public/#', $path, $m, PREG_OFFSET_CAPTURE)) {
        $variants[] = substr($path, $m[0][1]);
    }

    $candidates = [];
    foreach (array_unique(array_filter($variants, fn($v) => trim((string)$v) !== '')) as $variant) {
        $variant = str_replace('\\', '/', (string)$variant);
        if (preg_match('#^[A-Za-z]:/#', $variant) || substr($variant, 0, 2) === '//') {
            $candidates[] = $variant;
            continue;
        }

        $relative = ltrim($variant, '/');
        if ($printflow_root) {
            $candidates[] = $printflow_root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        }
        if ($htdocs_root) {
            $candidates[] = $htdocs_root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $candidates[] = rtrim($htdocs_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $variant), DIRECTORY_SEPARATOR);
        }
    }

    return array_values(array_unique($candidates));
}

function pf_serve_design_read_file(?string $storedPath): bool {
    global $htdocs_root, $printflow_root;

    $allowedRoots = array_values(array_filter([
        $printflow_root ? realpath($printflow_root) : null,
        $htdocs_root ? realpath($htdocs_root) : null,
    ]));

    foreach (pf_serve_design_file_candidates($storedPath) as $candidate) {
        $real = realpath($candidate);
        if (!$real || !is_file($real)) {
            continue;
        }

        $allowed = false;
        foreach ($allowedRoots as $root) {
            $checkPath = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if ($root !== '' && substr($real, 0, strlen($checkPath)) === $checkPath) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            continue;
        }

        pf_serve_design_emit_file($real);
    }

    return false;
}

function pf_serve_design_find_related_order_item(int $orderId, int $excludeOrderItemId, string $field = 'design'): ?array {
    if ($orderId <= 0) {
        return null;
    }

    if ($field === 'reference') {
        $rows = db_query(
            "SELECT order_item_id, reference_image_file
             FROM order_items
             WHERE order_id = ?
               AND order_item_id <> ?
               AND TRIM(COALESCE(reference_image_file, '')) <> ''
             ORDER BY order_item_id ASC
             LIMIT 1",
            'ii',
            [$orderId, $excludeOrderItemId]
        ) ?: [];
        return $rows[0] ?? null;
    }

    $rows = db_query(
        "SELECT order_item_id, design_image, design_image_mime, design_image_name, design_file
         FROM order_items
         WHERE order_id = ?
           AND order_item_id <> ?
           AND (
                IFNULL(LENGTH(design_image), 0) > 0
                OR TRIM(COALESCE(design_file, '')) <> ''
           )
         ORDER BY order_item_id ASC
         LIMIT 1",
        'ii',
        [$orderId, $excludeOrderItemId]
    ) ?: [];

    return $rows[0] ?? null;
}

function pf_serve_design_decode_json_payload(?string $raw): array {
    $payload = json_decode((string)$raw, true);
    return is_array($payload) ? $payload : [];
}

function pf_serve_design_first_nonempty_scalar(array $payload, array $keys): ?string {
    foreach ($keys as $key) {
        if (!array_key_exists($key, $payload)) {
            continue;
        }
        $value = $payload[$key];
        if (is_scalar($value)) {
            $text = trim((string)$value);
            if ($text !== '') {
                return $text;
            }
        }
    }
    return null;
}

function pf_serve_design_emit_data_url_if_present(?string $dataUrl, string $fallbackName = 'design'): bool {
    $raw = trim((string)$dataUrl);
    if ($raw === '' || !preg_match('#^data:([^;]+);base64,(.+)$#s', $raw, $matches)) {
        return false;
    }
    $blob = base64_decode($matches[2], true);
    if ($blob === false || $blob === '') {
        return false;
    }
    pf_serve_design_emit_blob($blob, trim((string)$matches[1]), $fallbackName);
    return true;
}

function pf_serve_design_try_customization_payload_media(int $orderItemId, string $field = 'design'): bool {
    if ($orderItemId <= 0) {
        return false;
    }

    $rows = db_query(
        "SELECT customization_details
         FROM customizations
         WHERE order_item_id = ?
         ORDER BY customization_id DESC
         LIMIT 1",
        'i',
        [$orderItemId]
    ) ?: [];
    if (!empty($rows) && pf_serve_design_emit_payload_media(
        pf_serve_design_decode_json_payload((string)($rows[0]['customization_details'] ?? '')),
        $field
    )) {
        return true;
    }

    $itemRows = db_query(
        "SELECT customization_data
         FROM order_items
         WHERE order_item_id = ?
         LIMIT 1",
        'i',
        [$orderItemId]
    ) ?: [];
    if (!empty($itemRows)) {
        return pf_serve_design_emit_payload_media(
            pf_serve_design_decode_json_payload((string)($itemRows[0]['customization_data'] ?? '')),
            $field
        );
    }

    return false;
}

function pf_serve_design_emit_payload_media(array $payload, string $field = 'design'): bool {
    if ($payload === []) {
        return false;
    }

    if ($field === 'reference') {
        $dataUrl = pf_serve_design_first_nonempty_scalar($payload, [
            'reference_upload_data',
            'upload_reference_data',
            'reference_data',
        ]);
        if ($dataUrl !== null) {
            return pf_serve_design_emit_data_url_if_present($dataUrl, (string)(pf_serve_design_first_nonempty_scalar($payload, [
                'reference_upload_name',
                'reference_upload',
                'reference_file',
            ]) ?? 'reference'));
        }
        $path = pf_serve_design_first_nonempty_scalar($payload, [
            'reference_upload_path',
            'upload_reference_path',
            'reference_file',
            'reference_upload',
        ]);
        if ($path !== null && pf_serve_design_read_file($path)) {
            return true;
        }
        return false;
    }

    $dataUrl = pf_serve_design_first_nonempty_scalar($payload, [
        'design_upload_data',
        'upload_design_data',
        'design_data',
    ]);
    if ($dataUrl !== null) {
        return pf_serve_design_emit_data_url_if_present($dataUrl, (string)(pf_serve_design_first_nonempty_scalar($payload, [
            'design_upload_name',
            'design_upload',
            'design_file',
        ]) ?? 'design'));
    }

    $path = pf_serve_design_first_nonempty_scalar($payload, [
        'design_upload_path',
        'upload_design_path',
        'design_file',
        'design_upload',
        'layout_file',
    ]);
    if ($path !== null && pf_serve_design_read_file($path)) {
        return true;
    }

    return false;
}

function pf_serve_design_try_customization_payload_media_by_order(int $orderId, int $preferredOrderItemId = 0, string $field = 'design'): bool {
    if ($orderId <= 0) {
        return false;
    }

    $rows = db_query(
        "SELECT customization_details
         FROM customizations
         WHERE order_id = ?
         ORDER BY CASE WHEN order_item_id = ? THEN 0 ELSE 1 END, customization_id DESC
         LIMIT 10",
        'ii',
        [$orderId, $preferredOrderItemId]
    ) ?: [];

    foreach ($rows as $row) {
        $payload = pf_serve_design_decode_json_payload((string)($row['customization_details'] ?? ''));
        if ($payload !== [] && pf_serve_design_emit_payload_media($payload, $field)) {
            return true;
        }
    }

    $itemRows = db_query(
        "SELECT customization_data
         FROM order_items
         WHERE order_id = ?
         ORDER BY CASE WHEN order_item_id = ? THEN 0 ELSE 1 END, order_item_id ASC
         LIMIT 10",
        'ii',
        [$orderId, $preferredOrderItemId]
    ) ?: [];

    foreach ($itemRows as $row) {
        $payload = pf_serve_design_decode_json_payload((string)($row['customization_data'] ?? ''));
        if ($payload !== [] && pf_serve_design_emit_payload_media($payload, $field)) {
            return true;
        }
    }

    return false;
}

function pf_serve_design_try_job_order_artwork(int $orderItemId, int $orderId = 0): bool {
    $queries = [];
    if ($orderItemId > 0) {
        $queries[] = [
            "SELECT artwork_path FROM job_orders
             WHERE order_item_id = ?
               AND TRIM(COALESCE(artwork_path, '')) <> ''
             ORDER BY id DESC
             LIMIT 5",
            'i',
            [$orderItemId]
        ];
    }
    if ($orderId > 0) {
        $queries[] = [
            "SELECT artwork_path FROM job_orders
             WHERE order_id = ?
               AND TRIM(COALESCE(artwork_path, '')) <> ''
             ORDER BY CASE WHEN order_item_id = ? THEN 0 ELSE 1 END, id DESC
             LIMIT 5",
            'ii',
            [$orderId, $orderItemId]
        ];
    }

    foreach ($queries as [$sql, $types, $params]) {
        $rows = db_query($sql, $types, $params) ?: [];
        foreach ($rows as $row) {
            if (pf_serve_design_read_file((string)($row['artwork_path'] ?? ''))) {
                return true;
            }
        }
    }

    return false;
}

// Role-based access (Customers can only see their own, Staff can see all)
if (!is_logged_in()) {
    http_response_code(403);
    die('Unauthorized');
}

$type = $_GET['type'] ?? 'order_item'; // 'order_item', 'temp_cart', etc.
$id   = (int)($_GET['id'] ?? 0);
$field = $_GET['field'] ?? 'design'; // 'design' or 'reference'

if (!$id) {
    pf_serve_design_emit_fallback('Invalid image request');
}

$user_id = get_user_id();
$is_staff = is_staff() || is_admin() || is_manager();

if ($type === 'order_item') {
    // 1. Check if user has access to this order
    $check = db_query(
        "SELECT oi.order_id, o.customer_id
         FROM order_items oi
         JOIN orders o ON oi.order_id = o.order_id
         WHERE oi.order_item_id = ?",
        'i',
        [$id]
    );
    if (!$is_staff) {
        if (empty($check) || $check[0]['customer_id'] != $user_id) {
            http_response_code(403);
            die('Unauthorized access to this order item.');
        }
    }
    $orderId = (int)($check[0]['order_id'] ?? 0);

    // 2. Get data
    $item = db_query(
        "SELECT order_item_id, design_image, design_image_mime, design_image_name, design_file,
                reference_image_file, revision_design_name, revision_design_path
         FROM order_items
         WHERE order_item_id = ?",
        'i',
        [$id]
    )[0] ?? null;

    if (!$item) {
        pf_serve_design_emit_fallback('Image not found');
    }

    if ($field === 'revision_design') {
        // Serve revision design if it exists
        if (pf_serve_design_read_file($item['revision_design_path'] ?? '')) {
            exit;
        }
        pf_serve_design_emit_fallback('Revision not found');
    } elseif ($field === 'reference') {
        if (pf_serve_design_read_file($item['reference_image_file'] ?? '')) {
            exit;
        }
        if (pf_serve_design_try_customization_payload_media($id, 'reference')) {
            exit;
        }
        $relatedReference = pf_serve_design_find_related_order_item($orderId, $id, 'reference');
        if (!empty($relatedReference['reference_image_file']) && pf_serve_design_read_file((string)$relatedReference['reference_image_file'])) {
            exit;
        }
    } else {
        // Try BLOB first
        if (!empty($item['design_image'])) {
            pf_serve_design_emit_blob(
                (string)$item['design_image'],
                (string)($item['design_image_mime'] ?: 'image/jpeg'),
                (string)($item['design_image_name'] ?? 'design')
            );
        }
        // Then try File
        if (pf_serve_design_read_file($item['design_file'] ?? '')) {
            exit;
        }
        if (pf_serve_design_read_file($item['design_image_name'] ?? '')) {
            exit;
        }
        if (pf_serve_design_try_customization_payload_media($id, 'design')) {
            exit;
        }
        if (pf_serve_design_try_customization_payload_media_by_order($orderId, $id, 'design')) {
            exit;
        }
        if (pf_serve_design_try_job_order_artwork($id, $orderId)) {
            exit;
        }
        $relatedDesign = pf_serve_design_find_related_order_item($orderId, $id, 'design');
        if (!empty($relatedDesign)) {
            if (!empty($relatedDesign['design_image'])) {
                pf_serve_design_emit_blob(
                    (string)$relatedDesign['design_image'],
                    (string)($relatedDesign['design_image_mime'] ?: 'image/jpeg'),
                    (string)($relatedDesign['design_image_name'] ?? 'design')
                );
            }
            if (pf_serve_design_read_file((string)($relatedDesign['design_file'] ?? ''))) {
                exit;
            }
            if (pf_serve_design_read_file((string)($relatedDesign['design_image_name'] ?? ''))) {
                exit;
            }
        }
    }

    pf_serve_design_emit_fallback('Image unavailable');
}

if ($type === 'service_file') {
    $row = db_query(
        "SELECT sf.file_data, sf.mime_type, sf.original_name, sf.file_path, so.customer_id
         FROM service_order_files sf
         INNER JOIN service_orders so ON sf.order_id = so.id
         WHERE sf.id = ?",
        'i',
        [$id]
    );
    if (empty($row)) {
        pf_serve_design_emit_fallback('File not found');
    }
    $row = $row[0];

    if (!$is_staff) {
        if (get_user_type() !== 'Customer' || (int)$row['customer_id'] !== (int)$user_id) {
            http_response_code(403);
            die('Unauthorized access to this file.');
        }
    }

    if (!empty($row['file_data'])) {
        pf_serve_design_emit_blob(
            (string)$row['file_data'],
            (string)($row['mime_type'] ?: 'application/octet-stream'),
            (string)($row['original_name'] ?: 'design')
        );
    }

    $rel = $row['file_path'] ?? '';
    if ($rel !== '') {
        if (pf_serve_design_read_file($rel)) {
            exit;
        }
    }

    pf_serve_design_emit_fallback('File unavailable');
}

pf_serve_design_emit_fallback('Image unavailable');
?>
