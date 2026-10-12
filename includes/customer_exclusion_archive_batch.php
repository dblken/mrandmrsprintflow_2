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

if (!function_exists('printflow_customer_archive_legacy_reasons')) {
    /** Keep the original Jenny Ferr archive reasons recognizable so that archive stays restorable. */
    function printflow_customer_archive_legacy_reasons(): array
    {
        return [
            'Admin-authorized reversible customer-specific archive/hide for Jenny Ferr account 25.',
            'Admin-authorized restoration of Jenny Ferr customer-specific archive/hide.',
        ];
    }
}

if (!function_exists('printflow_customer_archive_reference')) {
    function printflow_customer_archive_reference(?string $reason): ?string
    {
        if (!is_string($reason) || !preg_match('/Archive reference: ((?:ARC|JNY)-\d+-[A-Z0-9-]+)/', $reason, $match)) return null;
        return $match[1];
    }
}

if (!function_exists('printflow_customer_archive_managed_reason')) {
    function printflow_customer_archive_managed_reason(?string $reason): bool
    {
        return printflow_customer_archive_reference($reason) !== null
            || in_array($reason, printflow_customer_archive_legacy_reasons(), true);
    }
}

if (!function_exists('printflow_customer_archive_order_reason_is_managed')) {
    function printflow_customer_archive_order_reason_is_managed(int $customerId, ?string $reason): bool
    {
        if ($customerId < 1) return false;
        if (in_array($reason, printflow_customer_archive_legacy_reasons(), true)) return $customerId === 25;
        $reference = printflow_customer_archive_reference($reason);
        if ($reference === null) return false;
        return str_starts_with($reference, 'ARC-' . $customerId . '-')
            || str_starts_with($reference, 'JNY-' . $customerId . '-');
    }
}
