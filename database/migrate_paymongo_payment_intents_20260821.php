<?php
declare(strict_types=1);

/**
 * Additive storage for PayMongo Payment Intent and Dynamic QRPh flows.
 *
 * Run after the base PayMongo and reconciliation migrations:
 *   php database/migrate_paymongo_payment_intents_20260821.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

set_exception_handler(static function (Throwable $exception): void {
    try {
        $reference = strtoupper(bin2hex(random_bytes(6)));
    } catch (Throwable $ignored) {
        $reference = strtoupper(substr(hash('sha256', uniqid('', true)), 0, 12));
    }
    error_log(
        '[paymongo-payment-intent-migration][' . $reference . '] class='
        . get_class($exception) . ' code=' . (string)$exception->getCode()
    );
    fwrite(STDERR, 'Migration failed. Review the server error log with reference ' . $reference . ".\n");
    exit(1);
});

require_once __DIR__ . '/../includes/db.php';

if (!$pdo instanceof PDO) {
    throw new RuntimeException('PDO is required to run this migration.');
}

$changes = 0;
$tableQuery = $pdo->prepare(
    'SELECT 1 FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
);
$columnQuery = $pdo->prepare(
    'SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
);
$indexQuery = $pdo->prepare(
    'SELECT 1 FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1'
);

$tableExists = static function (string $table) use ($tableQuery): bool {
    $tableQuery->execute([$table]);
    return (bool)$tableQuery->fetchColumn();
};
$columnExists = static function (string $table, string $column) use ($columnQuery): bool {
    $columnQuery->execute([$table, $column]);
    return (bool)$columnQuery->fetchColumn();
};
$indexExists = static function (string $table, string $index) use ($indexQuery): bool {
    $indexQuery->execute([$table, $index]);
    return (bool)$indexQuery->fetchColumn();
};
$addColumns = static function (string $table, array $definitions) use (
    $pdo,
    $columnExists,
    &$changes
): void {
    foreach ($definitions as $column => $definition) {
        if ($columnExists($table, $column)) {
            continue;
        }
        if (!preg_match('/^[a-z0-9_]+$/i', $table)
            || !preg_match('/^[a-z0-9_]+$/i', $column)) {
            throw new RuntimeException('Unsafe migration identifier.');
        }
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        $changes++;
    }
};
$addIndexes = static function (string $table, array $definitions) use (
    $pdo,
    $indexExists,
    &$changes
): void {
    foreach ($definitions as $index => $definition) {
        if ($indexExists($table, $index)) {
            continue;
        }
        if (!preg_match('/^[a-z0-9_]+$/i', $table)
            || !preg_match('/^[a-z0-9_]+$/i', $index)) {
            throw new RuntimeException('Unsafe migration identifier.');
        }
        $pdo->exec("ALTER TABLE `{$table}` ADD {$definition}");
        $changes++;
    }
};

foreach (['provider_payments', 'provider_webhook_events'] as $requiredTable) {
    if (!$tableExists($requiredTable)) {
        throw new RuntimeException(
            "Required table {$requiredTable} is missing. Apply the base PayMongo migrations first."
        );
    }
}

$addColumns('provider_payments', [
    'payment_flow' => "varchar(30) NOT NULL DEFAULT 'payment_link' AFTER `mode`",
    'payment_intent_id' => 'varchar(100) DEFAULT NULL AFTER `link_id`',
    'payment_method_id' => 'varchar(100) DEFAULT NULL AFTER `payment_intent_id`',
    'qr_image_url' => 'mediumtext DEFAULT NULL AFTER `payment_method_id`',
    'qr_expires_at' => 'datetime DEFAULT NULL AFTER `qr_image_url`',
    'client_key' => 'varchar(255) DEFAULT NULL AFTER `qr_expires_at`',
]);
$addIndexes('provider_payments', [
    'uq_provider_payment_intent' =>
        'UNIQUE KEY `uq_provider_payment_intent` (`payment_intent_id`)',
    'uq_provider_payment_method' =>
        'UNIQUE KEY `uq_provider_payment_method` (`payment_method_id`)',
    'idx_provider_payment_flow_status' =>
        'KEY `idx_provider_payment_flow_status` (`provider`,`mode`,`payment_flow`,`status`)',
]);

$addColumns('provider_webhook_events', [
    'payment_intent_id' => 'varchar(100) DEFAULT NULL',
    'payment_method_id' => 'varchar(100) DEFAULT NULL AFTER `payment_intent_id`',
]);

// The existing UNIQUE(provider, event_id) index is intentionally untouched.
fwrite(
    STDOUT,
    $changes > 0 ? "Migration completed successfully\n" : "Migration already applied\n"
);
