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
$printEndpoint = $read('staff/api/pos_receipt_print.php');
$customerOrders = $read('customer/orders.php');
$customerItems = $read('customer/get_order_items.php');
$staffOrders = $read('staff/orders.php');
$printerApi = $read('public/api/printer/jobs.php');
$escposDelivery = $read('printing/client/order-to-escpos/index.php');
$pushprinterStatus = $read('printing/jobs/update/status/index.php');
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
$assert(str_contains($printer, 'Print intent expired before the printer claimed it. Use Retry Print.'), 'stale pending jobs expire instead of printing on reconnect');
$assert(str_contains($printer, "updated_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)"), 'printer polling can claim only a fresh explicit print intent');
$assert(!str_contains($printer, "SET status = 'pending', claimed_at = NULL,"), 'stale claimed jobs are not automatically requeued');
$assert(str_contains($printer, "\$nextStatus = 'failed';"), 'printer failures wait for deliberate Retry Print');
$assert(str_contains($escposDelivery, "status IN ('pending', 'claimed')") && str_contains($escposDelivery, 'updated_at >= DATE_SUB'), 'stale Pushy notifications cannot fetch printable ESC/POS data');
$assert(str_contains($pushprinterStatus, "\$jobStatuses = \$status === 'received'") && str_contains($pushprinterStatus, 'updated_at >= DATE_SUB'), 'stale PushPrinter callbacks cannot reactivate old jobs');
$assert(str_contains($escposDelivery, "SET status = 'delivering'") && str_contains($escposDelivery, 'already delivered'), 'one Pushy notification can fetch the ESC/POS payload only once');
$assert(!str_contains(substr($checkout, strpos($checkout, '$receipt = pos_build_receipt_payload((int)$order_id')), 'printflow_receipt_enqueue_order_print_safe'), 'cash checkout does not queue a physical receipt');
$assert(str_contains($checkout, "'cashier' =>"), 'cash receipt includes the cashier');
$assert(!str_contains($paymongo, 'printflow_receipt_enqueue_order_print_safe'), 'PayMongo completion does not queue a physical receipt');
$assert(str_contains($printEndpoint, 'printflow_receipt_enqueue_order_print_safe'), 'staff Print Receipt uses the existing print queue');
$assert(str_contains($printEndpoint, "\$action === 'reprint'"), 'staff endpoint supports a print-only reprint action');
$assert(str_contains($printEndpoint, "\$receipt['reprint'] = true"), 'reprints are clearly marked');
$assert(!str_contains($pos, 'autoPrintReceiptAfterTransaction'), 'POS no longer auto-invokes browser printing');
$assert(str_contains($pos, 'openReceiptModal(data.receipt)'), 'POS opens the finalized receipt preview after completion');
$assert(str_contains($pos, "action: 'print'"), 'POS Print Receipt explicitly starts printing');
$assert(str_contains($pos, "'Retry Print'"), 'failed receipt printing offers a Retry Print action');
$assert(str_contains($pos, 'pos_receipt_print_retry.php'), 'Retry Print requeues the existing receipt job');
$assert(str_contains($staffOrders, 'Reprint Receipt'), 'completed Walk-in Order details provide reprint access');
$assert(!str_contains($customerOrders, 'window.print()'), 'customer receipt cannot open the system print dialog');
$assert(!str_contains($customerOrders, '>Print Receipt</button>'), 'customer receipt modal has no Print Receipt control');
$assert(str_contains($customerOrders, 'Download Receipt'), 'customer receipt keeps Download Receipt');
$assert(str_contains($customerItems, 'printflow_receipt_qr_payload($orderId)'), 'online and POS receipts share the canonical QR payload helper');
$assert(str_contains($format, 'Scan for order details'), 'thermal QR includes the order details caption');
$assert(str_contains($pos, 'Scan for order details') && str_contains($customerOrders, 'Scan for order details'), 'POS and online previews include the QR caption');
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
