<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$admin = (string)file_get_contents($root . '/admin/products_management.php');
$barcodeApi = (string)file_get_contents($root . '/admin/api_product_barcode.php');
$barcodeHelper = (string)file_get_contents($root . '/includes/barcode.php');
$lookupApi = (string)file_get_contents($root . '/staff/api/get_product_by_sku.php');
$pos = (string)file_get_contents($root . '/staff/pos.php');
$scanner = (string)file_get_contents($root . '/public/assets/js/receipt-scanner.js');

$passed = 0;
$check = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $passed++;
    echo 'PASS: ', $message, PHP_EOL;
};

require_once $root . '/includes/barcode.php';
$check(printflow_barcode_clean_value(" TSH-0004\r\n") === 'TSH-0004', 'barcode payload normalizes to the visible canonical SKU');
$check(
    str_contains($admin, "pfProductBarcodeUrl(sku)")
        && str_contains($admin, 'txt.textContent = sku')
        && str_contains($barcodeApi, 'printflow_barcode_svg($sku'),
    'Admin preview text and generated Code 128 image use the same SKU value'
);
$check(
    str_contains($lookupApi, 'WHERE LOWER(TRIM(p.sku)) = LOWER(?)')
        && str_contains($lookupApi, 'LIMIT 2')
        && str_contains($lookupApi, 'count($rows) > 1'),
    'POS lookup is normalized exact-match and rejects duplicate canonical SKUs'
);
$check(
    str_contains($pos, "key === 'Enter' || key === 'Tab' || key === '\\r' || key === '\\n'")
        && str_contains($pos, "replace(/[\\r\\n]+/g, '').trim()"),
    'POS accepts Enter, CR, LF, CRLF, and Tab while preserving SKU punctuation'
);
$check(
    str_contains($scanner, 'if (isProductBarcodeTarget(event.target)) { reset(); return; }'),
    'global receipt scanner yields focused POS barcode events'
);
$check(
    str_contains($pos, 'if (barcodeScanBusy) return;')
        && str_contains($pos, 'scannedCartQuantity(product) >= stock')
        && str_contains($pos, 'await addToCart(product, null, null, { silentErrors: true })'),
    'one-at-a-time scanning retains stock checks and the existing cart action'
);
$check(
    str_contains($lookupApi, 'printflow_product_option_stock_total')
        && str_contains($lookupApi, "\$product['variant_stock_options']"),
    'lookup preserves existing size and variant stock metadata'
);
$check(
    !str_contains($barcodeHelper, 'order_receipt_lookup')
        && !str_contains($lookupApi, 'pos_checkout'),
    'product barcode generation and lookup do not create orders or checkouts'
);

echo "All {$passed} product barcode contract tests passed.", PHP_EOL;
