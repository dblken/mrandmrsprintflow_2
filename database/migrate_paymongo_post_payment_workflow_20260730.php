<?php
/**
 * Add PayMongo reconciliation fields and idempotent workflow transition history.
 *
 * Run from the project root:
 *   php database/migrate_paymongo_post_payment_workflow_20260730.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

$changes = 0;
$columns = [
    'payment_status' => "ALTER TABLE provider_payments
        ADD COLUMN payment_status VARCHAR(30) NOT NULL DEFAULT 'awaiting_payment' AFTER status",
    'provider_status' => "ALTER TABLE provider_payments
        ADD COLUMN provider_status VARCHAR(30) NOT NULL DEFAULT 'awaiting_payment' AFTER payment_status",
    'payment_method' => "ALTER TABLE provider_payments
        ADD COLUMN payment_method VARCHAR(30) DEFAULT NULL AFTER provider_payment_id",
    'last_reconciled_at' => "ALTER TABLE provider_payments
        ADD COLUMN last_reconciled_at DATETIME DEFAULT NULL AFTER paid_at",
];

foreach ($columns as $column => $sql) {
    if (db_table_has_column('provider_payments', $column)) {
        continue;
    }
    if (!db_execute($sql)) {
        fwrite(STDERR, "Migration failed. Review the server database log.\n");
        exit(1);
    }
    $changes++;
}

if (!db_execute(
    "UPDATE provider_payments
     SET payment_status = status,
         provider_status = CASE WHEN status = 'paid' THEN 'paid' ELSE status END"
)) {
    fwrite(STDERR, "Migration failed. Review the server database log.\n");
    exit(1);
}

$historyExists = !empty(db_query("SHOW TABLES LIKE 'provider_payment_status_history'"));
if (!$historyExists) {
    if (!db_execute(
        "CREATE TABLE provider_payment_status_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider_payment_id BIGINT UNSIGNED NOT NULL,
            order_id INT DEFAULT NULL,
            event_key VARCHAR(50) NOT NULL,
            old_status VARCHAR(50) NOT NULL,
            new_status VARCHAR(50) NOT NULL,
            actor_type VARCHAR(30) NOT NULL,
            actor_id INT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_provider_payment_event (provider_payment_id, event_key),
            KEY idx_provider_payment_history_order (order_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    )) {
        fwrite(STDERR, "Migration failed. Review the server database log.\n");
        exit(1);
    }
    $changes++;
}

$statusColumns = db_query("SHOW COLUMNS FROM orders LIKE 'status'") ?: [];
$statusColumn = $statusColumns[0] ?? [];
$statusType = (string)($statusColumn['Type'] ?? '');
if (stripos($statusType, 'enum(') === 0
    && stripos($statusType, "'Payment Confirmed'") === false) {
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $statusType, $matches);
    $statusValues = array_map(
        static fn(string $value): string => str_replace("\\'", "'", $value),
        $matches[1] ?? []
    );
    $statusValues[] = 'Payment Confirmed';
    $escapedValues = array_map(
        static fn(string $value): string => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'",
        array_values(array_unique($statusValues))
    );
    $nullSql = strtoupper((string)($statusColumn['Null'] ?? 'YES')) === 'NO' ? ' NOT NULL' : ' NULL';
    $default = $statusColumn['Default'] ?? null;
    $defaultSql = $default === null
        ? ($nullSql === ' NULL' ? ' DEFAULT NULL' : '')
        : " DEFAULT '" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$default) . "'";
    if (!db_execute(
        'ALTER TABLE orders MODIFY COLUMN status ENUM('
        . implode(',', $escapedValues)
        . ')' . $nullSql . $defaultSql
    )) {
        fwrite(STDERR, "Migration failed. Review the server database log.\n");
        exit(1);
    }
    $changes++;
}

fwrite(
    STDOUT,
    $changes > 0
        ? "Migration completed successfully\n"
        : "Migration already applied\n"
);
