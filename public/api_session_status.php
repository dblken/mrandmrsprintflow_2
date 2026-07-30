<?php
require_once __DIR__ . '/../includes/json_endpoint.php';
printflow_json_endpoint_bootstrap();
require_once __DIR__ . '/../includes/auth.php';

SessionManager::setNoCacheHeaders();

if (!is_logged_in()) {
    echo json_encode([
        'logged_in' => false,
        'redirect' => null,
        'logout_reason' => function_exists('printflow_get_forced_logout_reason') ? printflow_get_forced_logout_reason() : null,
        'logout_message' => function_exists('printflow_get_forced_logout_message') ? printflow_get_forced_logout_message() : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$user_type = get_user_type();
$redirect = match ($user_type) {
    'Admin' => AUTH_REDIRECT_BASE . '/admin/dashboard.php',
    'Manager' => AUTH_REDIRECT_BASE . '/manager/dashboard.php',
    'Staff' => AUTH_REDIRECT_BASE . '/staff/dashboard.php',
    'Customer' => AUTH_REDIRECT_BASE . '/customer/services.php',
    default => AUTH_REDIRECT_BASE . '/',
};

echo json_encode([
    'logged_in' => true,
    'user_type' => $user_type,
    'redirect' => $redirect,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
