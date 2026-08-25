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
$tshirtBarcodeSvg = printflow_barcode_code128b_svg('TSH-0004', 2, 72);
$stickerBarcodeSvg = printflow_barcode_code128b_svg('STK-0001', 2, 72);
$activeStickerBarcodeSvg = printflow_barcode_svg('STK-0001', 2, 72);
preg_match_all('/<rect x="(\d+)" y="0" width="(\d+)" height="72" \/>/', $stickerBarcodeSvg, $stickerBars, PREG_SET_ORDER);
$stickerLastBar = $stickerBars[count($stickerBars) - 1] ?? null;
$stickerRightQuietZone = $stickerLastBar
    ? 286 - ((int)$stickerLastBar[1] + (int)$stickerLastBar[2])
    : -1;
$check(
    str_contains($tshirtBarcodeSvg, 'width="286"')
        && str_contains($tshirtBarcodeSvg, 'height="72"')
        && str_contains($tshirtBarcodeSvg, 'shape-rendering="crispEdges"'),
    'TSH-0004 uses a crisp 286x72 Code 128 SVG with integer-width bars'
);
$check(
    str_contains($stickerBarcodeSvg, 'width="286"')
        && str_contains($stickerBarcodeSvg, 'height="72"')
        && $stickerBarcodeSvg !== $tshirtBarcodeSvg,
    'STK-0001 keeps its exact payload in a distinct crisp 286x72 Code 128 SVG'
);
$check(
    preg_match('/<rect x="20" y="0" width="\d+" height="72" \/>/', $stickerBarcodeSvg) === 1
        && $stickerRightQuietZone === 20
        && str_contains($stickerBarcodeSvg, '<rect width="100%" height="100%" fill="#fff"/>')
        && str_contains($stickerBarcodeSvg, '<g fill="#000">')
        && str_contains($barcodeHelper, 'BarcodeGeneratorSVG')
        && str_contains($barcodeHelper, 'TYPE_CODE_128')
        && str_contains($activeStickerBarcodeSvg, '<svg'),
    'last-known-good Picqer Code 128 path is restored with the black/white quiet-zone fallback'
);
$check(
    str_contains($admin, "pfProductBarcodeUrl(sku)")
        && str_contains($admin, 'txt.textContent = sku')
        && str_contains($barcodeApi, 'printflow_barcode_svg($sku'),
    'Admin preview text and generated Code 128 image use the same SKU value'
);
$check(
    !str_contains($admin, 'function pfApplyBarcodeScreenGeometry(img)')
        && !str_contains($admin, 'targetDeviceModulePx')
        && !str_contains($admin, 'devicePixelRatio')
        && str_contains($admin, '.pf-product-barcode-image')
        && str_contains($admin, 'width: auto;')
        && str_contains($admin, 'height: auto;'),
    'Admin preview preserves the last-known-good native SVG dimensions without device-pixel rewriting'
);
$check(
    str_contains($admin, 'class="view-product-details-grid"')
        && str_contains($admin, '@media (max-width: 768px)')
        && str_contains($admin, 'grid-template-columns: minmax(0, 1fr) !important;')
        && str_contains($admin, 'max-height: calc(100dvh - 16px);'),
    'Product Details switches to a viewport-contained single column on tablet and mobile'
);
$check(
    str_contains($admin, 'class="pf-product-barcode-track"')
        && str_contains($admin, 'width: max-content;')
        && str_contains($admin, 'overflow-x: auto;')
        && str_contains($admin, 'class="pf-product-barcode-text"'),
    'Barcode keeps scanner-safe width in its own horizontal viewport while the SKU stays visible below'
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
