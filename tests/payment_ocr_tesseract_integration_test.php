<?php

$windowsBinary = 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe';
if (is_file($windowsBinary)) putenv('PAYMENT_OCR_TESSERACT_PATH=' . $windowsBinary);
putenv('PAYMENT_OCR_PROVIDER=tesseract');
putenv('PAYMENT_OCR_LANGUAGE=eng');

require_once __DIR__ . '/../includes/payment_verification.php';

$failures = [];
if (!payment_ocr_tesseract_available()) {
    $failures[] = 'Tesseract is not available through the configured executable path.';
}
if (!function_exists('imagettftext')) {
    $failures[] = 'PHP GD TrueType support is required for the OCR integration fixture.';
}

$source = null;
if (!$failures) {
    $font = 'C:\\Windows\\Fonts\\arial.ttf';
    if (!is_file($font)) $failures[] = 'Arial is unavailable for the OCR integration fixture.';
}

if (!$failures) {
    $source = payment_ocr_temp_directory() . DIRECTORY_SEPARATOR . 'gcash_fixture_' . bin2hex(random_bytes(6)) . '.png';
    $image = imagecreatetruecolor(1400, 1100);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 8, 18, 35);
    imagefill($image, 0, 0, $white);
    $lines = [
        'GCash',
        'Payment Successful',
        'Sender Name: AR**N T.',
        'Sender Mobile: +63 995 484 9142',
        'Amount Sent PHP 60.00',
        'Total Amount Sent PHP 60.00',
        'Reference Number: 6039905089284',
        'Date: Apr 17, 2026',
        'Time: 12:01 PM',
    ];
    foreach ($lines as $index => $line) {
        imagettftext($image, 36, 0, 80, 100 + ($index * 105), $black, $font, $line);
    }
    imagepng($image, $source, 2);
    imagedestroy($image);

    $ocr = payment_ocr_with_tesseract($source, 'image/png');
    if (empty($ocr['success'])) {
        $failures[] = 'Tesseract failed to scan the generated receipt: ' . ($ocr['error'] ?? 'unknown error');
    } else {
        $parsed = payment_ocr_parse_receipt_text((string)$ocr['text'], $ocr['tokens'] ?? [], (float)($ocr['confidence'] ?? 0));
        if (($ocr['provider'] ?? '') !== 'Tesseract') $failures[] = 'The integration scan did not use Tesseract.';
        if (($parsed['detected_payment_method'] ?? '') !== 'GCash') $failures[] = 'The integration scan did not detect GCash.';
        if (abs((float)($parsed['amount_sent'] ?? 0) - 60.00) > 0.001) $failures[] = 'The integration scan did not extract amount 60.00.';
        if (abs((float)($parsed['total_amount_sent'] ?? 0) - 60.00) > 0.001) $failures[] = 'The integration scan did not extract total amount sent 60.00.';
        if (($parsed['reference_number'] ?? '') !== '6039905089284') $failures[] = 'The integration scan did not extract reference 6039905089284.';
        if (($parsed['transaction_date'] ?? '') !== '2026-04-17') $failures[] = 'The integration scan did not extract Apr 17, 2026.';
        if (($parsed['transaction_time'] ?? '') !== '12:01:00') $failures[] = 'The integration scan did not extract 12:01 PM.';
    }
}

if ($source !== null && is_file($source)) @unlink($source);
putenv('PAYMENT_OCR_PROVIDER');
putenv('PAYMENT_OCR_TESSERACT_PATH');
putenv('PAYMENT_OCR_LANGUAGE');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "Payment Tesseract integration test passed.\n";
