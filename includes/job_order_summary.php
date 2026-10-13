<?php

/**
 * Stable, deliberately small staff list-row contract.
 *
 * Keep this file dependency-free so the contract and payload ceiling can be
 * tested without a database or authenticated web session.
 */
function jo_api_summary_row(array $row): array
{
    static $allowed = [
        'id', 'order_id', 'job_order_id', 'order_item_id', 'customer_id', 'branch_id',
        'first_name', 'last_name', 'customer_full_name', 'customer_name', 'customer_email',
        'customer_type', 'transaction_count', 'order_type', 'order_source', 'order_code',
        'service_type', 'job_title', 'width_ft', 'height_ft', 'quantity', 'status',
        'payment_proof_status', 'payment_status', 'provider_payment_status',
        'created_at', 'updated_at', 'order_date', 'order_business_date', 'due_date', 'priority',
        'estimated_total', 'amount_paid', 'required_payment', 'readiness', 'estimated_cost',
        'items',
        'is_urgent_request', 'is_regular_priority', 'has_priority_field',
        'priority_request_label', 'priority_request_value', 'priority_request_related',
        'needed_date', 'needed_date_display',
        'has_change_item', 'change_item_active', 'change_item_count', 'change_item_badge', 'change_item_status',
        'change_item_request_id', 'change_item_pending_review', 'change_item_code',
        'change_item_request_source', 'change_item_request_source_label',
        'change_item_verification_status', 'change_item_change_status',
    ];

    $summary = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $row)) {
            $summary[$key] = $row[$key];
        }
    }
    return $summary;
}

/** @param array<int,array<string,mixed>> $rows */
function jo_api_summary_rows(array $rows): array
{
    return array_map('jo_api_summary_row', $rows);
}

/**
 * Business/order datetime for customizations (POS backdated order_date when set).
 */
function printflow_job_order_business_date_value(array $jobRow, ?array $orderRow = null): string
{
    $orderDate = '';
    if ($orderRow !== null) {
        $orderDate = trim((string)($orderRow['order_date'] ?? ''));
    }
    if ($orderDate === '') {
        $orderDate = trim((string)($jobRow['order_date'] ?? $jobRow['store_order_date'] ?? ''));
    }
    if ($orderDate !== '') {
        return $orderDate;
    }

    return trim((string)($jobRow['created_at'] ?? ''));
}

function printflow_format_business_datetime_display(string $raw, string $timezone = 'Asia/Manila'): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable(str_replace('T', ' ', $raw), new DateTimeZone($timezone));
    } catch (Throwable $e) {
        $ts = strtotime($raw);
        if ($ts === false) {
            return $raw;
        }
        $dt = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone($timezone));
    }

    return $dt->format('M j, Y g:i A');
}

/**
 * Resolve the service label for one job line (customization/order item), not the first cart line.
 */
function printflow_job_order_display_service_type(array $jobRow): string
{
    $orderId = (int)($jobRow['order_id'] ?? 0);
    $orderItemId = (int)($jobRow['order_item_id'] ?? 0);

    if ($orderId > 0 && $orderItemId > 0 && function_exists('db_query')) {
        $rows = db_query(
            "SELECT c.service_type, c.customization_details, oi.customization_data
             FROM customizations c
             LEFT JOIN order_items oi ON oi.order_item_id = c.order_item_id
             WHERE c.order_id = ? AND c.order_item_id = ?
             LIMIT 1",
            'ii',
            [$orderId, $orderItemId]
        ) ?: [];
        if (!empty($rows[0])) {
            $serviceType = trim((string)($rows[0]['service_type'] ?? ''));
            if ($serviceType !== '') {
                return $serviceType;
            }
            if (!function_exists('printflow_decode_modal_customization_payload')) {
                require_once __DIR__ . '/functions.php';
            }
            $details = printflow_decode_modal_customization_payload((string)($rows[0]['customization_details'] ?? ''));
            if ($details === [] && !empty($rows[0]['customization_data'])) {
                $details = printflow_decode_modal_customization_payload((string)$rows[0]['customization_data']);
            }
            if (function_exists('get_service_name_from_customization')) {
                $fromCustom = get_service_name_from_customization($details, '');
                if ($fromCustom !== '') {
                    return $fromCustom;
                }
            }
        }
    }

    if ($orderId > 0 && $orderItemId > 0) {
        require_once __DIR__ . '/JobOrderService.php';
        $payload = JobOrderService::getStoreOrderItemsPayload($orderId, false, true);
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if (function_exists('printflow_anchor_material_context_to_order_item')) {
            require_once __DIR__ . '/functions.php';
            $ctx = printflow_anchor_material_context_to_order_item(
                $items,
                $orderItemId,
                trim((string)($jobRow['service_type'] ?? '')),
                [],
                (string)($jobRow['width_ft'] ?? '1'),
                (string)($jobRow['height_ft'] ?? '1')
            );
            $resolved = trim((string)($ctx['serviceName'] ?? ''));
            if ($resolved !== '' && strcasecmp($resolved, 'Custom Order') !== 0) {
                return $resolved;
            }
        }
    }

    return trim((string)($jobRow['service_type'] ?? $jobRow['job_title'] ?? ''));
}
