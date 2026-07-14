<?php
/**
 * Additive payment-verification reliability migration.
 *
 * Run from the project root:
 *   php database/migrate_payment_verification_reliability_20260714.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payment_verification.php';

if (!payment_verification_ensure_schema()) {
    fwrite(STDERR, "Payment verification schema upgrade failed. Check the PHP/database error log.\n");
    exit(1);
}

$backfilled = db_execute_affected_rows(
    "UPDATE payment_submissions ps
     LEFT JOIN orders o ON o.order_id = ps.order_id
     LEFT JOIN job_orders jo ON jo.id = ps.job_order_id
     SET ps.branch_id = COALESCE(NULLIF(ps.branch_id, 0), NULLIF(o.branch_id, 0), NULLIF(jo.branch_id, 0)),
         ps.receipt_storage_path = COALESCE(NULLIF(ps.receipt_storage_path, ''), TRIM(LEADING '/' FROM ps.receipt_file))
     WHERE ps.branch_id IS NULL OR ps.branch_id = 0
        OR ps.receipt_storage_path IS NULL OR ps.receipt_storage_path = ''"
);

if ($backfilled < 0) {
    fwrite(STDERR, "Schema upgraded, but the legacy branch/path backfill failed. Check the database error log.\n");
    exit(1);
}

$urlRows = db_query(
    "SELECT id, receipt_file FROM payment_submissions
     WHERE receipt_url IS NULL OR receipt_url = ''"
) ?: [];
$urlBackfilled = 0;
foreach ($urlRows as $row) {
    $url = payment_verification_proof_url((string)($row['receipt_file'] ?? ''));
    if ($url === '') continue;
    if (db_execute('UPDATE payment_submissions SET receipt_url = ? WHERE id = ?', 'si', [$url, (int)$row['id']])) {
        $urlBackfilled++;
    }
}

echo 'Payment verification migration complete. Branch/path rows: ' . $backfilled
    . '; receipt URLs: ' . $urlBackfilled . PHP_EOL;
