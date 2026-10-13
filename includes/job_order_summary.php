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
 * Prefer saved order-item specifications over workflow mirror columns (e.g. customizations.service_type).
 *
 * @param array<string,mixed>|null $storeLine One line from getStoreOrderItemsPayload()['items']
 * @param array<string,mixed> $customizationMirror Row from customizations + order_items join
 */
function printflow_job_order_service_label_from_sources(?array $storeLine, array $customizationMirror = [], string $jobFallback = ''): string
{
    if (is_array($storeLine)) {
        $fromName = trim((string)($storeLine['product_name'] ?? ''));
        if ($fromName !== '' && strcasecmp($fromName, 'Custom Order') !== 0) {
            return $fromName;
        }
        $lineCustom = is_array($storeLine['customization'] ?? null)
            ? $storeLine['customization']
            : (is_array($storeLine['specifications'] ?? null) ? $storeLine['specifications'] : []);
        if ($lineCustom !== [] && function_exists('get_service_name_from_customization')) {
            require_once __DIR__ . '/functions.php';
            $fromLine = get_service_name_from_customization($lineCustom, '');
            if ($fromLine !== '' && strcasecmp($fromLine, 'Custom Order') !== 0) {
                return $fromLine;
            }
        }
    }

    if (!function_exists('printflow_decode_modal_customization_payload')) {
        require_once __DIR__ . '/functions.php';
    }
    $details = printflow_decode_modal_customization_payload((string)($customizationMirror['customization_details'] ?? ''));
    if ($details === [] && !empty($customizationMirror['customization_data'])) {
        $details = printflow_decode_modal_customization_payload((string)$customizationMirror['customization_data']);
    }
    if ($details !== [] && function_exists('get_service_name_from_customization')) {
        $fromDetails = get_service_name_from_customization($details, '');
        if ($fromDetails !== '' && strcasecmp($fromDetails, 'Custom Order') !== 0) {
            return $fromDetails;
        }
    }

    $mirrorType = trim((string)($customizationMirror['service_type'] ?? ''));
    if ($mirrorType !== '' && strcasecmp($mirrorType, 'Custom Order') !== 0) {
        return $mirrorType;
    }

    $fallback = trim($jobFallback);
    return $fallback !== '' ? $fallback : '';
}

/**
 * Human-readable dimensions for one anchored order line (customer-submitted values).
 *
 * @param array<string,mixed>|null $storeLine
 */
function printflow_job_order_display_dimensions_label(?array $storeLine, array $jobRow = []): string
{
    $custom = [];
    if (is_array($storeLine)) {
        $custom = is_array($storeLine['customization'] ?? null)
            ? $storeLine['customization']
            : (is_array($storeLine['specifications'] ?? null) ? $storeLine['specifications'] : []);
    }
    if ($custom === [] && is_array($jobRow['customization_details'] ?? null)) {
        $custom = $jobRow['customization_details'];
    }

    require_once __DIR__ . '/customization_normalizer.php';
    $resolved = printflow_resolve_customization_dimensions($custom);
    $width = trim((string)($resolved['width'] ?? ''));
    $height = trim((string)($resolved['height'] ?? ''));
    if ($width !== '' && $height !== '') {
        return $width . ' ft × ' . $height . ' ft';
    }

    foreach (['Dimensions', 'Size / Dimensions', 'dimensions', 'size', 'Size'] as $key) {
        if (!empty($custom[$key])) {
            $text = trim((string)$custom[$key]);
            if ($text !== '') {
                return $text;
            }
        }
    }

    $jw = trim((string)($jobRow['width_ft'] ?? ''));
    $jh = trim((string)($jobRow['height_ft'] ?? ''));
    $numeric = static function (string $v): bool {
        return $v !== '' && is_numeric($v) && abs((float)$v) >= 0.000001;
    };
    if ($numeric($jw) && $numeric($jh)) {
        return $jw . ' ft × ' . $jh . ' ft';
    }
    if ($numeric($jw) && !$numeric($jh)) {
        return $jw . ' ft';
    }

    return '';
}

/**
 * @return array<string,mixed>|null
 */
function printflow_job_order_find_store_line(array $items, int $orderItemId): ?array
{
    if ($orderItemId > 0) {
        foreach ($items as $line) {
            if ((int)($line['order_item_id'] ?? 0) === $orderItemId) {
                return is_array($line) ? $line : null;
            }
        }
    }
    if (count($items) === 1 && is_array($items[0])) {
        return $items[0];
    }

    return null;
}

/**
 * Resolve the service label for one job line (customization/order item), not the first cart line.
 */
function printflow_job_order_display_service_type(array $jobRow): string
{
    $orderId = (int)($jobRow['order_id'] ?? 0);
    $orderItemId = (int)($jobRow['order_item_id'] ?? 0);
    if ($orderItemId <= 0 && (int)($jobRow['linked_order_item_id'] ?? 0) > 0) {
        $orderItemId = (int)$jobRow['linked_order_item_id'];
    }

    $storeLine = null;
    if ($orderId > 0) {
        require_once __DIR__ . '/JobOrderService.php';
        $payload = JobOrderService::getStoreOrderItemsPayload($orderId, false, true);
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $storeLine = printflow_job_order_find_store_line($items, $orderItemId);
        if ($storeLine === null && $orderItemId <= 0 && function_exists('printflow_anchor_material_context_to_order_item')) {
            require_once __DIR__ . '/functions.php';
            $ctx = printflow_anchor_material_context_to_order_item(
                $items,
                0,
                trim((string)($jobRow['service_type'] ?? '')),
                [],
                (string)($jobRow['width_ft'] ?? '1'),
                (string)($jobRow['height_ft'] ?? '1')
            );
            $primary = $ctx['primaryItem'] ?? null;
            if (is_array($primary)) {
                $storeLine = $primary;
                $orderItemId = (int)($primary['order_item_id'] ?? 0);
            }
        }
    }

    $customizationMirror = [];
    if ($orderId > 0 && $orderItemId > 0 && function_exists('db_query')) {
        $rows = db_query(
            "SELECT c.service_type, c.customization_details, oi.customization_data, oi.specifications
             FROM customizations c
             LEFT JOIN order_items oi ON oi.order_item_id = c.order_item_id
             WHERE c.order_id = ? AND c.order_item_id = ?
             LIMIT 1",
            'ii',
            [$orderId, $orderItemId]
        ) ?: [];
        if (!empty($rows[0])) {
            $customizationMirror = $rows[0];
        } elseif ($storeLine === null) {
            $itemRows = db_query(
                "SELECT customization_data, specifications FROM order_items WHERE order_id = ? AND order_item_id = ? LIMIT 1",
                'ii',
                [$orderId, $orderItemId]
            ) ?: [];
            if (!empty($itemRows[0])) {
                $customizationMirror = $itemRows[0];
            }
        }
    }

    $resolved = printflow_job_order_service_label_from_sources(
        $storeLine,
        $customizationMirror,
        trim((string)($jobRow['service_type'] ?? $jobRow['job_title'] ?? ''))
    );
    if ($resolved !== '') {
        return $resolved;
    }

    return trim((string)($jobRow['service_type'] ?? $jobRow['job_title'] ?? ''));
}

/**
 * Customer-facing priority label (not job_orders.priority workflow codes).
 */
function printflow_format_customer_priority_display(?string $selectedValue): string
{
    $value = trim((string)$selectedValue);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^regular(\s+order)?$/i', $value)) {
        return 'Regular';
    }
    if (preg_match('/^urgent(\s+order)?$/i', $value)) {
        return 'Urgent';
    }

    return $value;
}

/**
 * Remove internal POS metadata and duplicate specification tiles from admin/staff views.
 *
 * @param array<string,string> $specs
 * @return array<string,string>
 */
function printflow_filter_operations_display_specifications(array $specs): array
{
    if ($specs === []) {
        return [];
    }

    if (!function_exists('printflow_is_internal_customization_key')) {
        require_once __DIR__ . '/service_field_priority_helper.php';
    }

    $skipExact = [
        'Needed Date',
        'Order Priority',
        'Quantity',
        'Qty',
        'Dimensions',
        'Size',
        'Size / Dimensions',
        'Design Input Mode',
        'Material Name',
        'Calculated Unit Price',
        'Calculated Estimated Price',
        'Notes',
    ];

    $material = trim((string)($specs['Material'] ?? ''));
    $out = [];
    $seenFingerprints = [];

    foreach ($specs as $label => $value) {
        if (!is_string($label)) {
            continue;
        }
        $text = trim((string)$value);
        if ($text === '') {
            continue;
        }
        if (in_array($label, $skipExact, true)) {
            continue;
        }
        if (printflow_is_internal_customization_key($label)) {
            continue;
        }
        if (preg_match('/design\s*(input\s*)?mode$/iu', $label)) {
            continue;
        }
        if (preg_match('/^calculated\b/iu', $label)) {
            continue;
        }
        if (strcasecmp($label, 'Material Name') === 0) {
            continue;
        }
        if (strcasecmp($text, 'file') === 0 && preg_match('/design|upload|input|mode/iu', $label)) {
            continue;
        }
        if ($material !== '' && strcasecmp($label, 'Material') !== 0 && strcasecmp($text, $material) === 0) {
            continue;
        }

        $fingerprint = strtolower(preg_replace('/\s+/', ' ', $label)) . '|' . strtolower(preg_replace('/\s+/', ' ', $text));
        if (isset($seenFingerprints[$fingerprint])) {
            continue;
        }
        $seenFingerprints[$fingerprint] = true;
        $out[$label] = $text;
    }

    return $out;
}

/**
 * Admin customization modal: hide material tiles (staff POS detail still shows them).
 *
 * @param array<string,string> $specs
 * @return array<string,string>
 */
function printflow_filter_admin_customization_display_specifications(array $specs): array
{
    $filtered = printflow_filter_operations_display_specifications($specs);
    foreach (['Material', 'Material Type', 'Temp Plate Material'] as $label) {
        unset($filtered[$label]);
    }

    return $filtered;
}

/**
 * Merge persisted order-item JSON with hydrated store line payload (staff/admin source of truth).
 *
 * @param array<string,mixed>|null $storeLine
 * @return array<string,mixed>
 */
function printflow_merge_order_item_customization_sources(?array $storeLine): array
{
    if (!is_array($storeLine)) {
        return [];
    }

    if (!function_exists('printflow_decode_modal_customization_payload')) {
        require_once __DIR__ . '/functions.php';
    }

    $merged = printflow_decode_modal_customization_payload((string)($storeLine['customization_data'] ?? ''));
    $specifications = printflow_decode_modal_customization_payload((string)($storeLine['specifications_raw'] ?? ''));
    if ($specifications !== []) {
        $merged = function_exists('printflow_overlay_nonempty_assoc')
            ? printflow_overlay_nonempty_assoc($merged, $specifications)
            : array_merge($merged, $specifications);
    }

    $hydrated = is_array($storeLine['customization'] ?? null)
        ? $storeLine['customization']
        : (is_array($storeLine['specifications'] ?? null) ? $storeLine['specifications'] : []);
    if ($hydrated !== []) {
        $merged = function_exists('printflow_overlay_nonempty_assoc')
            ? printflow_overlay_nonempty_assoc($merged, $hydrated)
            : array_merge($merged, $hydrated);
    }

    return is_array($merged) ? $merged : [];
}

/**
 * Resolve needed date for one anchored customization line (no order_date fallback).
 *
 * @param array<string,mixed> $customization
 * @return array{needed_date:string,needed_date_display:string}
 */
function printflow_job_order_line_needed_date_payload(array $customization): array
{
    require_once __DIR__ . '/service_field_priority_helper.php';
    $serviceId = (int)($customization['service_id'] ?? 0);
    $raw = printflow_resolve_needed_date_from_customization($customization, $serviceId);
    if ($raw === '') {
        return ['needed_date' => '', 'needed_date_display' => ''];
    }

    return [
        'needed_date' => $raw,
        'needed_date_display' => printflow_format_needed_date_display($raw),
    ];
}
