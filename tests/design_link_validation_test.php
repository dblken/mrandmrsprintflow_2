<?php

require_once __DIR__ . '/../includes/service_order_helper.php';
require_once __DIR__ . '/../includes/order_ui_helper.php';

function design_link_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$canvaLink = 'https://canva.link/918p710d7i2meb1';
$canvaCheck = service_order_validate_design_link($canvaLink);
design_link_test_assert($canvaCheck['ok'] === true, 'the provided Canva link is accepted');
design_link_test_assert($canvaCheck['url'] === $canvaLink, 'the provided Canva link is returned unchanged');

$redirectingCanva = service_order_validate_design_link('https://www.canva.com/design/DAFexample/view');
design_link_test_assert($redirectingCanva['ok'] === true, 'a Canva share link is accepted without opening it');
design_link_test_assert(
    service_order_validate_design_link('https://canva.link/redirect-me')['ok'] === true,
    'a shortened Canva link is accepted without following redirects'
);

$validatorSource = (string)file_get_contents(__DIR__ . '/../includes/service_order_helper.php');
$validatorStart = strpos($validatorSource, 'function service_order_validate_design_link');
$validatorEnd = strpos($validatorSource, 'function service_order_extract_design_link_from_customization');
design_link_test_assert($validatorStart !== false && $validatorEnd !== false && $validatorEnd > $validatorStart, 'design-link validator source is present');
$validatorBody = substr($validatorSource, $validatorStart, $validatorEnd - $validatorStart);
foreach (['get_headers', 'curl_exec', 'file_get_contents', 'fopen'] as $networkCall) {
    design_link_test_assert(strpos($validatorBody, $networkCall) === false, 'validator does not ' . $networkCall . ' the customer link');
}

$existing = [
    'https://example.com/design.png',
    'https://drive.google.com/file/d/abc123/view',
    'https://www.dropbox.com/s/example/design.png?dl=0',
];
foreach ($existing as $existingUrl) {
    $check = service_order_validate_design_link($existingUrl);
    design_link_test_assert($check['ok'] === true && $check['url'] === $existingUrl, 'existing design link still works: ' . $existingUrl);
}

$padded = "  {$canvaLink}  ";
$trimmed = service_order_validate_design_link($padded);
design_link_test_assert(
    $trimmed['ok'] === false && $trimmed['error'] === 'Spaces are not allowed in the design link.',
    'leading and trailing spaces are rejected'
);
design_link_test_assert(
    service_order_validate_design_link('https://example.com/design file.png')['ok'] === false,
    'spaces inside a design link are rejected'
);
design_link_test_assert(
    service_order_validate_design_link('https://example.com/design%20file.png')['ok'] === true,
    'percent-encoded spaces remain valid URL characters'
);

$rejected = [
    'javascript:alert(1)',
    'data:text/html,hello',
    'file:///C:/design.png',
    'vbscript:msgbox(1)',
    'http://canva.link/918p710d7i2meb1',
    'https://localhost/design.png',
    'https://127.0.0.1/design.png',
    'https://192.168.1.20/design.png',
    'https://10.0.0.8/design.png',
    'not a url',
    'https://',
];
foreach ($rejected as $unsafeUrl) {
    $check = service_order_validate_design_link($unsafeUrl);
    design_link_test_assert($check['ok'] === false && $check['url'] === '', 'unsafe or invalid link rejected: ' . $unsafeUrl);
}

$posStylePayload = [
    'design_input_mode' => 'link',
    'upload_design_design_input_mode' => 'link',
    'upload_design' => 'link',
    'Upload Design ⓘ Link' => '  ' . $canvaLink . ' ',
];
$extractedFromOldPayload = service_order_extract_design_link_from_customization($posStylePayload, 'Upload Design', 'upload_design');
design_link_test_assert($extractedFromOldPayload === $canvaLink, 'a link stored beside the help icon is still recovered');

$configs = [
    'upload_design' => ['type' => 'file', 'label' => 'Upload Design', 'required' => true],
];
service_order_persist_validated_design_links($posStylePayload, $configs);
design_link_test_assert(($posStylePayload['upload_design_link'] ?? '') === $canvaLink, 'stored upload_design_link matches the customer URL');
design_link_test_assert(($posStylePayload['design_external_link'] ?? '') === $canvaLink, 'stored design_external_link matches the customer URL');
design_link_test_assert(($posStylePayload['Upload Design Link'] ?? '') === $canvaLink, 'stored display label matches the customer URL');
design_link_test_assert(!isset($posStylePayload['upload_design']), 'the mode token is not stored as the design file');

$unsafeOptional = [
    'upload_design_link' => 'javascript:alert(1)',
    'design_external_link' => 'http://example.com/design.png',
];
service_order_persist_validated_design_links($unsafeOptional, [
    'upload_design' => ['type' => 'file', 'label' => 'Upload Design', 'required' => false],
]);
design_link_test_assert(!isset($unsafeOptional['upload_design_link']), 'an unsafe scheme is not stored');
design_link_test_assert(!isset($unsafeOptional['design_external_link']), 'a non-HTTPS design link is not stored');

$encoded = json_encode($posStylePayload, JSON_UNESCAPED_UNICODE);
$decoded = json_decode((string)$encoded, true);
design_link_test_assert(is_array($decoded) && ($decoded['design_external_link'] ?? '') === $canvaLink, 'JSON storage round-trip keeps the original URL');
design_link_test_assert(strlen($canvaLink) < 65535, 'the Canva link fits the TEXT customization column');

$schema = (string)file_get_contents(__DIR__ . '/../database/create_customizations_table.sql');
$orderColumn = (string)file_get_contents(__DIR__ . '/../includes/ensure_chat_schema.php');
design_link_test_assert(strpos($schema, '`customization_details` TEXT') !== false, 'customization details column is TEXT');
design_link_test_assert(strpos($orderColumn, 'customization_data TEXT') !== false, 'order item customization column is TEXT');

$display = pf_order_ui_extract_design_external_link($decoded);
design_link_test_assert($display['url'] === $canvaLink, 'staff display reads the original Canva URL');
design_link_test_assert($display['platform'] === 'Canva Design', 'canva.link is labeled as a Canva design');
design_link_test_assert(pf_order_ui_is_direct_renderable_image_url($canvaLink) === false, 'the Canva link is not downloaded as an image');
$escapedHref = pf_order_ui_escape($display['url']);
design_link_test_assert($escapedHref === 'https://canva.link/918p710d7i2meb1', 'the URL is escaped for an HTML attribute');
design_link_test_assert(
    pf_order_ui_escape('https://example.com/?a=1&b="x"') === 'https://example.com/?a=1&amp;b=&quot;x&quot;',
    'HTML-sensitive characters are escaped in the link'
);

$orderUi = (string)file_get_contents(__DIR__ . '/../includes/order_ui_helper.php');
design_link_test_assert(strpos($orderUi, 'rel="noopener noreferrer"') !== false, 'staff review opens the design link with safe attributes');

$requiredMissing = [
    'design_input_mode' => 'link',
    'upload_design_design_input_mode' => 'link',
];
design_link_test_assert(
    service_order_extract_design_link_from_customization($requiredMissing, 'Upload Design', 'upload_design') === '',
    'a required link mode with no URL has no design link'
);
$handler = (string)file_get_contents(__DIR__ . '/../staff/api/pos_cart_handler.php');
design_link_test_assert(
    strpos($handler, "empty(\$config['required'])") !== false
        && strpos($handler, "Please provide the required design link.") !== false,
    'a missing required design link is rejected and an optional field is skipped'
);
$customer = (string)file_get_contents(__DIR__ . '/../customer/order_service_dynamic.php');
design_link_test_assert(
    strpos($customer, "if (\$design_mode === 'link' && !\$has_design_link && \$config['required'])") !== false,
    'customer missing-link error follows the service field required flag'
);

$pos = (string)file_get_contents(__DIR__ . '/../staff/pos.php');
design_link_test_assert(strpos($pos, 'function posStoreDesignLink') !== false, 'POS stores the design link on its own field');
design_link_test_assert(strpos($pos, 'customization.design_external_link = linkValue') !== false, 'POS keeps the original URL for staff display');
design_link_test_assert(strpos($pos, "parsed.protocol === 'https:'") !== false, 'POS accepts only HTTPS design links');
design_link_test_assert(strpos($customer, "parsed.protocol === 'https:'") !== false, 'customer page accepts only HTTPS design links');
design_link_test_assert(strpos($customer, "if (/\\s/.test(rawText)) return false;") !== false, 'customer page rejects design links containing whitespace');
design_link_test_assert(
    strpos($customer, "setError(linkInput, 'Spaces are not allowed in the design link.')") !== false,
    'customer page shows a specific whitespace validation message'
);

$renderer = (string)file_get_contents(__DIR__ . '/../includes/service_field_renderer.php');
design_link_test_assert(strpos($renderer, 'pattern="\\S+"') !== false, 'design link input declares a no-whitespace pattern');
design_link_test_assert(strpos($renderer, "rawValue.replace(/\\s+/g, '')") !== false, 'design link input removes pasted whitespace');

echo "Design link validation tests passed.\n";
