<?php
/**
 * Admin Payment Queue
 * Global payment verification view across branches.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/branch_ui.php';

require_role(['Admin', 'Manager']);

if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

$current_user = get_logged_in_user();
$branchCtx = init_branch_context(false);
$branchId = $branchCtx['selected_branch_id'];
// Match the customization list scope so generated product job rows do not duplicate product payments.
$jobCustomizationScopeSql = " AND (
    jo.order_id IS NULL
    OR EXISTS (
        SELECT 1
        FROM orders o_scope
        JOIN order_items oi_scope ON oi_scope.order_id = o_scope.order_id
        LEFT JOIN products p_scope ON p_scope.product_id = oi_scope.product_id
        WHERE o_scope.order_id = jo.order_id
          AND o_scope.order_type = 'custom'
          AND COALESCE(LOWER(TRIM(p_scope.product_type)), 'custom') <> 'fixed'
    )
)";

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'to_verify')));
$typeFilter = strtolower(trim((string)($_GET['type'] ?? 'all')));
$sortBy = strtolower(trim((string)($_GET['sort'] ?? 'newest')));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$allowedStatusFilters = ['to_verify', 'verified', 'rejected', 'all'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = 'to_verify';
}

$allowedTypeFilters = ['all', 'product', 'customization'];
if (!in_array($typeFilter, $allowedTypeFilters, true)) {
    $typeFilter = 'all';
}

$allowedSorts = ['newest', 'oldest', 'amount_high', 'amount_low'];
if (!in_array($sortBy, $allowedSorts, true)) {
    $sortBy = 'newest';
}

function pf_admin_payment_has_column(string $table, string $column): bool
{
    return function_exists('db_table_has_column') && db_table_has_column($table, $column);
}

function pf_admin_payment_proof_url(string $proof): string
{
    $proof = trim($proof);
    if ($proof === '') {
        return '';
    }
    $base = defined('BASE_PATH') ? rtrim(BASE_PATH, '/') : '/printflow';
    return $base . '/staff/api_view_proof.php?file=' . rawurlencode($proof);
}

function pf_admin_payment_badge(string $status, string $kind = 'proof'): string
{
    $normalized = strtoupper(trim($status));
    $map = [
        'SUBMITTED' => ['#fef3c7', '#92400e', 'To Verify'],
        'TO_VERIFY' => ['#fef3c7', '#92400e', 'To Verify'],
        'DOWNPAYMENT_SUBMITTED' => ['#fef3c7', '#92400e', 'To Verify'],
        'PENDING_VERIFICATION' => ['#fef3c7', '#92400e', 'To Verify'],
        'VERIFIED' => ['#dcfce7', '#166534', 'Verified'],
        'PAID' => ['#dcfce7', '#166534', 'Paid'],
        'PARTIAL' => ['#dbeafe', '#1d4ed8', 'Partial'],
        'REJECTED' => ['#fee2e2', '#991b1b', 'Rejected'],
        'UNPAID' => ['#f3f4f6', '#4b5563', 'Unpaid'],
    ];
    [$bg, $fg, $label] = $map[$normalized] ?? ['#f3f4f6', '#4b5563', ($status !== '' ? $status : 'Unknown')];

    return '<span class="pf-pay-badge" style="background:' . $bg . ';color:' . $fg . ';">' . htmlspecialchars($label) . '</span>';
}

function pf_admin_payment_status_bucket(array $row): string
{
    $proofStatus = strtoupper(trim((string)($row['proof_status'] ?? '')));
    $paymentStatus = strtoupper(trim((string)($row['payment_status'] ?? '')));
    $workflowStatus = strtoupper(str_replace([' ', '-'], '_', trim((string)($row['workflow_status'] ?? ''))));

    if ($proofStatus === 'REJECTED' || $workflowStatus === 'REJECTED') {
        return 'rejected';
    }
    if ($proofStatus === 'VERIFIED' || $paymentStatus === 'PAID') {
        return 'verified';
    }
    if (in_array($proofStatus, ['SUBMITTED', 'PENDING_VERIFICATION'], true)
        || in_array($workflowStatus, ['TO_VERIFY', 'VERIFY_PAY', 'PENDING_VERIFICATION', 'DOWNPAYMENT_SUBMITTED'], true)
    ) {
        return 'to_verify';
    }
    return 'all';
}

function pf_admin_payment_search_match(array $row, string $search): bool
{
    if ($search === '') {
        return true;
    }
    $needle = strtolower($search);
    $haystack = strtolower(implode(' ', [
        $row['payment_type'] ?? '',
        $row['record_id'] ?? '',
        $row['order_id'] ?? '',
        $row['customer_name'] ?? '',
        $row['customer_email'] ?? '',
        $row['branch_name'] ?? '',
        $row['reference'] ?? '',
        $row['service_type'] ?? '',
    ]));
    return strpos($haystack, $needle) !== false;
}

$payments = [];
$hasOrderProofPath = pf_admin_payment_has_column('orders', 'payment_proof_path');
$hasOrderProof = pf_admin_payment_has_column('orders', 'payment_proof');
$hasOrderProofStatus = pf_admin_payment_has_column('orders', 'payment_proof_status');
$hasOrderSubmittedAt = pf_admin_payment_has_column('orders', 'payment_submitted_at');
$hasOrderPaymentReference = pf_admin_payment_has_column('orders', 'payment_reference');

if ($typeFilter === 'all' || $typeFilter === 'product') {
    $proofExprParts = [];
    if ($hasOrderProofPath) {
        $proofExprParts[] = "NULLIF(o.payment_proof_path, '')";
    }
    if ($hasOrderProof) {
        $proofExprParts[] = "NULLIF(o.payment_proof, '')";
    }
    $proofExpr = !empty($proofExprParts) ? 'COALESCE(' . implode(', ', $proofExprParts) . ", '')" : "''";
    $proofStatusExpr = $hasOrderProofStatus ? "COALESCE(NULLIF(o.payment_proof_status, ''), o.status)" : "o.status";
    $submittedAtExpr = $hasOrderSubmittedAt ? "o.payment_submitted_at" : "o.updated_at";
    $referenceExpr = $hasOrderPaymentReference ? "COALESCE(NULLIF(o.payment_reference, ''), o.reference_id, '')" : "COALESCE(o.reference_id, '')";

    $sql = "SELECT
                'product' AS payment_type,
                o.order_id AS record_id,
                o.order_id,
                CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) AS customer_name,
                c.email AS customer_email,
                b.branch_name,
                o.branch_id,
                o.total_amount AS total_amount,
                COALESCE(o.downpayment_amount, o.total_amount, 0) AS submitted_amount,
                o.payment_status,
                {$proofStatusExpr} AS proof_status,
                o.status AS workflow_status,
                {$proofExpr} AS proof_path,
                {$submittedAtExpr} AS submitted_at,
                {$referenceExpr} AS reference,
                COALESCE(o.order_type, 'product') AS service_type
            FROM orders o
            LEFT JOIN customers c ON c.customer_id = o.customer_id
            LEFT JOIN branches b ON b.id = o.branch_id
            WHERE o.order_type = 'product'";
    [$bSql, $bTypes, $bParams] = branch_where_parts('o', $branchId);
    $sql .= $bSql;
    $rows = db_query($sql, $bTypes ?: null, $bParams ?: null) ?: [];
    foreach ($rows as $row) {
        if (trim((string)($row['proof_path'] ?? '')) === '') {
            continue;
        }
        $row['bucket'] = pf_admin_payment_status_bucket($row);
        $payments[] = $row;
    }
}

if ($typeFilter === 'all' || $typeFilter === 'customization') {
    $customProofExprParts = ["NULLIF(jo.payment_proof_path, '')", "NULLIF(jo.payment_proof, '')"];
    if ($hasOrderProofPath) {
        $customProofExprParts[] = "NULLIF(o.payment_proof_path, '')";
    }
    if ($hasOrderProof) {
        $customProofExprParts[] = "NULLIF(o.payment_proof, '')";
    }
    $customProofExpr = 'COALESCE(' . implode(', ', $customProofExprParts) . ", '')";

    $sql = "SELECT
                'customization' AS payment_type,
                jo.id AS record_id,
                jo.order_id,
                COALESCE(NULLIF(TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))), ''), jo.customer_name, 'Walk-in Customer') AS customer_name,
                c.email AS customer_email,
                b.branch_name,
                COALESCE(jo.branch_id, o.branch_id) AS branch_id,
                COALESCE(jo.estimated_total, o.total_amount, 0) AS total_amount,
                COALESCE(jo.payment_submitted_amount, o.downpayment_amount, jo.amount_paid, 0) AS submitted_amount,
                jo.payment_status,
                jo.payment_proof_status AS proof_status,
                jo.status AS workflow_status,
                {$customProofExpr} AS proof_path,
                COALESCE(jo.payment_proof_uploaded_at, o.updated_at, jo.updated_at, jo.created_at) AS submitted_at,
                COALESCE(o.reference_id, '') AS reference,
                COALESCE(NULLIF(jo.service_type, ''), 'Customization') AS service_type
            FROM job_orders jo
            LEFT JOIN orders o ON o.order_id = jo.order_id
            LEFT JOIN customers c ON c.customer_id = COALESCE(jo.customer_id, o.customer_id)
            LEFT JOIN branches b ON b.id = COALESCE(jo.branch_id, o.branch_id)
            WHERE 1=1" . $jobCustomizationScopeSql;
    [$bSql, $bTypes, $bParams] = branch_where_parts('jo', $branchId);
    if ($bSql !== '') {
        $sql .= str_replace('jo.branch_id', 'COALESCE(jo.branch_id, o.branch_id)', $bSql);
    }
    $rows = db_query($sql, $bTypes ?: null, $bParams ?: null) ?: [];
    foreach ($rows as $row) {
        if (trim((string)($row['proof_path'] ?? '')) === '') {
            continue;
        }
        $row['bucket'] = pf_admin_payment_status_bucket($row);
        $payments[] = $row;
    }
}

$payments = array_values(array_filter($payments, static function (array $row) use ($search): bool {
    return pf_admin_payment_search_match($row, $search);
}));

$counts = ['all' => count($payments), 'to_verify' => 0, 'verified' => 0, 'rejected' => 0];
foreach ($payments as $payment) {
    $bucket = $payment['bucket'] ?? 'all';
    if (isset($counts[$bucket])) {
        $counts[$bucket]++;
    }
}

$payments = array_values(array_filter($payments, static function (array $row) use ($statusFilter): bool {
    return $statusFilter === 'all' || (($row['bucket'] ?? '') === $statusFilter);
}));

usort($payments, static function (array $a, array $b) use ($sortBy): int {
    return match ($sortBy) {
        'oldest' => strtotime((string)($a['submitted_at'] ?? '')) <=> strtotime((string)($b['submitted_at'] ?? '')),
        'amount_high' => ((float)($b['submitted_amount'] ?? 0)) <=> ((float)($a['submitted_amount'] ?? 0)),
        'amount_low' => ((float)($a['submitted_amount'] ?? 0)) <=> ((float)($b['submitted_amount'] ?? 0)),
        default => strtotime((string)($b['submitted_at'] ?? '')) <=> strtotime((string)($a['submitted_at'] ?? '')),
    };
});

$totalRows = count($payments);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$visiblePayments = array_slice($payments, $offset, $perPage);

$buildFilterUrl = static function (array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    return '?' . http_build_query($query);
};

$activeFiltersCount = 0;
if ($search !== '') $activeFiltersCount++;
if ($typeFilter !== 'all') $activeFiltersCount++;
if ($statusFilter !== 'to_verify') $activeFiltersCount++;
if ($sortBy !== 'newest') $activeFiltersCount++;

$page_title = 'Payment - Admin | PrintFlow';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="<?php echo $base_path; ?>/public/assets/css/output.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <?php render_branch_css(); ?>
    <style>
        [x-cloak] { display:none !important; }
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
            text-decoration: none;
        }
        .sort-option:hover { background: #f9fafb; color: #111827; }
        .sort-option.selected { background: #f0fdfa; color: #0d9488; font-weight: 600; }
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
        .filter-panel-header { padding: 14px 18px; border-bottom: 1px solid #f3f4f6; font-size: 14px; font-weight: 700; color: #111827; }
        .filter-section { padding: 14px 18px; border-bottom: 1px solid #f3f4f6; }
        .filter-section:last-of-type { border-bottom: none; }
        .filter-section-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .filter-section-label { font-size: 13px; font-weight: 600; color: #374151; }
        .filter-input, .filter-select { width: 100%; height: 34px; border: 1px solid #e5e7eb; border-radius: 7px; font-size: 13px; padding: 0 10px; color: #1f2937; background: #fff; box-sizing: border-box; }
        .filter-input:focus, .filter-select:focus { outline: none; border-color: #0d9488; }
        .filter-actions { display: flex; gap: 8px; padding: 14px 18px; border-top: 1px solid #f3f4f6; }
        .filter-btn-reset { flex: 1; height: 36px; border: 1px solid #e5e7eb; background: #fff; border-radius: 8px; font-size: 13px; font-weight: 500; color: #374151; cursor: pointer; }
        .filter-btn-reset:hover { background: #f9fafb; }
        .filter-btn-apply { flex: 1; height: 36px; border: 1px solid #0d9488; background: #0d9488; border-radius: 8px; font-size: 13px; font-weight: 500; color: #fff; cursor: pointer; }
        .filter-badge { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; background: #0d9488; color: #fff; border-radius: 50%; font-size: 10px; font-weight: 700; }
        .customs-table tbody tr { transition: background 0.1s; }
        .customs-table tbody tr:hover td { background: #f9fafb; }
        .pf-pay-badge { display:inline-flex; align-items:center; border-radius:20px; padding:3px 10px; font-size:12px; font-weight:500; white-space:nowrap; }
        .proof-link { display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:7px; border:1px solid #d1d5db; color:#374151; background:#fff; text-decoration:none; }
        .row-actions { display:flex; flex-wrap:wrap; gap:8px; justify-content:flex-end; align-items:center; }
        .btn-action { display:inline-flex; align-items:center; justify-content:center; padding:6px 12px; border:1px solid transparent; background:transparent; border-radius:6px; font-size:12px; font-weight:500; transition:all 0.2s; cursor:pointer; text-decoration:none; line-height:1.2; }
        .btn-action.blue { color:#3b82f6; border-color:#3b82f6; }
        .btn-action.blue:hover { background:#3b82f6; color:white; }
        .btn-action.teal { color:#14b8a6; border-color:#14b8a6; }
        .btn-action.teal:hover { background:#14b8a6; color:white; }
        .btn-action.red { color:#ef4444; border-color:#ef4444; }
        .btn-action.red:hover { background:#ef4444; color:white; }
        .pf-btn { height:36px; display:inline-flex; align-items:center; justify-content:center; gap:7px; border-radius:8px; border:1px solid #e5e7eb; background:#fff; color:#374151; font-size:13px; font-weight:500; padding:0 12px; cursor:pointer; text-decoration:none; }
        .pf-btn.danger { border-color:#ef4444; background:#ef4444; color:#fff; }
        .pf-btn.ghost:hover { background:#f9fafb; }
        .pagination { display:flex; justify-content:flex-end; gap:8px; padding:14px 0; }
        .modal-backdrop { position:fixed; inset:0; z-index:1000; background:rgba(17,24,39,.55); display:none; align-items:center; justify-content:center; padding:16px; }
        .modal-backdrop.open { display:flex; }
        .modal-panel { width:100%; max-width:440px; background:#fff; border-radius:8px; box-shadow:0 24px 60px rgba(0,0,0,.24); overflow:hidden; }
        .modal-head { padding:16px 18px; border-bottom:1px solid #e5e7eb; }
        .modal-head h2 { margin:0; font-size:18px; font-weight:800; color:#111827; }
        .modal-body { padding:18px; }
        .modal-body textarea { width:100%; min-height:120px; border:1px solid #d1d5db; border-radius:7px; padding:10px; font-size:14px; resize:vertical; }
        .modal-foot { padding:14px 18px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:10px; }
        @media (max-width: 980px) {
            .kpi-row { grid-template-columns:repeat(2,1fr); }
        }
        @media (max-width: 640px) {
            .kpi-row { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . (($current_user['role'] ?? '') === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>

    <div class="main-content">
        <header class="pf-mobile-branch-inline">
                <h1 class="page-title">Payment</h1>
                <?php if (!defined('MANAGER_PANEL') || !MANAGER_PANEL) { render_branch_selector($branchCtx); } ?>
            </header>

            <main x-data="{ sortOpen:false, filterOpen:false }">
                <?php render_branch_context_banner($branchCtx['branch_name']); ?>

                <div class="kpi-row">
                <div class="kpi-card indigo"><div class="kpi-label">Visible Proofs</div><div class="kpi-value"><?php echo number_format((int)$counts['all']); ?></div><div class="kpi-sub">Across visible branches</div></div>
                <div class="kpi-card amber"><div class="kpi-label">To Verify</div><div class="kpi-value"><?php echo number_format((int)$counts['to_verify']); ?></div><div class="kpi-sub">Needs review</div></div>
                <div class="kpi-card blue"><div class="kpi-label">Verified</div><div class="kpi-value"><?php echo number_format((int)$counts['verified']); ?></div><div class="kpi-sub">Approved proofs</div></div>
                <div class="kpi-card emerald"><div class="kpi-label">Rejected</div><div class="kpi-value"><?php echo number_format((int)$counts['rejected']); ?></div><div class="kpi-sub">Returned to customer</div></div>
            </div>

                <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
                    <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;">Payment List</h3>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <div style="position:relative;">
                            <button type="button" class="toolbar-btn <?php echo $sortBy !== 'newest' ? 'active' : ''; ?>" @click="sortOpen = !sortOpen; filterOpen = false" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="9" y1="18" x2="15" y2="18"/>
                                </svg>
                                Sort by
                            </button>
                            <div class="sort-dropdown" x-show="sortOpen" x-cloak @click.outside="sortOpen = false">
                                <?php foreach (['newest' => 'Newest to Oldest', 'oldest' => 'Oldest to Newest', 'amount_high' => 'Amount High to Low', 'amount_low' => 'Amount Low to High'] as $sortKey => $sortLabel): ?>
                                    <a class="sort-option <?php echo $sortBy === $sortKey ? 'selected' : ''; ?>" href="<?php echo htmlspecialchars($buildFilterUrl(['sort' => $sortKey, 'page' => 1])); ?>">
                                        <?php echo htmlspecialchars($sortLabel); ?>
                                        <?php if ($sortBy === $sortKey): ?>
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                        <?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div style="position:relative;">
                            <button type="button" class="toolbar-btn <?php echo $activeFiltersCount > 0 ? 'active' : ''; ?>" @click="filterOpen = !filterOpen; sortOpen = false" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
                                </svg>
                                Filter
                                <?php if ($activeFiltersCount > 0): ?><span class="filter-badge"><?php echo (int)$activeFiltersCount; ?></span><?php endif; ?>
                            </button>
                            <form class="filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false" method="get">
                                <div class="filter-panel-header">Filter</div>
                                <div class="filter-section">
                                    <div class="filter-section-head"><span class="filter-section-label">Keyword search</span></div>
                                    <input type="text" name="search" class="filter-input" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                <div class="filter-section">
                                    <div class="filter-section-head"><span class="filter-section-label">Payment type</span></div>
                                    <select name="type" class="filter-select">
                                        <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>All payment types</option>
                                        <option value="product" <?php echo $typeFilter === 'product' ? 'selected' : ''; ?>>Product orders</option>
                                        <option value="customization" <?php echo $typeFilter === 'customization' ? 'selected' : ''; ?>>Customizations</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <div class="filter-section-head"><span class="filter-section-label">Status</span></div>
                                    <select name="status" class="filter-select">
                                        <option value="to_verify" <?php echo $statusFilter === 'to_verify' ? 'selected' : ''; ?>>To Verify</option>
                                        <option value="verified" <?php echo $statusFilter === 'verified' ? 'selected' : ''; ?>>Verified</option>
                                        <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All statuses</option>
                                    </select>
                                </div>
                                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortBy); ?>">
                                <input type="hidden" name="branch_id" value="<?php echo printflow_branch_value_is_all($branchId) ? 'all' : (int)$branchId; ?>">
                                <div class="filter-actions">
                                    <a class="filter-btn-reset" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;" href="<?php echo htmlspecialchars($buildFilterUrl(['search' => '', 'type' => 'all', 'status' => 'to_verify', 'sort' => 'newest', 'page' => 1])); ?>">Reset</a>
                                    <button class="filter-btn-apply" type="submit">Apply</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto" id="customsTableContainer">
                    <table class="w-full text-sm customs-table">
                        <thead>
                            <tr style="border-bottom: 1px solid #e5e7eb;">
                                <th class="text-left py-3">Payment</th>
                                <th class="text-left py-3">Customer</th>
                                <th class="text-left py-3">Branch</th>
                                <th class="text-right py-3">Amount</th>
                                <th class="text-center py-3">Payment</th>
                                <th class="text-center py-3">Uploaded</th>
                                <th class="text-center py-3">Proof</th>
                                <th class="text-right py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($visiblePayments)): ?>
                                <tr><td colspan="8" class="py-12 text-center text-gray-400" style="border-bottom: 1px solid #f3f4f6;">No payment proofs found</td></tr>
                            <?php endif; ?>
                            <?php foreach ($visiblePayments as $payment):
                                $isCustomization = ($payment['payment_type'] ?? '') === 'customization';
                                $recordId = (int)($payment['record_id'] ?? 0);
                                $orderId = (int)($payment['order_id'] ?? 0);
                                $proofPath = (string)($payment['proof_path'] ?? '');
                                $proofUrl = pf_admin_payment_proof_url($proofPath);
                                $bucket = (string)($payment['bucket'] ?? 'all');
                                $openUrl = $isCustomization
                                    ? $base_path . '/admin/customizations.php?open_job=' . $recordId
                                    : $base_path . '/admin/orders_management.php?search=' . urlencode((string)$orderId);
                            ?>
                                <tr style="border-bottom: 1px solid #f3f4f6;">
                                    <td class="py-3 text-gray-900">
                                        <div style="font-weight:600;"><?php echo $isCustomization ? 'Customization #' : 'Order #'; ?><?php echo $recordId; ?></div>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars((string)($payment['service_type'] ?? '')); ?><?php echo $orderId > 0 && $orderId !== $recordId ? ' / Order #' . $orderId : ''; ?></div>
                                    </td>
                                    <td class="py-3">
                                        <div class="text-gray-900" style="max-width:170px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars(trim((string)($payment['customer_name'] ?? '')) ?: 'Customer'); ?>"><?php echo htmlspecialchars(trim((string)($payment['customer_name'] ?? '')) ?: 'Customer'); ?></div>
                                        <div class="text-xs text-gray-400" style="max-width:170px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars((string)($payment['customer_email'] ?? '')); ?>"><?php echo htmlspecialchars((string)($payment['customer_email'] ?? '')); ?></div>
                                    </td>
                                    <td class="py-3"><?php echo htmlspecialchars((string)($payment['branch_name'] ?? 'Unassigned')); ?></td>
                                    <td class="py-3 text-right">
                                        <?php echo format_currency((float)($payment['submitted_amount'] ?? 0)); ?>
                                        <div class="text-xs text-gray-400">Total <?php echo format_currency((float)($payment['total_amount'] ?? 0)); ?></div>
                                    </td>
                                    <td class="py-3 text-center">
                                        <?php echo pf_admin_payment_badge((string)($payment['proof_status'] ?? ''), 'proof'); ?>
                                    </td>
                                    <td class="py-3 text-center text-gray-500 text-xs"><?php echo !empty($payment['submitted_at']) ? htmlspecialchars(date('M j, Y', strtotime((string)$payment['submitted_at']))) : 'No date'; ?></td>
                                    <td class="py-3 text-center">
                                        <?php if ($proofUrl !== ''): ?>
                                            <a class="proof-link" href="<?php echo htmlspecialchars($proofUrl); ?>" target="_blank" rel="noopener" title="View proof">
                                                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-gray-400 text-xs">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 text-right">
                                        <div class="row-actions">
                                            <a class="btn-action blue" href="<?php echo htmlspecialchars($openUrl); ?>">View</a>
                                            <?php if ($bucket === 'to_verify'): ?>
                                                <button class="btn-action teal" type="button" onclick="verifyPayment('<?php echo $isCustomization ? 'customization' : 'product'; ?>', <?php echo $recordId; ?>, <?php echo $orderId; ?>)">Approve</button>
                                                <button class="btn-action red" type="button" onclick="openRejectModal('<?php echo $isCustomization ? 'customization' : 'product'; ?>', <?php echo $recordId; ?>, <?php echo $orderId; ?>)">Reject</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a class="pf-btn ghost" href="<?php echo htmlspecialchars($buildFilterUrl(['page' => $page - 1])); ?>">Previous</a>
                    <?php endif; ?>
                    <span class="pf-btn ghost">Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a class="pf-btn ghost" href="<?php echo htmlspecialchars($buildFilterUrl(['page' => $page + 1])); ?>">Next</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </main>
    </div>
</div>

<div class="modal-backdrop" id="rejectModal">
    <div class="modal-panel">
        <div class="modal-head">
            <h2>Reject Payment Proof</h2>
        </div>
        <div class="modal-body">
            <textarea id="rejectReason" placeholder="Reason for rejection"></textarea>
        </div>
        <div class="modal-foot">
            <button class="pf-btn ghost" type="button" onclick="closeRejectModal()">Cancel</button>
            <button class="pf-btn danger" type="button" onclick="submitRejectPayment()">Reject</button>
        </div>
    </div>
</div>

<script>
let pendingReject = null;

async function postPaymentAction(type, id, orderId, action, reason) {
    const fd = new FormData();
    if (type === 'customization') {
        fd.append('id', id);
        fd.append('action', action === 'approve' ? 'verify_payment' : 'reject_payment');
        if (orderId) fd.append('order_id', orderId);
        if (reason) fd.append('reason', reason);
        return fetch('<?php echo $base_path; ?>/admin/api_verify_job_payment.php', { method: 'POST', body: fd });
    }

    fd.append('order_id', orderId || id);
    fd.append('action', action === 'approve' ? 'Approve' : 'Reject');
    if (reason) fd.append('reason', reason);
    return fetch('<?php echo $base_path; ?>/staff/api_verify_payment.php', { method: 'POST', body: fd });
}

async function parsePaymentResponse(response) {
    const text = await response.text();
    try {
        return JSON.parse(text);
    } catch (error) {
        return { success: false, error: text || 'Unexpected server response.' };
    }
}

async function verifyPayment(type, id, orderId) {
    if (!confirm('Approve this payment proof?')) return;
    const response = await postPaymentAction(type, id, orderId, 'approve', '');
    const data = await parsePaymentResponse(response);
    if (data.success) {
        window.location.reload();
        return;
    }
    alert(data.error || data.message || 'Payment approval failed.');
}

function openRejectModal(type, id, orderId) {
    pendingReject = { type, id, orderId };
    document.getElementById('rejectReason').value = '';
    document.getElementById('rejectModal').classList.add('open');
    setTimeout(() => document.getElementById('rejectReason').focus(), 50);
}

function closeRejectModal() {
    pendingReject = null;
    document.getElementById('rejectModal').classList.remove('open');
}

async function submitRejectPayment() {
    if (!pendingReject) return;
    const reason = document.getElementById('rejectReason').value.trim();
    if (!reason) {
        alert('Please enter a rejection reason.');
        return;
    }
    const response = await postPaymentAction(pendingReject.type, pendingReject.id, pendingReject.orderId, 'reject', reason);
    const data = await parsePaymentResponse(response);
    if (data.success) {
        window.location.reload();
        return;
    }
    alert(data.error || data.message || 'Payment rejection failed.');
}
</script>
</body>
</html>
