<?php
/** Admin-only PayMongo test diagnostic. Never returns or logs credentials. */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paymongo.php';

require_role('Admin');

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function printflow_paymongo_json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$run = isset($_GET['run'])
    ? strtolower(trim((string) $_GET['run']))
    : '';

if ($run === 'create_test_payment_link') {
    $linkResult = printflow_paymongo_create_test_payment_link();
    $created = (bool)($linkResult['ok'] ?? false)
        && !((bool)($linkResult['livemode'] ?? true))
        && (string)($linkResult['id'] ?? '') !== ''
        && (string)($linkResult['url'] ?? '') !== ''
        && (int)($linkResult['amount'] ?? 0) === 10000
        && (string)($linkResult['currency'] ?? '') === 'PHP';

    if ($created) {
        printflow_paymongo_json_response([
            'success' => true,
            'action' => 'create_test_payment_link',
            'test_mode' => true,
            'payment_link_created' => true,
            'payment_link_id' => (string)$linkResult['id'],
            'checkout_url' => (string)$linkResult['url'],
            'amount' => (int)($linkResult['amount'] ?? 10000),
            'currency' => (string)($linkResult['currency'] ?? 'PHP'),
        ], 201);
    }

    $httpStatus = (int)($linkResult['http_status'] ?? 502);
    if ($httpStatus < 400 || $httpStatus > 599) {
        $httpStatus = 502;
    }
    $message = (bool)($linkResult['ok'] ?? false)
        ? 'PayMongo did not return a valid test Payment Link.'
        : (string)($linkResult['message'] ?? 'The test Payment Link could not be created.');

    $response = [
        'success' => false,
        'action' => 'create_test_payment_link',
        'test_mode' => (bool)($linkResult['test_mode'] ?? false),
        'payment_link_created' => false,
        'message' => $message,
        'http_status' => $httpStatus,
    ];
    if (!empty($linkResult['error_code'])) {
        $response['error_code'] = (string)$linkResult['error_code'];
    }
    printflow_paymongo_json_response($response, $httpStatus);
}

if ($run === 'test_api') {
    $apiResult = printflow_paymongo_test_api_request();
    $response = array_merge(
        [
            'success' => (bool)($apiResult['ok'] ?? false),
            'action' => 'test_api',
        ],
        printflow_paymongo_diagnostic_flags(),
        [
            'api_request_ok' => (bool)($apiResult['ok'] ?? false),
            'api_request_test_mode' => (bool)($apiResult['test_mode'] ?? false),
        ]
    );
    if (!(bool)($apiResult['ok'] ?? false)) {
        $response['message'] = (string)($apiResult['message'] ?? 'The PayMongo API test failed.');
        $response['http_status'] = (int)($apiResult['http_status'] ?? 502);
        if (!empty($apiResult['error_code'])) {
            $response['error_code'] = (string)$apiResult['error_code'];
        }
    }
    printflow_paymongo_json_response($response);
}

printflow_paymongo_json_response(array_merge(
    ['success' => true, 'action' => 'diagnostic'],
    printflow_paymongo_diagnostic_flags()
));
