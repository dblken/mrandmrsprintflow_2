<?php

declare(strict_types=1);

/**
 * Canonical customization status counts for staff Custom Orders UI and POS dashboard KPIs.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/order_archive.php';
require_once __DIR__ . '/change_item_workflow.php';
require_once __DIR__ . '/service_order_helper.php';
require_once __DIR__ . '/service_field_priority_helper.php';
require_once __DIR__ . '/job_order_list_helpers.php';

if (!defined('PRINTFLOW_CUSTOMIZATION_COUNTS_QUERY_VERSION')) {
    define('PRINTFLOW_CUSTOMIZATION_COUNTS_QUERY_VERSION', 'staff_customizations_status_authority_20261016');
}

if (!function_exists('printflow_staff_customization_count_rows')) {
    /**
     * Build de-duplicated customization count rows (same sources as job_orders_api customization_counts).
     *
     * @return list<array<string,mixed>>
     */
    function printflow_staff_customization_count_rows(?int $branchId, string $listSource = 'pos'): array
    {
        $listSource = strtolower(trim($listSource));
        if (!in_array($listSource, ['all', 'online', 'pos'], true)) {
            $listSource = 'all';
        }

        $countRows = [];
        $orderCountSql = "SELECT o.order_id AS id, o.order_id, o.status, o.status AS store_order_status, o.updated_at,
                                 'ORDER' AS order_type, COALESCE(o.order_source, 'customer') AS order_source,
                                 LOWER(TRIM(COALESCE(c.email, ''))) AS customer_email
                          FROM orders o
                          LEFT JOIN customers c ON c.customer_id = o.customer_id
                          WHERE LOWER(TRIM(COALESCE(o.order_type, ''))) = 'custom'
                            AND " . printflow_order_archive_scope_sql('o') . "
                            AND LOWER(TRIM(COALESCE(o.order_source, ''))) NOT IN ('pos_merged', 'pos_draft')
                            AND o.status IN (
                                'Pending', 'Pending Review', 'Pending Approval', 'For Revision',
                                'Approved', 'Design Approved', 'To Pay', 'Payment Confirmed',
                                'Downpayment Submitted', 'Pending Verification', 'To Verify',
                                'Processing', 'In Production', 'Printing', 'Paid – In Process',
                                'Paid - In Process', 'Ready for Pickup', 'Completed', 'Rejected', 'Cancelled'
                            )";
        $countTypes = '';
        $countParams = [];
        if ($branchId !== null && $branchId > 0) {
            $orderCountSql .= ' AND o.branch_id = ?';
            $countTypes .= 'i';
            $countParams[] = $branchId;
        }
        $orderRows = db_query($orderCountSql, $countTypes !== '' ? $countTypes : null, $countParams !== [] ? $countParams : null) ?: [];
        $orderSourceMap = jo_api_resolve_order_sources_batch($orderRows);
        foreach ($orderRows as $row) {
            $orderId = (int)($row['order_id'] ?? 0);
            $source = $orderSourceMap[$orderId] ?? strtolower(trim((string)($row['order_source'] ?? 'customer')));
            if (jo_api_source_matches($source, $listSource)) {
                $row['_count_key'] = 'ORDER:' . $orderId;
                $row['order_source'] = $source;
                $countRows[] = $row;
            }
        }

        if ($listSource !== 'online') {
            $customCountSql = "SELECT cust.customization_id AS id, cust.order_id, cust.status, cust.updated_at,
                                      o.status AS store_order_status,
                                      'CUSTOMIZATION' AS order_type, 'pos' AS order_source
                               FROM customizations cust
                               LEFT JOIN orders o ON o.order_id = cust.order_id
                               WHERE cust.order_id IS NOT NULL
                                 AND (o.order_id IS NULL OR " . printflow_order_archive_scope_sql('o') . ")
                                 AND " . printflow_customer_exclusion_customer_scope_sql('cust.customer_id') . "
                                 AND cust.status IN (
                                     'Pending Review', 'Pending', 'Pending Approval', 'For Revision',
                                     'Approved', 'To Pay', 'Payment Confirmed', 'Pending Verification',
                                     'Downpayment Submitted', 'To Verify', 'Processing', 'In Production',
                                     'Ready for Pickup', 'Ready For Pickup', 'Completed', 'Rejected', 'Cancelled'
                                 )
                                 AND COALESCE(o.order_source, '') NOT IN ('pos_merged', 'pos_draft')
                                 AND (
                                     LOWER(TRIM(COALESCE(o.order_source, ''))) IN ('pos', 'walk-in')
                                     OR cust.customization_details LIKE '%\"source\":\"POS\"%'
                                     OR cust.customization_details LIKE '%\"source\": \"POS\"%'
                                     OR cust.customization_details LIKE '%\"_seed_batch_id\"%'
                                     OR EXISTS (SELECT 1 FROM order_items oi_seed WHERE oi_seed.order_id = cust.order_id AND oi_seed.customization_data LIKE '%\"_seed_batch_id\"%')
                                 )"
                                 . jo_api_sql_exclude_unfinalized_pos_drafts('o', 'cust');
            $customCountTypes = '';
            $customCountParams = [];
            if ($branchId !== null && $branchId > 0) {
                $customCountSql .= ' AND o.branch_id = ?';
                $customCountTypes = 'i';
                $customCountParams[] = $branchId;
            }
            foreach (db_query($customCountSql, $customCountTypes !== '' ? $customCountTypes : null, $customCountParams !== [] ? $customCountParams : null) ?: [] as $row) {
                $row['_count_key'] = 'ORDER:' . (int)($row['order_id'] ?? 0);
                $countRows[] = $row;
            }
        }

        $jobCountSql = "SELECT id, NULL AS order_id, status, updated_at, 'JOB' AS order_type
                        FROM job_orders WHERE order_id IS NULL AND " . printflow_customer_exclusion_customer_scope_sql('job_orders.customer_id');
        $jobCountTypes = '';
        $jobCountParams = [];
        if ($branchId !== null && $branchId > 0) {
            $jobCountSql .= ' AND branch_id = ?';
            $jobCountTypes = 'i';
            $jobCountParams[] = $branchId;
        }
        if ($listSource !== 'pos') {
            foreach (db_query($jobCountSql, $jobCountTypes !== '' ? $jobCountTypes : null, $jobCountParams !== [] ? $jobCountParams : null) ?: [] as $row) {
                $row['_count_key'] = 'JOB:' . (int)($row['id'] ?? 0);
                $countRows[] = $row;
            }
        }

        if ($listSource !== 'pos') {
            service_order_ensure_tables();
            $serviceCountSql = "SELECT id, id AS order_id, status, updated_at, 'SERVICE' AS order_type FROM service_orders
                                WHERE " . printflow_customer_exclusion_customer_scope_sql('service_orders.customer_id') . "
                                  AND status IN (
                                    'Pending Review', 'Pending', 'Pending Approval', 'For Revision',
                                    'Approved', 'Processing', 'Ready for Pickup', 'Ready For Pickup',
                                    'Completed', 'Rejected', 'Cancelled'
                                  )";
            $serviceCountTypes = '';
            $serviceCountParams = [];
            if ($branchId !== null && $branchId > 0) {
                $serviceCountSql .= ' AND branch_id = ?';
                $serviceCountTypes = 'i';
                $serviceCountParams[] = $branchId;
            }
            foreach (db_query($serviceCountSql, $serviceCountTypes !== '' ? $serviceCountTypes : null, $serviceCountParams !== [] ? $serviceCountParams : null) ?: [] as $row) {
                $row['_count_key'] = 'SERVICE:' . (int)($row['id'] ?? 0);
                $countRows[] = $row;
            }
        }

        $countRowsByKey = [];
        foreach ($countRows as $row) {
            $key = (string)($row['_count_key'] ?? '');
            if ($key === '') {
                continue;
            }
            jo_api_apply_authoritative_store_status($row);
            if (!isset($countRowsByKey[$key])) {
                $countRowsByKey[$key] = $row;
                continue;
            }
            $countRowsByKey[$key] = jo_api_pick_authoritative_list_row($countRowsByKey[$key], $row);
        }
        $countRows = array_values($countRowsByKey);
        foreach ($countRows as &$countRow) {
            jo_api_apply_authoritative_store_status($countRow);
        }
        unset($countRow);
        jo_api_attach_provider_payments($countRows);

        return $countRows;
    }
}

if (!function_exists('printflow_staff_customization_bucket_counts')) {
    /**
     * @param list<array<string,mixed>> $countRows
     * @return array<string,int>
     */
    function printflow_staff_customization_bucket_counts(array $countRows): array
    {
        $counts = [
            'ALL' => 0, 'INQUIRY' => 0, 'PAYMENT' => 0, 'PRODUCTION' => 0,
            'TO_RECEIVE' => 0, 'COMPLETED' => 0, 'CHANGED_ITEMS' => 0, 'CLOSED' => 0,
            'PENDING' => 0, 'CANCELLED' => 0, 'URGENT' => 0,
        ];
        $changeItemOrderIds = [];
        foreach ($countRows as $row) {
            $oid = (int)($row['order_id'] ?? 0);
            if ($oid > 0) {
                $changeItemOrderIds[] = $oid;
            }
        }
        $changeItemSummaries = printflow_change_item_batch_summaries($changeItemOrderIds);
        foreach ($countRows as $row) {
            $statusKey = strtoupper(str_replace(' ', '_', trim((string)($row['status'] ?? ''))));
            $statusKey = str_replace(['–', '-'], '_', $statusKey);
            $orderId = (int)($row['order_id'] ?? 0);
            $pendingChangeReview = $orderId > 0
                && !empty($changeItemSummaries[$orderId]['change_item_pending_review']);
            $counts['ALL']++;
            if ($pendingChangeReview) {
                $counts['CHANGED_ITEMS']++;
                continue;
            }
            if (in_array($statusKey, ['REJECTED', 'CANCELLED'], true)) {
                $counts['CLOSED']++;
                $counts['CANCELLED']++;
            } elseif ($statusKey === 'COMPLETED') {
                $counts['COMPLETED']++;
            } elseif (in_array($statusKey, ['TO_RECEIVE', 'READY_FOR_PICKUP', 'READY_TO_COLLECT'], true)) {
                $counts['TO_RECEIVE']++;
                $counts['PENDING']++;
            } elseif (in_array($statusKey, ['IN_PRODUCTION', 'PROCESSING', 'PRINTING', 'PAID___IN_PROCESS'], true)) {
                $counts['PRODUCTION']++;
                $counts['PENDING']++;
            } elseif (in_array($statusKey, ['TO_PAY', 'PAYMENT_CONFIRMED', 'TO_VERIFY', 'VERIFY_PAY', 'PENDING_VERIFICATION', 'DOWNPAYMENT_SUBMITTED'], true)) {
                $counts['PAYMENT']++;
                $counts['PENDING']++;
            } else {
                $counts['INQUIRY']++;
                $counts['PENDING']++;
            }
        }
        $urgentSeen = [];
        foreach ($countRows as $row) {
            $orderId = (int)($row['order_id'] ?? 0);
            if ($orderId <= 0 || isset($urgentSeen[$orderId])) {
                continue;
            }
            $urgentSeen[$orderId] = true;
            if (printflow_order_has_urgent_request($orderId)) {
                $counts['URGENT']++;
            }
        }

        return $counts;
    }
}

if (!function_exists('printflow_staff_customization_status_counts')) {
    /**
     * @return array<string,int>
     */
    function printflow_staff_customization_status_counts(?int $branchId, string $listSource = 'pos'): array
    {
        return printflow_staff_customization_bucket_counts(
            printflow_staff_customization_count_rows($branchId, $listSource)
        );
    }
}

if (!function_exists('printflow_staff_customization_pending_count')) {
    function printflow_staff_customization_pending_count(int $branchId, ?string $listSource = null): int
    {
        if ($listSource === null || $listSource === '') {
            $listSource = 'pos';
            if (function_exists('printflow_get_staff_access_role')) {
                $role = printflow_get_staff_access_role();
                if ($role === 'online') {
                    $listSource = 'online';
                } elseif ($role === 'pos') {
                    $listSource = 'pos';
                }
            }
        }
        $counts = printflow_staff_customization_status_counts($branchId, $listSource);

        return (int)($counts['PENDING'] ?? 0);
    }
}
