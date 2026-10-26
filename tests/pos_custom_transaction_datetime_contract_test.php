<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string)file_get_contents($root . '/' . $path);
$pos = $read('staff/pos.php');
$checkout = $read('staff/api/pos_checkout.php');
$datetime = $read('includes/pos_custom_transaction_datetime.php');
$serviceFields = $read('staff/api/pos_service_fields.php');
$renderer = $read('includes/service_field_renderer.php');

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $passed++;
    echo 'PASS: ', $message, PHP_EOL;
};

$assert(
    !str_contains($pos, 'Set Date &amp; Time')
        && !str_contains($pos, 'Date/Time: Current server time')
        && !str_contains($pos, 'pos-open-transaction-datetime-btn')
        && !str_contains($pos, 'pos-tx-datetime-modal')
        && !str_contains($pos, 'posCustomTransactionState')
        && !str_contains($pos, 'posCheckoutCustomTransactionPayload')
        && !str_contains($pos, 'validatePosCheckoutTransactionDateTime')
        && !str_contains($pos, 'resetPosCheckoutTransactionDateTime'),
    'POS markup and JavaScript contain no Set Date & Time UI or checkout controls'
);
$assert(
    str_contains($pos, 'Needed date cannot be in the past.')
        && !str_contains($pos, 'posCustomTransactionState.enabled'),
    'Needed Date keeps its normal past-date validation without transaction date mode'
);
$assert(
    str_contains($datetime, 'function printflow_pos_selected_transaction_datetime')
        && str_contains($datetime, 'Asia/Manila')
        && str_contains($datetime, 'Future transaction dates are not allowed'),
    'Shared datetime helper validates Manila wall time and rejects future stamps'
);
$assert(
    str_contains($checkout, 'printflow_pos_selected_transaction_datetime')
        && str_contains($checkout, 'pos_business_datetime')
        && str_contains($checkout, "'transaction_date' => \$selectedTransactionAt"),
    'Checkout keeps server-selected timestamps for orders, customizations, and inventory'
);
$assert(
    !str_contains($pos, 'posCheckoutCustomTransactionPayload()')
        && !str_contains($pos, 'custom_transaction_date')
        && !str_contains($pos, 'custom_transaction_time')
        && str_contains($datetime, "return printflow_pos_manila_now()->format('Y-m-d H:i:s')"),
    'POS submits no custom transaction time and checkout defaults to current Manila server time'
);
$assert(
    str_contains($serviceFields, 'printflow_allow_past_needed_date')
        && str_contains($renderer, 'printflow_allow_past_needed_date'),
    'Authorized POS service fields allow past Needed Date when backdating is enabled'
);

echo "POS custom transaction datetime contract: {$passed} assertions passed.\n";
