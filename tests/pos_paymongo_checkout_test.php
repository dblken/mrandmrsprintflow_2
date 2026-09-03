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
    'New POS orders and reused POS orders are finalized before creating QR Ph.'
);
pos_paymongo_checkout_assert(
    strpos($checkoutSource, 'price_finalized_at = COALESCE(price_finalized_at, NOW())') !== false
        && strpos($checkoutSource, 'price_finalized_by = COALESCE(price_finalized_by, ?)') !== false
        && strpos($checkoutSource, "'ssd' . \$pendingPriceFinalTypes . 'i'") !== false,
    'Merged pending POS orders keep or set final-price audit metadata.'
);
pos_paymongo_checkout_assert(
    strpos($checkoutSource, "'cash' => 'Cash'") !== false
        && strpos($checkoutSource, "'paymongo qrph' => 'PayMongo QRPh'") !== false
        && strpos($checkoutSource, 'Unsupported POS payment method.') !== false
        && strpos($checkoutSource, 'printflow_provider_payment_create_link(') === false,
    'POS checkout accepts only Cash and PayMongo QR Ph and cannot create a hosted checkout.'
);
pos_paymongo_checkout_assert(
    strpos($posSource, '<option value="Cash">Cash</option>') !== false
        && strpos($posSource, '<option value="PayMongo QRPh">PayMongo QR Ph</option>') !== false
        && strpos($posSource, '<option value="GCash">') === false
        && strpos($posSource, '<option value="PayMongo Checkout">') === false,
    'POS payment selector exposes only Cash and available PayMongo QR Ph.'
);
pos_paymongo_checkout_assert(
    strpos($checkoutSource, "unset(\$_SESSION['pos_paymongo_checkouts'][\$checkoutToken]);") !== false,
    'POS checkout clears stale checkout-token mappings before creating a fresh PayMongo order.'
);
pos_paymongo_checkout_assert(
    strpos($posSource, 'posPayMongoCheckoutPending') !== false
        && strpos($posSource, 'getPosPayMongoCheckoutToken(true)') !== false
        && strpos($posSource, "sessionStorage.removeItem('pos_paymongo_checkout_token');") !== false,
    'POS QR Ph reuses a pending token only for the same attempt and resets stale checkout state for new ones.'
);
pos_paymongo_checkout_assert(
    strpos($posSource, 'payment?.qr_image_url') !== false
        && strpos($posSource, "action: 'create_qrph'") !== false
        && strpos($posSource, 'Waiting for payment confirmation.') !== false,
    'POS QR Ph image, retry, and polling UI remain intact.'
);

$paymongoApi = (string)file_get_contents(__DIR__ . '/../staff/api/paymongo_payment.php');
$providerSource = (string)file_get_contents(__DIR__ . '/../includes/provider_payments.php');
pos_paymongo_checkout_assert(
    strpos($paymongoApi, "\$channel === 'pos' && \$action === 'create_link'") !== false
        && strpos($paymongoApi, "'code' => 'unsupported_pos_payment_method'") !== false,
    'direct POS requests for PayMongo Checkout are rejected server-side.'
);
pos_paymongo_checkout_assert(
    strpos($providerSource, 'function printflow_provider_payment_create_link(') !== false,
    'shared and historical PayMongo Payment Link support remains available.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "POS PayMongo checkout regression test passed.\n";
