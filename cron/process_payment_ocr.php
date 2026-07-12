<?php
/**
 * Background payment OCR queue worker.
 *
 * Scheduler example: php cron/process_payment_ocr.php 20
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/payment_verification.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$limit = isset($argv[1]) ? (int)$argv[1] : 10;
$summary = payment_ocr_process_queue($limit);
echo '[payment_ocr] processed=' . $summary['processed']
    . ' completed=' . $summary['completed']
    . ' needs_review=' . $summary['needs_review']
    . ' failed=' . $summary['failed'] . PHP_EOL;
