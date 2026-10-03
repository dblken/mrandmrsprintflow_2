<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$receipt = file_get_contents($root . '/includes/pos_receipt.php');
$checkout = file_get_contents($root . '/staff/api/pos_checkout.php');
$pos = file_get_contents($root . '/staff/pos.php');
if ($receipt === false || $checkout === false || $pos === false) {
    throw new RuntimeException('Unable to read POS sources.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

$assert(strpos($receipt, 'function printflow_pos_receipt_customer_fields(') !== false, 'receipt builder exposes customer field helper');
$assert(
    preg_match("/\\\$isSharedPlaceholder && \\(\\\$fullName === '' \\|\\| strcasecmp\\(\\\$fullName, 'Walk-in Guest'\\)/", $receipt) === 1,
    'receipt only falls back to Walk-in Guest for the shared placeholder account'
);
$assert(strpos($receipt, "'customer' => \$receiptCustomer") !== false, 'receipt payload uses resolved customer fields');

$assert(strpos($checkout, 'function pos_resolve_checkout_customer_id(') !== false, 'checkout resolves guest vs account customers');
$assert(strpos($checkout, 'function pos_create_name_only_pos_customer(') !== false, 'checkout can persist name-only walk-in customers');
$assert(strpos($checkout, 'pos.guest.') !== false && strpos($checkout, '@pos.local') !== false, 'name-only guests use synthetic local emails');
$assert(
    strpos($checkout, "SET first_name = 'Walk-in'") === false,
    'checkout no longer resets the shared walk-in placeholder name on every sale'
);

$assert(strpos($pos, 'function posCheckoutCustomerPayload') !== false, 'POS UI sends guest display name at checkout');
$assert(strpos($pos, 'id="pos-guest-name"') !== false, 'POS UI collects walk-in customer name');
$assert(strpos($pos, 'Enter Customer Name') !== false, 'checkout button reflects missing walk-in name');
$assert(strpos($pos, 'id="pos-barcode-input"') === false, 'products view no longer uses a duplicate SKU input');
$assert(
    preg_match('/id="pos-search"[^>]*pos-barcode-entry/s', $pos) === 1,
    'combined product search input still supports SKU scan on Enter'
);

echo "OK pos_guest_customer_receipt_test\n";
