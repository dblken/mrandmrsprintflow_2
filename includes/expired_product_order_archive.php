<?php

/**
 * Expired unpaid online QRPH product orders — archive visibility and admin purge.
 */

require_once __DIR__ . '/provider_payments.php';
require_once __DIR__ . '/staff_order_status_buckets.php';

function printflow_expired_product_order_provider_mode_sql(string $ppAlias = 'pp'): string
{
    $mode = printflow_paymongo_mode();
    if (!in_array($mode, ['test', 'live'], true) || !db_table_has_column('provider_payments', 'mode')) {
        return '';
    }

    return " AND {$ppAlias}.mode = '" . $mode . "'";
}

function printflow_expired_product_order_expired_at_expr(string $ppAlias = 'pp'): string
{
    return "COALESCE({$ppAlias}.updated_at, {$ppAlias}.qr_expires_at, {$ppAlias}.created_at)";
}

/** Base: confirmed expired QRPH, unpaid product order, no paid ledger, no fulfillment. */
function printflow_expired_product_order_unpaid_expired_sql(string $oAlias = 'o'): string
{
    if (!printflow_provider_payments_ready()) {
        return '0=1';
    }

    $modeSql = printflow_expired_product_order_provider_mode_sql('pp');
    $expiredAt = printflow_expired_product_order_expired_at_expr('pp');

    $unpaidOrder = "LOWER(TRIM(COALESCE({$oAlias}.payment_status, ''))) NOT IN ('paid', 'fully paid')
        AND LOWER(TRIM(COALESCE({$oAlias}.status, ''))) NOT IN ('completed', 'cancelled', 'rejected')";
    $noInventoryOut = "NOT EXISTS (
        SELECT 1 FROM inventory_transactions it
        WHERE UPPER(TRIM(COALESCE(it.ref_type, ''))) IN ('ORDER', 'ORDER_PRODUCT')
          AND it.ref_id = {$oAlias}.order_id
          AND UPPER(TRIM(COALESCE(it.direction, ''))) = 'OUT'
    )";
    $expiredLedger = "EXISTS (
        SELECT 1 FROM provider_payments pp
        WHERE pp.subject_type = 'order'
          AND pp.subject_id = {$oAlias}.order_id
          AND pp.channel = 'online'
          AND pp.provider = 'paymongo'{$modeSql}
          AND pp.status = 'expired'
          AND pp.fulfillment_applied_at IS NULL
    )";
    $noPaidLedger = "NOT EXISTS (
        SELECT 1 FROM provider_payments pp_paid
        WHERE pp_paid.subject_type = 'order'
          AND pp_paid.subject_id = {$oAlias}.order_id
          AND pp_paid.channel = 'online'
          AND pp_paid.provider = 'paymongo'" . printflow_expired_product_order_provider_mode_sql('pp_paid') . "
          AND pp_paid.status = 'paid'
    )";

    return "({$unpaidOrder}) AND ({$noInventoryOut}) AND ({$expiredLedger}) AND ({$noPaidLedger})";
}

/** Archived modal: expired unpaid orders older than 7 days (still in DB until admin purge). */
function printflow_expired_product_order_archived_sql(string $oAlias = 'o'): string
{
    if (!printflow_provider_payments_ready()) {
        return '0=1';
    }

    $modeSql = printflow_expired_product_order_provider_mode_sql('pp');
    $expiredAt = printflow_expired_product_order_expired_at_expr('pp');
    $base = printflow_expired_product_order_unpaid_expired_sql($oAlias);

    $agedOut = "EXISTS (
        SELECT 1 FROM provider_payments pp
        WHERE pp.subject_type = 'order'
          AND pp.subject_id = {$oAlias}.order_id
          AND pp.channel = 'online'
          AND pp.provider = 'paymongo'{$modeSql}
          AND pp.status = 'expired'
          AND pp.fulfillment_applied_at IS NULL
          AND {$expiredAt} < DATE_SUB(NOW(), INTERVAL 7 DAY)
    )";

    return "({$base}) AND ({$agedOut})";
}

/** Visible on main Product Orders list: not archived-eligible. */
function printflow_expired_product_order_exclude_archived_sql(string $oAlias = 'o'): string
{
    return 'NOT (' . printflow_expired_product_order_archived_sql($oAlias) . ')';
}

/** Expired but still within the 7-day grace window (shows Expired on main list). */
function printflow_expired_product_order_recent_expired_sql(string $oAlias = 'o'): string
{
    $base = printflow_expired_product_order_unpaid_expired_sql($oAlias);
    $notArchived = printflow_expired_product_order_exclude_archived_sql($oAlias);

    return "({$base}) AND ({$notArchived})";
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
           AND " . printflow_expired_product_order_archived_sql('o'),
        $types,
        $params
    ) ?: [];

    return (int)($row[0]['c'] ?? 0);
}

/**
 * @return array{ok:bool,message:string,deleted_count:int,order_ids:array<int,int>}
 */
function printflow_expired_product_order_purge_archived(?int $branchId = null): array
{
    global $conn;

    $scopeSql = printflow_staff_order_source_sql('o', 'online');
    $types = '';
    $params = [];
    $branchSql = '';
    if ($branchId !== null && $branchId > 0) {
        $branchSql = branch_where('o', $branchId, $types, $params);
    }

    $rows = db_query(
        "SELECT o.order_id
         FROM orders o
         WHERE o.order_type = 'product'
           AND {$scopeSql}
           {$branchSql}
           AND " . printflow_expired_product_order_archived_sql('o') . "
         ORDER BY o.order_id ASC",
        $types !== '' ? $types : null,
        $params !== [] ? $params : null
    ) ?: [];

    $orderIds = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int)($row['order_id'] ?? 0),
        $rows
    ))));

    if ($orderIds === []) {
        return [
            'ok' => true,
            'message' => 'No eligible archived expired orders to delete.',
            'deleted_count' => 0,
            'order_ids' => [],
        ];
    }

    if (!($conn instanceof mysqli)) {
        return ['ok' => false, 'message' => 'Database connection unavailable.', 'deleted_count' => 0, 'order_ids' => []];
    }

    $conn->begin_transaction();
    $deletedIds = [];
    try {
        foreach ($orderIds as $orderId) {
            $stillEligible = db_query(
                "SELECT o.order_id
                 FROM orders o
                 WHERE o.order_id = ?
                   AND o.order_type = 'product'
                   AND {$scopeSql}
                   AND " . printflow_expired_product_order_archived_sql('o') . "
                 LIMIT 1",
                'i',
                [$orderId]
            ) ?: [];
            if ($stillEligible === []) {
                continue;
            }
            printflow_expired_product_order_delete_single($orderId);
            $deletedIds[] = $orderId;
        }
        $conn->commit();

        $adminId = (int)(function_exists('get_user_id') ? get_user_id() : 0);
        if ($adminId > 0 && function_exists('log_activity') && $deletedIds !== []) {
            log_activity(
                $adminId,
                'Clear Archived Expired Orders',
                'Permanently deleted ' . count($deletedIds) . ' archived expired product order(s): '
                . implode(', ', array_map(static fn(int $id): string => '#' . $id, $deletedIds))
            );
        }

        return [
            'ok' => true,
            'message' => $deletedIds === []
                ? 'No eligible archived expired orders to delete.'
                : ('Deleted ' . count($deletedIds) . ' archived expired product order(s).'),
            'deleted_count' => count($deletedIds),
            'order_ids' => $deletedIds,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[expired_product_order_archive] purge failed: ' . $e->getMessage());

        return [
            'ok' => false,
            'message' => 'Deletion failed. No partial changes were saved.',
            'deleted_count' => 0,
            'order_ids' => [],
        ];
    }
}

function printflow_expired_product_order_delete_single(int $orderId): void
{
    if ($orderId <= 0) {
        return;
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
        if (function_exists('db_table_has_column') && !db_table_has_column($table, $column)) {
            continue;
        }
        db_execute("DELETE FROM {$table} WHERE {$column} = ?", 'i', [$orderId]);
    }

    if (db_table_has_column('provider_payments', 'subject_id')) {
        db_execute(
            "DELETE FROM provider_payments WHERE subject_type = 'order' AND subject_id = ?",
            'i',
            [$orderId]
        );
    }

    if (db_table_has_column('notifications', 'data_id')) {
        db_execute(
            "DELETE FROM notifications WHERE data_id = ? AND notification_type IN ('Order', 'Payment', 'Status')",
            'i',
            [$orderId]
        );
    }

    db_execute('DELETE FROM orders WHERE order_id = ?', 'i', [$orderId]);
}
