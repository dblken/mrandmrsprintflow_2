<?php
/**
 * Branch Context System
 * PrintFlow - Multi-Branch Filtering
 *
 * Provides helpers for:
 *   - Determining which branches a user can see
 *   - Validating and normalising the selected branch session variable
 *   - Building safe SQL WHERE fragments for branch filtering
 *   - Rendering branch badges
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/branch_context.php';
 *   $ctx = init_branch_context();           // resolve + store in session
 *   [$bSql, $bTypes, $bParams] = branch_where_parts('o', $ctx['selected_branch_id']); // 'all' omits archived branches
 *
 * Session key: $_SESSION['selected_branch_id']  — 'all' | int
 */

if (!defined('BRANCH_CONTEXT_LOADED')) {
    define('BRANCH_CONTEXT_LOADED', true);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/** ─────────────────────────────────────────────────────
 *  Branch colours used for badges
 * ──────────────────────────────────────────────────── */
const BRANCH_BADGE_COLORS = [
    1 => ['bg' => '#dbeafe', 'text' => '#1d4ed8', 'label' => 'Main'],
    2 => ['bg' => '#dcfce7', 'text' => '#15803d', 'label' => 'QC'],
    3 => ['bg' => '#fef3c7', 'text' => '#b45309', 'label' => 'Makati'],
    4 => ['bg' => '#f3e8ff', 'text' => '#7e22ce', 'label' => 'BGC'],
    5 => ['bg' => '#ffedd5', 'text' => '#c2410c', 'label' => 'Ortigas'],
];

/** ─────────────────────────────────────────────────────
 *  1. get_all_branches()
 *  Returns all active branches from the DB.
 * ──────────────────────────────────────────────────── */
function get_all_branches(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $cache = db_query("SELECT id, branch_name FROM branches WHERE status != 'Archived' ORDER BY id ASC") ?: [];
    } catch (Exception $e) {
        $cache = [];
    }
    return $cache;
}

/** ─────────────────────────────────────────────────────
 *  2. get_user_allowed_branches($user_id, $role)
 *
 *  Admin   → 'all'
 *  Staff   → [branch_id] (single)
 *  Manager → [branch_id] (single; assigned branch in users.branch_id)
 * ──────────────────────────────────────────────────── */
function get_user_allowed_branches(int $user_id, string $role) {
    if ($role === 'Admin') {
        return 'all';
    }

    // Look up assigned branch(es) from users table
    try {
        $row = db_query(
            "SELECT branch_id FROM users WHERE user_id = ?",
            'i', [$user_id]
        );
        $branch_id = (int)($row[0]['branch_id'] ?? 0);
        if ($branch_id > 0) {
            return [$branch_id];
        }
    } catch (Exception $e) {
        // fallthrough
    }

    // Fallback: return first available branch
    $branches = get_all_branches();
    if (!empty($branches)) {
        return [(int)$branches[0]['id']];
    }
    return [1];
}

/** ─────────────────────────────────────────────────────
 *  3. normalize_selected_branch($selected, $allowed, $requires_branch)
 *
 *  Ensures the chosen branch is valid for this user/page.
 *
 *  Rules:
 *   - If allowed === 'all' and page doesn't require branch → 'all' ok
 *   - If allowed === 'all' and page requires branch → return first available branch id
 *   - If allowed is array:
 *       - 'all' selected → return first allowed branch
 *       - selected not in allowed → fallback to first allowed
 *       - otherwise → return selected (int)
 * ──────────────────────────────────────────────────── */
function normalize_selected_branch($selected, $allowed, bool $requires_branch = false) {
    if ($allowed === 'all') {
        if ($requires_branch) {
            // Keep the explicitly selected branch when it is valid.
            if ($selected !== 'all' && $selected !== null) {
                $selected_int = (int)$selected;
                foreach (get_all_branches() as $branch) {
                    if ((int)($branch['id'] ?? 0) === $selected_int) {
                        return $selected_int;
                    }
                }
            }

            // Otherwise fall back to the configured main/default branch.
            if (function_exists('printflow_get_default_admin_branch_id')) {
                return (int)printflow_get_default_admin_branch_id();
            }

            $branches = get_all_branches();
            return !empty($branches) ? (int)$branches[0]['id'] : 1;
        }
        // Admin on an analytics page → can keep 'all'
        return ($selected === 'all' || $selected === null) ? 'all' : (int)$selected;
    }

    // Restricted user
    if ($selected === 'all' || $selected === null) {
        return (int)$allowed[0];
    }
    $selected_int = (int)$selected;
    if (in_array($selected_int, array_map('intval', $allowed), true)) {
        return $selected_int;
    }
    return (int)$allowed[0];
}

/** ─────────────────────────────────────────────────────
 *  4. init_branch_context(bool $page_requires_branch = false)
 *
 *  Call once at the top of each admin page.
 *  Handles GET ?branch_id= switch, stores in session,
 *  returns the resolved context array.
 *
 *  Returns:
 *   [
 *     'selected_branch_id' => 'all' | int,
 *     'allowed_branches'   => 'all' | int[],
 *     'branches_list'      => [...],   // all branches for the dropdown
 *     'branch_name'        => string,
 *   ]
 * ──────────────────────────────────────────────────── */
function init_branch_context(bool $page_requires_branch = false): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $user_id   = (int)($_SESSION['user_id'] ?? 0);
    $role      = $_SESSION['user_type'] ?? 'Staff';
    $allowed   = get_user_allowed_branches($user_id, $role);
    $branches  = get_all_branches();

    // Branch switch via URL — Admins only (Managers/Staff are locked to assignment)
    if (isset($_GET['branch_id']) && $role === 'Admin') {
        $switch = $_GET['branch_id'] === 'all' ? 'all' : (int)$_GET['branch_id'];
        $_SESSION['selected_branch_id'] = $switch;
    }

    $default_selected = ($role === 'Admin')
        ? printflow_get_default_admin_branch_id()
        : 'all';
    $raw_selected = $_SESSION['selected_branch_id'] ?? $default_selected;
    $selected = normalize_selected_branch($raw_selected, $allowed, $page_requires_branch);
    $_SESSION['selected_branch_id'] = $selected;

    // Managers and Staff always use their assigned branch (defense in depth)
    if (in_array($role, ['Manager', 'Staff'], true) && is_array($allowed) && $allowed !== []) {
        $selected = (int)$allowed[0];
        $_SESSION['selected_branch_id'] = $selected;
    }

    // Resolve human-readable name
    if ($selected === 'all') {
        $branch_name = 'All Branches';
    } else {
        $branch_name = 'Branch';
        foreach ($branches as $b) {
            if ((int)$b['id'] === (int)$selected) {
                $branch_name = $b['branch_name'];
                break;
            }
        }
    }

    return [
        'selected_branch_id' => $selected,
        'allowed_branches'   => $allowed,
        'branches_list'      => $branches,
        'branch_name'        => $branch_name,
    ];
}

/**
 * SQL fragment: customer is tied to a branch via orders or job_orders.
 *
 * @return array{0:string,1:string,2:array} [sql_fragment, types, params]
 */
function branch_customers_belong_where_sql(int $branchId, string $customerAlias = 'c'): array {
    $bid = (int)$branchId;
    if ($bid <= 0) {
        return ['', '', []];
    }
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $customerAlias);
    if ($a === '') {
        $a = 'c';
    }
    $sql = " AND (EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = {$a}.customer_id AND o.branch_id = ?) OR EXISTS (SELECT 1 FROM job_orders jo WHERE jo.customer_id = {$a}.customer_id AND jo.branch_id = ?)) ";
    return [$sql, 'ii', [$bid, $bid]];
}

/** Total + activated counts for customers visible at a branch. */
function branch_customers_summary_for_branch(int $branchId): array {
    [$w, $t, $p] = branch_customers_belong_where_sql($branchId, 'c');
    $row = db_query(
        "SELECT COUNT(*) as total, COALESCE(SUM(CASE WHEN c.status = 'Activated' THEN 1 ELSE 0 END), 0) as active FROM customers c WHERE 1=1" . $w,
        $t, $p
    )[0] ?? ['total' => 0, 'active' => 0];
    return [(int)$row['total'], (int)$row['active']];
}

/**
 * Customer rows for branch-scoped reports (orders + customizations at that branch).
 *
 * @return list<array<string,mixed>>
 */
function branch_customers_report_list(int $branchId): array {
    $bid = (int)$branchId;
    if ($bid <= 0) {
        return [];
    }
    $sql = "SELECT c.customer_id, CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) AS name,
            COALESCE(c.email,'') AS email, COALESCE(c.contact_number,'') AS contact_number, c.status, c.created_at,
            (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.customer_id AND o.branch_id = ?)
            + (SELECT COUNT(*) FROM job_orders jo WHERE jo.customer_id = c.customer_id AND jo.branch_id = ?) AS order_count,
            COALESCE((SELECT SUM(o.total_amount) FROM orders o WHERE o.customer_id = c.customer_id AND o.branch_id = ?), 0)
            + COALESCE((SELECT SUM(jo.amount_paid) FROM job_orders jo WHERE jo.customer_id = c.customer_id AND jo.branch_id = ? AND jo.payment_status = 'PAID'), 0) AS total_spent
        FROM customers c
        WHERE (EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = c.customer_id AND o.branch_id = ?)
            OR EXISTS (SELECT 1 FROM job_orders jo WHERE jo.customer_id = c.customer_id AND jo.branch_id = ?))
        ORDER BY total_spent DESC";
    $types = str_repeat('i', 6);
    $params = [$bid, $bid, $bid, $bid, $bid, $bid];
    return db_query($sql, $types, $params) ?: [];
}

/** ─────────────────────────────────────────────────────
 *  5. branch_where_parts($tableAlias, $branchContext)
 *
 *  Returns [$sqlFragment, $types, $params]
 *
 *  Example:
 *   [$ws, $wt, $wp] = branch_where_parts('o', 3);
 *   $sql .= $ws;   // " AND o.branch_id = ? "
 *   $types .= $wt; // "i"
 *   $params[] = ...; // merge $wp
 *
 *  If $branchContext === 'all':
 *   Restricts to orders/jobs whose branch_id refers to a row where status != 'Archived'
 *   (archived branches are omitted from aggregates and operational filters).
 * ──────────────────────────────────────────────────── */
function branch_where_parts(string $tableAlias, $branchContext): array {
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $tableAlias);
    if ($a === '') {
        $a = 'o';
    }
    if ($branchContext === 'all') {
        return [
            " AND {$a}.branch_id IN (SELECT id FROM branches WHERE status != 'Archived') ",
            '',
            [],
        ];
    }
    $branch_id = (int)$branchContext;
    return [" AND {$a}.branch_id = ? ", 'i', [$branch_id]];
}

/**
 * Convenience wrapper — returns just the SQL fragment and appends
 * to the caller's flat $types string and $params array by reference.
 *
 * Usage:
 *   $sql .= branch_where('o', $ctx, $types, $params);
 */
function branch_where(string $tableAlias, $branchContext, string &$types, array &$params): string {
    [$sql, $t, $p] = branch_where_parts($tableAlias, $branchContext);
    $types  .= $t;
    $params  = array_merge($params, $p);
    return $sql;
}

/** ─────────────────────────────────────────────────────
 *  6. get_branch_badge_html($branch_id, $branch_name)
 *
 *  Returns the HTML for a colour-coded branch badge.
 * ──────────────────────────────────────────────────── */
function get_branch_badge_html(?int $branch_id, string $branch_name = ''): string {
    if (!$branch_id) return '';

    $colors = BRANCH_BADGE_COLORS[$branch_id] ?? ['bg' => '#f3f4f6', 'text' => '#374151', 'label' => ''];
    $display = htmlspecialchars($branch_name ?: $colors['label'] ?: "Branch #{$branch_id}");
    $bg      = htmlspecialchars($colors['bg']);
    $fg      = htmlspecialchars($colors['text']);

    return "<span class=\"branch-badge\" style=\"background:{$bg};color:{$fg};padding:3px 10px;border-radius:9999px;"
         . "font-size:11px;font-weight:600;white-space:nowrap;\">{$display}</span>";
}

/** ─────────────────────────────────────────────────────
 *  7. render_branch_context_banner($branchName)
 *
 *  Prints the "Viewing: ___" header banner.
 * ──────────────────────────────────────────────────── */
function render_branch_context_banner(string $branchName): void {
    // Hidden per user request to clean up UI
}

/**
 * Branch filter for Staff/Manager operational pages and APIs.
 * Admin → null (no automatic filter; use init_branch_context + UI).
 * Staff / Manager → locked assigned branch id (int).
 */
function printflow_branch_filter_for_user(): ?int {
    $t = $_SESSION['user_type'] ?? '';
    if ($t === 'Admin') {
        return null;
    }
    if (!in_array($t, ['Staff', 'Manager'], true)) {
        return null;
    }
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $ctx = init_branch_context(false);
    $s = $ctx['selected_branch_id'];
    $cached = ($s === 'all') ? (int)($_SESSION['branch_id'] ?? 1) : (int)$s;
    return $cached;
}

/**
 * True if a store order belongs to the given branch (staff/manager access control).
 */
function printflow_order_in_branch(int $order_id, int $branch_id): bool {
    $row = db_query(
        'SELECT 1 FROM orders WHERE order_id = ? AND branch_id = ? LIMIT 1',
        'ii',
        [$order_id, $branch_id]
    );
    return !empty($row);
}

/**
 * Non-admin users (Manager/Staff) may only access orders for their branch.
 * Call after resolving order_id; sends JSON 403 and exits if denied.
 */
function printflow_assert_order_branch_access(int $order_id): void {
    if ($order_id <= 0) {
        return;
    }
    if (get_user_type() === 'Admin') {
        return;
    }
    $bid = printflow_branch_filter_for_user();
    if ($bid === null || $bid <= 0) {
        return;
    }
    if (printflow_order_in_branch($order_id, $bid)) {
        return;
    }
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'This order is not in your branch.']);
    exit;
}
