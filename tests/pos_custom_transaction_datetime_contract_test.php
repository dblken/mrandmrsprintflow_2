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
    str_contains($pos, 'Set Date &amp; Time')
        && str_contains($pos, 'pos-order-datetime-section')
        && str_contains($pos, 'pos-datetime-feature: staff-walkin-20261016')
        && str_contains($pos, 'POS_CAN_CUSTOM_TRANSACTION_DATETIME')
        && str_contains($pos, 'posCheckoutCustomTransactionPayload')
        && str_contains($pos, 'validatePosCheckoutTransactionDateTime'),
    'POS exposes authorized Set Date & Time controls and checkout payload wiring'
);
$assert(
    str_contains($pos, 'Needed date cannot be in the past.')
        && str_contains($pos, 'posCustomTransactionState.enabled'),
    'Needed Date stays validated unless custom business datetime mode is active'
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
    'Checkout persists custom business datetime on orders, customizations, and inventory'
);
$assert(
    str_contains($serviceFields, 'printflow_allow_past_needed_date')
        && str_contains($renderer, 'printflow_allow_past_needed_date'),
    'Authorized POS service fields allow past Needed Date when backdating is enabled'
);

echo "POS custom transaction datetime contract: {$passed} assertions passed.\n";
