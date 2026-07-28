<?php

$notificationInserts = [];
$failNotificationInsert = false;
$reviewerMode = 'staff';

function db_execute($sql, $types = '', $params = []) {
    return true;
}

function create_notification($userId, $userType, $message, $type = 'System', $sendEmail = false, $sendSms = false, $dataId = null) {
    global $notificationInserts, $failNotificationInsert;
    if ($failNotificationInsert) return false;
    $notificationInserts[] = compact('userId', 'userType', 'message', 'type', 'sendEmail', 'sendSms', 'dataId');
    return 500 + count($notificationInserts);
}

function printflow_format_order_code($orderId, $sku = ''): string {
    return trim((string)$sku) !== '' ? $sku : 'ORD-' . (int)$orderId;
}

function db_query($sql, $types = '', $params = []): array {
    global $reviewerMode;
    $sql = (string)$sql;
    if (stripos($sql, "SHOW TABLES LIKE 'notifications'") !== false) return [['table' => 'notifications']];
    if (stripos($sql, "SHOW TABLE STATUS LIKE 'notifications'") !== false) return [['Engine' => 'InnoDB']];
    if (stripos($sql, "SHOW COLUMNS FROM notifications LIKE 'type'") !== false) {
        return [['Field' => 'type', 'Type' => "enum('System','Payment')"]];
    }
    if (stripos($sql, 'SHOW COLUMNS FROM notifications LIKE') !== false) return [['Field' => 'present']];
    if (stripos($sql, "SHOW TABLES LIKE 'payment_submissions'") !== false) return [['table' => 'payment_submissions']];
    if (stripos($sql, 'SHOW INDEX FROM payment_submissions') !== false) {
        return [
            ['Key_name' => 'idx_payment_submissions_branch'],
            ['Key_name' => 'uq_payment_submissions_token'],
        ];
    }
    if (stripos($sql, 'FROM payment_submissions ps') !== false) {
        return [[
            'id' => 91,
            'order_id' => 11233,
            'job_order_id' => null,
            'customer_id' => 8,
            'branch_id' => 3,
            'order_branch_id' => 3,
            'job_branch_id' => null,
            'customer_name' => 'Jenny Ferr',
            'order_sku' => 'TSH-0003-11233',
        ]];
    }
    if (stripos($sql, "FROM users") !== false && stripos($sql, "role = 'Staff'") !== false) {
        if ($reviewerMode !== 'staff') return [];
        return [
            ['user_id' => 21, 'user_type' => 'Staff', 'position' => '', 'branch_id' => 3],
            ['user_id' => 22, 'user_type' => 'Staff', 'position' => '', 'branch_id' => 3],
        ];
    }
    if (stripos($sql, "FROM users") !== false && stripos($sql, "role = 'Admin'") !== false) {
        return $reviewerMode === 'admin'
            ? [['user_id' => 31, 'user_type' => 'Admin']]
            : [];
    }
    if (stripos($sql, 'FROM notifications') !== false) return [];
    return [];
}

function db_table_has_column(string $table, string $column, bool $refresh = false): bool {
    return true;
}

require_once __DIR__ . '/../includes/payment_verification.php';

$failures = [];
$result = payment_verification_notify_reviewers(11233, 0, 91, true);
if (empty($result['success'])) $failures[] = 'Expected strict reviewer notifications to succeed.';
if (($result['recipient_count'] ?? 0) !== 2) $failures[] = 'Expected both branch reviewers to be selected.';
if (count($notificationInserts) !== 2) $failures[] = 'Expected one in-system notification per reviewer.';
foreach ($notificationInserts as $insert) {
    if (($insert['userType'] ?? '') !== 'Staff') $failures[] = 'Branch reviewers must use the Staff recipient role.';
    if (($insert['dataId'] ?? 0) !== 91) $failures[] = 'Notification data_id must be the payment submission ID.';
    if (($insert['type'] ?? '') !== 'Payment') $failures[] = 'Notification type must be Payment.';
    if (!empty($insert['sendEmail']) || !empty($insert['sendSms'])) $failures[] = 'External delivery must remain disabled.';
    if (($insert['message'] ?? '') !== 'Jenny Ferr submitted a payment proof for order TSH-0003-11233.') {
        $failures[] = 'Notification content must identify the customer and formatted order code.';
    }
}

$reviewerMode = 'admin';
$adminFallback = payment_verification_notify_reviewers(11233, 0, 93, true);
if (empty($adminFallback['success']) || ($adminFallback['recipient_count'] ?? 0) !== 1) {
    $failures[] = 'Expected an active admin fallback when a branch has no online reviewer.';
}

$reviewerMode = 'staff';
$failNotificationInsert = true;
$failed = payment_verification_notify_reviewers(11233, 0, 92, true);
if (!empty($failed['success'])) $failures[] = 'Notification helper must report a failed shared-helper insert.';

$endpoint = file_get_contents(__DIR__ . '/../customer/api_submit_payment.php');
$jobPreparation = strpos($endpoint, 'JobOrderService::ensureJobsForStoreOrder');
$begin = strpos($endpoint, '$conn->begin_transaction()');
$notify = strpos($endpoint, '$notify_reviewers_best_effort(', $begin ?: 0);
$commit = strpos($endpoint, '$conn->commit()', $begin ?: 0);
if ($jobPreparation === false || $begin === false || $jobPreparation > $begin) {
    $failures[] = 'Job preparation must run before the payment transaction.';
}
if ($notify === false || $commit === false || $notify < $commit) {
    $failures[] = 'Reviewer notifications must run only after the payment commit.';
}
if (strpos($endpoint, 'if (!payment_verification_ensure_notification_schema())') !== false) {
    $failures[] = 'Notification schema availability must not gate the core payment submission.';
}
if (strpos($endpoint, "'notification_created'") === false || strpos($endpoint, '[PAYMENT NOTIFICATION ERROR]') === false) {
    $failures[] = 'The success response and post-commit notification failure log are required.';
}
if (strpos($endpoint, "'transaction_state' => \$transactionState") === false) {
    $failures[] = 'Notification diagnostics must record the committed transaction state.';
}
if (strpos($endpoint, 'The verification record could not be sent to staff. No payment submission was saved.') !== false) {
    $failures[] = 'A notification failure must never claim that a committed payment was not saved.';
}
$helperSource = file_get_contents(__DIR__ . '/../includes/payment_verification.php');
if (strpos($helperSource, "WHERE role = 'Staff'") === false || strpos($helperSource, 'create_notification(') === false) {
    $failures[] = 'Payment notifications must use the established users.role schema and shared helper.';
}
if (strpos($endpoint, "error_reference") === false || strpos($endpoint, '[PAYMENT SUBMISSION ERROR]') === false) {
    $failures[] = 'Backend failures must return a correlation ID and write detailed server logs.';
}
$tryPosition = strpos($endpoint, 'try {');
$authRequirePosition = strpos($endpoint, "require_once __DIR__ . '/../includes/auth.php'");
if ($tryPosition === false || $authRequirePosition === false || $tryPosition > $authRequirePosition) {
    $failures[] = 'The JSON exception boundary must include endpoint bootstrap files.';
}
if (strpos($endpoint, "Content-Type: application/json; charset=utf-8") === false) {
    $failures[] = 'The payment endpoint must declare UTF-8 JSON responses.';
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "Payment notification persistence test passed.\n";
