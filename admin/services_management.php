<?php
/**
 * Admin Services Management — service catalog (no inventory).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/customer_service_catalog.php';
require_once __DIR__ . '/../includes/service_field_config_helper.php';

require_role(['Admin', 'Manager']);

/** Manager: services list is read-only (view + filter/sort only). */
$pf_manager_services_readonly = defined('MANAGER_PANEL') && MANAGER_PANEL;

$base_path = pf_app_base_path();
$current_user = get_logged_in_user();
$error = '';
$success = '';

function admin_service_media_url(string $path): string {
    global $base_path;
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) {
        $parts = parse_url($path);
        $host = strtolower((string)($parts['host'] ?? ''));
        $currentHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '' && $currentHost !== '' && $host !== $currentHost) {
            return $path;
        }
        $path = (string)($parts['path'] ?? '');
    }
    if (preg_match('#^[A-Za-z]:/#', $path)) {
        $path = preg_replace('#^[A-Za-z]:#', '', $path);
    }
    $publicPos = strpos($path, '/public/');
    if ($publicPos !== false) {
        $path = substr($path, $publicPos);
    }
    $uploadsPos = strpos($path, '/uploads/');
    if ($uploadsPos !== false && ($publicPos === false || $uploadsPos < $publicPos)) {
        $path = substr($path, $uploadsPos);
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

function admin_service_media_list_url(string $value): string {
    $items = array_filter(array_map('trim', explode(',', $value)), static fn($item) => $item !== '');
    $items = array_map('admin_service_media_url', $items);
    return implode(',', array_filter($items, static fn($item) => $item !== ''));
}

/** Duplicate name check (case-insensitive, trimmed). */
function service_name_exists(string $name, int $excludeId = 0): bool {
    $name = trim($name);
    $rows = db_query(
        "SELECT service_id FROM services WHERE LOWER(TRIM(name)) = LOWER(?) AND service_id != ? AND status != 'Archived'",
        'si',
        [$name, $excludeId]
    );
    return !empty($rows);
}

/** Categories allowed in admin add/edit and filters (legacy rows may still store other labels). */
function printflow_allowed_service_categories(): array {
    return ['Tarpaulin', 'T-Shirt', 'Stickers', 'Sintraboard Standees', 'Signage', 'Merchandise', 'Print'];
}

function printflow_service_category_is_allowed(string $category): bool {
    $category = trim($category);
    if ($category === '') {
        return false;
    }
    foreach (printflow_allowed_service_categories() as $a) {
        if (strcasecmp($category, $a) === 0) {
            return true;
        }
    }
    return false;
}

function printflow_canonical_service_category(string $category): ?string {
    foreach (printflow_allowed_service_categories() as $a) {
        if (strcasecmp(trim($category), $a) === 0) {
            return $a;
        }
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    if ($pf_manager_services_readonly) {
        http_response_code(403);
        exit('Forbidden');
    }

    // Handle file uploads
    $uploaded_images = [];
    $uploaded_video = '';

    if (isset($_FILES['photo_files']) && is_array($_FILES['photo_files']['name'])) {
        $photo_count = count($_FILES['photo_files']['name']);
        for ($i = 0; $i < $photo_count; $i++) {
            if (($_FILES['photo_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }

            $file_name = $_FILES['photo_files']['name'][$i];
            $file_tmp = $_FILES['photo_files']['tmp_name'][$i];
            $file_size = (int)($_FILES['photo_files']['size'][$i] ?? 0);
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_img = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (!in_array($file_ext, $allowed_img, true) || $file_size > 5 * 1024 * 1024 || count($uploaded_images) >= 5) {
                continue;
            }

            $upload_dir = __DIR__ . '/../public/assets/images/services/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $new_filename = 'service_' . time() . '_' . uniqid() . '_' . $i . '.' . $file_ext;
            $upload_path = $upload_dir . $new_filename;

            if (move_uploaded_file($file_tmp, $upload_path)) {
                $uploaded_images[] = $base_path . '/public/assets/images/services/' . $new_filename;
            }
        }
    }

    if (isset($_FILES['video_file']) && (int)($_FILES['video_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $file_name = $_FILES['video_file']['name'];
        $file_tmp = $_FILES['video_file']['tmp_name'];
        $file_size = (int)($_FILES['video_file']['size'] ?? 0);
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_vid = ['mp4', 'webm', 'mov', 'avi'];

        if (in_array($file_ext, $allowed_vid, true) && $file_size <= 100 * 1024 * 1024) {
            $upload_dir = __DIR__ . '/../public/assets/videos/services/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            $new_filename = 'service_' . time() . '_' . uniqid() . '.' . $file_ext;
            $upload_path = $upload_dir . $new_filename;

            if (move_uploaded_file($file_tmp, $upload_path)) {
                $uploaded_video = $base_path . '/public/assets/videos/services/' . $new_filename;
            }
        }
    }
    
    if (isset($_POST['create_service'])) {
        $name = preg_replace('/\s+/', ' ', trim($_POST['name'] ?? ''));
        $category = sanitize($_POST['category'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $price = 1.0;
        $statusRaw = trim((string) ($_POST['status'] ?? ''));
        $status = ($statusRaw === 'Deactivated') ? 'Deactivated' : 'Activated';
        $hero_image = admin_service_media_url(sanitize(trim((string) ($_POST['hero_image'] ?? ''))));
        $display_image = !empty($uploaded_images) ? implode(',', $uploaded_images) : admin_service_media_list_url(sanitize(trim((string) ($_POST['display_image'] ?? ''))));
        $video_url = $uploaded_video ?: admin_service_media_url(sanitize(trim((string) ($_POST['video_url'] ?? ''))));
        $customer_modal_text = '';

        if ($name === '') {
            $error = 'Service name is required.';
        } elseif (strlen($name) > 150) {
            $error = 'Service name must not exceed 150 characters.';
        } elseif (empty($category) || $category === '-- Select Category --') {
            $error = 'Please select a category.';
        } elseif (!printflow_service_category_is_allowed($category)) {
            $error = 'Invalid category.';
        } elseif (strlen($description) > 2000) {
            $error = 'Description must not exceed 2000 characters.';
        } elseif ($display_image === '') {
            $error = 'Please provide at least one service photo.';
        } elseif (service_name_exists($name, 0)) {
            $error = 'A service with this name already exists.';
        } else {
            $category = printflow_canonical_service_category($category);
            $result = db_execute(
                'INSERT INTO services (name, category, description, price, duration, status, visible_to_customer, hero_image, display_image, video_url, customer_modal_text, created_at, updated_at) VALUES (?, ?, ?, ?, NULL, ?, 1, ?, ?, ?, ?, NOW(), NOW())',
                'sssdsssss',
                [$name, $category, $description, $price, $status, $hero_image, $display_image, $video_url, $customer_modal_text]
            );
            if ($result) {
                $msg = 'Service created successfully.';
                if (!empty($uploaded_images)) {
                    $msg .= ' Uploaded ' . count($uploaded_images) . ' image(s).';
                }
                if (!empty($uploaded_video)) {
                    $msg .= ' Uploaded 1 video.';
                }
                $success = $msg;
            } else {
                global $conn;
                $error = 'Failed to create service. ' . ($conn->error ?? '');
            }
        }
    } elseif (isset($_POST['update_service'])) {
        $service_id = (int)($_POST['service_id'] ?? 0);
        $name = preg_replace('/\s+/', ' ', trim($_POST['name'] ?? ''));
        $category = sanitize($_POST['category'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $price = 1.0;
        $statusRaw = trim((string) ($_POST['status'] ?? ''));
        $status = ($statusRaw === 'Deactivated') ? 'Deactivated' : 'Activated';
        $hero_image = admin_service_media_url(sanitize(trim((string) ($_POST['hero_image'] ?? ''))));
        $display_image = !empty($uploaded_images) ? implode(',', $uploaded_images) : admin_service_media_list_url(sanitize(trim((string) ($_POST['display_image'] ?? ''))));
        $video_url = $uploaded_video ?: admin_service_media_url(sanitize(trim((string) ($_POST['video_url'] ?? ''))));
        $existing_modal = db_query("SELECT customer_modal_text FROM services WHERE service_id = ? LIMIT 1", 'i', [$service_id]);
        $customer_modal_text = trim((string)($existing_modal[0]['customer_modal_text'] ?? ''));

        if ($service_id < 1) {
            $error = 'Invalid service.';
        } elseif ($name === '') {
            $error = 'Service name is required.';
        } elseif (strlen($name) > 150) {
            $error = 'Service name must not exceed 150 characters.';
        } elseif (empty($category) || $category === '-- Select Category --') {
            $error = 'Please select a category.';
        } elseif (!printflow_service_category_is_allowed($category)) {
            $error = 'Invalid category.';
        } elseif (strlen($description) > 2000) {
            $error = 'Description must not exceed 2000 characters.';
        } elseif ($display_image === '') {
            $error = 'Please provide at least one service photo.';
        } elseif (service_name_exists($name, $service_id)) {
            $error = 'A service with this name already exists.';
        } else {
            $category = printflow_canonical_service_category($category);
            $result = db_execute(
                'UPDATE services SET name = ?, category = ?, description = ?, price = ?, duration = NULL, status = ?, hero_image = ?, display_image = ?, video_url = ?, customer_modal_text = ?, updated_at = NOW() WHERE service_id = ?',
                'sssdsssssi',
                [$name, $category, $description, $price, $status, $hero_image, $display_image, $video_url, $customer_modal_text, $service_id]
            );
            if ($result) {
                $msg = 'Service updated successfully';
                if (!empty($uploaded_images)) {
                    $msg .= ' - Uploaded ' . count($uploaded_images) . ' image(s)';
                }
                if (!empty($uploaded_video)) {
                    $msg .= ' - Uploaded 1 video';
                }
                $success = $msg;
            } else {
                global $conn;
                $error = 'Failed to update service. ' . ($conn->error ?? '');
            }
        }
    } elseif (isset($_POST['archive_service'])) {
        $service_id = (int)$_POST['service_id'];
        db_execute("UPDATE services SET status = 'Archived', updated_at = NOW() WHERE service_id = ?", 'i', [$service_id]);
        $success = 'Service archived successfully!';
    } elseif (isset($_POST['restore_service'])) {
        $service_id = (int)$_POST['service_id'];
        
        // Get the name of the service we want to restore
        $svc_to_restore = db_query("SELECT name FROM services WHERE service_id = ?", 'i', [$service_id]);
        $svc_name = $svc_to_restore[0]['name'] ?? '';
        
        if (service_name_exists($svc_name, $service_id)) {
            $error = 'Cannot restore: An active service with this name already exists.';
        } else {
            db_execute("UPDATE services SET status = 'Activated', updated_at = NOW() WHERE service_id = ?", 'i', [$service_id]);
            $success = 'Service restored successfully!';
        }
    } elseif (isset($_POST['delete_service'])) {
        $service_id = (int)$_POST['service_id'];
        $current = db_query("SELECT status FROM services WHERE service_id = ?", 'i', [$service_id]);
        $status = $current[0]['status'] ?? '';

        if ($status === 'Archived') {
            $error = 'Archived services cannot be permanently deleted.';
        } else {
            $new_status = ($status === 'Activated') ? 'Deactivated' : 'Activated';
            db_execute("UPDATE services SET status = ?, updated_at = NOW() WHERE service_id = ?", 'si', [$new_status, $service_id]);
            $success = 'Service ' . strtolower($new_status) . ' successfully!';
        }
    }
}

// Archived list (modal)
if (isset($_GET['get_archived'])) {
    if ($pf_manager_services_readonly) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'html' => '', 'forbidden' => true]);
        exit;
    }
    header('Content-Type: application/json');
    $archived = db_query("SELECT * FROM services WHERE status = 'Archived' ORDER BY updated_at DESC") ?: [];

    $html = '<table class="orders-table" style="width:100%;">';
    $html .= '<thead><tr><th>Name</th><th>Category</th><th style="text-align:right;">Actions</th></tr></thead>';
    $html .= '<tbody>';

    if (empty($archived)) {
        $html .= '<tr><td colspan="3" style="padding:40px;text-align:center;color:#9ca3af;">No archived services found.</td></tr>';
    } else {
        foreach ($archived as $s) {
            $html .= '<tr>';
            $html .= '<td style="font-weight:500; max-width:300px; word-break: break-word;">' . htmlspecialchars($s['name']) . '</td>';
            $html .= '<td>' . htmlspecialchars($s['category'] ?? '—') . '</td>';
            $html .= '<td style="text-align:right;white-space:nowrap;">';
            $html .= '<form method="POST" class="inline service-status-form" data-pf-skip-guard style="display:inline-block;margin-right:4px;" data-action="Restore" data-service-name="' . htmlspecialchars($s['name'], ENT_QUOTES) . '" onsubmit="showServiceStatusModal(event, this);return false;">';
            $html .= csrf_field();
            $html .= '<input type="hidden" name="service_id" value="' . (int)$s['service_id'] . '">';
            $html .= '<button type="submit" name="restore_service" class="btn-action teal">Restore</button></form>';
            $html .= '</td></tr>';
        }
    }
    $html .= '</tbody></table>';

    echo json_encode(['success' => true, 'html' => $html]);
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$search = trim($_GET['search'] ?? '');
$cat_filter = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort_by = $_GET['sort'] ?? 'newest';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$category_options = printflow_allowed_service_categories();

$service_filter_category_map = [];
$categories_raw_sv = db_query("SELECT DISTINCT category FROM services WHERE category IS NOT NULL AND TRIM(category) != '' AND status != 'Archived' ORDER BY category ASC") ?: [];
foreach ($categories_raw_sv as $crow) {
    $c = trim((string)($crow['category'] ?? ''));
    if ($c === '') {
        continue;
    }
    $lk = strtolower($c);
    if (!isset($service_filter_category_map[$lk])) {
        $service_filter_category_map[$lk] = $c;
    }
}
if ($cat_filter !== '') {
    $lkf = strtolower($cat_filter);
    if (!isset($service_filter_category_map[$lkf])) {
        $cat_filter = '';
    } else {
        $cat_filter = $service_filter_category_map[$lkf];
    }
}

$sql = "SELECT * FROM services WHERE status != 'Archived'";
$params = [];
$types = '';

if ($search !== '') {
    $like = '%' . $search . '%';
    $sql .= " AND (name LIKE ? OR category LIKE ?)";
    $params = array_merge($params, [$like, $like]);
    $types .= 'ss';
}
if ($cat_filter !== '') {
    $sql .= " AND category = ?";
    $params[] = $cat_filter;
    $types .= 's';
}
if ($status_filter !== '') {
    $sql .= " AND status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if (!empty($date_from)) {
    $sql .= " AND DATE(created_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}
if (!empty($date_to)) {
    $sql .= " AND DATE(created_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$count_sql = str_replace('SELECT *', 'SELECT COUNT(*) as total', $sql);
$total_row = db_query($count_sql, $types ?: null, $params ?: null);
$total_services = $total_row[0]['total'] ?? 0;
$total_pages = max(1, ceil($total_services / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$order_clause = match ($sort_by) {
    'oldest' => 'created_at ASC',
    'az' => 'name ASC',
    'za' => 'name DESC',
    default => 'created_at DESC',
};
$sql .= " ORDER BY $order_clause LIMIT $per_page OFFSET $offset";
$services = db_query($sql, $types ?: null, $params ?: null) ?: [];

$page_title = $pf_manager_services_readonly ? 'Services' : 'Services Management - Admin';

$stat_total = db_query("SELECT COUNT(*) as c FROM services WHERE status != 'Archived'")[0]['c'] ?? 0;
$stat_active = db_query("SELECT COUNT(*) as c FROM services WHERE status='Activated'")[0]['c'] ?? 0;
$stat_inactive = db_query("SELECT COUNT(*) as c FROM services WHERE status='Deactivated'")[0]['c'] ?? 0;
$stat_archived = db_query("SELECT COUNT(*) as c FROM services WHERE status='Archived'")[0]['c'] ?? 0;

$categories = [];
foreach ($service_filter_category_map as $c) {
    $categories[] = ['category' => $c];
}
usort($categories, static function ($a, $b) {
    return strcasecmp($a['category'], $b['category']);
});

/** Minimal, stable payload for row JS (view/edit modals). Avoids fragile inline JSON in onclick. */
function pf_admin_service_row_payload(array $svc): array {
    return [
        'service_id' => isset($svc['service_id']) ? (int) $svc['service_id'] : 0,
        'name' => (string) ($svc['name'] ?? ''),
        'category' => (string) ($svc['category'] ?? ''),
        'description' => (string) ($svc['description'] ?? ''),
        'status' => (string) ($svc['status'] ?? ''),
        'display_image' => (string) ($svc['display_image'] ?? ''),
        'video_url' => (string) ($svc['video_url'] ?? ''),
        'customer_modal_text' => isset($svc['customer_modal_text']) ? (string) $svc['customer_modal_text'] : '',
    ];
}

function pf_admin_service_row_json(array $svc): string {
    $json = json_encode(
        pf_admin_service_row_payload($svc),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
    );

    return $json !== false ? $json : '{}';
}

function render_services_table_rows(array $services): void {
    $readonly = defined('MANAGER_PANEL') && MANAGER_PANEL;
    ?>
    <table class="orders-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Service Name</th>
                <th>Category</th>
                <th>Status</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody id="servicesTableBody">
            <?php if (empty($services)): ?>
                <tr><td colspan="5" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">No services found.</td></tr>
            <?php else: ?>
                <?php foreach ($services as $svc): ?>
                    <?php $svc_row_json = htmlspecialchars(pf_admin_service_row_json($svc), ENT_QUOTES, 'UTF-8'); ?>
                    <tr data-pf-service="<?php echo $svc_row_json; ?>">
                        <td style="color:#1f2937;"><?php echo (int)$svc['service_id']; ?></td>
                        <td style="font-weight:500;color:#1f2937;max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($svc['name']); ?></td>
                        <td><?php echo htmlspecialchars($svc['category'] ?? '—'); ?></td>
                        <td>
                            <?php
                            $sc = match ($svc['status']) {
                                'Activated' => 'background:#dcfce7;color:#166534;',
                                'Deactivated' => 'background:#fee2e2;color:#991b1b;',
                                'Archived' => 'background:#f3f4f6;color:#374151;',
                                default => 'background:#fef9c3;color:#854d0e;',
                            };
                            ?>
                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;<?php echo $sc; ?>"><?php echo htmlspecialchars($svc['status']); ?></span>
                        </td>
                        <td style="text-align:right;white-space:nowrap;" onclick="event.stopPropagation();">
                            <div class="services-actions">
                            <?php if ($readonly): ?>
                            <button type="button" class="btn-action blue" onclick="event.stopPropagation(); openViewModal(pfParseServiceRow(this))">View</button>
                            <?php else: ?>
                            <button type="button" class="btn-action blue" onclick="event.stopPropagation(); openServiceModal(&quot;edit&quot;, pfParseServiceRow(this))">Edit</button>
                            <a href="service_field_config.php?service_id=<?php echo (int)$svc['service_id']; ?>" class="btn-action green" title="Configure service fields">Fields</a>
                            <?php if ($svc['status'] !== 'Archived'): ?>
                                <form method="POST" class="inline service-status-form" data-pf-skip-guard data-action="<?php echo $svc['status'] === 'Activated' ? 'Deactivate' : 'Activate'; ?>" data-service-name="<?php echo htmlspecialchars($svc['name'], ENT_QUOTES); ?>" onsubmit="showServiceStatusModal(event, this);return false;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="service_id" value="<?php echo (int)$svc['service_id']; ?>">
                                    <button type="submit" name="delete_service" class="btn-action <?php echo $svc['status'] === 'Activated' ? 'red' : 'teal'; ?>">
                                        <?php echo $svc['status'] === 'Activated' ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                                <?php if ($svc['status'] === 'Deactivated'): ?>
                                    <form method="POST" class="inline service-status-form" data-pf-skip-guard data-action="Archive" data-service-name="<?php echo htmlspecialchars($svc['name'], ENT_QUOTES); ?>" onsubmit="showServiceStatusModal(event, this);return false;">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="service_id" value="<?php echo (int)$svc['service_id']; ?>">
                                        <button type="submit" name="archive_service" class="btn-action gray">Archive</button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <form method="POST" class="inline service-status-form" data-pf-skip-guard data-action="Restore" data-service-name="<?php echo htmlspecialchars($svc['name'], ENT_QUOTES); ?>" onsubmit="showServiceStatusModal(event, this);return false;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="service_id" value="<?php echo (int)$svc['service_id']; ?>">
                                    <button type="submit" name="restore_service" class="btn-action teal">Restore</button>
                                </form>
                            <?php endif; ?>
                            <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
}

if (isset($_GET['ajax'])) {
    ob_start();
    render_services_table_rows($services);
    $table_html = ob_get_clean();
    ob_start();
    $pp = array_filter(['search' => $search, 'category' => $cat_filter, 'status' => $status_filter, 'sort' => $sort_by, 'date_from' => $date_from, 'date_to' => $date_to], function ($v) {
        return $v !== null && $v !== '';
    });
    echo render_pagination($page, $total_pages, $pp);
    $pagination_html = ob_get_clean();
    echo json_encode([
        'success' => true,
        'table' => '<div class="overflow-x-auto">' . $table_html . '</div>',
        'pagination' => $pagination_html,
        'count' => number_format($total_services),
        'badge' => count(array_filter([$search, $cat_filter, $status_filter, $date_from, $date_to], function ($v) {
            return $v !== null && $v !== '';
        })),
    ]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($base_path); ?>/public/assets/css/output.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        .btn-action { display:inline-flex; align-items:center; justify-content:center; box-sizing:border-box; height:30px; min-height:30px; padding:0 12px; min-width:72px; border:1px solid transparent; background:transparent; border-radius:6px; font-size:12px; font-weight:500; line-height:1; cursor:pointer; white-space:nowrap; text-decoration:none; vertical-align:middle; }
        .btn-action.teal { color:#14b8a6; border-color:#14b8a6; } .btn-action.teal:hover { background:#14b8a6; color:#fff; }
        .btn-action.blue { color:#3b82f6; border-color:#3b82f6; } .btn-action.blue:hover { background:#3b82f6; color:#fff; }
        .btn-action.red { color:#ef4444; border-color:#ef4444; } .btn-action.red:hover { background:#ef4444; color:#fff; }
        .btn-action.gray { color:#6b7280; border-color:#d1d5db; } .btn-action.gray:hover { background:#6b7280; color:#fff; }
        .btn-action.green { color:#059669; border-color:#059669; } .btn-action.green:hover { background:#059669; color:#fff; }
        .services-actions { display:inline-flex; align-items:center; justify-content:flex-end; gap:6px; flex-wrap:wrap; }
        .services-actions form.inline { display:inline-flex; margin:0; }
        .kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px; align-items:stretch; }
        @media(max-width:900px) { .kpi-row { grid-template-columns:repeat(2,1fr); } }
        .kpi-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; position:relative; overflow:hidden; height:100%; display:flex; flex-direction:column; }
        .kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
        .kpi-card.indigo::before { background:linear-gradient(90deg,#6366f1,#818cf8); }
        .kpi-card.emerald::before { background:linear-gradient(90deg,#059669,#34d399); }
        .kpi-card.rose::before { background:linear-gradient(90deg,#e11d48,#fb7185); }
        .kpi-card.slate::before { background:linear-gradient(90deg,#64748b,#94a3b8); }
        .kpi-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#9ca3af; margin-bottom:6px; }
        .kpi-sub { font-size:12px; color:#6b7280; margin-top:auto; }
        [x-cloak] { display:none !important; }
        .toolbar-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border:1px solid #e5e7eb; background:#fff; border-radius:8px; font-size:13px; font-weight:500; color:#374151; cursor:pointer; white-space:nowrap; }
        .toolbar-btn:hover { border-color:#9ca3af; background:#f9fafb; }
        .toolbar-btn.active { border-color:#0d9488; color:#0d9488; background:#f0fdfa; }
        .sort-dropdown { position:absolute; top:calc(100% + 6px); right:0; width:180px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1); z-index:200; padding:6px; }
        .sort-option { padding:9px 12px; font-size:13px; color:#4b5563; border-radius:6px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; }
        .sort-option:hover { background:#f9fafb; }
        .sort-option.selected { background:#f0fdfa; color:#0d9488; font-weight:600; }
        .filter-panel { position:absolute; top:calc(100% + 6px); right:0; width:320px; background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,0.12); z-index:200; overflow:hidden; }
        .filter-panel-header { padding:14px 18px; border-bottom:1px solid #f3f4f6; font-size:14px; font-weight:700; }
        .filter-section { padding:14px 18px; border-bottom:1px solid #f3f4f6; }
        .filter-section-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
        .filter-section-label { font-size:13px; font-weight:600; color:#374151; }
        .filter-reset-link { font-size:12px; font-weight:600; color:#0d9488; cursor:pointer; background:none; border:none; padding:0; }
        .filter-input, .filter-select, .filter-search-input { width:100%; height:34px; border:1px solid #e5e7eb; border-radius:7px; font-size:13px; padding:0 10px; box-sizing:border-box; }
        .filter-date-row { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
        .filter-date-label { font-size:11px; color:#6b7280; margin-bottom:4px; }
        .filter-actions { padding:14px 18px; border-top:1px solid #f3f4f6; }
        .filter-btn-reset { width:100%; height:36px; border:1px solid #e5e7eb; background:#fff; border-radius:8px; font-size:13px; cursor:pointer; }
        .filter-badge { display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; background:#0d9488; color:#fff; border-radius:50%; font-size:10px; font-weight:700; }
        .file-upload-area { border:2px dashed #e5e7eb; border-radius:12px; padding:32px 20px; text-align:center; cursor:pointer; transition:all 0.2s; position:relative; background:#fafbfc; min-height:180px; }
        .file-upload-area:hover { border-color:#0d9488; background:#f0fdfa; }
        .file-upload-area.drag-over { border-color:#0d9488; background:#f0fdfa; border-style:solid; }
        .upload-preview { position:relative; display:flex; align-items:center; justify-content:center; }
        .media-item { position:relative; border-radius:8px; overflow:hidden; border:2px solid #e5e7eb; }
        .media-item img, .media-item video { width:100%; height:120px; object-fit:cover; display:block; }
        .media-item .remove-btn { position:absolute; top:4px; right:4px; background:#ef4444; color:white; border:none; border-radius:50%; width:24px; height:24px; cursor:pointer; display:flex; align-items:center; justify-content:center; opacity:0; transition:opacity 0.2s; }
        .media-item:hover .remove-btn { opacity:1; }
        .media-item .media-badge { position:absolute; bottom:4px; left:4px; background:rgba(0,0,0,0.7); color:white; padding:2px 6px; border-radius:4px; font-size:10px; font-weight:600; }
        #service-modal-overlay, #view-service-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; padding:16px; }
        #service-modal-overlay.active, #view-service-modal-overlay.active { display:flex; }
        #service-modal { max-width:560px; }
        #view-service-modal { max-width:640px; }
        #service-modal, #view-service-modal { background:#fff; border-radius:12px; box-shadow:0 25px 50px rgba(0,0,0,0.25); width:100%; max-height:90vh; overflow-y:auto; display:flex; flex-direction:column; }
        #service-modal .modal-header, #view-service-modal .modal-header { padding:18px 20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; flex-shrink:0; }
        #service-modal .modal-body, #view-service-modal .modal-body { padding:18px 20px 20px; overflow-y:auto; flex:1; }
        #service-modal .form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:12px; }
        #service-modal .form-group { margin-bottom:12px; }
        #service-modal .form-group label { display:block; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:6px; }
        #service-modal .form-group input, #service-modal .form-group select, #service-modal .form-group textarea { width:100%; padding:10px 14px; border:1px solid #e5e7eb; border-radius:8px; font-size:14px; font-family:inherit; color:#1f2937; box-sizing:border-box; background:#f9fafb; resize:vertical; max-width:100%; transition:border-color 0.2s; }
        #service-modal .form-group input:focus, #service-modal .form-group select:focus, #service-modal .form-group textarea:focus { outline:none; border-color:#0d9488; box-shadow:0 0 0 2px rgba(13,148,136,0.15); }
        #service-modal .form-group.has-error input, #service-modal .form-group.has-error select, #service-modal .form-group.has-error textarea { border-color:#ef4444 !important; box-shadow:0 0 0 2px rgba(239,68,68,0.15); }
        #service-modal .form-group.has-success input, #service-modal .form-group.has-success select, #service-modal .form-group.has-success textarea { border-color:#22c55e !important; }
        #service-modal .field-error { display:block; color:#dc2626; font-size:12px; margin-top:4px; }
        #service-modal .btn-save:disabled { opacity:0.6; cursor:not-allowed; }
        #service-modal .modal-footer { display:flex; gap:10px; margin-top:18px; padding-top:18px; border-top:1px solid #e5e7eb; }
        #service-modal .btn-cancel { flex:1; padding:10px 16px; border-radius:8px; background:#f3f4f6; border:none; font-weight:600; cursor:pointer; }
        #service-modal .btn-save { flex:1; padding:10px 16px; border-radius:8px; background:#0d9488; color:#fff; border:none; font-weight:600; cursor:pointer; }
        #service-modal .btn-save:disabled { opacity:0.65; cursor:not-allowed; }
        .view-label { display:block; font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; margin-bottom:6px; }
        .view-value-box { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:10px 14px; font-size:14px; word-break:break-word; }
        .orders-table { width:100%; border-collapse:collapse; font-size:13px; table-layout:fixed; }
        .orders-table th { padding:12px 16px; font-weight:600; color:#6b7280; text-align:left; border-bottom:1px solid #e5e7eb; }
        .orders-table td { padding:12px 16px; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
        .orders-table th:nth-child(1), .orders-table td:nth-child(1) { width:72px; }
        .orders-table th:nth-child(2), .orders-table td:nth-child(2) { width:28%; }
        .orders-table th:nth-child(3), .orders-table td:nth-child(3) { width:18%; }
        .orders-table th:nth-child(4), .orders-table td:nth-child(4) { width:14%; }
        .orders-table th:nth-child(5), .orders-table td:nth-child(5) { width:auto; text-align:right; }
        .orders-table td:nth-child(5) { text-align:right; }
        .orders-table tbody tr { cursor:pointer; transition:background 0.1s; }
        .orders-table tbody tr:hover { background:#f9fafb; }
        @media (max-width:600px) { #service-modal .form-row { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php
    if (defined('MANAGER_PANEL') && MANAGER_PANEL) {
        include __DIR__ . '/../includes/manager_sidebar.php';
    } else {
        include __DIR__ . '/../includes/admin_sidebar.php';
    }
    ?>
    <div class="main-content">
        <header><h1 class="page-title"><?php echo $pf_manager_services_readonly ? 'Services' : 'Services Management'; ?></h1></header>
        <main>
            <?php if ($success): ?>
                <div style="background:#f0fdf4;border:1px solid #86efac;color:#166534;padding:12px 16px;border-radius:8px;margin-bottom:16px;">✓ <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div style="background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;padding:12px 16px;border-radius:8px;margin-bottom:16px;">✗ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="kpi-row" style="<?php echo $pf_manager_services_readonly ? 'grid-template-columns:repeat(3,1fr);' : ''; ?>">
                <div class="kpi-card indigo">
                    <div class="kpi-label">Total Services</div>
                    <div class="kpi-value"><?php echo (int)$stat_total; ?></div>
                    <div class="kpi-sub">Active list (non-archived)</div>
                </div>
                <div class="kpi-card emerald">
                    <div class="kpi-label">Active</div>
                    <div class="kpi-value"><?php echo (int)$stat_active; ?></div>
                    <div class="kpi-sub">Available for booking / quoting</div>
                </div>
                <div class="kpi-card rose">
                    <div class="kpi-label">Inactive</div>
                    <div class="kpi-value"><?php echo (int)$stat_inactive; ?></div>
                    <div class="kpi-sub">Hidden from default flows</div>
                </div>
                <?php if (!$pf_manager_services_readonly): ?>
                <div class="kpi-card slate">
                    <div class="kpi-label">Archived</div>
                    <div class="kpi-value"><?php echo (int)$stat_archived; ?></div>
                    <div class="kpi-sub">In archive storage</div>
                </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;" x-data="filterPanel()">
                    <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;" id="servicesListHeader">Service List</h3>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <?php if (!$pf_manager_services_readonly): ?>
                        <button class="toolbar-btn" type="button" onclick="openServiceModal('create')" style="height:38px;border-color:#3b82f6;color:#3b82f6;">Add Service</button>
                        <button class="toolbar-btn" type="button" onclick="window.openArchiveModal()" style="height:38px;border-color:#6b7280;color:#6b7280;display:flex;align-items:center;gap:6px;">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                            Archived
                        </button>
                        <?php endif; ?>
                        <div style="position:relative;">
                            <button type="button" class="toolbar-btn" :class="{active: sortOpen || (activeSort !== 'newest')}" @click="sortOpen = !sortOpen; filterOpen = false" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="9" y1="18" x2="15" y2="18"/></svg>
                                Sort by
                            </button>
                            <div class="sort-dropdown" x-show="sortOpen" x-cloak @click.outside="sortOpen = false">
                                <?php $sorts = ['newest' => 'Newest to Oldest', 'oldest' => 'Oldest to Newest', 'az' => 'A → Z', 'za' => 'Z → A'];
                                foreach ($sorts as $key => $label): ?>
                                <div class="sort-option" :class="{ 'selected': activeSort === '<?php echo $key; ?>' }" onclick="applySortFilter('<?php echo $key; ?>')">
                                    <?php echo htmlspecialchars($label); ?>
                                    <svg x-show="activeSort === '<?php echo $key; ?>'" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div style="position:relative;">
                            <button type="button" class="toolbar-btn" :class="{active: filterOpen || hasActiveFilters}" @click="filterOpen = !filterOpen; sortOpen = false" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                Filter
                                <span id="filterBadgeContainer">
                                    <?php $afc = count(array_filter([$search, $cat_filter, $status_filter, $date_from, $date_to], function ($v) { return $v !== null && $v !== ''; }));
                                    if ($afc > 0): ?><span class="filter-badge"><?php echo $afc; ?></span><?php endif; ?>
                                </span>
                            </button>
                            <div class="filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false">
                                <div class="filter-panel-header">Filter</div>
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Date range</span>
                                        <button class="filter-reset-link" type="button" onclick="resetFilterField(['date_from','date_to'])">Reset</button>
                                    </div>
                                    <div class="filter-date-row">
                                        <div><div class="filter-date-label">From</div><input type="date" id="fp_date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from); ?>"></div>
                                        <div><div class="filter-date-label">To</div><input type="date" id="fp_date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to); ?>"></div>
                                    </div>
                                </div>
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Category</span>
                                        <button class="filter-reset-link" type="button" onclick="resetFilterField(['category'])">Reset</button>
                                    </div>
                                    <select id="fp_category" class="filter-select">
                                        <option value="">All categories</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo $cat_filter === $cat['category'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['category']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Status</span>
                                        <button class="filter-reset-link" type="button" onclick="resetFilterField(['status'])">Reset</button>
                                    </div>
                                    <select id="fp_status" class="filter-select">
                                        <option value="">All statuses</option>
                                        <option value="Activated" <?php echo $status_filter === 'Activated' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Deactivated" <?php echo $status_filter === 'Deactivated' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Search</span>
                                        <button class="filter-reset-link" type="button" onclick="resetFilterField(['search'])">Reset</button>
                                    </div>
                                    <input type="text" id="fp_search" class="filter-search-input" placeholder="Service name or category..." value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                <div class="filter-actions">
                                    <button type="button" class="filter-btn-reset" onclick="applyFilters(true)">Reset all filters</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="servicesTableContainer">
                    <div class="overflow-x-auto">
                        <?php render_services_table_rows($services); ?>
                    </div>
                    <div id="servicesPagination">
                        <?php
                        $pagination_params = array_filter(['search' => $search, 'category' => $cat_filter, 'status' => $status_filter, 'sort' => $sort_by, 'date_from' => $date_from, 'date_to' => $date_to], function ($v) {
                            return $v !== null && $v !== '';
                        });
                        echo render_pagination($page, $total_pages, $pagination_params);
                        ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Confirm modal (z-index above printflow_form_guard overlays at 10030+) -->
<?php if (!$pf_manager_services_readonly): ?>
<div id="serviceStatusConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:10100;align-items:center;justify-content:center;padding:16px;flex-wrap:wrap;">
    <div style="background:white;border-radius:16px;padding:26px;max-width:420px;width:100%;box-shadow:0 25px 50px rgba(0,0,0,0.25);text-align:center;position:relative;z-index:1;" role="dialog" aria-modal="true" aria-labelledby="serviceStatusConfirmTitle" onclick="event.stopPropagation();">
        <h3 id="serviceStatusConfirmTitle" style="font-size:18px;font-weight:700;margin:0 0 8px;">Confirm</h3>
        <p id="serviceStatusConfirmText" style="font-size:14px;color:#4b5563;margin:0 0 16px;line-height:1.5;"></p>
        <div id="serviceStatusInfoBox" style="font-size:12px;color:#6b7280;background:#f9fafb;padding:12px;border-radius:10px;margin-bottom:20px;text-align:left;border:1px solid #e5e7eb;">
            <div id="serviceStatusInfoText"></div>
        </div>
        <div style="display:flex;gap:12px;">
            <button type="button" id="serviceStatusConfirmCancel" style="flex:1;padding:12px;border:1px solid #e5e7eb;background:white;border-radius:10px;font-weight:600;cursor:pointer;">Cancel</button>
            <button type="button" id="serviceStatusConfirmOk" style="flex:1;padding:12px;border:none;background:#3b82f6;border-radius:10px;font-weight:600;color:white;cursor:pointer;">Confirm</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add/Edit -->
<?php if (!$pf_manager_services_readonly): ?>
<div id="service-modal-overlay" onclick="handleOverlayClick(event)">
    <div id="service-modal" onclick="event.stopPropagation();">
        <div class="modal-header">
            <h3 id="modal-title" style="font-size:18px;font-weight:700;margin:0;">Add Service</h3>
            <button type="button" id="close-modal-btn" onclick="closeServiceModal()" style="background:none;border:none;cursor:pointer;color:#9ca3af;">
                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <form method="POST" id="service-form" data-pf-skip-guard novalidate enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <input type="hidden" id="modal-mode-input" name="create_service" value="1">
                <input type="hidden" id="modal-service-id" name="service_id" value="">

                <div class="form-group">
                    <label for="modal-name">Service Name <span style="color:red">*</span></label>
                    <input type="text" id="modal-name" name="name" maxlength="150" required placeholder="e.g. Large format printing">
                    <span id="err-name" class="field-error"></span>
                </div>

                <div class="form-group">
                    <label for="modal-category">Category <span style="color:red">*</span></label>
                    <select id="modal-category" name="category" required>
                        <option value="">-- Select Category --</option>
                        <?php foreach ($category_options as $opt): ?>
                            <option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span id="err-category" class="field-error"></span>
                </div>

                <div class="form-group">
                    <label for="modal-description">Description <span style="color:red">*</span></label>
                    <textarea id="modal-description" name="description" rows="3" maxlength="2000" required placeholder="What this service includes..."></textarea>
                    <span id="err-description" class="field-error"></span>
                </div>

                <div class="form-group">
                    <label>Service Photos <span style="color:red">*</span></label>
                    <small style="display:block;color:#6b7280;font-size:12px;margin-bottom:8px;">Upload 1 to 5 photos. Photos are required.</small>
                    <div class="file-upload-area" id="photo-upload-area" onclick="document.getElementById('modal-photo-files').click()">
                        <input type="file" id="modal-photo-files" name="photo_files[]" accept="image/*" multiple style="display:none;" onchange="handlePhotoUpload(this)">
                        <div class="upload-placeholder" id="photo-placeholder">
                            <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color:#9ca3af;margin-bottom:8px;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                            </svg>
                            <p style="margin:0;font-size:14px;color:#6b7280;font-weight:500;">Click to upload photos</p>
                            <p style="margin:4px 0 0;font-size:12px;color:#9ca3af;">PNG, JPG, GIF, WebP up to 5MB each</p>
                            <p style="margin:6px 0 0;font-size:11px;color:#0d9488;font-weight:600;">Max: 5 photos</p>
                        </div>
                        <div class="upload-preview-grid" id="photo-preview" style="display:none;">
                            <div id="photo-items-container" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px;"></div>
                        </div>
                    </div>
                    <span id="err-photos" class="field-error"></span>
                    <input type="hidden" id="modal-display-image" name="display_image" value="">
                </div>

                <div class="form-group">
                    <label>Service Video <span style="color:#9ca3af;font-weight:400;">(optional)</span></label>
                    <small style="display:block;color:#6b7280;font-size:12px;margin-bottom:8px;">Upload one optional video.</small>
                    <div class="file-upload-area" id="video-upload-area" onclick="document.getElementById('modal-video-file').click()">
                        <input type="file" id="modal-video-file" name="video_file" accept="video/*" style="display:none;" onchange="handleVideoUpload(this)">
                        <div class="upload-placeholder" id="video-placeholder">
                            <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color:#9ca3af;margin-bottom:8px;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                            </svg>
                            <p style="margin:0;font-size:14px;color:#6b7280;font-weight:500;">Click to upload video</p>
                            <p style="margin:4px 0 0;font-size:12px;color:#9ca3af;">MP4, WebM, MOV, AVI up to 100MB</p>
                        </div>
                        <div class="upload-preview-grid" id="video-preview" style="display:none;">
                            <div id="video-items-container" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px;"></div>
                        </div>
                    </div>
                    <input type="hidden" id="modal-video-url" name="video_url" value="">
                </div>

                <div class="form-group">
                    <label for="modal-status">Status <span style="color:red">*</span></label>
                    <select id="modal-status" name="status" required>
                        <option value="Activated">Active</option>
                        <option value="Deactivated">Inactive</option>
                    </select>
                    <span id="err-status" class="field-error"></span>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeServiceModal()">Cancel</button>
                    <button type="submit" id="modal-submit-btn" class="btn-save">Save Service</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- View -->
<div id="view-service-modal-overlay" onclick="handleViewOverlayClick(event)">
    <div id="view-service-modal" onclick="event.stopPropagation();">
        <div class="modal-header">
            <h3 style="font-size:18px;font-weight:700;margin:0;">Service Details</h3>
            <button type="button" onclick="closeViewModal()" style="background:none;border:none;cursor:pointer;color:#9ca3af;">
                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <div style="display:grid;gap:14px;">
                <div><span class="view-label">Service Name</span><div id="view-name" class="view-value-box" style="font-weight:700;">—</div></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div><span class="view-label">Category</span><div id="view-category" class="view-value-box">—</div></div>
                    <div><span class="view-label">Status</span><div id="view-status" class="view-value-box">—</div></div>
                </div>
                <div><span class="view-label">Description</span><div id="view-description" class="view-value-box" style="white-space:pre-wrap;min-height:60px;">—</div></div>
            </div>
            <div style="padding:16px 0 0;margin-top:24px;border-top:1px solid #f3f4f6;display:flex;justify-content:flex-end;">
                <button type="button" class="btn-secondary" onclick="closeViewModal()">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Archive modal (z-index above form-guard / sidebar layers) -->
<?php if (!$pf_manager_services_readonly): ?>
<div id="archive-storage-overlay" role="dialog" aria-modal="true" aria-labelledby="archive-services-title" onclick="if (event.target === this) window.closeArchiveModal()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:10090;align-items:center;justify-content:center;padding:16px;pointer-events:auto;">
    <div onclick="event.stopPropagation()" style="background:white;border-radius:16px;width:100%;max-width:900px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 25px 50px rgba(0,0,0,0.25);pointer-events:auto;">
        <div style="padding:20px 24px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;">
            <h3 id="archive-services-title" style="font-size:18px;font-weight:700;margin:0;">Archived Services</h3>
            <button type="button" onclick="window.closeArchiveModal()" style="background:none;border:none;cursor:pointer;color:#9ca3af;">✕</button>
        </div>
        <div style="padding:0;overflow-y:auto;flex:1;">
            <div id="archived-services-container" style="min-height:160px;padding:16px;">
                <p style="text-align:center;color:#9ca3af;">Loading…</p>
            </div>
        </div>
        <div style="padding:16px 24px;border-top:1px solid #e5e7eb;text-align:right;">
            <button type="button" class="btn-secondary" onclick="window.closeArchiveModal()">Close</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
window.PF_SERVICES_MANAGER_VIEW_ONLY = <?php echo $pf_manager_services_readonly ? 'true' : 'false'; ?>;
window.PF_DEFAULT_SERVICE_MODAL_TEXT = <?php echo json_encode(printflow_default_customer_service_modal_text(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.PF_SERVICE_CATEGORY_ALLOWLIST = <?php echo json_encode(printflow_allowed_service_categories(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.PF_BASE_PATH = <?php echo json_encode($base_path); ?>;
/* var: Turbo re-runs inline scripts; let/const would throw "already been declared". */
var activeSort = '<?php echo htmlspecialchars($sort_by); ?>';
var searchDebounceTimer = null;
var _serviceStatusForm = null;
var _serviceStatusButtonName = null;

function serviceMediaUrl(path) {
    let value = String(path || '').trim().replace(/\\/g, '/');
    const base = (window.PF_BASE_PATH || '').replace(/\/+$/, '');
    if (!value) return '';
    if (value.startsWith('data:') || value.startsWith('blob:')) return value;
    if (/^https?:\/\//i.test(value)) {
        try {
            const url = new URL(value, window.location.origin);
            if (url.origin !== window.location.origin) return value;
            value = url.pathname;
        } catch (e) {
            return value;
        }
    }
    value = value.replace(/^[A-Za-z]:/, '');
    const publicIndex = value.indexOf('/public/');
    if (publicIndex !== -1) value = value.slice(publicIndex);
    const uploadsIndex = value.indexOf('/uploads/');
    if (uploadsIndex !== -1 && (publicIndex === -1 || uploadsIndex < publicIndex)) value = value.slice(uploadsIndex);
    if (!base && value.startsWith('/printflow/')) value = value.slice('/printflow'.length);
    if (!value.startsWith('/')) value = '/' + value.replace(/^\/+/, '');
    if (base && !value.startsWith(base + '/')) value = base + value;
    return value;
}

function serviceMediaList(paths) {
    return String(paths || '')
        .split(',')
        .map(item => serviceMediaUrl(item))
        .filter(Boolean)
        .join(',');
}

function pfParseServiceRow(el) {
    const tr = el && el.closest ? el.closest('tr[data-pf-service]') : null;
    if (!tr) return null;
    try {
        return JSON.parse(tr.getAttribute('data-pf-service'));
    } catch (e) {
        console.error(e);
        return null;
    }
}

function filterPanel() {
    return {
        sortOpen: false,
        filterOpen: false,
        activeSort: activeSort,
        get hasActiveFilters() {
            return document.getElementById('fp_date_from')?.value ||
                document.getElementById('fp_date_to')?.value ||
                document.getElementById('fp_category')?.value ||
                document.getElementById('fp_status')?.value ||
                document.getElementById('fp_search')?.value;
        }
    };
}

function buildFilterURL(page = 1) {
    const params = new URLSearchParams();
    params.set('page', page);
    const df = document.getElementById('fp_date_from')?.value; if (df) params.set('date_from', df);
    const dt = document.getElementById('fp_date_to')?.value; if (dt) params.set('date_to', dt);
    const cat = document.getElementById('fp_category')?.value; if (cat) params.set('category', cat);
    const st = document.getElementById('fp_status')?.value; if (st) params.set('status', st);
    const s = document.getElementById('fp_search')?.value; if (s) params.set('search', s);
    if (activeSort !== 'newest') params.set('sort', activeSort);
    return '?' + params.toString();
}

function fetchUpdatedTable(page = 1) {
    fetch(buildFilterURL(page) + '&ajax=1')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const wrap = document.getElementById('servicesTableContainer');
            if (wrap) {
                wrap.innerHTML = data.table + '<div id="servicesPagination">' + data.pagination + '</div>';
                if (typeof Alpine !== 'undefined' && typeof Alpine.initTree === 'function') {
                    try {
                        Alpine.initTree(wrap);
                    } catch (e) {
                        console.error(e);
                    }
                }
            }
            const cont = document.getElementById('filterBadgeContainer');
            if (cont) cont.innerHTML = data.badge > 0 ? '<span class="filter-badge">' + data.badge + '</span>' : '';
            history.replaceState(null, '', buildFilterURL(page));
        })
        .catch(console.error);
}

function applyFilters(reset = false) {
    if (reset) {
        ['fp_date_from', 'fp_date_to', 'fp_category', 'fp_status', 'fp_search'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        activeSort = 'newest';
    }
    fetchUpdatedTable(1);
}

function resetFilterField(fields) {
    const map = { date_from: 'fp_date_from', date_to: 'fp_date_to', category: 'fp_category', status: 'fp_status', search: 'fp_search' };
    fields.forEach(f => {
        const el = document.getElementById(map[f] || f);
        if (el) el.value = '';
    });
    fetchUpdatedTable(1);
}

function applySortFilter(sortKey) {
    activeSort = sortKey;
    fetchUpdatedTable(1);
    const alpineEl = document.querySelector('[x-data="filterPanel()"]');
    if (alpineEl && alpineEl._x_dataStack) {
        alpineEl._x_dataStack[0].activeSort = sortKey;
        alpineEl._x_dataStack[0].sortOpen = false;
    }
}

function printflowInitServicesPage() {
    if (!document.getElementById('servicesListHeader')) return;

    const tableWrap = document.getElementById('servicesTableContainer');
    if (tableWrap && !tableWrap.dataset.pfSvcRowClickBound) {
        tableWrap.dataset.pfSvcRowClickBound = '1';
        tableWrap.addEventListener('click', function (e) {
            const tr = e.target.closest('tr[data-pf-service]');
            if (!tr) return;
            if (e.target.closest('td:last-child')) return;
            let svc;
            try {
                svc = JSON.parse(tr.getAttribute('data-pf-service'));
            } catch (err) {
                console.error(err);
                return;
            }
            openViewModal(svc);
        });
    }

    // Filter and Search
    ['fp_date_from', 'fp_date_to', 'fp_category', 'fp_status'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', () => fetchUpdatedTable());
    });
    const searchInput = document.getElementById('fp_search');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => fetchUpdatedTable(), 450);
        });
    }

    // Form submit guard
    document.getElementById('service-form')?.addEventListener('submit', function (e) {
        // Transfer staged files to the real inputs before submit.
        const photoInput = document.getElementById('modal-photo-files');
        if (uploadedPhotoFiles.length > 0 && photoInput.files.length === 0) {
            const dt = new DataTransfer();
            uploadedPhotoFiles.forEach(file => {
                dt.items.add(file);
            });
            photoInput.files = dt.files;
        }

        const videoInput = document.getElementById('modal-video-file');
        if (uploadedVideoFile && videoInput.files.length === 0) {
            const dtVideo = new DataTransfer();
            dtVideo.items.add(uploadedVideoFile);
            videoInput.files = dtVideo.files;
        }
        const existingPhotos = (document.getElementById('modal-display-image')?.value || '')
            .split(',')
            .map(v => v.trim())
            .filter(Boolean);
        const photoError = document.getElementById('err-photos');
        if (uploadedPhotoFiles.length === 0 && existingPhotos.length === 0) {
            e.preventDefault();
            if (photoError) photoError.textContent = 'Please provide at least one service photo.';
            return;
        }
        if (photoError) photoError.textContent = '';

        const btn = document.getElementById('modal-submit-btn');
        if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
    });

    /* #servicesTableContainer has no x-data; turbo-init initTree(.main-content) already walked it. */
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', printflowInitServicesPage);
} else {
    printflowInitServicesPage();
}
document.addEventListener('printflow:page-init', printflowInitServicesPage);

function openServiceModal(mode, svc) {
    if (window.PF_SERVICES_MANAGER_VIEW_ONLY) return;
    const overlay = document.getElementById('service-modal-overlay');
    const title = document.getElementById('modal-title');
    const modeInput = document.getElementById('modal-mode-input');
    const submitBtn = document.getElementById('modal-submit-btn');
    const form = document.getElementById('service-form');
    if (!overlay || !title || !modeInput || !submitBtn || !form) {
        console.warn('openServiceModal: service modal markup not in DOM yet.');
        return;
    }
    form.reset();

    document.getElementById('modal-category').querySelectorAll('option[data-pf-legacy-cat]').forEach(function (o) { o.remove(); });

    if (mode === 'edit') {
        if (!svc) {
            console.warn('openServiceModal: missing service row data');
            return;
        }
        title.textContent = 'Edit Service';
        modeInput.name = 'update_service';
        submitBtn.textContent = 'Save Changes';
        document.getElementById('modal-service-id').value = svc.service_id || '';
        document.getElementById('modal-name').value = svc.name || '';
        const catSel = document.getElementById('modal-category');
        const catVal = svc.category || '';
        catSel.querySelectorAll('option[data-pf-legacy-cat]').forEach(function (o) { o.remove(); });
        const allow = window.PF_SERVICE_CATEGORY_ALLOWLIST || [];
        const catNorm = String(catVal).toLowerCase();
        const allowedPick = allow.some(function (a) { return String(a).toLowerCase() === catNorm; });
        if (catVal && !allowedPick) {
            const o = document.createElement('option');
            o.value = catVal;
            o.textContent = catVal + ' (legacy — pick an allowed category to save)';
            o.setAttribute('data-pf-legacy-cat', '1');
            catSel.appendChild(o);
        }
        catSel.value = catVal;

        document.getElementById('modal-description').value = svc.description || '';
        
        // Load existing media files
        uploadedPhotoFiles = [];
        uploadedVideoFile = null;
        const existingImages = (svc.display_image || '').split(',').map(img => serviceMediaUrl(img)).filter(Boolean);
        const existingVideo = serviceMediaUrl(svc.video_url || '');
        
        // Store existing media paths in hidden fields
        document.getElementById('modal-display-image').value = existingImages.join(',');
        document.getElementById('modal-video-url').value = existingVideo;
        
        // Display existing media previews
        renderExistingPhotoPreviews(existingImages);
        renderExistingVideoPreview(existingVideo);
        document.getElementById('modal-status').value = (svc.status === 'Deactivated') ? 'Deactivated' : 'Activated';
    } else {
        title.textContent = 'Add Service';
        modeInput.name = 'create_service';
        submitBtn.textContent = 'Save Service';
        document.getElementById('modal-service-id').value = '';
        document.getElementById('modal-display-image').value = '';
        document.getElementById('modal-video-url').value = '';
        document.getElementById('modal-photo-files').value = '';
        document.getElementById('modal-video-file').value = '';
        uploadedPhotoFiles = [];
        uploadedVideoFile = null;
        renderPhotoPreviews();
        renderVideoPreview();
        document.getElementById('modal-status').value = 'Activated';
    }
    submitBtn.disabled = false;
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
    document.getElementById('modal-name').focus();
    if (typeof window.printflowServiceFormValidationRun === 'function') {
        window.printflowServiceFormValidationRun();
    }
    try {
        document.dispatchEvent(new CustomEvent('pf-service-modal-shown'));
    } catch (e) { /* ignore */ }
}

function closeServiceModal() {
    document.getElementById('service-modal-overlay').classList.remove('active');
    document.body.style.overflow = '';
    const btn = document.getElementById('modal-submit-btn');
    if (btn) { btn.disabled = false; btn.textContent = document.getElementById('modal-mode-input').name === 'update_service' ? 'Save Changes' : 'Save Service'; }
}

function handleOverlayClick(e) {
    if (e.target.id === 'service-modal-overlay') closeServiceModal();
}

function openViewModal(svc) {
    document.getElementById('view-name').textContent = svc.name || '—';
    document.getElementById('view-category').textContent = svc.category || '—';
    const st = svc.status || '';
    document.getElementById('view-status').textContent = st === 'Activated' ? 'Active' : (st === 'Deactivated' ? 'Inactive' : st);
    document.getElementById('view-description').textContent = svc.description || '—';

    // Display existing media if available
    const existingImages = (svc.display_image || '').split(',').map(img => serviceMediaUrl(img)).filter(Boolean);
    const existingVideo = serviceMediaUrl(svc.video_url || '');
    
    // Add media preview section to view modal if not exists
    let mediaSection = document.getElementById('view-media-section');
    if (!mediaSection) {
        mediaSection = document.createElement('div');
        mediaSection.id = 'view-media-section';
        const descDiv = document.getElementById('view-description').parentElement;
        descDiv.parentElement.insertBefore(mediaSection, descDiv.nextSibling);
    }
    
    if (existingImages.length > 0 || existingVideo) {
        let html = '<span class="view-label">Media</span><div class="view-value-box" style="padding:12px;">';
        html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:8px;">';
        
        existingImages.forEach((img, i) => {
            html += `<div style="position:relative;border-radius:6px;overflow:hidden;border:1px solid #e5e7eb;">
                <img src="${serviceMediaUrl(img)}" alt="Image ${i+1}" style="width:100%;height:100px;object-fit:cover;display:block;">
                <span style="position:absolute;bottom:4px;left:4px;background:rgba(0,0,0,0.7);color:white;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;">IMG ${i+1}</span>
            </div>`;
        });
        
        if (existingVideo) {
            html += `<div style="position:relative;border-radius:6px;overflow:hidden;border:1px solid #e5e7eb;">
                <video src="${serviceMediaUrl(existingVideo)}" style="width:100%;height:100px;object-fit:cover;display:block;"></video>
                <span style="position:absolute;bottom:4px;left:4px;background:rgba(0,0,0,0.7);color:white;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;">VIDEO</span>
            </div>`;
        }
        
        html += '</div></div>';
        mediaSection.innerHTML = html;
        mediaSection.style.display = 'block';
    } else {
        mediaSection.style.display = 'none';
    }
    
    document.getElementById('view-service-modal-overlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeViewModal() {
    document.getElementById('view-service-modal-overlay').classList.remove('active');
    document.body.style.overflow = '';
}

function handleViewOverlayClick(e) {
    if (e.target.id === 'view-service-modal-overlay') closeViewModal();
}

function showServiceStatusModal(event, form) {
    if (event) event.preventDefault();
    const action = form.getAttribute('data-action') || 'proceed';
    const svcName = form.getAttribute('data-service-name') || 'this service';
    _serviceStatusForm = form;
    const btn = form.querySelector('button[type="submit"]');
    _serviceStatusButtonName = btn ? btn.getAttribute('name') : null;

    if (typeof window.closeArchiveModal === 'function') window.closeArchiveModal();
    closeServiceModal();
    closeViewModal();

    document.getElementById('serviceStatusConfirmTitle').textContent = 'Confirm ' + action;
    document.getElementById('serviceStatusConfirmText').innerHTML = 'Are you sure you want to <strong>' + action.toLowerCase() + '</strong> <strong style="color:#111827;">' + svcName.replace(/</g, '&lt;') + '</strong>?';

    let msg = '';
    if (action === 'Deactivate') msg = 'This service will be marked inactive and hidden from default customer flows.';
    else if (action === 'Activate') msg = 'This service will be active again.';
    else if (action === 'Archive') msg = 'The service moves to archive storage; you can restore it later.';
    else if (action === 'Restore') msg = 'The service returns to the main list as Active.';
    else if (action === 'Delete Permanently') msg = 'This cannot be undone.';
    document.getElementById('serviceStatusInfoText').textContent = msg;

    const m = document.getElementById('serviceStatusConfirmModal');
    m.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeServiceStatusModal() {
    document.getElementById('serviceStatusConfirmModal').style.display = 'none';
    document.body.style.overflow = '';
    _serviceStatusForm = null;
    _serviceStatusButtonName = null;
}

document.getElementById('serviceStatusConfirmModal')?.addEventListener('click', function (e) {
    if (e.target === this) closeServiceStatusModal();
});

document.getElementById('serviceStatusConfirmCancel')?.addEventListener('click', closeServiceStatusModal);
document.getElementById('serviceStatusConfirmOk')?.addEventListener('click', function () {
    if (_serviceStatusForm) {
        if (_serviceStatusButtonName) {
            const hid = document.createElement('input');
            hid.type = 'hidden';
            hid.name = _serviceStatusButtonName;
            hid.value = '1';
            _serviceStatusForm.appendChild(hid);
        }
        _serviceStatusForm.submit();
    }
    closeServiceStatusModal();
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        var archiveOv = document.getElementById('archive-storage-overlay');
        if (archiveOv && archiveOv.style.display === 'flex') {
            if (typeof window.closeArchiveModal === 'function') window.closeArchiveModal();
            return;
        }
        var confirmModal = document.getElementById('serviceStatusConfirmModal');
        if (confirmModal && confirmModal.style.display === 'flex') closeServiceStatusModal();
        else { closeServiceModal(); closeViewModal(); }
    }
});

window.openArchiveModal = function openArchiveModal() {
    if (window.PF_SERVICES_MANAGER_VIEW_ONLY) return;
    var el = document.getElementById('archive-storage-overlay');
    if (!el) return;
    el.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    const c = document.getElementById('archived-services-container');
    c.innerHTML = '<p style="text-align:center;color:#9ca3af;">Loading…</p>';
    fetch('?get_archived=1').then(r => r.json()).then(data => {
        if (data.success) c.innerHTML = data.html;
        else c.innerHTML = '<p style="color:#ef4444;text-align:center;">Failed to load.</p>';
    }).catch(() => { c.innerHTML = '<p style="color:#ef4444;text-align:center;">Error loading archive.</p>'; });
};

window.closeArchiveModal = function closeArchiveModal() {
    var el = document.getElementById('archive-storage-overlay');
    if (el) el.style.display = 'none';
    document.body.style.overflow = '';
};

// Page-specific initialization is now handled above via printflowInitServicesPage.

let uploadedPhotoFiles = [];
let uploadedVideoFile = null;

function handlePhotoUpload(input) {
    const files = Array.from(input.files || []);
    let nextPhotos = uploadedPhotoFiles.slice();

    for (const file of files) {
        if (!file.type.startsWith('image/')) continue;
        if (nextPhotos.length >= 5) {
            alert('Maximum 5 photos allowed');
            continue;
        }
        if (file.size > 5 * 1024 * 1024) {
            alert(`Photo "${file.name}" exceeds 5MB limit`);
            continue;
        }
        nextPhotos.push(file);
    }

    uploadedPhotoFiles = nextPhotos;
    renderPhotoPreviews();
}

function handleVideoUpload(input) {
    const file = input.files && input.files[0] ? input.files[0] : null;
    if (!file) return;
    if (!file.type.startsWith('video/')) {
        alert('Please upload a valid video file.');
        input.value = '';
        return;
    }
    if (file.size > 100 * 1024 * 1024) {
        alert(`Video "${file.name}" exceeds 100MB limit`);
        input.value = '';
        return;
    }
    uploadedVideoFile = file;
    renderVideoPreview();
}

function renderPhotoPreviews() {
    const container = document.getElementById('photo-items-container');
    const placeholder = document.getElementById('photo-placeholder');
    const preview = document.getElementById('photo-preview');
    if (!container || !placeholder || !preview) return;

    if (uploadedPhotoFiles.length === 0) {
        placeholder.style.display = 'block';
        preview.style.display = 'none';
        container.innerHTML = '';
        return;
    }

    placeholder.style.display = 'none';
    preview.style.display = 'block';
    container.innerHTML = '';

    uploadedPhotoFiles.forEach((file, index) => {
        const div = document.createElement('div');
        div.className = 'media-item';
        const reader = new FileReader();
        reader.onload = function(e) {
            div.innerHTML = `
                <img src="${e.target.result}" alt="Photo ${index + 1}">
                <span class="media-badge">PHOTO ${index + 1}</span>
                <button type="button" class="remove-btn" onclick="removePhotoItem(${index})">
                    <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            `;
        };
        reader.readAsDataURL(file);
        container.appendChild(div);
    });
}

function renderVideoPreview() {
    const container = document.getElementById('video-items-container');
    const placeholder = document.getElementById('video-placeholder');
    const preview = document.getElementById('video-preview');
    if (!container || !placeholder || !preview) return;

    if (!uploadedVideoFile) {
        placeholder.style.display = 'block';
        preview.style.display = 'none';
        container.innerHTML = '';
        return;
    }

    placeholder.style.display = 'none';
    preview.style.display = 'block';
    const reader = new FileReader();
    reader.onload = function(e) {
        container.innerHTML = `
            <div class="media-item">
                <video src="${e.target.result}" controls></video>
                <span class="media-badge">VIDEO</span>
                <button type="button" class="remove-btn" onclick="removeVideoItem()">
                    <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        `;
    };
    reader.readAsDataURL(uploadedVideoFile);
}

function removePhotoItem(index) {
    uploadedPhotoFiles.splice(index, 1);
    const input = document.getElementById('modal-photo-files');
    if (input) input.value = '';
    renderPhotoPreviews();
}

function removeVideoItem() {
    uploadedVideoFile = null;
    const input = document.getElementById('modal-video-file');
    if (input) input.value = '';
    renderVideoPreview();
}

function renderExistingPhotoPreviews(images) {
    const container = document.getElementById('photo-items-container');
    const placeholder = document.getElementById('photo-placeholder');
    const preview = document.getElementById('photo-preview');
    if (!container || !placeholder || !preview) return;

    if (images.length === 0) {
        placeholder.style.display = 'block';
        preview.style.display = 'none';
        container.innerHTML = '';
        return;
    }

    placeholder.style.display = 'none';
    preview.style.display = 'block';
    container.innerHTML = '';

    images.forEach((imgPath, index) => {
        const div = document.createElement('div');
        div.className = 'media-item';
        div.innerHTML = `
            <img src="${serviceMediaUrl(imgPath)}" alt="Photo ${index + 1}">
            <span class="media-badge">PHOTO ${index + 1}</span>
            <button type="button" class="remove-btn" onclick="removeExistingPhoto(${index})">
                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        `;
        container.appendChild(div);
    });
}

function renderExistingVideoPreview(video) {
    const container = document.getElementById('video-items-container');
    const placeholder = document.getElementById('video-placeholder');
    const preview = document.getElementById('video-preview');
    if (!container || !placeholder || !preview) return;

    if (!video) {
        placeholder.style.display = 'block';
        preview.style.display = 'none';
        container.innerHTML = '';
        return;
    }

    placeholder.style.display = 'none';
    preview.style.display = 'block';
    container.innerHTML = `
        <div class="media-item">
            <video src="${serviceMediaUrl(video)}" controls></video>
            <span class="media-badge">VIDEO</span>
            <button type="button" class="remove-btn" onclick="removeExistingVideo()">
                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    `;
}

function removeExistingPhoto(index) {
    const displayImageInput = document.getElementById('modal-display-image');
    const images = displayImageInput.value.split(',').map(img => serviceMediaUrl(img)).filter(Boolean);
    images.splice(index, 1);
    displayImageInput.value = images.join(',');
    renderExistingPhotoPreviews(images);
}

function removeExistingVideo() {
    document.getElementById('modal-video-url').value = '';
    renderExistingVideoPreview('');
}

function bindDropZone(areaId, inputId, handler) {
    const area = document.getElementById(areaId);
    if (!area) return;

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        area.addEventListener(eventName, function(e) {
            e.preventDefault();
            e.stopPropagation();
        }, false);
    });

    ['dragenter', 'dragover'].forEach(eventName => {
        area.addEventListener(eventName, () => area.classList.add('drag-over'), false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        area.addEventListener(eventName, () => area.classList.remove('drag-over'), false);
    });

    area.addEventListener('drop', function(e) {
        const dt = e.dataTransfer;
        const input = document.getElementById(inputId);
        if (!input) return;
        input.files = dt.files;
        handler(input);
    }, false);
}

bindDropZone('photo-upload-area', 'modal-photo-files', handlePhotoUpload);
bindDropZone('video-upload-area', 'modal-video-file', handleVideoUpload);
</script>
<script src="<?php echo htmlspecialchars($base_path); ?>/public/assets/js/service-form-validation.js?v=2"></script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
