<?php
declare(strict_types=1);

$checkoutSource = (string)file_get_contents(__DIR__ . '/../staff/api/pos_checkout.php');
$posSource = (string)file_get_contents(__DIR__ . '/../staff/pos.php');
$failures = [];

function pos_paymongo_checkout_assert(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

pos_paymongo_checkout_assert(
    strpos($checkoutSource, "db_table_has_column('orders', 'price_finalized_at')") !== false
        && strpos($checkoutSource, "db_table_has_column('orders', 'price_finalized_by')") !== false,
    'POS checkout detects optional order price-finalization columns.'
);
pos_paymongo_checkout_assert(
    strpos($checkoutSource, 'function pos_prepare_order_for_paymongo_checkout(') !== false
        && strpos($checkoutSource, 'price_finalized_at = COALESCE(price_finalized_at, NOW())') !== false
        && strpos($checkoutSource, 'price_finalized_by = COALESCE(price_finalized_by, ?)') !== false,
    'New POS orders and reused POS orders are finalized for PayMongo before creating a payment link.'
);
pos_paymongo_checkout_assert(
    strpos($checkoutSource, 'price_finalized_at = COALESCE(price_finalized_at, NOW())') !== false
        && strpos($checkoutSource, 'price_finalized_by = COALESCE(price_finalized_by, ?)') !== false
        && strpos($checkoutSource, "'ssd' . \$pendingPriceFinalTypes . 'i'") !== false,
    'Merged pending POS orders keep or set final-price audit metadata.'
);
pos_paymongo_checkout_assert(
    strpos($checkoutSource, "printflow_provider_payment_create_link(") !== false
        && strpos($checkoutSource, "'pos',") !== false,
    'POS PayMongo checkout still uses the shared provider-payment link creator.'
);
pos_paymongo_checkout_assert(
    strpos($checkoutSource, "unset(\$_SESSION['pos_paymongo_checkouts'][\$checkoutToken]);") !== false,
    'POS checkout clears stale checkout-token mappings before creating a fresh PayMongo order.'
);
pos_paymongo_checkout_assert(
    strpos($posSource, 'posPayMongoCheckoutPending') !== false
        && strpos($posSource, 'getPosPayMongoCheckoutToken(true)') !== false
        && strpos($posSource, "sessionStorage.removeItem('pos_paymongo_checkout_token');") !== false,
    'POS PayMongo checkout reuses a pending token only for the same attempt and resets stale checkout state for new ones.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "POS PayMongo checkout regression test passed.\n";
