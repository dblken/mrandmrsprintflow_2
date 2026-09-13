<?php

require_once __DIR__ . '/../includes/pos_receipt_printer.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

$printerId = 42;
$apiKey = printflow_receipt_printer_generate_api_key($printerId);

$assert(
    printflow_receipt_printer_normalize_api_key('Bearer ' . $apiKey) === $apiKey,
    'normalize strips Bearer prefix'
);
$assert(
    printflow_receipt_printer_normalize_api_key($apiKey) === $apiKey,
    'normalize keeps raw PrintFlow printer key'
);
$assert(
    printflow_receipt_printer_hash_key('Bearer ' . $apiKey) === printflow_receipt_printer_hash_key($apiKey),
    'hash_key normalizes Bearer-wrapped keys'
);

$assert(
    printflow_receipt_printer_extract_api_key_from_payload([
        'query' => ['_id' => 'job-uuid', 'apiKey' => $apiKey],
    ]) === $apiKey,
    'extract apiKey from PushPrinter-style query payload'
);
$assert(
    printflow_receipt_printer_extract_api_key_from_payload([
        'api_key' => $apiKey,
        'query' => ['_id' => 'job-uuid'],
    ]) === $apiKey,
    'extract api_key from JSON request root'
);
$assert(
    printflow_receipt_printer_extract_api_key_from_payload([
        'headers' => ['Authorization' => 'Bearer ' . $apiKey],
        'query' => ['_id' => 'job-uuid'],
    ]) === $apiKey,
    'extract Authorization from embedded JSON headers'
);

echo "POS receipt printer auth test passed.\n";
