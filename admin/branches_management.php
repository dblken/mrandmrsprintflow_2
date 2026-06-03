<?php
/**
 * Admin Branch Management
 * PrintFlow - Printing Shop PWA
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Access Control: ONLY Owner or Admin
require_role(['Owner', 'Admin']);
// Ensure $base_path is defined
if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

$current_user = get_logged_in_user();

// Pagination, sort, filter
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 10;
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$sort_by = $_GET['sort'] ?? 'newest';

$sql = "SELECT b.*, 
    (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id AND u.role = 'Staff') as staff_count
    FROM branches b 
    WHERE b.status != 'Archived'";
$params = [];
$types = '';

if ($search) {
    $like = '%' . $search . '%';
    $sql .= " AND (b.branch_name LIKE ? OR b.email LIKE ? OR b.address LIKE ? OR b.contact_number LIKE ?)";
    $params = array_merge($params, [$like, $like, $like, $like]);
    $types .= 'ssss';
}
if ($status_filter === 'Active' || $status_filter === 'Inactive') {
    $sql .= " AND b.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$count_sql = "SELECT COUNT(*) as total FROM branches b WHERE b.status != 'Archived'";
if ($search) {
    $count_sql .= " AND (b.branch_name LIKE ? OR b.email LIKE ? OR b.address LIKE ? OR b.contact_number LIKE ?)";
}
if ($status_filter === 'Active' || $status_filter === 'Inactive') {
    $count_sql .= " AND b.status = ?";
}
$total_branches = db_query($count_sql, $types ?: null, $params ?: null)[0]['total'] ?? 0;
$total_pages = max(1, ceil($total_branches / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$order_clause = match($sort_by) {
    'oldest' => "b.created_at ASC",
    'az' => "b.branch_name ASC",
    'za' => "b.branch_name DESC",
    default => "b.created_at DESC"
};
$sql .= " ORDER BY $order_clause LIMIT $per_page OFFSET $offset";
$branches = db_query($sql, $types ?: null, $params ?: null) ?: [];

$page_title = 'Branch Management - Admin';

$branch_success = '';
if (isset($_GET['restored']) && $_GET['restored'] === '1') $branch_success = 'Branch restored successfully!';

// AJAX response for realtime filter/sort
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    ob_start();
    ?>
    <table class="branches-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Branch Name</th>
                <th>Email</th>
                <th>Contact Number</th>
                <th>Staff Assignees</th>
                <th>Status</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($branches)): ?>
                <tr>
                    <td colspan="7" style="padding:40px;text-align:center;color:#6b7280;font-size:14px;"><?php echo $search ? 'No branches found matching your search.' : 'No branches configured yet.'; ?></td>
                </tr>
            <?php else: ?>
                <?php foreach ($branches as $branch):
                    $branchData = json_encode([
                        'id' => $branch['id'],
                        'name' => $branch['branch_name'],
                        'email' => $branch['email'] ?? '',
                        'address' => $branch['address'] ?? '',
                        'address_line' => $branch['address_line'] ?? '',
                        'address_barangay' => $branch['barangay'] ?? '',
                        'address_city' => $branch['city'] ?? '',
                        'address_province' => $branch['province'] ?? '',
                        'contact' => $branch['contact_number'] ?? '',
                        'status' => $branch['status'],
                        'staff_count' => $branch['staff_count']
                    ]);
                    $branchDataAttr = htmlspecialchars($branchData, ENT_QUOTES, 'UTF-8');
                ?>
                    <tr data-branch="<?php echo $branchDataAttr; ?>" style="cursor:pointer;">
                        <td style="color:#6b7280;font-size:12px;"><?php echo $branch['id']; ?></td>
                        <td style="font-weight:600;color:#111827;"><?php echo htmlspecialchars($branch['branch_name']); ?></td>
                        <td style="color:#374151;font-size:13px;"><?php echo htmlspecialchars($branch['email'] ?: '—'); ?></td>
                        <td><?php echo htmlspecialchars($branch['contact_number'] ?: '—'); ?></td>
                        <td>
                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#dbeafe;color:#1e40af;">
                                <?php echo $branch['staff_count']; ?> Staff
                            </span>
                        </td>
                        <td>
                            <?php if ($branch['status'] === 'Active'): ?>
                                <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#dcfce7;color:#166534;">Active</span>
                            <?php else: ?>
                                <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#fee2e2;color:#991b1b;">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;white-space:nowrap;" class="no-truncate">
                            <button type="button" data-view class="btn-action blue">View</button>
                            <button type="button" data-edit class="btn-action teal">Edit</button>
                            <button type="button" data-archive data-id="<?php echo $branch['id']; ?>" data-name="<?php echo htmlspecialchars($branch['branch_name'], ENT_QUOTES, 'UTF-8'); ?>" class="btn-action gray">Archive</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
    $table = ob_get_clean();
    $pagination_params = ['page' => $page];
    if ($search) $pagination_params['search'] = $search;
    if ($status_filter) $pagination_params['status'] = $status_filter;
    if ($sort_by !== 'newest') $pagination_params['sort'] = $sort_by;
    $pagination = render_pagination($page, $total_pages, $pagination_params);
    $badge = count(array_filter([$search, $status_filter], fn($v) => $v !== null && $v !== ''));
    echo json_encode(['success' => true, 'table' => $table, 'pagination' => $pagination, 'badge' => $badge]);
    exit;
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
    <style>
        [x-cloak] { display: none !important; }
        .branches-table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: auto; }
        .branches-table th { padding: 12px 16px; font-size: 13px; font-weight: 600; color: #6b7280; text-align: left; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
        .branches-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; color: #374151; max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .no-truncate { max-width: none !important; overflow: visible !important; white-space: nowrap !important; text-overflow: clip !important; }
        .branches-table tbody tr { cursor: pointer; transition: background 0.1s; }
        .branches-table tbody tr:hover td { background: #f9fafb; }
        .branches-table tbody tr:last-child td { border-bottom: none; }
        .modal-overlay { position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:9999; }
        .modal-panel { background:#fff; border-radius:12px; box-shadow:0 25px 50px rgba(0,0,0,0.25); width:100%; max-width:500px; max-height:85vh; margin:16px; position:relative; display:flex; flex-direction:column; overflow:hidden; }
        .modal-header { padding:24px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; flex-shrink:0; }
        .modal-body { padding:24px; overflow-y:auto; flex:1; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .form-input { width: 100%; padding: 10px 14px; border: 1.5px solid #e5e7eb; border-radius: 8px; font-size: 14px; color: #1f2937; background: #f9fafb; outline: none; transition: all 0.2s; }
        .form-input:focus { border-color: #3b82f6; background: #ffffff; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12); }
        .toolbar-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border: 1px solid #e5e7eb; background: #fff; border-radius: 8px; font-size: 13px; font-weight: 500; color: #374151; cursor: pointer; transition: all 0.15s; white-space: nowrap; }
        .toolbar-btn:hover { border-color: #9ca3af; background: #f9fafb; }
        .btn-action { display: inline-flex; align-items: center; justify-content: center; padding: 5px 12px; min-width: 80px; border: 1px solid transparent; background: transparent; border-radius: 6px; font-size: 12px; font-weight: 500; transition: all 0.2s; cursor: pointer; text-decoration: none; white-space: nowrap; }
        .btn-action.blue { color: #3b82f6; border-color: #3b82f6; }
        .btn-action.blue:hover { background: #3b82f6; color: white; }
        .btn-action.teal { color: #14b8a6; border-color: #14b8a6; }
        .btn-action.teal:hover { background: #14b8a6; color: white; }
        .btn-action.gray { color: #6b7280; border-color: #d1d5db; }
        .btn-action.gray:hover { background: #6b7280; color: white; }
        .btn-action.red { color: #ef4444; border-color: #ef4444; }
        .btn-action.red:hover { background: #ef4444; color: white; }
        .toolbar-btn.active { border-color: #0d9488; color: #0d9488; background: #f0fdfa; }
        .toolbar-btn svg { flex-shrink: 0; }
        .sort-dropdown { position: absolute; top: calc(100% + 6px); right: 0; width: 180px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); z-index: 200; padding: 6px; }
        .sort-option { padding: 9px 12px; font-size: 13px; color: #4b5563; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: space-between; }
        .sort-option:hover { background: #f9fafb; color: #111827; }
        .sort-option.selected { background: #f0fdfa; color: #0d9488; font-weight: 600; }
        .btn-secondary { border-radius: 10px; height: 44px; padding: 0 24px; border: 1px solid #e5e7eb; background: #fff; color: #374151; font-weight: 600; cursor: pointer; }
        .btn-secondary:hover { background: #f9fafb; }
        .btn-save { border-radius: 10px; height: 44px; padding: 0 24px; background: #0d9488; color: #fff; border: none; font-weight: 600; cursor: pointer; transition: background 0.2s; }
        .btn-save:hover:not(:disabled) { background: #0f766e; }
        .btn-save:disabled { opacity: 0.65; cursor: not-allowed; }
        .modal-footer-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 32px; padding-top: 24px; border-top: 1px solid #f3f4f6; flex-shrink: 0; }
        .confirm-modal-actions { display: flex; gap: 12px; justify-content: center; }
        .confirm-modal-actions .btn-secondary,
        .confirm-modal-actions .btn-save { flex: 1; height: auto; padding: 12px 16px; font-size: 14px; }
        .filter-panel { position: absolute; top: calc(100% + 6px); right: 0; width: 280px; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.12); z-index: 200; overflow: hidden; }
        .filter-panel-header { padding: 14px 18px; border-bottom: 1px solid #f3f4f6; font-size: 14px; font-weight: 700; color: #111827; }
        .filter-section { padding: 14px 18px; border-bottom: 1px solid #f3f4f6; }
        .filter-section-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .filter-section-label { font-size: 13px; font-weight: 600; color: #374151; }
        .filter-reset-link { font-size: 12px; font-weight: 600; color: #0d9488; cursor: pointer; background: none; border: none; padding: 0; }
        .filter-reset-link:hover { text-decoration: underline; }
        .filter-select { width: 100%; height: 34px; border: 1px solid #e5e7eb; border-radius: 7px; font-size: 13px; padding: 0 10px; color: #1f2937; background: #fff; box-sizing: border-box; cursor: pointer; }
        .filter-select:focus { outline: none; border-color: #0d9488; }
        .filter-search-input { width: 100%; height: 34px; border: 1px solid #e5e7eb; border-radius: 7px; font-size: 13px; padding: 0 12px; color: #1f2937; box-sizing: border-box; }
        .filter-search-input:focus { outline: none; border-color: #0d9488; }
        .filter-actions { display: flex; gap: 8px; padding: 14px 18px; border-top: 1px solid #f3f4f6; }
        .filter-btn-reset { flex: 1; height: 36px; border: 1px solid #e5e7eb; background: #fff; border-radius: 8px; font-size: 13px; font-weight: 500; color: #374151; cursor: pointer; }
        .filter-btn-reset:hover { background: #f9fafb; }
        .filter-badge { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; background: #0d9488; color: #fff; border-radius: 50%; font-size: 10px; font-weight: 700; }
    </style>
</head>
<body>

<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . ($current_user['role'] === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>

<script>
    var branchSearchDebounceTimer = null;

    function branchManagement() {
        return {
            viewModal: { isOpen: false, data: {} },
            archiveModal: { isOpen: false, loading: false, content: '', pagination: '', page: 1 },
            archiveConfirmModal: { isOpen: false, id: 0, name: '' },
            restoreConfirmModal: { isOpen: false, id: 0, name: '' },
            modal: { isOpen: false, mode: 'create', isSubmitting: false, error: '' },
            form: { branch_id: 0, branch_name: '', email: '', address: '', address_province: '', address_city: '', address_barangay: '', address_line: '', contact_number: '09', status: 'Active' },
            errors: { email: '' },
            addressProvinces: [], addressCities: [], addressBarangays: [],
            loadingCities: false, loadingBarangays: false,
            toast: { show: false, message: '', type: 'success' },

            async init() { if (typeof this.loadProvinces === 'function') await this.loadProvinces(); this.checkUrlSuccess(); },
            
            checkUrlSuccess() {
                const params = new URLSearchParams(window.location.search);
                let strip = false;
                if (params.get('restored') === '1') {
                    this.showToast('Branch restored successfully.', 'success');
                    params.delete('restored');
                    strip = true;
                }
                if (params.get('deleted') === '1') {
                    params.delete('deleted');
                    strip = true;
                }
                if (strip) {
                    const qs = params.toString();
                    window.history.replaceState({}, '', window.location.pathname + (qs ? '?' + qs : ''));
                }
            },

            async validateEmail() {
                const email = this.form.email.trim();
                if (!email) return;
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { this.errors.email = 'Invalid email'; return; }
                try {
                    const resp = await fetch('<?php echo $base_path; ?>/admin/api_branch.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'check_email', email, exclude_id: this.form.branch_id, csrf_token: '<?php echo $_SESSION["csrf_token"] ?? ""; ?>' })
                    });
                    const res = await resp.json();
                    this.errors.email = res.exists ? 'Email already assigned' : '';
                } catch (e) {}
            },

            async loadProvinces() {
                try {
                    const r = await fetch('<?php echo $base_path; ?>/admin/api_address.php?address_action=provinces');
                    const d = await r.json();
                    if (d.success) this.addressProvinces = d.data;
                } catch (e) {}
            },

            async loadCities(provinceName = null, targetValue = null) {
                const pName = provinceName || this.form.address_province;
                const p = this.addressProvinces.find(x => x.name.toLowerCase() === (pName || '').toLowerCase());
                if (!p?.code) return;
                this.loadingCities = true;
                try {
                    const r = await fetch('<?php echo $base_path; ?>/admin/api_address.php?address_action=cities&province_code=' + encodeURIComponent(p.code));
                    const d = await r.json();
                    if (d.success) {
                        this.addressCities = d.data;
                        if (targetValue) {
                            const matched = d.data.find(c => c.name.toLowerCase() === targetValue.toLowerCase().trim());
                            if (matched) this.form.address_city = matched.name;
                        }
                    }
                    this.buildAddress();
                } catch (e) {} finally { this.loadingCities = false; }
            },

            async loadBarangays(cityName = null, targetValue = null) {
                const cName = cityName || this.form.address_city;
                const c = this.addressCities.find(x => x.name.toLowerCase() === (cName || '').toLowerCase());
                if (!c?.code) return;
                this.loadingBarangays = true;
                try {
                    const r = await fetch('<?php echo $base_path; ?>/admin/api_address.php?address_action=barangays&city_code=' + encodeURIComponent(c.code));
                    const d = await r.json();
                    if (d.success) {
                        this.addressBarangays = d.data;
                        if (targetValue) {
                            const matched = d.data.find(b => b.name.toLowerCase() === targetValue.toLowerCase().trim());
                            if (matched) this.form.address_barangay = matched.name;
                        }
                    }
                    this.buildAddress();
                } catch (e) {} finally { this.loadingBarangays = false; }
            },

            buildAddress() {
                const p = [this.form.address_line, this.form.address_barangay ? 'Brgy. ' + this.form.address_barangay : '', this.form.address_city, this.form.address_province].filter(Boolean);
                this.form.address = p.length ? p.join(', ') + ', Philippines' : '';
            },

            openViewModal(data) { if (data) { this.viewModal.data = data; this.viewModal.isOpen = true; } },
            
            async openModal(mode, data = null) {
                this.modal.mode = mode; this.modal.error = ''; this.errors = { email: '' };
                this.viewModal.isOpen = false; this.modal.isOpen = true;
                if (mode === 'create') {
                    this.addressCities = []; this.addressBarangays = [];
                    this.form = { branch_id: 0, branch_name: '', email: '', address: '', address_province: '', address_city: '', address_barangay: '', address_line: '', contact_number: '09', status: 'Active' };
                } else if (mode === 'update' && data) {
                    this.form = {
                        branch_id: data.id, branch_name: (data.name || '').replace(/\s+Branch$/i, ''),
                        email: data.email || '', address: data.address || '',
                        address_province: data.address_province || '', address_city: data.address_city || '',
                        address_barangay: data.address_barangay || '', address_line: data.address_line || '',
                        contact_number: data.contact || '', status: data.status || 'Active'
                    };
                    await this.cascadeLoadAddress(data.address_province, data.address_city, data.address_barangay);
                }
            },

            async cascadeLoadAddress(targetP, targetC, targetB) {
                if (!this.addressProvinces.length) await this.loadProvinces();
                if (targetP) {
                    const mp = this.addressProvinces.find(p => p.name.toLowerCase() === targetP.toLowerCase());
                    if (mp) {
                        this.form.address_province = mp.name;
                        await this.loadCities(mp.name, targetC);
                        if (this.form.address_city || targetC) await this.loadBarangays(this.form.address_city || targetC, targetB);
                    }
                }
            },

            showToast(m, t = 'success') { this.toast.message = m; this.toast.type = t; this.toast.show = true; setTimeout(() => this.toast.show = false, 3000); },

            async submitForm() {
                await this.validateEmail(); if (this.errors.email) return;
                this.modal.isSubmitting = true; this.modal.error = '';
                try {
                    const res = await fetch('<?php echo $base_path; ?>/admin/api_branch.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: this.modal.mode, ...this.form, csrf_token: '<?php echo $_SESSION["csrf_token"] ?? ""; ?>'
                        })
                    });
                    const result = await res.json();
                    if (result.success) { this.modal.isOpen = false; this.showToast(result.message); setTimeout(() => window.location.reload(), 1200); }
                    else { this.modal.error = result.error || 'Request failed'; }
                } catch (e) { this.modal.error = 'Network error'; } finally { this.modal.isSubmitting = false; }
            },

            async openArchiveModal(page = 1) {
                this.archiveModal.isOpen = true; this.archiveModal.loading = true;
                try {
                    const r = await fetch('<?php echo $base_path; ?>/admin/api_branch.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'get_archived', page, per_page: 7, csrf_token: '<?php echo $_SESSION["csrf_token"] ?? ""; ?>' })
                    });
                    const res = await r.json();
                    if (res.success) { this.archiveModal.content = res.html; this.archiveModal.pagination = res.pagination; this.archiveModal.page = page; }
                } catch (e) {} finally { this.archiveModal.loading = false; }
            },

            showRestoreConfirmModal(id, name) { this.restoreConfirmModal = { id, name, isOpen: true }; },
            async confirmRestoreBranch() {
                await this.restoreBranch(this.restoreConfirmModal.id);
                this.restoreConfirmModal.isOpen = false;
            },
            async restoreBranch(id) {
                try {
                    const r = await fetch('<?php echo $base_path; ?>/admin/api_branch.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'restore', branch_id: id, csrf_token: '<?php echo $_SESSION["csrf_token"] ?? ""; ?>' })
                    });
                    if ((await r.json()).success) window.location.href = 'branches_management.php?restored=1';
                } catch (e) {}
            },

            showArchiveConfirmModal(id, name) { this.archiveConfirmModal = { id, name, isOpen: true }; },
            async confirmArchiveBranch() {
                await this.archiveBranch(this.archiveConfirmModal.id);
                this.archiveConfirmModal.isOpen = false;
            },
            async archiveBranch(id) {
                try {
                    const r = await fetch('<?php echo $base_path; ?>/admin/api_branch.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'archive', branch_id: id, csrf_token: '<?php echo $_SESSION["csrf_token"] ?? ""; ?>' })
                    });
                    if ((await r.json()).success) { this.showToast('Archived'); setTimeout(() => window.location.reload(), 500); }
                } catch (e) {}
            },

            handleArchiveAction(e) {
                const btn = e.target.closest('button[data-action]');
                if (!btn) return;
                e.stopPropagation();
                const action = btn.getAttribute('data-action');
                const id = parseInt(btn.getAttribute('data-id'));
                const name = btn.getAttribute('data-name');
                if (action === 'view') {
                    const data = JSON.parse(btn.getAttribute('data-branch'));
                    this.openViewModal(data);
                } else if (action === 'restore') {
                    this.showRestoreConfirmModal(id, name);
                }
            },

            handleArchivePagination(e) {
                const link = e.target.closest('a[data-archive-page]');
                if (!link) return;
                e.preventDefault();
                const page = parseInt(link.getAttribute('data-archive-page'));
                this.openArchiveModal(page);
            }
        };
    }
    window.branchManagement = branchManagement;

    function branchFilterPanel() {
        return {
            sortOpen: false, filterOpen: false, activeSort: '<?php echo $sort_by; ?>',
            get hasActiveFilters() { return document.getElementById('fp_status')?.value || document.getElementById('fp_search')?.value; },
            fetchBranchTable(page = 1) {
                const p = new URLSearchParams(); p.set('ajax', '1'); if (page > 1) p.set('page', page);
                const st = document.getElementById('fp_status')?.value; if (st) p.set('status', st);
                const s = document.getElementById('fp_search')?.value; if (s) p.set('search', s);
                if (this.activeSort !== 'newest') p.set('sort', this.activeSort);
                fetch('?' + p.toString()).then(r => r.json()).then(d => {
                    if (!d.success) return;
                    const c = document.getElementById('branchesTableContainer');
                    if (c) {
                        c.innerHTML = d.table + '<div id="branchesPagination">' + d.pagination + '</div>';
                        /* AJAX fragment is plain table rows; no Alpine directives — avoid nested initTree under branchManagement(). */
                    }
                    const b = document.getElementById('branchFilterBadgeContainer');
                    if (b) b.innerHTML = d.badge > 0 ? `<span class="filter-badge">${d.badge}</span>` : '';
                    p.delete('ajax');
                    window.history.replaceState(null, '', 'branches_management.php' + (p.toString() ? '?' + p.toString() : ''));
                });
            },
            applySortFilter(k) { this.activeSort = k; this.sortOpen = false; this.fetchBranchTable(1); },
            applyBranchFilters(reset) {
                if (reset) {
                    const st = document.getElementById('fp_status'); if (st) st.value = '';
                    const s = document.getElementById('fp_search'); if (s) s.value = '';
                    this.activeSort = 'newest';
                }
                this.fetchBranchTable(1);
            },
            applyBranchFiltersDebounced() {
                clearTimeout(branchSearchDebounceTimer);
                branchSearchDebounceTimer = setTimeout(() => this.fetchBranchTable(1), 400);
            },
            resetFilterField(fs) {
                fs.forEach(f => { const el = document.getElementById('fp_' + f); if (el) el.value = ''; });
                this.fetchBranchTable(1);
            }
        };
    }

    function printflowInitBranchesPage() {
        const c = document.getElementById('branchesTableContainer'); if (!c) return;
        /* [x-data] roots: Alpine.start / turbo-init initTree(.main-content) — avoid extra initTree passes. */
        if (!c._pf_bound) {
            c._pf_bound = true;
            c.addEventListener('click', e => {
                const row = e.target.closest('tr[data-branch]'); if (!row) return;
                const btnArc = e.target.closest('button[data-archive]');
                const mainRoot = document.querySelector('main[x-data="branchManagement()"]');
                const mgmt = mainRoot?._x_dataStack?.[0];
                if (!mgmt) return;
                if (btnArc) { e.stopPropagation(); mgmt.showArchiveConfirmModal(parseInt(btnArc.getAttribute('data-id')), btnArc.getAttribute('data-name')); return; }
                const data = JSON.parse(row.getAttribute('data-branch'));
                const btnView = e.target.closest('button[data-view]');
                const btnEdit = e.target.closest('button[data-edit]');
                if (btnView) { e.stopPropagation(); mgmt.openViewModal(data); }
                else if (btnEdit) { e.stopPropagation(); mgmt.openModal('update', data); }
                else mgmt.openViewModal(data);
            });
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', printflowInitBranchesPage);
    else printflowInitBranchesPage();
    document.addEventListener('printflow:page-init', printflowInitBranchesPage);
</script>


    <!-- Main Content -->
    <div class="main-content">
        <header>
            <h1 class="page-title">Branch Management</h1>
        </header>

        <main x-data="branchManagement()" x-init="init(); checkUrlSuccess()">
            <?php if ($branch_success): ?>
            <div style="background:#f0fdf4; border:1px solid #86efac; color:#166534; padding:12px 16px; border-radius:8px; margin-bottom:16px;">
                ✓ <?php echo htmlspecialchars($branch_success); ?>
            </div>
            <?php endif; ?>
            <!-- Alert message for successful actions (matches products page style) -->
            <div x-show="toast.show" x-cloak
                 style="background:#f0fdf4; border:1px solid #86efac; color:#166534; padding:12px 16px; border-radius:8px; margin-bottom:16px; display:flex; align-items:center; gap:10px; font-size:14px; font-weight:500;"
                 :style="toast.type === 'error' ? 'background:#fef2f2; border-color:#fecaca; color:#991b1b' : ''"
                 x-transition.opacity.duration.300ms>
                <span x-show="toast.type === 'success'" style="font-size:16px; font-weight:bold;">✓</span>
                <span x-show="toast.type === 'error'" style="font-size:16px; font-weight:bold;">!</span>
                <span x-text="toast.message"></span>
            </div>

            <!-- Branch Table -->
            <div class="card">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;" x-data="branchFilterPanel()">
                    <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;">Branches List</h3>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <button type="button" onclick="document.querySelector('main[x-data]')._x_dataStack[0].openModal('create')" class="toolbar-btn" style="height:38px;border-color:#3b82f6;color:#3b82f6;">Add Item</button>
                        <button type="button" onclick="document.querySelector('main[x-data]')._x_dataStack[0].openArchiveModal()" class="toolbar-btn" style="height:38px;border-color:#6b7280;color:#6b7280;display:flex;align-items:center;gap:6px;">
                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                            </svg>
                            Archived Items
                        </button>
                        <div style="position:relative;">
                            <button type="button" class="toolbar-btn" :class="{active: sortOpen}" @click="sortOpen = !sortOpen; filterOpen = false" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="9" y1="18" x2="15" y2="18"/>
                                </svg>
                                Sort by
                            </button>
                            <div class="sort-dropdown" x-show="sortOpen" x-cloak @click.outside="sortOpen = false">
                                <?php $sorts = ['newest'=>'Newest to Oldest','oldest'=>'Oldest to Newest','az'=>'A → Z','za'=>'Z → A']; foreach ($sorts as $key => $label): ?>
                                <div class="sort-option" :class="{ 'selected': activeSort === '<?php echo $key; ?>' }" @click="applySortFilter('<?php echo $key; ?>')">
                                    <?php echo htmlspecialchars($label); ?>
                                    <svg x-show="activeSort === '<?php echo $key; ?>'" class="check" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div style="position:relative;">
                            <button type="button" class="toolbar-btn" :class="{active: filterOpen || hasActiveFilters}" @click="filterOpen = !filterOpen; sortOpen = false" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                                </svg>
                                Filter
                                <span id="branchFilterBadgeContainer">
                                    <?php $afc = count(array_filter([$search, $status_filter], fn($v) => $v !== null && $v !== '')); if ($afc > 0): ?>
                                    <span class="filter-badge"><?php echo $afc; ?></span>
                                    <?php endif; ?>
                                </span>
                            </button>
                            <div class="filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false">
                                <div class="filter-panel-header">Filter</div>
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Status</span>
                                        <button type="button" class="filter-reset-link" @click="resetFilterField(['status'])">Reset</button>
                                    </div>
                                    <select id="fp_status" class="filter-select" @change="applyBranchFilters()">
                                        <option value="">All statuses</option>
                                        <option value="Active" <?php echo $status_filter==='Active'?'selected':''; ?>>Active</option>
                                        <option value="Inactive" <?php echo $status_filter==='Inactive'?'selected':''; ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Keyword search</span>
                                        <button type="button" class="filter-reset-link" @click="resetFilterField(['search'])">Reset</button>
                                    </div>
                                    <input type="text" id="fp_search" class="filter-search-input" placeholder="Search by name, email..." value="<?php echo htmlspecialchars($search); ?>" @input="applyBranchFiltersDebounced()">
                                </div>
                                <div class="filter-actions">
                                    <button type="button" class="filter-btn-reset" style="width:100%;" @click="applyBranchFilters(true)">Reset all filters</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="branchesTableContainer">
                    <div class="overflow-x-auto">
                    <table class="branches-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Branch Name</th>
                                <th>Email</th>
                                <th>Contact Number</th>
                                <th>Staff Assignees</th>
                                <th>Status</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($branches)): ?>
                                <tr>
                                    <td colspan="7" style="padding:40px;text-align:center;color:#6b7280;font-size:14px;">No branches configured yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($branches as $branch): 
                                    $branchData = json_encode([
                                        'id' => $branch['id'],
                                        'name' => $branch['branch_name'],
                                        'email' => $branch['email'] ?? '',
                                        'address' => $branch['address'] ?? '',
                                        'address_line' => $branch['address_line'] ?? '',
                                        'address_barangay' => $branch['barangay'] ?? '',
                                        'address_city' => $branch['city'] ?? '',
                                        'address_province' => $branch['province'] ?? '',
                                        'contact' => $branch['contact_number'] ?? '',
                                        'status' => $branch['status'],
                                        'staff_count' => $branch['staff_count']
                                    ]);
                                    $branchDataAttr = htmlspecialchars($branchData, ENT_QUOTES, 'UTF-8');
                                ?>
                                    <tr data-branch="<?php echo $branchDataAttr; ?>" style="cursor:pointer;">
                                        <td style="color:#6b7280;font-size:12px;"><?php echo $branch['id']; ?></td>
                                        <td style="font-weight:600;color:#111827;"><?php echo htmlspecialchars($branch['branch_name']); ?></td>
                                        <td style="color:#374151;font-size:13px;"><?php echo htmlspecialchars($branch['email'] ?: '—'); ?></td>
                                        <td><?php echo htmlspecialchars($branch['contact_number'] ?: '—'); ?></td>
                                        <td>
                                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#dbeafe;color:#1e40af;">
                                                <?php echo $branch['staff_count']; ?> Staff
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($branch['status'] === 'Active'): ?>
                                                <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#dcfce7;color:#166534;">Active</span>
                                            <?php else: ?>
                                                <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#fee2e2;color:#991b1b;">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right;white-space:nowrap;" class="no-truncate">
                                            <button type="button" data-view class="btn-action blue">View</button>
                                            <button type="button" data-edit class="btn-action teal">Edit</button>
                                            <button type="button" data-archive data-id="<?php echo $branch['id']; ?>" data-name="<?php echo htmlspecialchars($branch['branch_name'], ENT_QUOTES, 'UTF-8'); ?>" class="btn-action gray">Archive</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                    </table>
                    </div>
                <?php 
                $pagination_params = ['page' => $page];
                if ($search) $pagination_params['search'] = $search;
                if ($status_filter) $pagination_params['status'] = $status_filter;
                if ($sort_by !== 'newest') $pagination_params['sort'] = $sort_by;
                echo render_pagination($page, $total_pages, $pagination_params); 
                ?>
                </div>
            </div>

<!-- Branch modals (inside main x-data="branchManagement()" for Alpine scope) -->
<!-- View Branch Modal -->
<div x-show="viewModal.isOpen" x-cloak>
    <div class="modal-overlay" @click.self="viewModal.isOpen = false">
        <div class="modal-panel" @click.stop style="max-width:500px;">
            <div class="modal-header">
                <h2 style="font-size:20px; font-weight:700; color:#111827; margin:0;">Branch Details</h2>
                <button @click="viewModal.isOpen = false" style="background:none; border:none; font-size:24px; color:#6b7280; cursor:pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom:16px;">
                    <div style="font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">Branch Name</div>
                    <div style="font-size:14px; color:#1f2937;" x-text="viewModal.data.name || '—'"></div>
                </div>
                <div style="margin-bottom:16px;">
                    <div style="font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">Email Address</div>
                    <div style="font-size:14px; color:#1f2937;" x-text="viewModal.data.email || '—'"></div>
                </div>
                <div style="margin-bottom:16px;">
                    <div style="font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">Address</div>
                    <div style="font-size:14px; color:#1f2937; white-space:pre-wrap;" x-text="viewModal.data.address || '—'"></div>
                </div>
                <div style="margin-bottom:16px;">
                    <div style="font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">Phone Number</div>
                    <div style="font-size:14px; color:#1f2937;" x-text="viewModal.data.contact || '—'"></div>
                </div>
                <div style="margin-bottom:16px;">
                    <div style="font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">Staff Assignees</div>
                    <div><span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#dbeafe;color:#1e40af;" x-text="(viewModal.data.staff_count || 0) + ' Staff'"></span></div>
                </div>
                <div style="margin-bottom:16px;">
                    <div style="font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">Status</div>
                    <div>
                        <span x-show="viewModal.data.status === 'Active'" style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#dcfce7;color:#166534;">Active</span>
                        <span x-show="viewModal.data.status !== 'Active'" style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#fee2e2;color:#991b1b;">Inactive</span>
                    </div>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:24px; padding-top:20px; border-top:1px solid #e5e7eb;">
                    <button type="button" @click="viewModal.isOpen = false" style="padding:10px 16px; border:1px solid #e5e7eb; border-radius:8px; background:#fff; cursor:pointer;">Close</button>
                    <button type="button" @click="viewModal.isOpen = false; openModal('update', viewModal.data)" class="btn-action teal">Edit</button>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Archive Storage Modal -->
<div x-show="archiveModal.isOpen" x-cloak>
    <div class="modal-overlay" @click.self="archiveModal.isOpen = false">
        <div class="modal-panel" @click.stop style="max-width:950px; width:95vw;">
            <div class="modal-header">
                <h2 style="font-size:20px; font-weight:700; color:#111827; margin:0;">Archived Items</h2>
                <button @click="archiveModal.isOpen = false" style="background:none; border:none; font-size:24px; color:#6b7280; cursor:pointer;">&times;</button>
            </div>
            <div class="modal-body">
                <div x-show="archiveModal.loading" style="padding:40px; text-align:center; color:#6b7280;">Loading archived branches...</div>
                <div x-show="!archiveModal.loading">
                    <div class="overflow-x-auto" @click="handleArchiveAction($event)" x-html="archiveModal.content"></div>
                    <div @click="handleArchivePagination($event)" x-html="archiveModal.pagination"></div>
                </div>
                
                <div style="display:flex; justify-content:flex-end; margin-top:24px; padding-top:20px; border-top:1px solid #e5e7eb;">
                    <button type="button" @click="archiveModal.isOpen = false" style="padding:10px 16px; border:1px solid #e5e7eb; border-radius:8px; background:#fff; cursor:pointer;">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Restore Confirmation Modal -->
<div x-show="restoreConfirmModal.isOpen" x-cloak>
    <div class="modal-overlay" @click.self="restoreConfirmModal.isOpen = false">
        <div style="background:white;border-radius:16px;padding:26px;max-width:420px;width:100%;box-shadow:0 25px 50px rgba(0,0,0,0.25);text-align:center;margin:16px;">
            <div style="width:48px;height:48px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;color:#166534;">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
            </div>
            <h3 style="font-size:18px;font-weight:700;color:#1f2937;margin:0 0 8px;">Confirm Restore</h3>
            <p style="font-size:14px;color:#4b5563;margin:0 0 16px;line-height:1.5;" x-text="'Are you sure you want to restore branch \'' + (restoreConfirmModal.name || '') + '\'? It will be moved back to the main list.'"></p>
            <div style="font-size:12px;color:#6b7280;background:#f9fafb;padding:12px;border-radius:10px;margin-bottom:24px;text-align:left;border:1px solid #e5e7eb;line-height:1.5;">
                <div style="font-weight:700;margin-bottom:4px;color:#374151;">What happens next?</div>
                <div>This will bring the branch back to the active list and make it available for use in the system.</div>
            </div>
            <div class="confirm-modal-actions">
                <button type="button" @click="restoreConfirmModal.isOpen = false" class="btn-secondary">Cancel</button>
                <button type="button" @click="confirmRestoreBranch()" class="btn-save">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- Archive Confirmation Modal -->
<div x-show="archiveConfirmModal.isOpen" x-cloak>
    <div class="modal-overlay" @click.self="archiveConfirmModal.isOpen = false">
        <div style="background:white;border-radius:16px;padding:26px;max-width:420px;width:100%;box-shadow:0 25px 50px rgba(0,0,0,0.25);text-align:center;margin:16px;">
            <div style="width:48px;height:48px;background:#f3f4f6;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;color:#6b7280;">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
            <h3 style="font-size:18px;font-weight:700;color:#1f2937;margin:0 0 8px;">Confirm Archive</h3>
            <p style="font-size:14px;color:#4b5563;margin:0 0 16px;line-height:1.5;" x-text="'Are you sure you want to archive branch \'' + (archiveConfirmModal.name || '') + '\'? It will be moved to Archive Storage.'"></p>
            <div style="font-size:12px;color:#6b7280;background:#f9fafb;padding:12px;border-radius:10px;margin-bottom:24px;text-align:left;border:1px solid #e5e7eb;line-height:1.5;">
                <div style="font-weight:700;margin-bottom:4px;color:#374151;">What happens next?</div>
                <div>Archiving will remove this branch from the main list. You can still access and restore it from the <em>Archived Items</em>.</div>
            </div>
            <div style="display:flex;gap:12px;justify-content:center;">
                <button type="button" @click="archiveConfirmModal.isOpen = false" style="flex:1;padding:12px 16px;border:1px solid #e5e7eb;background:white;border-radius:10px;font-size:14px;font-weight:600;color:#4b5563;cursor:pointer;">Cancel</button>
                <button type="button" @click="confirmArchiveBranch()" style="flex:1;padding:12px 16px;border:none;background:#6b7280;border-radius:10px;font-size:14px;font-weight:600;color:white;cursor:pointer;">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Branch Modal -->
<div x-show="modal.isOpen" x-cloak>
    <div class="modal-overlay" @click.self="modal.isOpen = false">
        <div class="modal-panel" @click.stop>
            <div class="modal-header">
                <h2 style="font-size:20px; font-weight:700; color:#111827; margin:0;" x-text="modal.mode === 'create' ? 'Register New Branch' : 'Edit Branch'"></h2>
                <button @click="modal.isOpen = false" style="background:none; border:none; font-size:24px; color:#6b7280; cursor:pointer;">&times;</button>
            </div>
            <div class="modal-body">
            <form @submit.prevent="submitForm()">
                <div x-show="modal.error" x-text="modal.error" style="background:#fef2f2; color:#b91c1c; padding:12px; border-radius:8px; font-size:14px; margin-bottom:16px;"></div>
                
                <div class="form-group">
                    <label class="form-label">Branch Name <span style="color:#ef4444">*</span></label>
                    <input type="text" x-model="form.branch_name" 
                            @input="
                                let v = $event.target.value.replace(/^\s+/, '');
                                form.branch_name = v.replace(/(^\w|\s\w)/g, m => m.toUpperCase());
                            "
                            @blur="form.branch_name = form.branch_name.trim().split(/\s+/).map(w => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase()).join(' ')"
                            class="form-input" placeholder="e.g. Quezon City" required>
                    <p style="font-size:11px; color:#6b7280; margin-top:4px;">"Branch" will be added automatically (e.g. "Quezon City Branch"). Don't type "Branch" — it's appended for you.</p>
                </div>

                <div class="form-group">
                    <label class="form-label">Email Address <span style="color:#ef4444">*</span></label>
                    <input type="email" x-model="form.email" 
                           @keydown.space.prevent
                           @input="form.email = form.email.replace(/\s/g, ''); validateEmail()" 
                           class="form-input" placeholder="e.g. branch@printflow.com" required>
                    <template x-if="errors.email">
                        <div class="error-message" x-text="errors.email" style="color:#ef4444; font-size:12px; margin-top:4px; display:block;"></div>
                    </template>
                </div>

                <div class="form-group">
                    <label class="form-label">Phone Number <span style="color:#ef4444">*</span></label>
                    <input type="text" x-model="form.contact_number" 
                           @input="
                                let v = $event.target.value.replace(/[^0-9]/g, '');
                                if (v.length < 2) v = '09';
                                if (!v.startsWith('09')) v = '09' + v.replace(/^0+/, '');
                                form.contact_number = v.slice(0, 11);
                           "
                           class="form-input" placeholder="e.g. 09123456789" maxlength="11" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Province</label>
                    <select :value="form.address_province" 
                            @change="form.address_province = $event.target.value; loadCities()" 
                            class="form-input" :disabled="!addressProvinces.length">
                        <option value="">Select province</option>
                        <template x-for="p in (addressProvinces || [])" :key="p.code">
                            <option :value="p.name" x-text="p.name" :selected="p.name === form.address_province"></option>
                        </template>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">City / Municipality</label>
                    <select :value="form.address_city" 
                            @change="form.address_city = $event.target.value; loadBarangays()" 
                            class="form-input" :disabled="!form.address_province || loadingCities">
                        <option value="" x-text="loadingCities ? 'Loading cities...' : 'Select city/municipality'"></option>
                        <template x-for="c in (addressCities || [])" :key="c.code">
                            <option :value="c.name" x-text="c.name" :selected="c.name === form.address_city"></option>
                        </template>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Barangay</label>
                    <select :value="form.address_barangay" 
                            @change="form.address_barangay = $event.target.value; buildAddress()" 
                            class="form-input" :disabled="!form.address_city || loadingBarangays">
                        <option value="" x-text="loadingBarangays ? 'Loading barangays...' : 'Select barangay'"></option>
                        <template x-for="b in (addressBarangays || [])" :key="b.code">
                            <option :value="b.name" x-text="b.name" :selected="b.name === form.address_barangay"></option>
                        </template>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Street / House No. (Optional)</label>
                    <input type="text" x-model="form.address_line" @input="buildAddress()" class="form-input" maxlength="120" placeholder="e.g. 123 Rizal St.">
                </div>
                <div class="form-group">
                    <label class="form-label">Address Preview</label>
                    <textarea x-model="form.address" class="form-input" rows="2" readonly placeholder="Select province, city, and barangay"></textarea>
                </div>


                <div class="form-group" x-show="modal.mode === 'update'">
                    <label class="form-label">Operating Status</label>
                    <select x-model="form.status" class="form-input">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive (Prevents new orders)</option>
                    </select>
                </div>

                <div class="modal-footer-actions">
                    <button type="button" @click="modal.isOpen = false" class="btn-secondary">Cancel</button>
                    <button type="submit" class="btn-save"
                            x-text="modal.isSubmitting ? 'Saving...' : (modal.mode === 'create' ? 'Create Branch' : 'Save Changes')"
                            :disabled="modal.isSubmitting"></button>
                </div>
            </form>
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
