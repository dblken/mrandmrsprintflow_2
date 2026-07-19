<?php

$notificationInserts = [];
$failNotificationInsert = false;

function db_execute($sql, $types = '', $params = []) {
    global $notificationInserts, $failNotificationInsert;
    if (stripos((string)$sql, 'INSERT INTO notifications') !== false) {
        if ($failNotificationInsert) return false;
        $notificationInserts[] = ['sql' => $sql, 'types' => $types, 'params' => $params];
        return 500 + count($notificationInserts);
    }
    return true;
}

function db_query($sql, $types = '', $params = []): array {
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
    if (stripos($sql, "FROM users") !== false && stripos($sql, "user_type = 'Staff'") !== false) {
        return [
            ['user_id' => 21, 'user_type' => 'Staff', 'position' => '', 'branch_id' => 3],
            ['user_id' => 22, 'user_type' => 'Staff', 'position' => '', 'branch_id' => 3],
        ];
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
    if (($insert['types'] ?? '') !== 'isi') $failures[] = 'Notification bind types changed unexpectedly.';
    if (($insert['params'][2] ?? 0) !== 91) $failures[] = 'Notification data_id must be the payment submission ID.';
    if (stripos((string)$insert['sql'], "'Payment'") === false) $failures[] = 'Notification type must be Payment.';
    if (stripos((string)$insert['sql'], '0, 0, 0') === false) $failures[] = 'Notification must be unread with external delivery disabled.';
}

$failNotificationInsert = true;
$failed = payment_verification_notify_reviewers(11233, 0, 92, true);
if (!empty($failed['success'])) $failures[] = 'Strict mode must fail when a notification insert fails.';

$endpoint = file_get_contents(__DIR__ . '/../customer/api_submit_payment.php');
$jobPreparation = strpos($endpoint, 'JobOrderService::ensureJobsForStoreOrder');
$begin = strpos($endpoint, '$conn->begin_transaction()');
$notify = strpos($endpoint, 'payment_verification_notify_reviewers(', $begin ?: 0);
$commit = strpos($endpoint, '$conn->commit()', $begin ?: 0);
if ($jobPreparation === false || $begin === false || $jobPreparation > $begin) {
    $failures[] = 'Job preparation must run before the payment transaction.';
}
if ($notify === false || $commit === false || $notify > $commit) {
    $failures[] = 'Reviewer notifications must be written before the payment commit.';
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "Payment notification persistence test passed.\n";
