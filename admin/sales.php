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

if ($is_manager && !(defined('MANAGER_PANEL') && MANAGER_PANEL)) {
    $managerSalesUrl = rtrim(AUTH_REDIRECT_BASE, '/') . '/manager/sales.php';
    if (!empty($_SERVER['QUERY_STRING'])) {
        $managerSalesUrl .= '?' . $_SERVER['QUERY_STRING'];
    }
    header('Location: ' . $managerSalesUrl);
    exit;
}

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

$sales_href_base = (defined('MANAGER_PANEL') && MANAGER_PANEL)
    ? rtrim(AUTH_REDIRECT_BASE, '/') . '/manager/sales.php'
    : rtrim(AUTH_REDIRECT_BASE, '/') . '/admin/sales.php';
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
$salesTrendIsAllBranches = printflow_branch_value_is_all($branchId);
try {
    $salesTrendData = $salesTrendIsAllBranches
        ? pf_sales_trend_branch_breakdown($salesPeriodInfo, $salesFilters)
        : ['mode' => 'mix', 'series' => ['Product Sales', 'Custom Sales'], 'rows' => pf_sales_trend_breakdown($salesPeriodInfo, $branchId, $salesFilters)];
} catch (Throwable $e) {
    $salesTrendData = ['mode' => $salesTrendIsAllBranches ? 'branches' : 'mix', 'series' => [], 'rows' => []];
}
$salesTrendRows = $salesTrendData['rows'] ?? [];
$salesSourceBreakdown = $salesTrendData['source_breakdown'] ?? [];
$sourceBreakdownHasSales = false;
foreach ($salesSourceBreakdown as $sourceRow) {
    if ((float)($sourceRow['product_sales'] ?? 0) > 0 || (float)($sourceRow['custom_sales'] ?? 0) > 0) {
        $sourceBreakdownHasSales = true;
        break;
    }
}
$salesTrendJson = json_encode($salesTrendData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
if ($salesTrendJson === false) $salesTrendJson = '{"mode":"mix","series":[],"rows":[]}';
$salesSourceJson = json_encode($salesSourceBreakdown, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
if ($salesSourceJson === false) $salesSourceJson = '[]';
$salesBranchPerformance = [
    'total_revenue' => 0.0,
    'top_branch' => '',
    'top_branch_revenue' => 0.0,
    'visible_branches' => count($salesSourceBreakdown),
    'average_revenue' => 0.0,
    'product_sales' => 0.0,
    'custom_sales' => 0.0,
    'product_percent' => 0.0,
    'custom_percent' => 0.0,
];
foreach ($salesSourceBreakdown as $sourceRow) {
    $productSales = (float)($sourceRow['product_sales'] ?? 0);
    $customSales = (float)($sourceRow['custom_sales'] ?? 0);
    $branchRevenue = $productSales + $customSales;
    $salesBranchPerformance['total_revenue'] += $branchRevenue;
    $salesBranchPerformance['product_sales'] += $productSales;
    $salesBranchPerformance['custom_sales'] += $customSales;
    if ($branchRevenue > $salesBranchPerformance['top_branch_revenue']) {
        $salesBranchPerformance['top_branch'] = (string)($sourceRow['branch_name'] ?? '');
        $salesBranchPerformance['top_branch_revenue'] = $branchRevenue;
    }
}
$salesBranchPerformance['total_revenue'] = round($salesBranchPerformance['total_revenue'], 2);
$salesBranchPerformance['product_sales'] = round($salesBranchPerformance['product_sales'], 2);
$salesBranchPerformance['custom_sales'] = round($salesBranchPerformance['custom_sales'], 2);
$salesBranchPerformance['average_revenue'] = $salesBranchPerformance['visible_branches'] > 0
    ? round($salesBranchPerformance['total_revenue'] / $salesBranchPerformance['visible_branches'], 2)
    : 0.0;
if (!$salesTrendIsAllBranches) {
    $salesBranchPerformance['visible_branches'] = 1;
    $salesBranchPerformance['top_branch'] = trim((string)$branchName) ?: 'Selected Branch';
    foreach ($salesTrendRows as $trendBucket) {
        $salesBranchPerformance['product_sales'] += (float)($trendBucket['product_sales'] ?? 0);
        $salesBranchPerformance['custom_sales'] += (float)($trendBucket['custom_sales'] ?? 0);
    }
    $salesBranchPerformance['product_sales'] = round($salesBranchPerformance['product_sales'], 2);
    $salesBranchPerformance['custom_sales'] = round($salesBranchPerformance['custom_sales'], 2);
    $salesBranchPerformance['total_revenue'] = round($salesBranchPerformance['product_sales'] + $salesBranchPerformance['custom_sales'], 2);
    $salesBranchPerformance['top_branch_revenue'] = $salesBranchPerformance['total_revenue'];
    $salesBranchPerformance['average_revenue'] = $salesBranchPerformance['total_revenue'];
}
if ($salesBranchPerformance['total_revenue'] > 0) {
    $salesBranchPerformance['product_percent'] = round(($salesBranchPerformance['product_sales'] / $salesBranchPerformance['total_revenue']) * 100, 1);
    $salesBranchPerformance['custom_percent'] = round(100 - $salesBranchPerformance['product_percent'], 1);
}
$trendHasSales = false;
foreach ($salesTrendRows as $trendBucket) {
    if ($salesTrendIsAllBranches) {
        foreach (($trendBucket['branch_sales'] ?? []) as $branchSale) {
            if ((float)$branchSale > 0) {
                $trendHasSales = true;
                break 2;
            }
        }
    } elseif ((float)($trendBucket['product_sales'] ?? 0) > 0 || (float)($trendBucket['custom_sales'] ?? 0) > 0) {
        $trendHasSales = true;
        break;
    }
}
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
$printSalesUrl = sales_export_url('reports_print.php', ['report' => 'sales', 'autoprint' => 1]);
$xlsxSalesUrl = sales_export_url('reports_export_excel.php', ['report' => 'sales']);

function sales_transaction_modal_payload(array $row): array
{
    $refType = strtolower(trim((string)($row['ref_type'] ?? '')));
    $type = strtolower(trim((string)($row['type'] ?? '')));
    $linkedOrder = (int)($row['store_order_id'] ?? 0);
    return [
        'date' => !empty($row['sales_date']) ? date('M j, Y g:i A', strtotime((string)$row['sales_date'])) : '—',
        'type' => (string)($row['type'] ?? ''),
        'item' => (string)($row['item_name'] ?? '-'),
        'order' => '#' . (int)($row['id'] ?? 0),
        'customer' => (string)($row['customer_name'] ?? ''),
        'branch' => (string)($row['branch_name'] ?? ''),
        'payment_status' => sales_format_label($row['payment_status'] ?? ''),
        'payment_method' => sales_method_display($row['payment_method'] ?? ''),
        'order_status' => sales_format_label($row['status'] ?? ''),
        'amount' => number_format((float)($row['amount'] ?? 0), 2),
        'record_type' => ($refType === 'job' || $type === 'service') ? 'Customization / Service Order' : 'Store Product Order',
        'linked_order' => $linkedOrder > 0 ? '#' . $linkedOrder : '',
    ];
}
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
.sales-trend-card { margin-bottom:24px; }
.sales-all-branches-top { display:grid; grid-template-columns:minmax(0,1.55fr) minmax(280px,.85fr); gap:20px; align-items:stretch; margin-bottom:24px; }
.sales-all-branches-top .sales-trend-card { margin-bottom:0; }
.sales-performance-summary { display:flex; flex-direction:column; gap:14px; padding:12px; border:1px solid #eef2f7; border-radius:14px; background:radial-gradient(circle at top right, rgba(83,197,224,0.12), transparent 34%), linear-gradient(180deg, #fbfdff 0%, #f8fafc 100%); box-shadow:inset 0 1px 0 rgba(255,255,255,0.75); }
.sales-performance-summary .pf-branch-summary-title { margin:0 0 10px; font-size:11px; font-weight:700; letter-spacing:.02em; text-transform:uppercase; color:#475569; }
.sales-performance-summary .pf-branch-summary-grid { display:grid; gap:10px; }
.sales-performance-summary .pf-branch-stat { display:grid; grid-template-columns:42px minmax(0, 1fr); gap:12px; align-items:center; padding:12px; border-radius:12px; background:rgba(255,255,255,.88); border:1px solid rgba(226,232,240,.92); box-shadow:0 10px 25px rgba(15,23,42,.04); }
.sales-performance-summary .pf-branch-stat-icon { width:42px; height:42px; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; }
.sales-performance-summary .pf-branch-stat-icon svg { width:18px; height:18px; }
.sales-performance-summary .pf-branch-stat-copy { min-width:0; }
.sales-performance-summary .pf-branch-stat-total .pf-branch-stat-icon { background:linear-gradient(180deg, #ecf8fb 0%, #f0fafc 100%); color:#00232b; }
.sales-performance-summary .pf-branch-stat-top .pf-branch-stat-icon { background:linear-gradient(180deg, #dcfce7 0%, #f0fdf4 100%); color:#16a34a; }
.sales-performance-summary .pf-branch-stat-avg .pf-branch-stat-icon { background:linear-gradient(180deg, #ffedd5 0%, #fff7ed 100%); color:#f97316; }
.sales-performance-summary .pf-branch-stat-label { font-size:11px; font-weight:600; color:#64748b; margin-bottom:4px; line-height:1.35; }
.sales-performance-summary .pf-branch-stat-value { font-size:12px; font-weight:700; color:#00232b; line-height:1.25; }
.sales-performance-summary .pf-branch-stat-sub { font-size:11px; font-weight:600; margin-top:3px; line-height:1.35; }
.sales-performance-summary .pf-branch-stat-sub.pos { color:#16a34a; }
.sales-performance-summary .pf-branch-stat-sub.neu { color:#475569; }
.sales-performance-summary .pf-branch-breakdown { border-top:1px solid #e5e7eb; padding-top:14px; }
.sales-performance-summary .pf-branch-section-title { margin:0 0 10px; font-size:11px; font-weight:700; letter-spacing:.02em; text-transform:uppercase; color:#475569; }
.sales-performance-summary .pf-branch-breakdown-row { display:grid; gap:6px; margin-bottom:10px; }
.sales-performance-summary .pf-branch-breakdown-row:last-child { margin-bottom:0; }
.sales-performance-summary .pf-branch-breakdown-meta { display:flex; justify-content:space-between; align-items:baseline; gap:8px; }
.sales-performance-summary .pf-branch-breakdown-meta span { font-size:11px; font-weight:600; color:#64748b; }
.sales-performance-summary .pf-branch-breakdown-meta strong { font-size:12px; font-weight:700; color:#00232b; line-height:1.25; }
.sales-performance-summary .pf-branch-breakdown-bar { height:8px; background:#e2e8f0; border-radius:999px; overflow:hidden; }
.sales-performance-summary .pf-branch-breakdown-bar > span { display:block; height:100%; border-radius:inherit; }
.sales-performance-summary .pf-branch-breakdown-bar--product > span { background:linear-gradient(90deg, #00232b 0%, #0F4C5C 100%); }
.sales-performance-summary .pf-branch-breakdown-bar--custom > span { background:linear-gradient(90deg, #53C5E0 0%, #3498DB 100%); }
.sales-performance-summary .sales-list-header { margin-bottom:14px; }
.sales-performance-metric { padding:12px 0; border-bottom:1px solid #f1f5f9; }
.sales-performance-metric:last-child { border-bottom:0; }
.sales-performance-label { color:#64748b; font-size:12px; font-weight:600; }
.sales-performance-value { margin-top:4px; color:#0f172a; font-size:20px; line-height:1.2; font-weight:800; }
.sales-performance-sub { margin-top:3px; color:#64748b; font-size:12px; }
.sales-performance-split { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
@media(max-width:960px){ .sales-all-branches-top{ grid-template-columns:1fr; } .sales-all-branches-top .sales-trend-card{ margin-bottom:0; } }
.sales-trend-chart-wrap { position:relative; height:320px; width:100%; }
.sales-source-chart-wrap { position:relative; height:260px; width:100%; }
@media(max-width:640px){ .sales-trend-chart-wrap{ height:260px; } }
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
.sales-txn-row { cursor:pointer; transition:background .15s; }
.sales-txn-row:hover { background:#f0fdfa !important; }
.sales-txn-row-extra { display:none; }
.sales-item-row-extra { display:none; }
.sales-txn-expand { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; margin-top:12px; padding:10px 14px; border:1px solid #e5e7eb; border-radius:8px; background:#fff; color:#374151; font-size:13px; font-weight:600; cursor:pointer; }
.sales-txn-expand:hover { background:#f9fafb; border-color:#9ca3af; }
.sales-txn-expand svg { transition:transform .2s ease; }
.sales-txn-expand[aria-expanded="true"] svg { transform:rotate(180deg); }
.sales-txn-modal-overlay { position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:1000; display:none; align-items:center; justify-content:center; padding:20px; }
.sales-txn-modal-overlay.open { display:flex; }
.sales-txn-modal { background:#fff; border-radius:16px; width:100%; max-width:650px; max-height:88vh; overflow:auto; box-shadow:0 24px 60px rgba(15,23,42,.22); }
.sales-txn-modal-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:22px 28px 18px; border-bottom:1px solid #eef2f7; }
.sales-txn-modal-heading { display:flex; flex-direction:column; align-items:flex-start; gap:8px; min-width:0; }
.sales-txn-modal-header h3 { margin:0; font-size:23px; line-height:1.2; font-weight:800; color:#1f2937; }
.sales-txn-modal-close { border:0; background:transparent; color:#6b7280; cursor:pointer; width:32px; height:32px; border-radius:8px; font-size:22px; line-height:1; }
.sales-txn-modal-close:hover { background:#f3f4f6; }
.sales-txn-modal-body { padding:24px 28px 28px; }
.sales-txn-detail-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; font-size:13px; }
.sales-txn-detail-item { min-width:0; padding:13px 16px; border-radius:10px; background:#f8fafc; border:1px solid #f1f5f9; }
.sales-txn-detail-item dt { margin:0 0 7px; font-size:11px; line-height:1.2; text-transform:uppercase; letter-spacing:.03em; font-weight:700; color:#94a3b8; }
.sales-txn-detail-item dd { margin:0; color:#1f2937; word-break:break-word; line-height:1.45; }
.sales-txn-detail-item--amount { grid-column:1 / -1; background:#ecfeff; border-color:#c8f1f5; }
.sales-txn-detail-amount { font-size:24px; font-weight:800; color:#0f766e; margin-top:0; }
.sales-txn-status-badge { display:inline-flex; align-items:center; padding:5px 11px; border-radius:999px; background:#dcfce7; color:#15803d; font-size:12px; font-weight:700; }
.sales-txn-status-badge.is-warning { background:#fef3c7; color:#a16207; }
.sales-txn-status-badge.is-danger { background:#fee2e2; color:#b91c1c; }
@media (max-width:640px) {
.sales-txn-modal-overlay { padding:12px; }
.sales-txn-modal { max-height:92vh; border-radius:14px; }
.sales-txn-modal-header { padding:18px 20px 16px; }
.sales-txn-modal-body { padding:18px 20px 22px; }
.sales-txn-detail-grid { grid-template-columns:1fr; }
.sales-txn-detail-item--amount { grid-column:auto; }
}
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

<div class="sales-all-branches-top">
            <div class="card sales-trend-card">
                <div class="sales-list-header">
                    <h3>
                        <svg width="16" height="16" fill="none" stroke="#53C5E0" viewBox="0 0 24 24" style="flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 19V5m0 14h16M8 16v-5m4 5V8m4 8V3"/></svg>
                        <?php echo $salesTrendIsAllBranches ? 'Branch Sales Comparison' : 'Sales Trend'; ?>
                        <span style="padding:3px 8px;background:#EBF8FF;color:#2C5282;border-radius:6px;font-size:11px;font-weight:600;"><?php echo htmlspecialchars($sales_label); ?></span>
                    </h3>
                    <span style="font-size:12px;color:#64748b;"><?php echo $salesTrendIsAllBranches ? 'Branch comparison' : 'Product and custom revenue'; ?></span>
                </div>
                <?php if (!$trendHasSales): ?>
                    <div class="sales-breakdown-empty">No sales data for this period.</div>
                <?php else: ?>
                    <div class="sales-trend-chart-wrap">
                        <canvas id="salesTrendChart" aria-label="Sales trend chart"></canvas>
                    </div>
                <?php endif; ?>
            </div>
            <aside class="sales-performance-summary">
                <h4 class="pf-branch-summary-title">Sales Summary</h4>
                <div class="pf-branch-summary-grid">
                    <?php $salesTopPct = $salesBranchPerformance['total_revenue'] > 0 ? (($salesBranchPerformance['top_branch_revenue'] / $salesBranchPerformance['total_revenue']) * 100) : 0; ?>
                    <div class="pf-branch-stat pf-branch-stat-total">
                        <div class="pf-branch-stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .672-3 1.5S10.343 11 12 11s3 .672 3 1.5S13.657 14 12 14m0-6V6m0 8v2m9-4a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
                        <div class="pf-branch-stat-copy">
                            <div class="pf-branch-stat-label">Total Revenue</div>
                            <div class="pf-branch-stat-value">&#8369;<?php echo number_format((float)$salesBranchPerformance['total_revenue'], 0); ?></div>
                            <div class="pf-branch-stat-sub neu"><?php echo number_format((int)$salesBranchPerformance['visible_branches']); ?> branch<?php echo $salesBranchPerformance['visible_branches'] === 1 ? '' : 'es'; ?> shown</div>
                        </div>
                    </div>
<?php if ($salesTrendIsAllBranches): ?>
                    <div class="pf-branch-stat pf-branch-stat-top">
                        <div class="pf-branch-stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81H7.03a1 1 0 00.95-.69l1.07-3.292z"/></svg></div>
                        <div class="pf-branch-stat-copy">
                            <div class="pf-branch-stat-label">Top Performing Branch</div>
                            <div class="pf-branch-stat-value"><?php echo htmlspecialchars($salesBranchPerformance['top_branch'] ?: 'No sales'); ?></div>
                            <div class="pf-branch-stat-sub pos">&#8369;<?php echo number_format((float)$salesBranchPerformance['top_branch_revenue'], 0); ?> (<?php echo number_format((float)$salesTopPct, 1); ?>%)</div>
                        </div>
                    </div>
                    <div class="pf-branch-stat pf-branch-stat-avg">
                        <div class="pf-branch-stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 11V5a1 1 0 012 0v6h6a1 1 0 110 2h-6v6a1 1 0 11-2 0v-6H5a1 1 0 110-2h6z"/></svg></div>
                        <div class="pf-branch-stat-copy">
                            <div class="pf-branch-stat-label">Average Revenue per Branch</div>
                            <div class="pf-branch-stat-value">&#8369;<?php echo number_format((float)$salesBranchPerformance['average_revenue'], 0); ?></div>
                            <div class="pf-branch-stat-sub neu">Across visible branches</div>
                        </div>
                    </div>
<?php else: ?>
                    <div class="pf-branch-stat pf-branch-stat-avg">
                        <div class="pf-branch-stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h10"/></svg></div>
                        <div class="pf-branch-stat-copy">
                            <div class="pf-branch-stat-label">Sales Transactions</div>
                            <div class="pf-branch-stat-value"><?php echo number_format((int)($salesSummary['transaction_count'] ?? 0)); ?></div>
                            <div class="pf-branch-stat-sub neu">Filtered paid sales</div>
                        </div>
                    </div>
                    <div class="pf-branch-stat pf-branch-stat-total">
                        <div class="pf-branch-stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0v10l-8 4-8-4V7m16 0l-8 4m-8-4l8 4m0 0v10"/></svg></div>
                        <div class="pf-branch-stat-copy">
                            <div class="pf-branch-stat-label">Product Sales</div>
                            <div class="pf-branch-stat-value">&#8369;<?php echo number_format((float)$salesBranchPerformance['product_sales'], 0); ?></div>
                            <div class="pf-branch-stat-sub neu">Filtered product revenue</div>
                        </div>
                    </div>
                    <div class="pf-branch-stat pf-branch-stat-top">
                        <div class="pf-branch-stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0v10l-8 4-8-4V7m16 0l-8 4m-8-4l8 4m0 0v10"/></svg></div>
                        <div class="pf-branch-stat-copy">
                            <div class="pf-branch-stat-label">Custom Sales</div>
                            <div class="pf-branch-stat-value">&#8369;<?php echo number_format((float)$salesBranchPerformance['custom_sales'], 0); ?></div>
                            <div class="pf-branch-stat-sub neu">Filtered custom revenue</div>
                        </div>
                    </div>
<?php endif; ?>
                </div>
                <div class="pf-branch-breakdown">
                    <h4 class="pf-branch-section-title">Revenue Contribution Breakdown</h4>
                    <div class="pf-branch-breakdown-row">
                        <div class="pf-branch-breakdown-meta"><span>Product Sales</span><strong>&#8369;<?php echo number_format((float)$salesBranchPerformance['product_sales'], 0); ?> &middot; <?php echo number_format((float)$salesBranchPerformance['product_percent'], 1); ?>%</strong></div>
                        <div class="pf-branch-breakdown-bar pf-branch-breakdown-bar--product"><span style="width:<?php echo max(0, min(100, (float)$salesBranchPerformance['product_percent'])); ?>%"></span></div>
                    </div>
                    <div class="pf-branch-breakdown-row">
                        <div class="pf-branch-breakdown-meta"><span>Custom Sales</span><strong>&#8369;<?php echo number_format((float)$salesBranchPerformance['custom_sales'], 0); ?> &middot; <?php echo number_format((float)$salesBranchPerformance['custom_percent'], 1); ?>%</strong></div>
                        <div class="pf-branch-breakdown-bar pf-branch-breakdown-bar--custom"><span style="width:<?php echo max(0, min(100, (float)$salesBranchPerformance['custom_percent'])); ?>%"></span></div>
                    </div>
                </div>
            </aside>
            </div>
            <?php if ($salesTrendIsAllBranches): ?>
            <div class="card sales-trend-card" style="display:none;">
                <div class="sales-list-header">
                    <h3>
                        <svg width="16" height="16" fill="none" stroke="#53C5E0" viewBox="0 0 24 24" style="flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 19V5m0 14h16M8 16v-5m4 5V8m4 8V3"/></svg>
                        Sales Source Breakdown by Branch
                    </h3>
                    <span style="font-size:12px;color:#64748b;">Product and custom revenue</span>
                </div>
                <?php if (!$sourceBreakdownHasSales): ?>
                    <div class="sales-breakdown-empty">No sales data for this period.</div>
                <?php else: ?>
                    <div class="sales-source-chart-wrap">
                        <canvas id="salesSourceBreakdownChart" aria-label="Sales source breakdown by branch"></canvas>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
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
                                <?php foreach ($salesData['by_item'] as $itemIndex => $row): ?>
                                    <tr class="<?php echo $itemIndex >= 5 ? 'sales-item-row-extra' : ''; ?>"><td><span class="sales-breakdown-pill<?php echo sales_type_pill_class($row['type'] ?? ''); ?>"><?php echo htmlspecialchars((string)$row['type']); ?></span></td><td><?php echo htmlspecialchars((string)$row['item_name']); ?></td><td class="num">&#8369;<?php echo number_format((float)$row['revenue'], 2); ?></td></tr>
                                <?php endforeach; ?>
                                </tbody></table>
                                <?php if (count($salesData['by_item']) > 5): ?>
                                    <button type="button" class="sales-txn-expand" id="salesItemExpand" aria-expanded="false">
                                        <span data-item-expand-label>View all <?php echo number_format(count($salesData['by_item'])); ?> products/services</span>
                                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6"/></svg>
                                    </button>
                                <?php endif; ?>
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
                                <?php foreach ($salesData['transactions'] as $transactionIndex => $row): ?>
                                    <?php
                                    $txnPayload = sales_transaction_modal_payload($row);
                                    $txnPayloadAttr = htmlspecialchars(json_encode($txnPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <tr class="sales-txn-row<?php echo $transactionIndex >= 10 ? ' sales-txn-row-extra' : ''; ?>" tabindex="0" role="button" data-sales-txn="<?php echo $txnPayloadAttr; ?>" aria-label="View transaction details for order #<?php echo (int)($row['id'] ?? 0); ?>">
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
                            <?php if (count($salesData['transactions']) > 10): ?>
                                <button type="button" class="sales-txn-expand" id="salesTxnExpand" aria-expanded="false">
                                    <span data-expand-label>View all <?php echo number_format(count($salesData['transactions'])); ?> transactions</span>
                                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m6 9 6 6 6-6"/></svg>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<div class="sales-txn-modal-overlay" id="salesTxnModal" aria-hidden="true">
    <div class="sales-txn-modal" role="dialog" aria-modal="true" aria-labelledby="salesTxnModalTitle">
        <div class="sales-txn-modal-header">
            <div class="sales-txn-modal-heading">
                <h3 id="salesTxnModalTitle">Transaction Details</h3>
                <span class="sales-txn-status-badge" id="salesTxnModalStatus"></span>
            </div>
            <button type="button" class="sales-txn-modal-close" id="salesTxnModalClose" aria-label="Close">&times;</button>
        </div>
        <div class="sales-txn-modal-body">
            <dl class="sales-txn-detail-grid" id="salesTxnModalBody"></dl>
        </div>
    </div>
</div>

<script>
function salesEscapeHtml(value) {
    return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function openSalesTxnModal(payload) {
    const modal = document.getElementById('salesTxnModal');
    const body = document.getElementById('salesTxnModalBody');
    const title = document.getElementById('salesTxnModalTitle');
    const statusBadge = document.getElementById('salesTxnModalStatus');
    if (!modal || !body || !payload) return;
    title.textContent = 'Transaction ' + (payload.order || '');
    if (statusBadge) {
        const status = String(payload.order_status || 'Recorded');
        const statusKey = status.toLowerCase();
        statusBadge.textContent = status;
        statusBadge.className = 'sales-txn-status-badge' + (statusKey.includes('cancel') || statusKey.includes('reject') ? ' is-danger' : (statusKey.includes('pending') || statusKey.includes('to pay') ? ' is-warning' : ''));
    }
    const rows = [
        ['Date', payload.date],
        ['Type', payload.type],
        ['Item', payload.item],
        ['Order #', payload.order],
        ['Customer', payload.customer],
        ['Branch', payload.branch],
        ['Payment Status', payload.payment_status],
        ['Payment Method', payload.payment_method],
        ['Order Status', payload.order_status],
        ['Record Type', payload.record_type],
    ];
    if (payload.linked_order) rows.push(['Linked Store Order', payload.linked_order]);
    body.innerHTML = rows.map(function (pair) {
        return '<div class="sales-txn-detail-item"><dt>' + salesEscapeHtml(pair[0]) + '</dt><dd>' + salesEscapeHtml(pair[1]) + '</dd></div>';
    }).join('') + '<div class="sales-txn-detail-item sales-txn-detail-item--amount"><dt>Amount</dt><dd class="sales-txn-detail-amount">&#8369;' + salesEscapeHtml(payload.amount) + '</dd></div>';
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
}

function closeSalesTxnModal() {
    const modal = document.getElementById('salesTxnModal');
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
}

document.addEventListener('DOMContentLoaded', function () {
    const expandItems = document.getElementById('salesItemExpand');
    expandItems?.addEventListener('click', function () {
        const expanded = expandItems.getAttribute('aria-expanded') === 'true';
        document.querySelectorAll('.sales-item-row-extra').forEach(function (row) {
            row.style.display = expanded ? 'none' : 'table-row';
        });
        expandItems.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        const label = expandItems.querySelector('[data-item-expand-label]');
        if (label) label.textContent = expanded ? 'View all products/services' : 'Show fewer products/services';
    });
    const expandTransactions = document.getElementById('salesTxnExpand');
    expandTransactions?.addEventListener('click', function () {
        const expanded = expandTransactions.getAttribute('aria-expanded') === 'true';
        document.querySelectorAll('.sales-txn-row-extra').forEach(function (row) {
            row.style.display = expanded ? 'none' : 'table-row';
        });
        expandTransactions.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        const label = expandTransactions.querySelector('[data-expand-label]');
        if (label) label.textContent = expanded ? 'View all transactions' : 'Show fewer transactions';
    });
    document.querySelectorAll('.sales-txn-row').forEach(function (row) {
        const open = function () {
            try {
                openSalesTxnModal(JSON.parse(row.getAttribute('data-sales-txn') || '{}'));
            } catch (e) { console.error(e); }
        };
        row.addEventListener('click', open);
        row.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                open();
            }
        });
    });
    document.getElementById('salesTxnModalClose')?.addEventListener('click', closeSalesTxnModal);
    document.getElementById('salesTxnModal')?.addEventListener('click', function (e) {
        if (e.target === e.currentTarget) closeSalesTxnModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeSalesTxnModal();
    });

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
<?php if ($trendHasSales): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const canvas = document.getElementById('salesTrendChart');
    if (!canvas || typeof Chart === 'undefined') return;
    const trend = <?php echo $salesTrendJson; ?>;
    const rows = trend.rows || [];
    const branchComparison = trend.mode === 'branches';
    const money = function (value) {
        return 'PHP ' + Number(value || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    };
    new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: rows.map(function (row) { return row.bucket_label; }),
            datasets: branchComparison
                ? (trend.series || []).map(function (branchName, index) {
                    const colors = ['#53C5E0', '#6366f1', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'];
                    return {
                        label: branchName,
                        data: rows.map(function (row) { return Number((row.branch_sales || {})[branchName] || 0); }),
                        backgroundColor: colors[index % colors.length],
                        borderColor: colors[index % colors.length],
                        borderWidth: 1
                    };
                })
                : [
                    {
                        label: 'Product Sales',
                        data: rows.map(function (row) { return Number(row.product_sales || 0); }),
                        backgroundColor: '#53C5E0',
                        borderColor: '#249bb8',
                        borderWidth: 1
                    },
                    {
                        label: 'Custom Sales',
                        data: rows.map(function (row) { return Number(row.custom_sales || 0); }),
                        backgroundColor: '#6366f1',
                        borderColor: '#4f46e5',
                        borderWidth: 1
                    }
                ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { stacked: !branchComparison, grid: { display: false } },
                y: {
                    stacked: !branchComparison,
                    beginAtZero: true,
                    ticks: { callback: function (value) { return money(value); } }
                }
            },
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: function (context) { return context.dataset.label + ': ' + money(context.parsed.y); },
                        footer: function (items) {
                            const total = items.reduce(function (sum, item) { return sum + Number(item.parsed.y || 0); }, 0);
                            return 'Total: ' + money(total);
                        }
                    }
                }
            }
        }
    });
});
</script>
<?php if ($salesTrendIsAllBranches && $sourceBreakdownHasSales): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const canvas = document.getElementById('salesSourceBreakdownChart');
    if (!canvas || typeof Chart === 'undefined') return;
    const rows = <?php echo $salesSourceJson; ?>;
    const money = function (value) {
        return 'PHP ' + Number(value || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    };
    new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: rows.map(function (row) { return row.branch_name; }),
            datasets: [
                {
                    label: 'Product Sales',
                    data: rows.map(function (row) { return Number(row.product_sales || 0); }),
                    backgroundColor: '#53C5E0',
                    borderColor: '#249bb8',
                    borderWidth: 1
                },
                {
                    label: 'Custom Sales',
                    data: rows.map(function (row) { return Number(row.custom_sales || 0); }),
                    backgroundColor: '#6366f1',
                    borderColor: '#4f46e5',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { stacked: true, grid: { display: false } },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: { callback: function (value) { return money(value); } }
                }
            },
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: function (context) { return context.dataset.label + ': ' + money(context.parsed.y); }
                    }
                }
            }
        }
    });
});
</script>
<?php endif; ?>
<?php endif; ?>
</body>
</html>