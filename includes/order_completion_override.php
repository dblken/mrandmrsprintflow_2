<?php

declare(strict_types=1);

if (!function_exists('printflow_order_completion_override_date_in_scope')) {
    /**
     * orders.order_date is stored as the selected Manila business wall time.
     * Compare its calendar date directly so browser/server timezone settings
     * cannot move an order across the requested date boundary.
     */
    function printflow_order_completion_override_date_in_scope(string $orderDate): bool
    {
        $value = trim($orderDate);
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T]|$)/', $value, $matches)) {
            return false;
        }
        $date = $matches[1];
        return $date >= '2026-09-01' && $date <= '2026-10-05';
    }
}

if (!function_exists('printflow_order_completion_override_pending_status')) {
    function printflow_order_completion_override_pending_status(string $status): bool
    {
        $key = strtoupper(str_replace([' ', '-'], '_', trim($status)));
        return in_array($key, ['PENDING', 'PENDING_APPROVAL'], true);
    }
}
