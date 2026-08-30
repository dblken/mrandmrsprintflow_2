<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/branch_context.php';
require_once __DIR__ . '/../../includes/order_receipt_lookup.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$requestId = '';
try {
    $requestId = bin2hex(random_bytes(6));
} catch (Throwable $ignored) {
    $requestId = substr(hash('sha256', uniqid('receipt-lookup-', true)), 0, 12);
}

$respond = static function (array $payload, int $status = 200) use ($requestId): void {
    http_response_code($status);
    $payload['request_id'] = $requestId;
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

$fail = static function (string $code, string $message, int $status, int $orderId = 0) use ($respond, $requestId): void {
    error_log(sprintf(
        '[order-receipt-lookup] request_id=%s code=%s order_id=%d http_status=%d',
        $requestId,
        preg_replace('/[^A-Z0-9_]/', '', strtoupper($code)),
        max(0, $orderId),
        $status
    ));
    $respond(['success' => false, 'code' => $code, 'message' => $message], $status);
};

if (!is_logged_in()) {
    $fail('UNAUTHENTICATED', 'Your session has expired. Please sign in and scan again.', 401);
}

$userType = (string)get_user_type();
if (!in_array($userType, ['Staff', 'Manager', 'Admin'], true)) {
    $fail('FORBIDDEN', 'You are not authorized to look up receipt orders.', 403);
}

$identifier = printflow_order_lookup_normalize_identifier($_GET['identifier'] ?? '');
$orderId = printflow_order_lookup_candidate_order_id($identifier);
if ($identifier === '' || $orderId <= 0) {
    $fail('INVALID_IDENTIFIER', 'Invalid receipt QR or order identifier. Please verify the receipt and scan again.', 422);
}

try {
    $rows = db_query(
        "SELECT o.order_id, o.branch_id, o.order_source, o.order_type, o.status,
                MIN(jo.id) AS job_order_id,
                MAX(CASE WHEN cust.customization_details LIKE '%\"source\":\"POS\"%' THEN 1 ELSE 0 END) AS legacy_pos_marker
         FROM orders o
         LEFT JOIN job_orders jo ON jo.order_id = o.order_id
         LEFT JOIN customizations cust ON cust.order_id = o.order_id
         WHERE o.order_id = ?
         GROUP BY o.order_id, o.branch_id, o.order_source, o.order_type, o.status
         LIMIT 1",
        'i',
        [$orderId]
    ) ?: [];
    if (empty($rows)) {
        $fail('ORDER_NOT_FOUND', 'Order not found. Please verify the receipt and scan again.', 404, $orderId);
    }

    $order = $rows[0];
    $orderSource = strtolower(trim((string)($order['order_source'] ?? 'customer'))) ?: 'customer';
    if (!printflow_order_lookup_is_pos_source($orderSource) && (int)($order['legacy_pos_marker'] ?? 0) === 1) {
        $orderSource = 'pos';
    }
    $canonical = (string)(printflow_get_order_inventory_reference($orderId)['code'] ?? '');
    if (!printflow_order_lookup_visible_identifier_matches($identifier, $orderId, $orderSource, strtoupper($canonical))) {
        $fail('ORDER_NOT_FOUND', 'Order not found. Please verify the receipt and scan again.', 404, $orderId);
    }

    $orderBranchId = (int)($order['branch_id'] ?? 0);
    if ($userType !== 'Admin') {
        $allowedBranches = get_user_allowed_branches((int)get_user_id(), $userType);
        if (!is_array($allowedBranches) || !in_array($orderBranchId, array_map('intval', $allowedBranches), true)) {
            $fail('BRANCH_FORBIDDEN', 'This order belongs to another branch. Ask an authorized user from that branch for assistance.', 403, $orderId);
        }
    }

    if ($userType === 'Staff' && !printflow_staff_role_can_access_order_source(printflow_get_staff_access_role(), $orderSource)) {
        $fail('STAFF_SCOPE_FORBIDDEN', 'This order belongs to a different staff operation. Please ask the appropriate counter or online staff team for assistance.', 403, $orderId);
    }

    $basePath = rtrim((string)(defined('BASE_PATH') ? BASE_PATH : '/printflow'), '/');
    $jobOrderId = (int)($order['job_order_id'] ?? 0);
    $isCustom = strtolower(trim((string)($order['order_type'] ?? ''))) === 'custom' || $jobOrderId > 0;
    $staffRoute = $userType === 'Staff' ? printflow_staff_order_management_url($orderId, false) : '';
    $route = printflow_order_lookup_management_route(
        $userType,
        $orderId,
        $orderBranchId,
        $jobOrderId,
        $isCustom,
        $basePath,
        $staffRoute
    );
    if ($route === '') {
        $fail('ROUTE_UNAVAILABLE', 'The order was found, but its destination is unavailable. Please try again.', 500, $orderId);
    }

    $status = trim((string)($order['status'] ?? ''));
    $warning = in_array(strtolower($status), ['cancelled', 'canceled', 'deleted', 'rejected'], true)
        ? 'This order is ' . ($status !== '' ? strtolower($status) : 'not active') . '. Opening its existing record for review.'
        : '';

    $respond([
        'success' => true,
        'order_id' => $orderId,
        'identifier' => printflow_order_lookup_is_pos_source($orderSource)
            ? 'POS-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT)
            : $canonical,
        'source' => printflow_order_lookup_is_pos_source($orderSource) ? 'pos' : 'online',
        'status' => $status,
        'warning' => $warning,
        'route' => $route,
    ]);
} catch (Throwable $e) {
    error_log(sprintf(
        '[order-receipt-lookup] request_id=%s code=LOOKUP_ERROR order_id=%d exception=%s',
        $requestId,
        $orderId,
        get_class($e)
    ));
    $respond(['success' => false, 'code' => 'LOOKUP_ERROR', 'message' => 'Order lookup is temporarily unavailable. Please try again.'], 500);
}
