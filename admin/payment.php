<?php
/**
 * Admin Payment Queue
 * Global payment verification view across branches.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/branch_ui.php';
require_once __DIR__ . '/../includes/payment_verification.php';

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
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$typeFilter = strtolower(trim((string)($_GET['type'] ?? 'all')));
$sortBy = strtolower(trim((string)($_GET['sort'] ?? 'newest')));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$allowedStatusFilters = ['to_verify', 'verified', 'rejected', 'all'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = 'all';
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
    $normalized = strtoupper(str_replace([' ', '-'], '_', trim($status)));
    $map = [
        'SUBMITTED' => ['#fef9c3', '#854d0e', 'To Verify'],
        'TO_VERIFY' => ['#fef9c3', '#854d0e', 'To Verify'],
        'VERIFY_PAY' => ['#fef9c3', '#854d0e', 'To Verify'],
        'DOWNPAYMENT_SUBMITTED' => ['#fef9c3', '#854d0e', 'To Verify'],
        'PENDING_VERIFICATION' => ['#fef9c3', '#854d0e', 'To Verify'],
        'PENDING_REVIEW' => ['#fef9c3', '#854d0e', 'To Verify'],
        'NEEDS_REVIEW' => ['#fef3c7', '#92400e', 'Needs Review'],
        'DUPLICATE_SUSPECTED' => ['#ffedd5', '#9a3412', 'Duplicate Suspected'],
        'MATCHED' => ['#dcfce7', '#166534', 'Matched'],
        'APPROVED' => ['#dcfce7', '#166534', 'Approved'],
        'VERIFIED' => ['#dcfce7', '#166534', 'Verified'],
        'PAID' => ['#dcfce7', '#166534', 'Paid'],
        'PARTIAL' => ['#fef3c7', '#b45309', 'Partial'],
        'REJECTED' => ['#fee2e2', '#991b1b', 'Rejected'],
        'UNPAID' => ['#fee2e2', '#991b1b', 'Unpaid'],
    ];
    [$bg, $fg, $label] = $map[$normalized] ?? ['#f3f4f6', '#4b5563', ($status !== '' ? $status : 'Unknown')];

    return '<span class="pf-pay-badge" style="background:' . $bg . ';color:' . $fg . ';">' . htmlspecialchars($label) . '</span>';
}

function pf_admin_payment_status_bucket(array $row): string
{
    $proofStatus = strtoupper(trim((string)($row['proof_status'] ?? '')));
    $paymentStatus = strtoupper(trim((string)($row['payment_status'] ?? '')));
    $workflowStatus = strtoupper(str_replace([' ', '-'], '_', trim((string)($row['workflow_status'] ?? ''))));

    if (in_array($proofStatus, ['REJECTED'], true) || $workflowStatus === 'REJECTED') {
        return 'rejected';
    }
    if (in_array($proofStatus, ['VERIFIED', 'APPROVED', 'MATCHED'], true) || $paymentStatus === 'PAID') {
        return 'verified';
    }
    if (in_array($proofStatus, ['SUBMITTED', 'PENDING_VERIFICATION', 'PENDING_REVIEW', 'NEEDS_REVIEW', 'DUPLICATE_SUSPECTED'], true)
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
$schemaReady = payment_verification_ensure_schema();
if ($schemaReady) {
    // Keep admin Payment in sync with staff/payment_verification.php, including legacy proof imports.
    payment_verification_import_legacy_submissions(100);
    payment_verification_repair_duplicate_states(100);

    $branchExpression = "COALESCE(NULLIF(ps.branch_id, 0), NULLIF(o.branch_id, 0), NULLIF(jo.branch_id, 0), 0)";
    $orderSkuExpression = "(SELECT GROUP_CONCAT(DISTINCT sku_product.sku ORDER BY sku_product.sku SEPARATOR '-')
                            FROM order_items sku_item
                            LEFT JOIN products sku_product ON sku_product.product_id = sku_item.product_id
                            WHERE sku_item.order_id = ps.order_id)";
    $sql = "SELECT ps.*,
                   {$orderSkuExpression} AS order_sku,
                   CONCAT_WS(' ', c.first_name, c.last_name) AS customer_name,
                   c.email AS customer_email,
                   b.branch_name,
                   {$branchExpression} AS resolved_branch_id,
                   COALESCE(o.order_type, CASE WHEN ps.job_order_id IS NOT NULL AND ps.job_order_id > 0 THEN 'customization' ELSE 'product' END) AS source_order_type,
                   COALESCE(NULLIF(jo.service_type, ''), NULLIF(jo.job_title, ''), COALESCE(o.order_type, 'Payment')) AS service_type,
                   COALESCE(o.reference_id, ps.reference_number, ps.ocr_reference_number, '') AS reference
            FROM payment_submissions ps
            LEFT JOIN orders o ON o.order_id = ps.order_id
            LEFT JOIN job_orders jo ON jo.id = ps.job_order_id
            LEFT JOIN customers c ON c.customer_id = ps.customer_id
            LEFT JOIN branches b ON b.id = {$branchExpression}
            WHERE 1=1";
    $types = '';
    $params = [];

    if (printflow_branch_value_is_all($branchId)) {
        $sql .= " AND {$branchExpression} IN (SELECT id FROM branches WHERE status != 'Archived')";
    } else {
        $sql .= " AND {$branchExpression} = ?";
        $types .= 'i';
        $params[] = (int)$branchId;
    }

    if ($typeFilter === 'product') {
        $sql .= " AND (ps.job_order_id IS NULL OR ps.job_order_id = 0)";
    } elseif ($typeFilter === 'customization') {
        $sql .= " AND ps.job_order_id IS NOT NULL AND ps.job_order_id > 0";
    }

    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= " AND (
            CONCAT_WS(' ', c.first_name, c.last_name) LIKE ?
            OR CAST(ps.order_id AS CHAR) LIKE ?
            OR CAST(ps.job_order_id AS CHAR) LIKE ?
            OR CAST(ps.id AS CHAR) LIKE ?
            OR COALESCE({$orderSkuExpression}, '') LIKE ?
            OR COALESCE(NULLIF(ps.reference_number, ''), ps.ocr_reference_number, '') LIKE ?
            OR COALESCE(NULLIF(ps.sender_name, ''), ps.ocr_sender_name, '') LIKE ?
        )";
        $types .= 'sssssss';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }

    $sql .= " ORDER BY
                CASE ps.verification_status
                    WHEN 'Duplicate Suspected' THEN 1
                    WHEN 'Needs Review' THEN 2
                    WHEN 'Pending Review' THEN 3
                    WHEN 'Matched' THEN 4
                    ELSE 5
                END,
                ps.created_at DESC,
                ps.id DESC";

    $rows = db_query($sql, $types ?: null, $params ?: null) ?: [];
    foreach ($rows as $row) {
        $isCustomization = (int)($row['job_order_id'] ?? 0) > 0;
        $receiptFile = (string)($row['receipt_file'] ?? '');
        if (trim($receiptFile) === '') {
            continue;
        }
        $row['payment_type'] = $isCustomization ? 'customization' : 'product';
        $row['record_id'] = $isCustomization ? (int)($row['job_order_id'] ?? 0) : (int)($row['order_id'] ?? 0);
        if ((int)$row['record_id'] <= 0) {
            $row['record_id'] = (int)($row['id'] ?? 0);
        }
        $row['order_id'] = (int)($row['order_id'] ?? 0);
        $row['branch_id'] = (int)($row['resolved_branch_id'] ?? 0);
        $row['total_amount'] = (float)($row['expected_amount'] ?? 0);
        $row['submitted_amount'] = (float)($row['expected_amount'] ?? $row['submitted_amount'] ?? 0);
        $effectiveAmount = payment_verification_effective_value($row, 'amount_sent', 'ocr_amount_sent');
        if ($effectiveAmount !== null && $effectiveAmount !== '') {
            $row['submitted_amount'] = (float)$effectiveAmount;
        }
        $row['payment_status'] = (string)($row['verification_status'] ?? 'Pending Review');
        $row['proof_status'] = (string)($row['verification_status'] ?? 'Pending Review');
        $row['workflow_status'] = (string)($row['verification_status'] ?? 'Pending Review');
        $row['proof_path'] = $receiptFile;
        $row['proof_url'] = payment_verification_proof_url($receiptFile);
        $previewPath = (string)(($row['receipt_thumbnail'] ?? '') ?: $receiptFile);
        $row['proof_preview_url'] = payment_verification_proof_url($previewPath);
        $row['submitted_at'] = (string)($row['created_at'] ?? '');
        $row['service_type'] = trim((string)($row['service_type'] ?? '')) ?: ($isCustomization ? 'Customization' : 'Product');
        $row['customer_name'] = trim((string)($row['customer_name'] ?? '')) ?: 'Customer';
        $row['order_label'] = payment_verification_order_label($row);
        $row['bucket'] = pf_admin_payment_status_bucket($row);
        $payments[] = $row;
    }
}

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
        default => 0,
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
if ($statusFilter !== 'all') $activeFiltersCount++;
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
        .kpi-row { display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px; }
        @media(max-width:768px){ .kpi-row{grid-template-columns:repeat(2,1fr);} }
        .kpi-card { background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;position:relative;overflow:hidden; }
        .kpi-card::before { content:'';position:absolute;top:0;left:0;right:0;height:3px; }
        .kpi-card.indigo::before { background:linear-gradient(90deg,#6366f1,#818cf8); }
        .kpi-card.amber::before { background:linear-gradient(90deg,#f59e0b,#fbbf24); }
        .kpi-card.blue::before { background:linear-gradient(90deg,#3b82f6,#60a5fa); }
        .kpi-card.emerald::before { background:linear-gradient(90deg,#059669,#34d399); }
        .kpi-label { font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;margin-bottom:6px; }
        .kpi-sub { font-size:12px;color:#6b7280;margin-top:4px; }
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
        .filter-reset-link { font-size:12px;font-weight:600;color:#0d9488;cursor:pointer;background:none;border:none;padding:0; }
        .filter-reset-link:hover { text-decoration:underline; }
        .filter-input, .filter-select { width: 100%; height: 34px; border: 1px solid #e5e7eb; border-radius: 7px; font-size: 13px; padding: 0 10px; color: #1f2937; background: #fff; box-sizing: border-box; }
        .filter-input:focus, .filter-select:focus { outline: none; border-color: #0d9488; }
        .filter-actions { display: flex; gap: 8px; padding: 14px 18px; border-top: 1px solid #f3f4f6; }
        .filter-btn-reset { flex: 1; height: 36px; border: 1px solid #e5e7eb; background: #fff; border-radius: 8px; font-size: 13px; font-weight: 500; color: #374151; cursor: pointer; }
        .filter-btn-reset:hover { background: #f9fafb; }
        .filter-badge { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; background: #0d9488; color: #fff; border-radius: 50%; font-size: 10px; font-weight: 700; }
        .customs-table tbody tr { cursor:pointer; transition: background 0.1s; }
        .payment-order-text { font-weight:400;white-space:nowrap;color:#111827; }
        .customs-table tbody tr:hover td { background: #f9fafb; }
        .pf-pay-badge { display:inline-flex; align-items:center; border-radius:20px; padding:3px 10px; font-size:12px; font-weight:500; white-space:nowrap; }
        .proof-thumb { width:38px;height:38px;border-radius:50%;border:1px solid #e5e7eb;padding:0;background:#fff;overflow:hidden;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;vertical-align:middle; }
        .proof-thumb img { width:100%;height:100%;object-fit:cover;display:block; }
        .proof-thumb:hover { border-color:#9ca3af; box-shadow:0 2px 8px rgba(15,23,42,.08); }
        .row-actions { display:flex; flex-wrap:wrap; gap:8px; justify-content:center; align-items:center; }
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
        .proof-modal-panel { width:min(92vw,720px);max-height:88vh;background:#fff;border-radius:12px;box-shadow:0 24px 60px rgba(0,0,0,.24);overflow:hidden; }
        .proof-modal-image-wrap { background:#f9fafb;display:flex;align-items:center;justify-content:center;padding:16px;max-height:72vh; }
        .proof-modal-image-wrap img { max-width:100%;max-height:68vh;object-fit:contain;border-radius:8px; }
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
                    <div class="kpi-card indigo">
                        <div class="kpi-label">Visible Proofs</div>
                        <div class="kpi-value"><?php echo number_format((int)$counts['all']); ?></div>
                        <div class="kpi-sub">Across visible branches</div>
                    </div>
                    <div class="kpi-card amber">
                        <div class="kpi-label">To Verify</div>
                        <div class="kpi-value"><?php echo number_format((int)$counts['to_verify']); ?></div>
                        <div class="kpi-sub">Needs review</div>
                    </div>
                    <div class="kpi-card blue">
                        <div class="kpi-label">Verified</div>
                        <div class="kpi-value"><?php echo number_format((int)$counts['verified']); ?></div>
                        <div class="kpi-sub">Approved proofs</div>
                    </div>
                    <div class="kpi-card emerald">
                        <div class="kpi-label">Rejected</div>
                        <div class="kpi-value"><?php echo number_format((int)$counts['rejected']); ?></div>
                        <div class="kpi-sub">Returned to customer</div>
                    </div>
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
                            <form class="filter-panel" id="paymentFilterForm" x-show="filterOpen" x-cloak @click.outside="filterOpen = false" method="get" onsubmit="return false;">
                                <div class="filter-panel-header">Filter</div>
                                <div class="filter-section">
                                    <div class="filter-section-head"><span class="filter-section-label">Keyword search</span><button type="button" class="filter-reset-link" onclick="resetPaymentFilter(['search'])">Reset</button></div>
                                    <input type="text" name="search" class="filter-input" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>" oninput="paymentFilterChanged()">
                                </div>
                                <div class="filter-section">
                                    <div class="filter-section-head"><span class="filter-section-label">Payment type</span><button type="button" class="filter-reset-link" onclick="resetPaymentFilter(['type'])">Reset</button></div>
                                    <select name="type" class="filter-select" onchange="submitPaymentFilters()">
                                        <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>All payment types</option>
                                        <option value="product" <?php echo $typeFilter === 'product' ? 'selected' : ''; ?>>Product orders</option>
                                        <option value="customization" <?php echo $typeFilter === 'customization' ? 'selected' : ''; ?>>Customizations</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <div class="filter-section-head"><span class="filter-section-label">Status</span><button type="button" class="filter-reset-link" onclick="resetPaymentFilter(['status'])">Reset</button></div>
                                    <select name="status" class="filter-select" onchange="submitPaymentFilters()">
                                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All statuses</option>
                                        <option value="to_verify" <?php echo $statusFilter === 'to_verify' ? 'selected' : ''; ?>>To Verify</option>
                                        <option value="verified" <?php echo $statusFilter === 'verified' ? 'selected' : ''; ?>>Verified</option>
                                        <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    </select>
                                </div>
                                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortBy); ?>">
                                <input type="hidden" name="branch_id" value="<?php echo printflow_branch_value_is_all($branchId) ? 'all' : (int)$branchId; ?>">
                                <div class="filter-actions">
                                    <a class="filter-btn-reset" style="width:100%;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;" href="<?php echo htmlspecialchars($buildFilterUrl(['search' => '', 'type' => 'all', 'status' => 'all', 'sort' => 'newest', 'page' => 1])); ?>">Reset all filters</a>
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
                                <th class="text-center py-3">Actions</th>
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
                                $proofUrl = (string)($payment['proof_url'] ?? '');
                                $proofPreviewUrl = (string)($payment['proof_preview_url'] ?? $proofUrl);
                                $bucket = (string)($payment['bucket'] ?? 'all');
                                $openUrl = $isCustomization
                                    ? $base_path . '/admin/customizations.php?open_job=' . $recordId
                                    : $base_path . '/admin/orders_management.php?search=' . urlencode((string)$orderId);
                            ?>
                                <tr style="border-bottom: 1px solid #f3f4f6;" <?php if ($proofUrl !== ''): ?>onclick="openProofModal('<?php echo htmlspecialchars($proofUrl, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars((string)($payment['order_label'] ?? (($isCustomization ? 'Customization #' : 'Order #') . $recordId)), ENT_QUOTES, 'UTF-8'); ?>')"<?php endif; ?>>
                                    <td class="py-3 text-gray-900">
                                        <span class="payment-order-text"><?php echo htmlspecialchars((string)($payment['order_label'] ?? (($isCustomization ? 'Customization #' : 'Order #') . $recordId))); ?></span>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars((string)($payment['service_type'] ?? '')); ?><?php echo $orderId > 0 && $orderId !== $recordId ? ' / Order #' . $orderId : ''; ?></div>
                                    </td>
                                    <td class="py-3">
                                        <div class="text-gray-900" style="max-width:170px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars(trim((string)($payment['customer_name'] ?? '')) ?: 'Customer'); ?>"><?php echo htmlspecialchars(trim((string)($payment['customer_name'] ?? '')) ?: 'Customer'); ?></div>
                                        <div class="text-xs text-gray-400" style="max-width:170px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars((string)($payment['customer_email'] ?? '')); ?>"><?php echo htmlspecialchars((string)($payment['customer_email'] ?? '')); ?></div>
                                    </td>
                                    <td class="py-3"><?php echo htmlspecialchars((string)($payment['branch_name'] ?? 'Unassigned')); ?></td>
                                    <td class="py-3 text-right">
                                        <?php echo format_currency((float)($payment['submitted_amount'] ?? 0)); ?>
                                    </td>
                                    <td class="py-3 text-center">
                                        <?php
                                            $paymentBadgeStatus = (string)($payment['proof_status'] ?? '');
                                            if ($paymentBadgeStatus === '' || strtoupper(str_replace([' ', '-'], '_', $paymentBadgeStatus)) === 'TO_VERIFY') {
                                                $paymentBadgeStatus = $bucket === 'to_verify' ? 'PENDING_VERIFICATION' : ($paymentBadgeStatus ?: (string)($payment['payment_status'] ?? ''));
                                            }
                                            echo pf_admin_payment_badge($paymentBadgeStatus, 'proof');
                                        ?>
                                    </td>
                                    <td class="py-3 text-center text-gray-500 text-xs"><?php echo !empty($payment['submitted_at']) ? htmlspecialchars(date('M j, Y', strtotime((string)$payment['submitted_at']))) : 'No date'; ?></td>
                                    <td class="py-3 text-center">
                                        <?php if ($proofUrl !== ''): ?>
                                            <button class="proof-thumb" type="button" onclick="event.stopPropagation(); openProofModal('<?php echo htmlspecialchars($proofUrl, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars((string)($payment['order_label'] ?? (($isCustomization ? 'Customization #' : 'Order #') . $recordId)), ENT_QUOTES, 'UTF-8'); ?>')" title="View proof">
                                                <img src="<?php echo htmlspecialchars($proofPreviewUrl); ?>" alt="Payment proof">
                                            </button>
                                        <?php else: ?>
                                            <span class="text-gray-400 text-xs">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 text-center">
                                        <div class="row-actions">
                                            <a class="btn-action blue" href="<?php echo htmlspecialchars($openUrl); ?>" onclick="event.stopPropagation();">View</a>
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

<div class="modal-backdrop" id="proofModal" onclick="closeProofModal()">
    <div class="proof-modal-panel" onclick="event.stopPropagation()">
        <div class="modal-head" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <h2 id="proofModalTitle">Payment Proof</h2>
            <button class="btn-action blue" type="button" onclick="closeProofModal()">Close</button>
        </div>
        <div class="proof-modal-image-wrap">
            <img id="proofModalImage" src="" alt="Payment proof preview">
        </div>
    </div>
</div>
<script>
let paymentFilterTimer = null;

function paymentFilterChanged() {
    clearTimeout(paymentFilterTimer);
    paymentFilterTimer = setTimeout(submitPaymentFilters, 500);
}

function submitPaymentFilters() {
    const form = document.getElementById('paymentFilterForm');
    if (form) form.submit();
}

function resetPaymentFilter(fields) {
    const form = document.getElementById('paymentFilterForm');
    if (!form) return;
    fields.forEach(function (field) {
        const input = form.elements[field];
        if (!input) return;
        input.value = field === 'type' ? 'all' : (field === 'status' ? 'all' : '');
    });
    submitPaymentFilters();
}

function openProofModal(src, title) {
    const modal = document.getElementById('proofModal');
    const image = document.getElementById('proofModalImage');
    const heading = document.getElementById('proofModalTitle');
    image.src = src;
    heading.textContent = title || 'Payment Proof';
    modal.classList.add('open');
}

function closeProofModal() {
    const modal = document.getElementById('proofModal');
    const image = document.getElementById('proofModalImage');
    modal.classList.remove('open');
    image.src = '';
}

window.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeProofModal();
    }
});
</script>
</body>
</html>
