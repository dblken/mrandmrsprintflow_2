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
    'resolved_env_path',
    'connection_driver',
    'database_server_version',
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
    (bool)($response['pdo_connected'] ?? false),
    (bool)($response['database_selected'] ?? false),
    (bool)($diagnostic['mysqli_connected'] ?? false),
], true);

http_response_code($response['success'] ? 200 : 503);
echo json_encode($response, JSON_UNESCAPED_SLASHES);
