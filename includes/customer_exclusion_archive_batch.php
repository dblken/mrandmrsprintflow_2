<?php
declare(strict_types=1);

/** Pure helpers for counting reversible customer-order archive batches. */
if (!function_exists('printflow_customer_archive_order_is_hidden')) {
    function printflow_customer_archive_order_is_hidden(array $row, bool $customerIsHidden): bool
    {
        return $customerIsHidden
            || strtolower((string)($row['order_exclusion_status'] ?? '')) === 'active'
            || strtolower((string)($row['archive_map_status'] ?? '')) === 'archived';
    }
}

if (!function_exists('printflow_customer_archive_batch_counts')) {
    /** Returns deterministic counts without changing any records. */
    function printflow_customer_archive_batch_counts(array $orders, bool $customerIsHidden): array
    {
        $total = count($orders);
        $alreadyArchived = 0;
        foreach ($orders as $order) {
            if (printflow_customer_archive_order_is_hidden($order, $customerIsHidden)) $alreadyArchived++;
        }
        return [
            'total_orders_found' => $total,
            'already_archived_count' => $alreadyArchived,
            'newly_archived_count' => $total - $alreadyArchived,
        ];
    }
}
