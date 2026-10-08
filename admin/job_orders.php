<?php
/**
 * Admin: Job Orders — Read-Only View with Branch Filtering
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/branch_ui.php';

require_role(['Admin', 'Manager']);
// Ensure $base_path is defined
if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

$current_user = get_logged_in_user();

// Branch context (analytics mode — Allow "All")
$branchCtx = init_branch_context(false);
$branchId  = $branchCtx['selected_branch_id'];

// Filters
$search          = trim($_GET['search'] ?? '');
$status_filter   = $_GET['status'] ?? '';
$payment_filter  = $_GET['payment'] ?? '';
$sort            = $_GET['sort'] ?? 'created_at';
$dir             = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$page            = max(1, (int)($_GET['page'] ?? 1));
$per_page        = 15;

$sort_cols = ['id','customer_name','service_type','created_at','estimated_total','status','payment_status'];
$sort = in_array($sort, $sort_cols) ? $sort : 'created_at';
$sort_col_sql = match($sort) {
    'customer_name'  => "CONCAT(c.first_name,' ',c.last_name)",
    'estimated_total'=> 'jo.estimated_total',
    'service_type'   => 'jo.service_type',
    'status'         => 'jo.status',
    'payment_status' => 'jo.payment_status',
    'id'             => 'jo.id',
    default          => 'jo.created_at',
};

// Build query
$sql = "SELECT jo.*, CONCAT(c.first_name, ' ', c.last_name) AS customer_name, c.email AS customer_email,
               b.branch_name
        FROM job_orders jo
        LEFT JOIN customers c ON jo.customer_id = c.customer_id
        LEFT JOIN branches b ON jo.branch_id = b.id
        WHERE 1=1 AND " . printflow_order_archive_exclusion_sql('jo.order_id');
$params = []; $types = '';

// Branch filter
[$bSql, $bTypes, $bParams] = branch_where_parts('jo', $branchId);
if ($bSql !== '') {
    $sql    .= $bSql;
    $types  .= $bTypes;
    $params  = array_merge($params, $bParams);
}

if (!empty($search)) {
    $s = '%' . $search . '%';
    $sql   .= " AND (CONCAT(c.first_name,' ',c.last_name) LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR jo.service_type LIKE ? OR jo.id LIKE ?)";
    $params = array_merge($params, [$s,$s,$s,$s,$s]);
    $types .= 'sssss';
}

if (!empty($status_filter)) {
    $sql   .= " AND jo.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($payment_filter)) {
    $sql   .= " AND jo.payment_status = ?";
    $params[] = $payment_filter;
    $types .= 's';
}

// Count
$count_sql      = preg_replace('/SELECT jo\.\*.*?FROM/s', 'SELECT COUNT(*) as total FROM', $sql);
$total_filtered = db_query($count_sql, $types ?: null, $params ?: null)[0]['total'] ?? 0;
$total_pages    = max(1, ceil($total_filtered / $per_page));
$page           = min($page, $total_pages);
$offset         = ($page - 1) * $per_page;

$sql .= " ORDER BY $sort_col_sql $dir LIMIT $per_page OFFSET $offset";
$jobs = db_query($sql, $types ?: null, $params ?: null) ?: [];

// Sort helpers
$build_sort_url = function(string $col) use ($sort, $dir): string {
    $params = array_filter([
        'sort'    => $col,
        'dir'     => ($sort === $col && $dir === 'ASC') ? 'DESC' : 'ASC',
        'search'  => $_GET['search'] ?? '',
        'status'  => $_GET['status'] ?? '',
        'payment' => $_GET['payment'] ?? '',
        'branch_id'=> $_GET['branch_id'] ?? '',
    ]);
    return '?' . http_build_query($params);
};
$sort_icon = fn(string $col): string => $sort === $col ? ($dir === 'ASC' ? ' ▲' : ' ▼') : '';

// KPIs — branch-aware
[$bSqlKpi, $bTypesKpi, $bParamsKpi] = branch_where_parts('jo', $branchId);
$bSqlKpi .= ' AND ' . printflow_order_archive_exclusion_sql('jo.order_id');
$kpi_total   = db_query("SELECT COUNT(*) as c FROM job_orders jo WHERE 1=1$bSqlKpi",  $bTypesKpi ?: null, $bParamsKpi ?: null)[0]['c'] ?? 0;

[$bSqlK2, $bTK2, $bPK2] = branch_where_parts('jo', $branchId);
$bSqlK2 .= ' AND ' . printflow_order_archive_exclusion_sql('jo.order_id');
$kpi_pending = db_query("SELECT COUNT(*) as c FROM job_orders jo WHERE status IN ('PENDING','APPROVED')$bSqlK2", $bTK2 ?: null, $bPK2 ?: null)[0]['c'] ?? 0;

[$bSqlK3, $bTK3, $bPK3] = branch_where_parts('jo', $branchId);
$bSqlK3 .= ' AND ' . printflow_order_archive_exclusion_sql('jo.order_id');
$kpi_active  = db_query("SELECT COUNT(*) as c FROM job_orders jo WHERE status = 'IN_PRODUCTION'$bSqlK3", $bTK3 ?: null, $bPK3 ?: null)[0]['c'] ?? 0;

[$bSqlK4, $bTK4, $bPK4] = branch_where_parts('jo', $branchId);
$bSqlK4 .= ' AND ' . printflow_order_archive_exclusion_sql('jo.order_id');
$kpi_done    = db_query("SELECT COUNT(*) as c FROM job_orders jo WHERE status = 'COMPLETED'$bSqlK4", $bTK4 ?: null, $bPK4 ?: null)[0]['c'] ?? 0;

$page_title = 'Customization - Admin | PrintFlow';

function jo_status_badge($status) {
    $map = [
        'PENDING'       => ['background:#fef9c3;color:#92400e', 'Pending'],
        'APPROVED'      => ['background:#dbeafe;color:#1e40af', 'Approved'],
        'TO_PAY'        => ['background:#fce7f3;color:#9d174d', 'To Pay'],
        'IN_PRODUCTION' => ['background:#d1fae5;color:#065f46', 'In Production'],
        'TO_RECEIVE'    => ['background:#ede9fe;color:#5b21b6', 'To Receive'],
        'COMPLETED'     => ['background:#f0fdf4;color:#166534', 'Completed'],
        'CANCELLED'     => ['background:#fee2e2;color:#991b1b', 'Cancelled'],
    ];
    [$style, $label] = $map[$status] ?? ['background:#f3f4f6;color:#6b7280', $status];
    return "<span style=\"display:inline-flex;align-items:center;padding:3px 10px;border-radius:9999px;font-size:11px;font-weight:600;$style\">$label</span>";
}

function jo_payment_badge($status) {
    $map = [
        'UNPAID'               => ['background:#fee2e2;color:#991b1b', 'Unpaid'],
        'PENDING_VERIFICATION' => ['background:#fef9c3;color:#92400e', 'Verifying'],
        'PARTIAL'              => ['background:#fef3c7;color:#b45309', 'Partial'],
        'PAID'                 => ['background:#d1fae5;color:#065f46', 'Paid'],
    ];
    [$style, $label] = $map[$status] ?? ['background:#f3f4f6;color:#6b7280', $status];
    return "<span style=\"display:inline-flex;align-items:center;padding:3px 10px;border-radius:9999px;font-size:11px;font-weight:600;$style\">$label</span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="<?php echo $base_path; ?>/public/assets/css/output.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <?php render_branch_css(); ?>
    <style>
        .btn-action {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 6px 12px; border: 1px solid transparent; background: transparent;
            border-radius: 6px; font-size: 12px; font-weight: 500; transition: all 0.2s;
            cursor: pointer; text-decoration: none;
        }
        .btn-action.blue  { color:#3b82f6; border-color:#3b82f6; }
        .btn-action.blue:hover  { background:#3b82f6; color:white; }
        .btn-action.teal  { color:#14b8a6; border-color:#14b8a6; }
        .btn-action.teal:hover  { background:#14b8a6; color:white; }

        .modal-overlay { position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:9999; }
        .modal-panel { background:#fff;border-radius:12px;box-shadow:0 25px 50px rgba(0,0,0,0.25);width:100%;max-width:640px;max-height:88vh;overflow-y:auto;margin:16px; }
        [x-cloak] { display:none !important; }
        @keyframes spin { to { transform:rotate(360deg); } }

        .search-box { position:relative; }
        .search-box input { padding-left:36px;width:240px;height:38px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;background:#fff;transition:border-color 0.2s; }
        .search-box input:focus { border-color:#3b82f6;outline:none;box-shadow:0 0 0 3px rgba(59,130,246,.1); }
        .search-box .search-icon { position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;pointer-events:none; }

        .kpi-row { display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px; }
        @media(max-width:768px){ .kpi-row{grid-template-columns:repeat(2,1fr);} }
        .kpi-card { background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;position:relative;overflow:hidden; }
        .kpi-card::before { content:'';position:absolute;top:0;left:0;right:0;height:3px; }
        .kpi-card.indigo::before { background:linear-gradient(90deg,#6366f1,#818cf8); }
        .kpi-card.amber::before  { background:linear-gradient(90deg,#f59e0b,#fbbf24); }
        .kpi-card.blue::before   { background:linear-gradient(90deg,#3b82f6,#60a5fa); }
        .kpi-card.emerald::before{ background:linear-gradient(90deg,#059669,#34d399); }
        .kpi-label { font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;margin-bottom:6px; }
        .kpi-sub   { font-size:12px;color:#6b7280;margin-top:4px; }

        .mobile-header { display:none; }
        @media (max-width:768px) {
            .mobile-header { display:flex;position:fixed;top:0;left:0;right:0;height:60px;background:#fff;z-index:60;padding:0 20px;align-items:center;justify-content:space-between;border-bottom:1px solid #e5e7eb; }
        }

        .detail-row { display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px; }
        .detail-block { flex:1;min-width:140px;background:#f9fafb;border-radius:8px;padding:12px 14px; }
        .detail-block label { font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:4px; }
        .detail-block span  { font-size:13px;font-weight:600;color:#1f2937; }

        .tab-btn { padding: 8px 16px; font-size: 13px; font-weight: 600; border-radius: 8px; transition: all 0.2s; cursor: pointer; border: 1px solid transparent; }
        .tab-btn.active { background: #eef2ff; color: #4f46e5; border-color: #c7d2fe; }
        .tab-btn:not(.active) { color: #6b7280; }
        .history-item { padding: 10px; border-bottom: 1px solid #f3f4f6; display: flex; justify-content: space-between; align-items: center; }
        .history-item:last-child { border-bottom: none; }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include defined('MANAGER_PANEL') ? __DIR__ . '/../includes/manager_sidebar.php' : __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <script>
        function jobOrderModal() {
            return {
                showModal: false,
                loading: false,
                errorMsg: '',
                job: null,
                items: [],
                showHistory: false,
                historyLoading: false,
                historyOrders: [],
                historyCustoms: [],
                historyTab: 'orders',
                historyCustomerName: '',

                openModal(id) {
                    this.showModal = true;
                    this.loading = true;
                    this.errorMsg = '';
                    this.job = null;
                    this.items = [];
                    fetch('<?php echo $base_path; ?>/admin/job_orders_api.php?action=get_order&id=' + id, { credentials: 'same-origin' })
                        .then(r => r.json())
                        .then(data => {
                            this.loading = false;
                            if (data.success) {
                                this.job = data.data || data.job;
                                this.items = data.items || [];
                                if (this.job?.customer_id) {
                                    this.fetchHistory(this.job.customer_id, this.job.customer_name);
                                }
                            } else { this.errorMsg = data.error || 'Failed to load details'; }
                        })
                        .catch(() => { this.loading = false; this.errorMsg = 'Network error'; });
                },

                async fetchHistory(customerId, name) {
                    if (!customerId) return;
                    this.historyCustomerName = name || 'Customer';
                    this.historyLoading = true;
                    this.historyOrders = [];
                    this.historyCustoms = [];
                    try {
                        const [ordersRes, customsRes] = await Promise.all([
                            fetch(`<?php echo BASE_PATH; ?>/admin/api_order_details.php?customer_id=${customerId}`, { credentials: 'same-origin' }),
                            fetch(`<?php echo BASE_PATH; ?>/admin/job_orders_api.php?action=list_orders&customer_id=${customerId}`, { credentials: 'same-origin' })
                        ]);
                        const ordersData = await ordersRes.json();
                        const customsData = await customsRes.json();
                        this.historyOrders = Array.isArray(ordersData) ? ordersData : (ordersData.data || []);
                        this.historyCustoms = customsData.data || [];
                    } catch (e) { console.error('History fetch error', e); } finally { this.historyLoading = false; }
                },

                openHistory(id, name) {
                    this.showHistory = true;
                    this.fetchHistory(id, name);
                },

                statusBadge(status) {
                    const map = { PENDING: 'background:#fef9c3;color:#92400e', APPROVED: 'background:#dbeafe;color:#1e40af', TO_PAY: 'background:#fce7f3;color:#9d174d', IN_PRODUCTION: 'background:#d1fae5;color:#065f46', TO_RECEIVE: 'background:#ede9fe;color:#5b21b6', COMPLETED: 'background:#f0fdf4;color:#166534', CANCELLED: 'background:#fee2e2;color:#991b1b' };
                    const labels = { PENDING:'Pending', APPROVED:'Approved', TO_PAY:'To Pay', IN_PRODUCTION:'In Production', TO_RECEIVE:'To Receive', COMPLETED:'Completed', CANCELLED:'Cancelled' };
                    const s = map[status] || 'background:#f3f4f6;color:#6b7280';
                    return `<span style="display:inline-flex;align-items:center;padding:3px 10px;border-radius:9999px;font-size:11px;font-weight:600;${s}">${labels[status] || status}</span>`;
                },

                paymentBadge(status) {
                    const map = { UNPAID: 'background:#fee2e2;color:#991b1b', PENDING_VERIFICATION: 'background:#fef9c3;color:#92400e', PARTIAL: 'background:#fef3c7;color:#b45309', PAID: 'background:#d1fae5;color:#065f46' };
                    const labels = { UNPAID:'Unpaid', PENDING_VERIFICATION:'Verifying', PARTIAL:'Partial', PAID:'Paid' };
                    const s = map[status] || 'background:#f3f4f6;color:#6b7280';
                    return `<span style="display:inline-flex;align-items:center;padding:3px 10px;border-radius:9999px;font-size:11px;font-weight:600;${s}">${labels[status] || status}</span>`;
                }
            };
        }

        function printflowInitJobOrdersPage() {
            if (typeof Alpine === 'undefined' || typeof Alpine.initTree !== 'function') return;
            var root = document.querySelector('main[x-data="jobOrderModal()"]');
            if (root && !root._x_dataStack) {
                try { Alpine.initTree(root); } catch (e) { console.error(e); }
            }
        }

        window.openJobModal = function(id) {
            function run() {
                var m = document.querySelector('main[x-data="jobOrderModal()"]');
                var st = m && m._x_dataStack;
                if (st && st[0] && typeof st[0].openModal === 'function') {
                    st[0].openModal(id);
                    return true;
                }
                return false;
            }
            if (run()) return;
            printflowInitJobOrdersPage();
            if (run()) return;
            setTimeout(run, 100);
        };

        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', printflowInitJobOrdersPage); }
        else { printflowInitJobOrdersPage(); }

        function printflowOpenJobFromQuery() {
            var oj = new URLSearchParams(window.location.search).get('open_job');
            if (!oj) return;
            var jid = parseInt(oj, 10);
            if (!(jid > 0)) return;
            requestAnimationFrame(function () { openJobModal(jid); });
        }
        printflowOpenJobFromQuery();
        </script>
    <div class="main-content">

        <header>
            <div style="display:flex;align-items:center;gap:10px;">
                <h1 class="page-title">Customization</h1>
            </div>
            <?php render_branch_selector($branchCtx); ?>
        </header>



        <main x-data="jobOrderModal()">
            <?php render_branch_context_banner($branchCtx['branch_name']); ?>

            <!-- KPI Row -->
            <div class="kpi-row">
                <div class="kpi-card indigo">
                    <div class="kpi-label">Total</div>
                    <div class="kpi-value"><?php echo $kpi_total; ?></div>
                    <div class="kpi-sub">All customizations</div>
                </div>
                <div class="kpi-card amber">
                    <div class="kpi-label">Pending / Approved</div>
                    <div class="kpi-value"><?php echo $kpi_pending; ?></div>
                    <div class="kpi-sub">Awaiting production</div>
                </div>
                <div class="kpi-card blue">
                    <div class="kpi-label">In Production</div>
                    <div class="kpi-value"><?php echo $kpi_active; ?></div>
                    <div class="kpi-sub">Currently printing</div>
                </div>
                <div class="kpi-card emerald">
                    <div class="kpi-label">Completed</div>
                    <div class="kpi-value"><?php echo $kpi_done; ?></div>
                    <div class="kpi-sub">Finished orders</div>
                </div>
            </div>

            <!-- Table Card -->
            <div class="card">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px;">
                    <span style="font-size:13px; color:#6b7280; white-space:nowrap;">Showing <strong style="color:#1f2937;"><?php echo $total_filtered; ?></strong> orders</span>
                    
                    <form method="GET" style="display:flex; gap:8px; align-items:center; flex-wrap:nowrap;" id="filterForm">
                        <?php if ($branchId !== 'all'): ?>
                            <input type="hidden" name="branch_id" value="<?php echo (int)$branchId; ?>">
                        <?php endif; ?>
                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                        <input type="hidden" name="dir" value="<?php echo htmlspecialchars($dir); ?>">
                        <select name="payment" onchange="this.form.submit()" style="height:38px; padding:0 10px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px; background:#fff;">
                            <option value="">Payment: All</option>
                            <option value="UNPAID" <?php echo $payment_filter==='UNPAID'?'selected':''; ?>>Unpaid</option>
                            <option value="PENDING_VERIFICATION" <?php echo $payment_filter==='PENDING_VERIFICATION'?'selected':''; ?>>Pending Verification</option>
                            <option value="PARTIAL" <?php echo $payment_filter==='PARTIAL'?'selected':''; ?>>Partial</option>
                            <option value="PAID" <?php echo $payment_filter==='PAID'?'selected':''; ?>>Paid</option>
                        </select>
                        <select name="status" onchange="this.form.submit()" style="height:38px; padding:0 10px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px; background:#fff;">
                            <option value="">Status: All</option>
                            <?php foreach(['PENDING','APPROVED','TO_PAY','IN_PRODUCTION','TO_RECEIVE','COMPLETED','CANCELLED'] as $s): ?>
                                <option value="<?php echo $s; ?>" <?php echo $status_filter===$s?'selected':''; ?>>
                                    <?php echo ucwords(strtolower(str_replace('_',' ',$s))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="search-box">
                            <svg class="search-icon" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6 m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <input type="text" name="search" id="searchInput" placeholder="Search customer or service..." value="<?php echo htmlspecialchars($search); ?>" onkeydown="if(event.key==='Enter'){this.form.submit();}" style="width:200px;">
                        </div>
                    </form>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b-2">
                                <th class="text-center py-3 pl-1" style="width:70px;"><a href="<?php echo $build_sort_url('id'); ?>" style="text-decoration:none;color:inherit;">#<?php echo $sort_icon('id'); ?></a></th>
                                <th class="text-left py-3"><a href="<?php echo $build_sort_url('customer_name'); ?>" style="text-decoration:none;color:inherit;">Customer<?php echo $sort_icon('customer_name'); ?></a></th>
                                <th class="text-left py-3"><a href="<?php echo $build_sort_url('service_type'); ?>" style="text-decoration:none;color:inherit;">Service<?php echo $sort_icon('service_type'); ?></a></th>
                                <?php if ($branchId === 'all'): ?>
                                <th class="text-left py-3">Branch</th>
                                <?php endif; ?>
                                <th class="text-center py-3"><a href="<?php echo $build_sort_url('status'); ?>" style="text-decoration:none;color:inherit;">Status<?php echo $sort_icon('status'); ?></a></th>
                                <th class="text-center py-3"><a href="<?php echo $build_sort_url('payment_status'); ?>" style="text-decoration:none;color:inherit;">Payment<?php echo $sort_icon('payment_status'); ?></a></th>
                                <th class="text-right py-3"><a href="<?php echo $build_sort_url('estimated_total'); ?>" style="text-decoration:none;color:inherit;">Est. Total<?php echo $sort_icon('estimated_total'); ?></a></th>
                                <th class="text-center py-3"><a href="<?php echo $build_sort_url('created_at'); ?>" style="text-decoration:none;color:inherit;">Date Submitted<?php echo $sort_icon('created_at'); ?></a></th>
                                <th class="text-right py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="jobOrdersTableBody">
                            <?php $colspan = ($branchId === 'all') ? 9 : 8; ?>
                            <?php if (empty($jobs)): ?>
                                <tr id="emptyJobOrdersRow"><td colspan="<?php echo $colspan; ?>" class="py-12 text-center text-gray-400">
                                    <?php echo $search ? 'No customizations found matching "' . htmlspecialchars($search) . '"' : 'No customizations found'; ?>
                                </td></tr>
                            <?php else: ?>
                                <tr id="emptyJobOrdersRow" style="display:none;"><td colspan="<?php echo $colspan; ?>" class="py-12 text-center text-gray-400">
                                    No customizations found
                                </td></tr>
                                <?php foreach ($jobs as $jo): ?>
                                    <tr class="border-b hover:bg-gray-50">
                                        <td class="py-3 text-center font-medium text-gray-500"><?php echo $jo['id']; ?></td>
                                        <td class="py-3">
                                            <div class="font-semibold text-gray-900" style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($jo['customer_name'] ?: 'Walk-in Customer'); ?>">
                                                <?php echo htmlspecialchars($jo['customer_name'] ?: 'Walk-in Customer'); ?>
                                            </div>
                                            <div class="text-xs text-gray-400" style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($jo['customer_email'] ?? ''); ?>">
                                                <?php echo htmlspecialchars($jo['customer_email'] ?? ''); ?>
                                            </div>
                                        </td>
                                        <td class="py-3">
                                            <div style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($jo['service_type']); ?>">
                                                <?php echo htmlspecialchars($jo['service_type']); ?>
                                            </div>
                                            <?php if (($jo['quantity'] ?? 1) > 1): ?>
                                                <div class="text-xs text-gray-400">Qty: <?php echo $jo['quantity']; ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($branchId === 'all'): ?>
                                        <td class="py-3">
                                            <?php echo get_branch_badge_html((int)($jo['branch_id'] ?? 0), $jo['branch_name'] ?? 'Unknown'); ?>
                                        </td>
                                        <?php endif; ?>
                                        <td class="py-3 text-center">
                                            <?php
                                                $sc = match($jo['status']) {
                                                    'PENDING'       => 'background:#fef9c3;color:#92400e;',
                                                    'APPROVED'      => 'background:#dbeafe;color:#1e40af;',
                                                    'TO_PAY'        => 'background:#fce7f3;color:#9d174d;',
                                                    'IN_PRODUCTION' => 'background:#d1fae5;color:#065f46;',
                                                    'TO_RECEIVE'    => 'background:#ede9fe;color:#5b21b6;',
                                                    'COMPLETED'     => 'background:#f0fdf4;color:#166534;',
                                                    'CANCELLED'     => 'background:#fee2e2;color:#991b1b;',
                                                    default         => 'background:#fef9c3;color:#854d0e;'
                                                };
                                            ?>
                                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;<?php echo $sc; ?>">
                                                <?php echo ucwords(strtolower(str_replace('_',' ',$jo['status']))); ?>
                                            </span>
                                        </td>
                                        <td class="py-3 text-center">
                                            <?php
                                                $pc = match($jo['payment_status']) {
                                                    'UNPAID'               => 'background:#fee2e2;color:#991b1b;',
                                                    'PENDING_VERIFICATION' => 'background:#fef9c3;color:#854d0e;',
                                                    'PARTIAL'              => 'background:#fef3c7;color:#b45309;',
                                                    'PAID'                 => 'background:#d1fae5;color:#065f46;',
                                                    default                => 'background:#fef9c3;color:#854d0e;'
                                                };
                                            ?>
                                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;<?php echo $pc; ?>">
                                                <?php echo ucwords(strtolower(str_replace('_',' ',$jo['payment_status']))); ?>
                                            </span>
                                        </td>
                                        <td class="py-3 text-right font-semibold">
                                            <?php echo $jo['estimated_total'] ? '₱' . number_format($jo['estimated_total'], 2) : '<span class="text-gray-400 text-xs">Pending</span>'; ?>
                                        </td>
                                        <td class="py-3 text-center text-gray-500 text-xs"><?php echo date('M j, Y', strtotime($jo['created_at'])); ?></td>
                                        <td class="py-3 text-right">
                                            <button @click="openModal(<?php echo $jo['id']; ?>)" class="btn-action blue">
                                                View
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div id="jobOrdersPagination">
                    <?php
                    $pagination_params = array_filter([
                        'search'   => $search,
                        'status'   => $status_filter,
                        'payment'  => $payment_filter,
                        'sort'     => $sort,
                        'dir'      => $dir,
                        'branch_id'=> $branchId !== 'all' ? (int)$branchId : '',
                    ]);
                    echo render_pagination($page, $total_pages, $pagination_params);
                    ?>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- View Details Modal -->
<div x-show="showModal" x-cloak>
    <div class="modal-overlay" @click.self="showModal = false">
        <div class="modal-panel" @click.stop>
            <!-- Loading -->
            <div x-show="loading" style="padding:48px;text-align:center;">
                <div style="width:40px;height:40px;border:3px solid #e5e7eb;border-top-color:#6366f1;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 12px;"></div>
                <p style="color:#6b7280;font-size:14px;">Loading details...</p>
            </div>
            <!-- Error -->
            <div x-show="errorMsg && !loading" style="padding:32px;text-align:center;">
                <p style="color:#ef4444;font-size:14px;margin-bottom:12px;" x-text="errorMsg"></p>
                <button @click="showModal = false" class="btn-secondary">Close</button>
            </div>
            <!-- Content -->
            <div x-show="job && !loading">
                <!-- Header -->
                <div style="padding:18px 24px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <h3 style="font-size:18px;font-weight:700;color:#1f2937;margin:0 0 2px;" x-text="'Customization #' + job?.id"></h3>
                        <div x-html="statusBadge(job?.status)" style="display:inline-block;"></div>
                    </div>
                    <button @click="showModal = false" style="background:transparent;border:none;cursor:pointer;color:#6b7280;">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <!-- Body -->
                <div style="padding:24px;">
                    <!-- Customer -->
                    <div style="margin-bottom:18px;">
                        <p style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:8px;">Customer</p>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:16px;" x-text="job?.customer_name?.charAt(0)?.toUpperCase() || 'W'"></div>
                            <div>
                                <div style="font-weight:700;color:#1f2937;max-width:250px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="job?.customer_name || 'Walk-in Customer'" :title="job?.customer_name || 'Walk-in Customer'"></div>
                                <div style="font-size:12px;color:#6b7280;max-width:250px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="job?.customer_email || ''" :title="job?.customer_email || ''"></div>
                            </div>
                        </div>
                    </div>
                    <!-- Details Grid -->
                    <div class="detail-row">
                        <div class="detail-block">
                            <label>Service Type</label>
                            <span style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;" x-text="job?.service_type" :title="job?.service_type"></span>
                        </div>
                        <div class="detail-block">
                            <label>Quantity</label>
                            <span x-text="job?.quantity ?? 1"></span>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-block">
                            <label>Dimensions</label>
                            <span x-text="job?.width_ft && job?.height_ft ? job.width_ft + ' ft × ' + job.height_ft + ' ft' : '—'"></span>
                        </div>
                        <div class="detail-block">
                            <label>Sq. Ft.</label>
                            <span x-text="job?.total_sqft ? job.total_sqft + ' sqft' : '—'"></span>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-block">
                            <label>Estimated Total</label>
                            <span x-text="job?.estimated_total ? '₱' + parseFloat(job.estimated_total).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) : 'Pending'"></span>
                        </div>
                        <div class="detail-block">
                            <label>Amount Paid</label>
                            <span x-text="'₱' + parseFloat(job?.amount_paid ?? 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></span>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-block">
                            <label>Payment Status</label>
                            <span x-html="paymentBadge(job?.payment_status)"></span>
                        </div>
                        <div class="detail-block">
                            <label>Priority</label>
                            <span x-text="job?.priority ?? 'NORMAL'"></span>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-block">
                            <label>Date Submitted</label>
                            <span x-text="job?.created_at ? new Date(job.created_at).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'}) : '—'"></span>
                        </div>
                        <div class="detail-block">
                            <label>Due Date</label>
                            <span x-text="job?.due_date ? new Date(job.due_date).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'}) : 'Not set'"></span>
                        </div>
                    </div>
                    <!-- Notes -->
                    <div x-show="job?.notes" style="margin-top:4px;">
                        <p style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin-bottom:6px;">Notes</p>
                        <div style="background:#f9fafb;border-radius:8px;padding:12px 14px;font-size:13px;color:#374151;white-space:pre-wrap;" x-text="job?.notes"></div>
                    </div>
                </div>
                <!-- Footer -->
                <div style="padding:16px 24px;border-top:1px solid #f3f4f6;display:flex;justify-content:flex-end;gap:8px;">
                    <button @click="showModal = false" class="btn-secondary">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Transaction History Modal -->
<div x-show="showHistory" x-cloak>
    <div class="modal-overlay" @click.self="showHistory = false">
        <div class="modal-panel" style="max-width:500px;" @click.stop>
            <div style="padding:18px 24px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;">
                <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;" x-text="'History: ' + historyCustomerName"></h3>
                <button @click="showHistory = false" style="background:transparent;border:none;cursor:pointer;color:#6b7280;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div style="padding:16px 24px;">
                <div style="display:flex;gap:8px;margin-bottom:16px;border-bottom:2px solid #f3f4f6;padding-bottom:8px;">
                    <button class="tab-btn" :class="{ 'active': historyTab === 'orders' }" @click="historyTab = 'orders'">Store Products</button>
                    <button class="tab-btn" :class="{ 'active': historyTab === 'customs' }" @click="historyTab = 'customs'">Customizations</button>
                </div>
                <div x-show="historyLoading" style="padding:32px;text-align:center;">
                    <div style="width:32px;height:32px;border:3px solid #e5e7eb;border-top-color:#6366f1;border-radius:50%;animation:spin 0.8s linear infinite;margin:0 auto 12px;"></div>
                </div>
                <div x-show="!historyLoading">
                    <div x-show="historyTab === 'orders'">
                        <template x-if="historyOrders.length === 0">
                            <p style="text-align:center;padding:20px;color:#9ca3af;font-size:13px;">No store orders found.</p>
                        </template>
                        <template x-for="ord in (historyOrders || [])" :key="ord.order_id">
                            <div class="history-item">
                                <div>
                                    <div style="font-size:13px;font-weight:700;color:#1f2937;" x-text="'Order #' + ord.order_id"></div>
                                    <div style="font-size:11px;color:#9ca3af;" x-text="new Date(ord.order_date).toLocaleDateString()"></div>
                                </div>
                                <div style="text-align:right;">
                                    <div style="font-size:13px;font-weight:700;color:#1f2937;" x-text="'₱' + parseFloat(ord.total_amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})"></div>
                                    <div x-html="statusBadge(ord.status)" style="transform:scale(0.85);transform-origin:right;"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                    <div x-show="historyTab === 'customs'">
                        <template x-if="historyCustoms.length === 0">
                            <p style="text-align:center;padding:20px;color:#9ca3af;font-size:13px;">No customizations found.</p>
                        </template>
                        <template x-for="cst in (historyCustoms || [])" :key="cst.id">
                            <div class="history-item">
                                <div>
                                    <div style="font-size:13px;font-weight:700;color:#1f2937;" x-text="cst.service_type"></div>
                                    <div style="font-size:11px;color:#9ca3af;" x-text="new Date(cst.created_at).toLocaleDateString()"></div>
                                </div>
                                <div style="text-align:right;">
                                    <div style="font-size:13px;font-weight:700;color:#1f2937;" x-text="cst.estimated_total ? '₱' + parseFloat(cst.estimated_total).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) : 'Pending'"></div>
                                    <div x-html="statusBadge(cst.status)" style="transform:scale(0.85);transform-origin:right;"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
            <div style="padding:16px 24px;border-top:1px solid #f3f4f6;text-align:right;">
                <button @click="showHistory = false" class="btn-secondary">Close</button>
            </div>
        </div>
    </div>
</div>



</body>
</html>
