<?php
/**
 * PrintFlow Sales Breakdown
 * Dedicated sales details page using the shared official report sales rule.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/branch_ui.php';
require_once __DIR__ . '/../includes/reports_dashboard_queries.php';

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
    $keys = ['from', 'to', 'branch_id', 'sales_period'];
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

$sales_period = $_GET['sales_period'] ?? 'today';
if (!in_array($sales_period, ['today', 'week', 'month', 'custom'], true)) {
    $sales_period = 'today';
}

$todayYmd = date('Y-m-d');
$requested_from = $_GET['from'] ?? '';
$requested_to = $_GET['to'] ?? '';
$valid_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$requested_from) ? (string)$requested_from : '';
$valid_to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$requested_to) ? (string)$requested_to : '';

if ($sales_period === 'custom' && $valid_from !== '' && $valid_to !== '') {
    $sales_from = $valid_from;
    $sales_to = $valid_to;
    if (strtotime($sales_from) > strtotime($sales_to)) {
        [$sales_from, $sales_to] = [$sales_to, $sales_from];
    }
    $sales_label = 'Filtered';
} elseif ($sales_period === 'week') {
    $sales_from = date('Y-m-d', strtotime('monday this week'));
    $sales_to = $todayYmd;
    $sales_label = 'This Week';
} elseif ($sales_period === 'month') {
    $sales_from = date('Y-m-01');
    $sales_to = $todayYmd;
    $sales_label = 'This Month';
} else {
    $sales_period = 'today';
    $sales_from = $todayYmd;
    $sales_to = $todayYmd;
    $sales_label = 'Today';
}
$sales_to_end = $sales_to . ' 23:59:59';

$branchEmpty = !pf_reports_branch_has_activity($branchId);
$salesData = !$branchEmpty
    ? pf_reports_official_sales_breakdown($sales_from, $sales_to_end, $branchId, 100)
    : ['summary' => [], 'by_branch' => [], 'by_item' => [], 'transactions' => []];
$salesSummary = $salesData['summary'] ?? [];
$salesFilterCount = ($sales_period === 'custom' && ($sales_from !== '' || $sales_to !== '')) ? 1 : 0;
$salesBranchParam = printflow_branch_value_is_all($branchId) ? 'all' : (string)(int)$branchId;

$page_title = 'Sales Breakdown - Admin';
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
<style>
.ana-wrap { display:flex; flex-direction:column; gap:24px; }
.ana-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.05); transition:box-shadow .2s; display:flex; flex-direction:column; height:100%; }
.ana-card:hover { box-shadow:0 4px 12px rgba(0,0,0,.08); }
.ana-hd { display:flex; align-items:center; justify-content:space-between; padding:18px 20px; border-bottom:1px solid #f3f4f6; gap:10px; flex-wrap:wrap; flex-shrink:0; }
.ana-hd h3 { margin:0; font-size:14px; font-weight:700; color:#1f2937; display:flex; align-items:center; gap:8px; white-space:nowrap; }
.ana-hd h3 svg { width:16px; height:16px; color:#53C5E0; flex-shrink:0; }
.ana-bd { padding:20px; flex:1; display:flex; flex-direction:column; min-height:0; }
.chart-title-nowrap { min-width:0; }
.toolbar-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; height:36px; padding:0 14px; border:1px solid #e5e7eb; border-radius:10px; background:#fff; color:#111827; font-size:13px; font-weight:500; cursor:pointer; transition:all .2s; text-decoration:none; white-space:nowrap; }
.toolbar-btn:hover { border-color:#9ca3af; background:#f9fafb; }
.toolbar-btn.active { border-color:#00232b; color:#00232b; background:#ecf8fb; }
.sales-page-subhead { display:flex; align-items:center; justify-content:space-between; gap:12px; font-size:13px; color:#6b7280; margin-top:-10px; }
.sales-breakdown-tabs { display:flex; flex-wrap:wrap; gap:8px; }
.sales-breakdown-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; margin-bottom:20px; }
.sales-breakdown-metric { border:1px solid #e5e7eb; border-radius:10px; padding:20px 22px; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.sales-breakdown-metric-label { font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
.sales-breakdown-metric-value { margin-top:10px; font-size:28px; line-height:1.1; font-weight:900; color:#0f172a; }
.sales-breakdown-split { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:20px; margin-bottom:20px; }
.sales-breakdown-panel { border:1px solid #eef2f7; border-radius:10px; padding:16px; min-width:0; background:#fff; }
.pf-branch-section-title { margin:0 0 12px; font-size:13px; color:#334155; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
.sales-breakdown-table { width:100%; border-collapse:collapse; font-size:14px; }
.sales-breakdown-table th { text-align:left; color:#64748b; font-weight:600; font-size:13px; padding:12px 8px; border-bottom:1px solid #e5e7eb; }
.sales-breakdown-table td { padding:14px 8px; border-bottom:1px solid #f1f5f9; color:#111827; vertical-align:middle; }
.sales-breakdown-table .num { text-align:right; font-weight:600; color:#0f172a; }
.sales-breakdown-pill { display:inline-flex; align-items:center; justify-content:center; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:600; background:#ecfdf5; color:#047857; }
.sales-breakdown-empty { min-height:110px; display:flex; align-items:center; justify-content:center; color:#64748b; font-size:13px; border:1px dashed #d1d5db; border-radius:10px; background:#fff; text-align:center; }
.filter-panel { position:absolute; top:calc(100% + 6px); right:0; width:376px; background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.12); z-index:200; overflow:hidden; display:none; }
.filter-panel.open { display:block; }
.filter-panel-header { display:flex; align-items:center; justify-content:space-between; padding:18px 22px; border-bottom:1px solid #f3f4f6; font-size:18px; font-weight:700; color:#111827; }
.filter-panel-close { border:0; background:transparent; color:#374151; cursor:pointer; width:28px; height:28px; display:inline-flex; align-items:center; justify-content:center; border-radius:8px; }
.filter-panel-close:hover { background:#f3f4f6; }
.filter-section { padding:18px 22px; border-bottom:1px solid #f3f4f6; }
.filter-section-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; }
.filter-section-label { font-size:14px; font-weight:600; color:#374151; }
.filter-reset-link { font-size:13px; font-weight:700; color:#0d9488; cursor:pointer; background:none; border:0; padding:0; }
.filter-date-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.filter-date-label { font-size:12px; color:#6b7280; margin-bottom:6px; }
.filter-input { width:100%; height:42px; border:1px solid #e5e7eb; border-radius:8px; font-size:14px; padding:0 12px; color:#1f2937; box-sizing:border-box; }
.filter-input:focus { outline:none; border-color:#0d9488; }
.fp-preset-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:7px; margin-top:8px; }
.fp-preset-btn { height:34px; border:1px solid #e5e7eb; border-radius:7px; background:#fff; color:#374151; font-size:12px; font-weight:500; cursor:pointer; }
.fp-preset-btn:hover,.fp-preset-btn.active { border-color:#00232b; background:#ecf8fb; color:#00232b; font-weight:700; }
.filter-actions { padding:16px 22px; }
.filter-btn-reset { width:100%; height:44px; border:1px solid #e5e7eb; background:#fff; border-radius:9px; font-size:14px; font-weight:500; color:#374151; cursor:pointer; }
.filter-btn-reset:hover { background:#f9fafb; }
.filter-badge { display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; background:#0d9488; color:#fff; border-radius:50%; font-size:10px; font-weight:700; }
.sales-breakdown-pill-product { background:#ecfdf5; color:#047857; }
.sales-breakdown-pill-service { background:#eff6ff; color:#1d4ed8; }
.sales-breakdown-total-row td { background:#f8fafc; border-top:1px solid #e5e7eb; border-bottom:0; font-weight:800; color:#0f172a; }
.sales-page-actions { display:flex; align-items:center; gap:8px; position:relative; }
@media(max-width:520px){ .filter-panel{right:auto;left:0;width:min(376px,calc(100vw - 48px));} .fp-preset-grid{grid-template-columns:1fr 1fr;} }
@media(max-width:960px){ .sales-breakdown-grid,.sales-breakdown-split{ grid-template-columns:1fr; } .ana-hd{align-items:flex-start;} .sales-page-subhead{align-items:flex-start;flex-direction:column;} }
</style>
</head>
<body>
<div class="dashboard-container">
    <?php include __DIR__ . '/../includes/' . (($current_user['role'] ?? '') === 'Admin' ? 'admin_sidebar.php' : 'manager_sidebar.php'); ?>
    <div class="main-content">
        <header class="pf-mobile-branch-inline">
            <h1 class="page-title">Sales Breakdown</h1>
            <?php if (!defined('MANAGER_PANEL') || !MANAGER_PANEL) { render_branch_selector($branchCtx); } ?>
        </header>
        <main>
            <?php render_branch_context_banner($branchCtx['branch_name']); ?>
            <div class="ana-wrap">
                <div class="sales-page-subhead">
                    <span><?php echo htmlspecialchars($branchName); ?> &nbsp;&middot;&nbsp; <?php echo htmlspecialchars(date('M d, Y', strtotime($sales_from))); ?><?php if ($sales_from !== $sales_to): ?> - <?php echo htmlspecialchars(date('M d, Y', strtotime($sales_to))); ?><?php endif; ?></span>
                    <div class="sales-page-actions no-print">
                        <a class="toolbar-btn" style="height:38px;text-decoration:none;" href="<?php echo htmlspecialchars(rtrim(AUTH_REDIRECT_BASE, '/') . '/admin/reports.php?' . sales_page_query(['sales_period' => null]), ENT_QUOTES, 'UTF-8'); ?>">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
                            Back to Reports
                        </a>
                        <div style="position:relative;">
                            <button type="button" class="toolbar-btn <?php echo $salesFilterCount > 0 ? 'active' : ''; ?>" id="salesFilterToggle" style="height:38px;">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                Filter
                                <?php if ($salesFilterCount > 0): ?><span class="filter-badge"><?php echo (int)$salesFilterCount; ?></span><?php endif; ?>
                            </button>
                            <div class="filter-panel" id="salesFilterPanel">
                                <div class="filter-panel-header">
                                    <span>Filter</span>
                                    <button type="button" class="filter-panel-close" id="salesFilterClose" aria-label="Close filter">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                    </button>
                                </div>
                                <form method="GET" id="salesFilterForm">
                                    <input type="hidden" name="branch_id" value="<?php echo htmlspecialchars($salesBranchParam); ?>">
                                    <input type="hidden" name="sales_period" value="custom">
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
                                        <div style="margin-top:12px;">
                                            <div class="filter-date-label">Quick presets</div>
                                            <div class="fp-preset-grid">
                                                <button type="button" class="fp-preset-btn" data-preset="today">Today</button>
                                                <button type="button" class="fp-preset-btn" data-preset="last_7">Last 7 days</button>
                                                <button type="button" class="fp-preset-btn" data-preset="last_30">Last 30 days</button>
                                                <button type="button" class="fp-preset-btn" data-preset="this_month">This month</button>
                                                <button type="button" class="fp-preset-btn" data-preset="last_6">Last 6 months</button>
                                                <button type="button" class="fp-preset-btn" data-preset="last_12">Last 12 months</button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="filter-actions">
                                        <button type="button" class="filter-btn-reset" id="salesResetFilter">Reset</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="ana-card">
                <div class="ana-hd">
                    <h3 class="chart-title-nowrap">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/></svg>
                        Sales Breakdown
                        <span style="margin-left:8px;padding:3px 8px;background:#EBF8FF;color:#2C5282;border-radius:6px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.04em;"><?php echo htmlspecialchars($sales_label); ?></span>
                    </h3>
                    <div class="sales-breakdown-tabs no-print">
                        <?php foreach (["today" => "Today", "week" => "This Week", "month" => "This Month"] as $periodKey => $periodLabel): ?>
                            <a class="toolbar-btn <?php echo $sales_period === $periodKey ? 'active' : ''; ?>" style="height:32px;padding:0 12px;font-size:12px;text-decoration:none;" href="<?php echo htmlspecialchars($sales_href_base . '?' . sales_page_query(['sales_period' => $periodKey, 'from' => null, 'to' => null]), ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($periodLabel); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="ana-bd">
                    <div class="sales-breakdown-grid">
                        <div class="sales-breakdown-metric"><div class="sales-breakdown-metric-label">Total Sales</div><div class="sales-breakdown-metric-value">&#8369;<?php echo number_format((float)($salesSummary['total_sales'] ?? 0), 2); ?></div></div>
                        <div class="sales-breakdown-metric"><div class="sales-breakdown-metric-label">Sales Transactions</div><div class="sales-breakdown-metric-value"><?php echo number_format((int)($salesSummary['transaction_count'] ?? 0)); ?></div></div>
                        <div class="sales-breakdown-metric"><div class="sales-breakdown-metric-label">Product Sales</div><div class="sales-breakdown-metric-value">&#8369;<?php echo number_format((float)($salesSummary['product_sales'] ?? 0), 2); ?></div></div>
                        <div class="sales-breakdown-metric"><div class="sales-breakdown-metric-label">Service/Custom Sales</div><div class="sales-breakdown-metric-value">&#8369;<?php echo number_format((float)($salesSummary['service_sales'] ?? 0), 2); ?></div></div>
                    </div>
                    <div class="sales-breakdown-split">
                        <div class="sales-breakdown-panel">
                            <h4 class="pf-branch-section-title">Sales by Branch</h4>
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
                            <h4 class="pf-branch-section-title">Sales by Product/Service</h4>
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
                    <div class="sales-breakdown-panel">
                        <h4 class="pf-branch-section-title">Transaction Details</h4>
                    <?php if (empty($salesData['transactions'])): ?>
                        <div class="sales-breakdown-empty">No sales transactions for this period.</div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="sales-breakdown-table">
                                <thead><tr><th>Date</th><th>Type</th><th>Order</th><th>Customer</th><th>Branch</th><th>Payment</th><th>Status</th><th class="num">Amount</th></tr></thead>
                                <tbody>
                                <?php foreach ($salesData['transactions'] as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)$row['sales_date']))); ?></td>
                                        <td><span class="sales-breakdown-pill<?php echo sales_type_pill_class($row['type'] ?? ''); ?>"><?php echo htmlspecialchars((string)$row['type']); ?></span></td>
                                        <td>#<?php echo (int)$row['id']; ?></td>
                                        <td><?php echo htmlspecialchars((string)$row['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$row['branch_name']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$row['payment_status']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$row['status']); ?></td>
                                        <td class="num">&#8369;<?php echo number_format((float)$row['amount'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot><tr class="sales-breakdown-total-row"><td colspan="7">Total Amount</td><td class="num">&#8369;<?php echo number_format((float)($salesSummary['total_sales'] ?? 0), 2); ?></td></tr></tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            </div>
        </main>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('salesFilterToggle');
    const panel = document.getElementById('salesFilterPanel');
    const close = document.getElementById('salesFilterClose');
    const form = document.getElementById('salesFilterForm');
    const from = document.getElementById('salesFilterFrom');
    const to = document.getElementById('salesFilterTo');
    const resetDates = document.getElementById('salesResetDates');
    const resetFilter = document.getElementById('salesResetFilter');
    if (!toggle || !panel || !form || !from || !to) return;

    const pad = n => String(n).padStart(2, '0');
    const ymd = date => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    const submit = () => form.submit();
    const setRange = (start, end) => { from.value = ymd(start); to.value = ymd(end); submit(); };

    toggle.addEventListener('click', function (event) {
        event.stopPropagation();
        panel.classList.toggle('open');
    });
    close?.addEventListener('click', function () { panel.classList.remove('open'); });
    document.addEventListener('click', function (event) {
        if (!panel.contains(event.target) && !toggle.contains(event.target)) panel.classList.remove('open');
    });
    panel.addEventListener('click', function (event) { event.stopPropagation(); });

    from.addEventListener('change', submit);
    to.addEventListener('change', submit);
    document.querySelectorAll('[data-preset]').forEach(function (button) {
        button.addEventListener('click', function () {
            const today = new Date();
            const start = new Date(today);
            const preset = button.getAttribute('data-preset');
            if (preset === 'today') {
                setRange(today, today);
            } else if (preset === 'last_7') {
                start.setDate(today.getDate() - 6);
                setRange(start, today);
            } else if (preset === 'last_30') {
                start.setDate(today.getDate() - 29);
                setRange(start, today);
            } else if (preset === 'this_month') {
                start.setDate(1);
                setRange(start, today);
            } else if (preset === 'last_6') {
                start.setMonth(today.getMonth() - 6);
                setRange(start, today);
            } else if (preset === 'last_12') {
                start.setMonth(today.getMonth() - 12);
                setRange(start, today);
            }
        });
    });
    resetDates?.addEventListener('click', function () { setRange(new Date(), new Date()); });
    resetFilter?.addEventListener('click', function () {
        window.location.href = '<?php echo htmlspecialchars($sales_href_base . '?' . sales_page_query(['sales_period' => 'today', 'from' => null, 'to' => null]), ENT_QUOTES, 'UTF-8'); ?>';
    });
});
</script>
</body>
</html>
