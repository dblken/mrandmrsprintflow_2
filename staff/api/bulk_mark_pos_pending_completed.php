<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/json_endpoint.php';
printflow_json_endpoint_bootstrap();

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/branch_context.php';
require_once __DIR__ . '/../../includes/staff_access.php';
require_once __DIR__ . '/../../includes/bulk_pos_pending_complete.php';

if (!is_logged_in()) {
    printflow_json_response(['success' => false, 'error' => 'Authentication required.'], 401);
}
if (!in_array((string)get_user_type(), ['Staff', 'Admin', 'Manager'], true)) {
    printflow_json_response(['success' => false, 'error' => 'Forbidden.'], 403);
}
printflow_require_staff_module('customizations');

$userType = (string)get_user_type();
$staffRole = printflow_get_staff_access_role();
if ($userType === 'Staff' && $staffRole === 'online') {
    printflow_json_response([
        'success' => false,
        'error' => 'Bulk completion is only available on the walk-in custom orders list.',
    ], 403);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    printflow_json_response(['success' => false, 'error' => 'Method not allowed.'], 405);
}

if (!verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
    printflow_json_response([
        'success' => false,
        'error' => 'Your session expired. Please refresh and try again.',
        'csrf_token' => generate_csrf_token(),
    ], 419);
}

$action = strtolower(trim((string)($_POST['action'] ?? 'preview')));
if (!in_array($action, ['preview', 'execute'], true)) {
    printflow_json_response(['success' => false, 'error' => 'Invalid action.'], 422);
}

$startRaw = trim((string)($_POST['start_datetime'] ?? ''));
$endRaw = trim((string)($_POST['end_datetime'] ?? ''));
$start = printflow_bulk_parse_staff_datetime($startRaw);
$end = printflow_bulk_parse_staff_datetime($endRaw);
if (!$start || !$end) {
    printflow_json_response(['success' => false, 'error' => 'Enter valid start and end date/time values.'], 422);
}
if ($end < $start) {
    printflow_json_response(['success' => false, 'error' => 'End date/time must be on or after the start.'], 422);
}

$inclusiveRange = printflow_bulk_complete_inclusive_calendar_range($start, $end);
if ($inclusiveRange === null) {
    printflow_json_response(['success' => false, 'error' => 'End date/time must be on or after the start.'], 422);
}
[$rangeStart, $rangeEnd] = $inclusiveRange;

$branchId = function_exists('printflow_branch_filter_for_user') ? printflow_branch_filter_for_user() : null;
$branchId = $branchId !== null ? (int)$branchId : null;
if ($branchId !== null && $branchId <= 0) {
    $branchId = null;
}

$range = printflow_bulk_pos_pending_orders_in_range($rangeStart, $rangeEnd, $branchId, 500);
$previewOrders = $range['orders'];
$total = (int)($range['total'] ?? 0);
$allOrderIds = $range['all_order_ids'] ?? array_column($previewOrders, 'order_id');

if ($action === 'preview') {
    printflow_json_response([
        'success' => true,
        'action' => 'preview',
        'start_datetime' => $rangeStart->format('Y-m-d H:i:s'),
        'end_datetime' => $rangeEnd->format('Y-m-d H:i:s'),
        'selected_start_datetime' => $start->format('Y-m-d H:i:s'),
        'selected_end_datetime' => $end->format('Y-m-d H:i:s'),
        'total' => $total,
        'preview_limit' => 500,
        'truncated' => $total > count($previewOrders),
        'orders' => $previewOrders,
    ]);
}

$idempotencyKey = trim((string)($_POST['idempotency_key'] ?? ''));
if ($idempotencyKey === '') {
    printflow_json_response(['success' => false, 'error' => 'Missing idempotency key. Refresh and try again.'], 422);
}

$staffUserId = (int)get_user_id();
$cached = printflow_bulk_complete_load_idempotent($idempotencyKey, $staffUserId);
if ($cached !== null) {
    printflow_json_response($cached + ['duplicate_submission' => true]);
}

if ($total === 0) {
    $emptyResult = [
        'success' => true,
        'action' => 'execute',
        'start_datetime' => $rangeStart->format('Y-m-d H:i:s'),
        'end_datetime' => $rangeEnd->format('Y-m-d H:i:s'),
        'total_matched' => 0,
        'completed_count' => 0,
        'skipped_count' => 0,
        'failed_count' => 0,
        'completed' => [],
        'skipped' => [],
        'failed' => [],
        'message' => 'No pending walk-in orders matched the selected range.',
    ];
    printflow_bulk_complete_store_idempotent($idempotencyKey, $staffUserId, 'execute', $emptyResult);
    printflow_json_response($emptyResult);
}

if ($total > 500) {
    printflow_json_response([
        'success' => false,
        'error' => 'Too many orders match this range (maximum 500). Narrow the date/time window and try again.',
        'total' => $total,
    ], 422);
}

$requestedOrderIds = printflow_bulk_parse_execute_order_ids($_POST);
if ($requestedOrderIds === []) {
    printflow_json_response([
        'success' => false,
        'error' => 'Missing preview order list. Run preview again, then confirm.',
    ], 422);
}

$executeOrderIds = printflow_bulk_filter_execute_order_ids(
    $requestedOrderIds,
    $rangeStart,
    $rangeEnd,
    $branchId
);
if ($executeOrderIds === []) {
    printflow_json_response([
        'success' => false,
        'error' => 'None of the previewed orders are still eligible. Refresh preview and try again.',
    ], 422);
}

$executeResult = printflow_bulk_execute_pos_pending_complete($executeOrderIds);
$responseSkippedPreview = count($requestedOrderIds) - count($executeOrderIds);
$response = [
    'success' => true,
    'action' => 'execute',
    'start_datetime' => $rangeStart->format('Y-m-d H:i:s'),
    'end_datetime' => $rangeEnd->format('Y-m-d H:i:s'),
    'total_matched' => count($executeOrderIds),
    'preview_total' => $total,
    'preview_stale_skipped' => max(0, $responseSkippedPreview),
    'completed_count' => (int)($executeResult['completed_count'] ?? 0),
    'skipped_count' => (int)($executeResult['skipped_count'] ?? 0) + max(0, $responseSkippedPreview),
    'failed_count' => (int)($executeResult['failed_count'] ?? 0),
    'completed' => $executeResult['completed'] ?? [],
    'skipped' => $executeResult['skipped'] ?? [],
    'failed' => $executeResult['failed'] ?? [],
    'message' => sprintf(
        'Completed %d order(s). Skipped %d. Failed %d.',
        (int)($executeResult['completed_count'] ?? 0),
        (int)($executeResult['skipped_count'] ?? 0),
        (int)($executeResult['failed_count'] ?? 0)
    ),
];

printflow_bulk_complete_store_idempotent($idempotencyKey, $staffUserId, 'execute', $response);
printflow_json_response($response);
