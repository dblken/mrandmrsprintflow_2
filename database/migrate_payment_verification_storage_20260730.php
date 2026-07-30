<?php
declare(strict_types=1);

/**
 * Additive, idempotent repair for Payment Verification/OCR storage.
 *
 * Run:
 *   php database/migrate_payment_verification_storage_20260730.php
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
        '[payment-verification-migration][' . $reference . '] class='
        . get_class($exception) . ' code=' . (string)$exception->getCode()
    );
    fwrite(STDERR, 'Migration failed. Review the server error log with reference ' . $reference . ".\n");
    exit(1);
});

require_once __DIR__ . '/../includes/db.php';

/** @var PDO $pdo */
$changes = 0;

$tableExists = (bool)$pdo->query(
    "SELECT 1 FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'payment_submissions'
     LIMIT 1"
)->fetchColumn();

if (!$tableExists) {
    $pdo->exec(
        "CREATE TABLE `payment_submissions` (
          `id` bigint unsigned NOT NULL AUTO_INCREMENT,
          `order_id` int DEFAULT NULL,
          `job_order_id` bigint DEFAULT NULL,
          `customer_id` int NOT NULL,
          `branch_id` int DEFAULT NULL,
          `receipt_file` varchar(500) NOT NULL,
          `receipt_storage_path` varchar(500) DEFAULT NULL,
          `receipt_url` varchar(700) DEFAULT NULL,
          `receipt_thumbnail` varchar(500) DEFAULT NULL,
          `receipt_original_name` varchar(255) DEFAULT NULL,
          `receipt_mime` varchar(100) DEFAULT NULL,
          `receipt_size` bigint unsigned NOT NULL DEFAULT 0,
          `receipt_sha256` char(64) DEFAULT NULL,
          `selected_payment_method` varchar(80) DEFAULT NULL,
          `submission_token` char(64) DEFAULT NULL,
          `expected_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          `submitted_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
          `ocr_sender_name` varchar(190) DEFAULT NULL,
          `sender_name` varchar(190) DEFAULT NULL,
          `ocr_sender_mobile` varchar(40) DEFAULT NULL,
          `sender_mobile` varchar(40) DEFAULT NULL,
          `ocr_reference_number` varchar(190) DEFAULT NULL,
          `reference_number` varchar(190) DEFAULT NULL,
          `reference_normalized` varchar(190) DEFAULT NULL,
          `ocr_amount_sent` decimal(12,2) DEFAULT NULL,
          `amount_sent` decimal(12,2) DEFAULT NULL,
          `ocr_total_amount_sent` decimal(12,2) DEFAULT NULL,
          `total_amount_sent` decimal(12,2) DEFAULT NULL,
          `ocr_detected_payment_method` varchar(80) DEFAULT NULL,
          `detected_payment_method` varchar(80) DEFAULT NULL,
          `ocr_transaction_date` date DEFAULT NULL,
          `transaction_date` date DEFAULT NULL,
          `ocr_transaction_time` time DEFAULT NULL,
          `transaction_time` time DEFAULT NULL,
          `ocr_transaction_status` varchar(80) DEFAULT NULL,
          `transaction_status` varchar(80) DEFAULT NULL,
          `ocr_receiver_name` varchar(190) DEFAULT NULL,
          `receiver_name` varchar(190) DEFAULT NULL,
          `ocr_receiver_account` varchar(190) DEFAULT NULL,
          `receiver_account` varchar(190) DEFAULT NULL,
          `raw_ocr_text` mediumtext,
          `ocr_normalized_text` mediumtext,
          `overall_confidence` decimal(5,2) DEFAULT NULL,
          `sender_confidence` decimal(5,2) DEFAULT NULL,
          `sender_mobile_confidence` decimal(5,2) DEFAULT NULL,
          `reference_confidence` decimal(5,2) DEFAULT NULL,
          `amount_confidence` decimal(5,2) DEFAULT NULL,
          `total_amount_confidence` decimal(5,2) DEFAULT NULL,
          `method_confidence` decimal(5,2) DEFAULT NULL,
          `date_confidence` decimal(5,2) DEFAULT NULL,
          `receiver_confidence` decimal(5,2) DEFAULT NULL,
          `status_confidence` decimal(5,2) DEFAULT NULL,
          `ocr_status` varchar(30) NOT NULL DEFAULT 'Pending',
          `ocr_provider` varchar(50) DEFAULT NULL,
          `ocr_error` varchar(500) DEFAULT NULL,
          `ocr_attempts` smallint unsigned NOT NULL DEFAULT 0,
          `ocr_duration_ms` int unsigned DEFAULT NULL,
          `ocr_processed_at` datetime DEFAULT NULL,
          `amount_match_status` varchar(20) NOT NULL DEFAULT 'Unknown',
          `method_match_status` varchar(20) NOT NULL DEFAULT 'Unknown',
          `duplicate_submission_id` bigint unsigned DEFAULT NULL,
          `verification_status` varchar(40) NOT NULL DEFAULT 'Pending Review',
          `staff_notes` text,
          `rejection_reason` text,
          `corrected_by` int DEFAULT NULL,
          `corrected_at` datetime DEFAULT NULL,
          `verified_by` int DEFAULT NULL,
          `verified_at` datetime DEFAULT NULL,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_payment_submissions_order` (`order_id`, `created_at`),
          KEY `idx_payment_submissions_job` (`job_order_id`, `created_at`),
          KEY `idx_payment_submissions_customer` (`customer_id`, `created_at`),
          KEY `idx_payment_submissions_branch` (`branch_id`, `created_at`),
          KEY `idx_payment_submissions_queue` (`ocr_status`, `created_at`),
          KEY `idx_payment_submissions_review` (`verification_status`, `created_at`),
          KEY `idx_payment_submissions_reference` (`reference_normalized`),
          KEY `idx_payment_submissions_hash` (`receipt_sha256`),
          UNIQUE KEY `uq_payment_submissions_token` (`customer_id`, `submission_token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $changes++;
}

$columnDefinitions = [
    'order_id' => 'int DEFAULT NULL',
    'job_order_id' => 'bigint DEFAULT NULL',
    'customer_id' => 'int NOT NULL',
    'branch_id' => 'int DEFAULT NULL',
    'receipt_file' => "varchar(500) NOT NULL DEFAULT ''",
    'receipt_storage_path' => 'varchar(500) DEFAULT NULL',
    'receipt_url' => 'varchar(700) DEFAULT NULL',
    'receipt_thumbnail' => 'varchar(500) DEFAULT NULL',
    'receipt_original_name' => 'varchar(255) DEFAULT NULL',
    'receipt_mime' => 'varchar(100) DEFAULT NULL',
    'receipt_size' => 'bigint unsigned NOT NULL DEFAULT 0',
    'receipt_sha256' => 'char(64) DEFAULT NULL',
    'selected_payment_method' => 'varchar(80) DEFAULT NULL',
    'submission_token' => 'char(64) DEFAULT NULL',
    'expected_amount' => 'decimal(12,2) NOT NULL DEFAULT 0.00',
    'submitted_amount' => 'decimal(12,2) NOT NULL DEFAULT 0.00',
    'ocr_sender_name' => 'varchar(190) DEFAULT NULL',
    'sender_name' => 'varchar(190) DEFAULT NULL',
    'ocr_sender_mobile' => 'varchar(40) DEFAULT NULL',
    'sender_mobile' => 'varchar(40) DEFAULT NULL',
    'ocr_reference_number' => 'varchar(190) DEFAULT NULL',
    'reference_number' => 'varchar(190) DEFAULT NULL',
    'reference_normalized' => 'varchar(190) DEFAULT NULL',
    'ocr_amount_sent' => 'decimal(12,2) DEFAULT NULL',
    'amount_sent' => 'decimal(12,2) DEFAULT NULL',
    'ocr_total_amount_sent' => 'decimal(12,2) DEFAULT NULL',
    'total_amount_sent' => 'decimal(12,2) DEFAULT NULL',
    'ocr_detected_payment_method' => 'varchar(80) DEFAULT NULL',
    'detected_payment_method' => 'varchar(80) DEFAULT NULL',
    'ocr_transaction_date' => 'date DEFAULT NULL',
    'transaction_date' => 'date DEFAULT NULL',
    'ocr_transaction_time' => 'time DEFAULT NULL',
    'transaction_time' => 'time DEFAULT NULL',
    'ocr_transaction_status' => 'varchar(80) DEFAULT NULL',
    'transaction_status' => 'varchar(80) DEFAULT NULL',
    'ocr_receiver_name' => 'varchar(190) DEFAULT NULL',
    'receiver_name' => 'varchar(190) DEFAULT NULL',
    'ocr_receiver_account' => 'varchar(190) DEFAULT NULL',
    'receiver_account' => 'varchar(190) DEFAULT NULL',
    'raw_ocr_text' => 'mediumtext',
    'ocr_normalized_text' => 'mediumtext',
    'overall_confidence' => 'decimal(5,2) DEFAULT NULL',
    'sender_confidence' => 'decimal(5,2) DEFAULT NULL',
    'sender_mobile_confidence' => 'decimal(5,2) DEFAULT NULL',
    'reference_confidence' => 'decimal(5,2) DEFAULT NULL',
    'amount_confidence' => 'decimal(5,2) DEFAULT NULL',
    'total_amount_confidence' => 'decimal(5,2) DEFAULT NULL',
    'method_confidence' => 'decimal(5,2) DEFAULT NULL',
    'date_confidence' => 'decimal(5,2) DEFAULT NULL',
    'receiver_confidence' => 'decimal(5,2) DEFAULT NULL',
    'status_confidence' => 'decimal(5,2) DEFAULT NULL',
    'ocr_status' => "varchar(30) NOT NULL DEFAULT 'Pending'",
    'ocr_provider' => 'varchar(50) DEFAULT NULL',
    'ocr_error' => 'varchar(500) DEFAULT NULL',
    'ocr_attempts' => 'smallint unsigned NOT NULL DEFAULT 0',
    'ocr_duration_ms' => 'int unsigned DEFAULT NULL',
    'ocr_processed_at' => 'datetime DEFAULT NULL',
    'amount_match_status' => "varchar(20) NOT NULL DEFAULT 'Unknown'",
    'method_match_status' => "varchar(20) NOT NULL DEFAULT 'Unknown'",
    'duplicate_submission_id' => 'bigint unsigned DEFAULT NULL',
    'verification_status' => "varchar(40) NOT NULL DEFAULT 'Pending Review'",
    'staff_notes' => 'text',
    'rejection_reason' => 'text',
    'corrected_by' => 'int DEFAULT NULL',
    'corrected_at' => 'datetime DEFAULT NULL',
    'verified_by' => 'int DEFAULT NULL',
    'verified_at' => 'datetime DEFAULT NULL',
    'created_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
    'updated_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
];

$columnQuery = $pdo->prepare(
    "SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'payment_submissions' AND column_name = ?
     LIMIT 1"
);
foreach ($columnDefinitions as $column => $definition) {
    $columnQuery->execute([$column]);
    if ($columnQuery->fetchColumn()) {
        continue;
    }
    $pdo->exec("ALTER TABLE `payment_submissions` ADD COLUMN `{$column}` {$definition}");
    $changes++;
}

$indexDefinitions = [
    'idx_payment_submissions_order' => 'KEY `idx_payment_submissions_order` (`order_id`, `created_at`)',
    'idx_payment_submissions_job' => 'KEY `idx_payment_submissions_job` (`job_order_id`, `created_at`)',
    'idx_payment_submissions_customer' => 'KEY `idx_payment_submissions_customer` (`customer_id`, `created_at`)',
    'idx_payment_submissions_branch' => 'KEY `idx_payment_submissions_branch` (`branch_id`, `created_at`)',
    'idx_payment_submissions_queue' => 'KEY `idx_payment_submissions_queue` (`ocr_status`, `created_at`)',
    'idx_payment_submissions_review' => 'KEY `idx_payment_submissions_review` (`verification_status`, `created_at`)',
    'idx_payment_submissions_reference' => 'KEY `idx_payment_submissions_reference` (`reference_normalized`)',
    'idx_payment_submissions_hash' => 'KEY `idx_payment_submissions_hash` (`receipt_sha256`)',
    'uq_payment_submissions_token' => 'UNIQUE KEY `uq_payment_submissions_token` (`customer_id`, `submission_token`)',
];
$indexQuery = $pdo->prepare(
    "SELECT 1 FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'payment_submissions' AND index_name = ?
     LIMIT 1"
);
foreach ($indexDefinitions as $index => $definition) {
    $indexQuery->execute([$index]);
    if ($indexQuery->fetchColumn()) {
        continue;
    }

    if ($index === 'uq_payment_submissions_token') {
        $pdo->exec(
            "UPDATE payment_submissions duplicate_row
             INNER JOIN (
                 SELECT customer_id, submission_token, MIN(id) AS keep_id
                 FROM payment_submissions
                 WHERE submission_token IS NOT NULL AND submission_token <> ''
                 GROUP BY customer_id, submission_token
                 HAVING COUNT(*) > 1
             ) duplicate_group
               ON duplicate_group.customer_id = duplicate_row.customer_id
              AND duplicate_group.submission_token = duplicate_row.submission_token
              AND duplicate_row.id <> duplicate_group.keep_id
             SET duplicate_row.submission_token = NULL"
        );
    }

    $pdo->exec("ALTER TABLE `payment_submissions` ADD {$definition}");
    $changes++;
}

echo $changes > 0 ? "Migration completed successfully\n" : "Migration already applied\n";
