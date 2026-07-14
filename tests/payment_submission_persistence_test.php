<?php

$capturedInsert = null;

function db_execute($sql, $types = '', $params = []) {
    global $capturedInsert;
    if (stripos((string)$sql, 'INSERT INTO payment_submissions') !== false) {
        $capturedInsert = ['sql' => $sql, 'types' => $types, 'params' => $params];
        return 77;
    }
    return true;
}

function db_query($sql, $types = '', $params = []): array {
    if (stripos((string)$sql, "SHOW TABLES LIKE 'payment_submissions'") !== false) return [['table' => 'payment_submissions']];
    if (stripos((string)$sql, 'SHOW INDEX FROM payment_submissions') !== false) {
        return [
            ['Key_name' => 'idx_payment_submissions_branch'],
            ['Key_name' => 'uq_payment_submissions_token'],
        ];
    }
    if (stripos((string)$sql, 'AS payable_amount') !== false) return [['payable_amount' => '125.50']];
    if (stripos((string)$sql, 'SELECT branch_id FROM orders') !== false) return [['branch_id' => 3]];
    return [];
}

function db_table_has_column(string $table, string $column, bool $refresh = false): bool {
    return true;
}

require_once __DIR__ . '/../includes/payment_verification.php';

$id = payment_verification_create_submission([
    'order_id' => 15,
    'customer_id' => 9,
    'receipt_file' => '/uploads/secure_payments/example.webp',
    'receipt_storage_path' => 'secure_payments/example.webp',
    'receipt_url' => '/api_view_proof.php?file=example.webp',
    'receipt_mime' => 'image/webp',
    'receipt_size' => 12345,
    'receipt_sha256' => str_repeat('a', 64),
    'selected_payment_method' => 'GCash',
    'submitted_amount' => 125.50,
    'submission_token' => str_repeat('b', 48),
]);

$failures = [];
if ($id !== 77) $failures[] = 'Expected the created submission ID.';
if (!is_array($capturedInsert)) $failures[] = 'Expected a payment_submissions INSERT.';
if (is_array($capturedInsert) && strlen($capturedInsert['types']) !== count($capturedInsert['params'])) {
    $failures[] = 'INSERT bind type count does not match parameter count.';
}
if (($capturedInsert['params'][3] ?? null) !== 3) $failures[] = 'Expected branch ID to be derived from the order.';
if (($capturedInsert['params'][5] ?? null) !== 'secure_payments/example.webp') $failures[] = 'Expected the storage path to be persisted.';
if (($capturedInsert['params'][6] ?? null) !== '/api_view_proof.php?file=example.webp') $failures[] = 'Expected the authorized receipt URL to be persisted.';
if (($capturedInsert['params'][15] ?? null) !== str_repeat('b', 48)) $failures[] = 'Expected the idempotency token to be persisted.';

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "Payment submission persistence test passed.\n";
