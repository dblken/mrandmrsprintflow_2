<?php

declare(strict_types=1);

/**
 * Shared job-order list status, source resolution, and provider payment helpers.
 * Used by admin/job_orders_api.php and staff customization count metrics.
 */

require_once __DIR__ . '/provider_payments.php';

if (!function_exists('jo_api_store_order_status_map')) {
    function jo_api_store_order_status_map(): array
    {
        return [
            'Pending Review' => 'PENDING',
            'Pending' => 'PENDING',
            'Pending Approval' => 'PENDING',
            'For Revision' => 'PENDING',
            'Approved' => 'APPROVED',
            'Design Approved' => 'APPROVED',
            'To Pay' => 'TO_PAY',
            'Payment Confirmed' => 'PAYMENT_CONFIRMED',
            'Pending Verification' => 'VERIFY_PAY',
            'Downpayment Submitted' => 'VERIFY_PAY',
            'To Verify' => 'VERIFY_PAY',
            'Processing' => 'IN_PRODUCTION',
            'In Production' => 'IN_PRODUCTION',
            'Printing' => 'IN_PRODUCTION',
            'Paid – In Process' => 'IN_PRODUCTION',
            'Paid - In Process' => 'IN_PRODUCTION',
            'Ready for Pickup' => 'TO_RECEIVE',
            'Ready For Pickup' => 'TO_RECEIVE',
            'Completed' => 'COMPLETED',
            'Change Item Request' => 'CHANGE_ITEM_REQUEST',
            'Cancelled' => 'CANCELLED',
            'Rejected' => 'REJECTED',
        ];
    }
}

if (!function_exists('jo_api_map_store_order_status_to_list_enum')) {
    function jo_api_map_store_order_status_to_list_enum(?string $storeStatus): ?string
    {
        $storeStatus = trim((string)$storeStatus);
        if ($storeStatus === '') {
            return null;
        }

        return jo_api_store_order_status_map()[$storeStatus] ?? null;
    }
}

if (!function_exists('jo_api_list_status_rank')) {
    function jo_api_list_status_rank(?string $status): int
    {
        $raw = strtoupper(str_replace([' ', '–', '-'], '_', trim((string)$status)));
        $priorities = [
            'REJECTED' => 100,
            'CANCELLED' => 95,
            'COMPLETED' => 90,
            'TO_RECEIVE' => 80,
            'READY_TO_COLLECT' => 80,
            'READY_FOR_PICKUP' => 80,
            'IN_PRODUCTION' => 70,
            'PROCESSING' => 70,
            'PRINTING' => 70,
            'TO_VERIFY' => 60,
            'VERIFY_PAY' => 60,
            'PENDING_VERIFICATION' => 60,
            'DOWNPAYMENT_SUBMITTED' => 60,
            'TO_PAY' => 50,
            'PAYMENT_CONFIRMED' => 50,
            'APPROVED' => 40,
            'PENDING' => 30,
        ];

        return $priorities[$raw] ?? 0;
    }
}

if (!function_exists('jo_api_list_row_type_rank')) {
    function jo_api_list_row_type_rank(?string $orderType): int
    {
        $type = strtoupper(trim((string)$orderType));

        return match ($type) {
            'ORDER' => 4,
            'CUSTOMIZATION' => 3,
            'JOB' => 2,
            'SERVICE' => 1,
            default => 0,
        };
    }
}

if (!function_exists('jo_api_apply_authoritative_store_status')) {
    function jo_api_apply_authoritative_store_status(array &$row): void
    {
        $storeStatus = trim((string)($row['store_order_status'] ?? ''));
        if ($storeStatus === '') {
            return;
        }
        $mapped = jo_api_map_store_order_status_to_list_enum($storeStatus);
        if ($mapped !== null) {
            $row['status'] = $mapped;
        }
    }
}

if (!function_exists('jo_api_pick_authoritative_list_row')) {
    function jo_api_pick_authoritative_list_row(array $existing, array $incoming): array
    {
        $existingRank = jo_api_list_status_rank((string)($existing['status'] ?? ''));
        $incomingRank = jo_api_list_status_rank((string)($incoming['status'] ?? ''));
        if ($incomingRank !== $existingRank) {
            return $incomingRank > $existingRank ? $incoming : $existing;
        }
        $existingTypeRank = jo_api_list_row_type_rank((string)($existing['order_type'] ?? ''));
        $incomingTypeRank = jo_api_list_row_type_rank((string)($incoming['order_type'] ?? ''));
        if ($incomingTypeRank !== $existingTypeRank) {
            return $incomingTypeRank > $existingTypeRank ? $incoming : $existing;
        }
        $existingTs = strtotime((string)($existing['updated_at'] ?? $existing['created_at'] ?? '')) ?: 0;
        $incomingTs = strtotime((string)($incoming['updated_at'] ?? $incoming['created_at'] ?? '')) ?: 0;

        return $incomingTs >= $existingTs ? $incoming : $existing;
    }
}

if (!function_exists('jo_api_attach_provider_payments')) {
    /**
     * Attach the latest PayMongo ledger row without exposing provider secrets.
     */
    function jo_api_attach_provider_payments(array &$rows): void
    {
        if ($rows === [] || !printflow_provider_payments_ready()) {
            return;
        }

        $orderIds = [];
        $jobIds = [];
        foreach ($rows as $row) {
            $orderId = (int)($row['order_id'] ?? 0);
            if ($orderId > 0) {
                $orderIds[$orderId] = $orderId;
            }
            if (strtoupper((string)($row['order_type'] ?? 'JOB')) === 'JOB') {
                $jobId = (int)($row['id'] ?? 0);
                if ($jobId > 0) {
                    $jobIds[$jobId] = $jobId;
                }
            }
        }

        $conditions = [];
        if ($orderIds !== []) {
            $conditions[] = 'order_id IN (' . implode(',', $orderIds) . ')';
        }
        if ($jobIds !== []) {
            $conditions[] = "(subject_type = 'job_order' AND subject_id IN (" . implode(',', $jobIds) . '))';
        }
        if ($conditions === []) {
            return;
        }

        $payments = db_query(
            "SELECT * FROM provider_payments
             WHERE provider = 'paymongo' AND mode IN ('test', 'live')
               AND (" . implode(' OR ', $conditions) . ")
             ORDER BY CASE WHEN status = 'paid' THEN 0 WHEN status = 'awaiting_payment' THEN 1 ELSE 2 END,
                      id DESC"
        ) ?: [];
        $byOrder = [];
        $byJob = [];
        foreach ($payments as $payment) {
            $orderId = (int)($payment['order_id'] ?? 0);
            if ($orderId > 0 && !isset($byOrder[$orderId])) {
                $byOrder[$orderId] = $payment;
            }
            if (($payment['subject_type'] ?? '') === 'job_order') {
                $jobId = (int)($payment['subject_id'] ?? 0);
                if ($jobId > 0 && !isset($byJob[$jobId])) {
                    $byJob[$jobId] = $payment;
                }
            }
        }

        foreach ($rows as &$row) {
            $orderId = (int)($row['order_id'] ?? 0);
            $jobId = strtoupper((string)($row['order_type'] ?? 'JOB')) === 'JOB'
                ? (int)($row['id'] ?? 0)
                : 0;
            $payment = $orderId > 0
                ? ($byOrder[$orderId] ?? [])
                : ($byJob[$jobId] ?? []);
            if ($payment === []) {
                $row['provider_payment'] = null;
                $row['provider_payment_status'] = '';
                continue;
            }

            $public = printflow_provider_payment_public($payment);
            $row['provider_payment'] = $public;
            $row['provider_payment_status'] = (string)($public['status'] ?? '');
            if (($public['status'] ?? '') === 'paid') {
                $row['payment_status'] = 'PAID';
                $row['amount_paid'] = ((int)($public['paid_amount_centavos'] ?? 0)) / 100;
                $normalizedStatus = strtoupper(str_replace(' ', '_', trim((string)($row['status'] ?? ''))));
                if (in_array($normalizedStatus, [
                    'PENDING', 'PENDING_REVIEW', 'PENDING_APPROVAL', 'APPROVED',
                    'TO_PAY', 'VERIFY_PAY', 'TO_VERIFY', 'PENDING_VERIFICATION',
                    'DOWNPAYMENT_SUBMITTED', 'PAYMENT_CONFIRMED',
                ], true)) {
                    $row['status'] = 'PAYMENT_CONFIRMED';
                }
            }
        }
        unset($row);
    }
}

if (!function_exists('jo_api_resolve_order_sources_batch')) {
    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,string>
     */
    function jo_api_resolve_order_sources_batch(array $rows): array
    {
        $sourceMap = [];
        $orderIds = [];

        foreach ($rows as $row) {
            $orderId = (int)($row['order_id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $orderIds[$orderId] = $orderId;
            $current = strtolower(trim((string)($row['order_source'] ?? '')));
            $sourceMap[$orderId] = $current !== '' ? $current : 'customer';
        }

        if ($orderIds === []) {
            return $sourceMap;
        }

        $idList = implode(',', array_map('intval', array_values($orderIds)));
        $resolvedRows = db_query(
            "SELECT o.order_id,
                    LOWER(TRIM(COALESCE(o.order_source, ''))) AS db_order_source,
                    o.reference_id,
                    LOWER(TRIM(COALESCE(c.email, ''))) AS customer_email,
                    EXISTS(
                        SELECT 1 FROM customizations cust
                        WHERE cust.order_id = o.order_id
                          AND (
                              cust.customization_details LIKE '%\"source\":\"POS\"%'
                              OR cust.customization_details LIKE '%\"source\": \"POS\"%'
                          )
                    ) AS has_pos_customization,
                    EXISTS(
                        SELECT 1 FROM order_items oi
                        WHERE oi.order_id = o.order_id
                          AND (
                              oi.customization_data LIKE '%\"source\":\"POS\"%'
                              OR oi.customization_data LIKE '%\"source\": \"POS\"%'
                          )
                    ) AS has_pos_order_item
             FROM orders o
             LEFT JOIN customers c ON c.customer_id = o.customer_id
             WHERE o.order_id IN ({$idList})"
        ) ?: [];

        $posIdsToPersist = [];
        foreach ($resolvedRows as $row) {
            $orderId = (int)($row['order_id'] ?? 0);
            $dbSource = strtolower(trim((string)($row['db_order_source'] ?? '')));
            if (preg_match('/^HGEN-/i', trim((string)($row['reference_id'] ?? '')))) {
                $sourceMap[$orderId] = 'historical_generator';
                continue;
            }
            if (in_array($dbSource, ['pos', 'walk-in', 'pos_draft'], true)) {
                $sourceMap[$orderId] = $dbSource;
                continue;
            }

            $isWalkInGuest = strtolower(trim((string)($row['customer_email'] ?? ''))) === 'walkin@pos.local';
            if ($dbSource === 'pos_draft') {
                $sourceMap[$orderId] = 'pos_draft';
            } elseif ($isWalkInGuest || !empty($row['has_pos_customization']) || !empty($row['has_pos_order_item'])) {
                $sourceMap[$orderId] = 'pos';
                $posIdsToPersist[] = $orderId;
            } else {
                $sourceMap[$orderId] = $dbSource !== '' ? $dbSource : 'customer';
            }
        }

        if ($posIdsToPersist !== []) {
            $posIdList = implode(',', array_map('intval', array_unique($posIdsToPersist)));
            db_execute(
                "UPDATE orders
                 SET order_source = 'pos'
                 WHERE order_id IN ({$posIdList})
                   AND LOWER(TRIM(COALESCE(order_source, ''))) NOT IN ('pos', 'walk-in', 'pos_merged', 'pos_draft')"
            );
        }

        return $sourceMap;
    }
}

if (!function_exists('jo_api_source_matches')) {
    function jo_api_source_matches(string $source, string $filter): bool
    {
        if ($filter === 'all') {
            return true;
        }
        $isPos = in_array(strtolower(trim($source)), ['pos', 'walk-in'], true);

        return $filter === 'pos' ? $isPos : !$isPos;
    }
}

if (!function_exists('jo_api_sql_exclude_unfinalized_pos_drafts')) {
    function jo_api_sql_exclude_unfinalized_pos_drafts(string $orderAlias = 'o', ?string $customAlias = null): string
    {
        $customSourceClause = $customAlias !== null
            ? " OR {$customAlias}.customization_details LIKE '%\"source\":\"POS\"%'
                OR {$customAlias}.customization_details LIKE '%\"source\": \"POS\"%'"
            : '';

        return " AND NOT (
            LOWER(TRIM(COALESCE({$orderAlias}.payment_status, ''))) = 'unpaid'
            AND LOWER(TRIM(COALESCE({$orderAlias}.status, ''))) = 'draft'
            AND (
                LOWER(TRIM(COALESCE({$orderAlias}.order_source, ''))) IN ('pos_draft', '')
                {$customSourceClause}
            )
        )";
    }
}
