<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_role('Admin');
if (!is_admin()) {
    http_response_code(403);
    exit('Admin authorization is required.');
}
$basePath = defined('BASE_PATH') ? BASE_PATH : '/printflow';
header('Location: ' . $basePath . '/admin/archived_orders.php', true, 302);
exit;
