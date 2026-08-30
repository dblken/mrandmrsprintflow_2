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
    strpos($source, '$priceFinalColumns = \', price_finalized_at, price_finalized_by\';') !== false
        && strpos($source, '$priceFinalValues = \', NOW(), ?\';') !== false
        && strpos($source, "array_merge(\n            [\$customer_id, \$branch_id, \$reference_id, \$total_amount") !== false,
    'New POS orders stamp the staff-calculated total as finalized before creating a PayMongo link.'
);
pos_paymongo_checkout_assert(
    strpos($source, 'price_finalized_at = COALESCE(price_finalized_at, NOW())') !== false
        && strpos($source, 'price_finalized_by = COALESCE(price_finalized_by, ?)') !== false
        && strpos($source, "'ssd' . \$pendingPriceFinalTypes . 'i'") !== false,
    'Merged pending POS orders keep or set final-price audit metadata.'
);
pos_paymongo_checkout_assert(
    strpos($source, "printflow_provider_payment_create_link(\n            'order',\n            (int)\$order_id,\n            'pos',") !== false,
    'POS PayMongo checkout still uses the shared provider-payment link creator.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "POS PayMongo checkout regression test passed.\n";
