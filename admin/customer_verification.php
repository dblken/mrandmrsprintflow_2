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

$search = trim((string)($_GET['search'] ?? ''));
$status_filter = trim((string)($_GET['status_filter'] ?? ''));
$upload_filter = trim((string)($_GET['upload_filter'] ?? ''));
$sort_by = $_GET['sort'] ?? 'newest';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;

[$custBranchSql, $custBranchTypes, $custBranchParams] = ($viewerBranch)
    ? branch_customers_belong_where_sql((int)$viewerBranch, 'customers')
    : ['', '', []];

$sql = "SELECT customer_id, first_name, last_name, email, contact_number, id_type, id_image, id_status, id_reject_reason, created_at
        FROM customers WHERE 1=1" . $custBranchSql;
$params = $custBranchParams;
$types = $custBranchTypes;

if ($search !== '') {
    $search_term = '%' . $search . '%';
    $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?)";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
    $types .= 'ssss';
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

$sort_clause = match ($sort_by) {
    'oldest' => ' ORDER BY created_at ASC',
    'az' => ' ORDER BY first_name ASC, last_name ASC',
    'za' => ' ORDER BY first_name DESC, last_name DESC',
    'pending_first' => " ORDER BY CASE
        WHEN (id_status IS NULL OR id_status = '' OR id_status IN ('Pending', 'None', 'Unverified'))
             AND id_image IS NOT NULL AND TRIM(id_image) <> '' THEN 0
        WHEN COALESCE(NULLIF(id_status, ''), 'Pending') = 'Pending' THEN 1
        WHEN id_status = 'Rejected' THEN 2
        WHEN id_status = 'Verified' THEN 3
        ELSE 4 END, created_at DESC",
    default => ' ORDER BY created_at DESC',
};

$sql .= $sort_clause . " LIMIT $per_page OFFSET $offset";
$customers = db_query($sql, $types, $params);

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
                <th>Registered</th>
                <th>Status</th>
                <th style="text-align:right;" class="no-print">Actions</th>
            </tr>
        </thead>
        <tbody id="verificationTableBody">
            <?php if (empty($customers)): ?>
                <tr id="emptyVerificationRow">
                    <td colspan="7" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">No verification records found</td>
                </tr>
            <?php else: ?>
                <tr id="emptyVerificationRow" style="display:none;">
                    <td colspan="7" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">No verification records found</td>
                </tr>
                <?php foreach ($customers as $customer):
                    $id_status = pf_customer_id_status_normalize($customer['id_status'] ?? 'Pending');
                    $status_style = pf_customer_id_status_badge_style($id_status);
                    $payload_attr = pf_customer_verification_payload_attr($customer, $base_path);
                    $has_id = trim((string)($customer['id_image'] ?? '')) !== '';
                ?>
                    <tr class="verification-row" data-customer-id="<?php echo (int)$customer['customer_id']; ?>" data-customer="<?php echo $payload_attr; ?>" onclick="openVerificationModal(<?php echo (int)$customer['customer_id']; ?>, this)">
                        <td style="color:#1f2937;"><?php echo (int)$customer['customer_id']; ?></td>
                        <td style="font-weight:500;color:#1f2937;">
                            <div style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars(trim($customer['first_name'] . ' ' . $customer['last_name'])); ?>">
                                <?php echo htmlspecialchars(trim($customer['first_name'] . ' ' . $customer['last_name'])); ?>
                            </div>
                        </td>
                        <td style="text-transform:lowercase;">
                            <div style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars(strtolower((string)($customer['email'] ?? ''))); ?>">
                                <?php echo htmlspecialchars(strtolower((string)($customer['email'] ?? ''))); ?>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($customer['id_type'] ?: ($has_id ? 'Not specified' : '—')); ?></td>
                        <td style="color:#6b7280;font-size:12px;"><?php echo format_date($customer['created_at']); ?></td>
                        <td><span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;<?php echo $status_style; ?>"><?php echo htmlspecialchars($id_status); ?></span></td>
                        <td style="text-align:right;" class="no-print actions" onclick="event.stopPropagation()">
                            <button type="button" onclick="event.stopPropagation();openVerificationModal(<?php echo (int)$customer['customer_id']; ?>, this.closest('tr'))" class="btn-action blue">Review</button>
                            <a href="<?php echo $base_path; ?>/admin/customers_management.php?open_customer=<?php echo (int)$customer['customer_id']; ?>" class="btn-action teal" onclick="event.stopPropagation()">Profile</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
    $table_html = ob_get_clean();
    ob_start();
    $pagination_params = array_filter([
        'search' => $search,
        'status_filter' => $status_filter,
        'upload_filter' => $upload_filter,
        'sort' => $sort_by,
    ], static fn($v) => $v !== null && $v !== '');
    echo render_pagination($page, $total_pages, $pagination_params);
    $pagination_html = ob_get_clean();

    echo json_encode([
        'success' => true,
        'table' => $table_html,
        'pagination' => $pagination_html,
        'count' => number_format($total_filtered),
        'badge' => count(array_filter([$search, $status_filter, $upload_filter])),
    ]);
    exit;
}

$kpi_base_sql = "SELECT COUNT(*) as c FROM customers WHERE 1=1" . $custBranchSql;
$kpi_types = $custBranchTypes;
$kpi_params = $custBranchParams;

$pending_review = (int)(db_query(
    $kpi_base_sql . " AND (id_status IS NULL OR id_status = '' OR id_status IN ('Pending', 'None', 'Unverified'))
     AND id_image IS NOT NULL AND TRIM(id_image) <> ''",
    $kpi_types,
    $kpi_params
)[0]['c'] ?? 0);

$verified_count = (int)(db_query(
    $kpi_base_sql . " AND COALESCE(NULLIF(id_status, ''), 'Pending') = 'Verified'",
    $kpi_types,
    $kpi_params
)[0]['c'] ?? 0);

$rejected_count = (int)(db_query(
    $kpi_base_sql . " AND id_status = 'Rejected'",
    $kpi_types,
    $kpi_params
)[0]['c'] ?? 0);

$no_id_count = (int)(db_query(
    $kpi_base_sql . " AND (id_image IS NULL OR TRIM(id_image) = '')",
    $kpi_types,
    $kpi_params
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
        .kpi-row { display:grid; grid-template-columns:repeat(4, 1fr); gap:16px; margin-bottom:24px; }
        @media (max-width:768px) { .kpi-row { grid-template-columns:repeat(2, 1fr); } }
        .kpi-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; position:relative; overflow:hidden; cursor:pointer; transition:box-shadow .15s,border-color .15s; text-decoration:none; color:inherit; display:block; }
        .kpi-card:hover { box-shadow:0 4px 14px rgba(0,0,0,.06); border-color:#d1d5db; }
        .kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
        .kpi-card.amber::before { background:linear-gradient(90deg,#f59e0b,#fbbf24); }
        .kpi-card.emerald::before { background:linear-gradient(90deg,#059669,#34d399); }
        .kpi-card.rose::before { background:linear-gradient(90deg,#f43f5e,#fb7185); }
        .kpi-card.slate::before { background:linear-gradient(90deg,#64748b,#94a3b8); }
        .kpi-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#9ca3af; margin-bottom:6px; }
        .kpi-value { font-size:28px; font-weight:700; color:#1f2937; line-height:1.1; }
        .kpi-sub { font-size:12px; color:#6b7280; margin-top:4px; }
        .btn-action { display:inline-flex; align-items:center; justify-content:center; padding:6px 12px; border:1px solid transparent; background:transparent; border-radius:6px; font-size:12px; font-weight:500; transition:all .2s; cursor:pointer; text-decoration:none; }
        .btn-action.teal { color:#14b8a6; border-color:#14b8a6; }
        .btn-action.teal:hover { background:#14b8a6; color:#fff; }
        .btn-action.blue { color:#3b82f6; border-color:#3b82f6; }
        .btn-action.blue:hover { background:#3b82f6; color:#fff; }
        .toolbar-btn { display:inline-flex; align-items:center; gap:8px; padding:0 16px; height:38px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; color:#374151; font-size:13px; font-weight:500; cursor:pointer; transition:all .2s; }
        .toolbar-btn:hover { background:#f9fafb; border-color:#d1d5db; }
        .toolbar-btn.active { background:#f0fdfa; border-color:#0d9488; color:#0d9488; }
        .sort-dropdown, .filter-panel { position:absolute; top:calc(100% + 6px); right:0; background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,.12); z-index:200; padding:6px 0; overflow:hidden; }
        .sort-dropdown { min-width:220px; }
        .filter-panel { width:300px; }
        .sort-option { display:flex; align-items:center; gap:8px; padding:9px 16px; font-size:13px; color:#374151; cursor:pointer; }
        .sort-option:hover { background:#f9fafb; }
        .sort-option.selected { color:#0d9488; font-weight:600; background:#f0fdfa; }
        .sort-option .check { margin-left:auto; color:#0d9488; }
        .filter-panel-header { padding:14px 18px; border-bottom:1px solid #f3f4f6; font-size:14px; font-weight:700; color:#111827; }
        .filter-section { padding:14px 18px; border-bottom:1px solid #f3f4f6; }
        .filter-section-label { font-size:13px; font-weight:600; color:#374151; margin-bottom:8px; display:block; }
        .filter-input { width:100%; height:38px; padding:0 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:13px; }
        .filter-actions { display:flex; gap:8px; padding:14px 18px; border-top:1px solid #f3f4f6; }
        .filter-btn-reset, .filter-btn-apply { flex:1; height:36px; border-radius:8px; font-size:13px; font-weight:500; cursor:pointer; }
        .filter-btn-reset { border:1px solid #e5e7eb; background:#fff; color:#374151; }
        .filter-btn-apply { border:none; background:#0d9488; color:#fff; }
        .filter-badge { display:inline-flex; align-items:center; justify-content:center; min-width:18px; height:18px; padding:0 5px; border-radius:999px; background:#0d9488; color:#fff; font-size:11px; font-weight:700; margin-left:4px; }
        .orders-table { width:100%; border-collapse:collapse; font-size:13px; }
        .orders-table th { padding:12px 16px; font-size:13px; font-weight:600; color:#6b7280; text-align:left; border-bottom:1px solid #e5e7eb; white-space:nowrap; }
        .orders-table td { padding:12px 16px; border-bottom:1px solid #f3f4f6; vertical-align:middle; color:#374151; }
        .orders-table tbody tr { cursor:pointer; transition:background .1s; }
        .orders-table tbody tr:hover { background:#f9fafb; }
        .verification-row .actions { pointer-events:auto; }
        [x-cloak] { display:none !important; }
        @keyframes spin { to { transform:rotate(360deg); } }
        .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.5); display:flex; align-items:center; justify-content:center; z-index:9999; }
        .modal-panel { background:#fff; border-radius:12px; box-shadow:0 25px 50px rgba(0,0,0,.25); width:100%; max-height:88vh; overflow-y:auto; margin:16px; position:relative; }
        .btn-secondary { padding:8px 16px; border:1px solid #e5e7eb; background:#fff; border-radius:8px; font-size:13px; font-weight:600; color:#374151; cursor:pointer; }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . (($current_user['role'] ?? '') === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>

    <div class="main-content">
        <header>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <h1 class="page-title">Customer Verification</h1>
                <a href="<?php echo $base_path; ?>/admin/customers_management.php" class="toolbar-btn" style="text-decoration:none;">
                    Customers Management
                </a>
            </div>
        </header>

        <script>
            var searchDebounceTimer = null;

            function buildVerificationFilterURL(overrides = {}, isAjax = false) {
                const params = new URLSearchParams(window.location.search);
                const fields = {
                    search: () => document.getElementById('fp_search')?.value || '',
                    status_filter: () => document.getElementById('fp_status_filter')?.value || '',
                    upload_filter: () => document.getElementById('fp_upload_filter')?.value || '',
                };
                Object.keys(fields).forEach((key) => {
                    const val = overrides[key] !== undefined ? overrides[key] : fields[key]();
                    if (val) params.set(key, val); else params.delete(key);
                });
                if (overrides.sort !== undefined) {
                    if (overrides.sort) params.set('sort', overrides.sort); else params.delete('sort');
                }
                params.delete('page');
                if (isAjax) params.set('ajax', '1');
                else params.delete('ajax');
                return 'customer_verification.php' + (params.toString() ? '?' + params.toString() : '');
            }

            function applyVerificationSort(sortKey) {
                const url = buildVerificationFilterURL({ sort: sortKey });
                window.location.href = url;
            }

            function applyVerificationKpiFilter(status, upload) {
                const url = buildVerificationFilterURL({ status_filter: status, upload_filter: upload });
                window.location.href = url;
            }

            async function fetchUpdatedVerificationTable() {
                const url = buildVerificationFilterURL({}, true);
                try {
                    const res = await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const data = await res.json();
                    if (!data.success) return;
                    const container = document.getElementById('verificationTableContainer');
                    const pagination = document.getElementById('verificationPagination');
                    if (container) container.innerHTML = data.table;
                    if (pagination) pagination.innerHTML = data.pagination;
                    const badge = document.getElementById('filterBadgeContainer');
                    if (badge) {
                        badge.innerHTML = data.badge > 0 ? '<span class="filter-badge">' + data.badge + '</span>' : '';
                    }
                    const countEl = document.getElementById('verificationCountLabel');
                    if (countEl) countEl.textContent = data.count + ' records';
                } catch (e) {
                    console.error(e);
                }
            }

            function verificationModal() {
                return {
                    showModal: false,
                    loading: false,
                    errorMsg: '',
                    customer: null,
                    idActionCsrfToken: <?php echo json_encode(generate_csrf_token()); ?>,
                    idActionSelection: '',
                    idRejectReason: '',
                    idRejectReasonOther: '',

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

                    resetIdActionForm() {
                        this.idActionSelection = '';
                        this.idRejectReason = '';
                        this.idRejectReasonOther = '';
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
                        if (action === 'reject' && !selectedReason) {
                            alert('Please select a rejection reason first.');
                            return;
                        }
                        if (action === 'reject' && selectedReason === 'Other' && !otherReason) {
                            alert('Please enter a rejection note for "Other".');
                            return;
                        }
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
                                fetchUpdatedVerificationTable();
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
                        const action = (this.idActionSelection || '').trim();
                        if (!action) {
                            alert('Please choose Approve ID or Reject ID.');
                            return;
                        }
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

            document.addEventListener('DOMContentLoaded', function () {
                const searchInput = document.getElementById('fp_search');
                if (searchInput && !searchInput._pf_bound) {
                    searchInput._pf_bound = true;
                    searchInput.addEventListener('input', function () {
                        clearTimeout(searchDebounceTimer);
                        searchDebounceTimer = setTimeout(function () {
                            fetchUpdatedVerificationTable();
                        }, 500);
                    });
                }
            });
        </script>

        <main x-data="verificationModal()">
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
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
                    <div>
                        <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;">Verification Requests</h3>
                        <p id="verificationCountLabel" style="font-size:12px;color:#6b7280;margin:4px 0 0;"><?php echo number_format($total_filtered); ?> records</p>
                    </div>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <div style="position:relative;" x-data="{ sortOpen: false, activeSort: '<?php echo htmlspecialchars($sort_by, ENT_QUOTES); ?>' }">
                            <button type="button" class="toolbar-btn" :class="{ active: sortOpen || (activeSort !== 'newest') }" @click="sortOpen = !sortOpen; filterOpen = false" style="height:38px;">
                                Sort by
                            </button>
                            <div class="sort-dropdown" x-show="sortOpen" x-cloak @click.outside="sortOpen = false">
                                <?php
                                $sorts = [
                                    'pending_first' => 'Pending first',
                                    'newest' => 'Newest to Oldest',
                                    'oldest' => 'Oldest to Newest',
                                    'az' => 'A → Z',
                                    'za' => 'Z → A',
                                ];
                                foreach ($sorts as $key => $label): ?>
                                <div class="sort-option <?php echo $sort_by === $key ? 'selected' : ''; ?>" onclick="applyVerificationSort('<?php echo $key; ?>')">
                                    <?php echo htmlspecialchars($label); ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div style="position:relative;" x-data="{ filterOpen: false, hasActiveFilters: <?php echo json_encode($search !== '' || $status_filter !== '' || $upload_filter !== ''); ?> }">
                            <button type="button" class="toolbar-btn" :class="{ active: filterOpen || hasActiveFilters }" @click="filterOpen = !filterOpen" style="height:38px;">
                                Filter
                                <span id="filterBadgeContainer">
                                    <?php
                                    $active_filters_count = count(array_filter([$search, $status_filter, $upload_filter]));
                                    if ($active_filters_count > 0): ?>
                                    <span class="filter-badge"><?php echo $active_filters_count; ?></span>
                                    <?php endif; ?>
                                </span>
                            </button>
                            <div class="filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false">
                                <div class="filter-panel-header">Filter verification records</div>
                                <div class="filter-section">
                                    <label class="filter-section-label" for="fp_search">Search</label>
                                    <input type="text" id="fp_search" class="filter-input" placeholder="Name or email" value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                <div class="filter-section">
                                    <label class="filter-section-label" for="fp_status_filter">Verification status</label>
                                    <select id="fp_status_filter" class="filter-input">
                                        <option value="">All statuses</option>
                                        <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="Verified" <?php echo $status_filter === 'Verified' ? 'selected' : ''; ?>>Verified</option>
                                        <option value="Rejected" <?php echo $status_filter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <label class="filter-section-label" for="fp_upload_filter">ID upload</label>
                                    <select id="fp_upload_filter" class="filter-input">
                                        <option value="">All customers</option>
                                        <option value="with_id" <?php echo $upload_filter === 'with_id' ? 'selected' : ''; ?>>With ID image</option>
                                        <option value="without_id" <?php echo $upload_filter === 'without_id' ? 'selected' : ''; ?>>Without ID image</option>
                                    </select>
                                </div>
                                <div class="filter-actions">
                                    <button type="button" class="filter-btn-reset" onclick="window.location.href='customer_verification.php'">Reset</button>
                                    <button type="button" class="filter-btn-apply" onclick="window.location.href=buildVerificationFilterURL()">Apply</button>
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
                                <th>Registered</th>
                                <th>Status</th>
                                <th style="text-align:right;" class="no-print">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="verificationTableBody">
                            <?php if (empty($customers)): ?>
                                <tr id="emptyVerificationRow">
                                    <td colspan="7" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">No verification records found</td>
                                </tr>
                            <?php else: ?>
                                <tr id="emptyVerificationRow" style="display:none;">
                                    <td colspan="7" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">No verification records found</td>
                                </tr>
                                <?php foreach ($customers as $customer):
                                    $id_status = pf_customer_id_status_normalize($customer['id_status'] ?? 'Pending');
                                    $status_style = pf_customer_id_status_badge_style($id_status);
                                    $payload_attr = pf_customer_verification_payload_attr($customer, $base_path);
                                    $has_id = trim((string)($customer['id_image'] ?? '')) !== '';
                                ?>
                                    <tr class="verification-row" data-customer-id="<?php echo (int)$customer['customer_id']; ?>" data-customer="<?php echo $payload_attr; ?>" onclick="openVerificationModal(<?php echo (int)$customer['customer_id']; ?>, this)">
                                        <td style="color:#1f2937;"><?php echo (int)$customer['customer_id']; ?></td>
                                        <td style="font-weight:500;color:#1f2937;">
                                            <div style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars(trim($customer['first_name'] . ' ' . $customer['last_name'])); ?>">
                                                <?php echo htmlspecialchars(trim($customer['first_name'] . ' ' . $customer['last_name'])); ?>
                                            </div>
                                        </td>
                                        <td style="text-transform:lowercase;">
                                            <div style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars(strtolower((string)($customer['email'] ?? ''))); ?>">
                                                <?php echo htmlspecialchars(strtolower((string)($customer['email'] ?? ''))); ?>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($customer['id_type'] ?: ($has_id ? 'Not specified' : '—')); ?></td>
                                        <td style="color:#6b7280;font-size:12px;"><?php echo format_date($customer['created_at']); ?></td>
                                        <td><span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;<?php echo $status_style; ?>"><?php echo htmlspecialchars($id_status); ?></span></td>
                                        <td style="text-align:right;" class="no-print actions" onclick="event.stopPropagation()">
                                            <button type="button" onclick="event.stopPropagation();openVerificationModal(<?php echo (int)$customer['customer_id']; ?>, this.closest('tr'))" class="btn-action blue">Review</button>
                                            <a href="<?php echo $base_path; ?>/admin/customers_management.php?open_customer=<?php echo (int)$customer['customer_id']; ?>" class="btn-action teal" onclick="event.stopPropagation()">Profile</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="verificationPagination">
                    <?php
                    $pagination_params = array_filter([
                        'search' => $search,
                        'status_filter' => $status_filter,
                        'upload_filter' => $upload_filter,
                        'sort' => $sort_by,
                    ], static fn($v) => $v !== null && $v !== '');
                    echo render_pagination($page, $total_pages, $pagination_params);
                    ?>
                </div>
            </div>

        <div x-show="showModal" x-cloak>
            <div class="modal-overlay" @click.self="showModal = false">
                <div class="modal-panel" style="max-width:640px;" @click.stop>
                    <div x-show="loading" style="padding:48px;text-align:center;">
                        <div style="width:40px;height:40px;border:3px solid #e5e7eb;border-top-color:#3b82f6;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 12px;"></div>
                        <p style="color:#6b7280;font-size:14px;">Loading verification record...</p>
                    </div>
                    <div x-show="!loading">
                        <div style="padding:20px 24px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;">
                            <div>
                                <h3 style="font-size:18px;font-weight:700;color:#1f2937;margin:0;">Review ID Verification</h3>
                                <p style="font-size:13px;color:#6b7280;margin:2px 0 0 0;" x-text="(customer?.first_name || '') + ' ' + (customer?.last_name || '')"></p>
                            </div>
                            <button @click="showModal = false" style="background:transparent;border:none;cursor:pointer;color:#6b7280;">
                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <div style="padding:24px;">
                            <p x-show="errorMsg" style="color:#dc2626;font-size:13px;margin:0 0 12px;" x-text="errorMsg"></p>

                            <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:16px;">
                                <div>
                                    <p style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin:0 0 4px;">Customer ID</p>
                                    <p style="font-size:13px;color:#1f2937;font-weight:600;margin:0;" x-text="customer?.customer_id || '—'"></p>
                                </div>
                                <div>
                                    <p style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin:0 0 4px;">Email</p>
                                    <p style="font-size:13px;color:#1f2937;font-weight:600;margin:0;" x-text="customer?.email || '—'"></p>
                                </div>
                                <div>
                                    <p style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin:0 0 4px;">ID Type</p>
                                    <p style="font-size:13px;color:#1f2937;font-weight:600;margin:0;" x-text="customer?.id_type || 'Not provided'"></p>
                                </div>
                                <div>
                                    <p style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;margin:0 0 4px;">Status</p>
                                    <p style="font-size:13px;color:#1f2937;font-weight:600;margin:0;" x-text="(['Verified','Rejected'].includes(customer?.id_status) ? customer.id_status : 'Pending')"></p>
                                </div>
                            </div>

                            <div x-show="customer?.id_image" style="margin-bottom:14px;">
                                <a :href="customer?.id_image" target="_blank" rel="noopener">
                                    <img :src="customer?.id_image" alt="Customer ID" style="width:100%;max-height:280px;object-fit:contain;border-radius:8px;border:1px solid #e5e7eb;cursor:zoom-in;">
                                </a>
                            </div>
                            <p x-show="!customer?.id_image" style="font-size:13px;color:#9ca3af;font-style:italic;margin:0 0 14px;">No ID image uploaded.</p>
                            <p x-show="customer?.id_reject_reason" style="font-size:12px;color:#dc2626;margin:0 0 12px;">Rejection reason: <span x-text="customer?.id_reject_reason"></span></p>

                            <?php if ($can_manage_customer_verification): ?>
                            <div x-show="customer?.id_image && customer?.id_status !== 'Verified' && customer?.id_status !== 'Rejected'" style="margin-top:8px;">
                                <div style="border:1px solid #e5e7eb;border-radius:10px;padding:16px;background:#ffffff;">
                                    <label style="display:flex;align-items:center;gap:10px;font-size:14px;color:#111827;cursor:pointer;">
                                        <input type="radio" name="id_action_choice" value="approve" x-model="idActionSelection" style="margin:0;">
                                        <span>Approve ID</span>
                                    </label>
                                    <label style="display:flex;align-items:center;gap:10px;font-size:14px;color:#111827;cursor:pointer;margin-top:12px;">
                                        <input type="radio" name="id_action_choice" value="reject" x-model="idActionSelection" style="margin:0;">
                                        <span>Reject ID</span>
                                    </label>
                                    <div x-show="idActionSelection === 'reject'" style="margin-top:14px;">
                                        <select x-model="idRejectReason" @change="if (idRejectReason !== 'Other') idRejectReasonOther = ''" style="width:100%;height:44px;padding:0 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;background:#fff;color:#111827;">
                                            <option value="">Select rejection reason</option>
                                            <?php foreach (PF_CUSTOMER_ID_REJECTION_OPTIONS as $reject_option): ?>
                                            <option value="<?php echo htmlspecialchars($reject_option, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($reject_option); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <textarea x-show="idRejectReason === 'Other'" x-model="idRejectReasonOther" maxlength="250" placeholder="Optional note..." style="width:100%;margin-top:12px;min-height:92px;padding:12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;color:#111827;resize:vertical;"></textarea>
                                    </div>
                                </div>
                                <div style="display:flex;justify-content:flex-start;align-items:center;gap:12px;margin-top:16px;">
                                    <button type="button" class="btn-secondary" @click="resetIdActionForm()">Cancel</button>
                                    <button type="button" class="btn-action blue" style="background:#111827;color:#fff;border-color:#111827;min-width:140px;" @click="submitSelectedIdAction()">Submit Action</button>
                                </div>
                            </div>
                            <p x-show="customer?.id_status === 'Verified'" style="font-size:12px;color:#16a34a;font-weight:600;margin:8px 0 0;">&#10003; ID Verified</p>
                            <?php else: ?>
                            <p style="font-size:12px;color:#6b7280;margin:0;">Only admins can verify or reject customer IDs.</p>
                            <?php endif; ?>
                        </div>

                        <div style="padding:16px 24px;border-top:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;gap:12px;">
                            <a :href="'<?php echo $base_path; ?>/admin/customers_management.php?open_customer=' + (customer?.customer_id || '')" class="btn-action teal">View customer profile</a>
                            <button @click="showModal = false" class="btn-secondary">Close</button>
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
