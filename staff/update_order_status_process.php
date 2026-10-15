<?php
/**
 * Staff order-status mutation endpoint.
 * Core status changes are synchronous and never depend on realtime delivery.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/json_endpoint.php';
printflow_json_endpoint_bootstrap();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/branch_context.php';
require_once __DIR__ . '/../includes/order_status_mutation.php';
require_once __DIR__ . '/../includes/order_completion_override.php';

if (!is_logged_in()) {
    printflow_json_response(['success' => false, 'error' => 'Authentication required.'], 401);
}
if (!in_array((string)get_user_type(), ['Staff', 'Admin', 'Manager'], true)) {
    printflow_json_response(['success' => false, 'error' => 'Forbidden.'], 403);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    printflow_json_response(['success' => false, 'error' => 'Method not allowed.'], 405);
}

$orderId = max(0, (int)($_POST['order_id'] ?? 0));
$newStatus = trim((string)($_POST['status'] ?? ''));
$expectedStatus = trim((string)($_POST['expected_status'] ?? ''));
$cancelReason = trim((string)($_POST['cancel_reason'] ?? ''));
$changeItemId = max(0, (int)($_POST['change_item_id'] ?? 0));
$completeChangeItem = filter_var($_POST['complete_change_item'] ?? false, FILTER_VALIDATE_BOOLEAN)
    || $changeItemId > 0;
$allowPendingCompletionOverride = filter_var($_POST['allow_pending_completion_override'] ?? false, FILTER_VALIDATE_BOOLEAN);
$confirmUnpaidCompletion = filter_var($_POST['confirm_unpaid_completion'] ?? false, FILTER_VALIDATE_BOOLEAN);

if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
    printflow_json_response([
        'success' => false,
        'error' => 'Your session expired. Please refresh and try again.',
        'csrf_token' => generate_csrf_token(),
    ], 419);
}
if ($orderId <= 0 || $newStatus === '' || $expectedStatus === '') {
    printflow_json_response(['success' => false, 'error' => 'Missing required fields.'], 422);
}

$allowedStatuses = [
    'Pending', 'Pending Approval', 'Approved', 'To Pay', 'To Verify',
    'Processing', 'In Production', 'Ready for Pickup', 'Completed', 'Cancelled',
];
if (!in_array($newStatus, $allowedStatuses, true)) {
    printflow_json_response(['success' => false, 'error' => 'Invalid order status.'], 422);
}
if (($allowPendingCompletionOverride || $confirmUnpaidCompletion) && $newStatus !== 'Completed') {
    printflow_json_response(['success' => false, 'error' => 'Completion override can only be used when marking an order Completed.'], 422);
}
if ($confirmUnpaidCompletion && !$allowPendingCompletionOverride) {
    printflow_json_response(['success' => false, 'error' => 'The unpaid completion confirmation is invalid. Please retry from the Customizations page.'], 422);
}

printflow_assert_order_branch_access($orderId);

$result = printflow_staff_mutate_order_status($orderId, $newStatus, $expectedStatus, [
    'cancel_reason' => $cancelReason,
    'change_item_id' => $changeItemId,
    'complete_change_item' => $completeChangeItem,
    'allow_pending_completion_override' => $allowPendingCompletionOverride,
    'confirm_unpaid_completion' => $confirmUnpaidCompletion,
    'manage_transaction' => true,
]);

$httpStatus = (int)($result['_http_status'] ?? ($result['success'] ? 200 : 500));
unset($result['_http_status']);
printflow_json_response($result, $httpStatus);
