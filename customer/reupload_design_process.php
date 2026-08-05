<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/revision_workflow.php';

require_role('Customer');
header('Content-Type: application/json; charset=utf-8');

function revision_upload_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    revision_upload_json(['success' => false, 'error' => 'Invalid request method'], 405);
}
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    revision_upload_json(['success' => false, 'error' => 'Invalid CSRF token'], 419);
}

$orderId = (int)($_POST['order_id'] ?? 0);
$customerId = (int)get_user_id();
if ($orderId <= 0) {
    revision_upload_json(['success' => false, 'error' => 'Invalid order ID'], 400);
}

$request = printflow_revision_get_active_or_legacy($orderId, $customerId);
if ($request === null) {
    revision_upload_json(['success' => false, 'error' => 'No active revision request was found.'], 409);
}
$permissions = $request['permitted_fields_array'];
if ($permissions !== ['uploaded_design']) {
    revision_upload_json([
        'success' => false,
        'error' => 'This revision includes additional fields. Use the Revise Order page.',
        'revise_url' => 'edit_order.php?order_id=' . $orderId,
    ], 409);
}

try {
    $result = printflow_revision_submit($orderId, $customerId, $_POST, $_FILES);
    log_activity($customerId, 'Order Resubmitted', "Customer resubmitted a revised design for Order #{$orderId}.");
    $staffId = (int)($result['requesting_staff_id'] ?? 0);
    if ($staffId > 0) {
        $staffRows = db_query('SELECT role FROM users WHERE user_id = ? LIMIT 1', 'i', [$staffId]) ?: [];
        create_notification(
            $staffId,
            (string)($staffRows[0]['role'] ?? 'Staff'),
            "Customer submitted a revised design for Order #{$orderId}. Review is required.",
            'Order',
            false,
            false,
            $orderId
        );
    }
    revision_upload_json(['success' => true, 'message' => 'Revised design submitted for review.']);
} catch (Throwable $e) {
    revision_upload_json(['success' => false, 'error' => $e->getMessage()], 400);
}
