<?php
/**
 * Staff Reviews Page
 * Enhanced with Filtering by Type and improved data mapping.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role(['Staff', 'Admin']);
require_once __DIR__ . '/../includes/staff_pending_check.php';
ensure_ratings_table_exists();
db_execute("CREATE TABLE IF NOT EXISTS review_helpful (
    id INT AUTO_INCREMENT PRIMARY KEY,
    review_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_review_user (review_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

require_once __DIR__ . '/../includes/branch_context.php';
$branch_ctx = init_branch_context(false);
$reviewBranchFilter = printflow_branch_filter_for_user();
$staffBranchId = $reviewBranchFilter ?? ($branch_ctx['selected_branch_id'] === 'all' ? (int)($_SESSION['branch_id'] ?? 1) : (int)$branch_ctx['selected_branch_id']);
$branchName = $branch_ctx['branch_name'] ?? 'Main Branch';

$review_columns = array_flip(array_column(db_query("SHOW COLUMNS FROM reviews") ?: [], 'Field'));
$review_customer_expr = isset($review_columns['customer_id']) ? 'r.customer_id' : (isset($review_columns['user_id']) ? 'r.user_id' : '0');
$review_message_expr = isset($review_columns['message']) ? 'r.message' : (isset($review_columns['comment']) ? 'r.comment' : "''");
$review_reference_expr = isset($review_columns['reference_id']) ? 'r.reference_id' : 'NULL';
$review_type_expr = isset($review_columns['review_type']) ? 'r.review_type' : "'custom'";
$review_service_expr = isset($review_columns['service_type']) ? 'r.service_type' : "''";
$review_video_expr = isset($review_columns['video_path']) ? 'r.video_path' : "''";
$review_title_expr = isset($review_columns['title']) ? 'r.title' : (isset($review_columns['review_title']) ? 'r.review_title' : "''");
$review_branch_from = 'FROM reviews r';
$review_kpi_types = '';
$review_kpi_params = [];
if ($reviewBranchFilter !== null) {
    $review_branch_from .= " LEFT JOIN orders o ON o.order_id = r.order_id WHERE (o.branch_id = ? OR r.order_id IS NULL OR r.order_id = 0)";
    $review_kpi_types = 'i';
    $review_kpi_params = [(int)$reviewBranchFilter];
}

// Filters
$search = sanitize($_GET['search'] ?? '');
$review_type = sanitize($_GET['review_type'] ?? '');
$rating = (int) ($_GET['rating'] ?? 0);
$service = sanitize($_GET['service'] ?? '');
$sort_by = sanitize($_GET['sort_by'] ?? 'newest');

// Get distinct services for the filter from reviews, services, and products table
$available_services = db_query("
    SELECT DISTINCT name FROM (
        SELECT {$review_service_expr} COLLATE utf8mb4_general_ci as name FROM reviews r WHERE {$review_service_expr} != '' AND {$review_service_expr} IS NOT NULL
        UNION
        SELECT name COLLATE utf8mb4_general_ci FROM services WHERE status = 'Activated'
        UNION
        SELECT name COLLATE utf8mb4_general_ci FROM products WHERE status = 'Activated'
    ) as combined_services WHERE name IS NOT NULL AND name != '' ORDER BY name ASC
") ?: [];

// Pagination
$items_per_page = 5;
$current_page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($current_page - 1) * $items_per_page;

$sql_base = "
    FROM reviews r
    INNER JOIN customers c ON c.customer_id = {$review_customer_expr}
    LEFT JOIN orders o ON o.order_id = r.order_id
    WHERE 1=1
";
$params = [];
$types = '';

if ($reviewBranchFilter !== null) {
    $sql_base .= " AND (o.branch_id = ? OR r.order_id IS NULL OR r.order_id = 0)";
    $params[] = (int)$reviewBranchFilter;
    $types .= 'i';
}

if (!empty($search)) {
    $sql_base .= " AND (c.first_name LIKE ? OR c.last_name LIKE ? OR r.order_id = ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = (int) str_replace(['#', 'ORD-'], '', $search);
    $types .= 'ssi';
}
if (!empty($review_type)) {
    $sql_base .= " AND {$review_type_expr} = ?";
    $params[] = $review_type;
    $types .= 's';
}
if ($rating > 0 && $rating <= 5) {
    $sql_base .= " AND r.rating = ?";
    $params[] = $rating;
    $types .= 'i';
}
if (!empty($service)) {
    // Robust filtering by name (legacy) or by ID mapping (modern)
    $sql_base .= " AND (
        {$review_service_expr} = ? 
        OR {$review_service_expr} LIKE ? 
        OR ({$review_type_expr} = 'custom' AND {$review_reference_expr} IN (SELECT service_id FROM services WHERE name = ? OR name LIKE ?))
        OR ({$review_type_expr} = 'product' AND {$review_reference_expr} IN (SELECT product_id FROM products WHERE name = ? OR name LIKE ?))
    )";
    $like = $service . '%';
    $params[] = $service;
    $params[] = $like;
    $params[] = $service;
    $params[] = $like;
    $params[] = $service;
    $params[] = $like;
    $types .= 'ssssss';
}

$count_sql = "SELECT COUNT(*) as total" . $sql_base;
$total_result = db_query($count_sql, $types ?: null, $params ?: null);
$total_items = (int) ($total_result[0]['total'] ?? 0);
$total_pages = ceil($total_items / $items_per_page);

$query_sql = "
    SELECT
        r.id,
        r.order_id,
        {$review_reference_expr} as reference_id,
        {$review_type_expr} as review_type,
        {$review_service_expr} as legacy_service_type,
        {$review_title_expr} as review_title,
        r.rating,
        {$review_message_expr} as comment,
        {$review_video_expr} as video_path,
        r.created_at,
        c.first_name,
        c.last_name,
        (CASE 
            WHEN {$review_type_expr} = 'product' THEN (SELECT name FROM products WHERE product_id = {$review_reference_expr})
            WHEN {$review_type_expr} = 'custom' THEN (SELECT name FROM services WHERE service_id = {$review_reference_expr})
            ELSE {$review_service_expr}
        END) as item_name,
        (SELECT COUNT(*) FROM review_helpful WHERE review_id = r.id) as helpful_count
    " . $sql_base . "
    ";

$order_sql = " ORDER BY r.created_at DESC ";
if ($sort_by === 'oldest')
    $order_sql = " ORDER BY r.created_at ASC ";
if ($sort_by === 'rating_high')
    $order_sql = " ORDER BY r.rating DESC, r.created_at DESC ";
if ($sort_by === 'rating_low')
    $order_sql = " ORDER BY r.rating ASC, r.created_at DESC ";

$query_sql .= $order_sql . " LIMIT ? OFFSET ? ";

$fetch_params = array_merge($params, [$items_per_page, $offset]);
$fetch_types = $types . 'ii';
$reviews = db_query($query_sql, $fetch_types ?: null, $fetch_params ?: null) ?: [];

// Map services for reset/links
$page_query_params = ['search' => $search, 'review_type' => $review_type, 'rating' => $rating, 'service' => $service];

// Fetch images and replies for each review
$reviews_raw = $reviews;
$reviews = [];
foreach ($reviews_raw as $r) {
    $rid = (int) $r['id'];
    $images = db_query("SELECT image_path FROM review_images WHERE review_id = ?", 'i', [$rid]) ?: [];
    $replies = db_query("
        SELECT rr.id, rr.reply_message, rr.created_at, u.first_name, u.last_name
        FROM review_replies rr
        INNER JOIN users u ON u.user_id = rr.staff_id
        WHERE rr.review_id = ?
        ORDER BY rr.created_at ASC
    ", 'i', [$rid]) ?: [];

    $r['images'] = $images;
    $r['replies'] = $replies;
    $displayItemName = trim((string)($r['item_name'] ?? ''));
    $legacyServiceType = trim((string)($r['legacy_service_type'] ?? ''));
    if ($displayItemName === '' || pf_review_value_looks_like_path($displayItemName)) {
        if ($legacyServiceType !== '' && !pf_review_value_looks_like_path($legacyServiceType)) {
            $displayItemName = $legacyServiceType;
        } elseif (!empty($r['order_id'])) {
            $preview = printflow_order_notification_preview((int)$r['order_id']);
            $previewName = trim((string)($preview['display_name'] ?? ''));
            if ($previewName !== '' && !pf_review_value_looks_like_path($previewName)) {
                $displayItemName = $previewName;
            }
        }
    }
    $r['display_item_name'] = $displayItemName;
    $reviews[] = $r;
}

function pf_normalize_review_media_path($path, $base_path, $default = '')
{
    $path = trim((string)$path);
    if ($path === '') {
        return $default;
    }
    $path = str_replace('\\', '/', $path);
    if (preg_match('#^https?://#i', $path)) {
        $parts = parse_url($path);
        if (!empty($parts['path'])) {
            $path = $parts['path'];
        } else {
            return $path;
        }
    }
    if (preg_match('#^[A-Za-z]:/#', $path)) {
        $path = preg_replace('#^[A-Za-z]:#', '', $path);
    }
    $public_pos = strpos($path, '/public/');
    if ($public_pos !== false) {
        $path = substr($path, $public_pos);
    }
    $uploads_pos = strpos($path, '/uploads/');
    if ($uploads_pos !== false && ($public_pos === false || $uploads_pos < $public_pos)) {
        $path = substr($path, $uploads_pos);
    }
    if ($base_path === '' && strpos($path, '/printflow/') === 0) {
        $path = substr($path, strlen('/printflow'));
    }
    if ($path !== '' && $path[0] !== '/') {
        $path = '/' . ltrim($path, '/');
    }
    if ($base_path !== '' && strpos($path, $base_path . '/') !== 0) {
        $path = $base_path . $path;
    }
    return $path;
}

function pf_review_video_candidates($path, $base_path, $review_id = 0)
{
    $sources = [];
    $base = rtrim((string)$base_path, '/');
    if ((int)$review_id > 0) {
        $sources[] = ($base !== '' ? $base : '') . '/public/serve_review_video.php?review_id=' . (int)$review_id;
    }
    foreach (pf_review_video_direct_candidates((string)$path, (string)$base_path) as $candidate) {
        if (!in_array($candidate, $sources, true)) {
            $sources[] = $candidate;
        }
    }
    return $sources;
}

function pf_review_video_mime_type($src)
{
    $ext = strtolower(pathinfo(parse_url((string)$src, PHP_URL_PATH) ?? (string)$src, PATHINFO_EXTENSION));
    return match ($ext) {
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'ogv' => 'video/ogg',
        'avi' => 'video/x-msvideo',
        default => 'video/mp4',
    };
}

function pf_render_review_video_sources($sources)
{
    $html = '';
    foreach ($sources as $src) {
        $html .= '<source src="' . htmlspecialchars($src) . '" type="' . htmlspecialchars(pf_review_video_mime_type($src)) . '">' . "\n";
    }
    return $html;
}

function pf_review_value_looks_like_path($value): bool
{
    $value = trim((string)$value);
    if ($value === '') {
        return false;
    }
    return (bool)preg_match('#(^/|\\\\|/uploads/|/public/|\.mp4$|\.mov$|\.jpg$|\.jpeg$|\.png$|\.webp$)#i', $value);
}

function stars_text($value)
{
    $v = max(1, min(5, (int) $value));
    return str_repeat('&#9733;', $v) . str_repeat('&#9734;', 5 - $v);
}

$csrf_token = generate_csrf_token();
$page_title = 'Review Management - Staff';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_PATH . '/public/assets/css/output.css'); ?>">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        .main-content > header {
            margin-bottom: 0;
        }

        .page-title {
            margin-bottom: 0;
            font-size: 24px;
            font-weight: 600;
            color: #1f2937;
        }

        .page-subtitle {
            margin-top: 4px;
            font-size: 14px;
            font-weight: 400;
            line-height: 1.5;
            color: #64748b;
        }

        .kpi-row {
            margin-bottom: 28px;
        }

        .status-badge-pill {
            font-size: 10px;
            padding: 4px 10px;
            font-weight: 700;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .table-text-main {
            font-size: 13px;
            font-weight: 500;
            color: #111827;
        }

        .table-text-sub {
            font-size: 11px;
            color: #6b7280;
            font-weight: 400;
        }

        .filter-search-input {
            width: 100%;
            height: 38px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 13px;
            padding: 0 12px 0 32px;
            color: #1f2937;
            box-sizing: border-box;
            transition: all 0.2s;
        }

        .filter-search-input:focus {
            outline: none;
            border-color: #0d9488;
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 8px;
            padding: 14px 18px;
            border-top: 1px solid #f3f4f6;
        }

        .filter-btn-reset {
            flex: 1;
            height: 40px;
            border: 1px solid #e5e7eb;
            background: #fff;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 400;
            color: #374151;
            cursor: pointer;
            transition: all 0.2s;
            width: 100%;
        }

        .filter-btn-reset:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }

        [x-cloak] {
            display: none !important;
        }

        .rv-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .rv-toolbar {
            padding: 20px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: flex-end;
            background: #fff;
            border-radius: 14px;
            margin-bottom: 24px;
            border: 1px solid #e5e7eb;
        }

        .rv-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .rv-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: #64748b;
            font-weight: 800;
        }

        .rv-input,
        .rv-select {
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 13px;
            outline: none;
            transition: border-color 0.2s;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }

        .rv-input:focus,
        .rv-select:focus {
            border-color: #0a2530;
        }

        .rv-btn {
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 41px;
        }

        .rv-btn.primary {
            background: #0a2530;
            color: #fff;
        }

        .rv-btn.light {
            background: #f8fafc;
            color: #334155;
            border: 1px solid #cbd5e1;
            text-decoration: none;
        }

        .review-item {
            padding: 24px;
            border-bottom: 1px solid #f1f5f9;
        }

        .review-item:last-child {
            border-bottom: none;
        }

        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .review-user {
            font-weight: 800;
            font-size: 16px;
            color: #0a2530;
            margin-bottom: 4px;
        }

        .review-meta {
            display: flex;
            gap: 10px;
            align-items: center;
            font-size: 12px;
            color: #64748b;
        }

        .type-badge {
            padding: 3px 10px;
            border-radius: 999px;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.02em;
        }

        .type-product {
            background: rgba(59, 130, 246, 0.1);
            color: #1d4ed8;
        }

        .type-custom {
            background: rgba(16, 185, 129, 0.1);
            color: #047857;
        }

        .review-stars {
            color: #f59e0b;
            font-size: 16px;
            margin-bottom: 12px;
        }

        .review-msg {
            font-size: 14px;
            line-height: 1.6;
            color: #334155;
            margin-bottom: 16px;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .review-media {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .media-thumb {
            width: 80px;
            height: 80px;
            border-radius: 10px;
            object-fit: cover;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .media-thumb:hover {
            transform: scale(1.04);
        }

        .video-thumb {
            background: #0f172a;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #3b82f6 !important;
        }

        .video-thumb::after {
            content: '\25B6';
            color: #fff;
            font-size: 24px;
            filter: drop-shadow(0 0 8px rgba(59, 130, 246, 0.5));
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 3;
            pointer-events: none;
        }

        .video-thumb::before {
            content: 'VIDEO';
            position: absolute;
            bottom: 4px;
            font-size: 8px;
            font-weight: 800;
            color: #3b82f6;
            letter-spacing: 0.1em;
        }

        .video-thumb video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 10px;
            background: #0f172a;
        }

        .replies-container {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            margin-top: 20px;
        }

        .reply-item {
            margin-bottom: 16px;
        }

        .reply-item:last-child {
            margin-bottom: 0;
        }

        .reply-header {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .reply-msg {
            font-size: 13px;
            color: #475569;
            background: #fff;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid #edf2f7;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .reply-form {
            margin-top: 20px;
            border-top: 1px solid #f1f5f9;
            padding-top: 20px;
        }

        .reply-input-wrap {
            display: flex;
            gap: 12px;
            margin-top: 12px;
        }

        .reply-textarea {
            flex: 1;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 12px;
            font-size: 13px;
            min-height: 50px;
            resize: none;
        }

        .reply-submit {
            background: #0a2530;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 0 20px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
        }

        .reply-submit:hover {
            background: #1a3a4a;
        }

        .rv-pager {
            padding: 20px;
            text-align: center;
        }

        .rv-empty {
            text-align: center;
            padding: 80px 20px;
            background: #fff;
            border-radius: 14px;
            border: 1px solid #e5e7eb;
            color: #64748b;
        }

        .rv-modal {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.8);
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 40px;
        }

        .rv-modal.open {
            display: flex;
        }

        .rv-modal-content {
            max-width: 90%;
            max-height: 90%;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        .rv-close {
            position: absolute;
            top: -30px;
            right: -30px;
            color: #fff;
            font-size: 30px;
            cursor: pointer;
        }

        .rv-nav-btn {
            width: 42px;
            height: 42px;
            border-radius: 999px;
            border: none;
            background: rgba(15, 23, 42, 0.78);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform 0.18s ease, background 0.18s ease;
            z-index: 2;
        }

        .rv-nav-btn:hover {
            background: rgba(2, 6, 23, 0.9);
            transform: scale(1.08);
        }

        .main-content {
            padding-top: 10px !important;
        }

        .pf-reviews-table-card {
            margin-bottom: 24px;
            margin-top: 0;
        }

        .pf-reviews-table-card table {
            width: 100%;
            table-layout: fixed;
            border-collapse: separate;
            border-spacing: 0;
        }

        .pf-reviews-table-card thead th {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
            background: #f9fafb;
            border-bottom: 2px solid #f3f4f6;
        }

        .pf-reviews-table-card tbody tr {
            transition: background-color 0.18s ease;
        }

        .pf-reviews-table-card tbody tr:hover {
            background: linear-gradient(90deg, rgba(6, 161, 161, 0.05) 0%, rgba(158, 215, 196, 0.10) 100%);
        }

        .pf-reviews-table-card tbody td {
            vertical-align: top;
            padding-top: 22px;
            padding-bottom: 22px;
            border-bottom: 1px solid #eef2f7;
        }

        .pf-reviews-table-card .rv-card {
            margin: 0;
            border: 0;
            border-radius: 0;
            box-shadow: none;
            background: transparent;
        }

        .reviews-data-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: separate;
            border-spacing: 0;
        }

        .reviews-data-table thead th {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
            background: #f9fafb;
            border-bottom: 2px solid #f3f4f6;
            text-align: left;
            padding: 16px 18px;
        }

        .reviews-data-table tbody td {
            vertical-align: top;
            padding: 22px 18px;
            border-bottom: 1px solid #eef2f7;
        }

        .reviews-data-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .pf-reviews-table-card .review-item {
            display: grid;
            grid-template-columns: minmax(0, 1.8fr) minmax(320px, 1fr);
            gap: 24px;
            padding: 28px 24px;
            border-bottom: 1px solid #eef2f7;
        }

        .pf-reviews-table-card .review-item:last-child {
            border-bottom: 0;
        }

        .reviews-table-head {
            display: grid;
            grid-template-columns: minmax(0, 1.8fr) minmax(320px, 1fr);
            gap: 24px;
            padding: 16px 24px;
            border-bottom: 2px solid #f3f4f6;
            background: #f9fafb;
        }

        .reviews-table-head span {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
        }

        .review-customer-review-col,
        .review-response-col {
            min-width: 0;
        }

        .truncate-ellipsis {
            display: block;
            min-width: 0;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .pf-reviews-table-card .rv-pager {
            padding: 20px 24px;
            border-top: 1px solid #eef2f7;
            text-align: center;
        }

        .customer-review-col {
            display: flex;
            flex-direction: column;
            gap: 14px;
            min-width: 0;
        }

        .customer-review-head {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 0;
        }

        .review-customer-name {
            font-size: 15px;
            font-weight: 700;
            color: #111827;
            line-height: 1.35;
        }

        .customer-review-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 10px;
            align-items: center;
            font-size: 12px;
            color: #64748b;
        }

        .customer-review-body {
            display: flex;
            flex-direction: column;
            gap: 12px;
            min-width: 0;
        }

        .review-item-title {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            line-height: 1.4;
            white-space: normal;
            overflow: visible;
            text-overflow: unset;
        }

        .review-rating-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 12px;
        }

        .review-helpful {
            font-size: 12px;
            font-weight: 600;
            color: #059669;
        }

        .review-product-name {
            font-size: 12px;
            color: #475569;
            line-height: 1.45;
        }

        .review-product-name strong {
            color: #111827;
            font-weight: 700;
        }

        .reply-layout {
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 14px;
        }

        .reply-history {
            flex: 1 1 auto;
            min-width: 0;
        }

        .reply-compose {
            flex: 0 0 auto;
            min-width: 0;
        }

        .reply-compose .reply-form {
            margin-top: 0;
            padding-top: 0;
            border-top: 0;
            width: 100%;
        }

        .reply-compose .reply-input-wrap {
            flex-direction: column;
            gap: 10px;
        }

        .reply-compose .reply-submit {
            min-height: 42px;
            width: 100%;
        }

        .reply-panel {
            min-height: 100%;
        }

        .type-badge {
            padding: 4px 10px;
            border-radius: 999px;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.05em;
        }

        .review-stars {
            color: #f59e0b;
            font-size: 18px;
            margin-bottom: 0;
            font-weight: 700;
            letter-spacing: 0.01em;
        }

        .review-msg {
            font-size: 13px;
            line-height: 1.6;
            color: #475569;
            margin-bottom: 16px;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .reply-header {
            display: flex;
            justify-content: space-between;
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 6px;
            letter-spacing: 0.04em;
        }

        .reply-msg {
            font-size: 13px;
            color: #475569;
            background: #fff;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid #edf2f7;
            overflow-wrap: anywhere;
            word-break: break-word;
            line-height: 1.55;
        }

        .reply-textarea,
        .rv-select,
        .filter-select,
        .filter-input {
            font-size: 13px;
            font-weight: 400;
            color: #334155;
        }

        .reply-submit,
        .filter-btn-reset {
            font-size: 13px;
            font-weight: 500;
        }
        .pagination-container .pagination-link.is-active,
        .pagination-container .pagination-link[aria-current="page"] {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e3a8a 100%) !important;
            border-color: #1d4ed8 !important;
            color: #ffffff !important;
            box-shadow: 0 8px 20px rgba(29, 78, 216, 0.24) !important;
        }
        .pagination-container .pagination-link:not(.is-active) {
            color: #475569 !important;
        }
        .pagination-container .pagination-link:not(.is-active):hover {
            border-color: #1d4ed8 !important;
            color: #1d4ed8 !important;
        }

        @media (max-width: 960px) {
            .pf-reviews-table-card table,
            .pf-reviews-table-card thead,
            .pf-reviews-table-card tbody,
            .pf-reviews-table-card tr,
            .pf-reviews-table-card th,
            .pf-reviews-table-card td {
                display: block;
                width: 100%;
            }

            .pf-reviews-table-card thead {
                display: none;
            }

            .pf-reviews-table-card .review-item,
            .reviews-table-head {
                display: block;
                padding: 0;
            }

            .reply-input-wrap {
                flex-direction: column;
            }

            .reply-submit {
                min-height: 44px;
            }

            .reply-layout {
                flex-direction: column;
            }

            .reply-compose {
                flex: 1 1 auto;
                min-width: 0;
                width: 100%;
            }
            
            .reply-form,
            .reply-input-wrap,
            .rv-select,
            .reply-textarea,
            .reply-submit {
                width: 100%;
                max-width: 100%;
                min-width: 0;
            }

            .reviews-table-head {
                display: none;
            }

            .pf-reviews-table-card .review-item {
                display: block;
                padding: 24px 18px;
                gap: 0;
            }

            .review-customer-review-col {
                margin-bottom: 18px;
            }
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <?php if (($_SESSION['user_type'] ?? '') === 'Admin'): ?>
            <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>
        <?php else: ?>
            <?php include __DIR__ . '/../includes/staff_sidebar.php'; ?>
        <?php endif; ?>

        <div class="main-content" x-data="reviewManager()" x-init="init()">
            <header style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1 class="page-title">Review Management</h1>
                    <p class="page-subtitle">Track and respond to customer feedback and service ratings</p>
                </div>
            </header>

            <main>
                <!-- KPI Summary Row -->
                <div class="kpi-row">
                    <div class="kpi-card indigo">
                        <span class="kpi-card-inner">
                            <span class="kpi-label">Total Reviews</span>
                            <span class="kpi-value"><?php echo number_format($total_items); ?></span>
                            <span class="kpi-sub">Lifetime customer feedback</span>
                        </span>
                    </div>
                    <div class="kpi-card amber">
                        <span class="kpi-card-inner">
                        <span class="kpi-label">Average Rating</span>
                        <span class="kpi-value">
                            <?php
                            $avg = db_query("SELECT AVG(r.rating) as avg {$review_branch_from}", $review_kpi_types ?: null, $review_kpi_params ?: null);
                            echo number_format($avg[0]['avg'] ?? 0, 1);
                            ?>
                                <span style="font-size: 18px; color: #f59e0b;">&#9733;</span>
                        </span>
                        <span class="kpi-sub">System-wide performance quality</span>
                        </span>
                    </div>
                    <div class="kpi-card blue">
                        <span class="kpi-card-inner">
                        <span class="kpi-label">Service Focus</span>
                        <span class="kpi-value">
                            <?php
                            $top = db_query("SELECT {$review_service_expr} AS service_type, COUNT(*) as c {$review_branch_from} GROUP BY service_type ORDER BY c DESC LIMIT 1", $review_kpi_types ?: null, $review_kpi_params ?: null);
                            echo htmlspecialchars(ucfirst($top[0]['service_type'] ?? 'None'));
                            ?>
                        </span>
                        <span class="kpi-sub">Most frequently reviewed type</span>
                        </span>
                    </div>
                    <div class="kpi-card emerald">
                        <span class="kpi-card-inner">
                        <span class="kpi-label">Pending Replies</span>
                        <span class="kpi-value">
                            <?php
                            $pendingWhere = $reviewBranchFilter !== null ? ' AND ' : ' WHERE ';
                            $pending = db_query("SELECT COUNT(*) as c {$review_branch_from}{$pendingWhere}(SELECT COUNT(*) FROM review_replies rr WHERE rr.review_id = r.id) = 0", $review_kpi_types ?: null, $review_kpi_params ?: null);
                            echo $pending[0]['c'] ?? 0;
                            ?>
                        </span>
                        <span class="kpi-sub">Customer responses awaiting action</span>
                        </span>
                    </div>
                </div>

                <!-- Standardized Toolbar -->
                <div class="card overflow-visible pf-reviews-table-card">
                    <div class="toolbar-container" style="display: flex !important; justify-content: space-between !important; align-items: center !important; flex-wrap: wrap !important; gap: 16px !important; width: 100% !important;">
                        <h3 style="font-size:16px; font-weight:700; color:#1f2937; margin:0;">Reviews Feed</h3>
                        <div class="toolbar-group" style="display: flex !important; gap: 8px !important; margin-left: auto !important; justify-content: flex-end !important;">


                            <!-- Sort Button -->
                            <div style="position:relative;">
                                <button class="toolbar-btn" :class="{ active: sortOpen || (activeSort !== 'newest') }"
                                    @click="sortOpen = !sortOpen; filterOpen = false">
                                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12" />
                                    </svg>
                                    <span class="toolbar-btn-label">Sort by</span>
                                </button>
                                <div class="dropdown-panel sort-dropdown" x-show="sortOpen" x-cloak
                                    @click.outside="sortOpen = false">
                                    <template x-for="s in [
                                    {id:'newest', label:'Newest first'},
                                    {id:'oldest', label:'Oldest first'},
                                    {id:'rating_high', label:'Rating: High to Low'},
                                    {id:'rating_low', label:'Rating: Low to High'}
                                ]" :key="s.id">
                                        <div class="sort-option" :class="{ 'active': activeSort === s.id }"
                                            @click="applySort(s.id)">
                                            <span x-text="s.label"></span>
                                            <svg x-show="activeSort === s.id" class="check" width="14" height="14"
                                                viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                                                stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="20 6 9 17 4 12" />
                                            </svg>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Filter Button -->
                            <div style="position:relative;">
                                <button class="toolbar-btn" :class="{ active: filterOpen || filterActiveCount > 0 }"
                                    @click="filterOpen = !filterOpen; sortOpen = false">
                                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
                                    </svg>
                                    Filter
                                    <template x-if="filterActiveCount > 0">
                                        <span class="filter-badge" x-text="filterActiveCount"></span>
                                    </template>
                                </button>

                                <!-- Filter Panel -->
                                <div class="dropdown-panel filter-panel" x-show="filterOpen" x-cloak
                                    @click.outside="filterOpen = false">
                                    <div class="filter-header">
                                        <span>Refine Reviews</span>
                                        <button type="button" class="filter-close-btn" @click="filterOpen = false">×</button>
                                    </div>

                                    <div class="filter-section">
                                        <div class="filter-section-head">
                                            <span class="filter-label" style="margin:0;">Service</span>
                                            <button @click="service = ''; applyFilters()"
                                                class="filter-reset-link">Reset</button>
                                        </div>
                                        <select class="filter-select" x-model="service" @change="applyFilters()">
                                            <option value="">All Services</option>
                                            <?php foreach ($available_services as $as): ?>
                                                <option value="<?php echo htmlspecialchars($as['name']); ?>">
                                                    <?php echo htmlspecialchars($as['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="filter-section">
                                        <div class="filter-section-head">
                                            <span class="filter-label" style="margin:0;">Type</span>
                                            <button @click="reviewType = ''; applyFilters()"
                                                class="filter-reset-link">Reset</button>
                                        </div>
                                        <select class="filter-select" x-model="reviewType" @change="applyFilters()">
                                            <option value="">All Types</option>
                                            <option value="product">Fixed Products</option>
                                            <option value="custom">Custom Services</option>
                                        </select>
                                    </div>

                                    <div class="filter-section">
                                        <div class="filter-section-head">
                                            <span class="filter-label" style="margin:0;">Rating</span>
                                            <button @click="rating = 0; applyFilters()"
                                                class="filter-reset-link">Reset</button>
                                        </div>
                                        <select class="filter-select" x-model="rating" @change="applyFilters()">
                                            <option value="0">All Ratings</option>
                                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                                <option value="<?php echo $i; ?>"><?php echo $i; ?> Stars</option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>

                                    <div class="filter-section">
                                        <div class="filter-section-head">
                                            <span class="filter-label" style="margin:0;">Keyword search</span>
                                            <button @click="search = ''; applyFilters()"
                                                class="filter-reset-link">Reset</button>
                                        </div>
                                        <input type="text" class="filter-input" placeholder="Search..." x-model="search"
                                            @change="applyFilters()">
                                    </div>

                                    <div class="filter-footer">
                                        <button class="filter-btn-reset" style="width:100%;"
                                            @click="resetFilters()">Reset all filters</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                

                    <?php if (empty($reviews)): ?>
                        <div class="rv-empty">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">ðŸ’¬</div>
                            <p>No reviews found matching your criteria.</p>
                        </div>
                    <?php else: ?>
                        <div class="rv-card">
                            <div class="reviews-table-head">
                                <span>Customer Review</span>
                                <span>Response</span>
                            </div>
                            <?php foreach ($reviews as $review): ?>
                                <div class="review-item" id="review-<?php echo $review['id']; ?>">
                                    <div class="review-customer-review-col customer-review-col">
                                        <div class="customer-review-head">
                                            <div class="review-customer-name truncate-ellipsis" title="<?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?>">
                                                <?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?>
                                            </div>
                                            <div class="customer-review-meta">
                                                <span class="type-badge <?php echo $review['review_type'] === 'product' ? 'type-product' : 'type-custom'; ?>">
                                                    <?php echo $review['review_type'] === 'product' ? 'Fixed Product' : 'Custom Service'; ?>
                                                </span>
                                                <span><?php echo date('M d, Y h:i A', strtotime($review['created_at'])); ?></span>
                                            </div>
                                        </div>
                                        <div class="customer-review-body">
                                            <?php if (!empty(trim((string)($review['review_title'] ?? '')))): ?>
                                                <div class="review-item-title"><?php echo htmlspecialchars($review['review_title']); ?></div>
                                            <?php endif; ?>
                                            <div class="review-rating-row">
                                                <div class="review-stars"><?php echo stars_text($review['rating']); ?></div>
                                                <?php if (!empty($review['display_item_name'])): ?>
                                                    <div class="review-product-name"><?php echo htmlspecialchars($review['display_item_name']); ?></div>
                                                <?php endif; ?>
                                                <?php if (($review['helpful_count'] ?? 0) > 0): ?>
                                                    <span class="review-helpful"><?php echo (int)$review['helpful_count']; ?> Like<?php echo ((int)$review['helpful_count'] === 1) ? '' : 's'; ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="review-msg"><?php echo nl2br(htmlspecialchars($review['comment'] ?: '')); ?></div>
                                        </div>

                                        <?php
                                        $has_imgs = !empty($review['images']);
                                        $has_vid = !empty($review['video_path']);
                                        if ($has_imgs || $has_vid):
                                            $video_sources = $has_vid ? pf_review_video_candidates((string)($review['video_path'] ?? ''), BASE_PATH, (int)$review['id']) : [];
                                            $vpath = $video_sources[0] ?? '';
                                            $normalized_images = [];
                                            foreach (($review['images'] ?? []) as $imgRow) {
                                                $ipath = pf_normalize_review_media_path((string)($imgRow['image_path'] ?? ''), BASE_PATH, '');
                                                if ($ipath !== '') {
                                                    $normalized_images[] = $ipath;
                                                }
                                            }
                                            $media_items = [];
                                            if ($vpath !== '') {
                                                $media_items[] = [
                                                    'type' => 'video',
                                                    'src' => $vpath,
                                                    'sources' => array_values($video_sources),
                                                ];
                                            }
                                            foreach ($normalized_images as $imgSrc) {
                                                $media_items[] = ['type' => 'image', 'src' => $imgSrc];
                                            }
                                            $media_items_json = json_encode($media_items, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                        ?>
                                            <div class="review-media" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap:12px; max-width:600px;">
                                                <?php if ($vpath !== ''): ?>
                                                    <div class="media-thumb video-thumb" style="width:100%; aspect-ratio:1; cursor:pointer;" onclick='openReviewMediaCarousel(<?php echo $media_items_json ?: "[]"; ?>, 0)'>
                                                        <video
                                                            muted
                                                            playsinline
                                                            preload="metadata"
                                                            onloadedmetadata="try{this.currentTime=0.1;}catch(e){}"
                                                            style="pointer-events:none;">
                                                            <?php echo pf_render_review_video_sources($video_sources); ?>
                                                        </video>
                                                    </div>
                                                <?php elseif ($has_vid): ?>
                                                    <div style="display:flex;align-items:center;justify-content:center;min-height:100px;border:1px solid #e5e7eb;border-radius:10px;color:#6b7280;font-size:0.9rem;background:#f8fafc;">
                                                        Video not available
                                                    </div>
                                                <?php endif; ?>

                                                <?php foreach ($normalized_images as $imgIndex => $ipath): ?>
                                                    <?php $mediaStartIndex = ($vpath !== '' ? 1 : 0) + (int)$imgIndex; ?>
                                                    <img src="<?php echo htmlspecialchars($ipath); ?>" class="media-thumb" style="width:100%; aspect-ratio:1;" alt="Review image" onclick='openReviewMediaCarousel(<?php echo $media_items_json ?: "[]"; ?>, <?php echo $mediaStartIndex; ?>)'>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="review-response-col">
                                        <div class="reply-layout">
                                            <div class="reply-history">
                                                <div class="replies-container" id="replies-<?php echo $review['id']; ?>" <?php echo empty($review['replies']) ? 'style="display:none"' : ''; ?>>
                                                    <?php foreach ($review['replies'] as $reply): ?>
                                                        <div class="reply-item">
                                                            <div class="reply-header">
                                                                <span>Staff Response &bull; <?php echo htmlspecialchars($reply['first_name'] . ' ' . $reply['last_name']); ?></span>
                                                                <span><?php echo date('M d, Y', strtotime($reply['created_at'])); ?></span>
                                                            </div>
                                                            <div class="reply-msg">
                                                                <?php echo nl2br(htmlspecialchars($reply['reply_message'])); ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <div class="reply-compose">
                                                <div class="reply-form">
                                                    <select class="rv-select" style="width: 100%; font-size: 12px;" onchange="applyQuickReply(this, <?php echo $review['id']; ?>)">
                                                        <option value="">Quick reply</option>
                                                        <option value="Thank you! We're happy to hear you're satisfied with your order.">Positive</option>
                                                        <option value="We apologize for the inconvenience. Please contact us so we can resolve this.">Negative</option>
                                                    </select>
                                                    <div class="reply-input-wrap">
                                                        <textarea class="reply-textarea" placeholder="Type your response..." id="reply-text-<?php echo $review['id']; ?>"></textarea>
                                                        <button class="reply-submit" onclick="submitReply(<?php echo $review['id']; ?>, this)">Reply</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="pagination-container" style="display:flex; justify-content:center; padding: 24px 0; border-top: 1px solid #f3f4f6; margin-top: auto;">
                            <?php echo render_pagination($current_page, $total_pages, $page_query_params); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Media Modal -->
    <div id="mediaModal" class="rv-modal" onclick="closeMediaModal()">
        <span class="rv-close">&times;</span>
        <button type="button" id="rvPrevBtn" class="rv-nav-btn" style="display:none;" onclick="event.stopPropagation(); changeModalImage(-1);" aria-label="Previous image">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </button>
        <div class="rv-modal-content" id="modalContent" onclick="event.stopPropagation()"></div>
        <button type="button" id="rvNextBtn" class="rv-nav-btn" style="display:none;" onclick="event.stopPropagation(); changeModalImage(1);" aria-label="Next image">
            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </button>
    </div>

    <script>
        function reviewManager() {
            return {
                search: '<?php echo addslashes($search); ?>',
                service: '<?php echo addslashes($service); ?>',
                reviewType: '<?php echo addslashes($review_type); ?>',
                rating: <?php echo $rating; ?>,
                activeSort: '<?php echo addslashes($_GET['sort_by'] ?? 'newest'); ?>',
                filterOpen: false,
                sortOpen: false,
                getProfileImage(image) {
                    if (!image || image === 'null' || image === 'undefined') {
                        return (window.PF_BASE_PATH || '') + '/public/assets/uploads/profiles/default.png';
                    }
                    if (typeof image !== 'string') return (window.PF_BASE_PATH || '') + '/public/assets/uploads/profiles/default.png';
                    if (image.startsWith('/') || image.startsWith('http')) return image;
                    return (window.PF_BASE_PATH || '') + '/public/assets/uploads/profiles/' + image;
                },

                init() {
                    // Keep state
                },

                get filterActiveCount() {
                    let count = 0;
                    if (this.service) count++;
                    if (this.reviewType) count++;
                    if (this.rating > 0) count++;
                    return count;
                },

                applyFilters() {
                    const params = new URLSearchParams();
                    if (this.search) params.set('search', this.search);
                    if (this.service) params.set('service', this.service);
                    if (this.reviewType) params.set('review_type', this.reviewType);
                    if (this.rating > 0) params.set('rating', this.rating);
                    if (this.activeSort !== 'newest') params.set('sort_by', this.activeSort);
                    window.location.search = params.toString();
                },

                applySort(id) {
                    this.activeSort = id;
                    this.sortOpen = false;
                    this.applyFilters();
                },

                resetFilters() {
                    window.location.href = 'reviews.php';
                }
            };
        }

        let rvModalGallery = [];
        let rvModalIndex = 0;

        function renderModalItem() {
            const content = document.getElementById('modalContent');
            const prevBtn = document.getElementById('rvPrevBtn');
            const nextBtn = document.getElementById('rvNextBtn');
            const item = rvModalGallery[rvModalIndex] || null;
            if (!item) {
                content.innerHTML = '';
                prevBtn.style.display = 'none';
                nextBtn.style.display = 'none';
                return;
            }
            if (item.type === 'video') {
                const sources = Array.isArray(item.sources) ? item.sources : [item.src];
                const sourcesHtml = sources
                    .filter(Boolean)
                    .map((src) => `<source src="${src}" type="${String(src).toLowerCase().includes('.webm') ? 'video/webm' : 'video/mp4'}">`)
                    .join('');
                content.innerHTML = `<video controls autoplay playsinline preload="auto" style="max-height: 80vh; max-width: 100%; border-radius: 12px; background:#000;">${sourcesHtml}</video>`;
                const vid = content.querySelector('video');
                if (vid) { vid.load(); vid.play().catch(() => {}); }
            } else {
                content.innerHTML = `<img src="${item.src || ''}" style="max-height: 80vh; max-width: 100%; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.3);">`;
            }
            const hasMultiple = rvModalGallery.length > 1;
            prevBtn.style.display = hasMultiple ? 'inline-flex' : 'none';
            nextBtn.style.display = hasMultiple ? 'inline-flex' : 'none';
        }

        function openReviewMediaCarousel(items, startIndex = 0) {
            rvModalGallery = Array.isArray(items) ? items.filter((item) => item && item.src) : [];
            if (!rvModalGallery.length) return;
            rvModalIndex = Math.max(0, Math.min(Number(startIndex) || 0, rvModalGallery.length - 1));
            renderModalItem();
            document.getElementById('mediaModal').classList.add('open');
        }

        function changeModalImage(step) {
            if (!rvModalGallery.length) return;
            rvModalIndex = (rvModalIndex + step + rvModalGallery.length) % rvModalGallery.length;
            renderModalItem();
        }

        function openMediaModal(src, type) {
            const item = type === 'video'
                ? { type: 'video', src: src, sources: [src] }
                : { type: 'image', src: src };
            openReviewMediaCarousel([item], 0);
        }

        function closeMediaModal() {
            document.getElementById('mediaModal').classList.remove('open');
            document.getElementById('modalContent').innerHTML = '';
            rvModalGallery = [];
            rvModalIndex = 0;
        }

        function applyQuickReply(select, id) {
            if (select.value) {
                document.getElementById('reply-text-' + id).value = select.value;
                select.value = "";
            }
        }

        async function submitReply(reviewId, btnElement) {
            const textarea = document.getElementById('reply-text-' + reviewId);
            const msg = textarea.value.trim();
            if (!msg) return;

            const originalText = btnElement.innerText;
            btnElement.innerText = "Sending...";
            btnElement.disabled = true;

            try {
                const response = await fetch('api_review_reply.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ review_id: reviewId, message: msg, csrf_token: '<?php echo $csrf_token; ?>' })
                });
                const data = await response.json();
                if (data.success) {
                    btnElement.style.backgroundColor = '#16a34a';
                    btnElement.style.color = '#fff';
                    btnElement.innerText = "Reply Sent";
                    setTimeout(() => { location.reload(); }, 1500);
                } else {
                    alert(data.error || 'Failed to post reply');
                    btnElement.innerText = originalText;
                    btnElement.disabled = false;
                }
            } catch (e) {
                alert('An error occurred');
                btnElement.innerText = originalText;
                btnElement.disabled = false;
            }
        }
    </script>
</body>

</html>
