<?php
/**
 * Admin Notifications Page
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';

require_role(['Admin', 'Manager']);
// Ensure $base_path is defined
if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

$current_user = get_logged_in_user();
$admin_id = get_user_id();
$base_url = defined('BASE_URL') ? BASE_URL : (defined('BASE_PATH') ? BASE_PATH : '/printflow');
$viewer_role = (string)($current_user['role'] ?? get_user_type());
$viewer_branch_id = $viewer_role === 'Manager'
    ? (int)(printflow_branch_filter_for_user() ?? 0)
    : 0;

// Auto-delete notifications older than 1 month
db_execute("DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 MONTH)");

// Mark notification as read then redirect to target if provided.
if (isset($_GET['mark_read'])) {
    $notification_id = (int)$_GET['mark_read'];
    db_execute("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?", 'ii', [$notification_id, $admin_id]);
    if (!empty($_GET['next'])) {
        $next = (string)$_GET['next'];
        $path = parse_url($next, PHP_URL_PATH);
        $host = parse_url($next, PHP_URL_HOST);
        $base_path = parse_url($base_url, PHP_URL_PATH) ?: $base_url;
        if (!$host && $path && strpos($path, rtrim($base_path, '/') . '/') === 0) {
            redirect($next);
        }
    }
    $redirect_base = $viewer_role === 'Manager'
        ? $base_url . '/manager/notifications.php'
        : $base_url . '/admin/notifications.php';
    redirect($redirect_base);
}

// Handle actions
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    if ($action === 'mark_read' && isset($_GET['id'])) {
        $notification_id = (int)$_GET['id'];
        db_execute("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?", 'ii', [$notification_id, $admin_id]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'mark_all_read') {
        db_execute("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0", 'i', [$admin_id]);
        $redirect_base = $viewer_role === 'Manager'
            ? $base_url . '/manager/notifications.php'
            : $base_url . '/admin/notifications.php';
        redirect($redirect_base . '?success=' . urlencode('All notifications marked as read'));
    }

    if ($action === 'get_unread_count') {
        $visible_notifications = db_query(
            "SELECT * FROM notifications WHERE user_id = ? AND type != 'Message' ORDER BY created_at DESC",
            'i',
            [$admin_id]
        ) ?: [];

        if ($viewer_role === 'Manager' && $viewer_branch_id > 0) {
            $visible_notifications = array_values(array_filter(
                $visible_notifications,
                static fn(array $notif): bool => printflow_admin_notification_visible($notif, $viewer_branch_id, 'Manager')
            ));
        }

        $latest_id = 0;
        $unread_total = 0;
        foreach ($visible_notifications as $notif) {
            $latest_id = max($latest_id, (int)($notif['notification_id'] ?? 0));
            if ((int)($notif['is_read'] ?? 0) === 0) {
                $unread_total++;
            }
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'count' => $unread_total,
            'latest_id' => $latest_id,
        ]);
        exit;
    }
    

}

// Get filter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort_by = $_GET['sort'] ?? 'newest';

// Build query
$where = "user_id = ? AND type != 'Message'";
$params = [$admin_id];
$types = 'i';

if ($filter === 'unread') {
    $where .= " AND is_read = 0";
} elseif (in_array($filter, ['Order', 'Stock', 'System'])) {
    $where .= " AND type = ?";
    $params[] = $filter;
    $types .= 's';
}

if (!empty($search)) {
    $where .= " AND message LIKE ?";
    $params[] = "%$search%";
    $types .= 's';
}

// Sort order
$order_clause = match($sort_by) {
    'oldest' => "created_at ASC",
    default  => "created_at DESC"
};

$all_notifications = db_query(
    "SELECT * FROM notifications WHERE $where ORDER BY $order_clause",
    $types,
    $params
) ?: [];

if ($viewer_role === 'Manager' && $viewer_branch_id > 0) {
    $all_notifications = array_values(array_filter(
        $all_notifications,
        static fn(array $notif): bool => printflow_admin_notification_visible($notif, $viewer_branch_id, 'Manager')
    ));
}

$per_page = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$total_count = count($all_notifications);
$total_pages = max(1, (int)ceil(max(1, $total_count) / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;
$notifications = array_slice($all_notifications, $offset, $per_page);

$unread_count = count(array_filter(
    $all_notifications,
    static fn(array $notif): bool => (int)($notif['is_read'] ?? 0) === 0
));
$latest_notification_id = 0;
foreach ($all_notifications as $notif) {
    $latest_notification_id = max($latest_notification_id, (int)($notif['notification_id'] ?? 0));
}

$notif_filter_badge = ($filter !== 'all' ? 1 : 0) + ($search !== '' ? 1 : 0);
$notif_pagination_params = ['filter' => $filter, 'sort' => $sort_by];
if ($search !== '') {
    $notif_pagination_params['search'] = $search;
}

$page_title = 'Notifications - Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo $base_path; ?>/public/assets/css/output.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        [x-cloak] { display: none !important; }
        /* Products-style toolbar + filter (notifications) */
        .notif-card-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            padding: 18px 20px 14px;
            border-bottom: 1px solid #f3f4f6;
        }
        .notif-card-head h3 { margin: 0; font-size: 16px; font-weight: 700; color: #1f2937; }
        .notif-card-sub { font-size: 12px; color: #6b7280; margin-top: 4px; }
        .toolbar-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border: 1px solid #e5e7eb;
            background: #fff;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
            transition: all 0.15s;
            white-space: nowrap;
            height: 38px;
        }
        .toolbar-btn:hover { border-color: #9ca3af; background: #f9fafb; }
        .toolbar-btn.active { border-color: #0d9488; color: #0d9488; background: #f0fdfa; }
        .toolbar-btn svg { flex-shrink: 0; }

        /* Sort Dropdown */
        .sort-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            width: 180px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            z-index: 200;
            padding: 6px;
        }
        .sort-option {
            padding: 9px 12px;
            font-size: 13px;
            color: #4b5563;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .sort-option:hover { background: #f9fafb; color: #111827; }
        .sort-option.selected { background: #f0fdfa; color: #0d9488; font-weight: 600; }
        .sort-option svg.check { color: #0d9488; }

        .filter-panel {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            width: 320px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            z-index: 200;
            overflow: hidden;
        }
        .filter-panel-header {
            padding: 14px 18px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
            font-weight: 700;
            color: #111827;
        }
        .filter-section { padding: 14px 18px; border-bottom: 1px solid #f3f4f6; }
        .filter-section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .filter-section-label { font-size: 13px; font-weight: 600; color: #374151; }
        .filter-reset-link {
            font-size: 12px;
            font-weight: 600;
            color: #0d9488;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
        }
        .filter-reset-link:hover { text-decoration: underline; }
        .filter-select {
            width: 100%;
            height: 34px;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            font-size: 13px;
            padding: 0 10px;
            color: #1f2937;
            background: #fff;
            box-sizing: border-box;
            cursor: pointer;
        }
        .filter-select:focus { outline: none; border-color: #0d9488; }
        .filter-search-input {
            width: 100%;
            height: 34px;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            font-size: 13px;
            padding: 0 12px;
            color: #1f2937;
            box-sizing: border-box;
        }
        .filter-search-input:focus { outline: none; border-color: #0d9488; }
        .filter-actions { display: flex; gap: 8px; padding: 14px 18px; border-top: 1px solid #f3f4f6; }
        .filter-btn-reset {
            flex: 1;
            height: 36px;
            border: 1px solid #e5e7eb;
            background: #fff;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
        }
        .filter-btn-reset:hover { background: #f9fafb; }
        .filter-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            background: #0d9488;
            color: #fff;
            border-radius: 50%;
            font-size: 10px;
            font-weight: 700;
        }
        .notif-header-primary {
            height: 38px;
            padding: 0 16px;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            background: #0d9488;
            color: #fff !important;
            border: none;
            border-radius: 8px;
            font-weight: 600;
        }
        .notif-header-primary:hover { background: #0f766e; color: #fff !important; }

        /* ── Notification Row ──────────────────────── */
        .notif-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 16px 0;
            border-bottom: 1px solid #f3f4f6;
            transition: background 0.1s;
            cursor: pointer;
        }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: #fafafa; margin: 0 -20px; padding: 16px 20px; border-radius: 8px; }
        .notif-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #0d9488; flex-shrink: 0; margin-top: 6px;
        }
        .notif-dot.read { background: transparent; border: 2px solid #e5e7eb; }
        .notif-icon-wrap {
            width: 38px; height: 38px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
            overflow: hidden;
        }
        .notif-icon-wrap.order { background: #dbeafe; color: #1e40af; }
        .notif-icon-wrap.stock { background: #fef3c7; color: #b45309; }
        .notif-icon-wrap.system { background: #f3f4f6; color: #374151; }
        .notif-thumb { width: 100%; height: 100%; object-fit: cover; display: block; }
        .notif-body { flex: 1; min-width: 0; }
        .notif-msg {
            font-size: 13px; font-weight: 500; color: #111827;
            line-height: 1.5; margin-bottom: 4px;
        }
        .notif-item.read .notif-msg { color: #6b7280; font-weight: 400; }
        .notif-time {
            font-size: 12px; color: #9ca3af; display: flex; align-items: center; gap: 6px;
        }
        .type-pill {
            display: inline-block; padding: 2px 8px; border-radius: 20px;
            font-size: 11px; font-weight: 600;
        }
        .type-pill.order { background: #dbeafe; color: #1e40af; }
        .type-pill.stock { background: #fef3c7; color: #b45309; }
        .type-pill.system { background: #f3f4f6; color: #374151; }
        .notif-actions-wrap {
            display: flex; gap: 6px; flex-shrink: 0; align-items: flex-start; padding-top: 2px;
            position: relative;
            z-index: 1;
        }
        .notif-action-btn {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 500;
            border: 1px solid #e5e7eb; background: #fff; color: #374151; cursor: pointer; transition: all 0.15s;
        }
        .notif-action-btn:hover { background: #f3f4f6; }
        .notif-action-btn.danger { color: #dc2626; border-color: #fecaca; background: #fff5f5; }
        .notif-action-btn.danger:hover { background: #fee2e2; }

        /* ── Group Label ──────────────────────────── */
        .notif-group-label {
            font-size: 11px; font-weight: 700; color: #9ca3af;
            text-transform: uppercase; letter-spacing: 0.06em;
            padding: 16px 0 8px;
        }

        /* ── Empty State ──────────────────────────── */
        .empty-notif {
            text-align: center; padding: 60px 20px;
        }
        .empty-notif-icon {
            width: 64px; height: 64px; border-radius: 50%;
            background: #f3f4f6; margin: 0 auto 16px;
            display: flex; align-items: center; justify-content: center;
        }
        .empty-notif-title { font-size: 16px; font-weight: 700; color: #111827; margin-bottom: 6px; }
        .empty-notif-text { font-size: 13px; color: #9ca3af; }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . ($current_user['role'] === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>

    <!-- Main Content -->
    <div class="main-content">
        <header>
            <div class="pf-mobile-title-group">
                <h1 class="page-title" style="margin-bottom:4px;">Notifications</h1>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <button type="button" id="pf-push-toggle" class="btn-secondary mobile-hidden" style="height:38px;padding:0 16px;font-size:13px;display:inline-flex;align-items:center;gap:6px;cursor:pointer;position:relative;z-index:5;pointer-events:auto;" onclick="return window.PFNotifications && window.PFNotifications.handlePushToggleClick ? (window.PFNotifications.handlePushToggleClick(this), false) : false;">
                    Enable notifications
                </button>
                <button onclick="refreshNotifications()" class="btn-secondary mobile-hidden" style="height:38px;padding:0 16px;font-size:13px;display:inline-flex;align-items:center;gap:6px;">
                    <svg class="mobile-hidden" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    Refresh
                </button>
                <?php if ($unread_count > 0): ?>
                <a href="?action=mark_all_read" class="notif-header-primary">
                    <svg class="mobile-hidden" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    Mark All Read
                </a>
                <?php endif; ?>
            </div>
        </header>

        <main>
            <div class="card" style="padding:0;overflow:hidden;">
                <div class="notif-card-head" x-data="notifFilterPanel()">
                    <div>
                        <h3>All Notifications</h3>
                        <div class="notif-card-sub">Page <?php echo (int)$page; ?> of <?php echo (int)$total_pages; ?> · <?php echo number_format($total_count); ?> total</div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <!-- Sort Button -->
                        <div style="position:relative;">
                            <button type="button" class="toolbar-btn" :class="{active: sortOpen || (activeSort !== 'newest')}" @click="sortOpen = !sortOpen; filterOpen = false" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="9" y1="18" x2="15" y2="18"/>
                                </svg>
                                Sort by
                            </button>
                            <div class="sort-dropdown" x-show="sortOpen" x-cloak @click.outside="sortOpen = false">
                                <div class="sort-option" :class="{'selected': activeSort === 'newest'}" @click="applySortFilter('newest')">
                                    Newest to Oldest
                                    <svg x-show="activeSort === 'newest'" class="check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <div class="sort-option" :class="{'selected': activeSort === 'oldest'}" @click="applySortFilter('oldest')">
                                    Oldest to Newest
                                    <svg x-show="activeSort === 'oldest'" class="check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                            </div>
                        </div>

                        <!-- Filter Button -->
                        <div style="position:relative;">
                            <button type="button" class="toolbar-btn" :class="{active: filterOpen || hasActiveFilters}" @click="filterOpen = !filterOpen" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                                </svg>
                                Filter
                                <?php if ($notif_filter_badge > 0): ?>
                                <span class="filter-badge"><?php echo $notif_filter_badge; ?></span>
                                <?php endif; ?>
                            </button>
                            <div class="filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false">
                                <div class="filter-panel-header">Filter</div>
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Type</span>
                                        <button type="button" class="filter-reset-link" onclick="notifResetField('filter')">Reset</button>
                                    </div>
                                    <select id="nt_fp_filter" class="filter-select">
                                        <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All notifications</option>
                                        <option value="unread" <?php echo $filter === 'unread' ? 'selected' : ''; ?>>Unread only</option>
                                        <option value="Order" <?php echo $filter === 'Order' ? 'selected' : ''; ?>>Orders</option>
                                        <option value="Stock" <?php echo $filter === 'Stock' ? 'selected' : ''; ?>>Inventory</option>
                                        <option value="System" <?php echo $filter === 'System' ? 'selected' : ''; ?>>System</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Keyword search</span>
                                        <button type="button" class="filter-reset-link" onclick="notifResetField('search')">Reset</button>
                                    </div>
                                    <input type="text" id="nt_fp_search" class="filter-search-input" placeholder="Search message..." value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                <div class="filter-actions">
                                    <button type="button" class="filter-btn-reset" style="width:100%;" onclick="notifResetAllFilters()">Reset all filters</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="padding:0 20px 12px;" id="notifications-container">
                    <?php if (empty($notifications)): ?>
                        <div class="empty-notif">
                            <div class="empty-notif-icon">
                                <svg width="28" height="28" fill="none" stroke="#9ca3af" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                                </svg>
                            </div>
                            <div class="empty-notif-title">No notifications</div>
                            <p class="empty-notif-text">
                                <?php if ($filter === 'unread'): ?>You're all caught up! No unread notifications.
                                <?php elseif (!empty($search)): ?>No results for "<?php echo htmlspecialchars($search); ?>"
                                <?php else: ?>You don't have any notifications yet.<?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <?php 
                        $grouped = ['New' => [], 'Earlier' => []];
                        foreach ($notifications as $n) {
                            $grouped[$n['is_read'] == 0 ? 'New' : 'Earlier'][] = $n;
                        }
                        $grouped = array_filter($grouped);

                        foreach ($grouped as $group => $notifs): ?>
                            <div class="notif-group-label"><?php echo $group; ?></div>
                            <?php foreach ($notifs as $notif):
                                $type     = strtolower($notif['type']);
                                $is_unread = !$notif['is_read'];
                                $target_url = admin_notification_target_url($notif);
                                $base_path_val = defined('BASE_PATH') ? rtrim(BASE_PATH, '/') : '/printflow';
                                $defaultNotifImage = $base_path_val . '/public/assets/images/services/default.png';
                                $notifImage = staff_admin_notification_image_url($notif, $defaultNotifImage);
                                $displayMessage = printflow_notification_display_message($notif);
                                $iconSvg = match($type) {
                                    'order'  => '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>',
                                    'stock'  => '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>',
                                    default  => '<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
                                };
                            ?>
                            <div class="notif-item <?php echo $is_unread ? '' : 'read'; ?>"
                                 role="button"
                                 tabindex="0"
                                 data-id="<?php echo (int)$notif['notification_id']; ?>"
                                 data-unread="<?php echo $is_unread ? '1' : '0'; ?>"
                                 data-target-url="<?php echo htmlspecialchars($target_url, ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="notif-dot <?php echo $is_unread ? '' : 'read'; ?>"></div>
                                <div class="notif-icon-wrap <?php echo $type; ?>">
                                    <img src="<?php echo htmlspecialchars($notifImage); ?>" alt="" class="notif-thumb" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($defaultNotifImage, ENT_QUOTES); ?>';">
                                </div>
                                <div class="notif-body">
                                    <div class="notif-msg"><?php echo htmlspecialchars($displayMessage); ?></div>
                                    <div class="notif-time">
                                        <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        <?php echo time_ago($notif['created_at']); ?>
                                        <span class="type-pill <?php echo $type; ?>"><?php echo ucfirst($type); ?></span>
                                    </div>
                                </div>
                                <div class="notif-actions-wrap">
                                    <?php if ($is_unread): ?>
                                    <button onclick="markAsRead(<?php echo $notif['notification_id']; ?>)" class="notif-action-btn">
                                        <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        Read
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div id="nt-pagination" style="padding: 0 20px 20px;">
                    <?php echo render_pagination($page, $total_pages, $notif_pagination_params); ?>
                </div>
            </div>
        </main>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
var ntSearchTimer = null;
var activeSort = '<?php echo $sort_by; ?>';
window.PF_ADMIN_NOTIF_LATEST_ID = <?php echo (int)$latest_notification_id; ?>;
window.PF_ADMIN_NOTIF_HAS_PENDING_UPDATES = false;

function notifFilterPanel() {
    return {
        filterOpen: false,
        sortOpen: false,
        activeSort: '<?php echo $sort_by; ?>',
        get hasActiveFilters() {
            var f = document.getElementById('nt_fp_filter');
            var s = document.getElementById('nt_fp_search');
            var fv = f ? f.value : 'all';
            var sv = s ? (s.value || '').trim() : '';
            return fv !== 'all' || sv.length > 0;
        }
    };
}
window.notifFilterPanel = notifFilterPanel;

function buildNotifFilterURL(page = 1) {
    const params = new URLSearchParams();
    params.set('page', page);
    const f = document.getElementById('nt_fp_filter')?.value;
    if (f && f !== 'all') params.set('filter', f);
    const s = document.getElementById('nt_fp_search')?.value;
    if (s) params.set('search', s);
    if (activeSort !== 'newest') params.set('sort', activeSort);
    return '?' + params.toString();
}

function notifNavigate(page) {
    window.location.href = buildNotifFilterURL(page);
}

function applySortFilter(sortKey) {
    activeSort = sortKey;
    notifNavigate(1);
}

function notifResetAllFilters() {
    window.location.href = window.location.pathname || 'notifications.php';
}
function notifResetField(which) {
    if (which === 'filter') {
        var el = document.getElementById('nt_fp_filter');
        if (el) el.value = 'all';
    }
    if (which === 'search') {
        var el2 = document.getElementById('nt_fp_search');
        if (el2) el2.value = '';
    }
    notifNavigate(1);
}

function checkForNewNotifications() {
    fetch('?action=get_unread_count', { credentials: 'include' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (data) {
            if (!data || !data.success) return;

            if (window.PFNotifications && typeof window.PFNotifications.updateBadge === 'function') {
                window.PFNotifications.updateBadge(data.count || 0);
            }

            var latestId = parseInt(data.latest_id || 0, 10);
            var currentLatestId = parseInt(window.PF_ADMIN_NOTIF_LATEST_ID || 0, 10);
            if (latestId > currentLatestId) {
                window.PF_ADMIN_NOTIF_HAS_PENDING_UPDATES = true;
                var refreshButton = document.querySelector('button[onclick="refreshNotifications()"]');
                if (refreshButton && !refreshButton.dataset.pendingLabelApplied) {
                    refreshButton.dataset.pendingLabelApplied = '1';
                    refreshButton.title = 'New notifications available';
                }
            }
        })
        .catch(function () { /* silently ignore polling errors */ });
}

function refreshNotifications() {
    window.location.reload();
}

function markAsRead(notifId, redirectUrl) {
    redirectUrl = redirectUrl || null;
    fetch('?action=mark_read&id=' + notifId)
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                if (redirectUrl) {
                    window.location.href = redirectUrl;
                    return;
                }
                var item = document.querySelector('[data-id="' + notifId + '"]');
                if (item) {
                    item.classList.add('read');
                    var dot = item.querySelector('.notif-dot');
                    if (dot) dot.classList.add('read');
                    var readBtn = item.querySelector('.notif-action-btn:not(.danger)');
                    if (readBtn) readBtn.remove();
                }
                setTimeout(function() { window.location.reload(); }, 500);
            }
        })
        .catch(function(error) { console.error('Error:', error); });
}

function bindNotifRowNavigation() {
    var c = document.getElementById('notifications-container');
    if (!c || c._pf_notif_rows) return;
    c._pf_notif_rows = true;
    c.addEventListener('click', function (e) {
        var row = e.target.closest('.notif-item');
        if (!row || !c.contains(row)) return;
        if (e.target.closest('.notif-actions-wrap')) return;
        var url = row.getAttribute('data-target-url') || '';
        if (!url) return;
        var id = parseInt(row.getAttribute('data-id'), 10);
        var unread = row.getAttribute('data-unread') === '1';
        if (unread) {
            markAsRead(id, url);
        } else {
            window.location.href = url;
        }
    });
    c.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var row = e.target.closest('.notif-item');
        if (!row || !c.contains(row) || document.activeElement !== row) return;
        e.preventDefault();
        var url = row.getAttribute('data-target-url') || '';
        if (!url) return;
        var id = parseInt(row.getAttribute('data-id'), 10);
        var unread = row.getAttribute('data-unread') === '1';
        if (unread) markAsRead(id, url);
        else window.location.href = url;
    });
}

// Turbo-safe init: called on first load and on every Turbo navigation
function printflowInitNotificationsPage() {
    if (!document.getElementById('notifications-container')) return;

    bindNotifRowNavigation();

    // Re-attach filter input listeners (idempotent)
    var sel = document.getElementById('nt_fp_filter');
    if (sel && !sel._pf_bound) {
        sel._pf_bound = true;
        sel.addEventListener('change', function () { notifNavigate(1); });
    }
    var inp = document.getElementById('nt_fp_search');
    if (inp && !inp._pf_bound) {
        inp._pf_bound = true;
        inp.addEventListener('input', function () {
            clearTimeout(ntSearchTimer);
            ntSearchTimer = setTimeout(function () { notifNavigate(1); }, 500);
        });
    }

    // Clear any existing auto-refresh interval before starting a new one
    if (window._pf_ntInterval) {
        clearInterval(window._pf_ntInterval);
        window._pf_ntInterval = null;
    }
    window._pf_ntInterval = setInterval(checkForNewNotifications, 10000);
}

// First full page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', printflowInitNotificationsPage);
} else {
    printflowInitNotificationsPage();
}

// Every Turbo navigation
document.addEventListener('printflow:page-init', printflowInitNotificationsPage);

// Stop auto-refresh when tab is hidden; restart when visible
document.addEventListener('visibilitychange', function () {
    if (!document.getElementById('notifications-container')) return;
    if (document.hidden) {
        if (window._pf_ntInterval) {
            clearInterval(window._pf_ntInterval);
            window._pf_ntInterval = null;
        }
    } else {
        if (!window._pf_ntInterval) {
            window._pf_ntInterval = setInterval(checkForNewNotifications, 10000);
        }
    }
});

// Clear on Turbo navigation away from this page
document.addEventListener('turbo:before-visit', function () {
    if (window._pf_ntInterval) {
        clearInterval(window._pf_ntInterval);
        window._pf_ntInterval = null;
    }
});
</script>

</body>
</html>
