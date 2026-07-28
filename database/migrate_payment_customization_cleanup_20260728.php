<?php
/**
 * Additive migration for payment verification and production requirements.
 *
 * Run from the project root:
 *   php database/migrate_payment_customization_cleanup_20260728.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payment_verification.php';

if (!db_table_has_column('services', 'requires_ink', true)) {
    $ok = db_execute(
        "ALTER TABLE services
         ADD COLUMN requires_ink TINYINT(1) NOT NULL DEFAULT 1
         COMMENT '1 when production requires ink; 0 for non-ink services'"
    );
    if (!$ok || !db_table_has_column('services', 'requires_ink', true)) {
        fwrite(STDERR, "Could not add services.requires_ink. Check database ALTER permissions and the PHP error log.\n");
        exit(1);
    }
}

$orderProofParts = [];
if (db_table_has_column('orders', 'payment_proof')) $orderProofParts[] = "NULLIF(TRIM(payment_proof), '')";
if (db_table_has_column('orders', 'payment_proof_path')) $orderProofParts[] = "NULLIF(TRIM(payment_proof_path), '')";
$ordersUpdated = 0;
if ($orderProofParts) {
    $orderProofExpression = count($orderProofParts) === 1
        ? $orderProofParts[0]
        : 'COALESCE(' . implode(', ', $orderProofParts) . ')';
    $ordersUpdated = db_execute_affected_rows(
        "UPDATE orders
         SET payment_status = 'Payment Proof Submitted'
         WHERE {$orderProofExpression} IS NOT NULL
           AND LOWER(TRIM(COALESCE(payment_status, ''))) IN ('', 'unpaid')
           AND LOWER(TRIM(COALESCE(status, ''))) IN ('to verify', 'pending verification', 'verify_pay')"
    );
}

$jobsUpdated = db_execute_affected_rows(
    "UPDATE job_orders
     SET payment_status = 'UNDER VERIFICATION'
     WHERE UPPER(TRIM(COALESCE(payment_proof_status, ''))) = 'SUBMITTED'
       AND UPPER(TRIM(COALESCE(payment_status, ''))) IN ('', 'UNPAID')"
);

$ocrRows = db_query(
    "SELECT id
     FROM payment_submissions
     WHERE NULLIF(TRIM(raw_ocr_text), '') IS NOT NULL
       AND UPPER(COALESCE(NULLIF(reference_number, ''), ocr_reference_number, ''))
           REGEXP '(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC|20[0-9]{2}|AM|PM)'
     ORDER BY id ASC"
) ?: [];
$ocrRepaired = 0;
foreach ($ocrRows as $row) {
    if (payment_verification_repair_contaminated_reference((int)$row['id'])) $ocrRepaired++;
}

$duplicateRowsRecalculated = payment_verification_repair_duplicate_states(500, true);

echo "Migration complete.\n";
echo "Orders moved to Payment Proof Submitted: {$ordersUpdated}\n";
echo "Jobs moved to UNDER VERIFICATION: {$jobsUpdated}\n";
echo "OCR references reparsed: {$ocrRepaired}\n";
echo "Duplicate states recalculated: {$duplicateRowsRecalculated}\n";
echo "Existing services default to requires_ink=1; configure genuine non-ink services with requires_ink=0.\n";
