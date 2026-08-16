<?php

$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $contents = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    if (!is_string($contents)) throw new RuntimeException('Could not read ' . $relative);
    return $contents;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
};

$printer = $read('includes/pos_receipt_printer.php');
$format = $read('includes/pos_receipt_format.php');
$checkout = $read('staff/api/pos_checkout.php');
$paymongo = $read('staff/api/paymongo_payment.php');
$pos = $read('staff/pos.php');
$printerApi = $read('public/api/printer/jobs.php');
$migration = $read('database/receipt_printers_pushprinter_20260816.sql');

$assert(str_contains($migration, 'UNIQUE KEY uq_receipt_print_jobs_idempotency'), 'print jobs have database-level idempotency');
$assert(str_contains($printer, "\$jobType . ':order:' . \$orderId . ':printer:'"), 'receipt idempotency is scoped to order and printer');
$assert(str_contains($printer, 'max(32, min(42, $columns))'), '58mm formatter constrains output to 32-42 columns');
$assert(str_contains($printer, 'wordwrap('), 'long receipt text is wrapped');
$assert(str_contains($printer, 'escpos_base64'), 'queue stores ESC/POS output');
$assert(str_contains($format, 'printflow_receipt_escpos_qr_commands'), 'receipt output supports native ESC/POS QR commands');
$assert(str_contains($format, "'PF1:ORDER:' . \$orderId"), 'receipt QR uses the canonical unique orders primary key payload');
$assert(str_contains($printer, "'printer_type' => 'escpos'"), 'PushPrinter notification requests ESC/POS');
$assert(str_contains($printer, "'order_number' => \$orderNumber"), 'PushPrinter notification includes its required order_number field');
$assert(str_contains($printer, "status IN ('pending', 'failed')"), 'pending or failed receipt jobs can be retried without recreating a sale');
$assert(str_contains($printer, 'SET job_uuid = ?'), 'retry rotates the delivery UUID so PushPrinter does not reject a cached job');
$assert(str_contains($checkout, 'printflow_receipt_enqueue_order_print_safe'), 'cash checkout queues only after successful completion');
$assert(str_contains($checkout, "'cashier' =>"), 'cash receipt includes the cashier');
$assert(str_contains($paymongo, 'printflow_receipt_enqueue_order_print_safe'), 'PayMongo completion uses the same print queue');
$assert(!str_contains($pos, 'autoPrintReceiptAfterTransaction'), 'POS no longer auto-invokes browser printing');
$assert(str_contains($pos, 'monitorReceiptPrintJob(data.print_job)'), 'POS monitors the server print job without opening a receipt modal');
$assert(str_contains($pos, "'Retry Print'"), 'failed receipt printing offers a Retry Print action');
$assert(str_contains($pos, 'pos_receipt_print_retry.php'), 'Retry Print requeues the existing receipt job');
$assert(!str_contains($pos, "showPosScanToast("), 'receipt monitoring uses an existing defined notification function');
$assert(str_contains($printerApi, "\$action === 'diagnostics'"), 'printer API exposes authenticated receipt diagnostics');
$assert(str_contains($printerApi, "\$action === 'adopt-retry'"), 'printer API can safely adopt and retry an existing receipt job');
$assert(str_contains($printerApi, "WHERE id = ? AND status IN ('pending', 'failed')"), 'printer job adoption cannot recreate or alter an active completed sale');

foreach ([
    'printing/pushy/register-device/index.php',
    'printing/client/order-to-escpos/index.php',
    'printing/jobs/update/status/index.php',
    'printing/jobs/update/error/index.php',
] as $route) {
    $assert(is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $route)), $route . ' exists');
}

echo "PushPrinter POS receipt regression test passed.\n";
