<?php
/**
 * Online Staff/Admin payment receipt verification workspace.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/payment_verification.php';

require_role(['Admin', 'Staff']);
printflow_require_staff_module('payment_verification');
if (($_SESSION['user_type'] ?? '') === 'Staff') {
    require_once __DIR__ . '/../includes/staff_pending_check.php';
}

$schemaReady = payment_verification_ensure_schema();
if ($schemaReady) payment_verification_import_legacy_submissions(100);
$branchFilter = printflow_branch_filter_for_user();
if (($_SESSION['user_type'] ?? '') === 'Staff' && ($branchFilter === null || $branchFilter <= 0)) {
    $branchFilter = (int)($_SESSION['branch_id'] ?? 0);
    if ($branchFilter <= 0) $branchFilter = -1;
}

$search = trim((string)($_GET['search'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$method = trim((string)($_GET['method'] ?? ''));
$amountFilter = trim((string)($_GET['amount_match'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$allowedStatuses = ['Pending Review', 'Matched', 'Needs Review', 'Approved', 'Rejected', 'Duplicate Suspected'];
if (!in_array($status, $allowedStatuses, true)) $status = '';
$allowedAmountFilters = ['Matched', 'Mismatch', 'Unknown'];
if (!in_array($amountFilter, $allowedAmountFilters, true)) $amountFilter = '';

$from = " FROM payment_submissions ps
          LEFT JOIN orders o ON o.order_id = ps.order_id
          LEFT JOIN job_orders jo ON jo.id = ps.job_order_id
          LEFT JOIN customers c ON c.customer_id = ps.customer_id
          WHERE 1=1";
$types = '';
$params = [];
if ($branchFilter !== null) {
    $from .= ' AND COALESCE(o.branch_id, jo.branch_id, 0) = ?';
    $types .= 'i';
    $params[] = (int)$branchFilter;
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $from .= " AND (
        CONCAT_WS(' ', c.first_name, c.last_name) LIKE ?
        OR CAST(ps.order_id AS CHAR) LIKE ? OR CAST(ps.job_order_id AS CHAR) LIKE ?
        OR COALESCE(o.order_sku, '') LIKE ?
        OR COALESCE(NULLIF(ps.reference_number, ''), ps.ocr_reference_number, '') LIKE ?
        OR COALESCE(NULLIF(ps.sender_name, ''), ps.ocr_sender_name, '') LIKE ?
    )";
    $types .= 'ssssss';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($status !== '') {
    $from .= ' AND ps.verification_status = ?';
    $types .= 's';
    $params[] = $status;
}
if ($method !== '') {
    $from .= " AND (ps.selected_payment_method = ? OR COALESCE(NULLIF(ps.detected_payment_method, ''), ps.ocr_detected_payment_method, '') = ?)";
    $types .= 'ss';
    $params[] = $method;
    $params[] = $method;
}
if ($amountFilter !== '') {
    $from .= ' AND ps.amount_match_status = ?';
    $types .= 's';
    $params[] = $amountFilter;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $from .= ' AND ps.created_at >= ?';
    $types .= 's';
    $params[] = $dateFrom . ' 00:00:00';
} else {
    $dateFrom = '';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $from .= ' AND ps.created_at <= ?';
    $types .= 's';
    $params[] = $dateTo . ' 23:59:59';
} else {
    $dateTo = '';
}

$total = 0;
$submissions = [];
if ($schemaReady) {
    $count = db_query('SELECT COUNT(*) AS total' . $from, $types, $params);
    $total = (int)($count[0]['total'] ?? 0);
    $submissions = db_query(
        "SELECT ps.*, o.order_sku,
                CONCAT_WS(' ', c.first_name, c.last_name) AS customer_name,
                COALESCE(o.branch_id, jo.branch_id, 0) AS branch_id
         {$from}
         ORDER BY
            CASE ps.verification_status
                WHEN 'Duplicate Suspected' THEN 1
                WHEN 'Needs Review' THEN 2
                WHEN 'Pending Review' THEN 3
                WHEN 'Matched' THEN 4
                ELSE 5
            END,
            ps.created_at DESC
         LIMIT ? OFFSET ?",
        $types . 'ii',
        array_merge($params, [$perPage, $offset])
    ) ?: [];
}
$totalPages = max(1, (int)ceil($total / $perPage));

$kpis = ['pending' => 0, 'matched' => 0, 'review' => 0, 'approved' => 0];
if ($schemaReady) {
    $kpiWhere = ' FROM payment_submissions ps LEFT JOIN orders o ON o.order_id = ps.order_id LEFT JOIN job_orders jo ON jo.id = ps.job_order_id WHERE 1=1';
    $kpiTypes = '';
    $kpiParams = [];
    if ($branchFilter !== null) {
        $kpiWhere .= ' AND COALESCE(o.branch_id, jo.branch_id, 0) = ?';
        $kpiTypes = 'i';
        $kpiParams[] = (int)$branchFilter;
    }
    $kpiRows = db_query(
        "SELECT
            SUM(ps.verification_status = 'Pending Review') AS pending,
            SUM(ps.verification_status = 'Matched') AS matched,
            SUM(ps.verification_status IN ('Needs Review', 'Duplicate Suspected')) AS review,
            SUM(ps.verification_status = 'Approved') AS approved
         {$kpiWhere}",
        $kpiTypes,
        $kpiParams
    );
    if (!empty($kpiRows)) {
        foreach ($kpis as $key => $unused) $kpis[$key] = (int)($kpiRows[0][$key] ?? 0);
    }
}

$detailId = (int)($_GET['submission_id'] ?? 0);
$detail = $detailId > 0 ? payment_verification_get_submission($detailId) : null;
if ($detail && !payment_verification_can_access($detail, $branchFilter)) $detail = null;
if ($detail) {
    payment_verification_recalculate($detailId);
    $detail = payment_verification_get_submission($detailId);
}

function pv_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pv_effective(array $row, string $corrected, string $ocr): string {
    return trim((string)payment_verification_effective_value($row, $corrected, $ocr));
}

function pv_status_class(string $status): string {
    return match ($status) {
        'Approved' => 'is-approved',
        'Matched' => 'is-matched',
        'Rejected' => 'is-rejected',
        'Duplicate Suspected' => 'is-duplicate',
        'Needs Review' => 'is-review',
        default => 'is-pending',
    };
}

function pv_confidence_class(float $value): string {
    if ($value >= 85) return 'confidence-high';
    if ($value >= 60) return 'confidence-review';
    return 'confidence-low';
}

$basePath = defined('BASE_PATH') ? rtrim((string)BASE_PATH, '/') : '';
$pageTitle = 'Payment Verification - PrintFlow';
$csrfToken = generate_csrf_token();
$filterState = [
    'search' => $search, 'status' => $status, 'method' => $method,
    'amount_match' => $amountFilter, 'date_from' => $dateFrom, 'date_to' => $dateTo,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo pv_h($pageTitle); ?></title>
    <link rel="stylesheet" href="<?php echo pv_h($basePath); ?>/public/assets/css/output.css">
    <?php include __DIR__ . '/../includes/admin_style.php'; ?>
    <style>
        :root { --pv-ink:#082f3a; --pv-teal:#087f83; --pv-cyan:#53c5e0; --pv-mist:#eef9fa; --pv-line:#d7e8eb; --pv-warn:#b45309; --pv-danger:#b42318; }
        body { background: radial-gradient(circle at 82% 5%, rgba(83,197,224,.16), transparent 28%), #f5f8f8; color:var(--pv-ink); }
        .pv-main { padding:28px; min-width:0; }
        .pv-header { display:flex; align-items:flex-end; justify-content:space-between; gap:20px; margin-bottom:24px; }
        .pv-title { margin:0; font-size:30px; line-height:1.1; letter-spacing:-.03em; font-weight:850; }
        .pv-subtitle { margin:7px 0 0; color:#58717a; font-size:14px; }
        .pv-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:18px; }
        .pv-kpi { background:#fff; border:1px solid var(--pv-line); border-radius:16px; padding:17px 18px; box-shadow:0 8px 24px rgba(8,47,58,.045); position:relative; overflow:hidden; }
        .pv-kpi:before { content:""; position:absolute; inset:0 auto 0 0; width:4px; background:var(--accent,#53c5e0); }
        .pv-kpi-label { color:#68808a; text-transform:uppercase; letter-spacing:.08em; font-size:11px; font-weight:800; }
        .pv-kpi-value { display:block; margin-top:6px; font-size:28px; font-weight:850; color:var(--pv-ink); }
        .pv-panel { background:#fff; border:1px solid var(--pv-line); border-radius:18px; box-shadow:0 12px 32px rgba(8,47,58,.055); }
        .pv-filters { padding:16px; display:grid; grid-template-columns:2fr repeat(5,minmax(125px,1fr)) auto; gap:10px; margin-bottom:18px; }
        .pv-field, .pv-select { width:100%; height:42px; border:1px solid #c9dde1; border-radius:10px; padding:0 12px; background:#fff; color:#163e48; font-size:13px; box-sizing:border-box; }
        .pv-field:focus, .pv-select:focus { outline:3px solid rgba(83,197,224,.22); border-color:#32a1c4; }
        .pv-button { border:0; border-radius:10px; min-height:42px; padding:0 16px; font-weight:800; font-size:13px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:7px; text-decoration:none; }
        .pv-button.primary { background:var(--pv-ink); color:#fff; }
        .pv-button.teal { background:#0c9b88; color:#fff; }
        .pv-button.light { background:#edf7f8; color:#155866; border:1px solid #cce4e7; }
        .pv-button.danger { background:#fff0ee; color:var(--pv-danger); border:1px solid #f4c7c2; }
        .pv-button.warning { background:#fff8e8; color:#995300; border:1px solid #f5d99b; }
        .pv-button:disabled { opacity:.5; cursor:not-allowed; }
        .pv-table-wrap { overflow:auto; }
        .pv-table { width:100%; border-collapse:collapse; min-width:1120px; }
        .pv-table th { padding:13px 14px; text-align:left; color:#6a8088; font-size:10px; text-transform:uppercase; letter-spacing:.08em; border-bottom:1px solid var(--pv-line); background:#f8fbfb; }
        .pv-table td { padding:14px; vertical-align:middle; border-bottom:1px solid #eaf1f2; font-size:13px; }
        .pv-table tr:hover td { background:#fbfefe; }
        .pv-receipt-thumb { width:58px; height:58px; border-radius:10px; object-fit:cover; border:1px solid #d8e5e7; background:#edf4f5; display:block; }
        .pv-pdf-thumb { width:58px; height:58px; border-radius:10px; background:#fff1f0; color:#b42318; display:grid; place-items:center; font-size:11px; font-weight:900; border:1px solid #f1c8c4; }
        .pv-mainline { font-weight:800; color:#123b45; }
        .pv-muted { color:#70858d; font-size:12px; margin-top:3px; }
        .pv-money { font-variant-numeric:tabular-nums; font-weight:850; }
        .pv-status { display:inline-flex; align-items:center; padding:6px 9px; border-radius:999px; font-size:11px; font-weight:850; white-space:nowrap; }
        .is-approved { background:#dcfce7; color:#166534; }.is-matched { background:#dff7f2; color:#08705f; }
        .is-rejected { background:#fee2e2; color:#991b1b; }.is-duplicate { background:#ffedd5; color:#9a3412; }
        .is-review { background:#fef3c7; color:#92400e; }.is-pending { background:#e7f3fb; color:#075985; }
        .pv-match { font-size:11px; font-weight:800; }.pv-match.ok { color:#08705f; }.pv-match.bad { color:#b42318; }.pv-match.unknown { color:#74878e; }
        .pv-confidence { display:inline-flex; padding:5px 8px; border-radius:8px; font-size:11px; font-weight:850; }
        .confidence-high { background:#dcfce7; color:#166534; }.confidence-review { background:#fef3c7; color:#92400e; }.confidence-low { background:#fee2e2; color:#991b1b; }
        .pv-empty { padding:54px 24px; text-align:center; color:#6b8189; }
        .pv-pagination { display:flex; justify-content:space-between; align-items:center; padding:14px 16px; color:#647981; font-size:12px; }
        .pv-pages { display:flex; gap:6px; }.pv-page { min-width:34px; height:34px; display:grid; place-items:center; border:1px solid #d4e3e5; border-radius:8px; color:#315661; background:#fff; text-decoration:none; }.pv-page.active { background:var(--pv-ink); color:#fff; border-color:var(--pv-ink); }
        .pv-overlay { position:fixed; inset:0; z-index:3000; background:rgba(1,24,30,.68); backdrop-filter:blur(5px); display:flex; justify-content:flex-end; }
        .pv-drawer { width:min(1120px,96vw); height:100%; background:#f7faf9; overflow:auto; box-shadow:-24px 0 70px rgba(0,0,0,.2); }
        .pv-drawer-head { position:sticky; top:0; z-index:5; padding:18px 22px; background:rgba(255,255,255,.96); border-bottom:1px solid var(--pv-line); display:flex; align-items:center; justify-content:space-between; gap:14px; }
        .pv-detail-grid { display:grid; grid-template-columns:minmax(320px,.82fr) minmax(460px,1.18fr); gap:18px; padding:18px; }
        .pv-card { background:#fff; border:1px solid var(--pv-line); border-radius:16px; padding:18px; margin-bottom:16px; }
        .pv-card-title { margin:0 0 14px; font-size:13px; text-transform:uppercase; letter-spacing:.07em; color:#38606a; }
        .pv-proof-large { width:100%; max-height:68vh; object-fit:contain; background:#eef3f3; border-radius:12px; border:1px solid #d6e3e5; }
        .pv-proof-pdf { width:100%; height:68vh; border:1px solid #d6e3e5; border-radius:12px; background:#fff; }
        .pv-info-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; }
        .pv-info { padding:12px; border-radius:11px; background:#f4f9f9; border:1px solid #e1eeee; }
        .pv-info-label { display:block; color:#72878e; font-size:10px; text-transform:uppercase; letter-spacing:.07em; font-weight:800; margin-bottom:4px; }
        .pv-info-value { font-size:13px; font-weight:800; color:#143c46; overflow-wrap:anywhere; }
        .pv-compare { display:grid; grid-template-columns:1fr auto 1fr; gap:12px; align-items:center; }
        .pv-compare-box { padding:15px; border-radius:12px; background:#f4f9f9; text-align:center; }.pv-compare-value { font-size:21px; font-weight:900; font-variant-numeric:tabular-nums; }
        .pv-edit-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:13px; }
        .pv-edit-field label { display:flex; justify-content:space-between; gap:8px; font-size:11px; font-weight:850; color:#355e67; margin-bottom:6px; }
        .pv-edit-field input, .pv-edit-field select, .pv-edit-field textarea { width:100%; box-sizing:border-box; border:1px solid #c9dce0; border-radius:9px; padding:10px 11px; color:#153d47; background:#fff; font-size:13px; }
        .pv-original { margin-top:5px; font-size:10px; color:#768b92; overflow-wrap:anywhere; }
        .pv-actions { display:flex; flex-wrap:wrap; gap:9px; margin-top:16px; padding-top:16px; border-top:1px solid #e4eeee; }
        .pv-raw { white-space:pre-wrap; max-height:260px; overflow:auto; background:#071e25; color:#cbe9ee; border-radius:10px; padding:13px; font:12px/1.55 ui-monospace,Consolas,monospace; }
        .pv-toast { position:fixed; right:22px; bottom:22px; z-index:5000; max-width:380px; padding:13px 16px; border-radius:11px; color:#fff; background:#082f3a; box-shadow:0 12px 30px rgba(0,0,0,.24); font-size:13px; font-weight:750; display:none; }
        @media(max-width:1150px){.pv-filters{grid-template-columns:repeat(3,1fr)}.pv-kpis{grid-template-columns:repeat(2,1fr)}}
        @media(max-width:820px){.pv-main{padding:18px 12px}.pv-header{align-items:flex-start;flex-direction:column}.pv-kpis{grid-template-columns:1fr 1fr}.pv-filters{grid-template-columns:1fr 1fr}.pv-detail-grid{grid-template-columns:1fr}.pv-edit-grid,.pv-info-grid{grid-template-columns:1fr}.pv-drawer{width:100vw}.pv-compare{grid-template-columns:1fr}.pv-proof-large,.pv-proof-pdf{max-height:50vh;height:50vh}}
        @media(max-width:520px){.pv-kpis,.pv-filters{grid-template-columns:1fr}.pv-title{font-size:25px}}
    </style>
</head>
<body data-csrf="<?php echo pv_h($csrfToken); ?>" data-base-path="<?php echo pv_h($basePath); ?>">
<div class="dashboard-container">
    <?php if (($_SESSION['user_type'] ?? '') === 'Admin'): ?>
        <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>
    <?php else: ?>
        <?php include __DIR__ . '/../includes/staff_sidebar.php'; ?>
    <?php endif; ?>
    <main class="main-content pv-main">
        <header class="pv-header">
            <div>
                <h1 class="pv-title">Payment Verification</h1>
                <p class="pv-subtitle">OCR-assisted receipt review. Staff confirmation is always required before payment approval.</p>
            </div>
            <a class="pv-button light" href="<?php echo pv_h($basePath); ?>/staff/payment_verification.php">Refresh Queue</a>
        </header>

        <?php if (!$schemaReady): ?>
            <div class="pv-panel pv-empty">Payment verification storage could not be initialized. Apply <strong>database/payment_verification_ocr_20260711.sql</strong> and refresh this page.</div>
        <?php else: ?>
        <section class="pv-kpis" aria-label="Payment verification totals">
            <div class="pv-kpi" style="--accent:#4aaed0"><span class="pv-kpi-label">Pending OCR Review</span><strong class="pv-kpi-value"><?php echo number_format($kpis['pending']); ?></strong></div>
            <div class="pv-kpi" style="--accent:#0c9b88"><span class="pv-kpi-label">Amount Matched</span><strong class="pv-kpi-value"><?php echo number_format($kpis['matched']); ?></strong></div>
            <div class="pv-kpi" style="--accent:#e29b31"><span class="pv-kpi-label">Needs Attention</span><strong class="pv-kpi-value"><?php echo number_format($kpis['review']); ?></strong></div>
            <div class="pv-kpi" style="--accent:#25a76f"><span class="pv-kpi-label">Approved</span><strong class="pv-kpi-value"><?php echo number_format($kpis['approved']); ?></strong></div>
        </section>

        <form class="pv-panel pv-filters" method="get">
            <input class="pv-field" type="search" name="search" value="<?php echo pv_h($search); ?>" placeholder="Customer, order, sender, or reference">
            <select class="pv-select" name="status"><option value="">All statuses</option><?php foreach ($allowedStatuses as $option): ?><option value="<?php echo pv_h($option); ?>" <?php echo $status === $option ? 'selected' : ''; ?>><?php echo pv_h($option); ?></option><?php endforeach; ?></select>
            <select class="pv-select" name="method"><option value="">All methods</option><?php foreach (['GCash','Maya','Bank Transfer','Bank Transfer / InstaPay','Bank Transfer / PESONet'] as $option): ?><option value="<?php echo pv_h($option); ?>" <?php echo $method === $option ? 'selected' : ''; ?>><?php echo pv_h($option); ?></option><?php endforeach; ?></select>
            <select class="pv-select" name="amount_match"><option value="">Any amount result</option><?php foreach ($allowedAmountFilters as $option): ?><option value="<?php echo pv_h($option); ?>" <?php echo $amountFilter === $option ? 'selected' : ''; ?>>Amount <?php echo pv_h($option); ?></option><?php endforeach; ?></select>
            <input class="pv-field" type="date" name="date_from" value="<?php echo pv_h($dateFrom); ?>" title="Submitted from">
            <input class="pv-field" type="date" name="date_to" value="<?php echo pv_h($dateTo); ?>" title="Submitted through">
            <button class="pv-button primary" type="submit">Apply Filters</button>
        </form>

        <section class="pv-panel">
            <?php if (empty($submissions)): ?>
                <div class="pv-empty"><strong>No payment submissions found.</strong><div class="pv-muted">New and imported payment proofs will appear here.</div></div>
            <?php else: ?>
            <div class="pv-table-wrap">
                <table class="pv-table">
                    <thead><tr><th>Receipt</th><th>Order / Customer</th><th>Sender / Reference</th><th>Amount Comparison</th><th>Payment Method</th><th>Transaction</th><th>OCR Confidence</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($submissions as $submission):
                        $sender = pv_effective($submission, 'sender_name', 'ocr_sender_name');
                        $reference = pv_effective($submission, 'reference_number', 'ocr_reference_number');
                        $detectedMethod = pv_effective($submission, 'detected_payment_method', 'ocr_detected_payment_method');
                        $amountRaw = payment_verification_effective_value($submission, 'amount_sent', 'ocr_amount_sent');
                        $amount = ($amountRaw === null || $amountRaw === '') ? null : (float)$amountRaw;
                        $confidence = (float)($submission['overall_confidence'] ?? 0);
                        $previewPath = (string)($submission['receipt_thumbnail'] ?: $submission['receipt_file']);
                        $isPdf = strtolower((string)$submission['receipt_mime']) === 'application/pdf' || preg_match('/\.pdf(?:$|\?)/i', (string)$submission['receipt_file']);
                        $viewQuery = array_merge($filterState, ['page' => $page, 'submission_id' => (int)$submission['id']]);
                    ?>
                    <tr>
                        <td><?php if ($isPdf): ?><div class="pv-pdf-thumb">PDF</div><?php else: ?><img class="pv-receipt-thumb" loading="lazy" src="<?php echo pv_h(payment_verification_proof_url($previewPath)); ?>" alt="Receipt preview"><?php endif; ?><div class="pv-muted"><?php echo pv_h(date('M j, Y', strtotime((string)$submission['created_at']))); ?></div></td>
                        <td><div class="pv-mainline"><?php echo pv_h(payment_verification_order_label($submission)); ?></div><div class="pv-muted"><?php echo pv_h($submission['customer_name'] ?: 'Customer'); ?></div></td>
                        <td><div class="pv-mainline"><?php echo pv_h($sender !== '' ? $sender : 'Not detected'); ?></div><div class="pv-muted">Ref: <?php echo pv_h($reference !== '' ? $reference : 'Not detected'); ?></div></td>
                        <td><div class="pv-money"><?php echo $amount === null ? 'Not detected' : pv_h(format_currency($amount)); ?></div><div class="pv-muted">Expected <?php echo pv_h(format_currency((float)$submission['expected_amount'])); ?></div><div class="pv-match <?php echo $submission['amount_match_status'] === 'Matched' ? 'ok' : ($submission['amount_match_status'] === 'Mismatch' ? 'bad' : 'unknown'); ?>"><?php echo pv_h('Amount ' . $submission['amount_match_status']); ?></div></td>
                        <td><div class="pv-mainline"><?php echo pv_h($detectedMethod !== '' ? $detectedMethod : 'Not detected'); ?></div><div class="pv-muted">Selected: <?php echo pv_h($submission['selected_payment_method'] ?: 'Unknown'); ?></div><div class="pv-match <?php echo $submission['method_match_status'] === 'Matched' ? 'ok' : ($submission['method_match_status'] === 'Mismatch' ? 'bad' : 'unknown'); ?>"><?php echo pv_h('Method ' . $submission['method_match_status']); ?></div></td>
                        <td><div class="pv-mainline"><?php echo pv_h(pv_effective($submission, 'transaction_date', 'ocr_transaction_date') ?: 'Date unknown'); ?></div><div class="pv-muted"><?php echo pv_h(pv_effective($submission, 'transaction_time', 'ocr_transaction_time') ?: 'Time unknown'); ?></div></td>
                        <td><span class="pv-confidence <?php echo pv_confidence_class($confidence); ?>"><?php echo number_format($confidence, 0); ?>%</span><div class="pv-muted"><?php echo pv_h($submission['ocr_status']); ?></div></td>
                        <td><span class="pv-status <?php echo pv_status_class((string)$submission['verification_status']); ?>"><?php echo pv_h($submission['verification_status']); ?></span><?php if ((int)$submission['duplicate_submission_id'] > 0): ?><div class="pv-muted">Matches #<?php echo (int)$submission['duplicate_submission_id']; ?></div><?php endif; ?></td>
                        <td><a class="pv-button light" href="?<?php echo pv_h(http_build_query(array_filter($viewQuery, static fn($value) => $value !== ''))); ?>">Review</a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="pv-pagination"><span>Showing <?php echo $total ? ($offset + 1) : 0; ?>-<?php echo min($offset + $perPage, $total); ?> of <?php echo $total; ?></span><div class="pv-pages"><?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): $query = array_merge($filterState, ['page' => $p]); ?><a class="pv-page <?php echo $p === $page ? 'active' : ''; ?>" href="?<?php echo pv_h(http_build_query(array_filter($query, static fn($value) => $value !== ''))); ?>"><?php echo $p; ?></a><?php endfor; ?></div></div>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </main>
</div>

<?php if ($detail):
    $detailStatus = (string)$detail['verification_status'];
    $isFinal = in_array($detailStatus, ['Approved', 'Rejected'], true);
    $detailAmountRaw = payment_verification_effective_value($detail, 'amount_sent', 'ocr_amount_sent');
    $detailAmount = ($detailAmountRaw === null || $detailAmountRaw === '') ? null : (float)$detailAmountRaw;
    $detailMethod = pv_effective($detail, 'detected_payment_method', 'ocr_detected_payment_method');
    $closeQuery = $filterState + ['page' => $page];
    $proofUrl = payment_verification_proof_url((string)$detail['receipt_file']);
    $detailIsPdf = strtolower((string)$detail['receipt_mime']) === 'application/pdf' || preg_match('/\.pdf(?:$|\?)/i', (string)$detail['receipt_file']);
?>
<div class="pv-overlay" role="dialog" aria-modal="true" aria-label="Payment submission review">
    <section class="pv-drawer">
        <header class="pv-drawer-head"><div><strong style="font-size:19px;">Review <?php echo pv_h(payment_verification_order_label($detail)); ?></strong><div class="pv-muted">Submission #<?php echo (int)$detail['id']; ?>, received <?php echo pv_h(date('M j, Y g:i A', strtotime((string)$detail['created_at']))); ?></div></div><a class="pv-button light" href="?<?php echo pv_h(http_build_query(array_filter($closeQuery, static fn($value) => $value !== ''))); ?>">Close</a></header>
        <div class="pv-detail-grid">
            <div>
                <div class="pv-card"><h2 class="pv-card-title">Receipt</h2><?php if ($detailIsPdf): ?><object class="pv-proof-pdf" data="<?php echo pv_h($proofUrl); ?>" type="application/pdf"><a href="<?php echo pv_h($proofUrl); ?>" target="_blank" rel="noopener">Open PDF receipt</a></object><?php else: ?><img class="pv-proof-large" src="<?php echo pv_h($proofUrl); ?>" alt="Uploaded payment receipt"><?php endif; ?><div class="pv-actions"><a class="pv-button light" href="<?php echo pv_h($proofUrl); ?>" target="_blank" rel="noopener">Open Full Receipt</a><button class="pv-button light" type="button" onclick="pvRescan(<?php echo (int)$detail['id']; ?>)" <?php echo $isFinal ? 'disabled' : ''; ?>>Re-scan OCR</button></div></div>
                <div class="pv-card"><h2 class="pv-card-title">Order Information</h2><div class="pv-info-grid"><div class="pv-info"><span class="pv-info-label">Order Code</span><span class="pv-info-value"><?php echo pv_h(payment_verification_order_label($detail)); ?></span></div><div class="pv-info"><span class="pv-info-label">Customer</span><span class="pv-info-value"><?php echo pv_h($detail['customer_name'] ?: 'Customer'); ?></span></div><div class="pv-info"><span class="pv-info-label">Order Total</span><span class="pv-info-value"><?php echo pv_h(format_currency((float)$detail['expected_amount'])); ?></span></div><div class="pv-info"><span class="pv-info-label">Selected Method</span><span class="pv-info-value"><?php echo pv_h($detail['selected_payment_method'] ?: 'Unknown'); ?></span></div><div class="pv-info"><span class="pv-info-label">Order Status</span><span class="pv-info-value"><?php echo pv_h($detail['order_status'] ?: $detail['job_status']); ?></span></div><div class="pv-info"><span class="pv-info-label">Payment Status</span><span class="pv-info-value"><?php echo pv_h($detail['order_payment_status'] ?: $detail['job_payment_status']); ?></span></div></div></div>
            </div>
            <div>
                <div class="pv-card"><h2 class="pv-card-title">Amount Comparison</h2><div class="pv-compare"><div class="pv-compare-box"><span class="pv-info-label">Expected Amount</span><div class="pv-compare-value"><?php echo pv_h(format_currency((float)$detail['expected_amount'])); ?></div></div><span class="pv-status <?php echo $detail['amount_match_status'] === 'Matched' ? 'is-matched' : ($detail['amount_match_status'] === 'Mismatch' ? 'is-rejected' : 'is-pending'); ?>"><?php echo pv_h($detail['amount_match_status']); ?></span><div class="pv-compare-box"><span class="pv-info-label">OCR Detected</span><div class="pv-compare-value"><?php echo $detailAmount === null ? 'Unknown' : pv_h(format_currency($detailAmount)); ?></div></div></div></div>

                <form class="pv-card" id="pvCorrectionForm" onsubmit="return false;">
                    <input type="hidden" name="submission_id" value="<?php echo (int)$detail['id']; ?>">
                    <h2 class="pv-card-title">OCR Extracted Details</h2>
                    <div class="pv-edit-grid">
                        <?php $senderConfidence = (float)($detail['sender_confidence'] ?? 0); ?>
                        <div class="pv-edit-field"><label>Sender Name <span class="pv-confidence <?php echo pv_confidence_class($senderConfidence); ?>"><?php echo number_format($senderConfidence, 0); ?>%</span></label><input name="sender_name" value="<?php echo pv_h(pv_effective($detail, 'sender_name', 'ocr_sender_name')); ?>" <?php echo $isFinal ? 'disabled' : ''; ?>><div class="pv-original">OCR original: <?php echo pv_h($detail['ocr_sender_name'] ?: 'Not detected'); ?></div></div>
                        <?php $referenceConfidence = (float)($detail['reference_confidence'] ?? 0); ?>
                        <div class="pv-edit-field"><label>Reference Number <span class="pv-confidence <?php echo pv_confidence_class($referenceConfidence); ?>"><?php echo number_format($referenceConfidence, 0); ?>%</span></label><input name="reference_number" value="<?php echo pv_h(pv_effective($detail, 'reference_number', 'ocr_reference_number')); ?>" <?php echo $isFinal ? 'disabled' : ''; ?>><div class="pv-original">OCR original: <?php echo pv_h($detail['ocr_reference_number'] ?: 'Not detected'); ?></div></div>
                        <?php $amountConfidence = (float)($detail['amount_confidence'] ?? 0); ?>
                        <div class="pv-edit-field"><label>Amount Sent <span class="pv-confidence <?php echo pv_confidence_class($amountConfidence); ?>"><?php echo number_format($amountConfidence, 0); ?>%</span></label><input name="amount_sent" inputmode="decimal" value="<?php echo $detailAmount === null ? '' : pv_h(number_format($detailAmount, 2, '.', '')); ?>" <?php echo $isFinal ? 'disabled' : ''; ?>><div class="pv-original">OCR original: <?php echo $detail['ocr_amount_sent'] === null ? 'Not detected' : pv_h(format_currency((float)$detail['ocr_amount_sent'])); ?></div></div>
                        <?php $methodConfidence = (float)($detail['method_confidence'] ?? 0); ?>
                        <div class="pv-edit-field"><label>Detected Method <span class="pv-confidence <?php echo pv_confidence_class($methodConfidence); ?>"><?php echo number_format($methodConfidence, 0); ?>%</span></label><select name="detected_payment_method" <?php echo $isFinal ? 'disabled' : ''; ?>><option value="">Not detected</option><?php foreach (['GCash','Maya','Bank Transfer','Bank Transfer / InstaPay','Bank Transfer / PESONet'] as $option): ?><option value="<?php echo pv_h($option); ?>" <?php echo $detailMethod === $option ? 'selected' : ''; ?>><?php echo pv_h($option); ?></option><?php endforeach; ?></select><div class="pv-original">OCR original: <?php echo pv_h($detail['ocr_detected_payment_method'] ?: 'Not detected'); ?></div></div>
                        <div class="pv-edit-field"><label>Transaction Date <span class="pv-confidence <?php echo pv_confidence_class((float)($detail['date_confidence'] ?? 0)); ?>"><?php echo number_format((float)($detail['date_confidence'] ?? 0), 0); ?>%</span></label><input type="date" name="transaction_date" value="<?php echo pv_h(pv_effective($detail, 'transaction_date', 'ocr_transaction_date')); ?>" <?php echo $isFinal ? 'disabled' : ''; ?>></div>
                        <div class="pv-edit-field"><label>Transaction Time</label><input type="time" name="transaction_time" value="<?php echo pv_h(substr(pv_effective($detail, 'transaction_time', 'ocr_transaction_time'), 0, 5)); ?>" <?php echo $isFinal ? 'disabled' : ''; ?>></div>
                        <div class="pv-edit-field"><label>Receiver Name</label><input name="receiver_name" value="<?php echo pv_h(pv_effective($detail, 'receiver_name', 'ocr_receiver_name')); ?>" <?php echo $isFinal ? 'disabled' : ''; ?>><div class="pv-original">OCR original: <?php echo pv_h($detail['ocr_receiver_name'] ?: 'Not detected'); ?></div></div>
                        <div class="pv-edit-field"><label>Receiver Account</label><input name="receiver_account" value="" placeholder="Enter a correction only if needed" <?php echo $isFinal ? 'disabled' : ''; ?>><div class="pv-original">Stored masked: <?php echo pv_h(payment_verification_mask_account(pv_effective($detail, 'receiver_account', 'ocr_receiver_account')) ?: 'Not detected'); ?></div></div>
                    </div>
                    <div class="pv-edit-field" style="margin-top:13px;"><label>Staff Notes</label><textarea name="staff_notes" rows="3" <?php echo $isFinal ? 'disabled' : ''; ?>><?php echo pv_h($detail['staff_notes']); ?></textarea></div>
                    <div class="pv-actions">
                        <button class="pv-button light" type="button" onclick="pvSaveCorrections()" <?php echo $isFinal ? 'disabled' : ''; ?>>Save Corrections</button>
                        <button class="pv-button teal" type="button" onclick="pvApprove()" <?php echo $isFinal ? 'disabled' : ''; ?>>Approve Payment</button>
                        <button class="pv-button danger" type="button" onclick="pvReject()" <?php echo $isFinal ? 'disabled' : ''; ?>>Reject / New Receipt</button>
                        <button class="pv-button warning" type="button" onclick="pvMarkDuplicate()" <?php echo $isFinal ? 'disabled' : ''; ?>>Mark as Duplicate</button>
                        <span class="pv-status <?php echo pv_status_class($detailStatus); ?>"><?php echo pv_h($detailStatus); ?></span>
                    </div>
                </form>
                <details class="pv-card"><summary class="pv-card-title" style="cursor:pointer;margin:0;">Raw OCR Text (staff only)</summary><pre class="pv-raw"><?php echo pv_h($detail['raw_ocr_text'] ?: 'No OCR text is available. Review the receipt manually.'); ?></pre></details>
            </div>
        </div>
    </section>
</div>
<script>
window.pvDetail = <?php echo json_encode([
    'submissionId' => (int)$detail['id'],
    'orderId' => (int)($detail['order_id'] ?? 0),
    'jobOrderId' => (int)($detail['job_order_id'] ?? 0),
    'returnUrl' => '?' . http_build_query(array_filter($closeQuery, static fn($value) => $value !== '')),
], JSON_UNESCAPED_SLASHES); ?>;
</script>
<?php endif; ?>
<div id="pvToast" class="pv-toast" role="status"></div>
<script>
(function () {
    const csrf = document.body.dataset.csrf || '';
    const base = document.body.dataset.basePath || '';
    const detail = window.pvDetail || null;
    const form = document.getElementById('pvCorrectionForm');
    function toast(message, bad) {
        const el = document.getElementById('pvToast');
        if (!el) return;
        el.textContent = message;
        el.style.background = bad ? '#991b1b' : '#082f3a';
        el.style.display = 'block';
        window.setTimeout(() => { el.style.display = 'none'; }, 4200);
    }
    async function post(url, data) {
        data.set('csrf_token', csrf);
        const response = await fetch(url, { method:'POST', body:data, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.success) throw new Error(payload.error || 'The action could not be completed.');
        return payload;
    }
    function correctionData() {
        const data = new FormData(form);
        data.set('action', 'save_corrections');
        return data;
    }
    async function saveCorrections(silent) {
        if (!detail || !form) throw new Error('Payment details are unavailable.');
        const result = await post(base + '/staff/api_payment_verification.php', correctionData());
        if (!silent) toast('OCR corrections and staff notes were saved.');
        return result;
    }
    window.pvSaveCorrections = async function () {
        try { await saveCorrections(false); window.setTimeout(() => location.reload(), 500); }
        catch (error) { toast(error.message, true); }
    };
    window.pvApprove = async function () {
        if (!detail || !confirm('Approve this payment after reviewing the original receipt and OCR details?')) return;
        try {
            await saveCorrections(true);
            const data = new FormData();
            data.set('submission_id', detail.submissionId);
            data.set('staff_notes', new FormData(form).get('staff_notes') || '');
            let url;
            if (detail.jobOrderId > 0) {
                url = base + '/admin/api_verify_job_payment.php';
                data.set('id', detail.jobOrderId);
                data.set('order_id', detail.orderId);
                data.set('action', 'verify_payment');
            } else {
                url = base + '/staff/api_verify_payment.php';
                data.set('order_id', detail.orderId);
                data.set('action', 'Approve');
            }
            await post(url, data);
            toast('Payment verified and approved.');
            window.setTimeout(() => { location.href = detail.returnUrl; }, 800);
        } catch (error) { toast(error.message, true); }
    };
    window.pvReject = async function () {
        if (!detail) return;
        const reason = prompt('Enter the rejection reason. The customer will be asked to upload a new receipt:');
        if (!reason || !reason.trim()) return;
        try {
            await saveCorrections(true);
            const data = new FormData();
            data.set('submission_id', detail.submissionId);
            data.set('staff_notes', new FormData(form).get('staff_notes') || '');
            data.set('reason', reason.trim());
            let url;
            if (detail.jobOrderId > 0) {
                url = base + '/admin/api_verify_job_payment.php';
                data.set('id', detail.jobOrderId);
                data.set('order_id', detail.orderId);
                data.set('action', 'reject_payment');
            } else {
                url = base + '/staff/api_verify_payment.php';
                data.set('order_id', detail.orderId);
                data.set('action', 'Reject');
            }
            await post(url, data);
            toast('Payment proof rejected. The customer can upload a new receipt.');
            window.setTimeout(() => { location.href = detail.returnUrl; }, 900);
        } catch (error) { toast(error.message, true); }
    };
    window.pvMarkDuplicate = async function () {
        if (!detail || !confirm('Mark this reference or receipt as a suspected duplicate? This does not reject or approve payment.')) return;
        const data = new FormData(form);
        data.set('submission_id', detail.submissionId);
        data.set('action', 'mark_duplicate');
        try { await post(base + '/staff/api_payment_verification.php', data); toast('Submission marked as Duplicate Suspected.'); window.setTimeout(() => location.reload(), 650); }
        catch (error) { toast(error.message, true); }
    };
    window.pvRescan = async function (submissionId) {
        if (!confirm('Run OCR on this receipt again? Existing staff corrections will be preserved.')) return;
        const data = new FormData();
        data.set('submission_id', submissionId);
        data.set('action', 'rescan');
        toast('OCR re-scan started. This may take a few moments.');
        try { await post(base + '/staff/api_payment_verification.php', data); toast('OCR re-scan completed.'); window.setTimeout(() => location.reload(), 650); }
        catch (error) { toast(error.message, true); }
    };
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && detail) location.href = detail.returnUrl;
    });
    const queueData = new FormData();
    queueData.set('action', 'process_queue');
    queueData.set('csrf_token', csrf);
    fetch(base + '/staff/api_payment_verification.php', {
        method:'POST', body:queueData, credentials:'same-origin', keepalive:true,
        headers:{'X-Requested-With':'XMLHttpRequest'}
    }).catch(function () {});
})();
</script>
</body>
</html>
