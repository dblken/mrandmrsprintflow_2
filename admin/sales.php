<?php
/**
 * PrintFlow Sales Management
 * Dedicated sales management page using the shared official report sales rule.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/branch_ui.php';
require_once __DIR__ . '/../includes/reports_dashboard_queries.php';
require_once __DIR__ . '/../includes/sales_page_queries.php';

require_role(['Admin', 'Manager']);

if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

$current_user = get_logged_in_user();
$is_manager = (($current_user['role'] ?? '') === 'Manager');

$branchCtx = init_branch_context(false);
$branchId = $branchCtx['selected_branch_id'];
$branchName = $branchCtx['branch_name'];
if ($is_manager) {
    $forcedBranch = (int)(printflow_branch_filter_for_user() ?? ($_SESSION['branch_id'] ?? 0));
    if ($forcedBranch > 0) {
        $branchId = $forcedBranch;
        $branchCtx['selected_branch_id'] = $branchId;
        foreach (($branchCtx['branches_list'] ?? []) as $b) {
            if ((int)($b['id'] ?? 0) === $branchId) {
                $branchCtx['branch_name'] = $b['branch_name'];
                $branchName = $b['branch_name'];
                break;
            }
        }
    }
}

$sales_href_base = rtrim(AUTH_REDIRECT_BASE, '/') . '/admin/sales.php';
function sales_page_query(array $overrides = []): string {
    $keys = ['from', 'to', 'branch_id', 'sales_period', 'type', 'method', 'item', 'filter_open', 'scroll_y'];
    $q = [];
    foreach ($keys as $key) {
        if (array_key_exists($key, $overrides)) {
            if ($overrides[$key] !== null) {
                $q[$key] = $overrides[$key];
            }
        } elseif (isset($_GET[$key])) {
            $q[$key] = $_GET[$key];
        }
    }
    return http_build_query($q);
}

function sales_type_pill_class(?string $type): string {
    return strtolower(trim((string)$type)) === 'service'
        ? ' sales-breakdown-pill-service'
        : ' sales-breakdown-pill-product';
}

function sales_format_label(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') return 'â€”';
    return ucwords(strtolower(str_replace(['_', '-'], ' ', $value)));
}

function sales_method_filter_key(?string $value): string {
    return pf_sales_method_filter_key($value);
}

function sales_method_display(?string $value): string {
    $key = sales_method_filter_key($value);
    if ($key === 'cash') return 'Cash';
    if ($key === 'qrph') return 'QR Ph';
    return sales_format_label($value);
}

$salesPeriodInfo = pf_sales_resolve_period($_GET);
$sales_period = $salesPeriodInfo['period'];
$sales_from = $salesPeriodInfo['from'];
$sales_to = $salesPeriodInfo['to'];
$sales_to_end = $salesPeriodInfo['to_end'];
$sales_label = $salesPeriodInfo['label'];

$salesFilters = pf_sales_filters_from_request($_GET);
$salesTypeFilter = $salesFilters['type'];
$salesMethodFilter = $salesFilters['method'];
$salesItemFilter = $salesFilters['item'];

$branchEmpty = !pf_reports_branch_has_activity($branchId);
$salesData = !$branchEmpty
    ? pf_reports_official_sales_breakdown($sales_from, $sales_to_end, $branchId, 5000)
    : ['summary' => [], 'by_branch' => [], 'by_item' => [], 'transactions' => []];

$allItems = $salesData['by_item'] ?? [];
$salesData['transactions'] = pf_sales_filter_transactions($salesData['transactions'] ?? [], $salesFilters);
$itemTotals = [];
foreach ($salesData['transactions'] as $row) {
    $typeLabel = strtolower(trim((string)($row['type'] ?? ''))) === 'service' ? 'Service' : 'Product';
    $itemName = trim((string)($row['item_name'] ?? '')) ?: ($typeLabel === 'Service' ? 'Customization' : 'Product Order');
    $key = strtolower($typeLabel) . '|' . $itemName;
    if (!isset($itemTotals[$key])) {
        $itemTotals[$key] = ['type' => $typeLabel, 'item_name' => $itemName, 'quantity' => 0, 'revenue' => 0.0];
    }
    $itemTotals[$key]['quantity'] += 1;
    $itemTotals[$key]['revenue'] += (float)($row['amount'] ?? 0);
}
foreach ($itemTotals as &$itemRow) {
    $itemRow['revenue'] = round((float)$itemRow['revenue'], 2);
}
unset($itemRow);
$salesData['by_item'] = array_values($itemTotals);
usort($salesData['by_item'], static fn($a, $b) => (($b['revenue'] ?? 0) <=> ($a['revenue'] ?? 0)));

$salesSummary = pf_sales_summary_from_transactions($salesData['transactions']);
$branchTotals = [];
foreach ($salesData['transactions'] as $row) {
    $amount = (float)($row['amount'] ?? 0);
    $branch = trim((string)($row['branch_name'] ?? '')) ?: 'â€”';
    if (!isset($branchTotals[$branch])) $branchTotals[$branch] = ['branch_name' => $branch, 'transaction_count' => 0, 'total_sales' => 0.0];
    $branchTotals[$branch]['transaction_count']++;
    $branchTotals[$branch]['total_sales'] += $amount;
}
foreach ($branchTotals as &$branchRow) {
    $branchRow['total_sales'] = round((float)$branchRow['total_sales'], 2);
}
unset($branchRow);
$salesData['by_branch'] = array_values($branchTotals);
$salesFilterCount = (int)($sales_period === 'custom') + (int)($salesTypeFilter !== 'all') + (int)($salesMethodFilter !== 'all') + (int)($salesItemFilter !== '');
$salesBranchParam = printflow_branch_value_is_all($branchId) ? 'all' : (string)(int)$branchId;
$salesFilterOpen = ($_GET['filter_open'] ?? '') === '1';

function sales_export_url(string $file, array $extra = []): string {
    global $base_path, $sales_from, $sales_to, $salesBranchParam, $sales_period, $salesTypeFilter, $salesMethodFilter, $salesItemFilter;
    $params = array_merge([
        'from' => $sales_from,
        'to' => $sales_to,
        'branch_id' => $salesBranchParam,
        'sales_period' => $sales_period,
    ], $extra);
    if ($salesTypeFilter !== 'all') {
        $params['type'] = $salesTypeFilter;
    }
    if ($salesMethodFilter !== 'all') {
        $params['method'] = $salesMethodFilter;
    }
    if ($salesItemFilter !== '') {
        $params['item'] = $salesItemFilter;
    }
    return rtrim($base_path, '/') . '/admin/' . $file . '?' . http_build_query($params);
}

$salesPeriodLabel = date('M d, Y', strtotime($sales_from));
if ($sales_from !== $sales_to) {
    $salesPeriodLabel = date('M d, Y', strtotime($sales_from)) . ' – ' . date('M d, Y', strtotime($sales_to));
}
$salesToolbarSummary = $salesPeriodLabel . ' (' . $sales_label . ')';
$printSalesUrl = sales_export_url('reports_print.php', ['report' => 'sales']);
$csvSalesUrl = sales_export_url('reports_export.php', ['report' => 'sales']);
$xlsxSalesUrl = sales_export_url('reports_export_excel.php', ['report' => 'sales']);
$je = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

$page_title = 'Sales Management - Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($page_title); ?></title>
<?php require_once __DIR__ . '/../includes/favicon_links.php'; ?>
<link rel="stylesheet" href="<?php echo $base_path; ?>/public/assets/css/output.css">
<?php include __DIR__ . '/../includes/admin_style.php'; ?>
<?php render_branch_css(); ?>
<script>
function salesPrintInPlace(url) {
    const iframe = document.createElement('iframe');
    iframe.style.cssText = 'position:absolute;width:0;height:0;border:0;visibility:hidden';
    document.body.appendChild(iframe);
    iframe.onload = function () {
        try {
            iframe.contentWindow.focus();
            iframe.contentWindow.print();
        } catch (e) { console.error(e); }
        setTimeout(function () { iframe.remove(); }, 1000);
    };
    iframe.src = url;
}
</script>
<style>
[x-cloak] { display:none !important; }
.ana-wrap { display:flex; flex-direction:column; gap:24px; }
.ana-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:visible; box-shadow:0 1px 3px rgba(0,0,0,.05); transition:box-shadow .2s; display:flex; flex-direction:column; height:100%; }
.ana-card:hover { box-shadow:0 4px 12px rgba(0,0,0,.08); }
.ana-hd { display:flex; align-items:center; justify-content:space-between; padding:18px 20px; border-bottom:1px solid #f3f4f6; gap:10px; flex-wrap:wrap; flex-shrink:0; }
.ana-hd h3 { margin:0; font-size:14px; font-weight:700; color:#1f2937; display:flex; align-items:center; gap:8px; white-space:nowrap; }
.ana-hd h3 svg { width:16px; height:16px; color:#53C5E0; flex-shrink:0; }
.ana-bd { padding:20px; flex:1; display:flex; flex-direction:column; min-height:0; }
.chart-title-nowrap { min-width:0; }
.toolbar-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; height:38px; padding:7px 14px; border:1px solid #e5e7eb; border-radius:8px; background:#fff; color:#374151; font-size:13px; font-weight:500; cursor:pointer; transition:all .2s; text-decoration:none; white-space:nowrap; }
.toolbar-btn:hover { border-color:#9ca3af; background:#f9fafb; }
.toolbar-btn.active { border-color:#0d9488; color:#0d9488; background:#f0fdfa; }
.sales-toolbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:22px; }
.sales-toolbar-summary { font-size:13px; color:#6b7280; }
.sales-toolbar-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.kpi-row { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px; align-items:stretch; }
.kpi-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; position:relative; overflow:hidden; height:100%; display:flex; flex-direction:column; }
.kpi-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; }
.kpi-card.indigo::before { background:linear-gradient(90deg,#6366f1,#818cf8); }
.kpi-card.emerald::before { background:linear-gradient(90deg,#059669,#34d399); }
.kpi-card.rose::before { background:linear-gradient(90deg,#e11d48,#fb7185); }
.kpi-card.slate::before { background:linear-gradient(90deg,#64748b,#94a3b8); }
.kpi-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#9ca3af; margin-bottom:6px; }
.kpi-value { font-size:26px; font-weight:800; color:#111827; line-height:1.15; }
.kpi-sub { font-size:12px; color:#6b7280; margin-top:auto; }
@media(max-width:900px){ .kpi-row{ grid-template-columns:repeat(2,1fr); } }
.sales-list-header { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:20px; min-width:0; flex-wrap:wrap; }
.sales-list-header h3 { margin:0; font-size:16px; font-weight:700; color:#1f2937; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.sales-section-title { margin:0 0 12px; font-size:14px; font-weight:700; color:#1f2937; }
.sales-breakdown-tabs { display:flex; flex-wrap:wrap; gap:8px; }
.sales-breakdown-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; margin-bottom:20px; }
.sales-breakdown-metric { border:1px solid #e5e7eb; border-radius:10px; padding:20px 22px; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.sales-breakdown-metric-label { font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
.sales-breakdown-metric-value { margin-top:10px; font-size:28px; line-height:1.1; font-weight:900; color:#0f172a; }
.sales-breakdown-split { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:20px; margin-bottom:20px; }
.sales-breakdown-panel { border:1px solid #eef2f7; border-radius:10px; padding:16px; min-width:0; background:#fff; }
.sales-breakdown-table { width:100%; border-collapse:collapse; font-size:14px; }
.sales-breakdown-table th { text-align:left; color:#64748b; font-weight:600; font-size:13px; padding:12px 8px; border-bottom:1px solid #e5e7eb; }
.sales-breakdown-table td { padding:14px 8px; border-bottom:1px solid #f1f5f9; color:#111827; vertical-align:middle; }
.sales-breakdown-table .num { text-align:right; font-weight:600; color:#0f172a; }
.sales-txn-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
.sales-txn-table { min-width:1080px; table-layout:fixed; }
.sales-txn-table th,
.sales-txn-table td { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sales-txn-table .sales-breakdown-pill { max-width:100%; overflow:hidden; text-overflow:ellipsis; }
.sales-breakdown-pill { display:inline-flex; align-items:center; justify-content:center; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:600; background:#ecfdf5; color:#047857; }
.sales-breakdown-empty { min-height:110px; display:flex; align-items:center; justify-content:center; color:#64748b; font-size:13px; border:1px dashed #d1d5db; border-radius:10px; background:#fff; text-align:center; }
.filter-panel { position:absolute; top:calc(100% + 6px); right:0; width:320px; max-height:min(560px,calc(100vh - 120px)); overflow-y:auto; background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.12); z-index:200; }
.filter-panel-header { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid #f3f4f6; font-size:14px; font-weight:700; color:#111827; }
.filter-panel-close { border:0; background:transparent; color:#374151; cursor:pointer; width:28px; height:28px; display:inline-flex; align-items:center; justify-content:center; border-radius:8px; }
.filter-panel-close:hover { background:#f3f4f6; }
.filter-section { padding:14px 18px; border-bottom:1px solid #f3f4f6; }
.filter-section-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
.filter-section-label { font-size:13px; font-weight:600; color:#374151; }
.filter-reset-link { font-size:12px; font-weight:600; color:#0d9488; cursor:pointer; background:none; border:0; padding:0; }
.filter-date-row { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.filter-date-label { font-size:11px; color:#6b7280; margin-bottom:4px; }
.filter-input { width:100%; height:34px; border:1px solid #e5e7eb; border-radius:7px; font-size:13px; padding:0 10px; color:#1f2937; box-sizing:border-box; }
.filter-input:focus { outline:none; border-color:#0d9488; }
.fp-preset-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:8px; }
.fp-preset-btn { height:34px; border:1px solid #e5e7eb; border-radius:7px; background:#fff; color:#374151; font-size:12px; font-weight:500; cursor:pointer; }
.fp-preset-btn:hover,.fp-preset-btn.active { border-color:#00232b; background:#ecf8fb; color:#00232b; font-weight:700; }
.filter-actions { padding:14px 18px; }
.filter-btn-reset { width:100%; height:36px; border:1px solid #e5e7eb; background:#fff; border-radius:8px; font-size:13px; font-weight:500; color:#374151; cursor:pointer; }
.filter-btn-reset:hover { background:#f9fafb; }
.filter-badge { display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; background:#0d9488; color:#fff; border-radius:50%; font-size:10px; font-weight:700; }
.sales-breakdown-pill-product { background:#ecfdf5; color:#047857; }
.sales-breakdown-pill-service { background:#eff6ff; color:#1d4ed8; }
.sales-breakdown-total-row td { background:#f8fafc; border-top:1px solid #e5e7eb; border-bottom:0; font-weight:800; color:#0f172a; }
.sort-dropdown { position:absolute; top:calc(100% + 6px); right:0; background:#fff; border:1px solid #e5e7eb; box-shadow:0 10px 30px rgba(0,0,0,.12); z-index:200; border-radius:10px; overflow:hidden; }
.export-dropdown-wide { min-width:260px; max-height:min(70vh,480px); overflow-y:auto; }
.export-dd-label { padding:10px 16px 4px; font-size:10px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.06em; }
.export-dd-hr { height:1px; background:#f3f4f6; margin:6px 12px; border:0; }
.export-dd-link { display:block; padding:9px 16px; font-size:13px; color:#374151; text-decoration:none; }
.export-dd-link:hover { background:#f9fafb; }
.sort-option { display:flex; align-items:center; width:100%; border:none; background:none; cursor:pointer; font-size:13px; font-family:inherit; font-weight:500; text-align:left; padding:9px 16px; color:#374151; }
.sort-option:hover { background:#f9fafb; }
@media(max-width:520px){ .filter-panel{right:auto;left:0;width:min(320px,calc(100vw - 48px));} .fp-preset-grid{grid-template-columns:1fr 1fr;} }
@media(max-width:960px){ .sales-breakdown-grid,.sales-breakdown-split{ grid-template-columns:1fr; } .ana-hd{align-items:flex-start;} .sales-toolbar{align-items:flex-start;} }
</style>
</head>
<body>
<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . (($current_user['role'] ?? '') === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>
    <div class="main-content">
        <header class="pf-mobile-branch-inline">
            <h1 class="page-title">Sales</h1>
            <?php if (!defined('MANAGER_PANEL') || !MANAGER_PANEL) { render_branch_selector($branchCtx); } ?>
        </header>
        <main>
            <?php render_branch_context_banner($branchCtx['branch_name']); ?>

            <div class="sales-toolbar no-print" x-data="{ filterOpen: <?php echo $salesFilterOpen ? 'true' : 'false'; ?>, exportOpen: false }">
                <div class="sales-toolbar-summary">
                    <?php echo htmlspecialchars($branchName); ?> &nbsp;&middot;&nbsp; <?php echo htmlspecialchars($salesToolbarSummary); ?>
                </div>
                <div class="sales-toolbar-actions">
                    <div style="position:relative;">
                        <button type="button" class="toolbar-btn <?php echo $salesFilterCount > 0 ? 'active' : ''; ?>" id="salesFilterToggle" style="height:38px;" @click="filterOpen = !filterOpen; exportOpen = false">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                            Filter
                            <?php if ($salesFilterCount > 0): ?><span class="filter-badge"><?php echo (int)$salesFilterCount; ?></span><?php endif; ?>
                        </button>
                        <div class="filter-panel" id="salesFilterPanel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false">
                            <div class="filter-panel-header">
                                <span>Filter</span>
                                <button type="button" class="filter-panel-close" id="salesFilterClose" aria-label="Close filter" @click="filterOpen = false">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                </button>
                            </div>
                            <form method="GET" id="salesFilterForm">
                                <input type="hidden" name="branch_id" value="<?php echo htmlspecialchars($salesBranchParam); ?>">
                                <input type="hidden" name="sales_period" id="salesPeriodInput" value="<?php echo htmlspecialchars($sales_period); ?>">
                                <input type="hidden" name="filter_open" value="1">
                                <input type="hidden" name="scroll_y" id="salesScrollY" value="<?php echo htmlspecialchars((string)($_GET['scroll_y'] ?? '')); ?>">
                                <div class="filter-section">
                                    <div class="filter-section-head"><span class="filter-section-label">Period</span></div>
                                    <div class="fp-preset-grid">
                                        <button type="button" class="fp-preset-btn <?php echo $sales_period === 'today' ? 'active' : ''; ?>" data-period="today">Today</button>
                                        <button type="button" class="fp-preset-btn <?php echo $sales_period === 'week' ? 'active' : ''; ?>" data-period="week">This Week</button>
                                        <button type="button" class="fp-preset-btn <?php echo $sales_period === 'month' ? 'active' : ''; ?>" data-period="month">This Month</button>
                                    </div>
                                </div>
                                <div class="filter-section">
                                    <div class="filter-section-head">
                                        <span class="filter-section-label">Date range</span>
                                        <button type="button" class="filter-reset-link" id="salesResetDates">Reset</button>
                                    </div>
                                    <div class="filter-date-row">
                                        <div>
                                            <div class="filter-date-label">From:</div>
                                            <input type="date" name="from" id="salesFilterFrom" class="filter-input" value="<?php echo htmlspecialchars($sales_from); ?>">
                                        </div>
                                        <div>
                                            <div class="filter-date-label">To:</div>
                                            <input type="date" name="to" id="salesFilterTo" class="filter-input" value="<?php echo htmlspecialchars($sales_to); ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="filter-section">
                                    <div class="filter-section-head"><span class="filter-section-label">Sales type</span></div>
                                    <select name="type" class="filter-input" onchange="submitSalesFilter(this.form)">
                                        <option value="all" <?php echo $salesTypeFilter === 'all' ? 'selected' : ''; ?>>All types</option>
                                        <option value="product" <?php echo $salesTypeFilter === 'product' ? 'selected' : ''; ?>>Product</option>
                                        <option value="service" <?php echo $salesTypeFilter === 'service' ? 'selected' : ''; ?>>Service</option>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <div class="filter-section-head"><span class="filter-section-label">Product / Service</span></div>
                                    <select name="item" class="filter-input" onchange="submitSalesFilter(this.form)">
                                        <option value="" <?php echo $salesItemFilter === '' ? 'selected' : ''; ?>>All products/services</option>
                                        <?php foreach ($allItems as $itemRow): $itemKey = strtolower(trim((string)($itemRow['type'] ?? ''))) . '|' . trim((string)($itemRow['item_name'] ?? '')); ?>
                                            <option value="<?php echo htmlspecialchars($itemKey); ?>" <?php echo $salesItemFilter === $itemKey ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$itemRow['type'] . ' - ' . (string)$itemRow['item_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="filter-section">
                                    <div class="filter-section-head"><span class="filter-section-label">Payment method</span></div>
                                    <select name="method" class="filter-input" onchange="submitSalesFilter(this.form)">
                                        <option value="all" <?php echo $salesMethodFilter === 'all' ? 'selected' : ''; ?>>All methods</option>
                                        <option value="cash" <?php echo $salesMethodFilter === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                        <option value="qrph" <?php echo $salesMethodFilter === 'qrph' ? 'selected' : ''; ?>>QR Ph</option>
                                    </select>
                                </div>
                                <div class="filter-actions">
                                    <button type="button" class="filter-btn-reset" id="salesResetFilter">Reset</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div style="position:relative;">
                        <button type="button" class="toolbar-btn" @click="exportOpen = !exportOpen; filterOpen = false" style="height:38px;">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Export
                        </button>
                        <div class="sort-dropdown export-dropdown-wide" x-show="exportOpen" x-cloak @click.outside="exportOpen = false">
                            <div class="export-dd-label" style="display:flex;justify-content:space-between;align-items:center;">
                                Reporting Period
                                <span style="text-transform:none;font-weight:600;color:#4b5563;font-size:11px;"><?php echo htmlspecialchars($salesPeriodLabel); ?></span>
                            </div>
                            <hr class="export-dd-hr" style="margin:4px 12px 8px;">
                            <div class="export-dd-label">Print</div>
                            <button type="button" class="sort-option" style="font-weight:600;color:#111827;" @click="salesPrintInPlace(<?php echo json_encode($printSalesUrl, $je); ?>); exportOpen = false">Print Sales Report</button>
                            <hr class="export-dd-hr">
                            <div class="export-dd-label">Excel</div>
                            <a class="export-dd-link" href="<?php echo htmlspecialchars($xlsxSalesUrl, ENT_QUOTES, 'UTF-8'); ?>" @click="exportOpen = false">Excel – Sales detail</a>
                            <hr class="export-dd-hr">
                            <div class="export-dd-label">CSV</div>
                            <a class="export-dd-link" href="<?php echo htmlspecialchars($csvSalesUrl, ENT_QUOTES, 'UTF-8'); ?>" @click="exportOpen = false">CSV – Sales detail</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="kpi-row">
                <div class="kpi-card indigo">
                    <div class="kpi-label">Total Sales</div>
                    <div class="kpi-value">&#8369;<?php echo number_format((float)($salesSummary['total_sales'] ?? 0), 2); ?></div>
                    <div class="kpi-sub"><?php echo htmlspecialchars($sales_label); ?> sales revenue</div>
                </div>
                <div class="kpi-card emerald">
                    <div class="kpi-label">Sales Transactions</div>
                    <div class="kpi-value"><?php echo number_format((int)($salesSummary['transaction_count'] ?? 0)); ?></div>
                    <div class="kpi-sub">Sales transactions in period</div>
                </div>
                <div class="kpi-card rose">
                    <div class="kpi-label">Product Sales</div>
                    <div class="kpi-value">&#8369;<?php echo number_format((float)($salesSummary['product_sales'] ?? 0), 2); ?></div>
                    <div class="kpi-sub">Store product revenue</div>
                </div>
                <div class="kpi-card slate">
                    <div class="kpi-label">Service/Custom Sales</div>
                    <div class="kpi-value">&#8369;<?php echo number_format((float)($salesSummary['service_sales'] ?? 0), 2); ?></div>
                    <div class="kpi-sub">Customization revenue</div>
                </div>
            </div>

            <div class="card">
                <div class="sales-list-header">
                    <h3>
                        <svg width="16" height="16" fill="none" stroke="#53C5E0" viewBox="0 0 24 24" style="flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/></svg>
                        Sales Overview
                        <span style="padding:3px 8px;background:#EBF8FF;color:#2C5282;border-radius:6px;font-size:11px;font-weight:600;"><?php echo htmlspecialchars($sales_label); ?></span>
                    </h3>
                </div>

                <div class="sales-breakdown-split">
                        <div class="sales-breakdown-panel">
                            <h4 class="sales-section-title">Sales by Branch</h4>
                            <?php if (empty($salesData['by_branch'])): ?>
                                <div class="sales-breakdown-empty">No branch sales for this period.</div>
                            <?php else: ?>
                                <table class="sales-breakdown-table"><thead><tr><th>Branch</th><th class="num">Transactions</th><th class="num">Sales</th></tr></thead><tbody>
                                <?php foreach ($salesData['by_branch'] as $row): ?>
                                    <tr><td><?php echo htmlspecialchars((string)$row['branch_name']); ?></td><td class="num"><?php echo number_format((int)$row['transaction_count']); ?></td><td class="num">&#8369;<?php echo number_format((float)$row['total_sales'], 2); ?></td></tr>
                                <?php endforeach; ?>
                                </tbody></table>
                            <?php endif; ?>
                        </div>
                        <div class="sales-breakdown-panel">
                            <h4 class="sales-section-title">Sales by Product/Service</h4>
                            <?php if (empty($salesData['by_item'])): ?>
                                <div class="sales-breakdown-empty">No product or service sales for this period.</div>
                            <?php else: ?>
                                <table class="sales-breakdown-table"><thead><tr><th>Type</th><th>Item</th><th class="num">Sales</th></tr></thead><tbody>
                                <?php foreach (array_slice($salesData['by_item'], 0, 12) as $row): ?>
                                    <tr><td><span class="sales-breakdown-pill<?php echo sales_type_pill_class($row['type'] ?? ''); ?>"><?php echo htmlspecialchars((string)$row['type']); ?></span></td><td><?php echo htmlspecialchars((string)$row['item_name']); ?></td><td class="num">&#8369;<?php echo number_format((float)$row['revenue'], 2); ?></td></tr>
                                <?php endforeach; ?>
                                </tbody></table>
                            <?php endif; ?>
                        </div>
                    </div>
                <div class="sales-breakdown-panel" style="margin-top:20px;">
                        <h4 class="sales-section-title">Transaction Details</h4>
                    <?php if (empty($salesData['transactions'])): ?>
                        <div class="sales-breakdown-empty">No sales transactions for this period.</div>
                    <?php else: ?>
                        <div class="sales-txn-wrap">
                            <table class="sales-breakdown-table sales-txn-table">
                                <thead><tr><th>Date</th><th>Type</th><th>Item</th><th>Order</th><th>Customer</th><th>Branch</th><th>Payment</th><th>Method</th><th>Status</th><th class="num">Amount</th></tr></thead>
                                <tbody>
                                <?php foreach ($salesData['transactions'] as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)$row['sales_date']))); ?></td>
                                        <td><span class="sales-breakdown-pill<?php echo sales_type_pill_class($row['type'] ?? ''); ?>"><?php echo htmlspecialchars((string)$row['type']); ?></span></td>
                                        <td><?php echo htmlspecialchars((string)($row['item_name'] ?? '-')); ?></td>
                                        <td>#<?php echo (int)$row['id']; ?></td>
                                        <td><?php echo htmlspecialchars((string)$row['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$row['branch_name']); ?></td>
                                        <td><?php echo htmlspecialchars(sales_format_label($row['payment_status'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars(sales_method_display($row['payment_method'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars(sales_format_label($row['status'] ?? '')); ?></td>
                                        <td class="num">&#8369;<?php echo number_format((float)$row['amount'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot><tr class="sales-breakdown-total-row"><td colspan="9">Total Amount</td><td class="num">&#8369;<?php echo number_format((float)($salesSummary['total_sales'] ?? 0), 2); ?></td></tr></tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('salesFilterForm');
    const from = document.getElementById('salesFilterFrom');
    const to = document.getElementById('salesFilterTo');
    const resetDates = document.getElementById('salesResetDates');
    const periodInput = document.getElementById('salesPeriodInput');
    const resetFilter = document.getElementById('salesResetFilter');
    const scrollInput = document.getElementById('salesScrollY');
    if (!form || !from || !to) return;

    const pad = n => String(n).padStart(2, '0');
    const ymd = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    const rememberScroll = () => { if (scrollInput) scrollInput.value = String(Math.max(0, Math.round(window.scrollY || document.documentElement.scrollTop || 0))); };
    const submit = () => { rememberScroll(); form.submit(); };
    const submitCustom = () => { if (periodInput) periodInput.value = 'custom'; submit(); };
    const setRange = (start, end) => { from.value = ymd(start); to.value = ymd(end); if (periodInput) periodInput.value = 'custom'; submit(); };

    from.addEventListener('change', submitCustom);
    to.addEventListener('change', submitCustom);
    document.querySelectorAll('[data-period]').forEach(function (button) {
        button.addEventListener('click', function () {
            const url = new URL(window.location.href); url.searchParams.set('sales_period', button.getAttribute('data-period')); url.searchParams.delete('from'); url.searchParams.delete('to'); url.searchParams.set('filter_open', '1'); url.searchParams.set('scroll_y', String(Math.max(0, Math.round(window.scrollY || document.documentElement.scrollTop || 0)))); window.location.href = url.toString();
        });
    });
    resetDates?.addEventListener('click', function () { setRange(new Date(), new Date()); });
    resetFilter?.addEventListener('click', function () {
        window.location.href = '<?php echo htmlspecialchars($sales_href_base . '?' . sales_page_query(['sales_period' => 'today', 'from' => null, 'to' => null, 'type' => null, 'method' => null, 'item' => null]), ENT_QUOTES, 'UTF-8'); ?>';
    });
    const requestedScroll = Number('<?php echo htmlspecialchars((string)($_GET['scroll_y'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>');
    if (!Number.isNaN(requestedScroll) && requestedScroll > 0) {
        requestAnimationFrame(function () { window.scrollTo({ top: requestedScroll, left: 0, behavior: 'auto' }); });
    }
});
function submitSalesFilter(form) {
    const scrollInput = document.getElementById('salesScrollY');
    if (scrollInput) scrollInput.value = String(Math.max(0, Math.round(window.scrollY || document.documentElement.scrollTop || 0)));
    form.submit();
}
</script>
</body>
</html>