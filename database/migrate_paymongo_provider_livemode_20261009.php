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
$hasLivemode = (bool)$columnQuery->fetchColumn();
if (!$hasLivemode) {
    $pdo->exec(
        "ALTER TABLE `provider_payments`
         ADD COLUMN `provider_livemode` TINYINT(1) NULL DEFAULT NULL
             COMMENT 'PayMongo livemode flag from provider API (0=test, 1=live)' AFTER `mode`"
    );
}

$columnQuery->execute(['provider_payments', 'provider_livemode_verified_at']);
if (!$columnQuery->fetchColumn()) {
    $pdo->exec(
        "ALTER TABLE `provider_payments`
         ADD COLUMN `provider_livemode_verified_at` DATETIME NULL DEFAULT NULL
             COMMENT 'Timestamp of a successful PayMongo API mode verification'
             AFTER `provider_livemode`"
    );
}

$columnQuery->execute(['provider_payments', 'provider_test_url']);
if (!$columnQuery->fetchColumn()) {
    $pdo->exec(
        "ALTER TABLE `provider_payments`
         ADD COLUMN `provider_test_url` VARCHAR(2048) NULL DEFAULT NULL
             COMMENT 'PayMongo Test Mode QRPh simulator URL' AFTER `provider_livemode_verified_at`"
    );
}

fwrite(STDOUT, "PayMongo provider livemode verification and Test simulator columns are ready; historical rows remain unverified until checked against PayMongo.\n");
