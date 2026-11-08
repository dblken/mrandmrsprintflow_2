<?php
/**
 * Shared inclusion rules for Admin transaction views.
 * Payment submissions are deliberately not evidence of a successful payment.
 */
if (defined('PRINTFLOW_ADMIN_TRANSACTION_VISIBILITY_LOADED')) {
    return;
}
define('PRINTFLOW_ADMIN_TRANSACTION_VISIBILITY_LOADED', true);

function printflow_admin_visibility_alias(string $alias, string $fallback): string
{
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias);
    return $alias !== '' ? $alias : $fallback;
}

function printflow_admin_success_payment_status_values(): array
{
    return ['paid', 'fully paid', 'payment confirmed', 'success', 'successful', 'succeeded'];
}

function printflow_admin_success_order_status_values(): array
{
    return ['paid', 'payment confirmed', 'paid in process', 'processing', 'in production', 'printing', 'to receive', 'ready for pickup', 'completed', 'delivered', 'released'];
}

function printflow_admin_normalized_status_in($status, array $allowed): bool
{
    return in_array(printflow_admin_normalize_transaction_status($status), $allowed, true);
}

function printflow_admin_normalize_transaction_status($status): string
{
    $status = strtolower(str_replace(['–', '—'], ' ', trim((string)$status)));
    return preg_replace('/[ _-]+/', ' ', $status) ?? '';
}

function printflow_admin_transaction_row_is_visible($paymentStatus, $orderStatus): bool
{
    return printflow_admin_normalized_status_in($paymentStatus, printflow_admin_success_payment_status_values())
        || printflow_admin_normalized_status_in($orderStatus, printflow_admin_success_order_status_values());
}

function printflow_admin_provider_ledger_available(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    if (function_exists('db_table_has_column')) {
        // Provider payment rows are environment-scoped. A paid test ledger row
        // must never qualify an unrelated live transaction (or vice versa).
        require_once __DIR__ . '/paymongo.php';
        $mode = function_exists('printflow_paymongo_mode') ? printflow_paymongo_mode() : '';
        if (!in_array($mode, ['test', 'live'], true)
            || !db_table_has_column('provider_payments', 'mode')) {
            return $available = false;
        }
    } else {
        return $available = false;
    }
    return $available = function_exists('db_table_has_column')
        && db_table_has_column('provider_payments', 'subject_type')
        && db_table_has_column('provider_payments', 'subject_id')
        && db_table_has_column('provider_payments', 'status');
}

function printflow_admin_provider_payment_mode_sql(string $alias): string
{
    $alias = printflow_admin_visibility_alias($alias, 'pp');
    if (!printflow_admin_provider_ledger_available()) {
        return ' AND 1 = 0';
    }
    $mode = printflow_paymongo_mode();
    // printflow_paymongo_mode() is constrained to the literal allowlist above.
    return " AND {$alias}.mode = '{$mode}'";
}

function printflow_admin_status_in_sql(string $expression, array $statuses): string
{
    $normalized = "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(COALESCE({$expression}, '')), '_', ' '), '-', ' '), '–', ' '), '—', ' '))";
    $values = array_map(static fn(string $status): string => "'" . str_replace("'", "''", $status) . "'", $statuses);
    return "{$normalized} IN (" . implode(', ', $values) . ')';
}

function printflow_admin_success_payment_sql(string $expression): string
{
    return printflow_admin_status_in_sql($expression, printflow_admin_success_payment_status_values());
}

function printflow_admin_success_order_sql(string $expression): string
{
    return printflow_admin_status_in_sql($expression, printflow_admin_success_order_status_values());
}

function printflow_admin_order_transaction_visible_sql(string $alias = 'o'): string
{
    $alias = printflow_admin_visibility_alias($alias, 'o');
    $parts = [printflow_admin_success_payment_sql("{$alias}.payment_status"), printflow_admin_success_order_sql("{$alias}.status")];
    if (printflow_admin_provider_ledger_available()) {
        $parts[] = "EXISTS (SELECT 1 FROM provider_payments pp_admin WHERE pp_admin.subject_type = 'order' AND pp_admin.subject_id = {$alias}.order_id" . printflow_admin_provider_payment_mode_sql('pp_admin') . " AND LOWER(TRIM(COALESCE(pp_admin.status, ''))) = 'paid')";
    }
    return '(' . implode(' OR ', $parts) . ')';
}

function printflow_admin_job_transaction_visible_sql(string $alias = 'jo'): string
{
    $alias = printflow_admin_visibility_alias($alias, 'jo');
    $orderVisible = printflow_admin_order_transaction_visible_sql('o_admin_visibility');
    $parts = [printflow_admin_success_payment_sql("{$alias}.payment_status"), printflow_admin_success_order_sql("{$alias}.status")];
    if (printflow_admin_provider_ledger_available()) {
        $parts[] = "EXISTS (SELECT 1 FROM provider_payments pp_admin WHERE pp_admin.subject_type = 'job_order' AND pp_admin.subject_id = {$alias}.id" . printflow_admin_provider_payment_mode_sql('pp_admin') . " AND LOWER(TRIM(COALESCE(pp_admin.status, ''))) = 'paid')";
    }
    $parts[] = "EXISTS (SELECT 1 FROM orders o_admin_visibility WHERE o_admin_visibility.order_id = {$alias}.order_id AND {$alias}.order_id > 0 AND {$orderVisible})";
    return '(' . implode(' OR ', $parts) . ')';
}

function printflow_admin_provider_payment_visible_sql(string $paymentAlias = 'pp', string $orderAlias = 'o'): string
{
    $paymentAlias = printflow_admin_visibility_alias($paymentAlias, 'pp');
    $orderAlias = printflow_admin_visibility_alias($orderAlias, 'o');
    return '((' . $paymentAlias . ".status IS NOT NULL" . printflow_admin_provider_payment_mode_sql($paymentAlias)
        . " AND LOWER(TRIM(COALESCE({$paymentAlias}.status, ''))) = 'paid')"
        . ')';
}

function printflow_admin_notification_transaction_visible_sql(string $alias = 'n'): string
{
    $alias = printflow_admin_visibility_alias($alias, 'n');
    $orderVisible = printflow_admin_order_transaction_visible_sql('o_admin_notification');
    $jobVisible = printflow_admin_job_transaction_visible_sql('jo_admin_notification');
    $paymentIssueOrderVisible = printflow_admin_order_transaction_visible_sql('o_admin_payment_issue');
    $paymentIssueJobVisible = printflow_admin_job_transaction_visible_sql('jo_admin_payment_issue');
    return "((LOWER(TRIM(COALESCE({$alias}.type, ''))) NOT IN ('order', 'payment') OR (CAST(COALESCE({$alias}.data_id, 0) AS UNSIGNED) > 0 AND EXISTS (SELECT 1 FROM orders o_admin_notification WHERE o_admin_notification.order_id = CAST({$alias}.data_id AS UNSIGNED) AND {$orderVisible})))
        AND (LOWER(TRIM(COALESCE({$alias}.type, ''))) NOT IN ('job order', 'job_order') OR (CAST(COALESCE({$alias}.data_id, 0) AS UNSIGNED) > 0 AND EXISTS (SELECT 1 FROM job_orders jo_admin_notification WHERE jo_admin_notification.id = CAST({$alias}.data_id AS UNSIGNED) AND {$jobVisible})))
        AND (LOWER(TRIM(COALESCE({$alias}.type, ''))) <> 'payment issue' OR (CAST(COALESCE({$alias}.data_id, 0) AS UNSIGNED) > 0 AND (EXISTS (SELECT 1 FROM orders o_admin_payment_issue WHERE o_admin_payment_issue.order_id = CAST({$alias}.data_id AS UNSIGNED) AND {$paymentIssueOrderVisible}) OR (NOT EXISTS (SELECT 1 FROM orders o_admin_payment_issue_missing WHERE o_admin_payment_issue_missing.order_id = CAST({$alias}.data_id AS UNSIGNED)) AND EXISTS (SELECT 1 FROM job_orders jo_admin_payment_issue WHERE jo_admin_payment_issue.id = CAST({$alias}.data_id AS UNSIGNED) AND {$paymentIssueJobVisible})))))";
}

/** Hide mapped ledger movements whose parent transaction is not successful. */
function printflow_admin_inventory_transaction_visible_sql(string $alias = 't'): string
{
    $alias = printflow_admin_visibility_alias($alias, 't');
    $refType = "UPPER(TRIM(COALESCE({$alias}.ref_type, '')))";
    $orderVisible = printflow_admin_order_transaction_visible_sql('o_admin_ledger');
    $jobVisible = printflow_admin_job_transaction_visible_sql('jo_admin_ledger');
    $serviceStatusVisible = printflow_admin_success_order_sql('so_admin_ledger.status');

    return "(({$refType} <> 'ORDER' OR EXISTS (
                SELECT 1 FROM orders o_admin_ledger
                WHERE o_admin_ledger.order_id = {$alias}.ref_id AND {$orderVisible}
            ))
        AND ({$refType} <> 'JOB_ORDER' OR EXISTS (
                SELECT 1 FROM job_orders jo_admin_ledger
                WHERE jo_admin_ledger.id = {$alias}.ref_id AND {$jobVisible}
            ))
        AND ({$refType} <> 'CHANGE_ITEM' OR EXISTS (
                SELECT 1 FROM change_item_requests cir_admin_ledger
                INNER JOIN orders o_admin_change ON o_admin_change.order_id = cir_admin_ledger.order_id
                WHERE cir_admin_ledger.change_item_id = {$alias}.ref_id AND " . printflow_admin_order_transaction_visible_sql('o_admin_change') . "
            ))
        AND ({$refType} <> 'SERVICE_ORDER' OR EXISTS (
                SELECT 1 FROM service_orders so_admin_ledger
                WHERE so_admin_ledger.id = {$alias}.ref_id AND {$serviceStatusVisible}
            )))";
}
