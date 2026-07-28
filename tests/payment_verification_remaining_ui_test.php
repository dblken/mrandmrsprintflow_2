<?php

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$paymentPage = (string)file_get_contents(__DIR__ . '/../staff/payment_verification.php');
$customizations = (string)file_get_contents(__DIR__ . '/../staff/customizations.php');
$servicesPage = (string)file_get_contents(__DIR__ . '/../customer/services.php');
$modalStart = strpos($paymentPage, '<?php if ($detail):');
$modalEnd = strpos($paymentPage, '<div id="pvToast"', $modalStart === false ? 0 : $modalStart);
$reviewModal = ($modalStart !== false && $modalEnd !== false)
    ? substr($paymentPage, $modalStart, $modalEnd - $modalStart)
    : '';

$assert($reviewModal !== '', 'Payment review modal source should be discoverable.');
foreach ([
    'Staff Notes',
    'Approve Payment',
    'Reject / Request Resubmission',
    'Mark as Duplicate',
    'Duplicate Suspected',
    'Raw OCR Text',
    'Payment Status',
] as $removedLabel) {
    $assert(strpos($reviewModal, $removedLabel) === false, "{$removedLabel} must not appear in the standard review modal.");
}

foreach ([
    'sender_name',
    'sender_mobile',
    'reference_number',
    'amount_sent',
    'total_amount_sent',
    'detected_payment_method',
    'transaction_date',
    'transaction_time',
    'transaction_status',
] as $field) {
    $assert(
        preg_match('/name="' . preg_quote($field, '/') . '"[^>]*\breadonly\b/', $reviewModal) === 1,
        "{$field} should remain read-only."
    );
}

$assert(
    strpos($paymentPage, 'JobOrderService::getStoreOrderItemsPayload($detailOrderId, false, true)') !== false,
    'Payment Verification should use the same resolved order-item payload as Customizations.'
);
$assert(
    strpos($paymentPage, "WHERE order_id = ?") !== false
        && strpos($paymentPage, "(int)(\$resolvedItem['order_id'] ?? 0) !== \$detailOrderId") !== false,
    'Payment item rendering must remain scoped to the selected submission order.'
);
$assert(strpos($customizations, '.payment-proof-preview') !== false, 'Payment proof preview should use scoped clarity styles.');
$assert(strpos($customizations, 'payment_proof_original_url') !== false, 'Customizations should prefer the original secure proof URL.');
$assert(strpos($customizations, '.ink-set-options') !== false, 'Ink set selector should use scoped component styles.');
$assert(strpos($customizations, 'role="radiogroup"') !== false, 'Ink set selector should expose a radio group.');
$assert(strpos($customizations, ':aria-checked=') !== false, 'Ink set options should expose selected state accessibly.');
$assert(strpos($customizations, 'Download Image') !== false, 'Image lightbox should use the Download Image label.');
$assert(strpos($customizations, 'Download Artwork') === false, 'The old Download Artwork label should be removed.');
$assert(strpos($servicesPage, '>Customize Now</a>') !== false, 'Service cards should use the Customize Now action.');
$serviceCardRender = substr($servicesPage, 0, (int)(strpos($servicesPage, '<style>') ?: strlen($servicesPage)));
$assert(strpos($serviceCardRender, 'class="shopee-price-row"') === false, 'Service cards should not render a price row.');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "Remaining Payment Verification and Customizations UI tests passed.\n";
