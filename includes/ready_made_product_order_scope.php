<?php

/**
 * SQL predicates that approximate {@see printflow_is_ready_made_product_order()}.
 * Used for list/count exclusion without per-row PHP classification.
 */

if (!function_exists('printflow_ready_made_order_sql_alias')) {
    function printflow_ready_made_order_sql_alias(string $alias, string $fallback = 'o'): string
    {
        $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?? '';
        return $alias !== '' ? $alias : $fallback;
    }
}

if (!function_exists('printflow_ready_made_product_order_scope_sql')) {
    /**
     * Ready-made catalog product orders (including sticker SKUs), not service/custom workflows.
     */
    function printflow_ready_made_product_order_scope_sql(string $oAlias = 'o'): string
    {
        $o = printflow_ready_made_order_sql_alias($oAlias);
        $isProductOrder = "LOWER(TRIM(COALESCE({$o}.order_type, ''))) = 'product'";
        $hasProductLine = "EXISTS (
            SELECT 1 FROM order_items oi_p
            WHERE oi_p.order_id = {$o}.order_id AND COALESCE(oi_p.product_id, 0) > 0
        )";

        $legacyServiceRefOnly = "(
            COALESCE({$o}.reference_id, 0) > 0
            AND EXISTS (SELECT 1 FROM services s_legacy WHERE s_legacy.service_id = {$o}.reference_id)
            AND NOT {$hasProductLine}
        )";

        // Catalog lines (incl. stickers) may retain legacy service_id / item_type markers;
        // a persisted product_id is authoritative, matching printflow_is_ready_made_product_order().
        return "(
            {$isProductOrder}
            AND {$hasProductLine}
            AND NOT {$legacyServiceRefOnly}
        )";
    }
}
