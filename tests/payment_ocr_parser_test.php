<?php

require_once __DIR__ . '/../includes/payment_verification.php';

$failures = [];

function payment_ocr_test_assert($condition, string $message): void {
    global $failures;
    if (!$condition) $failures[] = $message;
}

$gcash = <<<'TEXT'
GCash
Express Send
Sent by: JUAN DELA CRUZ
Sent to: KENTLLOYD VILLANUEVA
GCash Number: 09171234567
Amount Sent PHP 100.00
Reference No. 1234 5678 9012
July 11, 2026 11:30 AM
TEXT;

$parsed = payment_ocr_parse_receipt_text($gcash, [], 88.0);
payment_ocr_test_assert($parsed['detected_payment_method'] === 'GCash', 'GCash should be detected.');
payment_ocr_test_assert(abs((float)$parsed['amount_sent'] - 100.00) < 0.001, 'GCash amount should be 100.00.');
payment_ocr_test_assert($parsed['sender_name'] === 'JUAN DELA CRUZ', 'Sender name should be extracted.');
payment_ocr_test_assert(payment_verification_normalize_reference($parsed['reference_number']) === '123456789012', 'Reference formatting should normalize.');
payment_ocr_test_assert($parsed['transaction_date'] === '2026-07-11', 'Transaction date should normalize.');
payment_ocr_test_assert($parsed['transaction_time'] === '11:30:00', 'Transaction time should normalize.');

$providedGcash = <<<'TEXT'
GCash
Payment successful
Sender Name: AR**N T.
Sender Mobile: +63 995 484 9142
Amount Sent PHP 60.00
Total Amount Sent PHP 60.00
Reference Number: 6039905089284
Date: Apr 17, 2026
Time: 12:01 PM
TEXT;
$parsedProvided = payment_ocr_parse_receipt_text($providedGcash, [], 90.0);
payment_ocr_test_assert($parsedProvided['sender_name'] === 'AR**N T.', 'Masked GCash sender name should be extracted.');
payment_ocr_test_assert($parsedProvided['sender_mobile'] === '+63 995 484 9142', 'GCash sender mobile should be extracted.');
payment_ocr_test_assert(abs((float)$parsedProvided['amount_sent'] - 60.00) < 0.001, 'Provided GCash amount should be 60.00.');
payment_ocr_test_assert($parsedProvided['reference_number'] === '6039905089284', 'Reference Number label should be supported.');
payment_ocr_test_assert($parsedProvided['transaction_date'] === '2026-04-17', 'Separate GCash date label should normalize.');
payment_ocr_test_assert($parsedProvided['transaction_time'] === '12:01:00', 'Separate GCash time label should normalize.');
payment_ocr_test_assert($parsedProvided['transaction_status'] === 'Successful', 'GCash transaction status should be extracted.');

$maya = <<<'TEXT'
Maya
Payment Successful
From: MARIA SANTOS
Recipient: Printflow Shop
Amount Paid P 1,000.00
Transaction ID: MAYA-ABC-998877
2026-07-12 08:15 PM
TEXT;
$parsedMaya = payment_ocr_parse_receipt_text($maya, [], 90.0);
payment_ocr_test_assert($parsedMaya['detected_payment_method'] === 'Maya', 'Maya should be detected.');
payment_ocr_test_assert(abs((float)$parsedMaya['amount_sent'] - 1000.00) < 0.001, 'Maya amount should be 1000.00.');
payment_ocr_test_assert(payment_verification_normalize_reference($parsedMaya['reference_number']) === 'MAYAABC998877', 'Alphanumeric Maya reference should normalize.');

$instapay = "InstaPay Transfer\nAmount PHP 80.00\nTrace No: 0000-1111-2222\n";
$parsedInstaPay = payment_ocr_parse_receipt_text($instapay, [], 92.0);
$mismatch = payment_verification_review_state(
    (float)$parsedInstaPay['amount_sent'],
    100.00,
    payment_verification_methods_match('GCash', $parsedInstaPay['detected_payment_method']),
    92.0,
    payment_verification_normalize_reference($parsedInstaPay['reference_number']),
    0
);
payment_ocr_test_assert($parsedInstaPay['detected_payment_method'] === 'Bank Transfer / InstaPay', 'InstaPay should map to bank transfer.');
payment_ocr_test_assert($mismatch['amount_match_status'] === 'Mismatch', '80.00 must mismatch a 100.00 order.');
payment_ocr_test_assert($mismatch['method_match_status'] === 'Mismatch', 'GCash and InstaPay must mismatch.');
payment_ocr_test_assert($mismatch['verification_status'] === 'Needs Review', 'A mismatch must require review.');

$matched = payment_verification_review_state(210.00, 210.00, true, 91.0, 'ABC123456', 0);
payment_ocr_test_assert($matched['verification_status'] === 'Matched', 'High-confidence matching details should be marked Matched, not Approved.');
$centSafe = payment_verification_review_state(210.004, 210.00, true, 91.0, 'ABC123456', 0);
payment_ocr_test_assert($centSafe['amount_match_status'] === 'Matched', 'Money comparison should use rounded integer cents.');
$oneCentShort = payment_verification_review_state(209.99, 210.00, true, 91.0, 'ABC123456', 0);
payment_ocr_test_assert($oneCentShort['amount_match_status'] === 'Mismatch', 'A one-cent underpayment must not match.');
payment_ocr_test_assert(payment_verification_amount_result(['ocr_status' => 'Completed', 'ocr_amount_sent' => '209.99', 'expected_amount' => '210.00']) === 'underpaid', 'Amount result should identify underpayment.');
payment_ocr_test_assert(payment_verification_amount_result(['ocr_status' => 'Completed', 'ocr_amount_sent' => '210.01', 'expected_amount' => '210.00']) === 'overpaid', 'Amount result should identify overpayment.');
payment_ocr_test_assert(payment_verification_amount_result(['ocr_status' => 'Pending', 'expected_amount' => '210.00']) === 'pending_ocr', 'Pending OCR should remain a distinct amount result.');
$low = payment_verification_review_state(50.00, 50.00, true, 58.0, 'ABC123456', 0);
payment_ocr_test_assert($low['verification_status'] === 'Needs Review', 'Low-confidence OCR must require review.');
$duplicate = payment_verification_review_state(50.00, 50.00, true, 95.0, 'ABC123456', 42);
payment_ocr_test_assert($duplicate['verification_status'] === 'Duplicate Suspected', 'Duplicate references must be flagged without automatic rejection.');

payment_ocr_test_assert(payment_verification_methods_match('Bank Transfer', 'Bank Transfer / PESONet') === true, 'Generic bank transfer should match PESONet.');
payment_ocr_test_assert(payment_verification_mask_account('09171234567') === '*******4567', 'Receiver account should be masked.');
payment_ocr_test_assert(
    payment_verification_sanitize_ocr_text("GCash\x00\x07\r\nAmount 100") === "GCash\nAmount 100",
    'OCR control characters should be removed before persistence.'
);
payment_ocr_test_assert(
    payment_ocr_normalize_text('PAYMENT PHP RECEIPT') === 'PAYMENT PHP RECEIPT',
    'OCR normalization must not replace ordinary P or H letters.'
);

if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg')) {
    $source = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'payment_ocr_source_' . bin2hex(random_bytes(6)) . '.jpg';
    $image = imagecreatetruecolor(640, 960);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    imagefill($image, 0, 0, $white);
    imagestring($image, 5, 30, 50, 'GCash Amount PHP 60.00 Ref 6039905089284', $black);
    imagejpeg($image, $source, 90);
    imagedestroy($image);
    $sourceHash = hash_file('sha256', $source);
    $processed = payment_ocr_preprocess_image($source, 'image/jpeg');
    payment_ocr_test_assert($processed !== null && is_file($processed), 'OCR preprocessing should create a separate readable copy.');
    payment_ocr_test_assert(hash_file('sha256', $source) === $sourceHash, 'OCR preprocessing must preserve the original proof.');
    if ($processed !== null && is_file($processed)) unlink($processed);
    if (is_file($source)) unlink($source);
}

$staffQueueSource = (string)file_get_contents(__DIR__ . '/../staff/payment_verification.php');
$detailQuerySource = (string)file_get_contents(__DIR__ . '/../includes/payment_verification.php');
payment_ocr_test_assert(strpos($staffQueueSource, 'o.order_sku') === false, 'Queue SQL must compute order SKU instead of selecting a nonexistent orders.order_sku column.');
payment_ocr_test_assert(strpos($detailQuerySource, 'o.order_sku') === false, 'Detail SQL must compute order SKU instead of selecting a nonexistent orders.order_sku column.');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "Payment OCR parser tests passed.\n";
