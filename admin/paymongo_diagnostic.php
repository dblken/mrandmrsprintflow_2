<?php
/**
 * Admin-only PayMongo diagnostic. Returns booleans only; never keys.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paymongo.php';

require_role('Admin');

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$flags = printflow_paymongo_diagnostic_flags();
$run = strtolower(trim((string)($_GET['run'] ?? '')));

if ($run === 'test_api' || $run === 'create_test_payment_link') {
    $apiResult = printflow_paymongo_test_api_request();
    $flags['api_request_ok'] = (bool)($apiResult['ok'] ?? false);
    $flags['api_request_test_mode'] = (bool)($apiResult['test_mode'] ?? false);
}

if ($run === 'create_test_payment_link') {
    $linkResult = printflow_paymongo_create_test_payment_link();
    $flags['payment_link_created'] = (bool)($linkResult['ok'] ?? false);
    $flags['payment_link_test_mode'] = array_key_exists('livemode', $linkResult)
        ? !((bool)$linkResult['livemode'])
        : false;
}

echo json_encode($flags, JSON_UNESCAPED_SLASHES);
