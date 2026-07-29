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

require_once __DIR__ . '/../includes/functions.php';

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

fwrite(STDOUT, "PayMongo provider payment migration completed.\n");
