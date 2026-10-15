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

function printflow_format_business_date_display(string $raw, string $timezone = 'Asia/Manila'): string
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

    return $dt->format('M j, Y');
}

/**
 * Prefer saved order-item specifications over workflow mirror columns (e.g. customizations.service_type).
 *
 * @param array<string,mixed>|null $storeLine One line from getStoreOrderItemsPayload()['items']
 * @param array<string,mixed> $customizationMirror Row from customizations + order_items join
 */
/**
 * Payment list/modal Product/Service label from saved order lines (not job_orders mirror or POS placeholder products).
 *
 * @param array<string,mixed> $row
 * @param array<int,string> $identityLabels from printflow_dashboard_order_service_labels()
 */
function printflow_payment_transaction_item_label(array $row, array $identityLabels = []): string
{
    $orderId = (int)($row['order_id'] ?? 0);
    if ($orderId > 0 && isset($identityLabels[$orderId])) {
        $resolved = trim((string)$identityLabels[$orderId]);
        if ($resolved !== '' && strcasecmp($resolved, 'Custom Service') !== 0) {
            return $resolved;
        }
    }

    if (strtolower(trim((string)($row['order_type'] ?? ''))) === 'custom') {
        return 'Customization';
    }

    $products = trim((string)($row['product_names'] ?? ''));
    if ($products !== '') {
        return $products;
    }

    $services = trim((string)($row['service_names'] ?? ''));
    return $services !== '' ? $services : 'Item details unavailable';
}

function printflow_job_order_service_label_from_sources(?array $storeLine, array $customizationMirror = [], string $jobFallback = ''): string
{
    if (is_array($storeLine)) {
        $fromName = trim((string)($storeLine['product_name'] ?? ''));
        if (
            $fromName !== ''
            && strcasecmp($fromName, 'Custom Order') !== 0
            && !(function_exists('customer_orders_is_generic_item_name') && customer_orders_is_generic_item_name($fromName))
        ) {
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
    foreach (['Dimensions', 'Size / Dimensions', 'dimensions', 'size', 'Size'] as $key) {
        if (empty($custom[$key])) {
            continue;
        }
        $text = trim((string)$custom[$key]);
        if ($text === '') {
            continue;
        }
        if (strcasecmp($label, 'Specifications') === 0 && strcasecmp($text, 'Historical walk-in service transaction from existing service catalog.') === 0) {
            continue;
        }
        // Keep customer wording such as a sticker cut style with its size, while
        // collapsing the accidental repeated size that older payloads can contain.
        $dimensionPair = '\d+(?:\.\d+)?\s*(?:ft|feet|in|inch|inches|cm|mm|m)?\s*[xX*]\s*\d+(?:\.\d+)?\s*(?:ft|feet|in|inch|inches|cm|mm|m)?';
        $text = preg_replace('/(' . $dimensionPair . '),\s*\1\s*$/iu', '$1', $text) ?? $text;
        if (preg_match('/' . $dimensionPair . '/iu', $text)) {
            return $text;
        }
    }
    $resolved = printflow_resolve_customization_dimensions($custom);
    $width = trim((string)($resolved['width'] ?? ''));
    $height = trim((string)($resolved['height'] ?? ''));
    if ($width !== '' && $height !== '') {
        return $width . ' ft × ' . $height . ' ft';
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
function printflow_job_order_find_store_line_from_references(array $items, int $selectedItemId, int $jobItemId): ?array
{
    foreach ([$selectedItemId, $jobItemId] as $reference) {
        if ($reference <= 0) continue;
        foreach ($items as $line) {
            if (is_array($line) && (int)($line['order_item_id'] ?? 0) === $reference) {
                return $line;
            }
        }
    }
    return printflow_job_order_find_store_line($items, 0);
}

/** Resolve one job's display identity from its own saved order line. */
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
        if ($storeLine === null && count($items) > 1) {
            $summary = printflow_store_order_service_summary($orderId, $payload);
            if ($summary !== '') {
                return $summary;
            }

            return 'Unknown Service';
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
        'Material',
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

    if (!function_exists('printflow_deduplicate_display_specifications')) {
        require_once __DIR__ . '/customization_normalizer.php';
    }

    return printflow_deduplicate_display_specifications($out);
}

/**
 * Admin/staff/customer specification grids: hide material (production uses it internally).
 *
 * @param array<string,string> $specs
 * @return array<string,string>
 */
function printflow_filter_admin_customization_display_specifications(array $specs): array
{
    $orderPriority = trim((string)($specs['Order Priority'] ?? ''));
    $filtered = printflow_filter_operations_display_specifications($specs);
    if ($orderPriority !== '') {
        $filtered['Order Priority'] = printflow_format_customer_priority_display($orderPriority) ?: $orderPriority;
    }
    if (!function_exists('printflow_deduplicate_display_specifications')) {
        require_once __DIR__ . '/customization_normalizer.php';
    }

    return printflow_deduplicate_display_specifications($filtered);
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

/**
 * @param array<string,mixed> $item Store order line from JobOrderService payload
 */
function printflow_order_item_service_label(array $item): string
{
    $name = trim((string)($item['product_name'] ?? ''));
    if ($name !== '' && !in_array(strtolower($name), ['custom order', 'customer order', 'service order', 'order item'], true)) {
        return $name;
    }
    $custom = is_array($item['customization'] ?? null) ? $item['customization'] : [];
    if ($custom !== [] && function_exists('get_service_name_from_customization')) {
        $fromCustom = trim((string)get_service_name_from_customization($custom, $name));
        if ($fromCustom !== '' && strcasecmp($fromCustom, 'Custom Order') !== 0) {
            return $fromCustom;
        }
    }

    return $name;
}

/**
 * @param array{items?:list<array<string,mixed>>}|null $payload
 */
function printflow_store_order_service_summary(int $orderId, ?array $payload = null): string
{
    if ($orderId <= 0) {
        return '';
    }
    if ($payload === null) {
        require_once __DIR__ . '/JobOrderService.php';
        $payload = JobOrderService::getStoreOrderItemsPayload($orderId, false, true);
    }
    $parts = [];
    foreach (is_array($payload['items'] ?? null) ? $payload['items'] : [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $label = printflow_order_item_service_label($item);
        if ($label === '') {
            continue;
        }
        $qty = max(1, (int)($item['quantity'] ?? 1));
        $parts[] = $qty > 1 ? $label . ' - ' . $qty . 'pcs' : $label;
    }

    return implode(', ', array_values(array_unique($parts)));
}

/**
 * Attach per-line display metadata for admin/staff modals (specs, dimensions, dates).
 *
 * @param list<array<string,mixed>>|null $items
 */
function printflow_enrich_store_order_items_for_display(?array &$items): void
{
    if (!is_array($items) || $items === []) {
        return;
    }
    require_once __DIR__ . '/customization_normalizer.php';
    require_once __DIR__ . '/service_field_priority_helper.php';

    foreach ($items as &$line) {
        if (!is_array($line)) {
            continue;
        }
        $raw = printflow_merge_order_item_customization_sources($line);
        $displaySpecsRaw = $raw !== []
            ? printflow_customization_display_specs($raw, [
                'include_service' => false,
                'include_design' => false,
                'include_notes' => true,
                'include_quantity' => false,
            ])
            : [];
        $line['display_specifications'] = printflow_filter_admin_customization_display_specifications($displaySpecsRaw);
        $neededPayload = printflow_job_order_line_needed_date_payload($raw);
        $line['needed_date'] = $neededPayload['needed_date'];
        $line['needed_date_display'] = $neededPayload['needed_date_display'];
        $line['display_dimensions'] = printflow_job_order_display_dimensions_label($line, []);
        $priorityPayload = printflow_resolve_customer_priority_from_customization($raw);
        $line['display_priority'] = printflow_format_customer_priority_display(
            (string)($priorityPayload['selected_value'] ?? '')
        );
    }
    unset($line);
}

/**
 * Admin customizations list: one row per store order (multiple job_orders merged).
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function printflow_group_admin_customization_job_rows(array $rows): array
{
    if ($rows === []) {
        return [];
    }

    $groups = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $orderId = (int)($row['order_id'] ?? 0);
        $jobId = (int)($row['id'] ?? 0);
        $key = $orderId > 0 ? 'o:' . $orderId : 'j:' . $jobId;
        $lineLabel = trim((string)($row['display_service_type'] ?? $row['service_type'] ?? $row['job_title'] ?? ''));
        if (!isset($groups[$key])) {
            $row['group_job_ids'] = [$jobId];
            $row['group_service_labels'] = $lineLabel !== '' ? [$lineLabel] : [];
            $groups[$key] = $row;
            continue;
        }
        $existing = $groups[$key];
        $mergedIds = array_values(array_unique(array_merge(
            is_array($existing['group_job_ids'] ?? null) ? $existing['group_job_ids'] : [(int)($existing['id'] ?? 0)],
            [$jobId]
        )));
        $mergedLabels = is_array($existing['group_service_labels'] ?? null) ? $existing['group_service_labels'] : [];
        if ($lineLabel !== '') {
            $mergedLabels[] = $lineLabel;
        }
        if ($jobId > 0 && ($existing['id'] ?? PHP_INT_MAX) > $jobId) {
            $row['group_job_ids'] = $mergedIds;
            $row['group_service_labels'] = $mergedLabels;
            $groups[$key] = $row;
        } else {
            $groups[$key]['group_job_ids'] = $mergedIds;
            $groups[$key]['group_service_labels'] = $mergedLabels;
        }
    }

    $out = [];
    foreach ($groups as $group) {
        $orderId = (int)($group['order_id'] ?? 0);
        if ($orderId > 0) {
            $summary = '';
            try {
                $summary = printflow_store_order_service_summary($orderId);
            } catch (Throwable $e) {
                $summary = '';
            }
            if ($summary === '') {
                $fallbackLabels = array_values(array_unique(array_filter(array_map(
                    static fn($label): string => trim((string)$label),
                    is_array($group['group_service_labels'] ?? null) ? $group['group_service_labels'] : []
                ))));
                $summary = implode(', ', $fallbackLabels);
            }
            if ($summary !== '') {
                $group['display_service_type'] = $summary;
                $group['job_title'] = $summary;
            } elseif (trim((string)($group['display_service_type'] ?? '')) === '') {
                $group['display_service_type'] = printflow_job_order_display_service_type($group);
            }
        }
        unset($group['group_service_labels']);
        $out[] = $group;
    }

    return $out;
}
