<?php
/**
 * Admin Customer Verification
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/customer_id_verification.php';

require_role(['Admin', 'Manager']);

if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

pf_process_customer_id_verification_post('customer_verification.php');

$current_user = get_logged_in_user();
$can_manage_customer_verification = (($current_user['role'] ?? '') === 'Admin');
$viewerBranch = printflow_branch_filter_for_user();

pf_ensure_customer_id_verification_columns();

$search = trim((string)($_GET['search'] ?? ''));
$date_from = trim((string)($_GET['date_from'] ?? ''));
$date_to = trim((string)($_GET['date_to'] ?? ''));
$status_filter = trim((string)($_GET['status_filter'] ?? ''));
$upload_filter = trim((string)($_GET['upload_filter'] ?? ''));
$sort_by = $_GET['sort'] ?? 'latest_upload';
if (in_array($sort_by, ['priority_first', 'new_submissions'], true)) {
    $sort_by = 'latest_upload';
}
$allowed_sorts = ['latest_upload', 'oldest', 'az', 'za'];
if (!in_array($sort_by, $allowed_sorts, true)) {
    $sort_by = 'latest_upload';
}
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;

[$custBranchSql, $custBranchTypes, $custBranchParams] = ($viewerBranch)
    ? branch_customers_belong_where_sql((int)$viewerBranch, 'customers')
    : ['', '', []];

$sql = "SELECT customer_id, first_name, last_name, email, contact_number, id_type, id_image, id_status, id_reject_reason, id_uploaded_at, id_reviewed_at, created_at
        FROM customers WHERE 1=1" . $custBranchSql;
$params = $custBranchParams;
$types = $custBranchTypes;

if ($search !== '') {
    $search_term = '%' . $search . '%';
    $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?
              OR id_type LIKE ? OR CAST(customer_id AS CHAR) LIKE ?)";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term, $search_term]);
    $types .= 'ssssss';
}

if ($date_from !== '') {
    $sql .= ' AND DATE(COALESCE(id_uploaded_at, created_at)) >= ?';
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to !== '') {
    $sql .= ' AND DATE(COALESCE(id_uploaded_at, created_at)) <= ?';
    $params[] = $date_to;
    $types .= 's';
}

[$statusSql, $statusTypes, $statusParams] = pf_customer_id_verification_sql_filter($status_filter, $upload_filter);
$sql .= $statusSql;
$params = array_merge($params, $statusParams);
$types .= $statusTypes;

$count_sql = "SELECT COUNT(*) as total FROM ({$sql}) as count_wrap";
$total_filtered = (int)(db_query($count_sql, $types, $params)[0]['total'] ?? 0);
$total_pages = max(1, (int)ceil($total_filtered / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$sql .= pf_customer_verification_sort_clause($sort_by) . " LIMIT $per_page OFFSET $offset";
$customers = db_query($sql, $types, $params);
$status_counts = pf_customer_verification_status_counts($custBranchSql, $custBranchTypes, $custBranchParams);

if (isset($_GET['ajax'])) {
    ob_start();
    ?>
    <table class="orders-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Customer</th>
                <th>Email</th>
                <th>ID Type</th>
                <th>Uploaded</th>
                <th>Registered</th>
                <th>Status</th>
                <th style="text-align:right;" class="no-print">Actions</th>
            </tr>
        </thead>
        <tbody id="verificationTableBody">
            <?php echo pf_render_verification_table_rows($customers, $base_path); ?>
        </tbody>
    </table>
    <?php
    $table_html = ob_get_clean();
    ob_start();
    $pagination_params = array_filter([
        'search' => $search,
        'date_from' => $date_from,
        'date_to' => $date_to,
        'status_filter' => $status_filter,
        'upload_filter' => $upload_filter,
        'sort' => $sort_by !== 'latest_upload' ? $sort_by : '',
    ], static fn($v) => $v !== null && $v !== '');
    echo render_pagination($page, $total_pages, $pagination_params);
    $pagination_html = ob_get_clean();

    echo json_encode([
        'success' => true,
        'table' => $table_html,
        'pagination' => $pagination_html,
        'count' => number_format($total_filtered),
        'badge' => count(array_filter([$search, $date_from, $date_to, $status_filter])),
    ]);
    exit;
}

$pending_review = $status_counts['pending'];
$verified_count = $status_counts['verified'];
$rejected_count = $status_counts['rejected'];
$no_id_count = (int)(db_query(
    'SELECT COUNT(*) AS c FROM customers WHERE 1=1' . $custBranchSql . ' AND (id_image IS NULL OR TRIM(id_image) = \'\')',
    $custBranchTypes,
    $custBranchParams
)[0]['c'] ?? 0);

$reviewed_flash = null;
if (isset($_GET['reviewed'])) {
    $reviewed_flash = ($_GET['reviewed'] === '1')
        ? ['type' => 'success', 'message' => 'Customer ID verification updated successfully.']
        : ['type' => 'error', 'message' => 'Unable to update customer ID verification. Please try again.'];
}

$page_title = 'Customer Verification - Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="<?php echo $base_path; ?>/public/assets/css/output.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        .customer-verification-page,
        #verification-modal {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }
        .kpi-row { display:grid; grid-template-columns:repeat(4, 1fr); gap:16px; margin-bottom:24px; }
        @media (max-width:768px) { .kpi-row { grid-template-columns:repeat(2, 1fr); } }
        .kpi-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; position:relative; overflow:hidden; cursor:pointer; transition:box-shadow .15s,border-color .15s; text-decoration:none; color:inherit; display:block; }
        .kpi-card:hover { box-shadow:0 4px 14px rgba(0,0,0,.06); border-color:#d1d5db; }
        .kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
        .kpi-card.amber::before { background:linear-gradient(90deg,#f59e0b,#fbbf24); }
        .kpi-card.emerald::before { background:linear-gradient(90deg,#059669,#34d399); }
        .kpi-card.rose::before { background:linear-gradient(90deg,#f43f5e,#fb7185); }
        .kpi-card.slate::before { background:linear-gradient(90deg,#64748b,#94a3b8); }
        .kpi-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#6b7280; margin-bottom:6px; }
        .kpi-value { font-size:28px; font-weight:700; color:#1f2937; line-height:1.1; }
        .kpi-sub { font-size:12px; color:#6b7280; margin-top:4px; }
        .btn-action { display:inline-flex; align-items:center; justify-content:center; padding:6px 12px; border:1px solid transparent; background:transparent; border-radius:6px; font-size:12px; font-weight:500; transition:all .2s; cursor:pointer; text-decoration:none; }
        .btn-action.teal { color:#14b8a6; border-color:#14b8a6; }
        .btn-action.teal:hover { background:#14b8a6; color:#fff; }
        .btn-action.blue { color:#3b82f6; border-color:#3b82f6; }
        .btn-action.blue:hover { background:#3b82f6; color:#fff; }
        .orders-table td.actions { display:flex; align-items:center; justify-content:flex-end; gap:6px; flex-wrap:nowrap; }
        .orders-table td.actions .btn-action { min-height:30px; height:30px; padding:0 12px; box-sizing:border-box; line-height:1; font-family:inherit; white-space:nowrap; }
        .toolbar-btn { display:inline-flex; align-items:center; gap:8px; padding:0 16px; height:38px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; color:#374151; font-size:13px; font-weight:500; cursor:pointer; transition:all .2s; font-family:inherit; }
        .toolbar-btn:hover { background:#f9fafb; border-color:#d1d5db; }
        .toolbar-btn.active { background:#f0fdfa; border-color:#0d9488; color:#0d9488; }
        .sort-dropdown { position:absolute; top:calc(100% + 6px); right:0; min-width:200px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,.12); z-index:200; padding:6px 0; overflow:hidden; }
        .sort-option { display:flex; align-items:center; gap:8px; padding:9px 16px; font-size:13px; color:#374151; cursor:pointer; transition:background .1s; }
        .sort-option:hover { background:#f9fafb; }
        .sort-option.selected { color:#0d9488; font-weight:600; background:#f0fdfa; }
        .sort-option .check { margin-left:auto; color:#0d9488; }
        .filter-panel { position:absolute; top:calc(100% + 6px); right:0; width:300px; background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.12); z-index:200; overflow:hidden; }
        .filter-panel-header { padding:14px 18px; border-bottom:1px solid #f3f4f6; font-size:14px; font-weight:700; color:#111827; }
        .filter-section { padding:14px 18px; border-bottom:1px solid #f3f4f6; }
        .filter-section:last-of-type { border-bottom:none; }
        .filter-section-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
        .filter-section-label { font-size:13px; font-weight:600; color:#374151; }
        .filter-reset-link { font-size:12px; font-weight:600; color:#0d9488; cursor:pointer; background:none; border:none; padding:0; }
        .filter-reset-link:hover { text-decoration:underline; }
        .filter-badge { display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; background:#0d9488; color:#fff; font-size:10px; font-weight:700; border-radius:50%; margin-left:4px; }
        .filter-input { width:100%; height:34px; border:1px solid #e5e7eb; border-radius:7px; font-size:13px; padding:0 10px; color:#374151; box-sizing:border-box; transition:border-color .15s; font-family:inherit; }
        .filter-input:focus { outline:none; border-color:#0d9488; }
        .filter-date-row { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
        .customer-verification-page .vf-section-title { font-size:16px; font-weight:700; color:#1f2937; margin:0; line-height:1.3; }
        .customer-verification-page .vf-section-meta { font-size:12px; color:#6b7280; margin:4px 0 0; line-height:1.4; }
        .filter-date-label { font-size:12px; font-weight:600; color:#6b7280; margin-bottom:4px; }
        .filter-search-wrap { position:relative; }
        .filter-search-input { width:100%; height:34px; border:1px solid #e5e7eb; border-radius:7px; font-size:13px; padding:0 12px; color:#374151; box-sizing:border-box; font-family:inherit; }
        .filter-search-input:focus { outline:none; border-color:#0d9488; }
        .filter-actions { display:flex; gap:8px; padding:14px 18px; border-top:1px solid #f3f4f6; }
        .filter-btn-reset { flex:1; height:36px; border:1px solid #e5e7eb; background:#fff; border-radius:8px; font-size:13px; font-weight:500; color:#374151; cursor:pointer; }
        .filter-btn-reset:hover { background:#f9fafb; }
        .orders-table { width:100%; border-collapse:collapse; font-size:13px; font-family:inherit; }
        .orders-table th { padding:12px 16px; font-size:13px; font-weight:600; color:#6b7280; text-align:left; border-bottom:1px solid #e5e7eb; white-space:nowrap; }
        .orders-table td { padding:12px 16px; border-bottom:1px solid #f3f4f6; vertical-align:middle; color:#374151; }
        .orders-table tbody tr { cursor:pointer; transition:background .1s; }
        .orders-table tbody tr:hover { background:#f9fafb; }
        .verification-row .actions { pointer-events:auto; }
        .orders-table tbody tr.verification-row--pending { background:#fffbeb; }
        .orders-table tbody tr.verification-row--pending:hover { background:#fef3c7 !important; }
        .orders-table tbody tr.verification-row--approved { background:#f0fdf4; }
        .orders-table tbody tr.verification-row--approved:hover { background:#dcfce7 !important; }
        .orders-table tbody tr.verification-row--rejected { background:#fef2f2; }
        .orders-table tbody tr.verification-row--rejected:hover { background:#fee2e2 !important; }
        [x-cloak] { display:none !important; }
        @keyframes spin { to { transform:rotate(360deg); } }
        .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.5); display:flex; align-items:center; justify-content:center; z-index:9999; }
        .modal-panel { background:#fff; border-radius:12px; box-shadow:0 25px 50px rgba(0,0,0,.25); width:100%; max-height:88vh; overflow-y:auto; margin:16px; position:relative; font-size:13px; color:#374151; }
        .btn-secondary { padding:8px 16px; border:1px solid #e5e7eb; background:#fff; border-radius:8px; font-size:13px; font-weight:600; color:#374151; cursor:pointer; font-family:inherit; }
        #verification-modal .vf-modal-header { padding:20px 24px; border-bottom:1px solid #f3f4f6; display:flex; align-items:center; justify-content:space-between; }
        #verification-modal .vf-modal-title { font-size:18px; font-weight:700; color:#1f2937; margin:0; line-height:1.3; }
        #verification-modal .vf-modal-subtitle { font-size:13px; color:#6b7280; margin:2px 0 0; font-weight:400; }
        #verification-modal .vf-modal-body { padding:24px; }
        #verification-modal .vf-field-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin-bottom:16px; }
        #verification-modal .vf-field-label { font-size:13px; font-weight:600; color:#6b7280; margin:0 0 4px; line-height:1.4; }
        #verification-modal .vf-field-value { font-size:13px; color:#374151; margin:0; font-weight:400; line-height:1.4; }
        #verification-modal .vf-field-value--strong { font-size:13px; color:#1f2937; font-weight:500; margin:0; line-height:1.4; }
        #verification-modal .vf-text-secondary { font-size:12px; color:#6b7280; line-height:1.4; }
        #verification-modal .vf-text-meta { font-size:12px; color:#9ca3af; line-height:1.4; }
        #verification-modal .vf-text-error { font-size:12px; color:#dc2626; line-height:1.4; }
        #verification-modal .vf-text-success { font-size:12px; color:#16a34a; font-weight:600; line-height:1.4; }
        #verification-modal .vf-loading-text { font-size:13px; color:#6b7280; }
        .verification-action-section { margin-top:8px; }
        .verification-action-label { font-size:13px; font-weight:600; color:#374151; margin:0 0 8px; line-height:1.4; }
        .verification-action-cards { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px; }
        .verification-action-card { display:flex; align-items:center; gap:12px; padding:14px 16px; border:1px solid #e5e7eb; border-radius:10px; background:#fff; cursor:pointer; font-size:13px; font-weight:500; color:#374151; transition:border-color .15s,background .15s; position:relative; font-family:inherit; }
        .verification-action-card input { position:absolute; opacity:0; pointer-events:none; }
        .verification-action-card .action-radio { width:18px; height:18px; border:2px solid #d1d5db; border-radius:50%; flex-shrink:0; position:relative; box-sizing:border-box; }
        .verification-action-card.approve-card { border-color:#86efac; }
        .verification-action-card.approve-card.selected { background:#f0fdf4; }
        .verification-action-card.approve-card.selected .action-radio { border-color:#22c55e; }
        .verification-action-card.approve-card.selected .action-radio::after { content:''; position:absolute; inset:3px; background:#22c55e; border-radius:50%; }
        .verification-action-card.reject-card.selected { border-color:#fca5a5; }
        .verification-action-card.reject-card.selected .action-radio { border-color:#f97316; }
        .verification-action-card.reject-card.selected .action-radio::after { content:''; position:absolute; inset:3px; background:#f97316; border-radius:50%; }
        .verification-reject-fields { margin-bottom:20px; }
        .verification-reject-fields .verification-action-label + select { margin-bottom:16px; }
        .verification-reject-select { width:100%; height:38px; padding:0 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px; background:#fff; color:#374151; box-sizing:border-box; font-family:inherit; }
        .verification-reject-select:focus { outline:none; border-color:#0d9488; }
        .verification-note-wrap textarea { width:100%; min-height:100px; padding:12px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px; color:#374151; resize:vertical; box-sizing:border-box; font-family:inherit; line-height:1.5; }
        .verification-note-wrap textarea:focus { outline:none; border-color:#0d9488; }
        .verification-char-count { display:block; text-align:right; font-size:12px; color:#9ca3af; margin-top:6px; }
        .verification-action-footer { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:4px; }
        .btn-submit-action { padding:10px 20px; background:#111827; color:#fff; border:1px solid #111827; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; min-width:140px; font-family:inherit; }
        .btn-submit-action:hover { background:#1f2937; }
        #verification-modal .form-group { margin-bottom:16px; }
        #verification-modal .form-group.has-error .verification-reject-select,
        #verification-modal .form-group.has-error .verification-note-wrap textarea { border-color:#ef4444 !important; box-shadow:0 0 0 2px rgba(239,68,68,.15); }
        #verification-modal .form-group.has-error .verification-action-cards .verification-action-card { border-color:#fca5a5 !important; }
        #verification-modal .field-error { display:block; font-size:12px; color:#ef4444; margin-top:4px; min-height:18px; }
        #verification-modal .verification-modal-footer { padding:16px 24px; border-top:1px solid #f3f4f6; display:flex; justify-content:flex-end; align-items:center; gap:12px; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . (($current_user['role'] ?? '') === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>

    <div class="main-content">
        <header>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <h1 class="page-title">Customer Verification</h1>
            </div>
        </header>

        <script>
            var searchDebounceTimer = null;

            function buildFilterURL(overrides = {}, isAjax = false) {
                const params = new URLSearchParams(window.location.search);
                params.delete('branch_id');
                const fields = {
                    search: () => document.getElementById('fp_search')?.value || '',
                    date_from: () => document.getElementById('fp_date_from')?.value || '',
                    date_to: () => document.getElementById('fp_date_to')?.value || '',
                    status_filter: () => document.getElementById('fp_status_filter')?.value || '',
                };
                for (const [key, getter] of Object.entries(fields)) {
                    const val = (overrides[key] !== undefined) ? overrides[key] : getter();
                    if (val) params.set(key, val);
                    else params.delete(key);
                }
                if (overrides.upload_filter !== undefined) {
                    if (overrides.upload_filter) params.set('upload_filter', overrides.upload_filter);
                    else params.delete('upload_filter');
                }
                if (overrides.sort !== undefined) {
                    if (overrides.sort && overrides.sort !== 'latest_upload') params.set('sort', overrides.sort);
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
                        const tc = document.getElementById('verificationTableContainer');
                        if (tc) {
                            tc.innerHTML = data.table;
                            if (typeof Alpine !== 'undefined' && typeof Alpine.initTree === 'function') {
                                Alpine.initTree(tc);
                            }
                        }
                        const pc = document.getElementById('verificationPagination');
                        if (pc) pc.innerHTML = data.pagination;
                        const bc = document.getElementById('filterBadgeContainer');
                        if (bc) bc.innerHTML = data.badge > 0 ? `<span class="filter-badge">${data.badge}</span>` : '';
                        const countEl = document.getElementById('verificationCountLabel');
                        if (countEl) countEl.textContent = data.count + ' records';
                        window.dispatchEvent(new CustomEvent('filter-badge-update', { detail: { badge: data.badge } }));
                        const displayUrl = buildFilterURL(overrides, false);
                        window.history.replaceState({ path: displayUrl }, '', displayUrl);
                    }
                } catch (e) { console.error('Error updating table:', e); }
            }

            function applyFilters(resetAll = false) {
                if (resetAll) {
                    window.location.href = window.location.pathname;
                } else {
                    fetchUpdatedTable();
                }
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

            function applyVerificationKpiFilter(status, upload) {
                if (document.getElementById('fp_status_filter')) {
                    document.getElementById('fp_status_filter').value = status;
                }
                fetchUpdatedTable({ status_filter: status, upload_filter: upload, sort: 'latest_upload' });
            }

            var _activeSortKey = '<?php echo $sort_by; ?>';
            var _hasActiveFilters = <?php echo (!empty($search) || !empty($date_from) || !empty($date_to) || !empty($status_filter)) ? 'true' : 'false'; ?>;

            function verificationModal() {
                return {
                    showModal: false,
                    loading: false,
                    errorMsg: '',
                    customer: null,
                    filterOpen: false,
                    sortOpen: false,
                    activeSort: _activeSortKey,
                    hasActiveFilters: _hasActiveFilters,
                    idActionCsrfToken: <?php echo json_encode(generate_csrf_token()); ?>,
                    idActionSelection: '',
                    idRejectReason: '',
                    idRejectReasonOther: '',
                    idActionError: '',
                    idRejectReasonError: '',
                    idNoteError: '',

                    init() {
                        window.addEventListener('filter-badge-update', e => { this.hasActiveFilters = (e.detail.badge > 0); });
                        window.addEventListener('sort-changed', e => { this.activeSort = e.detail.sortKey; this.sortOpen = false; });
                    },

                    getCustomerFromRow(sourceEl, fallbackId) {
                        const host = sourceEl?.closest?.('[data-customer]') || document.querySelector('[data-customer-id="' + fallbackId + '"]');
                        if (!host?.dataset?.customer) return null;
                        try {
                            const parsed = JSON.parse(host.dataset.customer);
                            if (parsed && typeof parsed === 'object') {
                                if (!parsed.customer_id && fallbackId) parsed.customer_id = fallbackId;
                                return parsed;
                            }
                        } catch (err) {
                            console.error(err);
                        }
                        return null;
                    },

                    async fetchJsonResponse(url, options = {}) {
                        const response = await fetch(url, {
                            credentials: 'same-origin',
                            ...options,
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                ...(options.headers || {}),
                            },
                        });
                        const raw = await response.text();
                        const body = raw.trim().replace(/^\uFEFF/, '');
                        if (!body) throw new Error('Empty server response');
                        let data = JSON.parse(body);
                        if (!response.ok && !(data && data.success === false)) {
                            throw new Error('Request failed (' + response.status + ')');
                        }
                        return data;
                    },

                    clearIdActionErrors() {
                        this.idActionError = '';
                        this.idRejectReasonError = '';
                        this.idNoteError = '';
                    },

                    resetIdActionForm() {
                        this.idActionSelection = '';
                        this.idRejectReason = '';
                        this.idRejectReasonOther = '';
                        this.clearIdActionErrors();
                    },

                    validateIdActionForm() {
                        this.clearIdActionErrors();
                        let valid = true;
                        const action = (this.idActionSelection || '').trim();
                        if (!action) {
                            this.idActionError = 'Please select an action.';
                            valid = false;
                        }
                        if (action === 'reject') {
                            const selectedReason = (this.idRejectReason || '').trim();
                            if (!selectedReason) {
                                this.idRejectReasonError = 'Please select a rejection reason.';
                                valid = false;
                            }
                            if (selectedReason === 'Other' && !(this.idRejectReasonOther || '').trim()) {
                                this.idNoteError = 'Please enter something in the note.';
                                valid = false;
                            }
                        }
                        return valid;
                    },

                    onIdActionChange() {
                        this.idActionError = '';
                        if (this.idActionSelection !== 'reject') {
                            this.idRejectReason = '';
                            this.idRejectReasonOther = '';
                            this.idRejectReasonError = '';
                            this.idNoteError = '';
                        }
                    },

                    onRejectReasonChange() {
                        this.idRejectReasonError = '';
                        if (this.idRejectReason !== 'Other') {
                            this.idRejectReasonOther = '';
                            this.idNoteError = '';
                        }
                    },

                    onNoteInput() {
                        if (this.idNoteError) this.idNoteError = '';
                    },

                    async openModal(customerId, sourceEl = null) {
                        this.showModal = true;
                        this.loading = true;
                        this.errorMsg = '';
                        this.customer = null;
                        this.resetIdActionForm();
                        const inline = this.getCustomerFromRow(sourceEl, customerId);
                        if (inline) {
                            this.customer = inline;
                            this.loading = false;
                            return;
                        }
                        this.loading = false;
                        this.errorMsg = 'Unable to load verification record.';
                    },

                    async submitIdAction(action, attempt = 0) {
                        if (!<?php echo $can_manage_customer_verification ? 'true' : 'false'; ?>) return;
                        if (!this.customer?.customer_id) return;
                        const selectedReason = (this.idRejectReason || '').trim();
                        const otherReason = (this.idRejectReasonOther || '').trim();
                        const fd = new FormData();
                        fd.append('ajax', '1');
                        fd.append('id_action', action);
                        fd.append('cid', this.customer.customer_id);
                        fd.append('reject_reason', selectedReason);
                        fd.append('reject_reason_other', otherReason);
                        fd.append('csrf_token', this.idActionCsrfToken);
                        try {
                            const data = await this.fetchJsonResponse('<?php echo $base_path; ?>/admin/customer_verification.php', {
                                method: 'POST',
                                body: fd,
                            });
                            if (data.success) {
                                const rejectReason = selectedReason === 'Other'
                                    ? (otherReason || 'ID could not be verified. Please resubmit a clearer photo.')
                                    : (selectedReason || 'ID could not be verified. Please resubmit a clearer photo.');
                                this.customer = {
                                    ...this.customer,
                                    id_status: data.id_status || (action === 'approve' ? 'Verified' : 'Rejected'),
                                    id_reject_reason: typeof data.id_reject_reason === 'string'
                                        ? data.id_reject_reason
                                        : (action === 'approve' ? '' : rejectReason),
                                };
                                this.resetIdActionForm();
                                fetchUpdatedTable();
                                return;
                            }
                            if (data.code === 'csrf_mismatch' && data.csrf_token && attempt === 0) {
                                this.idActionCsrfToken = data.csrf_token;
                                return this.submitIdAction(action, attempt + 1);
                            }
                            alert(data.error || 'Failed to update customer ID status.');
                        } catch (e) {
                            console.error(e);
                            alert('Failed to update customer ID status. Please refresh and try again.');
                        }
                    },

                    async submitSelectedIdAction() {
                        if (!this.validateIdActionForm()) return;
                        const action = (this.idActionSelection || '').trim();
                        await this.submitIdAction(action);
                    },
                };
            }
            window.verificationModal = verificationModal;

            function callVerificationModalMethod(methodName, id, sourceEl) {
                function run() {
                    try {
                        const m = document.querySelector('main[x-data="verificationModal()"]');
                        const st = m && m._x_dataStack;
                        if (st && st[0] && typeof st[0][methodName] === 'function') {
                            st[0][methodName](id, sourceEl);
                            return true;
                        }
                    } catch (e) { console.error(e); }
                    return false;
                }
                if (run()) return;
                if (typeof Alpine !== 'undefined' && typeof Alpine.nextTick === 'function') {
                    Alpine.nextTick(function () { if (!run()) setTimeout(run, 50); });
                } else {
                    setTimeout(run, 100);
                }
            }
            window.openVerificationModal = function (id, sourceEl) {
                callVerificationModalMethod('openModal', id, sourceEl);
            };

            function autoOpenVerificationFromQuery() {
                if (window._pf_verification_auto_open_done) return;
                const params = new URLSearchParams(window.location.search);
                const customerId = parseInt(params.get('open_customer') || '0', 10);
                if (!customerId) return;
                window._pf_verification_auto_open_done = true;
                function tryOpen() {
                    const row = document.querySelector('[data-customer-id="' + customerId + '"]');
                    window.openVerificationModal(customerId, row || null);
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', tryOpen);
                } else {
                    setTimeout(tryOpen, 50);
                }
            }
            autoOpenVerificationFromQuery();

            function printflowInitVerificationPage() {
                const searchInput = document.getElementById('fp_search');
                if (searchInput && !searchInput._pf_bound) {
                    searchInput._pf_bound = true;
                    searchInput.addEventListener('input', function () {
                        clearTimeout(searchDebounceTimer);
                        searchDebounceTimer = setTimeout(function () { fetchUpdatedTable(); }, 500);
                    });
                }
                ['fp_date_from', 'fp_date_to', 'fp_status_filter'].forEach(function (id) {
                    const el = document.getElementById(id);
                    if (el && !el._pf_bound) {
                        el._pf_bound = true;
                        el.addEventListener('change', function () { fetchUpdatedTable(); });
                    }
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', printflowInitVerificationPage);
            } else {
                printflowInitVerificationPage();
            }
            document.addEventListener('printflow:page-init', printflowInitVerificationPage);
        </script>

        <main x-data="verificationModal()" class="customer-verification-page">
            <?php if ($reviewed_flash): ?>
                <div class="<?php echo $reviewed_flash['type'] === 'success' ? 'bg-green-100 border-green-400 text-green-700' : 'bg-red-100 border-red-400 text-red-700'; ?> border px-4 py-3 rounded relative mb-4">
                    <?php echo htmlspecialchars($reviewed_flash['message']); ?>
                </div>
            <?php endif; ?>

            <div class="kpi-row">
                <a class="kpi-card amber" href="#" onclick="applyVerificationKpiFilter('Pending','with_id'); return false;">
                    <div class="kpi-label">Pending Review</div>
                    <div class="kpi-value"><?php echo number_format($pending_review); ?></div>
                    <div class="kpi-sub">ID uploaded, awaiting approval</div>
                </a>
                <a class="kpi-card emerald" href="#" onclick="applyVerificationKpiFilter('Verified',''); return false;">
                    <div class="kpi-label">Verified</div>
                    <div class="kpi-value"><?php echo number_format($verified_count); ?></div>
                    <div class="kpi-sub">Approved customer IDs</div>
                </a>
                <a class="kpi-card rose" href="#" onclick="applyVerificationKpiFilter('Rejected',''); return false;">
                    <div class="kpi-label">Rejected</div>
                    <div class="kpi-value"><?php echo number_format($rejected_count); ?></div>
                    <div class="kpi-sub">Needs customer resubmission</div>
                </a>
                <a class="kpi-card slate" href="#" onclick="applyVerificationKpiFilter('','without_id'); return false;">
                    <div class="kpi-label">No ID Uploaded</div>
                    <div class="kpi-value"><?php echo number_format($no_id_count); ?></div>
                    <div class="kpi-sub">Customers without ID image</div>
                </a>
            </div>

            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
                    <div>
                        <h3 class="vf-section-title">Verification Requests</h3>
                        <p id="verificationCountLabel" class="vf-section-meta"><?php echo number_format($total_filtered); ?> records</p>
                    </div>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <div style="position:relative;">
                            <button type="button" class="toolbar-btn" :class="{ active: sortOpen || (activeSort !== 'latest_upload') }" @click="sortOpen = !sortOpen; filterOpen = false" id="sortBtn" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="9" y1="18" x2="15" y2="18"/>
                                </svg>
                                Sort by
                            </button>
                            <div class="sort-dropdown" x-show="sortOpen" x-cloak @click.outside="sortOpen = false">
                                <?php
                                $sorts = [
                                    'latest_upload' => 'Newest (Latest Upload)',
                                    'oldest' => 'Oldest',
                                    'az' => 'A → Z',
                                    'za' => 'Z → A',
                                ];
                                foreach ($sorts as $key => $label): ?>
                                <div class="sort-option"
                                     :class="{ 'selected': activeSort === '<?php echo $key; ?>' }"
                                     onclick="applySortFilter('<?php echo $key; ?>')">
                                    <?php echo htmlspecialchars($label); ?>
                                    <svg x-show="activeSort === '<?php echo $key; ?>'" class="check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div style="position:relative;">
                            <button class="toolbar-btn" :class="{ active: filterOpen || hasActiveFilters }" @click="filterOpen = !filterOpen; sortOpen = false" id="filterBtn" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                                </svg>
                                Filter
                                <span id="filterBadgeContainer">
                                    <?php
                                    $active_filters_count = count(array_filter([$search, $date_from, $date_to, $status_filter], function ($v) { return $v !== null && $v !== ''; }));
                                    if ($active_filters_count > 0): ?>
                                    <span class="filter-badge"><?php echo $active_filters_count; ?></span>
                                    <?php endif; ?>
                                </span>
                            </button>

                            <div class="filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false" id="filterPanel">
                                <div class="filter-panel-header">Filter</div>

                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Date range</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['date_from','date_to'])">Reset</button>
                                    </div>
                                    <div class="filter-date-row">
                                        <div>
                                            <div class="filter-date-label">From:</div>
                                            <input type="date" id="fp_date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from); ?>">
                                        </div>
                                        <div>
                                            <div class="filter-date-label">To:</div>
                                            <input type="date" id="fp_date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to); ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Verification status</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['status_filter'])">Reset</button>
                                    </div>
                                    <select id="fp_status_filter" class="filter-input">
                                        <option value="">All statuses</option>
                                        <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="Verified" <?php echo $status_filter === 'Verified' ? 'selected' : ''; ?>>Approved</option>
                                        <option value="Rejected" <?php echo $status_filter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    </select>
                                </div>

                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Keyword search</span>
                                        <button class="filter-reset-link" onclick="resetFilterField(['search'])">Reset</button>
                                    </div>
                                    <div class="filter-search-wrap">
                                        <input type="text" id="fp_search" class="filter-search-input" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                                    </div>
                                </div>

                                <div class="filter-actions">
                                    <button type="button" class="filter-btn-reset" style="width:100%;" onclick="applyFilters(true)">Reset all filters</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto" id="verificationTableContainer">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Customer</th>
                                <th>Email</th>
                                <th>ID Type</th>
                                <th>Uploaded</th>
                                <th>Registered</th>
                                <th>Status</th>
                                <th style="text-align:right;" class="no-print">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="verificationTableBody">
                            <?php echo pf_render_verification_table_rows($customers, $base_path); ?>
                        </tbody>
                    </table>
                </div>
                <div id="verificationPagination">
                    <?php
                    $pagination_params = array_filter([
                        'search' => $search,
                        'date_from' => $date_from,
                        'date_to' => $date_to,
                        'status_filter' => $status_filter,
                        'upload_filter' => $upload_filter,
                        'sort' => $sort_by !== 'latest_upload' ? $sort_by : '',
                    ], static fn($v) => $v !== null && $v !== '');
                    echo render_pagination($page, $total_pages, $pagination_params);
                    ?>
                </div>
            </div>

        <div x-show="showModal" x-cloak>
            <div class="modal-overlay" @click.self="showModal = false">
                <div class="modal-panel" id="verification-modal" style="max-width:640px;" @click.stop>
                    <div x-show="loading" style="padding:48px;text-align:center;">
                        <div style="width:40px;height:40px;border:3px solid #e5e7eb;border-top-color:#3b82f6;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 12px;"></div>
                        <p class="vf-loading-text">Loading verification record...</p>
                    </div>
                    <div x-show="!loading">
                        <div class="vf-modal-header">
                            <div>
                                <h3 class="vf-modal-title">Review ID Verification</h3>
                                <p class="vf-modal-subtitle" x-text="(customer?.first_name || '') + ' ' + (customer?.last_name || '')"></p>
                            </div>
                            <button @click="showModal = false" style="background:transparent;border:none;cursor:pointer;color:#6b7280;">
                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <div class="vf-modal-body">
                            <p x-show="errorMsg" class="vf-text-error" style="margin:0 0 12px;" x-text="errorMsg"></p>

                            <div class="vf-field-grid">
                                <div>
                                    <p class="vf-field-label">Customer ID</p>
                                    <p class="vf-field-value--strong" x-text="customer?.customer_id || '—'"></p>
                                </div>
                                <div>
                                    <p class="vf-field-label">Email</p>
                                    <p class="vf-field-value--strong" x-text="customer?.email || '—'"></p>
                                </div>
                                <div>
                                    <p class="vf-field-label">ID Type</p>
                                    <p class="vf-field-value--strong" x-text="customer?.id_type || 'Not provided'"></p>
                                </div>
                                <div>
                                    <p class="vf-field-label">Status</p>
                                    <p class="vf-field-value--strong" x-text="customer?.id_status_label || '—'"></p>
                                </div>
                            </div>

                            <div x-show="customer?.id_image" style="margin-bottom:14px;">
                                <a :href="customer?.id_image" target="_blank" rel="noopener">
                                    <img :src="customer?.id_image" alt="Customer ID" style="width:100%;max-height:280px;object-fit:contain;border-radius:8px;border:1px solid #e5e7eb;cursor:zoom-in;">
                                </a>
                            </div>
                            <p x-show="!customer?.id_image" class="vf-text-meta" style="font-style:italic;margin:0 0 14px;">No ID image uploaded.</p>
                            <p x-show="customer?.id_reject_reason" class="vf-text-error" style="margin:0 0 12px;">Rejection reason: <span x-text="customer?.id_reject_reason"></span></p>

                            <?php if ($can_manage_customer_verification): ?>
                            <div x-show="customer?.id_image && customer?.id_status !== 'Verified' && customer?.id_status !== 'Rejected'" class="verification-action-section">
                                <div class="form-group" :class="{ 'has-error': idActionError }">
                                    <p class="verification-action-label">Action</p>
                                    <div class="verification-action-cards">
                                        <label class="verification-action-card approve-card" :class="{ selected: idActionSelection === 'approve' }">
                                            <input type="radio" name="id_action_choice" value="approve" x-model="idActionSelection" @change="onIdActionChange()">
                                            <span class="action-radio" aria-hidden="true"></span>
                                            <span>Approve ID</span>
                                        </label>
                                        <label class="verification-action-card reject-card" :class="{ selected: idActionSelection === 'reject' }">
                                            <input type="radio" name="id_action_choice" value="reject" x-model="idActionSelection" @change="onIdActionChange()">
                                            <span class="action-radio" aria-hidden="true"></span>
                                            <span>Reject ID</span>
                                        </label>
                                    </div>
                                    <span class="field-error" x-text="idActionError" x-show="idActionError"></span>
                                </div>

                                <div x-show="idActionSelection === 'reject'" x-cloak class="verification-reject-fields">
                                    <div class="form-group" :class="{ 'has-error': idRejectReasonError }">
                                        <p class="verification-action-label">Rejection Reason</p>
                                        <select x-model="idRejectReason" class="verification-reject-select" @change="onRejectReasonChange()">
                                            <option value="">Select rejection reason</option>
                                            <?php foreach (PF_CUSTOMER_ID_REJECTION_OPTIONS as $reject_option): ?>
                                            <option value="<?php echo htmlspecialchars($reject_option, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($reject_option); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span class="field-error" x-text="idRejectReasonError" x-show="idRejectReasonError"></span>
                                    </div>

                                    <div x-show="idRejectReason === 'Other'" class="form-group" :class="{ 'has-error': idNoteError }">
                                        <p class="verification-action-label">Note</p>
                                        <div class="verification-note-wrap">
                                            <textarea x-model="idRejectReasonOther" maxlength="500" placeholder="Add a note..." @input="onNoteInput()"></textarea>
                                            <span class="verification-char-count" x-text="(idRejectReasonOther || '').length + ' / 500'"></span>
                                        </div>
                                        <span class="field-error" x-text="idNoteError" x-show="idNoteError"></span>
                                    </div>
                                </div>
                            </div>
                            <p x-show="customer?.id_status === 'Verified'" class="vf-text-success" style="margin:8px 0 0;">&#10003; ID Verified</p>
                            <?php else: ?>
                            <p class="vf-text-secondary" style="margin:0;">Only admins can verify or reject customer IDs.</p>
                            <?php endif; ?>
                        </div>

                        <div class="verification-modal-footer">
                            <button type="button" @click="showModal = false" class="btn-secondary">Cancel</button>
                            <?php if ($can_manage_customer_verification): ?>
                            <button type="button"
                                    class="btn-submit-action"
                                    x-show="customer?.id_image && customer?.id_status !== 'Verified' && customer?.id_status !== 'Rejected'"
                                    @click="submitSelectedIdAction()">Submit Action</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </main>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
