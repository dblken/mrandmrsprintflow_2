<?php
/**
 * Admin / Manager — Expense Management (Phase 1)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/branch_ui.php';
require_once __DIR__ . '/../includes/expense_management.php';

require_role(['Admin', 'Manager']);

if (!isset($base_path)) {
    if (file_exists(__DIR__ . '/../config.php')) {
        require_once __DIR__ . '/../config.php';
    }
    $base_path = defined('BASE_PATH') ? BASE_PATH : '/printflow';
}

[$branchCtx, $branchId, $is_manager, $current_user] = pf_expense_resolve_branch_context();
$isManagerPanel = defined('MANAGER_PANEL') && MANAGER_PANEL;

$search = trim((string)($_GET['search'] ?? ''));
$category_filter = trim((string)($_GET['category'] ?? ''));
$status_filter = trim((string)($_GET['status_filter'] ?? ''));
$date_from = trim((string)($_GET['date_from'] ?? ''));
$date_to = trim((string)($_GET['date_to'] ?? ''));
$sort_by = trim((string)($_GET['sort'] ?? 'newest'));
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;

$allowed_sorts = ['newest', 'oldest', 'az', 'za', 'amount_high', 'amount_low'];
if (!in_array($sort_by, $allowed_sorts, true)) {
    $sort_by = 'newest';
}

$filters = [
    'search' => $search,
    'category' => $category_filter,
    'status_filter' => $status_filter,
    'date_from' => $date_from,
    'date_to' => $date_to,
];

$activeFilterCount = count(array_filter([
    $search !== '' ? 1 : null,
    ($category_filter !== '' && in_array($category_filter, PF_EXPENSE_CATEGORIES, true)) ? 1 : null,
    in_array($status_filter, ['Paid', 'To Be Paid'], true) ? 1 : null,
    ($date_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) ? 1 : null,
    ($date_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) ? 1 : null,
]));

$kpi = pf_expense_kpi_totals($branchId);
$stat_archived = pf_expense_archived_count($branchId);

$branchesForModal = [];
if ($is_manager) {
    $mgrBranchId = (int)(printflow_branch_filter_for_user() ?? ($current_user['branch_id'] ?? 0));
    foreach (($branchCtx['branches_list'] ?? []) as $b) {
        if ((int)($b['id'] ?? 0) === $mgrBranchId) {
            $branchesForModal[] = $b;
            break;
        }
    }
    if (empty($branchesForModal) && $mgrBranchId > 0) {
        $branchesForModal = db_query(
            "SELECT id, branch_name FROM branches WHERE id = ? AND status != 'Archived' LIMIT 1",
            'i',
            [$mgrBranchId]
        ) ?: [];
    }
} else {
    $branchesForModal = db_query(
        "SELECT id, branch_name FROM branches WHERE status != 'Archived' ORDER BY branch_name ASC"
    ) ?: [];
}

$defaultFormBranchId = printflow_branch_value_is_all($branchId)
    ? (int)($branchesForModal[0]['id'] ?? 0)
    : (int)$branchId;

function pf_expense_page_query(array $overrides = []): string {
    $keys = ['search', 'category', 'status_filter', 'date_from', 'date_to', 'branch_id', 'sort', 'page'];
    $q = [];
    foreach ($keys as $key) {
        if (array_key_exists($key, $overrides)) {
            if ($overrides[$key] !== null && $overrides[$key] !== '') {
                $q[$key] = $overrides[$key];
            }
        } elseif (isset($_GET[$key]) && $_GET[$key] !== '') {
            $q[$key] = $_GET[$key];
        }
    }
    return '?' . http_build_query($q);
}

function render_expenses_table(array $expenses, bool $archivedOnly = false): void {
    ?>
    <table class="orders-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Expense</th>
                <th>Category</th>
                <th>Branch</th>
                <th>Amount</th>
                <th>Date</th>
                <th>Status</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody id="expensesTableBody">
            <?php if (empty($expenses)): ?>
                <tr>
                    <td colspan="8" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">
                        <?php echo $archivedOnly ? 'No archived expenses found.' : 'No expenses found.'; ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($expenses as $row): ?>
                    <?php
                    $payload = pf_expense_build_payload($row);
                    $payloadAttr = htmlspecialchars(
                        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    $badge = pf_expense_status_badge((string)($row['status'] ?? ''));
                    $expenseId = (int)($row['expense_id'] ?? 0);
                    $expenseName = htmlspecialchars((string)($row['expense_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $category = htmlspecialchars((string)($row['category'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $branchName = htmlspecialchars((string)($row['branch_name'] ?? '—'), ENT_QUOTES, 'UTF-8');
                    $amount = format_currency((float)($row['amount'] ?? 0));
                    $expenseDate = !empty($row['expense_date']) ? date('M j, Y', strtotime((string)$row['expense_date'])) : '—';
                    ?>
                    <tr class="expense-row" data-expense-id="<?php echo $expenseId; ?>" data-expense="<?php echo $payloadAttr; ?>">
                        <td style="color:#1f2937;"><?php echo $expenseId; ?></td>
                        <td style="font-weight:500;color:#1f2937;max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo $expenseName; ?>"><?php echo $expenseName; ?></td>
                        <td><?php echo $category; ?></td>
                        <td><?php echo $branchName; ?></td>
                        <td style="font-weight:600;color:#111827;"><?php echo $amount; ?></td>
                        <td><?php echo $expenseDate; ?></td>
                        <td>
                            <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;<?php echo $badge['style']; ?>"><?php echo htmlspecialchars($badge['label']); ?></span>
                        </td>
                        <td style="text-align:right;white-space:nowrap;">
                            <div class="expenses-actions">
                                <?php if ($archivedOnly): ?>
                                    <button type="button" class="btn-action teal pf-expense-restore" data-expense-id="<?php echo $expenseId; ?>">Restore</button>
                                <?php else: ?>
                                    <button type="button" class="btn-action blue pf-expense-edit" data-expense-id="<?php echo $expenseId; ?>">Edit</button>
                                    <button type="button" class="btn-action red pf-expense-archive" data-expense-id="<?php echo $expenseId; ?>" data-expense-name="<?php echo $expenseName; ?>">Archive</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
}

if (isset($_GET['get_archived'])) {
    header('Content-Type: application/json; charset=utf-8');
    $parts = pf_expense_list_query_parts(['search' => $search], $branchId, true);
    $archived = db_query(
        "SELECT e.*, b.branch_name
         {$parts['sql']}
         ORDER BY e.archived_at DESC, e.expense_id DESC
         LIMIT 200",
        $parts['types'] ?: null,
        $parts['params'] ?: null
    ) ?: [];

    ob_start();
    render_expenses_table($archived, true);
    $html = ob_get_clean();

    echo json_encode(['success' => true, 'html' => $html, 'csrf_token' => generate_csrf_token()]);
    exit;
}

$parts = pf_expense_list_query_parts($filters, $branchId, false);
$countRow = db_query(
    "SELECT COUNT(*) AS total {$parts['sql']}",
    $parts['types'] ?: null,
    $parts['params'] ?: null
)[0] ?? ['total' => 0];
$total_filtered = (int)($countRow['total'] ?? 0);
$total_pages = max(1, (int)ceil($total_filtered / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;
$orderSql = pf_expense_sort_order_clause($sort_by);

$expenses = db_query(
    "SELECT e.*, b.branch_name, u.first_name, u.last_name
     {$parts['sql']}
     ORDER BY {$orderSql}
     LIMIT {$per_page} OFFSET {$offset}",
    $parts['types'] ?: null,
    $parts['params'] ?: null
) ?: [];

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    ob_start();
    render_expenses_table($expenses, false);
    $table_html = ob_get_clean();
    ob_start();
    $pp = array_filter([
        'search' => $search,
        'category' => $category_filter,
        'status_filter' => $status_filter,
        'sort' => $sort_by !== 'newest' ? $sort_by : null,
        'date_from' => $date_from,
        'date_to' => $date_to,
        'branch_id' => printflow_branch_value_is_all($branchId) ? null : (string)(int)$branchId,
    ], static fn($v) => $v !== null && $v !== '');
    echo render_pagination($page, $total_pages, $pp);
    $pagination_html = ob_get_clean();

    echo json_encode([
        'success' => true,
        'table' => '<div class="overflow-x-auto">' . $table_html . '</div>',
        'pagination' => $pagination_html,
        'badge' => $activeFilterCount,
        'kpis' => array_merge($kpi, ['archived' => $stat_archived]),
        'csrf_token' => generate_csrf_token(),
    ]);
    exit;
}

$apiUrl = rtrim($base_path, '/') . '/admin/api_expenses.php';
$csrfToken = generate_csrf_token();
$page_title = 'Expense Management - PrintFlow';
$sidebar_file = (($current_user['role'] ?? '') === 'Admin') ? 'admin_sidebar.php' : 'manager_sidebar.php';
$branchParam = printflow_branch_value_is_all($branchId) ? 'all' : (string)(int)$branchId;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
<title><?php echo htmlspecialchars($page_title); ?></title>
<?php require_once __DIR__ . '/../includes/favicon_links.php'; ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($base_path); ?>/public/assets/css/output.css">
<?php include __DIR__ . '/../includes/admin_style.php'; ?>
<?php render_branch_css(); ?>
<style>
[x-cloak]{display:none!important}
.btn-action{display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;height:30px;min-height:30px;padding:0 12px;min-width:72px;border:1px solid transparent;background:transparent;border-radius:6px;font-size:12px;font-weight:500;line-height:1;cursor:pointer;white-space:nowrap;text-decoration:none;vertical-align:middle}
.btn-action.teal{color:#14b8a6;border-color:#14b8a6}.btn-action.teal:hover{background:#14b8a6;color:#fff}
.btn-action.blue{color:#3b82f6;border-color:#3b82f6}.btn-action.blue:hover{background:#3b82f6;color:#fff}
.btn-action.red{color:#ef4444;border-color:#ef4444}.btn-action.red:hover{background:#ef4444;color:#fff}
.btn-action.gray{color:#6b7280;border-color:#d1d5db}.btn-action.gray:hover{background:#6b7280;color:#fff}
.expenses-actions{display:inline-flex;align-items:center;justify-content:flex-end;gap:6px;flex-wrap:wrap}
.kpi-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;align-items:stretch}
@media(max-width:900px){.kpi-row{grid-template-columns:repeat(2,1fr)}}
.kpi-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;position:relative;overflow:hidden;height:100%;display:flex;flex-direction:column}
.kpi-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.kpi-card.indigo::before{background:linear-gradient(90deg,#6366f1,#818cf8)}
.kpi-card.emerald::before{background:linear-gradient(90deg,#059669,#34d399)}
.kpi-card.rose::before{background:linear-gradient(90deg,#e11d48,#fb7185)}
.kpi-card.slate::before{background:linear-gradient(90deg,#64748b,#94a3b8)}
.kpi-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#9ca3af;margin-bottom:6px}
.kpi-value{font-size:26px;font-weight:800;color:#111827;line-height:1.15}
.kpi-sub{font-size:12px;color:#6b7280;margin-top:auto}
.toolbar-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:1px solid #e5e7eb;background:#fff;border-radius:8px;font-size:13px;font-weight:500;color:#374151;cursor:pointer;white-space:nowrap;text-decoration:none}
.toolbar-btn:hover{border-color:#9ca3af;background:#f9fafb}
.toolbar-btn.active{border-color:#0d9488;color:#0d9488;background:#f0fdfa}
.sort-dropdown{position:absolute;top:calc(100% + 6px);right:0;width:200px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 10px 15px -3px rgba(0,0,0,.1);z-index:200;padding:6px}
.sort-option{padding:9px 12px;font-size:13px;color:#4b5563;border-radius:6px;cursor:pointer;display:flex;justify-content:space-between;align-items:center}
.sort-option:hover{background:#f9fafb}
.sort-option.selected{background:#f0fdfa;color:#0d9488;font-weight:600}
.filter-panel{position:absolute;top:calc(100% + 6px);right:0;width:320px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.12);z-index:200;overflow:hidden}
.filter-panel-header{padding:14px 18px;border-bottom:1px solid #f3f4f6;font-size:14px;font-weight:700}
.filter-section{padding:14px 18px;border-bottom:1px solid #f3f4f6}
.filter-section-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.filter-section-label{font-size:13px;font-weight:600;color:#374151}
.filter-reset-link{font-size:12px;font-weight:600;color:#0d9488;cursor:pointer;background:none;border:none;padding:0}
.filter-input,.filter-select,.filter-search-input{width:100%;height:34px;border:1px solid #e5e7eb;border-radius:7px;font-size:13px;padding:0 10px;box-sizing:border-box}
.filter-date-row{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.filter-date-label{font-size:11px;color:#6b7280;margin-bottom:4px}
.filter-actions{padding:14px 18px;border-top:1px solid #f3f4f6}
.filter-btn-reset{width:100%;height:36px;border:1px solid #e5e7eb;background:#fff;border-radius:8px;font-size:13px;cursor:pointer}
.filter-badge{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;background:#0d9488;color:#fff;border-radius:50%;font-size:10px;font-weight:700}
.orders-table{width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed}
.orders-table th{padding:12px 16px;font-weight:600;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb}
.orders-table td{padding:12px 16px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
.orders-table tbody tr:hover{background:#f9fafb}
.pf-modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:1000;display:none;align-items:center;justify-content:center;padding:20px}
.pf-modal-overlay.open{display:flex}
.pf-modal{background:#fff;border-radius:14px;width:100%;max-width:560px;max-height:90vh;overflow:auto;box-shadow:0 20px 50px rgba(0,0,0,.18)}
.pf-modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid #f3f4f6}
.pf-modal-header h3{margin:0;font-size:18px;font-weight:700;color:#111827}
.pf-modal-close{border:0;background:transparent;color:#6b7280;cursor:pointer;width:32px;height:32px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center}
.pf-modal-close:hover{background:#f3f4f6}
.pf-modal-body{padding:20px 22px}
.pf-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.pf-form-group{display:flex;flex-direction:column;gap:6px}
.pf-form-group.full{grid-column:1/-1}
.pf-form-group label{font-size:12px;font-weight:600;color:#374151}
.pf-form-group input,.pf-form-group select,.pf-form-group textarea{border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;font-size:13px;color:#1f2937;background:#fff}
.pf-form-group input:disabled,.pf-form-group select:disabled{background:#f9fafb;color:#6b7280}
.pf-form-group textarea{min-height:90px;resize:vertical}
.pf-field-error{font-size:12px;color:#dc2626}
.pf-modal-footer{display:flex;justify-content:flex-end;gap:10px;padding:16px 22px;border-top:1px solid #f3f4f6}
.pf-flash{margin:0 0 16px;padding:12px 16px;border-radius:8px;font-size:13px;display:none}
.pf-flash.show{display:block}
.pf-flash.success{background:#f0fdf4;color:#166534;border:1px solid #86efac}
.pf-flash.error{background:#fef2f2;color:#dc2626;border:1px solid #fca5a5}
@media(max-width:900px){.pf-form-grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="dashboard-container">
<?php include __DIR__ . '/../includes/' . $sidebar_file; ?>
<div class="main-content">
    <header class="pf-mobile-branch-inline">
        <h1 class="page-title">Expense Management</h1>
        <?php if (!$isManagerPanel): render_branch_selector($branchCtx); endif; ?>
    </header>
    <main>
        <?php render_branch_context_banner($branchCtx['branch_name']); ?>

        <div id="pfExpenseFlash" class="pf-flash"></div>

        <div class="kpi-row">
            <div class="kpi-card indigo">
                <div class="kpi-label">Total Expenses</div>
                <div class="kpi-value" id="kpiTotalExpenses"><?php echo format_currency($kpi['total_expenses']); ?></div>
                <div class="kpi-sub">Paid expenses only</div>
            </div>
            <div class="kpi-card emerald">
                <div class="kpi-label">This Month</div>
                <div class="kpi-value" id="kpiThisMonth"><?php echo format_currency($kpi['this_month']); ?></div>
                <div class="kpi-sub"><?php echo date('F Y'); ?> paid expenses</div>
            </div>
            <div class="kpi-card rose">
                <div class="kpi-label">Pending Expenses</div>
                <div class="kpi-value" id="kpiPending"><?php echo format_currency($kpi['pending_expenses']); ?></div>
                <div class="kpi-sub"><span id="kpiPendingCount"><?php echo number_format($kpi['pending_count']); ?></span> to be paid</div>
            </div>
            <div class="kpi-card slate">
                <div class="kpi-label">Archived</div>
                <div class="kpi-value" id="kpiArchived"><?php echo number_format($stat_archived); ?></div>
                <div class="kpi-sub">In archive storage</div>
            </div>
        </div>

        <div class="card">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px;" x-data="filterPanel()">
                <h3 style="font-size:16px;font-weight:700;color:#1f2937;margin:0;" id="expensesListHeader">Expense List</h3>
                <div style="display:flex;align-items:center;gap:8px;">
                    <button type="button" class="toolbar-btn" id="btnAddExpense" style="height:38px;border-color:#3b82f6;color:#3b82f6;">Add Expense</button>
                    <button type="button" class="toolbar-btn" id="btnViewArchived" style="height:38px;border-color:#6b7280;color:#6b7280;display:flex;align-items:center;gap:6px;">
                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                        Archived
                    </button>
                    <div style="position:relative;">
                        <button type="button" class="toolbar-btn" :class="{active: sortOpen || (activeSort !== 'newest')}" @click="sortOpen = !sortOpen; filterOpen = false" style="height:38px;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="6" y1="12" x2="18" y2="12"/><line x1="9" y1="18" x2="15" y2="18"/></svg>
                            Sort by
                        </button>
                        <div class="sort-dropdown" x-show="sortOpen" x-cloak @click.outside="sortOpen = false">
                            <?php
                            $sorts = [
                                'newest' => 'Newest to Oldest',
                                'oldest' => 'Oldest to Newest',
                                'az' => 'A → Z',
                                'za' => 'Z → A',
                                'amount_high' => 'Amount: High to Low',
                                'amount_low' => 'Amount: Low to High',
                            ];
                            foreach ($sorts as $key => $label): ?>
                            <div class="sort-option" :class="{ 'selected': activeSort === '<?php echo $key; ?>' }" onclick="applySortFilter('<?php echo $key; ?>')">
                                <?php echo htmlspecialchars($label); ?>
                                <svg x-show="activeSort === '<?php echo $key; ?>'" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div style="position:relative;">
                        <button type="button" class="toolbar-btn" :class="{active: filterOpen || hasActiveFilters}" @click="filterOpen = !filterOpen; sortOpen = false" style="height:38px;">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                            Filter
                            <span id="filterBadgeContainer">
                                <?php if ($activeFilterCount > 0): ?><span class="filter-badge"><?php echo $activeFilterCount; ?></span><?php endif; ?>
                            </span>
                        </button>
                        <div class="filter-panel" x-show="filterOpen" x-cloak @click.outside="filterOpen = false">
                            <div class="filter-panel-header">Filter</div>
                            <div class="filter-section">
                                <div class="filter-section-head">
                                    <span class="filter-section-label">Date range</span>
                                    <button class="filter-reset-link" type="button" onclick="resetFilterField(['date_from','date_to'])">Reset</button>
                                </div>
                                <div class="filter-date-row">
                                    <div><div class="filter-date-label">From</div><input type="date" id="fp_date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from, ENT_QUOTES, 'UTF-8'); ?>"></div>
                                    <div><div class="filter-date-label">To</div><input type="date" id="fp_date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to, ENT_QUOTES, 'UTF-8'); ?>"></div>
                                </div>
                            </div>
                            <div class="filter-section">
                                <div class="filter-section-head">
                                    <span class="filter-section-label">Category</span>
                                    <button class="filter-reset-link" type="button" onclick="resetFilterField(['category'])">Reset</button>
                                </div>
                                <select id="fp_category" class="filter-select">
                                    <option value="">All categories</option>
                                    <?php foreach (PF_EXPENSE_CATEGORIES as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $category_filter === $cat ? ' selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-section">
                                <div class="filter-section-head">
                                    <span class="filter-section-label">Status</span>
                                    <button class="filter-reset-link" type="button" onclick="resetFilterField(['status_filter'])">Reset</button>
                                </div>
                                <select id="fp_status" class="filter-select">
                                    <option value="">All statuses</option>
                                    <option value="Paid"<?php echo $status_filter === 'Paid' ? ' selected' : ''; ?>>Paid</option>
                                    <option value="To Be Paid"<?php echo $status_filter === 'To Be Paid' ? ' selected' : ''; ?>>To Be Paid</option>
                                </select>
                            </div>
                            <div class="filter-section">
                                <div class="filter-section-head">
                                    <span class="filter-section-label">Search</span>
                                    <button class="filter-reset-link" type="button" onclick="resetFilterField(['search'])">Reset</button>
                                </div>
                                <input type="text" id="fp_search" class="filter-search-input" placeholder="Expense name, category, or ID..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="filter-actions">
                                <button type="button" class="filter-btn-reset" onclick="applyFilters(true)">Reset all filters</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="expensesTableContainer">
                <div class="overflow-x-auto">
                    <?php render_expenses_table($expenses, false); ?>
                </div>
                <div id="expensesPagination">
                    <?php
                    $pagination_params = array_filter([
                        'search' => $search,
                        'category' => $category_filter,
                        'status_filter' => $status_filter,
                        'sort' => $sort_by !== 'newest' ? $sort_by : null,
                        'date_from' => $date_from,
                        'date_to' => $date_to,
                        'branch_id' => printflow_branch_value_is_all($branchId) ? null : (string)(int)$branchId,
                    ], static fn($v) => $v !== null && $v !== '');
                    echo render_pagination($page, $total_pages, $pagination_params);
                    ?>
                </div>
            </div>
        </div>
    </main>
</div>
</div>

<div class="pf-modal-overlay" id="expenseFormModal" aria-hidden="true">
    <div class="pf-modal" role="dialog" aria-modal="true" aria-labelledby="expenseModalTitle">
        <div class="pf-modal-header">
            <h3 id="expenseModalTitle">Add Expense</h3>
            <button type="button" class="pf-modal-close" data-close-modal="expenseFormModal" aria-label="Close">&times;</button>
        </div>
        <form id="expenseForm">
            <div class="pf-modal-body">
                <input type="hidden" name="expense_id" id="expenseIdField" value="">
                <div class="pf-form-grid">
                    <div class="pf-form-group full">
                        <label for="expenseName">Expense Name</label>
                        <input type="text" id="expenseName" name="expense_name" maxlength="255" required>
                        <span class="pf-field-error" data-error-for="expense_name"></span>
                    </div>
                    <div class="pf-form-group">
                        <label for="expenseCategory">Category</label>
                        <select id="expenseCategory" name="category" required>
                            <option value="">Select category</option>
                            <?php foreach (PF_EXPENSE_CATEGORIES as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="pf-field-error" data-error-for="category"></span>
                    </div>
                    <div class="pf-form-group">
                        <label for="expenseBranch">Branch</label>
                        <select id="expenseBranch"<?php echo $is_manager ? ' disabled' : ' name="branch_id" required'; ?>>
                            <?php foreach ($branchesForModal as $b): ?>
                                <option value="<?php echo (int)($b['id'] ?? 0); ?>"<?php echo (int)($b['id'] ?? 0) === $defaultFormBranchId ? ' selected' : ''; ?>><?php echo htmlspecialchars((string)($b['branch_name'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($is_manager): ?>
                            <input type="hidden" id="expenseBranchHidden" name="branch_id" value="<?php echo (int)($branchesForModal[0]['id'] ?? 0); ?>">
                        <?php endif; ?>
                        <span class="pf-field-error" data-error-for="branch_id"></span>
                    </div>
                    <div class="pf-form-group">
                        <label for="expenseAmount">Amount</label>
                        <input type="number" id="expenseAmount" name="amount" min="0.01" step="0.01" required>
                        <span class="pf-field-error" data-error-for="amount"></span>
                    </div>
                    <div class="pf-form-group">
                        <label for="expenseDate">Date</label>
                        <input type="date" id="expenseDate" name="expense_date" required>
                        <span class="pf-field-error" data-error-for="expense_date"></span>
                    </div>
                    <div class="pf-form-group">
                        <label for="expenseStatus">Status</label>
                        <select id="expenseStatus" name="status" required>
                            <option value="To Be Paid">To Be Paid</option>
                            <option value="Paid">Paid</option>
                        </select>
                        <span class="pf-field-error" data-error-for="status"></span>
                    </div>
                    <div class="pf-form-group">
                        <label for="expensePaymentMethod">Payment Method</label>
                        <select id="expensePaymentMethod" name="payment_method">
                            <option value="">Select method</option>
                            <?php foreach (PF_EXPENSE_PAYMENT_METHODS as $pm): ?>
                                <option value="<?php echo htmlspecialchars($pm, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($pm); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="pf-field-error" data-error-for="payment_method"></span>
                    </div>
                    <div class="pf-form-group full">
                        <label for="expenseNotes">Notes</label>
                        <textarea id="expenseNotes" name="notes" maxlength="2000" placeholder="Optional notes"></textarea>
                        <span class="pf-field-error" data-error-for="notes"></span>
                    </div>
                </div>
            </div>
            <div class="pf-modal-footer">
                <button type="button" class="toolbar-btn" data-close-modal="expenseFormModal">Cancel</button>
                <button type="submit" class="toolbar-btn" id="expenseFormSubmit" style="background:#0d9488;color:#fff;border-color:#0d9488;">Save Expense</button>
            </div>
        </form>
    </div>
</div>

<div class="pf-modal-overlay" id="archivedModal" aria-hidden="true">
    <div class="pf-modal" role="dialog" aria-modal="true" style="max-width:900px;">
        <div class="pf-modal-header">
            <h3>Archived Expenses</h3>
            <button type="button" class="pf-modal-close" data-close-modal="archivedModal" aria-label="Close">&times;</button>
        </div>
        <div class="pf-modal-body" id="archivedModalBody">
            <div style="padding:30px;text-align:center;color:#9ca3af;">Loading...</div>
        </div>
    </div>
</div>

<div class="pf-modal-overlay" id="archiveConfirmModal" aria-hidden="true">
    <div class="pf-modal" role="dialog" aria-modal="true" style="max-width:420px;">
        <div class="pf-modal-header">
            <h3>Archive Expense</h3>
            <button type="button" class="pf-modal-close" data-close-modal="archiveConfirmModal" aria-label="Close">&times;</button>
        </div>
        <div class="pf-modal-body">
            <p style="margin:0;color:#374151;font-size:14px;line-height:1.5;">Archive <strong id="archiveConfirmName"></strong>? It will be removed from active totals and lists.</p>
        </div>
        <div class="pf-modal-footer">
            <button type="button" class="toolbar-btn" data-close-modal="archiveConfirmModal">Cancel</button>
            <button type="button" class="toolbar-btn" id="archiveConfirmBtn" style="background:#b91c1c;color:#fff;border-color:#b91c1c;">Archive</button>
        </div>
    </div>
</div>

<script>
const apiUrl = <?php echo json_encode($apiUrl); ?>;
let csrfToken = <?php echo json_encode($csrfToken); ?>;
const isManager = <?php echo $is_manager ? 'true' : 'false'; ?>;
const defaultBranchId = <?php echo json_encode($defaultFormBranchId); ?>;
const branchParam = <?php echo json_encode($branchParam); ?>;
let activeSort = <?php echo json_encode($sort_by); ?>;
let archiveTargetId = 0;
let searchDebounceTimer = null;

function qs(sel, root) { return (root || document).querySelector(sel); }
function qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

function formatMoney(value) {
    const num = Number(value || 0);
    return '₱ ' + num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function showFlash(message, type) {
    const el = qs('#pfExpenseFlash');
    if (!el) return;
    el.textContent = message;
    el.className = 'pf-flash show ' + (type || 'success');
    window.setTimeout(() => { el.className = 'pf-flash'; }, 4500);
}

function openModal(id) {
    const modal = qs('#' + id);
    if (!modal) return;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
}

function closeModal(id) {
    const modal = qs('#' + id);
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
}

function clearFormErrors() {
    qsa('[data-error-for]').forEach(el => { el.textContent = ''; });
}

function applyFormErrors(errors) {
    Object.keys(errors || {}).forEach(key => {
        const el = qs('[data-error-for="' + key + '"]');
        if (el) el.textContent = errors[key];
    });
}

function updateCsrfToken(token) {
    if (!token) return;
    csrfToken = token;
    const meta = qs('meta[name="csrf-token"]');
    if (meta) meta.setAttribute('content', token);
}

function refreshKpis(kpis) {
    if (!kpis) return;
    const total = qs('#kpiTotalExpenses');
    const month = qs('#kpiThisMonth');
    const pending = qs('#kpiPending');
    const pendingCount = qs('#kpiPendingCount');
    const archived = qs('#kpiArchived');
    if (total) total.textContent = formatMoney(kpis.total_expenses);
    if (month) month.textContent = formatMoney(kpis.this_month);
    if (pending) pending.textContent = formatMoney(kpis.pending_expenses);
    if (pendingCount) pendingCount.textContent = Number(kpis.pending_count || 0).toLocaleString();
    if (archived) archived.textContent = Number(kpis.archived || 0).toLocaleString();
}

function filterPanel() {
    return {
        sortOpen: false,
        filterOpen: false,
        activeSort: activeSort,
        get hasActiveFilters() {
            return document.getElementById('fp_date_from')?.value ||
                document.getElementById('fp_date_to')?.value ||
                document.getElementById('fp_category')?.value ||
                document.getElementById('fp_status')?.value ||
                document.getElementById('fp_search')?.value;
        }
    };
}

function buildFilterURL(page = 1) {
    const params = new URLSearchParams();
    params.set('page', page);
    if (branchParam && branchParam !== 'all') params.set('branch_id', branchParam);
    const df = document.getElementById('fp_date_from')?.value; if (df) params.set('date_from', df);
    const dt = document.getElementById('fp_date_to')?.value; if (dt) params.set('date_to', dt);
    const cat = document.getElementById('fp_category')?.value; if (cat) params.set('category', cat);
    const st = document.getElementById('fp_status')?.value; if (st) params.set('status_filter', st);
    const s = document.getElementById('fp_search')?.value; if (s) params.set('search', s);
    if (activeSort !== 'newest') params.set('sort', activeSort);
    return '?' + params.toString();
}

function fetchUpdatedTable(page = 1) {
    fetch(window.location.pathname + buildFilterURL(page) + '&ajax=1', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            updateCsrfToken(data.csrf_token);
            const wrap = document.getElementById('expensesTableContainer');
            if (wrap) {
                wrap.innerHTML = data.table + '<div id="expensesPagination">' + data.pagination + '</div>';
                bindTableActions();
                bindPaginationLinks();
            }
            const cont = document.getElementById('filterBadgeContainer');
            if (cont) cont.innerHTML = data.badge > 0 ? '<span class="filter-badge">' + data.badge + '</span>' : '';
            refreshKpis(data.kpis);
            history.replaceState(null, '', buildFilterURL(page));
        })
        .catch(() => showFlash('Failed to refresh expense list.', 'error'));
}

function applyFilters(reset = false) {
    if (reset) {
        ['fp_date_from', 'fp_date_to', 'fp_category', 'fp_status', 'fp_search'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        activeSort = 'newest';
    }
    fetchUpdatedTable(1);
}

function resetFilterField(fields) {
    const map = { date_from: 'fp_date_from', date_to: 'fp_date_to', category: 'fp_category', status_filter: 'fp_status', search: 'fp_search' };
    fields.forEach(f => {
        const el = document.getElementById(map[f] || f);
        if (el) el.value = '';
    });
    fetchUpdatedTable(1);
}

function applySortFilter(sortKey) {
    activeSort = sortKey;
    fetchUpdatedTable(1);
    const alpineEl = document.querySelector('[x-data="filterPanel()"]');
    if (alpineEl && alpineEl._x_dataStack) {
        alpineEl._x_dataStack[0].activeSort = sortKey;
        alpineEl._x_dataStack[0].sortOpen = false;
    }
}

function bindPaginationLinks() {
    qsa('#expensesPagination a[data-page], #expensesPagination .pagination-link').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const page = parseInt(link.getAttribute('data-page') || link.dataset.page || '1', 10);
            fetchUpdatedTable(page);
        });
    });
}

async function postExpense(action, payload) {
    const body = Object.assign({}, payload, { action, csrf_token: csrfToken });
    const resp = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(body)
    });
    const data = await resp.json().catch(() => ({}));
    updateCsrfToken(data.csrf_token);
    return { ok: resp.ok, status: resp.status, data };
}

function resetExpenseForm() {
    const form = qs('#expenseForm');
    form.reset();
    qs('#expenseIdField').value = '';
    qs('#expenseModalTitle').textContent = 'Add Expense';
    qs('#expenseFormSubmit').textContent = 'Save Expense';
    qs('#expenseDate').value = new Date().toISOString().slice(0, 10);
    if (defaultBranchId) {
        qs('#expenseBranch').value = String(defaultBranchId);
        const hidden = qs('#expenseBranchHidden');
        if (hidden) hidden.value = String(defaultBranchId);
    }
    clearFormErrors();
    togglePaymentMethodRequired();
}

function fillExpenseForm(data) {
    qs('#expenseIdField').value = data.expense_id || '';
    qs('#expenseName').value = data.expense_name || '';
    qs('#expenseCategory').value = data.category || '';
    qs('#expenseBranch').value = String(data.branch_id || defaultBranchId);
    if (isManager) {
        const hidden = qs('#expenseBranchHidden');
        if (hidden) hidden.value = String(data.branch_id || defaultBranchId);
    }
    qs('#expenseAmount').value = data.amount != null ? Number(data.amount).toFixed(2) : '';
    qs('#expenseDate').value = data.expense_date || '';
    qs('#expenseStatus').value = data.status || 'To Be Paid';
    qs('#expensePaymentMethod').value = data.payment_method || '';
    qs('#expenseNotes').value = data.notes || '';
    qs('#expenseModalTitle').textContent = 'Edit Expense';
    qs('#expenseFormSubmit').textContent = 'Update Expense';
    clearFormErrors();
    togglePaymentMethodRequired();
}

function togglePaymentMethodRequired() {
    const status = qs('#expenseStatus').value;
    qs('#expensePaymentMethod').required = status === 'Paid';
}

function bindTableActions() {
    qsa('.pf-expense-edit').forEach(btn => {
        btn.onclick = () => {
            const row = btn.closest('.expense-row');
            if (!row) return;
            try {
                fillExpenseForm(JSON.parse(row.getAttribute('data-expense') || '{}'));
                openModal('expenseFormModal');
            } catch (e) {
                showFlash('Unable to load expense details.', 'error');
            }
        };
    });

    qsa('.pf-expense-archive').forEach(btn => {
        btn.onclick = () => {
            archiveTargetId = parseInt(btn.getAttribute('data-expense-id') || '0', 10);
            qs('#archiveConfirmName').textContent = btn.getAttribute('data-expense-name') || 'this expense';
            openModal('archiveConfirmModal');
        };
    });

    qsa('.pf-expense-restore').forEach(btn => {
        btn.onclick = async () => {
            const expenseId = parseInt(btn.getAttribute('data-expense-id') || '0', 10);
            if (!expenseId) return;
            btn.disabled = true;
            const result = await postExpense('restore', { expense_id: expenseId });
            if (result.ok && result.data.success) {
                showFlash('Expense restored successfully.');
                loadArchivedList();
                fetchUpdatedTable(1);
            } else {
                showFlash(result.data.error || 'Failed to restore expense.', 'error');
                btn.disabled = false;
            }
        };
    });
}

async function loadArchivedList() {
    const params = new URLSearchParams(buildFilterURL(1).slice(1));
    params.set('get_archived', '1');
    params.delete('ajax');
    qs('#archivedModalBody').innerHTML = '<div style="padding:30px;text-align:center;color:#9ca3af;">Loading...</div>';
    try {
        const resp = await fetch(window.location.pathname + '?' + params.toString());
        const data = await resp.json();
        updateCsrfToken(data.csrf_token);
        qs('#archivedModalBody').innerHTML = data.html || '<div style="padding:30px;text-align:center;color:#9ca3af;">No archived expenses.</div>';
        bindTableActions();
    } catch (e) {
        qs('#archivedModalBody').innerHTML = '<div style="padding:30px;text-align:center;color:#dc2626;">Failed to load archived expenses.</div>';
    }
}

function initExpensesPage() {
    qsa('[data-close-modal]').forEach(btn => {
        btn.addEventListener('click', () => closeModal(btn.getAttribute('data-close-modal')));
    });

    qsa('.pf-modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeModal(overlay.id);
        });
    });

    qs('#btnAddExpense').addEventListener('click', () => {
        resetExpenseForm();
        openModal('expenseFormModal');
    });

    qs('#btnViewArchived').addEventListener('click', () => {
        openModal('archivedModal');
        loadArchivedList();
    });

    qs('#expenseStatus').addEventListener('change', togglePaymentMethodRequired);

    qs('#expenseForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        clearFormErrors();
        const form = e.target;
        const expenseId = parseInt(qs('#expenseIdField').value || '0', 10);
        const payload = {
            expense_name: form.expense_name.value.trim(),
            category: form.category.value,
            branch_id: parseInt((isManager ? qs('#expenseBranchHidden') : form.branch_id).value || '0', 10),
            amount: form.amount.value,
            expense_date: form.expense_date.value,
            status: form.status.value,
            payment_method: form.payment_method.value,
            notes: form.notes.value.trim()
        };
        const action = expenseId > 0 ? 'update' : 'create';
        if (expenseId > 0) payload.expense_id = expenseId;

        const submitBtn = qs('#expenseFormSubmit');
        submitBtn.disabled = true;
        const result = await postExpense(action, payload);
        submitBtn.disabled = false;

        if (result.ok && result.data.success) {
            closeModal('expenseFormModal');
            showFlash(expenseId > 0 ? 'Expense updated successfully.' : 'Expense added successfully.');
            fetchUpdatedTable(1);
            return;
        }

        if (result.data.errors) applyFormErrors(result.data.errors);
        showFlash(result.data.error || 'Unable to save expense.', 'error');
    });

    qs('#archiveConfirmBtn').addEventListener('click', async () => {
        if (!archiveTargetId) return;
        const btn = qs('#archiveConfirmBtn');
        btn.disabled = true;
        const result = await postExpense('archive', { expense_id: archiveTargetId });
        btn.disabled = false;
        if (result.ok && result.data.success) {
            closeModal('archiveConfirmModal');
            showFlash('Expense archived successfully.');
            fetchUpdatedTable(1);
        } else {
            showFlash(result.data.error || 'Failed to archive expense.', 'error');
        }
    });

    ['fp_date_from', 'fp_date_to', 'fp_category', 'fp_status'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', () => fetchUpdatedTable(1));
    });

    const searchInput = document.getElementById('fp_search');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => fetchUpdatedTable(1), 450);
        });
    }

    bindTableActions();
    bindPaginationLinks();
    togglePaymentMethodRequired();
}

document.addEventListener('DOMContentLoaded', initExpensesPage);
</script>
</body>
</html>
