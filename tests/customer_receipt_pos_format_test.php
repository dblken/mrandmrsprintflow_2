<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$customer = (string)file_get_contents($root . '/customer/orders.php');
$pos = (string)file_get_contents($root . '/staff/pos.php');
$receiptPrinter = (string)file_get_contents($root . '/includes/pos_receipt_printer.php');

$passed = 0;
function receipt_format_check(bool $condition, string $name): void {
    global $passed;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
    $passed++;
    echo "PASS: {$name}\n";
}

foreach ([
    '.receipt-sheet',
    'width: 58mm',
    'padding: 4mm',
    'font-size: 11px',
    'line-height: 1.25',
    'font-family: "Courier New", "Liberation Mono", Consolas, monospace',
    'border-top: 1px dashed #111827',
    'size: 58mm auto',
    'width: 52mm !important',
] as $contract) {
    receipt_format_check(
        str_contains($customer, $contract) && str_contains($pos, $contract),
        "customer receipt reuses POS receipt contract: {$contract}"
    );
}

receipt_format_check(
    str_contains($customer, 'class="receipt-line-items"')
        && str_contains($customer, 'class="receipt-item-amounts"')
        && !str_contains($customer, '<th>Item / Service</th>'),
    'online receipt uses a 58mm-safe stacked item layout'
);

receipt_format_check(
    str_contains($customer, 'const pageHeightMm = Math.max(58, Math.ceil((contentHeightMm + 0.5) * 10) / 10)')
        && str_contains($customer, 'format: [contentWidthMm, pageHeightMm]')
        && str_contains($customer, 'format: [contentWidthMm, 2000]')
        && str_contains($customer, 'expectedCanvasWidthPx')
        && str_contains($customer, "throw new Error('Receipt capture width is not 58mm.')")
        && str_contains($customer, 'receiptCanvasHasVisibleContent(canvas)')
        && !str_contains($customer, 'width: captureViewportWidthPx')
        && str_contains($customer, "from(canvas, 'canvas')")
        && str_contains($customer, 'actualWidthMm')
        && str_contains($customer, 'pageCount !== 1')
        && !str_contains($customer, 'format: [58, 210]'),
    'downloaded receipt enforces a one-page 58mm media box with dynamic content height'
);

receipt_format_check(
    str_contains($customer, "outputCanvas.toDataURL('image/png')")
        && str_contains($customer, 'receiptQrPngDataUrl')
        && str_contains($customer, 'qrTarget.replaceChildren(qrImage)')
        && str_contains($customer, 'await receiptWaitForImages(qrTarget)')
        && str_contains($customer, 'await receiptWaitForImages(capture)'),
    'downloaded receipt embeds a deterministic QR PNG before capture'
);

receipt_format_check(
    str_contains($customer, 'const pdfWorker = window.html2pdf().set({')
        && str_contains($customer, 'await pdfWorker.toPdf()')
        && str_contains($customer, "const pdf = await pdfWorker.get('pdf')")
        && !str_contains($customer, 'window.jspdf?.jsPDF')
        && !str_contains($customer, "throw new Error('PDF renderer is unavailable.')"),
    'download uses the html2pdf bundle pipeline without requiring an unavailable jsPDF global'
);

receipt_format_check(
    str_contains($customer, 'const quietZonePx = 8')
        && str_contains($customer, "context.fillStyle = '#ffffff'")
        && str_contains($customer, 'context.imageSmoothingEnabled = false')
        && str_contains($customer, 'image.naturalWidth')
        && str_contains($customer, 'image.naturalHeight'),
    'QR is a deterministic padded PNG and blank images fail before PDF capture'
);

receipt_format_check(
    str_contains($customer, 'typeof window.html2pdf')
        && str_contains($customer, 'Unable to generate the receipt PDF right now. Please try again.')
        && str_contains($customer, "failureStage = 'pdf-render'"),
    'missing renderer and render failures show a safe customer message with staged diagnostics'
);

receipt_format_check(
    str_contains($customer, 'width:116px !important') && str_contains($customer, 'height:116px !important')
        && str_contains($pos, 'width:116px !important') && str_contains($pos, 'height:116px !important'),
    'customer receipt QR dimensions match POS receipt QR dimensions'
);

receipt_format_check(
    str_contains($customer, 'overflow-wrap: anywhere')
        && str_contains($customer, 'word-break: break-word')
        && str_contains($customer, 'font-variant-numeric: tabular-nums'),
    'long item names, order numbers, references, and totals wrap safely'
);

receipt_format_check(
    str_contains($customer, 'body *') && str_contains($customer, 'visibility: hidden !important')
        && str_contains($customer, '#receipt-print-area')
        && str_contains($customer, '.receipt-modal-header')
        && str_contains($customer, 'display: none !important'),
    'print output targets only the receipt area and hides modal chrome'
);

receipt_format_check(
    str_contains($customer, 'Scan for order details')
        && str_contains($customer, 'new QRCode(target, { text: String(payload), width: 116, height: 116'),
    'customer receipt QR caption and size match the POS receipt QR'
);

receipt_format_check(
    str_contains($customer, 'Official Online Receipt')
        && str_contains($customer, 'Order Number')
        && str_contains($customer, 'Payment Status')
        && str_contains($customer, 'Reference')
        && str_contains($customer, 'materials.join'),
    'online-specific receipt data is preserved'
);

receipt_format_check(
    str_contains($pos, 'Official POS Receipt')
        && str_contains($pos, 'Print Receipt')
        && str_contains($pos, 'renderPosReceiptQr'),
    'POS receipt implementation remains present'
);

receipt_format_check(
    str_contains($receiptPrinter, 'printflow_receipt_format_text')
        && str_contains($receiptPrinter, 'columns = 32')
        && str_contains($receiptPrinter, 'paper_width_mm'),
    'thermal receipt printer formatter remains intact'
);

receipt_format_check(
    !str_contains($customer, 'CREATE TABLE')
        && !str_contains($customer, 'ALTER TABLE')
        && !str_contains($customer, 'DROP TABLE'),
    'no database migration is introduced by receipt UI changes'
);

echo "All {$passed} customer/POS receipt format consistency tests passed.\n";
