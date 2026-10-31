<?php
$checkout = file_get_contents(__DIR__ . '/../staff/api/pos_checkout.php');
if (!preg_match('/function\s+pos_checkout_pair_design_fee_lines\s*\(.*?\n\}/s', $checkout, $m)) {
    fwrite(STDERR, "FAIL: design-fee checkout pair function is missing.\n");
    exit(1);
}
if (strpos($m[0], 'pos_cart_item_is_service(') !== false) {
    fwrite(STDERR, "FAIL: checkout references cart-handler-only service helper.\n");
    exit(1);
}
if (strpos($m[0], 'pos_payload_item_is_service(') === false ||
    !preg_match('/function\s+pos_payload_item_is_service\s*\(array\s+\$item\)/', $checkout)) {
    fwrite(STDERR, "FAIL: checkout does not use its locally defined service predicate.\n");
    exit(1);
}
echo "POS design-fee checkout helper regression passed.\n";
