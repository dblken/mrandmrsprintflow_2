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

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'to_verify')));
$typeFilter = strtolower(trim((string)($_GET['type'] ?? 'all')));
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
            WHERE 1=1";
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

usort($payments, static function (array $a, array $b): int {
    return strtotime((string)($b['submitted_at'] ?? '')) <=> strtotime((string)($a['submitted_at'] ?? ''));
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
        .payment-page { padding: 0; }
        .pf-mobile-branch-inline { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:24px; }
        .page-title { margin:0; font-size:28px; font-weight:800; color:#111827; letter-spacing:0; }
        .kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px; }
        .kpi-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; position:relative; overflow:hidden; }
        .kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
        .kpi-card.indigo::before { background:linear-gradient(90deg,#6366f1,#818cf8); }
        .kpi-card.amber::before { background:linear-gradient(90deg,#f59e0b,#fbbf24); }
        .kpi-card.blue::before { background:linear-gradient(90deg,#3b82f6,#60a5fa); }
        .kpi-card.emerald::before { background:linear-gradient(90deg,#059669,#34d399); }
        .kpi-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#9ca3af; margin-bottom:6px; }
        .kpi-value { font-size:28px; font-weight:800; color:#111827; line-height:1.1; }
        .kpi-sub { font-size:12px; color:#6b7280; margin-top:4px; }
        .payment-filters { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:14px; display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:16px; }
        .payment-filters input, .payment-filters select { height:38px; border:1px solid #d1d5db; border-radius:7px; padding:0 10px; font-size:13px; color:#111827; background:#fff; }
        .payment-filters input { min-width:260px; }
        .pf-btn { height:38px; display:inline-flex; align-items:center; justify-content:center; gap:7px; border-radius:7px; border:1px solid #d1d5db; background:#fff; color:#374151; font-size:13px; font-weight:700; padding:0 12px; cursor:pointer; text-decoration:none; }
        .pf-btn.primary { border-color:#0d9488; background:#0d9488; color:#fff; }
        .pf-btn.danger { border-color:#dc2626; background:#dc2626; color:#fff; }
        .pf-btn.ghost:hover { background:#f9fafb; }
        .payment-tabs { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
        .payment-tab { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:7px; border:1px solid #e5e7eb; color:#4b5563; background:#fff; font-size:13px; font-weight:700; text-decoration:none; }
        .payment-tab.active { border-color:#0d9488; color:#0f766e; background:#f0fdfa; }
        .payment-tab small { min-width:22px; padding:1px 6px; border-radius:999px; background:#f3f4f6; color:#4b5563; text-align:center; }
        .payment-table-wrap { background:#fff; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; }
        .payment-table { width:100%; border-collapse:collapse; }
        .payment-table th { background:#f9fafb; color:#6b7280; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; text-align:left; padding:12px; border-bottom:1px solid #e5e7eb; white-space:nowrap; }
        .payment-table td { padding:13px 12px; border-bottom:1px solid #f3f4f6; vertical-align:middle; font-size:13px; color:#374151; }
        .payment-table tr:last-child td { border-bottom:0; }
        .payment-table .main-text { font-weight:800; color:#111827; }
        .payment-table .muted { color:#6b7280; font-size:12px; margin-top:2px; }
        .pf-pay-badge { display:inline-flex; align-items:center; border-radius:999px; padding:3px 9px; font-size:11px; font-weight:800; white-space:nowrap; }
        .proof-link { display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:7px; border:1px solid #d1d5db; color:#374151; background:#fff; text-decoration:none; }
        .row-actions { display:flex; flex-wrap:wrap; gap:7px; justify-content:flex-end; }
        .empty-state { padding:42px 16px; text-align:center; color:#6b7280; }
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
            .payment-table-wrap { overflow-x:auto; }
            .payment-table { min-width:980px; }
        }
        @media (max-width: 640px) {
            .payment-page { padding:16px; }
            .pf-mobile-branch-inline { display:block; }
            .kpi-row { grid-template-columns:1fr; }
            .payment-filters input, .payment-filters select, .pf-btn { width:100%; }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . (($current_user['role'] ?? '') === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>

    <div class="main-content">
        <div class="payment-page">
            <header class="pf-mobile-branch-inline">
                <h1 class="page-title">Payment</h1>
                <?php render_branch_selector($branchCtx); ?>
            </header>

            <?php render_branch_context_banner($branchCtx['branch_name']); ?>

            <div class="kpi-row">
                <div class="kpi-card indigo"><div class="kpi-label">Visible Proofs</div><div class="kpi-value"><?php echo number_format((int)$counts['all']); ?></div><div class="kpi-sub">Across visible branches</div></div>
                <div class="kpi-card amber"><div class="kpi-label">To Verify</div><div class="kpi-value"><?php echo number_format((int)$counts['to_verify']); ?></div><div class="kpi-sub">Needs review</div></div>
                <div class="kpi-card blue"><div class="kpi-label">Verified</div><div class="kpi-value"><?php echo number_format((int)$counts['verified']); ?></div><div class="kpi-sub">Approved proofs</div></div>
                <div class="kpi-card emerald"><div class="kpi-label">Rejected</div><div class="kpi-value"><?php echo number_format((int)$counts['rejected']); ?></div><div class="kpi-sub">Returned to customer</div></div>
            </div>

            <form class="payment-filters" method="get">
                <input type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search customer, order, branch, reference">
                <select name="type">
                    <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>All payment types</option>
                    <option value="product" <?php echo $typeFilter === 'product' ? 'selected' : ''; ?>>Product orders</option>
                    <option value="customization" <?php echo $typeFilter === 'customization' ? 'selected' : ''; ?>>Customizations</option>
                </select>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                <input type="hidden" name="branch_id" value="<?php echo printflow_branch_value_is_all($branchId) ? 'all' : (int)$branchId; ?>">
                <button class="pf-btn primary" type="submit">Apply</button>
                <a class="pf-btn ghost" href="<?php echo htmlspecialchars($buildFilterUrl(['search' => '', 'type' => 'all', 'status' => 'to_verify', 'page' => 1])); ?>">Reset</a>
            </form>

            <div class="payment-tabs">
                <a class="payment-tab <?php echo $statusFilter === 'to_verify' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($buildFilterUrl(['status' => 'to_verify', 'page' => 1])); ?>">To Verify <small><?php echo (int)$counts['to_verify']; ?></small></a>
                <a class="payment-tab <?php echo $statusFilter === 'verified' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($buildFilterUrl(['status' => 'verified', 'page' => 1])); ?>">Verified <small><?php echo (int)$counts['verified']; ?></small></a>
                <a class="payment-tab <?php echo $statusFilter === 'rejected' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($buildFilterUrl(['status' => 'rejected', 'page' => 1])); ?>">Rejected <small><?php echo (int)$counts['rejected']; ?></small></a>
                <a class="payment-tab <?php echo $statusFilter === 'all' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($buildFilterUrl(['status' => 'all', 'page' => 1])); ?>">All <small><?php echo (int)$counts['all']; ?></small></a>
            </div>

            <div class="payment-table-wrap">
                <table class="payment-table">
                    <thead>
                        <tr>
                            <th>Payment</th>
                            <th>Customer</th>
                            <th>Branch</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Uploaded</th>
                            <th>Proof</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($visiblePayments)): ?>
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state">No payment proofs match this view.</div>
                                </td>
                            </tr>
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
                            <tr>
                                <td>
                                    <div class="main-text"><?php echo $isCustomization ? 'Customization #' : 'Order #'; ?><?php echo $recordId; ?></div>
                                    <div class="muted"><?php echo htmlspecialchars((string)($payment['service_type'] ?? '')); ?><?php echo $orderId > 0 && $orderId !== $recordId ? ' / Order #' . $orderId : ''; ?></div>
                                </td>
                                <td>
                                    <div class="main-text"><?php echo htmlspecialchars(trim((string)($payment['customer_name'] ?? '')) ?: 'Customer'); ?></div>
                                    <div class="muted"><?php echo htmlspecialchars((string)($payment['customer_email'] ?? '')); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars((string)($payment['branch_name'] ?? 'Unassigned')); ?></td>
                                <td>
                                    <div class="main-text"><?php echo format_currency((float)($payment['submitted_amount'] ?? 0)); ?></div>
                                    <div class="muted">Total <?php echo format_currency((float)($payment['total_amount'] ?? 0)); ?></div>
                                </td>
                                <td>
                                    <?php echo pf_admin_payment_badge((string)($payment['proof_status'] ?? ''), 'proof'); ?>
                                    <div class="muted"><?php echo htmlspecialchars((string)($payment['payment_status'] ?? '')); ?></div>
                                </td>
                                <td><?php echo !empty($payment['submitted_at']) ? htmlspecialchars(format_datetime((string)$payment['submitted_at'])) : '<span class="muted">No date</span>'; ?></td>
                                <td>
                                    <?php if ($proofUrl !== ''): ?>
                                        <a class="proof-link" href="<?php echo htmlspecialchars($proofUrl); ?>" target="_blank" rel="noopener" title="View proof">
                                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                        </a>
                                    <?php else: ?>
                                        <span class="muted">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <a class="pf-btn ghost" href="<?php echo htmlspecialchars($openUrl); ?>">Open</a>
                                        <?php if ($bucket === 'to_verify'): ?>
                                            <button class="pf-btn primary" type="button" onclick="verifyPayment('<?php echo $isCustomization ? 'customization' : 'product'; ?>', <?php echo $recordId; ?>, <?php echo $orderId; ?>)">Approve</button>
                                            <button class="pf-btn danger" type="button" onclick="openRejectModal('<?php echo $isCustomization ? 'customization' : 'product'; ?>', <?php echo $recordId; ?>, <?php echo $orderId; ?>)">Reject</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
        </div>
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
