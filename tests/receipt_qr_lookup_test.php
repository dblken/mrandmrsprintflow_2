<?php

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
};

require_once $root . '/includes/order_receipt_lookup.php';
require_once $root . '/includes/pos_receipt_format.php';
require_once $root . '/includes/staff_access.php';

$assert(printflow_receipt_format_datetime('2026-08-16 00:05:00') === 'Aug 16, 2026 12:05 AM', 'midnight time is formatted correctly');
$assert(printflow_receipt_format_datetime('2026-08-16 09:05:00') === 'Aug 16, 2026 09:05 AM', 'single-digit hour and minute are zero-padded');
$assert(printflow_receipt_format_datetime('2026-08-16 12:05:00') === 'Aug 16, 2026 12:05 PM', 'noon time is formatted correctly');
$assert(printflow_receipt_format_datetime('2026-08-16 21:50:00') === 'Aug 16, 2026 09:50 PM', 'evening time is formatted correctly');
$dateLines32 = printflow_receipt_labeled_value_lines('Date/Time', 'Aug 16, 2026 09:50 AM', 32);
$assert(count($dateLines32) === 1 && $dateLines32[0] === 'Date/Time  Aug 16, 2026 09:50 AM', '32-column receipt preserves the complete timestamp without truncation');
$dateLines42 = printflow_receipt_labeled_value_lines('Date/Time', 'Aug 16, 2026 09:50 AM', 42);
$assert(count($dateLines42) === 1 && str_ends_with($dateLines42[0], 'Aug 16, 2026 09:50 AM'), 'wider receipt keeps the full timestamp on one line');

$text = "        RECEIPT INFO        \nReceipt No.          POS-011280\nDate/Time Aug 16, 2026 09:50 AM\n";
$raw = base64_decode(printflow_receipt_escpos_base64($text, 'PF1:ORDER:11280'), true);
$assert(is_string($raw), 'ESC/POS output is valid base64');
$assert(str_contains($raw, "\x1D(k\x04\x00\x31\x41\x32\x00"), 'native ESC/POS QR model command is present');
$assert(str_contains($raw, "\x1D(k\x03\x00\x31\x43\x06"), 'native ESC/POS QR uses six-dot modules for 58mm thermal reliability');
$assert(str_contains($raw, "\x1D(k\x03\x00\x31\x45\x32"), 'native ESC/POS QR uses Q-level error correction');
$assert(str_contains($raw, "\x1Ba\x01\n\x1D(k") && str_contains($raw, "\x31\x51\x30\n\n\x1Ba\x00"), 'native QR has centered horizontal space and blank vertical quiet zones');
$assert(str_contains($raw, "\x1D(k\x03\x00\x31\x51\x30"), 'native ESC/POS QR print command is present');
$assert(str_contains($raw, 'PF1:ORDER:11280'), 'QR payload is stored in the ESC/POS command stream');
$assert(strpos($raw, 'RECEIPT INFO') < strpos($raw, 'PF1:ORDER:11280'), 'QR follows the RECEIPT INFO heading');
$assert(strpos($raw, 'PF1:ORDER:11280') < strpos($raw, 'Receipt No.'), 'QR precedes visible receipt details');
$assert(str_contains($text, 'Receipt No.') && str_contains($text, 'POS-011280'), 'visible Receipt No. is preserved in the text stream');

$assert(printflow_order_lookup_normalize_identifier("PF1:\nORDER:11280") === '', 'control characters are rejected');
$assert(printflow_order_lookup_normalize_identifier(' pf1:order:11280 ') === 'PF1:ORDER:11280', 'scanner input is normalized');
$assert(printflow_order_lookup_candidate_order_id('PF1:ORDER:11280') === 11280, 'canonical QR resolves one order id');
$assert(printflow_order_lookup_candidate_order_id('POS-011280') === 11280, 'visible POS receipt number resolves its candidate id');
$assert(printflow_order_lookup_candidate_order_id('SNB-0005-11280') === 11280, 'real SKU-order code shape resolves its candidate id');
$assert(printflow_order_lookup_candidate_order_id('RANDOM VALUE') === 0, 'random identifier is rejected');
$assert(printflow_order_lookup_candidate_order_id('PF1:ORDER:1 OR 1=1') === 0, 'malformed/injection input is rejected');
$assert(printflow_order_lookup_visible_identifier_matches('POS-011280', 11280, 'pos', 'SNB-0005-11280'), 'POS receipt matches only its POS source record');
$assert(printflow_order_lookup_is_pos_source('pos_merged'), 'merged POS drafts remain classified as walk-in sales after checkout');
$assert(printflow_staff_role_can_access_order_source('pos', 'pos_merged'), 'POS staff authorization accepts finalized merged walk-in orders');
$assert(!printflow_staff_role_can_access_order_source('online', 'pos_merged'), 'online staff authorization does not claim merged walk-in orders');
$assert(printflow_order_lookup_visible_identifier_matches('POS-011280', 11280, 'pos_merged', 'SNB-0005-11280'), 'merged POS receipt identifiers match their walk-in order');
$assert(!printflow_order_lookup_visible_identifier_matches('POS-011280', 11280, 'customer', 'SNB-0005-11280'), 'POS identifier cannot pretend an online record is POS');
$assert(printflow_order_lookup_visible_identifier_matches('SNB-0005-11280', 11280, 'customer', 'SNB-0005-11280'), 'online visible code matches its exact database-derived code');
$assert(!printflow_order_lookup_visible_identifier_matches('FAKE-11280', 11280, 'customer', 'SNB-0005-11280'), 'wrong SKU with a real numeric suffix is rejected');
$assert(printflow_order_lookup_management_route('Admin', 11280, 2, 0, false, '/printflow') === '/printflow/admin/orders_management.php?open_order=11280&branch_id=2', 'admin product route opens the exact existing order and branch');
$assert(printflow_order_lookup_management_route('Manager', 11280, 2, 45, true, '/printflow') === '/printflow/manager/customizations.php?open_job=45', 'manager custom route opens the exact existing job');
$assert(printflow_order_lookup_management_route('Staff', 11280, 2, 0, false, '/printflow', '/printflow/staff/orders.php?order_id=11280') === '/printflow/staff/orders.php?order_id=11280', 'staff route reuses the existing staff destination');

$api = file_get_contents($root . '/staff/api/order_receipt_lookup.php');
$pos = file_get_contents($root . '/staff/pos.php');
$printer = file_get_contents($root . '/includes/pos_receipt_printer.php');
$scanner = file_get_contents($root . '/public/assets/js/receipt-scanner.js');
$assert(str_contains($api, "require_once __DIR__ . '/../../includes/auth.php'"), 'lookup API requires application authentication');
$assert(str_contains($api, 'if (!is_logged_in())'), 'missing sessions fail with a JSON authentication response');
$assert(str_contains($api, "['Staff', 'Manager', 'Admin']"), 'only authorized operational roles can use receipt lookup');
$assert(str_contains($api, 'get_user_allowed_branches'), 'lookup API enforces branch scope');
$assert(str_contains($api, 'This order belongs to another branch.'), 'unauthorized branches fail with a staff-safe message');
$assert(str_contains($api, 'printflow_staff_role_can_access_order_source'), 'lookup API enforces staff operation scope');
$assert(str_contains($api, 'WHERE o.order_id = ?'), 'lookup API uses a prepared order-id query');
$assert(str_contains($api, "'code' => 'LOOKUP_ERROR'") && str_contains($api, "'request_id'"), 'lookup API returns structured failure codes and a safe diagnostic request id');
$assert(str_contains($pos, '/^PF1:ORDER:'), 'POS scanner reuses its existing input for receipt QR payloads');
$assert(str_contains($scanner, 'isProductBarcodeTarget(target)'), 'global receipt scanner distinguishes the dedicated POS product barcode input');
$assert(str_contains($scanner, 'PF1:ORDER:[1-9][0-9]{0,9}'), 'global scanner accepts only the canonical receipt payload');
$assert(str_contains($scanner, 'MAX_GAP_MS') && str_contains($scanner, 'DUPLICATE_MS'), 'global scanner enforces scanner speed and duplicate-scan protection');
$assert(str_contains($scanner, "event.key === 'Tab'") && str_contains($scanner, "event.key === 'Shift'"), 'global scanner accepts Tab termination and does not discard shifted colon input');
$assert(str_contains($scanner, 'transient lookup failure; retrying once'), 'global scanner retries one transient lookup failure');
$assert(str_contains($scanner, 'markOrderOpened'), 'destination pages can confirm that the exact scanned order opened');
$assert(str_contains($scanner, '/staff/api/order_receipt_lookup.php'), 'global scanner uses the authenticated server lookup');
$assert(str_contains($scanner, 'window.location.assign(String(data.route))'), 'global scanner follows only the backend-computed route');
$assert(!str_contains($scanner, 'pos_checkout.php'), 'receipt scanning cannot create a checkout or sale');
$assert(strpos($printer, "printflow_receipt_center('RECEIPT INFO'") < strpos($printer, "printflow_receipt_pair('Receipt No.'"), 'receipt formatter keeps QR insertion heading above visible receipt details');

echo "Receipt QR and secure order lookup tests passed.\n";
