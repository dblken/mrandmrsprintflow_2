<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/order_archive.php';
require_once __DIR__ . '/../includes/temporary_order_visibility.php';

require_role('Admin');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!printflow_temporary_hidden_orders_table_ready()) {
    error_log('[temporary pending order hide] visibility table is not installed');
    http_response_code(503);
    exit('Temporary order visibility is unavailable.');
}

global $conn;
$started = false;
try {
    if (!$conn || !$conn->begin_transaction()) {
        throw new RuntimeException('Unable to start visibility transaction.');
    }
    $started = true;

    $eligibility = printflow_temporary_unpaid_hide_eligible_sql('o');
    $candidateRows = db_query(
        "SELECT o.order_id FROM orders o WHERE {$eligibility} ORDER BY o.order_id FOR UPDATE"
    ) ?: [];
    $ids = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int)($row['order_id'] ?? 0),
        $candidateRows
    ), static fn(int $id): bool => $id > 0)));

    $inserted = 0;
    foreach ($ids as $id) {
        // Recheck the exact order while its parent row remains locked.
        $stillEligible = db_query(
            "SELECT o.order_id FROM orders o WHERE o.order_id = ? AND {$eligibility} LIMIT 1",
            'i',
            [$id]
        ) ?: [];
        if ($stillEligible === []) continue;
        $insertResult = db_execute_affected_rows(
            'INSERT IGNORE INTO printflow_pending_unpaid_hidden_orders (order_id) VALUES (?)',
            'i',
            [$id]
        );
        if ($insertResult < 0) {
            throw new RuntimeException('Failed to record temporary order visibility.');
        }
        $inserted += $insertResult;
    }

    if (!$conn->commit()) throw new RuntimeException('Failed to commit temporary order visibility.');
    $started = false;
    header('Location: ../admin/job_orders.php?hidden_pending_unpaid=' . $inserted, true, 303);
    exit;
} catch (Throwable $e) {
    if ($started) $conn->rollback();
    $ref = bin2hex(random_bytes(6));
    error_log('[temporary pending order hide][' . $ref . '] ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    exit('The pending unpaid orders could not be hidden. Reference: ' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8'));
}
