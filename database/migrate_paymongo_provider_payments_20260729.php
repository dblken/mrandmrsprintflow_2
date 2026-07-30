<?php
/**
 * Add the shared provider payment ledger used by online and POS payments.
 *
 * Run from the project root:
 *   php database/migrate_paymongo_provider_payments_20260729.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Use the same connection bootstrap as the live application without loading
// unrelated application helpers that may perform their own schema checks.
require_once __DIR__ . '/../includes/db.php';

$requiredTables = ['provider_payments', 'provider_webhook_events'];
$tablesReady = true;
foreach ($requiredTables as $table) {
    $escapedTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$escapedTable}'");
    if (!$result || $result->num_rows === 0) {
        $tablesReady = false;
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }
}

$paymentStatusReady = false;
$columnResult = $conn->query("SHOW COLUMNS FROM `orders` LIKE 'payment_status'");
if ($columnResult instanceof mysqli_result) {
    $column = $columnResult->fetch_assoc();
    $paymentStatusReady = is_array($column)
        && strtolower((string)($column['Type'] ?? '')) === 'varchar(40)'
        && (string)($column['Default'] ?? '') === 'Unpaid'
        && strtoupper((string)($column['Null'] ?? '')) === 'NO';
    $columnResult->free();
}

if ($tablesReady && $paymentStatusReady) {
    fwrite(STDOUT, "Migration already applied\n");
    exit(0);
}

$sql = file_get_contents(__DIR__ . '/paymongo_provider_payments_20260729.sql');
if (!is_string($sql) || trim($sql) === '') {
    fwrite(STDERR, "Migration SQL could not be read.\n");
    exit(1);
}

$statements = array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql)));
foreach ($statements as $statement) {
    if (!db_execute($statement)) {
        fwrite(STDERR, "Migration failed. Review the server database log.\n");
        exit(1);
    }
}

fwrite(STDOUT, "Migration completed successfully\n");
