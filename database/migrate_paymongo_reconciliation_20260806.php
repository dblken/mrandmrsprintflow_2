<?php
declare(strict_types=1);

/**
 * Additive PayMongo test/live, reconciliation, and order-authority migration.
 *
 * Run from the project root after the 20260729 base PayMongo migration:
 *   php database/migrate_paymongo_reconciliation_20260806.php
 *
 * This migration does not remove legacy columns or rewrite authoritative
 * amounts/payment evidence. It backfills only new audit/identity columns when
 * existing workflow metadata makes the value unambiguous.
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
        '[paymongo-reconciliation-migration][' . $reference . '] class='
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

$tableExistsQuery = $pdo->prepare(
    'SELECT 1 FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
);
$columnQuery = $pdo->prepare(
    'SELECT column_type FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
);
$indexQuery = $pdo->prepare(
    'SELECT 1 FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1'
);

$tableExists = static function (string $table) use ($tableExistsQuery): bool {
    $tableExistsQuery->execute([$table]);
    return (bool)$tableExistsQuery->fetchColumn();
};
$columnType = static function (string $table, string $column) use ($columnQuery) {
    $columnQuery->execute([$table, $column]);
    $type = $columnQuery->fetchColumn();
    return is_string($type) ? strtolower($type) : false;
};
$indexExists = static function (string $table, string $index) use ($indexQuery): bool {
    $indexQuery->execute([$table, $index]);
    return (bool)$indexQuery->fetchColumn();
};
$addColumns = static function (
    string $table,
    array $definitions
) use ($pdo, $columnType, &$changes): array {
    $added = [];
    foreach ($definitions as $column => $definition) {
        if ($columnType($table, $column) !== false) {
            continue;
        }
        if (!preg_match('/^[a-z0-9_]+$/i', $table)
            || !preg_match('/^[a-z0-9_]+$/i', $column)) {
            throw new RuntimeException('Unsafe migration identifier.');
        }
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        $changes++;
        $added[] = $column;
    }
    return $added;
};
$addIndexes = static function (
    string $table,
    array $definitions
) use ($pdo, $indexExists, &$changes): void {
    foreach ($definitions as $index => $definition) {
        if ($indexExists($table, $index)) {
            continue;
        }
        if (!preg_match('/^[a-z0-9_]+$/i', $table)) {
            throw new RuntimeException('Unsafe migration identifier.');
        }
        $pdo->exec("ALTER TABLE `{$table}` ADD {$definition}");
        $changes++;
    }
};
$widenMode = static function (string $table) use ($pdo, $columnType, &$changes): void {
    $type = $columnType($table, 'mode');
    if ($type === false) {
        throw new RuntimeException("{$table}.mode is missing.");
    }
    if (in_array($type, ["enum('test','live')", "enum('live','test')"], true)) {
        return;
    }
    $invalid = (int)$pdo->query(
        "SELECT COUNT(*) FROM `{$table}`
         WHERE mode IS NULL OR mode NOT IN ('test', 'live')"
    )->fetchColumn();
    if ($invalid > 0) {
        throw new RuntimeException("{$table}.mode contains unsupported values.");
    }
    $pdo->exec(
        "ALTER TABLE `{$table}`
         MODIFY COLUMN `mode` ENUM('test','live') NOT NULL DEFAULT 'test'"
    );
    $changes++;
};

foreach (
    ['provider_payments', 'provider_webhook_events', 'orders', 'order_items', 'products', 'services']
    as $requiredTable
) {
    if (!$tableExists($requiredTable)) {
        throw new RuntimeException(
            "Required table {$requiredTable} is missing. Apply the base database migrations first."
        );
    }
}

$widenMode('provider_payments');
$widenMode('provider_webhook_events');

$providerColumnsAdded = $addColumns('provider_payments', [
    'payment_status' => "varchar(30) NOT NULL DEFAULT 'awaiting_payment' AFTER `status`",
    'provider_status' => "varchar(30) NOT NULL DEFAULT 'awaiting_payment' AFTER `payment_status`",
    'idempotency_key' => 'varchar(255) DEFAULT NULL AFTER `mode`',
    'paid_amount_centavos' => 'int unsigned DEFAULT NULL AFTER `amount_centavos`',
    'reference_number' => 'varchar(100) DEFAULT NULL AFTER `link_id`',
    'payment_method' => 'varchar(30) DEFAULT NULL AFTER `provider_payment_id`',
    'reconciliation_error_code' => 'varchar(100) DEFAULT NULL AFTER `last_error_code`',
    'provider_paid_at' => 'datetime DEFAULT NULL AFTER `paid_at`',
    'last_reconciled_at' => 'datetime DEFAULT NULL AFTER `provider_paid_at`',
]);

if (in_array('payment_status', $providerColumnsAdded, true)) {
    $pdo->exec(
        "UPDATE provider_payments
         SET payment_status = CASE WHEN status = 'paid' THEN 'paid' ELSE status END"
    );
}
if (in_array('provider_status', $providerColumnsAdded, true)) {
    $pdo->exec(
        "UPDATE provider_payments
         SET provider_status = CASE WHEN status = 'paid' THEN 'paid' ELSE status END"
    );
}

$addIndexes('provider_payments', [
    'uq_provider_payment_idempotency' =>
        'UNIQUE KEY `uq_provider_payment_idempotency` (`provider`,`mode`,`idempotency_key`)',
    'idx_provider_payment_reconciliation' =>
        'KEY `idx_provider_payment_reconciliation` (`provider`,`mode`,`status`,`last_reconciled_at`)',
]);

$addColumns('provider_webhook_events', [
    'attempt_count' => 'int unsigned NOT NULL DEFAULT 0 AFTER `status`',
    'last_attempt_at' => 'datetime DEFAULT NULL AFTER `attempt_count`',
    'last_error_code' => 'varchar(100) DEFAULT NULL AFTER `last_attempt_at`',
    'payload_sha256' => 'char(64) DEFAULT NULL AFTER `last_error_code`',
    'payment_link_id' => 'varchar(100) DEFAULT NULL AFTER `payload_sha256`',
    'provider_transaction_id' => 'varchar(100) DEFAULT NULL AFTER `provider_payment_id`',
    'paid_amount_centavos' => 'int unsigned DEFAULT NULL AFTER `provider_transaction_id`',
    'currency' => 'char(3) DEFAULT NULL AFTER `paid_amount_centavos`',
    'payment_method' => 'varchar(30) DEFAULT NULL AFTER `currency`',
    'reference_number' => 'varchar(100) DEFAULT NULL AFTER `payment_method`',
    'provider_paid_at' => 'datetime DEFAULT NULL AFTER `reference_number`',
    'updated_at' =>
        'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `processed_at`',
]);
$addIndexes('provider_webhook_events', [
    'idx_provider_webhook_retry' =>
        'KEY `idx_provider_webhook_retry` (`provider`,`mode`,`status`,`last_attempt_at`)',
    'idx_provider_webhook_link' =>
        'KEY `idx_provider_webhook_link` (`payment_link_id`,`status`)',
    'idx_provider_webhook_transaction' =>
        'KEY `idx_provider_webhook_transaction` (`provider_transaction_id`)',
]);

if (!$tableExists('provider_payment_status_history')) {
    $pdo->exec(
        "CREATE TABLE `provider_payment_status_history` (
          `id` bigint unsigned NOT NULL AUTO_INCREMENT,
          `provider_payment_id` bigint unsigned NOT NULL,
          `order_id` int DEFAULT NULL,
          `event_key` varchar(50) NOT NULL,
          `old_status` varchar(50) NOT NULL,
          `new_status` varchar(50) NOT NULL,
          `actor_type` varchar(30) NOT NULL,
          `actor_id` int DEFAULT NULL,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_provider_payment_event` (`provider_payment_id`,`event_key`),
          KEY `idx_provider_payment_history_order` (`order_id`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $changes++;
}

// orders.total_amount remains the sole approved/final amount. These nullable
// markers make finalization explicit without creating a conflicting price.
$addColumns('orders', [
    'price_finalized_at' => 'datetime DEFAULT NULL AFTER `total_amount`',
    'price_finalized_by' => 'int DEFAULT NULL AFTER `price_finalized_at`',
]);

// New order lines can identify their catalog source explicitly. The guarded
// backfill below tags only legacy rows with verified source markers; uncertain
// rows stay NULL and continue through the legacy resolver.
$addColumns('order_items', [
    'item_type' => "enum('product','service') DEFAULT NULL AFTER `product_id`",
    'service_id' => 'int DEFAULT NULL AFTER `item_type`',
    'item_name_snapshot' => 'varchar(255) DEFAULT NULL AFTER `service_id`',
]);
$addIndexes('order_items', [
    'idx_order_items_source' =>
        'KEY `idx_order_items_source` (`item_type`,`service_id`,`product_id`)',
]);

// Backfill only facts that are unambiguous from historical workflow state.
// This preserves catalog/service separation and leaves uncertain legacy lines
// NULL for the runtime resolver instead of guessing from overlapping IDs.
$pdo->beginTransaction();
try {
    $changes += (int)$pdo->exec(
        "UPDATE orders o
         SET o.price_finalized_at = COALESCE(o.price_finalized_at, o.updated_at, o.created_at)
         WHERE o.price_finalized_at IS NULL
           AND o.total_amount > 0
           AND (
                UPPER(REPLACE(TRIM(COALESCE(o.status, '')), ' ', '_')) IN
                    ('TO_PAY','DOWNPAYMENT_SUBMITTED','TO_VERIFY','PENDING_VERIFICATION',
                     'PAYMENT_CONFIRMED','PROCESSING','IN_PRODUCTION','PRINTING',
                     'READY','READY_FOR_PICKUP','READY_FOR_DELIVERY','COMPLETED')
                OR LOWER(TRIM(COALESCE(o.payment_status, ''))) = 'paid'
                OR EXISTS (
                    SELECT 1 FROM provider_payments pp
                    WHERE pp.order_id = o.order_id
                      AND pp.status IN ('generating','awaiting_payment','paid')
                      AND pp.amount_centavos = ROUND(o.total_amount * 100)
                )
           )"
    );

    $legacyRows = $pdo->query(
        "SELECT oi.order_item_id, oi.product_id, oi.customization_data,
                o.reference_id, oi_counts.order_item_count
         FROM order_items oi
         INNER JOIN orders o ON o.order_id = oi.order_id
         INNER JOIN (
             SELECT order_id, COUNT(*) AS order_item_count
             FROM order_items
             GROUP BY order_id
         ) oi_counts ON oi_counts.order_id = oi.order_id
         WHERE oi.item_type IS NULL
         ORDER BY oi.order_item_id"
    );
    $serviceById = $pdo->prepare(
        "SELECT service_id, name FROM services
         WHERE service_id = ? AND LOWER(TRIM(COALESCE(status, ''))) <> 'archived'
         LIMIT 1"
    );
    $serviceByName = $pdo->prepare(
        "SELECT service_id, name FROM services
         WHERE LOWER(TRIM(COALESCE(name, ''))) = LOWER(TRIM(?))
           AND LOWER(TRIM(COALESCE(status, ''))) <> 'archived'
         ORDER BY service_id LIMIT 2"
    );
    $tagService = $pdo->prepare(
        "UPDATE order_items
         SET item_type = 'service', service_id = ?, item_name_snapshot = ?
         WHERE order_item_id = ? AND item_type IS NULL"
    );
    $tagProduct = $pdo->prepare(
        "UPDATE order_items
         SET item_type = 'product', service_id = NULL,
             item_name_snapshot = COALESCE(item_name_snapshot,
                 (SELECT name FROM products WHERE product_id = order_items.product_id LIMIT 1))
         WHERE order_item_id = ? AND item_type IS NULL"
    );

    while ($row = $legacyRows->fetch(PDO::FETCH_ASSOC)) {
        $custom = json_decode((string)($row['customization_data'] ?? ''), true);
        $custom = is_array($custom) ? $custom : [];
        $sourcePage = strtolower(trim((string)($custom['source_page'] ?? '')));
        $formType = strtolower(trim((string)($custom['form_type'] ?? '')));
        $catalogProduct = in_array($sourcePage, ['products', 'product', 'dynamic_form'], true)
            || $formType === 'dynamic'
            || !empty($custom['config_id']);
        if ($catalogProduct) {
            $tagProduct->execute([(int)$row['order_item_id']]);
            $changes += $tagProduct->rowCount();
            continue;
        }

        $serviceSource = in_array($sourcePage, ['service', 'services'], true)
            || strtolower(trim((string)($custom['source'] ?? ''))) === 'service';
        $explicitServiceId = (int)($custom['service_id'] ?? 0);
        $explicitServiceName = trim((string)($custom['service_type'] ?? ''));
        if (!$serviceSource && $explicitServiceId <= 0 && $explicitServiceName === '') {
            continue;
        }

        $service = false;
        if ($explicitServiceId > 0) {
            $serviceById->execute([$explicitServiceId]);
            $service = $serviceById->fetch(PDO::FETCH_ASSOC);
        }
        $serviceName = $explicitServiceName;
        if ($serviceName === '' && $serviceSource) {
            $serviceName = trim((string)($custom['product_type'] ?? ''));
        }
        if (!$service && $serviceName !== '') {
            $serviceByName->execute([$serviceName]);
            $matches = $serviceByName->fetchAll(PDO::FETCH_ASSOC);
            $service = count($matches) === 1 ? $matches[0] : false;
        }
        if (!$service && $serviceSource && $serviceName === ''
            && (int)($row['order_item_count'] ?? 0) === 1
            && (int)($row['reference_id'] ?? 0) > 0) {
            $serviceById->execute([(int)$row['reference_id']]);
            $service = $serviceById->fetch(PDO::FETCH_ASSOC);
        }
        if ($service) {
            $tagService->execute([
                (int)$service['service_id'],
                (string)$service['name'],
                (int)$row['order_item_id'],
            ]);
            $changes += $tagService->rowCount();
        }
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

fwrite(
    STDOUT,
    $changes > 0 ? "Migration completed successfully\n" : "Migration already applied\n"
);
