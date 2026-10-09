<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/expired_product_order_archive.php';
require_once __DIR__ . '/../../includes/staff_access.php';

if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required.']);
    exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? 'count')));

if ($method === 'POST') {
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    if (!verify_csrf_token((string)($payload['csrf_token'] ?? ''))) {
        http_response_code(419);
        echo json_encode(['success' => false, 'message' => 'Your session expired. Please refresh and try again.']);
        exit;
    }
    if ($action !== 'delete') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
        exit;
    }

    static $purgeLock = false;
    if ($purgeLock) {
        echo json_encode(['success' => false, 'message' => 'A delete operation is already in progress.']);
        exit;
    }
    $purgeLock = true;

    $result = printflow_expired_product_order_purge_archived(null);
    $payload = [
        'success' => (bool)($result['ok'] ?? false),
        'message' => (string)($result['message'] ?? ''),
        'deleted_count' => (int)($result['deleted_count'] ?? 0),
        'eligible_count' => printflow_expired_product_order_archived_count(
            null,
            printflow_staff_order_source_sql('o', 'online')
        ),
        'order_ids' => $result['order_ids'] ?? [],
    ];
    if (!empty($_GET['debug']) && is_admin() && defined('PRINTFLOW_DEBUG') && PRINTFLOW_DEBUG) {
        $payload['debug'] = [
            'endpoint' => 'admin/api/clear_archived_expired_product_orders.php',
            'action' => 'delete',
            'user_type' => (string)(get_user_type() ?? ''),
            'deleted_count' => $payload['deleted_count'],
            'eligible_count' => $payload['eligible_count'],
        ];
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$count = printflow_expired_product_order_archived_count(
    null,
    printflow_staff_order_source_sql('o', 'online')
);

$payload = [
    'success' => true,
    'eligible_count' => $count,
];

if (!empty($_GET['debug']) && is_admin() && defined('PRINTFLOW_DEBUG') && PRINTFLOW_DEBUG) {
    $payload['debug'] = [
        'endpoint' => 'admin/api/clear_archived_expired_product_orders.php',
        'action' => 'count',
        'user_type' => (string)(get_user_type() ?? ''),
        'eligible_count' => $count,
    ];
}

echo json_encode($payload, JSON_UNESCAPED_SLASHES);
