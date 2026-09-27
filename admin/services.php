<?php
require_once __DIR__ . '/../includes/auth.php';
require_role(['Admin', 'Manager']);

$target = get_user_type() === 'Manager'
    ? AUTH_REDIRECT_BASE . '/manager/services.php'
    : AUTH_REDIRECT_BASE . '/admin/services_management.php';

header('Location: ' . $target, true, 302);
exit;
