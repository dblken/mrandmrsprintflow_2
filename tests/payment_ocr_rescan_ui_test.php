<?php

$failures = [];
$page = (string)file_get_contents(__DIR__ . '/../staff/payment_verification.php');
$api = (string)file_get_contents(__DIR__ . '/../staff/api_payment_verification.php');
$service = (string)file_get_contents(__DIR__ . '/../includes/payment_verification.php');
$docker = (string)file_get_contents(__DIR__ . '/../Dockerfile');
$example = (string)file_get_contents(__DIR__ . '/../.env.example');

$rescanStart = strpos($page, 'window.pvRescan = async function');
$rescanEnd = $rescanStart === false ? false : strpos($page, "document.addEventListener('keydown'", $rescanStart);
$rescanBlock = ($rescanStart !== false && $rescanEnd !== false) ? substr($page, $rescanStart, $rescanEnd - $rescanStart) : '';

if ($rescanBlock === '') $failures[] = 'The Re-scan OCR handler is missing.';
if (strpos($rescanBlock, 'location.reload') !== false) $failures[] = 'Re-scan must refresh extracted fields without a full-page reload.';
foreach (['fetchRescanStatus', 'updateOcrUi', 'pvOcrStatus', 'pvRawOcrText'] as $fragment) {
    if (strpos($page, $fragment) === false) $failures[] = "Missing in-place OCR refresh fragment: {$fragment}";
}
foreach (['ocr_sender_mobile', 'ocr_transaction_status', 'ocr_total_amount_sent', 'raw_ocr_text', 'ocr_provider', 'confidences'] as $fragment) {
    if (strpos($api, $fragment) === false) $failures[] = "Re-scan status API is missing: {$fragment}";
}
if (strpos($service, "SET ocr_status = 'Processing', ocr_attempts = ocr_attempts + 1") !== false) {
    $failures[] = 'An unavailable provider must not increment OCR attempts when the row is claimed.';
}
if (strpos($service, 'if ($attempted)') === false || strpos($service, 'ocr_attempts = ocr_attempts + 1') === false) {
    $failures[] = 'OCR attempts must increment only after a provider reports an actual attempt.';
}
foreach (['tesseract-ocr', 'tesseract-ocr-eng', 'docker-php-ext-install curl'] as $fragment) {
    if (strpos($docker, $fragment) === false) $failures[] = "Container OCR dependency is missing: {$fragment}";
}
foreach (['PAYMENT_OCR_API_KEY=', 'PAYMENT_OCR_TESSERACT_PATH=', 'PAYMENT_OCR_TEMP_DIR='] as $fragment) {
    if (strpos($example, $fragment) === false) $failures[] = "OCR environment example is missing: {$fragment}";
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "Payment OCR Re-scan UI tests passed.\n";
