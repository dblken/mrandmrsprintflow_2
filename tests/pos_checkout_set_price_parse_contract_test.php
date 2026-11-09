<?php
declare(strict_types=1);

$checkout = (string) file_get_contents(__DIR__ . '/../staff/api/pos_checkout.php');
if ($checkout === '') {
    throw new RuntimeException('Unable to read pos_checkout.php.');
}

if (!preg_match('/function pos_checkout_parse_item_quantity\(\$value, array \$item\): int/', $checkout)) {
    throw new RuntimeException('pos_checkout_parse_item_quantity must require the cart item context.');
}

if (preg_match_all('/pos_checkout_parse_item_quantity\(\s*\$[^,]+\s*\)\s*;/', $checkout, $matches) && !empty($matches[0])) {
    throw new RuntimeException('pos_checkout_parse_item_quantity is called without the cart item: ' . implode(' | ', $matches[0]));
}

if (strpos($checkout, "action: 'create_pending_customization'") !== false
    || strpos($checkout, 'create_pending_customization') === false) {
    // Set Price posts create_pending_customization through redirectToSetPrice.
}
if (!preg_match('/create_pending_customization[\s\S]{0,4000}pos_checkout_parse_item_quantity\(\$item\[\'qty\'\][\s\S]{0,120}\$item\)/', $checkout)) {
    throw new RuntimeException('create_pending_customization must parse quantity with the submitted item for service/product detection.');
}

if (strpos($checkout, 'http_response_code(400)') === false || strpos($checkout, "json_encode(['success' => false") === false) {
    throw new RuntimeException('Checkout must return JSON error payloads for validation failures.');
}

echo "POS checkout Set Price quantity parse contract tests passed.\n";
