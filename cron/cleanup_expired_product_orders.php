<?php
/**
 * Permanently remove eligible unpaid online product orders after 24 hours.
 *
 * Schedule from the project root's Hostinger hPanel Cron Jobs, for example
 * every minute. This file is CLI-only; staff/orders.php also runs a
 * branch-scoped fallback before rendering its list and counters.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/expired_product_order_archive.php';

$databaseRows = db_query('SELECT DATABASE() AS database_name');
if (!is_array($databaseRows) || trim((string)($databaseRows[0]['database_name'] ?? '')) === '') {
    fwrite(STDERR, "[product-order-retention] failed=database_unavailable\n");
    exit(1);
}
$lockName = 'pf_order_retention_' . substr(hash('sha256', (string)$databaseRows[0]['database_name']), 0, 32);
$lockRows = db_query('SELECT GET_LOCK(?, 0) AS acquired', 's', [$lockName]);
if (!is_array($lockRows)) {
    fwrite(STDERR, "[product-order-retention] failed=lock_query\n");
    exit(1);
}
if ((int)($lockRows[0]['acquired'] ?? 0) !== 1) {
    fwrite(STDOUT, "[product-order-retention] skipped=lock_busy\n");
    exit(0);
}

$result = null;
try {
    $result = printflow_expired_product_order_cleanup(null, 500);
    $clock = db_query('SELECT NOW() AS database_now, @@session.time_zone AS database_timezone');
    printf(
        "[product-order-retention] database_now=%s database_timezone=%s eligible=%d deleted=%d skipped=%d failed=%d\n",
        (string)($clock[0]['database_now'] ?? 'unknown'),
        (string)($clock[0]['database_timezone'] ?? 'unknown'),
        (int)($result['eligible_count'] ?? 0),
        (int)($result['deleted_count'] ?? 0),
        (int)($result['skipped_count'] ?? 0),
        (int)($result['failed_count'] ?? 0)
    );
} catch (Throwable $e) {
    error_log('[product-order-retention] scheduled cleanup failed: ' . $e->getMessage());
    fwrite(STDERR, "[product-order-retention] failed\n");
    $result = ['ok' => false];
} finally {
    db_query('SELECT RELEASE_LOCK(?) AS released', 's', [$lockName]);
}

if (empty($result['ok'])) {
    exit(1);
}
