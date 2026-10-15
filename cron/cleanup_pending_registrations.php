<?php
/**
 * Remove abandoned local customer registrations after 24 hours.
 *
 * Run from the project root with a scheduler, for example:
 *   php cron/cleanup_pending_registrations.php
 *
 * This script is CLI-only and does not create schema or touch verified,
 * Google, or order-linked accounts. Blank auth_provider is retained as a
 * legacy local-account value; newer registrations explicitly use 'local'.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$serviceOrderGuard = '';
if (!empty(db_query("SHOW TABLES LIKE 'service_orders'"))) {
    $serviceOrderGuard = "
       AND NOT EXISTS (
           SELECT 1
           FROM service_orders so
           WHERE so.customer_id = c.customer_id
       )";
}

$eligiblePendingAccounts = (int)(db_query(
    "SELECT COUNT(*) AS total
     FROM customers c
     WHERE COALESCE(c.email_verified, 0) = 0
       AND LOWER(TRIM(COALESCE(c.auth_provider, ''))) IN ('', 'local', 'password')
       AND c.created_at IS NOT NULL
       AND c.created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
       AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = c.customer_id)
       AND NOT EXISTS (SELECT 1 FROM job_orders jo WHERE jo.customer_id = c.customer_id)" . $serviceOrderGuard
)[0]['total'] ?? 0);

$protectedLinkedAccounts = (int)(db_query(
    "SELECT COUNT(*) AS total
     FROM customers c
     WHERE COALESCE(c.email_verified, 0) = 0
       AND LOWER(TRIM(COALESCE(c.auth_provider, ''))) IN ('', 'local', 'password')
       AND c.created_at IS NOT NULL
       AND c.created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
       AND (
           EXISTS (SELECT 1 FROM orders o WHERE o.customer_id = c.customer_id)
           OR EXISTS (SELECT 1 FROM job_orders jo WHERE jo.customer_id = c.customer_id)
           " . ($serviceOrderGuard !== '' ? "OR EXISTS (SELECT 1 FROM service_orders so WHERE so.customer_id = c.customer_id)" : '') . "
       )"
)[0]['total'] ?? 0);

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
       )" . $serviceOrderGuard
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
    "[pending-registration-cleanup] time=%s eligible_pending_accounts=%d protected_linked_accounts=%d deleted_customers=%d cleared_customer_otps=%d deleted_verification_codes=%d errors=%d\n",
    date('Y-m-d H:i:s'),
    $eligiblePendingAccounts,
    $protectedLinkedAccounts,
    (int)$deletedCustomers,
    (int)$clearedCustomerOtps,
    (int)$deletedVerificationCodes,
    count(function_exists('printflow_db_errors') ? printflow_db_errors() : [])
);
