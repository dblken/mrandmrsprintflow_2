<?php
declare(strict_types=1);

$pos = file_get_contents(__DIR__ . '/../staff/pos.php');
if ($pos === false) {
    throw new RuntimeException('Unable to read POS page source.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

$assert(strpos($pos, 'font-awesome/6.5.1/css/all.min.css') !== false, 'POS must load Font Awesome for modal icons');
$assert(strpos($pos, 'function applyPOSModalIcon(') !== false, 'POS modal icons must use a shared preset helper');
$assert(strpos($pos, "warning: { bg: '#fef3c7', color: '#d97706'") !== false, 'warning modal icon must use amber styling');
$assert(strpos($pos, "confirm: { bg: '#edf4fc', color: '#2f6fae'") !== false, 'confirm modal icon must use POS blue styling');
$assert(strpos($pos, 'function estimatePosReceiptPrintDurationMs(') !== false, 'receipt print duration must be calculated per receipt');
$assert(strpos($pos, 'speedMmPerSec: 50') !== false, 'receipt timing must use the 50 mm/s printer spec');
$assert(strpos($pos, 'calibrationBufferSec: 0.4') !== false, 'receipt timing must include startup calibration buffer');
$assert(strpos($pos, 'function runReceiptFeedAnimation(') !== false, 'receipt feed animation helper must exist');
$assert(strpos($pos, 'receipt-printer-viewport') !== false, 'receipt modal must include a printer viewport wrapper');
$assert(strpos($pos, 'max-height ${durationMs}ms linear') !== false, 'receipt feed must reveal via viewport max-height, not scroll');
$assert(strpos($pos, 'clip-path ${durationMs}ms linear') !== false, 'receipt feed must use clip-path reveal from the fixed slot');
$assert(strpos($pos, 'translateY(100%)') === false, 'receipt feed must not translate the whole receipt block');
$assert(strpos($pos, 'WebUSB/WebSerial') !== false, 'receipt timing must document hardware sync limitation');

echo "POS modal + receipt UI test passed.\n";
