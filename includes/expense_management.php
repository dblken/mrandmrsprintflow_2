<?php
/**
 * Expense Management helpers (branch-scoped operating expenses).
 */

if (!defined('PF_EXPENSE_CATEGORIES')) {
    define('PF_EXPENSE_CATEGORIES', [
        'Salary / Wages',
        'Rent / Lease',
        'Utilities',
        'Supplies / Inventory Purchase',
        'Equipment / Maintenance',
        'Marketing',
        'Transportation',
        'Taxes / Fees',
        'Other',
    ]);
}

if (!defined('PF_EXPENSE_PAYMENT_METHODS')) {
    define('PF_EXPENSE_PAYMENT_METHODS', [
        'Cash',
        'QR Ph',
        'Bank Transfer',
        'GCash',
        'Maya',
        'Other',
    ]);
}

function pf_ensure_expenses_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    global $conn;
    if (empty($conn)) {
        return;
    }

    if (!empty(db_query("SHOW TABLES LIKE 'expenses'"))) {
        return;
    }

    @$conn->query(
        "CREATE TABLE expenses (
            expense_id INT NOT NULL AUTO_INCREMENT,
            expense_name VARCHAR(255) NOT NULL,
            category VARCHAR(100) NOT NULL,
            branch_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            expense_date DATE NOT NULL,
            status ENUM('Paid','To Be Paid','Archived') NOT NULL DEFAULT 'To Be Paid',
            payment_method VARCHAR(50) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            paid_at DATETIME DEFAULT NULL,
            archived_at DATETIME DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (expense_id),
            KEY idx_expenses_branch_date (branch_id, expense_date),
            KEY idx_expenses_status (status),
            KEY idx_expenses_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function pf_expense_normalize_status(string $status): string
{
    $status = trim($status);
    return match ($status) {
        'Paid' => 'Paid',
        'To Be Paid' => 'To Be Paid',
        'Archived' => 'Archived',
        default => '',
    };
}

function pf_expense_status_badge(string $status): array
{
    return match (pf_expense_normalize_status($status)) {
        'Paid' => ['label' => 'Paid', 'style' => 'background:#dcfce7;color:#166534;'],
        'To Be Paid' => ['label' => 'To Be Paid', 'style' => 'background:#fef3c7;color:#92400e;'],
        'Archived' => ['label' => 'Archived', 'style' => 'background:#f3f4f6;color:#6b7280;'],
        default => ['label' => $status, 'style' => 'background:#f3f4f6;color:#6b7280;'],
    };
}

function pf_expense_user_can_access_branch(int $branchId): bool
{
    if ($branchId <= 0) {
        return false;
    }

    $role = (string)(get_user_type() ?? '');
    if ($role === 'Admin') {
        $row = db_query(
            "SELECT id FROM branches WHERE id = ? AND status != 'Archived' LIMIT 1",
            'i',
            [$branchId]
        );
        return !empty($row);
    }

    if ($role === 'Manager') {
        $allowed = (int)(printflow_branch_filter_for_user() ?? 0);
        return $allowed > 0 && $allowed === $branchId;
    }

    return false;
}

function pf_expense_resolve_branch_context(): array
{
    pf_ensure_expenses_table();

    $current_user = get_logged_in_user();
    $is_manager = (get_user_type() === 'Manager' || (($current_user['role'] ?? '') === 'Manager'));
    $branchCtx = init_branch_context(false);
    $branchId = $branchCtx['selected_branch_id'];

    if ($is_manager) {
        $forcedBranch = (int)(printflow_branch_filter_for_user() ?? ($_SESSION['branch_id'] ?? 0));
        if ($forcedBranch > 0) {
            $branchId = $forcedBranch;
            $branchCtx['selected_branch_id'] = $branchId;
            foreach (($branchCtx['branches_list'] ?? []) as $b) {
                if ((int)($b['id'] ?? 0) === $forcedBranch) {
                    $branchCtx['branch_name'] = $b['branch_name'];
                    break;
                }
            }
        }
    }

    return [$branchCtx, $branchId, $is_manager, $current_user];
}

/**
 * @return array{sql:string,types:string,params:array}
 */
function pf_expense_list_query_parts(array $filters, $branchId, bool $archivedOnly = false): array
{
    pf_ensure_expenses_table();

    $sql = " FROM expenses e
             LEFT JOIN branches b ON b.id = e.branch_id
             LEFT JOIN users u ON u.user_id = e.created_by
             WHERE 1=1";
    $types = '';
    $params = [];

    if ($archivedOnly) {
        $sql .= " AND e.status = 'Archived'";
    } else {
        $sql .= " AND e.status IN ('Paid', 'To Be Paid')";
    }

    [$branchSql, $branchTypes, $branchParams] = branch_where_parts('e', $branchId);
    $sql .= $branchSql;
    $types .= $branchTypes;
    $params = array_merge($params, $branchParams);

    $search = trim((string)($filters['search'] ?? ''));
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= " AND (e.expense_name LIKE ? OR e.category LIKE ? OR e.notes LIKE ? OR CAST(e.expense_id AS CHAR) LIKE ?)";
        $types .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }

    $category = trim((string)($filters['category'] ?? ''));
    if ($category !== '' && in_array($category, PF_EXPENSE_CATEGORIES, true)) {
        $sql .= ' AND e.category = ?';
        $types .= 's';
        $params[] = $category;
    }

    $status = pf_expense_normalize_status((string)($filters['status_filter'] ?? ''));
    if (!$archivedOnly && in_array($status, ['Paid', 'To Be Paid'], true)) {
        $sql .= ' AND e.status = ?';
        $types .= 's';
        $params[] = $status;
    }

    $dateFrom = trim((string)($filters['date_from'] ?? ''));
    if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $sql .= ' AND e.expense_date >= ?';
        $types .= 's';
        $params[] = $dateFrom;
    }

    $dateTo = trim((string)($filters['date_to'] ?? ''));
    if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $sql .= ' AND e.expense_date <= ?';
        $types .= 's';
        $params[] = $dateTo;
    }

    return ['sql' => $sql, 'types' => $types, 'params' => $params];
}

function pf_expense_kpi_totals($branchId): array
{
    pf_ensure_expenses_table();

    [$branchSql, $branchTypes, $branchParams] = branch_where_parts('e', $branchId);
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');

    $paidRow = db_query(
        "SELECT COALESCE(SUM(e.amount), 0) AS total
         FROM expenses e
         WHERE e.status = 'Paid' {$branchSql}",
        $branchTypes,
        $branchParams
    )[0] ?? [];

    $monthRow = db_query(
        "SELECT COALESCE(SUM(e.amount), 0) AS total
         FROM expenses e
         WHERE e.status = 'Paid'
           AND e.expense_date BETWEEN ? AND ?
           {$branchSql}",
        'ss' . $branchTypes,
        array_merge([$monthStart, $monthEnd], $branchParams)
    )[0] ?? [];

    $pendingRow = db_query(
        "SELECT COALESCE(SUM(e.amount), 0) AS total, COUNT(*) AS cnt
         FROM expenses e
         WHERE e.status = 'To Be Paid' {$branchSql}",
        $branchTypes,
        $branchParams
    )[0] ?? [];

    return [
        'total_expenses' => round((float)($paidRow['total'] ?? 0), 2),
        'this_month' => round((float)($monthRow['total'] ?? 0), 2),
        'pending_expenses' => round((float)($pendingRow['total'] ?? 0), 2),
        'pending_count' => (int)($pendingRow['cnt'] ?? 0),
    ];
}

function pf_expense_archived_count($branchId): int
{
    pf_ensure_expenses_table();

    [$branchSql, $branchTypes, $branchParams] = branch_where_parts('e', $branchId);
    $row = db_query(
        "SELECT COUNT(*) AS cnt FROM expenses e WHERE e.status = 'Archived' {$branchSql}",
        $branchTypes,
        $branchParams
    )[0] ?? [];

    return (int)($row['cnt'] ?? 0);
}

function pf_expense_sort_order_clause(string $sortBy): string
{
    return match ($sortBy) {
        'oldest' => 'e.expense_date ASC, e.expense_id ASC',
        'az' => 'e.expense_name ASC, e.expense_id DESC',
        'za' => 'e.expense_name DESC, e.expense_id DESC',
        'amount_high' => 'e.amount DESC, e.expense_id DESC',
        'amount_low' => 'e.amount ASC, e.expense_id DESC',
        default => 'e.expense_date DESC, e.expense_id DESC',
    };
}

/**
 * @return array{ok:bool,errors:array<string,string>,data:array<string,mixed>}
 */
function pf_expense_validate_payload(array $input, bool $isUpdate = false): array
{
    $errors = [];
    $data = [];

    $name = trim((string)($input['expense_name'] ?? ''));
    if ($name === '') {
        $errors['expense_name'] = 'Expense name is required.';
    } elseif (mb_strlen($name) > 255) {
        $errors['expense_name'] = 'Expense name must be 255 characters or less.';
    } else {
        $data['expense_name'] = $name;
    }

    $category = trim((string)($input['category'] ?? ''));
    if (!in_array($category, PF_EXPENSE_CATEGORIES, true)) {
        $errors['category'] = 'Please select a valid category.';
    } else {
        $data['category'] = $category;
    }

    $branchId = (int)($input['branch_id'] ?? 0);
    if (!pf_expense_user_can_access_branch($branchId)) {
        $errors['branch_id'] = 'Invalid or unauthorized branch.';
    } else {
        $data['branch_id'] = $branchId;
    }

    $amountRaw = trim((string)($input['amount'] ?? ''));
    if ($amountRaw === '' || !is_numeric($amountRaw)) {
        $errors['amount'] = 'Amount must be a valid number.';
    } else {
        $amount = round((float)$amountRaw, 2);
        if ($amount <= 0) {
            $errors['amount'] = 'Amount must be greater than zero.';
        } elseif ($amount > 9999999999.99) {
            $errors['amount'] = 'Amount is too large.';
        } else {
            $data['amount'] = $amount;
        }
    }

    $expenseDate = trim((string)($input['expense_date'] ?? ''));
    if ($expenseDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
        $errors['expense_date'] = 'A valid expense date is required.';
    } else {
        $dt = DateTime::createFromFormat('Y-m-d', $expenseDate);
        $dtErrors = DateTime::getLastErrors();
        if (!$dt || ($dtErrors['warning_count'] ?? 0) > 0 || ($dtErrors['error_count'] ?? 0) > 0) {
            $errors['expense_date'] = 'A valid expense date is required.';
        } else {
            $data['expense_date'] = $expenseDate;
        }
    }

    $status = pf_expense_normalize_status((string)($input['status'] ?? ''));
    if (!in_array($status, ['Paid', 'To Be Paid'], true)) {
        $errors['status'] = 'Status must be Paid or To Be Paid.';
    } else {
        $data['status'] = $status;
    }

    $paymentMethod = trim((string)($input['payment_method'] ?? ''));
    if ($status === 'Paid') {
        if ($paymentMethod === '' || !in_array($paymentMethod, PF_EXPENSE_PAYMENT_METHODS, true)) {
            $errors['payment_method'] = 'Payment method is required when status is Paid.';
        } else {
            $data['payment_method'] = $paymentMethod;
        }
    } else {
        $data['payment_method'] = ($paymentMethod !== '' && in_array($paymentMethod, PF_EXPENSE_PAYMENT_METHODS, true))
            ? $paymentMethod
            : null;
    }

    $notes = trim((string)($input['notes'] ?? ''));
    $data['notes'] = $notes !== '' ? mb_substr($notes, 0, 2000) : null;

    if ($isUpdate) {
        $expenseId = (int)($input['expense_id'] ?? 0);
        if ($expenseId <= 0) {
            $errors['expense_id'] = 'Invalid expense record.';
        } else {
            $data['expense_id'] = $expenseId;
        }
    }

    return [
        'ok' => empty($errors),
        'errors' => $errors,
        'data' => $data,
    ];
}

function pf_expense_get_row(int $expenseId): ?array
{
    pf_ensure_expenses_table();
    $rows = db_query(
        "SELECT e.*, b.branch_name
         FROM expenses e
         LEFT JOIN branches b ON b.id = e.branch_id
         WHERE e.expense_id = ?
         LIMIT 1",
        'i',
        [$expenseId]
    );
    return $rows[0] ?? null;
}

function pf_expense_user_can_manage_row(array $row): bool
{
    return pf_expense_user_can_access_branch((int)($row['branch_id'] ?? 0));
}

function pf_expense_build_payload(array $row): array
{
    return [
        'expense_id' => (int)($row['expense_id'] ?? 0),
        'expense_name' => (string)($row['expense_name'] ?? ''),
        'category' => (string)($row['category'] ?? ''),
        'branch_id' => (int)($row['branch_id'] ?? 0),
        'branch_name' => (string)($row['branch_name'] ?? ''),
        'amount' => round((float)($row['amount'] ?? 0), 2),
        'expense_date' => (string)($row['expense_date'] ?? ''),
        'status' => pf_expense_normalize_status((string)($row['status'] ?? '')),
        'payment_method' => (string)($row['payment_method'] ?? ''),
        'notes' => (string)($row['notes'] ?? ''),
    ];
}

/**
 * Paid operating expenses total for Reports (date range + branch scoped).
 * Uses expense_date; includes Paid only (excludes To Be Paid and Archived).
 */
function pf_expense_reports_paid_total(string $from, string $toEnd, $branchId): float
{
    $sql = "SELECT COALESCE(SUM(e.amount), 0) AS total FROM expenses e WHERE e.status = 'Paid'";
    $types = '';
    $params = [];

    if ($from !== '' && $toEnd !== '') {
        $toDate = preg_match('/^(\d{4}-\d{2}-\d{2})/', $toEnd, $m) ? $m[1] : substr($toEnd, 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            $sql .= ' AND e.expense_date BETWEEN ? AND ?';
            $types .= 'ss';
            $params[] = $from;
            $params[] = $toDate;
        }
    } elseif ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $sql .= ' AND e.expense_date >= ?';
        $types .= 's';
        $params[] = $from;
    } elseif ($toEnd !== '') {
        $toDate = preg_match('/^(\d{4}-\d{2}-\d{2})/', $toEnd, $m) ? $m[1] : substr($toEnd, 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            $sql .= ' AND e.expense_date <= ?';
            $types .= 's';
            $params[] = $toDate;
        }
    }

    [$branchSql, $branchTypes, $branchParams] = branch_where_parts('e', $branchId);
    $sql .= $branchSql;
    $types .= $branchTypes;
    $params = array_merge($params, $branchParams);

    try {
        $row = db_query($sql, $types !== '' ? $types : null, $params !== [] ? $params : null)[0] ?? [];
        return round((float)($row['total'] ?? 0), 2);
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * Resolve expense list period from request (today, week, month, custom, or all).
 *
 * @return array{period:string,date_from:string,date_to:string,label:string,range_label:string}
 */
function pf_expense_resolve_period(array $input): array
{
    $todayYmd = date('Y-m-d');
    $requestedFrom = trim((string)($input['date_from'] ?? ''));
    $requestedTo = trim((string)($input['date_to'] ?? ''));
    $validFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedFrom) ? $requestedFrom : '';
    $validTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedTo) ? $requestedTo : '';
    $period = trim((string)($input['expense_period'] ?? ''));

    if (!in_array($period, ['today', 'week', 'month', 'custom', 'all'], true)) {
        $period = ($validFrom !== '' || $validTo !== '') ? 'custom' : 'all';
    }

    if ($period === 'custom' && $validFrom !== '' && $validTo !== '') {
        $from = $validFrom;
        $to = $validTo;
        if (strtotime($from) > strtotime($to)) {
            [$from, $to] = [$to, $from];
        }
        $label = 'Custom';
    } elseif ($period === 'week') {
        $from = date('Y-m-d', strtotime('monday this week'));
        $to = $todayYmd;
        $label = 'This Week';
    } elseif ($period === 'month') {
        $from = date('Y-m-01');
        $to = $todayYmd;
        $label = 'This Month';
    } elseif ($period === 'today') {
        $from = $todayYmd;
        $to = $todayYmd;
        $label = 'Today';
    } else {
        $from = $validFrom;
        $to = $validTo;
        $label = 'All Time';
        if ($from !== '' && $to !== '') {
            $period = 'custom';
            $label = 'Custom';
        } elseif ($from !== '' || $to !== '') {
            $period = 'custom';
            $label = 'Custom';
        }
    }

    if ($from !== '' && $to !== '') {
        $rangeLabel = date('M j, Y', strtotime($from));
        if ($from !== $to) {
            $rangeLabel = date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));
        }
    } else {
        $rangeLabel = 'All time';
    }

    return [
        'period' => $period,
        'date_from' => $from,
        'date_to' => $to,
        'label' => $label,
        'range_label' => $rangeLabel,
    ];
}

/**
 * Build list/export filters from GET request.
 *
 * @return array{search:string,category:string,status_filter:string,date_from:string,date_to:string,expense_period:string,sort:string}
 */
function pf_expense_filters_from_request(array $input): array
{
    $periodInfo = pf_expense_resolve_period($input);
    $sort = trim((string)($input['sort'] ?? 'newest'));
    $allowedSorts = ['newest', 'oldest', 'az', 'za', 'amount_high', 'amount_low'];
    if (!in_array($sort, $allowedSorts, true)) {
        $sort = 'newest';
    }

    return [
        'search' => trim((string)($input['search'] ?? '')),
        'category' => trim((string)($input['category'] ?? '')),
        'status_filter' => trim((string)($input['status_filter'] ?? '')),
        'date_from' => $periodInfo['date_from'],
        'date_to' => $periodInfo['date_to'],
        'expense_period' => $periodInfo['period'],
        'sort' => $sort,
    ];
}

/**
 * Human-readable export metadata for applied expense filters.
 *
 * @return array<string,string>
 */
function pf_expense_export_filter_meta(array $filters, string $branchName, array $periodInfo): array
{
    $meta = [
        'Branch' => $branchName !== '' ? $branchName : 'All Branches',
        'Period' => $periodInfo['label'],
        'Date Range' => $periodInfo['range_label'],
    ];

    $category = trim((string)($filters['category'] ?? ''));
    $meta['Category'] = ($category !== '' && in_array($category, PF_EXPENSE_CATEGORIES, true))
        ? $category
        : 'All categories';

    $status = pf_expense_normalize_status((string)($filters['status_filter'] ?? ''));
    $meta['Status'] = in_array($status, ['Paid', 'To Be Paid'], true) ? $status : 'All statuses';

    $search = trim((string)($filters['search'] ?? ''));
    $meta['Search'] = $search !== '' ? $search : 'None';

    $sortLabels = [
        'newest' => 'Newest to Oldest',
        'oldest' => 'Oldest to Newest',
        'az' => 'A → Z',
        'za' => 'Z → A',
        'amount_high' => 'Amount: High to Low',
        'amount_low' => 'Amount: Low to High',
    ];
    $meta['Sort'] = $sortLabels[$filters['sort'] ?? 'newest'] ?? 'Newest to Oldest';

    return $meta;
}

/**
 * Fetch all expense rows matching filters (no pagination) for export.
 */
function pf_expense_list_for_export(array $filters, $branchId): array
{
    $parts = pf_expense_list_query_parts($filters, $branchId, false);
    $orderSql = pf_expense_sort_order_clause((string)($filters['sort'] ?? 'newest'));

    return db_query(
        "SELECT e.*, b.branch_name, u.first_name, u.last_name
         {$parts['sql']}
         ORDER BY {$orderSql}",
        $parts['types'] ?: null,
        $parts['params'] ?: null
    ) ?: [];
}
