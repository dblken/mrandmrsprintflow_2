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
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;

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

function pf_expense_page_query(array $overrides = []): string {
    $keys = ['search', 'category', 'status_filter', 'date_from', 'date_to', 'branch_id', 'page'];
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

function pf_expense_render_table_rows(array $expenses, string $base_path, bool $archivedOnly = false): void {
    if (empty($expenses)) {
        $colspan = 8;
        echo '<tr><td colspan="' . $colspan . '" style="padding:40px;text-align:center;color:#9ca3af;font-size:14px;">'
            . ($archivedOnly ? 'No archived expenses found.' : 'No expenses found.')
            . '</td></tr>';
        return;
    }

    foreach ($expenses as $row) {
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

        echo '<tr class="expense-row" data-expense-id="' . $expenseId . '" data-expense="' . $payloadAttr . '">';
        echo '<td style="color:#1f2937;">' . $expenseId . '</td>';
        echo '<td style="font-weight:500;color:#1f2937;max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="' . $expenseName . '">' . $expenseName . '</td>';
        echo '<td>' . $category . '</td>';
        echo '<td>' . $branchName . '</td>';
        echo '<td style="font-weight:600;color:#111827;">' . $amount . '</td>';
        echo '<td>' . $expenseDate . '</td>';
        echo '<td><span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;' . $badge['style'] . '">' . htmlspecialchars($badge['label']) . '</span></td>';
        echo '<td style="text-align:right;white-space:nowrap;">';
        if ($archivedOnly) {
            echo '<button type="button" class="btn-action teal pf-expense-restore" data-expense-id="' . $expenseId . '">Restore</button>';
        } else {
            echo '<button type="button" class="btn-action teal pf-expense-edit" data-expense-id="' . $expenseId . '">Edit</button>';
            echo ' <button type="button" class="btn-action red pf-expense-archive" data-expense-id="' . $expenseId . '" data-expense-name="' . $expenseName . '">Archive</button>';
        }
        echo '</td></tr>';
    }
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
    echo '<table class="orders-table" style="width:100%;"><thead><tr>';
    echo '<th>ID</th><th>Expense</th><th>Category</th><th>Branch</th><th>Amount</th><th>Date</th><th style="text-align:right;">Actions</th>';
    echo '</tr></thead><tbody>';
    pf_expense_render_table_rows($archived, $base_path, true);
    echo '</tbody></table>';
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

$expenses = db_query(
    "SELECT e.*, b.branch_name, u.first_name, u.last_name
     {$parts['sql']}
     ORDER BY e.expense_date DESC, e.expense_id DESC
     LIMIT {$per_page} OFFSET {$offset}",
    $parts['types'] ?: null,
    $parts['params'] ?: null
) ?: [];

if (isset($_GET['ajax'])) {
    ob_start();
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
                <th style="text-align:right;" class="no-print">Actions</th>
            </tr>
        </thead>
        <tbody id="expensesTableBody">
            <?php pf_expense_render_table_rows($expenses, $base_path, false); ?>
        </tbody>
    </table>
    <?php if ($total_pages > 1): ?>
    <div class="pagination-wrap" style="margin-top:16px;display:flex;justify-content:center;gap:8px;flex-wrap:wrap;">
        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
            <a href="<?php echo htmlspecialchars(pf_expense_page_query(['page' => $p])); ?>"
               class="toolbar-btn<?php echo $p === $page ? ' active' : ''; ?>"
               data-page="<?php echo $p; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
    <?php
    echo ob_get_clean();
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
.exp-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:0 0 24px}
.exp-kpi{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;position:relative;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.exp-kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#00232b,#53C5E0)}
.exp-kpi:nth-child(2)::before{background:linear-gradient(90deg,#059669,#34d399)}
.exp-kpi:nth-child(3)::before{background:linear-gradient(90deg,#f59e0b,#fbbf24)}
.exp-kpi-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:6px}
.exp-kpi-value{font-size:26px;font-weight:800;color:#111827;line-height:1.15}
.exp-kpi-sub{font-size:12px;color:#6b7280;margin-top:6px}
.exp-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.exp-toolbar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:18px}
.exp-toolbar-left,.exp-toolbar-right{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.exp-search-wrap{position:relative;min-width:220px;flex:1;max-width:320px}
.exp-search-wrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;pointer-events:none}
.exp-search{width:100%;height:36px;border:1px solid #e5e7eb;border-radius:10px;padding:0 12px 0 38px;font-size:13px;color:#1f2937;box-sizing:border-box}
.exp-search:focus{outline:none;border-color:#53C5E0;box-shadow:0 0 0 3px rgba(83,197,224,.15)}
.toolbar-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:36px;padding:0 14px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;color:#111827;font-size:13px;font-weight:500;cursor:pointer;transition:all .2s;text-decoration:none;white-space:nowrap}
.toolbar-btn:hover{border-color:#9ca3af;background:#f9fafb}
.toolbar-btn.active,.toolbar-btn.primary{border-color:#00232b;color:#00232b;background:#ecf8fb}
.toolbar-btn.primary{background:#00232b;color:#fff;border-color:#00232b}
.toolbar-btn.primary:hover{background:#003945;border-color:#003945;color:#fff}
.filter-panel{position:absolute;top:calc(100% + 6px);right:0;width:320px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.12);z-index:200;display:none}
.filter-panel.open{display:block}
.filter-panel-header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #f3f4f6;font-size:14px;font-weight:700;color:#111827}
.filter-section{padding:14px 18px;border-bottom:1px solid #f3f4f6}
.filter-section-label{font-size:13px;font-weight:600;color:#374151;margin-bottom:8px;display:block}
.filter-input,.filter-select{width:100%;height:34px;border:1px solid #e5e7eb;border-radius:7px;font-size:13px;padding:0 10px;color:#1f2937;background:#fff;box-sizing:border-box}
.filter-date-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px}
.filter-date-label{font-size:11px;color:#6b7280;margin-bottom:4px}
.filter-actions{padding:14px 18px;display:flex;flex-direction:column;gap:8px}
.filter-badge{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;background:#0d9488;color:#fff;border-radius:999px;font-size:10px;font-weight:700}
.btn-action{display:inline-flex;align-items:center;justify-content:center;padding:6px 12px;border:1px solid transparent;background:transparent;border-radius:6px;font-size:12px;font-weight:500;transition:all .2s;cursor:pointer;text-decoration:none}
.btn-action.teal{color:#14b8a6;border-color:#14b8a6}.btn-action.teal:hover{background:#14b8a6;color:#fff}
.btn-action.red{color:#ef4444;border-color:#ef4444}.btn-action.red:hover{background:#ef4444;color:#fff}
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
.pf-flash{margin:0 0 16px;padding:12px 16px;border-radius:10px;font-size:13px;display:none}
.pf-flash.show{display:block}
.pf-flash.success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.pf-flash.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
@media(max-width:900px){.exp-kpis{grid-template-columns:1fr}.pf-form-grid{grid-template-columns:1fr}.exp-search-wrap{max-width:none;flex-basis:100%}}
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/' . $sidebar_file; ?>
<div class="main-content">
    <header class="pf-mobile-branch-inline">
        <h1 class="page-title">Expense Management</h1>
        <?php if (!$isManagerPanel): render_branch_selector($branchCtx); endif; ?>
    </header>
    <main x-data="{ filterOpen: false }">
        <?php render_branch_context_banner($branchCtx['branch_name']); ?>

        <div id="pfExpenseFlash" class="pf-flash"></div>

        <div class="exp-kpis">
            <div class="exp-kpi">
                <div class="exp-kpi-label">Total Expenses</div>
                <div class="exp-kpi-value" id="kpiTotalExpenses"><?php echo format_currency($kpi['total_expenses']); ?></div>
                <div class="exp-kpi-sub">Paid expenses only</div>
            </div>
            <div class="exp-kpi">
                <div class="exp-kpi-label">This Month</div>
                <div class="exp-kpi-value" id="kpiThisMonth"><?php echo format_currency($kpi['this_month']); ?></div>
                <div class="exp-kpi-sub"><?php echo date('F Y'); ?> paid expenses</div>
            </div>
            <div class="exp-kpi">
                <div class="exp-kpi-label">Pending Expenses</div>
                <div class="exp-kpi-value" id="kpiPending"><?php echo format_currency($kpi['pending_expenses']); ?></div>
                <div class="exp-kpi-sub"><?php echo number_format($kpi['pending_count']); ?> to be paid</div>
            </div>
        </div>

        <div class="exp-card">
            <div class="exp-toolbar">
                <div class="exp-toolbar-left">
                    <div class="exp-search-wrap">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                        <input type="search" id="expenseSearch" class="exp-search" placeholder="Search expenses..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>
                <div class="exp-toolbar-right">
                    <div style="position:relative;">
                        <button type="button" class="toolbar-btn<?php echo $activeFilterCount ? ' active' : ''; ?>" @click="filterOpen = !filterOpen">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                            Filter
                            <?php if ($activeFilterCount): ?><span class="filter-badge"><?php echo $activeFilterCount; ?></span><?php endif; ?>
                        </button>
                        <form id="expenseFilterForm" class="filter-panel" :class="{ 'open': filterOpen }" method="get" @click.outside="filterOpen = false">
                            <div class="filter-panel-header">Filter Expenses</div>
                            <input type="hidden" name="branch_id" value="<?php echo htmlspecialchars($branchParam, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="search" id="filterSearchHidden" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="filter-section">
                                <label class="filter-section-label">Category</label>
                                <select class="filter-select" name="category">
                                    <option value="">All Categories</option>
                                    <?php foreach (PF_EXPENSE_CATEGORIES as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $category_filter === $cat ? ' selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-section">
                                <label class="filter-section-label">Status</label>
                                <select class="filter-select" name="status_filter">
                                    <option value="">All Statuses</option>
                                    <option value="Paid"<?php echo $status_filter === 'Paid' ? ' selected' : ''; ?>>Paid</option>
                                    <option value="To Be Paid"<?php echo $status_filter === 'To Be Paid' ? ' selected' : ''; ?>>To Be Paid</option>
                                </select>
                            </div>
                            <div class="filter-section">
                                <label class="filter-section-label">Date Range</label>
                                <div class="filter-date-row">
                                    <div>
                                        <div class="filter-date-label">From</div>
                                        <input class="filter-input" type="date" name="date_from" value="<?php echo htmlspecialchars($date_from, ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div>
                                        <div class="filter-date-label">To</div>
                                        <input class="filter-input" type="date" name="date_to" value="<?php echo htmlspecialchars($date_to, ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="filter-actions">
                                <a href="<?php echo htmlspecialchars(pf_expense_page_query(['search' => $search, 'category' => null, 'status_filter' => null, 'date_from' => null, 'date_to' => null, 'page' => 1])); ?>" class="toolbar-btn" style="justify-content:center;">Reset filters</a>
                                <button type="submit" class="toolbar-btn primary" style="justify-content:center;">Apply filters</button>
                            </div>
                        </form>
                    </div>
                    <button type="button" class="toolbar-btn" id="btnViewArchived">View Archived</button>
                    <button type="button" class="toolbar-btn primary" id="btnAddExpense">+ Add Expense</button>
                </div>
            </div>

            <div id="expensesTableWrap">
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
                            <th style="text-align:right;" class="no-print">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="expensesTableBody">
                        <?php pf_expense_render_table_rows($expenses, $base_path, false); ?>
                    </tbody>
                </table>
                <?php if ($total_pages > 1): ?>
                <div class="pagination-wrap" style="margin-top:16px;display:flex;justify-content:center;gap:8px;flex-wrap:wrap;">
                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                        <a href="<?php echo htmlspecialchars(pf_expense_page_query(['page' => $p])); ?>"
                           class="toolbar-btn<?php echo $p === $page ? ' active' : ''; ?>"
                           data-page="<?php echo $p; ?>"><?php echo $p; ?></a>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
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
                                <option value="<?php echo (int)($b['id'] ?? 0); ?>"><?php echo htmlspecialchars((string)($b['branch_name'] ?? '')); ?></option>
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
                <button type="submit" class="toolbar-btn primary" id="expenseFormSubmit">Save Expense</button>
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
            <button type="button" class="toolbar-btn primary" id="archiveConfirmBtn" style="background:#b91c1c;border-color:#b91c1c;">Archive</button>
        </div>
    </div>
</div>

<script>
(function () {
    const apiUrl = <?php echo json_encode($apiUrl); ?>;
    let csrfToken = <?php echo json_encode($csrfToken); ?>;
    const isManager = <?php echo $is_manager ? 'true' : 'false'; ?>;
    const defaultBranchId = <?php echo json_encode((int)($is_manager ? ($branchesForModal[0]['id'] ?? 0) : 0)); ?>;
    let archiveTargetId = 0;
    let searchTimer = null;

    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

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

    function currentQueryParams() {
        return new URLSearchParams(window.location.search);
    }

    function reloadTable(page) {
        const params = currentQueryParams();
        if (page) params.set('page', String(page));
        params.set('ajax', '1');
        fetch(window.location.pathname + '?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(r => r.text())
            .then(html => {
                qs('#expensesTableWrap').innerHTML = html;
                bindTableActions();
            })
            .catch(() => showFlash('Failed to refresh expense list.', 'error'));
    }

    function reloadPageWithKpis() {
        window.location.reload();
    }

    async function postExpense(action, payload) {
        const body = Object.assign({}, payload, { action, csrf_token: csrfToken });
        const resp = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body)
        });
        const data = await resp.json().catch(() => ({}));
        if (data.csrf_token) csrfToken = data.csrf_token;
        const meta = qs('meta[name="csrf-token"]');
        if (meta && data.csrf_token) meta.setAttribute('content', data.csrf_token);
        return { ok: resp.ok, status: resp.status, data };
    }

    function resetExpenseForm() {
        const form = qs('#expenseForm');
        form.reset();
        qs('#expenseIdField').value = '';
        qs('#expenseModalTitle').textContent = 'Add Expense';
        qs('#expenseFormSubmit').textContent = 'Save Expense';
        qs('#expenseDate').value = new Date().toISOString().slice(0, 10);
        if (isManager && defaultBranchId) {
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
        qs('#expenseBranch').value = String(data.branch_id || '');
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
        const pm = qs('#expensePaymentMethod');
        pm.required = status === 'Paid';
    }

    function bindTableActions() {
        qsa('.pf-expense-edit').forEach(btn => {
            btn.addEventListener('click', () => {
                const row = btn.closest('.expense-row');
                if (!row) return;
                try {
                    const data = JSON.parse(row.getAttribute('data-expense') || '{}');
                    fillExpenseForm(data);
                    openModal('expenseFormModal');
                } catch (e) {
                    showFlash('Unable to load expense details.', 'error');
                }
            });
        });

        qsa('.pf-expense-archive').forEach(btn => {
            btn.addEventListener('click', () => {
                archiveTargetId = parseInt(btn.getAttribute('data-expense-id') || '0', 10);
                qs('#archiveConfirmName').textContent = btn.getAttribute('data-expense-name') || 'this expense';
                openModal('archiveConfirmModal');
            });
        });

        qsa('.pf-expense-restore').forEach(btn => {
            btn.addEventListener('click', async () => {
                const expenseId = parseInt(btn.getAttribute('data-expense-id') || '0', 10);
                if (!expenseId) return;
                btn.disabled = true;
                const result = await postExpense('restore', { expense_id: expenseId });
                if (result.ok && result.data.success) {
                    showFlash('Expense restored successfully.');
                    loadArchivedList();
                    reloadPageWithKpis();
                } else {
                    showFlash(result.data.error || 'Failed to restore expense.', 'error');
                    btn.disabled = false;
                }
            });
        });
    }

    async function loadArchivedList() {
        const params = currentQueryParams();
        params.set('get_archived', '1');
        params.delete('ajax');
        qs('#archivedModalBody').innerHTML = '<div style="padding:30px;text-align:center;color:#9ca3af;">Loading...</div>';
        try {
            const resp = await fetch(window.location.pathname + '?' + params.toString());
            const data = await resp.json();
            if (data.csrf_token) csrfToken = data.csrf_token;
            qs('#archivedModalBody').innerHTML = data.html || '<div style="padding:30px;text-align:center;color:#9ca3af;">No archived expenses.</div>';
            bindTableActions();
        } catch (e) {
            qs('#archivedModalBody').innerHTML = '<div style="padding:30px;text-align:center;color:#dc2626;">Failed to load archived expenses.</div>';
        }
    }

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
            reloadPageWithKpis();
            return;
        }

        if (result.data.errors) {
            applyFormErrors(result.data.errors);
        }
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
            reloadPageWithKpis();
        } else {
            showFlash(result.data.error || 'Failed to archive expense.', 'error');
        }
    });

    const searchInput = qs('#expenseSearch');
    const filterSearchHidden = qs('#filterSearchHidden');
    searchInput.addEventListener('input', () => {
        if (filterSearchHidden) filterSearchHidden.value = searchInput.value;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            const params = currentQueryParams();
            const val = searchInput.value.trim();
            if (val) params.set('search', val); else params.delete('search');
            params.delete('page');
            const qsStr = params.toString();
            const url = window.location.pathname + (qsStr ? '?' + qsStr : '');
            window.history.replaceState({}, '', url);
            reloadTable(1);
        }, 350);
    });

    bindTableActions();
    togglePaymentMethodRequired();
})();
</script>
</body>
</html>
