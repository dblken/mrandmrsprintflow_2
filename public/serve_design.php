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

        $mime = mime_content_type($real) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($real) . '"');
        readfile($real);
        return true;
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
    http_response_code(404);
    die('Not Found');
}

$user_id = get_user_id();
$is_staff = is_staff() || is_admin() || is_manager();

if ($type === 'order_item') {
    // 1. Check if user has access to this order
    if (!$is_staff) {
        $check = db_query("SELECT o.customer_id FROM order_items oi JOIN orders o ON oi.order_id = o.order_id WHERE oi.order_item_id = ?", 'i', [$id]);
        if (empty($check) || $check[0]['customer_id'] != $user_id) {
            http_response_code(403);
            die('Unauthorized access to this order item.');
        }
    }

    // 2. Get data
    $item = db_query("SELECT design_image, design_image_mime, design_file, reference_image_file, revision_design_name, revision_design_path FROM order_items WHERE order_item_id = ?", 'i', [$id])[0] ?? null;

    if (!$item) {
        http_response_code(404);
        die('Item not found');
    }

    if ($field === 'revision_design') {
        // Serve revision design if it exists
        if (pf_serve_design_read_file($item['revision_design_path'] ?? '')) {
            exit;
        }
        http_response_code(404);
        die('Revision design not found');
    } elseif ($field === 'reference') {
        if (pf_serve_design_read_file($item['reference_image_file'] ?? '')) {
            exit;
        }
    } else {
        // Try BLOB first
        if (!empty($item['design_image'])) {
            $mime = $item['design_image_mime'] ?: 'image/jpeg';
            header("Content-Type: $mime");
            echo $item['design_image'];
            exit;
        }
        // Then try File
        if (pf_serve_design_read_file($item['design_file'] ?? '')) {
            exit;
        }
    }
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
        http_response_code(404);
        die('Not found');
    }
    $row = $row[0];

    if (!$is_staff) {
        if (get_user_type() !== 'Customer' || (int)$row['customer_id'] !== (int)$user_id) {
            http_response_code(403);
            die('Unauthorized access to this file.');
        }
    }

    if (!empty($row['file_data'])) {
        $mime = $row['mime_type'] ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($row['original_name'] ?: 'design') . '"');
        echo $row['file_data'];
        exit;
    }

    $rel = $row['file_path'] ?? '';
    if ($rel !== '') {
        if (pf_serve_design_read_file($rel)) {
            exit;
        }
    }
}

http_response_code(404);
echo "Image not found.";
?>
