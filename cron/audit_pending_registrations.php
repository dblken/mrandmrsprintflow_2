<?php
/**
 * Read-only audit for abandoned pending customer registrations.
 *
 * Run from the project root:
 *   php cron/audit_pending_registrations.php
 *
 * This file intentionally contains SELECT/metadata queries only. It never
 * deletes customers, clears OTPs, or removes verification codes.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$hasTable = static function (string $table): bool {
    return !empty(db_query("SHOW TABLES LIKE '" . preg_replace('/[^a-z0-9_]/i', '', $table) . "'"));
};

$serviceOrdersAvailable = $hasTable('service_orders');

$rows = db_query(
    "SELECT c.customer_id, c.created_at, c.email_verified, c.auth_provider, c.status,
            TIMESTAMPDIFF(MINUTE, c.created_at, NOW()) / 60 AS age_hours,
            (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.customer_id) AS orders_count,
            (SELECT COUNT(*) FROM job_orders jo WHERE jo.customer_id = c.customer_id) AS job_orders_count,
            " . ($serviceOrdersAvailable
                ? "(SELECT COUNT(*) FROM service_orders so2 WHERE so2.customer_id = c.customer_id)"
                : '0') . " AS service_orders_count,
            CASE
                WHEN c.created_at IS NULL THEN 'FAIL TO MATCH: created_at is NULL'
                WHEN COALESCE(c.email_verified, 0) <> 0 THEN 'PROTECT: email verified'
                WHEN LOWER(TRIM(COALESCE(c.auth_provider, ''))) NOT IN ('', 'local', 'password') THEN 'PROTECT: non-local provider'
                WHEN c.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 'FAIL TO MATCH: younger than 24 hours'
                WHEN EXISTS (SELECT 1 FROM orders o2 WHERE o2.customer_id = c.customer_id) THEN 'PROTECT: linked orders'
                WHEN EXISTS (SELECT 1 FROM job_orders jo2 WHERE jo2.customer_id = c.customer_id) THEN 'PROTECT: linked job orders'
                " . ($serviceOrdersAvailable
                    ? "WHEN EXISTS (SELECT 1 FROM service_orders so3 WHERE so3.customer_id = c.customer_id) THEN 'PROTECT: linked service orders'"
                    : '') . "
                ELSE 'DELETE candidate under cleanup predicate'
            END AS decision
     FROM customers c
     WHERE COALESCE(c.email_verified, 0) = 0
       AND LOWER(TRIM(COALESCE(c.auth_provider, ''))) IN ('', 'local', 'password')
       AND c.created_at IS NOT NULL
       AND c.created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
     ORDER BY c.created_at ASC, c.customer_id ASC"
);

$dbTime = db_query('SELECT NOW() AS database_now, @@session.time_zone AS session_time_zone, @@global.time_zone AS global_time_zone');

echo "[pending-registration-audit] READ-ONLY\n";
echo json_encode([
    'database_clock' => $dbTime[0] ?? [],
    'service_orders_table_present' => $serviceOrdersAvailable,
    'cleanup_predicate' => [
        'email_verified' => 0,
        'auth_provider' => ['', 'local', 'password'],
        'status' => 'not used for cleanup eligibility; active/activated accounts can be deleted when otherwise eligible',
        'created_at' => 'created_at IS NOT NULL AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)',
        'protected_links' => array_merge(['orders', 'job_orders'], $serviceOrdersAvailable ? ['service_orders'] : []),
    ],
    'rows' => $rows ?: [],
    'counts' => [
        'delete_candidates' => count(array_filter($rows ?: [], static fn(array $row): bool => str_starts_with((string)($row['decision'] ?? ''), 'DELETE'))),
        'protected_or_excluded' => count(array_filter($rows ?: [], static fn(array $row): bool => !str_starts_with((string)($row['decision'] ?? ''), 'DELETE'))),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
