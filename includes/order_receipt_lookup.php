<?php

/**
 * Pure helpers for receipt/order lookup. Database access and authorization stay
 * in the authenticated API endpoint.
 */

function printflow_order_lookup_normalize_identifier($value): string {
    if (!is_scalar($value)) return '';
    $value = trim((string)$value);
    $value = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $value) ?? '';
    if ($value === '' || strlen($value) > 120 || preg_match('/[\x00-\x1F\x7F]/', $value)) return '';
    return strtoupper($value);
}

function printflow_order_lookup_candidate_order_id(string $identifier): int {
    $patterns = [
        '/^PF1:ORDER:([1-9][0-9]{0,9})$/',
        '/^POS-([0-9]{1,10})$/',
        '/^ORD-([0-9]{1,10})$/',
        '/^[A-Z0-9][A-Z0-9._-]{0,100}-([0-9]{1,10})$/',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $identifier, $matches)) {
            $id = (int)$matches[1];
            return $id > 0 && $id <= 2147483647 ? $id : 0;
        }
    }
    return 0;
}

function printflow_order_lookup_is_qr_payload(string $identifier): bool {
    return (bool)preg_match('/^PF1:ORDER:[1-9][0-9]{0,9}$/', $identifier);
}

function printflow_order_lookup_is_pos_source(?string $source): bool {
    return in_array(strtolower(trim((string)$source)), ['pos', 'walk-in'], true);
}

function printflow_order_lookup_visible_identifier_matches(
    string $identifier,
    int $orderId,
    string $orderSource,
    string $canonicalOrderCode
): bool {
    if (printflow_order_lookup_is_qr_payload($identifier)) {
        return $identifier === 'PF1:ORDER:' . $orderId;
    }
    if (printflow_order_lookup_is_pos_source($orderSource)) {
        return $identifier === 'POS-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT);
    }
    return strcasecmp($identifier, $canonicalOrderCode) === 0;
}

function printflow_order_lookup_management_route(
    string $userType,
    int $orderId,
    int $branchId,
    int $jobOrderId,
    bool $isCustom,
    string $basePath,
    string $staffRoute = ''
): string {
    $basePath = rtrim($basePath, '/');
    if ($userType === 'Staff') return $staffRoute;
    $panel = $userType === 'Manager' ? 'manager' : 'admin';
    $branchQuery = $userType === 'Admin' && $branchId > 0 ? '&branch_id=' . $branchId : '';
    if ($isCustom && $jobOrderId > 0) {
        return $basePath . '/' . $panel . '/customizations.php?open_job=' . $jobOrderId . $branchQuery;
    }
    $page = $userType === 'Admin' ? 'orders_management.php' : 'orders.php';
    return $basePath . '/' . $panel . '/' . $page . '?open_order=' . $orderId . $branchQuery;
}
