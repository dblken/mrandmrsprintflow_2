<?php

require_once __DIR__ . '/../includes/customization_normalizer.php';
require_once __DIR__ . '/../includes/receipt_access.php';

function expect_true($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$legacy = [
    'branch_id' => '2',
    'branch' => '2',
    'service_id' => '17',
    'service_type' => 'Poster Printing',
    'layout' => 'Without Layout',
    'Layout' => 'Without Layout',
    'size' => 'A4',
    'Sizes' => 'A4',
    'dimensions' => 'A4',
    'needed_date' => '2026-08-16',
    'Needed Date' => '2026-08-16',
    'notes' => 'Handle with care',
    'Notes' => 'Handle with care',
    'design_upload' => 'poster-final.png',
    'design_upload_name' => 'poster-final.png',
    'design_file' => '/private/path/poster-final.png',
    'design_upload_mime' => 'image/png',
    'quantity' => 1,
];

$stored = printflow_customization_normalize_storage($legacy);
expect_true(!isset($stored['branch']), 'storage normalization removes the duplicate branch alias');
expect_true(!isset($stored['Layout']), 'storage normalization removes exact label aliases');
expect_true(!isset($stored['Needed Date']), 'storage normalization removes needed-date label aliases');

$specs = printflow_customization_display_specs($legacy, [
    'include_service' => true, 'include_design' => true, 'include_notes' => true, 'include_quantity' => false,
]);
expect_true(($specs['Service'] ?? '') === 'Poster Printing', 'service is shown once');
expect_true(($specs['Layout'] ?? '') === 'Without Layout', 'layout aliases collapse');
expect_true(($specs['Size'] ?? '') === 'A4', 'size/dimensions aliases collapse');
expect_true(($specs['Needed Date'] ?? '') === 'Aug 16, 2026', 'needed date is canonical and readable');
expect_true(($specs['Notes'] ?? '') === 'Handle with care', 'notes aliases collapse');
expect_true(($specs['Uploaded Design'] ?? '') === 'poster-final.png', 'one safe uploaded filename is shown');
expect_true(!in_array('2', $specs, true), 'branch ID is hidden');
expect_true(!in_array('image/png', $specs, true), 'MIME metadata is hidden');
expect_true(!in_array('/private/path/poster-final.png', $specs, true), 'raw upload path is hidden');

$distinct = printflow_customization_display_specs(['size' => 'A4', 'dimensions' => '8x10 in']);
expect_true(count($distinct) === 2, 'different values in one semantic group are preserved');

expect_true(!printflow_customer_receipt_is_available('Processing', 'Pending'), 'unpaid processing order has no final receipt');
expect_true(!printflow_customer_receipt_is_available('Approved', 'Paid'), 'paid but pre-production approval has no final receipt');
expect_true(printflow_customer_receipt_is_available('Processing', 'Paid'), 'paid processing order has a final receipt');
expect_true(printflow_customer_receipt_is_available('In Production', 'Paid'), 'paid production order has a final receipt');
expect_true(printflow_customer_receipt_is_available('PAID – IN PROCESS', 'PAID'), 'legacy paid/production status has a final receipt');
expect_true(printflow_customer_receipt_is_available('Ready for Pickup', 'Paid'), 'paid ready order has a final receipt');

$customerEndpoint = file_get_contents(__DIR__ . '/../customer/get_order_items.php');
expect_true(str_contains($customerEndpoint, 'WHERE o.order_id = ? AND o.customer_id = ?'), 'customer receipt query enforces order ownership');
expect_true(str_contains($customerEndpoint, 'printflow_customer_receipt_is_available'), 'customer receipt payload is server-side stage gated');
expect_true(str_contains($customerEndpoint, "'qr_payload' => 'PF1:ORDER:'"), 'customer receipt uses the canonical non-secret QR payload');

$customerUi = file_get_contents(__DIR__ . '/../customer/orders.php');
expect_true(str_contains($customerUi, 'html2pdf().set'), 'customer PDF uses the authorized canonical web receipt payload');
expect_true(str_contains($customerUi, 'renderCustomerReceiptQr'), 'customer web/PDF receipt renders its support QR');

echo "Customization and customer receipt normalization tests passed.\n";
