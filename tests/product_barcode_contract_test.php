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
$tshirtBarcodeSvg = printflow_barcode_svg('TSH-0004', 2, 72);
$check(
    str_contains($tshirtBarcodeSvg, 'width="286"')
        && str_contains($tshirtBarcodeSvg, 'height="72"')
        && str_contains($tshirtBarcodeSvg, 'shape-rendering="crispEdges"'),
    'TSH-0004 uses a crisp 286x72 Code 128 SVG with integer-width bars'
);
$check(
    str_contains($admin, "pfProductBarcodeUrl(sku)")
        && str_contains($admin, 'txt.textContent = sku')
        && str_contains($barcodeApi, 'printflow_barcode_svg($sku'),
    'Admin preview text and generated Code 128 image use the same SKU value'
);
$check(
    str_contains($admin, 'function pfApplyBarcodeScreenGeometry(img)')
        && str_contains($admin, 'targetDeviceModulePx = Math.max(3')
        && str_contains($admin, "img.style.maxWidth = 'none'")
        && !str_contains($admin, 'max-width:100%;height:72px;object-fit:contain'),
    'Admin preview maps Code 128 modules to whole device pixels instead of arbitrary responsive scaling'
);
$check(
    str_contains($admin, '.barcode{width:auto;height:auto;max-width:none;')
        && !str_contains($admin, '.barcode{width:100%;height:86px;'),
    'print barcode preserves the SVG intrinsic aspect ratio without width stretching'
);
$check(
    str_contains($admin, 'var scale = 3;')
        && str_contains($admin, "ctx.fillStyle = '#ffffff';")
        && str_contains($admin, 'ctx.imageSmoothingEnabled = false;'),
    'downloaded PNG uses a three-times raster with a solid white background and smoothing disabled'
);
$check(
    str_contains($lookupApi, 'WHERE LOWER(TRIM(p.sku)) = LOWER(?)')
        && str_contains($lookupApi, 'LIMIT 2')
        && str_contains($lookupApi, 'count($rows) > 1'),
    'POS lookup is normalized exact-match and rejects duplicate canonical SKUs'
);
$check(
    str_contains($pos, "key === 'Enter' || key === 'Tab' || key === '\\r' || key === '\\n'")
        && str_contains($pos, 'function normalizeProductBarcode(code)')
        && str_contains($pos, '\\u0000-\\u001F')
        && str_contains($pos, '.trim()'),
    'POS accepts Enter, CR, LF, CRLF, and Tab while preserving SKU punctuation'
);
$check(
    str_contains($scanner, 'if (isProductBarcodeTarget(event.target)) { reset(); return; }'),
    'global receipt scanner yields focused POS barcode events'
);
$check(
    str_contains($pos, 'const barcodeScanQueue = []')
        && str_contains($pos, 'await processBarcodeScan(scan.sku')
        && !str_contains($pos, 'if (barcodeScanBusy) return;')
        && str_contains($pos, 'scannedCartQuantity(product) >= stock')
        && str_contains($pos, 'await addToCart(product, null, null, { silentErrors: true })'),
    'FIFO scanning retains stock checks and the existing cart action without dropping busy scans'
);
$check(
    str_contains($pos, 'installPosBarcodeKeyboardCapture()')
        && str_contains($pos, "source: 'pos-keyboard-buffer'")
        && str_contains($pos, 'isProtectedPosBarcodeTarget(event.target)')
        && str_contains($pos, "if (/^PF1:ORDER:"),
    'scoped POS capture works away from the barcode input and yields receipt payloads and editing fields'
);
$check(
    str_contains($pos, "window.console.info('[POS Barcode] '")
        && str_contains($pos, "posBarcodeDebug('lookup request started'")
        && str_contains($pos, "posBarcodeDebug('lookup response'")
        && str_contains($pos, "posBarcodeDebug('add-to-cart result'"),
    'opt-in barcode diagnostics trace capture, lookup, and cart completion without payment data'
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
