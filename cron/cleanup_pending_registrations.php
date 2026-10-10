<?php
/**
 * Remove abandoned local customer registrations after 24 hours.
 *
 * Run from the project root with a scheduler, for example:
 *   php cron/cleanup_pending_registrations.php
 *
 * A daily run is sufficient. This script is CLI-only and does not create
 * schema or touch verified, active, Google, or order-linked accounts.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$deletedCustomers = db_execute_affected_rows(
    "DELETE c
     FROM customers c
     WHERE COALESCE(c.email_verified, 0) = 0
       AND LOWER(TRIM(COALESCE(c.auth_provider, ''))) IN ('', 'local', 'password')
       AND c.created_at IS NOT NULL
       AND c.created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
       AND NOT EXISTS (
           SELECT 1
           FROM orders o
           WHERE o.customer_id = c.customer_id
       )
       AND NOT EXISTS (
           SELECT 1
           FROM job_orders jo
           WHERE jo.customer_id = c.customer_id
       )"
);

$clearedCustomerOtps = db_execute_affected_rows(
    "UPDATE customers
     SET otp_code = NULL, otp_expiry = NULL, otp_last_sent = NULL
     WHERE COALESCE(email_verified, 0) = 0
       AND LOWER(TRIM(COALESCE(auth_provider, ''))) IN ('', 'local', 'password')
       AND otp_expiry IS NOT NULL
       AND otp_expiry <= NOW()"
);

$deletedVerificationCodes = 0;
if (!empty(db_query("SHOW TABLES LIKE 'verification_codes'"))) {
    $deletedVerificationCodes = db_execute_affected_rows(
        "DELETE FROM verification_codes
         WHERE (expires_at IS NOT NULL AND expires_at <= NOW())
            OR (is_used = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR))"
    );
}

printf(
    "[pending-registration-cleanup] time=%s deleted_customers=%d cleared_customer_otps=%d deleted_verification_codes=%d\n",
    date('Y-m-d H:i:s'),
    (int)$deletedCustomers,
    (int)$clearedCustomerOtps,
    (int)$deletedVerificationCodes
);