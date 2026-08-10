<?php
declare(strict_types=1);

$source = (string)file_get_contents(__DIR__ . '/../staff/api/pos_checkout.php');
$failures = [];

function pos_paymongo_checkout_assert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

pos_paymongo_checkout_assert(
    strpos($source, "db_table_has_column('orders', 'price_finalized_at')") !== false
        && strpos($source, "db_table_has_column('orders', 'price_finalized_by')") !== false,
    'POS checkout detects optional order price-finalization columns.'
);
pos_paymongo_checkout_assert(
    strpos($source, 'function pos_prepare_order_for_paymongo_checkout(') !== false
        && strpos($source, 'price_finalized_at = COALESCE(price_finalized_at, NOW())') !== false
        && strpos($source, 'price_finalized_by = COALESCE(price_finalized_by, ?)') !== false,
    'New POS orders and reused POS orders are finalized for PayMongo before creating a payment link.'
);
pos_paymongo_checkout_assert(
    strpos($source, 'price_finalized_at = COALESCE(price_finalized_at, NOW())') !== false
        && strpos($source, 'price_finalized_by = COALESCE(price_finalized_by, ?)') !== false
        && strpos($source, "'ssd' . \$pendingPriceFinalTypes . 'i'") !== false,
    'Merged pending POS orders keep or set final-price audit metadata.'
);
pos_paymongo_checkout_assert(
    strpos($source, "printflow_provider_payment_create_link(") !== false
        && strpos($source, "'pos',") !== false,
    'POS PayMongo checkout still uses the shared provider-payment link creator.'
);
pos_paymongo_checkout_assert(
    strpos($source, "unset(\$_SESSION['pos_paymongo_checkouts'][\$checkoutToken]);") !== false,
    'POS checkout clears stale checkout-token mappings before creating a fresh PayMongo order.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "POS PayMongo checkout regression test passed.\n";
