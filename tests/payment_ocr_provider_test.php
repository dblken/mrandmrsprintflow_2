<?php

$GLOBALS['payment_ocr_provider_test_calls'] = [];
$GLOBALS['payment_ocr_provider_test_api_success'] = false;

function payment_ocr_with_ocrspace(string $filePath, string $mime): array {
    $GLOBALS['payment_ocr_provider_test_calls'][] = 'OCR.Space';
    if (!empty($GLOBALS['payment_ocr_provider_test_api_success'])) {
        return ['success' => true, 'attempted' => true, 'provider' => 'OCR.Space', 'text' => 'GCash', 'tokens' => [], 'confidence' => 80];
    }
    return ['success' => false, 'attempted' => true, 'unavailable' => false, 'error' => 'API test failure'];
}

function payment_ocr_with_tesseract(string $filePath, string $mime): array {
    $GLOBALS['payment_ocr_provider_test_calls'][] = 'Tesseract';
    return ['success' => true, 'attempted' => true, 'provider' => 'Tesseract', 'text' => 'GCash', 'tokens' => [], 'confidence' => 90];
}

require_once __DIR__ . '/../includes/payment_verification.php';

$failures = [];
function payment_ocr_provider_assert($condition, string $message): void {
    global $failures;
    if (!$condition) $failures[] = $message;
}

putenv('PAYMENT_OCR_PROVIDER=auto');
putenv('PAYMENT_OCR_API_KEY=test-key');
$GLOBALS['payment_ocr_provider_test_calls'] = [];
$fallback = payment_ocr_extract(__FILE__, 'image/png');
payment_ocr_provider_assert(($fallback['provider'] ?? '') === 'Tesseract', 'Auto mode should fall back to Tesseract after an OCR.Space failure.');
payment_ocr_provider_assert($GLOBALS['payment_ocr_provider_test_calls'] === ['OCR.Space', 'Tesseract'], 'Configured OCR.Space must run before the local fallback.');

$GLOBALS['payment_ocr_provider_test_calls'] = [];
$GLOBALS['payment_ocr_provider_test_api_success'] = true;
$primary = payment_ocr_extract(__FILE__, 'image/png');
payment_ocr_provider_assert(($primary['provider'] ?? '') === 'OCR.Space', 'Configured OCR.Space should remain the primary provider.');
payment_ocr_provider_assert($GLOBALS['payment_ocr_provider_test_calls'] === ['OCR.Space'], 'Tesseract should not run after primary OCR succeeds.');

putenv('PAYMENT_OCR_API_KEY');
$GLOBALS['payment_ocr_provider_test_calls'] = [];
$localOnly = payment_ocr_extract(__FILE__, 'image/png');
payment_ocr_provider_assert(($localOnly['provider'] ?? '') === 'Tesseract', 'Auto mode without an API key should use local Tesseract.');
payment_ocr_provider_assert($GLOBALS['payment_ocr_provider_test_calls'] === ['Tesseract'], 'A missing API key should not trigger an OCR.Space request.');

putenv('PAYMENT_OCR_PROVIDER=disabled');
$GLOBALS['payment_ocr_provider_test_calls'] = [];
$disabled = payment_ocr_extract(__FILE__, 'image/png');
payment_ocr_provider_assert(empty($disabled['attempted']), 'Disabled OCR must not count as an attempted scan.');
payment_ocr_provider_assert($GLOBALS['payment_ocr_provider_test_calls'] === [], 'Disabled OCR must not start a provider.');

putenv('PAYMENT_OCR_PROVIDER');
putenv('PAYMENT_OCR_API_KEY');
if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "Payment OCR provider tests passed.\n";
