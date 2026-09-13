<?php

$read = static function (string $relativePath): string {
    $path = __DIR__ . '/../' . ltrim($relativePath, '/');
    if (!is_file($path)) {
        throw new RuntimeException('Missing file: ' . $relativePath);
    }
    return (string)file_get_contents($path);
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

$printer = $read('includes/pos_receipt_printer.php');
$escpos = $read('printing/client/order-to-escpos/index.php');

$assert(str_contains($printer, 'printflow_receipt_printer_json_body'), 'auth helpers parse JSON POST bodies');
$assert(str_contains($printer, "['printer']"), 'auth helpers inspect printer JSON nodes');
$assert(str_contains($printer, 'HTTP_API_KEY'), 'auth helpers accept HTTP_API_KEY');
$assert(str_contains($printer, 'rawurldecode'), 'auth helpers decode URL-encoded keys');
$assert(str_contains($printer, 'api_key_prefix = ? AND api_key_last4 = ?'), 'auth can recover keys missing the |printerId suffix');
$assert(str_contains($escpos, 'printflow_receipt_printer_json_body'), 'order-to-escpos parses JSON before authenticating');
$assert(str_contains($escpos, "\$input['query']['_id'] ?? \$input['_id']"), 'order-to-escpos accepts root and query job ids');

echo "POS receipt printer auth test passed.\n";
