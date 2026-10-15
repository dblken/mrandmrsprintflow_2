<?php
/**
 * Admin Activity Logs Page
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('Admin');
// Ensure $base_path is defined
if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}
$current_user = get_logged_in_user();

// Pagination & Sorting defaults (print_all fetches all matching logs)
$page = (int)($_GET['page'] ?? 1);
$per_page = !empty($_GET['print_all']) ? 100000 : 20;
$offset = !empty($_GET['print_all']) ? 0 : ($page - 1) * 20;

$sort_by = sanitize($_GET['sort_by'] ?? 'created_at');
$dir = strtoupper(sanitize($_GET['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

$search = sanitize($_GET['search'] ?? '');
$role = sanitize($_GET['role'] ?? '');
$date_from = sanitize($_GET['date_from'] ?? '');
$date_to = sanitize($_GET['date_to'] ?? '');

$sql_base = "SELECT al.log_id, al.user_id, al.action AS action_type, al.details AS description, al.created_at, 
           CONCAT(u.first_name, ' ', u.last_name) as user_name, u.role 
           FROM activity_logs al 
           LEFT JOIN users u ON al.user_id = u.user_id 
           WHERE 1=1
             AND al.action <> 'Legacy historical generator markers repaired'
             AND al.action <> 'Historical generated data hidden'
             AND al.action <> 'Historical transaction batch generated'
             AND al.action <> 'Historical generated data removed'
             AND al.action <> 'Historical generated statuses repaired'
             AND al.action <> 'customer_archive_restore_all'
             AND al.action <> 'customer_archive_hide_all'
             AND al.action <> 'Import Demo Seed Batch'
             AND al.action <> 'Delete Demo Seed Batch'";

$params = [];
$types = '';

if ($search) {
    $sql_base .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR al.action LIKE ? OR al.details LIKE ?)";
    $p = "%$search%";
    $params = array_merge($params, [$p, $p, $p, $p]);
    $types .= 'ssss';
}
if ($role) {
    $sql_base .= " AND u.role = ?";
    $params[] = $role;
    $types .= 's';
}
if ($date_from && $date_to) {
    $sql_base .= " AND DATE(al.created_at) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
    $types .= 'ss';
}

$sort_col_sql = match($sort_by) {
    'user_name' => 'user_name',
    'action_type' => 'al.action',
    default => 'al.created_at'
};

$total_sql = "SELECT COUNT(*) as total FROM ($sql_base) as t";
$total_res = db_query($total_sql, $types ?: null, $params ?: null);
$total_records = $total_res[0]['total'] ?? 0;
$total_pages = $per_page > 20 ? 1 : max(1, (int)ceil($total_records / $per_page));

$query_sql = $sql_base . " ORDER BY $sort_col_sql $dir LIMIT $per_page OFFSET $offset";
$logs = db_query($query_sql, $types ?: null, $params ?: null) ?: [];

// AJAX Handler
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    ob_start();
    ?>
    <table class="logs-table">
        <thead>
            <tr>
                <th class="col-timestamp">Timestamp</th>
                <th class="col-user">User</th>
                <th class="col-role">Role</th>
                <th class="col-action">Action</th>
                <th class="col-desc">Description</th>
            </tr>
        </thead>
        <tbody id="logsTableBody">
            <?php if (empty($logs)): ?>
                <tr><td colspan="5" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">No activity logs found matching the filters.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log):
                    $roleLower = strtolower($log['role'] ?? '');
                    $roleBadgeClass = $roleLower === 'admin' ? 'admin' : ($roleLower === 'manager' ? 'manager' : 'staff');
                ?>
                    <tr>
                        <td style="color:#6b7280;font-size:12px;"><?php echo format_datetime($log['created_at']); ?></td>
                        <td style="font-weight:500;color:#111827;"><?php echo htmlspecialchars($log['user_name'] ?? 'N/A'); ?></td>
                        <td><span class="role-badge <?php echo $roleBadgeClass; ?>"><?php echo $log['role'] ?? 'N/A'; ?></span></td>
                        <td style="font-weight:600;color:#374151;"><?php echo htmlspecialchars($log['action_type']); ?></td>
                        <td title="<?php echo htmlspecialchars($log['description']); ?>" style="color:#6b7280;"><?php echo htmlspecialchars($log['description']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
    $table_html = ob_get_clean();

    ob_start();
    $pp = array_filter(['search'=>$search, 'role'=>$role, 'date_from'=>$date_from, 'date_to'=>$date_to], function($v) { return $v !== null && $v !== ''; });
    echo render_pagination($page, $total_pages, $pp);
    $pagination_html = ob_get_clean();

    echo json_encode([
        'success' => true,
        'table' => $table_html,
        'pagination' => $pagination_html,
        'count' => $total_records,
        'showing' => count($logs),
        'offset_start' => $offset + 1,
        'offset_end' => $offset + count($logs)
    ]);
    exit;
}

$page_title = 'Activity Logs - Admin';

// Define variables needed by footer
$base_url = '/printflow';
$url_products = '<?php echo $base_path; ?>/public/products.php';
$is_logged_in = true;

$print_date_range = 'All dates';
if ($date_from && $date_to) {
    $print_date_range = date('F j, Y', strtotime($date_from)) . ' – ' . date('F j, Y', strtotime($date_to));
} elseif ($date_from) {
    $print_date_range = 'From ' . date('F j, Y', strtotime($date_from));
} elseif ($date_to) {
    $print_date_range = 'Through ' . date('F j, Y', strtotime($date_to));
}
$print_role_label = $role !== '' ? $role : 'All roles';
$print_search_label = $search !== '' ? $search : '—';
$print_generated = date('F j, Y, g:i A');
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
        @keyframes fadeIn { from { opacity: 0; transform: scale(0.98); } to { opacity: 1; transform: scale(1); } }

        /* Standardized Toolbar Styles */
        /* Standardized Toolbar Styles */
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
        }
        .toolbar-btn:hover { border-color: #9ca3af; background: #f9fafb; }
        .toolbar-btn.active { border-color: #0d9488; color: #0d9488; background: #f0fdfa; }
        .toolbar-btn svg { flex-shrink: 0; }

        /* ── Filter Panel ─── */
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
        .filter-section {
            padding: 14px 18px;
            border-bottom: 1px solid #f3f4f6;
        }
        .filter-section:last-of-type { border-bottom: none; }
        .filter-section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .filter-section-label {
            font-size: 13px;
            font-weight: 600;
            color: #374151;
        }
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
        .filter-input {
            width: 100%;
            height: 34px;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            font-size: 13px;
            padding: 0 10px;
            color: #1f2937;
            box-sizing: border-box;
            transition: border-color 0.15s;
        }
        .filter-input:focus { outline: none; border-color: #0d9488; }
        .filter-date-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .filter-date-label { font-size: 11px; color: #6b7280; margin-bottom: 4px; }
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
        .filter-actions {
            display: flex;
            gap: 8px;
            padding: 14px 18px;
            border-top: 1px solid #f3f4f6;
        }
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

        /* ── Sort Dropdown ─── */
        .sort-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            min-width: 204px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            z-index: 200;
            padding: 6px 0;
            overflow: hidden;
        }
        .sort-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            font-size: 13px;
            color: #374151;
            cursor: pointer;
            transition: background 0.1s;
        }
        .sort-option:hover { background: #f9fafb; }
        .sort-option.selected { color: #0d9488; font-weight: 600; background: #f0fdfa; }
        .sort-option svg.check { margin-left: auto; color: #0d9488; }

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
        [x-cloak] { display: none !important; }

        /* Logs Table */
        .logs-table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        .logs-table th { padding: 12px 16px; font-size: 13px; font-weight: 600; color: #6b7280; text-align: left; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
        .logs-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; color: #374151; word-break: break-word; }
        .logs-table tbody tr:hover td { background: #f9fafb; }
        .logs-table tbody tr:last-child td { border-bottom: none; }
        .logs-table .col-timestamp { width: 18%; }
        .logs-table .col-user { width: 18%; }
        .logs-table .col-role { width: 12%; }
        .logs-table .col-action { width: 18%; }
        .logs-table .col-desc { width: 34%; }
        .role-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .role-badge.admin { background: #fee2e2; color: #991b1b; }
        .role-badge.staff { background: #dbeafe; color: #1e40af; }
        .role-badge.manager { background: #ede9fe; color: #5b21b6; }

        /* ── Print: simple B&W report (matches PrintFlow report print style) ── */
        .activity-print-only { display: none !important; }
        @media print {
            .activity-print-only { display: block !important; }
            .activity-no-print { display: none !important; }
            #printflow-persistent-sidebar,
            #mobileBurger,
            #sidebarOverlay { display: none !important; }
            html, body {
                height: auto !important;
                overflow: visible !important;
            }
            body {
                background: #fff !important;
                color: #000 !important;
                font-family: Arial, Helvetica, sans-serif !important;
                font-size: 11px;
                margin: 0;
                padding: 0;
            }
            .dashboard-container { display: block !important; min-height: 0 !important; }
                .main-content {
                margin-left: 0 !important;
                overflow: visible !important;
                width: 100% !important;
                max-width: 100% !important;
                height: auto !important;
                padding: 0 !important;
            }
            main { padding: 0 !important; }
            #logsTableContainer, .overflow-x-auto { overflow: visible !important; max-height: none !important; }
            .card {
                overflow: visible !important;
                border: none !important;
                box-shadow: none !important;
                background: transparent !important;
                padding: 0 !important;
            }
            .activity-print-only {
                padding: 0 !important;
            }
            .activity-print-body {
                padding: 0 0 16px 0;
            }
            .activity-print-header-block {
                text-align: left;
                margin: 0;
                padding: 0;
            }
            .activity-print-doc-title {
                font-size: 22px;
                font-weight: 700;
                color: #000;
                margin: 0 0 10px;
                padding: 0;
                letter-spacing: -0.02em;
                line-height: 1.2;
            }
            .activity-print-meta {
                font-size: 11px;
                line-height: 1.5;
                color: #333;
                margin: 0 0 0 0;
                padding: 0;
            }
            .activity-print-meta-line {
                margin-bottom: 4px;
            }
            .activity-print-meta-line:last-child {
                margin-bottom: 0;
            }
            .activity-print-meta strong { font-weight: 600; color: #000; }
            .activity-print-rule-thick {
                border: none;
                border-bottom: 2px solid #000;
                margin: 12px 0 16px;
            }
            .activity-print-section-title {
                font-size: 12px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.4px;
                margin: 0 0 10px;
                color: #000;
                text-align: left;
            }
            .logs-table {
                width: 100%;
                table-layout: auto !important;
                font-size: 10px;
                border-collapse: collapse;
            }
            .logs-table thead { display: table-header-group; }
            .logs-table th {
                background: transparent !important;
                color: #000 !important;
                font-size: 9px !important;
                font-weight: 700 !important;
                text-transform: uppercase;
                letter-spacing: 0.35px;
                padding: 8px 8px 8px 0 !important;
                border-bottom: 1px solid #000 !important;
                white-space: nowrap;
            }
            .logs-table td {
                padding: 7px 8px 7px 0 !important;
                border-bottom: 1px solid #bbb !important;
                color: #000 !important;
                background: transparent !important;
                vertical-align: top;
            }
            .logs-table tbody tr:hover td { background: transparent !important; }
            .logs-table tbody tr:last-child td { border-bottom: 1px solid #bbb !important; }
            .logs-table .role-badge {
                background: transparent !important;
                color: #000 !important;
                padding: 0 !important;
                border-radius: 0 !important;
                font-weight: 500 !important;
                font-size: inherit !important;
            }
            @page {
                margin: 15mm;
                size: A4;
            }
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . ($current_user['role'] === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>

    <script>
    var searchDebounceTimer = null;
    function activityDoPrint(e) {
        if (e) e.preventDefault();
        try { window.print(); } catch (err) { console.error('Print failed', err); }
    }

    function buildFilterURL(overrides = {}, isAjax = false) {
        const params = new URLSearchParams(window.location.search);
        const fields = {
            search: () => document.getElementById('fp_search')?.value || '',
            role: () => document.getElementById('fp_role')?.value || '',
            date_from: () => document.getElementById('fp_date_from')?.value || '',
            date_to: () => document.getElementById('fp_date_to')?.value || ''
        };
        for (const [key, getter] of Object.entries(fields)) {
            const val = (overrides[key] !== undefined) ? overrides[key] : getter();
            if (val) params.set(key, val);
            else params.delete(key);
        }
        if (overrides.sort !== undefined) {
            if (overrides.sort && overrides.sort !== 'newest') params.set('sort', overrides.sort);
            else params.delete('sort');
        }
        if (isAjax) params.set('ajax', '1');
        else params.delete('ajax');
        params.delete('page');
        return window.location.pathname + '?' + params.toString();
    }

    async function fetchUpdatedTable(overrides = {}) {
        const url = buildFilterURL(overrides, true);
        try {
            const resp = await fetch(url);
            const data = await resp.json();
            if (data.success) {
                const tc = document.getElementById('logsTableContainer');
                if (tc) {
                    tc.innerHTML = data.table;
                    if (typeof Alpine !== 'undefined' && typeof Alpine.initTree === 'function') {
                        Alpine.initTree(tc);
                    }
                }
                const pc = document.getElementById('logsPagination');
                if (pc) pc.innerHTML = data.pagination;
                const bc = document.getElementById('filterBadgeContainer');
                if (bc) bc.innerHTML = data.badge > 0 ? `<span class="filter-badge">${data.badge}</span>` : '';
                window.dispatchEvent(new CustomEvent('filter-badge-update', { detail: { badge: data.badge } }));
                const displayUrl = buildFilterURL(overrides, false);
                window.history.replaceState({ path: displayUrl }, '', displayUrl);
                
                // Update print meta
                const rangeTxt = (document.getElementById('fp_date_from')?.value || 'All Time') + ' - ' + (document.getElementById('fp_date_to')?.value || 'All Time');
                const rangeEl = document.getElementById('apPrintMetaDateRange');
                if (rangeEl) rangeEl.textContent = rangeTxt;
                const roleEl = document.getElementById('apPrintMetaRole');
                if (roleEl) roleEl.textContent = document.getElementById('fp_role')?.value || 'All';
                const searchEl = document.getElementById('apPrintMetaSearch');
                if (searchEl) searchEl.textContent = document.getElementById('fp_search')?.value || 'All';
            }
        } catch (e) { console.error('Error updating table:', e); }
    }

    function applyFilters(resetAll = false) {
        if (resetAll) {
            const base = window.location.pathname;
            const branch = new URLSearchParams(window.location.search).get('branch_id');
            const target = base + (branch ? '?branch_id=' + encodeURIComponent(branch) : '');
            window.location.href = target;
        } else { fetchUpdatedTable(); }
    }

    function applySortFilter(sortKey) {
        window.dispatchEvent(new CustomEvent('sort-changed', { detail: { sortKey } }));
        fetchUpdatedTable({ sort: sortKey });
    }

    function resetFilterField(fields) {
        fields.forEach(f => {
            const el = document.getElementById('fp_' + f);
            if (el) el.value = '';
        });
        fetchUpdatedTable();
    }

    /* var: safe when Turbo re-executes this inline script */
    var _activeSortKey = '<?php echo $sort_by; ?>';
    var _hasActiveFilters = <?php echo (!empty($search) || !empty($role_filter) || !empty($date_from) || !empty($date_to)) ? 'true' : 'false'; ?>;

    function filterPanel() {
        return {
            filterOpen: false,
            sortOpen: false,
            activeSort: _activeSortKey,
            hasActiveFilters: _hasActiveFilters,
            init() {
                window.addEventListener('filter-badge-update', e => { this.hasActiveFilters = (e.detail.badge > 0); });
                window.addEventListener('sort-changed', e => { this.activeSort = e.detail.sortKey; this.sortOpen = false; });
            }
        };
    }

    function printflowInitActivityLogsPage() {
        if (typeof Alpine === 'undefined' || typeof Alpine.initTree !== 'function') return;
        var roots = document.querySelectorAll('[x-data]');
        roots.forEach(r => {
            if (!r._x_dataStack) {
                try { Alpine.initTree(r); } catch (e) { console.error(e); }
            }
        });
        ['fp_role', 'fp_date_from', 'fp_date_to'].forEach(id => {
            const el = document.getElementById(id);
            if (el && !el._pf_bound) {
                el._pf_bound = true;
                el.addEventListener('change', () => fetchUpdatedTable());
            }
        });
        const searchInput = document.getElementById('fp_search');
        if (searchInput && !searchInput._pf_bound) {
            searchInput._pf_bound = true;
            searchInput.addEventListener('input', () => {
                clearTimeout(searchDebounceTimer);
                searchDebounceTimer = setTimeout(() => { fetchUpdatedTable(); }, 500);
            });
        }
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', printflowInitActivityLogsPage); }
    else { printflowInitActivityLogsPage(); }
    document.addEventListener('printflow:page-init', printflowInitActivityLogsPage);
    </script>


    <!-- Main Content -->
    <div class="main-content">
        <header class="activity-no-print">
            <h1 class="page-title">Activity Logs</h1>
            <button class="btn-secondary" onclick="activityDoPrint(event)" id="printLogsBtn">
                Print Logs
            </button>
        </header>

        <main x-data="filterPanel()">
            <div class="activity-print-only" aria-hidden="true">
                <div class="activity-print-body">
                    <div class="activity-print-header-block">
                        <h1 class="activity-print-doc-title">PrintFlow Activity Logs Report</h1>
                        <div class="activity-print-meta">
                            <div class="activity-print-meta-line"><strong>Report Type:</strong> Activity Logs</div>
                            <div class="activity-print-meta-line"><strong>Date Range:</strong> <span id="apPrintMetaDateRange"><?php echo htmlspecialchars($print_date_range); ?></span></div>
                            <div class="activity-print-meta-line"><strong>Role:</strong> <span id="apPrintMetaRole"><?php echo htmlspecialchars($print_role_label); ?></span></div>
                            <div class="activity-print-meta-line"><strong>Keyword:</strong> <span id="apPrintMetaSearch"><?php echo htmlspecialchars($print_search_label); ?></span></div>
                            <div class="activity-print-meta-line"><strong>Generated On:</strong> <?php echo htmlspecialchars($print_generated); ?></div>
                        </div>
                    </div>
                    <hr class="activity-print-rule-thick">
                    <h2 class="activity-print-section-title">Activity logs</h2>
                </div>
            </div>
            <!-- Activity Logs Card -->
            <div class="card">
                <div class="activity-no-print" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
                    <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;">Activity Logs List</h3>
                    
                    <div style="display:flex; align-items:center; gap:8px;">
                        <!-- Sort Button -->
                        <div style="position:relative;">
                            <button class="toolbar-btn" :class="{active: sortOpen}" @click="sortOpen = !sortOpen; filterOpen = false" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="9" y1="18" x2="15" y2="18"/></svg>
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
                                <div class="sort-option" :class="{'selected': activeSort === 'az'}" @click="applySortFilter('az')">
                                    User A → Z
                                    <svg x-show="activeSort === 'az'" class="check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <div class="sort-option" :class="{'selected': activeSort === 'za'}" @click="applySortFilter('za')">
                                    User Z → A
                                    <svg x-show="activeSort === 'za'" class="check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                            </div>
                        </div>

                        <!-- Filter Button -->
                        <div style="position:relative;">
                            <button class="toolbar-btn" :class="{active: filterOpen || hasActiveFilters}" @click="filterOpen = !filterOpen; sortOpen = false" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                Filter
                                <span id="filterBadgeContainer"></span>
                            </button>
                            <div class="filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false">
                                <div class="filter-panel-header">Filter</div>
                                
                                <!-- Date Range -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Date range</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['date_from', 'date_to'])">Reset</button>
                                    </div>
                                    <div class="filter-date-row">
                                        <div><div class="filter-date-label">From:</div><input type="date" id="fp_date_from" class="filter-input" value="<?php echo $date_from; ?>"></div>
                                        <div><div class="filter-date-label">To:</div><input type="date" id="fp_date_to" class="filter-input" value="<?php echo $date_to; ?>"></div>
                                    </div>
                                </div>

                                <!-- Role -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Role</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['role'])">Reset</button>
                                    </div>
                                    <select id="fp_role" class="filter-select">
                                        <option value="">All Roles</option>
                                        <option value="Admin">Admin</option>
                                        <option value="Manager">Manager</option>
                                        <option value="Staff">Staff</option>
                                    </select>
                                </div>

                                <!-- Keyword -->
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Keyword search</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['search'])">Reset</button>
                                    </div>
                                    <input type="text" id="fp_search" class="filter-search-input" placeholder="Search logs..." value="<?php echo htmlspecialchars($search); ?>">
                                </div>

                                <div class="filter-actions">
                                    <button class="filter-btn-reset" onclick="applyFilters(true)">Reset all filters</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="logsTableContainer" class="overflow-x-auto">
                    <table class="logs-table">
                        <thead>
                            <tr>
                                <th class="col-timestamp">Timestamp</th>
                                <th class="col-user">User</th>
                                <th class="col-role">Role</th>
                                <th class="col-action">Action</th>
                                <th class="col-desc">Description</th>
                            </tr>
                        </thead>
                        <tbody id="logsTableBody">
                            <?php if (empty($logs)): ?>
                                <tr><td colspan="5" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">No activity logs found</td></tr>
                            <?php else: ?>
                                <?php foreach ($logs as $log): 
                                    $roleLower = strtolower($log['role'] ?? '');
                                    $roleBadgeClass = $roleLower === 'admin' ? 'admin' : ($roleLower === 'manager' ? 'manager' : 'staff');
                                ?>
                                    <tr>
                                        <td style="color:#6b7280;font-size:12px;"><?php echo format_datetime($log['created_at']); ?></td>
                                        <td style="font-weight:500;color:#111827;"><?php echo htmlspecialchars($log['user_name'] ?? 'N/A'); ?></td>
                                        <td><span class="role-badge <?php echo $roleBadgeClass; ?>"><?php echo $log['role'] ?? 'N/A'; ?></span></td>
                                        <td style="font-weight:600;color:#374151;"><?php echo htmlspecialchars($log['action_type']); ?></td>
                                        <td title="<?php echo htmlspecialchars($log['description']); ?>" style="color:#6b7280;"><?php echo htmlspecialchars($log['description']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="logsPagination" class="activity-no-print">
                    <?php 
                        $pp = array_filter(['search'=>$search, 'role'=>$role, 'date_from'=>$date_from, 'date_to'=>$date_to], function($v) { return $v !== null && $v !== ''; });
                        echo render_pagination($page, $total_pages, $pp); 
                    ?>
                </div>
            </div>
        </main>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
