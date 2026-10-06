<?php

/**
 * JSON payload for staff Archived Expired Orders modal.
 */

require_once __DIR__ . '/staff_order_status_buckets.php';

function staff_orders_attach_provider_payments_for_list(array &$orders): void
{
    if ($orders === [] || !printflow_provider_payments_ready()) {
        return;
    }
    $ids = [];
    foreach ($orders as $order) {
        $id = (int)($order['order_id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if ($ids === []) {
        return;
    }

    $mode = printflow_paymongo_mode();
    $modeSql = in_array($mode, ['test', 'live'], true) && db_table_has_column('provider_payments', 'mode')
        ? " AND mode = '{$mode}'"
        : '';
    $rows = db_query(
        "SELECT * FROM provider_payments
         WHERE subject_type = 'order' AND subject_id IN (" . implode(',', $ids) . ")
           AND channel = 'online' AND provider = 'paymongo'{$modeSql}
         ORDER BY CASE WHEN status = 'paid' THEN 0 WHEN status = 'awaiting_payment' THEN 1 ELSE 2 END,
                  id DESC"
    ) ?: [];
    $byOrder = [];
    foreach ($rows as $row) {
        $id = (int)($row['subject_id'] ?? 0);
        if ($id > 0 && !isset($byOrder[$id])) {
            $byOrder[$id] = $row;
        }
    }
    foreach ($orders as &$order) {
        $payment = $byOrder[(int)($order['order_id'] ?? 0)] ?? [];
        $order['provider_payment'] = $payment === [] ? null : printflow_provider_payment_public($payment);
    }
    unset($order);
}

function staff_orders_archived_product_name(array $order): string
{
    $display_items = (string)($order['item_names'] ?? '');
    if ($display_items === '') {
        return 'Custom Product';
    }

    if ($display_items === 'Custom Product' || $display_items === 'Custom Order') {
        $firstCustomization = $order['first_item_customization'] ?? '{}';
        $display_items = get_service_name_from_customization($firstCustomization, $display_items);
        $cJson = json_decode((string)$firstCustomization, true);
        if (is_array($cJson) && !empty($cJson['product_type']) && $cJson['product_type'] !== $display_items) {
            $display_items .= ' (' . $cJson['product_type'] . ')';
        }
    }

    return $display_items;
}

function staff_orders_archived_display_status(string $status, ?array $providerPayment = null): string
{
    $status = trim($status);
    if (in_array($status, ['Completed', 'Cancelled', 'To Pickup', 'To Pick Up', 'Ready for Pickup'], true)) {
        return $status;
    }
    if ($providerPayment !== null) {
        return match (strtolower(trim((string)($providerPayment['status'] ?? '')))) {
            'generating' => 'Processing',
            'awaiting_payment' => 'Awaiting Payment',
            'paid' => 'Paid',
            'failed' => 'Payment Failed',
            'expired' => 'Expired',
            'cancelled' => 'Payment Cancelled',
            default => 'Processing',
        };
    }
    if (in_array($status, ['To Verify', 'Pending Verification', 'Verify Pay', 'Downpayment Submitted'], true)) {
        return 'Manual Review';
    }
    if ($status === 'To Pay') {
        return 'Awaiting Payment';
    }
    if ($status === 'Payment Confirmed') {
        return 'Paid';
    }
    $knownStatuses = [
        'Completed',
        'Cancelled',
        'To Verify',
        'Pending Verification',
        'Verify Pay',
        'To Pickup',
        'Ready for Pickup',
        'Rejected',
        'Payment Rejected',
    ];
    if (in_array($status, $knownStatuses, true)) {
        return $status;
    }

    return 'Pending';
}

/**
 * @return array{success:bool,count:int,orders:array<int,array<string,mixed>>,message?:string}
 */
function printflow_staff_archived_expired_orders_payload(int $staffBranchId, string $staffOrderScopeSql, int $limit = 200): array
{
    $archivedRows = printflow_expired_product_order_fetch_archived($staffBranchId, $staffOrderScopeSql, $limit, 0);
    staff_orders_attach_provider_payments_for_list($archivedRows);
    foreach ($archivedRows as &$archivedRow) {
        $archivedRow['order_code'] = printflow_format_order_code($archivedRow['order_id'] ?? 0, $archivedRow['order_sku'] ?? '');
        $archivedRow['display_status'] = staff_orders_archived_display_status(
            (string)($archivedRow['status'] ?? ''),
            is_array($archivedRow['provider_payment'] ?? null) ? $archivedRow['provider_payment'] : null
        );
        $archivedRow['formatted_total'] = format_currency((float)($archivedRow['total_amount'] ?? 0));
        $archivedRow['formatted_date'] = format_date((string)($archivedRow['order_date'] ?? ''));
        $archivedRow['product_name'] = staff_orders_archived_product_name($archivedRow);
    }
    unset($archivedRow);

    return [
        'success' => true,
        'count' => count($archivedRows),
        'orders' => $archivedRows,
    ];
}
