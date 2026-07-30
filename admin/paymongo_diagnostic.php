<?php
/** Admin-only PayMongo test diagnostic. Never returns or logs credentials. */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paymongo.php';

require_role('Admin');

function respondJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('CDN-Cache-Control: no-store');
    header('Surrogate-Control: no-store');
    header('X-LiteSpeed-Cache-Control: no-cache,no-store');
    header('X-Accel-Expires: 0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function paymongoRoutingDebug(string $run): array
{
    return [
        'raw_query_string' => printflow_paymongo_safe_api_text(
            substr((string)($_SERVER['QUERY_STRING'] ?? ''), 0, 500),
            ''
        ),
        'run_exists' => array_key_exists('run', $_GET),
        'resolved_run' => $run,
    ];
}

function respondDiagnostic(array $routing): never
{
    respondJson(array_merge(
        [
            'success' => true,
            'action' => 'diagnostic',
            'diagnostic_revision' => '20260729-1',
        ],
        printflow_paymongo_diagnostic_flags(),
        ['routing' => $routing]
    ));
}

function respondApiTest(array $routing): never
{
    $apiResult = printflow_paymongo_test_api_request();
    $response = array_merge(
        [
            'success' => (bool)($apiResult['ok'] ?? false),
            'action' => 'test_api',
            'diagnostic_revision' => '20260729-1',
        ],
        printflow_paymongo_diagnostic_flags(),
        [
            'api_request_ok' => (bool)($apiResult['ok'] ?? false),
            'api_request_test_mode' => (bool)($apiResult['test_mode'] ?? false),
            'routing' => $routing,
        ]
    );

    if (!(bool)($apiResult['ok'] ?? false)) {
        $response['message'] = (string)($apiResult['message'] ?? 'The PayMongo API test failed.');
        $response['http_status'] = (int)($apiResult['http_status'] ?? 502);
        if (!empty($apiResult['error_code'])) {
            $response['error_code'] = (string)$apiResult['error_code'];
        }
    }

    respondJson($response);
}

function respondCreateTestPaymentLink(array $routing): never
{
    $linkResult = printflow_paymongo_create_test_payment_link();
    $created = (bool)($linkResult['ok'] ?? false)
        && !((bool)($linkResult['livemode'] ?? true))
        && (string)($linkResult['id'] ?? '') !== ''
        && (string)($linkResult['url'] ?? '') !== ''
        && (int)($linkResult['amount'] ?? 0) === 10000
        && (string)($linkResult['currency'] ?? '') === 'PHP'
        && (string)($linkResult['status'] ?? '') === 'active';

    if ($created) {
        respondJson([
            'success' => true,
            'action' => 'create_test_payment_link',
            'diagnostic_revision' => '20260729-1',
            'test_mode' => true,
            'payment_link_created' => true,
            'payment_link_id' => (string)$linkResult['id'],
            'url' => (string)$linkResult['url'],
            'amount' => (int)$linkResult['amount'],
            'currency' => (string)$linkResult['currency'],
            'status' => (string)$linkResult['status'],
            'routing' => $routing,
        ], 201);
    }

    $httpStatus = (int)($linkResult['http_status'] ?? 502);
    if ($httpStatus < 400 || $httpStatus > 599) {
        $httpStatus = 502;
    }

    $response = [
        'success' => false,
        'action' => 'create_test_payment_link',
        'diagnostic_revision' => '20260729-1',
        'test_mode' => (bool)($linkResult['test_mode'] ?? false),
        'payment_link_created' => false,
        'message' => (bool)($linkResult['ok'] ?? false)
            ? 'PayMongo did not return a valid active test Payment Link.'
            : (string)($linkResult['message'] ?? 'The test Payment Link could not be created.'),
        'http_status' => $httpStatus,
        'routing' => $routing,
    ];
    if (!empty($linkResult['error_code'])) {
        $response['error_code'] = (string)$linkResult['error_code'];
    }

    respondJson($response, $httpStatus);
}

$run = isset($_GET['run'])
    ? trim((string) $_GET['run'])
    : '';
$routing = paymongoRoutingDebug($run);

switch ($run) {
    case '':
        respondDiagnostic($routing);
        break;

    case 'test_api':
        respondApiTest($routing);
        break;

    case 'create_test_payment_link':
        respondCreateTestPaymentLink($routing);
        break;

    default:
        respondJson([
            'success' => false,
            'action' => 'invalid_action',
            'diagnostic_revision' => '20260729-1',
            'requested_action' => printflow_paymongo_safe_api_text($run, ''),
            'message' => 'Unsupported diagnostic action.',
            'routing' => $routing,
        ], 400);
}
