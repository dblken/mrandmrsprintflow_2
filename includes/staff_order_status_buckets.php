<?php
/**
 * Shared SQL bucket predicates for online staff order status filtering.
 */

if (!function_exists('staff_orders_sql_payment_bucket')) {
    function staff_orders_sql_payment_bucket(string $oAlias = 'o'): string {
        require_once __DIR__ . '/provider_payments.php';
        $legacy = "{$oAlias}.status IN ('To Pay', 'Payment Confirmed', 'To Verify', 'Pending Verification', 'Verify Pay', 'Downpayment Submitted', 'Payment Rejected', 'Rejected', 'Processing')";
        if (!printflow_provider_payments_ready()) {
            return '(' . $legacy . ')';
        }

        $mode = printflow_paymongo_mode();
        $modeSql = in_array($mode, ['test', 'live'], true) && db_table_has_column('provider_payments', 'mode')
            ? " AND pp.mode = '{$mode}'"
            : '';
        $provider = "EXISTS (SELECT 1 FROM provider_payments pp
            WHERE pp.subject_type = 'order' AND pp.subject_id = {$oAlias}.order_id
              AND pp.channel = 'online' AND pp.provider = 'paymongo'{$modeSql}
              AND pp.status IN ('generating', 'awaiting_payment', 'paid', 'failed', 'expired', 'cancelled'))";
        $notFulfilled = "{$oAlias}.status NOT IN ('Ready for Pickup', 'To Pickup', 'To Pick Up', 'Completed', 'Cancelled')";
        return '(' . $legacy . ' OR (' . $notFulfilled . ' AND ' . $provider . '))';
    }
}

if (!function_exists('staff_orders_sql_awaiting_payment_bucket')) {
    function staff_orders_sql_awaiting_payment_bucket(string $oAlias = 'o'): string {
        require_once __DIR__ . '/provider_payments.php';
        $legacy = "{$oAlias}.status IN ('To Pay', 'To Verify', 'Pending Verification', 'Verify Pay', 'Downpayment Submitted', 'Payment Rejected', 'Rejected', 'Processing')";
        if (!printflow_provider_payments_ready()) {
            return '(' . $legacy . ')';
        }

        $mode = printflow_paymongo_mode();
        $modeSql = in_array($mode, ['test', 'live'], true) && db_table_has_column('provider_payments', 'mode')
            ? " AND pp.mode = '{$mode}'"
            : '';
        return '(' . $legacy . " OR EXISTS (SELECT 1 FROM provider_payments pp
            WHERE pp.subject_type = 'order' AND pp.subject_id = {$oAlias}.order_id
              AND pp.channel = 'online' AND pp.provider = 'paymongo'{$modeSql}
              AND pp.status IN ('generating', 'awaiting_payment', 'failed', 'expired', 'cancelled')))";
    }
}

if (!function_exists('staff_orders_sql_paid_bucket')) {
    function staff_orders_sql_paid_bucket(string $oAlias = 'o'): string {
        require_once __DIR__ . '/provider_payments.php';
        $legacy = "{$oAlias}.status = 'Payment Confirmed'";
        if (!printflow_provider_payments_ready()) {
            return '(' . $legacy . ')';
        }

        $mode = printflow_paymongo_mode();
        $modeSql = in_array($mode, ['test', 'live'], true) && db_table_has_column('provider_payments', 'mode')
            ? " AND pp.mode = '{$mode}'"
            : '';
        return '(' . $legacy . " OR EXISTS (SELECT 1 FROM provider_payments pp
            WHERE pp.subject_type = 'order' AND pp.subject_id = {$oAlias}.order_id
              AND pp.channel = 'online' AND pp.provider = 'paymongo'{$modeSql}
              AND pp.status = 'paid'))";
    }
}
