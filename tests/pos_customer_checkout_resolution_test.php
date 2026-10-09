<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$helpers = file_get_contents($root . '/includes/pos_customer_helpers.php');
$checkout = file_get_contents($root . '/staff/api/pos_checkout.php');
$receipt = file_get_contents($root . '/includes/pos_receipt.php');
$addCustomer = file_get_contents($root . '/staff/api/pos_add_customer.php');
$customersAdmin = file_get_contents($root . '/admin/customers_management.php');

if ($helpers === false || $checkout === false || $receipt === false || $addCustomer === false || $customersAdmin === false) {
    throw new RuntimeException('Unable to read POS customer flow sources.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

$assert(strpos($helpers, 'function printflow_pos_resolve_checkout_customer_context(') !== false, 'shared checkout customer resolver exists');
$assert(strpos($helpers, 'pos_get_walkin_customer_id()') !== false, 'name-only walk-in uses shared walk-in customer id');
$assert(strpos($helpers, 'pos_guest_display_name') !== false, 'walk-in name stored on order context');
$assert(strpos($helpers, 'function printflow_pos_find_or_create_customer_by_email(') !== false, 'email path can link or create one customer');
$assert(strpos($helpers, 'function printflow_pos_is_placeholder_customer_email(') !== false, 'placeholder emails rejected for registered accounts');

$assert(strpos($checkout, 'function pos_create_name_only_pos_customer(') === false, 'checkout no longer inserts pos.guest customers');
$assert(strpos($checkout, 'pos_checkout_guest_display_name_insert_sql') !== false, 'checkout persists order-level guest display name');
$assert(strpos($checkout, 'function pos_checkout_order_insert_types(') !== false, 'checkout uses explicit order insert bind types');
$assert(
    strpos($checkout, "'i' . \$guestTypes . 'iiidssssss'") === false,
    'checkout no longer uses mismatched order insert bind types'
);
$assert(strpos($checkout, 'printflow_pos_resolve_checkout_customer_context') !== false, 'checkout uses shared resolver');

$assert(strpos($receipt, 'pos_guest_display_name') !== false, 'receipt reads order-level guest display name');
$assert(strpos($addCustomer, 'linked_existing') !== false, 'add customer links existing email instead of duplicating');
$assert(strpos($addCustomer, 'printflow_pos_is_placeholder_customer_email') !== false, 'add customer rejects placeholder emails server-side');

$assert(strpos($customersAdmin, 'printflow_pos_sql_exclude_placeholder_customers') !== false, 'customers management hides POS placeholder accounts');

echo "OK pos_customer_checkout_resolution_test\n";
