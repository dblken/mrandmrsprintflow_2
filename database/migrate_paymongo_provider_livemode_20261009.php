<?php
declare(strict_types=1);

/**
 * Store PayMongo livemode on provider payment ledger rows.
 *
 * Run from the project root:
 *   php database/migrate_paymongo_provider_livemode_20261009.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';

if (!$pdo instanceof PDO) {
    fwrite(STDERR, "PDO is required to run this migration.\n");
    exit(1);
}

$columnQuery = $pdo->prepare(
    'SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
);
$columnQuery->execute(['provider_payments', 'provider_livemode']);
if ($columnQuery->fetchColumn()) {
    fwrite(STDOUT, "Migration already applied\n");
    exit(0);
}

$pdo->exec(
    "ALTER TABLE `provider_payments`
     ADD COLUMN `provider_livemode` TINYINT(1) NULL DEFAULT NULL
         COMMENT 'PayMongo livemode flag from provider API (0=test, 1=live)' AFTER `mode`"
);

$pdo->exec(
    "UPDATE `provider_payments`
     SET `provider_livemode` = CASE WHEN `mode` = 'live' THEN 1 WHEN `mode` = 'test' THEN 0 ELSE NULL END
     WHERE `provider_livemode` IS NULL AND `mode` IN ('test', 'live')"
);

fwrite(STDOUT, "Migration completed successfully\n");
