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
$legacyAmountFilters = ['Matched' => 'exact_match', 'Unknown' => 'unreadable'];
if (isset($legacyAmountFilters[$amountFilter])) $amountFilter = $legacyAmountFilters[$amountFilter];
$allowedAmountFilters = ['exact_match', 'underpaid', 'overpaid', 'unreadable', 'pending_ocr'];
if (!in_array($amountFilter, $allowedAmountFilters, true)) $amountFilter = '';

$branchExpression = "COALESCE(NULLIF(ps.branch_id, 0), NULLIF(o.branch_id, 0), NULLIF(jo.branch_id, 0), 0)";
$orderSkuExpression = "(SELECT GROUP_CONCAT(DISTINCT sku_product.sku ORDER BY sku_product.sku SEPARATOR '-')
                        FROM order_items sku_item
                        LEFT JOIN products sku_product ON sku_product.product_id = sku_item.product_id
                        WHERE sku_item.order_id = ps.order_id)";
$from = " FROM payment_submissions ps
          LEFT JOIN orders o ON o.order_id = ps.order_id
          LEFT JOIN job_orders jo ON jo.id = ps.job_order_id
          LEFT JOIN customers c ON c.customer_id = ps.customer_id
          WHERE 1=1";
$types = '';
$params = [];
if ($branchFilter !== null) {
    $from .= " AND {$branchExpression} = ?";
    $types .= 'i';
    $params[] = (int)$branchFilter;
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $from .= " AND (
        CONCAT_WS(' ', c.first_name, c.last_name) LIKE ?
        OR CAST(ps.order_id AS CHAR) LIKE ? OR CAST(ps.job_order_id AS CHAR) LIKE ?
        OR COALESCE({$orderSkuExpression}, '') LIKE ?
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
    $effectiveAmount = 'COALESCE(ps.amount_sent, ps.ocr_amount_sent)';
    if ($amountFilter === 'pending_ocr') {
        $from .= " AND ps.ocr_status IN ('Pending', 'Processing')";
    } elseif ($amountFilter === 'unreadable') {
        $from .= " AND ps.ocr_status NOT IN ('Pending', 'Processing') AND {$effectiveAmount} IS NULL";
    } elseif ($amountFilter === 'exact_match') {
        $from .= " AND {$effectiveAmount} IS NOT NULL AND {$effectiveAmount} = ps.expected_amount";
    } elseif ($amountFilter === 'underpaid') {
        $from .= " AND {$effectiveAmount} IS NOT NULL AND {$effectiveAmount} < ps.expected_amount";
    } elseif ($amountFilter === 'overpaid') {
        $from .= " AND {$effectiveAmount} IS NOT NULL AND {$effectiveAmount} > ps.expected_amount";
    }
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
$queueError = '';
$queueErrorBaseline = function_exists('printflow_db_errors') ? count(printflow_db_errors()) : 0;
if ($schemaReady) {
    $count = db_query('SELECT COUNT(*) AS total' . $from, $types, $params);
    $total = (int)($count[0]['total'] ?? 0);
    $submissions = db_query(
        "SELECT ps.*,
                {$orderSkuExpression} AS order_sku,
                CONCAT_WS(' ', c.first_name, c.last_name) AS customer_name,
                {$branchExpression} AS resolved_branch_id
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
        $kpiWhere .= " AND {$branchExpression} = ?";
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
$queueErrors = function_exists('printflow_db_errors') ? array_slice(printflow_db_errors(), $queueErrorBaseline) : [];
if ($schemaReady && !empty($queueErrors)) {
    $queueError = 'The payment queue could not be loaded. Your current results were kept; please try Refresh Queue again.';
    payment_verification_log('staff_queue_query_failed', [
        'branch_id' => $branchFilter,
        'error_count' => count($queueErrors),
        'last_error' => (string)($queueErrors[array_key_last($queueErrors)]['error'] ?? 'unknown'),
    ]);
} else {
    payment_verification_log('staff_queue_loaded', ['branch_id' => $branchFilter, 'records' => count($submissions), 'total' => $total]);
}

$detailId = (int)($_GET['submission_id'] ?? 0);
$detail = $detailId > 0 ? payment_verification_get_submission($detailId) : null;
if ($detail && !payment_verification_can_access($detail, $branchFilter)) $detail = null;
if ($detail) {
    payment_verification_recalculate($detailId);
    $detail = payment_verification_get_submission($detailId);
}
$detailItems = [];
if ($detail && (int)($detail['order_id'] ?? 0) > 0) {
    $detailItems = db_query(
        "SELECT oi.quantity, oi.unit_price, COALESCE(NULLIF(p.name, ''), 'Custom item') AS item_name
         FROM order_items oi
         LEFT JOIN products p ON p.product_id = oi.product_id
         WHERE oi.order_id = ?
         ORDER BY oi.order_item_id ASC",
        'i',
        [(int)$detail['order_id']]
    ) ?: [];
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
        .pv-main { padding: 24px; min-width: 0; }
        .pv-header { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; margin-bottom: 24px; }
        .pv-title { margin: 0; font-size: 24px; font-weight: 700; color: #1f2937; line-height: 1.2; }
        .pv-subtitle { margin: 4px 0 0; color: #6b7280; font-size: 14px; }
        .pv-kpi-row { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 24px; }
        .pv-table-wrap { overflow-x: auto; }
        .pv-table { width: 100%; border-collapse: collapse; min-width: 1100px; }
        .pv-table th { padding: 12px 16px; text-align: left; color: #6b7280; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; background: #f9fafb; }
        .pv-table td { padding: 14px 16px; vertical-align: middle; border-bottom: 1px solid #f3f4f6; font-size: 14px; color: #374151; }
        .pv-table tr:hover td { background: #f9fafb; }
        .pv-receipt-thumb { width: 56px; height: 56px; border-radius: 8px; object-fit: cover; border: 1px solid #e5e7eb; background: #f3f4f6; display: block; }
        .pv-pdf-thumb { width: 56px; height: 56px; border-radius: 8px; background: #fef2f2; color: #dc2626; display: grid; place-items: center; font-size: 11px; font-weight: 700; border: 1px solid #fecaca; }
        .pv-mainline { font-weight: 600; color: #1f2937; }
        .pv-muted { color: #6b7280; font-size: 13px; margin-top: 2px; }
        .pv-money { font-variant-numeric: tabular-nums; font-weight: 700; }
        .pv-status { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .is-approved { background: #d1fae5; color: #065f46; }
        .is-matched { background: #d1fae5; color: #065f46; }
        .is-rejected { background: #fee2e2; color: #991b1b; }
        .is-duplicate { background: #ffedd5; color: #9a3412; }
        .is-review { background: #fef3c7; color: #92400e; }
        .is-pending { background: #dbeafe; color: #1e40af; }
        .pv-match { font-size: 12px; font-weight: 600; }
        .pv-match.ok { color: #059669; }
        .pv-match.bad { color: #dc2626; }
        .pv-match.unknown { color: #6b7280; }
        .pv-confidence { display: inline-flex; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; }
        .confidence-high { background: #d1fae5; color: #065f46; }
        .confidence-review { background: #fef3c7; color: #92400e; }
        .confidence-low { background: #fee2e2; color: #991b1b; }
        .pv-empty { padding: 48px 24px; text-align: center; color: #6b7280; }
        .pv-pagination { display: flex; justify-content: space-between; align-items: center; padding: 16px; color: #6b7280; font-size: 13px; }
        .pv-pages { display: flex; gap: 6px; }
        .pv-page { min-width: 36px; height: 36px; display: grid; place-items: center; border: 1px solid #d1d5db; border-radius: 8px; color: #374151; background: #fff; text-decoration: none; font-weight: 600; font-size: 14px; transition: all 0.2s; }
        .pv-page:hover { border-color: #06A1A1; color: #06A1A1; }
        .pv-page.active { background: #06A1A1; color: #fff; border-color: #06A1A1; }
        .pv-overlay { position: fixed; inset: 0; z-index: 3000; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(4px); display: flex; justify-content: center; align-items: center; }
        .pv-drawer { width: min(1200px, 95vw); max-height: 90vh; background: #fff; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); overflow: hidden; display: flex; flex-direction: column; }
        .pv-drawer-head { padding: 20px 24px; background: #fff; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-shrink: 0; }
        .pv-detail-grid { display: grid; grid-template-columns: minmax(350px, 0.9fr) minmax(450px, 1.1fr); gap: 24px; padding: 24px; overflow-y: auto; flex: 1; }
        .pv-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; margin-bottom: 20px; }
        .pv-card-title { margin: 0 0 16px; font-size: 14px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #374151; }
        .pv-proof-large { width: 100%; max-height: 60vh; object-fit: contain; background: #fff; border-radius: 8px; border: 1px solid #e5e7eb; }
        .pv-proof-pdf { width: 100%; height: 60vh; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; }
        .pv-info-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .pv-info { padding: 12px; border-radius: 8px; background: #fff; border: 1px solid #e5e7eb; }
        .pv-info-label { display: block; color: #6b7280; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
        .pv-info-value { font-size: 14px; font-weight: 600; color: #1f2937; overflow-wrap: anywhere; }
        .pv-compare { display: grid; grid-template-columns: 1fr auto 1fr; gap: 16px; align-items: center; }
        .pv-compare-box { padding: 16px; border-radius: 10px; background: #fff; border: 1px solid #e5e7eb; text-align: center; }
        .pv-compare-value { font-size: 24px; font-weight: 700; font-variant-numeric: tabular-nums; color: #1f2937; }
        .pv-edit-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .pv-edit-field label { display: flex; justify-content: space-between; gap: 8px; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .pv-edit-field input, .pv-edit-field select, .pv-edit-field textarea { width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 8px; padding: 10px 12px; color: #374151; background: #fff; font-size: 14px; transition: border-color 0.2s, box-shadow 0.2s; }
        .pv-edit-field input:focus, .pv-edit-field select:focus, .pv-edit-field textarea:focus { outline: none; border-color: #06A1A1; box-shadow: 0 0 0 3px rgba(6, 161, 161, 0.1); }
        .pv-original { margin-top: 4px; font-size: 11px; color: #9ca3af; overflow-wrap: anywhere; }
        .pv-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb; }
        .pv-raw { white-space: pre-wrap; max-height: 250px; overflow: auto; background: #1f2937; color: #e5e7eb; border-radius: 8px; padding: 16px; font: 12px/1.6 ui-monospace, Consolas, monospace; }
        .pv-toast { position: fixed; right: 24px; bottom: 24px; z-index: 5000; max-width: 400px; padding: 16px 20px; border-radius: 10px; color: #fff; background: #1f2937; box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2); font-size: 14px; font-weight: 600; display: none; }
        @media(max-width: 1150px) { .pv-kpi-row { grid-template-columns: repeat(2, 1fr) } }
        @media(max-width: 820px) { .pv-main { padding: 16px 12px } .pv-header { align-items: flex-start; flex-direction: column } .pv-kpi-row { grid-template-columns: 1fr 1fr } .pv-detail-grid { grid-template-columns: 1fr } .pv-edit-grid, .pv-info-grid { grid-template-columns: 1fr } .pv-drawer { width: 95vw; max-height: 95vh } .pv-compare { grid-template-columns: 1fr } .pv-proof-large, .pv-proof-pdf { max-height: 50vh; height: 50vh } }
        @media(max-width: 520px) { .pv-kpi-row { grid-template-columns: 1fr } .pv-title { font-size: 20px } }
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
            <button class="pv-button light" type="button" id="pvRefreshQueue">Refresh Queue</button>
        </header>

        <?php if (!$schemaReady): ?>
            <div class="pv-panel pv-empty">Payment verification storage could not be initialized. Apply <strong>database/payment_verification_ocr_20260711.sql</strong> and refresh this page.</div>
        <?php else: ?>
        <section class="pv-kpi-row" aria-label="Payment verification totals">
            <div class="kpi-card blue" onclick="window.location.href='<?php echo pv_h($basePath); ?>/staff/payment_verification.php?<?php echo http_build_query(array_merge($filterState, ['status' => 'Pending Review'])); ?>'" style="cursor:pointer;">
                <span class="kpi-card-inner">
                    <span class="kpi-label">Pending OCR Review</span>
                    <span class="kpi-value"><?php echo number_format($kpis['pending']); ?></span>
                    <span class="kpi-sub">Awaiting processing</span>
                </span>
            </div>
            <div class="kpi-card emerald" onclick="window.location.href='<?php echo pv_h($basePath); ?>/staff/payment_verification.php?<?php echo http_build_query(array_merge($filterState, ['amount_match' => 'exact_match'])); ?>'" style="cursor:pointer;">
                <span class="kpi-card-inner">
                    <span class="kpi-label">Amount Matched</span>
                    <span class="kpi-value"><?php echo number_format($kpis['matched']); ?></span>
                    <span class="kpi-sub">Exact amount detected</span>
                </span>
            </div>
            <div class="kpi-card amber" onclick="window.location.href='<?php echo pv_h($basePath); ?>/staff/payment_verification.php?<?php echo http_build_query(array_merge($filterState, ['status' => 'Needs Review'])); ?>'" style="cursor:pointer;">
                <span class="kpi-card-inner">
                    <span class="kpi-label">Needs Attention</span>
                    <span class="kpi-value"><?php echo number_format($kpis['review']); ?></span>
                    <span class="kpi-sub">Requires staff review</span>
                </span>
            </div>
            <div class="kpi-card indigo" onclick="window.location.href='<?php echo pv_h($basePath); ?>/staff/payment_verification.php?<?php echo http_build_query(array_merge($filterState, ['status' => 'Approved'])); ?>'" style="cursor:pointer;">
                <span class="kpi-card-inner">
                    <span class="kpi-label">Approved</span>
                    <span class="kpi-value"><?php echo number_format($kpis['approved']); ?></span>
                    <span class="kpi-sub">Verified payments</span>
                </span>
            </div>
        </section>

        <section class="card overflow-visible" x-data="{
            sortOpen: false,
            filterOpen: false,
            sortOrder: 'newest',
            statusFilter: '<?php echo pv_h($status); ?>',
            methodFilter: '<?php echo pv_h($method); ?>',
            amountMatchFilter: '<?php echo pv_h($amountFilter); ?>',
            dateFromFilter: '<?php echo pv_h($dateFrom); ?>',
            dateToFilter: '<?php echo pv_h($dateTo); ?>',
            searchFilter: '<?php echo pv_h($search); ?>',
            applyFilters() {
                const params = new URLSearchParams();
                if (this.statusFilter) params.set('status', this.statusFilter);
                if (this.methodFilter) params.set('method', this.methodFilter);
                if (this.amountMatchFilter) params.set('amount_match', this.amountMatchFilter);
                if (this.dateFromFilter) params.set('date_from', this.dateFromFilter);
                if (this.dateToFilter) params.set('date_to', this.dateToFilter);
                if (this.searchFilter) params.set('search', this.searchFilter);
                window.location.href = '<?php echo pv_h($basePath); ?>/staff/payment_verification.php?' + params.toString();
            }
        }">
            <div class="toolbar-container" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div class="toolbar-group toolbar-group--title">
                    <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;white-space:nowrap;">Payment Submissions</h3>
                </div>
                <div class="toolbar-group toolbar-group--actions" style="margin-left: auto; display: flex; gap: 8px;">
                    <div style="position: relative;">
                        <button @click="sortOpen = !sortOpen" class="toolbar-btn" :class="sortOrder !== 'newest' ? 'active' : ''">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"/></svg>
                            <span class="toolbar-btn-label">Sort by</span>
                        </button>
                        <div x-show="sortOpen" @click.away="sortOpen = false" x-cloak class="dropdown-panel sort-dropdown" style="right: 0;">
                            <div class="sort-option" :class="sortOrder === 'newest' ? 'active' : ''" @click="sortOrder = 'newest'; sortOpen = false">
                                <span>Newest to Oldest</span>
                                <svg x-show="sortOrder === 'newest'" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" style="margin-left: auto; color: #0d9488;"><polyline points="20 6 9 17 4 12"/></svg>
                            </div>
                            <div class="sort-option" :class="sortOrder === 'oldest' ? 'active' : ''" @click="sortOrder = 'oldest'; sortOpen = false">
                                <span>Oldest to Newest</span>
                                <svg x-show="sortOrder === 'oldest'" width="14" height="14" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24" style="margin-left: auto; color: #0d9488;"><polyline points="20 6 9 17 4 12"/></svg>
                            </div>
                        </div>
                    </div>
                    <div style="position: relative;">
                        <button @click="filterOpen = !filterOpen; sortOpen = false" class="toolbar-btn" :class="(statusFilter !== '' || methodFilter !== '' || amountMatchFilter !== '' || dateFromFilter !== '' || dateToFilter !== '') ? 'active' : ''">
                            <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                            <span class="toolbar-btn-label-light">Filter</span>
                            <template x-if="statusFilter !== '' || methodFilter !== '' || amountMatchFilter !== '' || dateFromFilter !== '' || dateToFilter !== ''">
                                <span class="filter-badge" x-text="(statusFilter !== '' ? 1 : 0) + (methodFilter !== '' ? 1 : 0) + (amountMatchFilter !== '' ? 1 : 0) + (dateFromFilter !== '' ? 1 : 0) + (dateToFilter !== '' ? 1 : 0)"></span>
                            </template>
                        </button>
                        <div x-show="filterOpen" @click.away="filterOpen = false" x-cloak class="dropdown-panel filter-panel" style="right: 0; width: 320px;">
                            <div class="filter-header">Filter</div>
                            <div class="filter-section">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                    <span class="filter-label" style="margin:0;">Status</span>
                                    <button @click="statusFilter = ''" class="filter-reset-link">Reset</button>
                                </div>
                                <select x-model="statusFilter" class="filter-select">
                                    <option value="">All statuses</option>
                                    <?php foreach ($allowedStatuses as $option): ?><option value="<?php echo pv_h($option); ?>"><?php echo pv_h($option); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-section">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                    <span class="filter-label" style="margin:0;">Payment Method</span>
                                    <button @click="methodFilter = ''" class="filter-reset-link">Reset</button>
                                </div>
                                <select x-model="methodFilter" class="filter-select">
                                    <option value="">All methods</option>
                                    <?php foreach (['GCash','Maya','Bank Transfer','Bank Transfer / InstaPay','Bank Transfer / PESONet'] as $option): ?><option value="<?php echo pv_h($option); ?>"><?php echo pv_h($option); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-section">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                    <span class="filter-label" style="margin:0;">Amount Result</span>
                                    <button @click="amountMatchFilter = ''" class="filter-reset-link">Reset</button>
                                </div>
                                <select x-model="amountMatchFilter" class="filter-select">
                                    <option value="">Any result</option>
                                    <?php foreach ($allowedAmountFilters as $option): $label = ucwords(str_replace('_', ' ', $option)); ?><option value="<?php echo pv_h($option); ?>"><?php echo pv_h($label); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-section">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                    <span class="filter-label" style="margin:0;">Date Range</span>
                                    <button @click="dateFromFilter = ''; dateToFilter = ''" class="filter-reset-link">Reset</button>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                    <input type="date" x-model="dateFromFilter" class="filter-input" placeholder="From">
                                    <input type="date" x-model="dateToFilter" class="filter-input" placeholder="To">
                                </div>
                            </div>
                            <div class="filter-section">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                    <span class="filter-label" style="margin:0;">Search</span>
                                    <button @click="searchFilter = ''" class="filter-reset-link">Reset</button>
                                </div>
                                <input type="text" x-model="searchFilter" class="filter-input" placeholder="Customer, order, sender, or reference">
                            </div>
                            <div class="filter-footer">
                                <button @click="statusFilter = ''; methodFilter = ''; amountMatchFilter = ''; dateFromFilter = ''; dateToFilter = ''; searchFilter = ''; filterOpen = false" class="filter-clear-btn">Clear All</button>
                                <button @click="applyFilters(); filterOpen = false" class="filter-apply-btn">Apply Filters</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($queueError !== ''): ?>
                <div class="pv-empty" role="alert"><strong>Payment queue unavailable.</strong><div class="pv-muted"><?php echo pv_h($queueError); ?></div></div>
            <?php elseif (empty($submissions)): ?>
                <div class="pv-empty"><strong>No payment submissions found.</strong><div class="pv-muted">New and imported payment proofs will appear here.</div></div>
            <?php else: ?>
            <div class="pv-table-wrap">
                <table class="pv-table">
                    <thead><tr><th>Order / Customer</th><th>Sender / Reference</th><th>Payment Method</th><th>Transaction</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($submissions as $submission):
                        $sender = pv_effective($submission, 'sender_name', 'ocr_sender_name');
                        $reference = pv_effective($submission, 'reference_number', 'ocr_reference_number');
                        $detectedMethod = pv_effective($submission, 'detected_payment_method', 'ocr_detected_payment_method');
                        $amountRaw = payment_verification_effective_value($submission, 'amount_sent', 'ocr_amount_sent');
                        $amount = ($amountRaw === null || $amountRaw === '') ? null : (float)$amountRaw;
                        $confidence = (float)($submission['overall_confidence'] ?? 0);
                        $amountResult = payment_verification_amount_result($submission);
                        $amountResultLabel = ucwords(str_replace('_', ' ', $amountResult));
                        $previewPath = (string)($submission['receipt_thumbnail'] ?: $submission['receipt_file']);
                        $isPdf = strtolower((string)$submission['receipt_mime']) === 'application/pdf' || preg_match('/\.pdf(?:$|\?)/i', (string)$submission['receipt_file']);
                        $viewQuery = array_merge($filterState, ['page' => $page, 'submission_id' => (int)$submission['id']]);
                    ?>
                    <tr>
                        <td><div class="pv-mainline"><?php echo pv_h(payment_verification_order_label($submission)); ?></div><div class="pv-muted"><?php echo pv_h($submission['customer_name'] ?: 'Customer'); ?></div></td>
                        <td><div class="pv-mainline"><?php echo pv_h($sender !== '' ? $sender : 'Not detected'); ?></div><div class="pv-muted">Ref: <?php echo pv_h($reference !== '' ? $reference : 'Not detected'); ?></div></td>
                        <td><div class="pv-mainline"><?php echo pv_h($detectedMethod !== '' ? $detectedMethod : 'Not detected'); ?></div><div class="pv-muted">Selected: <?php echo pv_h($submission['selected_payment_method'] ?: 'Unknown'); ?></div></td>
                        <td><div class="pv-mainline"><?php echo pv_h(pv_effective($submission, 'transaction_date', 'ocr_transaction_date') ?: 'Date unknown'); ?></div><div class="pv-muted"><?php echo pv_h(pv_effective($submission, 'transaction_time', 'ocr_transaction_time') ?: 'Time unknown'); ?></div></td>
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
    $detailAmountResult = payment_verification_amount_result($detail);
    $detailAmountResultLabel = ucwords(str_replace('_', ' ', $detailAmountResult));
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
                <div class="pv-card"><h2 class="pv-card-title">Order Information</h2><div class="pv-info-grid"><div class="pv-info"><span class="pv-info-label">Order Code</span><span class="pv-info-value"><?php echo pv_h(payment_verification_order_label($detail)); ?></span></div><div class="pv-info"><span class="pv-info-label">Customer</span><span class="pv-info-value"><?php echo pv_h($detail['customer_name'] ?: 'Customer'); ?></span></div><div class="pv-info"><span class="pv-info-label">Order Total</span><span class="pv-info-value"><?php echo pv_h(format_currency((float)$detail['expected_amount'])); ?></span></div><div class="pv-info"><span class="pv-info-label">Selected Method</span><span class="pv-info-value"><?php echo pv_h($detail['selected_payment_method'] ?: 'Unknown'); ?></span></div><div class="pv-info"><span class="pv-info-label">Order Status</span><span class="pv-info-value"><?php echo pv_h($detail['order_status'] ?: $detail['job_status']); ?></span></div><div class="pv-info"><span class="pv-info-label">Payment Status</span><span class="pv-info-value"><?php echo pv_h($detail['order_payment_status'] ?: $detail['job_payment_status']); ?></span></div><?php if (!empty($detail['job_title']) || !empty($detail['service_type'])): ?><div class="pv-info"><span class="pv-info-label">Job / Service</span><span class="pv-info-value"><?php echo pv_h(trim((string)($detail['job_title'] ?: $detail['service_type']))); ?></span></div><?php endif; ?></div><?php if ($detailItems): ?><div style="margin-top:12px;"><?php foreach ($detailItems as $item): ?><div class="pv-info" style="margin-top:7px;"><span class="pv-info-label">Order Item</span><span class="pv-info-value"><?php echo pv_h($item['item_name']); ?> × <?php echo (int)$item['quantity']; ?> — <?php echo pv_h(format_currency((float)$item['unit_price'] * (int)$item['quantity'])); ?></span></div><?php endforeach; ?></div><?php endif; ?></div>
            </div>
            <div>
                <div class="pv-card"><h2 class="pv-card-title">Amount Comparison</h2><div class="pv-compare"><div class="pv-compare-box"><span class="pv-info-label">Expected Amount</span><div class="pv-compare-value"><?php echo pv_h(format_currency((float)$detail['expected_amount'])); ?></div></div><span class="pv-status <?php echo $detailAmountResult === 'exact_match' ? 'is-matched' : (in_array($detailAmountResult, ['underpaid','overpaid'], true) ? 'is-rejected' : 'is-pending'); ?>"><?php echo pv_h($detailAmountResultLabel); ?></span><div class="pv-compare-box"><span class="pv-info-label">OCR Detected</span><div class="pv-compare-value"><?php echo $detailAmount === null ? 'Unknown' : pv_h(format_currency($detailAmount)); ?></div></div></div></div>

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
                        <button class="pv-button light" type="button" onclick="pvSaveCorrections()" <?php echo $isFinal ? 'disabled' : ''; ?>>Save Manual Review</button>
                        <button class="pv-button light" type="button" onclick="pvApprove()" <?php echo $isFinal ? 'disabled' : ''; ?>>Approve Payment</button>
                        <button class="pv-button danger" type="button" onclick="pvReject()" <?php echo $isFinal ? 'disabled' : ''; ?>>Reject / Request Resubmission</button>
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
    const refreshButton = document.getElementById('pvRefreshQueue');
    if (refreshButton) {
        refreshButton.addEventListener('click', async function () {
            const originalLabel = refreshButton.textContent;
            refreshButton.disabled = true;
            refreshButton.textContent = 'Refreshing…';
            try {
                const queueData = new FormData();
                queueData.set('action', 'process_queue');
                await post(base + '/staff/api_payment_verification.php', queueData);
                const probe = await fetch(base + '/staff/api_payment_verification.php?action=queue_snapshot', {
                    method: 'GET', credentials: 'same-origin', cache: 'no-store',
                    headers: {'X-Requested-With':'XMLHttpRequest'}
                });
                const payload = await probe.json().catch(() => ({}));
                if (!probe.ok || !payload.success) throw new Error(payload.error || 'The refreshed queue could not be loaded.');
                window.location.reload();
            } catch (error) {
                refreshButton.disabled = false;
                refreshButton.textContent = originalLabel;
                toast(error.message || 'Refresh failed. The current queue was not changed.', true);
            }
        });
    }
})();
</script>
</body>
</html>
