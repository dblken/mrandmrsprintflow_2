<?php

/**
 * 24-hour retention for unpaid online product orders.
 *
 * All deadlines are evaluated by MySQL NOW(). includes/db.php configures the
 * database session to +08:00 (Asia/Manila), so the cron and page fallback use
 * the same clock. Only verified successful payments and active/successful
 * order statuses protect an overdue unpaid order from removal.
 */

require_once __DIR__ . '/provider_payments.php';
require_once __DIR__ . '/staff_order_status_buckets.php';
require_once __DIR__ . '/staff_access.php';
require_once __DIR__ . '/ready_made_product_order_scope.php';

/** Physical deletion is opt-in; operational views hide stale ready-made rows instead. */
function printflow_expired_product_order_physical_cleanup_enabled(): bool
{
    return defined('PRINTFLOW_EXPIRED_PRODUCT_ORDER_PHYSICAL_CLEANUP')
        && PRINTFLOW_EXPIRED_PRODUCT_ORDER_PHYSICAL_CLEANUP;
}

function printflow_expired_product_order_provider_mode_sql(string $ppAlias = 'pp'): string
{
    if (!db_table_has_column('provider_payments', 'mode')) {
        return '';
    }
    $mode = printflow_paymongo_mode();
    if (!in_array($mode, ['test', 'live'], true)) {
        return '';
    }
    return " AND {$ppAlias}.mode = '" . $mode . "'";
}

function printflow_expired_product_order_provider_table_exists(): bool
{
    static $exists = null;
    if ($exists === null) {
        $exists = !empty(db_query("SHOW TABLES LIKE 'provider_payments'"));
    }
    return $exists;
}

function printflow_expired_product_order_provider_schema_safe(): bool
{
    if (!printflow_expired_product_order_provider_table_exists()) {
        return true;
    }
    foreach (['id', 'subject_type', 'subject_id', 'channel', 'provider', 'status'] as $column) {
        if (!db_table_has_column('provider_payments', $column)) {
            return false;
        }
    }
    if (db_table_has_column('provider_payments', 'mode')) {
        $mode = printflow_paymongo_mode();
        if (!in_array($mode, ['test', 'live'], true)
            || !printflow_provider_payment_mode_supported($mode)) {
            return false;
        }
    }
    return true;
}

function printflow_expired_product_order_db_error_count(): int
{
    return function_exists('printflow_db_errors') ? count(printflow_db_errors()) : 0;
}

function printflow_expired_product_order_transactional_tables_safe(): bool
{
    static $safe = null;
    if ($safe !== null) {
        return $safe;
    }
    $tables = ['orders'];
    foreach ([
        ['customizations', 'order_id'],
        ['order_messages', 'order_id'],
        ['reviews', 'order_id'],
        ['order_status_history', 'order_id'],
        ['provider_payments', 'order_id'],
        ['order_items', 'order_id'],
    ] as [$table, $column]) {
        if (db_table_has_column($table, $column)) {
            $tables[] = $table;
        }
    }
    if (db_table_has_column('provider_payments', 'subject_id')) {
        $tables[] = 'provider_payments';
    }
    if (db_table_has_column('notifications', 'data_id')
        && db_table_has_column('notifications', 'notification_type')) {
        $tables[] = 'notifications';
    }
    $tables = array_values(array_unique($tables));
    $tableList = implode(',', array_map(static fn(string $table): string => "'{$table}'", $tables));
    $errorsBefore = printflow_expired_product_order_db_error_count();
    $rows = db_query(
        "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$tableList})"
    );
    if (!is_array($rows) || printflow_expired_product_order_db_error_count() > $errorsBefore) {
        return $safe = false;
    }
    $engines = [];
    foreach ($rows as $row) {
        $engines[(string)($row['TABLE_NAME'] ?? '')] = strtolower((string)($row['ENGINE'] ?? ''));
    }
    foreach ($tables as $table) {
        if (($engines[$table] ?? '') !== 'innodb') {
            return $safe = false;
        }
    }
    return $safe = true;
}

function printflow_expired_product_order_retention_schema_safe(): bool
{
    foreach (['order_id', 'order_type', 'status', 'payment_status', 'order_date'] as $column) {
        if (!db_table_has_column('orders', $column)) {
            return false;
        }
    }
    if (!printflow_expired_product_order_provider_schema_safe()) {
        return false;
    }
    if (!printflow_expired_product_order_transactional_tables_safe()) {
        return false;
    }
    $hasExpiredTimestamp = db_table_has_column('orders', 'updated_at')
        || (printflow_expired_product_order_provider_table_exists()
            && db_table_has_column('provider_payments', 'updated_at'))
        || (printflow_expired_product_order_provider_table_exists()
            && db_table_has_column('provider_payment_status_history', 'provider_payment_id')
            && db_table_has_column('provider_payment_status_history', 'new_status')
            && db_table_has_column('provider_payment_status_history', 'created_at'));
    return $hasExpiredTimestamp;
}

function printflow_expired_product_order_latest_payment_expr(string $oAlias, string $column): string
{
    if (!printflow_expired_product_order_provider_table_exists()
        || !db_table_has_column('provider_payments', $column)) {
        return 'NULL';
    }
    $modeSql = printflow_expired_product_order_provider_mode_sql('pp_latest');
    return "(SELECT pp_latest.{$column}
             FROM provider_payments pp_latest
             WHERE pp_latest.subject_type = 'order'
               AND pp_latest.subject_id = {$oAlias}.order_id
               AND pp_latest.channel = 'online'
               AND pp_latest.provider = 'paymongo'{$modeSql}
             ORDER BY pp_latest.id DESC LIMIT 1)";
}

function printflow_expired_product_order_expired_at_expr(string $ppAlias = 'pp'): string
{
    // Compatibility helper retained for callers of the previous archive flow.
    $parts = [];
    if (db_table_has_column('provider_payments', 'updated_at')) {
        $parts[] = "{$ppAlias}.updated_at";
    }
    if (db_table_has_column('provider_payments', 'qr_expires_at')) {
        $parts[] = "{$ppAlias}.qr_expires_at";
    }
    if (db_table_has_column('provider_payments', 'created_at')) {
        $parts[] = "{$ppAlias}.created_at";
    }
    return $parts === [] ? 'NULL' : 'COALESCE(' . implode(', ', $parts) . ')';
}

function printflow_expired_product_order_paid_protection_sql(string $oAlias = 'o'): string
{
    if (!printflow_expired_product_order_provider_table_exists()) {
        return '1=1';
    }
    if (!printflow_expired_product_order_provider_schema_safe()) {
        return '0=1';
    }
    return "NOT EXISTS (
        SELECT 1 FROM provider_payments pp_paid
        WHERE pp_paid.subject_type = 'order'
          AND pp_paid.subject_id = {$oAlias}.order_id
          AND LOWER(TRIM(COALESCE(pp_paid.status, ''))) = 'paid'
    )";
}

/**
 * Payment/order states that can age out of staff-facing lists. This predicate
 * deliberately excludes successful payments, but does not include payment
 * submissions, linked job records, or inventory movement rows as blockers.
 */
function printflow_expired_product_order_unpaid_expired_state_sql(string $oAlias = 'o'): string
{
    if (!db_table_has_column('orders', 'order_date')
        || !db_table_has_column('orders', 'status')
        || !db_table_has_column('orders', 'payment_status')
        || !printflow_expired_product_order_provider_schema_safe()) {
        return '0=1';
    }

    $latestStatus = printflow_expired_product_order_latest_payment_expr($oAlias, 'status');
    $hasProviderTable = printflow_expired_product_order_provider_table_exists();
    $providerStatus = $hasProviderTable
        ? "({$latestStatus})"
        : 'NULL';
    $rawStatus = "LOWER(TRIM(COALESCE({$oAlias}.status, '')))";
    // Successful/ongoing order states always win over a stale payment ledger.
    $activeOrderStatus = "{$rawStatus} NOT IN (
        'paid', 'payment confirmed', 'processing', 'in production', 'printing',
        'approved', 'approved design', 'ready for pickup', 'to pickup',
        'to pick up', 'completed', 'delivered', 'cancelled'
    )";
    $latestId = printflow_expired_product_order_latest_payment_expr($oAlias, 'id');
    $expiredState = $hasProviderTable
        ? "(({$latestStatus}) = 'expired' OR (({$latestId}) IS NULL AND {$rawStatus} = 'expired'))"
        : "({$rawStatus} = 'expired')";
    $awaitingState = "(
        {$providerStatus} = 'awaiting_payment'
        OR {$rawStatus} IN ('to pay', 'awaiting payment', 'pending')
    )";

    $unpaidOrder = "LOWER(TRIM(COALESCE({$oAlias}.payment_status, ''))) NOT IN ('paid', 'fully paid', 'success', 'successful', 'succeeded')";
    $noPaidLedger = printflow_expired_product_order_paid_protection_sql($oAlias);

    return "({$activeOrderStatus})
        AND ({$unpaidOrder})
        AND ({$noPaidLedger})
        AND (({$expiredState}) OR ({$awaitingState}))";
}

/** Payment/order state that qualifies for physical deletion after its grace period. */
function printflow_expired_product_order_unpaid_expired_sql(string $oAlias = 'o'): string
{
    return printflow_expired_product_order_unpaid_expired_state_sql($oAlias);
}

/** Awaiting Payment ages from the DATETIME order_date set with NOW() at online order creation. */
function printflow_expired_product_order_awaiting_age_sql(string $oAlias = 'o'): string
{
    return "{$oAlias}.order_date <= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
}

/**
 * Expired status age uses the payment status transition timestamp when it is
 * available; updated_at is a conservative fallback for older schemas/rows.
 */
function printflow_expired_product_order_expired_age_sql(string $oAlias = 'o'): string
{
    $latestId = printflow_expired_product_order_latest_payment_expr($oAlias, 'id');
    $latestStatus = printflow_expired_product_order_latest_payment_expr($oAlias, 'status');
    $historyAt = 'NULL';
    if (printflow_expired_product_order_provider_table_exists()
        && db_table_has_column('provider_payment_status_history', 'provider_payment_id')
        && db_table_has_column('provider_payment_status_history', 'new_status')
        && db_table_has_column('provider_payment_status_history', 'created_at')) {
        $historyAt = "(SELECT MAX(pph.created_at)
                      FROM provider_payment_status_history pph
                      WHERE pph.provider_payment_id = {$latestId}
                        AND LOWER(TRIM(pph.new_status)) = 'expired')";
    }
    $providerUpdatedAt = 'NULL';
    if (printflow_expired_product_order_provider_table_exists()
        && db_table_has_column('provider_payments', 'updated_at')) {
        $providerUpdatedAt = printflow_expired_product_order_latest_payment_expr($oAlias, 'updated_at');
    }
    $orderUpdatedAt = db_table_has_column('orders', 'updated_at')
        ? "{$oAlias}.updated_at"
        : 'NULL';
    $providerExpiredAt = "COALESCE({$historyAt}, {$providerUpdatedAt}, {$orderUpdatedAt})";
    $rawOrderExpiredAt = $orderUpdatedAt;
    $expiredAt = "CASE WHEN ({$latestStatus}) = 'expired'
        THEN {$providerExpiredAt}
        ELSE {$rawOrderExpiredAt}
    END";
    return "{$expiredAt} <= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
}

/** Orders eligible for permanent cleanup after 24 hours in Awaiting Payment or Expired. */
function printflow_expired_product_order_retention_eligible_sql(string $oAlias = 'o'): string
{
    $state = printflow_expired_product_order_unpaid_expired_sql($oAlias);
    $awaitingDue = '(' . printflow_expired_product_order_awaiting_age_sql($oAlias) . ')';
    $expiredDue = '(' . printflow_expired_product_order_expired_age_sql($oAlias) . ')';
    $latestStatus = printflow_expired_product_order_latest_payment_expr($oAlias, 'status');
    $hasProviderTable = printflow_expired_product_order_provider_table_exists();
    $rawStatus = "LOWER(TRIM(COALESCE({$oAlias}.status, '')))";
    $latestId = printflow_expired_product_order_latest_payment_expr($oAlias, 'id');
    $expiredState = $hasProviderTable
        ? "(({$latestStatus}) = 'expired' OR (({$latestId}) IS NULL AND {$rawStatus} = 'expired'))"
        : "({$rawStatus} = 'expired')";
    $awaitingState = $hasProviderTable
        ? "(({$latestStatus}) = 'awaiting_payment' OR {$rawStatus} IN ('to pay', 'awaiting payment', 'pending'))"
        : "({$rawStatus} IN ('to pay', 'awaiting payment', 'pending'))";

    $readyMade = printflow_ready_made_product_order_scope_sql($oAlias);

    return "({$readyMade}) AND ({$state}) AND (
        (({$expiredState}) AND {$expiredDue})
        OR (({$awaitingState}) AND {$awaitingDue})
    )";
}

/** Legacy name: rows due for deletion that previously appeared in the archive modal. */
function printflow_expired_product_order_archived_sql(string $oAlias = 'o'): string
{
    $expiredOnly = "(LOWER(TRIM(COALESCE({$oAlias}.status, ''))) = 'expired'";
    if (printflow_expired_product_order_provider_table_exists()) {
        $latestStatus = printflow_expired_product_order_latest_payment_expr($oAlias, 'status');
        $expiredOnly .= " OR ({$latestStatus}) = 'expired'";
    }
    $expiredOnly .= ')';
    return '(' . printflow_expired_product_order_unpaid_expired_sql($oAlias) . ') AND '
        . $expiredOnly . ' AND (' . printflow_expired_product_order_expired_age_sql($oAlias) . ')';
}

function printflow_ready_made_product_cancelled_operational_hidden_sql(string $oAlias = 'o'): string
{
    $readyMade = printflow_ready_made_product_order_scope_sql($oAlias);
    $cancelled = "LOWER(TRIM(COALESCE({$oAlias}.status, ''))) IN ('cancelled', 'canceled')";
    return "({$readyMade} AND ({$cancelled}))";
}

/** Ready-made rows in an expired checkout/payment state (hidden from operational views). */
function printflow_ready_made_product_expired_operational_hidden_sql(string $oAlias = 'o'): string
{
    $readyMade = printflow_ready_made_product_order_scope_sql($oAlias);
    $rawStatus = "LOWER(TRIM(COALESCE({$oAlias}.status, '')))";
    $latestStatus = printflow_expired_product_order_latest_payment_expr($oAlias, 'status');
    $hasProviderTable = printflow_expired_product_order_provider_table_exists();
    $expiredState = "({$rawStatus} = 'expired'";
    if ($hasProviderTable) {
        $expiredState .= " OR ({$latestStatus}) = 'expired'";
    }
    $expiredState .= ')';
    $activeProtection = "({$rawStatus} NOT IN (
        'paid', 'payment confirmed', 'processing', 'in production', 'printing',
        'approved', 'approved design', 'ready for pickup', 'to pickup',
        'to pick up', 'completed', 'delivered'
    ))";
    $noPaidLedger = printflow_expired_product_order_paid_protection_sql($oAlias);

    return "({$readyMade} AND ({$expiredState}) AND ({$activeProtection}) AND ({$noPaidLedger}))";
}

/** Schema-safe fallback when provider tables are unavailable: stale unpaid Pending/To Pay by order_date. */
function printflow_ready_made_product_stale_unpaid_fallback_hidden_sql(string $oAlias = 'o'): string
{
    $readyMade = printflow_ready_made_product_order_scope_sql($oAlias);
    $rawStatus = "LOWER(TRIM(COALESCE({$oAlias}.status, '')))";
    $unpaidPayment = "LOWER(TRIM(COALESCE({$oAlias}.payment_status, ''))) NOT IN ('paid', 'fully paid', 'success', 'successful', 'succeeded')";
    $awaitingOrder = "{$rawStatus} IN ('to pay', 'awaiting payment', 'pending')";
    $noPaidLedger = printflow_expired_product_order_paid_protection_sql($oAlias);

    return "({$readyMade} AND ({$unpaidPayment}) AND ({$noPaidLedger}) AND ({$awaitingOrder}) AND ("
        . printflow_expired_product_order_awaiting_age_sql($oAlias) . '))';
}

/** Unpaid ready-made rows that exceeded the 24-hour order_date / expiration clock. */
function printflow_ready_made_product_unpaid_overdue_operational_hidden_sql(string $oAlias = 'o'): string
{
    $readyMade = printflow_ready_made_product_order_scope_sql($oAlias);
    $state = printflow_expired_product_order_unpaid_expired_state_sql($oAlias);
    $fallback = printflow_ready_made_product_stale_unpaid_fallback_hidden_sql($oAlias);
    if ($state === '0=1') {
        return $fallback;
    }

    $latestStatus = printflow_expired_product_order_latest_payment_expr($oAlias, 'status');
    $hasProviderTable = printflow_expired_product_order_provider_table_exists();
    $expiredState = "(LOWER(TRIM(COALESCE({$oAlias}.status, ''))) = 'expired'"
        . ($hasProviderTable ? " OR ({$latestStatus}) = 'expired'" : '') . ')';
    $awaitingState = $hasProviderTable
        ? "(({$latestStatus}) = 'awaiting_payment' OR LOWER(TRIM(COALESCE({$oAlias}.status, ''))) IN ('to pay', 'awaiting payment', 'pending'))"
        : "(LOWER(TRIM(COALESCE({$oAlias}.status, ''))) IN ('to pay', 'awaiting payment', 'pending'))";

    $unpaidOverdue = "({$state}) AND (
        (({$expiredState}) AND (" . printflow_expired_product_order_expired_age_sql($oAlias) . "))
        OR (({$awaitingState}) AND (" . printflow_expired_product_order_awaiting_age_sql($oAlias) . "))
    )";

    return "(({$readyMade} AND ({$unpaidOverdue})) OR {$fallback})";
}

/** Prevent stale ready-made orders from operational lists when cleanup is disabled or delayed. */
function printflow_expired_product_order_overdue_for_display_sql(string $oAlias = 'o'): string
{
    $cancelled = printflow_ready_made_product_cancelled_operational_hidden_sql($oAlias);
    $expired = printflow_ready_made_product_expired_operational_hidden_sql($oAlias);
    $unpaidOverdue = printflow_ready_made_product_unpaid_overdue_operational_hidden_sql($oAlias);
    if ($unpaidOverdue === '0=1') {
        return "({$cancelled} OR {$expired})";
    }

    return "({$cancelled} OR {$expired} OR {$unpaidOverdue})";
}

/** Hide overdue unpaid orders from active lists even when deletion is guarded. */
function printflow_expired_product_order_exclude_archived_sql(string $oAlias = 'o'): string
{
    return 'NOT (' . printflow_expired_product_order_overdue_for_display_sql($oAlias) . ')';
}

/** Expired unpaid orders remain visible only during their first 24 hours expired. */
function printflow_expired_product_order_recent_expired_sql(string $oAlias = 'o'): string
{
    $base = printflow_expired_product_order_unpaid_expired_sql($oAlias);
    $agedOut = printflow_expired_product_order_archived_sql($oAlias);
    return "({$base}) AND NOT ({$agedOut})";
}

function printflow_expired_product_order_display_filter_sql(string $filter, string $oAlias = 'o'): string
{
    $filter = strtoupper(trim($filter));
    return match ($filter) {
        'PAID' => '(' . staff_orders_sql_paid_bucket($oAlias) . ')',
        'EXPIRED' => '(' . printflow_expired_product_order_recent_expired_sql($oAlias) . ')',
        'COMPLETED' => "({$oAlias}.status = 'Completed')",
        default => '1=1',
    };
}

/**
 * @return array<int, array<string, mixed>>
 */
function printflow_expired_product_order_fetch_archived(
    int $branchId,
    string $staffOrderScopeSql,
    int $limit = 100,
    int $offset = 0
): array {
    $types = '';
    $params = [];
    $branchSql = branch_where('o', $branchId, $types, $params);

    $sql = "SELECT o.*,
            COALESCE(
                NULLIF(TRIM(o.pos_guest_display_name), ''),
                NULLIF(TRIM(CONCAT_WS(' ', c.first_name, c.last_name)), ''),
                'Walk-in Customer (Guest)'
            ) AS customer_name,
            (SELECT GROUP_CONCAT(DISTINCT p.sku ORDER BY p.sku SEPARATOR '-')
             FROM order_items oi LEFT JOIN products p ON oi.product_id = p.product_id
             WHERE oi.order_id = o.order_id) AS order_sku,
            (SELECT GROUP_CONCAT(COALESCE(p.name, 'Custom Product') SEPARATOR ', ')
             FROM order_items oi LEFT JOIN products p ON oi.product_id = p.product_id
             WHERE oi.order_id = o.order_id) AS item_names
        FROM orders o
        LEFT JOIN customers c ON c.customer_id = o.customer_id
        WHERE o.order_type = 'product'
          AND {$staffOrderScopeSql}
          {$branchSql}
          AND " . printflow_expired_product_order_archived_sql('o') . "
          AND NOT (" . printflow_expired_product_order_retention_eligible_sql('o') . ")
        ORDER BY o.order_date DESC
        LIMIT ? OFFSET ?";
    $types .= 'ii';
    $params[] = $limit;
    $params[] = $offset;

    return db_query($sql, $types, $params) ?: [];
}

function printflow_expired_product_order_archived_count(
    ?int $branchId,
    string $staffOrderScopeSql
): int {
    $types = '';
    $params = [];
    $branchSql = '';
    if ($branchId !== null && $branchId > 0) {
        $branchSql = branch_where('o', $branchId, $types, $params);
    }

    $row = db_query(
        "SELECT COUNT(*) AS c
         FROM orders o
         WHERE o.order_type = 'product'
           AND {$staffOrderScopeSql}
           {$branchSql}
           AND " . printflow_expired_product_order_archived_sql('o') . "
           AND NOT (" . printflow_expired_product_order_retention_eligible_sql('o') . ')',
        $types,
        $params
    ) ?: [];

    return (int)($row[0]['c'] ?? 0);
}

/**
 * Recheck and remove one eligible order inside a transaction. Provider rows
 * are locked before the order row, matching PayMongo's paid-finalization lock
 * order. A payment finalized before this lock is observed as paid and retained.
 */
function printflow_expired_product_order_delete_if_eligible(
    int $orderId,
    ?int $branchId = null,
    bool $expiredOnly = false
): ?bool {
    global $conn;
    if ($orderId <= 0 || !($conn instanceof mysqli)) {
        return false;
    }
    if (!printflow_expired_product_order_retention_schema_safe()) {
        return false;
    }

    $scopeSql = printflow_staff_order_source_sql('o', 'online');
    $types = 'i';
    $params = [$orderId];
    $branchSql = '';
    if ($branchId !== null && $branchId > 0) {
        $branchSql = branch_where('o', $branchId, $types, $params);
    }

    if (!$conn->begin_transaction()) {
        return false;
    }
    try {
        if (printflow_expired_product_order_provider_table_exists()
            && printflow_expired_product_order_provider_schema_safe()) {
            $queryErrorsBefore = printflow_expired_product_order_db_error_count();
            $paymentLocks = db_query(
                "SELECT id, status FROM provider_payments
                 WHERE subject_type = 'order' AND subject_id = ?
                 ORDER BY id ASC FOR UPDATE",
                'i',
                [$orderId]
            );
            if (!is_array($paymentLocks)
                || printflow_expired_product_order_db_error_count() > $queryErrorsBefore) {
                throw new RuntimeException('Unable to lock the current payment state.');
            }
        }

        $queryErrorsBefore = printflow_expired_product_order_db_error_count();
        $order = db_query(
            "SELECT o.order_id FROM orders o WHERE o.order_id = ? AND o.order_type = 'product'
             AND {$scopeSql} {$branchSql} LIMIT 1 FOR UPDATE",
            $types,
            $params
        );
        if (!is_array($order) || printflow_expired_product_order_db_error_count() > $queryErrorsBefore) {
            throw new RuntimeException('Unable to lock the current order state.');
        }
        if ($order === []) {
            $conn->commit();
            return null;
        }

        $eligibilitySql = $expiredOnly
            ? printflow_expired_product_order_archived_sql('o')
            : printflow_expired_product_order_retention_eligible_sql('o');
        $queryErrorsBefore = printflow_expired_product_order_db_error_count();
        $stillEligible = db_query(
            "SELECT o.order_id FROM orders o
             WHERE o.order_id = ? AND o.order_type = 'product'
               AND {$scopeSql} {$branchSql}
               AND {$eligibilitySql}
             LIMIT 1 FOR UPDATE",
            $types,
            $params
        );
        if (!is_array($stillEligible)
            || printflow_expired_product_order_db_error_count() > $queryErrorsBefore) {
            throw new RuntimeException('Unable to recheck current order eligibility.');
        }
        if ($stillEligible === []) {
            $conn->commit();
            return null;
        }

        printflow_expired_product_order_delete_single($orderId);
        $deleted = db_execute_affected_rows('DELETE FROM orders WHERE order_id = ?', 'i', [$orderId]);
        if ($deleted !== 1) {
            throw new RuntimeException('Order row deletion did not affect exactly one row.');
        }
        if (!$conn->commit()) {
            throw new RuntimeException('Could not commit order cleanup.');
        }
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[expired_product_order_retention] order deletion failed for #' . $orderId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Remove up to $limit stale unpaid online product orders. A branch ID scopes
 * the staff-page fallback; null is reserved for the scheduled all-branch job.
 *
 * @return array{ok:bool,eligible_count:int,deleted_count:int,skipped_count:int,failed_count:int,order_ids:array<int,int>}
 */
function printflow_expired_product_order_cleanup(?int $branchId = null, int $limit = 500): array
{
    if (!printflow_expired_product_order_physical_cleanup_enabled()) {
        return [
            'ok' => true,
            'eligible_count' => 0,
            'deleted_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'order_ids' => [],
        ];
    }

    $limit = max(1, min(2000, $limit));
    $schemaErrorsBefore = printflow_expired_product_order_db_error_count();
    if (!printflow_expired_product_order_retention_schema_safe()
        || printflow_expired_product_order_db_error_count() > $schemaErrorsBefore) {
        error_log('[expired_product_order_retention] required order/payment timestamp schema is incomplete; cleanup failed closed.');
        return ['ok' => false, 'eligible_count' => 0, 'deleted_count' => 0, 'skipped_count' => 0, 'failed_count' => 0, 'order_ids' => []];
    }

    $scopeSql = printflow_staff_order_source_sql('o', 'online');
    $types = '';
    $params = [];
    $branchSql = '';
    if ($branchId !== null && $branchId > 0) {
        $branchSql = branch_where('o', $branchId, $types, $params);
    }
    $params[] = $limit;
    $types .= 'i';

    $queryErrorsBefore = printflow_expired_product_order_db_error_count();
    $rows = db_query(
        "SELECT o.order_id FROM orders o
         WHERE o.order_type = 'product'
           AND {$scopeSql} {$branchSql}
           AND " . printflow_expired_product_order_retention_eligible_sql('o') . "
         ORDER BY o.order_id ASC LIMIT ?",
        $types !== '' ? $types : null,
        $params
    );
    if (!is_array($rows) || printflow_expired_product_order_db_error_count() > $queryErrorsBefore) {
        error_log('[expired_product_order_retention] candidate query failed; no cleanup performed.');
        return ['ok' => false, 'eligible_count' => 0, 'deleted_count' => 0, 'skipped_count' => 0, 'failed_count' => 0, 'order_ids' => []];
    }
    $orderIds = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int)($row['order_id'] ?? 0),
        $rows
    ))));

    $deletedIds = [];
    $failed = 0;
    $skipped = 0;
    foreach ($orderIds as $orderId) {
        $deleteResult = printflow_expired_product_order_delete_if_eligible($orderId, $branchId);
        if ($deleteResult === true) {
            $deletedIds[] = $orderId;
        } elseif ($deleteResult === false) {
            $failed++;
        } else {
            $skipped++;
        }
    }

    return [
        'ok' => $failed === 0,
        'eligible_count' => count($orderIds),
        'deleted_count' => count($deletedIds),
        'skipped_count' => $skipped,
        'failed_count' => $failed,
        'order_ids' => $deletedIds,
    ];
}

/**
 * @return array{ok:bool,message:string,deleted_count:int,order_ids:array<int,int>}
 */
function printflow_expired_product_order_purge_archived(?int $branchId = null): array
{
    $scopeSql = printflow_staff_order_source_sql('o', 'online');
    $types = '';
    $params = [];
    $branchSql = '';
    if ($branchId !== null && $branchId > 0) {
        $branchSql = branch_where('o', $branchId, $types, $params);
    }
    $rows = db_query(
        "SELECT o.order_id FROM orders o
         WHERE o.order_type = 'product' AND {$scopeSql} {$branchSql}
           AND " . printflow_expired_product_order_archived_sql('o') . "
         ORDER BY o.order_id ASC",
        $types ?: null,
        $params ?: null
    ) ?: [];
    $orderIds = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int)($row['order_id'] ?? 0),
        $rows
    ))));
    $deletedIds = [];
    foreach ($orderIds as $orderId) {
        if (printflow_expired_product_order_delete_if_eligible($orderId, $branchId, true) === true) {
            $deletedIds[] = $orderId;
        }
    }

    $adminId = (int)(function_exists('get_user_id') ? get_user_id() : 0);
    if ($adminId > 0 && function_exists('log_activity') && $deletedIds !== []) {
        log_activity(
            $adminId,
            'Clear Archived Expired Orders',
            'Permanently deleted ' . count($deletedIds) . ' expired product order(s): '
            . implode(', ', array_map(static fn(int $id): string => '#' . $id, $deletedIds))
        );
    }

    return [
        'ok' => count($deletedIds) === count($orderIds),
        'message' => $deletedIds === [] ? 'No eligible archived expired orders to delete.' : ('Deleted ' . count($deletedIds) . ' expired product order(s).'),
        'deleted_count' => count($deletedIds),
        'order_ids' => $deletedIds,
    ];
}

function printflow_expired_product_order_delete_single(int $orderId): void
{
    if ($orderId <= 0) {
        throw new InvalidArgumentException('Invalid order ID for retention cleanup.');
    }

    $tables = [
        ['customizations', 'order_id'],
        ['order_messages', 'order_id'],
        ['reviews', 'order_id'],
        ['order_status_history', 'order_id'],
        ['provider_payments', 'order_id'],
        ['order_items', 'order_id'],
    ];

    foreach ($tables as [$table, $column]) {
        if (!db_table_has_column($table, $column)) {
            continue;
        }
        if (db_execute("DELETE FROM {$table} WHERE {$column} = ?", 'i', [$orderId]) === false) {
            throw new RuntimeException("Could not remove dependent {$table} rows.");
        }
    }

    if (db_table_has_column('provider_payments', 'subject_id')
        && db_execute(
            "DELETE FROM provider_payments WHERE subject_type = 'order' AND subject_id = ?",
            'i',
            [$orderId]
        ) === false) {
        throw new RuntimeException('Could not remove the unpaid payment ledger rows.');
    }

    if (db_table_has_column('notifications', 'data_id')
        && db_table_has_column('notifications', 'notification_type')
        && db_execute(
            "DELETE FROM notifications WHERE data_id = ? AND notification_type IN ('Order', 'Payment', 'Status')",
            'i',
            [$orderId]
        ) === false) {
        throw new RuntimeException('Could not remove order notifications.');
    }
}
