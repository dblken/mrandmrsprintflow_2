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

$sales_period = $_GET['sales_period'] ?? 'today';
if (!in_array($sales_period, ['today', 'week', 'month'], true)) {
    $sales_period = 'today';
}

$todayYmd = date('Y-m-d');
if ($sales_period === 'week') {
    $sales_from = date('Y-m-d', strtotime('monday this week'));
    $sales_to = $todayYmd;
    $sales_label = 'This Week';
} elseif ($sales_period === 'month') {
    $sales_from = date('Y-m-01');
    $sales_to = $todayYmd;
    $sales_label = 'This Month';
} else {
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
.sales-breakdown-tabs { display:flex; flex-wrap:wrap; gap:8px; }
.sales-breakdown-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:16px; }
.sales-breakdown-metric { border:1px solid #e5e7eb; border-radius:10px; padding:14px; background:#fff; }
.sales-breakdown-metric-label { font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.04em; }
.sales-breakdown-metric-value { margin-top:6px; font-size:22px; line-height:1.1; font-weight:900; color:#0f172a; }
.sales-breakdown-split { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:16px; }
.sales-breakdown-table { width:100%; border-collapse:collapse; font-size:13px; }
.sales-breakdown-table th { text-align:left; color:#64748b; font-weight:800; font-size:11px; text-transform:uppercase; letter-spacing:.04em; padding:10px 8px; border-bottom:1px solid #e5e7eb; }
.sales-breakdown-table td { padding:11px 8px; border-bottom:1px solid #f1f5f9; color:#1f2937; vertical-align:middle; }
.sales-breakdown-table .num { text-align:right; font-weight:800; color:#0f172a; }
.sales-breakdown-pill { display:inline-flex; align-items:center; justify-content:center; padding:4px 9px; border-radius:999px; font-size:11px; font-weight:800; background:#ecfdf5; color:#047857; }
.sales-breakdown-empty { min-height:90px; display:flex; align-items:center; justify-content:center; color:#64748b; font-size:13px; border:1px dashed #d1d5db; border-radius:10px; }
@media(max-width:960px){ .sales-breakdown-grid,.sales-breakdown-split{ grid-template-columns:1fr; } }
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
            <div class="ana-card">
                <div class="ana-hd">
                    <h3 class="chart-title-nowrap">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/></svg>
                        Sales Breakdown
                        <span style="margin-left:8px;padding:3px 8px;background:#EBF8FF;color:#2C5282;border-radius:6px;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.04em;"><?php echo htmlspecialchars($sales_label); ?></span>
                    </h3>
                    <div class="sales-breakdown-tabs no-print">
                        <?php foreach (["today" => "Today", "week" => "This Week", "month" => "This Month"] as $periodKey => $periodLabel): ?>
                            <a class="toolbar-btn <?php echo $sales_period === $periodKey ? 'active' : ''; ?>" style="height:32px;padding:0 12px;font-size:12px;text-decoration:none;" href="<?php echo htmlspecialchars($sales_href_base . '?' . sales_page_query(['sales_period' => $periodKey]), ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($periodLabel); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="ana-bd">
                    <div style="font-size:12px;color:#64748b;margin-bottom:14px;">
                        <?php echo htmlspecialchars(date('M j, Y', strtotime($sales_from))); ?><?php if ($sales_from !== $sales_to): ?> - <?php echo htmlspecialchars(date('M j, Y', strtotime($sales_to))); ?><?php endif; ?>
                        &middot; <?php echo htmlspecialchars($branchName); ?>
                    </div>
                    <div class="sales-breakdown-grid">
                        <div class="sales-breakdown-metric"><div class="sales-breakdown-metric-label">Total Sales</div><div class="sales-breakdown-metric-value">&#8369;<?php echo number_format((float)($salesSummary['total_sales'] ?? 0), 2); ?></div></div>
                        <div class="sales-breakdown-metric"><div class="sales-breakdown-metric-label">Sales Transactions</div><div class="sales-breakdown-metric-value"><?php echo number_format((int)($salesSummary['transaction_count'] ?? 0)); ?></div></div>
                        <div class="sales-breakdown-metric"><div class="sales-breakdown-metric-label">Product Sales</div><div class="sales-breakdown-metric-value">&#8369;<?php echo number_format((float)($salesSummary['product_sales'] ?? 0), 2); ?></div></div>
                        <div class="sales-breakdown-metric"><div class="sales-breakdown-metric-label">Service/Custom Sales</div><div class="sales-breakdown-metric-value">&#8369;<?php echo number_format((float)($salesSummary['service_sales'] ?? 0), 2); ?></div></div>
                    </div>
                    <div class="sales-breakdown-split" style="margin-bottom:16px;">
                        <div>
                            <h4 class="pf-branch-section-title" style="margin-bottom:8px;">Sales by Branch</h4>
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
                        <div>
                            <h4 class="pf-branch-section-title" style="margin-bottom:8px;">Sales by Product/Service</h4>
                            <?php if (empty($salesData['by_item'])): ?>
                                <div class="sales-breakdown-empty">No product or service sales for this period.</div>
                            <?php else: ?>
                                <table class="sales-breakdown-table"><thead><tr><th>Type</th><th>Item</th><th class="num">Sales</th></tr></thead><tbody>
                                <?php foreach (array_slice($salesData['by_item'], 0, 12) as $row): ?>
                                    <tr><td><span class="sales-breakdown-pill"><?php echo htmlspecialchars((string)$row['type']); ?></span></td><td><?php echo htmlspecialchars((string)$row['item_name']); ?></td><td class="num">&#8369;<?php echo number_format((float)$row['revenue'], 2); ?></td></tr>
                                <?php endforeach; ?>
                                </tbody></table>
                            <?php endif; ?>
                        </div>
                    </div>
                    <h4 class="pf-branch-section-title" style="margin-bottom:8px;">Transaction Details</h4>
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
                                        <td><span class="sales-breakdown-pill"><?php echo htmlspecialchars((string)$row['type']); ?></span></td>
                                        <td>#<?php echo (int)$row['id']; ?></td>
                                        <td><?php echo htmlspecialchars((string)$row['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$row['branch_name']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$row['payment_status']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$row['status']); ?></td>
                                        <td class="num">&#8369;<?php echo number_format((float)$row['amount'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>
</body>
</html>
