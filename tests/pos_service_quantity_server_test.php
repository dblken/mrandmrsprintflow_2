<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/pos_quantity_validation.php';

$failures = [];
$expect = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$handler = (string)file_get_contents(__DIR__ . '/../staff/api/pos_cart_handler.php');
$expect(str_contains($handler, 'function pos_cart_parse_positive_quantity'), 'POS cart handler wraps the shared quantity parser');
$expect(str_contains($handler, "case 'add':"), 'add action remains the service Add to Order entry point');
$expect(
    (bool)preg_match("/case 'add':.*?pos_cart_parse_positive_quantity\\(/s", $handler),
    'add parses qty through the strict positive-quantity helper'
);
$expect(
    (bool)preg_match("/case 'validate_service_stock':.*?pos_cart_parse_positive_quantity\\(/s", $handler),
    'validate_service_stock parses qty through the strict positive-quantity helper'
);
$expect(
    (bool)preg_match("/case 'update':.*?pos_cart_parse_positive_quantity\\(/s", $handler),
    'cart line quantity updates use the same strict parser'
);

foreach (['1' => 1, '4' => 4, '100' => 100, '0007' => 7] as $raw => $expected) {
    $expect(printflow_pos_parse_cart_quantity($raw) === $expected, "valid quantity {$raw} parses to {$expected}");
}

foreach (['', ' ', 'h5tgj', '12abc', '0', '000', '-1', '+1', '1.5', '1e3', '12!', null, 1.5, true] as $invalid) {
    $rejected = false;
    try {
        printflow_pos_parse_cart_quantity($invalid);
    } catch (InvalidArgumentException $error) {
        $rejected = true;
        $expect(
            str_contains($error->getMessage(), 'whole number') || str_contains($error->getMessage(), 'Quantity'),
            'invalid quantity error remains actionable'
        );
    }
    $label = is_scalar($invalid) || $invalid === null ? var_export($invalid, true) : gettype($invalid);
    $expect($rejected, "server rejects invalid quantity {$label}");
}

if ($failures !== []) {
    fwrite(STDERR, "pos_service_quantity_server_test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "pos_service_quantity_server_test passed.\n";
