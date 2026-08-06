<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/json_endpoint.php';
printflow_json_endpoint_bootstrap();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/session_manager.php';

SessionManager::start();
if ((int)($_SESSION['user_id'] ?? 0) <= 0 || (string)($_SESSION['user_type'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_SLASHES);
    exit;
}

define('PRINTFLOW_DB_DIAGNOSTIC_MODE', true);
require_once __DIR__ . '/../includes/db.php';

$diagnostic = $GLOBALS['printflow_db_diagnostics'] ?? [];
$allowed = [
    'env_loaded',
    'db_host_set',
    'db_port_set',
    'db_name_set',
    'db_user_set',
    'db_password_set',
    'pdo_connected',
    'database_selected',
];

$response = [];
foreach ($allowed as $key) {
    if (array_key_exists($key, $diagnostic)) {
        $response[$key] = $diagnostic[$key];
    }
}

$response['success'] = !in_array(false, [
    (bool)($response['env_loaded'] ?? false),
    (bool)($response['db_host_set'] ?? false),
    (bool)($response['db_port_set'] ?? false),
    (bool)($response['db_name_set'] ?? false),
    (bool)($response['db_user_set'] ?? false),
    (bool)($response['db_password_set'] ?? false),
    (bool)($response['database_selected'] ?? false),
    (bool)($diagnostic['mysqli_connected'] ?? false),
], true);

if ($response['success'] && isset($_GET['order_id'])) {
    require_once __DIR__ . '/../includes/functions.php';
    $orderId = filter_var($_GET['order_id'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($orderId === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid order ID.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $orders = db_query(
        'SELECT order_id, total_amount, payment_status, status FROM orders WHERE order_id = ? LIMIT 1',
        'i',
        [$orderId]
    ) ?: [];
    if (empty($orders)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $order = $orders[0];
    $payments = db_query(
        "SELECT * FROM provider_payments
         WHERE order_id = ? AND provider = 'paymongo' AND mode IN ('test', 'live')
         ORDER BY id DESC LIMIT 1",
        'i',
        [$orderId]
    ) ?: [];
    $payment = $payments[0] ?? [];
    $customizations = db_query(
        'SELECT status FROM customizations WHERE order_id = ? ORDER BY customization_id DESC LIMIT 1',
        'i',
        [$orderId]
    ) ?: [];
    $events = !empty($payment) ? (db_query(
        'SELECT status FROM provider_webhook_events
         WHERE provider_payment_id = ? ORDER BY id DESC LIMIT 1',
        'i',
        [(int)$payment['id']]
    ) ?: []) : [];
    $normalizeStatus = static function ($value): string {
        return strtolower((string)preg_replace(
            '/_+/',
            '_',
            preg_replace('/[^a-z0-9]+/i', '_', trim((string)$value))
        ));
    };
    $amountCentavos = (int)round(((float)$order['total_amount']) * 100);
    $response = [
        'success' => true,
        'order_id' => (int)$orderId,
        'provider_record_found' => !empty($payment),
        'provider_link_id_set' => !empty($payment['link_id']),
        'provider_payment_id_set' => !empty($payment['provider_payment_id']),
        'provider_status' => (string)($payment['provider_status'] ?? $payment['status'] ?? ''),
        'payment_status' => (string)($payment['payment_status'] ?? $payment['status'] ?? ''),
        'paid_at_set' => !empty($payment['paid_at']),
        'order_payment_status' => $normalizeStatus($order['payment_status'] ?? ''),
        'customization_status' => $normalizeStatus($customizations[0]['status'] ?? $order['status'] ?? ''),
        'webhook_event_found' => !empty($events),
        'signature_verified' => !empty($events),
        'amount_matches' => !empty($payment)
            && $amountCentavos > 0
            && $amountCentavos === (int)($payment['amount_centavos'] ?? 0),
    ];
}

http_response_code($response['success'] ? 200 : 503);
echo json_encode($response, JSON_UNESCAPED_SLASHES);
