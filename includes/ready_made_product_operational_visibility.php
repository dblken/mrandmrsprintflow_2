<?php

/**
 * Single operational visibility rule for ready-made catalog product orders (incl. stickers).
 * Hidden rows remain in the database for audit; lists/counts/search use NOT (hidden).
 */

require_once __DIR__ . '/ready_made_product_order_scope.php';
require_once __DIR__ . '/provider_payments.php';

if (!function_exists('printflow_ready_made_product_operational_order_alias')) {
    function printflow_ready_made_product_operational_order_alias(string $alias, string $fallback = 'o'): string
    {
        $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?? '';
        return $alias !== '' ? $alias : $fallback;
    }
}

if (!function_exists('printflow_ready_made_product_operational_age_expr')) {
    /** Age anchor: order creation time shown in staff lists (not need-by / display-only dates). */
    function printflow_ready_made_product_operational_age_expr(string $oAlias = 'o'): string
    {
        $o = printflow_ready_made_product_operational_order_alias($oAlias);
        $orderDate = "NULLIF({$o}.order_date, '0000-00-00 00:00:00')";
        if (function_exists('db_table_has_column') && db_table_has_column('orders', 'created_at')) {
            return "COALESCE({$orderDate}, NULLIF({$o}.created_at, '0000-00-00 00:00:00'))";
        }

        return $orderDate;
    }
}

if (!function_exists('printflow_ready_made_product_operational_age_due_sql')) {
    function printflow_ready_made_product_operational_age_due_sql(string $oAlias = 'o'): string
    {
        $ageExpr = printflow_ready_made_product_operational_age_expr($oAlias);

        return "({$ageExpr} IS NOT NULL AND {$ageExpr} <= DATE_SUB(NOW(), INTERVAL 24 HOUR))";
    }
}

if (!function_exists('printflow_ready_made_product_operational_latest_provider_status_expr')) {
    function printflow_ready_made_product_operational_latest_provider_status_expr(string $oAlias = 'o'): string
    {
        if (!function_exists('db_table_has_column') || !db_table_has_column('provider_payments', 'status')) {
            return 'NULL';
        }
        $mode = function_exists('printflow_paymongo_mode') ? printflow_paymongo_mode() : '';
        $modeSql = in_array($mode, ['test', 'live'], true) && db_table_has_column('provider_payments', 'mode')
            ? " AND pp_vis.mode = '{$mode}'"
            : '';

        return "(SELECT LOWER(TRIM(COALESCE(pp_vis.status, '')))
                 FROM provider_payments pp_vis
                 WHERE pp_vis.subject_type = 'order'
                   AND pp_vis.subject_id = {$oAlias}.order_id
                   AND pp_vis.channel = 'online'
                   AND pp_vis.provider = 'paymongo'{$modeSql}
                 ORDER BY pp_vis.id DESC LIMIT 1)";
    }
}

if (!function_exists('printflow_ready_made_product_operational_paid_sql')) {
    /** Paid or post-payment fulfillment — must remain visible in operational views. */
    function printflow_ready_made_product_operational_paid_sql(string $oAlias = 'o'): string
    {
        $o = printflow_ready_made_product_operational_order_alias($oAlias);
        $rawStatus = "LOWER(TRIM(COALESCE({$o}.status, '')))";
        $rawPayment = "LOWER(TRIM(COALESCE({$o}.payment_status, '')))";
        $fulfilledOrder = "{$rawStatus} IN (
            'paid', 'payment confirmed', 'processing', 'in production', 'printing',
            'approved', 'approved design', 'ready for pickup', 'to pickup',
            'to pick up', 'completed', 'delivered'
        )";
        $fulfilledPayment = "{$rawPayment} IN (
            'paid', 'fully paid', 'payment confirmed', 'success', 'successful', 'succeeded'
        )";
        $providerPaid = printflow_ready_made_product_operational_latest_provider_status_expr($o);
        $providerPaidMatch = "({$providerPaid}) = 'paid'";

        return "({$fulfilledOrder} OR {$fulfilledPayment} OR {$providerPaidMatch})";
    }
}

if (!function_exists('printflow_ready_made_product_checkout_incomplete_sql')) {
    /**
     * Legacy + current pre-fulfillment checkout labels (staff UI may read as "Pending").
     */
    function printflow_ready_made_product_checkout_incomplete_sql(string $oAlias = 'o'): string
    {
        $rawStatus = "LOWER(TRIM(COALESCE({$oAlias}.status, '')))";
        $legacyPending = "{$rawStatus} IN (
            'pending', 'pending review', 'pending approval', 'pending verification',
            'to pay', 'awaiting payment', 'to verify', 'verify pay',
            'downpayment submitted', 'payment rejected', 'rejected'
        )";
        $providerStatus = printflow_ready_made_product_operational_latest_provider_status_expr($oAlias);
        $providerCheckout = "({$providerStatus}) IN (
            'awaiting_payment', 'generating', 'failed', 'cancelled', 'expired'
        )";

        return "(({$legacyPending}) OR ({$providerCheckout}))";
    }
}

if (!function_exists('printflow_ready_made_product_operational_hidden_sql')) {
    /**
     * Ready-made rows that must not appear in staff/admin operational surfaces.
     */
    function printflow_ready_made_product_operational_hidden_sql(string $oAlias = 'o'): string
    {
        $readyMade = printflow_ready_made_product_order_scope_sql($oAlias);
        $rawStatus = "LOWER(TRIM(COALESCE({$oAlias}.status, '')))";
        $paid = printflow_ready_made_product_operational_paid_sql($oAlias);
        $notPaid = "NOT ({$paid})";

        $cancelled = "{$rawStatus} IN ('cancelled', 'canceled')";

        $providerStatus = printflow_ready_made_product_operational_latest_provider_status_expr($oAlias);
        $expired = "({$rawStatus} = 'expired' OR ({$providerStatus}) = 'expired')";

        $staleUnpaidCheckout = '(' . printflow_ready_made_product_checkout_incomplete_sql($oAlias) . ')
            AND (' . printflow_ready_made_product_operational_age_due_sql($oAlias) . ')';

        return "({$readyMade} AND (
            ({$cancelled})
            OR (({$expired}) AND {$notPaid})
            OR (({$staleUnpaidCheckout}) AND {$notPaid})
        ))";
    }
}

if (!function_exists('printflow_ready_made_product_operational_visible_sql')) {
    function printflow_ready_made_product_operational_visible_sql(string $oAlias = 'o'): string
    {
        return 'NOT (' . printflow_ready_made_product_operational_hidden_sql($oAlias) . ')';
    }
}
