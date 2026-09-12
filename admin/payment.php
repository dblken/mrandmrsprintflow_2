<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/branch_ui.php';
require_once __DIR__ . '/../includes/provider_payments.php';
require_once __DIR__ . '/../includes/payment_verification.php';

require_role(['Admin', 'Manager']);

if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

$current_user = get_logged_in_user();
$isManagerPanel = defined('MANAGER_PANEL') && MANAGER_PANEL;
$branchCtx = init_branch_context(false);
$branchId = $branchCtx['selected_branch_id'];

$search = trim((string)($_GET['search'] ?? ''));
$period = strtolower(trim((string)($_GET['period'] ?? 'today')));
$methodFilter = strtolower(trim((string)($_GET['method'] ?? 'all')));
$sourceFilter = strtolower(trim((string)($_GET['source'] ?? 'all')));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$sortBy = strtolower(trim((string)($_GET['sort'] ?? 'newest')));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

if (!in_array($period, ['today', 'week', 'month', 'custom', 'all'], true)) {
    $period = 'today';
}
if (!in_array($methodFilter, ['all', 'cash', 'qrph'], true)) {
    $methodFilter = 'all';
}
if (!in_array($sourceFilter, ['all', 'online', 'pos'], true)) {
    $sourceFilter = 'all';
}
if (!in_array($statusFilter, ['all', 'paid', 'processing', 'expired', 'failed'], true)) {
    $statusFilter = 'all';
}
if (!in_array($sortBy, ['newest', 'oldest', 'amount_high', 'amount_low', 'reference_az', 'reference_za'], true)) {
    $sortBy = 'newest';
}

function pf_status_label(string $s): string
{
    return match (strtolower($s)) {
        'paid' => 'Paid',
        'awaiting_payment', 'generating' => 'Processing',
        'expired' => 'Expired',
        'failed', 'cancelled' => 'Failed',
        default => ucfirst($s ?: 'Processing'),
    };
}

function pf_status_badge(string $s): string
{
    $m = [
        'Paid' => ['#dcfce7', '#166534'],
        'Processing' => ['#fef3c7', '#92400e'],
        'Expired' => ['#fee2e2', '#991b1b'],
        'Failed' => ['#fee2e2', '#991b1b'],
    ];
    [$bg, $fg] = $m[$s] ?? ['#f3f4f6', '#4b5563'];
    return '<span class="pf-pay-badge" style="background:' . $bg . ';color:' . $fg . '">' . htmlspecialchars($s) . '</span>';
}

function pf_date_range(string $p, string $f, string $t): array
{
    if ($p === 'all') {
        return ['', ''];
    }
    if ($p === 'custom') {
        return [$f !== '' ? $f . ' 00:00:00' : '', $t !== '' ? $t . ' 23:59:59' : ''];
    }
    if ($p === 'week') {
        return [date('Y-m-d 00:00:00', strtotime('monday this week')), date('Y-m-d 23:59:59')];
    }
    if ($p === 'month') {
        return [date('Y-m-01 00:00:00'), date('Y-m-d 23:59:59')];
    }
    return [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')];
}

[$start, $end] = pf_date_range($period, $from, $to);
$transactions = [];

if (printflow_provider_payments_ready()) {
    $amount = db_table_has_column('provider_payments', 'paid_amount_centavos')
        ? 'COALESCE(pp.paid_amount_centavos, pp.amount_centavos)'
        : 'pp.amount_centavos';
    $at = db_table_has_column('provider_payments', 'provider_paid_at')
        ? 'COALESCE(pp.provider_paid_at, pp.paid_at, pp.created_at)'
        : 'COALESCE(pp.paid_at, pp.created_at)';
    $sql = "SELECT pp.id, pp.order_id, pp.channel, pp.status, pp.provider_payment_id, {$amount} amount, {$at} occurred_at,
                   o.reference_id, CONCAT_WS(' ', c.first_name, c.last_name) customer_name, b.branch_name
            FROM provider_payments pp
            LEFT JOIN orders o ON o.order_id = pp.order_id
            LEFT JOIN customers c ON c.customer_id = pp.customer_id
            LEFT JOIN branches b ON b.id = COALESCE(NULLIF(pp.branch_id, 0), o.branch_id)
            WHERE 1=1";
    $types = '';
    $params = [];

    if (printflow_branch_value_is_all($branchId)) {
        $sql .= " AND COALESCE(NULLIF(pp.branch_id, 0), o.branch_id) IN (SELECT id FROM branches WHERE status != 'Archived')";
    } else {
        $sql .= ' AND COALESCE(NULLIF(pp.branch_id, 0), o.branch_id) = ?';
        $types .= 'i';
        $params[] = (int)$branchId;
    }
    if ($start !== '') {
        $sql .= " AND {$at} >= ?";
        $types .= 's';
        $params[] = $start;
    }
    if ($end !== '') {
        $sql .= " AND {$at} <= ?";
        $types .= 's';
        $params[] = $end;
    }
    if ($sourceFilter !== 'all') {
        $sql .= ' AND pp.channel = ?';
        $types .= 's';
        $params[] = $sourceFilter;
    }
    if ($statusFilter !== 'all') {
        if ($statusFilter === 'processing') {
            $sql .= " AND pp.status IN ('generating', 'awaiting_payment')";
        } elseif ($statusFilter === 'failed') {
            $sql .= " AND pp.status IN ('failed', 'cancelled')";
        } else {
            $sql .= ' AND pp.status = ?';
            $types .= 's';
            $params[] = $statusFilter;
        }
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= " AND (pp.provider_payment_id LIKE ? OR o.reference_id LIKE ? OR CONCAT_WS(' ', c.first_name, c.last_name) LIKE ?)";
        $types .= 'sss';
        array_push($params, $like, $like, $like);
    }

    foreach (db_query($sql . " ORDER BY {$at} DESC, pp.id DESC", $types ?: null, $params ?: null) ?: [] as $r) {
        $transactions[] = [
            'order_id' => (int)$r['order_id'],
            'reference' => (string)($r['provider_payment_id'] ?: 'Ledger #' . $r['id']),
            'order' => (string)($r['reference_id'] ?: 'Order #' . $r['order_id']),
            'customer' => trim((string)$r['customer_name']) ?: 'Walk-in Guest',
            'source' => strtolower((string)$r['channel']) === 'pos' ? 'POS' : 'Online',
            'amount' => (float)$r['amount'] / 100,
            'method' => 'QR Ph',
            'status' => pf_status_label((string)$r['status']),
            'at' => (string)$r['occurred_at'],
            'branch' => (string)($r['branch_name'] ?? 'Unassigned'),
        ];
    }
}

$sql = "SELECT o.order_id, o.reference_id, o.total_amount, o.order_date,
               CONCAT_WS(' ', c.first_name, c.last_name) customer_name, b.branch_name
        FROM orders o
        LEFT JOIN customers c ON c.customer_id = o.customer_id
        LEFT JOIN branches b ON b.id = o.branch_id
        WHERE LOWER(TRIM(COALESCE(o.order_source, ''))) IN ('pos', 'pos_merged')
          AND LOWER(TRIM(COALESCE(o.payment_method, ''))) = 'cash'
          AND LOWER(TRIM(o.payment_status)) = 'paid'";
$types = '';
$params = [];

if (printflow_branch_value_is_all($branchId)) {
    $sql .= " AND o.branch_id IN (SELECT id FROM branches WHERE status != 'Archived')";
} else {
    $sql .= ' AND o.branch_id = ?';
    $types .= 'i';
    $params[] = (int)$branchId;
}
if ($start !== '') {
    $sql .= ' AND o.order_date >= ?';
    $types .= 's';
    $params[] = $start;
}
if ($end !== '') {
    $sql .= ' AND o.order_date <= ?';
    $types .= 's';
    $params[] = $end;
}
if ($sourceFilter === 'online' || $methodFilter === 'qrph' || ($statusFilter !== 'all' && $statusFilter !== 'paid')) {
    $sql .= ' AND 1=0';
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $sql .= " AND (o.reference_id LIKE ? OR CAST(o.order_id AS CHAR) LIKE ? OR CONCAT_WS(' ', c.first_name, c.last_name) LIKE ?)";
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}

foreach (db_query($sql . ' ORDER BY o.order_date DESC', $types ?: null, $params ?: null) ?: [] as $r) {
    $transactions[] = [
        'order_id' => (int)$r['order_id'],
        'reference' => 'POS-' . str_pad((string)$r['order_id'], 6, '0', STR_PAD_LEFT),
        'order' => (string)($r['reference_id'] ?: 'Order #' . $r['order_id']),
        'customer' => trim((string)$r['customer_name']) ?: 'Walk-in Guest',
        'source' => 'POS',
        'amount' => (float)$r['total_amount'],
        'method' => 'Cash',
        'status' => 'Paid',
        'at' => (string)$r['order_date'],
        'branch' => (string)($r['branch_name'] ?? 'Unassigned'),
    ];
}

if ($methodFilter === 'cash') {
    $transactions = array_values(array_filter($transactions, fn($r) => $r['method'] === 'Cash'));
}
if ($methodFilter === 'qrph') {
    $transactions = array_values(array_filter($transactions, fn($r) => $r['method'] === 'QR Ph'));
}

usort($transactions, fn($a, $b) => match ($sortBy) {
    'oldest' => strtotime($a['at']) <=> strtotime($b['at']),
    'amount_high' => $b['amount'] <=> $a['amount'],
    'amount_low' => $a['amount'] <=> $b['amount'],
    'reference_az' => strcasecmp($a['reference'], $b['reference']),
    'reference_za' => strcasecmp($b['reference'], $a['reference']),
    default => strtotime($b['at']) <=> strtotime($a['at']),
});

$paid = array_values(array_filter($transactions, fn($r) => $r['status'] === 'Paid'));
$totalSales = array_sum(array_column($paid, 'amount'));
$cashSales = array_sum(array_column(array_filter($paid, fn($r) => $r['method'] === 'Cash'), 'amount'));
$qrSales = array_sum(array_column(array_filter($paid, fn($r) => $r['method'] === 'QR Ph'), 'amount'));
$visible = array_slice($transactions, ($page - 1) * $perPage, $perPage);
$activeFilters = count(array_filter([
    $search,
    $from,
    $to,
    $methodFilter !== 'all' ? $methodFilter : '',
    $sourceFilter !== 'all' ? $sourceFilter : '',
    $statusFilter !== 'all' ? $statusFilter : '',
    $period !== 'today' ? $period : '',
]));

$query = static function (array $o = []): string {
    $q = array_merge($_GET, $o);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) {
            unset($q[$k]);
        }
    }
    return '?' . http_build_query($q);
};

$sidebar_file = (($current_user['role'] ?? '') === 'Admin') ? 'admin_sidebar.php' : 'manager_sidebar.php';
$page_title = 'Payments - PrintFlow';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($page_title); ?></title>
<?php require_once __DIR__ . '/../includes/favicon_links.php'; ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($base_path); ?>/public/assets/css/output.css">
<?php include __DIR__ . '/../includes/admin_style.php'; ?>
<?php render_branch_css(); ?>
<style>
[x-cloak] { display: none !important; }
.kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; align-items: stretch; }
@media (max-width: 900px) { .kpi-row { grid-template-columns: repeat(2, 1fr); } }
.kpi-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px 20px; position: relative; overflow: hidden; height: 100%; display: flex; flex-direction: column; }
.kpi-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; }
.kpi-card.indigo::before { background: linear-gradient(90deg, #6366f1, #818cf8); }
.kpi-card.emerald::before { background: linear-gradient(90deg, #059669, #34d399); }
.kpi-card.rose::before { background: linear-gradient(90deg, #e11d48, #fb7185); }
.kpi-card.slate::before { background: linear-gradient(90deg, #64748b, #94a3b8); }
.kpi-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; margin-bottom: 6px; }
.kpi-value { font-size: 26px; font-weight: 800; color: #111827; line-height: 1.15; }
.kpi-sub { font-size: 12px; color: #6b7280; margin-top: auto; }
.payments-list-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; min-width: 0; }
.payments-list-header h3 { flex: 1 1 auto; min-width: 0; margin: 0; font-size: 16px; font-weight: 700; color: #1f2937; }
.payments-list-toolbar { display: flex; align-items: center; gap: 8px; flex-wrap: nowrap; flex-shrink: 0; }
.toolbar-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border: 1px solid #e5e7eb; background: #fff; border-radius: 8px; font-size: 13px; font-weight: 500; color: #374151; cursor: pointer; white-space: nowrap; text-decoration: none; }
.toolbar-btn:hover { border-color: #9ca3af; background: #f9fafb; }
.toolbar-btn.active { border-color: #0d9488; color: #0d9488; background: #f0fdfa; }
.sort-dropdown { position: absolute; top: calc(100% + 6px); right: 0; width: 220px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, .1); z-index: 200; padding: 6px; }
.sort-option { padding: 9px 12px; font-size: 13px; color: #4b5563; border-radius: 6px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; text-decoration: none; }
.sort-option:hover { background: #f9fafb; }
.sort-option.selected { background: #f0fdfa; color: #0d9488; font-weight: 600; }
.filter-panel { position: absolute; top: calc(100% + 6px); right: 0; width: 320px; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, .12); z-index: 200; overflow: hidden; }
.filter-panel-header { padding: 14px 18px; border-bottom: 1px solid #f3f4f6; font-size: 14px; font-weight: 700; color: #111827; display: flex; justify-content: space-between; align-items: center; }
.filter-section { padding: 14px 18px; border-bottom: 1px solid #f3f4f6; }
.filter-section-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.filter-section-label { font-size: 13px; font-weight: 600; color: #374151; }
.filter-reset-link { font-size: 12px; font-weight: 600; color: #0d9488; cursor: pointer; background: none; border: none; padding: 0; }
.filter-input, .filter-select { width: 100%; height: 34px; border: 1px solid #e5e7eb; border-radius: 7px; font-size: 13px; padding: 0 10px; box-sizing: border-box; color: #1f2937; background: #fff; }
.filter-date-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.filter-date-label { font-size: 11px; color: #6b7280; margin-bottom: 4px; }
.filter-actions { padding: 14px 18px; border-top: 1px solid #f3f4f6; }
.filter-btn-reset { width: 100%; height: 36px; border: 1px solid #e5e7eb; background: #fff; border-radius: 8px; font-size: 13px; font-weight: 500; color: #374151; cursor: pointer; }
.filter-btn-reset:hover { background: #f9fafb; }
.filter-badge { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; background: #0d9488; color: #fff; border-radius: 50%; font-size: 10px; font-weight: 700; }
.orders-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.orders-table th { padding: 12px 16px; font-weight: 600; color: #6b7280; text-align: left; border-bottom: 1px solid #e5e7eb; }
.orders-table td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; color: #1f2937; }
.orders-table tbody tr:hover { background: #f9fafb; }
.pf-pay-badge { display: inline-flex; border-radius: 999px; padding: 3px 10px; font-size: 11px; font-weight: 600; }
.pf-pay-sub { font-size: 12px; color: #6b7280; margin-top: 2px; }
@media (max-width: 768px) {
    .payments-list-header { flex-wrap: wrap; }
    .payments-list-toolbar { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 2px; }
    .filter-panel { max-width: calc(100vw - 32px); }
}
</style>
</head>
<body>
<div class="dashboard-container">
<?php include __DIR__ . '/../includes/' . $sidebar_file; ?>
<div class="main-content">
    <header class="pf-mobile-branch-inline">
        <h1 class="page-title">Payments</h1>
        <?php if (!$isManagerPanel): render_branch_selector($branchCtx); endif; ?>
    </header>
    <main x-data="{ sortOpen: false, filterOpen: false }">
        <?php render_branch_context_banner($branchCtx['branch_name']); ?>

        <div class="kpi-row">
            <div class="kpi-card indigo">
                <div class="kpi-label">Total Sales</div>
                <div class="kpi-value"><?php echo format_currency($totalSales); ?></div>
                <div class="kpi-sub">Paid transactions</div>
            </div>
            <div class="kpi-card emerald">
                <div class="kpi-label">Cash</div>
                <div class="kpi-value"><?php echo format_currency($cashSales); ?></div>
                <div class="kpi-sub">Successful POS cash</div>
            </div>
            <div class="kpi-card rose">
                <div class="kpi-label">QR Ph</div>
                <div class="kpi-value"><?php echo format_currency($qrSales); ?></div>
                <div class="kpi-sub">Online + POS QR payments</div>
            </div>
            <div class="kpi-card slate">
                <div class="kpi-label">Total Transactions</div>
                <div class="kpi-value"><?php echo number_format(count($paid)); ?></div>
                <div class="kpi-sub">Paid in selected period</div>
            </div>
        </div>

        <div class="card">
            <div class="payments-list-header">
                <h3>Payment Transactions</h3>
                <div class="payments-list-toolbar">
                    <div style="position:relative;">
                        <button type="button" class="toolbar-btn<?php echo $sortBy !== 'newest' ? ' active' : ''; ?>" style="height:38px;" @click="sortOpen = !sortOpen; filterOpen = false">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="9" y1="18" x2="15" y2="18"/></svg>
                            Sort by
                        </button>
                        <div class="sort-dropdown" x-show="sortOpen" x-cloak @click.outside="sortOpen = false">
                            <?php
                            $sorts = [
                                'newest' => 'Newest to Oldest',
                                'oldest' => 'Oldest to Newest',
                                'amount_high' => 'Amount: High to Low',
                                'amount_low' => 'Amount: Low to High',
                                'reference_az' => 'Reference A → Z',
                                'reference_za' => 'Reference Z → A',
                            ];
                            foreach ($sorts as $key => $label): ?>
                            <a class="sort-option<?php echo $sortBy === $key ? ' selected' : ''; ?>" href="<?php echo htmlspecialchars($query(['sort' => $key])); ?>">
                                <?php echo htmlspecialchars($label); ?>
                                <?php if ($sortBy === $key): ?>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                                <?php endif; ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div style="position:relative;">
                        <button type="button" class="toolbar-btn<?php echo $activeFilters ? ' active' : ''; ?>" style="height:38px;" @click="filterOpen = !filterOpen; sortOpen = false">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                            Filter
                            <?php if ($activeFilters): ?><span class="filter-badge"><?php echo $activeFilters; ?></span><?php endif; ?>
                        </button>
                        <form class="filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false" method="get">
                            <div class="filter-panel-header">
                                Filter
                                <button type="button" class="filter-reset-link" @click="filterOpen = false">✕</button>
                            </div>
                            <input type="hidden" name="branch_id" value="<?php echo printflow_branch_value_is_all($branchId) ? 'all' : (int)$branchId; ?>">
                            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sortBy); ?>">
                            <div class="filter-section">
                                <div class="filter-section-head">
                                    <span class="filter-section-label">Date range</span>
                                    <button class="filter-reset-link" type="button" onclick="pfReset(['period', 'from', 'to'])">Reset</button>
                                </div>
                                <select class="filter-select" name="period">
                                    <option value="today"<?php echo $period === 'today' ? ' selected' : ''; ?>>Today</option>
                                    <option value="week"<?php echo $period === 'week' ? ' selected' : ''; ?>>This Week</option>
                                    <option value="month"<?php echo $period === 'month' ? ' selected' : ''; ?>>This Month</option>
                                    <option value="all"<?php echo $period === 'all' ? ' selected' : ''; ?>>All Time</option>
                                    <option value="custom"<?php echo $period === 'custom' ? ' selected' : ''; ?>>Custom Range</option>
                                </select>
                                <div class="filter-date-row" style="margin-top:9px;">
                                    <div>
                                        <div class="filter-date-label">From</div>
                                        <input class="filter-input" type="date" name="from" value="<?php echo htmlspecialchars($from); ?>">
                                    </div>
                                    <div>
                                        <div class="filter-date-label">To</div>
                                        <input class="filter-input" type="date" name="to" value="<?php echo htmlspecialchars($to); ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="filter-section">
                                <div class="filter-section-head">
                                    <span class="filter-section-label">Payment method</span>
                                    <button class="filter-reset-link" type="button" onclick="pfReset(['method'])">Reset</button>
                                </div>
                                <select class="filter-select" name="method">
                                    <option value="all">All Methods</option>
                                    <option value="cash"<?php echo $methodFilter === 'cash' ? ' selected' : ''; ?>>Cash</option>
                                    <option value="qrph"<?php echo $methodFilter === 'qrph' ? ' selected' : ''; ?>>QR Ph</option>
                                </select>
                            </div>
                            <div class="filter-section">
                                <div class="filter-section-head">
                                    <span class="filter-section-label">Source</span>
                                    <button class="filter-reset-link" type="button" onclick="pfReset(['source'])">Reset</button>
                                </div>
                                <select class="filter-select" name="source">
                                    <option value="all">All Sources</option>
                                    <option value="online"<?php echo $sourceFilter === 'online' ? ' selected' : ''; ?>>Online</option>
                                    <option value="pos"<?php echo $sourceFilter === 'pos' ? ' selected' : ''; ?>>POS</option>
                                </select>
                            </div>
                            <div class="filter-section">
                                <div class="filter-section-head">
                                    <span class="filter-section-label">Status</span>
                                    <button class="filter-reset-link" type="button" onclick="pfReset(['status'])">Reset</button>
                                </div>
                                <select class="filter-select" name="status">
                                    <option value="all">All Statuses</option>
                                    <option value="paid"<?php echo $statusFilter === 'paid' ? ' selected' : ''; ?>>Paid</option>
                                    <option value="processing"<?php echo $statusFilter === 'processing' ? ' selected' : ''; ?>>Processing</option>
                                    <option value="expired"<?php echo $statusFilter === 'expired' ? ' selected' : ''; ?>>Expired</option>
                                    <option value="failed"<?php echo $statusFilter === 'failed' ? ' selected' : ''; ?>>Failed</option>
                                </select>
                            </div>
                            <div class="filter-section">
                                <div class="filter-section-head">
                                    <span class="filter-section-label">Keyword search</span>
                                    <button class="filter-reset-link" type="button" onclick="pfReset(['search'])">Reset</button>
                                </div>
                                <input class="filter-input" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search reference, order, customer">
                            </div>
                            <div class="filter-actions">
                                <button class="filter-btn-reset" type="button" onclick="pfReset(['period', 'from', 'to', 'method', 'source', 'status', 'search'])">Reset all filters</button>
                                <button class="filter-btn-reset" type="submit" style="margin-top:8px;">Apply filters</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Order / Receipt</th>
                            <th>Customer</th>
                            <th>Source</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Date / Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$visible): ?>
                        <tr>
                            <td colspan="8" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">No payment transactions found.</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($visible as $tx): ?>
                        <tr>
                            <td style="font-weight:500;"><?php echo htmlspecialchars($tx['reference']); ?></td>
                            <td>
                                <?php echo htmlspecialchars($tx['order']); ?>
                                <div class="pf-pay-sub"><?php echo htmlspecialchars($tx['branch']); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars($tx['customer']); ?></td>
                            <td><?php echo htmlspecialchars($tx['source']); ?></td>
                            <td style="font-weight:600;color:#111827;"><?php echo format_currency($tx['amount']); ?></td>
                            <td><?php echo htmlspecialchars($tx['method']); ?></td>
                            <td><?php echo pf_status_badge($tx['status']); ?></td>
                            <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($tx['at']))); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
</div>
<script>
function pfReset(fields) {
    const f = document.querySelector('.filter-panel');
    if (!f) return;
    fields.forEach(function (k) {
        if (!f.elements[k]) return;
        f.elements[k].value = k === 'period' ? 'today' : (['method', 'source', 'status'].includes(k) ? 'all' : '');
    });
    f.submit();
}
</script>
</body>
</html>
