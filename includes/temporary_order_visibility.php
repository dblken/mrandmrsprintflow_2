<?php
/**
 * Narrow visibility mapping for the temporary pending/unpaid order hide.
 * This is intentionally separate from the general archive map so reports,
 * payment history, receipts, inventory and customer history keep their scope.
 */

if (!function_exists('printflow_temporary_hidden_orders_table_ready')) {
    function printflow_temporary_hidden_orders_table_ready(): bool
    {
        static $ready = null;
        if ($ready !== null) return $ready;

        $rows = db_query(
            "SELECT COUNT(*) AS n FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'printflow_pending_unpaid_hidden_orders'"
        ) ?: [];
        return $ready = (int)($rows[0]['n'] ?? 0) === 1;
    }
}

if (!function_exists('printflow_temporary_hidden_order_exclusion_sql')) {
    function printflow_temporary_hidden_order_exclusion_sql(string $orderIdExpression): string
    {
        if (!preg_match('/^[A-Za-z0-9_.]+$/', $orderIdExpression)) {
            throw new InvalidArgumentException('Invalid order ID expression.');
        }
        if (!printflow_temporary_hidden_orders_table_ready()) return '1=1';

        return "NOT EXISTS (SELECT 1 FROM printflow_pending_unpaid_hidden_orders pf_tmp_hidden
                           WHERE pf_tmp_hidden.order_id = {$orderIdExpression})";
    }
}

if (!function_exists('printflow_temporary_unpaid_hide_eligible_sql')) {
    /** Exact past Manila business-date scope expressed in UTC for TIMESTAMP. */
    function printflow_temporary_unpaid_hide_eligible_sql(string $ordersAlias = 'o'): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $ordersAlias)) {
            throw new InvalidArgumentException('Invalid orders alias.');
        }
        $o = $ordersAlias;
        return "{$o}.order_date >= '2026-08-31 16:00:00'
            AND {$o}.order_date < '2026-10-15 16:00:00'
            AND BINARY TRIM(COALESCE({$o}.status, '')) = 'Pending'
            AND BINARY TRIM(COALESCE({$o}.payment_status, '')) = 'Unpaid'
            AND COALESCE({$o}.is_archived, 0) = 0
            AND TRIM(COALESCE({$o}.payment_reference, '')) = ''
            AND TRIM(COALESCE({$o}.payment_proof_path, '')) = ''
            AND TRIM(COALESCE({$o}.payment_proof, '')) = ''
            AND {$o}.payment_submitted_at IS NULL
            AND NOT EXISTS (SELECT 1 FROM payment_submissions pf_tmp_ps
                            WHERE pf_tmp_ps.order_id = {$o}.order_id
                               OR pf_tmp_ps.job_order_id IN (SELECT pf_tmp_ps_jo.id FROM job_orders pf_tmp_ps_jo
                                                             WHERE pf_tmp_ps_jo.order_id = {$o}.order_id))
            AND NOT EXISTS (SELECT 1 FROM provider_payments pf_tmp_pp
                            WHERE pf_tmp_pp.order_id = {$o}.order_id
                               OR (pf_tmp_pp.subject_type = 'order' AND pf_tmp_pp.subject_id = {$o}.order_id)
                               OR pf_tmp_pp.job_order_id IN (SELECT pf_tmp_pp_jo.id FROM job_orders pf_tmp_pp_jo
                                                             WHERE pf_tmp_pp_jo.order_id = {$o}.order_id))
            AND NOT EXISTS (SELECT 1 FROM job_orders pf_tmp_jo
                            WHERE pf_tmp_jo.order_id = {$o}.order_id
                              AND COALESCE(UPPER(REPLACE(TRIM(pf_tmp_jo.status), ' ', '_')), '')
                                  NOT IN ('PENDING', 'PENDING_APPROVAL'))
            AND NOT EXISTS (SELECT 1 FROM customizations pf_tmp_cust
                            WHERE pf_tmp_cust.order_id = {$o}.order_id
                              AND UPPER(REPLACE(TRIM(COALESCE(pf_tmp_cust.status, '')), ' ', '_'))
                                  NOT IN ('PENDING', 'PENDING_REVIEW', 'PENDING_APPROVAL'))
            AND NOT EXISTS (SELECT 1 FROM inventory_movements pf_tmp_im
                            WHERE UPPER(TRIM(pf_tmp_im.reference_type)) = 'ORDER'
                              AND TRIM(pf_tmp_im.reference_id) = CAST({$o}.order_id AS CHAR))
            AND NOT EXISTS (SELECT 1 FROM printflow_order_archive_map pf_tmp_am
                            WHERE pf_tmp_am.order_id = {$o}.order_id AND pf_tmp_am.status = 'archived')
            AND NOT EXISTS (SELECT 1 FROM historical_generator_hidden_orders pf_tmp_hh
                            WHERE pf_tmp_hh.order_id = {$o}.order_id)
            AND NOT EXISTS (SELECT 1 FROM printflow_pending_unpaid_hidden_orders pf_tmp_done
                            WHERE pf_tmp_done.order_id = {$o}.order_id)";
    }
}
