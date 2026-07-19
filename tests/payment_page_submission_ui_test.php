<?php

$source = file_get_contents(__DIR__ . '/../customer/payment.php');
$failures = [];

$requiredFragments = [
    'function showPaymentFeedback',
    'function resetPaymentSubmitButton',
    'paymentSubmissionInFlight',
    'finally {',
    'xhr.onerror = function()',
    'xhr.ontimeout = function()',
    'xhr.onabort = function()',
    'resetPaymentSubmitButton();',
    "xhr.timeout = 60000",
    "typeof window.showSuccessModal === 'function'",
    "Processing payment...",
];
foreach ($requiredFragments as $fragment) {
    if (strpos($source, $fragment) === false) {
        $failures[] = "Missing payment UI safeguard: {$fragment}";
    }
}

$onloadStart = strpos($source, 'xhr.onload = function()');
$onerrorStart = strpos($source, 'xhr.onerror = function()');
$onloadBlock = ($onloadStart !== false && $onerrorStart !== false)
    ? substr($source, $onloadStart, $onerrorStart - $onloadStart)
    : '';
if (strpos($onloadBlock, 'finally {') === false || strpos($onloadBlock, 'resetPaymentSubmitButton();') === false) {
    $failures[] = 'XHR load handling must reset the submit button in finally.';
}

$socketSource = file_get_contents(__DIR__ . '/../public/assets/js/printflow_call.js');
if (strpos($socketSource, 'reconnectionAttempts: 1') === false || strpos($socketSource, 'this.socket.io.opts.reconnection = false') === false) {
    $failures[] = 'Unavailable Socket.IO signaling must enter fallback mode without repeated retries.';
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "Payment page submission UI test passed.\n";
